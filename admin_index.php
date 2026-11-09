<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/system_config.php';
require_once __DIR__ . '/system_backup_service.php';

require_role('admin');


$error = '';
$notice = $_SESSION['admin_notice'] ?? '';
$paymentError = $_SESSION['payment_error'] ?? '';
$paymentNotice = $_SESSION['payment_notice'] ?? '';
$invoiceError = $_SESSION['invoice_error'] ?? '';
$invoiceNotice = $_SESSION['invoice_notice'] ?? '';
$temporaryCredentials = $_SESSION['temporary_credentials'] ?? null;
unset(
    $_SESSION['admin_notice'],
    $_SESSION['payment_error'],
    $_SESSION['payment_notice'],
    $_SESSION['invoice_error'],
    $_SESSION['invoice_notice'],
    $_SESSION['temporary_credentials']
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create_user') {

        $username = trim((string)($_POST['username'] ?? ''));
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $role = (string)($_POST['role'] ?? '');
        $securityQuestion1 = (string)($_POST['security_question_1'] ?? '');
        $securityAnswer1 = normalize_security_answer((string)($_POST['security_answer_1'] ?? ''));
        $securityQuestion2 = (string)($_POST['security_question_2'] ?? '');
        $securityAnswer2 = normalize_security_answer((string)($_POST['security_answer_2'] ?? ''));
        $roleQuestions = security_question_options($role);
        $dateOfBirth = trim((string)($_POST['date_of_birth'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $emergencyContact = trim((string)($_POST['emergency_contact'] ?? ''));
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
            $error = 'Enter a valid email address of no more than 190 characters.';
        } elseif (!in_array($role, ['admin', 'accountant', 'patient'], true)) {
            $error = 'Select a valid role.';
        } elseif (
            !isset($roleQuestions[$securityQuestion1])
            || !isset($roleQuestions[$securityQuestion2])
            || $securityQuestion1 === $securityQuestion2
            || strlen($securityAnswer1) < 3
            || strlen($securityAnswer1) > 200
            || strlen($securityAnswer2) < 3
            || strlen($securityAnswer2) > 200
        ) {
            $error = 'Choose two different role-specific questions and enter both answers.';
        } elseif (
            $role === 'patient'
            && (
                strlen($phone) > 30
                || strlen($address) > 255
                || strlen($emergencyContact) > 120
                || !$validDateOfBirth
            )
        ) {
            $error = 'Check the patient profile details.';
        } else {
            $temporaryPassword = rtrim(
                strtr(base64_encode(random_bytes(18)), '+/', '-_'),
                '='
            );
            $connection = db();

            try {
                $connection->beginTransaction();
                $stmt = $connection->prepare(
                    'INSERT INTO users
                        (username, full_name, email, password_hash, role,
                         security_question_1, security_answer_1_hash,
                         security_question_2, security_answer_2_hash)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $username,
                    $fullName,
                    $email,
                    password_hash_with_pepper($temporaryPassword),
                    $role,
                    $securityQuestion1,
                    security_answer_hash($securityAnswer1),
                    $securityQuestion2,
                    security_answer_hash($securityAnswer2)
                ]);
                $userId = (int)$connection->lastInsertId();

                if ($role === 'patient') {
                    $stmt = $connection->prepare(
                        'INSERT INTO patients
                            (user_id, date_of_birth, phone, address, emergency_contact)
                         VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([
                        $userId,
                        $dateOfBirth !== '' ? $dateOfBirth : null,
                        $phone !== '' ? $phone : null,
                        $address !== '' ? $address : null,
                        $emergencyContact !== '' ? $emergencyContact : null
                    ]);
                }

                audit('user_created', 'users', $userId);
                $connection->commit();
                $_SESSION['admin_notice'] = 'User created. Share their temporary password and question answers securely.';
                $_SESSION['temporary_credentials'] = [
                    'username' => $username,
                    'password' => $temporaryPassword
                ];
                header('Location: admin_index.php');
                exit;
            } catch (PDOException $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                $error = $exception->getCode() === '23000'
                    ? 'That username or email is already in use.'
                    : 'The user could not be created. Please try again.';
            }
        }
    } elseif ($action === 'reset_password') {

        $userId = filter_var(
            $_POST['user_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]]
        );

        if ($userId === false || $userId === null) {
            $error = 'Select a valid user.';
        } else {
            $stmt = db()->prepare('SELECT id, username FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $targetUser = $stmt->fetch();

            if (!$targetUser) {
                $error = 'That user no longer exists.';
            } else {
                $temporaryPassword = rtrim(
                    strtr(base64_encode(random_bytes(18)), '+/', '-_'),
                    '='
                );
                $connection = db();

                try {
                    $connection->beginTransaction();
                    $stmt = $connection->prepare(
                        'UPDATE users
                         SET password_hash = ?,
                             failed_attempts = 0,
                             locked_until = NULL,
                             security_question_1 = NULL,
                             security_answer_1_hash = NULL,
                             security_question_2 = NULL,
                             security_answer_2_hash = NULL,
                             totp_secret = NULL,
                             totp_enabled = 0
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        password_hash_with_pepper($temporaryPassword),
                        $userId
                    ]);
                    audit('admin_password_reset', 'users', (int)$userId);
                    $connection->commit();
                    $_SESSION['admin_notice'] = 'Password reset. Share the temporary password securely.';
                    $_SESSION['temporary_credentials'] = [
                        'username' => $targetUser['username'],
                        'password' => $temporaryPassword
                    ];
                    header('Location: admin_index.php');
                    exit;
                } catch (PDOException $exception) {
                    if ($connection->inTransaction()) {
                        $connection->rollBack();
                    }
                    $error = 'The password could not be reset. Please try again.';
                }
            }
        }
    } elseif ($action === 'create_system_backup') {
        try {
            $backup = generate_system_backup(db(), (int)$_SESSION['user_id']);
            audit('system_backup_generated', 'system_backups', $backup['id']);
            $_SESSION['admin_notice'] = "Full system backup '{$backup['filename']}' generated successfully with records of everything in the system (" . number_format($backup['records_count']) . " total records). Ready for Cloudflare storage.";
            header('Location: admin_index.php#backup');
            exit;
        } catch (Throwable $e) {
            $error = 'Backup failed: ' . $e->getMessage();
        }
    } elseif ($action === 'create_restore_point') {
        $pointName = trim((string)($_POST['point_name'] ?? ''));
        $description = trim((string)($_POST['point_description'] ?? ''));
        if ($pointName === '') {
            $pointName = 'Restore Point ' . date('Y-m-d H:i:s');
        }
        try {
            $pointId = create_system_restore_point(db(), $pointName, $description !== '' ? $description : null, (int)$_SESSION['user_id']);
            audit('restore_point_created', 'system_restore_points', $pointId);
            $_SESSION['admin_notice'] = "Configuration restore point '{$pointName}' created successfully. Note: Restore points protect configuration settings and never alter system data.";
            header('Location: admin_index.php#backup');
            exit;
        } catch (Throwable $e) {
            $error = 'Could not create restore point: ' . $e->getMessage();
        }
    } elseif ($action === 'restore_configuration') {
        $restorePointId = filter_var($_POST['restore_point_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$restorePointId) {
            $error = 'Select a valid restore point.';
        } else {
            try {
                $restored = restore_system_configuration(db(), (int)$restorePointId, (int)$_SESSION['user_id']);
                audit('config_restore_point_restored', 'system_restore_points', (int)$restorePointId);
                $_SESSION['admin_notice'] = "Configuration successfully restored to point '{$restored['name']}' (ID: {$restored['id']}). System data (patients, invoices, payments, users) was NOT changed.";
                header('Location: admin_index.php#backup');
                exit;
            } catch (Throwable $e) {
                $error = 'Could not restore configuration: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update_configuration') {
        $appName = trim((string)($_POST['app_name'] ?? ''));
        $require2fa = !empty($_POST['require_2fa']);
        $sessionTimeoutMinutes = filter_var($_POST['session_timeout_minutes'] ?? 30, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        $maxLoginAttempts = filter_var($_POST['max_login_attempts'] ?? 5, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        $lockoutMinutes = filter_var($_POST['lockout_minutes'] ?? 15, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1440]]);
        $backupDest = trim((string)($_POST['backup_destination_url'] ?? ''));

        if ($appName === '' || strlen($appName) > 120) {
            $error = 'Enter a valid application name (1-120 characters).';
        } elseif ($sessionTimeoutMinutes === false) {
            $error = 'Session timeout must be between 1 and 1440 minutes.';
        } elseif ($maxLoginAttempts === false) {
            $error = 'Max login attempts must be between 1 and 20.';
        } elseif ($lockoutMinutes === false) {
            $error = 'Lockout minutes must be between 1 and 1440.';
        } else {
            try {
                create_system_restore_point(
                    db(),
                    'Auto-backup before configuration update (' . date('Y-m-d H:i') . ')',
                    'Automatic restore point captured before administrator changed system configuration.',
                    (int)$_SESSION['user_id']
                );

                save_system_config([
                    'app_name' => $appName,
                    'require_2fa' => $require2fa,
                    'session_timeout' => $sessionTimeoutMinutes * 60,
                    'max_login_attempts' => $maxLoginAttempts,
                    'lockout_minutes' => $lockoutMinutes,
                    'backup_destination_url' => $backupDest !== '' ? $backupDest : 'https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home',
                    'brevo_api_key' => trim((string)($_POST['brevo_api_key'] ?? '')),
                    'resend_api_key' => trim((string)($_POST['resend_api_key'] ?? '')),
                    'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
                    'smtp_port' => (int)($_POST['smtp_port'] ?? 587),
                    'smtp_user' => trim((string)($_POST['smtp_user'] ?? '')),
                    'smtp_pass' => trim((string)($_POST['smtp_pass'] ?? '')),
                    'smtp_from_email' => trim((string)($_POST['smtp_from_email'] ?? '')),
                    'smtp_from_name' => trim((string)($_POST['smtp_from_name'] ?? ''))
                ]);
                audit('config_updated', 'system_config', null);
                $_SESSION['admin_notice'] = 'System configuration updated successfully. An automatic restore point was saved before the update.';
                header('Location: admin_index.php#backup');
                exit;
            } catch (Throwable $e) {
                $error = 'Could not update configuration: ' . $e->getMessage();
            }
        }
    } else {
        $error = 'Unknown administrative action.';
    }
}


$users = db()->query(

    "SELECT

        id,

        username,

        full_name,

        email,

        role,

        is_active,

        security_answer_1_hash,

        security_answer_2_hash,

        last_login_at,

        created_at

     FROM users

     ORDER BY created_at DESC"

)->fetchAll();

$financialSummary = db()->query(
    "SELECT
          COUNT(CASE WHEN i.status IN ('paid', 'unpaid') THEN 1 END) AS total_invoices,
          COALESCE(SUM(CASE WHEN i.status IN ('paid', 'unpaid') THEN i.amount ELSE 0 END), 0) AS total_billed,
          COALESCE(SUM(COALESCE(p.paid_amount, 0)), 0) AS total_paid,
          COALESCE(SUM(CASE WHEN i.status = 'unpaid' THEN GREATEST(i.amount - COALESCE(p.paid_amount, 0), 0) ELSE 0 END), 0) AS total_outstanding
      FROM invoices i
      LEFT JOIN (
          SELECT invoice_id, SUM(amount) AS paid_amount
          FROM invoice_payments
          WHERE voided_at IS NULL
          GROUP BY invoice_id
      ) p ON p.invoice_id = i.id"
)->fetch();

$patients = db()->query(
    "SELECT
        p.id,
        u.full_name,
        u.email,
        p.date_of_birth,
        p.phone,
        p.address,
        p.emergency_contact,
          COALESCE(SUM(COALESCE(payments.paid_amount, 0)), 0) AS amount_paid,
          COALESCE(SUM(CASE WHEN i.status = 'unpaid' THEN GREATEST(i.amount - COALESCE(payments.paid_amount, 0), 0) ELSE 0 END), 0) AS balance
     FROM patients p
     JOIN users u ON u.id = p.user_id
     LEFT JOIN invoices i ON i.patient_id = p.id
      LEFT JOIN (
          SELECT invoice_id, SUM(amount) AS paid_amount
          FROM invoice_payments
          WHERE voided_at IS NULL
          GROUP BY invoice_id
      ) payments ON payments.invoice_id = i.id
      GROUP BY p.id, u.full_name, u.email, p.date_of_birth, p.phone, p.address, p.emergency_contact
     ORDER BY u.full_name"
)->fetchAll();

$invoices = db()->query(
    "SELECT
          i.id,
        u.full_name AS patient,
        i.description,
        i.amount,
          COALESCE(payments.paid_amount, 0) AS amount_paid,
          GREATEST(i.amount - COALESCE(payments.paid_amount, 0), 0) AS balance,
        i.status,
        i.created_at,
        i.paid_at
     FROM invoices i
     JOIN patients p ON p.id = i.patient_id
     JOIN users u ON u.id = p.user_id
      LEFT JOIN (
          SELECT invoice_id, SUM(amount) AS paid_amount
          FROM invoice_payments
          WHERE voided_at IS NULL
          GROUP BY invoice_id
      ) payments ON payments.invoice_id = i.id
     ORDER BY i.created_at DESC"
)->fetchAll();

$patientOptions = db()->query(
    "SELECT p.id, u.full_name
     FROM patients p
     JOIN users u ON u.id = p.user_id
     WHERE u.role = 'patient' AND u.is_active = 1
     ORDER BY u.full_name"
)->fetchAll();

$payments = db()->query(
    "SELECT
        payment.id,
        payment.invoice_id,
        payment.amount,
        payment.paid_at,
        payment.voided_at,
        payment.void_reason,
        recorder.full_name AS recorded_by,
        remover.full_name AS removed_by,
        patient.full_name AS patient
     FROM invoice_payments payment
     JOIN invoices invoice ON invoice.id = payment.invoice_id
     JOIN patients patient_profile ON patient_profile.id = invoice.patient_id
     JOIN users patient ON patient.id = patient_profile.user_id
     LEFT JOIN users recorder ON recorder.id = payment.recorded_by
     LEFT JOIN users remover ON remover.id = payment.voided_by
     ORDER BY payment.paid_at DESC, payment.id DESC"
)->fetchAll();

ensure_initial_restore_point(db(), (int)($_SESSION['user_id'] ?? null));
$systemConfig = get_system_config();

$restorePoints = db()->query(
    "SELECT rp.*, u.full_name AS creator_name
     FROM system_restore_points rp
     LEFT JOIN users u ON u.id = rp.created_by
     ORDER BY rp.created_at DESC"
)->fetchAll();

$systemBackups = db()->query(
    "SELECT b.*, u.full_name AS creator_name
     FROM system_backups b
     LEFT JOIN users u ON u.id = b.created_by
     ORDER BY b.created_at DESC
     LIMIT 15"
)->fetchAll();

$recentActivityLogs = db()->query(
    "SELECT a.*, u.username, u.full_name, u.role
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC
     LIMIT 25"
)->fetchAll();

$systemCounts = [
    'audit_logs' => (int)db()->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn(),
    'login_events' => (int)db()->query("SELECT COUNT(*) FROM login_events")->fetchColumn(),
    'users' => (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    'patients' => (int)db()->query("SELECT COUNT(*) FROM patients")->fetchColumn(),
    'invoices' => (int)db()->query("SELECT COUNT(*) FROM invoices")->fetchColumn(),
    'payments' => (int)db()->query("SELECT COUNT(*) FROM invoice_payments")->fetchColumn(),
    'restore_points' => count($restorePoints),
    'backups' => (int)db()->query("SELECT COUNT(*) FROM system_backups")->fetchColumn()
];
$totalRecordsCount = $systemCounts['audit_logs'] + $systemCounts['login_events'] + $systemCounts['users'] + $systemCounts['patients'] + $systemCounts['invoices'] + $systemCounts['payments'];

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
Admin Dashboard
</title>

<link
    rel="stylesheet"
    href="style.css?v=20261018_mobile1"
>

</head>


<body>

<nav class="admin-navbar">
    <div class="admin-brand">
        <strong>SecureHealth Portal</strong>
        <span class="admin-brand-subtitle">System Administration</span>
    </div>

    <div class="admin-user-menu">
        <div class="admin-user-pill">
            <span><?= e($_SESSION['full_name']) ?></span>
            <span class="status-badge badge-role-admin">Admin</span>
        </div>
        <a href="change_password.php" class="quick-action-btn" style="padding:6px 14px; font-size:0.82rem;">Change Password</a>
        <a href="security_questions_change.php" class="quick-action-btn" style="padding:6px 14px; font-size:0.82rem;">Change Security Questions</a>
        <a href="logout.php" class="quick-action-btn" style="padding:6px 14px; font-size:0.82rem; color:var(--coral);">Logout</a>
    </div>
</nav>

<main class="wide">

    <?php if ($error): ?>
    <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($notice): ?>
    <div class="alert info"><?= e($notice) ?></div>
    <?php endif; ?>

    <?php if ($paymentError): ?>
    <div class="alert error"><?= e($paymentError) ?></div>
    <?php endif; ?>

    <?php if ($paymentNotice): ?>
    <div class="alert info"><?= e($paymentNotice) ?></div>
    <?php endif; ?>

    <?php if ($invoiceError): ?>
    <div class="alert error"><?= e($invoiceError) ?></div>
    <?php endif; ?>

    <?php if ($invoiceNotice): ?>
    <div class="alert info"><?= e($invoiceNotice) ?></div>
    <?php endif; ?>

    <?php if ($temporaryCredentials): ?>
    <div class="panel" style="border-left: 4px solid var(--lime); background: #fdfef9; margin-bottom: 24px;">
        <strong style="color: var(--green-dark); font-size: 1.05rem;">Temporary Sign-In Credentials Generated</strong>
        <p style="margin: 8px 0 4px;">Username: <strong><?= e($temporaryCredentials['username']) ?></strong></p>
        <p style="margin: 4px 0 10px;">Temporary Password: <code style="font-size:1.05rem; font-weight:700; color:var(--coral);"><?= e($temporaryCredentials['password']) ?></code></p>
        <p class="muted small" style="margin:0;">This password is shown only once. Share it and the user's selected question answers securely. Have the user change their password immediately upon initial login.</p>
    </div>
    <?php endif; ?>

    <header class="page-heading">
        <div>
            <p class="eyebrow">SYSTEM OVERVIEW</p>
            <h1>Admin Dashboard</h1>
            <p>Real-time management of user provisioning, clinical billing, Cloudflare encrypted backups, and system security policies.</p>
        </div>
        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <a href="#backup" class="button-cloudflare" onclick="switchAdminTab('tab-backup');" style="padding:9px 16px; font-size:0.86rem;">
                Cloudflare Storage
            </a>
            <span class="page-badge">Active Session</span>
        </div>
    </header>

    <!-- Executive KPI Grid -->
    <div class="kpi-grid">
        <div class="kpi-card accent-green">
            <div class="kpi-header">
                <span class="kpi-label">Total Invoiced</span>
            </div>
            <div class="kpi-value">GHS <?= number_format((float)$financialSummary['total_billed'], 2) ?></div>
            <p class="kpi-subtext"><?= e((string)$financialSummary['total_invoices']) ?> invoices issued to date</p>
        </div>

        <div class="kpi-card accent-blue">
            <div class="kpi-header">
                <span class="kpi-label">Total Collected</span>
            </div>
            <div class="kpi-value">GHS <?= number_format((float)$financialSummary['total_paid'], 2) ?></div>
            <p class="kpi-subtext">Received in clinic ledger</p>
        </div>

        <div class="kpi-card accent-amber">
            <div class="kpi-header">
                <span class="kpi-label">Outstanding Balance</span>
            </div>
            <div class="kpi-value">GHS <?= number_format((float)$financialSummary['total_outstanding'], 2) ?></div>
            <p class="kpi-subtext">Pending patient receivables</p>
        </div>

        <div class="kpi-card accent-dark">
            <div class="kpi-header">
                <span class="kpi-label">Active Patients</span>
            </div>
            <div class="kpi-value"><?= number_format($systemCounts['patients']) ?></div>
            <p class="kpi-subtext">Across <?= number_format($systemCounts['users']) ?> total registered accounts</p>
        </div>

        <div class="kpi-card accent-lime">
            <div class="kpi-header">
                <span class="kpi-label">Cloud Protection</span>
            </div>
            <div class="kpi-value"><?= count($restorePoints) ?> Points</div>
            <p class="kpi-subtext"><?= (int)$systemCounts['backups'] ?> backups synced to Cloudflare</p>
        </div>
    </div>

    <!-- Segmented Navigation Tabs -->
    <nav class="admin-tabs-bar" id="adminTabsBar" aria-label="Dashboard Section Navigation">
        <button type="button" class="admin-tab-btn active" data-tab="tab-overview" onclick="switchAdminTab('tab-overview')">
            <span>Overview</span>
        </button>
        <button type="button" class="admin-tab-btn" data-tab="tab-users" onclick="switchAdminTab('tab-users')">
            <span>User Accounts</span>
            <span class="tab-count"><?= count($users) ?></span>
        </button>
        <button type="button" class="admin-tab-btn" data-tab="tab-patients" onclick="switchAdminTab('tab-patients')">
            <span>Patient Records</span>
            <span class="tab-count"><?= count($patients) ?></span>
        </button>
        <button type="button" class="admin-tab-btn" data-tab="tab-billing" onclick="switchAdminTab('tab-billing')">
            <span>Invoices &amp; Billing</span>
            <span class="tab-count"><?= count($invoices) ?></span>
        </button>
        <button type="button" class="admin-tab-btn" data-tab="tab-backup" onclick="switchAdminTab('tab-backup')">
            <span>Backups &amp; Logs</span>
            <span class="tab-count"><?= count($systemBackups) ?></span>
        </button>
        <button type="button" class="admin-tab-btn" data-tab="tab-config" onclick="switchAdminTab('tab-config')">
            <span>System Configuration</span>
            <span class="tab-count"><?= count($restorePoints) ?></span>
        </button>
    </nav>

    <!-- ========================================================
         TAB 1: OVERVIEW & QUICK ACTIONS
    ============================================================= -->
    <div id="tab-overview" class="admin-tab-pane active">
        <div class="quick-actions-grid">
            <button type="button" class="quick-action-btn" onclick="switchAdminTab('tab-billing')">
                <span>Add Amount Due</span>
            </button>
            <button type="button" class="quick-action-btn" onclick="switchAdminTab('tab-users')">
                <span>Create New User</span>
            </button>
            <form method="POST" style="margin:0; display:inline;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_system_backup">
                <button type="submit" class="quick-action-btn btn-primary">
                    <span>Generate Cloud Backup</span>
                </button>
            </form>
            <button type="button" class="quick-action-btn" onclick="switchAdminTab('tab-config')">
                <span>System Settings</span>
            </button>
            <a href="https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home" target="_blank" rel="noopener noreferrer" class="quick-action-btn">
                <span>Open Cloudflare</span>
            </a>
        </div>

        <div class="dashboard-layout" style="margin-bottom: 24px;">
            <!-- Left: Financial Ledger Summary -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-title-group">
                        <h3>Recent Billing Activity</h3>
                    </div>
                    <button type="button" class="quick-action-btn" style="padding:4px 12px; font-size:0.8rem;" onclick="switchAdminTab('tab-billing')">
                        View Full Ledger
                    </button>
                </div>
                <div class="modern-table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$invoices): ?>
                            <tr><td colspan="5" class="muted">No invoices generated yet.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_slice($invoices, 0, 5) as $inv): ?>
                                <tr>
                                    <td><strong><?= e($inv['patient']) ?></strong></td>
                                    <td><?= e($inv['description']) ?></td>
                                    <td>GHS <?= number_format((float)$inv['amount'], 2) ?></td>
                                    <td>GHS <?= number_format((float)$inv['balance'], 2) ?></td>
                                    <td>
                                        <?php if ($inv['status'] === 'paid'): ?>
                                            <span class="status-badge badge-paid">PAID</span>
                                        <?php else: ?>
                                            <span class="status-badge badge-unpaid">UNPAID</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right: System Health & Security Snapshot -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-title-group">
                        <h3>Security &amp; Protection Status</h3>
                    </div>
                    <button type="button" class="quick-action-btn" style="padding:4px 12px; font-size:0.8rem;" onclick="switchAdminTab('tab-config')">
                        Manage Settings
                    </button>
                </div>
                <div style="display:flex; flex-direction:column; gap:14px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--canvas); border:1px solid var(--line); border-radius:8px;">
                        <div>
                            <strong>Two-Factor Authentication (2FA)</strong>
                            <p class="muted small" style="margin:2px 0 0;">Enforce email OTP &amp; security questions</p>
                        </div>
                        <div>
                            <?= !empty($systemConfig['require_2fa']) 
                                ? '<span class="status-badge badge-paid">Mandatory (Active)</span>' 
                                : '<span class="status-badge badge-inactive">Optional</span>' ?>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--canvas); border:1px solid var(--line); border-radius:8px;">
                        <div>
                            <strong>Real-Time Email OTP Provider</strong>
                            <p class="muted small" style="margin:2px 0 0;">Automated code dispatch via Brevo / SMTP</p>
                        </div>
                        <div>
                            <?= !empty($systemConfig['brevo_api_key'])
                                ? '<span class="status-badge badge-paid">Brevo API Connected</span>'
                                : (!empty($systemConfig['resend_api_key'])
                                    ? '<span class="status-badge badge-paid">Resend API Connected</span>'
                                    : '<span class="status-badge badge-inactive">SMTP / Standard</span>') ?>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--canvas); border:1px solid var(--line); border-radius:8px;">
                        <div>
                            <strong>Session Inactivity Timeout</strong>
                            <p class="muted small" style="margin:2px 0 0;">Auto-logout protection for idle sessions</p>
                        </div>
                        <div>
                            <span class="status-badge badge-role-admin"><?= round(((int)($systemConfig['session_timeout'] ?? 1800)) / 60) ?> minutes</span>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--canvas); border:1px solid var(--line); border-radius:8px;">
                        <div>
                            <strong>Account Lockout Threshold</strong>
                            <p class="muted small" style="margin:2px 0 0;">Brute force defense trigger</p>
                        </div>
                        <div>
                            <span class="status-badge badge-role-accountant"><?= e((string)($systemConfig['max_login_attempts'] ?? 5)) ?> attempts</span>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; padding:10px 14px; background:var(--canvas); border:1px solid var(--line); border-radius:8px;">
                        <div>
                            <strong>Cloudflare Storage Target</strong>
                            <p class="muted small" style="margin:2px 0 0;">AES-256-GCM encrypted offsite vault</p>
                        </div>
                        <div>
                            <span class="status-badge badge-paid">Synchronized</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================
         TAB 2: USER MANAGEMENT
    ============================================================= -->
    <div id="tab-users" class="admin-tab-pane">
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>User Accounts Directory</h2>
                    <span class="table-count-badge"><?= count($users) ?> accounts</span>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <input type="text" class="table-filter-input" placeholder="Search users by name, role..." onkeyup="filterTable(this, 'users-table')">
                </div>
            </div>

            <div class="modern-table-container">
                <table id="users-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Security Questions</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td>#<?= e((string)$user['id']) ?></td>
                            <td><strong><?= e($user['username']) ?></strong></td>
                            <td><?= e($user['full_name']) ?></td>
                            <td><?= e($user['email']) ?></td>
                            <td>
                                <?php if ($user['role'] === 'admin'): ?>
                                    <span class="status-badge badge-role-admin">ADMINISTRATOR</span>
                                <?php elseif ($user['role'] === 'accountant'): ?>
                                    <span class="status-badge badge-role-accountant">ACCOUNTANT</span>
                                <?php else: ?>
                                    <span class="status-badge badge-role-patient">PATIENT</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= $user['is_active']
                                    ? '<span class="status-badge badge-active">Active</span>'
                                    : '<span class="status-badge badge-inactive">Inactive</span>' ?>
                            </td>
                            <td>
                                <?= !empty($user['security_answer_1_hash']) && !empty($user['security_answer_2_hash'])
                                    ? '<span class="status-badge badge-paid">Configured</span>'
                                    : '<span class="status-badge badge-unpaid">Required</span>' ?>
                            </td>
                            <td style="font-size:0.85rem; color:var(--muted);"><?= e($user['last_login_at'] ?? 'Never') ?></td>
                            <td>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Generate a temporary password reset for user <?= e($user['username']) ?>?');">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
                                    <button type="submit" class="quick-action-btn" style="padding:4px 10px; font-size:0.8rem;">Reset Password</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Provision New User Card -->
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>Provision User Account</h2>
                </div>
            </div>
            <form method="POST" class="panel form-grid" style="box-shadow:none; border:none; padding:0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="create_user">

                <div>
                    <label for="username">Username</label>
                    <input id="username" name="username" maxlength="50" placeholder="e.g. jdoe" required>
                </div>
                <div>
                    <label for="full_name">Full Name</label>
                    <input id="full_name" name="full_name" maxlength="120" placeholder="e.g. Jane Doe" required>
                </div>
                <div>
                    <label for="email">Email Address</label>
                    <input id="email" name="email" type="email" maxlength="190" placeholder="jane.doe@example.com" required>
                </div>
                <div>
                    <label for="role">System Role</label>
                    <select id="role" name="role" required>
                        <option value="admin">Administrator</option>
                        <option value="accountant">Accountant</option>
                        <option value="patient" selected>Patient</option>
                    </select>
                </div>
                <div class="span-all question-guidance">
                    Ask the user to choose their preferred security questions and provide answers privately. The answers will be required upon sign-in.
                </div>
                <div>
                    <label for="security_question_1">Security Question 1</label>
                    <select id="security_question_1" name="security_question_1" required>
                        <?php foreach (security_question_options('patient') as $key => $question): ?>
                        <option value="<?= e($key) ?>"><?= e($question) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="security_answer_1">Answer 1</label>
                    <input id="security_answer_1" name="security_answer_1" type="password" minlength="3" maxlength="200" autocomplete="new-password" placeholder="Answer 1" required>
                </div>
                <div>
                    <label for="security_question_2">Security Question 2</label>
                    <select id="security_question_2" name="security_question_2" required>
                        <?php foreach (security_question_options('patient') as $key => $question): ?>
                        <option value="<?= e($key) ?>" <?= $key === 'first_pet' ? 'selected' : '' ?>><?= e($question) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="security_answer_2">Answer 2</label>
                    <input id="security_answer_2" name="security_answer_2" type="password" minlength="3" maxlength="200" autocomplete="new-password" placeholder="Answer 2" required>
                </div>
                <div>
                    <label for="date_of_birth">Patient Date of Birth (Optional)</label>
                    <input id="date_of_birth" name="date_of_birth" type="date">
                </div>
                <div>
                    <label for="phone">Patient Phone (Optional)</label>
                    <input id="phone" name="phone" maxlength="30" placeholder="+233 ...">
                </div>
                <div class="span-all">
                    <label for="address">Patient Address (Optional)</label>
                    <input id="address" name="address" maxlength="255" placeholder="Residential address">
                </div>
                <div class="span-all">
                    <label for="emergency_contact">Emergency Contact (Optional)</label>
                    <input id="emergency_contact" name="emergency_contact" maxlength="120" placeholder="Name and telephone">
                </div>
                <button type="submit" class="button-backup" style="margin-top:10px;">Create User Account</button>
            </form>
        </div>
    </div>

    <!-- ========================================================
         TAB 3: PATIENT DIRECTORY
    ============================================================= -->
    <div id="tab-patients" class="admin-tab-pane">
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>Patient Records Directory</h2>
                    <span class="table-count-badge"><?= count($patients) ?> registered patients</span>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <input type="text" class="table-filter-input" placeholder="Search patients by name, phone..." onkeyup="filterTable(this, 'patients-table')">
                </div>
            </div>

            <div class="modern-table-container">
                <table id="patients-table">
                    <thead>
                        <tr>
                            <th>Patient Name</th>
                            <th>Email Address</th>
                            <th>Date of Birth</th>
                            <th>Phone</th>
                            <th>Residential Address</th>
                            <th>Emergency Contact</th>
                            <th>Total Paid</th>
                            <th>Outstanding Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$patients): ?>
                        <tr><td colspan="8" class="muted">No patient accounts registered yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($patients as $patient): ?>
                        <tr>
                            <td><strong><?= e($patient['full_name']) ?></strong></td>
                            <td><?= e($patient['email']) ?></td>
                            <td><?= e($patient['date_of_birth'] ?? '—') ?></td>
                            <td><?= e($patient['phone'] ?? '—') ?></td>
                            <td style="max-width:220px;"><?= e($patient['address'] ?? '—') ?></td>
                            <td><?= e($patient['emergency_contact'] ?? '—') ?></td>
                            <td><strong style="color:var(--green); font-size:0.95rem;">GHS <?= number_format((float)$patient['amount_paid'], 2) ?></strong></td>
                            <td>
                                <?php if ((float)$patient['balance'] > 0): ?>
                                    <span class="status-badge badge-unpaid">GHS <?= number_format((float)$patient['balance'], 2) ?> DUE</span>
                                <?php else: ?>
                                    <span class="status-badge badge-paid">PAID IN FULL</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================
         TAB 4: INVOICES & BILLING
    ============================================================= -->
    <div id="tab-billing" class="admin-tab-pane">
        <!-- Add Amount Due Card -->
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>Add Amount Due</h2>
                </div>
            </div>
            <form method="POST" action="create_invoice.php" class="panel form-grid" style="box-shadow:none; border:none; padding:0;">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <div>
                    <label for="invoice_patient_admin">Select Patient</label>
                    <select id="invoice_patient_admin" name="patient_id" required <?= $patientOptions ? '' : 'disabled' ?>>
                        <?php if (!$patientOptions): ?>
                            <option value="">No active patient accounts</option>
                        <?php else: ?>
                            <option value="">Choose a patient...</option>
                            <?php foreach ($patientOptions as $patientOption): ?>
                                <option value="<?= e((string)$patientOption['id']) ?>"><?= e($patientOption['full_name']) ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>
                <div>
                    <label for="invoice_amount_admin">Amount Due (GHS)</label>
                    <input id="invoice_amount_admin" type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
                </div>
                <div class="span-all">
                    <label for="invoice_description_admin">Service / Invoice Description</label>
                    <input id="invoice_description_admin" name="description" maxlength="255" placeholder="e.g. Consultation fee, Lab diagnostics, Prescription medications" required>
                </div>
                <button type="submit" class="button-backup" <?= $patientOptions ? '' : 'disabled' ?>>Generate Invoice</button>
            </form>
        </div>

        <!-- All Invoices Card -->
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>All Invoices</h2>
                    <span class="table-count-badge"><?= count($invoices) ?> records</span>
                </div>
                <div style="display:flex; align-items:center; gap:12px;">
                    <input type="text" class="table-filter-input" placeholder="Search invoices by patient, status..." onkeyup="filterTable(this, 'invoices-table')">
                </div>
            </div>

            <div class="modern-table-container">
                <table id="invoices-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Paid So Far</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Issued At</th>
                            <th>Paid At</th>
                            <th>Record Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$invoices): ?>
                        <tr><td colspan="9" class="muted">No invoices generated yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td><strong><?= e($invoice['patient']) ?></strong></td>
                            <td><?= e($invoice['description']) ?></td>
                            <td>GHS <?= number_format((float)$invoice['amount'], 2) ?></td>
                            <td>GHS <?= number_format((float)$invoice['amount_paid'], 2) ?></td>
                            <td><strong style="color:<?= (float)$invoice['balance'] > 0 ? 'var(--coral)' : 'var(--green)' ?>;">GHS <?= number_format((float)$invoice['balance'], 2) ?></strong></td>
                            <td>
                                <?php if ($invoice['status'] === 'paid'): ?>
                                    <span class="status-badge badge-paid">PAID</span>
                                <?php else: ?>
                                    <span class="status-badge badge-unpaid">UNPAID</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.84rem; color:var(--muted);"><?= e($invoice['created_at']) ?></td>
                            <td style="font-size:0.84rem; color:var(--muted);"><?= e($invoice['paid_at'] ?? '—') ?></td>
                            <td>
                                <?php if ($invoice['status'] === 'unpaid' && (float)$invoice['balance'] > 0): ?>
                                <form method="POST" action="record_payment.php" class="payment-form" style="display:flex; gap:6px;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="invoice_id" value="<?= e((string)$invoice['id']) ?>">
                                    <input type="number" name="amount" min="0.01" max="<?= e(number_format((float)$invoice['balance'], 2, '.', '')) ?>" step="0.01" placeholder="GHS" style="width:90px; padding:4px 8px; font-size:0.85rem;" required>
                                    <button type="submit" class="quick-action-btn btn-primary" style="padding:4px 10px; font-size:0.82rem;">Record</button>
                                </form>
                                <?php else: ?>
                                <span class="muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment History Ledger -->
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>Payment Transaction History &amp; Void Audit</h2>
                    <span class="table-count-badge"><?= count($payments) ?> payments</span>
                </div>
            </div>

            <div class="modern-table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Invoice #</th>
                            <th>Payment Amount</th>
                            <th>Recorded Date</th>
                            <th>Recorded By</th>
                            <th>Correction / Removal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$payments): ?>
                        <tr><td colspan="6" class="muted">No payment transactions recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><strong><?= e($payment['patient']) ?></strong></td>
                            <td>#<?= e((string)$payment['invoice_id']) ?></td>
                            <td><strong style="color:var(--green); font-size:0.95rem;">GHS <?= number_format((float)$payment['amount'], 2) ?></strong></td>
                            <td style="font-size:0.85rem; color:var(--muted);"><?= e($payment['paid_at']) ?></td>
                            <td><?= e($payment['recorded_by'] ?? 'Account unavailable') ?></td>
                            <td>
                                <?php if ($payment['voided_at'] === null): ?>
                                <form method="POST" action="remove_payment.php" class="remove-payment-form" onsubmit="return confirm('Remove this payment from the balance? The correction will be audited in system logs.')" style="display:flex; gap:6px;">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="payment_id" value="<?= e((string)$payment['id']) ?>">
                                    <input type="text" name="reason" minlength="5" maxlength="255" placeholder="Reason for removal" style="width:180px; padding:4px 8px; font-size:0.82rem;" required>
                                    <button class="button-danger" type="submit" style="padding:4px 10px; font-size:0.82rem;">Void</button>
                                </form>
                                <?php else: ?>
                                <span style="color:var(--coral); font-weight:600; font-size:0.82rem;">VOIDED</span> by <?= e($payment['removed_by'] ?? 'Account unavailable') ?> on <?= e($payment['voided_at']) ?>.
                                <br><span class="muted small">Reason: <?= e($payment['void_reason'] ?? '') ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================
         TAB 5: SYSTEM BACKUP & CLOUDFLARE AUDIT TRAIL
    ============================================================= -->
    <div id="tab-backup" class="admin-tab-pane">
        <section id="backup">
            <div class="content-card" style="border-left: 4px solid var(--amber);">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; flex-wrap:wrap; gap:18px;">
                    <div>
                        <span class="backup-badge badge-cloudflare">Cloudflare Storage Integration</span>
                        <h2 style="margin: 8px 0 6px; font-size: 1.45rem;">Cloudflare Enterprise Backup Destination</h2>
                        <p style="margin: 0 0 10px;">
                            Active Destination Vault: <a href="https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home" target="_blank" rel="noopener noreferrer"><code>https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home</code></a>
                        </p>
                        <p class="muted small" style="margin:0; line-height:1.5;">
                            Backups are encrypted using AES-256-GCM. Cloud storage and R2 object storage are routed to your Cloudflare account <code>bf59619b0589ac8a0c46f818580a4755</code> with SHA-256 integrity verification.
                        </p>
                    </div>
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <a href="https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home" target="_blank" rel="noopener noreferrer" class="button-cloudflare">
                            Open Cloudflare Storage
                        </a>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="create_system_backup">
                            <button type="submit" class="button-backup">
                                Generate Backup (All System Records)
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="grid backup-grid">
                <div class="panel">
                    <strong>Total System Records</strong><br>
                    <span style="font-size: 1.6rem; font-weight:800; color:var(--green);"><?= number_format($totalRecordsCount) ?></span>
                    <p class="muted small" style="margin:4px 0 0;">Users, Patients, Invoices, Payments, Logins, Audit Logs</p>
                </div>
                <div class="panel">
                    <strong>Audit Log Entries</strong><br>
                    <span style="font-size: 1.6rem; font-weight:800; color:var(--green);"><?= number_format($systemCounts['audit_logs']) ?></span>
                    <p class="muted small" style="margin:4px 0 0;">Administrative and user actions recorded</p>
                </div>
                <div class="panel">
                    <strong>Login Events</strong><br>
                    <span style="font-size: 1.6rem; font-weight:800; color:var(--green);"><?= number_format($systemCounts['login_events']) ?></span>
                    <p class="muted small" style="margin:4px 0 0;">Security and authentication events</p>
                </div>
                <div class="panel">
                    <strong>Configuration Restore Points</strong><br>
                    <span style="font-size: 1.6rem; font-weight:800; color:var(--green);"><?= count($restorePoints) ?></span>
                    <p class="muted small" style="margin:4px 0 0;">Ready-to-apply configuration baselines</p>
                </div>
            </div>

            <!-- Backup Archive History -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-title-group">
                        <h2>System Backup Archive History</h2>
                        <span class="table-count-badge"><?= count($systemBackups) ?> archives</span>
                    </div>
                </div>
                <div class="modern-table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Backup Archive</th>
                                <th>Records</th>
                                <th>Filesize</th>
                                <th>Cloud Destination</th>
                                <th>Created</th>
                                <th>Initiated By</th>
                                <th>Download</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$systemBackups): ?>
                            <tr><td colspan="7" class="muted">No system backups generated yet. Click "Generate Backup" above to export records of everything that goes on in the system.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($systemBackups as $backup): ?>
                            <tr>
                                <td><strong><?= e($backup['filename']) ?></strong></td>
                                <td><?= number_format((int)$backup['records_count']) ?> records</td>
                                <td><?= round((int)$backup['filesize'] / 1024, 1) ?> KB</td>
                                <td>
                                    <a href="<?= e($backup['destination']) ?>" target="_blank" rel="noopener noreferrer" style="font-size:0.85rem;">
                                        Cloudflare Storage
                                    </a>
                                </td>
                                <td style="font-size:0.85rem; color:var(--muted);"><?= e($backup['created_at']) ?></td>
                                <td><?= e($backup['creator_name'] ?? 'System Task') ?></td>
                                <td>
                                    <?php if (str_ends_with($backup['filename'], '.sql')): ?>
                                    <a href="download_backup.php?id=<?= e((string)$backup['id']) ?>" class="quick-action-btn btn-primary" style="padding:4px 10px; font-size:0.82rem;">
                                        Download SQL
                                    </a>
                                    <?php else: ?>
                                    <span class="muted small">CLI Encrypted Archive</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- System Activity Trail -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-title-group">
                        <h2>System Activity Trail (Audit Logs)</h2>
                        <span class="table-count-badge"><?= count($recentActivityLogs) ?> recent events</span>
                    </div>
                    <div style="display:flex; align-items:center; gap:12px;">
                        <input type="text" class="table-filter-input" placeholder="Search activity logs..." onkeyup="filterTable(this, 'activity-table')">
                    </div>
                </div>
                <p class="muted" style="margin-top:0;">Comprehensive immutable audit log capturing user actions, financial updates, logins, and backups.</p>

                <div class="activity-table-wrap modern-table-container">
                    <table id="activity-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Action</th>
                                <th>Target Record</th>
                                <th>IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$recentActivityLogs): ?>
                            <tr><td colspan="6" class="muted">No system activity logged yet.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($recentActivityLogs as $log): ?>
                            <tr>
                                <td style="font-size:0.85rem; color:var(--muted);"><?= e($log['created_at']) ?></td>
                                <td><strong><?= e($log['full_name'] ?? ($log['username'] ?? 'System / Anonymous')) ?></strong></td>
                                <td><span class="backup-badge badge-info"><?= e($log['role'] ?? 'system') ?></span></td>
                                <td><code><?= e($log['action']) ?></code></td>
                                <td><?= e(($log['target_table'] ? $log['target_table'] . ' #' . $log['target_id'] : '—')) ?></td>
                                <td><code><?= e($log['ip_address'] ?? '—') ?></code></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <!-- ========================================================
         TAB 6: SYSTEM CONFIGURATION & RESTORE POINTS
    ============================================================= -->
    <div id="tab-config" class="admin-tab-pane">
        <div class="restore-guarantee-box">
            <strong style="font-size:1.05rem;">Data Protection &amp; Safe Configuration Guarantee</strong>
            <p style="margin: 6px 0 0; line-height: 1.5;">
                Restore points strictly govern and safeguard <strong>system security parameters</strong> (such as 2FA enforcement, session timeout durations, brute-force lockout rules, and Cloudflare cloud targets).
                <br>
                <strong>Restoring a configuration point will NEVER modify, overwrite, or delete any patient medical records, customer profiles, invoices, payments, or user database entries.</strong>
            </p>
        </div>

        <div class="dashboard-layout" style="margin-bottom: 24px;">
            <!-- Left: Create Named Restore Point -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-title-group">
                        <h3>Create Named Restore Point</h3>
                    </div>
                </div>
                <form method="POST" class="panel form-grid" style="box-shadow:none; border:none; padding:0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_restore_point">
                    <div class="span-all">
                        <label for="point_name">Restore Point Name</label>
                        <input id="point_name" name="point_name" placeholder="e.g. Pre-Audit Security Baseline" maxlength="120" required>
                    </div>
                    <div class="span-all">
                        <label for="point_description">Description / Notes (Optional)</label>
                        <input id="point_description" name="point_description" placeholder="e.g. Configuration before updating session timeout policy" maxlength="255">
                    </div>
                    <button type="submit" class="button-backup">Create Snapshot Point</button>
                </form>
            </div>

            <!-- Right: Update Live Configuration -->
            <div class="content-card">
                <div class="card-header-bar">
                    <div class="card-title-group">
                        <h3>Update Live Configuration</h3>
                    </div>
                </div>
                <form method="POST" class="panel form-grid" style="box-shadow:none; border:none; padding:0;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_configuration">
                    <div>
                        <label for="cfg_app_name">Application Name</label>
                        <input id="cfg_app_name" name="app_name" value="<?= e($systemConfig['app_name'] ?? 'Secure Health Management System') ?>" required>
                    </div>
                    <div>
                        <label for="cfg_require_2fa">Require 2FA for all users</label>
                        <select id="cfg_require_2fa" name="require_2fa">
                            <option value="0" <?= empty($systemConfig['require_2fa']) ? 'selected' : '' ?>>No (Optional)</option>
                            <option value="1" <?= !empty($systemConfig['require_2fa']) ? 'selected' : '' ?>>Yes (Mandatory)</option>
                        </select>
                    </div>
                    <div>
                        <label for="cfg_session_timeout">Session Timeout (minutes)</label>
                        <input id="cfg_session_timeout" type="number" name="session_timeout_minutes" min="1" max="1440" value="<?= e((string)(round(((int)($systemConfig['session_timeout'] ?? 1800)) / 60))) ?>" required>
                    </div>
                    <div>
                        <label for="cfg_max_attempts">Max Failed Login Attempts</label>
                        <input id="cfg_max_attempts" type="number" name="max_login_attempts" min="1" max="20" value="<?= e((string)($systemConfig['max_login_attempts'] ?? 5)) ?>" required>
                    </div>
                    <div>
                        <label for="cfg_lockout_minutes">Lockout Duration (minutes)</label>
                        <input id="cfg_lockout_minutes" type="number" name="lockout_minutes" min="1" max="1440" value="<?= e((string)($systemConfig['lockout_minutes'] ?? 15)) ?>" required>
                    </div>
                    <div class="span-all">
                        <label for="cfg_backup_destination">Cloudflare Backup URL</label>
                        <input id="cfg_backup_destination" name="backup_destination_url" value="<?= e($systemConfig['backup_destination_url'] ?? 'https://dash.cloudflare.com/bf59619b0589ac8a0c46f818580a4755/home') ?>" required>
                    </div>
                    <div class="span-all" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid var(--line);">
                        <h4 style="margin: 0 0 6px 0; color: var(--green-dark);">Real-Time Email OTP Settings (API / SMTP)</h4>
                        <p class="muted small" style="margin-bottom: 12px;">Enter an HTTP API Key (Brevo or Resend) or Gmail SMTP (Port 587 TLS with 16-letter App Password) for instant real-time OTP delivery directly to user inboxes.</p>
                    </div>
                    <div class="span-all">
                        <label for="cfg_brevo_api_key">Brevo API Key (Recommended - 300 free emails/day, bypasses port blocks)</label>
                        <input id="cfg_brevo_api_key" name="brevo_api_key" placeholder="xkeysib-..." value="<?= e($systemConfig['brevo_api_key'] ?? '') ?>">
                    </div>
                    <div class="span-all">
                        <label for="cfg_resend_api_key">Resend API Key (3,000 free emails/month)</label>
                        <input id="cfg_resend_api_key" name="resend_api_key" placeholder="re_..." value="<?= e($systemConfig['resend_api_key'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="cfg_smtp_host">SMTP Host</label>
                        <input id="cfg_smtp_host" name="smtp_host" placeholder="smtp.gmail.com" value="<?= e($systemConfig['smtp_host'] ?? 'smtp.gmail.com') ?>">
                    </div>
                    <div>
                        <label for="cfg_smtp_port">SMTP Port (Default 587 TLS)</label>
                        <input id="cfg_smtp_port" type="number" name="smtp_port" value="<?= e((string)($systemConfig['smtp_port'] ?? 587)) ?>">
                    </div>
                    <div>
                        <label for="cfg_smtp_user">SMTP Username / Gmail Address</label>
                        <input id="cfg_smtp_user" name="smtp_user" placeholder="yourname@gmail.com" value="<?= e($systemConfig['smtp_user'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="cfg_smtp_pass">SMTP Password / Google App Password</label>
                        <input id="cfg_smtp_pass" type="password" name="smtp_pass" placeholder="16-letter App Password" value="<?= e($systemConfig['smtp_pass'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="cfg_from_email">Sender Email Address</label>
                        <input id="cfg_from_email" name="smtp_from_email" placeholder="e.g. your email or verified sender" value="<?= e($systemConfig['smtp_from_email'] ?? '') ?>">
                    </div>
                    <div>
                        <label for="cfg_from_name">Sender Display Name</label>
                        <input id="cfg_from_name" name="smtp_from_name" value="<?= e($systemConfig['smtp_from_name'] ?? 'Secure Health System') ?>">
                    </div>
                    <button type="submit" class="button-backup">Save Configuration (Auto-Creates Restore Point)</button>
                </form>
            </div>
        </div>

        <!-- Saved Restore Points -->
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>Saved Configuration Restore Points</h2>
                    <span class="table-count-badge"><?= count($restorePoints) ?> points</span>
                </div>
            </div>

            <div class="modern-table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Point ID</th>
                            <th>Name &amp; Description</th>
                            <th>Configuration Snapshot</th>
                            <th>Created</th>
                            <th>Created By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($restorePoints as $rp): ?>
                        <?php $cfg = json_decode((string)$rp['configuration'], true) ?: []; ?>
                        <tr>
                            <td>#<?= e((string)$rp['id']) ?></td>
                            <td>
                                <strong><?= e($rp['name']) ?></strong>
                                <?php if (!empty($rp['description'])): ?>
                                <br><span class="muted small"><?= e($rp['description']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:0.84rem;">
                                2FA: <strong><?= !empty($cfg['require_2fa']) ? 'Required' : 'Optional' ?></strong> |
                                Timeout: <strong><?= round(((int)($cfg['session_timeout'] ?? 1800)) / 60) ?>m</strong> |
                                Attempts: <strong><?= e((string)($cfg['max_login_attempts'] ?? 5)) ?></strong> |
                                Lockout: <strong><?= e((string)($cfg['lockout_minutes'] ?? 15)) ?>m</strong>
                            </td>
                            <td style="font-size:0.85rem; color:var(--muted);"><?= e($rp['created_at']) ?></td>
                            <td><?= e($rp['creator_name'] ?? 'System Initialization') ?></td>
                            <td>
                                <form method="POST" class="inline-form" onsubmit="return confirm('Restore this configuration baseline? All patient records, invoices, and users will remain completely untouched.')">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="restore_configuration">
                                    <input type="hidden" name="restore_point_id" value="<?= e((string)$rp['id']) ?>">
                                    <button type="submit" class="quick-action-btn" style="padding:4px 10px; font-size:0.82rem;">Restore Config</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</main>

<script>
// Tab Switching
function switchAdminTab(tabId) {
    if (!tabId.startsWith('tab-')) {
        tabId = 'tab-' + tabId;
    }
    document.querySelectorAll('.admin-tab-pane').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.admin-tab-btn').forEach(el => el.classList.remove('active'));
    
    const targetPane = document.getElementById(tabId);
    const targetBtn = document.querySelector(`.admin-tab-btn[data-tab="${tabId}"]`);
    if (targetPane) targetPane.classList.add('active');
    if (targetBtn) targetBtn.classList.add('active');
    
    const hashName = tabId.replace('tab-', '');
    if (history.pushState) {
        history.pushState(null, null, '#' + hashName);
    } else {
        location.hash = hashName;
    }
}

// URL Hash routing on load & navigation
function checkUrlHash() {
    const hash = window.location.hash.replace('#', '');
    if (hash) {
        const tabName = 'tab-' + hash;
        if (document.getElementById(tabName)) {
            switchAdminTab(tabName);
        }
    }
}
window.addEventListener('load', checkUrlHash);
window.addEventListener('hashchange', checkUrlHash);

// Real-Time Instant Table Search Filtering
function filterTable(input, tableId) {
    const filter = input.value.toLowerCase();
    const table = document.getElementById(tableId);
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(filter) ? '' : 'none';
    });
}

// Role-based Security Questions
const questionsByRole = <?= json_encode([
    'admin' => security_question_options('admin'),
    'accountant' => security_question_options('accountant'),
    'patient' => security_question_options('patient')
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const roleSelect = document.getElementById('role');
const questionSelects = [
    document.getElementById('security_question_1'),
    document.getElementById('security_question_2')
];

function updateSecurityQuestions() {
    if (!roleSelect) return;
    const options = Object.entries(questionsByRole[roleSelect.value] || {});

    questionSelects.forEach((select, index) => {
        if (!select) return;
        const previousValue = select.value;
        select.replaceChildren();

        options.forEach(([value, label]) => {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = label;
            select.append(option);
        });

        if (options.some(([value]) => value === previousValue)) {
            select.value = previousValue;
        } else if (index === 1 && options.length > 1) {
            select.value = options[1][0];
        }
    });
}

if (roleSelect) {
    roleSelect.addEventListener('change', updateSecurityQuestions);
    updateSecurityQuestions();
}
</script>

</body>

</html>