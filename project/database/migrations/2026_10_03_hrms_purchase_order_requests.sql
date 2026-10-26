SET @schema_name = DATABASE();

SET @has_hrms_requester = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name
      AND table_name = 'purchase_orders'
      AND column_name = 'requested_by_hrms_user_id'
);
SET @ddl = IF(
    @has_hrms_requester = 0,
    'ALTER TABLE purchase_orders ADD COLUMN requested_by_hrms_user_id INT DEFAULT NULL AFTER created_by_name',
    'SELECT 1'
);
PREPARE hrms_po_request_stmt FROM @ddl;
EXECUTE hrms_po_request_stmt;
DEALLOCATE PREPARE hrms_po_request_stmt;
