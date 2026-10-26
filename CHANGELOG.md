# Changelog

Keep a Changelog style. Historical notes remain in
[docs/CHANGELOG.md](docs/CHANGELOG.md); current evidence qualifies their claims.

## [Unreleased]

### Documentation - 2026-10-26, public GitHub source preparation

- Added a public project overview with the owner's supplied author/contact
  email, two application paths, feature coverage, setup and verification limits.
- Preserved the previous README as a dated archive and documented a filtered
  public snapshot workflow that leaves the original Git history and work intact.
- Strengthened ignore rules for private environment variants, local migration
  markers, credentials and archives. Publication excludes private uploads,
  real-data screenshots and database backups; it does not deploy the app.

### Changed - 2026-10-26, preserve original nutrition comparison

- Restored the original comparison navigation, picker, table, theme and CSS,
  including its existing per-row minimum-value color and single-product table.
- Kept native verified/active/basis/serving validation, current selections,
  bounded numeric IDs, escaping and keyboard-scrollable results.
- Preserved the source's Arial/Georgia fallbacks; this original page does not
  load web fonts. Initial/table visual comparisons and null/zero tests pass.

### Changed - 2026-10-26, preserve original product details

- Replaced the redesigned product detail with source-derived Blade, the original
  photo/price/stock layout, information/nutrition cards and related products.
- Preserved original zero-value display and omission of unknown nutrient rows,
  while keeping verified-only fields, escaping, safe source URLs and image guards.
- Restored known dashboard return contexts using allowlisted native routes and
  correct search parameters. Empty-category related products match the source.
- Verified four original-presentation full-page comparisons and regression tests.
  This preserves UI; remaining comparison/staff screens and feature gaps stay open.

### Changed - 2026-10-26, restore original UI during the Laravel migration

- Applied the owner's correction: preserve the original design and features;
  change the stack only. Added the rule to AGENTS and the project handoff.
- Replaced redesigned landing/login/catalog views with source-derived Blade,
  original CSS, fonts, theme/menu/password controls and catalog interactions.
  Fonts are local, with their original SIL Open Font License notices.
- Restored catalog category counts, complete results, stock-first order,
  `search` and verified-allergen filtering. Retained `q` compatibility and
  guarded native availability. Private access stays in the preserved PHP app.
- Fixed original basename product-image references through the existing
  active-product, directory and MIME-checked route. No filename is published.
- Added source-hash, synthetic workflow and original-presentation browser
  evidence. Full migration/UI parity is still pending for other screens.

### Fixed - 2026-10-26, original PHP loading stall

- Recovered shared XAMPP MariaDB startup using verified private offline copies
  and a separately checked candidate: repaired `mysql.db`/`mysql.time_zone_name`,
  restored all 14 database-permission rows from intact data and existing setup,
  and preserved account files and business table files.
- Added legacy DB_PORT support and PDO connect timeouts. Public mysqlnd reads
  also have a three-second limit, verified against three synthetic stalled
  handshakes. Confirmed original homepage/catalog HTTP 200 and Chromium rendering.

### Checked - 2026-10-26, original PHP startup target

- Corrected the requested startup target to `/project/index`. Recorded its
  35-second HTTP timeout, the shared database listener's missing initial
  handshake and the policy-blocked browser launch in docs/PROGRESS.md.

### Added - 2026-10-26, scoped overtime review

- Added actual HR-domain Admin/supervisor overtime queues and decisions for assigned teams, keeping requested hours separate from recorded work and excluding GPS/financial data.
- Bind decisions to the request and raw punch/current-schedule evidence; recheck team/actor eligibility under locks and commit terminal status plus minimal domain audit together. No attendance or payroll write.
- Verified201 PHP tests/8,526 assertions and twelve native Chromium scenarios, with one explicit unavailable-legacy skip. On-behalf entry, full supervisor dashboard and payroll remain separate ports.

### Added - 2026-10-26, own overtime requests and recorded work

- Added private own overtime submission/history, true nonfuture dates and exact two-decimal0.01..12-hour validation. Identical pending retries normalize hours and return one request/audit across native identity domains.
- Display requested hours separately from recorded work/excess, preserving existing shift, early-arrival, overnight and rounding rules. Missing/invalid punches remain unknown; GPS and financial fields are excluded.
- Verified187 PHP tests/8,351 assertions and eleven native Chromium scenarios, with one explicit legacy-baseline skip after shared3306 had no listener and the legacy homepage returned503. Supervisor decisions, on-behalf requests and payroll execution remain separate ports.

### Added - 2026-10-26, scoped native leave approvals

- Added HR-domain Admin/HR Manager/HR Staff/supervisor queues and decisions, preserving role/team boundaries and using actual HR actor IDs. Ambiguous linked requester accounts use their highest role; non-Admin self decisions are denied.
- Bind decisions to the reviewed request, recheck current scope and vacation balance, and commit status, credit use, HR log and minimal domain audit together. Repeated or competing approvals cannot deduct twice or overdraw native vacation credit.
- Verified 171 PHP tests/8,038 assertions and eleven Chromium scenarios, including independent-process approval races and separate HR/employee sessions. Broader HR dashboards and complete leave/payroll parity remain pending.

