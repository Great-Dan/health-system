<?php

declare(strict_types=1);

require_once __DIR__ .
    '/security.php';


require_role('patient');


/*
|--------------------------------------------------------------------------
| GET CURRENT PATIENT
|--------------------------------------------------------------------------
*/

$stmt = db()->prepare(

    'SELECT

        p.*,

        u.full_name,

        u.email

     FROM patients p

     JOIN users u

        ON u.id = p.user_id

     WHERE p.user_id = ?'

);


$stmt->execute([

    $_SESSION['user_id']

]);


$patient =
    $stmt->fetch();


/*
|--------------------------------------------------------------------------
| GET PATIENT INVOICES
|--------------------------------------------------------------------------
*/

$stmt = db()->prepare(

    'SELECT

        id,

        description,

        amount,

        COALESCE(payments.paid_amount, 0) AS amount_paid,

        status,

        created_at,

        paid_at

     FROM invoices

      LEFT JOIN (

          SELECT invoice_id, SUM(amount) AS paid_amount

          FROM invoice_payments

          WHERE voided_at IS NULL

          GROUP BY invoice_id

      ) payments ON payments.invoice_id = invoices.id

     WHERE patient_id =

        (

            SELECT id

            FROM patients

            WHERE user_id = ?

        )

     ORDER BY created_at DESC'

);


$stmt->execute([

    $_SESSION['user_id']

]);


$invoices =
    $stmt->fetchAll();

$amountPaid = 0.0;
$balanceRemaining = 0.0;

