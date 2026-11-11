# Installation & Deployment Guide
## AutoPro Garage Management System with Secure Data Storage and Auditing

**Target Environment:** Localhost / Intranet under MAMP for Windows  
**PHP Version:** PHP 8.1, 8.2, or 8.3  
**Database:** MariaDB / MySQL 5.7+ / 8.0+ (InnoDB utf8mb4)  
**Dependencies:** Zero external dependencies (No Composer, No npm, No external CDNs)  

---

## 1. System Requirements & Prerequisites

### 1.1 Software Requirements
- **MAMP for Windows** (v5.x or higher) installed in `C:\MAMP\`.
- **Apache Web Server** (included in MAMP).
- **MySQL / MariaDB Database Server** (included in MAMP, default port `3306`).
- **Modern Web Browser** (Google Chrome, Microsoft Edge, Mozilla Firefox).

### 1.2 Mandatory PHP 8.x Extensions
Ensure the following standard native extensions are enabled in your MAMP `php.ini` (`C:\MAMP\bin\php\php8.x.x\php.ini`):
- `pdo_mysql` (Database PDO driver)
- `openssl` (AES-256-GCM authenticated encryption)
- `mbstring` (Multibyte string handling)
- `session` (Secure native session management)
- `json` (Audit log serialization and API payloads)

---

## 2. Step-by-Step Installation Procedure

### Step 1: Place Source Code in MAMP Web Root
Ensure the project files reside in your MAMP `htdocs` directory:
```
C:\MAMP\htdocs\garage-management-system (1)\garage-management-systems\
```

The core directory layout will look like:
```text
garage-management-systems/
├── app/
│   ├── config/
│   │   ├── database.php
│   │   ├── app.php
│   │   └── local.php.example
│   ├── models/
│   ├── services/
│   └── views/
├── database/
│   ├── database.sql
│   └── migrations/
├── documentation/
├── public/
│   ├── assets/
│   ├── customers/
│   ├── vehicles/
│   ├── appointments/
│   ├── job-cards/
│   ├── inventory/
│   ├── invoices/
│   ├── payments/
│   ├── services/
│   ├── suppliers/
│   ├── reports/
│   ├── audit/
│   └── index.php
├── storage/
│   ├── private/
│   └── logs/
└── tests/
```

---

### Step 2: Database Initialization & Schema Import

1. **Start MAMP:** Open MAMP control panel on Windows and click **Start Servers**. Ensure both **Apache** and **MySQL** indicators turn green.
2. **Access phpMyAdmin:** Open your browser and navigate to:
   ```
   http://localhost/phpmyadmin/
   ```
3. **Create Database:**
   - Click on the **Databases** tab.
   - Enter Database name: `garage_management_system`.
   - Collation: `utf8mb4_unicode_ci`.
   - Click **Create**.
4. **Import the baseline schema into this new, empty database only:**
   - Select the newly created `garage_management_system` database in the left sidebar.
   - Click on the **Import** tab.
   - Click **Choose File** and browse to:
     ```
     C:\MAMP\htdocs\garage-management-system (1)\garage-management-systems\database\database.sql
     ```
   - Click **Import** at the bottom of the page.
   - The baseline creates the initial schema, triggers, roles, permissions, catalog, and suppliers.

> **Warning:** `database/database.sql` contains destructive `DROP TABLE IF EXISTS` statements. Never import it into a database with data. Use the tracked migration runner for later schema updates.

### Step 3: Configure Database Accounts and Environment

The application does not load `.env` files automatically. For a local MAMP setup, copy `app/config/local.php.example` to the git-ignored `app/config/local.php` and enter the database host, port, name, dedicated application username, and password. Alternatively, configure the corresponding `GMS_DB_*` variables in Apache and restart it. Environment variables take precedence over `local.php`; never use the MySQL root account for web requests.

1. As a database administrator, edit the password placeholders in `database/least_privilege.sql` and run it against the database server. Use distinct, high-entropy passwords for `garage_app` and `garage_migrator`. The web application must use only the `garage_app` account; do not use `root`.
2. Either copy `app/config/local.php.example` to `app/config/local.php` and enter the matching `garage_app` credentials, or set `GMS_DB_HOST`, `GMS_DB_PORT`, `GMS_DB_NAME`, `GMS_DB_USER`, and `GMS_DB_PASS` in the Windows/Apache environment. `local.php` is git-ignored, and the `app` directory is denied by Apache. Set `GMS_DB_TZ` in the environment or `tz` in `local.php` if the default `+03:00` is not appropriate. Restart Apache after changing environment variables.
3. Keep `GMS_MIGRATION_DB_USER` and `GMS_MIGRATION_DB_PASS` out of Apache's environment. Supply them only to the administrative CLI process running migrations.

`.env.example` documents the supported variable names, but PHP does not load that file automatically. Do not place production secrets in the web document root or commit them to source control.

### Step 4: Apply Versioned Migrations

From the project directory, set the database variables for the CLI session and run:
```powershell
$env:GMS_DB_HOST = "127.0.0.1"
$env:GMS_DB_PORT = "3306"
$env:GMS_DB_NAME = "garage_management_system"
$env:GMS_DB_USER = "garage_app"
# Set GMS_DB_PASS and the migration-only credentials securely in this session.
& "C:\MAMP\bin\php\php8.3.1\php.exe" "database\migrate.php"
```

The runner is CLI-only, checks the baseline schema, and records checksums in `schema_migrations`. Migrations are forward-only: do not edit an applied SQL file; add a new migration and prepare a tested restore/forward-repair plan before production deployment. Back up the database before applying schema changes. The expense-ledger migration adds expenses and finance permissions without dropping data. On an existing installation with per-table grants, after applying that migration run `GRANT SELECT, INSERT ON garage_management_system.expenses TO 'garage_app'@'localhost';` as an authorized database administrator; do not rerun the credential script to add this grant.
**Expense ledger migration (20261108_expense_ledger.sql) — exact operator steps (MAMP/phpMyAdmin or MySQL CLI):**
1. Back up the database: `mysqldump -u <backup-user> -p garage_management_system > backup-before-expense-ledger.sql` (run from MAMP `bin\mysql\bin` or any MySQL client).
2. Apply via the tracked runner (recommended, records checksum in `schema_migrations`):
   ```powershell
   $env:GMS_DB_HOST='127.0.0.1'; $env:GMS_DB_PORT='3306'; $env:GMS_DB_NAME='garage_management_system'
   $env:GMS_DB_USER='garage_migrator'; $env:GMS_DB_PASS='<migration-secret>'
   & "C:\MAMP\bin\php\php8.2.14\php.exe" "database\migrate.php"
   ```
   Or import `database/migrations/20261108_expense_ledger.sql` in phpMyAdmin against `garage_management_system` (idempotent: `CREATE TABLE IF NOT EXISTS` and `INSERT IGNORE`).
3. Grant runtime access on existing installations that use `least_privilege.sql`-style per-table grants (authorized DBA only; **do not rerun `least_privilege.sql`** — it resets passwords):
   ```sql
   GRANT SELECT, INSERT ON garage_management_system.expenses TO 'garage_app'@'localhost';
   FLUSH PRIVILEGES;
   ```
4. Verify:
   ```sql
   SHOW TABLES LIKE 'expenses';
   SHOW COLUMNS FROM expenses;
   SHOW GRANTS FOR 'garage_app'@'localhost';
   SELECT code FROM permissions WHERE code IN ('expenses.view','expenses.manage');
   ```
   Expected: `expenses` table exists; runtime has `SELECT, INSERT` (no UPDATE/DELETE) on `expenses`; permissions/role mappings present. Existing expense rows are preserved; migration does not drop data or duplicate records.

### Administrator recovery

The public first-time setup page creates the initial administrator only when the users table is empty. If existing users remain but no administrator account exists, an explicitly authorized garage owner can use the CLI-only recovery tool. It refuses to run over HTTP, requires typing `RECOVER ADMIN`, refuses to create a second administrator, generates a one-time random password, and forces a password change at first sign-in:

```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "database\recover_admin.php"
```

Run it from the project root after configuring `app/config/local.php` or the database environment variables. Protect the terminal output containing the one-time password, sign in once, and immediately choose a new password. Do not run recovery to promote an existing receptionist or customer.

---

### Step 4: Cryptographic Key Setup & Directory Permissions

1. **Storage Directories:** The application automatically writes encrypted keys and logs to `storage/`:
   - `storage/private/` (Protected encryption key storage)
   - `storage/logs/` (Application error logs)
2. **Web Server Security:**
   The `storage/private/.htaccess` file enforces:
   ```apache
   Require all denied
   ```
   This ensures encryption keys cannot be accessed via a web browser.
3. **Encryption Key Generation:**
   On initial launch, if `storage/private/encryption.key` does not exist and `GMS_ENCRYPTION_KEY` is unset, `CryptoService` generates and stores a random 32-byte key. Ensure the PHP process can write to `storage/private/`; key-generation and persistence failures stop the operation.
   Alternatively, provide `GMS_ENCRYPTION_KEY` as exactly 64 hexadecimal characters encoding 32 random bytes. Generate it with a trusted local secrets tool (for example, `openssl rand -hex 32`); do not use a sample value or commit the secret. Preserve the active key in an encrypted, access-controlled backup.

---

### Step 5: Demo Data Seeding (Optional)

The browser-based setup endpoint is locked after setup and should be removed from the public directory. Do not expose it as a demo seeder. Use a disposable local database for test data; the checked-in integration test suite writes to its configured database.

The installer refuses all access once `storage/installed.lock` exists or any user row is present, returning HTTP 404. It also requires `GMS_SETUP_TOKEN` in the query string when that environment variable is set. Fresh installations must delete `storage/installed.lock` and `public/setup.php` only as part of a deliberate reinstall.

---

### Step 6: Verifying the Installation

1. Open your web browser and navigate to:
   ```
   http://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/login.php
   ```
2. Log in with the administrator account created during first-time setup. Passwords are never stored in documentation.
3. Verify that the Dashboard loads with full styling, metric cards, navigation sidebar, and topbar.

---

## 3. Running Automated Tests

AutoPro includes a self-contained regression test suite in `tests/run_tests.php` verifying:
1. Database connectivity and schema integrity
2. RBAC role enforcement and permission nodes
3. Cryptographic AES-256-GCM encryption and tamper detection
4. Job card workflow state machine
5. Inventory stock calculations and double-entry ledger
6. Invoicing math, tax calculations, and payment settlements
7. Append-only audit trigger immutability and SHA-256 hash chain verification

To execute the test suite (same CLI flags used by Phase 1 verification):
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" -d extension_dir="C:\MAMP\bin\php\php8.3.1\ext" -d extension=pdo_mysql "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\tests\run_tests.php"
```

