CREATE TABLE IF NOT EXISTS invoice_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id INT UNSIGNED NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    paid_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payment_invoice (invoice_id),
    KEY idx_payment_recorded_by (recorded_by),
    CONSTRAINT fk_payment_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_payment_user
        FOREIGN KEY (recorded_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO invoice_payments (invoice_id, amount, recorded_by, paid_at)
SELECT i.id, i.amount, NULL, COALESCE(i.paid_at, i.created_at)
FROM invoices i
WHERE i.status = 'paid'
  AND NOT EXISTS (
      SELECT 1
      FROM invoice_payments payment
      WHERE payment.invoice_id = i.id
  );