# HCI GroceryOne

GroceryOne is a web-based grocery store management system combining point of
sale, inventory, purchasing, finance and human resources. It also provides a
public product catalog with reviewed information and staff self-service.

**Maintainer:** John Mark O. Arceo
**Contact:** [jmoarceo@pcu.edu.ph](mailto:jmoarceo@pcu.edu.ph)
**GitHub:** [jmoarceo-cloud/manage-grocery-main](https://github.com/jmoarceo-cloud/manage-grocery-main)

**Status: active Laravel migration; full feature and original-interface parity
are not complete.** Source publication does not deploy a working store or
establish production readiness. The migration changes the technology stack
while preserving the original layouts, styling, wording and workflows.

## Why there are two application paths

Both versions belong to the same project. The original application remains
available while workflows are ported to Laravel.

| Application | Source | Local URL |
|---|---|---|
| New Laravel application | Root app/, routes/, resources/; HTTP entry public/index.php | http://localhost/manage-grocery-main/public/ |
| Preserved custom PHP/PDO application | project/, including project/HRMS/ | http://localhost/manage-grocery-main/project/index |

The bare `http://localhost/manage-grocery-main/` URL currently redirects to
`project/`. Open `/public/` explicitly for Laravel. The two applications have
separate sessions and logins; some native navigation still opens original pages.
Backups and framework staging directories are not additional app versions.

## System features and migration coverage

The original PHP application contains the broader workflow set. The table below
describes bounded native Laravel implementations, not complete module acceptance.
See the [parity ledger](docs/PARITY.md) for named checks and remaining requirements.

| Area | Available in native Laravel | Still legacy or awaiting full verification |
|---|---|---|
| Public storefront | Home, catalog/search, active product pages, verified nutrition comparison, manual/handheld product-code lookup | Private kiosk/camera access, scan analytics and remaining navigation contracts |
| Authentication | POS/HR identity handling, password plus email/recovery-code verification, role/status/session checks, own password changes | Broader reset/user administration and complete legacy session synchronization |
| Products and inventory | Product/category/supplier CRUD, stock filters, administrator restocking, barcode/shelf labels, product discounts, manual information review | Procurement-linked restocks, supplier approvals/ratings, AI/photo/full nutrition editing and other inventory workflows |
| Cashier POS | Server-priced checkout, FEFO batch allocation, request-key retries, receipts, scoped sales, approved discounts/refunds and same-day owner voids | Full register, reporting, device and financial reconciliation acceptance |
| Staff self-service | Own personal/contact information, recurring/covering schedule, leave balances/history/requests, overtime requests and recorded-work display | Attendance, avatars, released payslips, official business and broader self-service |
| HR approvals | Scoped leave decisions/credit updates and assigned-team overtime decisions | Recruitment, employee administration, team dashboards, scheduling management, payroll and HR letters |
| Purchasing and finance | Cashier/product discount decisions and bounded sales views | Purchase orders, signatures, partial receiving, payables, payments, budgets, expenses, ledgers and financial reports |
| Privacy and communication | Native field minimization and minimal domain audit where implemented | Full privacy-request, retention, notification and document-delivery workflows |

Native access retains the distinct POS roles (`admin`, `cashier`,
`inventory_staff`, `finance`) and HR roles (`admin`, `hr`, `hr_manager`,
`supervisor`, `employee`, `accountant`). Each route has its own restrictions;
having a role does not grant every feature in the table.

## Technology stack

| Layer | Technology |
|---|---|
| Native backend | Laravel 13.38.0, PHP 8.3+, Composer |
| Native interface | Blade, Vite 7, Tailwind CSS 4, original CSS and JavaScript |
| Preserved application | Custom PHP with PDO; original POS and HRMS pages |
| Database | MariaDB/MySQL, separate POS and HR domains (grocery_pos, hrms) |
| Local server | Apache/XAMPP; Laravel is served through public/ |
| Verification | PHPUnit, Playwright/Chromium, isolated synthetic fixtures |
| Asset tooling | Node.js 24 LTS and npm |

AcademiSync is a technology-stack reference only. Its UI, records and academic
modules are not part of GroceryOne. Original-style restoration is complete only
for the specifically documented screens; other native screens remain in progress.

## Getting started from a fresh checkout

Install PHP 8.3+ with the extensions required by `composer.json`, Composer,
Node.js 24 LTS, and a compatible MariaDB/MySQL server. The old XAMPP PHP 8.2
binary is not suitable for this Laravel version.

```powershell
git clone https://github.com/jmoarceo-cloud/manage-grocery-main.git
cd manage-grocery-main
Copy-Item .env.example .env
composer install --no-interaction
php artisan key:generate
npm.cmd ci
npm.cmd run build
```

Configure the private `.env` with your own database names, scoped credentials,
application URL and mail settings. Laravel authentication needs working email
delivery or an already enrolled recovery code. No real account credentials or
ready-made business database are supplied in this repository.

The original PHP app uses its separate `project/.env`; start from
`project/.env.example` only when deliberately configuring that application.
Public product images and private upload contents are not distributed, so some
existing records may require locally supplied assets.

For the documented XAMPP setup, place the checkout in
`C:\xampp\htdocs\manage-grocery-main`, start Apache and the intended database
server, then open the URLs above. A fresh clone needs database provisioning and
the reviewed schema procedure before database-backed pages can work.

Follow [installation](docs/INSTALL.md), [database structure](docs/DATABASE.md)
and [migration notes](docs/MIGRATION_NOTES.md) before running database commands.
Some helpers describe one-time operations on the owner's installation; they
are not an automatic fresh-install wizard. Do not run `migrate:fresh`, seeders
or imports against populated business databases.

The owner's installation adopted both original database domains in place. The
baseline contains 42 POS and 35 HR tables; native infrastructure is additive.
Public SQL artifacts contain schema/migration source, not business records or
a complete recovery backup.

## Verification and current limits

The recorded [native test result](docs/audit/NATIVE_TESTS.json), dated
2026-10-26, reports **216 PHP tests / 8,662 assertions**, and **17 browser
passes / 1 explicit legacy-outage skip**. These are prior synthetic verification
results, not tests rerun by the publication process. They do not cover every
workflow or establish full original-interface parity.

Shared database availability has changed during local recovery work. The dated
[progress log](docs/PROGRESS.md) records outages and successful checks;
publication makes no new availability claim and does not start the server.

Read [testing](docs/TESTING.md) and verify a marked disposable database pair
before running any DB-backed suite. No real employee, customer, payroll or
transaction records belong in test fixtures or GitHub issues.

## Repository map

```text
app/                 Laravel controllers, requests, models and services
bootstrap/, config/  Laravel startup and environment-based configuration
routes/              Native web and console routes
resources/           Blade views, CSS, JavaScript and licensed fonts
public/              Laravel web entry and deliberate public assets
project/             Preserved original PHP/PDO POS and HRMS application
database/            Reviewed migrations and schema source; no business dumps
tests/               Synthetic feature, calculation, concurrency and UI checks
bin/                 Reviewed CLI setup, migration, test and documentation tools
docs/                Architecture, routes, setup, evidence and migration status
```

Dependencies (`vendor/`, `node_modules/`), environment files, credentials,
database backups, uploads, local test-server data and old private Git history
are excluded from the public source snapshot. See [publication notes](docs/PUBLISHING.md).
Third-party libraries and fonts retain their included license notices.

## Documentation and contribution

- [Documentation index](docs/README.md)
- [Original system and workflow reference](project/HCI_SYSTEM.md)
- [Architecture](docs/ARCHITECTURE.md) and [folder structure](docs/FOLDER_STRUCTURE.md)
- [Routes/API](docs/API.md), [security](docs/SECURITY.md) and [privacy](docs/PRIVACY.md)
- [Migration mapping](docs/MIGRATION_MAPPING.md), [parity](docs/PARITY.md) and [progress](docs/PROGRESS.md)
- [Original-interface restoration](docs/UI.md) and [test procedure](docs/TESTING.md)
- [Changelog](CHANGELOG.md) and [contributor instructions](AGENTS.md)

Preserve the original UI and business behavior, both database domains and the
separate role/identity boundaries. Use synthetic fixtures, keep secrets and
private records out of commits, and update documentation in the same meaningful
change. Enable the local documentation hook with
`git config core.hooksPath .githooks`; remote CI is not currently configured.

Earlier local README notes are preserved as a dated
[historical snapshot](docs/archive/2026-10-26-pre-publication-README.md).
