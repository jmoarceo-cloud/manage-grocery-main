# GroceryOne security controls and verification limits

Updated 2026-10-26 for Laravel 13.38.0. The earlier [audit](AUDIT.md) describes
the starting point; its retired root MVC weaknesses are not descriptions of
the new router. Full migration/security coverage remains incomplete.

## Public source boundary - 2026-10-26

Public GitHub publication uses a separately prepared source snapshot with clean
history. Private environment variants, backups, credentials, local migration
markers, uploads and runtime state are excluded even if earlier local commits
tracked them. Export-only replacements remove local account-setup values; the
live working scripts are preserved. Configured-secret matching and redacted
Gitleaks checks precede upload. See [publication notes](PUBLISHING.md) and
[actual outcomes](PROGRESS.md); publication does not establish full security
coverage or change application authentication.

## Original PHP startup repair - 2026-10-26

The requested local repair restored two shared MariaDB system tables after an
ACL-restricted offline recovery copy and candidate checks. All 14 database
permission rows match the candidate; four intact rows were compared unchanged
and 13 account identities remain. Password/account files, web roles and business
table files were not replaced. Private recovery scripts verify the target
directory and remain outside HTTP/Git; they are not routine setup commands.

Legacy connections accept DB_PORT and use three-second PDO connect options.
Public bootstrap requests also set mysqlnd.net_read_timeout=3, covering a
stalled greeting and subsequent public connection reads. Their existing generic
503 response remains; no debug/credential output was added. POS/HR-only query
read settings, guards, sessions and transaction code are unchanged.

## Implemented in Laravel

- Separate POS/HRMS guards retain canonical identities and existing password
  hashes. Password success creates only a pending challenge. Email OTP expires
  after five minutes; existing hashed recovery codes are consumed under a row
  lock. An established verification email cannot be changed with password-only
  access. New setup verifies the entered address before persisting it.
- Login throttling is five failures per account/IP in fifteen minutes; MFA
  sends/verifications also have limits. Generic errors avoid account-detail
  disclosure. Account/employee status, current lockouts, suspension, forced
  logout, password signature and thirty-minute idle expiry are checked.
- Session IDs/tokens regenerate on authentication and logout. The separate
  cookie is HttpOnly/SameSite and scoped to the app path; local HTTP is not a
  claim of HTTPS transport. No remember-token column is assumed.
- Browser mutations use Laravel request-forgery protection. Staff and role
  middleware protect operational routes. Cashier cannot access Admin/catalog
  management or direct restocking. Receipt access checks ownership or a
  permitted reporting role. POS actor IDs are resolved across domains safely.
- FormRequests and service validation reject client prices, actor IDs,
  malformed quantities and excess tenders. Checkout locks products/batches and
  writes sale/details/allocations/logs/audit plus a durable UUID record in one
  transaction. Failed writes roll back; repeated UUIDs cannot repeat the sale.
- Refunds require verified supervisor/admin credentials, remaining-quantity
  checks and original-batch restoration. Inventory Staff cannot bypass Finance
  via direct restocking; the preserved approval workflow stays available.
- The web DB identity has CRUD only, with no privileged fallback. Explicit
  migration credentials are private and separate. Full backups/snapshots,
  nonpublic siblings and dotfiles are denied over HTTP and excluded from Git.
- Private disk direct serving is disabled; no public private-storage symlink
  was created. Public product image delivery uses fixed product IDs, bounded
  directories and verified image MIME. Native personnel downloads/uploads are
  not yet ported; the unsafe old root file proxy is no longer loaded.
- Blade escapes values; cart strings use textContent. Headers include nosniff,
  frame/referrer/permissions policy and CSP report-only. Exceptions do not
  expose detailed SQL; sensitive password/OTP fields are excluded from flashes.
- Minimal audit events store actor/entity/action/changed field names without
  secret or before/after personal values. Original stock-log protection
  triggers remain. Audit retention/complete observer coverage is still pending.

## Verification and remaining work

