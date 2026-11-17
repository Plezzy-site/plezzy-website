<?php
// PLEZZY Production OTP Verification API (Hostinger Apache/PHP Compatible)
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

    // Store OTP in JSON store and session
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

    // Send real email via PHP mail() if server supports it
    $subject = "Your Plezzy Verification Code: {$otp}";
    $message = "Hello {$name},\n\nYour 6-digit login verification code for Plezzy is:\n\n    {$otp}\n\nThis code expires in 10 minutes.\nIf you did not request this code, you can safely ignore this email.\n\nWarm regards,\nTeam Plezzy";
    $headers = "From: Plezzy Security <no-reply@plezzy.in>\r\n" .
               "Reply-To: support@plezzy.in\r\n" .
               "X-Mailer: PHP/" . phpversion();

    @mail($email, $subject, $message, $headers);

    echo json_encode([
        'success' => true,
        'message' => 'Verification code sent to ' . $email,
        'email' => $email,
        'name' => $name,
        'preview_otp' => $otp // Provided for instant preview / dev feedback
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

    if ($validEntry) {
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

    // Also check session fallback
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
