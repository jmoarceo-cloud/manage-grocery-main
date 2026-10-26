# GroceryOne database baseline and adoption requirements

## Shared system-table recovery - 2026-10-26

The original PHP loading stall required repair of MariaDB's `mysql.db` and
`mysql.time_zone_name` system tables. A private stopped-server copy verified all
1,774 files / 192,201,166 bytes by SHA-256. Candidate recovery retained four
intact database-permission rows and reconstructed 10 GroceryOne rows from the
existing setup scripts and configured identities, yielding the original 14.
The live server's permission rows match the checked candidate; 13 account
identities remain. No account/password file or business table file was replaced,
and no business schema migration/import ran. This is system-table recovery,
not new application schema adoption or a complete cross-project restore drill.
Exact checks and limitations are in PROGRESS.

## Live adoption update - 2026-10-26

Laravel adopted the original schemas in place after the full backup. The
approved migration added only POS `migrations`, `laravel_checkout_requests`
and `application_audit_events`; none of the original 77 tables was recreated
or imported. All original counts matched the protected pre-migration manifest:
8,631 aggregate rows. See [DATA_ADOPTION](audit/DATA_ADOPTION.json).

Phase 0 counted 8,632 rows. The single difference was `rate_limit_public`
(2 at audit, 1 already before backup, 1 after adoption). That change preceded
this migration; its cause was not established. No business-table count
changed during adoption. Later ordinary app telemetry can change counts.

Checkout requests have a unique cashier/UUID key, payload hash and sale link;
approved manual discount IDs are unique and foreign-key checked. Audit events
hold actor/domain/entity/action and changed field names, without value dumps.
The current live table count is 45 POS plus 35 HRMS. The tables below describe
the original baseline; schema-only dumps are historical and are not backups.

On the verified synthetic pair, the baseline migration reconstructs original
tables/keys/indexes/triggers and these additions. `migrate:fresh` is overridden
to verify both ownership markers before resetting only that test pair and
recreating its markers; seeders also enforce isolation. Live automatic
rollback is disabled. No live fresh migration or seeder was run.

Updated 2026-10-26 from [read-only audit metadata](audit/DATABASE_METADATA.json).
The initial audit made no schema or record changes. The [earlier dictionary draft](archive/2026-10-26-pre-audit-DATABASE.md)
is historical and describes fields absent from the live databases.

## Same-server identity transactions

Native password changes add no table/column. IdentityTransaction verifies the
same MySQL host/port/socket/credentials, distinct validated schema names and both
users tables' InnoDB engines before using qualified POS/HRMS names through one
PDO transaction. It locks exact-username rows in fixed domain order, validates
compatible roles/matching employee links and locks current employee status when
present. Both password/force_logout updates, actor recovery consumption and POS
audit events commit together. Other services retain separate transaction
boundaries. Synthetic tests inject a second-domain failure and verify rollback;
no live password was changed by testing.

## Verified targets and artifacts

| Domain | Database | Base tables | Aggregate rows | Triggers |
|---|---|---:|---:|---:|
| POS/inventory/procurement/finance | `grocery_pos` | 42 | 2,810 | 2 |
| Workforce/recruitment/payroll | `hrms` | 35 | 5,822 | 0 |

`manage_grocery` does not exist in audited local metadata. Counts were taken
in separate read-only transactions, can change during use, and do not prove
migration parity. [ROW_COUNTS](audit/ROW_COUNTS.md) lists every table/engine.
[DATABASE_METADATA](audit/DATABASE_METADATA.json) contains complete captured
column types/defaults/nullability, index entries, foreign keys/rules and trigger
inventory. The schema-only dumps cover [POS](audit/schema-grocery_pos.sql) and
[HRMS](audit/schema-hrms.sql); neither contains table records or substitutes for
full backups. Trigger definer identities were normalized in audit copies.

Both configured app and backup accounts failed access with 1044. A standalone
audit used the existing MVC fallback identity; credentials were not logged.
Provision intended access and fail closed before implementation. Do not adopt
the privileged fallback in Laravel.

