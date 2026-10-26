# GroceryOne Laravel parity ledger

Date: 2026-10-26. **Active migration; bounded native verification, full parity pending.**
Root runs Laravel 13.38.0. The owner authorized implementation after Phase 0.
The custom PHP runtime is retained. The later original-PHP startup repair
recovered shared MariaDB and intentionally changed three legacy connection
bootstraps. Original homepage/catalog HTTP 200, Chromium rendering and three
synthetic stalled-handshake probes are recorded in PROGRESS; they do not
establish operational or native parity. Synthetic database tests remain on a
separate project-local instance. Follow
[documentation maintenance](DOCUMENTATION_MAINTENANCE.md).

Status vocabulary: **Planned**, **Code present only**, **Tested but incomplete**,
**Verified**. Check a box only for a bounded verified requirement. Record the
Laravel file/route, allowed and denied actors, exact test/command/date, isolated
target identity (no credentials), result counts, skips and limits beside the
row or in a linked evidence record. Unchecked broad workflow rows remain incomplete even when a bounded part is tested.


## Current bounded evidence

All results below are from 2026-10-26, not a full release acceptance. No real
record fixtures or actual staff account was used for mutation tests.

| Implemented area | Evidence and limit |
|---|---|
| Framework, protected source/backups | Laravel about/routes, compiled Blade/Vite; 416 legacy/102 root files originally verified. Current audit checks259 original legacy hashes: three concurrent connection-file changes are preserved outside this native work; the earlier checkpoint matched all259. Full dump hash remains intact. See audit/NATIVE_VALIDATION.json. |
| Original schemas and additive adoption | SchemaAndCatalogTest verifies all 77 original schema/index/FK/engine/collation contracts on the nonce-marked pair. DATA_ADOPTION.json reconciles every original live table count against the protected pre-adoption backup; this does not prove financial/file-reference parity or a full restore. |
| Auth and distinct domains | AuthenticationAndAccessTest verifies OTP/enrollment, hashed once-only recovery, persistent locks, email-rebind denial, login throttle, CSRF/status/role gates, session revocation and HR-admin-to-POS-actor mapping with different primary keys. Not every employee state/role or legacy synchronization action is covered. |
| Transaction core | PosTransactionsTest covers server prices, atomic effects, stock/expiry rejection, injected log failure rollback, durable replay, partial/full refunds and same-day owner voids. ConcurrentCheckoutTest uses separate PHP processes for last-unit and duplicate-key contention. |
| Inventory/catalog | Tests verify escaped catalog output, category links, denied Inventory Staff restock and Admin batch/log/notification writes. Browser validates mobile inventory, named product selection and filter empty state. Procurement/signature workflows remain open; native label output is bounded below. |
| Manual basket discount | DiscountApprovalTest verifies server quote, Finance/Admin gates, owner cancellation/history, pending transitions, rejection and one-time use. Browser proves separate Cashier/Finance request-to-sale/retry at PHP38.25 after 10% off PHP42.50. Legacy historical consumption and other Finance workflows remain open. |
| Product labels | ProductLabelsTest plus Inventory Staff browser flow verifies active-only bounded selection, denied cashier access, escaping, effective prices and invalid EAN state. Actual EAN/QR canvas pixels decode correctly; an A4 PDF is generated and the QR opens the exact native product. No physical printer/phone or all-symbology claim. |
| Verified public facts | PublicProductFactsTest verifies all unverified states stay hidden, escaped approved text, safe source links, zero/unknown handling, comparable category/basis/servings and bounded selection. Desktop/mobile browser checks reach the second product column and reviewed product. Manual description review is now bounded below; full nutrition/AI/photo/private-catalog/analytics remain incomplete. |
| Browser and availability | Twelve native Chromium scenarios pass; one legacy baseline is explicitly skipped after a real HTTP503 failure. Shared3306 has no listener; probes record legacy503, a10-second landing client timeout and200 for the empty lookup form; earlier recovery200 samples are historical. DatabaseAvailabilityTest checks generic no-store503 output. See audit/UI_SMOKE.json, audit/PUBLIC_HTTP_CHECKS.json and UI.md. |
| Account security | AccountSecurityTest verifies current-password/recovery proof, solo POS/all six native HR roles, distinct linked IDs, matching employee status, ambiguous/canonical-domain denial, policy/throttle, masked details and atomic second-domain rollback. Chromium proves mobile form, forced sign-out, old-password rejection and fresh password+MFA. Broader HR profile/avatar/reset administration and complete legacy session revocation remain open. |
| Public product-code lookup | PublicProductCodeTest verifies active-only matching, exact precedence/leading-zero fallback, zero stock, validation before SQL, no writes/external/personnel query and throttling. Chromium verifies desktop/mobile unknown state and Enter submission to the correct product. Camera, kiosk authorization, encrypted legacy tokens, return contexts and scan analytics remain pending. |
| Product discount proposals | ProductDiscountTest verifies server snapshots, Inventory/Finance/Admin gates, own history/cancellation, stale/expiry/removal/orphan handling, tampering, rollback, mapped HR Admin IDs and public/checkout prices. Chromium uses separate Inventory/Finance sessions and confirms PHP12.50 becomes PHP11.25, including mobile action access. No whole-Finance or legacy-concurrency claim. |
| Manual information review | Sixteen ProductInformationTest cases verify state/role/source guards, stale row/nutrient/provenance and cross-product revision rejection, clean HTML conflicts, public unpublishing, audit rollback, credential-reference suppression and inactive IDs. Separate Inventory/Admin Chromium flow confirms dirty-state protection and publication, with reviewed responsive screenshots. Auto-AI/photo/full nutrition editing remains pending. |
| Request boundaries / Uncategorized | RequestBoundaryTest reproduces oversized receipt TypeError and NULL-category failure, then verifies six cases/210 assertions: all14 ID route templates across seven malformed forms, operational body limits/no lookups, uncategorized create/clear and unchanged stock/category rows. Chromium creates/reopens the empty-category product. This is bounded regression coverage, not an exhaustive security assessment. |
| Own employee personal information | OwnProfileTest plus OwnProfileConcurrencyTest verify explicit employee ownership/distinct IDs, all six HR roles, safe column projection, password/stale/tamper/phone validation, clean HTML errors, atomic audit rollback and real two-process case-variant email contention. Chromium verifies saved own contact/address, unchanged MFA address and desktop/mobile rendering. Contact email is not delivery-verified; native locks do not cover legacy writes. Other profile workflows remain pending. |
| Own shift schedule | Nine OwnScheduleTest cases/132 assertions verify ten native roles, explicit employee ownership, no private field reads/business writes, seven weekday/date boundaries, approved covers and weekly precedence, overnight/inactive/missing-time states and escaping. Chromium checks owned recurring/covering lists and responsive layouts. Company/team roster, scheduling changes and attendance/leave/holiday/pay rules remain pending. |
| Own leave submission/history | OwnLeaveTest and OwnLeaveConcurrencyTest add16 cases/226 assertions: all ten native roles, distinct ownership, safe GET/no credit creation, true dates, vacation cap/calendar cooldowns, uncapped sick/emergency, private HTML errors, escaping/filters/paging, audit rollback and independent POS/HR pending retry. Chromium submits/retries one pending request and reviews mobile history. Native approvals and credit deduction are now bounded below; cancellation, attachments, broader reconciliation and full leave parity remain open. |
| Scoped leave decisions | LeaveApprovalTest plus two real process contention cases add15 tests/188 assertions. Verify actual HR domain/IDs, role/team queues and denied reasons, highest duplicate role, moved-team/self denial, stale reviewer/request revisions, current fractional cap, tracked overflow, start-year usage, rejection and complete audit rollback. Chromium uses separate HR/employee sessions to confirm approval and14 remaining sick days. No legacy-writer, full dashboard, cancellation, attachment, statutory-policy or payroll claim. |
| Own overtime and recorded work | Sixteen OwnOvertimeTest/ConcurrencyTest/OvertimeEvidenceTest cases add313 assertions: all ten roles, exact date/hour bounds, safe own fields, no attendance/payroll writes, atomic rollback, normalized two-process pending retry, missing/invalid/overnight/early/fallback/second-rounding states and requested-versus-recorded distinction. Chromium verifies3.50 requested versus2.00 excess, retry and responsive privacy-safe history. Native scoped decisions are now bounded below; on-behalf and payroll remain pending. |
| Team overtime review | Fourteen OvertimeApprovalTest/ConcurrencyTest cases add168 assertions: actual HR Admin/supervisor assigned-team scope, private reasons, raw second/shift/schedule and reviewer/request revisions, preserved requested/recorded distinction, missing evidence, invalid legacy/ineligible cleanup, terminal decisions and rollback. A real Admin/supervisor race creates one audit. Separate supervisor/owner Chromium sessions verify approval and unchanged3.50 requested/2.00 excess. On-behalf, team preview, payroll and full supervisor parity remain pending. |
| Current overall PHP suite | 201 tests, 8,526 assertions passed on PHP 8.4.26 against the marked project-local synthetic pair. No skipped PHP tests. See TESTING and PROGRESS for commands. |

