# HCI GroceryOne

**Owner correction, 2026-10-26:** this is a technology-stack migration only.
Preserve the original `project/` UI, wording, navigation and feature behavior.
Do not continue the earlier redesign or add features. AcademiSync is a stack
reference only; its application data, academic modules and design are excluded.
Laravel 13 / PHP 8.3+ / Blade / Vite 7 / Tailwind 4 already match the relevant
core stack. Original-style restoration is in progress, not complete cutover.

The original home, initial login, public catalog, product-detail and comparison layouts now
run in Blade. Other native screens and remaining features are still being ported;
this does not establish complete original UI/workflow parity.

GroceryOne now runs **Laravel 13.38.0** at
`http://localhost/manage-grocery-main/public/`. The preserved custom PHP app
is preserved at `http://localhost/manage-grocery-main/project/index` while the
remaining workflows are ported. This is an active migration, not final cutover.

Latest availability check (2026-10-26, Asia/Manila): the legacy homepage returned
503; the Laravel landing produced no status within the10-second client limit.
The empty code-lookup form returned200. Shared3306 has no listener; earlier
recovery HTTP200 checks below are historical. Native verification uses the separate marked3307
instance. This phase did not restart or modify the shared server.

The original PHP app is available at
`http://localhost/manage-grocery-main/project/index`. Its local startup was
recovered on 2026-10-26 after repairing two corrupt MariaDB system tables and
restoring the existing database permissions from the recorded setup. Homepage
and catalog returned HTTP 200 in under half a second; Chromium rendered the
homepage. Private recovery copies remain outside HTTP. Business table files
were not replaced. Synthetic tests still use the separate port-3307 instance.
See [the recovery checks and limits](docs/PROGRESS.md).

Native Laravel work includes public landing/catalog/product pages, staff
password plus email/recovery-code authentication, role checks,
product/category/supplier management, inventory and administrator restocking,
cashier POS, receipts, partial/full refunds, same-day owner voids and scoped sales lists.
Cashier manual discount requests and Finance/Admin decisions now run natively,
with an owned approval selector and one-time use at checkout.
Inventory Staff/Admin can select active products and print native EAN-13 or
shelf QR labels; catalog, label and checkout product pricing is consistent.
Public product pages and nutrition comparison publish verified label information
with matching measurement/serving checks and distinct missing-versus-zero values.
Verified staff can change their password through Account security, using current
password or a one-use recovery code. Valid linked POS/HR identities update
atomically on the same server and require a fresh MFA sign-in afterward.

Public product-code lookup at `/catalog/scan` accepts manual or handheld-scanner
input, resolves active products and handles leading-zero GTIN codes locally.

Product discount changes now have native Inventory Staff/Admin requests and
Finance/Admin decisions. Approval rechecks the saved price/discount and expiry,
then applies the product change atomically with its decision and audit.

Native product information review now covers description/brand/unit/storage
edits, submission and Admin decisions, with stale-page protection and audited
publication. Editing verified facts hides them until they are reviewed again.
Nutrition/photo/AI editing and web-source confirmation remain separate workflows.

Procurement, finance, advanced reports, scanner/nutrition workflows and most
HRMS screens still use the preserved application. Navigation retains those
entry points; shared authentication between the two apps is not claimed.

Both existing databases are adopted in place. All 77 original table counts
matched the protected pre-migration backup at adoption (8,631 aggregate rows);
no business table was reimported/recreated. Three POS infrastructure tables
were added. Test fixtures are synthetic, in a separate marked database pair.

## Local commands

```powershell
php artisan about
php artisan route:list --except-vendor
php artisan view:cache
C:\xampp\php84\php.exe vendor\bin\phpunit --no-progress
bin\npm.cmd run build
php bin\check-docs.php --staged
```

PATH PHP 8.3.35 and Apache PHP 8.4.26 work with the locked PHP-8.3-compatible
dependencies. A verified portable Node 24 LTS runtime is stored locally under
ignored `storage/tooling/node/`; `bin/npm.cmd` uses it. Another machine can use
installed Node 24 LTS with `npm.cmd`. The old XAMPP PHP 8.2 binary is unsuitable.

Private environment files are excluded from HTTP/Git. The web identity has
CRUD permissions only; migrations have an explicit separate environment.
Tests verify names and creation markers before writes. Do not fresh-migrate,
seed or import into live schemas. See [installation](docs/INSTALL.md).

## Evidence and contributors

Current exact results and commands are maintained in [PROGRESS](docs/PROGRESS.md)
and [TESTING](docs/TESTING.md). Blade compilation and Vite production build
passed. Public landing/login/catalog returned 200 before the shared-server
outage; current availability limits are recorded above. This does not establish complete feature/browser/role parity,
real-mail delivery, a full recovery drill or live financial smoke testing.

Read [AGENTS](AGENTS.md), [context](.ai/project-context.md),
[progress](docs/PROGRESS.md), [parity](docs/PARITY.md),
[migration notes](docs/MIGRATION_NOTES.md), [API](docs/API.md),
[security](docs/SECURITY.md) and [privacy](docs/PRIVACY.md).
[Documentation tracks meaningful changes](docs/DOCUMENTATION_MAINTENANCE.md),
with a configured local pre-commit gate. The historical [audit](docs/AUDIT.md),
[legacy system map](project/HCI_SYSTEM.md), [backup guide](project/backup/README.md)
and [coursework proposal](HCI%20GroceryOne%20Lab%20Act%202.md) remain available.

Native staff can now review/update their own linked employee contact and personal information under Personal information. Current-password proof and repeated contact email are required; the sign-in verification address remains managed separately. This bounded form does not complete HR attendance, leave, payslip or avatar migration. See [parity](docs/PARITY.md) for131 PHP tests/7,485 assertions and ten browser checks.

My schedule now shows native staff their own recurring week and approved day-off covers for the next seven Asia/Manila dates. It remains separate from attendance, leave, payroll and HR roster management. Current bounded checks:140 PHP tests/7,617 assertions and ten Chromium scenarios.

My leave now supports own balances/history and pending requests, with consistent existing vacation checks and atomic pending retry handling. Native HR/supervisor decisions now use Leave approvals with role/team scope and atomic credit/log/audit updates. Latest bounded verification:171 PHP tests/8,038 assertions and eleven Chromium scenarios; broader leave/HR migration remains incomplete.

Native Leave approvals is available to actual HR-domain Admin, HR Manager, HR Staff
and assigned supervisors. POS Admin is not treated as an HR audit identity.
Current scope, reviewed-request revisions and locked vacation balance checks
protect decisions; broader HR dashboards, attendance and payroll remain separate.

My overtime now supports private own pending requests/history, with requested
hours separate from recorded work/excess. It does not create attendance, approve
overtime or release pay. Latest evidence:187 PHP tests/8,351 assertions, eleven
native Chromium passes and one explicit legacy-baseline skip after HTTP503.
Supervisor overtime decisions and payroll remain separate ports.

Native Overtime approvals now supports actual HR Admin/supervisor assigned-team decisions, with current raw-work revisions and atomic status/audit. It leaves requested hours, attendance and payroll unchanged. Latest verification:201 PHP tests/8,526 assertions; twelve native Chromium passes and one explicit legacy-outage skip. On-behalf entry, broader supervisor dashboards and payroll remain pending.
