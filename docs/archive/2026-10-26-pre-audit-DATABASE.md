> Historical draft preserved before the 2026-10-26 audit correction.
> Its schema, security, runtime and legal-compliance claims are unverified or
> contradicted by the current audit. Do not use it for implementation,
> operations, retention decisions or release evidence. Read [the audit](../AUDIT.md)
> and the current [document](../DATABASE.md) instead.

# Manage-Grocery Database Architecture & Dictionary

Manage-Grocery operates a dual MariaDB database schema: `grocery_pos` (Retail POS, Inventory, Finance, and Procurement) and `hrms` (Human Resources, Payroll, and Attendance).

---

## 1. Schema Specifications
- **Engine**: InnoDB (Foreign Key constraints enforced, ACID compliant transactions).
- **Default Charset / Collation**: `utf8mb4` / `utf8mb4_unicode_ci`.
- **Timezone**: `Asia/Manila` (UTC+08:00).

---

## 2. `grocery_pos` Core Data Dictionary (42 Tables)

### `products`
Master catalog of retail inventory items.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `category_id` (INT UNSIGNED, FK -> `categories.id`, Indexed)
- `barcode` (VARCHAR(50), UNIQUE, NOT NULL, Indexed)
- `name` (VARCHAR(255), NOT NULL)
- `price` (DECIMAL(10,2), NOT NULL) - *Selling retail price*
- `cost_price` (DECIMAL(10,2), NOT NULL) - *Default procurement cost*
- `stock_quantity` (INT, NOT NULL, Default 0) - *Current aggregated on-hand inventory*
- `reorder_level` (INT, NOT NULL, Default 10) - *Threshold triggering restock warning*
- `image` (VARCHAR(255), NULL) - *Relative path to product image*
- `status` (ENUM('active', 'inactive', 'archived'), Default 'active')
- `created_at`, `updated_at` (TIMESTAMP)

### `product_batches`
Lot and expiration date tracking supporting FIFO inventory consumption.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `product_id` (INT UNSIGNED, FK -> `products.id`, ON DELETE CASCADE, Indexed)
- `batch_number` (VARCHAR(100), NOT NULL, Indexed)
- `quantity` (INT, NOT NULL) - *Initial received batch quantity*
- `remaining_quantity` (INT, NOT NULL) - *Current remaining unsold batch quantity*
- `expiration_date` (DATE, NOT NULL, Indexed)
- `cost_price` (DECIMAL(10,2), NOT NULL)
- `created_at` (TIMESTAMP, Default CURRENT_TIMESTAMP)

### `sales`
Master transaction headers.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `sale_number` (VARCHAR(50), UNIQUE, NOT NULL, Indexed)
- `cashier_id` (INT UNSIGNED, FK -> `users.id`, Indexed)
- `pos_lane_id` (INT UNSIGNED, NULL)
- `total_amount` (DECIMAL(10,2), NOT NULL)
- `tax_amount` (DECIMAL(10,2), NOT NULL, Default 0.00)
- `discount_amount` (DECIMAL(10,2), NOT NULL, Default 0.00)
- `discount_type` (VARCHAR(50), NULL) - *e.g., senior, pwd, employee, promo*
- `payment_method` (VARCHAR(50), NOT NULL) - *cash, gcash, maya, split*
- `created_at` (TIMESTAMP, Default CURRENT_TIMESTAMP, Indexed)

### `sales_details`
Line items associated with a sales transaction.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `sale_id` (INT UNSIGNED, FK -> `sales.id`, ON DELETE CASCADE, Indexed)
- `product_id` (INT UNSIGNED, FK -> `products.id`, Indexed)
- `quantity` (INT, NOT NULL)
- `unit_price` (DECIMAL(10,2), NOT NULL)
- `subtotal` (DECIMAL(10,2), NOT NULL)

### `sale_batch_consumption`
Audit ledger mapping exact batch depletion per transaction line.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `sale_detail_id` (INT UNSIGNED, FK -> `sales_details.id`, ON DELETE CASCADE, Indexed)
- `batch_id` (INT UNSIGNED, FK -> `product_batches.id`, Indexed)
- `consumed_quantity` (INT, NOT NULL)

### `stock_logs`
Immutable inventory movement audit trail.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `product_id` (INT UNSIGNED, FK -> `products.id`, Indexed)
- `change_quantity` (INT, NOT NULL)
- `remaining_stock` (INT, NOT NULL)
- `reason` (VARCHAR(255), NOT NULL) - *sale, restock, damage, adjustment*
- `reference_id` (VARCHAR(100), NULL) - *Sale number or PO number*
- `user_id` (INT UNSIGNED, FK -> `users.id`)
- `created_at` (TIMESTAMP, Default CURRENT_TIMESTAMP)

### `purchase_orders` & `purchase_order_receipts`
Procurement and inventory replenishment lifecycle.
- `purchase_orders`: `id`, `po_number` (UNIQUE), `supplier_id`, `total_cost`, `status` (pending, approved, received, cancelled), `created_by`, `approved_by`, `signature_path`.
- `purchase_order_receipts`: `id`, `po_id` (FK -> `purchase_orders.id`), `receipt_number`, `invoice_amount`, `receipt_file_path`, `received_by`, `received_at`.

---

## 3. `hrms` Core Data Dictionary (35 Tables)

### `employees`
Employee master record and statutory profile.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `employee_number` (VARCHAR(50), UNIQUE, NOT NULL, Indexed)
- `first_name`, `last_name` (VARCHAR(100), NOT NULL)
- `department` (VARCHAR(100), NOT NULL)
- `position` (VARCHAR(100), NOT NULL)
- `basic_salary` (DECIMAL(10,2), NOT NULL)
- `sss_number`, `philhealth_number`, `pagibig_number`, `tin_number` (VARCHAR(50), NULL)
- `status` (ENUM('active', 'probationary', 'resigned', 'terminated'), Default 'active')

### `attendance`
Geofenced employee time-in and time-out events.
- `id` (INT UNSIGNED, PK, Auto Increment)
- `employee_id` (INT UNSIGNED, FK -> `employees.id`, Indexed)
- `work_date` (DATE, NOT NULL, Indexed)
- `time_in` (TIMESTAMP, NULL)
- `time_out` (TIMESTAMP, NULL)
- `latitude` (DECIMAL(10,8), NULL)
- `longitude` (DECIMAL(11,8), NULL)
- `geofence_status` (ENUM('inside_geofence', 'outside_geofence', 'manual_override'), NOT NULL)

