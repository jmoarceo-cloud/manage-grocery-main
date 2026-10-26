---
name: groceryone-project-work
description: "Plan, implement, debug or review groceryone project workflows while preserving its stack, data boundaries and current task scope."
---

# groceryone-project-work

Use this workflow for GroceryOne's authorized root Laravel migration and preserved custom PHP/PDO application. Read [project context](../../../.ai/project-context.md), [AGENTS](../../../AGENTS.md) and the relevant section of [HCI_SYSTEM](../../../project/HCI_SYSTEM.md).

1. Trace the affected page, shared POS/HR include, role guard, database effect and existing contract tests. Inspect source as text; including configuration can connect to databases or perform runtime schema work.
2. Root Laravel is explicitly authorized by the owner. Preserve legacy runtime and source backups while porting traced workflows. Follow the verified native structure at root; do not install frameworks merely to mirror a neighboring project's tooling. Exclude original runtime, snapshots and private backups from automated formatting.
3. For POS/stock/refund/payroll changes, identify the transaction, retry/reconciliation behavior, approval actor and audit consequence. Keep POS and HR identity synchronization and private personnel boundaries visible.
4. Select existing relevant checks after inspecting their imports, side effects and target configuration. Use an explicitly verified isolated database for DB-backed checks. `php -l` verifies syntax only; report that limit.
5. Update HCI_SYSTEM when behavior changes and docs/PROGRESS with the current task and actual checks. Do not copy existing source status into a fresh deployment-ready claim.

This skill preserves the current request's scope. AI tooling setup alone does not request business-feature changes, migrations, payroll execution or operational backups.
