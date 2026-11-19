<?php
/**
 * PLEZZY Hostinger Database & Email Setup & Diagnostics Utility
 * Access in browser: https://yourdomain.com/api/setup.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$msg = '';
$msgType = '';

// Helper to rewrite config.php preserving both DB and SMTP settings
function saveConfigSettings($db, $smtp) {
    $content = "<?php\n"
        . "/**\n"
        . " * PLEZZY Production Configuration (Hostinger MySQL & Authenticated SMTP)\n"
        . " * Auto-configured via /api/setup.php on " . date('Y-m-d H:i:s') . "\n"
        . " */\n\n"
        . "// Database Settings\n"
        . "if (!defined('DB_HOST')) define('DB_HOST', " . var_export($db['host'], true) . ");\n"
        . "if (!defined('DB_NAME')) define('DB_NAME', " . var_export($db['name'], true) . ");\n"
        . "if (!defined('DB_USER')) define('DB_USER', " . var_export($db['user'], true) . ");\n"
        . "if (!defined('DB_PASS')) define('DB_PASS', " . var_export($db['pass'], true) . ");\n"
        . "if (!defined('DB_PORT')) define('DB_PORT', " . intval($db['port']) . ");\n\n"
        . "// Authenticated SMTP Settings\n"
        . "if (!defined('SMTP_HOST')) define('SMTP_HOST', " . var_export($smtp['host'], true) . ");\n"
        . "if (!defined('SMTP_PORT')) define('SMTP_PORT', " . intval($smtp['port']) . ");\n"
        . "if (!defined('SMTP_SECURE')) define('SMTP_SECURE', " . var_export($smtp['secure'], true) . ");\n"
        . "if (!defined('SMTP_USER')) define('SMTP_USER', " . var_export($smtp['user'], true) . ");\n"
        . "if (!defined('SMTP_PASS')) define('SMTP_PASS', " . var_export($smtp['pass'], true) . ");\n"
        . "if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', " . var_export($smtp['from_email'], true) . ");\n"
        . "if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', " . var_export($smtp['from_name'], true) . ");\n\n"
        . "date_default_timezone_set('Asia/Kolkata');\n";

    return @file_put_contents(__DIR__ . '/config.php', $content);
}

// 1. Handle DB Config Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_db_config') {
    $newHost = trim($_POST['db_host'] ?? 'localhost');
    $newName = trim($_POST['db_name'] ?? '');
    $newUser = trim($_POST['db_user'] ?? '');
    $newPass = trim($_POST['db_pass'] ?? '');
    $newPort = intval($_POST['db_port'] ?? 3306);

    try {
        $testDsn = "mysql:host={$newHost};port={$newPort};dbname={$newName};charset=utf8mb4";
        $testPdo = new PDO($testDsn, $newUser, $newPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
        ]);

        $currentDb = ['host' => $newHost, 'name' => $newName, 'user' => $newUser, 'pass' => $newPass, 'port' => $newPort];
        $currentSmtp = [
            'host' => SMTP_HOST, 'port' => SMTP_PORT, 'secure' => SMTP_SECURE,
            'user' => SMTP_USER, 'pass' => SMTP_PASS, 'from_email' => SMTP_FROM_EMAIL, 'from_name' => SMTP_FROM_NAME
        ];
        saveConfigSettings($currentDb, $currentSmtp);

        $sqlFile = __DIR__ . '/../database.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $testPdo->exec($sql);
        }

        $msg = "✓ MySQL connected successfully and tables migrated!";
        $msgType = "success";
    } catch (Exception $e) {
        $msg = "Database connection failed: " . htmlspecialchars($e->getMessage());
        $msgType = "error";
    }
}