Native Account security requires an already verified staff session plus the
current password or a one-use recovery code. New passwords require confirmation,
at least 12 characters, mixed case, a number and a symbol, no control bytes and
at most 72 bytes for bcrypt. Five failed credential proofs block further attempts
for 15 minutes per domain/account. Target IDs, domains and roles are prohibited.
Linked identities must have the exact username, compatible roles and matching
employee IDs (or both be administrators without employee links). Status and
canonical-domain checks run again under locks. One qualified-schema PDO
transaction updates both password hashes, forced-logout flags, consumed recovery
hash and field-name-only audit; failures roll back together. Different servers,
credentials or non-InnoDB user tables fail closed. Success ends the current
session and revokes existing native sessions in both affected domains. This
does not establish revocation of every preserved legacy PHP session.

The recorded suite covers password-only denial, OTP completion, email-rebind
prevention, inactive accounts, throttle, forced logout, cashier role gates,
CSRF rejection, price/quantity tampering, transactions/retries/expiry/refunds,
schema contracts, restock approval boundaries and stock-log immutability.
Exact current counts/commands are in [TESTING](TESTING.md) and [PROGRESS](PROGRESS.md).

No real mail, live financial transaction or personnel upload was exercised.
Cross-browser, every-role, password-reset, comprehensive account recovery,
full privacy/retention, private-download and all legacy workflow coverage remain
open in [PARITY](PARITY.md). Filename/secret-pattern checks are bounded and do
not establish comprehensive secret scanning, malware safety, legal compliance
or release readiness. Historical [security draft](archive/2026-10-26-pre-audit-SECURITY.md)
claims are not evidence.

## Current verification additions and availability

Synthetic tests now verify genuine concurrent checkout and duplicate requests,
one-time recovery consumption, hashed first enrollment, persistent five-attempt
MFA locks and a fresh attempt window after lock expiry. A cached per-domain/user
session version prevents a later successful login from reviving a session that
predates force_logout. Password-signature and status checks still apply.
Revocation versions use protected file cache in this single-host installation;
cache deletion generates a fresh version and invalidates older sessions rather
than reviving them. Multi-host deployments require
shared durable session/revocation storage before verification.

Same-day void is owner-only, requires completed status and no refund records,
and preserves the original sale and immutable log history. Native DB connection
errors use generic no-store 503 responses; internal SQL/driver diagnostics do
not appear in that response. Connection attempts are bounded to three seconds.
The shared MariaDB privilege-table corruption is unresolved; changing its
system tables is outside the project's file boundary. Synthetic testing uses
a separate localhost-only project data directory, with private scoped accounts.

Direct Apache access to the inherited mixed public/assets/images tree is now
403; product images are delivered only through validated product IDs and MIME.
The test HTTP router exposes only native build assets/favicon/robots as static
files, then dispatches other URLs through Laravel. It does not rely on .htaccess,
which PHP's development server ignores. Browser checks deny dotfiles, manifests,
CLI paths and mixed image paths; feature checks reject inactive/traversal/fake-MIME
product images while allowing a synthetic valid PNG.

Automatic mail throttling/transport failure now retains the unauthenticated MFA
challenge so valid recovery codes remain usable; it never grants staff access.
Enrolled accounts can request a fresh code to their existing verified address.
Testing cache/locks and session files use separate testing subdirectories, so
test rate-limit or revocation state does not share the staff runtime store.
## Native manual discount decisions

Cashier requests accept only positive item IDs/quantities and bounded discount
values; server prices determine the quote. A cashier-row lock serializes native
submissions and prevents more than one pending native request. Finance/Admin
alone decide locked pending rows, with the actual mapped POS actor ID/time.
Cashiers can cancel only their own pending requests and view their own history.
These checks do not change the preserved legacy handlers. Checkout separately
checks ownership, matching subtotal, approved status and unused native linkage.
New requests do not reserve inventory. Existing approved legacy requests do not
have a retroactive native usage history; comprehensive historical reconciliation
remains open. Normal/manual-approved sales discard irrelevant statutory identity
fields before durable hashing and persistence.
Native label GET/POST requires Admin/Inventory Staff; POST retains web CSRF.
Selection permits 1-96 distinct positive IDs and rejects missing/inactive rows.
Labels select only public product fields and current discount metadata, never
cost/supplier/personnel data. Blade escapes names/barcodes. Raw SVG is generated
only by the digit/checksum-validated EAN encoder or the local QR writer, which
encodes the configured public product URL. User-supplied QR payloads/paths are
not accepted. Label requests do not mutate stock, prices or identifiers.
PublicProductFacts filters active products and review_status=verified in SQL and
selects an explicit public field list. Draft, AI-filled, needs-review and rejected
content never enters public view data. Reviewer/updater IDs and internal rejection
notes are excluded. Nutrition source links permit valid HTTP(S) without embedded
credentials; unsafe protocols are suppressed and outbound links use noreferrer.
Comparison IDs are distinct positive integers, capped at three, and unavailable
selections reject as a group. Product/image route IDs are positive and at most
ten digits, avoiding numeric overflow dispatch. New public pages are throttled.

