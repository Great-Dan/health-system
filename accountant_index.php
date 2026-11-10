<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';


require_role('accountant');

$paymentError = $_SESSION['payment_error'] ?? '';
$paymentNotice = $_SESSION['payment_notice'] ?? '';
$invoiceError = $_SESSION['invoice_error'] ?? '';
$invoiceNotice = $_SESSION['invoice_notice'] ?? '';
$balanceError = $_SESSION['balance_error'] ?? '';
$balanceNotice = $_SESSION['balance_notice'] ?? '';
unset(
    $_SESSION['payment_error'],
    $_SESSION['payment_notice'],
    $_SESSION['invoice_error'],
    $_SESSION['invoice_notice'],
    $_SESSION['balance_error'],
    $_SESSION['balance_notice']
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

$balanceAdjustments = db()->query(
    "SELECT
        adjustment.previous_amount,
        adjustment.new_amount,
        adjustment.invoice_id,
        adjustment.reason,
        adjustment.created_at,
        patient.full_name AS patient,
        recorder.full_name AS recorded_by
     FROM invoice_balance_adjustments adjustment
     JOIN patients patient_profile ON patient_profile.id = adjustment.patient_id
     JOIN users patient ON patient.id = patient_profile.user_id
     LEFT JOIN users recorder ON recorder.id = adjustment.recorded_by
     ORDER BY adjustment.created_at DESC, adjustment.id DESC
     LIMIT 25"
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
    href="style.css?v=20261018_mobile1"
>

</head>

<body>

<?php
$portalSubtitle = 'Financial Operations';
$portalRole = 'accountant';
require __DIR__ . '/dashboard_navbar.php';
?>

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

<?php if ($balanceError): ?>
<div class="alert error"><?= e($balanceError) ?></div>
<?php endif; ?>

<?php if ($balanceNotice): ?>
<div class="alert info"><?= e($balanceNotice) ?></div>
<?php endif; ?>

<header class="page-heading">
    <div>
        <p class="eyebrow">FINANCIAL OPERATIONS</p>
        <h1>Accountant Dashboard</h1>
        <p>Billing ledger, balance adjustments, recorded receipts, and outstanding accounts.</p>
    </div>
    <span class="page-badge">FINANCE &amp; ACCOUNTS</span>
</header>

<!-- Financial KPI Summary Grid -->
<div class="kpi-grid">
    <div class="kpi-card accent-dark">
        <div class="kpi-header">
            <span class="kpi-label">Total Invoices</span>
        </div>
        <div class="kpi-value"><?= e((string)$summary['total_invoices']) ?></div>
        <p class="kpi-subtext">Issued invoices on record</p>
    </div>

    <div class="kpi-card accent-green">
        <div class="kpi-header">
            <span class="kpi-label">Total Billed</span>
        </div>
        <div class="kpi-value">GHS <?= number_format((float)$summary['total_billed'], 2) ?></div>
        <p class="kpi-subtext">Gross patient billing charges</p>
    </div>

    <div class="kpi-card accent-blue">
        <div class="kpi-header">
            <span class="kpi-label">Total Collected</span>
        </div>
        <div class="kpi-value">GHS <?= number_format((float)$summary['total_paid'], 2) ?></div>
        <p class="kpi-subtext">Received and ledgered payments</p>
    </div>

    <div class="kpi-card accent-amber">
        <div class="kpi-header">
            <span class="kpi-label">Total Unpaid</span>
        </div>
        <div class="kpi-value" style="color:var(--coral);">GHS <?= number_format((float)$summary['total_unpaid'], 2) ?></div>
        <p class="kpi-subtext">Outstanding receivables due</p>
    </div>
</div>

<div class="dashboard-layout dashboard-layout--accountant" style="margin-bottom: 28px;">
    <!-- Add a Charge Card -->
    <div class="content-card">
        <div class="card-header-bar">
            <div class="card-title-group">
                <h2>Add a Charge</h2>
            </div>
        </div>
        <form method="POST" action="create_invoice.php" class="panel form-grid" style="box-shadow:none; border:none; padding:0;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <label for="invoice_patient_accountant">Patient</label>
                <select id="invoice_patient_accountant" name="patient_id" required <?= $patientOptions ? '' : 'disabled' ?>>
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
                <label for="invoice_amount_accountant">Charge Amount (GHS)</label>
                <input id="invoice_amount_accountant" type="number" name="amount" min="0.01" step="0.01" placeholder="0.00" required>
            </div>
            <div class="span-all">
                <label for="invoice_description_accountant">Description</label>
                <input id="invoice_description_accountant" name="description" maxlength="255" placeholder="e.g. Consultation fee, Lab tests, Medications" required>
            </div>
            <button type="submit" class="button-backup" <?= $patientOptions ? '' : 'disabled' ?>>Add Charge</button>
        </form>
        <p class="muted small" style="margin: 12px 0 0;">Adds a new billing charge to the patient's existing account ledger.</p>
    </div>

    <!-- Set Total Outstanding Balance Card -->
    <div class="content-card">
        <div class="card-header-bar">
            <div class="card-title-group">
                <h2>Set Total Outstanding Balance</h2>
            </div>
        </div>
        <form method="POST" action="set_patient_balance.php" class="panel form-grid" style="box-shadow:none; border:none; padding:0;">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <div>
                <label for="balance_patient_accountant">Patient</label>
                <select id="balance_patient_accountant" name="patient_id" required <?= $patientOptions ? '' : 'disabled' ?>>
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
                <label for="patient_balance_accountant">New Total Balance (GHS)</label>
                <input id="patient_balance_accountant" type="number" name="balance" min="0" step="0.01" placeholder="0.00" required>
            </div>
            <div class="span-all">
                <label for="balance_reason_accountant">Reason for Adjustment</label>
                <input id="balance_reason_accountant" name="reason" maxlength="220" placeholder="e.g. Waived fee, insurance deduction, agreed settlement" required>
            </div>
            <button type="submit" class="button-backup" <?= $patientOptions ? '' : 'disabled' ?>>Set Total Balance</button>
        </form>
        <p class="muted small" style="margin: 12px 0 0;">Sets the patient's total due to this exact figure with audited reason tracking.</p>
    </div>
</div>

<!-- Outstanding Patient Balances Card -->
<div class="content-card">
    <div class="card-header-bar">
        <div class="card-title-group">
            <h2>Outstanding Patient Balances</h2>
            <span class="table-count-badge"><?= count($patientsWithBalances) ?> patients with balance</span>
        </div>
        <?php if (count($patientsWithBalances) > 0): ?>
        <input type="text" class="table-filter-input" placeholder="Search balances..." onkeyup="filterTable(this, 'balances-table')">
        <?php endif; ?>
    </div>

    <div class="modern-table-container">
        <table id="balances-table">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Contact Email</th>
                    <th>Balance Due</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$patientsWithBalances): ?>
                <tr><td colspan="4" class="muted">No outstanding patient balances at this time.</td></tr>
                <?php endif; ?>
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
                    <td><strong><?= e($patient['patient']) ?></strong></td>
                    <td><?= e($patient['email']) ?></td>
                    <td><strong style="color:var(--coral); font-size:0.95rem;">GHS <?= number_format((float)$patient['balance'], 2) ?></strong></td>
                    <td>
                        <a href="<?= e($reminderLink) ?>" class="quick-action-btn" style="padding:4px 12px; font-size:0.82rem;">
                            Prepare Email
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted small" style="margin: 10px 0 0;">Prepare email opens your email application with a pre-filled balance reminder to review and send.</p>
</div>

<!-- Recent Invoice Adjustments Card -->
<div class="content-card">
    <div class="card-header-bar">
        <div class="card-title-group">
            <h2>Recent Invoice Adjustments</h2>
            <span class="table-count-badge"><?= count($balanceAdjustments) ?> records</span>
        </div>
        <?php if (count($balanceAdjustments) > 0): ?>
        <input type="text" class="table-filter-input" placeholder="Search adjustments..." onkeyup="filterTable(this, 'adjustments-table')">
        <?php endif; ?>
    </div>

    <div class="modern-table-container">
        <table id="adjustments-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Patient</th>
                    <th>Invoice #</th>
                    <th>Previous Amount</th>
                    <th>Adjusted Amount</th>
                    <th>Reason</th>
                    <th>Recorded By</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$balanceAdjustments): ?>
                <tr><td colspan="7" class="muted">No balance adjustments recorded yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($balanceAdjustments as $adjustment): ?>
                <tr>
                    <td style="font-size:0.85rem; color:var(--muted);"><?= e($adjustment['created_at']) ?></td>
                    <td><strong><?= e($adjustment['patient']) ?></strong></td>
                    <td>#<?= e((string)($adjustment['invoice_id'] ?? 'Deleted')) ?></td>
                    <td>GHS <?= number_format((float)$adjustment['previous_amount'], 2) ?></td>
                    <td>GHS <?= number_format((float)$adjustment['new_amount'], 2) ?></td>
                    <td><?= e($adjustment['reason']) ?></td>
                    <td><?= e($adjustment['recorded_by'] ?? 'Account unavailable') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- All Invoices Card -->