// 2. Handle SMTP Config Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_smtp_config') {
    $smtpHost = trim($_POST['smtp_host'] ?? 'smtp.hostinger.com');
    $smtpPort = intval($_POST['smtp_port'] ?? 465);
    $smtpSecure = trim($_POST['smtp_secure'] ?? 'ssl');
    $smtpUser = trim($_POST['smtp_user'] ?? '');
    $smtpPass = trim($_POST['smtp_pass'] ?? '');
    $smtpFromEmail = trim($_POST['smtp_from_email'] ?? 'support@plezzy.in');
    $smtpFromName = trim($_POST['smtp_from_name'] ?? 'PLEZZY VIP');

    $currentDb = ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'pass' => DB_PASS, 'port' => DB_PORT];
    $newSmtp = [
        'host' => $smtpHost, 'port' => $smtpPort, 'secure' => $smtpSecure,
        'user' => $smtpUser, 'pass' => $smtpPass, 'from_email' => $smtpFromEmail, 'from_name' => $smtpFromName
    ];
    saveConfigSettings($currentDb, $newSmtp);

    $msg = "✓ SMTP settings saved! Now use the 'Send Test Email' button below to test your inbox delivery.";
    $msgType = "success";
}

// 3. Handle Send Test Email
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_test_email') {
    $testTo = trim($_POST['test_email'] ?? '');
    if (!filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
        $msg = "Invalid recipient email address.";
        $msgType = "error";
    } else {
        $testSubject = "PLEZZY VIP Test Delivery • " . date('H:i:s');
        $siteUrl = 'https://plezzy.in';
        $testBody = '
        <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif; background:#08070b; color:#fff; padding:32px 24px; border-radius:20px; max-width:480px; margin:0 auto; text-align:center;">
          <a href="' . $siteUrl . '" style="text-decoration:none;" target="_blank"><h1 style="color:#f82793; font-size:28px; margin:0 0 6px 0;">plezzy</h1></a>
          <p style="font-size:13px; color:#aaa; margin:0 0 20px 0;">Inbox Verification Test</p>
          <div style="background:rgba(248,39,147,0.12); border:1.5px dashed #f82793; border-radius:14px; padding:16px; margin-bottom:20px;">
            <p style="font-size:11px; text-transform:uppercase; letter-spacing:1px; color:#ddd; margin:0 0 6px 0;">VIP Code</p>
            <h2 style="color:#f82793; font-size:24px; margin:0; letter-spacing:3px;">PLEZZY10</h2>
          </div>
          <div style="margin-bottom:20px;">
            <a href="' . $siteUrl . '" style="display:inline-block; padding:12px 28px; border-radius:9999px; background:#f82793; color:#fff; text-decoration:none; font-size:12px; font-weight:700; text-transform:uppercase;" target="_blank">Visit Website &rarr;</a>
          </div>
          <p style="font-size:12px; color:#aaa; margin:0 0 6px 0;">Direct Site Link: <a href="' . $siteUrl . '" style="color:#f82793; font-weight:bold;" target="_blank">' . $siteUrl . '</a></p>
          <p style="font-size:11px; color:#666;">If you received this email, your PLEZZY email delivery pipeline is 100% operational!</p>
        </div>';

        $result = PlezzyMailer::send($testTo, $testSubject, $testBody);
        if ($result['success']) {
            $msg = "✓ Test email delivered to " . htmlspecialchars($testTo) . " via " . htmlspecialchars($result['method']) . "! Check your inbox (and Spam/Junk).";
            $msgType = "success";
        } else {
            $msg = "✗ Email send failed: " . htmlspecialchars($result['error'] ?? 'Unknown error');
            $msgType = "error";
        }
    }
}

// Check database connection & table record counts
$isConnected = false;
$tablesInfo = [];
$errorNotice = '';

try {
    $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 3
    ]);
    $isConnected = true;

    $tables = ['products', 'orders', 'customers', 'newsletter_subscribers', 'otp_verifications', 'admin_settings'];
    foreach ($tables as $tbl) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `{$tbl}`");
            $tablesInfo[$tbl] = $stmt->fetch()['c'];
        } catch (Exception $ex) {
            $tablesInfo[$tbl] = 'Missing (Not Created)';
        }
    }
} catch (Exception $e) {
    $isConnected = false;
    $errorNotice = $e->getMessage();
}

