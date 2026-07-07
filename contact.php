<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

// ── Charger la config (hors du dossier public) ──
$configPath = __DIR__ . '/../../private/etienneconfig.php';
if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration serveur manquante.']);
    exit;
}
require_once $configPath;

// ── Charger PHPMailer ──
$autoload = __DIR__ . '/../../private/vendor/autoload.php';
$manualLoad = __DIR__ . '/../../private/PHPMailer/src/PHPMailer.php';

if (file_exists($autoload)) {
    require_once $autoload;
} elseif (file_exists($manualLoad)) {
    require_once $manualLoad;
    require_once __DIR__ . '/../../private/PHPMailer/src/SMTP.php';
    require_once __DIR__ . '/../../private/PHPMailer/src/Exception.php';
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'PHPMailer non installé.']);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Honeypot ──
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true, 'message' => 'Message envoyé avec succès !']);
    exit;
}

// ── Rate limiting ──
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ratefile = sys_get_temp_dir() . '/et_contact_' . md5($ip) . '.lock';
$rateLimit = defined('RATE_LIMIT') ? RATE_LIMIT : 60;
if (file_exists($ratefile) && (time() - filemtime($ratefile)) < $rateLimit) {
    echo json_encode(['success' => false, 'message' => 'Veuillez patienter avant de renvoyer un message.']);
    exit;
}

// ── Blocage géographique (via header Cloudflare) ──
$blockedCountries = ['IN','PK','BD','NG','GH','KE','PH','VN','CN','RU','UA','KZ','UZ'];
$country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
if ($country && in_array(strtoupper($country), $blockedCountries)) {
    echo json_encode(['success' => false, 'message' => 'Ce service n\'est pas disponible dans votre région.']);
    exit;
}

// ── Cloudflare Turnstile ──
$turnstileSecret = defined('TURNSTILE_SECRET') ? TURNSTILE_SECRET : '';
$turnstileResponse = $_POST['cf-turnstile-response'] ?? '';
if (!empty($turnstileSecret)) {
    if (empty($turnstileResponse)) {
        echo json_encode(['success' => false, 'message' => 'Veuillez compléter la vérification de sécurité.']);
        exit;
    }
    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'secret'   => $turnstileSecret,
            'response' => $turnstileResponse,
            'remoteip' => $ip,
        ]),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $verify = curl_exec($ch);
    curl_close($ch);
    $result = json_decode($verify, true);
    if (!$result || empty($result['success'])) {
        echo json_encode(['success' => false, 'message' => 'Vérification de sécurité échouée. Veuillez réessayer.']);
        exit;
    }
}

// ── Validation des champs ──
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$phone   = trim($_POST['phone'] ?? '');
$service = trim($_POST['service'] ?? '');
$message = trim($_POST['message'] ?? '');

if (empty($name) || empty($email) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs obligatoires.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Adresse email invalide.']);
    exit;
}

foreach ([$name, $email, $phone, $service] as $field) {
    if (preg_match('/[\r\n]/', $field)) {
        echo json_encode(['success' => false, 'message' => 'Données invalides.']);
        exit;
    }
}

// ── Filtrage contenu spam ──
$spamKeywords = [
    'SEO', 'backlink', 'link building', 'rank higher', 'page rank', 'google ranking',
    'web design service', 'website redesign', 'web development service',
    'digital marketing', 'online marketing', 'social media marketing',
    'lead generation', 'boost your', 'grow your business',
    'free consultation', 'free audit', 'free quote',
    'guaranteed results', 'first page', 'top of google',
    'affordable website', 'professional website', 'custom website',
    'increase traffic', 'increase sales', 'more customers',
    'marketing agency', 'marketing company', 'marketing team',
    'offshore', 'outsource', 'dedicated developer',
    'wordpress', 'shopify', 'wix',
    'unsubscribe', 'opt out', 'opt-out',
    'cryptocurrency', 'crypto', 'bitcoin', 'forex', 'casino',
    'viagra', 'cialis', 'pharmacy',
];
$contentLower = mb_strtolower($name . ' ' . $message, 'UTF-8');
$spamHits = 0;
foreach ($spamKeywords as $kw) {
    if (mb_stripos($contentLower, mb_strtolower($kw, 'UTF-8')) !== false) {
        $spamHits++;
    }
}
if ($spamHits >= 2) {
    echo json_encode(['success' => true, 'message' => 'Message envoyé avec succès !']);
    exit;
}

