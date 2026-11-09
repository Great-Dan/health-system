<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

require_login();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $currentPassword = (string)($_POST['current_password'] ?? '');
    $newPassword = (string)($_POST['new_password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify_with_pepper($currentPassword, $user['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 12) {
        $error = 'New password must be at least 12 characters.';
    } elseif (!hash_equals($newPassword, $confirmPassword)) {
        $error = 'New passwords do not match.';
    } else {
        $stmt = db()->prepare(
            'UPDATE users
             SET password_hash = ?, failed_attempts = 0, locked_until = NULL
             WHERE id = ?'
        );
        $stmt->execute([
            password_hash_with_pepper($newPassword),
            $_SESSION['user_id']
        ]);
        audit('password_changed', 'users', (int)$_SESSION['user_id']);
        $success = 'Password updated successfully.';
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Change Password</title>
    <link rel="stylesheet" href="style.css?v=20261015">
</head>
<body class="auth-page">
<main class="card auth-card auth-narrow">
    <a class="brand-mark" href="dashboard.php" aria-label="Return to dashboard">SH</a>
    <p class="eyebrow">ACCOUNT SETTINGS</p>
    <h1>Change Password</h1>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert info"><?= e($success) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <label for="current_password">Current password</label>
        <input id="current_password" name="current_password" type="password" required autocomplete="current-password">

        <label for="new_password">New password</label>
        <input id="new_password" name="new_password" type="password" minlength="12" required autocomplete="new-password">

        <label for="confirm_password">Confirm new password</label>
        <input id="confirm_password" name="confirm_password" type="password" minlength="12" required autocomplete="new-password">

        <button type="button" class="password-visibility-toggle" aria-label="Show passwords" aria-pressed="false" title="Show passwords" data-targets="current_password,new_password,confirm_password">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                <circle cx="12" cy="12" r="3"></circle>
                <path class="password-eye-slash" d="m4 4 16 16"></path>
            </svg>
        </button>

        <button type="submit">Update password</button>
    </form>

    <p><a href="dashboard.php">Return to dashboard</a></p>
</main>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.password-visibility-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const targetIds = (toggle.dataset.targets || '').split(',').map(function (id) {
                    return id.trim();
                }).filter(Boolean);

                const shouldShow = toggle.getAttribute('aria-pressed') !== 'true';

                targetIds.forEach(function (id) {
                    const field = document.getElementById(id);
                    if (!field) return;
                    field.type = shouldShow ? 'text' : 'password';
                });

                toggle.setAttribute('aria-pressed', String(shouldShow));
                toggle.setAttribute('aria-label', shouldShow ? 'Hide passwords' : 'Show passwords');
                toggle.setAttribute('title', shouldShow ? 'Hide passwords' : 'Show passwords');
            });
        });
    });
</script>
</body>
</html>