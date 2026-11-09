<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

require_login();

$userId = (int)$_SESSION['user_id'];
$role = (string)$_SESSION['role'];
$questions = security_question_options($role);
$error = '';
$success = '';

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
        $error = 'Choose two different questions for your role.';
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
                 security_answer_2_hash = ?
             WHERE id = ? AND role = ?'
        );
        $stmt->execute([
            $question1,
            security_answer_hash($answer1),
            $question2,
            security_answer_hash($answer2),
            $userId,
            $role
        ]);

        audit('security_questions_changed', 'users', $userId);
        $success = 'Your security questions have been updated.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Change Security Questions</title>
    <link rel="stylesheet" href="style.css?v=20261015">
</head>
<body class="auth-page">
<main class="card auth-card auth-narrow">
    <a class="brand-mark" href="dashboard.php" aria-label="Return to dashboard">SH</a>
    <p class="eyebrow">ACCOUNT SECURITY</p>
    <h1>Change Security Questions</h1>
    <p class="muted">Choose two different questions and answers. Answers are case-insensitive. Do not use information others can easily guess.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert info"><?= e($success) ?></div>
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

    <p class="auth-switch"><a href="dashboard.php">Return to dashboard</a></p>
</main>
</body>
</html>
