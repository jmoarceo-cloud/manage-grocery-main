-- Target database: grocery_pos
-- Supersedes and replaces the withdrawn 2026_09_21_consumer_ai_foundation.sql.
-- Schema-verified consumer layer foundation.
-- Apply once with a database migration account.

ALTER TABLE product_details
    ADD COLUMN ingredients TEXT NULL AFTER description,
    ADD COLUMN allergens VARCHAR(500) NULL AFTER ingredients,
    ADD COLUMN net_weight VARCHAR(50) NULL AFTER unit_name,
    ADD COLUMN nutrition_basis ENUM('per_serving','per_100g') NULL AFTER storage_notes,
    ADD COLUMN serving_size VARCHAR(50) NULL AFTER nutrition_basis,
    ADD COLUMN servings_per_pack DECIMAL(6,2) NULL AFTER serving_size,
    ADD COLUMN calories DECIMAL(8,2) NULL AFTER servings_per_pack,
    ADD COLUMN protein_g DECIMAL(8,2) NULL AFTER calories,
    ADD COLUMN carbs_g DECIMAL(8,2) NULL AFTER protein_g,
    ADD COLUMN fat_g DECIMAL(8,2) NULL AFTER carbs_g,
    ADD COLUMN sugar_g DECIMAL(8,2) NULL AFTER fat_g,
    ADD COLUMN sodium_mg DECIMAL(8,2) NULL AFTER sugar_g;

ALTER TABLE product_details
    MODIFY COLUMN review_status ENUM('draft','ai_filled','needs_review','verified','rejected')
        NOT NULL DEFAULT 'draft';

ALTER TABLE product_detail_audit
    ADD COLUMN source ENUM('manual','ai') NOT NULL DEFAULT 'manual' AFTER action;

CREATE TABLE IF NOT EXISTS rate_limit_public (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip_hash CHAR(64) NOT NULL,
    route VARCHAR(40) NOT NULL,
    hit_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rlp_lookup (ip_hash, route, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_scan_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id INT NULL,
    source ENUM('kiosk','phone','catalog_click') NOT NULL,
    ip_hash CHAR(64) NULL,
    scanned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pse_date (scanned_at),
    KEY idx_pse_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ai_settings
    MODIFY COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1;

ALTER TABLE ai_usage_log
    MODIFY COLUMN status ENUM('ok','cached','error','rate_limited','quota','no_model','capped') NOT NULL;

INSERT IGNORE INTO ai_settings (id, model, daily_call_cap, enabled)
VALUES (1, NULL, 100, 1);

CREATE TABLE IF NOT EXISTS ai_cache (
    hash CHAR(64) NOT NULL,
    response MEDIUMTEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (hash),
    KEY idx_ac_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