### Added - 2026-10-26, own leave requests and history

- Added native own leave balances, private paginated history and pending request submission. Apply the existing vacation balance and calendar cooldown rules consistently across POS and HR self-service.
- Keep GET read-only, confirm eligibility under transaction locks, commit request/lazy vacation credit/minimal audit atomically, and return identical pending retries without a second request. Private reasons are excluded from audit values and error-session input.
- Verified 156 PHP tests/7,843 assertions and ten Chromium scenarios, including a real cross-domain two-process retry and desktop/mobile request history. Leave decisions remain in the preserved HR/supervisor workflows.

### Added - 2026-10-26, own shift schedule

- Added native My schedule with recurring assignments and an Asia/Manila seven-day preview. Approved own covering shifts apply when no recurring shift exists; overnight, inactive and missing-time states are explicit.
- Read only the authenticated account's employee link and necessary schedule fields. No attendance, payroll, roster editing, personnel contacts or swap reasons are loaded.
- Verified 140 PHP tests/7,617 assertions and ten Chromium scenarios, including mobile schedule rendering, distinct identity IDs, cover precedence and private-field exclusion.

### Added - 2026-10-26, own employee personal information

- Ported staff personal/contact updates to `/account/profile`, using the authenticated account's employee link, current-password proof and an explicit field allowlist. Contact email changes leave the enrolled sign-in address unchanged.
- Added stale-form protection, atomic employee/audit updates, duplicate-email serialization and privacy-safe validation redirects. Existing unknown phone/option values can be retained without silent truncation.
- Verified 131 PHP tests/7,485 assertions and ten Chromium checks, including distinct identity IDs, two-process email contention, rollback, mobile layout and saved contact details. Other HR profile workflows remain pending.

### Fixed - 2026-10-26, request boundaries and uncategorized products

- Reproduced and fixed server errors from oversized receipt IDs and the native Uncategorized option. Numeric route IDs now share a positive 1-10 digit constraint; operational body IDs match existing signed integer storage.
- Reject malformed IDs before operational lookups and map empty product categories to the existing non-null text representation without creating a category row.
- Verified 116 PHP tests/7,341 assertions and nine browser checks, including uncategorized create/reopen and seven malformed ID forms across all 14 ID-bearing route templates.

### Added - 2026-10-26, product information review

- Added native description/brand/unit/storage editing, submission/resubmission and Admin verification/rejection with atomic review history.
- Hide updated verified facts until review; protect decisions against stale edits, changed nutrition/provenance and unsaved form changes. Web-sourced fields retain the separate label-review gate.
- Suppress unsafe or credential-bearing source references in staff display and new audit snapshots. Verified 110 PHP tests/7,131 assertions and nine Chromium checks, including the Inventory-to-Admin-to-public flow.

### Added - 2026-10-26, product discount approvals

- Added Inventory Staff/Admin product discount requests and Finance/Admin decisions, with server-derived price/discount snapshots and atomic application/audit.
- Recheck active status, expiry and unchanged price/discount before approval; enforce owner-or-Admin cancellation and prevent direct discount edits through catalog CRUD.
- Verified 94 PHP tests/7,015 assertions and eight browser checks, including separate Inventory/Finance sessions, mobile action access and the resulting public price. Existing schemas and real records were preserved.

### Added - 2026-10-26, public product-code lookup

- Added native manual/handheld-input product lookup with active-only indexed matching, exact-match precedence and leading-zero GTIN-14 fallback.
- Added responsive lookup, unknown/invalid states and catalog navigation; responses cannot redirect to supplied URLs and create no scan/identity records.
- Verified 84 PHP tests/6,941 assertions and all eight Chromium checks, including the restored legacy public baseline. Rechecked both live homepages and the native lookup: HTTP200. Shared-server recovery belongs to the separate local startup work.

### Added - 2026-10-26, account security

- Added own-password changes with current-password/recovery proof, stronger confirmed-password validation, throttling, masked account details and forced native sign-out.
- Made compatible linked POS/HR credential changes atomic on one verified same-server InnoDB transaction; ambiguous links and unsupported topology fail closed.
- Verified 78 PHP tests/6,823 assertions and six Chromium checks, including old-password rejection and fresh MFA; one shared-DB legacy check remains skipped.
- Separated compiled test views from live views and corrected adjacent login Blade directives. Updated behavior/security/privacy/API/schema/UI/progress and bounded parity evidence.

### Added - 2026-10-26, verified public product information

- Ported verified product descriptions, nutrition/ingredient/allergen/storage data, related products and native comparison with category/basis/serving checks.
- Excluded unverified/internal review data, suppressed unsafe source links and preserved missing versus zero; retained selected products outside the first100 choices.
- Improved mobile table access and image loading/default placeholders. Verified66 PHP tests/6,734 assertions and six passing browser checks; legacy baseline remains explicitly skipped while the shared DB is down.

