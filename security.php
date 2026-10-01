<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';


/*
|--------------------------------------------------------------------------
| HTML ESCAPING
|--------------------------------------------------------------------------
*/

function e(?string $value): string
{
    return htmlspecialchars(

        $value ?? '',

        ENT_QUOTES | ENT_SUBSTITUTE,

        'UTF-8'

    );
}


/*
|--------------------------------------------------------------------------
| CSRF TOKEN
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {

        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));

    }

    return $_SESSION['csrf_token'];
}


/*
|--------------------------------------------------------------------------
| VERIFY CSRF
|--------------------------------------------------------------------------
*/

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';


    if (
        !is_string($token)
        ||
        !hash_equals(

            $_SESSION['csrf_token'] ?? '',

            $token

        )
    ) {

        http_response_code(419);

        exit('Invalid CSRF token.');

    }
}


/*
|--------------------------------------------------------------------------
| CLIENT IP
|--------------------------------------------------------------------------
*/

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
}


/*
|--------------------------------------------------------------------------
| AUDIT LOG
|--------------------------------------------------------------------------
*/

function audit(

    string $action,

    ?string $table = null,

    ?int $targetId = null

): void {

    $stmt = db()->prepare(

        'INSERT INTO audit_logs

        (
            user_id,
            action,
            target_table,
            target_id,
            ip_address,
            user_agent
        )

        VALUES (?, ?, ?, ?, ?, ?)'

    );


    $stmt->execute([

        $_SESSION['user_id'] ?? null,

        $action,

        $table,

        $targetId,

        client_ip(),

        substr(

            $_SERVER['HTTP_USER_AGENT']
            ?? '',

            0,

            500

        )

    ]);
}


/*
|--------------------------------------------------------------------------
| LOGIN EVENT
|--------------------------------------------------------------------------
*/

function login_event(

    ?string $username,

    ?int $userId,

    bool $success,

    string $reason

): void {

    $stmt = db()->prepare(

        'INSERT INTO login_events

        (
            username,
            user_id,
            success,
            reason,
            ip_address
        )

        VALUES (?, ?, ?, ?, ?)'

    );


    $stmt->execute([

        $username,

        $userId,

        $success ? 1 : 0,

        $reason,

        client_ip()

    ]);
}


/*
|--------------------------------------------------------------------------
| PASSWORD HASHING
|--------------------------------------------------------------------------
|
| password_hash() automatically creates a unique cryptographic SALT.
|
| The application PEPPER is added before hashing.
|
*/

function password_hash_with_pepper(
    string $password
): string {

    return password_hash(

        $password . APP_PEPPER,

        PASSWORD_DEFAULT

    );
}


/*
|--------------------------------------------------------------------------
| VERIFY PASSWORD
|--------------------------------------------------------------------------
*/

function password_verify_with_pepper(
    string $password,
    string $hash
): bool {
    return password_verify($password . APP_PEPPER, $hash);
}


function security_question_options(string $role = 'patient'): array
{
    $questionsByRole = [
        'admin' => [
            'first_manager' => 'What was the surname of your first manager?',
            'first_workplace' => 'What was the name of your first workplace?',
            'first_project' => 'What was the name of an early project you worked on?'
        ],
        'accountant' => [
            'first_finance_job' => 'What was your first finance-related job?',
            'first_accounting_class' => 'Where did you take your first accounting class?',
            'first_payday_month' => 'In what month did you receive your first paycheck?'
        ],
        'patient' => [
            'first_school' => 'What was the name of your first school?',
            'first_pet' => 'What was the name of your first pet?',
            'childhood_nickname' => 'What was your childhood nickname?'
        ]
    ];

    return $questionsByRole[$role] ?? $questionsByRole['patient'];
}


function security_question_label(string $role, string $questionKey): ?string
{
    $roleQuestions = security_question_options($role);

    if (isset($roleQuestions[$questionKey])) {
        return $roleQuestions[$questionKey];
    }

    $legacyQuestions = [
        'first_school' => 'What was the name of your first school?',
        'first_pet' => 'What was the name of your first pet?',
        'childhood_nickname' => 'What was your childhood nickname?',
        'first_job' => 'What was your first job?',
        'favorite_teacher' => 'What was the surname of a favorite teacher?',
        'memorable_place' => 'What was the name of a memorable place from childhood?'
    ];

    return $legacyQuestions[$questionKey] ?? null;
}


function normalize_security_answer(string $answer): string
{
    $answer = preg_replace('/\s+/u', ' ', trim($answer)) ?? trim($answer);

    return function_exists('mb_strtolower')
        ? mb_strtolower($answer, 'UTF-8')
        : strtolower($answer);
}


function security_answer_hash(string $answer): string
{
    return password_hash(
        normalize_security_answer($answer) . APP_PEPPER,
        PASSWORD_DEFAULT
    );
}


function security_answer_verify(string $answer, string $hash): bool
{
    return password_verify(
        normalize_security_answer($answer) . APP_PEPPER,
        $hash
    );
}


function complete_pending_login(array $user, string $reason): void
{
    session_regenerate_id(true);

    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['last_activity'] = time();

    unset(
        $_SESSION['pending_user_id'],
        $_SESSION['pending_role'],
        $_SESSION['pending_username']
    );

    $stmt = db()->prepare(
        'UPDATE users
         SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([$user['id']]);

    login_event($user['username'], (int)$user['id'], true, $reason);
    audit('login');

    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| REQUIRE LOGIN
|--------------------------------------------------------------------------
*/

function require_login(): void
{
    if (

        empty($_SESSION['user_id'])

        ||

        empty($_SESSION['role'])

    ) {

        header('Location: /secure_health_system/login.php');

        exit;

    }


    /*
    |--------------------------------------------------------------------------
    | SESSION TIMEOUT
    |--------------------------------------------------------------------------
    */

    if (

        !empty($_SESSION['last_activity'])

        &&

        time()
        -
        (int)$_SESSION['last_activity']

        >

        SESSION_TIMEOUT

    ) {

        logout_user('session_timeout');

        header(
            'Location: /secure_health_system/login.php?expired=1'
        );

        exit;

    }


    $_SESSION['last_activity'] = time();
}


/*
|--------------------------------------------------------------------------
| ROLE AUTHORIZATION
|--------------------------------------------------------------------------
*/

function require_role(string $role): void
{
    require_login();


    if (

        !hash_equals(

            $role,

            (string)($_SESSION['role'] ?? '')

        )

    ) {

        http_response_code(403);

        exit(
            '403 Forbidden: insufficient privileges.'
        );

    }
}


/*
|--------------------------------------------------------------------------
| LOGOUT
|--------------------------------------------------------------------------
*/

function logout_user(

    string $reason = 'logout'

): void {

    if (!empty($_SESSION['user_id'])) {

        audit($reason);

    }


    $_SESSION = [];


    if (ini_get('session.use_cookies')) {

        $params =
            session_get_cookie_params();


        setcookie(

            session_name(),

            '',

            time() - 42000,

            $params['path'],

            $params['domain'] ?? '',

            $params['secure'],

            $params['httponly']

        );

    }


    session_destroy();
}