- [x] All 77 original live table row counts unchanged by the additive adoption, compared immediately against its coordinated pre-migration backup.
- [x] Native manual discount request/decision/cancel/one-time checkout flow verified above; no complete Finance-module claim.
- [x] Synthetic cashier OTP/enrollment/recovery and session/role rejection checks above pass.
- [x] Same-day owner void and approved partial/full refund integration tests restore original batch quantities and reject repeated/excessive restoration.
- [x] Public and staff responsive browser checks above pass within their explicitly bounded targets; the current legacy public baseline is explicitly skipped after HTTP503; earlier passes are historical.
- [ ] Shared port-3306 MariaDB repaired by its owner and both live app URLs rechecked after recovery.
- [ ] Complete native HRMS/procurement/finance/scanner/nutrition/privacy/workflow parity and final cutover.

The broad acceptance rows below and legacy-entry inventory are retained so
remaining scope is visible. Native routes do not imply every old entry is ported.
## Safety, framework and data

- [ ] Protected full backups of `grocery_pos` and `hrms`, with original hashes and successful isolated restore evidence.
- [x] Legacy `project/` copied before moves; existing root MVC work preserved; no unrelated work or `.kombai` loss.
- [x] Ignore/HTTP rules prevent secret, backup, private-document and nonpublic application access before staging or skeleton installation.
- [x] Laravel 13 installed with locked dependencies; Artisan about, named routes, Blade compilation and runtime verified on the selected PHP binary.
- [ ] `/manage-grocery-main/public/` works with correct base URLs/assets and nonpublic sibling protections; legacy URL still runnable.
- [x] Verified disposable POS/HRMS test pair and synthetic fixtures; destructive tests cannot target live databases.
- [x] All 42 POS tables preserve columns/types/defaults/nullability/enums/PKs/foreign keys/indexes/engines/collations.
- [x] All 35 HRMS tables preserve the same schema and identifier contracts.
- [x] Both immutable stock-log triggers preserved and tested on isolated targets.
- [ ] Baseline/import/adoption procedure reconciles every table's counts and relationships against a coordinated backup; current rows remain intact.
- [ ] Data integrity beyond counts verified: links, identifiers, financial totals, batch quantities and private upload references; no record dumps in evidence.
- [ ] Separate POS and HRMS users/employee IDs/guards cannot collide; cross-database identity synchronization remains correct.

