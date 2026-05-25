<#
.SYNOPSIS
    Script de déploiement FTP — etienne-terrassement.ch
.DESCRIPTION
    Déploie le site sur Infomaniak via FTP (upload incrémental basé sur hashes).

    Usage :
      .\deploy.ps1            Déployer les fichiers modifiés
      .\deploy.ps1 -Full      Forcer le redéploiement complet
      .\deploy.ps1 -DryRun    Lister les fichiers sans transférer
#>

param(
    [switch]$Full,
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

# ═══════════════════════════════════════
#  Configuration
# ═══════════════════════════════════════
$FTP_HOST    = "e03xy6.ftp.infomaniak.com"
$FTP_USER    = "e03xy6_qwdwq0o035"
$FTP_PASS    = 'p_.S$cFDww4Y4ig'
$REMOTE_WEB  = ""
$FTP_BASE    = "ftp://${FTP_HOST}"

$EXCLUDE_PATTERNS = @(
    ".git",
    ".gitignore",
    ".vscode",
    "deploy.ps1",
    "etienneconfig.php",
    "node_modules",
    ".deploy-manifest.json",
    ".claude"
)

$PROJECT_DIR = $PSScriptRoot
if (-not $PROJECT_DIR) { $PROJECT_DIR = (Get-Location).Path }

# ═══════════════════════════════════════
#  Helpers
# ═══════════════════════════════════════
function Write-Step  { param([string]$m) Write-Host "  > $m" -ForegroundColor Cyan }
function Write-Ok    { param([string]$m) Write-Host "  + $m" -ForegroundColor Green }
function Write-Err   { param([string]$m) Write-Host "  x $m" -ForegroundColor Red }
function Write-Title { param([string]$m) Write-Host ""; Write-Host "== $m ==" -ForegroundColor Yellow; Write-Host "" }

function Get-FtpCredential {
    return New-Object System.Net.NetworkCredential($FTP_USER, $FTP_PASS)
}

function Ensure-FtpDirectory {
    param([string]$remotePath)
    $parts = $remotePath.Trim('/').Split('/')
    $current = ""
    foreach ($part in $parts) {
        $current += "/$part"
        $uri = "${FTP_BASE}${current}/"
        try {
            $req = [System.Net.FtpWebRequest]::Create($uri)
            $req.Method = [System.Net.WebRequestMethods+Ftp]::MakeDirectory
            $req.Credentials = Get-FtpCredential
            $req.GetResponse() | Out-Null
        } catch {
            # Directory probably exists already
        }
    }
}

function Upload-FtpFile {
    param([string]$localPath, [string]$remotePath)
    $uri = "${FTP_BASE}${remotePath}"
    $req = [System.Net.FtpWebRequest]::Create($uri)
    $req.Method = [System.Net.WebRequestMethods+Ftp]::UploadFile
    $req.Credentials = Get-FtpCredential
    $req.UseBinary = $true
    $req.UsePassive = $true

    $fileContent = [System.IO.File]::ReadAllBytes($localPath)
    $req.ContentLength = $fileContent.Length

    $stream = $req.GetRequestStream()
    $stream.Write($fileContent, 0, $fileContent.Length)
    $stream.Close()

    $response = $req.GetResponse()
    $response.Close()
}

# ═══════════════════════════════════════
#  Déploiement
# ═══════════════════════════════════════
function Get-DeployableFiles {
    $allFiles = Get-ChildItem -Path $PROJECT_DIR -Recurse -File -Force | Where-Object {
        $relPath = $_.FullName.Substring($PROJECT_DIR.Length + 1)
        $excluded = $false
        foreach ($pattern in $EXCLUDE_PATTERNS) {
            if ($relPath -like $pattern -or $relPath -like "$pattern\*" -or $relPath.Split('\')[0] -like $pattern) {
                $excluded = $true; break
            }
        }
        -not $excluded
    }
    return $allFiles
}

function Deploy-Site {
    Write-Title "Deploiement etienne-terrassement.ch"
    Write-Host "  Cible : ftp://${FTP_HOST}" -ForegroundColor DarkGray
    if ($DryRun) { Write-Host "  Mode : DRY RUN (aucun transfert)" -ForegroundColor Yellow }
    Write-Host ""

    # ── 1. Charger le manifeste précédent ──
    $manifestPath = Join-Path $PROJECT_DIR ".deploy-manifest.json"
    $oldManifest = @{}
    if ((Test-Path $manifestPath) -and -not $Full) {
        $raw = Get-Content $manifestPath -Raw | ConvertFrom-Json
        $raw.PSObject.Properties | ForEach-Object { $oldManifest[$_.Name] = $_.Value }
    }

    # ── 2. Scanner et hasher ──
    Write-Step "Analyse des fichiers..."
    $allFiles = Get-DeployableFiles
    $newManifest = @{}
    $changedFiles = @()

    foreach ($file in $allFiles) {
        $relPath = $file.FullName.Substring($PROJECT_DIR.Length + 1).Replace('\', '/')
        $hash = (Get-FileHash -Path $file.FullName -Algorithm SHA256).Hash
        $newManifest[$relPath] = $hash

        if (-not $oldManifest.ContainsKey($relPath) -or $oldManifest[$relPath] -ne $hash) {
            $changedFiles += $file
        }
    }

    if ($changedFiles.Count -eq 0) {
        Write-Ok "Aucun fichier modifie — rien a deployer"
        return
    }

    Write-Ok "$($changedFiles.Count) fichier(s) modifie(s) detecte(s)"

    # ── DRY RUN ──
    if ($DryRun) {
        foreach ($file in $changedFiles) {
            $rel = $file.FullName.Substring($PROJECT_DIR.Length + 1).Replace('\', '/')
            Write-Host "  [DRY] $rel" -ForegroundColor DarkGray
        }
        Write-Host ""
        Write-Ok "Dry run termine. Relancer sans -DryRun pour deployer."
        return
    }

    # ── 3. Créer les dossiers distants nécessaires ──
    Write-Step "Creation des dossiers distants..."
    $remoteDirs = @()
    foreach ($file in $changedFiles) {
        $relPath = $file.FullName.Substring($PROJECT_DIR.Length + 1).Replace('\', '/')
        $relDir = [System.IO.Path]::GetDirectoryName($relPath).Replace('\', '/')
        if ($relDir -and $relDir -notin $remoteDirs) {
            $remoteDirs += $relDir
        }
    }
    foreach ($dir in $remoteDirs) {
        Ensure-FtpDirectory "${REMOTE_WEB}/${dir}"
    }

    # ── 4. Upload des fichiers ──
    Write-Step "Upload des fichiers..."
    $success = 0
    $errors = 0
    foreach ($file in $changedFiles) {
        $relPath = $file.FullName.Substring($PROJECT_DIR.Length + 1).Replace('\', '/')
        $remotePath = "${REMOTE_WEB}/${relPath}"
        try {
            Upload-FtpFile -localPath $file.FullName -remotePath $remotePath
            Write-Host "    OK  $relPath" -ForegroundColor DarkGray
            $success++
        } catch {
            Write-Err "ERR $relPath : $($_.Exception.Message)"
            $errors++
        }
    }
    Write-Ok "$success fichier(s) uploade(s)"

    # ── 5. Sauvegarder le manifeste ──
    if ($errors -eq 0) {
        $newManifest | ConvertTo-Json -Depth 1 | Set-Content -Path $manifestPath -Encoding UTF8
    }

    Write-Host ""
    if ($errors -gt 0) {
        Write-Err "$errors erreur(s) lors du deploiement."
    } else {
        Write-Ok "Deploiement termine !"
    }
    Write-Host ""
    Write-Host '  -> https://etienne-terrassement.ch' -ForegroundColor DarkGray
    Write-Host ""
}

# ═══════════════════════════════════════
#  Point d'entrée
# ═══════════════════════════════════════
Deploy-Site
