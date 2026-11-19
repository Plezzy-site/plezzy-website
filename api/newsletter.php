<?php
/**
 * PLEZZY Newsletter & VIP Subscription API (Hostinger MySQL & Fallback)
 * Endpoints:
 *   POST /api/newsletter (Subscribe email, store in DB, send VIP welcome email)
 *   GET  /api/newsletter (Admin list & CSV export)
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = PlezzyDB::getConnection();
$subscribersFile = __DIR__ . '/../data/subscribers.json';

// Auto-create table if not exists in MySQL
if ($pdo) {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `newsletter_subscribers` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `email` VARCHAR(191) NOT NULL,
            `status` ENUM('active', 'unsubscribed') NOT NULL DEFAULT 'active',
            `discount_code` VARCHAR(64) DEFAULT 'PLEZZY10',
            `ip_address` VARCHAR(64) DEFAULT NULL,
            `user_agent` TEXT DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_subscriber_email` (`email`),
            KEY `idx_subscriber_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Exception $e) {
        error_log('[Newsletter] Auto-table error: ' . $e->getMessage());
    }
}

// ── 1. POST /api/newsletter (Subscribe) ───────────────────────────────────────
if ($method === 'POST') {
    $input = PlezzyDB::getJsonInput();
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        PlezzyDB::json(['success' => false, 'error' => 'Please provide a valid email address.'], 400);
    }

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $discountCode = 'PLEZZY10';
    $isNew = true;

    // 1. Insert into MySQL
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("SELECT id FROM newsletter_subscribers WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $exists = $stmt->fetch();

            if ($exists) {
                $isNew = false;
                $pdo->prepare("UPDATE newsletter_subscribers SET status = 'active', updated_at = NOW() WHERE email = ?")->execute([$email]);
            } else {
                $ins = $pdo->prepare("INSERT INTO newsletter_subscribers (email, status, discount_code, ip_address, user_agent) VALUES (?, 'active', ?, ?, ?)");
                $ins->execute([$email, $discountCode, $ip, $userAgent]);
            }
        } catch (Exception $e) {
            error_log('[Newsletter] MySQL error: ' . $e->getMessage());
        }
    }

    // 2. Dual-save to data/subscribers.json fallback
    $subscribers = PlezzyDB::readJson($subscribersFile, []);
    $found = false;
    foreach ($subscribers as &$sub) {
        if (($sub['email'] ?? '') === $email) {
            $sub['status'] = 'active';
            $sub['updatedAt'] = date('c');
            $found = true;
            break;
        }
    }
    if (!$found) {
        $subscribers[] = [
            'email' => $email,
            'status' => 'active',
            'discountCode' => $discountCode,
            'ip' => $ip,
            'createdAt' => date('c')
        ];
    }
    PlezzyDB::writeJson($subscribersFile, $subscribers);

    // 3. Send Luxury Branded VIP Welcome Email via PlezzyMailer
    $siteUrl = 'https://plezzy.in';
    $subject = "Welcome to PLEZZY • Here is your 10% VIP gift";

    $htmlBody = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Welcome to PLEZZY</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #08070b; color: #ffffff; padding: 36px 12px; margin: 0; }
  .card { max-width: 520px; margin: 0 auto; background: #14111a; border: 1px solid rgba(255,255,255,0.14); border-radius: 28px; padding: 40px 32px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.6); }
  .brand-link { text-decoration: none; display: inline-block; }
  .brand { font-size: 36px; font-weight: 800; color: #f82793; letter-spacing: -0.04em; margin-bottom: 6px; }
  .tagline { font-size: 11px; text-transform: uppercase; letter-spacing: 0.25em; color: rgba(255,255,255,0.5); margin-bottom: 26px; }
  .title { font-size: 23px; font-weight: 700; color: #ffffff; margin-bottom: 12px; }
  .desc { font-size: 14px; color: rgba(255,255,255,0.75); line-height: 1.6; margin-bottom: 26px; }
  
  .code-container { background: rgba(248,39,147,0.12); border: 1.5px dashed #f82793; border-radius: 20px; padding: 22px 16px; margin: 0 auto 24px auto; max-width: 340px; }
  .code-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: rgba(255,255,255,0.7); margin-bottom: 6px; }
  .code-value { font-size: 30px; font-weight: 900; color: #f82793; letter-spacing: 4px; font-family: monospace; }
  .code-hint { font-size: 11.5px; color: rgba(255,255,255,0.55); margin-top: 6px; }

  .btn-shop { display: inline-block; padding: 16px 42px; border-radius: 9999px; background: #f82793; color: #ffffff !important; text-decoration: none; font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.12em; margin-bottom: 18px; box-shadow: 0 10px 25px rgba(248,39,147,0.4); }
  
  .site-link-box { background: rgba(255,255,255,0.04); border: 1px solid rgba(255,255,255,0.08); border-radius: 14px; padding: 12px 16px; margin: 0 auto 28px auto; max-width: 380px; font-size: 12.5px; color: rgba(255,255,255,0.7); }
  .site-url { color: #f82793 !important; font-weight: 700; text-decoration: underline; word-break: break-all; }

  .guarantees { border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px; text-align: left; margin-bottom: 24px; }
  .guarantee-item { font-size: 12px; color: rgba(255,255,255,0.65); margin-bottom: 9px; display: flex; align-items: center; gap: 8px; }
  
  .footer { border-top: 1px solid rgba(255,255,255,0.06); padding-top: 18px; font-size: 11px; color: rgba(255,255,255,0.4); line-height: 1.6; }
  .footer-links { margin-top: 8px; }
  .footer-links a { color: rgba(255,255,255,0.6); text-decoration: none; margin: 0 8px; }
  .footer-links a:hover { color: #f82793; }
</style>
</head>
<body>
<div class="card">
  <a href="' . $siteUrl . '" class="brand-link" target="_blank">
    <div class="brand">plezzy</div>
  </a>
  <div class="tagline">Everyday Essentials, Done Well</div>
  <div class="title">You\'re on the VIP list.</div>
  <div class="desc">
    Welcome to the PLEZZY insider circle. You\'ll receive discreet early access to our sensory drops, limited color editions, and member rituals.
  </div>

  <div class="code-container">
    <div class="code-label">Your VIP Welcome Gift</div>
    <div class="code-value">' . htmlspecialchars($discountCode) . '</div>
    <div class="code-hint">Use code at checkout for 10% off your entire order</div>
  </div>

  <div>
    <a href="' . $siteUrl . '?discount=' . htmlspecialchars($discountCode) . '" class="btn-shop" target="_blank">
      Shop The Editions &rarr;
    </a>
  </div>

  <div class="site-link-box">
    <span>Direct Site Link: </span>
    <a href="' . $siteUrl . '" class="site-url" target="_blank">' . $siteUrl . '</a>
  </div>

  <div class="guarantees">
    <div class="guarantee-item"><span>📦</span> 100% Plain unmarked protective packaging</div>
    <div class="guarantee-item"><span>🔒</span> Discreet neutral billing descriptor on bank statements</div>
    <div class="guarantee-item"><span>⚡</span> Express 2-3 day priority delivery across India</div>
  </div>

  <div class="footer">
    100% Confidential &bull; Plezzy Modern Intimate Essentials
    <div class="footer-links">
      <a href="' . $siteUrl . '" target="_blank">Visit Website (' . str_replace('https://', '', $siteUrl) . ')</a> &bull;
      <a href="' . $siteUrl . '/collections.html" target="_blank">Collections</a> &bull;
      <a href="' . $siteUrl . '/account.html" target="_blank">Account</a>
    </div>
  </div>
</div>
</body>
</html>';

    // Send VIP Welcome Email
    $mailResult = PlezzyMailer::send($email, $subject, $htmlBody);

    PlezzyDB::json([
        'success' => true,
        'message' => "You're in the loop! Check your inbox for your 10% VIP gift code.",
        'discountCode' => $discountCode,
        'email' => $email,
        'emailSent' => $mailResult['success'],
        'deliveryMethod' => $mailResult['method'] ?? 'unknown'
    ], 200);
}

// ── 2. GET /api/newsletter (Admin List & CSV Export) ──────────────────────────
if ($method === 'GET') {
    $subscribers = [];
    $isExport = isset($_GET['export']) && $_GET['export'] === 'csv';

    if ($pdo) {
        try {
            $stmt = $pdo->query("SELECT * FROM newsletter_subscribers ORDER BY created_at DESC");
            $rows = $stmt->fetchAll();
            $subscribers = array_map(function($r) {
                return [
                    'id' => $r['id'],
                    'email' => $r['email'],
                    'status' => $r['status'],
                    'discountCode' => $r['discount_code'],
                    'createdAt' => $r['created_at'],
                    'ip' => $r['ip_address']
                ];
            }, $rows);
        } catch (Exception $e) {}
    }

    if (empty($subscribers)) {
        $subscribers = PlezzyDB::readJson($subscribersFile, []);
    }

    if ($isExport) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="plezzy-newsletter-subscribers.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Email', 'Status', 'Discount Code', 'Subscribed Date', 'IP']);
        foreach ($subscribers as $s) {
            fputcsv($out, [$s['email'], $s['status'] ?? 'active', $s['discountCode'] ?? 'PLEZZY10', $s['createdAt'] ?? '', $s['ip'] ?? '']);
        }
        fclose($out);
        exit();
    }

    PlezzyDB::json([
        'success' => true,
        'count' => count($subscribers),
        'subscribers' => $subscribers
    ]);
}
