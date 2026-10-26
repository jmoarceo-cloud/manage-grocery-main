# GroceryOne documentation

Updated 2026-10-26. Root runs Laravel 13.38.0 through `public/`; `project/`
preserves the original custom PHP application. The owner authorized continued
implementation after Phase 0. Full workflow parity and final cutover are pending.
The current shared MariaDB outage is recorded in PROGRESS and INSTALL.

| Need | Document |
|---|---|
| Current implementation, actual checks and next work | [PROGRESS](PROGRESS.md) |
| Coverage and remaining acceptance requirements | [PARITY](PARITY.md) |
| Same-change tracking and local commit gate | [DOCUMENTATION_MAINTENANCE](DOCUMENTATION_MAINTENANCE.md) |
| Setup, private environments and test targets | [INSTALL](INSTALL.md) |
| Public GitHub source, included files and exclusions | [PUBLISHING](PUBLISHING.md) |
| Runtime structure and boundaries | [ARCHITECTURE](ARCHITECTURE.md), [FOLDER_STRUCTURE](FOLDER_STRUCTURE.md) |
| Database preservation and adoption | [DATABASE](DATABASE.md), [MIGRATION_NOTES](MIGRATION_NOTES.md) |
| Native routes and contracts | [API](API.md) |
| Auth, role gates and private information | [SECURITY](SECURITY.md), [PRIVACY](PRIVACY.md) |
| Interface and build measurements | [UI](UI.md), [PERFORMANCE](PERFORMANCE.md) |
| Repeatable checks and limitations | [TESTING](TESTING.md) |
| Native flow diagrams | [FLOWCHARTS](FLOWCHARTS.md) |
| Historical starting point and proposed port map | [AUDIT](AUDIT.md), [MIGRATION_MAPPING](MIGRATION_MAPPING.md) |
| Preserved workflow source of truth | [HCI_SYSTEM](../project/HCI_SYSTEM.md) |
| Current changes and contributor guidance | [CHANGELOG](../CHANGELOG.md), [AGENTS](../AGENTS.md) |

Audit artifacts include [redacted file inventory](audit/FILE_TREE.md),
[legacy entries](audit/LEGACY_ENTRYPOINTS.md), [source actions](audit/LEGACY_ACTIONS.md),
[original schema metadata](audit/DATABASE_METADATA.json),
[adoption counts](audit/DATA_ADOPTION.json), [native routes](audit/LARAVEL_ROUTES.json)
and [browser results](audit/UI_SMOKE.json), with [test totals](audit/NATIVE_TESTS.json).
Schema-only dumps contain no rows.
Full backups, credentials and uploaded personnel files remain private/ignored.

Archives and the Phase 0 validation record describe their dated state. They
are not present release or compliance evidence. Do not import historical SQL
or follow old default-account instructions against populated databases.
