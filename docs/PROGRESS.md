# GroceryOne work and verification log

Updated 2026-10-26. This log records work, evidence and limits. The current
[audit](AUDIT.md) establishes the migration starting point; the existing
[system map](../project/HCI_SYSTEM.md) remains the legacy workflow reference.

## 2026-10-26 - Requested public GitHub source publication

The owner requested public source publication and supplied
`jmoarceo@pcu.edu.ph` as the author/contact address. Read-only Git Credential
Manager authentication confirmed the existing GitHub account
`jmoarceo-cloud`; the target repository name was unused at that check. No
credential was printed or persisted by the publication tools. The API did not
provide email-verification status, so verified email ownership is not claimed.

Prepared a complete README describing the actual system, separate Laravel and
original PHP entry paths, feature coverage, setup, documentation and limits.
Preserved the previous README under docs/archive. Added docs/PUBLISHING.md and
private-artifact ignore rules. The original working tree has existing runtime
and UI work; it is retained. Publication uses a separate filtered snapshot and
clean Git history, not a push of unaudited original commits.

Initial source inspection: 745 tracked paths; four private environment files
were inspected in memory for 17 nonempty secret values. No selected tracked
source contained those values. This is an exact-value check, not a complete
secret audit. Identified a tracked avatar and local migration markers for
exclusion; no private upload content was read. Gitleaks 8.30.1 was downloaded
from its official release and its archive SHA-256 matched the published checksum.

Published https://github.com/jmoarceo-cloud/manage-grocery-main publicly on
`main`. Initial source commit:
`fc6bc9ed8db4481c80cfcf575c417decb9533140`, authored by John Mark O. Arceo with
the supplied email. This separate repository contains 771 files (about25 MB),
both application sources, documentation and synthetic/source-derived evidence.
The original repository's HEAD/index/history/remotes were not changed by the
publication workflow. Existing runtime work remains in place.

Exact meaningful publication checks:

- `storage/tooling/node/node-v24.22.1-win-x64/node.exe storage/publishing/prepare-public.cjs verify`:
  771 files;17 configured-secret checks; zero forbidden paths, known-secret
  matches, data statements in the four schema-only artifacts or broken links
  in README, PUBLISHING and the documentation index.337 original source-hash
  entries were checked for source-file-only TSV format before copying.
- `storage/tooling/gitleaks/v8.30.1/gitleaks.exe dir storage/publishing/groceryone-public --redact=100 --no-banner --report-format json --report-path storage/publishing/gitleaks-working-tree.json --log-level error`:
  exit0, no findings. The equivalent `git` scan with report
  `storage/publishing/gitleaks-history.json` also exited0 with no findings.
- `php -l` passed for exported `project/HRMS/create_admin.php`,
  `project/HRMS/reset_password.php` and `project/HRMS/includes/mailer.php`.
  Only generic setup values/comment examples differ in these exported files;
  local originals are unchanged. Syntax checks did not execute their includes.
- `php storage/publishing/groceryone-public/bin/check-docs.php --staged` and
  the configured pre-commit documentation hook passed. Scoped
  `git diff --check` passed after correcting two README line breaks.
- `git check-ignore --no-index` confirmed all nine synthetic private-path probes
  are ignored, including nested environment variants, uploads and backups.
  Explicit export filtering also removes already-tracked private paths.
- `git push --set-upstream origin main` succeeded. Anonymous GitHub REST
  requests confirmed `visibility=public`, `private=false`, default branch
  `main`, the exact source commit,771 blobs, the supplied author email and
  README contact. Recursive tree inspection found zero forbidden paths; the
  repository page returned HTTP200 without authentication.

Limits: automated scans cannot prove absence of every sensitive string. Public
history starts clean rather than exposing unaudited old commits. No original
private upload was opened or uploaded. No runtime migration, seed,
business-record query, Gmail message or app deployment was performed. Existing
native tests are prior results, not publication-time reruns. The complete
Laravel/original-interface migration remains unfinished. Later documentation
commits record publication outcomes without changing application behavior.

## 2026-10-26 - Original PHP loading stall fixed

The owner explicitly requested inspection and repair of the continuously
loading `/project/index`. The cause was shared MariaDB startup failing before
connection servicing: TCP accepted but no MySQL greeting was sent. PHP's public
bootstrap had no bounded network-read wait and rendered nothing while blocked.
Offline checks confirmed corruption in `mysql.db` and `mysql.time_zone_name`.

Recovery performed:

- Created an ACL-restricted recovery directory outside the web root, containing
  a precautionary pre-stop copy and a stopped-server copy. The stalled startup
  had no graceful shutdown event; terminated that exact verified process.
  An unrelated synthetic instance appeared on port 3307 and was left running.
- Verified SHA-256 for all **1,774 files / 192,201,166 bytes** of the stopped
  copy. Read-only checks covered 24 system Aria tables. Quick repair of the
  permissions table found zero rows; safe/extended trials found four of the
  header's 14. No incomplete trial was applied to the live directory. Log
  replay on another copy did not recover the missing entries.
- Started a private candidate on loopback port 3310 after table repair.
  Reconstructed exactly 10 missing GroceryOne permission rows from the existing
  prepare/adopt scripts and private environment identities, without creating
  accounts or changing passwords. All four intact permission rows and account
  identities were compared unchanged. Candidate total: **14 permission rows**.
- Shut down the candidate normally; prepared the two repaired tables for
  transfer and checked both successfully. Before application, reverified all
  1,774 original file hashes. Installed only four system-table files (MAI/MAD
  for the two named tables); no business table, account or password files were
  replaced. Restarted normal MariaDB with its existing my.ini on port 3306;
  it logged ready for connections. No global PHP/Apache/MySQL config was edited.
- A metadata-only live check verified the exact original datadir, all 14
  permission rows against the candidate, 13 existing accounts, and both legacy
  schema connections with `SELECT 1`. It read no business rows.

Legacy PHP changes: DB_PORT support (default 3306) and three-second PDO connect
options in the public/POS/HR bootstraps. The initial synthetic stalled-greeting
test exceeded six seconds despite PDO::ATTR_TIMEOUT; corrected the public
bootstrap with mysqlnd.net_read_timeout=3. This also bounds network reads for
queries on public connections. POS/HR-only requests retain their existing read
settings; their connect option alone does not establish a handshake-read bound.
No role, session, approval, transaction or business-schema logic changed.

Exact final checks:

- `C:\xampp\php84\php.exe project\tests\database_connection_timeout.php`:
  **3 passed**, stalled-greeting failures in **3.12 / 3.08 / 3.07 seconds**;
  public catalog failure status 503. Listener ports are ephemeral loopback,
  credentials/schema names synthetic, no database server or SQL statements used.
- PHP 8.4 lint of the three modified bootstraps and new timeout probe:
  **4 passed**. `project/tests/consumer_layer_smoke.php`: **OK** (source contract).
- `Invoke-WebRequest -UseBasicParsing` after final PHP changes:
  original `/project/index` **200 / 0.361 s**, `/project/catalog`
  **200 / 0.344 s**. Before PHP changes, repaired homepage **200 / 0.206 s**
  and landing stylesheet **200 / 0.072 s**. Response bodies were not logged.
- Standalone Chromium via `storage/testing/legacy-startup-smoke.mjs`:
  homepage **200**, visible main heading, DOMContentLoaded **0.641 s**;
  inspected screenshot at `storage/testing/legacy-startup.png` confirms rendered
  original storefront. These ignored local artifacts contain public catalog
  content, no private staff/customer records.
- Scoped `git diff --check` passed; recovery ACL inheritance is blocked and
  its directory is outside the web root. Candidate port 3310 is closed; Apache
  port 80 and normal MariaDB port 3306 remain listening.

Limits: availability/rendering and bounded public connection errors only;
no authenticated POS/HR workflow, real login submission, email, sale, payroll,
retention action, migration, seed or business-data import was performed. Public
requests can update their normal rate-limit bookkeeping. The local recovery
copy includes private server data and remains ACL-restricted outside HTTP/Git;
it is not a new test fixture, complete cross-project restore drill or release
acceptance. Previous outage/hash-preservation notes below are historical: this
authorized repair intentionally updates three legacy bootstrap files.

## 2026-10-26 - Original PHP application startup request

The owner clarified the requested target is the preserved PHP application:
`http://localhost/manage-grocery-main/project/index`. The preceding startup
check below targeted Laravel and does not establish availability of this URL.

`Get-NetTCPConnection -State Listen` now confirms Apache port 80 and shared
MariaDB port 3306 have listeners. However, `Invoke-WebRequest -UseBasicParsing`
to the exact original URL timed out after 35.13 seconds without an HTTP status.
A separate TCP check connected to 127.0.0.1:3306 but received no MySQL greeting
header within three seconds; no authentication or record queries were sent by
that check. A listener therefore does not establish a usable database service.
The recent MariaDB log reaches socket creation without a ready-for-connections
line; this does not establish the cause of the current stall.

`Start-Process -FilePath` for the exact original URL was rejected before
execution with `blocked by policy`, so browser opening was not confirmed.
No runtime/configuration changes, database repair, migration, seed or business
transaction was performed. The page request used a fresh unauthenticated
client and its body was not logged. Original-app availability remains blocked
pending database startup recovery and an HTTP recheck.

## 2026-10-26 - Requested local startup check

Requested: run the existing system at this workspace. Apache was already
listening on port 80. `Invoke-WebRequest -UseBasicParsing` confirmed native
`/public/login` HTTP 200 (Staff sign in), `/public/up` HTTP 200, and
`/public/` HTTP 503 (12.29 seconds). `Get-NetTCPConnection -State Listen`
confirmed that shared MariaDB port 3306 is unavailable; the separate synthetic
instance remains on 127.0.0.1:3307. The existing shared-server error log also
records Aria recovery failure and startup abort; no repair was attempted.

The command requesting hidden MariaDB startup and opening the sign-in URL
was rejected before execution with `blocked by policy`. Neither process launch
was confirmed. The sign-in URL can be opened manually at
`http://localhost/manage-grocery-main/public/login`. Database-backed use remains
blocked pending shared-server recovery. No runtime/configuration edits,
migration, seed, synthetic-data substitution, login submission or business
transaction was performed. These availability checks do not verify login or
workflow success.

## 2026-10-26 - Original nutrition comparison presentation restored

