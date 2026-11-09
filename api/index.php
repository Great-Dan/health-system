<?php

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri === '/' || $uri === '' || $uri === '/index.php') {
    require __DIR__ . '/../index.php';
    exit;
}

$target = __DIR__ . '/..' . $uri;

if (file_exists($target) && is_file($target)) {
    $ext = pathinfo($target, PATHINFO_EXTENSION);
    if ($ext === 'php') {
        require $target;
        exit;
    }
}

if (file_exists($target . '.php') && is_file($target . '.php')) {
    require $target . '.php';
    exit;
}

http_response_code(404);
echo '404 - Page not found';
