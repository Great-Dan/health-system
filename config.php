<?php

declare(strict_types=1);


/*
|--------------------------------------------------------------------------
| DATABASE SETTINGS
|--------------------------------------------------------------------------
*/

const DB_HOST = '127.0.0.1';

const DB_NAME = 'secure_health_system';

const DB_USER = 'root';

const DB_PASS = '';


/*
|--------------------------------------------------------------------------
| APPLICATION SETTINGS
|--------------------------------------------------------------------------
*/

const APP_NAME = 'Secure Health Management System';

const REQUIRE_2FA = false;


/*
|--------------------------------------------------------------------------
| PASSWORD PEPPER
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Change this value before using the system.
|
| In production, store the pepper in an environment variable or
| secret-management system rather than directly in this file.
|
*/

const APP_PEPPER =
    'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET_123456789';


/*
|--------------------------------------------------------------------------
| SESSION SETTINGS
|--------------------------------------------------------------------------
*/

const SESSION_TIMEOUT = 1800;


/*
|--------------------------------------------------------------------------
| LOGIN SECURITY
|--------------------------------------------------------------------------
*/

const MAX_LOGIN_ATTEMPTS = 5;

const LOCKOUT_MINUTES = 15;


/*
|--------------------------------------------------------------------------
| TIMEZONE
|--------------------------------------------------------------------------
*/

date_default_timezone_set('Africa/Accra');


/*
|--------------------------------------------------------------------------
| SECURE SESSION COOKIE
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    session_set_cookie_params([

        'lifetime' => 0,

        'path' => '/',

        'secure' =>
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',

        'httponly' => true,

        'samesite' => 'Lax'

    ]);

    session_start();
}