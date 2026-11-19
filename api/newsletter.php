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

    // 3. Send Luxury Branded VIP Welcome Email via PHP mail()
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'plezzy.in';
    $senderDomain = preg_replace('/^www\./', '', $host);
    if (!filter_var('test@' . $senderDomain, FILTER_VALIDATE_EMAIL)) {
        $senderDomain = 'plezzy.in';
    }

    $subject = "Welcome to PLEZZY • Here is your 10% VIP gift";

    $htmlBody = '<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background-color: #08070b; color: #ffffff; padding: 40px 16px; margin: 0; }
  .card { max-width: 500px; margin: 0 auto; background: #14111a; border: 1px solid rgba(255,255,255,0.12); border-radius: 28px; padding: 40px 32px; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.6); }
  .brand { font-size: 34px; font-weight: 800; color: #f82793; letter-spacing: -0.04em; margin-bottom: 8px; }
  .tagline { font-size: 11px; text-transform: uppercase; letter-spacing: 0.25em; color: rgba(255,255,255,0.45); margin-bottom: 24px; }
  .title { font-size: 22px; font-weight: 700; color: #ffffff; margin-bottom: 12px; }
  .desc { font-size: 13.5px; color: rgba(255,255,255,0.7); line-height: 1.6; margin-bottom: 28px; }
  .code-container { background: rgba(248,39,147,0.12); border: 1.5px dashed #f82793; border-radius: 20px; padding: 20px; margin: 0 auto 28px auto; max-width: 320px; }
  .code-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.15em; color: rgba(255,255,255,0.6); margin-bottom: 6px; }
  .code-value { font-size: 28px; font-weight: 900; color: #f82793; letter-spacing: 4px; font-family: monospace; }
  .btn-shop { display: inline-block; padding: 14px 36px; border-radius: 9999px; background: #f82793; color: #ffffff; text-decoration: none; font-size: 12.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 28px; }
  .guarantees { border-top: 1px solid rgba(255,255,255,0.08); padding-top: 20px; text-align: left; }
  .guarantee-item { font-size: 11.5px; color: rgba(255,255,255,0.6); margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
  .footer { font-size: 10.5px; color: rgba(255,255,255,0.35); margin-top: 24px; line-height: 1.5; }
</style>
</head>
<body>
<div class="card">
  <div class="brand">plezzy</div>
  <div class="tagline">Everyday Essentials, Done Well</div>
  <div class="title">You\'re on the list.</div>
  <div class="desc">
    Welcome to the PLEZZY insider circle. You\'ll receive discreet early access to our sensory drops, limited color editions, and member rituals.
  </div>

  <div class="code-container">
    <div class="code-label">Your VIP Welcome Gift</div>
    <div class="code-value">' . htmlspecialchars($discountCode) . '</div>
    <div style="font-size: 11px; color: rgba(255,255,255,0.5); margin-top: 6px;">Use code at checkout for 10% off your entire order</div>
  </div>

  <div>
    <a href="https://' . htmlspecialchars($senderDomain) . '" class="btn-shop">Explore The Editions &rarr;</a>
  </div>

  <div class="guarantees">
    <div class="guarantee-item"><span>📦</span> 100% Plain unmarked protective packaging</div>
    <div class="guarantee-item"><span>🔒</span> Discreet neutral billing descriptor on bank statements</div>
    <div class="guarantee-item"><span>⚡</span> Express 2-3 day priority delivery across India</div>
  </div>

  <div class="footer">
    100% Confidential &bull; Plezzy Modern Intimate Essentials<br>
    Unsubscribe anytime with one click.
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