The Phase 0 baseline is 42/35 tables and 2,810/5,822 aggregate rows. It is not a
backup or before/after migration comparison. See [row counts](audit/ROW_COUNTS.md)
and [metadata](audit/DATABASE_METADATA.json). Counts can change during use.

## Workflow acceptance

- [ ] Public landing, catalog/search, product details, nutrition comparison and scan flows preserve distinct navigation and empty/error states.
- [ ] In-store kiosk scanning/manual lookup preserves network/device boundary and verified-information rules.
- [ ] Public recruitment application, dynamic fields, resume upload and token requirements portal work without staff access.
- [ ] Unified staff login routes every existing POS/HRMS role correctly; HRMS login compatibility preserved.
- [ ] Password hashing, regeneration, 2FA setup/verification/recovery, throttling and generic failure responses verified.
- [ ] Inactive/suspended/locked/deactivated accounts, expiry, forced logout and employee-status synchronization enforced.
- [ ] Password reset request/admin decision/reset, profile/password/avatar and POST logout flows preserve authorization.
- [ ] Product/category CRUD, search/sort/pagination, active status, barcode labels and shelf-label printing verified.
- [ ] Product details, nutritional evidence/review, photo ingest and AI settings preserve approval/access boundaries; external calls faked in tests.
- [ ] Inventory/batches/expiry/low-stock/recommendations preserve FEFO availability and complete stock history.
- [ ] Supplier CRUD/ratings, restock requests, requester/supervisor/finance signatures and supplier token approvals verified.
- [ ] Purchase orders, request links, manager approvals, letters, partial receiving, receipt items and payables reconcile.
- [ ] POS scan/search/cart, current server prices, discounts/approvals, valid quantities and payment/change rules preserved.
- [ ] Sale header/items/stock/batch consumption/log are atomic; insufficient/expired/inactive stock and malformed/tampered carts rejected before commit.
- [x] Concurrent checkout, duplicate submission/retry and failure rollback preserve stock/payment reconciliation.
- [ ] Receipts use server-generated identity and correct historical totals; printable output and required register keyboard behavior verified.
- [ ] Void, full refund and partial refund restore the correct batches exactly once and preserve approval/audit/financial relationships.
- [ ] Shift, sales, inventory, finance, HR and supervisor reports/exports preserve scope, periods and access checks.
- [ ] Finance capital/payables/payments/expenses/categories/budgets/statements/analysis/audit preserve approval boundaries.
- [ ] Existing user/role administration, reset/status/force-logout and relevant dashboards implemented for all actual roles.
- [ ] Recruitment review/interviews/requirements/hiring create and link the correct employee/user/document identities.
- [ ] Employee CRUD/status/history, private documents and document download authorization verified.
- [ ] Attendance/geofencing/accuracy/late approvals, shifts/schedules, leave, overtime and official business preserve actor and team rules.
- [ ] Supervisor teams/performance/POS monitoring/inventory/procurement/report access scoped correctly.
- [ ] Payroll preparation/calculation/adjustments, accountant read-only queue, finance/admin approval/rejection/payment and released payslips verified.
- [ ] Payroll acknowledgement upload/download and self-service payslips enforce ownership/release/privacy boundaries.
- [ ] HR letters/templates/reminders/approval/recipient signature/download/event/mail flows preserved with token expiry.
- [ ] Public DSR intake, identity verification, authorized review, private response upload/expiry/download and configured retention verified against actual tables.
- [ ] Announcements/notifications/read tracking and links preserve role and recipient scope.