Continued after a13ecb4 under the original-style-only scope. Read
nutrition_compare.php as text and traced verified active products, three picks,
source table values, measurement/category checks, theme and minimum-cell styling.
Converted its document to Blade using identical inline CSS and original SVG/theme
script. Preserved its actual Arial/Georgia fallbacks: the source declares web
font family names but does not load them. No typography redesign was added.
Original per-row numeric minima are green, NULL/empty remain Not listed, zero
stays numeric, a single product displays its label table and unknown-only rows
receive no fabricated minimum. This restores a source display behavior; earlier
native no-ranking wording referred to the superseded view. No dietary study,
health recommendation or unit conversion is claimed from minimum-cell colors.

Existing native verified/active/category/basis/matching-serving guards remain,
along with bounded numeric ids[], selected product retention (including outside
the first100 choices), escaped data and keyboard scrolling. Original pickN/client
redirect handling is replaced by the existing native GET form. Current selected
picker values intentionally remain; they were not included in source-pixel proof.
No controller/business-data write, schema, authentication policy or legacy PHP
runtime file was changed. Other staff/MFA/scanner/private workflows remain open.

Checks: 216 PHP tests / 8,662 assertions; 17 Chromium scenarios passed, 1 legacy baseline skipped; 366 PHP files linted; 58 production routes. Two OriginalComparisonUiTest cases exercise minimum
zero/NULL/one-known-value behavior, single-product output, selected state and
public no-write/no-cost/no-personnel query boundaries. OriginalUiTest now proves
the comparison CSS byte-copy; existing facts/basis/serving/selection cases remain.
Focused PHP checks passed18 cases/148 assertions. Vite and Blade cache passed.
Eight Chromium comparisons passed the unchanged0.2% limit for pixels differing
by more than eight channel values. Observed maximum: 4 differing pixels (0.001215%); 7 of eight captures had zero differing pixels. Initial viewport plus selected
table, desktop1440/mobile390 and both
themes. The source reference is fingerprint-pinned, uses native synthetic public
data, removes the legacy client-redirect branch and excludes config/bootstrap/
original queries. Browser selection/form behavior, overflow and script errors
are checked. Screenshots were inspected. Full suites used guarded clean synthetic
3307 targets, with mail isolated and the legacy baseline explicitly skipped.
Evidence: audit/ORIGINAL_COMPARISON_BROWSER.json, ORIGINAL_UI_SOURCE.json,
NATIVE_TESTS.json, NATIVE_VALIDATION.json and UI_SMOKE.json.

Limits/next: visual proof covers the initial viewport and result table, not a
selected full-page picker or independent original SQL. Remaining staff/MFA/code
lookup/private/scanner screens still need original presentation and workflow
restoration. Live3306 availability, original scan analytics and full migration
parity remain open. Preserve the separately authorized bootstrap/startup edits,
original backup and remaining legacy runtime; continue without redesign or new
features. No release/cutover or deployment-readiness claim follows.

## 2026-10-26 - Original product-detail presentation restored

Continued after ce8447b under the owner's stack-only migration scope. Read
product_detail.php as text and traced active public products, effective price,
verified-field projection, related selection, return contexts and scan logging.
Converted the original document to Blade. Original CSS is unchanged except that
the one dynamic stock color uses an equivalent CSS custom property; its mapping
and source fingerprints are recorded. Original theme/SVG/type/layout remain.
Known admin/inventory/cashier return contexts use native protected routes;
arbitrary return URLs fall back to catalog. Product search uses q for native
management and search for catalog. Related products now include the original
empty-category matches, excluding self and unavailable rows, up to four.

Native active-only availability, server discount pricing, verified-only public
facts, safe source URLs, escaped multiline text and protected image delivery
remain. Restored the original nutrition presentation: zero renders as 0 and NULL
rows are omitted. Comparison retains its own unknown-value behavior. Initial
feature checks caught a Blade directive adjoining literal text; corrected it
and exercised both verified/unverified branches. One full-suite wording assertion
still expected the rejected redesign notice; it now checks the original notice.

Checks: 214 PHP tests / 8,641 assertions; 16 Chromium scenarios passed, 1 legacy baseline skipped; 364 PHP files linted; 58 native production routes. Full PHP and browser checks ran on the guarded clean
3307 synthetic pair; no live reset, product edit, sale or scan-log write ran.
Five OriginalProductUiTest cases cover verified/unknown/zero/multiline/source
rendering, no public writes/personnel/cost query, role-protected return targets,
CSS mapping and empty-category related links. Existing facts/info/code/discount/
label cases remain checked; focused run passed43 tests/333 assertions before
the full suite. Vite build and Blade cache passed. Four full-page Chromium
comparisons (1440px/390px, light/dark) had zero pixels above the eight-value
threshold against fingerprint-pinned original presentation using the same native
synthetic data/local fonts. The reference extracts presentation only; no original
config/bootstrap/query or pub_log_scan executes. Screenshots were inspected.
Evidence: audit/ORIGINAL_PRODUCT_BROWSER.json, ORIGINAL_UI_SOURCE.json,
NATIVE_TESTS.json, NATIVE_VALIDATION.json and UI_SMOKE.json.

Limits/next: no independent original SQL/availability equivalence is claimed.
Private catalog PHP sessions, scan analytics, native comparison and staff visual
restoration and other workflow ports remain pending. Shared3306 still has no
listener at the last observation; prior service-recovery samples are historical.
Separate legacy bootstrap/docs changes remain outside this phase. Continue
original presentation restoration without redesign or new-feature expansion.

## 2026-10-26 - Owner correction: original UI, Laravel stack only

The owner rejected the redesign and explicitly requires the original styling,
features and navigation. This supersedes the earlier UI-improvement scope.
Updated AGENTS, README and AI context. Read the neighboring AcademiSync Composer,
package and README files as a stack reference only; no config/data was executed
or copied. New on-behalf overtime work was hash-preserved privately (nine code
and test files) and removed from the active patch before this UI correction.

Home and login now use their original HTML converted to Blade, with identical
source CSS, SVGs, wording and interactions. Catalog uses its original sidebar,
cards, search debounce, stock badges, allergen selector and private-access modal.
Laravel handles queries, routes, escaping, CSRF, validation and existing auth.
Home restores active/positive master-stock RAND(4) and nine category chips.
Catalog restores all matching products and stock-first ordering, original base
price display and verified-label allergen exclusion; `q` remains an alias.
Native stock clamping/expiry protections remain, so complete business parity
is not inferred. Private/kiosk authorization continues in the legacy domain;
`private=1` redirects there before public catalog queries. No PHP session bridge
or supervisor/password-reset port is claimed. Native auth policies/MFA remain;
the old permanent-lock SweetAlert popup was not copied into a different policy.

Local Plus Jakarta Sans and Fraunces files retain license notices. Shared
theme-toggle/page-controls CSS and theme-sync JS are copied unchanged. The
protected image resolver now also accepts original basename product references,
while retaining active-product, allowlisted-realpath and actual-image checks.
Source was read as text; no legacy config/bootstrap executed for UI extraction.

Checks: 209 PHP tests / 8,591 assertions; 15 Chromium scenarios passed, 1 explicitly skipped; 362 PHP files linted; 58 production routes exported. The full PHP suite ran after a guarded clean seed
on the nonce-marked 3307 synthetic pair. An earlier targeted stock-log check
failed because a prior browser checkout remained in that disposable fixture;
the clean complete run passed. No live database reset was attempted. Browser
selectors now follow original wording; a theme assertion was corrected to the
original script's absence of `data-theme` in light mode, without changing it.
Vite build and Blade cache passed. All four home and four initial-login viewport
comparisons had zero pixels exceeding the eight-channel-value threshold.
These compare source-derived presentations under the same local fonts/browser,
with reduced motion, at 1440x1000 and 390x844, light/dark. Catalog interaction,
overflow and screenshots passed; no independent catalog pixel match is claimed.
Original CSS hashes and private-image/escaping/query boundaries are regression
tested. Evidence: audit/ORIGINAL_UI_SOURCE.json, ORIGINAL_UI_BROWSER.json,
ORIGINAL_LOGIN_BROWSER.json, NATIVE_TESTS.json, NATIVE_VALIDATION.json and UI_SMOKE.

Limits/next: other native product/comparison/staff screens still have the rejected
redesign and require original-presentation ports. Existing feature coverage is
partial. The shared 3306 service/outage and separate connection-recovery edits
were not changed by this phase; the legacy baseline remains explicitly skipped
after its recorded 503. Earlier successful availability samples are historical.
Continue source-traced original UI restoration, then remaining feature parity;
do not resume new features, declare final cutover or claim deployment readiness.

## 2026-10-26 - Native assigned-team overtime review

Continued after22dd8a0. Reused the traced supervisor_overtime.php and
processOvertimeDecisionForSupervisor contract: actual HR-domain Admin sees all
assigned teams, supervisor sees their assigned members, and unassigned requests
are outside that approval module. This intentionally differs from the broader
leave hierarchy. POS Admin, HR Staff/Manager, accountant and employee are denied;
no numeric-ID or automatic mirror fallback. Supervisor query parameters cannot
expand scope; malformed self-team mappings do not grant supervisor self review.
Admin keeps the existing all-assigned-teams scope. The source's on-behalf form and
specific Admin supervisor-preview selection remain pending.

Added GET /overtime-approvals and POST /overtime-approvals/{id}/decision with20-row
pending/approved/rejected queues. Safe employee name/code and request fields are
projected only after team SQL scope. Matching attendance date/time and recurring
shift evidence is batched for the authorized rows, without GPS, financial,
contact, government-ID or private-document columns. Current recurring/fallback
semantics remain clearly distinct from historical schedules and payable payroll.

Decision revisions bind the actual HR reviewer ID/role, request contents and raw
punch/shift/schedule-type evidence. Even a one-second punch change that leaves the
rounded display unchanged requires fresh review. Under one validated qualified-
schema transaction the service rechecks current actor/employee eligibility and
team mapping, locks request and evidence rows, rejects stale/cross-request/
cross-reviewer proof, and atomically commits status/actual HR approved_by/
decided_at plus minimal domain audit. InnoDB is verified for affected HR tables.
Audit values omit reasons and raw work details; injected audit failure rolls back
the decision. Terminal requests cannot be reversed or audited twice.

