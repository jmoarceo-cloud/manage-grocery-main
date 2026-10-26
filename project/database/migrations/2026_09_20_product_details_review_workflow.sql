-- Target database: grocery_pos
-- Product-details review workflow.
-- Apply during a controlled maintenance window with a migration account.
-- This migration does not change products.stock, price, barcode, or sales data.

ALTER TABLE product_details
    ADD COLUMN review_status ENUM('draft', 'needs_review', 'verified', 'rejected')
        NOT NULL DEFAULT 'draft' AFTER storage_notes,
    ADD COLUMN verified_by INT NULL AFTER updated_by,
    ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
    ADD COLUMN rejected_reason VARCHAR(1000) NULL AFTER verified_at;

ALTER TABLE product_detail_audit
    ADD COLUMN action VARCHAR(32) NOT NULL DEFAULT 'updated' AFTER changed_by,
    ADD COLUMN review_status VARCHAR(32) NULL AFTER action;
