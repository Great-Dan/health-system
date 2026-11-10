<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} elseif (file_exists(__DIR__ . '/config.example.php')) {
    require_once __DIR__ . '/config.example.php';
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (getenv('VERCEL') !== false) {
        $missing = [];
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $requiredSetting) {
            $value = getenv($requiredSetting);
            if ($value === false || trim($value) === '') {
                $missing[] = $requiredSetting;
            }
        }

        if ($missing !== []) {
            error_log('Database configuration missing on Vercel: ' . implode(', ', $missing));
            throw new RuntimeException('The database is not configured for this deployment.');
        }
    }

    $host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
    $port = getenv('DB_PORT') ?: (defined('DB_PORT') ? (string)DB_PORT : '3306');
    $name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'secure_health_system');
    $user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
    $pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (defined('DB_PASS') ? DB_PASS : '');
    $ssl  = getenv('DB_SSL') ?: (defined('DB_SSL') ? DB_SSL : false);

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($ssl && ($ssl === true || $ssl === 'true' || $ssl === '1')) {
        $caPaths = [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/etc/ssl/cert.pem',
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
            'C:/xampp/php/extras/ssl/cacert.pem',
        ];
        foreach ($caPaths as $ca) {
            if (file_exists($ca)) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
                break;
            }
        }
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }

    $pdo = new PDO($dsn, $user, $pass, $options);

    return $pdo;
}
