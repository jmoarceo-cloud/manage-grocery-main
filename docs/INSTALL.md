# GroceryOne local installation and migration operations

Updated 2026-10-26. Local runtime: Laravel 13.38.0, Apache PHP 8.4.26,
PATH PHP 8.3.35, MariaDB, Node 24.22.1 LTS and Composer 2.10.3. Dependencies are
locked for PHP 8.3.35 so both active PHP binaries work. No global PHP/Apache
files were modified. The old XAMPP PHP 8.2 executable is unsuitable for Laravel.

## Original PHP application startup recovery - 2026-10-26

The original custom PHP entry is
`http://localhost/manage-grocery-main/project/index`. Apache and shared
XAMPP MariaDB on port 3306 now serve it; homepage/catalog HTTP 200 and Chromium
rendering were checked. Start the normal database with its existing my.ini;
the port-3307 synthetic server does not replace the real databases.

Two MariaDB system tables were repaired after private offline backup and a
verified recovery candidate. All 14 database permission rows were recovered or
reconstructed from existing setup, without business table file replacement or
account/password reset. Recovery copies are ACL-restricted outside HTTP/Git.
This is not a routine repair recipe or complete cross-project restore test;
see PROGRESS before further repair. No global configuration was edited.

Legacy DB_PORT defaults to 3306. All seven legacy PDO connections specify a
three-second connect option. Public bootstrap connections also use a
three-second mysqlnd network-read limit, including query reads; the connect
option alone did not bound a stalled server greeting.

## Current local installation

Open `http://localhost/manage-grocery-main/public/`. Root `.htaccess` permits
only the public and legacy trees and blocks nonpublic siblings, dotfiles,
SQL/backups and office documents. This protects the requested subdirectory
URL; Apache's global DocumentRoot was not changed. Legacy remains available.

Root `.env` privately selects the CRUD-only web identity and both real schemas.
File sessions/cache avoid framework tables in HRMS. The session cookie has a
separate name and `/manage-grocery-main/public` path. Debug is false and time
is Manila. Existing SMTP settings were privately mapped for user-triggered
verification; no real mail was sent during implementation/testing.

```powershell
composer install --no-interaction
php artisan about
php artisan route:list --except-vendor
php artisan view:cache
bin\npm.cmd run build
```

The portable Node ZIP was checked against the official SHA-256 manifest before
extraction into ignored storage. A fresh checkout can use installed Node 24
LTS with `npm.cmd ci` and `npm.cmd run build`. Build outputs/node_modules stay
ignored. Composer setup scripts do not automatically migrate or seed databases.

## Isolated tests

Private `.env.testing` selects the newly created disposable POS/HRMS pair,
restricted test account and creation nonce. `IsolatedDatabase::verify()` checks
APP_ENV, both exact names and marker rows before writes. Names alone do not
establish disposal. No real records were restored into the targets.

```powershell
php artisan migrate --env=testing --no-interaction
C:\xampp\php84\php.exe vendor\bin\phpunit --no-progress
```

The migration builds all 77 original tables/constraints/indexes and both
stock-log protection triggers from schema-only evidence. Feature tests wrap
both domains in rollback transactions; separate-process contention tests use
committed synthetic fixtures and the guarded pair reset. No live fresh migration
or production seeder is part of this procedure. Another machine must explicitly provision
disposable databases, markers and private credentials.

## Live adoption already executed

The one-use `bin/adopt-laravel.php` verified the full-backup hash, provisioned a
separate migration identity, restored scoped access for the existing legacy
principals and ran `artisan migrate --env=migration --force`. It validated
original columns without rewriting business tables and added three POS tables:
`migrations`, `laravel_checkout_requests`, `application_audit_events`.
All 77 counts matched the pre-migration backup; see
[DATA_ADOPTION](audit/DATA_ADOPTION.json).

Bootstrap scripts refuse to overwrite artifacts/identities. They are not
routine commands or credential-rotation tools. Future schema changes require
reviewed migrations, backups, isolated tests and the migration environment.
Automatic live rollback is disabled to protect records.

## Documentation gate

Local Git uses `core.hooksPath=.githooks`; pre-commit runs
`php bin/check-docs.php --staged`. A staged auth change without changelog,
progress, security and privacy updates was correctly rejected. This checks
filenames, not text accuracy; no remote CI service or deployment was installed.
Private backups, environments, uploads and personnel documents are ignored.

## Earlier shared-server outage and separate synthetic instance

