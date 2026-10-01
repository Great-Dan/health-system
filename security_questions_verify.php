<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

if (empty($_SESSION['pending_user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$_SESSION['pending_user_id']]);
$user = $stmt->fetch();

if (!$user) {
    unset(
        $_SESSION['pending_user_id'],
        $_SESSION['pending_role'],
        $_SESSION['pending_username']
    );
    header('Location: login.php');
    exit;
}

if (empty($user['security_answer_1_hash']) || empty($user['security_answer_2_hash'])) {
    header('Location: security_questions_setup.php');
    exit;
}

$question1 = security_question_label(
    $user['role'],
    $user['security_question_1']
);
$question2 = security_question_label(
    $user['role'],
    $user['security_question_2']
);

if ($question1 === null || $question2 === null) {
    header('Location: security_questions_setup.php');
    exit;
}

$error = '';
$isLocked = !empty($user['locked_until'])
    && strtotime($user['locked_until']) > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if ($isLocked) {
        $error = 'Account temporarily locked. Please try again later.';
    } else {
        $answer1Valid = security_answer_verify(
            (string)($_POST['answer_1'] ?? ''),
            $user['security_answer_1_hash']
        );
        $answer2Valid = security_answer_verify(
            (string)($_POST['answer_2'] ?? ''),
            $user['security_answer_2_hash']
        );

        if ($answer1Valid && $answer2Valid) {
            complete_pending_login($user, 'security_questions_success');
        }

        $attempts = (int)$user['failed_attempts'] + 1;

        if ($attempts >= MAX_LOGIN_ATTEMPTS) {
            $lockedUntil = date(
                'Y-m-d H:i:s',
                time() + (LOCKOUT_MINUTES * 60)
            );
            $stmt = db()->prepare(
                'UPDATE users
                 SET failed_attempts = 0, locked_until = ?
                 WHERE id = ?'
            );
            $stmt->execute([$lockedUntil, $user['id']]);
            $isLocked = true;
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
            'bad_security_answers'
        );
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Answer Security Questions</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="card auth-card">
    <a class="brand-mark" href="login.php" aria-label="Secure Health home">SH</a>
    <p class="eyebrow">ACCOUNT SECURITY</p>
    <h1>Answer Security Questions</h1>
    <p class="muted">Answer both questions to finish signing in.</p>
    <p class="muted small">Forgot your answers? Ask an administrator to reset your password. You can choose new questions at your next sign-in.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if (!$isLocked): ?>
    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <label for="answer_1"><?= e($question1) ?></label>
        <input id="answer_1" name="answer_1" type="password" required autocomplete="off">

        <label for="answer_2"><?= e($question2) ?></label>
        <input id="answer_2" name="answer_2" type="password" required autocomplete="off">

        <button type="submit">Verify and Sign In</button>
    </form>
    <?php endif; ?>
</main>
</body>
</html>