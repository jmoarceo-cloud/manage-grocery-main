# Laravel route and API inventory

Updated 2026-10-26. Paths below are relative to
`/manage-grocery-main/public/`. Browser mutations retain Laravel request-forgery
protection. Staff middleware requires completed MFA, current account status
and idle/password checks. Role enforcement occurs on the server.

## Identifier and empty-category contracts

Native `{id}` paths accept canonical positive ASCII decimals of 1-10 digits.
Zero, negatives, leading zeros, exponent/decimal forms and longer values return
404 before route controllers; matching an ID never substitutes for role/ownership
checks. Existing operational IDs in checkout, refund, basket approval, labels,
restock and supplier references are validated from 1 through 2147483647 before
operational lookups. Invalid body values return422; normal authentication still
runs. Future bigint resources require explicit corresponding route/body rules.

Product create/update accepts the native Uncategorized choice. Empty/null category
is stored as the existing non-null empty string; creation also supports omission.
An omitted category on update preserves its former value. This does not create
a new named category or change existing stock.

## Own personal information

`GET /account/profile` and `POST /account/profile` require established staff MFA
and an eligible native POS/HR identity. There is no target-ID parameter: only the
actor's explicit employee_id selects the HR record. Unlinked accounts see an
empty state and cannot update. Read-only employment context excludes financial,
government-ID and private-document fields.

POST accepts revision, current_password, email/email_confirmation,
contact_number_country/contact_number, date_of_birth (YYYY-MM-DD), place_of_birth,
sex, civil_status, home_address, highest_educational_attainment and year_graduated.
Job/status/role/identity/payroll fields are prohibited. The64-hex revision binds
actor, domain and saved row; stale forms must reload. Current password is checked
against the locked current account, with five bad proofs per15 minutes. Contact
email must be repeated exactly and unused by another employee; it does not alter
MFA email and is not delivery-verified. Known country lengths follow legacy
compatibility data. KEEP permits only the unchanged unknown stored phone value.

Successful HTML updates redirect with a status message; validation failures
redirect to the profile with errors and no flashed personal values/password.
JSON validation failures return422; denied account/ownership returns403. Named
email-lock contention and unavailable supported topology return validation
errors without an employee write. Audit and allowed field changes are atomic.

## Own shift schedule

`GET /account/schedule` requires completed staff MFA and current eligible POS/HR
identity. It accepts no target employee, date range or other selection input;
query parameters cannot expand its scope. Only the actual linked employee_id is
read. Unlinked staff receive an empty state; ineligible/missing employee links
fail the normal staff gate. POST is405; no schedule mutation endpoint exists.

The response presents seven recurring weekdays and today through today+6 in
Asia/Manila. Approved own covering swaps are used only without a weekly shift.
No weekly rows yields Not set; configured unassigned weekdays are Day off.
Assigned inactive shifts remain visible with a warning. Invalid/missing shift
times ask HR confirmation, and end<=start shows next-day. Private swap reasons,
colleague/approver IDs, contact, payroll and attendance data are not selected.
The screen does not determine leave, holiday, pay or clock-in authorization.

## Own leave

`GET /account/leave` requires full staff MFA/eligibility and uses only the actual
employee_id. Optional year1000..9999 filters leave-start year, optional status is
pending/approved/rejected, and page is1..1000000;20 records per page. Unknown target
query parameters cannot select another employee. Safe own balances/history are
returned; GET creates no credit rows. Missing rows are labeled default estimates.
Unlinked accounts see the HR-link empty state and cannot submit.

`POST /account/leave` accepts only leave_type (sick/vacation/emergency/others),
date_from/date_to (real four-digit-year YYYY-MM-DD, start not past, ordered) and
reason (trimmed3..2000 characters). Target IDs, approval/status/credit/timestamps
are prohibited. Vacation enforces the existing start-year remaining balance,
one clear calendar day between vacation periods and30 calendar days since the
latest pending/approved vacation filing. Sick/emergency do not enforce a cap.
Pending balances are not deducted/reserved. All role domains use these same rules.

New JSON submission returns201 with request_id,status=pending,replayed=false;
an identical pending retry returns200 with the existing ID and replayed=true.
HTML success redirects to the leave-start year. Validation returns422 for JSON;
HTML validation/service errors redirect with errors and no flashed reason/input.
Ineligible or changed-link submissions are denied. No native cancel, decision,
document upload or credit-administration endpoint is introduced. Existing HR and
supervisor approvals remain in the preserved app with separate authentication.

## Leave review and decisions

