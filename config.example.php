<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| DATABASE SETTINGS
|--------------------------------------------------------------------------
*/

const DB_HOST = '127.0.0.1';
const DB_NAME = 'secure_health_system';
const DB_USER = 'root';
const DB_PASS = '';

/*
|--------------------------------------------------------------------------
| APPLICATION SETTINGS & CONFIGURATION
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/system_config.php';
$systemConfig = get_system_config();

if (!defined('APP_NAME')) {
    define('APP_NAME', (string)($systemConfig['app_name'] ?? 'Secure Health Management System'));
}

if (!defined('REQUIRE_2FA')) {
    define('REQUIRE_2FA', (bool)($systemConfig['require_2fa'] ?? false));
}

/*
|--------------------------------------------------------------------------
| PASSWORD PEPPER
|--------------------------------------------------------------------------
|
| Change this value to a strong, unique secret string before using the system.
|
*/

const APP_PEPPER = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_KEY';

/*
|--------------------------------------------------------------------------
| SESSION SETTINGS
|--------------------------------------------------------------------------
*/

if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', (int)($systemConfig['session_timeout'] ?? 1800));
}

/*
|--------------------------------------------------------------------------
| EMAIL DELIVERY SETTINGS (API or SMTP)
|--------------------------------------------------------------------------
*/

if (!defined('BREVO_API_KEY')) {
    define('BREVO_API_KEY', (string)($systemConfig['brevo_api_key'] ?? ''));
}
if (!defined('RESEND_API_KEY')) {
    define('RESEND_API_KEY', (string)($systemConfig['resend_api_key'] ?? ''));
}
if (!defined('SENDGRID_API_KEY')) {
    define('SENDGRID_API_KEY', (string)($systemConfig['sendgrid_api_key'] ?? ''));
}

if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', (string)($systemConfig['smtp_host'] ?? 'smtp.gmail.com'));
}
if (!defined('SMTP_PORT')) {
    define('SMTP_PORT', (int)($systemConfig['smtp_port'] ?? 587));
}
if (!defined('SMTP_USER')) {
    define('SMTP_USER', (string)($systemConfig['smtp_user'] ?? ''));
}
if (!defined('SMTP_PASS')) {
    define('SMTP_PASS', (string)($systemConfig['smtp_pass'] ?? ''));
}
if (!defined('SMTP_FROM_EMAIL')) {
    define('SMTP_FROM_EMAIL', (string)($systemConfig['smtp_from_email'] ?? ($systemConfig['mail_from_email'] ?? '')));
}
if (!defined('SMTP_FROM_NAME')) {
    define('SMTP_FROM_NAME', (string)($systemConfig['smtp_from_name'] ?? ($systemConfig['mail_from_name'] ?? 'Secure Health System')));
}

/*
|--------------------------------------------------------------------------
| LOGIN SECURITY & BACKUPS
|--------------------------------------------------------------------------
*/

if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', (int)($systemConfig['max_login_attempts'] ?? 5));
}

if (!defined('LOCKOUT_MINUTES')) {
    define('LOCKOUT_MINUTES', (int)($systemConfig['lockout_minutes'] ?? 15));
}

if (!defined('BACKUP_DESTINATION_URL')) {
    define('BACKUP_DESTINATION_URL', (string)($systemConfig['backup_destination_url'] ?? ''));
}

/*
|--------------------------------------------------------------------------
| TIMEZONE
|--------------------------------------------------------------------------
*/

date_default_timezone_set((string)($systemConfig['timezone'] ?? 'Africa/Accra'));

/*
|--------------------------------------------------------------------------
| SECURE SESSION COOKIE
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}
