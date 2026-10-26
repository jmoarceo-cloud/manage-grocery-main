# Database Backups (grocery_pos + hrms)

Automated daily backups for both databases using `mysqldump`, gzip-compressed,
with automatic rotation of old backups. Built for the current
Windows + XAMPP setup — no cron/Linux assumed.

## What it does

Running `backup_databases.bat` will:

1. Read backup credentials from the project's `.env` file. The backup account
   must be separate from the web application account — nothing is hardcoded,
   and the script refuses to fall back to `DB_USERNAME`/`DB_PASSWORD`.
2. Dump `grocery_pos` and `hrms` separately into
   `backup/files/<db>_<timestamp>.sql.gz`.
3. Delete backup files older than `BACKUP_RETENTION_DAYS` (default 14).
4. Append a log line per run to `C:\xampp\private\siaa-backups\backup_log.txt`.

## One-time setup

### 1. Confirm your XAMPP PHP path

Open `backup/backup_databases.bat` and check the `PHP_EXE` line matches
your install (default assumes `C:\xampp\php\php.exe`).

### 2. Add backup settings to your `.env`

Open `.env` in the project root (same file with `DB_HOST`, `DB_PASSWORD`,
etc.) and add:

```
MYSQLDUMP_PATH=C:\xampp\mysql\bin\mysqldump.exe
BACKUP_RETENTION_DAYS=14
BACKUP_DB_USERNAME=siaa_backup_local
BACKUP_DB_PASSWORD=use-a-unique-backup-password
```

(`BACKUP_DIR` is optional — it defaults to `C:\xampp\private\siaa-backups`,
outside the Apache document root. Set it explicitly if you want backups on a
different protected drive.)

### 3. Test it manually first

Double-click `backup_databases.bat`, or run it from a terminal:

```
cd "C:\xampp\htdocs\manage-grocery-main\project\backup"
backup_databases.bat
```

Check `C:\xampp\private\siaa-backups\backup_log.txt` and confirm two
`.sql.gz` files appear in `C:\xampp\private\siaa-backups`.

Run the read-only health check after the backup task:

```
php "C:\xampp\htdocs\manage-grocery-main\project\tests\backup_health.php"
```

It verifies both database dumps are recent and non-empty and checks the
recent backup log for errors. It exits with code 1 when monitoring should
raise an alert.

### 4. Schedule it with Windows Task Scheduler

1. Open **Task Scheduler** → **Create Task…** (not "Basic Task", so you get
   the "Run whether user is logged on or not" option).
2. **General tab**: name it e.g. `HCI GroceryOne DB Backup`. Check
   "Run whether user is logged on or not".
3. **Triggers tab** → New → Daily, pick a time when the system is idle
   (e.g. 2:00 AM).
4. **Actions tab** → New → Action: "Start a program" →
   Program/script: full path to `backup_databases.bat`
   (e.g. `C:\xampp\htdocs\manage-grocery-main\project\backup\backup_databases.bat`).
   Start in: the `backup` folder itself (so `%~dp0` resolves correctly).
5. **Conditions/Settings tabs**: leave defaults, or enable "Run task as
   soon as possible after a scheduled start is missed" if the PC is
   sometimes off at 2 AM.
6. Save. Right-click the task → **Run** once to confirm it works under
   Task Scheduler (not just when you double-click it yourself).

## Restoring a backup

```
"C:\xampp\mysql\bin\mysql.exe" -u root -p grocery_pos < restored.sql
```

Since backups are gzipped, decompress first (or pipe through `gzip -dc`
if you have it, e.g. via Git Bash):

```
gzip -dc grocery_pos_2026-08-22_020000.sql.gz | "C:\xampp\mysql\bin\mysql.exe" -u root -p grocery_pos
```

**Always restore into a fresh/empty database (or a test copy) first**
to verify the dump before overwriting live data.

## Notes / limitations

- The `.env` password is written momentarily to a temp "defaults file"
  (mode 0600) so it never appears in the Windows process list or Task
  Scheduler history; the temp file is deleted immediately after each
  dump, even on failure.
- This backs up data only. It does not back up `uploads/` (product
  images, employee/applicant documents) — those are plain files and can
  be backed up separately with a normal file copy/sync tool if needed.
- `backup/files/` and the external backup directory are excluded from git
  (see project `.gitignore`) since dumps contain real business/employee
  data.