## Critical verified column names
### `grocery_pos.products`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `product_name` | `varchar(150)` | NO | - |
| `barcode` | `varchar(64)` | YES | UNI |
| `category` | `varchar(80)` | NO | MUL |
| `price` | `decimal(10,2)` | NO | - |
| `discount_type` | `enum('none','percent','fixed')` | NO | - |
| `discount_value` | `decimal(10,2)` | NO | - |
| `discount_reason` | `varchar(100)` | YES | - |
| `cost_price` | `decimal(10,2)` | NO | - |
| `stock` | `int(11)` | NO | - |
| `supplier` | `varchar(255)` | YES | - |
| `supplier_id` | `int(11)` | YES | MUL |
| `expiry_date` | `date` | YES | - |
| `image` | `varchar(255)` | NO | - |
| `date_added` | `timestamp` | NO | - |
| `is_active` | `tinyint(1)` | NO | MUL |

### `grocery_pos.product_batches`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `product_id` | `int(11)` | NO | MUL |
| `quantity_received` | `int(11)` | NO | - |
| `quantity_remaining` | `int(11)` | NO | - |
| `cost_price` | `decimal(10,2)` | YES | - |
| `expiry_date` | `date` | YES | - |
| `date_received` | `datetime` | YES | - |
| `added_by` | `int(11)` | YES | MUL |
| `notes` | `varchar(255)` | YES | - |

### `grocery_pos.sales`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `cashier_id` | `int(11)` | NO | MUL |
| `total_amount` | `decimal(10,2)` | NO | - |
| `discount_amount` | `decimal(10,2)` | NO | - |
| `discount_id_number` | `varchar(50)` | YES | - |
| `discount_holder_name` | `varchar(150)` | YES | - |
| `discount_approved_by_name` | `varchar(150)` | YES | - |
| `payment` | `decimal(10,2)` | NO | - |
| `change_amount` | `decimal(10,2)` | NO | - |
| `status` | `enum('completed','voided','refunded','partial_refunded')` | NO | - |
| `refund_reason` | `varchar(500)` | YES | - |
| `refunded_at` | `datetime` | YES | - |
| `refunded_by` | `int(11)` | YES | MUL |
| `refund_approved_by_name` | `varchar(150)` | YES | - |
| `transaction_date` | `timestamp` | NO | - |

### `grocery_pos.sales_details`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `sale_id` | `int(11)` | NO | MUL |
| `product_id` | `int(11)` | NO | MUL |
| `quantity` | `int(11)` | NO | - |
| `subtotal` | `decimal(10,2)` | NO | - |

### `grocery_pos.sale_batch_consumption`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `sales_detail_id` | `int(11)` | NO | MUL |
| `batch_id` | `int(11)` | YES | MUL |
| `quantity` | `int(11)` | NO | - |

### `grocery_pos.stock_logs`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `product_id` | `int(11)` | NO | MUL |
| `sale_id` | `int(11)` | YES | MUL |
| `change_type` | `enum('sale','void','refund','restock','adjust','issuance')` | NO | - |
| `quantity_change` | `int(11)` | NO | - |
| `previous_stock` | `int(11)` | NO | - |
| `new_stock` | `int(11)` | NO | - |
| `reason` | `varchar(255)` | YES | - |
| `total_cost` | `decimal(10,2)` | YES | - |
| `user_id` | `int(11)` | NO | MUL |
| `date_created` | `timestamp` | NO | - |
| `validated_by` | `int(11)` | YES | - |
| `validated_at` | `timestamp` | YES | - |

