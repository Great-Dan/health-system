<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/system_config.php';

const BACKUP_CHUNK_SIZE = 65536;
const BACKUP_FORMAT_MAGIC = "SHSBACKUP2";
const CLOUDFLARE_DASHBOARD_URL = "https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home";
const CLOUDFLARE_DEFAULT_ACCOUNT_ID = "bf59619b0589ac8a0c46f818580a4755";

function backup_required_environment(string $name, ?string $fallback = null): string
{
    $value = getenv($name);
    if ($value === false || trim($value) === '') {
        if ($fallback !== null) {
            return $fallback;
        }
        throw new RuntimeException("Required environment variable is not set: {$name}");
    }

    return $value;
}

function backup_write_all($stream, string $data): void
{
    $offset = 0;
    $length = strlen($data);

    while ($offset < $length) {
        $written = fwrite($stream, substr($data, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write the backup file.');
        }
        $offset += $written;
    }
}

function backup_dump_database(PDO $pdo, string $destination): void
{
    $pdo->beginTransaction();
    $file = fopen($destination, 'wb');
    if ($file === false) {
        throw new RuntimeException('Could not create the database export.');
    }

    try {
        backup_write_all(
            $file,
            "-- Database export for " . DB_NAME . "\n-- Destination: " . CLOUDFLARE_DASHBOARD_URL . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n"
        );

        $objects = $pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);
        $tables = [];

        foreach (['TABLE', 'VIEW'] as $typeToCreate) {
            foreach ($objects as $object) {
                $name = (string)$object[0];
                $type = strtoupper((string)$object[1]) === 'VIEW' ? 'VIEW' : 'TABLE';
                if ($type !== $typeToCreate) {
                    continue;
                }

                $identifier = '`' . str_replace('`', '``', $name) . '`';
                $create = $pdo->query("SHOW CREATE {$type} {$identifier}")->fetch(PDO::FETCH_NUM);
                if (!isset($create[1])) {
                    throw new RuntimeException("Could not read the definition for database object: {$name}");
                }

                backup_write_all($file, "DROP {$type} IF EXISTS {$identifier};\n{$create[1]};\n\n");
                $tables[] = ['name' => $name, 'type' => $type];
            }
        }

        foreach ($tables as $table) {
            if ($table['type'] !== 'TABLE') {
                continue;
            }

            $identifier = '`' . str_replace('`', '``', $table['name']) . '`';
            $columnDefinitions = $pdo->query("SHOW COLUMNS FROM {$identifier}")->fetchAll(PDO::FETCH_ASSOC);
            $columns = array_column($columnDefinitions, 'Field');
            $quotedColumns = array_map(
                static fn(string $column): string => '`' . str_replace('`', '``', $column) . '`',
                $columns
            );
            $rows = $pdo->query("SELECT * FROM {$identifier}");

            while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                $values = [];
                foreach ($row as $index => $value) {
                    if ($value === null) {
                        $values[] = 'NULL';
                    } else {
                        $values[] = $pdo->quote((string)$value);
                    }
                }

                backup_write_all(
                    $file,
                    "INSERT INTO {$identifier} (" . implode(', ', $quotedColumns) . ') VALUES (' . implode(', ', $values) . ");\n"
                );
            }
            backup_write_all($file, "\n");
        }

        backup_write_all($file, "SET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fclose($file);
        throw $exception;
    }

    fclose($file);
}

function backup_excluded_path(string $relativePath): bool
{
    $segments = explode('/', strtolower(str_replace('\\', '/', $relativePath)));
    foreach ($segments as $segment) {
        if (in_array($segment, ['.git', '.github', '.expo', 'node_modules', 'vendor', 'backups', 'scratch', 'storage'], true)) {
            return true;
        }
        if (
            $segment === 'config.php'
            || str_starts_with($segment, '.env')
            || preg_match('/(?:service-account|credentials|client_secret|oauth|token).*\.json$/', $segment) === 1
            || preg_match('/\.(pem|key|p12|enc|log|tmp|tar|gz|zip|7z|sql)$/', $segment) === 1
        ) {
            return true;
        }
    }

    return false;
}

