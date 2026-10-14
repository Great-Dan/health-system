<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$step = 'lookup';
$user = null;
$question1 = null;
$question2 = null;
$recoveryUserId = (int)($_SESSION['password_recovery_user_id'] ?? 0);
$recoveryStartedAt = (int)($_SESSION['password_recovery_started_at'] ?? 0);
$recoveryVerifiedAt = (int)($_SESSION['password_recovery_verified_at'] ?? 0);

if ($recoveryUserId > 0 && time() - $recoveryStartedAt > 600) {
    unset(
        $_SESSION['password_recovery_user_id'],
        $_SESSION['password_recovery_started_at'],
        $_SESSION['password_recovery_verified_at']
    );
    $recoveryUserId = 0;
    $recoveryVerifiedAt = 0;
    $error = 'Recovery expired. Start again.';
}

if ($recoveryUserId > 0) {
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$recoveryUserId]);
    $user = $stmt->fetch();

    if (!$user || !$user['is_active']) {
        unset(
            $_SESSION['password_recovery_user_id'],
            $_SESSION['password_recovery_started_at'],
            $_SESSION['password_recovery_verified_at']
        );
        $recoveryUserId = 0;
        $recoveryVerifiedAt = 0;
        $user = null;
    } elseif ($recoveryVerifiedAt > 0) {
        $step = 'reset';
    } else {
        $step = 'answers';
        $question1 = security_question_label(
            $user['role'],
            $user['security_question_1']
        );
        $question2 = security_question_label(
            $user['role'],
            $user['security_question_2']
        );
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'lookup' && $recoveryUserId === 0) {
        $username = trim((string)($_POST['username'] ?? ''));
        $role = strtolower(trim((string)($_POST['role'] ?? '')));

        $stmt = db()->prepare(
            'SELECT id, role, is_active, locked_until,
                    security_answer_1_hash, security_answer_2_hash
             FROM users
             WHERE username = ? AND role = ?
             LIMIT 1'
        );
        $stmt->execute([$username, $role]);
        $candidate = $stmt->fetch();

        if (
            !$candidate
            || !$candidate['is_active']
            || empty($candidate['security_answer_1_hash'])
            || empty($candidate['security_answer_2_hash'])
            || (
                !empty($candidate['locked_until'])
                && strtotime($candidate['locked_until']) > time()
            )
        ) {
            $error = 'We could not start recovery for those details. Check them or contact an administrator.';
        } else {
            $_SESSION['password_recovery_user_id'] = (int)$candidate['id'];
            $_SESSION['password_recovery_started_at'] = time();
            unset($_SESSION['password_recovery_verified_at']);
            header('Location: forgot_password.php');
            exit;
        }
    } elseif ($action === 'verify_answers' && $step === 'answers' && $user) {
        $answer1Valid = security_answer_verify(
            (string)($_POST['answer_1'] ?? ''),
            $user['security_answer_1_hash']
        );
        $answer2Valid = security_answer_verify(
            (string)($_POST['answer_2'] ?? ''),
            $user['security_answer_2_hash']
        );

        if ($answer1Valid && $answer2Valid) {
            session_regenerate_id(true);
            $_SESSION['password_recovery_verified_at'] = time();
            header('Location: forgot_password.php');
            exit;
        }

        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + (LOCKOUT_MINUTES * 60));
            $stmt = db()->prepare(
                'UPDATE users SET failed_attempts = 0, locked_until = ? WHERE id = ?'
            );
            $stmt->execute([$lockedUntil, $user['id']]);
            unset(
                $_SESSION['password_recovery_user_id'],
                $_SESSION['password_recovery_started_at'],
                $_SESSION['password_recovery_verified_at']
            );
            $recoveryUserId = 0;
            $step = 'lookup';
            $error = 'Account temporarily locked. Please try again later.';
        } else {
            $stmt = db()->prepare(
                'UPDATE users SET failed_attempts = ? WHERE id = ?'
            );
            $stmt->execute([$attempts, $user['id']]);
            $error = 'The answers did not match. Please try again.';
        }

        login_event(
            $user['username'],
            (int)$user['id'],
            false,
            'bad_password_recovery_answers'
        );
    } elseif ($action === 'reset_password' && $step === 'reset' && $user) {
        if (time() - $recoveryVerifiedAt > 600) {
            unset(
                $_SESSION['password_recovery_user_id'],
                $_SESSION['password_recovery_started_at'],
                $_SESSION['password_recovery_verified_at']
            );
            $error = 'Recovery expired. Start again.';
            $step = 'lookup';
        } else {
            $password = (string)($_POST['password'] ?? '');
            $confirmation = (string)($_POST['password_confirmation'] ?? '');

            if (strlen($password) < 12) {
                $error = 'Password must be at least 12 characters.';
            } elseif (!hash_equals($password, $confirmation)) {
                $error = 'Passwords do not match.';
            } else {
                $stmt = db()->prepare(
                    'UPDATE users
                     SET password_hash = ?, failed_attempts = 0, locked_until = NULL
                     WHERE id = ?'
                );
                $stmt->execute([
                    password_hash_with_pepper($password),
                    $user['id']
                ]);
                audit('password_reset_security_questions', 'users', (int)$user['id']);
                login_event(
                    $user['username'],
                    (int)$user['id'],
                    true,
                    'password_reset_security_questions'
                );
                unset(
                    $_SESSION['password_recovery_user_id'],
                    $_SESSION['password_recovery_started_at'],
                    $_SESSION['password_recovery_verified_at']
                );
                $_SESSION['password_reset_notice'] = 'Password reset. Sign in with your new password.';
                header('Location: login.php');
                exit;
            }
        }
    } else {
        $error = 'Recovery session expired. Start again.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Reset Password</title>
    <link rel="stylesheet" href="style.css?v=20261012">
</head>
<body class="auth-page">
<main class="card auth-card auth-narrow">
    <a class="brand-mark" href="login.php" aria-label="Secure Health home">SH</a>
    <p class="eyebrow">ACCOUNT RECOVERY</p>
    <h1>Reset your password</h1>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($step === 'lookup'): ?>
    <p class="muted">Enter your account details to verify your security answers.</p>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="lookup">

        <label for="role">Role</label>
        <select id="role" name="role" required>
            <option value="">Select your role</option>
            <option value="admin">Administrator</option>
            <option value="accountant">Accountant</option>
            <option value="patient">Patient</option>
        </select>

        <label for="username">Username</label>
        <input id="username" name="username" maxlength="50" autocomplete="username" required>

        <button type="submit">Continue</button>
    </form>
    <?php elseif ($step === 'answers'): ?>
    <p class="muted">Answer both questions to continue. Recovery expires after ten minutes.</p>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="verify_answers">

        <label for="answer_1"><?= e($question1) ?></label>
        <input id="answer_1" name="answer_1" type="password" autocomplete="off" required>

        <label for="answer_2"><?= e($question2) ?></label>
        <input id="answer_2" name="answer_2" type="password" autocomplete="off" required>

        <button type="submit">Verify answers</button>
    </form>
    <?php else: ?>
    <p class="muted">Choose a new password. Your security questions will remain unchanged.</p>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="reset_password">

        <label for="password">New password</label>
        <div class="password-input-wrap">
            <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
            <button type="button" class="password-visibility-toggle" aria-label="Show new password" aria-pressed="false" title="Show new password" data-targets="password">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                    <path class="password-eye-slash" d="m4 4 16 16"></path>
                </svg>
            </button>
        </div>

        <label for="password_confirmation">Confirm new password</label>
        <div class="password-input-wrap">
            <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" autocomplete="new-password" required>
            <button type="button" class="password-visibility-toggle" aria-label="Show password confirmation" aria-pressed="false" title="Show password confirmation" data-targets="password_confirmation">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                    <path class="password-eye-slash" d="m4 4 16 16"></path>
                </svg>
            </button>
        </div>

        <button type="submit">Reset password</button>
    </form>
    <?php endif; ?>

    <p class="auth-switch"><a href="login.php">Return to sign in</a></p>
</main>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.password-visibility-toggle').forEach(function (toggle) {
            toggle.addEventListener('click', function () {
                const field = document.getElementById(toggle.dataset.targets);
                if (!field) return;

                const shouldShow = toggle.getAttribute('aria-pressed') !== 'true';
                field.type = shouldShow ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', String(shouldShow));
                const fieldLabel = field.id === 'password_confirmation'
                    ? 'password confirmation'
                    : 'new password';
                toggle.setAttribute('aria-label', (shouldShow ? 'Hide ' : 'Show ') + fieldLabel);
                toggle.setAttribute('title', (shouldShow ? 'Hide ' : 'Show ') + fieldLabel);
            });
        });
    });
</script>
</body>
</html>