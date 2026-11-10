<?php
/**
 * email_otp.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Email OTP utility: generate, send, and verify one-time passwords for the
 * two-step login flow.
 *
 * Supports Real-Time Outbound Email via:
 *   1. Brevo REST API (HTTPS port 443 - Recommended for instant delivery)
 *   2. Resend REST API (HTTPS port 443)
 *   3. SendGrid REST API (HTTPS port 443)
 *   4. Direct SMTP over TLS (Port 587 - Gmail, Brevo, or custom SMTP)
 *   5. Native PHP mail() fallback
 *   6. Local dev-mode file log fallback
 *
 * Functions:
 *   generate_email_otp(int $userId, PDO $pdo): string
 *   send_otp_email(string $toEmail, string $toName, string $otp): bool
 *   verify_email_otp(int $userId, string $enteredOtp, PDO $pdo): bool
 * ─────────────────────────────────────────────────────────────────────────────
 */

declare(strict_types=1);

if (!defined('OTP_EXPIRY_SECONDS')) {
    define('OTP_EXPIRY_SECONDS', 600);   // 10 minutes
}
if (!defined('OTP_MAX_ATTEMPTS')) {
    define('OTP_MAX_ATTEMPTS', 5);
}

// ─── App config fallback ──────────────────────────────────────────────────────
if (!defined('MAIL_FROM_ADDRESS')) {
    define('MAIL_FROM_ADDRESS', 'noreply@secure-health-system.rf.gd');
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', 'Secure Health System');
}

/**
 * Retrieve Mail API & SMTP configuration from constants, environment variables,
 * or system_config.json.
 */
function is_local_development_environment(): bool
{
    if (getenv('VERCEL') !== false) {
        return false;
    }

    $serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? ''));
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $env = strtolower((string)(getenv('APP_ENV') ?: ($_SERVER['APP_ENV'] ?? '')));

    if ($env !== '') {
        return in_array($env, ['local', 'development'], true);
    }

    return in_array($serverName, ['localhost', '127.0.0.1', '::1'], true)
        || in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function get_mail_delivery_settings(): array
{
    $sysConfig = [];
    if (file_exists(__DIR__ . '/system_config.php')) {
        require_once __DIR__ . '/system_config.php';
        if (function_exists('get_system_config')) {
            $sysConfig = get_system_config();
        }
    }

    $brevoKey = defined('BREVO_API_KEY') ? (string)BREVO_API_KEY : (getenv('BREVO_API_KEY') ?: ($sysConfig['brevo_api_key'] ?? ''));
    $resendKey = defined('RESEND_API_KEY') ? (string)RESEND_API_KEY : (getenv('RESEND_API_KEY') ?: ($sysConfig['resend_api_key'] ?? ''));
    $sendgridKey = defined('SENDGRID_API_KEY') ? (string)SENDGRID_API_KEY : (getenv('SENDGRID_API_KEY') ?: ($sysConfig['sendgrid_api_key'] ?? ''));

    $smtpHost = defined('SMTP_HOST') ? (string)SMTP_HOST : (getenv('SMTP_HOST') ?: ($sysConfig['smtp_host'] ?? 'smtp.gmail.com'));
    $smtpPort = defined('SMTP_PORT') ? (int)SMTP_PORT : (int)(getenv('SMTP_PORT') ?: ($sysConfig['smtp_port'] ?? 587));
    $smtpUser = defined('SMTP_USER') ? (string)SMTP_USER : (getenv('SMTP_USER') ?: ($sysConfig['smtp_user'] ?? ''));
    $smtpPass = defined('SMTP_PASS') ? (string)SMTP_PASS : (getenv('SMTP_PASS') ?: ($sysConfig['smtp_pass'] ?? ''));

    $fromEmail = defined('SMTP_FROM_EMAIL') ? (string)SMTP_FROM_EMAIL : (getenv('SMTP_FROM_EMAIL') ?: ($sysConfig['smtp_from_email'] ?? ($sysConfig['mail_from_email'] ?? '')));
    $fromName = defined('SMTP_FROM_NAME') ? (string)SMTP_FROM_NAME : (getenv('SMTP_FROM_NAME') ?: ($sysConfig['smtp_from_name'] ?? ($sysConfig['mail_from_name'] ?? MAIL_FROM_NAME)));

    if ($fromEmail === '' && $smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $smtpUser;
    }

    return [
        'brevo_key' => trim((string)$brevoKey),
        'resend_key' => trim((string)$resendKey),
        'sendgrid_key' => trim((string)$sendgridKey),
        'smtp_host' => trim((string)$smtpHost),
        'smtp_port' => $smtpPort > 0 ? $smtpPort : 587,
        'smtp_user' => trim((string)$smtpUser),
        'smtp_pass' => trim((string)$smtpPass),
        'from_email' => trim((string)$fromEmail) ?: MAIL_FROM_ADDRESS,
        'from_name' => trim((string)$fromName) ?: MAIL_FROM_NAME,
    ];
}