`GET /leave-approvals` requires established MFA and an actual HR-domain account
with role admin/hr/hr_manager/supervisor. POS Admin and HR employee/accountant are
denied403. Optional status is pending (default), approved or rejected; page is
bounded1..1000000,20 rows per page. Supervisor/employee/scope query input cannot
expand authorization. Current scopes: Admin all; HR Manager highest requester
role hr; HR Staff supervisors or regular/no-account employees without a team;
supervisor actual own team. Highest linked HR role resolves duplicate requester
accounts. Non-Admin self requests are excluded. Financial/contact/private-file
columns are not selected; reasons appear only within current authorized scope.

`POST /leave-approvals/{id}/decision` accepts decision=approved/rejected and a
64-hex revision bound to reviewer and reviewed request. Caller-supplied target
employee, approver/log actor, status, credits, dates and reason are prohibited.
Current scope is checked before loading reason data, then roles/team/current
links are locked and rechecked. Outside-scope IDs return404; stale/terminal or
unavailable transaction/credit conditions return422. HTML errors redirect without
old input. Success returns JSON200 or an HTML redirect with status text.

Approvals atomically update request, tracked credit, HR decision log and minimal
domain audit using the actual HR user ID. Vacation must still fit the current
recorded balance. Sick/emergency are not capped by allocation but must fit tracking
storage; others has no credit. Dates remain inclusive and charged to start year.
Ineligible employees can be rejected, not approved. No reversal/cancellation,
attachment, admin credit editing or payroll-release operation is exposed here.

## Own overtime

`GET /account/overtime` requires full staff MFA/eligibility and the actual linked
employee_id. Optional year1000..9999 filters overtime date, status is pending/
approved/rejected, and page1..1000000;20 rows per page. Unknown target inputs cannot
select another employee. History shows requested hours and separately derived
own recorded work/excess. GPS, financial/contact and approver fields are excluded.
Missing linkage is an empty state; absent assigned supervisor gets an HR notice.

`POST /account/overtime` accepts ot_date (true YYYY-MM-DD from1000-01-01 through
today in Asia/Manila), hours_requested (ordinary decimal0.01..12 with at most two
decimal places) and reason (trimmed3..2000 characters). Target/status/approval/
timestamp/actual-work fields are prohibited. Current linked actor/employee and
transactional tables are checked under locks. New JSON returns201 with request_id,
status=pending,replayed=false; identical normalized pending contents return200,
the same ID and replayed=true. HTML redirects to the requested date's year.
Validation is422 for JSON; HTML errors contain no old input/private reason.

Submission does not create punches, actual overtime, approval or payroll output.
The read-only display follows current recurring assignments/fallback shift rules,
including early-arrival exclusion and legacy rounding. Missing/invalid punches
remain unknown. Supervisor/Admin decisions, on-behalf entry, cancellation and
payroll release are outside these endpoints.

## Overtime review

`GET /overtime-approvals` requires completed MFA and actual HR-domain Admin or
supervisor. Admin sees all supervised members; supervisor only current own team,
excluding self. Unassigned requests and other native roles are not authorized by
this module. Scope/supervisor/employee query parameters cannot expand it. Status
is pending (default)/approved/rejected; page1..1000000,20 rows per page. Names,
request fields and matching date/time/shift evidence are scoped before delivery;
no GPS, contact, financial or private-document fields are selected.

`POST /overtime-approvals/{id}/decision` accepts decision=approved/rejected plus
64-hex revision. Proof binds reviewer, request and raw current attendance/shift/
schedule-type evidence. Current actor/link/team and evidence are locked/rechecked;
outside-scope IDs return404, stale/terminal/unsupported or invalid approval
conditions422. Target/actor/state/date/hours/reason/actual-work input is prohibited.
HTML errors redirect without old input; JSON success is200, HTML redirects with
status. Changed raw seconds require review even when rounded figures match.

Approval atomically changes status, actual HR approved_by/time and domain audit.
It preserves requested hours and writes no attendance, payroll or actual-hours
field. Missing complete punches or requested>recorded excess remain informational,
as legacy; payroll independently enforces its actual/approved ceiling. Ineligible
employees and future/zero/>12-hour legacy requests cannot be newly approved but
may be rejected. No reversal, on-behalf creation, attachment or payroll release
endpoint is introduced here.

## POS contracts

GET `catalog/{id}` now includes only active, verified descriptive/nutrition facts;
other review states return the basic public product with a generic unavailable
message. Internal reviewer/updater identifiers and rejection notes are omitted.
GET `compare?ids[]=1&ids[]=2` accepts up to three distinct active verified product
IDs (zero/one selection shows the picker). Mismatched/missing category, nutrition
basis or listed serving sizes produces a comparison notice. Missing nutrient
values remain unknown; numbers are not converted or ranked. Both pages are
throttled. Source URLs are limited to credential-free HTTP(S).