Public product-code lookup validates nullable/3-64-character ASCII codes before
queries, rejects arrays/embedded controls/links and applies60 requests/minute.
Empty and invalid requests perform no catalog query. Active-only ID resolution
uses bound values and at most two indexed lookups. Supplied return/URL parameters
cannot control redirects. Unknown and inactive products share the same state.
Public access does not grant staff, camera or private-kiosk authorization.

Native product discount submission is Inventory Staff/Admin; decisions are
Finance/Admin. Inventory history/cancellation is own-user only, with Admin-wide
cancellation. Current actor role/activity/locks and pending state are rechecked
under row locks. Server-derived snapshots prevent client quote/actor/status
tampering. Approval also rejects changed price/current discount/reason and
inactive/expired products. Fixed reductions must be positive and below price;
percentages are positive through 100; removal uses zero. Catalog writes prohibit
discount fields. Reasons are Blade-escaped. Decision/product/audit failure rolls
back together. Admin retains legacy request-and-decision permission; this does
not establish a new mandatory two-person control. Legacy handlers do not share
the native locking protocol.

Manual product information editing/submission requires Inventory Staff/Admin;
verification/rejection requires Admin and needs_review. Role/activity/lock state
is rechecked under transaction locks. Server review metadata cannot be posted;
decisions cannot smuggle new description text. Edits of verified facts revoke
their public visibility. An HMAC revision binds product/row/provenance, protecting
against lost updates and stale approval of changed nutrition or source context.
HTML conflicts clear old_input so stale text is not paired with a fresh revision.
Web-derived/latest AI web-field records cannot be verified through this editor;
malformed provenance also blocks publication. Label-derived ai_filled data may
be submitted for Admin review, with no automatic verification. Staff output is
escaped; source references omit unsafe protocols/embedded credentials. Browser
dirty-state disabling complements server checks and is not an authorization
control. Admin retains existing self-review permission; no independent-review
policy, automated fact accuracy or complete AI/photo workflow is claimed.

All native numeric {id} routes now reject zero/negative/noncanonical/oversized
values before typed controller invocation. Seven malformed forms across all14
ID-bearing route templates produce404 without record SQL in the synthetic tests.
Body IDs for checkout/refunds/approvals/labels/restocks/suppliers also enforce the
existing positive signed integer range. Restock/supplier rules bail before an
exists query when ID validation fails. This does not bypass or replace normal
MFA, account status, roles, ownership, CSRF or production authentication queries.
The previous 30-digit receipt TypeError is reproduced and fixed, not hidden by
a generic exception catch.

Own employee personal updates require the normal MFA/status/session gates plus
current-password proof (five failures/15 minutes). Target IDs are absent; the
current locked actor's employee_id must match the authenticated model and the
safe employee row must remain eligible. A keyed revision rejects stale or
reassigned forms. Explicit fields prohibit role, status, job, payroll and identity
changes. Repeated contact email is not sign-in enrollment or verified delivery.
Known safe personal columns only are selected/written; transaction/audit failure
rolls back. HTML errors clear old input rather than storing personal values or
proof. The concurrency worker is CLI-only and verifies the marked synthetic pair
before fixture access. Tests cover all six HR roles, other POS roles, differing
user/employee IDs, password limits, stale links, audit failure and native email
contention. Legacy profile/session implementations remain separate.

GET /account/schedule uses the authenticated POS/HR model and explicit employee_id only, after normal MFA/status/session checks and an eligibility recheck. All ten native roles have the same own-record boundary; Admin has no cross-employee override. Target/date query parameters cannot select records, and POST is405. Tests prove distinct numeric IDs, missing/terminated/suspended linkage denial, escaped labels and no attendance/payroll/write queries. The screen does not authorize clock-in.

