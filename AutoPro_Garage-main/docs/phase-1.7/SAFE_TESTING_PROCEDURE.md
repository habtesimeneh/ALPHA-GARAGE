# Phase 1.7A — Safe Local Testing & Isolation Procedure

**Phase:** 1.7A — Read-Only Admin Identity Investigation & Local Release Preparation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System  
**Test Suite:** Integration Harness (`tests/run_tests.php`) & Unit Suites (`tests/*_unit.php`)  
**Safety Mandate:** **NON-DESTRUCTIVE.** In accordance with safety directives, no test suite that can write to the live database was executed during Phase 1.7A.

---

## 1. Executive Summary & Isolation Audit

A comprehensive assessment of the existing test harness was conducted to determine whether testing operations are safely isolated from the live application environment.

### 1.1 Test Isolation Audit Findings

| Component / Subsystem | Isolation Status | Current State & Vulnerability | Risk Level |
|:---|:---:|:---|:---:|
| **Test Database** | **PARTIALLY ISOLATED** | `tests/run_tests.php` requires DB name ending in `_test` and refuses live DBs. **HOWEVER**, `database/database.sql` hardcodes `USE garage_management_system;`. Raw import silently drops and overwrites the live database. | **CRITICAL** |
| **Test Storage Directory** | **NOT ISOLATED** | The test server reads from the same codebase tree (`APP_ROOT . '/storage/'`). Test artifacts are written directly into live directories. | **HIGH** |
| **Test Mail Spool** | **NOT ISOLATED** | `MailService.php` writes failed/unauthenticated emails to `APP_ROOT . '/storage/mail_spool'`. Test password resets pollute live mail spool. | **MEDIUM** |
| **Rate-Limit Storage** | **NOT ISOLATED** | `RateLimiter.php` writes hashed lock files to `APP_ROOT . '/storage/rate_limits'`. Running test brute-force checks locks out `127.0.0.1` on the live application. | **HIGH** |
| **Test Credentials in Git** | **FULLY ISOLATED** | Credentials are supplied dynamically via environment variables (`ADMIN_PASS`, `DEMO_PASS`). `local.php` is `.gitignore`d. No passwords exist in Git. | **SAFE** |

---

## 2. Root Cause Analysis: The Live Overwrite Hazard

In Phase 1.6, an operational incident occurred during test environment setup. An engineer executed:
```bash
mysql garage_management_system_test < database/database.sql
```
Because line 17 of `database/database.sql` contained:
```sql
USE garage_management_system;
```
The MySQL interpreter switched active database contexts mid-stream. It dropped live tables and recreated empty tables in `garage_management_system`, completely wiping the active database until an emergency restore was authorized.

### Mandatory Rule:
**Never import `database/database.sql` directly into a test or secondary database without stripping `CREATE DATABASE` and `USE` statements.**

---

## 3. Tiered Testing Structure

The testing architecture is divided into two distinct tiers:

```
┌─────────────────────────────────────────────────────────────┐
│ TIER 1: Non-Destructive In-Memory Unit Suites               │
│ • Zero database connection                                  │
│ • Zero filesystem mutations                                 │
│ • 100% safe to run against live repository at any time       │
└─────────────────────────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────┐
│ TIER 2: Destructive Integration Harness (run_tests.php)     │
│ • Requires dedicated *_test database                        │
│ • Requires GMS_ALLOW_DESTRUCTIVE_TESTS=1                    │
│ • Writes customers, jobs, invoices, and drops login attempts│
│ • Requires strict storage & rate-limit isolation            │
└─────────────────────────────────────────────────────────────┘
```

---

## 4. Tier 1: Safe In-Memory Unit Testing Procedure

Tier 1 unit tests do not access the database and do not touch storage. They test algorithm logic, CSRF token validation boundaries, input validators, AES-256-GCM encryption, blind indexing, and inventory arithmetic.

