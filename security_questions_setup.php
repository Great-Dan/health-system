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

if ($user['security_answer_1_hash'] && $user['security_answer_2_hash']) {
    header('Location: security_questions_verify.php');
    exit;
}

$questions = security_question_options($user['role']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $question1 = (string)($_POST['question_1'] ?? '');
    $question2 = (string)($_POST['question_2'] ?? '');
    $answer1 = normalize_security_answer((string)($_POST['answer_1'] ?? ''));
    $answer2 = normalize_security_answer((string)($_POST['answer_2'] ?? ''));

    if (
        !isset($questions[$question1])
        || !isset($questions[$question2])
        || $question1 === $question2
    ) {
        $error = 'Choose two different questions.';
    } elseif (
        strlen($answer1) < 3
        || strlen($answer1) > 200
        || strlen($answer2) < 3
        || strlen($answer2) > 200
    ) {
        $error = 'Each answer must be between 3 and 200 characters.';
    } else {
        $stmt = db()->prepare(
            'UPDATE users
             SET security_question_1 = ?,
                 security_answer_1_hash = ?,
                 security_question_2 = ?,
                 security_answer_2_hash = ?,
                 totp_secret = NULL,
                 totp_enabled = 0
             WHERE id = ?'
        );
        $stmt->execute([
            $question1,
            security_answer_hash($answer1),
            $question2,
            security_answer_hash($answer2),
            $user['id']
        ]);

        $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$user['id']]);
        complete_pending_login($stmt->fetch(), 'security_questions_setup');
    }
}

?>

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Set Security Questions</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="card auth-card">
    <a class="brand-mark" href="login.php" aria-label="Secure Health home">SH</a>
    <p class="eyebrow">ACCOUNT SECURITY</p>
    <h1>Set Security Questions</h1>
    <p class="muted">Choose two questions and answers you can remember. Answers are not case-sensitive. Avoid details others can easily find or guess. These questions are less secure than an authenticator app.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <label for="question_1">Question 1</label>
        <select id="question_1" name="question_1" required>
            <option value="">Choose a question</option>
            <?php foreach ($questions as $key => $question): ?>
                <option value="<?= e($key) ?>"><?= e($question) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="answer_1">Answer 1</label>
        <input id="answer_1" name="answer_1" type="password" minlength="3" maxlength="200" required autocomplete="new-password">

        <label for="question_2">Question 2</label>
        <select id="question_2" name="question_2" required>
            <option value="">Choose a different question</option>
            <?php foreach ($questions as $key => $question): ?>
                <option value="<?= e($key) ?>"><?= e($question) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="answer_2">Answer 2</label>
        <input id="answer_2" name="answer_2" type="password" minlength="3" maxlength="200" required autocomplete="new-password">

        <button type="submit">Save Questions</button>
    </form>
</main>
</body>
</html>