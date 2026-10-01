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


if (!$user) {

    session_destroy();

    header('Location: login.php');

    exit;

}


if (!REQUIRE_2FA && empty($user['totp_secret'])) {

    header('Location: login.php');

    exit;

}


$error = '';


/*
|--------------------------------------------------------------------------
| CREATE SECRET IF NECESSARY
|--------------------------------------------------------------------------
*/

if (empty($user['totp_secret'])) {

    $secret =
        generate_totp_secret();


    $update = db()->prepare(

        'UPDATE users

         SET totp_secret = ?

         WHERE id = ?'

    );


    $update->execute([

        $secret,

        $user['id']

    ]);


    $user['totp_secret'] =
        $secret;
}


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

        $update = db()->prepare(

            'UPDATE users

             SET

                totp_enabled = 1,

                last_login_at = NOW()

             WHERE id = ?'

        );


        $update->execute([

            $user['id']

        ]);


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


        login_event(

            $user['username'],

            (int)$user['id'],

            true,

            '2fa_setup_and_login'

        );


        audit('login');


        header(
            'Location: dashboard.php'
        );

        exit;

    }


    $error =
        'Invalid verification code.';
}


$uri = totp_uri(

    APP_NAME,

    $user['username'],

    $user['totp_secret']

);

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
    Setup 2FA
</title>

<link
    rel="stylesheet"
    href="style.css"
>

</head>


<body>

<div class="card">

<h1>
    Set Up Two-Step Verification
</h1>


<p>

Install an authenticator application such as:

</p>

<ul>

<li>Google Authenticator</li>

<li>Microsoft Authenticator</li>

<li>Authy</li>

</ul>


<p>
Add a new account and enter this secret:
</p>


<div class="secret">

<?= e($user['totp_secret']) ?>

</div>


<p>

Then enter the 6-digit code generated
by the authenticator.

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
    Authentication Code
</label>

<input
    type="text"
    name="code"
    inputmode="numeric"
    pattern="[0-9]{6}"
    maxlength="6"
    required
>


<button type="submit">

Verify and Continue

</button>

</form>

</div>

</body>

</html>