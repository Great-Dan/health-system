<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

require_login();

if (!in_array($_SESSION['role'], ['admin', 'accountant'], true)) {
    http_response_code(403);
    exit('403 Forbidden: insufficient privileges.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

verify_csrf();

$returnPage = $_SESSION['role'] === 'admin'
    ? 'admin_index.php'
    : 'accountant_index.php';
$invoiceId = filter_var(
    $_POST['invoice_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$amountInput = trim((string)($_POST['amount'] ?? ''));

if (
    $invoiceId === false
    || $invoiceId === null
    || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput)
) {
    $_SESSION['payment_error'] = 'Enter a valid invoice and payment amount.';
    header('Location: ' . $returnPage);
    exit;
}

$amountCents = (int)round((float)$amountInput * 100);

if ($amountCents <= 0) {
    $_SESSION['payment_error'] = 'Payment amount must be greater than zero.';
    header('Location: ' . $returnPage);
    exit;
}

$connection = db();

try {
    $connection->beginTransaction();

    $stmt = $connection->prepare(
        'SELECT id, amount, status
         FROM invoices
         WHERE id = ?
         FOR UPDATE'
    );
    $stmt->execute([$invoiceId]);
    $invoice = $stmt->fetch();

    if (!$invoice || $invoice['status'] !== 'unpaid') {
        $connection->rollBack();
        $_SESSION['payment_error'] = 'That invoice is not available for payment.';
        header('Location: ' . $returnPage);
        exit;
    }

    $stmt = $connection->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM invoice_payments
         WHERE invoice_id = ? AND voided_at IS NULL'
    );
    $stmt->execute([$invoiceId]);
    $paidCents = (int)round((float)$stmt->fetchColumn() * 100);
    $invoiceCents = (int)round((float)$invoice['amount'] * 100);
    $balanceCents = $invoiceCents - $paidCents;

    if ($amountCents > $balanceCents) {
        $connection->rollBack();
        $_SESSION['payment_error'] = 'Payment cannot exceed the invoice balance.';
        header('Location: ' . $returnPage);
        exit;
    }

    $stmt = $connection->prepare(
        'INSERT INTO invoice_payments (invoice_id, amount, recorded_by)
         VALUES (?, ?, ?)'
    );
    $stmt->execute([
        $invoiceId,
        number_format($amountCents / 100, 2, '.', ''),
        $_SESSION['user_id']
    ]);
    $paymentId = (int)$connection->lastInsertId();

    if ($amountCents === $balanceCents) {
        $stmt = $connection->prepare(
            "UPDATE invoices
             SET status = 'paid', paid_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([$invoiceId]);
    }

    audit('payment_recorded', 'invoice_payments', $paymentId);
    $connection->commit();
    $_SESSION['payment_notice'] = 'Payment recorded successfully.';
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $_SESSION['payment_error'] = 'Payment could not be recorded. Please try again.';
}

header('Location: ' . $returnPage);
exit;