Approval retains the requested hours ceiling, including requests above recorded
excess or without complete punches; that is the existing informational evidence
contract, not a payroll amount. Payroll must independently cap payable overtime
at actual excess and approved ceilings. Native approval rejects ineligible
employees and legacy zero/>12-hour/future-date requests, while permitting their
rejection for cleanup. No punch, actual-hours, payroll, mail, attachment or new
table/log schema is created. Actual HR identity is recorded in the existing
application audit; no nonexistent overtime-log table is implied.

Checks: fresh guarded nonce-marked synthetic pair on local3307;
**201 PHP tests /8,526 assertions**, PHP8.4.26, no failures/errors/skips (~50.12s).
OvertimeApprovalTest has13 cases/162 assertions; a real Admin-versus-supervisor
process race adds one case/six assertions and confirms one decision/audit with no
attendance creation. Coverage includes role/team/private-reason scope, unassigned
and moved teams, actual distinct HR/POS IDs, missing evidence, raw second/shift/
schedule changes, requested-versus-recorded distinction, tampering/HTML privacy,
terminal rejection, invalid legacy values and complete audit rollback.
RequestBoundaryTest adds the new route: all16 numeric-ID templates across seven
malformed forms fail before record queries (+7 assertions). Tracked synthetic
team/shift/attendance/request/audit cleanup stays inside verified disposable IDs.

All twelve native Chromium scenarios pass with no unexpected/flaky results;
one legacy baseline remains explicitly skipped because shared3306 has no listener
at the current check. Last public probes remain in audit/PUBLIC_HTTP_CHECKS.json:
legacy503, landing client timeout after10s, empty lookup form200; do not turn this
skip into live availability evidence. A separate synthetic supervisor reviews
3.50 requested versus2.00 recorded excess, approves, checks Approved history,
then the owner signs in with a second once-only recovery code and sees Approved
with unchanged requested/recorded figures. Employee queue access is403. Reviewed
captures: docs/ui/synthetic-overtime-approvals-{desktop,mobile}.png; both controls
remain visible at390px, with no horizontal overflow/private GPS/financial markers
or script errors. Exact browser duration is in audit/NATIVE_TESTS.json.

Vite build/Blade cache pass; CSS55.16kB/gzip12.59 and JS11.83kB/gzip3.99 unchanged,
323ms compiler duration. All358 bounded PHP files lint clean;58 native routes
exported. Original full SQL backup hash remains intact;259 original PHP hashes
checked with the same three separate startup connection changes preserved.
No DDL, live record, actual payroll/entitlement calculation, full restore,
legacy-writer concurrency or complete supervisor/HR parity claim. Continue the
remaining on-behalf/team-preview and attendance/procurement ports after tracing
their actual actor, transaction and privacy boundaries.

## 2026-10-26 - Native own overtime request and recorded-work history

Continued after ba8df7a. Traced both my_profile.php handlers, POS
processCashierOvertimeRequest, HR supervisor_overtime.php, its decision helper,
getActualOvertimeForEmployeeDate and computePayrollAttendanceSummary as text.
Requested hours and recorded excess are distinct inputs; payroll independently
caps actual excess at approved requested hours. Ported the own submission/history
subset without executing or replacing payroll, attendance punching or supervisor
decisions. GET/POST /account/overtime uses only the authenticated employee link in
either native domain. There is no user-ID fallback, target or on-behalf selector.

POST validates a real four-digit-year DATE within storage, not in the future,
hours0.01..12 with at most two decimal places, and a3..2000-character brief reason.
This rejects exponent/excess-precision values that legacy is_numeric could accept
and round into storage. Caller-supplied target/approval/status/timestamp/actual-work
fields are prohibited. Current actor and employee eligibility are rechecked under
locks. Qualified HR pending request and minimal POS-domain audit share a validated
same-server transaction; injected audit failure rolls back the request. Identical
pending employee/date/normalized-hours/exact trimmed-reason retries return the
existing request before inserting or auditing again. This is not general UUID
idempotency, completed-request deduplication or a legacy-writer guarantee.

GET projects safe eligibility/schedule type,20 own requests and only the matching
own date/time punches plus seven weekday shift times. Attendance and weekly data
are batched rather than loaded per history row. No GPS coordinates/address,
financial/government IDs, approver identities or documents are selected. A missing
assigned supervisor has a helpful HR-review notice. HTML form/service/filter
errors discard private old input. Reasons are escaped and absent from audit
values; screenshots/fixtures contain synthetic people only.

OvertimeEvidence preserves existing display/payroll math: eight-hour full-time or
six-hour part-time fallback at08:00, current recurring shift duration, next-day
shift endpoints when end<=start, next-day clock-out only when out<in, and exclusion
of early arrival. Expected shift duration rounds to two decimals before excess,
including the tested15/30-second boundary. Missing punches and invalid clocks do
not become known zero/payable hours. Historical display uses current recurring
assignments, matching the existing source; it is explicitly not a reconstructed
historical schedule, payroll calculation or authorization to pay requested hours.

An initial test attempt found the private3307 instance stopped: ten feature tests
failed their isolation-marker queries before fixture writes, while five pure unit
tests passed. Restarted only the project-local instance using its existing config,
then fresh-reset only the nonce-marked pair. The live database was not restarted,
redirected or used for these fixtures. The complete rerun passes:
**187 PHP tests /8,351 assertions**, PHP8.4.26, no failures/errors/skips (~43.72s).
New evidence is16 cases/313 assertions: ten feature cases279, five calculation
cases28 and one genuine two-process cross-domain retry case6. Covers all ten native
roles, distinct IDs, safe read-only SQL, no time/payroll write, exact input bounds,
pending normalization, private HTML errors, escaping/filters/paging, unsupported
topology/links, recorded-work distinction and atomic audit failure. Committed
fixture cleanup removes only tracked own request/audit/attendance/weekly rows.

All eleven native Chromium scenarios pass with no flaky/unexpected results; the
legacy baseline is explicitly skipped. The initial combined run had eleven native
passes and one failed legacy baseline (HTTP503). Public read-only probes record
legacy503, no Laravel landing status within the10-second client limit, and an
HTTP200 empty code-lookup form. Shared3306 has no listener. Earlier
recovery HTTP200 checks are historical. This phase performed no shared-server
restart/repair or live environment redirection. A fresh guarded synthetic reset
and SKIP_LEGACY_BROWSER=1 produced the final explicitly scoped run; see
audit/PUBLIC_HTTP_CHECKS.json. A dedicated
synthetic cashier requests3.50 hours, sees10.00 worked/8.00 expected/2.00 excess,
retries3.5 through JSON and retains one pending row. It confirms GPS/address and
bank/government-ID markers are absent, no script error and no390px overflow.
Reviewed captures: docs/ui/synthetic-own-overtime-{desktop,mobile}.png. Existing
synthetic public, labels, facts, POS, profile/schedule and HR leave flows still pass.
Exact browser duration is in audit/NATIVE_TESTS.json; no real overtime, personnel,
location, wage, mail or private file mutation was used.

Vite/Blade compile pass: CSS55.16kB/gzip12.59, JS11.83kB/gzip3.99,178ms compiler
report. All351 bounded PHP files lint clean;56 native routes exported. Original
SQL dump hash remains intact;259 original PHP hashes checked with the same three
separate connection changes preserved. No DDL, new index, live load, payroll/legal
formula validation, full restore or complete overtime parity claim. The original
overtime table has only its primary-key index; filtered paging may still scan.
Next port the actual supervisor/Admin team-only decision contract and on-behalf
scope, preserving requested-versus-recorded evidence and private actor boundaries.

## 2026-10-26 - Scoped native leave approvals and credit deduction

Continued after 4ab8265. Traced HR admin/hr/hr_manager dashboards,
supervisor_leave.php, isAllowedToDecideLeave, processLeaveDecision and
processLeaveDecisionForSupervisor, team helpers and all affected schema/FKs as
text. Added GET /leave-approvals and POST /leave-approvals/{id}/decision for
actual HR-domain reviewers only. POS Admin is not an HR audit actor; no equal-ID
fallback, mirror creation or fabricated performed_by ID. Existing records remain
in place and both runtimes remain available.

Queues expose only the current review scope: Admin all; HR Manager HR Staff;
HR Staff supervisors and regular employees without a supervisor; supervisors
their actual assigned team. The existing accountant HR role is not silently
treated as employee; absent linked HR accounts retain the legacy employee
fallback. Highest linked HR role resolves duplicate accounts conservatively
instead of relying on arbitrary join order. Team scope follows existing assigned
membership, including its tier semantics; non-Admin self decisions are explicitly
denied even for a malformed self-team mapping. Admin retains existing full scope,
including self; this does not introduce a new two-person approval policy.
Query parameters cannot switch supervisor or expand scope. Queues paginate20
rows with pending/approved/rejected filters, without loading salary, bank,
government IDs, contact fields or private documents.

Decisions require a keyed revision bound to reviewer ID/role and the reviewed
request fields, including reason/dates/state. The server locks/rechecks the
current HR actor, current employee links/eligibility, requester roles and team
mapping before reading the decision row. A single validated same-server POS PDO
transaction handles qualified HR writes. HR employees/requests/credits/logs/team
tables must be InnoDB. Credit and employee locks serialize native decisions;
terminal requests cannot be reversed or decided again. A stale reason, another
request's revision or another reviewer's revision is rejected without writes.

Approval rechecks vacation allocation minus recorded usage at decision time,
closing the stale-balance gap after filing. Inclusive calendar days and existing
start-year charging are retained. Sick/emergency can exceed allocation, while
DECIMAL tracking overflow is rejected. Other leave has no credit row. Missing
tracked credit is created only inside a successful approval. Status, actual HR
approved_by, decided_at, leave_request_logs.performed_by and minimal POS-domain
audit commit together; injected audit failure rolls back all four effects.
Changed field names include credit usage when applicable, never reason values.
Ineligible former/suspended employees can have pending requests rejected, but
cannot receive new native approvals. No attachment, cancellation, credit editing,
payroll execution, mail or retention job was introduced.

Checks: fresh guarded exact nonce-marked synthetic pair on local3307;
**171 PHP tests /8,038 assertions**, PHP8.4.26, no failures/errors/skips (~29.06s).
LeaveApprovalTest adds13 cases/175 assertions; two process-based contention cases
add13 assertions. Two reviewers approving one request yield one credit deduction,
one HR log and one domain audit. Competing two-day vacations with three days
remaining yield one approval, one still-pending request and two days deducted.
RequestBoundaryTest now includes the new endpoint: seven malformed forms across
all15 numeric-ID route templates fail before record queries. No production record
or actual staff account was used. Scope tests include all reviewer tiers, denied
POS/employee/accountant access, duplicate requester roles, moved teams, private
reason suppression outside scope, tampering, HTML no-old-input, stale revisions,
rejection, start-year usage, fractional cap, overflow and rollback.