Individual read-only suites can be run the same way from `tests/`: `auth_unit.php`, `inventory_unit.php`, and `security_hardening_unit.php`.

---

## 4. Troubleshooting & Common Issues

### Issue 1: Database Connection Error (`SQLSTATE[HY000] [2002] Connection refused`)
- **Cause:** MySQL server is not running, or MAMP is configured on a non-standard port.
- **Resolution:**
  1. Open MAMP control panel and check if MySQL is running (green light).
  2. In MAMP preferences, check MySQL port (standard is `3306`, but some MAMP installs default to `8889`).
  3. Update `GMS_DB_PORT` in the Apache runtime environment and restart Apache.

### Issue 2: Blank Page or HTTP 500 Internal Server Error
- **Cause:** PHP error or missing required extension.
- **Resolution:**
  1. Open `storage/logs/error.log` to inspect the error stack trace.
  2. Verify that `pdo_mysql` and `openssl` extensions are enabled in your MAMP `php.ini`.

### Issue 3: Audit Log Mutation Error (`SQLSTATE[45000] audit_logs is append-only`)
- **Cause:** A direct SQL query attempted to execute `UPDATE` or `DELETE` on `audit_logs`.
- **Resolution:** This is **not a bug**; it is the intended security defense mechanism enforced by MySQL database triggers. Audit logs cannot be deleted or modified.

