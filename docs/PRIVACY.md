# GroceryOne privacy inventory and migration controls

Updated 2026-10-26. Existing workforce/customer data remains in its original
two schemas; no real records were copied into test fixtures or evidence.
Legal compliance and statutory retention periods have not been established.
Historical [privacy draft](archive/2026-10-26-pre-audit-PRIVACY.md) claims are
not current findings.

## Owner-requested source publication - 2026-10-26

The owner explicitly supplied `jmoarceo@pcu.edu.ph` for public author/contact
attribution. This address may appear in the README and public commit metadata.
Publication excludes personnel/customer uploads, avatars, database backups and
unclassified real-data screenshots. Private upload contents are not inspected
to prepare the source snapshot. Synthetic fixtures and clearly identified
source-derived UI evidence remain distinguishable from real records. No
Gmail message or business database is published. See [publication notes](PUBLISHING.md).

## Local recovery copies - 2026-10-26

Repair of original PHP startup preserved full server recovery copies outside
the web root and Git, with inherited access removed and access limited to the
current Windows identity, SYSTEM and local Administrators. Candidates stayed
in that private directory; the loopback candidate server was shut down after
verification. These copies include private server data and need deliberate
retention management; no automated deletion or compliance claim is made.
No real records were imported into another project or synthetic fixture.
Evidence reports metadata/counts and public storefront screenshots only.

## Data touchpoints

| Area | Existing source/schema | Current treatment |
|---|---|---|
| Workforce | `hrms.employees`: identity/contact/government/salary/bank fields | Original HRMS workflow remains; full native policies/forms are pending. |
| Recruitment | `job_applicants`, `applicant_documents`, consent and document references | Public application/requirements stay in the preserved app; no real applicant content entered evidence. |
| Attendance | HRMS time/location and schedule records | Existing workflow remains; no biometric implementation or new attendance-policy claim. |
| Payroll | Records/logs, payslip/release and acknowledgement files | Preserve approval/release/privacy boundaries while porting; no real payroll was executed. |
| POS discounts | Sales discount ID, holder/approver and refund metadata | Native validated transaction writes use established fields. JSON receipts do not disclose customer discount IDs or supervisor credentials. |
| Suppliers/signatures | Procurement contacts and signature references | Supplier CRUD is role restricted; private signatures and procurement portals remain to be ported. |
| Privacy requests | `data_subject_requests`, identity verification and expiring response-file references | Preserved legacy workflow, not a guessed replacement table or automatic purge. |
| Authentication | Separate user passwords, 2FA emails/recovery hashes/status | Verified session guards and protected pending challenges; no values in docs/log fixtures. Existing SMTP settings stay in private env. |
| New checkout requests | Cashier ID, opaque UUID, payload hash, sale link and optional approved request ID | Transactional retry control; no password/OTP or raw submitted cart is stored here. |
| New audit events | Actor domain/ID, entity/ID/action and changed field names | Values are omitted; policy and full retention implementation remain pending. |
| Optional mail/AI/search | User-triggered OTP and existing optional integrations | Tests fake mail; no real messages, AI requests or document contents were sent externally. |

These are processing touchpoints, not legal classifications or findings about
lawful basis. Schema metadata/aggregate counts are the evidence, not record
exports. Full backups necessarily preserve real data locally inside ignored,
HTTP-denied storage; the original hash and counts remain private.

## Access and retention

Account security shows only the signed-in staff member's name/username/role,
masked verification email and remaining recovery-code count. It never returns
password/recovery hashes or redisplays old codes. Submitted current/new passwords,
confirmation and recovery codes are excluded from validation flashes. Password
changes write only hashes, forced-logout flags and minimal audit field names;
no email delivery, external password check, employee edit or new schema is used.
Linked identity changes remain inside the verified same-server transaction.

Native public catalog queries select product/category fields only. Current
Laravel private disk serving is disabled and personnel files are not exposed
through a generic path proxy. Public product images require an active product,
allowed directory and image MIME. Sensitive office document extensions are
denied even under public assets. Workforce downloads will require explicit
owner/role/team policies before their native port is considered verified.