### `grocery_pos.users`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `employee_id` | `int(11)` | YES | UNI |
| `username` | `varchar(50)` | NO | UNI |
| `password` | `varchar(255)` | NO | - |
| `full_name` | `varchar(100)` | NO | - |
| `profile_picture` | `varchar(255)` | YES | - |
| `specimen_signature_file` | `varchar(255)` | YES | - |
| `role` | `enum('admin','cashier','inventory_staff','finance')` | NO | - |
| `created_at` | `timestamp` | NO | - |
| `force_logout` | `tinyint(1)` | NO | - |
| `is_active` | `tinyint(1)` | NO | - |
| `synced_from_hrms` | `tinyint(1)` | NO | - |
| `failed_attempts` | `int(11)` | NO | - |
| `is_locked` | `tinyint(1)` | NO | - |
| `two_factor_enabled` | `tinyint(1)` | NO | - |
| `two_factor_email` | `varchar(150)` | YES | - |
| `two_factor_backup_codes` | `text` | YES | - |
| `procurement_signature_reference` | `longtext` | YES | - |
| `procurement_signature_enrolled_at` | `datetime` | YES | - |
| `two_factor_failed_attempts` | `int(11)` | NO | - |
| `two_factor_locked_until` | `datetime` | YES | - |

### `hrms.data_subject_requests`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `request_id` | `bigint(20) unsigned` | NO | PRI |
| `requester_name` | `varchar(150)` | NO | - |
| `requester_email` | `varchar(254)` | NO | MUL |
| `request_type` | `enum('access','correction','deletion','portability','restriction','objection','other')` | NO | - |
| `request_details` | `text` | NO | - |
| `status` | `enum('pending','identity_verification','identity_verified','in_review','on_hold','completed','fulfilled','denied')` | NO | MUL |
| `admin_notes` | `text` | YES | - |
| `reviewed_by` | `int(11)` | YES | MUL |
| `reviewed_at` | `datetime` | YES | - |
| `identity_verified_at` | `datetime` | YES | - |
| `response_filename` | `varchar(255)` | YES | - |
| `response_original_name` | `varchar(255)` | YES | - |
| `response_expires_at` | `datetime` | YES | - |
| `submitted_at` | `timestamp` | NO | - |
| `updated_at` | `timestamp` | NO | - |

### `hrms.employees`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `employee_id` | `int(11)` | NO | PRI |
| `employee_code` | `varchar(20)` | NO | UNI |
| `first_name` | `varchar(50)` | NO | - |
| `last_name` | `varchar(50)` | NO | - |
| `middle_name` | `varchar(50)` | YES | - |
| `birthdate` | `date` | YES | - |
| `place_of_birth` | `varchar(150)` | YES | - |
| `sex` | `varchar(20)` | YES | - |
| `civil_status` | `varchar(20)` | YES | - |
| `highest_educational_attainment` | `varchar(60)` | YES | - |
| `year_graduated` | `varchar(4)` | YES | - |
| `character_references` | `text` | YES | - |
| `address` | `text` | YES | - |
| `contact_number` | `varchar(20)` | YES | - |
| `email` | `varchar(100)` | YES | - |
| `sss_number` | `varchar(20)` | YES | - |
| `philhealth_number` | `varchar(20)` | YES | - |
| `pagibig_number` | `varchar(20)` | YES | - |
| `tin_number` | `varchar(20)` | YES | - |
| `department` | `varchar(50)` | YES | - |
| `position` | `varchar(50)` | YES | - |
| `employment_type` | `enum('regular','probationary','contractual')` | YES | - |
| `schedule_type` | `enum('full_time','part_time')` | NO | - |
| `date_hired` | `date` | NO | - |
| `basic_salary` | `decimal(10,2)` | NO | - |
| `bank_name` | `varchar(100)` | YES | - |
| `bank_account_number` | `varchar(50)` | YES | - |
| `bank_account_name` | `varchar(100)` | YES | - |
| `payment_method` | `enum('bank','cash')` | NO | - |
| `status` | `enum('active','resigned','terminated','on-leave')` | YES | - |
| `offboarded_at` | `date` | YES | - |
| `created_at` | `timestamp` | NO | - |
| `suspension_start_date` | `date` | YES | - |
| `suspension_end_date` | `date` | YES | - |
| `suspension_reason` | `varchar(255)` | YES | - |

