-- Run once against the application database before using Set Total Outstanding Balance.
CREATE TABLE IF NOT EXISTS invoice_balance_adjustments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    patient_id INT UNSIGNED NOT NULL,
    invoice_id INT UNSIGNED DEFAULT NULL,
    previous_amount DECIMAL(12, 2) NOT NULL,
    new_amount DECIMAL(12, 2) NOT NULL,
    reason VARCHAR(220) NOT NULL,
    recorded_by INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_balance_adjustment_patient (patient_id),
    KEY idx_balance_adjustment_invoice (invoice_id),
    KEY idx_balance_adjustment_recorded_by (recorded_by),
    CONSTRAINT fk_balance_adjustment_patient
        FOREIGN KEY (patient_id) REFERENCES patients (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_balance_adjustment_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (id)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_balance_adjustment_user
        FOREIGN KEY (recorded_by) REFERENCES users (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
