<?php
/**
 * PLEZZY Hostinger Database Setup & Diagnostics Utility
 * Access in browser: https://yourdomain.com/api/setup.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$msg = '';
$msgType = '';

// Handle credentials update from Web Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $newHost = trim($_POST['db_host'] ?? 'localhost');
    $newName = trim($_POST['db_name'] ?? '');
    $newUser = trim($_POST['db_user'] ?? '');
    $newPass = trim($_POST['db_pass'] ?? '');
    $newPort = intval($_POST['db_port'] ?? 3306);

    // Test connection before saving
    try {
        $testDsn = "mysql:host={$newHost};port={$newPort};dbname={$newName};charset=utf8mb4";
        $testPdo = new PDO($testDsn, $newUser, $newPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 4
        ]);

        // Write to config.php
        $configContent = "<?php\n"
            . "/**\n"
            . " * PLEZZY Database & Hostinger Environment Configuration\n"
            . " * Auto-configured via /api/setup.php on " . date('Y-m-d H:i:s') . "\n"
            . " */\n\n"
            . "if (!defined('DB_HOST')) define('DB_HOST', " . var_export($newHost, true) . ");\n"
            . "if (!defined('DB_NAME')) define('DB_NAME', " . var_export($newName, true) . ");\n"
            . "if (!defined('DB_USER')) define('DB_USER', " . var_export($newUser, true) . ");\n"
            . "if (!defined('DB_PASS')) define('DB_PASS', " . var_export($newPass, true) . ");\n"
            . "if (!defined('DB_PORT')) define('DB_PORT', {$newPort});\n\n"
            . "date_default_timezone_set('Asia/Kolkata');\n";

        file_put_contents(__DIR__ . '/config.php', $configContent);

        // Auto-run database.sql migration
        $sqlFile = __DIR__ . '/../database.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $testPdo->exec($sql);
        }

        $msg = "✓ Hostinger MySQL connected successfully! Tables created and products seeded.";
        $msgType = "success";
    } catch (Exception $e) {
        $msg = "Connection failed: " . htmlspecialchars($e->getMessage());
        $msgType = "error";
    }
}

// Check current connection
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

    // Check tables
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Plezzy &bull; Hostinger Database Setup</title>
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
      max-width: 640px;
      background: #14111c;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 24px;
      padding: 32px 28px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.6);
    }
    .brand {
      font-size: 26px;
      font-weight: 800;
      color: #f82793;
      letter-spacing: -0.04em;
      margin-bottom: 4px;
    }
    .subtitle {
      font-size: 13px;
      color: rgba(255,255,255,0.6);
      margin-bottom: 24px;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 9999px;
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 20px;
    }
    .status-connected {
      background: rgba(16, 185, 129, 0.15);
      border: 1px solid rgba(16, 185, 129, 0.4);
      color: #34d399;
    }
    .status-disconnected {
      background: rgba(244, 63, 94, 0.15);
      border: 1px solid rgba(244, 63, 94, 0.4);
      color: #fb7185;
    }
    .table-list {
      background: rgba(255,255,255,0.03);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 16px;
      padding: 16px;
      margin-bottom: 24px;
    }
    .table-row {
      display: flex;
      justify-content: space-between;
      padding: 8px 0;
      border-bottom: 1px solid rgba(255,255,255,0.05);
      font-size: 12.5px;
    }
    .table-row:last-child { border-bottom: none; }
    .form-group {
      margin-bottom: 14px;
      text-align: left;
    }
    label {
      display: block;
      font-size: 11.5px;
      font-weight: 600;
      color: rgba(255,255,255,0.7);
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }
    input {
      width: 100%;
      background: rgba(255,255,255,0.05);
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 12px;
      padding: 12px 14px;
      color: #ffffff;
      font-size: 13px;
      outline: none;
      transition: border 0.2s;
    }
    input:focus {
      border-color: #f82793;
    }
    button {
      width: 100%;
      background: #f82793;
      color: #ffffff;
      border: none;
      border-radius: 14px;
      padding: 14px;
      font-size: 13px;
      font-weight: 700;
      cursor: pointer;
      margin-top: 10px;
      transition: background 0.2s;
    }
    button:hover {
      background: #d41c7b;
    }
    .alert {
      padding: 12px 16px;
      border-radius: 12px;
      font-size: 12.5px;
      margin-bottom: 18px;
    }
    .alert-success { background: rgba(16, 185, 129, 0.2); border: 1px solid #10b981; color: #a7f3d0; }
    .alert-error { background: rgba(244, 63, 94, 0.2); border: 1px solid #f43f5e; color: #fecdd3; }
    .hostinger-guide {
      background: rgba(248, 39, 147, 0.06);
      border: 1px solid rgba(248, 39, 147, 0.25);
      border-radius: 16px;
      padding: 16px;
      font-size: 12px;
      line-height: 1.6;
      margin-bottom: 24px;
      color: rgba(255,255,255,0.8);
    }
    .hostinger-guide strong { color: #f82793; }
  </style>
</head>
<body>
<div class="container">
  <div class="brand">plezzy</div>
  <div class="subtitle">Hostinger MySQL Database Manager & Diagnostics</div>

  <?php if (!empty($msg)): ?>
    <div class="alert alert-<?php echo $msgType; ?>"><?php echo $msg; ?></div>
  <?php endif; ?>

  <?php if ($isConnected): ?>
    <div class="status-badge status-connected">
      <span>●</span> MySQL Database Connected (Active)
    </div>
    <div class="table-list">
      <div style="font-size: 12px; font-weight: 700; margin-bottom: 8px; color: rgba(255,255,255,0.9);">Database Tables:</div>
      <?php foreach ($tablesInfo as $tbl => $count): ?>
        <div class="table-row">
          <span><?php echo htmlspecialchars($tbl); ?></span>
          <strong style="color: #f82793;"><?php echo htmlspecialchars($count); ?> records</strong>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <div class="status-badge status-disconnected">
      <span>●</span> MySQL Disconnected &bull; Using JSON Fallback Storage
    </div>
    <?php if ($errorNotice): ?>
      <div style="font-size: 11px; color: rgba(255,255,255,0.45); margin-bottom: 16px; font-family: monospace; word-break: break-all;">
        Reason: <?php echo htmlspecialchars($errorNotice); ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="hostinger-guide">
    <strong>Hostinger Setup Steps:</strong><br>
    1. Log in to your Hostinger hPanel &rarr; <strong>Databases</strong> &rarr; <strong>MySQL Databases</strong>.<br>
    2. Create a Database name (e.g. <code>u123456789_plezzy</code>) and User with password.<br>
    3. Enter those values below and click <strong>Connect & Migrate Database</strong>.<br>
    <em>All tables will be created automatically, and the 10 catalog products will be seeded.</em>
  </div>

  <form method="POST" action="">
    <input type="hidden" name="action" value="save_config">
    <div class="form-group">
      <label>Hostinger MySQL Host</label>
      <input type="text" name="db_host" value="<?php echo htmlspecialchars(DB_HOST); ?>" required>
    </div>
    <div class="form-group">
      <label>Database Name</label>
      <input type="text" name="db_name" placeholder="e.g. u123456789_plezzy" value="<?php echo htmlspecialchars(DB_NAME); ?>" required>
    </div>
    <div class="form-group">
      <label>Database User</label>
      <input type="text" name="db_user" placeholder="e.g. u123456789_user" value="<?php echo htmlspecialchars(DB_USER); ?>" required>
    </div>
    <div class="form-group">
      <label>Database Password</label>
      <input type="password" name="db_pass" placeholder="Enter Hostinger database password" value="<?php echo htmlspecialchars(DB_PASS); ?>">
    </div>
    <div class="form-group">
      <label>Port</label>
      <input type="number" name="db_port" value="<?php echo htmlspecialchars(DB_PORT); ?>" required>
    </div>
    <button type="submit">Connect & Migrate Database &rarr;</button>
  </form>
</div>
</body>
</html>