GET `labels` is Admin/Inventory Staff only, with validated product/barcode search
and pagination up to 96 products. POST `labels/print` requires kind shelf/barcode
and 1-96 distinct positive product_ids. Missing/inactive IDs reject the whole
selection. The read-only HTML response uses current effective prices and local
SVG. Shelf QR encodes configured APP_URL/catalog/{id}; barcode output requires a
valid existing EAN-13/checksum, otherwise it shows an explicit text fallback.

GET `discount-requests` shows a paginated status-filtered history to the owning
cashier or Finance/Admin reviewers. POST `api/discount-requests` is cashier-only:
send item IDs/positive quantities, discount_type percent/fixed, positive
discount_value and optional reason. The server recomputes the subtotal using
current product discounts; client subtotal/prices/cashier/status are rejected.
It permits one pending native request per cashier and does not reserve stock.

GET `api/discount-requests` returns at most 30 own approved, unused requests for
the register selector. POST `discount-requests/{id}/cancel` requires the owning
cashier and pending status. POST `discount-requests/{id}/decision` requires
Finance/Admin, decision approved/rejected and optional reason. The decision
locks the pending row, records the actual POS actor/time and cannot be replaced.
These endpoints use the existing discount_requests schema and minimal audit.

GET `api/pos/lookup?q=...` returns at most 12 allowed product results and
sellable availability, excluding private costs/suppliers/personnel fields.
POST `api/pos/checkout` requires a UUID request_key, 1-100 distinct items with
ID/positive integer quantity, payment and a supported discount_type. Item
prices and cashier identity cannot be supplied. Senior/PWD discounts require
ID data and active supervisor/admin approval; manual approved requests must
belong to the cashier, match the subtotal and be unused.
Irrelevant statutory customer identity/approval inputs are discarded for
ordinary/manual-approved sales before durable hashing or sale persistence.

Success returns `receipt` with sale_id, server-generated receipt_no, date,
items, subtotal/discount/total, payment/change and status. Identical UUID
retries return the original sale without more stock writes. A changed payload
with that UUID returns 409. Validation failures are 422, expired JSON access
401, permission failures 403 and missing forgery protection 419.

GET `api/pos/requests/{key}` resolves only the current cashier's completed key,
or returns 404. POST `sales/{id}/refund` requires positive per-sale quantities,
reason and supervisor credentials; it locks sale/products, checks remaining
return quantities, restores original batches and logs movements atomically.
Receipt GET requires the owning cashier or authorized Admin/Finance.

POST `sales/{id}/void` is cashier-only and accepts no client totals. The server
requires a completed sale owned by that cashier from today and no refund rows.
It restores original allocations, appends immutable void stock logs and changes
the sale status in one transaction. Replays and other owners/dates are rejected.
Database connection failures return a generic 503 with Retry-After: 60, without
SQL, credentials or record values. PDO connection attempts are bounded to three
seconds; query execution latency is a separate concern.

POST `two-factor/send` requires a pending password-verified challenge. First
enrollment supplies a valid email; enrolled accounts resend only to their saved
verified address. Automatic mail failure or send throttling retains pending
MFA and recovery-code entry, without authenticating the session.

Restock POST is Admin-only. Catalog mutations require Admin/Inventory Staff.
Referenced products/suppliers are deactivated; unused categories can be
deleted after checking product usage. Legacy routes in
[the source inventory](audit/LEGACY_ENTRYPOINTS.md) remain in the preserved app;
they are not automatically Laravel endpoints.

## Manual product information

GET `manage/products/{id}/information` is Inventory Staff/Admin only. It returns
saved editable description/brand/unit/storage, read-only recorded label facts,
review state, an opaque revision and ten minimal history entries. Unsafe source
references and raw audit/image payloads are not rendered. IDs are positive and
bounded. POST at the same URL accepts action save/submit/resubmit/verify/reject,
revision and appropriate fields. Edits require all four editable keys (nullable,
bounded strings); decisions prohibit edited text. Reject requires a reason.
Posted status/actor/verification metadata, ingredients/allergens, calories and source type are prohibited.

