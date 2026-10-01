<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$questions = security_question_options('patient');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $fullName = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordConfirmation = (string)($_POST['password_confirmation'] ?? '');
    $dateOfBirth = trim((string)($_POST['date_of_birth'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $question1 = (string)($_POST['security_question_1'] ?? '');
    $answer1 = normalize_security_answer((string)($_POST['security_answer_1'] ?? ''));
    $question2 = (string)($_POST['security_question_2'] ?? '');
    $answer2 = normalize_security_answer((string)($_POST['security_answer_2'] ?? ''));
    $dateParts = explode('-', $dateOfBirth);
    $validDateOfBirth = $dateOfBirth === ''
        || (
            count($dateParts) === 3
            && ctype_digit($dateParts[0])
            && ctype_digit($dateParts[1])
            && ctype_digit($dateParts[2])
            && checkdate((int)$dateParts[1], (int)$dateParts[2], (int)$dateParts[0])
        );

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        $error = 'Username must be 3-50 letters, numbers, dots, underscores, or hyphens.';
    } elseif ($fullName === '' || strlen($fullName) > 120) {
        $error = 'Enter a name of no more than 120 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 12) {
        $error = 'Password must be at least 12 characters.';
    } elseif (!hash_equals($password, $passwordConfirmation)) {
        $error = 'Passwords do not match.';
    } elseif (
        !isset($questions[$question1])
        || !isset($questions[$question2])
        || $question1 === $question2
    ) {
        $error = 'Choose two different security questions.';
    } elseif (
        strlen($answer1) < 3
        || strlen($answer1) > 200
        || strlen($answer2) < 3
        || strlen($answer2) > 200
        || strlen($phone) > 30
        || strlen($address) > 255
        || !$validDateOfBirth
    ) {
        $error = 'Check your profile details and question answers.';
    } else {
        $connection = db();

        try {
            $connection->beginTransaction();
            $stmt = $connection->prepare(
                'INSERT INTO users
                    (username, full_name, email, password_hash, role,
                     security_question_1, security_answer_1_hash,
                     security_question_2, security_answer_2_hash)
                 VALUES (?, ?, ?, ?, \'patient\', ?, ?, ?, ?)'
            );
            $stmt->execute([
                $username,
                $fullName,
                $email,
                password_hash_with_pepper($password),
                $question1,
                security_answer_hash($answer1),
                $question2,
                security_answer_hash($answer2)
            ]);
            $userId = (int)$connection->lastInsertId();

            $stmt = $connection->prepare(
                'INSERT INTO patients (user_id, date_of_birth, phone, address)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $dateOfBirth !== '' ? $dateOfBirth : null,
                $phone !== '' ? $phone : null,
                $address !== '' ? $address : null
            ]);

            audit('patient_self_signup', 'users', $userId);
            $connection->commit();
            $_SESSION['signup_notice'] = 'Account created. Sign in with your username and password.';
            header('Location: login.php');
            exit;
        } catch (PDOException $exception) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            $error = $exception->getCode() === '23000'
                ? 'That username or email is already registered.'
                : 'Account could not be created. Please try again.';
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Create Patient Account</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
<main class="card auth-card">
    <a class="brand-mark" href="login.php" aria-label="Secure Health home">SH</a>
    <p class="eyebrow">PATIENT ACCESS</p>
    <h1>Create your account</h1>
    <p class="muted">Set up your patient profile and choose the answers you’ll use after your password at sign-in.</p>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div>
            <label for="full_name">Full name</label>
            <input id="full_name" name="full_name" maxlength="120" required autocomplete="name" value="<?= e((string)($_POST['full_name'] ?? '')) ?>">
        </div>
        <div>
            <label for="username">Username</label>
            <input id="username" name="username" maxlength="50" required autocomplete="username" value="<?= e((string)($_POST['username'] ?? '')) ?>">
        </div>
        <div class="span-all">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="190" required autocomplete="email" value="<?= e((string)($_POST['email'] ?? '')) ?>">
        </div>
        <div>
            <label for="password">Password</label>
            <input id="password" name="password" type="password" minlength="12" required autocomplete="new-password">
        </div>
        <div>
            <label for="password_confirmation">Confirm password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" minlength="12" required autocomplete="new-password">
        </div>
        <div>
            <label for="date_of_birth">Date of birth <span class="muted">(optional)</span></label>
            <input id="date_of_birth" name="date_of_birth" type="date" value="<?= e((string)($_POST['date_of_birth'] ?? '')) ?>">
        </div>
        <div>
            <label for="phone">Phone <span class="muted">(optional)</span></label>
            <input id="phone" name="phone" maxlength="30" autocomplete="tel" value="<?= e((string)($_POST['phone'] ?? '')) ?>">
        </div>
        <div class="span-all">
            <label for="address">Address <span class="muted">(optional)</span></label>
            <input id="address" name="address" maxlength="255" autocomplete="street-address" value="<?= e((string)($_POST['address'] ?? '')) ?>">
        </div>

        <div class="span-all security-section">
            <h2>Sign-in questions</h2>
            <p class="muted small">Choose answers others cannot easily guess. Keep them private.</p>
        </div>
        <div>
            <label for="security_question_1">Question 1</label>
            <select id="security_question_1" name="security_question_1" required>
                <option value="">Choose a question</option>
                <?php foreach ($questions as $key => $question): ?>
                    <option value="<?= e($key) ?>" <?= ($_POST['security_question_1'] ?? '') === $key ? 'selected' : '' ?>><?= e($question) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="security_answer_1">Answer 1</label>
            <input id="security_answer_1" name="security_answer_1" type="password" minlength="3" maxlength="200" required autocomplete="off">
        </div>
        <div>
            <label for="security_question_2">Question 2</label>
            <select id="security_question_2" name="security_question_2" required>
                <option value="">Choose a different question</option>
                <?php foreach ($questions as $key => $question): ?>
                    <option value="<?= e($key) ?>" <?= ($_POST['security_question_2'] ?? '') === $key ? 'selected' : '' ?>><?= e($question) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="security_answer_2">Answer 2</label>
            <input id="security_answer_2" name="security_answer_2" type="password" minlength="3" maxlength="200" required autocomplete="off">
        </div>
        <button class="span-all" type="submit">Create patient account</button>
    </form>

    <p class="auth-switch">Already registered? <a href="login.php">Sign in</a></p>
</main>
</body>
</html>