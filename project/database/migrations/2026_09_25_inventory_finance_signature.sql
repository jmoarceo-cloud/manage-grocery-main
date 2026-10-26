-- Target database: grocery_pos
-- Drawn signature required when Finance approves an inventory restock request.
ALTER TABLE inventory_requests
  ADD COLUMN finance_signature LONGTEXT NULL AFTER decided_at,
  ADD COLUMN finance_signature_at DATETIME NULL AFTER finance_signature,
  ADD COLUMN requester_full_name VARCHAR(150) NULL,
  ADD COLUMN requester_signature LONGTEXT NULL,
  ADD COLUMN requester_signature_at DATETIME NULL,
  ADD COLUMN procurement_letter LONGTEXT NULL,
  ADD COLUMN supplier_approval_token_hash CHAR(64) NULL,
  ADD COLUMN supplier_approval_expires_at DATETIME NULL,
  ADD COLUMN supplier_signature LONGTEXT NULL,
  ADD COLUMN supplier_signature_at DATETIME NULL;

  ALTER TABLE inventory_requests
      ADD COLUMN requester_signature_checked_by INT DEFAULT NULL,
      ADD COLUMN requester_signature_checked_at DATETIME DEFAULT NULL;

  ALTER TABLE users
      ADD COLUMN procurement_signature_reference LONGTEXT NULL,
      ADD COLUMN procurement_signature_enrolled_at DATETIME NULL;

  ALTER TABLE suppliers
      ADD COLUMN procurement_signature_reference LONGTEXT NULL,
      ADD COLUMN procurement_signature_enrolled_at DATETIME NULL;

ALTER TABLE inventory_requests MODIFY status
  ENUM('pending_validation','pending_finance','pending_supplier','ready_to_receive','approved','rejected','fulfilled')
  NOT NULL DEFAULT 'pending_validation';
