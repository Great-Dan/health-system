<?php

declare(strict_types=1);

header('Location: login.php');
exit;

require_once __DIR__ .
    '/security.php';

require_once __DIR__ .
    '/totp.php';


if (
    empty($_SESSION['pending_user_id'])
) {

    header('Location: login.php');

    exit;

}


$stmt = db()->prepare(

    'SELECT *

     FROM users

     WHERE id = ?

     LIMIT 1'

);


$stmt->execute([

    $_SESSION['pending_user_id']

]);


$user = $stmt->fetch();


if (
    !$user
    ||
    empty($user['totp_secret'])
    ||
    (int)$user['totp_enabled'] !== 1
) {

    header('Location: login.php');

    exit;

}


$error = '';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();


    $code =
        trim(
            (string)(
                $_POST['code']
                ?? ''
            )
        );


    if (

        verify_totp(

            $user['totp_secret'],

            $code

        )

    ) {

        session_regenerate_id(true);


        $_SESSION['user_id'] =
            (int)$user['id'];

        $_SESSION['role'] =
            $user['role'];

        $_SESSION['username'] =
            $user['username'];

        $_SESSION['full_name'] =
            $user['full_name'];

        $_SESSION['last_activity'] =
            time();


        unset(

            $_SESSION['pending_user_id'],

            $_SESSION['pending_role'],

            $_SESSION['pending_username']

        );


        $update = db()->prepare(

            'UPDATE users

             SET last_login_at = NOW()

             WHERE id = ?'

        );


        $update->execute([

            $user['id']

        ]);


        login_event(

            $user['username'],

            (int)$user['id'],

            true,

            '2fa_success'

        );


        audit('login');


        header(
            'Location: dashboard.php'
        );

        exit;

    }


    login_event(

        $user['username'],

        (int)$user['id'],

        false,

        'bad_2fa'

    );


    $error =
        'Invalid or expired verification code.';
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
    Two-Step Verification
</title>

<link
    rel="stylesheet"
    href="style.css"
>

</head>


<body>

<div class="card">

<h1>
    Two-Step Verification
</h1>

<p>

Enter the 6-digit code from your
authenticator application.

</p>


<?php if ($error): ?>

<div class="alert error">

<?= e($error) ?>

</div>

<?php endif; ?>


<form method="POST">

<input
    type="hidden"
    name="csrf_token"
    value="<?= e(csrf_token()) ?>"
>


<label>
    Verification Code
</label>

<input
    type="text"
    name="code"
    inputmode="numeric"
    pattern="[0-9]{6}"
    maxlength="6"
    required
    autofocus
>


<button type="submit">

Verify

</button>

</form>


<p>

<a href="logout.php">
Cancel
</a>

</p>

</div>

</body>

</html>