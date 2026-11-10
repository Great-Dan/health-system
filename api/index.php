<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    require __DIR__ . '/../index.php';
    exit;
}

$target = __DIR__ . '/..' . $uri;

if (file_exists($target) && is_file($target)) {
    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
    if ($ext === 'php') {
        require $target;
        exit;
    }

    $mimes = [
        'css'  => 'text/css',
        'js'   => 'application/javascript',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'svg'  => 'image/svg+xml',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'pdf'  => 'application/pdf',
        'txt'  => 'text/plain',
    ];
    $contentType = $mimes[$ext] ?? 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($target));
    if ($ext === 'docx') {
        header('Content-Disposition: attachment; filename="' . basename($target) . '"');
    }
    readfile($target);
    exit;
}

if (file_exists($target . '.php') && is_file($target . '.php')) {
    require $target . '.php';
    exit;
}

http_response_code(404);
echo '404 - Page not found';
