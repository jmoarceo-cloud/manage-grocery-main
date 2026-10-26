-- Target database: grocery_pos
-- Adds a durable sale reference to new sale, void, and refund stock logs.
-- Existing legacy rows remain readable with sale_id NULL.
ALTER TABLE stock_logs ADD COLUMN sale_id INT NULL AFTER product_id;
CREATE INDEX idx_stock_logs_sale_id ON stock_logs (sale_id);