### Issue 4: CSS Styles or JavaScript Not Loading
- **Cause:** Incorrect `base_url` path in application configuration.
- **Resolution:**
  Verify that the Apache runtime environment contains the correct application variables, and that the URL path matches the project installation:
  ```text
  /garage-management-systems/public/
  ```
  Ensure trailing slash is present.

---

## 5. Backup & Disaster Recovery

### 5.1 Performing a Database Backup
To take a complete logical backup including triggers and stored routines:
```powershell
& "C:\MAMP\bin\mysql\bin\mysqldump.exe" -u <backup-user> -p -P 3306 --triggers --routines --single-transaction garage_management_system > "C:\MAMP\garage-backup-$(Get-Date -Format 'yyyyMMdd').sql"
```
Use a properly authorized backup account; enter its password only at the interactive prompt. Store backups outside the web root and verify them with periodic restore drills.

### 5.2 Restoring from Backup
```powershell
& "C:\MAMP\bin\mysql\bin\mysql.exe" -u restore_user -p -P 3306 garage_management_system
```
At the MySQL prompt, run `source C:/MAMP/garage-backup-YYYYMMDD.sql;`. Restore into an isolated database first and verify the result before switching the application.

### 5.3 Preserving Encryption Keys
> [!CAUTION]
> Back up the active encryption key configured through `GMS_ENCRYPTION_KEY` or stored under `storage/private/` using an encrypted, access-controlled process. Losing or rotating the key without a migration plan can make existing encrypted data unrecoverable.

---
*End of Installation & Deployment Guide.*
