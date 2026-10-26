# Current repository structure

Updated 2026-10-26. Laravel root and preserved custom PHP coexist during porting.

| Path | Purpose and boundary |
|---|---|
| `app/Http/Controllers`, `Requests`, `Middleware` | Native request handling, validation, session/role gates and headers |
| `app/Models` | Explicit POS/HRMS identity connections and legacy table mappings |
| `app/Services` | Auth, availability, sales/refunds/voids, discount approvals, label SVG generation, audit and identity mapping |
| `app/Support` | Verified isolated-target guard and session revocation versions |
| `app/Console/Commands` | Guarded synthetic-only reset override |
| `bootstrap/`, `config/`, `routes/`, `artisan` | Laravel bootstrap, configuration and routing |
| `resources/views`, `css`, `js` | Blade interface and Vite source |
| `public/index.php`, `.htaccess`, `build/` | Requested Laravel HTTP entry and generated assets |
| `project/` | Preserved original POS/HRMS runtime; separate login |
| `database/migrations`, `seeders` | Schema adoption/baseline and guarded synthetic fixtures |
| `database/backups/`, `project.bak.20261026/` | Protected full dump and source preservation; ignored, never formatter/test targets |
| `storage/` | Protected logs, file sessions/cache, local tool runtimes and synthetic test instance |
| `tests/Feature`, `Unit`, `support`, `browser` | Isolated HTTP/database, calculation, concurrency-worker and browser checks |
| `bin/` | CLI-only migration/provision/evidence/docs helpers; npm wrapper |
| `docs/`, `.ai/`, `.agents/`, `AGENTS.md` | Evidence, contributor rules and canonical project skills |
| `.githooks/pre-commit`, `bin/check-docs.php` | Local staged-documentation enforcement |

The old root MVC was copied before colliding directories were replaced.
No private upload tree was relocated or exposed through a generic native
file proxy. Original path/name inventories remain redacted in public evidence.
Root HTTP rules permit only the intended `public/` and `project/` trees;
Apache's global DocumentRoot and unrelated projects were not reconfigured.

`pint.json` excludes backups, original runtime, staging and local storage.
Run tools only over native source paths. Runtime dependencies, compiled assets,
private environments, data backups and `.kombai` remain on disk and Git-ignored.

Own-account security uses AccountSecurityController, ChangeOwnPasswordRequest,
AccountSecurityService, IdentityTransaction and RecoveryCodes, with its Blade
form under resources/views/account. The transaction helper is specific to
validated same-server linked identities. config/view.php separates compiled
test views from live application views.

PublicProductCodeController and resources/views/public/code-lookup.blade.php implement the bounded public resolver; PublicProductCodeTest and Chromium cover validation, resolution and responsive rendering.

ProductDiscountRequest, ProductDiscountController and ProductDiscountService implement the native proposal/decision boundary. The workspace view is resources/views/workspace/product-discounts.blade.php; ProductDiscountTest and the existing Inventory/Finance Chromium flow verify it.

ProductInformationRequest/Controller/Service and resources/views/manage/product-information.blade.php implement manual review. The existing PublicProductFacts source filter is shared for safe references. ProductInformationTest and a dedicated synthetic Inventory/Admin browser flow verify state, revision, source and publication boundaries.

OwnProfileController, OwnProfileRequest and OwnProfileService own the bounded personal form in resources/views/account/profile.blade.php. ProfilePhone/config/profile.php contain legacy-compatible phone choices. OwnProfileTestCase tracks committed synthetic fixtures for cross-connection/process visibility; OwnProfileTest, OwnProfileConcurrencyTest and CLI-only tests/support/profile-worker.php verify privacy, atomicity and native duplicate-email contention.

OwnScheduleController, OwnScheduleService and resources/views/account/schedule.blade.php implement the bounded own schedule view. OwnScheduleTest covers ownership, existing shift semantics, privacy and no business writes; the linked staff Chromium flow captures recurring/covering desktop and mobile layouts.

OwnLeaveController, OwnLeaveRequest, OwnLeaveService and config/leave.php own native self-service leave under resources/views/account/leave.blade.php. OwnLeaveTestCase tracks committed synthetic leave dependencies; OwnLeaveTest and OwnLeaveConcurrencyTest plus CLI-only tests/support/leave-worker.php verify privacy, policy, rollback and cross-domain pending retries.

LeaveApprovalPolicy/Service, LeaveApprovalController and LeaveDecisionRequest implement native review in resources/views/workforce/leave-approvals.blade.php. LeaveApprovalTestCase tracks synthetic team dependencies; LeaveApprovalTest/ConcurrencyTest and CLI-only leave-decision-worker.php cover scope, stale revisions, audit rollback and real credit contention.

OwnOvertimeController/Request/Service and resources/views/account/overtime.blade.php implement own overtime. OvertimeEvidence provides bounded pure time presentation. OwnOvertimeTestCase/Test/ConcurrencyTest and CLI-only overtime-worker.php verify tracked synthetic records; OvertimeEvidenceTest covers current-shift/fallback, early, overnight, missing/invalid and second-rounding cases.

OvertimeApprovalController, OvertimeDecisionRequest and OvertimeApprovalService implement team-only review in resources/views/workforce/overtime-approvals.blade.php. OvertimeApprovalTestCase/Test/ConcurrencyTest track isolated team/shift/work fixtures; CLI-only overtime-decision-worker.php verifies same-request contention. OvertimeEvidence remains the shared read-only presenter.
