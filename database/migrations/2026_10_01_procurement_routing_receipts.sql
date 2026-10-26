-- Idempotent procurement enhancements for department routing and receipt history.
-- Run against grocery_pos after the base schema and earlier migrations.
SET @schema_name = DATABASE();

SET @has_department = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_orders' AND column_name = 'department'
);
SET @ddl = IF(@has_department = 0,
    'ALTER TABLE purchase_orders ADD COLUMN department VARCHAR(100) NOT NULL DEFAULT ''Unassigned'' AFTER supplier_id',
    'SELECT 1');
PREPARE procurement_stmt FROM @ddl;
EXECUTE procurement_stmt;
DEALLOCATE PREPARE procurement_stmt;

SET @has_request_group = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'inventory_requests' AND column_name = 'request_group_id'
);
SET @ddl = IF(@has_request_group = 0,
    'ALTER TABLE inventory_requests ADD COLUMN request_group_id CHAR(36) DEFAULT NULL AFTER requested_by_employee_id',
    'SELECT 1');
PREPARE procurement_stmt FROM @ddl;
EXECUTE procurement_stmt;
DEALLOCATE PREPARE procurement_stmt;

CREATE TABLE IF NOT EXISTS purchase_order_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    po_id INT NOT NULL,
    receipt_file_path VARCHAR(255) NOT NULL,
    original_file_name VARCHAR(255) DEFAULT NULL,
    note VARCHAR(500) DEFAULT NULL,
    received_by INT NOT NULL,
    received_by_name VARCHAR(150) DEFAULT NULL,
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_por_po_received (po_id, received_at),
    CONSTRAINT fk_por_po FOREIGN KEY (po_id) REFERENCES purchase_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS purchase_order_receipt_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    receipt_id INT NOT NULL,
    item_name VARCHAR(150) NOT NULL,
    quantity_received INT NOT NULL,
    unit_cost DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,
    KEY idx_pori_receipt (receipt_id),
    CONSTRAINT fk_pori_receipt FOREIGN KEY (receipt_id)
        REFERENCES purchase_order_receipts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