**All eleven Chromium scenarios pass**, no skips/flaky/unexpected results,
~58.42s (exact report in audit/NATIVE_TESTS.json). A separate synthetic HR Staff
session reviews the employee's earlier pending request, confirms a native decision,
then the employee signs in with a second one-use recovery code and sees Approved
and14.00 remaining sick days. Direct employee access to the approval queue is403.
Reviewed captures: docs/ui/synthetic-leave-approvals-{desktop,mobile}.png; no
horizontal overflow at390px and both decision controls remain visible. Existing
public/legacy, review, label, POS, MFA, profile and schedule scenarios still pass.

Vite build/Blade cache pass: CSS54.73kB/gzip12.53, JS11.83kB/gzip3.99,172ms
compiler report. All342 bounded PHP files lint clean;54 native routes exported.
All259 original PHP hashes checked with the same three separately authorized
connection changes preserved; protected SQL dump hash remains intact. No schema
migration, full recovery drill, live transaction, statutory entitlement review,
all-legacy-writer concurrency or complete HR/payroll parity claim. Current queues
cover decision scope, not all legacy dashboard monitoring/roster functions.
Continue remaining HR self-service, attendance and procurement ports after source
tracing and verified isolated tests; preserve other working startup changes.

## 2026-10-26 - Native own leave submission, balances and history

Continued after a3306ff. Traced both my_profile.php leave actions, POS
processCashierLeaveRequest, HR employee_dashboard, credit/cooldown helpers and
HR/supervisor decision functions as text. HR my_profile omitted cooldown checks
that POS and the employee dashboard already apply. The native shared form now
applies the established vacation checks consistently to all ten native roles.
No legacy file or approval endpoint was replaced by this subset.

GET/POST /account/leave use only the authenticated account's explicit employee
link. GET loads safe eligibility fields, own credit columns and20 own history
rows, with validated start-year/status/page filters. It creates no default credit
rows. Missing recorded balances are clearly labeled default estimates from the
existing15 sick/15 vacation/5 emergency application policy, not legal entitlement
calculations. History includes only the owner's reason, dates and decision state;
approver IDs, other personnel, financial identifiers and documents are excluded.

POST validates true YYYY-MM-DD calendar dates within existing DATE storage,
today-or-later start, ordered end, four known types and a3-to-2000-character brief
reason. Identity/status/approval/credit/timestamp input is prohibited. Native
vacation checks preserve inclusive calendar days, allocation minus recorded used
days in the leave-start year, one clear calendar day between pending/approved
vacation periods and30 calendar days since the latest such filing. The original
comments call the period gap working days, but their implementation uses calendar
days; the new UI states that actual rule. Sick/emergency remain reporting-only
balances and others has no credit cap. Cross-year charging still follows the
existing start-year rule; no new allocation policy was inferred. Pending requests
are not deducted/reserved, clearly stated in the UI; approval consistency remains
part of the future native decision port.

One validated same-server qualified-schema transaction locks the current actor
and actual employee, rechecks account/employee eligibility, verifies transactional
HR tables and inserts pending leave plus minimal POS-domain audit. A missing
vacation credit row is created only after successful checks in this transaction;
used_days is unchanged. Audit contains changed field names and the real actor
domain/ID, not reason text. There is no fabricated HR performed_by foreign key for
a POS actor. Injected audit failure rolls back both request and lazy credit.
Identical pending contents (employee/type/dates/exact trimmed reason) return the
existing request before cooldown checks. This is a bounded pending retry rule,
not a general UUID idempotency scheme or protection against legacy writers.

Checks: fresh guarded nonce-marked synthetic POS/HR pair, project-local3307;
**156 PHP tests /7,843 assertions**, PHP8.4.26, zero failures/errors/skips (~26.09s).
OwnLeaveTest adds15 cases/217 assertions; OwnLeaveConcurrencyTest adds one case/
nine assertions. Two independent PHP workers, distinct POS/HR account IDs linked
to one synthetic employee, produce one pending row, one audit, the same result ID
and one replay. CLI-only workers verify exact names/markers before access.
Committed fixtures are tracked and cleaned by own employee/request IDs, including
credit/log/audit dependencies; they never touch a live pair.

All ten Chromium scenarios pass, without skips/flaky/unexpected results. The
linked synthetic staff flow submits a sick request, sees Pending in own history,
retries the same contents through JSON and retains exactly one request. It checks
no bank/government-ID markers and records desktop/mobile views without horizontal
overflow. Screenshots: docs/ui/synthetic-own-leave-{desktop,mobile}.png; exact
browser duration is in audit/NATIVE_TESTS.json. No real leave, mail, private
document or employee mutation was used. Approvals remain legacy and require that
application's separate sign-in; native submission does not imply approval.

Vite build/Blade cache pass; CSS54.57kB/gzip12.50, JS11.83kB/gzip3.99,171ms
compiler report. All334 bounded PHP files lint clean;52 native routes exported.
All259 original PHP hashes checked with the same three separate connection-file
changes; original full SQL dump hash remains intact. No DDL, production load,
statutory policy review, full restore or complete HR/leave parity claim. Next:
trace native HR/supervisor decision scopes, audit foreign keys and atomic credit
deduction before porting leave approval; preserve private recipient boundaries.

## 2026-10-26 - Native own recurring and covering schedule

Continued after9b001a1. Traced project/HRMS/employee_schedule.php (company roster,
HR-only), both my_profile.php sources and the getEmployeeWeeklySchedule,
getEmployeeShiftForDate and getApprovedShiftSwapForDate helpers as text. Ported
only the staff member's own schedule, not the HR roster or attendance engine.
GET /account/schedule is available after normal MFA/status/session checks in
either domain, with no target/date arguments or write endpoint. Explicit
employee_id ownership is rechecked; there is no user-ID fallback or admin
override to another person's schedule. Unlinked accounts see a helpful empty
state. No schema/data migration or legacy file change.

OwnScheduleService projects only employee eligibility and shift fields. Weekly
assignments preserve all seven weekdays (Monday-first display); no weekly rows
means Not set, while configured unassigned days mean Day off. The rolling seven
Manila calendar days use approved swaps only for the signed-in covering employee,
and only where no weekly shift exists, matching the legacy resolver precedence.
No original colleague, approver, reason, personnel contact, finance or document
field is selected. Inactive assigned shifts remain visible with an HR warning;
invalid clock values and covers whose shift was removed request confirmation
rather than inventing a time. End<=start is marked next-day, preserving legacy
equal-endpoint interpretation. Leave/holiday/attendance overlays and actual
clock-in eligibility are explicitly separate. The page writes no business row.

Checks: **140 PHP tests/7,617 assertions**, zero failures/errors/skips, on the
fresh guarded nonce-marked synthetic pair at127.0.0.1:3307 (PHP8.4.26,~22.92s).
OwnScheduleTest adds9 cases/132 assertions: MFA, absent POST, ten native roles,
different user/employee IDs, ignored target queries, safe SELECTs/no writes,
weekly empty/day-off states, bounded own approved covers/precedence, overnight/
inactive/extended SQL TIME handling, missing shifts, eligibility and escaping.
Early test fixture issues were corrected: helper names collided with Laravel's
session/swap test helpers; suspension uses the actual date columns, not an
unsupported suspended enum value. Final targeted and full runs pass.

All ten Chromium scenarios pass without skips/flaky/unexpected results. The
existing synthetic linked staff flow now opens My schedule, confirms seven
recurring and seven upcoming entries, the overnight next-day marker and an
approved day-off cover, checks private markers remain absent, and captures
desktop/mobile views without horizontal overflow. Screenshots:
docs/ui/synthetic-own-schedule-{desktop,mobile}.png. See audit/NATIVE_TESTS.json
for exact browser duration. No live staff account or personal data was used.

Vite build and Blade cache pass: CSS52.64kB/gzip12.17, unchanged JS11.83kB/
gzip3.99;461ms compiler duration. All326 bounded PHP files lint clean;50 native
routes exported. Original full SQL backup hash remains intact;259 original PHP
hashes checked with the same three separate startup-file changes preserved.
No load, geofence, actual attendance/payroll, full restore, roster-management or
complete HR parity claim. Continue the remaining self-service and approval ports
after tracing their ownership, validation, transaction and privacy boundaries.

## 2026-10-26 - Native own employee personal information

Continued after bcd3b36. Traced both legacy my_profile.php implementations,
country metadata, employee ownership, current-password validation and HR table
effects before adding GET/POST /account/profile. Both native identity domains
use only their authenticated user's employee_id; numeric user-ID fallback is
forbidden. Read-only employment context and nine personal/contact fields are
selected explicitly. Salary, bank details, government IDs, references and files
are excluded from profile queries and output. No target ID is accepted in the URL.

Updates require MFA, active/eligible staff, current password and a row/actor-bound
HMAC revision. Five wrong proofs throttle the account for15 minutes. Role/status/
job/payroll/identity input is prohibited; only explicit personal columns are
written. Repeated contact email confirms typing, not delivery or ownership; the
enrolled two_factor_email, credentials, role and payroll remain unchanged. HTML
failures discard old input, including personal values and proof, and reload saved
details. Unknown stored enum values and unsupported phone formats can be retained
unchanged. The32 legacy country lengths are compatibility metadata, not a claim
of worldwide telephone validation; changing the country never truncates input.

IdentityTransaction now accepts a feature-specific error field/message, retaining
the password feature defaults. One validated same-server POS PDO transaction
locks the actual account and qualified HR employee, verifies InnoDB and commits
employee changes plus a minimal POS audit together. Audits contain changed field
names and actor/domain only. An initial audit action exceeded the existing24-byte
column; changing it to profile_updated fixed the reproduced failure, whose
transaction rolled back without partial employee writes. No schema change.

A hashed, schema-scoped lowercase-ASCII email named lock is held through commit
and explicitly released in finally. Connection ownership is checked inside the
transaction, so a reconnect cannot silently lose the lock. This serializes native
same-email writers, not legacy writers or arbitrary SQL; no unique DB constraint
or multi-server guarantee is claimed. Unsupported connection topology fails
before writes. Audit failure and denial tests confirm lock release.