function backup_build_archive(string $rootDirectory, string $sqlPath, string $destinationTar): string
{
    if (file_exists($destinationTar)) {
        unlink($destinationTar);
    }
    $gzipPath = $destinationTar . '.gz';
    if (file_exists($gzipPath)) {
        unlink($gzipPath);
    }

    $archive = new PharData($destinationTar);
    $archive->addFile($sqlPath, 'database.sql');

    $systemConfigFile = $rootDirectory . DIRECTORY_SEPARATOR . 'system_config.json';
    if (file_exists($systemConfigFile)) {
        $archive->addFile($systemConfigFile, 'system_config.json');
    }

    $root = realpath($rootDirectory);
    if ($root === false) {
        throw new RuntimeException('Could not resolve the application directory.');
    }

    $directoryIterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $filteredIterator = new RecursiveCallbackFilterIterator(
        $directoryIterator,
        static function (SplFileInfo $entry) use ($root): bool {
            $relativePath = substr($entry->getPathname(), strlen($root) + 1);
            return !backup_excluded_path(str_replace('\\', '/', $relativePath));
        }
    );
    $iterator = new RecursiveIteratorIterator($filteredIterator);

    foreach ($iterator as $entry) {
        $path = $entry->getPathname();
        if ($entry->isLink()) {
            continue;
        }
        $relativePath = substr($path, strlen($root) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        if (backup_excluded_path($relativePath)) {
            continue;
        }
        if ($entry->isFile()) {
            $archive->addFile($path, $relativePath);
        }
    }

    unset($archive);
    $tarArchive = new PharData($destinationTar);
    $compressed = $tarArchive->compress(Phar::GZ);
    unset($tarArchive, $compressed);
    unlink($destinationTar);

    if (!file_exists($gzipPath)) {
        throw new RuntimeException('Could not create the compressed backup.');
    }

    return $gzipPath;
}

function backup_encrypt_file(string $sourceFile, string $destinationFile, string $base64Key): void
{
    $key = base64_decode($base64Key, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be a valid base64-encoded string representing exactly 32 random bytes.');
    }

    $input = fopen($sourceFile, 'rb');
    if ($input === false) {
        throw new RuntimeException('Could not open the archive for encryption.');
    }

    $output = fopen($destinationFile, 'wb');
    if ($output === false) {
        fclose($input);
        throw new RuntimeException('Could not create the encrypted backup.');
    }

    backup_write_all($output, BACKUP_FORMAT_MAGIC);
    $ivPrefix = random_bytes(4);
    backup_write_all($output, $ivPrefix);

    $sequence = 0;
    while (!feof($input)) {
        $plaintext = fread($input, BACKUP_CHUNK_SIZE);
        if ($plaintext === false) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('Failed reading plaintext backup data.');
        }

        if ($plaintext === '') {
            break;
        }

        $iv = $ivPrefix . pack('N', $sequence);
        $isFinal = feof($input);
        $aad = BACKUP_FORMAT_MAGIC . pack('N', $sequence) . ($isFinal ? 'FINAL' : '');

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad,
            16
        );

        if ($ciphertext === false || strlen($tag) !== 16) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('Encryption failed.');
        }

        $chunkHeader = pack('N', strlen($ciphertext)) . pack('C', $isFinal ? 1 : 0);
        backup_write_all($output, $chunkHeader . $tag . $ciphertext);
        $sequence++;
    }

    fclose($input);
    fclose($output);
}

function backup_decrypt_file(string $sourceFile, string $destinationFile, string $base64Key): void
{
    $key = base64_decode($base64Key, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('BACKUP_ENCRYPTION_KEY must be a valid base64-encoded string representing exactly 32 random bytes.');
    }

    $input = fopen($sourceFile, 'rb');
    if ($input === false) {
        throw new RuntimeException('Could not open the encrypted backup.');
    }

    $magic = fread($input, strlen(BACKUP_FORMAT_MAGIC));
    if ($magic !== BACKUP_FORMAT_MAGIC) {
        fclose($input);
        throw new RuntimeException('The file is not a supported backup archive.');
    }

    $ivPrefix = fread($input, 4);
    if (strlen($ivPrefix) !== 4) {
        fclose($input);
        throw new RuntimeException('Invalid backup archive.');
    }

    $output = fopen($destinationFile, 'wb');
    if ($output === false) {
        fclose($input);
        throw new RuntimeException('Could not create decrypted output file.');
    }

    $sequence = 0;
    $completed = false;

    while (!feof($input)) {
        $header = fread($input, 5);
        if ($header === false || $header === '') {
            break;
        }

        $unpacked = unpack('Nlength/CisFinal', $header);
        $length = $unpacked['length'];
        $isFinal = $unpacked['isFinal'] === 1;

        $tag = fread($input, 16);
        $ciphertext = fread($input, $length);

        $iv = $ivPrefix . pack('N', $sequence);
        $aad = BACKUP_FORMAT_MAGIC . pack('N', $sequence) . ($isFinal ? 'FINAL' : '');

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );

        if ($plaintext === false) {
            fclose($input);
            fclose($output);
            throw new RuntimeException('Decryption or authentication failed.');
        }

        backup_write_all($output, $plaintext);
        $sequence++;
        if ($isFinal) {
            $completed = true;
            break;
        }
    }

    fclose($input);
    fclose($output);

    if (!$completed) {
        throw new RuntimeException('The encrypted backup appears incomplete.');
    }
}

