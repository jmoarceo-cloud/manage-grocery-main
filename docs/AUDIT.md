# GroceryOne Laravel migration: Phase 0 audit

Date: 2026-10-26, Asia/Manila. **Audit complete; Laravel implementation has not started.**

The current owner explicitly requests migration. The attached brief also says
"Begin with Phase 0" and "Wait for my go before Phase 1." This is that
checkpoint. Only documentation and audit artifacts were changed. Standalone
collectors read configuration as text without including it; normal HTTP GET
probes used the reviewed newer MVC entry point. No legacy bootstrap,
migrations/seeders or operational backups ran, records were not exported, and
no Git repository/commit was created or application replaced.

## Starting point and inventory

| Area | Observed state | Migration consequence |
|---|---|---|
| `project/` | Custom PHP/PDO retail, finance, HRMS, recruitment, supervisor and payroll application | Preserve its source and full workflow coverage. |
| Root `app/`, `config/`, `public/` | Another custom PHP MVC implementation with its own router/services | Existing work occupies the destination; preserve before replacing colliding paths. |
| `composer.json` | No `laravel/framework`; custom `App\` autoloading | `/public/` is not Laravel. |
| Framework installation | No root `artisan`, `bootstrap/app.php`, `routes/`, `resources/` or installed `vendor/` established | Artisan/Blade/framework test commands cannot run yet. |
| Git | `git status --short`: not a repository | Apply safe ignores before staging a baseline. |

Pre-audit inventory: **575 files**, **416 in `project/`**, **135 legacy
entry-point candidates** (58 POS/public, 62 HRMS, 15 payroll), **200 static
action candidates**, and **27 custom MVC routes**. Utilities/helpers are
included as candidates; these are not counts of verified working features.

- [Complete directory/file inventory](audit/FILE_TREE.md), including empty
  directories; 116 private document/upload/backup/log filenames redacted.
- [Legacy entry points](audit/LEGACY_ENTRYPOINTS.md) and [action candidates](audit/LEGACY_ACTIONS.md).
- [Current custom MVC routes and declared middleware](audit/CURRENT_ROUTES.md).
- [337 source SHA-256 fingerprints](audit/SOURCE_SHA256.tsv).
- [Proposed mapping](MIGRATION_MAPPING.md) and [unchecked parity ledger](PARITY.md).

## Runtime and proposed framework

| Check | Result |
|---|---|
| `php -v` | PATH CLI PHP 8.3.35 at `C:/xampp/php83/php.exe`. |
| `composer --version` | Composer 2.10.3 using PHP 8.4.26. |
| Explicit old XAMPP PHP executable | `C:/xampp/php/php.exe` remains PHP 8.2.12. |
| Apache configuration, read as text | PHP module loads from `C:/xampp/php84/`; nothing changed. |
| Local HTTP response headers | Apache 2.4.58 / PHP 8.4.26. |
| MariaDB client | 10.4.32; live server version recorded in database metadata. |
| Python | Unavailable; PATH resolves to the Microsoft Store alias. Not needed. |
| Node | Not found by PATH lookup; frontend build tooling still needs verification/setup. |

Propose **Laravel 13 with the installed PHP 8.4 runtime**. Laravel 13 is the
current major and requires PHP 8.3+. The brief's description of Laravel 12 as
the latest LTS is outdated: current Laravel support policy applies to all
releases. Pin actual package versions at installation. No machine-wide PHP
replacement is needed on current evidence, and writes outside this workspace
remain outside the attached constraint.

Official sources checked 2026-10-26:
[Laravel release/support policy](https://laravel.com/docs/13.x/releases),
[PHP support policy](https://www.php.net/supported-versions.php).
Context7 `/laravel/docs` was also queried for runtime and web-root guidance.
Only public documentation queries were sent externally.

## Verified database targets

The private legacy environment names **`grocery_pos` and `hrms`**. The brief's
`manage_grocery` database does not exist in the audited local server metadata.
Preserve both domains, their identifiers and their relationship boundaries.

| Database | Tables | Aggregate rows | Columns | Index entries | Triggers |
|---|---:|---:|---:|---:|---:|
| `grocery_pos` | 42 | 2,810 | 471 | 123 | 2 |
| `hrms` | 35 | 5,822 | 391 | 104 | 0 |

Index entries are `information_schema.STATISTICS` rows, including composite
index columns, not distinct indexes. Separate read-only transactions captured
each database; this is not a coordinated cross-database backup. Counts can
change while the app is used.

Both the configured application identity and configured backup identity
returned access-denied code **1044** on both databases. The existing MVC
connection class contains a privileged local fallback. That existing fallback
was used only in standalone direct metadata/count and schema-dump processes;
no application config was included and no grants/accounts were changed.
Laravel must use a correctly provisioned application identity and fail closed.

- [All per-table counts](audit/ROW_COUNTS.md).
- [Column/default/index/FK/engine/trigger metadata](audit/DATABASE_METADATA.json).
- [POS schema-only dump](audit/schema-grocery_pos.sql): 44,356 bytes.
- [HRMS schema-only dump](audit/schema-hrms.sql): 35,644 bytes.

Both `mysqldump` processes exited 0, using `--no-defaults --no-data
--skip-comments --skip-dump-date --skip-lock-tables --skip-add-locks
--skip-add-drop-table --skip-routines --skip-events --no-tablespaces` plus
selected host/port/identity and the verified database. Credentials were held
in process memory/environment, not printed or saved in evidence. Saved audit
copies normalize trigger DEFINER identities to `CURRENT_USER` and omit
sensitive non-null string defaults if present. **These are not data backups;
do not execute them against live databases.**

Both existing `grocery_pos_schema.sql` files have identical SHA-256, 40
`CREATE TABLE` statements, and no `INSERT INTO` statements. They do not cover
all 42 live POS tables or provide a current HRMS data backup. Root and legacy
directories also duplicate SQL migrations. The custom `bin/migrate.php`
runner is not Laravel Artisan.

Two POS triggers prevent UPDATE/DELETE of `stock_logs`. Preserve this
database-level immutability when adding application audit controls.

## Business contracts traced

**Authentication:** Legacy login resolves identities across POS and HRMS,
includes 2FA, failed-login tracking, employee/account status, forced logout and
role-specific routing. `requireLogin()` rechecks accounts and expiry. Actual
roles include POS `admin`, `cashier`, `inventory_staff`, `finance`, and HRMS
`admin`, `hr`, `hr_manager`, `supervisor`, `employee`, `accountant`. Preserve
these roles and separate POS/HRMS primary-key contexts. The newer
`AuthController` reads only POS users and does not reproduce the full sequence.

**Sale/refund/stock:** Legacy `pos.php` refreshes server prices, validates
payment and availability, locks products with `FOR UPDATE`, rejects expired
stock, writes sale/details/logs and consumes batches by FEFO in a transaction.
It records consumed batches for void/full/partial-refund restoration. Approval
and discount helpers are part of parity. The newer `PosSaleService` trusts
submitted prices and lacks equivalent quantity/payment/availability/approval
and stock-sufficiency checks. Its tax arithmetic differs from legacy; preserve
financial behavior before separately validating any policy change.

| Newer SQL reference | Actual live schema |
|---|---|
| `sales.sale_number`, `tax_amount`, `discount_type`, `payment_method`, `created_at` | Absent; legacy stores payment/change/status and `transaction_date`. |
| `products.stock_quantity` | `stock`. |
| `product_batches.remaining_quantity`, `expiration_date` | `quantity_remaining`, `expiry_date`. |
| `sales_details.unit_price` | Absent; historical lines store quantity/subtotal. |
| `sale_batch_consumption.sale_detail_id`, `consumed_quantity` | `sales_detail_id`, `quantity`. |
| Newer `stock_logs` field names | Legacy uses `quantity_change`, previous/new stock, `sale_id`, `date_created` and related fields. |

These are static comparisons; no sale or refund was submitted. Current newer
checkout cannot be claimed functional against the live schema.

**HRMS and privacy:** Scope includes recruitment/requirements, private employee
and applicant files, letters/signatures, attendance/geofencing, schedules,
leave/overtime/official business, supervisor teams/performance, and payroll
preparation/approval/release. Payroll source distinguishes preparation from
finance/admin approval and release. A calculated payroll list is not parity.
One Laravel connection transaction does not establish atomicity across two
databases. Actual privacy/applicant tables are `data_subject_requests` and
`job_applicants`; the newer controller queries `privacy_requests` and
`applicant_applications`. Empty results/zero deletions are not parity. Its
purge endpoint was not invoked. The source's claim that 365 days is a statutory
period was not legally verified and must not determine automatic deletion.

## Security and privacy findings

| Priority | Evidence | Required treatment |
|---|---|---|
| Critical | HR/payroll/finance/reports/storage routes declare `auth` without role gates. Router dispatch recognizes auth/CSRF only; `RoleMiddleware` is unwired. | Route and record-level policies, with positive/negative role tests on an isolated target. |
| Critical | Checkout, attendance and applicant-purge POST routes have auth only, without equivalent CSRF checks in their controllers. | Laravel CSRF on browser mutations; test denial before writes. |
| High | Root `.htaccess` only redirects the empty path. `/composer.json` and `/docs/README.md` both return 200. No directory deny files under app/config/bin/storage. | `/public/` in a URL is not web-root isolation. Block nonpublic sibling access before root secrets/snapshots exist. |
| High | `bin/migrate.php` calls DDL without a CLI-only guard. Direct HTTP execution was not attempted. | Block at web boundary and retire from active Laravel operations while preserving source/history. |
| High | MVC silently falls back to a privileged identity after configured access failures. | Correct provisioning, separate backup/migration identities, fail closed. |
| High | File proxy checks login/path without owner/team/HR authorization; a document file exists in public image assets. Contents were not opened. | Private storage, file classification, per-document authorization; exclude personnel files from Git/assets. |
| High | Newer login omits legacy 2FA/status workflow; newer checkout mismatches schema and trusts client pricing. | Critical auth/POS regression tests before live writes. |
| Medium | Request input only reads GET/POST while checkout claims JSON support. | Explicit API contracts, FormRequests and HTTP tests. |
| Medium | Newer logout mutates state via GET. Comprehensive security headers/audit/retention are not established. | POST logout, verified middleware, bounded audit and configured retention policy. |
| Medium | Historical docs claim exclusive web-root isolation, universal CSRF and broad readiness beyond this evidence. | Add dated qualifications; use this audit/parity ledger for current status. |

A limited static check found no nonempty direct string assignments to selected
password/secret variable names. This is not comprehensive secret scanning.
Private `project/.env` has configured secret-bearing keys; values were not
printed. No personnel/customer records, password hashes, private document
contents or external integration calls were used in evidence.

## UI, assets and dead-file triage

Retain legacy branding, layout and information architecture as the baseline;
root public assets already copy some GroceryOne CSS/JS/scanners. Build Blade
components and Vite assets after the migration safety phase. The newer landing
page sends Careers to the authenticated employee list and Browse products to
the kiosk, whereas legacy has distinct public catalog/application flows.

Utilities, duplicate assets/migrations and helpers need individual
reachability/side-effect review. No file was established safe to delete and
none was deleted. `.kombai` and existing editor work were preserved.

## Executed checks and their limits

| Check | Actual result | Limit |
|---|---|---|
| Standalone PDO: `SET TRANSACTION READ ONLY`, begin, metadata SELECTs, `SELECT COUNT(*)` per verified table, rollback | 77 tables / 8,632 aggregate rows; schema metadata captured | No records compared/exported; separate DB snapshots; no import/restore. |
| Schema-only mysqldump, flags above | Two exit-0 dumps | No operational backup or restore test. |
| `C:/xampp/php84/php.exe -l <file>` for all PHP under app/bin/config/public/tests/project | **273/273 passed** | Syntax only; no config execution or behavior/query validation. |
| `Invoke-WebRequest -UseBasicParsing -TimeoutSec 20` for `/public/`, `/public/login` | Both HTTP 200, PHP 8.4.26 | No authentication submission, visual/browser QA or operational workflow test. |
| Same request for `/composer.json`, `/docs/README.md` | Both HTTP 200 | Confirms parent-path serving; no secret/personnel file requested. |
| Source/tree/action/route/hash review | Linked artifacts | Static candidates need per-action tests. |
| Existing `tests/runner.php` inspection | Inspected, not executed | Its upload check is test-local simulation; date arithmetic does not establish retention compliance. Auth/sale/refund integration coverage not established. |
| Final documentation/artifact checks | 337 fingerprinted sources unchanged; all 135 entries mapped; 190 parity boxes open; 123 local links across 29 Markdown files resolved; schema hashes and 42/35 table plus 2/0 trigger counts match | Fingerprints cover selected PHP/CSS/JS/SQL sources. Limited secret-pattern/private-filename checks found no hits; no comprehensive DLP/secret scan or additional behavior test. |

Legacy landing was not requested: its public bootstrap rate-limits/logs writes
and logs out staff sessions. Authenticated legacy config can perform schema
work. Capture its visual baseline later with those side effects understood.
No legacy browser screenshot was taken in Phase 0.

## Proposed next phase

1. Await the brief's explicit go before Phase 1.
2. Set ignores and HTTP protections before Git staging and backup/snapshot
   creation. Otherwise private files could enter Git or be served from siblings.
3. Fix/provision backup access; create protected full backups of **both** live
   databases and `project/`, plus preservation of the existing root MVC files.
4. Verify recovery on a specifically identified disposable database pair.
   Never fresh-migrate, seed, import or run destructive tests on the live pair.
5. Stage Laravel 13 within this workspace; reconcile collisions before moving
   into root. Keep legacy runnable; no premature deprecation or cutover.
6. Preserve live schema/IDs initially and use synthetic fixtures on isolated
   test databases. Do not blindly reimport records into already populated live
   databases. Plan additive, tested schema adoption separately.
7. Implement against [mapping](MIGRATION_MAPPING.md), [parity](PARITY.md) and
   the [same-change documentation rule](DOCUMENTATION_MAINTENANCE.md).

The requested full Laravel migration remains outstanding. This audit does not
claim cutover, legal compliance or release readiness.
