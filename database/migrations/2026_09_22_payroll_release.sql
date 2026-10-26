-- Target database: hrms
-- Finance payroll release support.
-- Run this against the HRMS database before enabling Finance release.
-- IF NOT EXISTS keeps this safe for installations that already received
-- the equivalent startup migration from HRMS/includes/config.php.

ALTER TABLE payroll_records
    ADD COLUMN IF NOT EXISTS payout_date DATE NULL,
    ADD COLUMN IF NOT EXISTS approval_status ENUM('draft','n/a','pending','approved','rejected') NOT NULL DEFAULT 'n/a',
    ADD COLUMN IF NOT EXISTS approved_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS approved_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_status ENUM('unpaid','paid') NOT NULL DEFAULT 'unpaid',
    ADD COLUMN IF NOT EXISTS paid_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_processed_by INT NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_processed_at TIMESTAMP NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS payment_reference_number VARCHAR(100) NULL DEFAULT NULL;

ALTER TABLE employees
    ADD COLUMN IF NOT EXISTS payment_method ENUM('bank','cash') NOT NULL DEFAULT 'bank';

CREATE TABLE IF NOT EXISTS payroll_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    payroll_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    performed_by INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_payroll_logs_payroll (payroll_id),
    INDEX idx_payroll_logs_performed (performed_by)
);
