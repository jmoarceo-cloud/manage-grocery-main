-- Target database: hrms
-- HR Contract and Official Letters
CREATE TABLE IF NOT EXISTS hr_letter_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  letter_type VARCHAR(60) NOT NULL DEFAULT 'official',
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY letter_type (letter_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_letters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no VARCHAR(40) NOT NULL,
  template_id INT UNSIGNED NULL, employee_id INT NULL, created_by INT NOT NULL,
  recipient_name VARCHAR(150) NOT NULL, recipient_email VARCHAR(150) NULL,
  letter_type VARCHAR(60) NOT NULL DEFAULT 'official', subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL, status ENUM('draft','pending_approval','approved','rejected','sent','accepted','declined') NOT NULL DEFAULT 'draft',
  rejection_reason VARCHAR(500) NULL, approved_by INT NULL, approved_at DATETIME NULL,
  sent_at DATETIME NULL, responded_at DATETIME NULL, response_note VARCHAR(500) NULL,
  access_token CHAR(64) NOT NULL, signed_file VARCHAR(255) NULL, signed_original_name VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY reference_no (reference_no), UNIQUE KEY access_token (access_token),
  KEY employee_id (employee_id), KEY status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_letter_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, letter_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL, actor_id INT NULL, details VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY letter_id (letter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_letter_reminders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, letter_id BIGINT UNSIGNED NOT NULL,
  reminder_type VARCHAR(40) NOT NULL, due_at DATETIME NOT NULL, sent_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY letter_reminder (letter_id, reminder_type), KEY due_at (due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS hr_letter_email_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, letter_id BIGINT UNSIGNED NULL,
  recipient_email VARCHAR(150) NOT NULL, subject VARCHAR(255) NOT NULL,
  status ENUM('sent','failed') NOT NULL, error_message VARCHAR(500) NULL,
  sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY letter_id (letter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
