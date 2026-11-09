<?php
/**
 * test_mail.php
 * Diagnostic tool to verify real-time email delivery via API (Brevo/Resend) or SMTP.
 *
 * Usage via CLI:
 *   php test_mail.php recipient@example.com
 */

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/email_otp.php';

// Allow CLI or logged-in admin
$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    require_role('admin');
}

$recipient = '';
if ($isCli) {
    $recipient = $argv[1] ?? '';
    if ($recipient === '') {
        echo "Usage: php test_mail.php <email_address>\n";
        exit(1);
    }
} else {
    $recipient = trim((string)($_GET['email'] ?? ''));
    if ($recipient === '') {
        echo "Please specify ?email=your_address@gmail.com in URL\n";
        exit;
    }
}

echo "=== Secure Health System: Email Delivery Diagnostic ===\n";
echo "Target Recipient: " . htmlspecialchars($recipient) . "\n\n";

$settings = get_mail_delivery_settings();
echo "Active Configuration:\n";
echo "- Brevo API Key: " . ($settings['brevo_key'] !== '' ? substr($settings['brevo_key'], 0, 10) . '...' : '(Not set)') . "\n";
echo "- Resend API Key: " . ($settings['resend_key'] !== '' ? substr($settings['resend_key'], 0, 8) . '...' : '(Not set)') . "\n";
echo "- SendGrid API Key: " . ($settings['sendgrid_key'] !== '' ? substr($settings['sendgrid_key'], 0, 8) . '...' : '(Not set)') . "\n";
echo "- SMTP Host: " . ($settings['smtp_host'] ?: '(Not set)') . ":" . $settings['smtp_port'] . "\n";
echo "- SMTP User: " . ($settings['smtp_user'] ?: '(Not set)') . "\n";
echo "- From Email: " . $settings['from_email'] . " (" . $settings['from_name'] . ")\n\n";

echo "Dispatching test 6-digit OTP code...\n";
$testCode = (string)random_int(100000, 999999);
$sent = send_otp_email($recipient, 'Test User', $testCode);

if ($sent) {
    echo "SUCCESS! OTP message accepted for immediate delivery.\n";
    echo "Check the inbox (and Spam/Junk folder) for '{$recipient}'.\n";
} else {
    echo "FAILED: Could not dispatch email.\n";
    $logFile = __DIR__ . '/backups/otp_mail_log.txt';
    if (file_exists($logFile)) {
        echo "\nLast 5 log entries from backups/otp_mail_log.txt:\n";
        $lines = array_slice(file($logFile), -5);
        echo implode('', $lines);
    }
}

