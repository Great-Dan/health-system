ALTER TABLE invoice_payments
    ADD COLUMN voided_at DATETIME DEFAULT NULL,
    ADD COLUMN voided_by INT UNSIGNED DEFAULT NULL,
    ADD COLUMN void_reason VARCHAR(255) DEFAULT NULL,
    ADD KEY idx_payment_voided_at (voided_at),
    ADD CONSTRAINT fk_payment_voided_by
        FOREIGN KEY (voided_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE;