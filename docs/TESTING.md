# Verification procedure and evidence

Updated 2026-10-26. Tests use only a specifically verified synthetic POS/HRMS
pair. Both exact names, APP_ENV=testing and creation marker rows must match;
a database name alone never establishes isolation. Every feature test checks
the target before work. Destructive reset and seeding fail before DDL elsewhere.

## Original PHP startup regression - 2026-10-26

Run `C:\xampp\php84\php.exe project\tests\database_connection_timeout.php`.
This starts three fresh PHP children against ephemeral loopback listeners that
accept but never send a MySQL greeting. Only synthetic credentials/schema names
are configured; no database server, SQL query or real record is used. The public
read helper must set failure status 503; the two write helpers must throw a
connection failure within 2-to-5.5 seconds. The first PDO-only attempt exceeded
six seconds; adding the public mysqlnd read timeout produced three passing
probes (3.12/3.08/3.07 seconds).

Four changed PHP files passed syntax lint; `consumer_layer_smoke.php` passed its
source contract. Public HTTP checks and standalone Chromium verified original
`/project/index`; the ignored local script/screenshot are in
storage/testing/legacy-startup-smoke.mjs and legacy-startup.png. No authenticated
workflow or business mutation suite ran against the live server.

## Commands

```powershell
php artisan migrate:fresh --env=testing --seed --no-interaction
C:\xampp\php84\php.exe vendor\bin\phpunit --no-progress --log-junit storage\testing\phpunit.xml
bin\npm.cmd run build
php artisan view:cache
Remove-Item Env:SKIP_LEGACY_BROWSER -ErrorAction SilentlyContinue
bin\npm.cmd run test:browser
```

`migrate:fresh` is the custom guarded command, not a live reset procedure.
Most tests roll back both connections. Concurrency tests require committed
synthetic fixtures, run two PHP processes behind a file barrier, and reset only
the marked pair afterward. Never run database suites in parallel with browser
mutations. Browser checkout consumes a synthetic recovery code and creates one
synthetic sale; reset the pair before repeating a whole suite.

The shared port-3306 server stopped with a corrupt mysql.db privilege table.
The current test pair uses a separately initialized project-local MariaDB
instance bound to 127.0.0.1:3307 under storage/testing/mariadb. It contains no
business records. bin/prepare-test-server.php refuses any other server data
directory, provisions only the exact pair and retains admin credentials privately.
Server startup/shutdown and initialization are documented in INSTALL.

## Coverage and limits

The last completed 36-test suite passed 6,532 assertions before later session
revocation and availability additions. Latest complete counts are in PROGRESS.
Named suites cover:

- Original 77 schema contracts, indexes, FK rules, table engines/collations and both immutable stock-log triggers.
- Password-only denial, OTP/enrollment/recovery, email rebinding prevention, throttle/persistent MFA lock, role/status/session/CSRF rejection and distinct identity mapping.
- Server prices, atomic sale/items/stock/batch/log, oversell/expiry denial, failure rollback, durable retries and genuine separate-process contention.
- Partial/full refunds and same-day owner-only void restoration/replay rejection.
- Catalog escaping/category links and Admin-only restock effects.
- Existing geofence/payroll calculation unit checks; these do not prove native attendance/payroll screens or current legal formulas.

Browser checks default entirely to the synthetic HTTP server on port 8017.
An optional BROWSER_PUBLIC_BASE_URL can target the real public landing for
read-only public checks. With SKIP_LEGACY_BROWSER=1, the legacy baseline test
is explicitly skipped because the shared database is unavailable; its earlier
passing screenshot/report is historical evidence. Current and historical
screenshots are distinguished in UI.md. Traces/videos stay disabled to avoid
capturing credentials. No real user account is used for staff tests.

PHP lint checks syntax only; Blade cache checks compilation; bundle builds
check frontend compilation. Dependency audits report current known advisories,
not comprehensive security assurance. No full backup restore, production load,
real email, payroll, private document, purge, full legacy parity or release
validation has been performed. Original Phase 0's 273-file lint/HTTP/schema
checks remain recorded in AUDIT and audit/VALIDATION.json.
## Current complete native checkpoint

**201 PHP tests / 8,526 assertions** pass on the marked synthetic pair, with no
failures/skips. **Twelve native Chromium checks pass**, one explicit legacy skip and no native failures/flaky results; exact
duration is in audit/NATIVE_TESTS.json. All staff operations use synthetic 3307;
the legacy baseline is currently skipped after HTTP503; earlier startup-recovery passes are historical.

AccountSecurityTest covers own POS/all six HR roles, linked distinct IDs,
employee eligibility, once-only proof, throttling/policy, masking and atomic
cross-schema rollback. Chromium rejects the changed password's predecessor and
completes fresh MFA. PublicProductCodeTest covers bounded input, active-only
exact/GTIN fallback, missing/zero-stock states and no writes/external/HR query;
desktop/mobile Enter submission reaches the intended product.