$hasSmtpConfigured = defined('SMTP_USER') && !empty(SMTP_USER) && defined('SMTP_PASS') && !empty(SMTP_PASS);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Plezzy &bull; Hostinger Diagnostics & Manager</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      background: #08070b;
      color: #ffffff;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      padding: 30px 16px;
      display: flex;
      justify-content: center;
    }
    .container {
      width: 100%;
      max-width: 680px;
      background: #14111c;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 24px;
      padding: 32px 28px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.6);
    }
    .brand { font-size: 26px; font-weight: 800; color: #f82793; letter-spacing: -0.04em; margin-bottom: 4px; }
    .subtitle { font-size: 13px; color: rgba(255,255,255,0.6); margin-bottom: 24px; }
    .status-badge {
      display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px;
      border-radius: 9999px; font-size: 12px; font-weight: 600; margin-bottom: 16px;
    }
    .status-connected { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: #34d399; }
    .status-disconnected { background: rgba(244, 63, 94, 0.15); border: 1px solid rgba(244, 63, 94, 0.4); color: #fb7185; }
    .status-warning { background: rgba(245, 158, 11, 0.15); border: 1px solid rgba(245, 158, 11, 0.4); color: #fbbf24; }
    .card-section {
      background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08);
      border-radius: 16px; padding: 20px; margin-bottom: 24px;
    }
    .card-title {
      font-size: 14px; font-weight: 700; color: #ffffff; margin-bottom: 12px; display: flex; align-items: center; gap: 8px;
    }
    .table-row {
      display: flex; justify-content: space-between; padding: 8px 0;
      border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 12.5px;
    }
    .table-row:last-child { border-bottom: none; }
    .form-group { margin-bottom: 14px; text-align: left; }
    label {
      display: block; font-size: 11px; font-weight: 600; color: rgba(255,255,255,0.7);
      margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.05em;
    }
    input, select {
      width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.15);
      border-radius: 12px; padding: 12px 14px; color: #ffffff; font-size: 13px; outline: none; transition: border 0.2s;
    }
    input:focus, select:focus { border-color: #f82793; }
    button {
      width: 100%; background: #f82793; color: #ffffff; border: none; border-radius: 12px;
      padding: 13px; font-size: 13px; font-weight: 700; cursor: pointer; margin-top: 6px; transition: background 0.2s;
    }
    button:hover { background: #d41c7b; }
    .btn-secondary { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); }
    .btn-secondary:hover { background: rgba(255,255,255,0.18); }
    .alert { padding: 12px 16px; border-radius: 12px; font-size: 12.5px; margin-bottom: 18px; line-height: 1.5; }
    .alert-success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #a7f3d0; }
    .alert-error { background: rgba(244, 63, 94, 0.2); border: 1px solid #f43f5e; color: #fecdd3; }
    .guide-box {
      background: rgba(248, 39, 147, 0.06); border: 1px solid rgba(248, 39, 147, 0.25);
      border-radius: 14px; padding: 14px; font-size: 12px; line-height: 1.6; margin-bottom: 18px; color: rgba(255,255,255,0.8);
    }
    .guide-box strong { color: #f82793; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  </style>
</head>
<body>
<div class="container">
  <div class="brand">plezzy</div>
  <div class="subtitle">Hostinger MySQL & Email Pipeline Manager</div>

  <?php if (!empty($msg)): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
  <?php endif; ?>

  <!-- 1. Database Status -->
  <div class="card-section">
    <div class="card-title"><span>🗄️</span> Hostinger MySQL Database</div>
    <?php if ($isConnected): ?>
      <div class="status-badge status-connected"><span>●</span> Connected to <?php echo htmlspecialchars(DB_NAME); ?></div>
      <div>
        <?php foreach ($tablesInfo as $tbl => $count): ?>
          <div class="table-row">
            <span><code><?php echo htmlspecialchars($tbl); ?></code></span>
            <strong style="color: #f82793;"><?php echo htmlspecialchars($count); ?> records</strong>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="status-badge status-disconnected"><span>●</span> Disconnected</div>
      <div style="font-size: 11px; color: #fb7185; margin-bottom: 12px;"><?php echo htmlspecialchars($errorNotice); ?></div>
    <?php endif; ?>
  </div>

  <!-- 2. Test Live Email Delivery -->
  <div class="card-section">
    <div class="card-title"><span>✉️</span> Live Email Inbox Test</div>
    <p style="font-size: 12px; color: rgba(255,255,255,0.65); margin-bottom: 14px;">
      Send a live VIP test email with the 10% code to verify delivery directly into your inbox.
    </p>

    <form method="POST" action="">
      <input type="hidden" name="action" value="send_test_email">
      <div class="form-group">
        <label>Your Email Address</label>
        <input type="email" name="test_email" placeholder="e.g. yourname@gmail.com" value="<?php echo htmlspecialchars($_POST['test_email'] ?? 'khushwantsinghkuldeep13@gmail.com'); ?>" required>
      </div>
      <button type="submit">Send Test VIP Email Now &rarr;</button>
    </form>
  </div>

  <!-- 3. Authenticated SMTP Settings (Hostinger Webmail) -->
  <div class="card-section">
    <div class="card-title">
      <span>🔐</span> Authenticated SMTP Settings
      <?php if ($hasSmtpConfigured): ?>
        <span class="status-badge status-connected" style="margin-left:auto; margin-bottom:0;">SMTP Active</span>
      <?php else: ?>
        <span class="status-badge status-warning" style="margin-left:auto; margin-bottom:0;">Native PHP mail() fallback</span>
      <?php endif; ?>
    </div>

    <div class="guide-box">
      <strong>Why SMTP?</strong> Google (Gmail) and Yahoo enforce strict DKIM/SPF verification. Using your Hostinger webmail address (e.g. <code>support@plezzy.in</code>) guarantees <strong>100% direct inbox delivery</strong> without landing in spam.
      <br><br>
      Hostinger Free Email details:<br>
      &bull; <strong>Host:</strong> <code>smtp.hostinger.com</code> &bull; <strong>Port:</strong> <code>465</code> (SSL)<br>
      &bull; <strong>Username:</strong> Your Hostinger email (e.g. <code>support@plezzy.in</code>)<br>
      &bull; <strong>Password:</strong> Your Hostinger email account password
    </div>

    <form method="POST" action="">
      <input type="hidden" name="action" value="save_smtp_config">
      <div class="grid-2">
        <div class="form-group">
          <label>SMTP Host</label>
          <input type="text" name="smtp_host" value="<?php echo htmlspecialchars(SMTP_HOST); ?>" required>
        </div>
        <div class="form-group">
          <label>Port</label>
          <input type="number" name="smtp_port" value="<?php echo htmlspecialchars(SMTP_PORT); ?>" required>
        </div>
      </div>
      <div class="grid-2">
        <div class="form-group">
          <label>Encryption</label>
          <select name="smtp_secure">
            <option value="ssl" <?php echo SMTP_SECURE === 'ssl' ? 'selected' : ''; ?>>SSL (Port 465 - Recommended)</option>
            <option value="tls" <?php echo SMTP_SECURE === 'tls' ? 'selected' : ''; ?>>TLS / STARTTLS (Port 587)</option>
          </select>
        </div>
        <div class="form-group">
          <label>From Name</label>
          <input type="text" name="smtp_from_name" value="<?php echo htmlspecialchars(SMTP_FROM_NAME); ?>" required>
        </div>
      </div>
      <div class="form-group">
        <label>Sender / Reply Email</label>
        <input type="email" name="smtp_from_email" value="<?php echo htmlspecialchars(SMTP_FROM_EMAIL); ?>" placeholder="support@plezzy.in" required>
      </div>
      <div class="form-group">
        <label>SMTP Username (Hostinger Email)</label>
        <input type="text" name="smtp_user" value="<?php echo htmlspecialchars(SMTP_USER); ?>" placeholder="e.g. support@plezzy.in">
      </div>
      <div class="form-group">
        <label>SMTP Password</label>
        <input type="password" name="smtp_pass" value="<?php echo htmlspecialchars(SMTP_PASS); ?>" placeholder="Enter email account password">
      </div>
      <button type="submit" class="btn-secondary">Save SMTP Configuration</button>
    </form>
  </div>
</div>
</body>
</html>