### `hrms.job_applicants`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `applicant_id` | `int(11)` | NO | PRI |
| `first_name` | `varchar(50)` | NO | - |
| `last_name` | `varchar(50)` | NO | - |
| `date_of_birth` | `date` | YES | - |
| `place_of_birth` | `varchar(150)` | YES | - |
| `sex` | `varchar(20)` | YES | - |
| `civil_status` | `varchar(20)` | YES | - |
| `home_address` | `varchar(255)` | YES | - |
| `email` | `varchar(100)` | NO | - |
| `contact_number` | `varchar(20)` | YES | - |
| `position_applied` | `varchar(100)` | NO | - |
| `employment_type` | `varchar(20)` | YES | - |
| `highest_educational_attainment` | `varchar(60)` | YES | - |
| `year_graduated` | `varchar(4)` | YES | - |
| `character_references` | `text` | YES | - |
| `privacy_consent` | `tinyint(1)` | NO | - |
| `privacy_notice_version` | `varchar(32)` | YES | - |
| `privacy_consent_at` | `datetime` | YES | - |
| `resume_filename` | `varchar(255)` | NO | - |
| `resume_original_name` | `varchar(255)` | NO | - |
| `cover_note` | `text` | YES | - |
| `custom_responses` | `longtext` | YES | - |
| `status` | `enum('for_resume','for_initial','for_final','for_requirements','for_admin_approval','hired','rejected','disqualified')` | NO | - |
| `initial_interview_date` | `date` | YES | - |
| `final_interview_date` | `date` | YES | - |
| `requirements_deadline` | `date` | YES | - |
| `requirements_token` | `varchar(64)` | YES | UNI |
| `reviewed_by` | `int(11)` | YES | MUL |
| `decided_by` | `int(11)` | YES | MUL |
| `orientation_date` | `date` | YES | - |
| `applied_at` | `timestamp` | NO | - |
| `updated_at` | `timestamp` | NO | - |

### `hrms.users`

| Column | Type | Nullable | Key |
|---|---|---|---|
| `id` | `int(11)` | NO | PRI |
| `employee_id` | `int(11)` | YES | MUL |
| `username` | `varchar(50)` | NO | UNI |
| `password` | `varchar(255)` | NO | - |
| `failed_attempts` | `int(11)` | NO | - |
| `locked_until` | `datetime` | YES | - |
| `full_name` | `varchar(100)` | NO | - |
| `profile_picture` | `varchar(255)` | YES | - |
| `role` | `enum('admin','hr','hr_manager','supervisor','accountant','employee')` | NO | - |
| `force_logout` | `tinyint(1)` | NO | - |
| `is_active` | `tinyint(1)` | YES | - |
| `created_at` | `timestamp` | NO | - |
| `two_factor_enabled` | `tinyint(1)` | NO | - |
| `two_factor_email` | `varchar(150)` | YES | - |
| `two_factor_backup_codes` | `text` | YES | - |
| `two_factor_failed_attempts` | `int(11)` | NO | - |
| `two_factor_locked_until` | `datetime` | YES | - |

## Porting contracts

- Preserve the source schema before proposing additive fields. Do not rename
  stock/date/identity fields to fit the newer MVC service's guessed SQL.
- Preserve exact decimal precision, nullability, enums, defaults, indexes,
  engine/collation and foreign-key actions from captured metadata. Table-level
  values can differ; do not infer one uniform setting from a generic draft.
- Historical sales lines store quantity/subtotal without `unit_price`. Products
  use `product_name`, `stock`, `is_active` and a category value; batches use
  `quantity_remaining` and `expiry_date`. Preserve historical price semantics.
- `sale_batch_consumption` uses `sales_detail_id`, `batch_id`, `quantity`.
  `stock_logs` uses previous/new stock and `quantity_change`; its two BEFORE
  triggers reject UPDATE/DELETE. Test the immutable ledger on an isolated DB.
- HR employees use `employee_id` and `employee_code`; privacy/applicant table
  names are `data_subject_requests` and `job_applicants`.
- Keep POS/HRMS users and primary keys separate, with explicit Laravel model
  connections/guards. Plan identity synchronization and reconciliation rather
  than assuming transactions on one connection cover both databases.
