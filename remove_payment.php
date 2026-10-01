<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';

require_login();

if (($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    exit('403 Forbidden: insufficient privileges.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

verify_csrf();

$paymentId = filter_var(
    $_POST['payment_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$reason = trim((string)($_POST['reason'] ?? ''));

if (
    $paymentId === false
    || $paymentId === null
    || strlen($reason) < 5
    || strlen($reason) > 255
) {
    $_SESSION['payment_error'] = 'Select a payment and enter a reason of 5-255 characters.';
    header('Location: admin_index.php');
    exit;
}

$connection = db();

try {
    $connection->beginTransaction();

    $stmt = $connection->prepare(
        'SELECT p.id, p.invoice_id, p.voided_at, i.amount AS invoice_amount
         FROM invoice_payments p
         JOIN invoices i ON i.id = p.invoice_id
         WHERE p.id = ?
         FOR UPDATE'
    );
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();

    if (!$payment || $payment['voided_at'] !== null) {
        $connection->rollBack();
        $_SESSION['payment_error'] = 'That payment is unavailable or has already been removed.';
        header('Location: admin_index.php');
        exit;
    }

    $stmt = $connection->prepare(
        'UPDATE invoice_payments
         SET voided_at = NOW(), voided_by = ?, void_reason = ?
         WHERE id = ? AND voided_at IS NULL'
    );
    $stmt->execute([
        $_SESSION['user_id'],
        $reason,
        $paymentId
    ]);

    $stmt = $connection->prepare(
        'SELECT COALESCE(SUM(amount), 0) AS paid_total, MAX(paid_at) AS last_payment_at
         FROM invoice_payments
         WHERE invoice_id = ? AND voided_at IS NULL'
    );
    $stmt->execute([$payment['invoice_id']]);
    $remainingPayments = $stmt->fetch();
    $isFullyPaid = (int)round((float)$remainingPayments['paid_total'] * 100)
        >= (int)round((float)$payment['invoice_amount'] * 100);

    if ($isFullyPaid) {
        $stmt = $connection->prepare(
            "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
        );
        $stmt->execute([
            $remainingPayments['last_payment_at'],
            $payment['invoice_id']
        ]);
    } else {
        $stmt = $connection->prepare(
            "UPDATE invoices SET status = 'unpaid', paid_at = NULL WHERE id = ?"
        );
        $stmt->execute([$payment['invoice_id']]);
    }

    audit('payment_removed', 'invoice_payments', (int)$paymentId);
    $connection->commit();
    $_SESSION['payment_notice'] = 'Payment removed from the balance and retained in the audit history.';
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $_SESSION['payment_error'] = 'Payment could not be removed. Please try again.';
}

header('Location: admin_index.php');
exit;