foreach ($invoices as $invoice) {
    $amountPaid += (float)$invoice['amount_paid'];

    if ($invoice['status'] === 'unpaid') {
        $balanceRemaining += max(
            (float)$invoice['amount'] - (float)$invoice['amount_paid'],
            0
        );
    }
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
Patient Portal
</title>

<link
    rel="stylesheet"
    href="style.css?v=20261018_mobile1"
>

</head>

<body>

<?php
$portalSubtitle = 'Patient Access';
$portalRole = 'patient';
require __DIR__ . '/dashboard_navbar.php';
?>

<main class="wide">

<header class="page-heading">
    <div>
        <p class="eyebrow">PATIENT PORTAL</p>
        <h1>Hello, <?= e($_SESSION['full_name']) ?></h1>
        <p>Your clinical profile, billing summary, and account invoices.</p>
    </div>
    <span class="page-badge">PATIENT ACCOUNT</span>
</header>

<div class="dashboard-layout dashboard-layout--patient">

    <div>
        <div class="content-card">
            <div class="card-header-bar">
                <div class="card-title-group">
                    <h2>My Profile</h2>
                </div>
            </div>

            <div class="profile-details" style="padding:0;">
                <p>
                    <strong>Full Name:</strong>
                    <span><?= e($patient['full_name'] ?? $_SESSION['full_name']) ?></span>
                </p>

                <p>
                    <strong>Email Address:</strong>
                    <span><?= e($patient['email'] ?? '—') ?></span>
                </p>

                <p>
                    <strong>Date of Birth:</strong>
                    <span><?= e(!empty($patient['date_of_birth']) ? $patient['date_of_birth'] : 'Not provided') ?></span>
                </p>

                <p>
                    <strong>Phone Number:</strong>
                    <span><?= e(!empty($patient['phone']) ? $patient['phone'] : 'Not provided') ?></span>
                </p>

                <p>
                    <strong>Residential Address:</strong>
                    <span><?= e(!empty($patient['address']) ? $patient['address'] : 'Not provided') ?></span>
                </p>

                <p>
                    <strong>Emergency Contact:</strong>
                    <span><?= e(!empty($patient['emergency_contact']) ? $patient['emergency_contact'] : 'Not provided') ?></span>
                </p>
            </div>
        </div>
    </div>

    <div class="dashboard-stack">
        <div class="kpi-grid" style="grid-template-columns: repeat(2, minmax(0, 1fr)); margin-bottom: 0;">
            <div class="kpi-card accent-green">
                <div class="kpi-header">
                    <span class="kpi-label">Amount Paid</span>
                </div>
                <div class="kpi-value">GHS <?= number_format($amountPaid, 2) ?></div>
                <p class="kpi-subtext">Total payments settled to date</p>
            </div>

            <div class="kpi-card <?= $balanceRemaining > 0 ? 'accent-amber' : 'accent-green' ?>">
                <div class="kpi-header">
                    <span class="kpi-label">Balance Remaining</span>
                </div>
                <div class="kpi-value" style="color:<?= $balanceRemaining > 0 ? 'var(--coral)' : 'var(--green-dark)' ?>;">
                    GHS <?= number_format($balanceRemaining, 2) ?>
                </div>
                <p class="kpi-subtext">
                    <?= $balanceRemaining > 0 ? 'Outstanding balance awaiting settlement' : 'All invoices settled in full' ?>
                </p>
            </div>
        </div>

        <?php if ($balanceRemaining > 0): ?>
        <div class="alert warning" style="margin: 0;">
            You have an outstanding balance of <strong>GHS <?= number_format($balanceRemaining, 2) ?></strong>. Please contact the clinic accounts office to arrange payment.
        </div>
        <?php else: ?>
        <div class="alert info" style="margin: 0;">
            Your account is in good standing with no outstanding balance.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$totalInvoiced = array_sum(array_column($invoices, 'amount'));
$paidCount     = count(array_filter($invoices, fn($i) => $i['status'] === 'paid'));
$unpaidCount   = count($invoices) - $paidCount;
?>

<div class="inv-section-wrapper">

    <!-- Financial Summary Strip -->
    <?php if ($invoices): ?>
    <div class="inv-summary-strip">
        <div class="inv-summary-item">
            <span class="inv-summary-label">Total Invoiced</span>
            <span class="inv-summary-value">GHS <?= number_format($totalInvoiced, 2) ?></span>
        </div>
        <div class="inv-summary-divider"></div>
        <div class="inv-summary-item">
            <span class="inv-summary-label">Total Paid</span>
            <span class="inv-summary-value accent-paid">GHS <?= number_format($amountPaid, 2) ?></span>
        </div>
        <div class="inv-summary-divider"></div>
        <div class="inv-summary-item">
            <span class="inv-summary-label">Outstanding</span>
            <span class="inv-summary-value <?= $balanceRemaining > 0 ? 'accent-due' : 'accent-paid' ?>">
                GHS <?= number_format($balanceRemaining, 2) ?>
            </span>
        </div>
        <div class="inv-summary-divider"></div>
        <div class="inv-summary-item">
            <span class="inv-summary-label">Invoices</span>
            <span class="inv-summary-value"><?= $paidCount ?> paid &nbsp;/&nbsp; <?= $unpaidCount ?> unpaid</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="content-card inv-card">
        <div class="card-header-bar">
            <div class="card-title-group">
                <h2>My Invoices</h2>
                <span class="table-count-badge"><?= count($invoices) ?> record<?= count($invoices) !== 1 ? 's' : '' ?></span>
            </div>
            <?php if (count($invoices) > 0): ?>
            <input type="text" class="table-filter-input" placeholder="Search invoices..." onkeyup="filterTable(this, 'patient-invoices-table')">
            <?php endif; ?>
        </div>

        <div class="modern-table-container">
            <table id="patient-invoices-table">
                <thead>
                    <tr>
                        <th style="width:38%;">Description</th>
                        <th>Total Amount</th>
                        <th>Amount Paid</th>
                        <th>Balance Due</th>
                        <th>Status</th>
                        <th>Date Issued</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$invoices): ?>
                    <tr>
                        <td colspan="6" class="inv-empty-row">No invoices on file for your account.</td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($invoices as $invoice):
                        $isPaid = $invoice['status'] === 'paid';
                        $bal = $isPaid ? 0.0 : max((float)$invoice['amount'] - (float)$invoice['amount_paid'], 0.0);
                        $rowClass = $isPaid ? 'inv-row-paid' : ($bal > 0 ? 'inv-row-unpaid' : '');
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="inv-desc"><strong><?= e($invoice['description']) ?></strong></td>
                        <td class="inv-amount">GHS <?= number_format((float)$invoice['amount'], 2) ?></td>
                        <td class="inv-paid-amt">GHS <?= number_format((float)$invoice['amount_paid'], 2) ?></td>
                        <td class="inv-balance">
                            <span class="inv-balance-chip <?= $bal > 0 ? 'chip-due' : 'chip-clear' ?>">
                                GHS <?= number_format($bal, 2) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isPaid): ?>
                                <span class="status-badge badge-paid">PAID</span>
                            <?php else: ?>
                                <span class="status-badge badge-unpaid">UNPAID</span>
                            <?php endif; ?>
                        </td>
                        <td class="inv-date"><?= e(date('d M Y', strtotime($invoice['created_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <?php if ($invoices): ?>
                <tfoot>
                    <tr class="inv-totals-row">
                        <td><strong>Totals</strong></td>
                        <td><strong>GHS <?= number_format($totalInvoiced, 2) ?></strong></td>
                        <td><strong>GHS <?= number_format($amountPaid, 2) ?></strong></td>
                        <td>
                            <span class="inv-balance-chip <?= $balanceRemaining > 0 ? 'chip-due' : 'chip-clear' ?>">
                                GHS <?= number_format($balanceRemaining, 2) ?>
                            </span>
                        </td>
                        <td colspan="2" style="color:var(--muted); font-size:0.82rem;">
                            <?= count($invoices) ?> invoice<?= count($invoices) !== 1 ? 's' : '' ?> total
                        </td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
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
