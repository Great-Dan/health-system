<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

require_role('accountant');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

verify_csrf();

$patientId = filter_var(
    $_POST['patient_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$balanceInput = trim((string)($_POST['balance'] ?? ''));
$reason = trim((string)($_POST['reason'] ?? ''));

if (
    $patientId === false
    || $patientId === null
    || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $balanceInput)
    || strlen($reason) < 5
    || strlen($reason) > 220
) {
    $_SESSION['balance_error'] = 'Select a patient, enter a valid non-negative balance, and provide a reason of 5-220 characters.';
    header('Location: accountant_index.php');
    exit;
}

$balanceParts = explode('.', $balanceInput, 2);
$targetCents = ((int)$balanceParts[0] * 100)
    + (int)str_pad($balanceParts[1] ?? '', 2, '0');

$connection = db();

try {
    $connection->beginTransaction();

    $stmt = $connection->prepare(
        "SELECT p.id
         FROM patients p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = ? AND u.role = 'patient' AND u.is_active = 1
         FOR UPDATE"
    );
    $stmt->execute([$patientId]);
    if (!$stmt->fetch()) {
        $connection->rollBack();
        $_SESSION['balance_error'] = 'Select an active patient account.';
        header('Location: accountant_index.php');
        exit;
    }

    $stmt = $connection->prepare(
        "SELECT id, amount
         FROM invoices
         WHERE patient_id = ? AND status = 'unpaid'
         ORDER BY created_at ASC, id ASC
         FOR UPDATE"
    );
    $stmt->execute([$patientId]);
    $invoices = $stmt->fetchAll();
    $paymentQuery = $connection->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS paid_amount,
                MAX(paid_at) AS last_payment_at
         FROM invoice_payments
         WHERE invoice_id = ? AND voided_at IS NULL'
    );

    $currentCents = 0;
    foreach ($invoices as &$invoice) {
        $paymentQuery->execute([$invoice['id']]);
        $payments = $paymentQuery->fetch();
        $invoice['paid_cents'] = (int)round((float)$payments['paid_amount'] * 100);
        $invoice['last_payment_at'] = $payments['last_payment_at'];
        $invoice['amount_cents'] = (int)round((float)$invoice['amount'] * 100);
        $invoice['balance_cents'] = max(
            $invoice['amount_cents'] - $invoice['paid_cents'],
            0
        );
        $currentCents += $invoice['balance_cents'];
    }
    unset($invoice);

    if ($targetCents === $currentCents) {
        $connection->commit();
        $_SESSION['balance_notice'] = 'The patient already has an outstanding balance of GHS '
            . number_format($targetCents / 100, 2, '.', '') . '. No changes were needed.';
        header('Location: accountant_index.php');
        exit;
    }

    if ($targetCents > $currentCents) {
        $increaseCents = $targetCents - $currentCents;
        $stmt = $connection->prepare(
            "INSERT INTO invoices (patient_id, description, amount, status)
             VALUES (?, ?, ?, 'unpaid')"
        );
        $stmt->execute([
            $patientId,
            'Balance adjustment: ' . $reason,
            number_format($increaseCents / 100, 2, '.', '')
        ]);
        $invoiceId = (int)$connection->lastInsertId();

        $stmt = $connection->prepare(
            'INSERT INTO invoice_balance_adjustments
                (patient_id, invoice_id, previous_amount, new_amount, reason, recorded_by)
             VALUES (?, ?, 0, ?, ?, ?)'
        );
        $stmt->execute([
            $patientId,
            $invoiceId,
            number_format($increaseCents / 100, 2, '.', ''),
            $reason,
            $_SESSION['user_id']
        ]);
    } else {
        $reductionRemaining = $currentCents - $targetCents;
        $adjustmentInsert = $connection->prepare(
            'INSERT INTO invoice_balance_adjustments
                (patient_id, invoice_id, previous_amount, new_amount, reason, recorded_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $invoiceUpdate = $connection->prepare(
            'UPDATE invoices SET amount = ?, status = ?, paid_at = ? WHERE id = ?'
        );

        foreach ($invoices as $invoice) {
            if ($reductionRemaining === 0) {
                break;
            }

            $reduction = min($reductionRemaining, $invoice['balance_cents']);
            if ($reduction === 0) {
                continue;
            }

            $newAmountCents = $invoice['amount_cents'] - $reduction;
            $newStatus = $newAmountCents === $invoice['paid_cents']
                ? 'paid'
                : 'unpaid';
            $invoiceUpdate->execute([
                number_format($newAmountCents / 100, 2, '.', ''),
                $newStatus,
                $newStatus === 'paid' ? $invoice['last_payment_at'] : null,
                $invoice['id']
            ]);
            $adjustmentInsert->execute([
                $patientId,
                $invoice['id'],
                number_format($invoice['amount_cents'] / 100, 2, '.', ''),
                number_format($newAmountCents / 100, 2, '.', ''),
                $reason,
                $_SESSION['user_id']
            ]);
            $reductionRemaining -= $reduction;
        }

        if ($reductionRemaining !== 0) {
            throw new RuntimeException('The outstanding balance changed while it was being adjusted. Please try again.');
        }
    }

    audit('patient_balance_set', 'patients', (int)$patientId);
    $connection->commit();
    $_SESSION['balance_notice'] = 'Outstanding balance set to GHS '
        . number_format($targetCents / 100, 2, '.', '') . '.';
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('Patient balance adjustment failed: ' . $exception->getMessage());
    $_SESSION['balance_error'] = 'The patient balance could not be updated. Please try again.';
}

header('Location: accountant_index.php');
exit;
