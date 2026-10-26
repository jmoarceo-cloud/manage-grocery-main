-- Persistent request-line to PO/PO-line links and requesting department.
-- Apply after 2026_10_01_procurement_routing_receipts.sql.
-- Existing requests/PO lines retain their data and receive NULL links;
-- department values are populated from HRMS when legacy requests are processed.
SET @schema_name = DATABASE();

SET @has_request_department = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'inventory_requests' AND column_name = 'request_department'
);
SET @ddl = IF(@has_request_department = 0,
    'ALTER TABLE inventory_requests ADD COLUMN request_department VARCHAR(100) DEFAULT NULL AFTER request_group_id',
    'SELECT 1');
PREPARE procurement_link_stmt FROM @ddl;
EXECUTE procurement_link_stmt;
DEALLOCATE PREPARE procurement_link_stmt;

SET @has_request_po = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'inventory_requests' AND column_name = 'purchase_order_id'
);
SET @ddl = IF(@has_request_po = 0,
    'ALTER TABLE inventory_requests ADD COLUMN purchase_order_id INT DEFAULT NULL AFTER request_department',
    'SELECT 1');
PREPARE procurement_link_stmt FROM @ddl;
EXECUTE procurement_link_stmt;
DEALLOCATE PREPARE procurement_link_stmt;

SET @has_poi_request = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = @schema_name AND table_name = 'purchase_order_items' AND column_name = 'inventory_request_id'
);
SET @ddl = IF(@has_poi_request = 0,
    'ALTER TABLE purchase_order_items ADD COLUMN inventory_request_id INT DEFAULT NULL AFTER product_id',
    'SELECT 1');
PREPARE procurement_link_stmt FROM @ddl;
EXECUTE procurement_link_stmt;
DEALLOCATE PREPARE procurement_link_stmt;

SET @has_link_index = (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = @schema_name AND table_name = 'purchase_order_items' AND index_name = 'uq_poi_inventory_request'
);
SET @ddl = IF(@has_link_index = 0,
    'ALTER TABLE purchase_order_items ADD UNIQUE KEY uq_poi_inventory_request (inventory_request_id)',
    'SELECT 1');
PREPARE procurement_link_stmt FROM @ddl;
EXECUTE procurement_link_stmt;
DEALLOCATE PREPARE procurement_link_stmt;

SET @has_link_fk = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = @schema_name AND table_name = 'purchase_order_items' AND constraint_name = 'fk_poi_inventory_request'
);
SET @ddl = IF(@has_link_fk = 0,
    'ALTER TABLE purchase_order_items ADD CONSTRAINT fk_poi_inventory_request FOREIGN KEY (inventory_request_id) REFERENCES inventory_requests(request_id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE procurement_link_stmt FROM @ddl;
EXECUTE procurement_link_stmt;
DEALLOCATE PREPARE procurement_link_stmt;

SET @has_request_po_fk = (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE constraint_schema = @schema_name AND table_name = 'inventory_requests' AND constraint_name = 'fk_ir_purchase_order'
);
SET @ddl = IF(@has_request_po_fk = 0,
    'ALTER TABLE inventory_requests ADD CONSTRAINT fk_ir_purchase_order FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE SET NULL',
    'SELECT 1');
PREPARE procurement_link_stmt FROM @ddl;
EXECUTE procurement_link_stmt;
DEALLOCATE PREPARE procurement_link_stmt;
