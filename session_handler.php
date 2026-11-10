<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private ?PDO $pdo = null;

    private function getPdo(): ?PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        try {
            $this->pdo = db();
        } catch (Throwable $e) {
            error_log('DatabaseSessionHandler: db() failed: ' . $e->getMessage());
            return null;
        }

        return $this->pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return '';
        }

        try {
            $stmt = $pdo->prepare('SELECT data FROM sessions WHERE id = ? LIMIT 1');
            $stmt->execute([$id]);
            $data = $stmt->fetchColumn();
            return is_string($data) ? $data : '';
        } catch (Throwable $e) {
            error_log('Session read error: ' . $e->getMessage());
            return '';
        }
    }

    public function write(string $id, string $data): bool
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('INSERT INTO sessions (id, data, last_activity) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE data = VALUES(data), last_activity = VALUES(last_activity)');
            return $stmt->execute([$id, $data, time()]);
        } catch (Throwable $e) {
            error_log('Session write error: ' . $e->getMessage());
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return true;
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE id = ?');
            return $stmt->execute([$id]);
        } catch (Throwable $e) {
            error_log('Session destroy error: ' . $e->getMessage());
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        $pdo = $this->getPdo();
        if (!$pdo) {
            return 0;
        }

        try {
            $cutoff = time() - $max_lifetime;
            $stmt = $pdo->prepare('DELETE FROM sessions WHERE last_activity < ?');
            $stmt->execute([$cutoff]);
            return $stmt->rowCount();
        } catch (Throwable $e) {
            error_log('Session gc error: ' . $e->getMessage());
            return 0;
        }
    }
}

function init_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    if (getenv('VERCEL') !== false || getenv('USE_DB_SESSIONS') === '1') {
        try {
            $handler = new DatabaseSessionHandler();
            session_set_save_handler($handler, true);
        } catch (Throwable $e) {
            error_log('Failed to register DatabaseSessionHandler: ' . $e->getMessage());
        }
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}