/**
 * Log delivery events or errors for diagnostics.
 */
function log_otp_delivery_event(string $provider, string $message): void
{
    $logDir = __DIR__ . '/backups';
    @mkdir($logDir, 0700, true);
    @file_put_contents(
        $logDir . '/otp_mail_log.txt',
        date('[Y-m-d H:i:s]') . " [{$provider}] " . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Send real-time email via Brevo (Sendinblue) REST API.
 * Uses HTTPS Port 443 - free tier provides 300 emails/day.
 */
function send_via_brevo_api(
    string $apiKey,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): bool {
    $payload = json_encode([
        'sender' => ['name' => $fromName, 'email' => $fromEmail],
        'to' => [['email' => $toEmail, 'name' => $toName]],
        'subject' => $subject,
        'htmlContent' => $htmlBody,
        'textContent' => $textBody,
    ]);

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'accept: application/json',
            'api-key: ' . $apiKey,
            'content-type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        log_otp_delivery_event('Brevo API', "SUCCESS: OTP sent to {$toEmail} (HTTP {$httpCode})");
        return true;
    }

    log_otp_delivery_event('Brevo API', "FAILED: HTTP {$httpCode} - {$response} (cURL: {$curlErr})");
    return false;
}

/**
 * Send real-time email via Resend REST API.
 * Uses HTTPS Port 443 - free tier provides 3,000 emails/month.
 */
function send_via_resend_api(
    string $apiKey,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): bool {
    $from = $fromName !== '' ? "{$fromName} <{$fromEmail}>" : $fromEmail;
    $payload = json_encode([
        'from' => $from,
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody,
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        log_otp_delivery_event('Resend API', "SUCCESS: OTP sent to {$toEmail} (HTTP {$httpCode})");
        return true;
    }

    log_otp_delivery_event('Resend API', "FAILED: HTTP {$httpCode} - {$response} (cURL: {$curlErr})");
    return false;
}

/**
 * Send real-time email via SendGrid v3 REST API.
 * Uses HTTPS Port 443.
 */
function send_via_sendgrid_api(
    string $apiKey,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody
): bool {
    $payload = json_encode([
        'personalizations' => [[
            'to' => [['email' => $toEmail, 'name' => $toName]]
        ]],
        'from' => ['email' => $fromEmail, 'name' => $fromName],
        'subject' => $subject,
        'content' => [
            ['type' => 'text/plain', 'value' => $textBody],
            ['type' => 'text/html', 'value' => $htmlBody]
        ]
    ]);

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        log_otp_delivery_event('SendGrid API', "SUCCESS: OTP sent to {$toEmail} (HTTP {$httpCode})");
        return true;
    }

    log_otp_delivery_event('SendGrid API', "FAILED: HTTP {$httpCode} - {$response} (cURL: {$curlErr})");
    return false;
}

/**
 * Pure PHP SMTP Client over TLS/STARTTLS on Port 587 (RFC 5321).
 * Zero external libraries needed; works out of the box on InfinityFree and local servers.
 */
function send_via_smtp_socket(
    string $host,
    int $port,
    string $username,
    string $password,
    string $fromEmail,
    string $fromName,
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $textBody,
    int $timeout = 15
): bool {
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if (!$socket) {
        log_otp_delivery_event('SMTP Socket', "Connection failed to {$host}:{$port} - {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($socket, $timeout);

    $readResponse = static function () use ($socket): string {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 512);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $data;
    };

    $sendCommand = static function (string $cmd) use ($socket, $readResponse): string {
        fwrite($socket, $cmd . "\r\n");
        return $readResponse();
    };

    $response = $readResponse();
    if (!str_starts_with($response, '220')) {
        log_otp_delivery_event('SMTP Socket', "Unexpected greeting: " . trim($response));
        fclose($socket);
        return false;
    }

    $hello = $sendCommand("EHLO localhost");

    // Negotiate STARTTLS (Standard Port 587)
    if ($port === 587 || stripos($hello, 'STARTTLS') !== false) {
        $tlsResp = $sendCommand("STARTTLS");
        if (!str_starts_with($tlsResp, '220')) {
            log_otp_delivery_event('SMTP Socket', "STARTTLS rejected: " . trim($tlsResp));
            fclose($socket);
            return false;
        }

        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            log_otp_delivery_event('SMTP Socket', "TLS cryptographic handshake failed");
            fclose($socket);
            return false;
        }

        $sendCommand("EHLO localhost");
    }

    // Authenticate
    if ($username !== '' && $password !== '') {
        $authResp = $sendCommand("AUTH LOGIN");
        if (!str_starts_with($authResp, '334')) {
            log_otp_delivery_event('SMTP Socket', "AUTH LOGIN rejected: " . trim($authResp));
            fclose($socket);
            return false;
        }

        $userResp = $sendCommand(base64_encode($username));
        if (!str_starts_with($userResp, '334')) {
            log_otp_delivery_event('SMTP Socket', "Username rejected: " . trim($userResp));
            fclose($socket);
            return false;
        }

        $passResp = $sendCommand(base64_encode($password));
        if (!str_starts_with($passResp, '235')) {
            log_otp_delivery_event('SMTP Socket', "Password authentication rejected: " . trim($passResp));
            fclose($socket);
            return false;
        }
    }

    $mailResp = $sendCommand("MAIL FROM:<{$fromEmail}>");
    if (!str_starts_with($mailResp, '250')) {
        log_otp_delivery_event('SMTP Socket', "MAIL FROM failed: " . trim($mailResp));
        fclose($socket);
        return false;
    }

    $rcptResp = $sendCommand("RCPT TO:<{$toEmail}>");
    if (!str_starts_with($rcptResp, '250') && !str_starts_with($rcptResp, '251')) {
        log_otp_delivery_event('SMTP Socket', "RCPT TO failed for {$toEmail}: " . trim($rcptResp));
        fclose($socket);
        return false;
    }

    $dataResp = $sendCommand("DATA");
    if (!str_starts_with($dataResp, '354')) {
        log_otp_delivery_event('SMTP Socket', "DATA command failed: " . trim($dataResp));
        fclose($socket);
        return false;
    }

    $boundary = 'SHS_OTP_' . bin2hex(random_bytes(8));
    $headers = [
        "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <{$fromEmail}>",
        "To: =?UTF-8?B?" . base64_encode($toName) . "?= <{$toEmail}>",
        "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
        "Date: " . date(DATE_RFC2822),
        "MIME-Version: 1.0",
        "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
        "X-Priority: 1",
        "X-Mailer: SecureHealthOTP/2.0"
    ];

    $payload = implode("\r\n", $headers) . "\r\n\r\n";
    $payload .= "--{$boundary}\r\n";
    $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $payload .= chunk_split(base64_encode($textBody)) . "\r\n";
    $payload .= "--{$boundary}\r\n";
    $payload .= "Content-Type: text/html; charset=UTF-8\r\n";
    $payload .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $payload .= chunk_split(base64_encode($htmlBody)) . "\r\n";
    $payload .= "--{$boundary}--\r\n";
    $payload .= ".\r\n";

    fwrite($socket, $payload);
    $sendResp = $readResponse();

    $sendCommand("QUIT");
    fclose($socket);

    $isOk = str_starts_with($sendResp, '250');
    if ($isOk) {
        log_otp_delivery_event('SMTP Socket', "SUCCESS: OTP delivered to {$toEmail} via SMTP.");
    } else {
        log_otp_delivery_event('SMTP Socket', "Message sending failed: " . trim($sendResp));
    }

    return $isOk;
}

/**
 * Generate a 6-digit OTP, store its hash in the DB, and return the plaintext
 * code so it can be emailed to the user.
 */
function generate_email_otp(int $userId, PDO $pdo): string
{
    // Invalidate all outstanding tokens for this user.
    $pdo->prepare(
        "UPDATE email_otp_tokens
            SET used_at = NOW()
          WHERE user_id = ?
            AND used_at IS NULL"
    )->execute([$userId]);

    // Generate a cryptographically secure 6-digit OTP.
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $hash      = password_hash($otp, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + OTP_EXPIRY_SECONDS);

    $pdo->prepare(
        "INSERT INTO email_otp_tokens (user_id, token_hash, expires_at)
         VALUES (?, ?, ?)"
    )->execute([$userId, $hash, $expiresAt]);

    return $otp;
}

/**
 * Send the OTP email in real time.
 *
 * Priority Order:
 *   1. Brevo REST API (HTTPS port 443 - Recommended)
 *   2. Resend REST API (HTTPS port 443)
 *   3. SendGrid REST API (HTTPS port 443)
 *   4. Direct SMTP Socket (TLS Port 587)
 *   5. Native PHP mail() fallback
 *   6. Local dev mode log fallback
 */
function send_otp_email(string $toEmail, string $toName, string $otp): bool
{
    if (trim($toEmail) === '') {
        return false;
    }

    $subject = 'Your Secure Health Login Code';
    $expires = (int)(OTP_EXPIRY_SECONDS / 60);

    $body = <<<TEXT
Hello {$toName},

Your one-time login verification code is:

    {$otp}

This code expires in {$expires} minutes. Do not share it with anyone.

If you did not attempt to log in, please secure your account immediately.

— Secure Health System
TEXT;

    $html = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="font-family:Arial,sans-serif;background:#f5f5f5;padding:20px;color:#333;">
  <div style="max-width:480px;margin:0 auto;background:#fff;border-radius:10px;padding:32px;box-shadow:0 2px 10px rgba(0,0,0,.08);">
    <div style="text-align:center;margin-bottom:20px;">
      <span style="display:inline-block;background:#0d47a1;color:#fff;padding:8px 16px;border-radius:20px;font-weight:bold;font-size:14px;letter-spacing:1px;">SECURE HEALTH</span>
    </div>
    <h2 style="color:#0d47a1;margin-top:0;text-align:center;">🔐 Login Verification Code</h2>
    <p>Hello <strong>{$toName}</strong>,</p>
    <p>Use the following 6-digit one-time code to complete your sign-in:</p>
    <div style="font-size:38px;font-weight:bold;letter-spacing:12px;text-align:center;
                padding:22px;background:#e3f2fd;border-radius:8px;color:#0d47a1;margin:24px 0;">
      {$otp}
    </div>
    <p style="color:#555;font-size:14px;text-align:center;">This code expires in <strong>{$expires} minutes</strong>. Do not share this code with anyone.</p>
    <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
    <p style="color:#888;font-size:12px;text-align:center;">
      If you did not attempt to log in to Secure Health System, please change your password immediately.
    </p>
  </div>
</body>
</html>
HTML;

    $settings = get_mail_delivery_settings();

    // ── LOCAL DEV: skip all external APIs for instant OTP testing ───────────
    if (is_local_development_environment()) {
        $logDir  = __DIR__ . '/backups';
        $logFile = $logDir . '/otp_dev_log.txt';
        @mkdir($logDir, 0700, true);
        $entry = date('[Y-m-d H:i:s]') . " OTP for {$toEmail}: {$otp}\n";
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        $_SESSION['_dev_otp'] = $otp;
        return false;
    }

    // ── 1. BREVO REST API (Recommended for InfinityFree) ─────────────────────
    if ($settings['brevo_key'] !== '') {
        $from = $settings['from_email'];
        // Brevo requires a verified sender. Always use the registered Gmail if no verified custom domain.
        if (empty($from) || str_contains($from, 'rf.gd') || str_contains($from, 'localhost') || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $from = 'danielboatrng876@gmail.com';
        }

        $sent = send_via_brevo_api(
            $settings['brevo_key'],
            $from,
            $settings['from_name'],
            $toEmail,
            $toName,
            $subject,
            $html,
            $body
        );
        if ($sent) {
            unset($_SESSION['_dev_otp']);
            return true;
        }
        // If send failed, check if the reason is a permission scope issue and log a helpful message.
        // (send_via_brevo_api already logs the raw HTTP error; this adds a human-readable fix hint)
        log_otp_delivery_event(
            'Brevo API',
            'HINT: If the error above says "permission_denied" for transactional.email, go to ' .
            'app.brevo.com → Settings → API Keys → edit your key → tick "Transactional email" → save, ' .
            'then paste the updated key in the Admin Dashboard → System Configuration → Brevo API Key.'
        );
    }

    // ── 2. RESEND REST API ───────────────────────────────────────────────────
    if ($settings['resend_key'] !== '') {
        $from = $settings['from_email'];
        // Resend test sandbox sender if no custom domain yet
        if (str_contains($from, 'rf.gd') || str_contains($from, 'localhost')) {
            $from = 'onboarding@resend.dev';
        }
        $sent = send_via_resend_api(
            $settings['resend_key'],
            $from,
            $settings['from_name'],
            $toEmail,
            $toName,
            $subject,
            $html,
            $body
        );
        if ($sent) {
            unset($_SESSION['_dev_otp']);
            return true;
        }
    }

    // ── 3. SENDGRID REST API ─────────────────────────────────────────────────
    if ($settings['sendgrid_key'] !== '') {
        $sent = send_via_sendgrid_api(
            $settings['sendgrid_key'],
            $settings['from_email'],
            $settings['from_name'],
            $toEmail,
            $toName,
            $subject,
            $html,
            $body
        );
        if ($sent) {
            unset($_SESSION['_dev_otp']);
            return true;
        }
    }

    // ── 4. REAL-TIME SMTP DELIVERY (Port 587 TLS) ────────────────────────────
    if ($settings['smtp_host'] !== '' && $settings['smtp_user'] !== '' && $settings['smtp_pass'] !== '') {
        $sent = send_via_smtp_socket(
            $settings['smtp_host'],
            $settings['smtp_port'],
            $settings['smtp_user'],
            $settings['smtp_pass'],
            $settings['from_email'],
            $settings['from_name'],
            $toEmail,
            $toName,
            $subject,
            $html,
            $body
        );
        if ($sent) {
            unset($_SESSION['_dev_otp']);
            return true;
        }
    }

    // ── 5. PHP mail() FALLBACK ───────────────────────────────────────────────
    $boundary = 'OTP_' . bin2hex(random_bytes(8));
    $fromAddr = $settings['from_email'];
    $fromName = $settings['from_name'];
    $safeFrom = "{$fromName} <{$fromAddr}>";
    $safeTo   = "{$toName} <{$toEmail}>";

    $headers  = "From: {$safeFrom}\r\n";
    $headers .= "Reply-To: {$fromAddr}\r\n";
    $headers .= "To: {$safeTo}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

    $message  = "--{$boundary}\r\n";
    $message .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
    $message .= $body . "\r\n";
    $message .= "--{$boundary}\r\n";
    $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
    $message .= $html . "\r\n";
    $message .= "--{$boundary}--\r\n";

    $sent = @mail($toEmail, $subject, $message, $headers);
    if ($sent) {
        unset($_SESSION['_dev_otp']);
        return true;
    }

    // ── 6. LOCAL DEV MODE FALLBACK ───────────────────────────────────────────
    // Keep the OTP visible in non-production environments only.
    if (is_local_development_environment()) {
        $logDir  = __DIR__ . '/backups';
        $logFile = $logDir . '/otp_dev_log.txt';
        @mkdir($logDir, 0700, true);
        $entry = date('[Y-m-d H:i:s]') . " OTP for {$toEmail}: {$otp}\n";
        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        $_SESSION['_dev_otp'] = $otp;
        return false;
    }

    return false;
}

/**
 * Verify a user-supplied OTP against the stored hash.
 * Returns true on success (and marks the token used).
 * Returns false on failure (wrong code, expired, already used).
 */
function verify_email_otp(int $userId, string $enteredOtp, PDO $pdo): bool
{
    // Fetch the most recent unused, unexpired token for this user.
    $stmt = $pdo->prepare(
        "SELECT id, token_hash
           FROM email_otp_tokens
          WHERE user_id   = ?
            AND used_at   IS NULL
            AND expires_at > NOW()
          ORDER BY created_at DESC
          LIMIT 1"
    );
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return false;
    }

    if (!password_verify($enteredOtp, $row['token_hash'])) {
        return false;
    }

    // Mark the token as used.
    $pdo->prepare(
        "UPDATE email_otp_tokens SET used_at = NOW() WHERE id = ?"
    )->execute([$row['id']]);

    return true;
}
