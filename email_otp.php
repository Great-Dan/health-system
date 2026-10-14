<?php
/**
 * email_otp.php
 * ─────────────────────────────────────────────────────────────────────────────
 * Email OTP utility: generate, send, and verify one-time passwords for the
 * two-step login flow.
 *
 * Supports Real-Time Outbound SMTP via Port 587 (TLS/STARTTLS) for instant
 * delivery to real email inboxes (e.g. Gmail, Brevo, Outlook, etc.).
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
 * Retrieve SMTP configuration from constants, environment variables, or system_config.json.
 */
function get_smtp_settings(): array
{
    $sysConfig = [];
    if (file_exists(__DIR__ . '/system_config.php')) {
        require_once __DIR__ . '/system_config.php';
        if (function_exists('get_system_config')) {
            $sysConfig = get_system_config();
        }
    }

    $host = defined('SMTP_HOST') ? (string)SMTP_HOST : (getenv('SMTP_HOST') ?: ($sysConfig['smtp_host'] ?? ''));
    $port = defined('SMTP_PORT') ? (int)SMTP_PORT : (int)(getenv('SMTP_PORT') ?: ($sysConfig['smtp_port'] ?? 587));
    $user = defined('SMTP_USER') ? (string)SMTP_USER : (getenv('SMTP_USER') ?: ($sysConfig['smtp_user'] ?? ''));
    $pass = defined('SMTP_PASS') ? (string)SMTP_PASS : (getenv('SMTP_PASS') ?: ($sysConfig['smtp_pass'] ?? ''));
    $fromEmail = defined('SMTP_FROM_EMAIL') ? (string)SMTP_FROM_EMAIL : (getenv('SMTP_FROM_EMAIL') ?: ($sysConfig['smtp_from_email'] ?? ''));
    $fromName = defined('SMTP_FROM_NAME') ? (string)SMTP_FROM_NAME : (getenv('SMTP_FROM_NAME') ?: ($sysConfig['smtp_from_name'] ?? MAIL_FROM_NAME));

    if ($fromEmail === '' && $user !== '' && filter_var($user, FILTER_VALIDATE_EMAIL)) {
        $fromEmail = $user;
    }

    return [
        'host' => trim((string)$host),
        'port' => $port > 0 ? $port : 587,
        'user' => trim((string)$user),
        'pass' => trim((string)$pass),
        'from_email' => trim((string)$fromEmail) ?: MAIL_FROM_ADDRESS,
        'from_name' => trim((string)$fromName) ?: MAIL_FROM_NAME,
    ];
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
    $errLog = function (string $msg) use ($host, $port) {
        $logDir = __DIR__ . '/backups';
        @mkdir($logDir, 0700, true);
        @file_put_contents(
            $logDir . '/otp_smtp_log.txt',
            date('[Y-m-d H:i:s]') . " [SMTP {$host}:{$port}] " . $msg . "\n",
            FILE_APPEND | LOCK_EX
        );
    };

    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
    if (!$socket) {
        $errLog("Connection failed: {$errstr} ({$errno})");
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
        $errLog("Unexpected greeting: " . trim($response));
        fclose($socket);
        return false;
    }

    $hello = $sendCommand("EHLO localhost");

    // Negotiate STARTTLS (Standard Port 587)
    if ($port === 587 || stripos($hello, 'STARTTLS') !== false) {
        $tlsResp = $sendCommand("STARTTLS");
        if (!str_starts_with($tlsResp, '220')) {
            $errLog("STARTTLS rejected: " . trim($tlsResp));
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
            $errLog("TLS cryptographic handshake failed");
            fclose($socket);
            return false;
        }

        $sendCommand("EHLO localhost");
    }

    // Authenticate
    if ($username !== '' && $password !== '') {
        $authResp = $sendCommand("AUTH LOGIN");
        if (!str_starts_with($authResp, '334')) {
            $errLog("AUTH LOGIN rejected: " . trim($authResp));
            fclose($socket);
            return false;
        }

        $userResp = $sendCommand(base64_encode($username));
        if (!str_starts_with($userResp, '334')) {
            $errLog("Username rejected: " . trim($userResp));
            fclose($socket);
            return false;
        }

        $passResp = $sendCommand(base64_encode($password));
        if (!str_starts_with($passResp, '235')) {
            $errLog("Password authentication rejected: " . trim($passResp));
            fclose($socket);
            return false;
        }
    }

    $mailResp = $sendCommand("MAIL FROM:<{$fromEmail}>");
    if (!str_starts_with($mailResp, '250')) {
        $errLog("MAIL FROM failed: " . trim($mailResp));
        fclose($socket);
        return false;
    }

    $rcptResp = $sendCommand("RCPT TO:<{$toEmail}>");
    if (!str_starts_with($rcptResp, '250') && !str_starts_with($rcptResp, '251')) {
        $errLog("RCPT TO failed for {$toEmail}: " . trim($rcptResp));
        fclose($socket);
        return false;
    }

    $dataResp = $sendCommand("DATA");
    if (!str_starts_with($dataResp, '354')) {
        $errLog("DATA command failed: " . trim($dataResp));
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
        $errLog("SUCCESS: OTP delivered to {$toEmail} via SMTP.");
    } else {
        $errLog("Message sending failed: " . trim($sendResp));
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
 * Send the OTP email.
 * 1. Attempts direct Real-Time SMTP over Port 587 if configured.
 * 2. Falls back to PHP mail().
 * 3. Falls back to DEV MODE local log if mail() fails.
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

    // ── 1. REAL-TIME SMTP DELIVERY ───────────────────────────────────────────
    $smtp = get_smtp_settings();
    if (!empty($smtp['host']) && !empty($smtp['user']) && !empty($smtp['pass'])) {
        $sentViaSmtp = send_via_smtp_socket(
            $smtp['host'],
            $smtp['port'],
            $smtp['user'],
            $smtp['pass'],
            $smtp['from_email'],
            $smtp['from_name'],
            $toEmail,
            $toName,
            $subject,
            $html,
            $body
        );

        if ($sentViaSmtp) {
            unset($_SESSION['_dev_otp']);
            return true;
        }
    }

    // ── 2. PHP mail() FALLBACK ───────────────────────────────────────────────
    $boundary = 'OTP_' . bin2hex(random_bytes(8));
    $fromAddr = $smtp['from_email'];
    $fromName = $smtp['from_name'];
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

    // ── 3. LOCAL DEV MODE FALLBACK ───────────────────────────────────────────
    $logDir  = __DIR__ . '/backups';
    $logFile = $logDir . '/otp_dev_log.txt';
    @mkdir($logDir, 0700, true);
    $entry = date('[Y-m-d H:i:s]') . " OTP for {$toEmail}: {$otp}\n";
    @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    $_SESSION['_dev_otp'] = $otp;

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
