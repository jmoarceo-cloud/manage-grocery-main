# GroceryOne project context

Updated 2026-10-26. Stable project map; current changes are in [progress](../docs/PROGRESS.md).

- Latest owner scope: stack migration only, with the original UI and features.
  Follow the correction in AGENTS.md. Home, initial login, public catalog, product details and comparison now
  use source-derived Blade presentations. Other native screens still need
  original-style restoration; prior redesigned screenshots are historical.
  AcademiSync supplies a stack reference only. Pause new feature expansion.

- Native application root: repository root, served by `public/`. Preserved legacy root: `project/`. Canonical legacy workflow/source map: [HCI_SYSTEM](../project/HCI_SYSTEM.md).
- Current root stack: Laravel 13.38.0, Blade, Tailwind/Vite and mostly vanilla JavaScript, served from `/public/`. The old root MVC was preserved privately. Legacy `project/` remains custom PHP/PDO and available while remaining workflows are ported. The owner explicitly authorized continued implementation without repeating the Phase 0 confirmation. See [progress](../docs/PROGRESS.md), [API](../docs/API.md), [mapping](../docs/MIGRATION_MAPPING.md) and [parity](../docs/PARITY.md).
- Operations entry points include `project/pos.php`, shared helpers in `project/includes/`, and HR under `project/HRMS/`. Trace the relevant page/helper/role guard and tests before editing.
- POS and HR use separate connections with documented defaults `grocery_pos` and `hrms`. Those names do not identify a disposable test target. Verify actual target and side effects before execution.
- Some configuration/bootstrap includes contain schema or other runtime work. Read a CLI test's imports and writes before running it; a filename containing test or health does not guarantee read-only behavior.
- Preserve sale/stock/payment reconciliation, approval boundaries, payroll privacy and employee account status propagation. ID obfuscation is not authorization.
- Native verification lives in `tests/` (PHPUnit and Playwright), with exact commands/results in [TESTING](../docs/TESTING.md). Legacy checks remain in `project/tests/`; inspect imports before execution. PHP syntax lint alone does not establish behavior.
- Coursework: [Lab Activity 2](../HCI%20GroceryOne%20Lab%20Act%202.md); backup limits: [backup guide](../project/backup/README.md).
- No thesis study/results were established by this tooling update. If thesis work is requested, trace claims to real artifacts and distinguish current source behavior from proposed changes.
- Update HCI_SYSTEM when documented behavior changes, and record actual work/checks in docs/PROGRESS. Never copy production records into new fixtures.
- Follow [documentation maintenance](../docs/DOCUMENTATION_MAINTENANCE.md) in the same meaningful change. Root CHANGELOG.md records new work; historical docs/CHANGELOG.md notes are not fresh verification.
- Tests use the privately configured, newly created synthetic database pair with name and marker verification. `migrate:fresh` is overridden to require that verified pair. Native runtime credentials cannot perform DDL. Keep `.env.testing`, `.env.migration`, backups and personnel documents private.
- The original PHP app at `/project/index` was restored on 2026-10-26 after the requested local startup repair. Shared MariaDB port 3306 now completes startup; two system tables were repaired after private offline backup and candidate verification. All 14 database-permission rows match the recovered candidate, including 10 reconstructed from existing setup instructions; 13 account identities remain. No business table files were replaced. Public legacy connections have three-second mysqlnd network reads; all seven legacy PDO connections have three-second connect options and support DB_PORT. The timeout probe uses only a synthetic loopback socket. Synthetic database tests remain on 127.0.0.1:3307; runtime was not redirected. Keep backup/original/staging/storage paths outside all formatters.

- Native own-password changes use one verified same-server qualified-schema PDO transaction for compatible linked identities. Do not replace this with two best-effort connection commits or infer identity from equal numeric IDs. Tests, compiled views, sessions and caches remain isolated.

- Native `/catalog/scan` resolves validated active barcodes/store codes through at most two indexed ID queries; no scan analytics or private-kiosk unlock. Latest native checkpoint rechecked HTTP200 after separate shared-server recovery; all8 Chromium checks, including the legacy public baseline, pass.

- Native product discount changes use ProductDiscountService and existing product_discount_requests: request/decision snapshots, active/expiry checks, owner cancellation and atomic product/status/audit. Avoid direct catalog discount writes or claiming legacy handlers share the native locks.

- Native manual product information uses ProductInformationService: four editable description fields, Admin decisions, HMAC row/provenance revisions and atomic review/domain audit. Keep web-derived fields behind label review; do not bypass this via direct status writes or reuse a stale revision after an edit.

- Native numeric {id} routes use the global positive 1-10 digit pattern; body IDs follow existing signed INT limits. Future bigint resources need explicit rules. Keep optional product categories as existing empty text, not NULL, and preserve omitted category on update.

- Native /account/profile edits only the actual linked employee personal/contact fields, with current password and keyed revision. Never infer employee ID from user ID or add salary/bank/government-ID fields to its projection. Contact email does not rebind MFA. Preserve unknown saved phone/enum values unchanged; no silent truncation. Native email named locks do not protect legacy writers.

- Native /account/schedule reads only actual own employee linkage, seven recurring weekdays and seven upcoming Manila dates. Weekly shifts win over approved covering swaps; do not treat this display as attendance/leave/holiday/payroll or clock-in eligibility. Keep colleague identities, swap reasons and contact/financial fields out of its projections.

- Native /account/leave provides own balances/history and pending submission. GET defaults do not create credit rows; vacation uses existing start-year cap and calendar cooldowns, sick/emergency are not capped. Pending is not reserved/deducted. Keep reasons out of audit values and flashed input; never put POS IDs into HR leave_request_logs.performed_by. Qualified transaction/employee locks cover native identical pending retries only. Decisions/credit deduction remain legacy pending their separate scoped port.

- Native /leave-approvals is HR-domain only. Preserve role/team SQL scope and locked rechecks, highest requester role for ambiguous accounts, non-Admin self denial, reviewer-bound request revisions and actual HR actor IDs. Status/credit/HR log/POS audit share one transaction; do not split commits or infer an HR actor from a POS numeric ID. Native races verify no repeat deduction/overdraw; legacy writers remain separate.

- Native /account/overtime submits only own linked pending requests with normalized two-decimal hours and atomic domain audit. OvertimeEvidence displays current-recurring/fallback attendance evidence separately from requested/payable hours; preserve early-arrival, overnight and expected-duration rounding. Never select GPS/financial columns or run attendance/payroll to render history. Supervisor decisions/on-behalf entry remain legacy; native pending retry locks do not cover legacy writers.

- Native /overtime-approvals is actual HR Admin/supervisor assigned-team scope, intentionally different from leave hierarchy. Preserve reviewer/request/raw punch/current-shift/schedule revisions and locked team/evidence rechecks. Approval changes state/actual HR actor/time plus minimal audit only; requested>recorded or missing work stays informational, and payroll independently caps actual/approved excess. On-behalf/team preview remains pending; no POS-to-HR numeric-ID fallback.
