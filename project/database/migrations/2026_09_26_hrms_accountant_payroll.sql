-- Target database: hrms
-- Adds an HRMS Accountant account role for the payroll approval queue.
-- Run after 2026_09_22_payroll_release.sql so approval/payment tracking
-- columns exist. Legacy unpaid n/a records are returned to pending, so
-- they require an explicit Finance/Admin decision before release.
ALTER TABLE users
    MODIFY COLUMN role ENUM('admin','hr','hr_manager','supervisor','accountant','employee')
    NOT NULL DEFAULT 'employee';

UPDATE payroll_records
SET approval_status = 'pending',
    approved_by = NULL,
    approved_at = NULL
WHERE approval_status = 'n/a'
  AND payment_status = 'unpaid';
