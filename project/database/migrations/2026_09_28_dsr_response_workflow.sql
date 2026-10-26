-- Upgrade for the DSAR admin response workflow.
-- HRMS/includes/config.php applies this idempotently at runtime as well.
ALTER TABLE data_subject_requests
  MODIFY status ENUM(
    'pending',
    'identity_verification',
    'identity_verified',
    'in_review',
    'on_hold',
    'completed',
    'fulfilled',
    'denied'
  ) NOT NULL DEFAULT 'pending',
  ADD COLUMN identity_verified_at DATETIME NULL AFTER reviewed_at,
  ADD COLUMN response_filename VARCHAR(255) NULL AFTER identity_verified_at,
  ADD COLUMN response_original_name VARCHAR(255) NULL AFTER response_filename,
  ADD COLUMN response_expires_at DATETIME NULL AFTER response_original_name;