Legacy DSR intake, identity review, response expiry and token delivery remain
available. No applicant purge, retention delete or anonymization job ran.
Earlier 365-day/five-year statutory descriptions were not legally verified and
must not control automatic deletion. Document policy owner, periods, holds,
dry-run and permission evidence before introducing retention execution.

See [security](SECURITY.md), [database adoption](DATABASE.md),
[parity](PARITY.md) and [documentation maintenance](DOCUMENTATION_MAINTENANCE.md).

## Recovery, local evidence and availability additions

First verified email enrollment generates eight recovery codes, returns them
once through a no-store staff page and stores only password hashes. A used
recovery hash is removed within the authentication transaction. Persistent MFA
attempt/lock counters contain no entered codes. Session revocation cache keys
contain domain/user ID and random versions, without names or credentials.

Browser testing stores no traces or videos. Staff screenshots contain only
synthetic fixtures; earlier real public screenshots contain public product
information. Current tests use a newly initialized local MariaDB data directory
with no real records. The failed shared-server startup's diagnostic is recorded
as an error category only; no system privilege records were read or disclosed.
Connection-failure pages disclose no schema, SQL, record values or credentials.
Inherited mixed image storage is denied direct HTTP access under public/; only
active-product ID delivery from allowed image folders passes actual MIME checks.
A dedicated test router avoids development-server static-file bypasses. Test
cache/session/lock directories are separated from staff runtime state. Recovery
remains available after mail delivery failure without changing the verified
email or bypassing the second factor. Synthetic image fixtures are removed after
tests and contain no real image/document content.
Manual discount requests retain cashier ID, monetary quote, optional business
reason, status/decision actor/time and rejection reason in the existing table.
Native access is own-cashier or authorized Finance/Admin, with no employee record
join or external message. Minimal audit records field names rather than reason
contents. Checkout now discards unused senior/PWD ID, cardholder and approval
inputs for ordinary/manual-approved sales before hashing or storing the sale.
No historical discount/customer record was modified during this change.
Product labels contain public product name/category/price and the existing
barcode or public product-page URL. Local SVG generation uses no cloud service,
tracking token, employee/customer record or camera upload. Staff print responses
retain no-store handling. Synthetic decoder/PDF artifacts stay in protected local
test storage; shareable screenshots contain synthetic product fixtures only.
Native public product details now expose only approved product descriptions,
label/ingredient/allergen/storage fields and recorded nutrition. The SQL review
filter excludes all unverified states and internal staff/rejection metadata.
Nothing is fetched from source links automatically. Comparison and related-item
queries read POS catalog data only, with no HR/customer join, new public tracking
record, external AI request or nutrition inference. Synthetic seeded facts do
not represent a real product verification or research result.

The native product-code resolver selects public product IDs only, emits no scan
analytics/customer/employee record and makes no third-party lookup or camera
request. Lookup responses use no-store. Submitted codes still appear in browser
history/access URLs because this is a read-only GET; enter product/store codes
only. Normal session/rate-limit middleware remains. Existing scan-insights and
private-kiosk processing are not enabled by this port.

Product discount requests keep POS requester/decider IDs, product/price/discount
snapshots, business reason and state/times in the established table. Inventory
Staff sees own history; Finance/Admin can review all. Native lists query no
HR/customer/password/signature/contact fields. Public catalog receives approved
product prices, never request reasons or actor IDs. Audit stores changed field
names only, without reason values. No external message/lookup or retention delete
is introduced; rejected/cancelled request history is retained.

The native information workspace reads product facts and review state for
authorized Inventory Staff/Admin, not customer/personnel data. It displays no
private photo filenames or raw AI/review snapshots. History selects ten minimal
action/source/state/time entries. Product audit keeps public field projections;
unsafe/credential-bearing source URLs are omitted from new copies and display,
without rewriting old records. Revision hashes expose no raw application key or
snapshot payload. Draft edits remain in browser memory only; no local/session
storage persistence or external AI/source fetch occurs. Screenshots use dedicated
synthetic editors/reviewers and fake product information.

Malformed route/body identifiers now stop before controller or operational record lookups as applicable, without a SQL/schema detail response. Normal authentication remains required for staff routes. Uncategorized normalization changes no personnel/customer field or retention behavior. All new regression records and browser products are synthetic.

