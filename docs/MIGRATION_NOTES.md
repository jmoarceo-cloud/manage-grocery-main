# Laravel migration notes

## 2026-10-26: continuation authorized and safety net created

The owner explicitly authorized continued work without further routine
questions. The earlier Phase 0 stop is satisfied; it must not be asked again.

Root HTTP routing now allows only the deliberate `project/` and `public/`
trees and denies nonpublic siblings and sensitive file extensions. Verified
403 responses for the root manifest, documentation and protected CLI path;
the newer public login still returns 200. No private file content was fetched.

Protected, Git-ignored backups now contain a single-transaction full dump of
both verified databases (2,473,472 bytes), a byte-verified 416-file legacy copy,
and a verified 102-file root MVC source copy. Original hashes/counts are held
in the protected backup manifest. No live records or table schemas changed.
The full backup has not yet undergone a real-data recovery drill; application
test targets remain synthetic-only.

A new Laravel web account has SELECT/INSERT/UPDATE/DELETE only on the two live
domains. Its private credentials are in root `.env`, excluded from Git and
HTTP. No privileged fallback is part of the intended Laravel runtime. Two
new, randomly named empty test databases have their own restricted identity
and target markers; `.env.testing` stays private. They contain no real records.

Existing root MVC directories must be preserved before staged Laravel files
replace collisions. The legacy source copy remains unchanged. Framework
installation is in progress in the ignored `.laravel-staging/` directory.

The documentation gate is `bin/check-docs.php`, invoked by
`.githooks/pre-commit` after Git hook-path setup. It checks staged filenames,
not text accuracy, and never boots PHP configuration or connects to a DB.

## 2026-10-26: installed framework and bounded native implementation

Laravel 13.38.0 replaced preserved root MVC collisions after verified snapshots.
Locked dependencies target PHP 8.3.35; Apache uses PHP 8.4.26. Root /public is
native Laravel, with Blade/Vite assets. The legacy runtime source remains intact.
The current web identity has CRUD only; schema adoption used a private separate
environment/account. The one-use adoption validated original columns and left
all 77 original table counts unchanged versus its coordinated pre-migration
backup, adding only migrations, durable checkout requests and minimal audit.
See audit/DATA_ADOPTION.json for exact per-table counts. No records were imported.

Native auth/catalog/inventory/retail workflows and tests are enumerated in API,
UI and TESTING; PARITY remains the acceptance authority. Advanced workflows
continue in the preserved application. Do not announce cutover or tag a full
Laravel release while these requirements remain open.

Current interruption: shared MariaDB cannot start due to a corrupt privilege
table. No shared-system repair was performed. A fresh project-contained test
instance permits continued synthetic validation without copying real records.
The original full database dump SHA-256 remains intact. A formatting command
reached archived root source PHP; those files were restored from the baseline,
including 120 available original audit-hash matches across both snapshot trees.
No unresolved source reconstruction remained. pint.json now excludes archives,
original runtime, storage and staging. The database dump was never formatted.
Own-account password changes now use one verified same-server qualified-schema
transaction for compatible linked identities, with recovery and audit rollback.
Native sessions are revoked on success; full profile/avatar/reset administration
and complete preserved-legacy session coverage remain open. Current evidence is
78 PHP tests/6,823 assertions and six Chromium checks (one legacy skip). The
shared database listener does not establish readiness; no shared repair occurred.

Native public code lookup now runs under /catalog/scan with active-only/exact/GTIN fallback and no telemetry. Latest evidence:84 PHP tests/6,941 assertions; all8 Chromium checks pass. Public HTTP availability is restored after separate startup recovery; camera/private kiosk/scan analytics and broad workflow migration remain open.

Native product discount proposals/Finance decisions now reuse the original request table with unchanged schema, server snapshots, stale/expiry checks, owner cancellation and atomic application/audit. Current evidence: 94 PHP tests/7,015 assertions, eight browser checks. This does not complete the wider Finance/procurement or image/AI workflows.

Manual product description review now runs natively with Admin decisions, saved-row/provenance revisions, public unpublishing and atomic history. Existing web-source safeguards remain; full nutrition/photo/AI editing is not ported. Current evidence: 110 PHP tests/7,131 assertions, nine browser checks, 47 routes and 313 linted PHP files. No schema or real fact/review mutation was performed.

Request-boundary regressions are fixed: typed IDs cannot overflow from route text, body IDs respect existing signed storage, and Uncategorized saves as non-null empty text. Current native evidence:116 PHP tests/7,341 assertions, nine browser checks,47 routes and314 linted PHP files. Original schemas and separate startup changes remain preserved.

Own employee personal information is now native without schema/data import:49 exported routes,131 passing PHP tests/7,485 assertions, ten browser scenarios and323 linted PHP files. The nine-field employee write and minimal audit share a validated transaction; native email locks do not constrain legacy writers. Other HR profile functions and overall migration remain incomplete.

Own schedule is now read-only native without schema changes:50 exported routes,140 passing PHP tests/7,617 assertions, ten Chromium scenarios and326 linted PHP files. The seven-day preview uses existing weekly precedence and approved covering shifts; attendance/leave/holiday/payroll authorization remains outside this screen.

Own leave submission/history is native without DDL:52 routes,156 passing PHP tests/7,843 assertions, ten Chromium scenarios and334 linted PHP files. Consistent existing vacation checks close the HR-profile cooldown omission; pending nonreservation and start-year charging remain explicit. Decisions and financial/HR reconciliation still require their separate port; no cutover or legal-policy claim.

Native leave decisions now cover scoped HR queues, reviewed request revisions and atomic credit/request/HR-log/domain-audit updates, without DDL. Current evidence:54 routes,171 PHP tests/8,038 assertions, eleven Chromium scenarios and342 linted PHP files. Highest requester role, non-Admin self denial, current cap recheck and inactive-target approval denial strengthen the original flow. Start-year credit policy and Admin full scope remain; complete HR/payroll parity and cutover are pending.

Own overtime request/history is native without DDL:56 routes,187 passing PHP tests/8,351 assertions, eleven native Chromium passes plus one explicit legacy-baseline skip and351 linted PHP files. Requested hours remain distinct from current-schedule attendance excess; no GPS or payroll write. The separately stopped synthetic3307 instance was restarted and its marked pair freshly reset for final checks; live3306 was not redirected. Shared3306 has no listener; current probes record legacy503, a10-second landing client timeout and200 for the empty lookup form; earlier recovery checks are historical. Approval/on-behalf and full HR parity remain pending.

Native overtime decisions now use scoped HR team queues and current raw-work revisions, without changing requested hours, attendance or payroll. Current bounded evidence:58 routes,201 PHP tests/8,526 assertions, twelve native Chromium passes plus one explicit legacy-outage skip, and358 linted PHP files. No DDL or live record mutation. On-behalf/team-preview, broader HR/payroll parity and cutover remain pending.