// ── Rejet si trop de liens ──
if (preg_match_all('/https?:\/\//i', $message, $m) >= 2) {
    echo json_encode(['success' => true, 'message' => 'Message envoyé avec succès !']);
    exit;
}

// ── Construire le corps du mail ──
$serviceLabels = [
    'genie-civil'   => 'Génie civil & Travaux publics',
    'terrassement'  => 'Terrassement & Aménagements',
    'chemins'       => 'Construction de chemins',
    'bitumineux'    => 'Revêtements bitumineux',
    'canalisations' => 'Canalisations',
    'autre'         => 'Autre',
];
$serviceLabel = $serviceLabels[$service] ?? ($service ?: 'Non spécifié');

$body  = "Nouveau message depuis le site web\n";
$body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
$body .= "Nom : $name\n";
$body .= "Email : $email\n";
$body .= "Téléphone : " . ($phone ?: '—') . "\n";
$body .= "Service : $serviceLabel\n\n";
$body .= "Message :\n$message\n\n";
$body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
$body .= "Date : " . date('d.m.Y H:i') . "\n";

// ── Envoi via PHPMailer ──
try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $mail->addAddress(MAIL_TO, MAIL_TO_NAME);
    $mail->addReplyTo($email, $name);

    $mail->Subject = "Demande de contact — $name";
    $mail->Body    = $body;

    $mail->send();

    // ── Email de confirmation au client ──
    $confirm = new PHPMailer(true);
    $confirm->isSMTP();
    $confirm->Host       = SMTP_HOST;
    $confirm->SMTPAuth   = true;
    $confirm->Username   = SMTP_USERNAME;
    $confirm->Password   = SMTP_PASSWORD;
    $confirm->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $confirm->Port       = SMTP_PORT;
    $confirm->CharSet    = 'UTF-8';

    $confirm->setFrom(MAIL_FROM, MAIL_FROM_NAME);
    $confirm->addAddress($email, $name);
    $confirm->addReplyTo(MAIL_TO, MAIL_TO_NAME);

    $confirm->Subject = "Confirmation de votre demande — Etienne Terrassement";

    $confirmBody  = "Bonjour $name,\n\n";
    $confirmBody .= "Nous avons bien reçu votre message et nous vous en remercions.\n";
    $confirmBody .= "Notre équipe vous répondra dans les plus brefs délais.\n\n";
    $confirmBody .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $confirmBody .= "Récapitulatif de votre demande :\n\n";
    $confirmBody .= "Service : $serviceLabel\n";
    $confirmBody .= "Message :\n$message\n";
    $confirmBody .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
    $confirmBody .= "Cordialement,\n";
    $confirmBody .= "Etienne Terrassement\n";
    $confirmBody .= "Tél. : +41 79 855 92 33\n\n";
    $confirmBody .= "---\n";
    $confirmBody .= "Merci de ne pas répondre à cet e-mail.\n";
    $confirmBody .= "Vous pouvez nous écrire directement à etienne.terrassement24@gmail.com\n";

    $confirm->Body = $confirmBody;
    $confirm->send();

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'envoi. Veuillez nous appeler au 079 855 92 33.']);
    exit;
}

touch($ratefile);

echo json_encode(['success' => true, 'message' => 'Votre message a été envoyé avec succès ! Nous vous répondrons dans les plus brefs délais.']);
