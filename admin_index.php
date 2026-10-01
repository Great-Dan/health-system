<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';

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
    } else {
        $error = 'Unknown user management action.';
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
    href="style.css"
>

</head>


<body>


<nav>

<strong>
Admin Dashboard
</strong>


<span>

<?= e($_SESSION['full_name']) ?>

|

<a href="change_password.php">
Change Password
</a>

|

<a href="logout.php">
Logout
</a>

</span>

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
<div class="panel">
    <strong>Temporary sign-in credentials</strong>
    <p>Username: <?= e($temporaryCredentials['username']) ?></p>
    <p>Password: <code><?= e($temporaryCredentials['password']) ?></code></p>
    <p class="muted small">This password is shown once. Share it and the user's selected question answers securely. Have the user change the password after signing in.</p>
</div>
<?php endif; ?>


<h2>Financial Overview</h2>

<div class="grid">
    <div class="panel"><strong>Invoices</strong><br><?= e((string)$financialSummary['total_invoices']) ?></div>
    <div class="panel"><strong>Total Billed</strong><br>GHS <?= number_format((float)$financialSummary['total_billed'], 2) ?></div>
    <div class="panel"><strong>Amount Paid</strong><br>GHS <?= number_format((float)$financialSummary['total_paid'], 2) ?></div>
    <div class="panel"><strong>Outstanding</strong><br>GHS <?= number_format((float)$financialSummary['total_outstanding'], 2) ?></div>
</div>

<h2>Add Amount Due</h2>
<form method="POST" action="create_invoice.php" class="panel form-grid">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div>
        <label for="invoice_patient_admin">Patient</label>
        <select id="invoice_patient_admin" name="patient_id" required <?= $patientOptions ? '' : 'disabled' ?>>
            <?php if (!$patientOptions): ?>
                <option value="">No active patient accounts</option>
            <?php else: ?>
                <option value="">Choose a patient</option>
                <?php foreach ($patientOptions as $patientOption): ?>
                    <option value="<?= e((string)$patientOption['id']) ?>"><?= e($patientOption['full_name']) ?></option>
                <?php endforeach; ?>
            <?php endif; ?>
        </select>
    </div>
    <div>
        <label for="invoice_amount_admin">Amount due (GHS)</label>
        <input id="invoice_amount_admin" type="number" name="amount" min="0.01" step="0.01" required>
    </div>
    <div class="span-all">
        <label for="invoice_description_admin">Description</label>
        <input id="invoice_description_admin" name="description" maxlength="255" required>
    </div>
    <button type="submit" <?= $patientOptions ? '' : 'disabled' ?>>Add amount due</button>
</form>

<h2>
User Management
</h2>


<p>

Only administrators can access
this page.

</p>


<h3>Create User</h3>

<form method="POST" class="panel form-grid">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="create_user">

    <div>
        <label for="username">Username</label>
        <input id="username" name="username" maxlength="50" required>
    </div>
    <div>
        <label for="full_name">Full name</label>
        <input id="full_name" name="full_name" maxlength="120" required>
    </div>
    <div>
        <label for="email">Email</label>
        <input id="email" name="email" type="email" maxlength="190" required>
    </div>
    <div>
        <label for="role">Role</label>
        <select id="role" name="role" required>
            <option value="admin">Administrator</option>
            <option value="accountant">Accountant</option>
            <option value="patient" selected>Patient</option>
        </select>
    </div>
    <div class="span-all question-guidance">
        Ask the user to choose and provide answers privately. The answers will be requested after their password at login.
    </div>
    <div>
        <label for="security_question_1">Security question 1</label>
        <select id="security_question_1" name="security_question_1" required>
            <?php foreach (security_question_options('patient') as $key => $question): ?>
            <option value="<?= e($key) ?>"><?= e($question) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="security_answer_1">Answer 1</label>
        <input id="security_answer_1" name="security_answer_1" type="password" minlength="3" maxlength="200" autocomplete="new-password" required>
    </div>
    <div>
        <label for="security_question_2">Security question 2</label>
        <select id="security_question_2" name="security_question_2" required>
            <?php foreach (security_question_options('patient') as $key => $question): ?>
            <option value="<?= e($key) ?>" <?= $key === 'first_pet' ? 'selected' : '' ?>><?= e($question) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="security_answer_2">Answer 2</label>
        <input id="security_answer_2" name="security_answer_2" type="password" minlength="3" maxlength="200" autocomplete="new-password" required>
    </div>
    <div>
        <label for="date_of_birth">Patient date of birth (optional)</label>
        <input id="date_of_birth" name="date_of_birth" type="date">
    </div>
    <div>
        <label for="phone">Patient phone (optional)</label>
        <input id="phone" name="phone" maxlength="30">
    </div>
    <div class="span-all">
        <label for="address">Patient address (optional)</label>
        <input id="address" name="address" maxlength="255">
    </div>
    <div class="span-all">
        <label for="emergency_contact">Patient emergency contact (optional)</label>
        <input id="emergency_contact" name="emergency_contact" maxlength="120">
    </div>
    <button type="submit">Create user</button>
</form>


<table>

<tr>

<th>
ID
</th>

<th>
Username
</th>

<th>
Name
</th>

<th>
Email
</th>

<th>
Role
</th>

<th>
Active
</th>

<th>
Security Questions
</th>

<th>
Last Login
</th>

<th>
Password
</th>

</tr>


<?php foreach ($users as $user): ?>

<tr>

<td>
<?= e((string)$user['id']) ?>
</td>

<td>
<?= e($user['username']) ?>
</td>

<td>
<?= e($user['full_name']) ?>
</td>

<td>
<?= e($user['email']) ?>
</td>

<td>
<?= e($user['role']) ?>
</td>

<td>

<?=

$user['is_active']

    ? 'Yes'

    : 'No'

?>

</td>

<td>

<?= !empty($user['security_answer_1_hash'])
    && !empty($user['security_answer_2_hash'])
    ? 'Set'
    : 'Setup required' ?>

</td>

<td>

<?= e(

$user['last_login_at']
    ?? 'Never'

) ?>

</td>

<td>
<form method="POST" class="inline-form">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="reset_password">
    <input type="hidden" name="user_id" value="<?= e((string)$user['id']) ?>">
    <button type="submit">Reset</button>
</form>
</td>

</tr>

<?php endforeach; ?>

</table>


<h2>Patient Records</h2>

<table>
<tr>
    <th>Patient</th>
    <th>Email</th>
    <th>Date of Birth</th>
    <th>Phone</th>
    <th>Address</th>
    <th>Emergency Contact</th>
    <th>Paid</th>
    <th>Balance</th>
</tr>
<?php foreach ($patients as $patient): ?>
<tr>
    <td><?= e($patient['full_name']) ?></td>
    <td><?= e($patient['email']) ?></td>
    <td><?= e($patient['date_of_birth'] ?? '') ?></td>
    <td><?= e($patient['phone'] ?? '') ?></td>
    <td><?= e($patient['address'] ?? '') ?></td>
    <td><?= e($patient['emergency_contact'] ?? '') ?></td>
    <td>GHS <?= number_format((float)$patient['amount_paid'], 2) ?></td>
    <td>GHS <?= number_format((float)$patient['balance'], 2) ?></td>
</tr>
<?php endforeach; ?>
</table>


<h2>All Invoices</h2>

<table>
<tr>
    <th>Patient</th>
    <th>Description</th>
    <th>Amount</th>
    <th>Paid So Far</th>
    <th>Balance</th>
    <th>Status</th>
    <th>Issued</th>
    <th>Paid</th>
    <th>Record Payment</th>
</tr>
<?php foreach ($invoices as $invoice): ?>
<tr>
    <td><?= e($invoice['patient']) ?></td>
    <td><?= e($invoice['description']) ?></td>
    <td>GHS <?= number_format((float)$invoice['amount'], 2) ?></td>
    <td>GHS <?= number_format((float)$invoice['amount_paid'], 2) ?></td>
    <td>GHS <?= number_format((float)$invoice['balance'], 2) ?></td>
    <td><?= e($invoice['status']) ?></td>
    <td><?= e($invoice['created_at']) ?></td>
    <td><?= e($invoice['paid_at'] ?? '') ?></td>
    <td>
        <?php if ($invoice['status'] === 'unpaid' && (float)$invoice['balance'] > 0): ?>
        <form method="POST" action="record_payment.php" class="payment-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="invoice_id" value="<?= e((string)$invoice['id']) ?>">
            <input type="number" name="amount" min="0.01" max="<?= e(number_format((float)$invoice['balance'], 2, '.', '')) ?>" step="0.01" placeholder="GHS" required>
            <button type="submit">Record</button>
        </form>
        <?php else: ?>
        -
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>

<h2>Payment History</h2>
<table>
<tr>
    <th>Patient</th>
    <th>Invoice</th>
    <th>Payment</th>
    <th>Recorded</th>
    <th>Entered By</th>
    <th>Correction</th>
</tr>
<?php if (!$payments): ?>
<tr><td colspan="6" class="muted">No payment transactions yet.</td></tr>
<?php endif; ?>
<?php foreach ($payments as $payment): ?>
<tr>
    <td><?= e($payment['patient']) ?></td>
    <td>#<?= e((string)$payment['invoice_id']) ?></td>
    <td>GHS <?= number_format((float)$payment['amount'], 2) ?></td>
    <td><?= e($payment['paid_at']) ?></td>
    <td><?= e($payment['recorded_by'] ?? 'Account unavailable') ?></td>
    <td>
        <?php if ($payment['voided_at'] === null): ?>
        <form method="POST" action="remove_payment.php" class="remove-payment-form" onsubmit="return confirm('Remove this payment from the balance? The correction will be audited.')">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="payment_id" value="<?= e((string)$payment['id']) ?>">
            <input type="text" name="reason" minlength="5" maxlength="255" placeholder="Reason for removal" aria-label="Reason for removing payment <?= e((string)$payment['id']) ?>" required>
            <button class="button-danger" type="submit">Remove</button>
        </form>
        <?php else: ?>
        Removed by <?= e($payment['removed_by'] ?? 'Account unavailable') ?> on <?= e($payment['voided_at']) ?>.
        <br><span class="muted small">Reason: <?= e($payment['void_reason'] ?? '') ?></span>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
</table>


</main>

<script>
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
    const options = Object.entries(questionsByRole[roleSelect.value] || {});

    questionSelects.forEach((select, index) => {
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

roleSelect.addEventListener('change', updateSecurityQuestions);
updateSecurityQuestions();
</script>

</body>

</html>