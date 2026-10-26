-- Target database: grocery_pos
ALTER TABLE product_details
    ADD COLUMN nutrition_source ENUM('label_photo','web','manual') NULL AFTER sodium_mg,
    ADD COLUMN nutrition_source_url VARCHAR(500) NULL AFTER nutrition_source;

ALTER TABLE ai_settings
    ADD COLUMN daily_search_cap INT NOT NULL DEFAULT 20 AFTER daily_call_cap;