Before the original-PHP recovery above, shared MariaDB startup failed: mysql.db was marked crashed
and automatic safe repair reports it cannot recover all expected privilege rows.
No cause was established and no manual repair, grant-table replacement or live
business-data restore was performed. Do not follow destructive repair recipes
against this multi-project data directory as a migration step. Apache was
restarted without changing its configuration; native login is available.

Synthetic testing now uses an independent initialized data directory inside
this repository, bound to 127.0.0.1:3307. It uses the existing MariaDB 10.4.32
binary without changing any global configuration. This is a test workaround,
not a recovery of the shared server. Bootstrap once into a NEW directory only;
never rerun initialization over an existing database directory:

```powershell
# Already initialized locally; shown as the reproducible procedure only.
C:\xampp\mysql\bin\mysql_install_db.exe --datadir=C:\xampp\htdocs\manage-grocery-main\storage\testing\mariadb --port=3307 --silent
# Run server in its own retained terminal/process session.
C:\xampp\mysql\bin\mysqld.exe --defaults-file=C:/xampp/htdocs/manage-grocery-main/storage/testing/mariadb/my.ini --bind-address=127.0.0.1 --innodb-use-native-aio=0 --tmpdir=C:/xampp/htdocs/manage-grocery-main/storage/testing --console
```

The local attempt launched through a detached helper encountered Aria file-I/O
errors and an InnoDB assertion. It was preserved under ignored test storage;
the fresh instance in a retained process passed the database suite. The cause
of that helper failure is unconfirmed. Do not reuse its failed data directory.

With the separate server running, `php bin/prepare-test-server.php` verifies
@@datadir resolves to the exact project test directory, creates only the existing
nonce-named pair/markers and scoped account, sets a private random admin password,
and privately updates .env.testing to port 3307. No root password is printed.
Tests use array/fake mail. Production .env remains directed at the original
business databases; it was not pointed at synthetic fixtures.

Use guarded `php artisan migrate:fresh --env=testing --seed` only after this
verification. Concurrency tests require committed synthetic fixtures and reset
only the verified pair after each test. Browser tests also leave synthetic
changes; reset before repeating. To stop the separate server, use an authenticated
SHUTDOWN through `php bin/stop-test-server.php` rather than killing its process.

Browser testing now starts `php -S 127.0.0.1:8017 -t public bin/qa-router.php`
with APP_ENV=testing, MAIL_MAILER=array and SESSION_PATH=/. This router allows
only build CSS/JS and favicon/robots as direct files; other paths enter Laravel.
Use it for the synthetic server: ordinary artisan serve ignores Apache .htaccess.
prepare-test-server.php creates separate testing cache/session directories.
No test cache flush or authentication state operates on the staff file stores.
## Native product labels

BaconQrCode 3.1.1 and dasprid/enum 1.0.7 are locked dependencies. SVG generation
uses XMLWriter (now an explicit platform requirement) and iconv; no Imagick,
cloud QR endpoint or new browser decoding dependency is required. Composer
strict validation/platform checks and the current PHP 8.4 feature suite pass;
the browser server uses PATH PHP 8.3.35 and renders/decodes the same QR output.

Shelf URLs use the configured APP_URL plus /catalog/<public-product-id>. Keep
APP_URL correct for the intended store hostname before printing labels for
other devices. The local localhost URL is appropriate only on this machine.
Tests explicitly use the isolated server's 127.0.0.1:8017 base URL. No global
Apache/PHP settings or shared database schema were changed.

Implementation references: [BaconQrCode SVG backend](https://github.com/Bacon/BaconQrCode)
and [GS1 check-digit calculation](https://www.gs1.org/services/how-calculate-check-digit-manually).
The renderer uses existing product barcodes; it does not assign new identifiers.

Native password changes require both identity schemas on the same MySQL server,
matching scoped credentials with existing CRUD rights on both schemas, distinct
validated database names and InnoDB users tables. Unsupported topology is refused
before password writes; do not introduce a privileged fallback. No extra DDL or
live migration is required by this feature. The preparation helper also creates
storage/framework/views/testing; compiled test views remain separate from live
views to avoid shared Windows file replacement conflicts.

The native product-code page uses existing catalog tables and runtime permissions;
no migration, external service or camera permission is required. It is served at
/public/catalog/scan. Shared-server recovery is recorded separately in PROGRESS;
this port rechecked HTTP200 for both homepages and lookup. Synthetic DB suites
remain on their marked3307 pair; they are never redirected to the restored3306
databases.