- Both existing POS schema files contain only 40 CREATE TABLE statements and
  no INSERTs. Generate adoption/rebuild migrations from current verified schema,
  including all 77 tables and both triggers, not just those older files.
- After the user checkpoint, create protected full backups and verify restore
  on a specifically identified disposable pair. Use synthetic fixtures for
  feature tests. Never fresh-migrate, seed or reimport onto live populated DBs.
- Record coordinated before/after counts, constraints and financial/batch/file
  reference reconciliation when data adoption actually occurs. Phase 0 does
  not check any migration-data parity box.

The preceding baseline notes describe the Phase 0 starting point. The current
adoption migration and guarded synthetic seeder are implemented; the additive
tables and before/after evidence are described at the top of this document.
No new index was added to an original business table. See [mapping](MIGRATION_MAPPING.md),
[parity](PARITY.md) and [documentation maintenance](DOCUMENTATION_MAINTENANCE.md).

Current operational caveat: the shared MariaDB privilege-table startup failure prevents a fresh live query/reconciliation. Original adoption evidence remains dated. Synthetic tests now run on a newly initialized project-local instance on port 3307; no business records were copied there.

Manual-discount port uses existing discount_requests(request_id, cashier_id,
discount_type/value, subtotal_at_request, computed_discount_amount, reason,
status, reject_reason, decided_by/at, requested_at). decided_by remains a POS
actor ID, including explicit HR-Admin mirror mapping. No schema change is needed;
the adopted unique laravel_checkout_requests.discount_request_id link records
native consumption. It does not retrospectively establish usage of older
approved requests in the preserved app. Only synthetic fixtures were mutated.
Label generation reads existing active products and their current discount
fields. No schema/adoption update is needed and no product identifier is repaired
or regenerated automatically. The synthetic-only guarded seeder adds an active,
zero-stock label sample with a known checksum-valid EAN for decoder verification.
No such product was inserted into either live domain.
Public facts use the existing product_details(product_id) relationship and only
review_status=verified for active products. Description, brand/unit/weight,
ingredients/allergens/storage, nutrition basis/serving information, six recorded
nutrients and a sanitized web source URL are the public projection. Internal
updated_by/verified_by/rejected_reason fields are not selected. No schema or live
record update was made; the guarded test seeder adds synthetic verified facts.

Public code lookup uses the existing unique products.barcode index and active
flag, selecting only an ID. It does not add tables/indexes, change stock, reserve
products or write scan logs. Verification checks no writes or HR-domain query.

Product discount approval reuses product_discount_requests and products, with
no migration or historical row rewrite. Native requests capture price_at_request
and old_discount_type/value/reason under product lock. Approval applies new
discount fields with status/decider/time/audit in one POS transaction; failed
audit rolls both back. Product locking serializes native pending checks; the
preserved table has only its request primary key, so no database uniqueness or
history-scale index claim is made. No stock, batch quantity, financial sale or
payment record changes during request/decision.

Manual information review reuses product_details (unique product_id) and
product_detail_audit. Only description/brand/unit/storage plus review metadata
are editable here. Other recorded facts remain unchanged. Product review audit
stores before/after public fact projections and state/rejection reason, omitting
unsafe credential-bearing source references; actor is recorded separately.
Minimal domain audit stores field names only. Creation/edit/publication and both
audit writes share one POS transaction; failures restore the former state.
No schema change is needed for stale protection: the HMAC covers the full saved
row and latest AI extraction context, including nutrient-only changes within
one timestamp second. Original historical audit/source values are not bulk rewritten.

Uncategorized native product writes use products.category = empty string, never
NULL; the existing non-null text column is preserved. Empty input on update
clears only that product's category, without inventing a categories row or
changing stock. Operational body references retain the existing signed INT ID
range (1..2147483647). No type, index, key, table or historical data migration is
introduced by these request-boundary fixes.