Checks: guarded fresh reset of the exact nonce-marked synthetic POS/HR pair on
project-local127.0.0.1:3307, then **131 PHP tests /7,485 assertions**, PHP8.4.26,
zero failures/errors/skips (~25.13s). OwnProfileTest has14 cases plus one real
two-process concurrency case (together15 tests/144 assertions). These fixtures
are committed for cross-PDO/process visibility, then cleaned by tracked synthetic
IDs. A prior combined targeted run hit old absolute audit-count assertions after
browser mutations; the clean complete run passes. The CLI-only worker guard was
then checked with the concurrency case again:1 test/6 assertions passed.

**All ten Chromium scenarios pass**, zero skips/flaky/unexpected results (~48.4s).
The new synthetic staff flow confirms own saved email/address, retained enrolled
MFA email, country hints without truncation, no bank/government-ID output and
desktop/mobile layout without horizontal overflow. Reviewed screenshots are
docs/ui/synthetic-personal-information-{desktop,mobile}.png. Other public,
legacy-baseline, POS, approval and review scenarios still pass. Browser mail is
array-only; no live authenticated account or real personal record was used.

Vite build and Blade cache pass: CSS49.90kB/gzip11.71 and JS11.83kB/gzip3.99
(uncompressed build byte reports, not transferred production metrics). All323
bounded PHP files lint clean;49 native routes exported. All259 original legacy
hashes were checked with the same three separately authorized connection-file
changes preserved; protected SQL dump SHA remains intact. Working startup repair
changes and cache markers are excluded from this native phase commit. No full
restore, real email, production load, deployment or complete migration parity
claim. Attendance, leave, overtime, payslips, avatar and administrator profile
operations remain pending; continue source tracing and isolated implementation.

## 2026-10-26 - Request boundaries and Uncategorized regression fixes

Continued after de9501a. Reproduced two native HTTP500 failures on the synthetic
pair: a 30-digit receipt ID passed whereNumber and failed PHP's typed controller
argument; posting the native Uncategorized option became NULL for the original
non-null products.category column. Added failing regression tests before fixes.

All numeric native {id} routes now share a positive 1-10 digit global constraint,
replacing unbounded whereNumber and duplicated local patterns. Zero, negative,
leading-zero, decimal/exponent and oversized path IDs return404 before controller
or identity/catalog record queries. UUID request keys and barcode query strings
retain their separate rules. IDs carried in checkout/refund/basket/label/restock/
supplier inputs now match existing positive signed integer storage. Bail prevents
restock/supplier exists queries after an invalid ID; ordinary authentication and
authorization still precede body validation in real authenticated requests.

Catalog save normalizes an explicitly empty category to the established empty
string, also defaulting an omitted category on creation. This supports the
existing Uncategorized UI while preserving the original schema. Clearing an
existing product category works, stock remains unchanged, and no fake named
category is created. No business schema, price, approval or role policy changed.

Checks: **116 PHP tests / 7,341 assertions**, no failures/skips, PHP8.4.26.
RequestBoundaryTest adds six cases/210 assertions. Its route matrix exercises
seven malformed IDs across all14 numeric-ID route templates and records zero
queries. Body tests cover nested arrays and storage overflow before operational
SQL; guards use cached synthetic actors in PHPUnit, not a claim of zero normal
authentication queries in production. Positive regressions verify uncategorized
creation/clearing, default zero stock and unchanged category-table counts.
The expanded test initially needed DB/Str imports restored after formatting;
the final targeted and complete runs pass.

**All nine Chromium checks pass**, no skips/flaky results; exact duration is in
audit/NATIVE_TESTS.json. The Admin flow now creates an uncategorized synthetic
product, reopens it and confirms the empty category before the existing inventory
checks. Other Inventory/Finance/review/POS/MFA/public/legacy checks still pass.
No CSS/JavaScript/dependency change required a new build. Existing compiled
assets are from the preceding successful information-review build.

**314 PHP files lint clean**. All259 original legacy hashes checked; the same
three separately authorized startup changes and legacy cache markers remain
outside this commit. Full SQL backup SHA remains intact; native routes remain47.
No real product, transaction, login, email or schema mutation was executed by
these tests. Public legacy baseline requests retain their normal bookkeeping.
The documented framework migration remains incomplete; no cutover/tag was made.

## 2026-10-26 - Native manual product information review

Continued after 9b3f5a7. Traced product_details_edit.php, product_ai_review.php,
the existing audit schema and verified-only public projection. Added native
Inventory Staff/Admin description, brand, unit and storage editing; draft save,
submit/resubmit and Admin-only verify/reject. Existing ingredients/allergens,
nutrition, measurement basis and source are shown for review without exposing
private photos or permitting their edits through this form. Native submit also
allows existing ai_filled rows to enter human review; it never publishes them.

Editing verified/rejected information moves it to needs_review and clears review
metadata. Public facts disappear immediately until Admin verification. The
existing web-source/latest AI web-field gate is enforced before verification;
malformed provenance fails closed. This editor cannot bypass the pending label/
nutrition review workflow. Source references share the public HTTP(S)/no-embedded-
credentials filter; unsafe values are suppressed in display and new snapshots.
Existing raw source records are not rewritten merely to inspect/edit a description.

An HMAC revision binds product ID, the complete saved row and latest AI provenance
context. The transaction locks actor/product/detail, rejects stale revisions and
commits the edit/status, established product_detail_audit and minimal domain audit
together. Failed audit writes roll back row creation or existing publication
state. HTML conflicts discard stale old_input before rendering current fields.
Decision forms cannot post edited text; unsaved browser edits disable decisions
until saved or reverted. New history displays only ten action/state/source/time
entries, not raw snapshots, private image references or personnel details.

Checks: **110 PHP tests / 7,131 assertions**, no failures/skips, PHP 8.4.26.
ProductInformationTest covers creation, Inventory submission, Admin-only decisions,
rejection/resubmission, unpublishing, invalid transitions/targets, stale editor and
nutrient/provenance changes, cross-product revisions, clean conflict flashes,
web-source denial, label-derived human review, audit rollback, escaping, source
credential suppression and inactive/unknown/bounded IDs. All prior suites pass.
Sixteen new feature tests use only synthetic records and fake external HTTP.

**Nine Chromium checks pass**, no skips/flaky results; exact duration is in
audit/NATIVE_TESTS.json. Dedicated synthetic Inventory/Admin fixtures make this
flow independent of the earlier label/password/Finance fixture lifecycle. The
browser edits a verified description, confirms it is hidden publicly, verifies
saved values in a separate Admin context and confirms the public update. It
also tests dirty-form decision disabling and mobile layout. A nested textarea
label locator initially timed out; using its actual textbox role/name resolved
the test. Screenshots were reviewed; spacing and stacked headings were refined.

Blade/Vite pass: CSS 48.79 kB/gzip 11.53, JS 11.49 kB/gzip 3.89; one 157 ms build.
**313 PHP files lint clean**, 259 original legacy hashes checked with the same
three separate startup bootstrap changes; full SQL backup SHA remains intact.
**47 native routes** exported. No schema migration, real fact/review/login/sale,
mail or AI request was executed. Legacy public baseline requests can perform
their ordinary rate-limit bookkeeping. Separate startup work remains preserved
outside this checkpoint. Full nutrition/photo/AI editing, camera/private kiosk,
HR/procurement/Finance parity, restore drill and final cutover remain pending.

## 2026-10-26 - Native product discount proposals and decisions

Continued after 3c9468c. Traced products.php and finance.php, including snapshots,
pending/cancel transitions and earliest remaining batch expiry. Added the native
Product discounts workspace: Inventory Staff/Admin submits a proposal; Finance/
Admin approves or rejects. Existing product_discount_requests is reused without
DDL. Price and old discount snapshots come from the locked product. Requests
do not change prices or stock. Catalog CRUD now explicitly prohibits discount
fields and links to the approval form.

Native product locking serializes pending submissions. Transitions lock actor,
product and request, accept pending only and record actual mapped POS decision
IDs/time. Approval rejects changed price/discount/reason, inactive or expired
products and invalid current amounts. Product discount, request status and minimal
audit commit together. An injected audit failure rolls back the complete change.
Expired discounts can be removed; orphaned legacy requests can be rejected or
cancelled, but cannot be approved. Native Inventory Staff sees only own history
and can cancel only own pending requests; Admin retains full cancellation.

Checks: **94 PHP tests / 7,015 assertions**, no failures/skips, PHP 8.4.26.
ProductDiscountTest covers server snapshots, role/tamper/amount checks, pending
uniqueness, owner cancellation, rejection/replay, stale proposals, expiry/removal,
orphan handling, atomic rollback, escaped reasons, distinct HR-admin/POS IDs,
public pricing and actual checkout pricing. Initial isolated tests assumed empty
logs after browser use; corrected assertions compare before/after counts, and
the full suite then passed on the guarded reset pair.

The Inventory Staff browser flow now requests 10% on the synthetic PHP 12.50
product; a separate Finance session sees PHP 11.25, approves it, and the public
detail displays that amount. Mobile checks scroll the focusable table to reach
owner actions; role-specific guidance and a scroll hint were added. An initial
getByLabel locator failed despite the correct accessible combobox name; the
test now locates that role/name directly. Screenshots contain only synthetic
accounts/products; both layouts were inspected. All eight final browser checks
pass, with no skips/flaky results; see audit/NATIVE_TESTS.json for duration.

Blade/Vite pass: CSS 47.65 kB/gzip 11.35, JS 11.00 kB/gzip 3.72, one 170 ms build.
**309 PHP files lint clean**, 259 original legacy hashes checked with the same
three separately changed startup bootstraps; full SQL backup SHA remains intact.
**45 native routes** exported. Documentation/parity/mapping/diagrams are updated
in the same checkpoint, with separate startup work excluded from this commit.
No real request, discount, checkout, email or schema change was executed.

Limits: native product locks do not serialize the preserved legacy handlers;
the old request table has no product/status index or native uniqueness key.
History-scale query plans and simultaneous native/legacy operation remain open.
Admin retains the legacy ability to request and decide; this is not a new
two-person policy. Broader Finance/procurement/HR and full cutover stay pending.

## 2026-10-26 - Native public product-code lookup

