-- Target database: grocery_pos
-- Add the category lookup index for product filtering.
ALTER TABLE products ADD INDEX idx_products_category (category);