## Security, quality and cutover

- [ ] Route role gates and record policies deny unauthorized cashier/employee access to admin/finance/HR/payroll/private documents.
- [ ] All browser POST/PUT/PATCH/DELETE actions require CSRF; lookup/query APIs expose only allowed fields.
- [ ] FormRequests validate inputs; totals/permissions are server-derived; Blade escaping and parameterized SQL preserve boundaries.
- [ ] Private uploads validate actual MIME/size, use generated names and have authorized delivery; personnel files absent from public/Git artifacts.
- [ ] Security/session headers and CSP strategy verified without breaking scanner/print/recipient flows.
- [ ] Audit changes are attributable and secret/PII-minimized; retention has explicit policy, protected exceptions and tested dry-run/authorization behavior.
- [x] Meaningful auth/sale/stock/refund/role feature tests pass on the verified isolated targets; no simulated helper checks presented as integration tests.
- [x] PHP lint, Blade compilation and scratch-only migration/seed checks pass with recorded versions and results.
- [ ] Responsive desktop/mobile/accessibility, validation/save/cancel/confirmation, table states and navigation verified against the legacy visual baseline.
- [ ] Query/index/cache/asset changes have actual measurements, correctness checks and production build evidence.
- [ ] Required API/setup/architecture/database/security/privacy/UI/testing/performance/flowchart/folder/migration documents reflect installed code.
- [ ] Commit/CI documentation gate implemented and checked; dated changelog/progress/parity records accompany each meaningful change.
- [ ] Full cutover smoke test records role-specific login, product, sale, stock/log, restock, dashboards/reports, logout and denied access results.
- [ ] Only after complete evidence: legacy deprecation notice, requested release commits/tag and final reconciliation; retain legacy for the requested cycle.

## Per-entry source checklist

Every entry below is pending. Routes/handlers are proposed in
[MIGRATION_MAPPING](MIGRATION_MAPPING.md), not installed. Utility/helper rows
require a restricted CLI/service equivalent and an explicit decision about
unsafe legacy exposure; they do not require a public web route. Each screen's
actions must also reconcile the [static action candidates](audit/LEGACY_ACTIONS.md)
and manually reviewed request/JS/download branches before verification.

