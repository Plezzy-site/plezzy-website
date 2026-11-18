<?php
// PLEZZY Production OTP Verification API (Hostinger Apache/PHP Compatible)
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

$action = isset($_GET['action']) ? $_GET['action'] : '';
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$storageDir = __DIR__ . '/../data';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
$otpStoreFile = $storageDir . '/otp_store.json';

function readOtpStore($file) {
    if (!file_exists($file)) return [];
    $raw = @file_get_contents($file);
    return $raw ? (json_decode($raw, true) ?: []) : [];
}

function writeOtpStore($file, $data) {
    @file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

// 1. ACTION: SEND OTP
if ($action === 'send' || (isset($input['action']) && $input['action'] === 'send')) {
    $name = trim(isset($input['name']) ? $input['name'] : '');
    $email = strtolower(trim(isset($input['email']) ? $input['email'] : ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please provide a valid email address.']);
        exit();
    }

    if (empty($name)) {
        $name = explode('@', $email)[0];
    }

    // Generate cryptographic 6-digit OTP
    $otp = strval(random_int(100000, 999999));
    $expiresAt = time() + 600; // 10 minutes validity

    // Store OTP securely
    $store = readOtpStore($otpStoreFile);
    $store[$email] = [
        'name' => $name,
        'email' => $email,
        'otp' => $otp,
        'expires_at' => $expiresAt,
        'created_at' => time()
    ];
    writeOtpStore($otpStoreFile, $store);
    $_SESSION['plezzy_otp_' . $email] = $otp;

    // Record in MySQL if connected
    $pdo = PlezzyDB::getConnection();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("INSERT INTO otp_verifications (email, name, otp_code, expires_at, ip_address) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$email, $name, $otp, date('Y-m-d H:i:s', $expiresAt), $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
        } catch (Exception $e) {}
    }

    // Send formatted HTML verification email
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'plezzy.in';
    $senderDomain = preg_replace('/^www\./', '', $host);
    if (!filter_var('test@' . $senderDomain, FILTER_VALIDATE_EMAIL)) {
        $senderDomain = 'plezzy.in';
    }

    $subject = "Your Plezzy Verification Code: {$otp}";
    
    $htmlMessage = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #08070b; color: #ffffff; padding: 40px 16px; margin: 0; }
  .card { max-width: 480px; margin: 0 auto; background: #16131c; border: 1px solid rgba(255,255,255,0.12); border-radius: 24px; padding: 36px 28px; text-align: center; }
  .brand { font-size: 32px; font-weight: 800; color: #ffffff; letter-spacing: -0.04em; margin-bottom: 8px; }
  .title { font-size: 20px; font-weight: 700; color: #ffffff; margin-bottom: 12px; }
  .desc { font-size: 13.5px; color: rgba(255,255,255,0.7); line-height: 1.5; margin-bottom: 28px; }
  .otp-box { display: inline-block; padding: 16px 32px; border-radius: 16px; background: rgba(248,39,147,0.15); border: 1.5px solid #f82793; font-size: 32px; font-weight: 800; letter-spacing: 6px; color: #ff479d; margin-bottom: 24px; font-family: monospace; }
  .footer { font-size: 11px; color: rgba(255,255,255,0.45); line-height: 1.5; margin-top: 24px; border-top: 1px solid rgba(255,255,255,0.08); pt: 16px; }
</style>
</head>
<body>
<div class="card">
  <div class="brand">plezzy</div>
  <div class="title">Email Verification Code</div>
  <div class="desc">Hello ' . htmlspecialchars($name) . ',<br>Use the 6-digit verification code below to securely sign in to your Plezzy account.</div>
  <div class="otp-box">' . htmlspecialchars($otp) . '</div>
  <div class="desc" style="font-size: 12px; margin-bottom: 0;">This code will expire in <strong>10 minutes</strong>.<br>If you did not request this verification, please ignore this email.</div>
  <div class="footer">100% Discreet & Confidential &bull; Plezzy Modern Pleasure Essentials</div>
</div>
</body>
</html>';

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Plezzy Security <no-reply@" . $senderDomain . ">\r\n";
    $headers .= "Reply-To: support@" . $senderDomain . "\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    @mail($email, $subject, $htmlMessage, $headers);

    // Return clean response without revealing the OTP code on screen
    echo json_encode([
        'success' => true,
        'message' => 'Verification code sent to ' . $em
    ail,
        'message' => 'Verification code sent to ' . $email,
        'email' => $email,
        'name' => $name
    ]);
    exit();
}

// 2. ACTION: VERIFY OTP
if ($action === 'verify' || (isset($input['action']) && $input['action'] === 'verify')) {
    $email = strtolower(trim(isset($input['email']) ? $input['email'] : ''));
    $otp = trim(isset($input['otp']) ? $input['otp'] : '');

    if (empty($email) || empty($otp)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Email and 6-digit OTP code are required.']);
        exit();
    }

    $store = readOtpStore($otpStoreFile);
    $validEntry = isset($store[$email]) ? $store[$email] : null;

    $isMatch = false;
    $userName = '';

    // Check MySQL records first
    $pdo = PlezzyDB::getConnection();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM otp_verifications WHERE email = ? AND otp_code = ? AND expires_at > NOW() AND is_verified = 0 ORDER BY id DESC LIMIT 1");
            $stmt->execute([$email, $otp]);
            $row = $stmt->fetch();
            if ($row) {
                $isMatch = true;
                $userName = $row['name'] ?: $userName;
                $pdo->prepare("UPDATE otp_verifications SET is_verified = 1 WHERE id = ?")->execute([$row['id']]);
            }
        } catch (Exception $e) {}
    }

    if (!$isMatch && $validEntry) {
        if (time() > $validEntry['expires_at']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Verification code has expired. Please request a new one.']);
            exit();
        }
        if ($validEntry['otp'] === $otp) {
            $isMatch = true;
            $userName = $validEntry['name'];
        }
    }

    // Session fallback
    if (!$isMatch && isset($_SESSION['plezzy_otp_' . $email]) && $_SESSION['plezzy_otp_' . $email] === $otp) {
        $isMatch = true;
    }

    if ($isMatch) {
        // Clear consumed OTP
        unset($store[$email]);
        writeOtpStore($otpStoreFile, $store);
        unset($_SESSION['plezzy_otp_' . $email]);

        // Record customer in customers.json
        $customersFile = $storageDir . '/customers.json';
        $customers = [];
        if (file_exists($customersFile)) {
            $raw = @file_get_contents($customersFile);
            $customers = $raw ? (json_decode($raw, true) ?: []) : [];
        }
        $customers[$email] = [
            'name' => $userName,
            'email' => $email,
            'verified' => true,
            'last_login' => date('Y-m-d H:i:s')
        ];
        @file_put_contents($customersFile, json_encode($customers, JSON_PRETTY_PRINT), LOCK_EX);

        echo json_encode([
            'success' => true,
            'message' => 'Email verified successfully!',
            'user' => [
                'name' => $userName,
                'email' => $email,
                'token' => bin2hex(random_bytes(16))
            ]
        ]);
        exit();
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid verification code. Please check and try again.']);
    exit();
}

http_response_code(404);
echo json_encode(['success' => false, 'error' => 'Unknown action. Use ?action=send or ?action=verify']);