<div class="content-card">
    <div class="card-header-bar">
        <div class="card-title-group">
            <h2>All Invoices</h2>
            <span class="table-count-badge"><?= count($invoices) ?> records</span>
        </div>
        <?php if (count($invoices) > 0): ?>
        <input type="text" class="table-filter-input" placeholder="Search invoices..." onkeyup="filterTable(this, 'accountant-invoices-table')">
        <?php endif; ?>
    </div>

    <div class="modern-table-container">
        <table id="accountant-invoices-table">
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Invoice #</th>
                    <th>Amount</th>
                    <th>Paid So Far</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Record Payment</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$invoices): ?>
                <tr><td colspan="8" class="muted">No invoices generated yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($invoices as $invoice): ?>
                <tr>
                    <td><strong><?= e($invoice['patient']) ?></strong></td>
                    <td>#<?= e((string)$invoice['id']) ?></td>
                    <td>GHS <?= number_format((float)$invoice['amount'], 2) ?></td>
                    <td>GHS <?= number_format((float)$invoice['amount_paid'], 2) ?></td>
                    <td>
                        <strong style="color:<?= (float)$invoice['balance'] > 0 ? 'var(--coral)' : 'var(--green-dark)' ?>;">
                            GHS <?= number_format((float)$invoice['balance'], 2) ?>
                        </strong>
                    </td>
                    <td>
                        <?php if ($invoice['status'] === 'paid'): ?>
                            <span class="status-badge badge-paid">PAID</span>
                        <?php else: ?>
                            <span class="status-badge badge-unpaid">UNPAID</span>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:0.85rem; color:var(--muted);"><?= e($invoice['created_at']) ?></td>
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

</main>

<script>
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
</script>

</body>

</html>