function backup_remove_temporary_files(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($directory);
}

// -------------------------------------------------------------
// MAIN CLI RUNNER
// -------------------------------------------------------------
if ($argc > 1) {
    if ($argv[1] === '--decrypt') {
        if ($argc !== 4) {
            fwrite(STDERR, "Usage: php cloudflare_backup.php --decrypt <backup.enc> <output.tar.gz>\n");
            exit(1);
        }
        $key = backup_required_environment('BACKUP_ENCRYPTION_KEY');
        backup_decrypt_file($argv[2], $argv[3], $key);
        echo "Backup decrypted successfully. Verify contents before extracting.\n";
        exit(0);
    }
}

echo "=== Secure Health System - Cloudflare Backup Engine ===\n";
echo "Target Cloudflare Dashboard: " . CLOUDFLARE_DASHBOARD_URL . "\n";
echo "Cloudflare Account ID: " . CLOUDFLARE_DEFAULT_ACCOUNT_ID . "\n\n";

try {
    $encryptionKey = getenv('BACKUP_ENCRYPTION_KEY');
    if ($encryptionKey === false || trim($encryptionKey) === '') {
        // Fallback default key for local dev backup if not set
        $encryptionKey = base64_encode(hash('sha256', 'secure_health_cloudflare_backup_key', true));
    }

    $workingDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'secure-health-cf-' . bin2hex(random_bytes(8));
    if (!mkdir($workingDirectory, 0700, true) && !is_dir($workingDirectory)) {
        throw new RuntimeException('Could not create temporary working directory.');
    }

    $sqlPath = $workingDirectory . DIRECTORY_SEPARATOR . 'database.sql';
    $tarPath = $workingDirectory . DIRECTORY_SEPARATOR . 'backup.tar';
    $timestamp = date('Y-m-d_H-i-s');
    $fileName = 'secure-health-backup-' . $timestamp . '.tar.gz.enc';

    $destDirectory = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($destDirectory)) {
        mkdir($destDirectory, 0750, true);
    }
    $encryptedPath = $destDirectory . DIRECTORY_SEPARATOR . $fileName;

    echo "1. Exporting database and system records...\n";
    backup_dump_database(db(), $sqlPath);

    echo "2. Building compressed application and data archive...\n";
    $gzipPath = backup_build_archive(__DIR__, $sqlPath, $tarPath);

    echo "3. Encrypting archive with AES-256-GCM...\n";
    backup_encrypt_file($gzipPath, $encryptedPath, $encryptionKey);

    $fileSize = filesize($encryptedPath);
    echo "4. Encrypted backup ready:\n";
    echo "   File: {$fileName} (" . round($fileSize / 1024, 2) . " KB)\n";
    echo "   Path: {$encryptedPath}\n";

    // Track in system_backups table
    $stmt = db()->prepare(
        'INSERT INTO system_backups (filename, filepath, filesize, destination)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $fileName,
        $encryptedPath,
        $fileSize,
        CLOUDFLARE_DASHBOARD_URL
    ]);

    backup_remove_temporary_files($workingDirectory);

    echo "\nBackup complete!\n";
    echo "Access your Cloudflare dashboard to store or manage backup objects:\n";
    echo "URL: " . CLOUDFLARE_DASHBOARD_URL . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Cloudflare backup failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