Own-profile updates introduce no migration, key, index or copied live record.
Only employees.email/contact_number/birthdate/place_of_birth/sex/civil_status/
address/highest_educational_attainment/year_graduated can change, through the
authenticated account's explicit employee link. IdentityTransaction validates
same-server connections and transactional user tables; profile additionally
requires InnoDB employees. Qualified HR update and POS profile_updated audit are
one transaction; an injected audit failure rolls both back. Audit values contain
field names, not personal values. users.two_factor_email and payroll are untouched.

The native duplicate-email protocol hashes schema names and lowercase ASCII
email into an advisory lock name. GET_LOCK is connection-scoped and does not end
at transaction commit; RELEASE_LOCK runs explicitly after commit/rollback.
IS_USED_LOCK is compared with CONNECTION_ID inside the transaction to reject a
lost/reconnected lock. See official MariaDB [GET_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/get_lock),
[IS_USED_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/is_used_lock)
and [RELEASE_LOCK](https://mariadb.com/docs/server/reference/sql-functions/secondary-functions/miscellaneous-functions/release_lock).
Two real synthetic PHP workers confirm one winner for case-variant email writes.
No unique constraint was added; legacy/direct writers and separate servers do
not participate. Existing duplicated records are not rewritten.

Own schedule reads existing employees, employee_weekly_shifts, shifts and shift_swaps with explicit projections. uniq_emp_day bounds the recurring read to seven valid weekdays; uniq_covering_date bounds the approved own covering read to seven calendar dates. No DDL, new index, business write, attendance auto-marking or historical schedule snapshot is introduced. A current schedule view cannot establish historical attendance/payroll correctness.

Own leave uses existing leave_requests/leave_credits and additive application
audit; no migration or new key/index. GET defaults are estimates and never
inserted. A successful vacation submission may create its missing start-year
credit row using existing defaults; request remains pending and used_days stays
unchanged. Single-PDO qualified HR writes plus minimal POS audit roll back
together. No submission leave_request_logs row is fabricated: its performed_by
references HR users and cannot safely receive a POS numeric ID. The native
application audit records the actual domain and ID instead. Two-process tests
verify same-employee pending retry serialization; no uniqueness constraint or
legacy-writer guarantee is claimed. Original start-year charging and pending
nonreservation semantics remain explicit; approval/credit reconciliation is open.

Native leave decisions use existing HR leave_requests, leave_credits,
leave_request_logs, employees/users/supervisor_teams and POS application audit.
No DDL/index or business-record import. HR performed_by/approved_by always uses
the authenticated HR user ID, never a POS numeric ID. One validated same-server
PDO transaction updates status/credit/log/audit; transactional HR engines are
verified. Missing tracked credit gets the existing default inside that commit.
Vacation balance is rechecked under locks; sick/emergency can exceed allocation
but not DECIMAL(5,2) tracking storage. Start-year/inclusive-day semantics remain.
Rejection creates no credit. Terminal decisions cannot deduct again. Injected
audit failure rolls back all effects. Two real contention checks confirm one
deduction per request and no native vacation overdraw across competing requests;
legacy/direct writers do not share this protocol. Native approvals supersede
the earlier own-leave checkpoint's pending decision-port limitation.

Own overtime introduces no DDL/index and writes only existing overtime_requests plus minimal application audit. The existing DECIMAL(4,2) request field receives normalized0.01..12.00 hours, always pending with server timestamps and no approver. One validated qualified-schema transaction locks the real actor/employee and commits request/audit atomically. Exact normalized pending retries serialize across POS/HR identities; no new unique key or legacy-writer guarantee. GET selects only own request columns, matching attendance date/time fields and weekly shift times; GPS/financial fields and payroll tables are not queried or written.

Native overtime decisions introduce no DDL or extra log table. Existing overtime_requests status/approved_by/decided_at and minimal application audit commit on one validated same-server PDO transaction. Actual HR user IDs are used. InnoDB is checked for involved employees, requests, teams, attendance, weekly assignments and shifts. Request hours/attendance/payroll stay unchanged. Same-request contention produces one terminal decision/audit; injected audit failure restores pending/null actor/time. Raw evidence and current team rows are locked/rechecked. Current-schedule display does not reconstruct historical assignment snapshots or guarantee legacy writer serialization.
