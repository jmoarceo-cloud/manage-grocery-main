-- Phase 0 schema-only audit, 2026-10-26. NOT a data backup.
-- No table rows exported. Trigger DEFINER identities replaced with CURRENT_USER.
-- Sensitive non-null defaults, if any, omitted. Do not execute on live databases.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `accounts_payable` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `source_type` enum('purchase_order','inventory_request','operating_expense') NOT NULL,
  `source_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `payee_name` varchar(150) DEFAULT NULL,
  `reference_label` varchar(150) DEFAULT NULL,
  `supplier_invoice_number` varchar(100) DEFAULT NULL,
  `supplier_invoice_date` date DEFAULT NULL,
  `supplier_invoice_amount` decimal(12,2) DEFAULT NULL,
  `amount_owed` decimal(12,2) NOT NULL,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `due_date` date NOT NULL,
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ap_supplier` (`supplier_id`),
  KEY `idx_ap_status` (`status`),
  KEY `idx_ap_source` (`source_type`,`source_id`),
  CONSTRAINT `fk_ap_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_cache` (
  `hash` char(64) NOT NULL,
  `response` longtext NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_ingest_product_cooldowns` (
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `last_attempt_at` datetime NOT NULL,
  PRIMARY KEY (`user_id`,`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_ingest_rate_limits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `hit_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_ingest_user_hit` (`user_id`,`hit_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_settings` (
  `id` int(11) NOT NULL,
  `model` varchar(80) DEFAULT NULL,
  `daily_call_cap` int(11) NOT NULL DEFAULT 100,
  `daily_search_cap` int(11) NOT NULL DEFAULT 20,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_usage_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task` varchar(50) NOT NULL,
  `model` varchar(80) DEFAULT NULL,
  `image_hash` char(64) DEFAULT NULL,
  `prompt_hash` char(64) DEFAULT NULL,
  `input_tokens` int(11) DEFAULT NULL,
  `output_tokens` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `status` enum('ok','cached','error','rate_limited','quota','no_model','capped') NOT NULL,
  `error` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ai_usage_hash` (`image_hash`),
  KEY `idx_ai_usage_created` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=55 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_reads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `announcement_id` int(11) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_announcement` (`user_id`,`announcement_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `budgets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `category` enum('revenue','payroll','restock') NOT NULL,
  `budgeted_amount` decimal(12,2) NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_period_category` (`period_start`,`period_end`,`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `capital_transactions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type` enum('capital_in','capital_out') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `payroll_period_start` date DEFAULT NULL,
  `payroll_period_end` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cashier_lane_schedule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_date` date NOT NULL,
  `employee_id` int(11) NOT NULL,
  `shift_id` int(11) DEFAULT NULL,
  `block_start` time NOT NULL,
  `block_end` time NOT NULL,
  `lane_id` int(11) DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_emp_block` (`schedule_date`,`employee_id`,`block_start`),
  KEY `idx_date_lane` (`schedule_date`,`lane_id`,`block_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(80) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_category` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `discount_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` int(11) NOT NULL,
  `discount_type` enum('percent','fixed') NOT NULL,
  `discount_value` decimal(10,2) NOT NULL,
  `subtotal_at_request` decimal(10,2) NOT NULL,
  `computed_discount_amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `reject_reason` varchar(255) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `expense_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `monthly_budget` decimal(12,2) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `export_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `role` varchar(30) DEFAULT NULL,
  `report_name` varchar(100) NOT NULL,
  `format` enum('xlsx','pdf','csv') NOT NULL,
  `row_count` int(11) NOT NULL DEFAULT 0,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `requested_by_employee_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity` int(11) NOT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `letter_purpose` text DEFAULT NULL,
  `requester_signature_name` varchar(150) DEFAULT NULL,
  `requester_signature_file` varchar(255) DEFAULT NULL,
  `requester_sign_ip` varchar(64) DEFAULT NULL,
  `requester_sign_ua` varchar(255) DEFAULT NULL,
  `supplier_signatory_name` varchar(150) DEFAULT NULL,
  `supplier_signature_file` varchar(255) DEFAULT NULL,
  `formality_letter_file` varchar(255) DEFAULT NULL,
  `letter_integrity_hash` varchar(64) DEFAULT NULL,
  `supplier_sign_token` varchar(64) DEFAULT NULL,
  `supplier_sign_token_expires` timestamp NULL DEFAULT NULL,
  `supplier_sign_requested_at` timestamp NULL DEFAULT NULL,
  `supplier_signed_at` timestamp NULL DEFAULT NULL,
  `supplier_sign_ip` varchar(64) DEFAULT NULL,
  `supplier_sign_ua` varchar(255) DEFAULT NULL,
  `status` enum('pending_validation','pending_finance','pending_supplier','ready_to_receive','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending_validation',
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `finance_signature` longtext DEFAULT NULL,
  `finance_signature_at` datetime DEFAULT NULL,
  `finance_approved_by` int(11) DEFAULT NULL,
  `finance_approved_at` timestamp NULL DEFAULT NULL,
  `finance_signature_name` varchar(150) DEFAULT NULL,
  `finance_signature_file` varchar(255) DEFAULT NULL,
  `finance_sign_ip` varchar(64) DEFAULT NULL,
  `finance_sign_ua` varchar(255) DEFAULT NULL,
  `received_by` int(11) DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `requester_full_name` varchar(150) DEFAULT NULL,
  `requester_signature` longtext DEFAULT NULL,
  `requester_signature_at` datetime DEFAULT NULL,
  `requester_signature_checked_by` int(11) DEFAULT NULL,
  `requester_signature_checked_at` datetime DEFAULT NULL,
  `procurement_letter` longtext DEFAULT NULL,
  `supplier_approval_token_hash` char(64) DEFAULT NULL,
  `supplier_approval_expires_at` datetime DEFAULT NULL,
  `supplier_signature` longtext DEFAULT NULL,
  `supplier_signature_at` datetime DEFAULT NULL,
  `request_group_id` char(36) DEFAULT NULL,
  `request_department` varchar(100) DEFAULT NULL,
  `purchase_order_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_ir_supplier` (`supplier_id`),
  KEY `fk_ir_purchase_order` (`purchase_order_id`),
  CONSTRAINT `fk_ir_purchase_order` FOREIGN KEY (`purchase_order_id`) REFERENCES `purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ir_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=29 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `login_ip_throttle` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `category` varchar(20) NOT NULL DEFAULT 'stock',
  `type` varchar(40) NOT NULL DEFAULT 'low_stock',
  `message` varchar(255) NOT NULL,
  `link_url` varchar(255) DEFAULT NULL,
  `source_type` varchar(30) DEFAULT NULL,
  `source_id` int(11) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_notif_product` (`product_id`),
  KEY `idx_notif_user` (`user_id`,`is_archived`,`is_read`),
  CONSTRAINT `fk_notif_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=537 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `operating_expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `payee_name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `billing_period_start` date DEFAULT NULL,
  `billing_period_end` date DEFAULT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `is_recurring` tinyint(1) NOT NULL DEFAULT 0,
  `receipt_file_path` varchar(255) NOT NULL,
  `status` enum('draft','pending_verification','verified','pending_approval','approved','returned','cancelled') NOT NULL DEFAULT 'draft',
  `requires_level2_approval` tinyint(1) NOT NULL DEFAULT 0,
  `over_budget_flag` tinyint(1) NOT NULL DEFAULT 0,
  `over_budget_reason` varchar(255) DEFAULT NULL,
  `encoded_by` int(11) NOT NULL,
  `encoded_by_name` varchar(150) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_by_name` varchar(150) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `return_reason` varchar(255) DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_oe_status` (`status`),
  KEY `idx_oe_category` (`category_id`),
  CONSTRAINT `fk_oe_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_requests` (
  `request_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(254) NOT NULL,
  `ip_hash` char(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','handled','dismissed') NOT NULL DEFAULT 'pending',
  `handled_by` int(11) DEFAULT NULL,
  `handled_at` datetime DEFAULT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `token_hash` char(64) DEFAULT NULL,
  `token_expires_at` datetime DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL,
  `used_at` datetime DEFAULT NULL,
  `account_system` enum('pos','hrms') NOT NULL DEFAULT 'pos',
  PRIMARY KEY (`request_id`),
  KEY `idx_password_reset_status` (`status`,`requested_at`),
  KEY `idx_password_reset_username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `pos_lanes` (
  `lane_id` int(11) NOT NULL AUTO_INCREMENT,
  `lane_number` int(11) NOT NULL,
  `lane_name` varchar(50) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`lane_id`),
  UNIQUE KEY `idx_lane_number` (`lane_number`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_batches` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `quantity_received` int(11) NOT NULL,
  `quantity_remaining` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `date_received` datetime DEFAULT current_timestamp(),
  `added_by` int(11) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_batch_user` (`added_by`),
  KEY `idx_batch_product_expiry` (`product_id`,`expiry_date`),
  CONSTRAINT `fk_batch_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_batch_user` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=181 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_detail_audit` (
  `audit_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `action` varchar(32) NOT NULL DEFAULT 'updated',
  `source` enum('manual','ai') NOT NULL DEFAULT 'manual',
  `review_status` varchar(32) DEFAULT NULL,
  `before_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_json`)),
  `after_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`after_json`)),
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `idx_product_detail_audit_product` (`product_id`),
  KEY `idx_product_detail_audit_changed_by` (`changed_by`)
) ENGINE=InnoDB AUTO_INCREMENT=446 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_details` (
  `detail_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `description` text DEFAULT NULL,
  `ingredients` text DEFAULT NULL,
  `allergens` varchar(500) DEFAULT NULL,
  `brand` varchar(100) DEFAULT NULL,
  `unit_name` varchar(50) DEFAULT NULL,
  `net_weight` varchar(50) DEFAULT NULL,
  `storage_notes` text DEFAULT NULL,
  `nutrition_basis` enum('per_serving','per_100g') DEFAULT NULL,
  `serving_size` varchar(50) DEFAULT NULL,
  `servings_per_pack` decimal(6,2) DEFAULT NULL,
  `calories` decimal(8,2) DEFAULT NULL,
  `protein_g` decimal(8,2) DEFAULT NULL,
  `carbs_g` decimal(8,2) DEFAULT NULL,
  `fat_g` decimal(8,2) DEFAULT NULL,
  `sugar_g` decimal(8,2) DEFAULT NULL,
  `sodium_mg` decimal(8,2) DEFAULT NULL,
  `nutrition_source` enum('label_photo','web','manual') DEFAULT NULL,
  `nutrition_source_url` varchar(500) DEFAULT NULL,
  `review_status` enum('draft','ai_filled','needs_review','verified','rejected') NOT NULL DEFAULT 'draft',
  `updated_by` int(11) DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `rejected_reason` varchar(1000) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`detail_id`),
  UNIQUE KEY `uq_product_details_product` (`product_id`),
  KEY `idx_product_details_updated_by` (`updated_by`)
) ENGINE=InnoDB AUTO_INCREMENT=208 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_discount_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `requested_by` int(11) NOT NULL,
  `discount_type` enum('none','percent','fixed') NOT NULL DEFAULT 'none',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(255) DEFAULT NULL,
  `price_at_request` decimal(10,2) NOT NULL,
  `old_discount_type` enum('none','percent','fixed') NOT NULL DEFAULT 'none',
  `old_discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `old_discount_reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `reject_reason` varchar(255) DEFAULT NULL,
  `decided_by` int(11) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `product_scan_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` int(11) DEFAULT NULL,
  `scanned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `source` enum('kiosk','phone','catalog_click') NOT NULL,
  `ip_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_scan_date` (`scanned_at`),
  KEY `idx_scan_product` (`product_id`)
) ENGINE=InnoDB AUTO_INCREMENT=320 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(150) NOT NULL,
  `barcode` varchar(64) DEFAULT NULL,
  `category` varchar(80) NOT NULL DEFAULT 'Uncategorized',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_type` enum('none','percent','fixed') NOT NULL DEFAULT 'none',
  `discount_value` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_reason` varchar(100) DEFAULT NULL,
  `cost_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `stock` int(11) NOT NULL DEFAULT 0,
  `supplier` varchar(255) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `image` varchar(255) NOT NULL DEFAULT 'default.png',
  `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_products_barcode` (`barcode`),
  KEY `idx_is_active` (`is_active`),
  KEY `fk_products_supplier` (`supplier_id`),
  KEY `idx_products_category` (`category`),
  CONSTRAINT `fk_products_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=97 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity_ordered` int(11) NOT NULL,
  `quantity_received` int(11) NOT NULL DEFAULT 0,
  `unit_cost` decimal(10,2) NOT NULL,
  `subtotal` decimal(12,2) NOT NULL,
  `inventory_request_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_poi_inventory_request` (`inventory_request_id`),
  KEY `idx_poi_po` (`po_id`),
  KEY `idx_poi_product` (`product_id`),
  CONSTRAINT `fk_poi_inventory_request` FOREIGN KEY (`inventory_request_id`) REFERENCES `inventory_requests` (`request_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_poi_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_receipt_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `receipt_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `quantity_received` int(11) NOT NULL,
  `unit_cost` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pori_receipt` (`receipt_id`),
  CONSTRAINT `fk_pori_receipt` FOREIGN KEY (`receipt_id`) REFERENCES `purchase_order_receipts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_order_receipts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_id` int(11) NOT NULL,
  `receipt_file_path` varchar(255) NOT NULL,
  `original_file_name` varchar(255) DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `received_by` int(11) NOT NULL,
  `received_by_name` varchar(150) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_por_po_received` (`po_id`,`received_at`),
  CONSTRAINT `fk_por_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `purchase_orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `po_number` varchar(20) DEFAULT NULL,
  `supplier_id` int(11) NOT NULL,
  `status` enum('draft','pending_approval','approved','pending_supplier','rejected','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_by_name` varchar(150) DEFAULT NULL,
  `requested_by_hrms_user_id` int(11) DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_by` int(11) DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `ordered_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `department` varchar(100) NOT NULL DEFAULT 'Unassigned',
  `requester_signature` longtext DEFAULT NULL,
  `requester_signature_at` datetime DEFAULT NULL,
  `finance_signature` longtext DEFAULT NULL,
  `finance_signature_at` datetime DEFAULT NULL,
  `supplier_signature` longtext DEFAULT NULL,
  `supplier_signature_at` datetime DEFAULT NULL,
  `supplier_approval_token_hash` char(64) DEFAULT NULL,
  `supplier_approval_expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_po_status` (`status`),
  KEY `idx_po_supplier` (`supplier_id`),
  CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `queue_tickets` (
  `ticket_id` int(11) NOT NULL AUTO_INCREMENT,
  `queue_date` date NOT NULL,
  `lane_id` int(11) NOT NULL,
  `ticket_number` int(11) NOT NULL,
  `status` enum('waiting','serving','done','skipped','cancelled') NOT NULL DEFAULT 'waiting',
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `called_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `served_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`ticket_id`),
  UNIQUE KEY `idx_ticket` (`queue_date`,`lane_id`,`ticket_number`),
  KEY `idx_date_status` (`queue_date`,`lane_id`,`status`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `rate_limit_public` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_hash` char(64) NOT NULL,
  `route` varchar(40) NOT NULL,
  `hit_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_rlp_lookup` (`ip_hash`,`route`,`hit_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1922 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_batch_consumption` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sales_detail_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_consumption_sales_detail` (`sales_detail_id`),
  KEY `fk_consumption_batch` (`batch_id`),
  CONSTRAINT `fk_consumption_batch` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_consumption_sales_detail` FOREIGN KEY (`sales_detail_id`) REFERENCES `sales_details` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=79 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sale_refunds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `sales_detail_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `refunded_by` int(11) DEFAULT NULL,
  `approved_by_name` varchar(150) DEFAULT NULL,
  `refunded_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sale_refunds_sale` (`sale_id`),
  KEY `idx_sale_refunds_detail` (`sales_detail_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `cashier_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_id_number` varchar(50) DEFAULT NULL,
  `discount_holder_name` varchar(150) DEFAULT NULL,
  `discount_approved_by_name` varchar(150) DEFAULT NULL,
  `payment` decimal(10,2) NOT NULL DEFAULT 0.00,
  `change_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('completed','voided','refunded','partial_refunded') NOT NULL DEFAULT 'completed',
  `refund_reason` varchar(500) DEFAULT NULL,
  `refunded_at` datetime DEFAULT NULL,
  `refunded_by` int(11) DEFAULT NULL,
  `refund_approved_by_name` varchar(150) DEFAULT NULL,
  `transaction_date` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cashier_id` (`cashier_id`),
  KEY `fk_refunded_by` (`refunded_by`),
  CONSTRAINT `fk_refunded_by` FOREIGN KEY (`refunded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sales_cashier` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=86 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sales_details` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_sale_id` (`sale_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `fk_sd_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_sd_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=301 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stock_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `change_type` enum('sale','void','refund','restock','adjust','issuance') NOT NULL,
  `quantity_change` int(11) NOT NULL,
  `previous_stock` int(11) NOT NULL,
  `new_stock` int(11) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `total_cost` decimal(10,2) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `validated_by` int(11) DEFAULT NULL,
  `validated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_stock_logs_sale_id` (`sale_id`),
  CONSTRAINT `stock_logs_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `stock_logs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=593 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER `trg_stock_logs_no_update` BEFORE UPDATE ON `stock_logs` FOR EACH ROW BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Audit trail records cannot be modified.';
                END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_general_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_AUTO_VALUE_ON_ZERO' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=CURRENT_USER*/ /*!50003 TRIGGER `trg_stock_logs_no_delete` BEFORE DELETE ON `stock_logs` FOR EACH ROW BEGIN
                    SIGNAL SQLSTATE '45000'
                    SET MESSAGE_TEXT = 'Audit trail records cannot be deleted.';
                END */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `store_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `supplier_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `payable_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `paid_by` int(11) DEFAULT NULL,
  `paid_by_name` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sp_payable` (`payable_id`),
  CONSTRAINT `fk_sp_payable` FOREIGN KEY (`payable_id`) REFERENCES `accounts_payable` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_name` varchar(150) NOT NULL,
  `contact_person` varchar(150) DEFAULT NULL,
  `contact_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `payment_terms_days` int(11) NOT NULL DEFAULT 30,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `procurement_signature_reference` longtext DEFAULT NULL,
  `procurement_signature_enrolled_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_suppliers_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `profile_picture` varchar(255) DEFAULT NULL,
  `specimen_signature_file` varchar(255) DEFAULT NULL,
  `role` enum('admin','cashier','inventory_staff','finance') NOT NULL DEFAULT 'cashier',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `force_logout` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `synced_from_hrms` tinyint(1) NOT NULL DEFAULT 0,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `two_factor_email` varchar(150) DEFAULT NULL,
  `two_factor_backup_codes` text DEFAULT NULL,
  `procurement_signature_reference` longtext DEFAULT NULL,
  `procurement_signature_enrolled_at` datetime DEFAULT NULL,
  `two_factor_failed_attempts` int(11) NOT NULL DEFAULT 0,
  `two_factor_locked_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_username` (`username`),
  UNIQUE KEY `employee_id` (`employee_id`)
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vendor_ratings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_id` int(11) DEFAULT NULL,
  `vendor_name` varchar(150) DEFAULT NULL,
  `rating_date` date NOT NULL,
  `on_time_delivery` tinyint(1) DEFAULT NULL,
  `quality_issue` tinyint(1) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `rated_by` int(11) NOT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_by_name` varchar(150) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