### Added - 2026-10-26, native product labels

- Ported guarded active-product selection and A4 shelf QR/EAN-13 printing, including bulk selection, checksum/error states and current discounted prices.
- Aligned catalog/detail display with checkout product pricing; added local SVG QR dependencies with an explicit XMLWriter requirement.
- Verified actual EAN/QR pixel decoding, generated a synthetic A4 PDF, and opened the decoded native product URL. Current suite: 58 PHP tests/6,672 assertions and five passing browser checks, with the shared-DB legacy baseline explicitly skipped.

### Added - 2026-10-26, native manual discount approvals

- Ported cashier basket-discount requests, owner cancellation and Finance/Admin decisions using the existing table and actual POS identities.
- Added a role-scoped approval history and register selector with quote preview, status refresh and one-time checkout use; shared server-side price calculation prevents request/checkout drift.
- Discarded irrelevant statutory identity fields for ordinary/manual-approved sales and added approval/ownership/tamper/privacy regression coverage.
- Verified 54 PHP tests/6,641 assertions and a separate Cashier-to-Finance browser approval/checkout/retry flow; updated routes, diagrams, UI, security/privacy and bounded parity evidence.


### Checked - 2026-10-26, local startup request

- Confirmed the existing Apache runtime serves native sign-in and health
  endpoints with HTTP 200; the homepage returns HTTP 503 while shared MariaDB
  port 3306 is offline. Recorded the policy-blocked startup/browser-launch
  attempt and the remaining database recovery requirement in docs/PROGRESS.md.

### Added - 2026-10-26, Laravel implementation

- Installed Laravel 13.38.0 at the requested root, preserving both prior
  implementations and private backups; built a responsive Blade/Tailwind UI.
- Adopted both original schemas in place with 77 unchanged table counts and
  three additive POS infrastructure tables, without importing business records.
- Implemented dual-domain OTP/recovery authentication, server role/CSRF/status
  checks, catalog management, inventory/Admin restock, transactional cashier
  checkout/receipts/refunds, durable retries and minimal audit metadata.
- Added ownership-verified synthetic schema setup/reset/seed and meaningful
  database/HTTP regression tests; updated API, setup, architecture and security
  documentation to describe actual implemented scope and pending parity.

### Added - 2026-10-26, migration safety phase

- Protected nonpublic HTTP paths and private runtime/backup files before
  creating full dual-database and verified source snapshots.
- Created a dedicated CRUD-only Laravel identity and separate marked,
  empty synthetic-test databases; no privileged web fallback or live schema change.
- Added a CLI-only staged-documentation gate and pre-commit hook, and began
  Laravel installation in an isolated, ignored staging directory.

### Added - 2026-10-26

- Phase 0 migration audit: redacted tree, legacy entries/actions, custom MVC
  routes, schema-only dumps, metadata and aggregate counts to establish the
  starting point without changing business records.
- Proposed migration mapping and unchecked parity ledger covering all retail,
  finance, HRMS, supervisor and payroll areas.
- AcademiSync-style same-change documentation rule linked from contributor
  guidance to keep future implementation and evidence synchronized.
- Corrected current architecture, database, security and privacy documents
  against the audit; retained the earlier drafts as explicitly historical
  references so unsupported controls/schema/compliance claims do not guide the port.

### Clarified - 2026-10-26

- `/public/` is custom PHP MVC, not Laravel. Preserve `grocery_pos` and `hrms`;
  the brief's `manage_grocery` database does not exist in audited metadata.
- Proposed Laravel 13 on installed PHP 8.4, after the brief's go checkpoint.
  No framework installation, full backup, migration, Git commit or cutover.
- Recorded 273 passing PHP 8.4 syntax checks, bounded HTTP/schema checks and
  the authorization/CSRF/schema/web-root gaps those checks do not resolve.

### Changed - 2026-10-26, native transaction and evidence checkpoint

- Added owner-only same-day void restoration, inventory filtering/expiry watch and named Admin restock controls.
- Hardened MFA lock expiry, once-only recovery and forced-session revocation; tested distinct HR/POS identity mapping.
- Added real concurrent-checkout tests, private retryable database errors and project-contained synthetic database provisioning/shutdown helpers.
- Reduced the frontend JavaScript bundle, synchronized route/flow/UI/testing/parity documents and excluded preserved backups from formatting.
- Recorded the unresolved shared MariaDB privilege-table failure; continued testing on a newly initialized private synthetic instance without importing real records.

- Final checkpoint also separates test cache/session/locks, preserves recovery access during mail failure/throttling, adds enrolled-address resend and blocks inherited mixed-storage static paths on both Apache and the synthetic server. Current evidence: 47 PHP tests / 6,609 assertions; 4 browser checks passed, 1 legacy baseline explicitly skipped.
