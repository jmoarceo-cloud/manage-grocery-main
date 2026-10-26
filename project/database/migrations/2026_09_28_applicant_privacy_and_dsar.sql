-- Target database: hrms
-- Alternative to the automatic migration in HRMS/includes/config.php.
-- Run this manually only if the application has not already applied it.
ALTER TABLE job_applicants
  ADD COLUMN privacy_consent TINYINT(1) NOT NULL DEFAULT 0 AFTER character_references,
  ADD COLUMN privacy_notice_version VARCHAR(32) NULL AFTER privacy_consent,
  ADD COLUMN privacy_consent_at DATETIME NULL AFTER privacy_notice_version;

CREATE TABLE IF NOT EXISTS data_subject_requests (
  request_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  requester_name VARCHAR(150) NOT NULL,
  requester_email VARCHAR(254) NOT NULL,
  request_type ENUM('access','correction','deletion','portability','restriction','objection','other') NOT NULL,
  request_details TEXT NOT NULL,
  status ENUM('pending','identity_verification','identity_verified','in_review','on_hold','completed','denied') NOT NULL DEFAULT 'pending',
  admin_notes TEXT DEFAULT NULL,
  reviewed_by INT(11) DEFAULT NULL,
  reviewed_at DATETIME DEFAULT NULL,
  submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id),
  KEY idx_dsr_status_submitted (status, submitted_at),
  KEY idx_dsr_email (requester_email),
  KEY idx_dsr_reviewer (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
