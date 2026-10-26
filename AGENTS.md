# GroceryOne contributor and AI instructions

## Owner correction - original UI and behavior, 2026-10-26

This is a technology-stack migration only. Preserve the original `project/`
layouts, styling, fonts, icons, wording, navigation and feature workflows.
Do not redesign screens or add features without a new explicit owner request.
The AcademiSync Laravel project is a stack reference (Laravel 13, PHP 8.3+,
Blade, Vite/Tailwind), not a UI or data source. Previously redesigned native
screens must be brought back to the original UI; earlier redesign checks do
not establish visual parity. Keep existing work safely preserved while doing so.

Read [README](README.md), [system documentation](project/HCI_SYSTEM.md), [.ai/project-context.md](.ai/project-context.md), [.ai/rules/index.md](.ai/rules/index.md), and [progress](docs/PROGRESS.md). Load only the relevant skill from [.ai/README.md](.ai/README.md).

The preserved application is custom PHP/PDO under `project/`. The explicitly
authorized migration now runs Laravel 13 at root, served through `public/`.
Keep legacy runtime files available while porting remaining workflows. Trace
validation, roles/sessions, DB effects and the affected POS/HR flow before changes.

Use the current user request as task scope. The AI setup request changes guidance and tooling only. Do not modify runtime behavior, read business records, run migrations, seed data or execute operational backups merely to configure tools. Ordinary reversible work within a later authorized task does not need repeated permission.

Keep secrets and employee/customer records out of prompts, logs and evidence. Verify disposable test targets and inspect script side effects before execution; default DB names do not prove isolation. Do not run or include PHP configuration files for read-only inspection. Never copy real records between projects.

Record exact meaningful checks and their limits; avoid claiming deployment readiness from source review. Update HCI_SYSTEM for behavior changes and docs/PROGRESS for work outcomes. Preserve existing user work and `.kombai`. Do not commit, publish, deploy or send external messages unless requested.

## Laravel migration and documentation rule - 2026-10-26

The current owner explicitly requests migration to Laravel. This supersedes
the restriction against adding Laravel merely to mirror another project's
tooling. The attached execution brief requires Phase 0 audit/mapping/parity,
then the user's go before Phase 1. Honor that checkpoint. Existing legacy and
root MVC apps remain custom PHP until framework installation is verified.

The owner's subsequent request on 2026-10-26 explicitly says to continue
without questions until their usage limit. That is the go for the remaining
migration phases; do not repeat the Phase 0 confirmation. Continue reversible
implementation, backups, isolated tests and documentation autonomously. Keep
destructive live-data operations, external publication and record disclosure
outside routine implementation.

Follow [documentation maintenance](docs/DOCUMENTATION_MAINTENANCE.md): update
root CHANGELOG.md and docs/PROGRESS.md in the same meaningful change; update
architecture/database/API/security/privacy/UI/performance documents when their
area changes. Keep docs/PARITY.md backed by actual evidence. Automated
commit enforcement is now configured through `.githooks/pre-commit` and
`bin/check-docs.php`; remote CI is not installed. The earlier Phase 0 lack of
enforcement is historical.

Read [audit](docs/AUDIT.md), [mapping](docs/MIGRATION_MAPPING.md) and
[parity](docs/PARITY.md) before migration work. Preserve both database domains
and existing root work before replacing colliding paths. Use synthetic data
on specifically verified isolated test targets; never direct destructive
migration/seed/test commands at the live pair.
