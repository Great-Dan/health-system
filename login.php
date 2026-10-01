<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';

if (!empty($_SESSION['user_id'])) {

    header('Location: dashboard.php');

    exit;
}


$error = '';

$info = '';
$signupNotice = $_SESSION['signup_notice'] ?? '';
unset($_SESSION['signup_notice']);


if (isset($_GET['expired'])) {

    $info =
        'Your session expired. Please sign in again.';

}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();


    $username =
        trim(
            (string)(
                $_POST['username']
                ?? ''
            )
        );


    $selectedRole =
        strtolower(
            trim(
                (string)(
                    $_POST['role']
                    ?? ''
                )
            )
        );


    $password =
        (string)(
            $_POST['password']
            ?? ''
        );


    $stmt = db()->prepare(

        'SELECT *

         FROM users

         WHERE username = ?

         LIMIT 1'

    );


    $stmt->execute([

        $username

    ]);


    $user = $stmt->fetch();


    /*
    |--------------------------------------------------------------------------
    | USER DOES NOT EXIST
    |--------------------------------------------------------------------------
    */

    if (!$user) {

        login_event(

            $username,

            null,

            false,

            'unknown_user'

        );


        $error =
            'Invalid username or password.';

    }


    /*
    |--------------------------------------------------------------------------
    | ACCOUNT DISABLED
    |--------------------------------------------------------------------------
    */

    elseif (
        !in_array(
            $selectedRole,
            ['admin', 'accountant', 'patient'],
            true
        )
        ||
        $user['role'] !== $selectedRole
    ) {

        login_event(

            $username,

            (int)$user['id'],

            false,

            'role_mismatch'

        );


        $error =
            'Invalid username or password.';

    }


    /*
    |--------------------------------------------------------------------------
    | ACCOUNT DISABLED
    |--------------------------------------------------------------------------
    */

    elseif (!$user['is_active']) {

        login_event(

            $username,

            (int)$user['id'],

            false,

            'inactive_account'

        );


        $error =
            'This account is inactive.';

    }


    /*
    |--------------------------------------------------------------------------
    | ACCOUNT LOCKED
    |--------------------------------------------------------------------------
    */

    elseif (

        !empty($user['locked_until'])

        &&

        strtotime(
            $user['locked_until']
        ) > time()

    ) {

        login_event(

            $username,

            (int)$user['id'],

            false,

            'account_locked'

        );


        $error =
            'Account temporarily locked.';

    }


    /*
    |--------------------------------------------------------------------------
    | PASSWORD INCORRECT
    |--------------------------------------------------------------------------
    */

    elseif (

        !password_verify_with_pepper(

            $password,

            $user['password_hash']

        )

    ) {

        $attempts =
            (int)$user['failed_attempts']
            +
            1;


        if (
            $attempts >=
            MAX_LOGIN_ATTEMPTS
        ) {

            $lockedUntil =
                date(

                    'Y-m-d H:i:s',

                    time()
                    +
                    (
                        LOCKOUT_MINUTES
                        *
                        60
                    )

                );


            $update = db()->prepare(

                'UPDATE users

                 SET

                    failed_attempts = 0,

                    locked_until = ?

                 WHERE id = ?'

            );


            $update->execute([

                $lockedUntil,

                $user['id']

            ]);

        }

        else {

            $update = db()->prepare(

                'UPDATE users

                 SET failed_attempts = ?

                 WHERE id = ?'

            );


            $update->execute([

                $attempts,

                $user['id']

            ]);

        }


        login_event(

            $username,

            (int)$user['id'],

            false,

            'bad_password'

        );


        $error =
            'Invalid username or password.';

    }


    /*
    |--------------------------------------------------------------------------
    | PASSWORD CORRECT
    |--------------------------------------------------------------------------
    */

    else {

        $update = db()->prepare(

            'UPDATE users

             SET

                failed_attempts = 0,

                locked_until = NULL

             WHERE id = ?'

        );


        $update->execute([

            $user['id']

        ]);


        /*
        |--------------------------------------------------------------------------
        | PREVENT SESSION FIXATION
        |--------------------------------------------------------------------------
        */

        session_regenerate_id(true);


        $_SESSION['pending_user_id'] =
            (int)$user['id'];


        $_SESSION['pending_role'] =
            $user['role'];


        $_SESSION['pending_username'] =
            $user['username'];


        $hasSecurityQuestions =
            !empty($user['security_answer_1_hash'])
            && !empty($user['security_answer_2_hash']);

        header(
            'Location: ' . (
                $hasSecurityQuestions
                    ? 'security_questions_verify.php'
                    : 'security_questions_setup.php'
            )
        );

        exit;

    }
}

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1.0"
>

<title>
    <?= e(APP_NAME) ?> - Login
</title>

<link
    rel="stylesheet"
    href="style.css"
>

</head>


<body class="auth-page">

<main class="card auth-card">

<a class="brand-mark" href="login.php" aria-label="Secure Health home">SH</a>
<p class="eyebrow">SECURE HEALTH</p>

<h1>
    <?= e(APP_NAME) ?>
</h1>

<p class="muted">
    Secure Login
</p>


<?php if ($error): ?>

<div class="alert error">

<?= e($error) ?>

</main>

<?php endif; ?>


<?php if ($info): ?>

<div class="alert info">

<?= e($info) ?>

</div>

<?php endif; ?>

<?php if ($signupNotice): ?>
<div class="alert info">
<?= e($signupNotice) ?>
</div>
<?php endif; ?>


<form
    method="POST"
    autocomplete="off"
>

<input
    type="hidden"
    name="csrf_token"
    value="<?= e(csrf_token()) ?>"
>


<label>
    Role
</label>

<select
    name="role"
    required
>

<option value="">
    Select your role
</option>

<option value="admin">
    Administrator
</option>

<option value="accountant">
    Accountant
</option>

<option value="patient">
    Patient
</option>

</select>


<label>
    Username
</label>

<input
    type="text"
    name="username"
    maxlength="50"
    required
    autofocus
>


<label>
    Password
</label>

<input
    type="password"
    name="password"
    required
>


<button type="submit">

Continue

</button>

</form>

<a class="button-link button-secondary" href="signup.php">Create patient account</a>

<p class="muted small">

Sign in with your role, username, and password, then set up or answer your security questions.

</p>

</div>

</body>

</html>