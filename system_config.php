<?php

declare(strict_types=1);

function get_default_config(): array
{
    return [
        'app_name' => 'Secure Health Management System',
        'require_2fa' => false,
        'session_timeout' => 1800,
        'max_login_attempts' => 5,
        'lockout_minutes' => 15,
        'timezone' => 'Africa/Accra',
        'backup_destination_url' => 'https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home',
        'cloudflare_account_id' => 'bf59619b0589ac8a0c46f818580a4755',
        'brevo_api_key' => '',
        'resend_api_key' => '',
        'sendgrid_api_key' => '',
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_user' => '',
        'smtp_pass' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => 'Secure Health System'
    ];
}

function get_system_config(): array
{
    $configFile = __DIR__ . '/system_config.json';
    $defaults = get_default_config();

    if (!file_exists($configFile)) {
        file_put_contents($configFile, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $defaults;
    }

    $raw = @file_get_contents($configFile);
    if ($raw === false || trim($raw) === '') {
        return $defaults;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        return $defaults;
    }

    return array_merge($defaults, $parsed);
}

function save_system_config(array $updates): bool
{
    $configFile = __DIR__ . '/system_config.json';
    $current = get_system_config();

    $clean = $current;
    if (isset($updates['app_name']) && is_string($updates['app_name'])) {
        $name = trim($updates['app_name']);
        if ($name !== '' && strlen($name) <= 120) {
            $clean['app_name'] = $name;
        }
    }

    if (isset($updates['require_2fa'])) {
        $clean['require_2fa'] = (bool)$updates['require_2fa'];
    }

    if (isset($updates['session_timeout'])) {
        $timeout = (int)$updates['session_timeout'];
        if ($timeout >= 60 && $timeout <= 86400) {
            $clean['session_timeout'] = $timeout;
        }
    }

    if (isset($updates['max_login_attempts'])) {
        $attempts = (int)$updates['max_login_attempts'];
        if ($attempts >= 1 && $attempts <= 20) {
            $clean['max_login_attempts'] = $attempts;
        }
    }

    if (isset($updates['lockout_minutes'])) {
        $lockout = (int)$updates['lockout_minutes'];
        if ($lockout >= 1 && $lockout <= 1440) {
            $clean['lockout_minutes'] = $lockout;
        }
    }

    if (isset($updates['timezone']) && is_string($updates['timezone'])) {
        if (in_array($updates['timezone'], timezone_identifiers_list(), true)) {
            $clean['timezone'] = $updates['timezone'];
        }
    }

    if (isset($updates['backup_destination_url']) && is_string($updates['backup_destination_url'])) {
        $dest = trim($updates['backup_destination_url']);
        if (filter_var($dest, FILTER_VALIDATE_URL) && strlen($dest) <= 255) {
            $clean['backup_destination_url'] = $dest;
        }
    }

    if (isset($updates['cloudflare_account_id']) && is_string($updates['cloudflare_account_id'])) {
        $clean['cloudflare_account_id'] = trim($updates['cloudflare_account_id']);
    }

    foreach (['brevo_api_key', 'resend_api_key', 'sendgrid_api_key', 'smtp_host', 'smtp_user', 'smtp_pass', 'smtp_from_email', 'smtp_from_name'] as $sKey) {
        if (isset($updates[$sKey]) && is_string($updates[$sKey])) {
            $clean[$sKey] = trim($updates[$sKey]);
        }
    }
    if (isset($updates['smtp_port'])) {
        $port = (int)$updates['smtp_port'];
        if ($port > 0 && $port <= 65535) {
            $clean['smtp_port'] = $port;
        }
    }

    $written = @file_put_contents(
        $configFile,
        json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );

    return $written !== false;
}

function create_system_restore_point(PDO $pdo, string $name, ?string $description = null, ?int $userId = null): int
{
    $currentConfig = get_system_config();
    $configJson = json_encode($currentConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare(
        'INSERT INTO system_restore_points (name, description, configuration, created_by)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $name !== '' ? $name : 'Restore Point ' . date('Y-m-d H:i:s'),
        $description,
        $configJson,
        $userId
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * Restores system configuration from a restore point.
 * NOTE: This strictly restores configuration parameters only.
 * Application data (patients, invoices, payments, users, records) is NEVER modified.
 */
function restore_system_configuration(PDO $pdo, int $restorePointId, ?int $userId = null): array
{
    $stmt = $pdo->prepare('SELECT * FROM system_restore_points WHERE id = ?');
    $stmt->execute([$restorePointId]);
    $point = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$point) {
        throw new RuntimeException('Selected restore point does not exist.');
    }

    $config = json_decode((string)$point['configuration'], true);
    if (!is_array($config)) {
        throw new RuntimeException('Restore point contains invalid configuration data.');
    }

    // Save only system configuration (pure config restore, data is completely untouched)
    if (!save_system_config($config)) {
        throw new RuntimeException('Could not apply the restored configuration file.');
    }

    return [
        'id' => (int)$point['id'],
        'name' => (string)$point['name'],
        'created_at' => (string)$point['created_at'],
        'config' => $config
    ];
}

function ensure_initial_restore_point(PDO $pdo, ?int $userId = null): void
{
    try {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM system_restore_points')->fetchColumn();
        if ($count === 0) {
            create_system_restore_point(
                $pdo,
                'Initial System Baseline',
                'Automatic initial configuration restore point captured during setup.',
                $userId
            );
        }
    } catch (Throwable) {
        // Table might not exist yet during fresh install
    }
}