Only Admin verifies/rejects needs_review. Submit accepts draft/ai_filled/
needs_review; resubmit requires rejected. Save of verified/rejected unpublishes
to needs_review; other saves retain draft/review state. Verification refuses web
sources/latest AI web fields. Inactive products cannot change. Current row and
provenance must match the product-bound HMAC revision; conflicts return 422.
HTML conflicts redirect to fresh information without stale old_input; other
validation errors use standard handling. Success is an HTML status redirect or
JSON review_status. Product row, established review audit and minimal domain
audit commit together. Staff MFA/roles/session/CSRF protections remain in force.

## Product discount proposals

GET `product-discounts` permits Admin/Inventory Staff/Finance, status filtering,
20-row history pages and bounded product search. Inventory Staff sees own history;
Admin/Finance sees all. Product choices select at most 100 active IDs/names/prices,
retaining an explicitly selected product. POST `product-discounts` is Admin/
Inventory Staff only: product_id, discount_type none/percent/fixed, discount_value
and optional discount_reason. Prices, actor IDs and approval status are prohibited;
snapshots are server-derived. Requests leave current prices/stock unchanged.

POST `product-discounts/{id}/decision` requires Admin/Finance, decision approved/
rejected and optional reason. Approval rechecks current price, discount and its
reason against snapshots, current active/expiry state and amount rules, then
atomically updates product/request/audit. Stale or invalid proposals return 422
without a decision. POST `{id}/cancel` is Admin or owning Inventory Staff, pending
only. Terminal decisions cannot be overwritten. HTML redirects carry a status;
JSON submission returns 201 with request_id/status, transitions return 200/status.
All mutations retain web CSRF, staff MFA and role/session checks. Catalog CRUD
explicitly rejects direct discount fields.

## Public product-code lookup

GET `catalog/scan?b=...` accepts an optional3-64-character ASCII barcode/store
code (letters, numbers, dot, underscore, hyphen). Empty input renders the form;
invalid input/arrays render422 without catalog SQL. An active exact match returns
302 to its native product page. Only a leading-zero14-digit code permits a final
13-digit fallback when no active exact match exists. Unknown/inactive codes use
the same200 form notice. Out-of-stock active products remain viewable. Responses
are no-store; the route permits60 requests/minute/client. Supplied return/URL
fields cannot control redirects. No analytics, camera permission, external lookup
or private-kiosk access is introduced. This HTML route is not the legacy external
food-database API at product_barcode_lookup.php.

## Account security

GET `account/security` requires completed staff MFA and returns own account
details, a masked verification email and recovery-code count. POST
`account/password` accepts credential_type password/recovery, corresponding
current_password/backup_code, password and password_confirmation. SECURITY and
ARCHITECTURE document the stronger password policy and atomic identity checks.
User/domain/role targets are prohibited. Invalid proof, policy or unsupported
identity topology returns 422; stale/noncanonical access is 403. Success ends
the session and redirects to login with a notice. JSON clients receive a message
and login redirect, never credential values.

## Registered native routes

Generated by `php bin/export-routes.php`; excludes vendor and testing-only routes.

