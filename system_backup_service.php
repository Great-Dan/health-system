<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/system_config.php';

function generate_system_backup(PDO $pdo, ?int $userId = null): array
{
    $backupDir = __DIR__ . '/backups';
    if (!is_dir($backupDir)) {
        if (!mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
            throw new RuntimeException('Could not create the backups directory.');
        }
        @file_put_contents($backupDir . '/.htaccess', "Require all denied\n");
    }

    $timestamp = date('Y-m-d_H-i-s');
    $filename = 'secure_health_backup_' . $timestamp . '.sql';
    $filepath = $backupDir . '/' . $filename;

    $file = fopen($filepath, 'wb');
    if ($file === false) {
        throw new RuntimeException('Could not open file to save backup.');
    }

    try {
        $config = get_system_config();
        $configComment = "-- System Configuration Snapshot:\n-- " . json_encode($config) . "\n\n";

        fwrite($file, "-- ========================================================\n");
        fwrite($file, "-- Secure Health Management System - Full System Backup\n");
        fwrite($file, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($file, "-- Cloudflare Storage: " . ($config['backup_destination_url'] ?? 'https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home') . "\n");
        fwrite($file, "-- ========================================================\n\n");
        fwrite($file, "SET NAMES utf8mb4;\n");
        fwrite($file, "SET FOREIGN_KEY_CHECKS=0;\n\n");
        fwrite($file, $configComment);

        $tables = $pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);
        $totalRecords = 0;

        foreach ($tables as $tableRow) {
            $tableName = (string)$tableRow[0];
            // Skip the backups tracking table itself during dump or include it
            $identifier = '`' . str_replace('`', '``', $tableName) . '`';

            $createTable = $pdo->query("SHOW CREATE TABLE {$identifier}")->fetch(PDO::FETCH_NUM);
            if (!isset($createTable[1])) {
                continue;
            }

            fwrite($file, "-- --------------------------------------------------------\n");
            fwrite($file, "-- Table structure for {$identifier}\n");
            fwrite($file, "-- --------------------------------------------------------\n");
            fwrite($file, "DROP TABLE IF EXISTS {$identifier};\n");
            fwrite($file, $createTable[1] . ";\n\n");

            // Dump data
            $rows = $pdo->query("SELECT * FROM {$identifier}");
            $countStmt = $pdo->query("SELECT COUNT(*) FROM {$identifier}");
            $rowCount = (int)$countStmt->fetchColumn();
            $totalRecords += $rowCount;

            if ($rowCount > 0) {
                fwrite($file, "-- Dumping data for {$identifier} ({$rowCount} records)\n");
                $colNames = [];
                $columns = $pdo->query("SHOW COLUMNS FROM {$identifier}")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columns as $col) {
                    $colNames[] = '`' . str_replace('`', '``', $col['Field']) . '`';
                }
                $colList = implode(', ', $colNames);

                while (($row = $rows->fetch(PDO::FETCH_NUM)) !== false) {
                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } else {
                            $values[] = $pdo->quote((string)$val);
                        }
                    }
                    fwrite($file, "INSERT INTO {$identifier} ({$colList}) VALUES (" . implode(', ', $values) . ");\n");
                }
                fwrite($file, "\n");
            }
        }

        fwrite($file, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($file);

        $filesize = (int)filesize($filepath);
        $dest = $config['backup_destination_url'] ?? 'https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home';

        // Record in system_backups table
        $stmt = $pdo->prepare(
            'INSERT INTO system_backups (filename, filepath, filesize, records_count, destination, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $filename,
            $filepath,
            $filesize,
            $totalRecords,
            $dest,
            $userId
        ]);
        $backupId = (int)$pdo->lastInsertId();

        return [
            'id' => $backupId,
            'filename' => $filename,
            'filesize' => $filesize,
            'records_count' => $totalRecords,
            'destination' => $dest,
            'filepath' => $filepath
        ];
    } catch (Throwable $e) {
        if (is_resource($file)) {
            fclose($file);
        }
        if (file_exists($filepath)) {
            @unlink($filepath);
        }
        throw $e;
    }
}

