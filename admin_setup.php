<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';

$count = (int)db()->query(

    "SELECT COUNT(*)

     FROM users

     WHERE role = 'admin'"

)->fetchColumn();


$error = '';

$success = '';

$questions = security_question_options('admin');


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();


    if ($count > 0) {

        $error =
            'An administrator already exists.';

    }

    else {

        $username =
            trim(
                (string)(
                    $_POST['username']
                    ?? ''
                )
            );


        $name =
            trim(
                (string)(
                    $_POST['full_name']
                    ?? ''
                )
            );


        $email =
            trim(
                (string)(
                    $_POST['email']
                    ?? ''
                )
            );


        $password =
            (string)(
                $_POST['password']
                ?? ''
            );

        $questions = security_question_options('admin');
        $question1 = (string)($_POST['security_question_1'] ?? '');
        $answer1 = normalize_security_answer((string)($_POST['security_answer_1'] ?? ''));
        $question2 = (string)($_POST['security_question_2'] ?? '');
        $answer2 = normalize_security_answer((string)($_POST['security_answer_2'] ?? ''));


        /*
        |--------------------------------------------------------------------------
        | USERNAME VALIDATION
        |--------------------------------------------------------------------------
        */

        if (
            !preg_match(

                '/^[A-Za-z0-9_.-]{3,50}$/',

                $username

            )
        ) {

            $error =
                'Invalid username.';

        }


        /*
        |--------------------------------------------------------------------------
        | EMAIL VALIDATION
        |--------------------------------------------------------------------------
        */

        elseif (
            !filter_var(

                $email,

                FILTER_VALIDATE_EMAIL

            )
        ) {

            $error =
                'Invalid email address.';

        }


        /*
        |--------------------------------------------------------------------------
        | PASSWORD VALIDATION
        |--------------------------------------------------------------------------
        */

        elseif (
            strlen($password) < 12
        ) {

            $error =
                'Password must be at least 12 characters.';

        }

        elseif (
            !isset($questions[$question1])
            || !isset($questions[$question2])
            || $question1 === $question2
            || strlen($answer1) < 3
            || strlen($answer1) > 200
            || strlen($answer2) < 3
            || strlen($answer2) > 200
        ) {

            $error =
                'Choose two different administrator questions and enter both answers.';

        }


        else {

            $stmt = db()->prepare(

                'INSERT INTO users

                (
                    username,

                    full_name,

                    email,

                    password_hash,

                    role,

                    security_question_1,

                    security_answer_1_hash,

                    security_question_2,

                    security_answer_2_hash

                )

                VALUES

                (?, ?, ?, ?, "admin", ?, ?, ?, ?)'

            );


            $stmt->execute([

                $username,

                $name,

                $email,

                password_hash_with_pepper($password),

                $question1,

                security_answer_hash($answer1),

                $question2,

                security_answer_hash($answer2)

            ]);


            $success =
                'Administrator created successfully. ' .
                'Sign in using the security answers you selected.';


            $count = 1;

        }

    }

}

?>

<!DOCTYPE html>

<html>

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width,initial-scale=1"
>

<title>
Initial Administrator Setup
</title>

<link
    rel="stylesheet"
    href="style.css"
>

</head>


<body class="auth-page">


<main class="card auth-card">

<a class="brand-mark" href="login.php" aria-label="Secure Health home">SH</a>
<p class="eyebrow">INITIAL CONFIGURATION</p>


<h1>
Initial Administrator Setup
</h1>


<?php if ($error): ?>

<div class="alert error">

<?= e($error) ?>

</main>

<?php endif; ?>


<?php if ($success): ?>

<div class="alert info">

<?= e($success) ?>

</div>

<?php endif; ?>


<?php if ($count === 0): ?>


<form method="POST">


<input
    type="hidden"
    name="csrf_token"
    value="<?= e(csrf_token()) ?>"
>


<label>
Username
</label>

<input
    type="text"
    name="username"
    required
>


<label>
Full Name
</label>

<input
    type="text"
    name="full_name"
    required
>


<label>
Email
</label>

<input
    type="email"
    name="email"
    required
>


<label>
Password
</label>

<input
    type="password"
    name="password"
    minlength="12"
    required
>

<label>
Security Question 1
</label>

<select name="security_question_1" required>
<?php foreach ($questions as $key => $question): ?>
    <option value="<?= e($key) ?>"><?= e($question) ?></option>
<?php endforeach; ?>
</select>

<label>
Answer 1
</label>

<input
    type="password"
    name="security_answer_1"
    minlength="3"
    maxlength="200"
    autocomplete="off"
    required
>

<label>
Security Question 2
</label>

<select name="security_question_2" required>
<?php foreach ($questions as $index => $question): ?>
    <option value="<?= e($index) ?>" <?= $index === 'first_workplace' ? 'selected' : '' ?>><?= e($question) ?></option>
<?php endforeach; ?>
</select>

<label>
Answer 2
</label>

<input
    type="password"
    name="security_answer_2"
    minlength="3"
    maxlength="200"
    autocomplete="off"
    required
>


<button type="submit">

Create Administrator

</button>


</form>


<?php else: ?>


<p>

Administrator setup is complete.

</p>


<p>

<strong>
Delete setup_admin.php from the server.
</strong>

</p>


<?php endif; ?>


</div>


</body>

</html>