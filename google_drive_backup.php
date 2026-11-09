<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo "Notice: Google Drive backup has been migrated to Cloudflare Storage.\n";
echo "Destination: https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home\n\n";

require_once __DIR__ . '/cloudflare_backup.php';