- [ ] `project/HRMS/admin_dashboard.php` - Tested but incomplete: native scoped leave queue/decisions at `/leave-approvals`, with named scope/rollback/concurrency/browser evidence above. Other dashboard/monitoring and supervisor-preview contracts remain pending.
- [ ] `project/HRMS/admin_profile.php` - Planned; proposed `/hrms/admin/profile`. Evidence: not yet run.
- [ ] `project/HRMS/announcements.php` - Planned; proposed `/hrms/announcements`. Evidence: not yet run.
- [ ] `project/HRMS/applicant_document_download.php` - Planned; proposed `/hrms/applicants/documents/download`. Evidence: not yet run.
- [ ] `project/HRMS/applicant_review.php` - Planned; proposed `/hrms/applicants/review`. Evidence: not yet run.
- [ ] `project/HRMS/applicant_review_queue.php` - Planned; proposed `/hrms/applicants`. Evidence: not yet run.
- [ ] `project/HRMS/apply.php` - Planned; proposed `/careers/apply`. Evidence: not yet run.
- [ ] `project/HRMS/attendance.php` - Planned; proposed `/hrms/attendance`. Evidence: not yet run.
- [ ] `project/HRMS/backfill_hired_documents.php` - Planned; CLI-only; no HTTP port. Evidence: not yet run.
- [x] `project/HRMS/change_password.php` - Verified bounded own-password flow on shared `/account/security` and `/account/password`; all six native HR roles plus distinct linked Admin IDs tested. Does not claim full profile/reset administration or every legacy session implementation.
- [ ] `project/HRMS/check_session_status.php` - Planned; proposed `/hrms/session/status`. Evidence: not yet run.
- [ ] `project/HRMS/create_admin.php` - Planned; CLI-only; no HTTP port. Evidence: not yet run.
- [ ] `project/HRMS/employee_dashboard.php` - Planned; proposed `/hrms/employee`. Evidence: not yet run.
- [ ] `project/HRMS/employee_document_download.php` - Planned; proposed `/hrms/employees/documents/download`. Evidence: not yet run.
- [ ] `project/HRMS/employee_documents.php` - Planned; proposed `/hrms/employees/documents`. Evidence: not yet run.
- [ ] `project/HRMS/employee_schedule.php` - Planned; proposed `/hrms/employee/schedule`. Evidence: not yet run.
- [ ] `project/HRMS/employees.php` - Planned; proposed `/hrms/employees`. Evidence: not yet run.
- [ ] `project/HRMS/generate_discount_code.php` - Planned; proposed `/hrms/discount-approvals`. Evidence: not yet run.
- [ ] `project/HRMS/hr_dashboard.php` - Tested but incomplete: native scoped leave queue/decisions at `/leave-approvals`, with named scope/rollback/concurrency/browser evidence above. Other dashboard/monitoring and supervisor-preview contracts remain pending.
- [ ] `project/HRMS/hr_letter_download.php` - Planned; proposed `/hrms/letters/download`. Evidence: not yet run.
- [ ] `project/HRMS/hr_letter_edit.php` - Planned; proposed `/hrms/letters/edit`. Evidence: not yet run.
- [ ] `project/HRMS/hr_letter_print.php` - Planned; proposed `/hrms/letters/print`. Evidence: not yet run.
- [ ] `project/HRMS/hr_letter_reminders.php` - Planned; proposed `/hrms/letters/reminders`. Evidence: not yet run.
- [ ] `project/HRMS/hr_letter_signed.php` - Planned; proposed `/hrms/letters/signed`. Evidence: not yet run.
- [ ] `project/HRMS/hr_letter_templates.php` - Planned; proposed `/hrms/letters/templates`. Evidence: not yet run.
- [ ] `project/HRMS/hr_letters.php` - Planned; proposed `/hrms/letters`. Evidence: not yet run.
- [ ] `project/HRMS/hr_manager_dashboard.php` - Tested but incomplete: native scoped leave queue/decisions at `/leave-approvals`, with named scope/rollback/concurrency/browser evidence above. Other dashboard/monitoring and supervisor-preview contracts remain pending.
- [ ] `project/HRMS/hr_notifications.php` - Planned; proposed `/hrms/notifications`. Evidence: not yet run.
- [ ] `project/HRMS/hr_reports.php` - Planned; proposed `/hrms/reports`. Evidence: not yet run.
- [ ] `project/HRMS/index.php` - Planned; proposed `/hrms`. Evidence: not yet run.
- [ ] `project/HRMS/late_timein_requests.php` - Planned; proposed `/hrms/attendance/late-requests`. Evidence: not yet run.
- [ ] `project/HRMS/letter_recipient.php` - Planned; proposed `/letters/respond`. Evidence: not yet run.
- [ ] `project/HRMS/login.php` - Planned; proposed `/hrms/login`. Evidence: not yet run.
- [ ] `project/HRMS/logout.php` - Planned; proposed `/hrms/logout`. Evidence: not yet run.
- [ ] `project/HRMS/manage_application_form.php` - Planned; proposed `/hrms/application-form`. Evidence: not yet run.
- [ ] `project/HRMS/manage_supervisor_teams.php` - Planned; proposed `/hrms/supervisor-teams`. Evidence: not yet run.
- [ ] `project/HRMS/my_profile.php` - Tested but incomplete: native own personal information, schedule, leave submission/decisions and overtime submission/history have named evidence above. Native team overtime decisions are also tested above; attendance/punching, payslips, avatar and broader HR workflows remain pending.
- [ ] `project/HRMS/password_reset_requests.php` - Planned; proposed `/admin/password-reset-requests`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/accountant.php` - Planned; proposed `/hrms/payroll/accountant`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/approve_record.php` - Planned; proposed `/hrms/payroll/decision`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/attendance.php` - Planned; proposed `/hrms/payroll/attendance`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/capital_transaction.php` - Planned; proposed `/finance/payroll-funding`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/cash_ack_download.php` - Planned; proposed `/hrms/payroll/cash-acknowledgement`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/employees.php` - Planned; proposed `/hrms/payroll/employees`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/get_employee_payslips.php` - Planned; proposed `/api/hrms/payroll/employee-payslips`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/history.php` - Planned; proposed `/hrms/payroll/history`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/payroll.php` - Planned; proposed `/hrms/payroll`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/payslip_lookup.php` - Planned; proposed `/hrms/payroll/payslip`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/save_record.php` - Planned; proposed `/hrms/payroll/records`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/submit_for_approval.php` - Planned; proposed `/hrms/payroll/submit`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/thirteenth.php` - Planned; proposed `/hrms/payroll/thirteenth-month`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/upload_cash_ack.php` - Planned; proposed `/hrms/payroll/cash-acknowledgement`. Evidence: not yet run.
- [ ] `project/HRMS/payroll/workflow.php` - Planned; service only; no HTTP route. Evidence: not yet run.
- [ ] `project/HRMS/payslip.php` - Planned; proposed `/hrms/account/payslips`. Evidence: not yet run.
- [ ] `project/HRMS/ph_location_lookup.php` - Planned; proposed `/api/locations`. Evidence: not yet run.
- [ ] `project/HRMS/privacy_request.php` - Planned; proposed `/privacy/request`. Evidence: not yet run.
- [ ] `project/HRMS/privacy_requests.php` - Planned; proposed `/admin/privacy-requests`. Evidence: not yet run.
- [ ] `project/HRMS/privacy_response_download.php` - Planned; proposed `/privacy/response`. Evidence: not yet run.
- [ ] `project/HRMS/purchase_orders.php` - Planned; proposed `/hrms/purchase-orders`. Evidence: not yet run.
- [ ] `project/HRMS/requirements_submit.php` - Planned; proposed `/careers/requirements`. Evidence: not yet run.
- [ ] `project/HRMS/reset_password.php` - Planned; proposed `/admin/users/password`. Evidence: not yet run.
- [ ] `project/HRMS/resume_download.php` - Planned; proposed `/hrms/applicants/resume`. Evidence: not yet run.
- [ ] `project/HRMS/setup_2fa.php` - Planned; proposed `/hrms/account/two-factor/setup`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_attendance.php` - Planned; proposed `/supervisor/attendance`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_catalog_security.php` - Planned; proposed `/supervisor/catalog-access`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_dashboard.php` - Planned; proposed `/supervisor`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_inventory.php` - Planned; proposed `/supervisor/inventory`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_late_timein.php` - Planned; proposed `/supervisor/late-time-in`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_leave.php` - Tested but incomplete: native scoped leave queue/decisions at `/leave-approvals`, with named scope/rollback/concurrency/browser evidence above. Other dashboard/monitoring and supervisor-preview contracts remain pending.
- [ ] `project/HRMS/supervisor_official_business.php` - Planned; proposed `/supervisor/official-business`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_overtime.php` - Tested but incomplete: native assigned-team queue/decisions at `/overtime-approvals`, raw-work revision/privacy/atomicity/concurrency and separate browser evidence above. On-behalf logging, Admin supervisor-preview and full supervisor parity remain pending.
- [ ] `project/HRMS/supervisor_performance.php` - Planned; proposed `/supervisor/performance`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_pos_monitor.php` - Planned; proposed `/supervisor/pos`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_purchase_orders.php` - Planned; proposed `/supervisor/purchase-orders`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_reports.php` - Planned; proposed `/supervisor/reports`. Evidence: not yet run.
- [ ] `project/HRMS/supervisor_schedule.php` - Planned; proposed `/supervisor/schedule`. Evidence: not yet run.
- [ ] `project/HRMS/verify_2fa.php` - Planned; proposed `/hrms/two-factor/verify`. Evidence: not yet run.
- [ ] `project/accounts_payable.php` - Planned; proposed `/finance/payables`. Evidence: not yet run.
- [ ] `project/ai_product_ingest.php` - Planned; proposed `/api/inventory/nutrition-ingest`. Evidence: not yet run.
- [ ] `project/ai_settings.php` - Planned; proposed `/inventory/ai-settings`. Evidence: not yet run.
- [ ] `project/announcements.php` - Planned; proposed `/announcements`. Evidence: not yet run.
- [ ] `project/budgeting.php` - Planned; proposed `/finance/budgets`. Evidence: not yet run.
- [ ] `project/catalog.php` - Planned; proposed `/catalog`. Evidence: not yet run.
- [ ] `project/catalog_supervisor_login.php` - Planned; proposed `/catalog/unlock`. Evidence: not yet run.
- [x] `project/change_password.php` - Verified bounded own-password flow: GET `/account/security`, POST `/account/password`; AccountSecurityTest and Chromium evidence above. Stronger policy, canonical compatible links and atomic synchronization replace best-effort writes; profile/avatar/reset administration remains separate.
- [ ] `project/dashboard.php` - Planned; proposed `/dashboard`. Evidence: not yet run.
- [ ] `project/expense_categories.php` - Planned; proposed `/finance/expense-categories`. Evidence: not yet run.
- [ ] `project/finance.php` - Tested but incomplete: native cashier and product discount decisions are verified above. Procurement, expenses, ledgers, payment/finance analysis and other approval queues remain preserved legacy.
- [ ] `project/finance_dashboard.php` - Planned; proposed `/finance`. Evidence: not yet run.
- [ ] `project/financial_analysis.php` - Planned; proposed `/finance/analysis`. Evidence: not yet run.
- [ ] `project/financial_audit_log.php` - Planned; proposed `/finance/audit`. Evidence: not yet run.
- [ ] `project/financial_statements.php` - Planned; proposed `/finance/statements`. Evidence: not yet run.
- [ ] `project/index.php` - Planned; proposed `/`. Evidence: not yet run.
- [ ] `project/inventory.php` - Planned; proposed `/inventory`. Evidence: not yet run.
- [ ] `project/inventory_dashboard.php` - Planned; proposed `/inventory/dashboard`. Evidence: not yet run.
- [ ] `project/inventory_reports.php` - Planned; proposed `/inventory/reports`. Evidence: not yet run.
- [ ] `project/inventory_requests.php` - Planned; proposed `/inventory/requests`. Evidence: not yet run.
- [ ] `project/kiosk.php` - Planned; proposed `/kiosk`. Evidence: not yet run.
- [ ] `project/label_photo.php` - Planned; proposed `/inventory/label-photo`. Evidence: not yet run.
- [ ] `project/login.php` - Planned; proposed `/login`. Evidence: not yet run.
- [ ] `project/logout.php` - Planned; proposed `/logout`. Evidence: not yet run.
- [ ] `project/my_profile.php` - Tested but incomplete: native own personal information, schedule, leave submission/decisions and overtime submission/history have named evidence above. Native team overtime decisions are also tested above; attendance/punching, payslips, avatar and broader HR workflows remain pending.
- [ ] `project/notifications.php` - Planned; proposed `/notifications`. Evidence: not yet run.
- [x] `project/nutrition_compare.php` - Verified bounded public port at /compare (up to3 verified active IDs); tests and desktop/mobile evidence above. Serving mismatch is blocked and no universal nutrient ranking/conversion is inferred.
- [ ] `project/operating_expenses.php` - Planned; proposed `/finance/expenses`. Evidence: not yet run.
- [ ] `project/password_reset.php` - Planned; proposed `/password/reset`. Evidence: not yet run.
- [ ] `project/password_reset_request.php` - Planned; proposed `/password/request`. Evidence: not yet run.
- [ ] `project/payslip_view.php` - Planned; proposed `/account/payslips/view`. Evidence: not yet run.
- [ ] `project/pos.php` - Planned; proposed `/pos`. Evidence: not yet run.
- [ ] `project/procurement_letter.php` - Planned; proposed `/procurement/letters/preview`. Evidence: not yet run.
- [ ] `project/procurement_signature_enrollment.php` - Planned; proposed `/procurement/signature`. Evidence: not yet run.
- [ ] `project/product_ai_review.php` - Planned; proposed `/products/nutrition/review`. Evidence: not yet run.
- [x] `project/product_barcode_labels.php` - Verified bounded native EAN-13 label port at GET /labels + POST /labels/print (kind=barcode). Admin/Inventory Staff allowed, Cashier denied. ProductLabelsTest and pixel-decoding browser evidence above; no physical-printer claim.
- [ ] `project/product_barcode_lookup.php` - Planned; proposed `/api/products/barcode`. Evidence: not yet run.
- [ ] `project/product_batches.php` - Planned; proposed `/inventory/batches`. Evidence: not yet run.
- [ ] `project/product_detail.php` - Tested but incomplete: /catalog/{id} publishes verified facts, safe sources and related items; private catalog, return contexts and scan analytics remain pending.
- [ ] `project/product_details_edit.php` - Tested but incomplete: native manual four-field save/submit/resubmit and Admin verify/reject lifecycle with stale/source/audit guards is verified above; legacy auto-AI/photo paths and full nutrition editing remain pending.
- [ ] `project/products.php` - Tested but incomplete: native `/manage/products` CRUD and `/product-discounts` proposal/cancellation with Finance decision evidence above. Image upload, AI/detail review and other legacy actions remain pending.
- [ ] `project/purchase_order_letter.php` - Planned; proposed `/procurement/purchase-orders/letter`. Evidence: not yet run.
- [ ] `project/purchase_order_receipt.php` - Planned; proposed `/procurement/purchase-orders/receipts`. Evidence: not yet run.
- [ ] `project/purchase_orders.php` - Planned; proposed `/procurement/purchase-orders`. Evidence: not yet run.
- [ ] `project/reports.php` - Planned; proposed `/reports`. Evidence: not yet run.
- [ ] `project/scan.php` - Tested but incomplete: native `/catalog/scan` manual/store-code and GTIN resolver verified above; encrypted legacy tokens, return contexts and scan analytics remain open.
- [ ] `project/scan_insights.php` - Planned; proposed `/inventory/scan-insights`. Evidence: not yet run.
- [ ] `project/scanner.php` - Planned; proposed `/scanner`. Evidence: not yet run.
- [ ] `project/setup_2fa.php` - Planned; proposed `/account/two-factor/setup`. Evidence: not yet run.
- [x] `project/shelf_labels.php` - Verified bounded shelf-label port at GET /labels + POST /labels/print (kind=shelf), maximum96 active IDs. Same roles; browser decodes exact public product URL and generates A4 output. Network/device setup and physical print remain unverified.
- [ ] `project/shift_report.php` - Planned; proposed `/reports/shifts`. Evidence: not yet run.
- [ ] `project/signature_image.php` - Planned; proposed `/procurement/signatures/image`. Evidence: not yet run.
- [ ] `project/stock_recommendations.php` - Planned; proposed `/inventory/recommendations`. Evidence: not yet run.
- [ ] `project/supplier_inventory_approval.php` - Planned; proposed `/supplier/inventory-approval`. Evidence: not yet run.
- [ ] `project/supplier_purchase_order_approval.php` - Planned; proposed `/supplier/purchase-order-approval`. Evidence: not yet run.
- [ ] `project/suppliers.php` - Planned; proposed `/suppliers`. Evidence: not yet run.
- [ ] `project/vendor_ratings.php` - Planned; proposed `/suppliers/ratings`. Evidence: not yet run.
- [ ] `project/verify_2fa.php` - Planned; proposed `/two-factor/verify`. Evidence: not yet run.