ProductDiscountTest covers server snapshots, roles/ownership, positive amount
bounds, tampering/direct catalog denial, pending duplication, terminal replay,
stale price/discount/reason, expiry/removal, orphan rejection, mapped HR Admin IDs,
escaping and atomic product/decision/audit rollback. Public and checkout prices
reflect approval. Separate Inventory/Finance browser contexts verify 10% off a
PHP12.50 product becomes PHP11.25; mobile reaches the rightmost owner action.
An initial browser label locator was corrected to the actual combobox role/name.
Earlier fixture and Blade corrections remain dated in PROGRESS.

Latest lint: 358 PHP files, zero failures. All 259 original legacy hashes are
checked, with three separately authorized startup bootstrap changes; the original
SQL backup hash matches. There are 47 native routes. Tests remain separate from
live compiled views/cache/sessions and do not send real mail. No dependency was
changed in this checkpoint. Physical scanners/printers, high-volume histories,
all legacy concurrency, full restore and whole-system parity remain unverified.

ProductInformationTest adds sixteen cases for manual state/role transitions,
Admin publication, stale complete-row/nutrient/provenance revisions, cross-product
revision misuse, clearing conflict flashes, web-source denial, label-derived
human review, audit rollback, unsafe source suppression and inactive/bounded IDs.
A dedicated Inventory/Admin browser flow verifies edit-to-hidden-to-public,
readonly facts, mobile rendering and unsaved-form decision disabling. The nested
textarea locator was corrected to its actual textbox role/name; final screenshots
were inspected and card/field spacing refined. Dedicated synthetic fixture actors
avoid consuming recovery codes from other scenarios. No native AI/photo processing,
real product review or external source request was executed.


RequestBoundaryTest adds six cases/210 assertions, including reproduced 500s
for oversized receipt IDs and NULL categories. Seven malformed IDs are checked
against all14 ID-bearing route templates with no record queries. Invalid body
IDs fail before operational/exists lookups; cached PHPUnit actors are not a claim
of zero authentication SQL in production. Product create/clear preserves the
non-null empty category contract, zero new stock and category-table count. Admin
Chromium creates/reopens an uncategorized product before inventory checks; all
nine browser scenarios pass. No asset rebuild was needed for these PHP-only fixes.

OwnProfileTest and OwnProfileConcurrencyTest add15 cases/144 assertions.
OwnProfileTestCase commits only tracked synthetic employees/users so the HR PDO
and independent PHP workers can see them, then deletes only its tracked rows and
minimal employee audits. Other tests retain their rollback strategies. Real
separate workers race case-variant emails behind a barrier; exactly one wins and
the lock is released. The worker requires CLI and verifies both target markers.
A fresh guarded pair before the full suite avoids absolute audit-count assertions
seeing prior browser mutations. Current full suite:131 tests/7,485 assertions,
no skips; all ten browser scenarios pass including synthetic own-profile saving,
MFA-address preservation, country input preservation and390px rendering. The
legacy public baseline currently runs after the separate shared-server recovery;
SKIP_LEGACY_BROWSER is only an explicitly reported outage fallback.

OwnScheduleTest adds9 cases/132 assertions on rollback-isolated synthetic
fixtures. Its fixed Manila clock crosses the UTC calendar boundary and exercises
weekly precedence, own approved bounded covering swaps, missing/inactive/invalid
shift times, seven-day order, distinct IDs, all ten native roles and explicit
private-field/no-business-write SQL assertions. The existing linked staff browser
flow now verifies My schedule, overnight and covering states and responsive
desktop/mobile captures. Current full results:140 PHP tests/7,617 assertions and
ten Chromium scenarios, no failures/skips/flaky results. This does not verify
actual clock-in, roster administration, leave, holiday or pay calculation.

OwnLeaveTest and OwnLeaveConcurrencyTest add16 cases/226 assertions. Their
committed fixtures are necessary for qualified-PDO/worker visibility; verified
teardown deletes only tracked own leave audit/log/request/credit dependencies
before owned employee cleanup. Two CLI-only synthetic workers, distinct POS/HR
actors sharing one employee, verify one pending request/audit and one replay.
Other cases cover all ten roles, input/identity tampering, read-only safe GET,
vacation fractional/inclusive bounds and calendar cooldown boundaries, uncapped
sick/emergency, HTML no-private-input errors, pagination/escaping and injected
audit rollback. The existing linked staff browser flow adds native leave submit,
JSON retry and desktop/mobile pending history. Latest complete results:156 PHP
tests/7,843 assertions and ten Chromium scenarios, no failures/errors/skips/flaky
results. Approval, payroll effects, legal entitlements and legacy concurrency are
outside this evidence.