Continued after 3bc293e. Traced legacy scan.php, scanner.php and barcode contracts.
Added GET /catalog/scan and catalog navigation, with a responsive code form and
unknown/invalid states. Active exact matches take precedence; a leading-zero
14-digit code falls back to its final13 digits only when no active exact match
exists. Out-of-stock active products still show public details/availability.
Inputs are bounded to3-64 ASCII code characters; invalid input/arrays never query
the catalog. Redirects are generated from matched IDs, never supplied URLs.

The resolver reads only product IDs, with at most two indexed queries. It adds
no telemetry, scan log, cookie fingerprint, external lookup or HR/customer query.
Responses are no-store and limited to60 requests/minute/client. It preserves
public product verification rules by using the existing native detail route.
Camera, private kiosk authorization, encrypted old QR tokens, role return contexts
and scan analytics remain separate pending ports; no device parity is claimed.

Checks: **84 PHP tests / 6,941 assertions**, no failures/skips, PHP8.4.26.
PublicProductCodeTest covers active/unknown/inactive codes, exact versus fallback
precedence, zero stock, invalid/malicious inputs before queries, native-only
redirects, no external calls/writes/personnel query and throttling. An initial
trailing-NUL fixture was normalized by Laravel's standard trimming; the invalid
control fixture now uses an embedded NUL and verifies rejection before SQL.
**All8 Chromium checks pass**, no skips/flaky results,34.0 seconds. New desktop/
mobile code lookup covers unknown state, Enter submission, GTIN fallback,
correct product/availability, no script errors and no external requests.
The synthetic screenshots were inspected; legacy capture contains public data.

Separate authorized startup work restored shared MariaDB while this port was in
progress. This work did not perform that repair. Fresh read-only public HTTP
checks: original homepage200/352 ms, native homepage200/335 ms, native lookup200/
382 ms. The previously skipped legacy browser baseline now also passes. These
single checks establish availability/rendering, not authenticated operational
parity, load capacity or full recovery. Both synthetic DB domains remain3307.

Blade/Vite pass; CSS47.48 kB/gzip11.32, JS11.00 kB/gzip3.72, one171 ms build.
**305 PHP files lint clean**; all259 original legacy hashes checked, with the
same three separately modified connection files; full SQL backup SHA still
matches. **41 native routes** exported. Documentation changes preserve separate
startup notes and source edits outside this commit. Remaining native migration
work is still open in PARITY; no final cutover or release tag was created.

## 2026-10-26 - Atomic own-account password changes

Continued after df576e3. Traced both legacy password pages and the full-profile
boundary. Added Account security with masked own details, recovery count,
current-password/recovery proof, confirmed stronger password policy, no secret
flashes and five-failure account throttling. The actor/domain cannot be supplied.
Canonical domain, compatible roles and exact matching employee identity are
rechecked under locks. Ambiguous links are refused rather than guessed.

IdentityTransaction validates same-server credentials/schema names/InnoDB users
tables and uses one POS PDO transaction with qualified POS/HRMS tables. Both
passwords, force_logout, actor recovery consumption and minimal audit commit or
roll back together. Employee status is rechecked on that connection. Native
session versions are revoked after commit, the current session ends, and a new
password plus MFA is required. No real credential/account or schema was changed.

Checks: **78 PHP tests / 6,823 assertions**, no failures/skips, PHP8.4.26. New
coverage includes all six native HR roles, linked distinct IDs, terminated
employee denial, recovery replay, policy/target tampering, throttling, masked
display and injected second-domain failure with complete credential/recovery/audit
rollback. **6 Chromium checks passed / 1 legacy baseline skipped**, 33.8 seconds.
The label flow additionally tests the mobile account form, forced sign-out,
old-password rejection and new-password/recovery MFA sign-in. Synthetic seed
accounts have a second explicitly synthetic recovery code for that check.

An adjacent @endif/@if initially broke login rendering; separated directives
and a status/error template regression check now pass. The JSON-session HTTP
fixture was replaced with a direct view-error fixture; real browser invalid
login also verifies error display. Compiled testing views are separated from
live views after shared Windows replacement errors appeared. Final mobile
capture waits for the responsive sidebar transition; the empty form was reviewed.

Blade/Vite builds pass. An initial302-file lint matched all259 original legacy
runtime hashes. The final **303-file lint passes** and backup SHA remains intact;
**three legacy connection files changed concurrently** outside this work. Those
changes and the new legacy timeout test are preserved unstaged and excluded from
this native checkpoint. No external configuration was overwritten. **40 native routes**. Assets:
CSS46.53 kB/gzip11.14, JS11.00 kB/gzip3.72 (one560 ms build). Architecture, API,
database, security/privacy, UI, installation, diagrams, mapping and bounded parity
were synchronized. Full profile/avatar/reset administration, legacy session
coverage and remaining HR/procurement/finance/scanner workflows stay pending.
Shared database readiness remains unresolved; later startup notes report a
listener without a greeting. No shared-system repair or final cutover occurred.

## 2026-10-26 - Verified public facts and nutrition comparison

Continued after 67a6b49. Traced product_detail.php / nutrition_compare.php and
existing product_details review states. Added PublicProductFacts, native verified
brand/description/unit/weight/ingredients/allergens/storage/nutrition display,
related in-stock items and GET /compare. SQL filters active/verified before
projection; all unverified states and internal reviewer/rejection fields stay
out of public views. Unsafe/credential-bearing source URLs are suppressed.

Comparison preserves category/basis guards, additionally requires matching
listed serving sizes and retains unknown versus zero without inferred conversion
or a universal lower-is-best ranking. Selected products remain available even
outside the first100 choices. Public IDs are bounded and pages throttled. Mobile
tables retain row headings, support keyboard/horizontal scrolling and show a
scroll hint. Native placeholders remain visible during load; default.png avoids
an unnecessary image request. No real facts were edited or externally fetched.

Checks: **66 PHP tests / 6,734 assertions**, no failures/skips. **6 browser checks
passed / 1 legacy shared-DB baseline skipped**, 25.8 seconds. PublicProductFactsTest
covers all unverified states, escaping, zero/unknown values, safe source URLs,
category/basis/serving mismatch, inactive/duplicate/excessive IDs and retained
selection beyond100 choices. Browser checks desktop/mobile comparison, horizontal
access to the second column and the reviewed product page; earlier POS/Finance,
label decode/PDF and role checks still pass. A new viewport assertion initially
needed the table scrolled vertically into view; corrected test setup then passed.

Blade/Vite builds pass; **295 PHP files lint clean**, all **259 original legacy
source hashes unchanged**, original full SQL backup hash intact. Routes: **38**.
Assets: CSS46.17 kB/gzip11.06, JS10.67 kB/gzip3.61; latest local build155 ms.
Synthetic screenshots and audit artifacts were refreshed. The nutrition reference
supports matching serving interpretation, not regulatory or medical approval.
Native editing/AI/photo-review/private catalog/scan logging/camera and remaining
HR/procurement/finance workflows stay pending. Shared port3306 is still not
listening; no shared-system repair, live transaction or final cutover occurred.
## 2026-10-26 - Native printable EAN and shelf QR labels

Continued after f03dada. Traced product_barcode_labels.php and shelf_labels.php,
including Inventory Staff/Admin guards, EAN generation and the 96-item limit.
Added native label selection/printing, active-only search/pagination and bulk
selection, escaped names, current discounted prices and A4 print CSS. Missing or
invalid EAN/checksum gets a readable correction state, with no identifier rewrite.
Shelf QR uses configured APP_URL and the native public product route. Catalog
cards/details now share checkout's product-price calculation and show a regular
price when discounted.

BaconQrCode 3.1.1 / dasprid enum 1.0.7 are locked for local in-memory SVG output;
XMLWriter is declared explicitly. Existing local ZXing-WASM is used only in the
browser test through interception, without an application decoder/file route or
external image upload. No private record was copied or live schema changed.

Checks: **58 PHPUnit tests / 6,672 assertions, zero failures/skips** on PHP8.4.26;
**5 browser checks passed / 1 shared-DB legacy baseline skipped**, 26.1 seconds.
The Inventory Staff browser check bulk-selects, prints a barcode, decodes its
actual pixels to 4006381333931, generates an A4 PDF, then prints/decodes a shelf
QR to the exact isolated native product URL and opens that product. Role denial,
inactive/missing/duplicate/oversized/malformed selection, escaping, discount
prices and invalid-checksum preservation are covered by ProductLabelsTest.
An initial full run found a duplicate synthetic EAN fixture after adding the
seeder sample; the fixture was made distinct and the complete suite passed.

Blade/Vite builds passed; **293 PHP files lint clean**. All **259 original legacy
source hashes are unchanged**, full SQL backup hash matches. Composer strict
validation/platform checks pass with no known advisories. Native routes: **37**.
Current assets: CSS43.95 kB/gzip10.63, JS10.53 kB/gzip3.58. Source/count evidence
is in audit/NATIVE_TESTS.json, NATIVE_VALIDATION.json and UI_SMOKE.json. Label
screenshots and local PDF contain synthetic fixtures only. Physical printer,
real phone/camera, all barcode symbologies and full cutover remain unverified.
The shared real database server is still unavailable; no shared repair was done.
## 2026-10-26 - Native cashier discounts and Finance decisions

Continued after local checkpoint 1fd339c. Traced legacy pos.php manual-discount
creation/cancellation and finance.php approve/reject actions before porting.
Added DiscountApprovalRequest/Service/Controller, five native routes, request
history/decision forms, role-filtered navigation and a register approval selector.
A shared unit-price calculation derives both request quotes and checkout prices.
Cashier submissions reject client subtotal/price/identity/status; native requests
are serialized per cashier. Finance/Admin decide only locked pending rows;
owner cancellation and one-time native use are enforced. No stock is reserved
by a request. Existing approved legacy requests have no retroactive native
consumption history; historical reconciliation remains open.

Normal/manual-approved checkout now discards irrelevant senior/PWD identities
and supervisor inputs before durable hashing and persistence. No original record
or schema was changed by this port; all mutations were synthetic.

Executed checks: **54 PHP tests / 6,641 assertions, no failures/skips** on PHP
8.4.26; **4 browser checks passed / 1 legacy baseline skipped**, 19.6 seconds.
The browser signs into separate Cashier and Finance sessions, requests/approves
10%, verifies PHP42.50 becomes PHP38.25, prints the receipt view and retries
without a second sale/stock movement. Mobile register/inventory, public views
and denied static paths still pass. Six new approval integration tests cover
server prices, roles/ownership, cancellation, rejection and one-time use; a
privacy test checks unused statutory fields are null in ordinary sales.