The native Personal information page displays only the signed-in account's
explicit employee link, safe employment context and editable contact/personal
fields. It does not query salary, bank details, government identifiers, character
references, private filenames or documents. Error redirects do not flash personal
values/current password. Minimal audit stores actor/domain, employee identifier
and changed column names, never old/new contact values or proof. No localStorage,
external service, mail, phone verification or new retention/purge path was added.
Contact email is repeated and password-confirmed but not delivery-verified; it
does not rebind the enrolled sign-in address. Existing unknown phone and option
values can be kept unchanged. Screenshots and committed fixture records are
synthetic; no real profile was copied or mutated for this feature.

My schedule projects only own employee eligibility, weekday/date and shift name/time/activity. It does not select colleague/original employee identities, swap reasons/approvers, private contacts, compensation, government IDs or files. Staff pages retain no-store headers. No analytics, external request, new retention, export, business audit or client storage is added by this read-only page; synthetic fixtures/screenshots contain no real personnel data.

My leave exposes only the actual linked employee balances and reasons/history. SELECT projections exclude other employees, approver identities, payroll, bank/government IDs and documents. Reasons are escaped and bounded; HTML form/service/filter errors discard old input rather than placing private reasons in session. Minimal submission audit stores field names, not reason/date values. No email, external request, attachment, analytics or retention job was added. The UI asks for a brief reason and directs medical documents to HR private handling. Existing approval visibility stays in the legacy system until its separate port.

Native leave queues display employee name/code and request reason/dates/state only within the current HR/team decision scope. Highest current linked role resolves ambiguous accounts conservatively; absent HR accounts follow the existing regular-employee fallback. Private contact, bank, government-ID, salary and file columns are excluded. Team/reviewer query inputs cannot broaden visibility. Reasons are escaped and included only in an opaque keyed revision, never audit values, old input, external requests or screenshots of real people. Current-role/team scope is not immutable historical requester-role provenance. New HR log notes are static and contain no medical/private reason.

My overtime selects only own request reason/dates/state, schedule type, matching date/time punches and recurring shift times. It never selects GPS latitude/longitude/accuracy/address, compensation, bank/government IDs, contacts, approver identities or private documents. A supervisor-exists boolean gives a routing notice without colleague details. Private reasons are escaped and excluded from audit values and error-session input. No external service, geolocation permission, mail, analytics, client storage, retention or payroll job was added. Captures and location markers are synthetic.

Overtime review displays only current assigned-team employee name/code, request reason/date/hours/state and matching punch/shift evidence. Neither queue nor decision queries select GPS, accuracy/address, compensation, bank/government IDs, contacts or files. Scoped raw evidence is represented by an opaque keyed revision, never an audit payload or error-session input. Audit records actual HR actor, request identifier and changed field names only. No mail, geolocation request, export, analytics or retention feature was added. All browser captures and concurrency fixtures are synthetic.

## Original UI migration evidence, 2026-10-26

Original-style visual checks use only synthetic public products. Source is read
as text; no original PHP configuration executes and no AcademiSync records or
private files are copied. Plus Jakarta Sans/Fraunces are served locally with OFL
notices, so these restored pages do not request a Google Fonts/CDN resource.
Catalog projects public fields/category aggregates, never costs/personnel data.
Product image URLs use active IDs, without raw stored filenames. Existing staff
auth/MFA and private domain boundaries remain; session bridging is not claimed.

## Product original-UI evidence

Detail/reference browser captures use synthetic products only. The native public
request remains read-only and selects no personnel/cost fields; original scan/IP
logging is not copied as a hidden presentation side effect. Source URLs retain
credential suppression and noreferrer. Protected image URLs reveal active IDs,
not stored filenames. Private sessions/analytics remain separate pending ports.

## Comparison source-style evidence

Comparison reads public verified product fields only. Synthetic checks assert
no HR/personnel/cost reads or writes. Its restored page uses the source's actual
fallback fonts with local theme assets and makes no font/CDN/external request.
Screenshots contain synthetic products. Numeric-minimum coloring is source
formatting and does not establish a health/dietary assessment of products/users.
