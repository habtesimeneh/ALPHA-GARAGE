# ALPHA GARAGE — PHASE 1.9B: TEST INFRASTRUCTURE & ISOLATION ARCHITECTURE

## 1. Executive Summary

Phase 1.9B establishes an automated, mathematically verified, and strictly isolated testing infrastructure for the **ALPHA GARAGE Management System**. This architecture guarantees that all existing and future automated test suites run exclusively against disposable, synthetic test environments without any risk to the live production database (`garage_management_system`) or production file storage (`storage/`).

---

## 2. Environment Isolation Design

The isolation architecture enforces hard boundaries across database connections, local file storage, HTTP/session cookies, and test runner execution modes.

```
+----------------------------------------------------------------------------------------------------+
|                                      OPERATING ENVIRONMENT                                         |
|                                                                                                    |
|   +---------------------------------------+          +-----------------------------------------+   |
|   |         PRODUCTION ENVIRONMENT        |          |          TEST RUNTIME ENVIRONMENT       |   |
|   |                                       |          |                                         |   |
|   |  - Apache 2.4 DocumentRoot: public/   |          |  - PHP 8.3 CLI Isolated Test Runner     |   |
|   |  - DB: garage_management_system       |          |  - DB: garage_management_system_test    |   |
|   |  - User: garage_app (least privilege) |          |  - User: root / garage_app              |   |
|   |  - Storage: storage/                  |          |  - Storage: storage/testing/            |   |
|   |  - Session Cookie: GMSSESSID          |          |  - Session Cookie: GMSSESSID_TEST       |   |
|   |  - State: READ-ONLY & UNTOUCHED       |          |  - State: SYNTHETIC & DISPOSABLE        |   |
|   +---------------------------------------+          +-----------------------------------------+   |
|                       ^                                                   ^                        |
|                       |                                                   |                        |
|              [STRICT SEPARATION]                                 [FAIL-CLOSED GUARDS]              |
|         No shared storage directories                     Rejects production DB target             |
|         No cross-database execution                       Rejects missing _test suffix             |
|         No session cookie leakage                         Rejects production storage in test mode  |
+----------------------------------------------------------------------------------------------------+
```

### 2.1 Configuration & Environment Variable Hierarchy

The application's runtime configuration (`app/config/database.php` and `app/bootstrap.php`) evaluates environment variables prior to falling back to configuration files:

| Variable | Production Value / Default | Test Runtime Value | Purpose & Safety Rule |
|---|---|---|---|
| `GMS_TEST_MODE` | *(Unset / 0)* | `1` | Enables test-mode isolation; activates test session names and strict storage path guards. |
| `GMS_ALLOW_DESTRUCTIVE_TESTS` | *(Unset / 0)* | `1` | Explicit authorization switch required before any test drops, mutates, or truncates test records. |
| `GMS_DB_NAME` | `garage_management_system` | `garage_management_system_test` | Target database name. Must strictly end with `_test`. |
| `GMS_STORAGE_PATH` | *(Unset -> `storage/`)* | `C:/.../storage/testing` | Isolated storage root for test logs, rate limits, mail spool, and sessions. |
| `GMS_DB_USER` | `garage_app` | `root` or `garage_app` | Database username for test runner operations. |
| `GMS_DB_PASS` | *(Encrypted secret)* | `root` | Database password for test environment. |

---

## 3. Dedicated Test Database Provisioning (`scripts/provision_test_db.php`)

### 3.1 Dangerous SQL Statement Sanitization

The project's baseline schema file (`database/database.sql`) contains embedded database management directives:
```sql
CREATE DATABASE IF NOT EXISTS garage_management_system ...;
USE garage_management_system;
DROP TABLE IF EXISTS ...;
```
If piped directly to a MySQL command-line client targeting a test database, MySQL executes the `USE garage_management_system;` statement, immediately switching execution context to the live production database and dropping all production tables.

To eliminate this vulnerability permanently, `scripts/provision_test_db.php` incorporates an AST-safe tokenizing statement parser (`parse_sql_statements()`) that:
1. Strips all `CREATE DATABASE` and `USE` statements from the schema file before query execution.
2. Connects directly to the explicitly verified database `garage_management_system_test`.
3. Verifies `SELECT DATABASE()` before and after statement execution, aborting instantly if the connection context ever shifts.

### 3.2 Provisioning Workflow

The provisioning engine executes 7 discrete, atomic steps:

