SET @schema_name = DATABASE();

SET @has_hrms_po_requester = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'requested_by_hrms_user_id'
);
SET @ddl = IF(@has_hrms_po_requester = 0,
    'ALTER TABLE purchase_orders ADD COLUMN requested_by_hrms_user_id INT DEFAULT NULL AFTER created_by_name',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_requester_signature = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'requester_signature'
);
SET @ddl = IF(@has_requester_signature = 0,
    'ALTER TABLE purchase_orders ADD COLUMN requester_signature LONGTEXT DEFAULT NULL AFTER requested_by_hrms_user_id',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_requester_signature_at = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'requester_signature_at'
);
SET @ddl = IF(@has_requester_signature_at = 0,
    'ALTER TABLE purchase_orders ADD COLUMN requester_signature_at DATETIME DEFAULT NULL AFTER requester_signature',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_finance_signature = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'finance_signature'
);
SET @ddl = IF(@has_finance_signature = 0,
    'ALTER TABLE purchase_orders ADD COLUMN finance_signature LONGTEXT DEFAULT NULL AFTER approved_at',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_finance_signature_at = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'finance_signature_at'
);
SET @ddl = IF(@has_finance_signature_at = 0,
    'ALTER TABLE purchase_orders ADD COLUMN finance_signature_at DATETIME DEFAULT NULL AFTER finance_signature',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_supplier_signature = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'supplier_signature'
);
SET @ddl = IF(@has_supplier_signature = 0,
    'ALTER TABLE purchase_orders ADD COLUMN supplier_signature LONGTEXT DEFAULT NULL AFTER finance_signature_at',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_supplier_signature_at = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'supplier_signature_at'
);
SET @ddl = IF(@has_supplier_signature_at = 0,
    'ALTER TABLE purchase_orders ADD COLUMN supplier_signature_at DATETIME DEFAULT NULL AFTER supplier_signature',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_supplier_token_hash = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'supplier_approval_token_hash'
);
SET @ddl = IF(@has_supplier_token_hash = 0,
    'ALTER TABLE purchase_orders ADD COLUMN supplier_approval_token_hash CHAR(64) DEFAULT NULL AFTER supplier_signature_at',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_supplier_token_expiry = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'supplier_approval_expires_at'
);
SET @ddl = IF(@has_supplier_token_expiry = 0,
    'ALTER TABLE purchase_orders ADD COLUMN supplier_approval_expires_at DATETIME DEFAULT NULL AFTER supplier_approval_token_hash',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;

SET @has_pending_supplier_status = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders'
      AND column_name = 'status' AND column_type LIKE '%pending_supplier%'
);
SET @ddl = IF(@has_pending_supplier_status = 0,
    'ALTER TABLE purchase_orders MODIFY status ENUM(''draft'',''pending_approval'',''approved'',''pending_supplier'',''rejected'',''ordered'',''partially_received'',''received'',''cancelled'') NOT NULL DEFAULT ''draft''',
    'SELECT 1');
PREPARE hr_po_stmt FROM @ddl;
EXECUTE hr_po_stmt;
DEALLOCATE PREPARE hr_po_stmt;
