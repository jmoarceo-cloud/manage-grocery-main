-- Target database: hrms
-- Applicant-to-contract workflow and drawn electronic signatures.
ALTER TABLE hr_letters
  ADD COLUMN applicant_id INT NULL AFTER employee_id,
  ADD COLUMN contract_start_date DATE NULL AFTER letter_type,
  ADD COLUMN contract_end_date DATE NULL AFTER contract_start_date,
  ADD COLUMN contract_duration_label VARCHAR(100) NULL AFTER contract_end_date,
  ADD COLUMN recipient_signature LONGTEXT NULL AFTER response_note,
  ADD COLUMN recipient_signature_at DATETIME NULL AFTER recipient_signature,
  ADD KEY applicant_id (applicant_id);
