<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';


require_role('accountant');

$paymentError = $_SESSION['payment_error'] ?? '';
$paymentNotice = $_SESSION['payment_notice'] ?? '';
$invoiceError = $_SESSION['invoice_error'] ?? '';
$invoiceNotice = $_SESSION['invoice_notice'] ?? '';
unset(
    $_SESSION['payment_error'],
    $_SESSION['payment_notice'],
    $_SESSION['invoice_error'],
    $_SESSION['invoice_notice']
);


/*
|--------------------------------------------------------------------------
| FINANCIAL SUMMARY
|--------------------------------------------------------------------------
*/

$summary = db()->query(
    "SELECT
          COUNT(CASE WHEN i.status IN ('paid', 'unpaid') THEN 1 END) AS total_invoices,
          COALESCE(SUM(CASE WHEN i.status IN ('paid', 'unpaid') THEN i.amount ELSE 0 END), 0) AS total_billed,
          COALESCE(SUM(COALESCE(p.paid_amount, 0)), 0) AS total_paid,
          COALESCE(SUM(CASE WHEN i.status = 'unpaid' THEN GREATEST(i.amount - COALESCE(p.paid_amount, 0), 0) ELSE 0 END), 0) AS total_unpaid
      FROM invoices i
      LEFT JOIN (
          SELECT invoice_id, SUM(amount) AS paid_amount
          FROM invoice_payments
          WHERE voided_at IS NULL
          GROUP BY invoice_id
      ) p ON p.invoice_id = i.id"
)->fetch();


/*
|--------------------------------------------------------------------------
| INVOICE LIST
|--------------------------------------------------------------------------
*/

$invoices = db()->query(
    "SELECT
        i.id,
        u.full_name AS patient,
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

$patientsWithBalances = db()->query(
    "SELECT
        u.full_name AS patient,
        u.email,
          SUM(GREATEST(i.amount - COALESCE(payments.paid_amount, 0), 0)) AS balance
     FROM invoices i
     JOIN patients p ON p.id = i.patient_id
     JOIN users u ON u.id = p.user_id
      LEFT JOIN (
          SELECT invoice_id, SUM(amount) AS paid_amount
          FROM invoice_payments
          WHERE voided_at IS NULL
          GROUP BY invoice_id
      ) payments ON payments.invoice_id = i.id
     WHERE i.status = 'unpaid'
     GROUP BY p.id, u.full_name, u.email
      HAVING SUM(GREATEST(i.amount - COALESCE(payments.paid_amount, 0), 0)) > 0
     ORDER BY balance DESC, u.full_name"
)->fetchAll();

$patientOptions = db()->query(
    "SELECT p.id, u.full_name
     FROM patients p
     JOIN users u ON u.id = p.user_id
     WHERE u.role = 'patient' AND u.is_active = 1
     ORDER BY u.full_name"
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
Accountant Dashboard
</title>

<link
    rel="stylesheet"
    href="style.css"
>

</head>


<body>


<nav>

<strong>
Accountant Dashboard
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


<h2>
Financial Summary
</h2>


<div class="grid">


<div class="panel">

<strong>
Total Invoices
</strong>

<br>

<?= e(

(string)$summary['total_invoices']

) ?>

</div>


<div class="panel">

<strong>
Total Billed
</strong>

<br>

GHS

<?= number_format(

(float)$summary['total_billed'],

2

) ?>

</div>


<div class="panel">

<strong>
Total Paid
</strong>

<br>

GHS

<?= number_format(

(float)$summary['total_paid'],

2

) ?>

</div>


<div class="panel">

<strong>
Total Unpaid
</strong>

<br>

GHS

<?= number_format(

(float)$summary['total_unpaid'],

2

) ?>

</div>


</div>


<h2>Add Amount Due</h2>
<form method="POST" action="create_invoice.php" class="panel form-grid">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <div>
        <label for="invoice_patient_accountant">Patient</label>
        <select id="invoice_patient_accountant" name="patient_id" required <?= $patientOptions ? '' : 'disabled' ?>>
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
        <label for="invoice_amount_accountant">Amount due (GHS)</label>
        <input id="invoice_amount_accountant" type="number" name="amount" min="0.01" step="0.01" required>
    </div>
    <div class="span-all">
        <label for="invoice_description_accountant">Description</label>
        <input id="invoice_description_accountant" name="description" maxlength="255" required>
    </div>
    <button type="submit" <?= $patientOptions ? '' : 'disabled' ?>>Add amount due</button>
</form>


<h2>Outstanding Patient Balances</h2>

<table>
<tr>
    <th>Patient</th>
    <th>Contact Email</th>
    <th>Balance Due</th>
    <th>Reminder</th>
</tr>
<?php foreach ($patientsWithBalances as $patient): ?>
<?php
$reminderSubject = rawurlencode('Outstanding balance reminder');
$reminderBody = rawurlencode(
    'Hello ' . $patient['patient'] . ",\n\nOur records show an outstanding balance of GHS "
    . number_format((float)$patient['balance'], 2)
    . ". Please contact the accounts office to arrange payment.\n\nThank you."
);
$reminderLink = 'mailto:' . $patient['email']
    . '?subject=' . $reminderSubject
    . '&body=' . $reminderBody;
?>
<tr>
    <td><?= e($patient['patient']) ?></td>
    <td><?= e($patient['email']) ?></td>
    <td>GHS <?= number_format((float)$patient['balance'], 2) ?></td>
    <td><a href="<?= e($reminderLink) ?>">Prepare email</a></td>
</tr>
<?php endforeach; ?>
</table>

<p class="muted small">Prepare email opens the user's mail application; the accountant reviews and sends the reminder.</p>


<h2>
Invoices
</h2>


<table>

<tr>

<th>
Patient
</th>

<th>
Invoice #
</th>

<th>
Amount
</th>

<th>
Paid So Far
</th>

<th>
Balance
</th>

<th>
Status
</th>

<th>
Date
</th>

<th>
Record Payment
</th>

</tr>


<?php foreach ($invoices as $invoice): ?>

<tr>

<td>

<?= e(
    $invoice['patient']
) ?>

</td>


<td>

<?= e((string)$invoice['id']) ?>

</td>


<td>

GHS

<?= number_format(

    (float)$invoice['amount'],

    2

) ?>

</td>

<td>
GHS <?= number_format((float)$invoice['amount_paid'], 2) ?>
</td>

<td>
GHS <?= number_format((float)$invoice['balance'], 2) ?>
</td>


<td>

<?= e(
    $invoice['status']
) ?>

</td>


<td>

<?= e(
    $invoice['created_at']
) ?>

</td>

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


</main>

</body>

</html>