Vite build and Blade compilation pass; **290 PHP files lint clean**, all **259
original legacy source hashes unchanged**, original SQL-backup hash intact.
Native routes: **35**. Current assets: CSS41.43 kB/gzip10.14, JS10.36 kB/gzip3.53;
removing unused skeleton views reduced CSS, while approval UI adds useful JS.
See audit/NATIVE_TESTS.json, NATIVE_VALIDATION.json and UI_SMOKE.json. Screenshots
contain public information or synthetic fixtures only. No real mail, sale,
approval or financial transaction was performed. Shared MariaDB recovery and
remaining full-module parity are still open; no cutover/tag/deployment claim.
## 2026-10-26 - Transaction contention, recovery, inventory and validation checkpoint

Owner instruction remains: continue implementation without routine questions.
This checkpoint adds native same-day owner-only voids (original receipt/logs
preserved), inventory search/stock filters/expiry watch and named restock
selection, persistent MFA attempt windows, one-use enrollment recovery codes,
forced-session revocation versions and generic database-unavailable responses.
Mirrored HR Admin authentication/actor mapping is tested with deliberately
different POS and HR IDs. PDO connection timeout is configured to three seconds;
direct timeout measures 3.013 seconds and the live outage HTTP 503 request takes 12.24 seconds including framework retries.

Completed checks:

- PHP 8.4.26 PHPUnit: **47 tests, 6,609 assertions**, 0 failures/skips. Includes two real concurrent PHP checkout processes, injected rollback, original batch restoration, MFA recovery/enrollment/locks, CSRF, roles, separate-domain actor mapping and original schema/index/FK/trigger contracts.
- Playwright Chromium: **4 passed, 1 skipped**, 14.8 seconds. Synthetic desktop/mobile public, Admin inventory and cashier login/checkout/receipt/durable retry passed. Legacy baseline explicitly skipped because the shared MariaDB is unavailable; an earlier complete run passed all 3 then-existing tests. First new inventory run exposed an ambiguous select label; it was corrected before the passing run.
- PHP lint: **286 files, 0 failures** across native and legacy source paths, excluding dependencies/private uploads/backups. **259 original legacy source hashes unchanged**. Full database backup SHA-256 still matches. See audit/NATIVE_VALIDATION.json.
- Composer lock refreshed without dependency changes; composer validate --strict passed and composer audit returned no advisories/abandoned packages. Full npm audit (including dev dependencies) returned zero known vulnerabilities.
- Vite build and Blade compilation passed. Unused Axios/bootstrap removal reduced JS from 59.20 kB (gzip 22.13) to 7.66 kB (gzip 2.84); CSS is 67.25 kB (gzip 16.12). These are bundle measurements, not load tests.
- Native route export records 30 routes. API/UI/performance/testing/structure/flow diagrams and parity documentation now distinguish implemented evidence from pending legacy scope.

Interruption and safe workaround: both local services were found stopped.
Apache restarted without configuration edits. Shared MariaDB startup reports
mysql.db is crashed and automatic safe recovery cannot recover all expected
privilege rows. Cause unconfirmed. No manual system-table repair or live restore
was performed. It is outside the requested project file boundary. A new
project-local MariaDB instance on 127.0.0.1:3307 contains synthetic schemas only;
provisioning checks exact server datadir plus both name/nonce markers. Production
.env still selects the original real pair. Current login HTTP 200 and protected
root manifest HTTP 403 are confirmed; live data-page HTTP availability is blocked.

A broad Pint invocation reached archived root PHP snapshots. Restored 121 PHP
snapshot files from the preserved Git baseline; 120 had original audit hashes
and all matched, with zero unresolved reconstruction. The full SQL backup was
untouched and hash-verified. pint.json now excludes original runtime, archives,
backups, storage and staging. Later formatting uses native paths explicitly.
A first session-revocation test used a mass-assignment-protected field and failed
to set its fixture flag; the fixture was corrected through the isolated DB and
the full suite passed. These failures are retained here as verification history.

Final additions before the checkpoint: direct inherited image URLs now return
403 on Apache while native built CSS returns 200. A dedicated PHP test router
prevents static-file bypasses and the browser suite checks five denied private
paths. Synthetic valid-image, inactive-product, traversal and fake-MIME feature
checks pass. Browser testing exposed persistent mail-send throttles blocking the
recovery screen; pending MFA now remains accessible after throttling/transport
failure without granting access. Enrolled email resend and dedicated testing
cache/session/lock directories are implemented. Added corresponding regression
tests. Removed unused generated welcome/Axios files and two boilerplate example
tests; reported totals include meaningful remaining tests only. A temporarily
overlapping failed browser/PHP attempt was stopped; final PHP and browser suites
were run sequentially on reset synthetic fixtures. No real records were involved.
Local documentation gate passes; 109 maintained-document links resolve.
Remaining authorized work: full HRMS/procurement/finance/scanner/nutrition,
account/profile/reset/admin and advanced reporting/printing ports; exact
financial/private-file reconciliation and protected recovery drill; all-role,
camera/device/accessibility/cross-browser coverage; shared-server recovery by
its owner, live recheck and final parity/cutover. No release/tag, remote push,
payroll, real mail, retention purge or real sale/refund/void was performed.

## 2026-10-26 - Laravel runtime, schema adoption and native retail core

Current owner authorization: continue without routine questions through the
migration. Laravel **13.38.0** is now installed at root and serves `/public/`.
PATH PHP 8.3.35 and Apache PHP 8.4.26 work with locked compatible dependencies;
portable Node 24.22.1 was verified against the official checksum manifest.

Live adoption validated the original schemas and added only POS migrations,
checkout-request and audit tables. All 77 original table counts matched the
protected pre-migration manifest (8,631 aggregate rows). Phase 0 counted one
more public rate-limit row; it was already absent before backup/adoption.
No business table was recreated or imported. Scoped access was restored for
existing legacy principals; original legacy runtime source remains unchanged.

Native implementation: public storefront/catalog/product/image routes;
dual-domain password plus OTP/recovery sign-in; role/status/session/CSRF
guards; allowlisted product/category/supplier operations; inventory and
Admin-only direct restock; cashier lookup/checkout/receipts/refunds; scoped
sales lists; durable checkout retries and value-minimized audit events.
First-time verified enrollment now creates one-time recovery codes. Remaining
HR/procurement/finance/scanner and advanced workflows retain legacy links;
neither shared sessions nor complete native parity is claimed.

Executed evidence:

- `artisan about`, route inventory, public landing/login/catalog HTTP 200;
  `artisan view:cache` and Vite 7.3.7 production build passed.
- `php artisan migrate --env=testing` created schema only on the specifically
  marked synthetic pair. The approved separate migration environment then
  adopted live schemas; its backup/count evidence is in DATA_ADOPTION.json.
- Safe `migrate:fresh --env=testing --seed` successfully rebuilt both verified
  synthetic domains and seeded only synthetic examples. Default reset is
  guarded before DDL; an automated non-testing-environment denial test passed.
- Latest suite before the next additions: **28 tests, 6,457 assertions passed**
  on PHP 8.4.26. Includes schema types/defaults/nullability/keys/indexes/collation/
  engine/FK rules, auth/role/CSRF, transactions/retries/rollback/expiry/refunds,
  Admin restock, category link preservation and both immutable-log triggers.
- Pint formatted native PHP paths only; original `project/` PHP was not changed.
- The configured Git documentation gate rejected a staged auth change without
  changelog/progress/security/privacy updates. This was an intentional test,
  not an unresolved approval rejection.

Limits: no real mail, financial transaction, payroll, personnel upload, purge,
full recovery drill, full cross-browser/concurrency/all-role smoke test or
release/tag/deployment was performed. Further auth/browser/domain work and
documentation synchronization are ongoing. The Phase 0 stop below is history.

## 2026-10-26 - Phase 1 authorized; safety net and Laravel installation

Owner steering: continue without questions until the usage limit. This is the
go for remaining phases and supersedes the earlier checkpoint next-step text.
Do not repeat that confirmation.

Created protected full backup of both databases (2,473,472 bytes), verified
416 legacy and 102 root MVC copied files against SHA-256, and stored a private
manifest. Added root HTTP sibling protections and ignored private artifacts;
root manifest/docs/CLI probes return 403, public login remains 200.
Created a new CRUD-only web account and separate newly marked empty test pair.
No business record or live table schema was modified. Full recovery drill is
not yet complete; real records are not application test fixtures.

Added staged-doc gate source and hook. Laravel Composer installation is
running in protected `.laravel-staging/`; installed versions and runtime checks
will be recorded after completion. See [migration notes](MIGRATION_NOTES.md).

## 2026-10-26 - Laravel migration Phase 0 audit and documentation rule

Requested: migrate all legacy GroceryOne workflows/data to Laravel, improve
UI/security/privacy/performance and adopt AcademiSync-style docs tracking.
The attached brief explicitly requires audit/mapping/parity first and the
user's go before Phase 1. This entry supersedes older next-step notes as the
current work scope; their original verification claims remain historical.

Delivered:

- [AUDIT.md](AUDIT.md), with runtime/source/security findings and limits;
  redacted full tree, 135 legacy entry candidates, 200 static action candidates,
  27 current MVC routes and 337 source fingerprints under `docs/audit/`.
- Direct read-only metadata and COUNT(*) checks of `grocery_pos` (42 tables,
  2,810 rows) and `hrms` (35 tables, 5,822 rows). The brief's `manage_grocery`
  database is absent. No business-record contents were displayed/exported.
- Two successful schema-only mysqldumps with definer identities normalized in
  audit copies, plus column/index/FK/engine/trigger metadata and per-table counts.
  These are not full backups or migration data-parity evidence.
- [Proposed file/route mapping](MIGRATION_MAPPING.md), covering all 135 source
  entry candidates, and [PARITY.md](PARITY.md) with all migration checks open.
- [Documentation maintenance rule](DOCUMENTATION_MAINTENANCE.md), root
  [CHANGELOG](../CHANGELOG.md), and links/current scope in AGENTS, README and
  shared AI guidance. Automatic Git/CI documentation enforcement is pending.
- Corrected current ARCHITECTURE, DATABASE, SECURITY and PRIVACY documents
  against source/schema evidence. Preserved their earlier drafts under
  `docs/archive/` with explicit historical/unverified notices; removed current
  unsupported schema, TOTP, universal-CSRF and legal-compliance claims.

