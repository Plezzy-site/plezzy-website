<?php
/**
 * PLEZZY Luxury Mailer Engine
 * High-deliverability transactional email delivery:
 *   1. Authenticated SMTP (Hostinger Webmail smtp.hostinger.com:465 SSL, Gmail, Resend, Brevo)
 *   2. Strict RFC-compliant native mail() fallback with custom Return-Path & Envelope Sender (-f)
 */

require_once __DIR__ . '/config.php';

class PlezzyMailer {

    /**
     * Send email with automatic SMTP selection & native fallback
     */
    public static function send($to, $subject, $htmlBody, $plainAlt = '') {
        $fromEmail = defined('SMTP_FROM_EMAIL') && !empty(SMTP_FROM_EMAIL) ? SMTP_FROM_EMAIL : 'support@plezzy.in';
        $fromName  = defined('SMTP_FROM_NAME') && !empty(SMTP_FROM_NAME)   ? SMTP_FROM_NAME  : 'PLEZZY VIP';

        // 1. If SMTP is configured, send via Authenticated SMTP (highest deliverability to Gmail)
        if (defined('SMTP_HOST') && !empty(SMTP_HOST) && defined('SMTP_USER') && !empty(SMTP_USER) && defined('SMTP_PASS') && !empty(SMTP_PASS)) {
            $smtpRes = self::sendSmtp($to, $subject, $htmlBody, $fromEmail, $fromName);
            if ($smtpRes['success']) {
                return $smtpRes;
            }
            error_log('[PlezzyMailer] SMTP attempt failed (' . ($smtpRes['error'] ?? '') . '). Falling back to native mail().');
        }

        // 2. Enhanced Native mail() with RFC envelope sender
        return self::sendNativeMail($to, $subject, $htmlBody, $fromEmail, $fromName);
    }

    /**
     * Native PHP mail() with envelope sender parameter (-f)
     */
    public static function sendNativeMail($to, $subject, $htmlBody, $fromEmail, $fromName) {
        $domain = substr(strrchr($fromEmail, "@"), 1) ?: 'plezzy.in';
        $msgId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $domain . '>';

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'Reply-To: support@' . $domain;
        $headers[] = 'Return-Path: <' . $fromEmail . '>';
        $headers[] = 'Message-ID: ' . $msgId;
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'X-Mailer: PLEZZY Native Mailer PHP/' . phpversion();
        $headers[] = 'List-Unsubscribe: <mailto:unsubscribe@' . $domain . '?subject=unsubscribe>';

        $headerStr = implode("\r\n", $headers);
        $extraParams = '-f' . escapeshellcmd($fromEmail);

        $sent = @mail($to, $subject, $htmlBody, $headerStr, $extraParams);
        if (!$sent) {
            $sent = @mail($to, $subject, $htmlBody, $headerStr);
        }

        return [
            'success' => $sent,
            'method'  => 'mail()',
            'error'   => $sent ? null : 'Native mail() was not accepted by the host mail transfer agent.'
        ];
    }

    /**
     * Pure PHP Socket SMTP Client (Hostinger, Gmail, Brevo, SendGrid, etc.)
     * Zero dependencies, supports SSL (465) and STARTTLS (587)
     */
    public static function sendSmtp($to, $subject, $htmlBody, $fromEmail, $fromName) {
        $host = SMTP_HOST;
        $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 465;
        $user = SMTP_USER;
        $pass = SMTP_PASS;
        $secure = defined('SMTP_SECURE') ? strtolower(SMTP_SECURE) : 'ssl';
        $timeout = 10;

        $target = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return ['success' => false, 'error' => "Cannot connect to SMTP {$host}:{$port} - {$errstr} ({$errno})"];
        }

        stream_set_timeout($socket, $timeout);

        $readResponse = function() use ($socket) {
            $resp = '';
            while ($line = fgets($socket, 515)) {
                $resp .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $resp;
        };

        $command = function($cmd, $expectedCode) use ($socket, $readResponse) {
            fputs($socket, $cmd . "\r\n");
            $resp = $readResponse();
            $code = substr($resp, 0, 3);
            if ($code !== (string)$expectedCode) {
                return ['success' => false, 'error' => "SMTP Error on [{$cmd}]: {$resp}"];
            }
            return ['success' => true, 'response' => $resp];
        };

        $banner = $readResponse();
        if (substr($banner, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'error' => "Invalid SMTP greeting: {$banner}"];
        }

        $helloRes = $command("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'plezzy.in'), 250);
        if (!$helloRes['success']) {
            fclose($socket);
            return $helloRes;
        }

        if ($secure === 'tls') {
            $tlsRes = $command("STARTTLS", 220);
            if (!$tlsRes['success']) {
                fclose($socket);
                return $tlsRes;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                fclose($socket);
                return ['success' => false, 'error' => 'Failed to negotiate TLS encryption'];
            }
            $command("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'plezzy.in'), 250);
        }

        // AUTH LOGIN
        $authRes = $command("AUTH LOGIN", 334);
        if (!$authRes['success']) {
            fclose($socket);
            return $authRes;
        }

        $userRes = $command(base64_encode($user), 334);
        if (!$userRes['success']) {
            fclose($socket);
            return $userRes;
        }

        $passRes = $command(base64_encode($pass), 235);
        if (!$passRes['success']) {
            fclose($socket);
            return ['success' => false, 'error' => "SMTP Authentication failed for {$user}"];
        }

        // MAIL FROM
        $mailFromRes = $command("MAIL FROM: <{$fromEmail}>", 250);
        if (!$mailFromRes['success']) {
            fclose($socket);
            return $mailFromRes;
        }

        // RCPT TO
        $rcptRes = $command("RCPT TO: <{$to}>", 250);
        if (!$rcptRes['success']) {
            fclose($socket);
            return $rcptRes;
        }

        // DATA
        $dataRes = $command("DATA", 354);
        if (!$dataRes['success']) {
            fclose($socket);
            return $dataRes;
        }

        $domain = substr(strrchr($fromEmail, "@"), 1) ?: 'plezzy.in';
        $msgId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . $domain . '>';

        $headers = [];
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        $headers[] = 'From: ' . self::encodeHeader($fromName) . ' <' . $fromEmail . '>';
        $headers[] = 'To: <' . $to . '>';
        $headers[] = 'Subject: ' . self::encodeHeader($subject);
        $headers[] = 'Reply-To: support@' . $domain;
        $headers[] = 'Return-Path: <' . $fromEmail . '>';
        $headers[] = 'Message-ID: ' . $msgId;
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'X-Mailer: PLEZZY Authenticated SMTP';

        $bodyPayload = implode("\r\n", $headers) . "\r\n\r\n" . $htmlBody . "\r\n.";
        $sendRes = $command($bodyPayload, 250);
        $command("QUIT", 221);
        fclose($socket);

        return $sendRes['success'] ? ['success' => true, 'method' => 'SMTP'] : $sendRes;
    }

    private static function encodeHeader($str) {
        return '=?UTF-8?B?' . base64_encode($str) . '?=';
    }
}
