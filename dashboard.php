<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';


require_login();


$role =
    $_SESSION['role'];


if ($role === 'admin') {

    header(
        'Location: admin_index.php'
    );

}

elseif ($role === 'patient') {

    header(
        'Location: patient_index.php'
    );

}

elseif ($role === 'accountant') {

    header(
        'Location: accountant_index.php'
    );

}

else {

    http_response_code(403);

    exit('Unknown role.');

}


exit;