## Later owner correction - original visual parity

The owner's latest scope is stack migration only. Prior redesigned UI checks
remain functional history and do not satisfy original-style acceptance.
Home and initial login now use source-derived Blade, original CSS and local
licensed fonts. Four viewport/theme comparisons each have zero pixels exceeding
the eight-value threshold under reduced motion and the same local fonts/browser.
See [home evidence](audit/ORIGINAL_UI_BROWSER.json), [login evidence](audit/ORIGINAL_LOGIN_BROWSER.json)
and [source fingerprints](audit/ORIGINAL_UI_SOURCE.json). This is initial-screen
viewport evidence; full login policy/lock-popup parity is not established.
OriginalUiTest verifies home query/category bounds, output escaping, no legacy
bootstrap inclusion, catalog full results/order/base prices/search alias/category
counts, verified-allergen exclusion, private guard handoff and safe basename
image delivery. Chromium exercises original menu/theme/password/catalog controls.
Native availability still applies the existing stock clamp/expiry safeguards.

Catalog has screenshots and functional evidence, without an independent pixel
reference. Private/kiosk access/reset and all remaining workflow ports stay
partial/legacy. Product/comparison/staff screens still require original-style
restoration. Keep broad workflow/visual acceptance unchecked until verified.

## Original product detail - bounded evidence