1. **Storage Initialization**: Creates `storage/testing/sessions/`, `storage/testing/logs/`, `storage/testing/rate_limits/`, `storage/testing/mail_spool/`, and a local `.gitignore` (`*` / `!.gitignore`).
2. **Database Verification**: Connects as database administrator and ensures `garage_management_system_test` exists with `utf8mb4` encoding.
3. **Privilege Allocation**: Grants `ALL PRIVILEGES` on `garage_management_system_test.*` to `'garage_app'@'localhost'` so runtime queries function seamlessly.
4. **Table Drop & Reset**: Drops existing tables in `garage_management_system_test` only, under disabled foreign key checks.
5. **Sanitized Baseline Import**: Executes 46 table, trigger, and configuration statements from `database/database.sql`.
6. **Migration Sequence**: Applies all 9 migrations from `database/migrations/*.sql` in sequential order and records cryptographic SHA-256 checksums in `schema_migrations`.
7. **Synthetic Fixture Seeding**: Provisions an administrator user (`admin`) and executes `DemoSeeder::run()` to establish 5 demo users, 8 customers, 10 vehicles, 15 inventory items, 7 job cards, 4 service appointments, and 73 tamper-evident audit records.

---

## 4. File Storage & Session Isolation

### 4.1 Storage Path Enforcement (`app/helpers/functions.php`)

The helper function `storage_path()` incorporates a native safety guard:
```php
$isTestMode = getenv('GMS_TEST_MODE') === '1' || getenv('GMS_ALLOW_DESTRUCTIVE_TESTS') === '1';
$prodDefault = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/storage';
if ($isTestMode && strcasecmp($root, $prodDefault) === 0) {
    throw new RuntimeException('Test mode is enabled but storage points to production storage. Set GMS_STORAGE_PATH to an isolated directory.');
}
```
Any attempt to execute tests without overriding `GMS_STORAGE_PATH` throws a `RuntimeException`, guaranteeing zero writes to production logs or session storage.

### 4.2 Hardened Test Sessions (`app/bootstrap.php`)

When `GMS_TEST_MODE === '1'`, the bootstrap process:
- Changes the session cookie name to `GMSSESSID_TEST` (distinguishing it from production `GMSSESSID`).
- Sets `session_save_path()` to `storage/testing/sessions/`.
- Prevents session state cross-contamination between production browser users and automated test actors.

---

## 5. Automated Isolated Test Runner (`scripts/run_isolated_tests.php`)

The automated runner coordinates test suite execution within the isolated harness.

### 5.1 Architecture

```
scripts/run_isolated_tests.php
  |
  +---> Preflight Safety Checks (Target DB == *_test, Storage == storage/testing/)
  |
  +---> [Optional: --provision] Provision & Seed garage_management_system_test
  |
  +---> Suite 1: tests/auth_unit.php (Sub-process execution)
  +---> Suite 2: tests/inventory_unit.php (Sub-process execution)
  +---> Suite 3: tests/security_hardening_unit.php (Sub-process execution)
  +---> Suite 4: tests/safety_validation.php (Sub-process execution)
  |
  +---> Suite 5: Database State & Hash Chain Verification (Direct PDO inspection)
  |
  +---> Results Aggregation & Execution Report
```

### 5.2 Key Runner Features

1. **Sub-process Isolation**: Each test file executes in an independent PHP CLI process with explicit environment injection. Failures or fatal errors in one test cannot corrupt runner state.
2. **Fail-Closed Execution**: If target database identity cannot be verified or storage points to production, the runner terminates immediately with exit code 2.
3. **Trigger & Immutability Verification**: Verifies active triggers (`trg_audit_no_update`, `trg_audit_no_delete`, `trg_payments_immutable`) in `information_schema.TRIGGERS`.
4. **Cryptographic Chain Check**: Invokes `AuditLogger::verifyChain()` to mathematically prove zero record tampering.

---

## 6. MAMP Apache Boundary vs CLI Test Runner

A critical distinction identified during Phase 1.9B:

> [!WARNING]
> MAMP Apache runs on `http://localhost/` and `https://localhost/`, loading database credentials from `app/config/local.php` which connects to the **live production database** (`garage_management_system`).
> 
> Therefore, HTTP integration tests (such as `tests/run_tests.php`) must **NEVER** target the live MAMP Apache server directly. HTTP integration tests require a dedicated background PHP built-in server (e.g., `php -S 127.0.0.1:8088 -t public`) launched with explicit test environment variables (`GMS_DB_NAME=garage_management_system_test`, `GMS_STORAGE_PATH=.../storage/testing`).

---

## 7. Operational Instructions

### 7.1 Running the Isolated Test Runner

```powershell
cd C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main
C:\MAMP\bin\php\php8.3.1\php.exe -c C:\MAMP\conf\php8.3.1\php.ini scripts/run_isolated_tests.php
```

### 7.2 Re-provisioning the Dedicated Test Database

```powershell
cd C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main
C:\MAMP\bin\php\php8.3.1\php.exe -c C:\MAMP\conf\php8.3.1\php.ini scripts/provision_test_db.php
```

### 7.3 Running Test Runner with Automatic Re-provisioning

```powershell
cd C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main
C:\MAMP\bin\php\php8.3.1\php.exe -c C:\MAMP\conf\php8.3.1\php.ini scripts/run_isolated_tests.php --provision
```