LeaveApprovalTest adds13 cases/175 assertions; two independent process races
add13 assertions. Fixtures verify role/team queue privacy, actual HR actor IDs,
conservative duplicate-role resolution, moved/self teams, stale/cross-request/
cross-reviewer revisions, rejection/terminal behavior, current vacation caps,
uncapped tracked leave/storage bounds, start-year charging and atomic log/audit
rollback. Process races approve the same request and competing vacations against
one remaining balance, proving one deduction and no native overdraw. Scoped team
fixture cleanup runs before owned employee cleanup. RequestBoundaryTest now
checks all15 numeric-ID templates (+7 assertions). Fresh complete results:
171 PHP tests/8,038 assertions and eleven Chromium scenarios, no failures/errors/
skips/flaky results. The new browser uses separate HR and employee MFA sessions,
including a second once-only owner recovery code, to confirm approval and the
updated own balance. General HR dashboards/legacy writer races/payroll remain open.

OwnOvertimeTest adds10 cases/279 assertions, OvertimeEvidenceTest five/28, and
OwnOvertimeConcurrencyTest one/six:16 new cases/313 assertions. Verify exact
retrospective dates/decimal hours, ten roles, own safe projection, no attendance/
payroll mutation, private HTML errors, pending normalization, audit rollback,
escaping/paging, and pure early/overnight/fallback/missing/invalid/second-rounding
evidence. Real POS/HR workers share one employee and create one identical pending
request/audit. Synthetic attendance/weekly/request cleanup is scoped to tracked
owned IDs. The first feature attempt failed isolation-marker reads while3307 was
stopped; restart only that private instance and reset only the marked pair before
rerunning. Final full run:187 tests/8,351 assertions, zero failures/errors/skips.
Eleven native Chromium scenarios pass, including3.50 requested versus2.00 recorded excess,
normalized retry and no private GPS/financial data in responsive captures. One legacy baseline is explicitly skipped after a real HTTP503 failure; shared3306 has no listener. Use SKIP_LEGACY_BROWSER=1 only with this reported limit. No
supervisor approval, live attendance/payroll or historical schedule claim.

OvertimeApprovalTest adds13 cases/162 assertions and a real Admin/supervisor
process race adds one/six. Cover actual HR-only assigned scope, no unassigned or
other-team reasons, private fields, requested/actual separation, raw second/shift/
schedule/reviewer/request revisions, preserved requested ceiling and missing
evidence, rejection/terminal behavior, invalid legacy/ineligible cleanup and
audit rollback with no attendance/payroll write. Tracked team/shift/work cleanup
is isolated. RequestBoundaryTest now checks all16 numeric-ID templates (+7
assertions). Fresh full results:201 PHP tests/8,526 assertions, zero failures/
errors/skips. Twelve native Chromium scenarios pass, one explicit legacy outage
skip and no unexpected/flaky results. Supervisor/owner sessions verify the
approved3.50-hour ceiling stays separate from2.00 recorded excess. On-behalf,
legacy writer races, historical schedules and payroll execution remain unverified.

## Original UI comparison procedure, 2026-10-26

`OriginalUiTest` checks meaningful original-query, escape, image, filter and guard
handoff contracts on the verified pair. `tests/browser/original-ui.spec.js`
compares native home/initial login with original presentation read as text.
Reference renderers in `tests/Reference` are available only in testing and verify
the isolated nonce before rendering; no legacy config/bootstrap is included.
Use the same local font files and reduced motion for both pages to eliminate
time-dependent entrance animation rasterization. The eight-channel-value pixel
threshold and 0.2% maximum are unchanged; observed changed-pixel counts were zero
for all eight viewport/theme pairs. This is viewport, Chromium and initial-screen
evidence; it does not establish full application, auth policy or catalog pixels.
Catalog browser checks cover themes, overflow, original layout, auto-search and
verified-allergen filter. Existing native functional tests now use original
login/landing labels. Reset only the nonce-verified synthetic pair before a full
suite/browser run; prior browser transactions make absolute fixture counts stale.
Do not point fresh migrations/seeds at the live pair. The legacy browser baseline
remains explicitly skipped after the recorded shared-service failure.

## Product original-presentation checks

Run OriginalProductUiTest plus existing public facts/information/code/labels/
discount checks on the verified synthetic pair. Tests assert exact rendered
zero cells and absent unknown rows, escaping, original notices, guarded return
targets, no public writes/personnel/cost reads, related context and CSS mapping.
The original product browser scenario captures four full pages against pinned
source presentation with the same native synthetic data and local fonts.
No original backend code runs. Zero pixels exceeded the unchanged eight-value
threshold; full app/SQL/private-session/analytics parity is not inferred.
Generated view caching alone does not catch every PHP render error; actual
verified/unverified branch rendering caught and resolved an adjacent directive.

## Original comparison checks

OriginalComparisonUiTest covers minimum-cell behavior for zero/NULL/one-known
value, original single-product output, selected state and no public write/cost/
personnel query. OriginalUiTest verifies byte-identical source comparison CSS.
The browser original-comparison scenario measures initial viewport and selected
table in both themes/widths. It retains native selected picker values and does
not compare that selected full-page state. The pinned reference removes legacy
redirect/backend code and uses the same synthetic native verified data. All eight
comparisons pass the unchanged0.2% limit for pixels exceeding eight channel values.
Existing native serving/basis/category and publication/selection checks remain.