OriginalProductUiTest adds five source-contract cases for native detail layout,
verified-only facts, exact zero/unknown behavior, safe multiline text/URLs,
read-only public/no-personnel query boundary, allowlisted guarded returns and
empty-category related selection/context. Existing facts, information, labels,
discounts and code tests now assert original presentation wording. Four full-page
source-presentation comparisons match with zero pixels above the eight-value
threshold: [ORIGINAL_PRODUCT_BROWSER](audit/ORIGINAL_PRODUCT_BROWSER.json).
The source fingerprint is pinned before PHP presentation extraction; original
config/bootstrap/queries and scan logging never execute. The CSS stock expression
maps to a custom property, with otherwise identical CSS recorded in source hashes.
Private catalog sessions, scan analytics, comparison/staff UI restoration and
complete workflow parity remain pending; do not check broad acceptance from this.

## Original comparison presentation - bounded evidence

Original comparison CSS, navigation, picker, theme and table are now source
derived. OriginalComparisonUiTest proves source minimum-cell/null/zero behavior,
single-product output and read-only/no-personnel/cost boundaries; OriginalUiTest
adds exact CSS-copy verification. Existing verified/active/category/basis/serving
and selected-outside100 checks remain. Eight initial-viewport/table comparisons
pass the unchanged0.2% threshold: [comparison evidence](audit/ORIGINAL_COMPARISON_BROWSER.json).
Native current selections, numeric ids[] and stronger data guards are retained;
selected-picker full-page or independent original SQL parity is not claimed.
Row-minimum color restores source formatting, not a universal nutrient ranking.
Other staff/MFA/private/scanner/full workflow acceptance remains unchecked.
