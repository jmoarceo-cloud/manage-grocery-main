# GroceryOne documentation maintenance rule

Established 2026-10-26 from the requested AcademiSync tracking workflow. Only
its documentation policy was read; no application records/code were imported.
Update documentation **in the same change** as meaningful code, setup, scope,
security or verification work. Documentation is part of completing the change.
This rule does not authorize DB writes/releases or bypass a user checkpoint.

## Authorities

| Question | Authority |
|---|---|
| Current starting point | `docs/AUDIT.md`; legacy workflow map `project/HCI_SYSTEM.md`. |
| Work, checks, limitations and next step | `docs/PROGRESS.md`. |
| Migration coverage/evidence | `docs/PARITY.md`, backed by named tests/artifacts. |
| Proposed screen/file mapping | `docs/MIGRATION_MAPPING.md`; proposals remain proposals. |
| Scope and safety | Root `AGENTS.md` and current user request. |
| Navigation | `docs/README.md`. |

Root now runs Laravel 13.38.0; the preserved `project/` app remains custom PHP.
The owner's continuation superseded the Phase 0 stop. Follow live PROGRESS,
API and parity evidence; the Phase 0 audit is a dated starting point.

## Same-change updates

For every meaningful change, update root `CHANGELOG.md` with dated what/why
and `docs/PROGRESS.md` with outcomes, exact checks, limits and next work.
Update `docs/PARITY.md` when implementation or evidence changes a claim.
Also update the relevant documents:

| Change | Documents |
|---|---|
| Feature/business behavior | `project/HCI_SYSTEM.md` for affected system behavior, parity and progress. |
| Structure | `docs/ARCHITECTURE.md`, `docs/FOLDER_STRUCTURE.md`, mapping as applicable. |
| Schema/index/FK/trigger/data adoption | `docs/DATABASE.md`, migration/rollback notes and bounded data evidence. |
| Route/method/API contract | `docs/API.md`, including access requirements and errors. |
| Auth/roles/session/CSRF/uploads/headers/audit | `docs/SECURITY.md`; privacy when personal data is affected. |
| PII/downloads/retention/external processing | `docs/PRIVACY.md`, including access boundary, configured policy and limits. |
| Setup/dependency/PHP/operations | `README.md`, `docs/INSTALL.md`, applicable migration notes. |
| UI/navigation | `docs/UI.md`, parity and actual visual evidence when captured. |
| Performance | `docs/PERFORMANCE.md`, actual conditions/timings; no invented baselines. |
| Tests/verification | `docs/TESTING.md` if procedure changes; progress/parity with commands, counts, skips and limits. |
| New document/authority/path | `docs/README.md`. |

Create related documents when authorized work first touches that area; do not
fill future documents with claims of implemented controls or passing tests.

## Evidence and history

- **Planned:** not implemented in Laravel.
- **Code present only:** source exists without meaningful behavior evidence.
- **Tested but incomplete:** named checks passed; material coverage remains.
- **Verified:** the bounded row has implementation and meaningful named
  verification, with limits alongside it.

Check parity boxes only for verified rows. GET 200, syntax passes, simulated
helper tests or similar controller names do not establish workflow parity.
Never translate a passing check into deployment/legal-compliance claims.

Keep historical release/progress records. Add dated corrections when new
evidence contradicts them; do not make old claims appear freshly tested.
Keep secrets, credential values, personnel/customer records, original private
document filenames and real-data fixtures out of docs/evidence. Use aggregate
counts, metadata, placeholders and synthetic fixtures.

## Enforcement status

The local Git repository uses `core.hooksPath=.githooks`. Its pre-commit hook
runs `bin/check-docs.php --staged`, requiring changelog/progress plus related
API/schema/security/privacy/UI/testing/setup documents for detected code paths.
A staged auth change lacking the required docs was correctly rejected.
The checker does not boot the app or query a database. Documentation-only work
is allowed. This is a filename-presence gate; text accuracy still needs review.
Remote CI was not installed. Phase 0's lack of enforcement is historical.