Checks executed:

- `php -v`: PATH PHP 8.3.35. `composer --version`: Composer 2.10.3 on PHP
  8.4.26. Apache config and HTTP headers identify PHP 8.4.26; no host config changed.
- `C:/xampp/php84/php.exe -l <file>` across app/bin/config/public/tests/project:
  **273 checked, 0 syntax failures**. No application/config was executed by lint.
- `Invoke-WebRequest -UseBasicParsing -TimeoutSec 20` for `/public/` and
  `/public/login`: both 200. Harmless `/composer.json` and `/docs/README.md`
  probes also returned 200, disproving exclusive public-root isolation.
- Standalone PDO read-only transactions for metadata/counts, then rollback;
  schema-only mysqldump flags/results are recorded in AUDIT. Configured app and
  backup identities failed with 1044; only the existing MVC fallback identity
  was used in direct audit processes. No grants/accounts/records changed.
- Final artifact checks: all 337 fingerprinted PHP/CSS/JS/SQL sources unchanged;
  135 mapped entries and 190 open parity boxes, none checked; 123 local links
  across 29 Markdown files resolved. Schema hashes matched, with 42/35 table
  definitions, 2/0 triggers and no record INSERT statements or original definer
  identities. Limited secret-pattern/private-filename reference checks found
  no hits in docs; these are not comprehensive secret scanning or DLP.

Limits/findings: `/public/` is custom MVC, not Laravel; its role middleware is
unwired, three operational POST routes lack CSRF checks, and POS/privacy SQL
mismatches live columns/tables. Existing simulated tests do not establish
auth/sale/refund/role parity. No login, sale, refund, payroll, upload, retention
purge, legacy HTTP request, external mail/API, migration, seed or full backup
ran. Legacy/bootstrap side effects were inspected as text. No Git init/commit,
framework installation, Apache modification, release or cutover occurred.

Next authorized checkpoint: show the audit/mapping/parity to the user and wait
for their go, as explicitly required by the attached execution brief. Proposed
Phase 1 preserves both DBs and both source implementations, repairs backup
access, protects nonpublic paths and establishes safe Git ignores before staging.

Older entries below describe prior custom MVC work, not Laravel completion or
fresh end-to-end verification.

## 2026-10-26 - Visual Style Restoration & UI/UX Parity (Authentic GroceryOne Theme)

Requested: Restore the authentic GroceryOne visual styling, layout conventions, and CSS/JS assets across the MVC application views (`app/Views/`).

Root Cause Analysis:
- In the initial view scaffolding, `app/Views/layouts/header.php` lacked links to the master stylesheet `style.css` (56KB) and `theme-toggle.css`, and used a temporary simplified navbar with generic inline styles instead of the genuine GroceryOne `.app-shell`, collapsible `.sidebar`, `.topbar`, and panel card layouts.
- `public/assets/css/` lacked the root `style.css` and `theme-toggle.css`, causing 404 styling fallbacks.

Delivered:
1. Asset Restoration in `public/assets/`:
   - Copied `project/style.css` (56,506 bytes) to `public/assets/css/style.css`.
   - Copied `project/theme-toggle.css` (3,745 bytes) to `public/assets/css/theme-toggle.css`.
   - Copied `project/theme-sync.js` (3,388 bytes) to `public/assets/js/theme-sync.js`.
2. Authentic Split-Screen Login View (`app/Views/auth/login.php`):
   - Replicated the authentic GroceryOne login layout: dark-blue left brand panel with glowing animated orbs (`.brand-orb`), custom SVG shopping bag crest logo, Fraunces typography, and feature pills.
   - Restored the right login card with dark/light mode toggle, CSRF token guard, password visibility toggle, and SweetAlert2 error handling.
3. Master Layout Architecture (`app/Views/layouts/header.php` & `footer.php`):
   - Restored the authentic `.app-shell` responsive container.
   - Built the complete authentic GroceryOne `.sidebar` with custom 20x20 SVG nav icons (`$iconDashboard`, `$iconPos`, `$iconProducts`, `$iconInventory`, `$iconReports`, `$iconProcurement`, `$iconFinance`, `$iconEmployees`, `$iconAttendance`, `$iconPrivacy`, `$iconLogout`).
   - Integrated sidebar collapse/expand with `localStorage` persistence (`siaa_sidebar_collapsed`).
   - Restored the authentic `.topbar` with page icon, title, dark/light mode theme toggle, and user initial avatar.
   - Linked Google Fonts (`Fraunces`, `Plus Jakarta Sans`, `Inter`, `Sora`), Font Awesome 6, and SweetAlert2.
4. Comprehensive View Restyling:
   - `dashboard/index.php`: Restored `.dashboard-hero` greeting banner, primary stat cards with color-coded left borders, secondary pending approvals/kiosk cards, 2-column `.content-grid` with `.panel` and `.data-table` for Recent Transactions and Low Stock Watchlist, and quick action buttons.
   - `pos/index.php`: Restored 2-column register layout, barcode search input, live cart table with void button, statutory discount dropdown (Senior/PWD 20% VAT-exempt), subtotal/VAT breakdown, and SweetAlert2 transaction receipt dialogs.
   - `products/index.php`, `inventory/index.php`, `reports/index.php`, `procurement/index.php`, `finance/index.php`, and `hrms/` views: Replaced unstyled tables with authentic GroceryOne `.panel`, `.panel-header`, `.data-table`, and status badges.
5. Automated Test Suite Expansion (`tests/runner.php`):
   - Added Upload Security Hardening tests (blocking `.php.png` double extensions, blocking embedded PHP webshell scripts, allowing clean images).
   - Added Code Standards test validating `declare(strict_types=1);` across all 51 application PHP classes.
   - All 11/11 tests pass with 100% verification rate.
   - All 18 modified view and controller files verified with `php -l` (0 errors).

Next work: Browser-level end-to-end smoke testing under Apache XAMPP.

## 2026-10-25 - Middleware Pipeline, Reports/Exports, Shelf Labels & Privacy DSR Queue

Requested: Continue modernizing Manage-Grocery to complete remaining operational modules, reporting, and security middleware.

Delivered:
1. Implemented Security Middleware Pipeline:
   - `AuthMiddleware.php`: Enforces authenticated session; redirects unauthenticated requests to `/login`.
   - `RoleMiddleware.php`: Role permission gating (`admin`, `cashier`, `supervisor`, `accountant`, `hr_manager`).
   - `CsrfMiddleware.php`: Automatic verification of anti-CSRF token on all state-changing HTTP requests.
   - Updated `Router.php` to accept route-specific middleware filters.
2. Built Reports & CSV Export Engine:
   - `ReportController.php` & `app/Views/reports/index.php`: 30-day trailing gross sales, transaction counts, 12% VAT collection, and top 10 selling products.
   - Streaming RFC-4180 CSV export at `/reports/export/sales-csv`.
3. Retail Shelf Labels & Barcode Printing:
   - `BarcodeController.php` & `app/Views/products/labels.php`: Print-optimized CSS grid for retail shelf edge tags featuring product name, selling price, barcode digits, and PCU Dasmariñas branding.
4. HRMS Privacy & Statutory DSR Queue:
   - `Hrms\PrivacyDsrController.php` & `app/Views/hrms/privacy/index.php`: Data Subject Rights queue under RA 10173 and automated 365-day statutory hard deletion endpoint for rejected job applicants.
5. Automated Test Suite Expansion:
   - Expanded `tests/runner.php` to 7 assertions covering Geofence calculations, TRAIN law payroll tax brackets, AI nutritional algorithms, CSRF token validation, and RA 10173 365-day retention boundaries (100% pass rate).
   - Executed `php -l` on all 59 PHP files across `app/`, `bin/`, `config/`, `public/`, and `tests/` (0 syntax errors).

## 2026-10-25 - Active Record Models, Consumer AI Kiosk, POS Checkout & Migration Runner

Requested: Continue modernizing Manage-Grocery to achieve feature completeness and technical parity with the reference project.

Delivered:
1. Implemented Active Record Entities in `app/Models/`: `Product`, `User`, `ProductBatch`, `Sale`, `Employee`.
2. Implemented Consumer AI & Self-Service Kiosk: `AiNutritionService`, `KioskController`, and views.
3. Completed POS Checkout API & Cart Integration with transactional FIFO batch depletion.
4. Database Migration CLI Tooling (`bin/migrate`).

## 2026-10-25 - Core Services, Full Controller Migration & Automated CLI Test Suite

Requested: Continue executing the architectural enhancement roadmap to bring Manage-Grocery up to the reference quality bar.

Delivered:
1. Implemented isolated domain service layer in `app/Services/`: `PosSaleService`, `GeofenceService`, `PayrollCalculationService`.
2. Implemented controllers and view templates for Procurement, Finance, HRMS Employees, HRMS Attendance, HRMS Payroll, and Storage.
3. Quality tooling: Created `composer.json` and `phpstan.neon`.

## 2026-10-25 - System Documentation Suite & Clean MVC Architecture Scaffolding

Requested: Audit `manage-grocery-main`, produce comprehensive documentation under `/docs` reflecting actual code, and scaffold a clean, safe MVC folder structure suitable for plain PHP with webroot isolation.

Delivered:
1. Complete 10-document technical documentation pack in `docs/`: README, ARCHITECTURE, SECURITY, PRIVACY, FLOWCHARTS, DATABASE, FOLDER_STRUCTURE, INSTALL, TESTING, CHANGELOG.
2. Initial scaffolding of clean MVC directory structure.
3. Removed dead prototype files.

## 2026-10-23 - Portable AI tooling

Requested: add AI instructions, reusable skills, editor entry files and MCP setup to this project as well as HROMS and AcademiSync.


The first browser attempt timed out because decorative navigation symbols were
included in accessible link names. Sidebar icons now use aria-hidden, leaving
the visible link text as its accessible name. Screenshot review also caught the
mobile drawer during its closing transition; the final capture waits for the
drawer to leave the viewport and disables animations. These findings required
a fresh guarded browser rerun; the final complete report passes all ten.


Browser verification initially timed out on the nested leave-type label lookup;
the select now has an explicit accessible label. Visual review then caught a
Windows text-edit encoding artifact in the default-balance caption, corrected to
plain text. The final captures were regenerated after these fixes.