| Method | URI | Name | Middleware |
|---|---|---|---|
| `GET\|HEAD` | `/` | `home` | `web, throttle:120,1` |
| `GET\|HEAD` | `account/leave` | `leave.index` | `web, staff` |
| `POST` | `account/leave` | `leave.store` | `web, staff` |
| `GET\|HEAD` | `account/overtime` | `overtime.index` | `web, staff` |
| `POST` | `account/overtime` | `overtime.store` | `web, staff` |
| `POST` | `account/password` | `account.password` | `web, staff` |
| `GET\|HEAD` | `account/profile` | `profile.edit` | `web, staff` |
| `POST` | `account/profile` | `profile.update` | `web, staff` |
| `GET\|HEAD` | `account/recovery-codes` | `mfa.recovery` | `web, staff` |
| `GET\|HEAD` | `account/schedule` | `schedule.index` | `web, staff` |
| `GET\|HEAD` | `account/security` | `account.security` | `web, staff` |
| `GET\|HEAD` | `admin` | `admin` | `web, staff, role:admin` |
| `GET\|HEAD` | `api/discount-requests` | `discounts.available` | `web, staff, role:cashier` |
| `POST` | `api/discount-requests` | `discounts.store` | `web, staff, role:cashier` |
| `POST` | `api/pos/checkout` | `pos.checkout` | `web, staff, role:cashier` |
| `GET\|HEAD` | `api/pos/lookup` | `pos.lookup` | `web, staff, role:cashier` |
| `GET\|HEAD` | `api/pos/requests/{key}` | `pos.resolve` | `web, staff, role:cashier` |
| `GET\|HEAD` | `catalog` | `catalog` | `web, throttle:120,1` |
| `GET\|HEAD` | `catalog/scan` | `catalog.scan` | `web, throttle:60,1` |
| `GET\|HEAD` | `catalog/{id}` | `product` | `web, throttle:120,1` |
| `GET\|HEAD` | `compare` | `compare` | `web, throttle:120,1` |
| `GET\|HEAD` | `dashboard` | `dashboard` | `web, staff` |
| `GET\|HEAD` | `discount-requests` | `discounts.index` | `web, staff, role:cashier,finance,admin` |
| `POST` | `discount-requests/{id}/cancel` | `discounts.cancel` | `web, staff, role:cashier` |
| `POST` | `discount-requests/{id}/decision` | `discounts.decide` | `web, staff, role:finance,admin` |
| `GET\|HEAD` | `index` | `` | `web` |
| `GET\|HEAD` | `inventory` | `inventory` | `web, staff, role:admin,inventory_staff` |
| `POST` | `inventory/restock` | `inventory.restock` | `web, staff, role:admin` |
| `GET\|HEAD` | `labels` | `labels.index` | `web, staff, role:admin,inventory_staff` |
| `POST` | `labels/print` | `labels.print` | `web, staff, role:admin,inventory_staff` |
| `GET\|HEAD` | `leave-approvals` | `leave-approvals.index` | `web, staff, role:admin,hr,hr_manager,supervisor` |
| `POST` | `leave-approvals/{id}/decision` | `leave-approvals.decide` | `web, staff, role:admin,hr,hr_manager,supervisor` |
| `GET\|HEAD` | `login` | `login` | `web` |
| `POST` | `login` | `login.submit` | `web` |
| `POST` | `logout` | `logout` | `web` |
| `GET\|HEAD` | `manage/products/{id}/information` | `product-information.edit` | `web, staff, role:admin,inventory_staff` |
| `POST` | `manage/products/{id}/information` | `product-information.update` | `web, staff, role:admin,inventory_staff` |
| `GET\|HEAD` | `manage/{entity}` | `manage.index` | `web, staff, role:admin,inventory_staff` |
| `POST` | `manage/{entity}` | `manage.store` | `web, staff, role:admin,inventory_staff` |
| `GET\|HEAD` | `manage/{entity}/create` | `manage.create` | `web, staff, role:admin,inventory_staff` |
| `PUT` | `manage/{entity}/{id}` | `manage.update` | `web, staff, role:admin,inventory_staff` |
| `DELETE` | `manage/{entity}/{id}` | `manage.deactivate` | `web, staff, role:admin,inventory_staff` |
| `GET\|HEAD` | `manage/{entity}/{id}/edit` | `manage.edit` | `web, staff, role:admin,inventory_staff` |
| `GET\|HEAD` | `overtime-approvals` | `overtime-approvals.index` | `web, staff, role:admin,supervisor` |
| `POST` | `overtime-approvals/{id}/decision` | `overtime-approvals.decide` | `web, staff, role:admin,supervisor` |
| `GET\|HEAD` | `pos` | `pos` | `web, staff, role:cashier` |
| `GET\|HEAD` | `product-discounts` | `product-discounts.index` | `web, staff, role:admin,inventory_staff,finance` |
| `POST` | `product-discounts` | `product-discounts.store` | `web, staff, role:admin,inventory_staff` |
| `POST` | `product-discounts/{id}/cancel` | `product-discounts.cancel` | `web, staff, role:admin,inventory_staff` |
| `POST` | `product-discounts/{id}/decision` | `product-discounts.decide` | `web, staff, role:admin,finance` |
| `GET\|HEAD` | `product-image/{id}` | `product.image` | `web` |
| `GET\|HEAD` | `receipts/{id}` | `receipt` | `web, staff, role:admin,finance,cashier` |
| `GET\|HEAD` | `reports` | `reports` | `web, staff, role:admin,finance,cashier` |
| `POST` | `sales/{id}/refund` | `pos.refund` | `web, staff, role:cashier` |
| `POST` | `sales/{id}/void` | `pos.void` | `web, staff, role:cashier` |
| `GET\|HEAD` | `two-factor` | `mfa` | `web` |
| `POST` | `two-factor` | `mfa.verify` | `web` |
| `POST` | `two-factor/send` | `mfa.send` | `web, throttle:10,1` |

## Comparison presentation compatibility

GET `/compare` retains its existing bounded numeric ids[] contract, verified/
active products, category/basis/serving checks and selected choices. Original
picker/table styling and single-product output now replace the redesigned view.
Per-row minima only affect cell styling. No production route or data write was
added; the original-comparison reference exists only on verified testing targets.
