<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_role('admin');

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(400);
    exit('Invalid backup ID requested.');
}

$stmt = db()->prepare('SELECT * FROM system_backups WHERE id = ?');
$stmt->execute([$id]);
$backup = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$backup) {
    http_response_code(404);
    exit('Backup record not found.');
}

$filepath = (string)$backup['filepath'];
if (!file_exists($filepath) || !is_readable($filepath)) {
    http_response_code(404);
    exit('The requested backup file is no longer available on disk.');
}

audit('system_backup_downloaded', 'system_backups', $id);

header('Content-Description: File Transfer');
header('Content-Type: application/sql');
header('Content-Disposition: attachment; filename="' . basename($backup['filename']) . '"');
header('Expires: 0');
header('Cache-Control: must-revalidate');
header('Pragma: public');
header('Content-Length: ' . filesize($filepath));

readfile($filepath);
exit;