Own leave requires normal MFA/session/status and explicit employee ownership; POST rechecks the locked current actor/link/employee and refuses unsupported topology or nontransactional HR tables. No target or caller-supplied approval/credit/status/timestamp is trusted. Real calendar dates and storage bounds prevent date coercion into SQL. All ten native roles share vacation cap/cooldown validation, closing the legacy HR-profile omission. Injected audit failure rolls back all native writes; independent POS/HR workers create one identical pending request/audit. This is not a guarantee about legacy handlers or completed/reason-changed retries. Native approval and cancellation are not exposed by this port.

Native leave review requires the actual HR guard/domain plus Admin/HR Manager/
HR Staff/supervisor role. POS Admin does not inherit HR audit identity. Highest
linked requester role avoids arbitrary join authorization; current team/role
scope is applied to queues and rechecked under write locks. Non-Admin self
decisions are denied; Admin full scope is retained from legacy, not claimed as
a two-person policy. Request revisions bind reviewer, reason, dates and state;
stale/cross-request/cross-reviewer proof fails. Body values cannot set actors,
status, credit or request fields. Outside-scope IDs return404 without selecting
their reasons. Inactive/terminated/suspended targets can be rejected but cannot
receive a new approval. HTML errors omit private old input. Real process races
and injected audit failure verify bounded native atomicity; no legacy-wide
concurrency or complete authorization audit claim. All15 numeric-ID templates
now have seven malformed-form regression cases.

Own overtime requires normal MFA/status/session plus current locked actor and employee eligibility. It never infers employee from user ID or accepts an on-behalf target. Dates are real nonfuture calendar dates within DATE storage; hours reject exponent, overflow, leading-zero and excess-precision input before SQL. Status/actor/actual-work fields are prohibited and server-derived. HTML form/service/filter failures clear private old input. Audit failure rolls back the pending write, and distinct POS/HR worker processes prove one normalized identical pending request/audit. Approval and payable overtime are not granted by submission or display.

Overtime approvals require actual HR Admin/supervisor, current team membership and normal MFA/status/session. Unassigned rows remain outside this source module even for Admin; supervisor self-team misuse is denied. SQL scope protects private reasons before listing and is rechecked under locks before decisions. Proof binds reviewer/request/raw work evidence, catching even second-only changes. Role/target/status/hours/reason/actual-work body tampering cannot change authority or work records. HTML errors omit private old input. Invalid legacy values/ineligible targets can be rejected but cannot be approved. All16 ID-route templates now have seven malformed-form checks; no broader legacy authorization or concurrency assurance is claimed.

## Original UI port boundary, 2026-10-26

Source-derived home/login/catalog templates preserve presentation while using
Blade escaping, Laravel CSRF, existing staff authentication and bounded inputs.
Legacy configs/bootstrap are never included. A private catalog flag hands off
to the original PHP guard and cannot create a Laravel private session. Original
basename images pass active-product checks, allowlisted realpath resolution and
actual JPEG/PNG/WebP MIME validation; path names are not exposed in template URLs.
The old permanent-account-lock popup is not copied into differing native auth
policy. Testing-only source-presentation references require testing environment
and nonce-verified targets; their static/allowlisted text cannot run bootstrap,
includes or arbitrary changed PHP. This is bounded testing, not a security audit.

## Original product UI guards

The restored detail continues active-product lookup, verified-only projection,
native stock/price calculations and MIME/path-checked ID image delivery. Text is
Blade escaped; multiline output applies e before nl2br. Source URLs retain the
native HTTP(S)/credential validation and noopener/noreferrer. Known return names
resolve to native protected routes, with no user-supplied external redirects.
Array/oversized return parameters are rejected. A testing-only fingerprint-pinned
reference renders original presentation PHP with synthetic public data; original
config/bootstrap/query and scan-log code are excluded. No auth policy changed.

## Comparison UI boundary

The restored original table keeps native active/verified and basis/category/
matching-serving validation, bounded IDs and Blade escaping. Numeric-minimum
formatting operates only on returned verified values and excludes NULL/empty.
The testing-only reference pins original source before extracting presentation;
config/bootstrap/original query and client-redirect code are excluded. No new
authentication/authorization or business-data write path is introduced.
