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
$patientId = filter_var(
    $_POST['patient_id'] ?? null,
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$description = trim((string)($_POST['description'] ?? ''));
$amountInput = trim((string)($_POST['amount'] ?? ''));

if (
    $patientId === false
    || $patientId === null
    || $description === ''
    || strlen($description) > 255
    || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput)
) {
    $_SESSION['invoice_error'] = 'Enter a patient, description, and valid amount.';
    header('Location: ' . $returnPage);
    exit;
}

$amountCents = (int)round((float)$amountInput * 100);

if ($amountCents <= 0) {
    $_SESSION['invoice_error'] = 'Amount due must be greater than zero.';
    header('Location: ' . $returnPage);
    exit;
}

$connection = db();

try {
    $stmt = $connection->prepare(
        'SELECT p.id
         FROM patients p
         JOIN users u ON u.id = p.user_id
         WHERE p.id = ? AND u.role = \'patient\' AND u.is_active = 1'
    );
    $stmt->execute([$patientId]);
    if (!$stmt->fetch()) {
        $_SESSION['invoice_error'] = 'Select an active patient account.';
        header('Location: ' . $returnPage);
        exit;
    }

    $connection->beginTransaction();
    $stmt = $connection->prepare(
        'INSERT INTO invoices (patient_id, description, amount, status)
         VALUES (?, ?, ?, \'unpaid\')'
    );
    $stmt->execute([
        $patientId,
        $description,
        number_format($amountCents / 100, 2, '.', '')
    ]);
    $invoiceId = (int)$connection->lastInsertId();

    audit('invoice_created', 'invoices', $invoiceId);
    $connection->commit();
    $_SESSION['invoice_notice'] = 'Amount due added to the patient account.';
} catch (PDOException $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    $_SESSION['invoice_error'] = 'Amount due could not be added. Please try again.';
}

header('Location: ' . $returnPage);
exit;