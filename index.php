<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';


if (
    !empty($_SESSION['user_id'])
    &&
    !empty($_SESSION['role'])
) {

    header('Location: dashboard.php');

    exit;

}


header('Location: login.php');

exit;