### Execution Command:
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\tests\auth_unit.php"
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\tests\inventory_unit.php"
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\tests\security_hardening_unit.php"
```

### Verified Output:
```
Authentication unit tests passed.
Inventory unit tests passed.
Security hardening unit tests passed.
```
*Note: Tier 1 tests can be run as part of pre-commit hooks or local sanity checks with zero risk to live data.*

---

## 5. Tier 2: Safe Destructive Integration Testing Procedure

When full integration testing is required (e.g., prior to a major release), follow this strict 7-step isolation protocol to ensure zero leakage into the live application.

### Step 1: Pre-Test Live Database Safety Backup
Always verify and back up the live database before touching any test database:
```powershell
# Verify live checksums before beginning
& "C:\MAMP\bin\mysql\bin\mysqldump.exe" -u root -proot --routines --triggers garage_management_system > "C:\MAMP\htdocs\AutoPro_Garage-main\pre_test_live_backup.sql"
```

### Step 2: Provision Sanitized Test Database
Create the dedicated test database and strip all destructive `USE` / `CREATE DATABASE` statements from `database.sql` before piping to MySQL:

```powershell
# 1. Create fresh isolated test database
& "C:\MAMP\bin\mysql\bin\mysql.exe" -u root -proot -e "DROP DATABASE IF EXISTS garage_management_system_test; CREATE DATABASE garage_management_system_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Strip CREATE DATABASE and USE statements to prevent targeting live DB
$cleanSql = (Get-Content "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\database\database.sql") | Where-Object { 
    $_ -notmatch '(?i)^\s*CREATE\s+DATABASE' -and $_ -notmatch '(?i)^\s*USE\s+' 
}
$cleanSql | & "C:\MAMP\bin\mysql\bin\mysql.exe" -u root -proot garage_management_system_test

# 3. Apply migrations to test database
$env:GMS_DB_NAME = "garage_management_system_test"
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\database\migrate.php"
```

### Step 3: Populate Test Database Demo Data
Seed standard demo accounts (admin, reception, mechanic1, mechanic2, stock, accounts) into `garage_management_system_test`.

### Step 4: Storage & Rate-Limit Isolation Protocol
To prevent tests from locking out live IP addresses or cluttering live mail spools:

1. **Pre-Test Storage Backup:**
   ```powershell
   Copy-Item -Recurse "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\storage\rate_limits" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\storage\rate_limits_backup"
   ```
2. **Clear Rate Limits for Test:**
   Remove existing lockout tokens so tests run with a clean state.
3. **Future Architectural Enhancement:**
   Support `GMS_STORAGE_PATH` environment variable in `bootstrap.php` so tests can dynamically point to a temporary directory (e.g., `sys_get_temp_dir() . '/gms_test_storage'`).

### Step 5: Launch Ephemeral Test Server on Isolated Port
Do **NOT** run integration tests against the live MAMP port (80/443). Launch PHP's built-in web server bound strictly to an isolated port:

```powershell
$env:GMS_DB_NAME = "garage_management_system_test"
$env:GMS_APP_URL = "http://127.0.0.1:19877"

# Launch in background
Start-Process "C:\MAMP\bin\php\php8.3.1\php.exe" -ArgumentList "-S 127.0.0.1:19877 -t C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public" -WindowStyle Hidden
```

### Step 6: Execute Integration Harness
Run `run_tests.php` with all required guard flags:

```powershell
$env:GMS_ALLOW_DESTRUCTIVE_TESTS = "1"
$env:GMS_DB_NAME = "garage_management_system_test"
$env:BASE = "http://127.0.0.1:19877/"
$env:ADMIN_PASS = "TestAdminPass123!"
$env:DEMO_PASS = "TestDemoPass123!"

& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\tests\run_tests.php"
```

### Step 7: Post-Test Teardown & Live Verification
Immediately after test completion:
1. Stop the ephemeral test web server process.
2. Drop the test database:
   ```powershell
   & "C:\MAMP\bin\mysql\bin\mysql.exe" -u root -proot -e "DROP DATABASE IF EXISTS garage_management_system_test;"
   ```
3. Purge test mail spool items (`recovery.tester.*` files in `storage/mail_spool/`).
4. Restore clean live rate-limit directory:
   ```powershell
   Remove-Item -Recurse -Force "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\storage\rate_limits\*"
   ```
5. Verify live database integrity:
   ```powershell
   & "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -r "
       require 'C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/app/bootstrap.php';
       \$v = AuditLogger::verifyChain();
       echo 'Live Audit Chain: ' . (\$v['ok'] ? 'INTACT' : 'CORRUPT') . ' (' . \$v['checked'] . ' rows)\n';
   "
   ```

---

## 6. Developer Safety Rules

1. **Rule of the Redirection:** Never run raw SQL import files without inspecting for `USE` or `CREATE DATABASE`.
2. **Rule of Environment Check:** Verify `$dbConfig['name']` ends in `_test` before initiating any testing script.
3. **Rule of Live Immutability:** Never execute `UPDATE`, `DELETE`, or `ALTER` directly on `garage_management_system`.
4. **Rule of Audit Chain Preservation:** Never attempt manual deletion of `audit_logs` or `login_attempts` in the live database. Triggers will abort the query and record failure.
