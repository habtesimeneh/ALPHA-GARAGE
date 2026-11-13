# ALPHA GARAGE — PHASE 1.9A
# COMPREHENSIVE APPLICATION SECURITY & ARCHITECTURAL AUDIT

**Audit Date:** November 13, 2026  
**Auditor:** Antigravity  
**Target Environment:** Local MAMP Development (Windows 10/11)  
**Web Server:** Apache 2.4.33 (Win64) | PHP 8.3.1 | MySQL 5.7.24  
**Project Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`  
**Public Web Root:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`  
**Live Database:** `garage_management_system` (Runtime User: `garage_app@localhost`)  
**Audit Mode:** Strictly Read-Only (Zero Code/Database Modifications)  

---

## 1. Executive Summary

This comprehensive security and architectural audit evaluates the ALPHA GARAGE Management System across eight primary security and functional domains following the successful completion of Phase 1.8B (Apache DocumentRoot cutover).

The audit confirms that the core application possesses a mature, defense-in-depth security foundation:
- **Authentication & Sessions:** Cryptographic password hashing (Bcrypt), dummy timing hash mitigation against user enumeration, strict session parameter controls (`HttpOnly`, `SameSite=Lax`, `Secure`), idle/absolute timeouts, and session regeneration on privilege transitions.
- **Access Control:** Centralized server-side RBAC with 6 distinct roles and 40 fine-grained permissions. Object-level access control (IDOR prevention) restricts mechanics strictly to their own assigned jobs.
- **Injection Defenses:** 100% of analyzed database queries utilize parameterized prepared statements with PDO native prepares (`ATTR_EMULATE_PREPARES => false`). Zero raw superglobal concatenations into SQL queries were found across 100 PHP source files.
- **Audit Logging:** Linear cryptographic hash chaining (SHA-256) with table-level chain serialization (`audit_chain_head`) and sensitive data redaction. Live chain integrity is 100% intact (53/53 records verified).
- **Financial Arithmetic:** Complete avoidance of floating-point arithmetic in ledger operations via integer cents conversion (`to_cents` / `from_cents`).

However, the audit identified notable architectural defects, configuration risks, and missing test infrastructure that must be prioritized before Phase 1.9 testing:
1. **Missing MySQL Append-Only Triggers (High):** The intended `trg_audit_no_update` and `trg_audit_no_delete` triggers are absent from the live database. While mitigated for `garage_app` via table privileges, database-layer defense-in-depth is incomplete.
2. **Setup Script in Public Web Root (Medium):** `public/setup.php` remains accessible over HTTP. While currently locked by database user presence, best practice requires removing this file from the web root.
3. **Password Reset Debug Link Exposure Risk (Medium):** If `debug` mode is enabled on a non-SMTP environment, `forgot-password.php` exposes the direct plaintext password reset link in the HTML response.
4. **Notification State IDOR (Low):** `NotificationService::markRead(int $id)` updates `is_read = 1` without validating ownership of the notification record.
5. **Customer Booking Workflow Disconnect (Low/Medium):** The public landing page directs logged-in customers to `appointments/form.php`, which requires `appointments.manage`, resulting in an unexpected 403 Forbidden error for customers.

---

## 2. Components & Files Inspected

| Category | Component / File | Purpose & Scope |
|---|---|---|
| **Bootstrap & Config** | `app/bootstrap.php` | Error handling, session initialization, global CSRF gating, security headers |
| | `app/config/app.php` | Core settings, session lifetimes, rate limit parameters |
| | `app/config/database.php` | Connection factory and environment variable resolution |
| | `app/config/local.php` | Local database credentials (least-privilege `garage_app`) |
| **Helpers & Middleware**| `app/helpers/functions.php` | `e()`, `csrf_*()`, `storage_path()`, `paginate()`, `csv_safe()` |
| | `app/middleware/auth.php` | `current_user()`, `require_login()`, idle/absolute timeouts |
| | `app/middleware/permissions.php`| `require_permission()`, `can()`, `deny()` |
| **Core Services** | `app/services/AuthService.php` | Authentication, Bcrypt, timing attacks, rate limiting, remember-me |
| | `app/services/PasswordResetService.php` | Single-use tokens, SHA-256 storage, reset completion |
| | `app/services/AuditLogger.php` | Hash chaining, serial locking, data redaction, `verifyChain()` |
| | `app/services/JobService.php` | State machine transitions, mechanic IDOR gating, part allocation |
| | `app/services/InvoiceService.php` | Integer cents math, tax calculation, discount limits, balance sync |
| | `app/services/PaymentService.php` | Immutable receipts, refund validation, overpayment blocking |
| | `app/services/InventoryService.php`| Stock issuance, ledger transactions, low-stock thresholds |
| | `app/services/RateLimiter.php` | File-based sliding window rate limiter |
| | `app/services/CryptoService.php` | AES-256-GCM encryption and blind indexing |
| | `app/services/NotificationService.php`| System alerts and broadcast delivery |
| **Public Endpoints** | `public/login.php`, `public/logout.php` | Auth lifecycle and role routing |
| | `public/forgot-password.php`, `public/reset-password.php` | Password recovery workflow |
| | `public/register.php` | Public customer registration with honeypot & rate limit |
| | `public/setup.php` | One-time installer script |
| | `public/dashboard.php` | Analytics rendering and permission-scoped metrics |
| | `public/job-cards/*.php` | Work order creation, status progression, mechanic assignment |
| | `public/invoices/*.php` | Invoice generation, viewing, and printing |
| | `public/payments/*.php` | Payment recording, refund processing, receipt generation |
| | `public/customers/*.php`, `public/vehicles/*.php` | Entity management and repair history |
| | `public/inventory/*.php`, `public/expenses/*.php` | Parts stock adjustments and operating expenses |
| | `public/users/*.php`, `public/audit-logs/*.php` | User admin, role permissions, and tamper-evident audit view |
| **Database & Schema** | `database/database.sql` | Master DDL schema, table structures, indexes, foreign keys |
| | `database/least_privilege.sql` | Runtime privilege grants for `garage_app` |
| | `database/migrations/*.sql` | 9 schema migrations |
| **Test Suites** | `tests/run_tests.php` | 410-line comprehensive destructive integration test suite |
| | `tests/auth_unit.php` | Authentication, password policy, and CSRF token unit tests |
| | `tests/inventory_unit.php` | Domain exception boundary tests for stock and purchasing |
| | `tests/security_hardening_unit.php`| CSRF expiry boundaries, crypto roundtrip, contact validation |

---

## 3. Detailed Audit Findings by Domain

### 3.1 Authentication & Session Security

#### Existing Controls
- **Timing Attack Mitigation:** `AuthService::attempt()` uses `self::DUMMY_HASH` (`$2y$10$XV2ZfpFI...`) whenever an entered username does not exist. This ensures `password_verify()` always executes with constant-time characteristics, preventing username enumeration through timing discrepancies.
- **Credential Storage:** Passwords are encrypted with `PASSWORD_DEFAULT` (Bcrypt, cost 10). When users log in, `password_needs_rehash()` verifies whether the stored hash meets current algorithmic requirements and automatically updates outdated hashes.
- **Session Hardening:** `app/bootstrap.php` sets `session.use_strict_mode = 1`, `session.use_only_cookies = 1`, `session.cookie_httponly = 1`, and sets cookie parameters: `['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']`.
- **Session Regeneration:** Upon successful credential validation, `session_regenerate_id(true)` destroys the old session ID and issues a fresh one, neutralizing session fixation attacks.
- **Session Lifecycle & Hijacking Defense:** `require_login()` tracks `last_activity` (30-minute idle timeout) and `started_at` (8-hour absolute maximum lifetime). Furthermore, it binds the session to the client's User Agent via `hash_equals($_SESSION['ua'], hash('sha256', user_agent()))`, invalidating sessions if the client environment changes.
- **Remember-Me Architecture:** 32-byte cryptographically secure tokens (`random_bytes(32)`) stored on client as `GMS_REMEMBER` and in MySQL as SHA-256 hashes (`remember_token`). On every successful authentication via cookie, the token is automatically rotated in the database and re-issued to the client (theft-limited single reuse).

#### Findings & Vulnerability Analysis
- **Finding SEC-AUTH-01 (Medium):** Direct reset link exposure in `forgot-password.php` when `debug = true`.  
  *Detail:* Lines 91–93 of `PasswordResetService.php` store `$_SESSION['_dev_reset_link'] = $resetUrl` when `debug = true` and transport is `dev_spool`. In `forgot-password.php` lines 125–135, this link is rendered directly in an informational banner on screen. If debug mode is inadvertently enabled in a deployed environment, an attacker could trigger a reset for any administrator email and immediately view the recovery link on screen.
- **Finding SEC-AUTH-02 (Low):** Shared database storage for rate limiting.  
  *Detail:* `login_attempts` table is used for login rate limiting. While effective, a denial-of-service against the MySQL connection pool could impair login rate limit checks or lock out legitimate users across shared IPs.

---

### 3.2 Role-Based Access Control (RBAC)

#### Existing Controls
- **Strict Server-Side Enforcement:** Permissions are never checked solely in the UI. Every controller calls `require_login()` followed by `require_permission('...')`.
- **Fresh Credential Loading:** `current_user()` re-queries the `users` and `role_permissions` tables on every request. If a user is deactivated (`is_active = 0`) or their role changes, their active session reflects the change immediately on the very next HTTP request without waiting for session expiry.
- **Granular Privilege Distribution:**
  - `administrator` (40 permissions): Full system access.
  - `receptionist` (20 permissions): Front-desk operations, appointments, work orders, customers, invoicing, payment recording. Cannot manage users, alter roles, or view audit logs.
  - `mechanic` (7 permissions): `dashboard.view`, `inventory.view`, `jobs.progress`, `jobs.request_parts`, `jobs.view_assigned`, `services.view`, `vehicles.view`. Cannot view financial records, customer profiles, or unassigned jobs.
  - `inventory_manager` (9 permissions): Stock management, parts procurement, parts issuance to jobs. Cannot create invoices or record payments.
  - `accountant` (9 permissions): Invoices, payments, refunds, expenses, financial reports. Cannot create job cards or issue inventory.
  - `customer` (0 staff permissions): Non-staff role.
- **Self-Lockout Protections:** `public/users/form.php` explicitly prevents administrators from deactivating their own account or stripping their own administrator role. In `public/users/roles.php`, the administrator role is hardcoded as locked.

#### Findings & Vulnerability Analysis
- **Finding SEC-RBAC-01 (Medium):** Inconsistent customer portal routing.  
  *Detail:* In `public/login.php` and `public/register.php`, users with role `customer` are redirected to `index.php`. On `index.php`, clicking "Book Service" links to `appointments/form.php?service_id=X`. However, `appointments/form.php` requires `appointments.manage`, causing an unhandled 403 Forbidden error for authenticated customers.
- **Finding SEC-RBAC-02 (Low):** Notification state IDOR in `NotificationService::markRead()`.  
  *Detail:* `NotificationService::markRead(int $id)` executes `UPDATE notifications SET is_read = 1 WHERE id = ?` without verifying that `$id` matches `user_id = current_user()['id']` or the user's role. Any logged-in user can mark arbitrary notification IDs as read.

---

### 3.3 Application Security (SQLi, XSS, CSRF, File Upload, Traversal)

#### Existing Controls
- **SQL Injection Prevention:** 100% prepared statements. PDO emulation is disabled (`PDO::ATTR_EMULATE_PREPARES => false`). Real prepared statements are executed by MySQL for all dynamic variables. An exhaustive regex audit across all 100 PHP files confirmed zero direct string concatenations of superglobals into SQL statements.
- **Cross-Site Scripting (XSS):** All dynamic output in HTML templates is sanitized via the global `e()` helper (`htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`).
- **CSV Injection Prevention:** `send_csv()` processes all cell values through `csv_safe()`, which prepends a single quote (`'`) to any string starting with `=`, `+`, `-`, `@`, `\t`, or `\r`.
- **Global CSRF Protection:** `app/bootstrap.php` automatically calls `verify_csrf()` on every incoming `POST` request. Missing or mismatched tokens abort with `HTTP 419` and log a `csrf_validation_failed` security audit event.
- **Security Headers:** Comprehensive baseline emitted on every request:
  - `X-Frame-Options: DENY`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: same-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
  - `Content-Security-Policy: default-src 'self'; img-src 'self' data: https://images.unsplash.com; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'`
  - `Cache-Control: no-store, max-age=0`
- **File Upload Attack Surface:** Zero file upload endpoints exist in the application (`$_FILES` is completely unused), eliminating arbitrary file upload and web shell execution vectors.
- **Path Traversal Defense:** `storage_path()` strictly normalizes paths, forbids traversal characters (`..`), rejects null bytes (`\0`), and throws an exception if storage is configured inside the public web root.

#### Findings & Vulnerability Analysis
- **Finding SEC-APP-01 (Medium):** `public/setup.php` remains in web root.  
  *Detail:* The setup wizard script resides directly under `public/setup.php`. While lines 12–15 and 26–33 verify `storage/installed.lock` and `SELECT COUNT(*) FROM users > 0` before rendering, keeping an installer script in the production web root is a defense-in-depth risk.
- **Finding SEC-APP-02 (Low):** Static 2-hour CSRF token validity window.  
  *Detail:* `csrf_token()` generates a token valid for 7200 seconds (2 hours) and does not regenerate the token upon each state-changing POST request. A token remains reusable within the session window until expiry.

---

### 3.4 Business Workflow Integrity

#### Existing Controls
- **Job Card State Machine:** `JobService::TRANSITIONS` defines valid lifecycle states (`Pending` -> `Diagnosing` -> `Waiting for Parts` -> `In Progress` -> `Completed` -> `Delivered` / `Cancelled`). Illegal state jumps are blocked with `DomainException`.
- **Relational Integrity Enforcement:** `JobService::create()` validates that the customer exists and is active, the vehicle exists and is active, and that `(int) $vehicle['customer_id'] === (int) $customer['id']`, preventing cross-customer vehicle work orders.
- **Inventory Stock Protection:** Parts issued to a job card immediately decrement available stock. If an issue request exceeds on-hand stock, `InventoryService::issue()` throws a `DomainException`, rolling back the entire database transaction.
- **Automatic Part Restock on Cancellation:** Cancelling a job card automatically returns all non-returned parts back to inventory stock and marks the job part records as returned (`returned = 1`).

#### Findings & Vulnerability Analysis
- **Finding SEC-BUS-01 (Low):** Unlinked appointment status on vehicle deactivation.  
  *Detail:* If a customer or vehicle is deactivated while an appointment is in `confirmed` status, the appointment remains active in the calendar. Converting the appointment via `AppointmentService::convertToJob()` will then fail with an unhandled exception because `JobService::create()` rejects inactive vehicles.

---

### 3.5 Financial Correctness & Ledger Integrity

#### Existing Controls
- **Integer Cents Representation:** All money amounts are calculated in integer cents using `to_cents()` and stored as exact strings using `from_cents()`, eliminating IEEE 754 floating-point rounding errors.
- **Overpayment Prevention:** `PaymentService::record()` locks the invoice row with `SELECT ... FOR UPDATE` and checks:
  ```php
  $balance = to_cents($inv['balance']);
  if ($cents > $balance) {
      throw new DomainException('The payment exceeds the outstanding balance.');
  }
  ```
- **Over-Refund Prevention:** `PaymentService::refund()` computes net paid (`paid - refunded`) and blocks any refund where `cents > net`.
- **Payment Immutability:** Payments and refunds cannot be edited or deleted. Financial mistakes must be resolved through an authorized refund entry.
- **Invoice Synchronization Lockout:** `InvoiceService::syncFromJob()` refuses to modify invoice items once any payment has been recorded against the invoice.

#### Findings & Vulnerability Analysis
- **Finding SEC-FIN-01 (Medium):** Concurrency window on rapid partial payments (Double-Submit Risk).  
  *Detail:* While overpayment is blocked when paying the full remaining balance, rapid double-submission of partial payments (e.g. two clicks on a $50 payment on a $200 balance) with the same non-rotated CSRF token could record two valid $50 payments. A unique idempotency token or unique constraint on transaction references is recommended.

---

### 3.6 Audit Logging & Tamper Evidence

#### Existing Controls
- **Linear Cryptographic Hash Chaining:** Every row in `audit_logs` records `prev_hash` (the previous row's `row_hash`) and `row_hash = SHA256(prev_hash + unit_separated_row_fields)`.
- **Serialized Append Lock:** `AuditLogger::log()` queries `SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE`, serializing concurrent log writes and preventing fork branches in the hash chain.
- **Automatic Data Redaction:** Passwords, password hashes, tokens, CSRF values, and secret parameters are replaced with `[REDACTED]` prior to JSON serialization and hashing.
- **Full Verification Tooling:** `AuditLogger::verifyChain()` validates the entire chain from genesis block (`0` x 64) to the chain head. The live database was verified during this audit: **53 / 53 records intact (`ok: true`)**.
- **Database Privilege Separation:** Runtime user `garage_app` is granted only `SELECT, INSERT` on `audit_logs`. It has no `UPDATE` or `DELETE` privileges.

#### Findings & Vulnerability Analysis
- **Finding SEC-AUD-01 (High):** Missing MySQL append-only triggers on `audit_logs`.  
  *Detail:* In `database/database.sql` lines 436–437, triggers `trg_audit_no_update` and `trg_audit_no_delete` are defined to raise a SQLSTATE 45000 exception upon any attempt to update or delete rows. However, `SHOW TRIGGERS` on the live database confirms **zero triggers are installed**. While `garage_app` lacks update/delete privileges, any superuser or misconfigured service account could modify rows without trigger-level rejection.
- **Finding SEC-AUD-02 (Low):** Lack of automated continuous chain verification.  
  *Detail:* `AuditLogger::verifyChain()` is currently executed only on demand by administrators via the web UI or CLI scripts. No recurring scheduled task alerts administrators if the hash chain is broken.

---

### 3.7 Database Architecture & Schema Integrity

#### Existing Controls
- **Relational Integrity:** 29 tables with 40 foreign key constraints enforcing cascading or restrictive integrity across all parent-child relationships.
- **Unique Constraints:** 16 unique indexes protecting usernames, emails, vehicle plates, VIN numbers, inventory SKUs, supplier codes, receipt numbers, and job numbers.
- **1:1 Work Order to Invoice Guarantee:** Unique index `UNIQUE (job_card_id)` on the `invoices` table ensures no work order can ever produce duplicate invoices.
- **Least-Privilege Database User:** `garage_app` is restricted to minimum required table grants:
  - No `DROP`, `ALTER`, or `CREATE` privileges.
  - No `DELETE` privilege on `audit_logs`, `payments`, `expenses`, or `schema_migrations`.
  - Column-restricted `UPDATE (receipt_number)` on `payments`.

#### Findings & Vulnerability Analysis
- **Finding SEC-DB-01 (Low):** Missing composite index on `login_attempts (attempted_at, username, ip_address)`.  
  *Detail:* `login_attempts` is frequently queried for lockout counts. Adding an index optimizing the range lookup `attempted_at >= ?` will improve performance under high traffic.

---

### 3.8 Existing Test Infrastructure

#### Existing Controls
- **Unit Test Coverage:** Three standalone, database-independent unit test files:
  - `tests/auth_unit.php`: Password hashing, policy validation, CSRF token validation, database config resolution.
  - `tests/inventory_unit.php`: Stock purchase quantity limits, negative cost rejection, adjustment direction checks, duplicate parts rejection.
  - `tests/security_hardening_unit.php`: CSRF expiry boundary tests, contact inquiry validation, AES-256-GCM encryption/decryption roundtrips, blind index case-insensitivity.
  - All 3 unit test files passed verification during this audit.
- **Integration Test Suite:** `tests/run_tests.php` provides an extensive 410-line suite testing authentication, rate limiting, RBAC, IDOR, SQLi, XSS, stock deduction, invoice mathematics, and tamper detection.
- **Destructive Execution Guard:** Lines 17–22 of `tests/run_tests.php` enforce a strict guard:
  ```php
  if (getenv('GMS_ALLOW_DESTRUCTIVE_TESTS') !== '1'
      || !str_ends_with($dbConfig['name'], '_test')
      || !in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true)) {
      fwrite(STDERR, "Refusing destructive tests: use a local database ending in _test...\n");
      exit(2);
  }
  ```

#### Findings & Vulnerability Analysis
- **Finding SEC-TEST-01 (High):** Live database mutation risk in `run_tests.php`.  
  *Detail:* `run_tests.php` performs destructive operations including `DELETE FROM login_attempts`, dropping triggers, inserting dummy test entities, and simulating audit tampering. It MUST NOT be executed against the live database `garage_management_system`.
- **Finding SEC-TEST-02 (Medium):** Missing automated test database orchestration.  
  *Detail:* There is no single command to provision an isolated `*_test` database, import schema, seed demo accounts, run tests, and tear down the environment safely.
- **Finding SEC-TEST-03 (Medium):** Storage isolation not enforced in `run_tests.php`.  
  *Detail:* While `app/helpers/functions.php` supports `GMS_STORAGE_PATH`, `tests/run_tests.php` does not explicitly verify or configure an isolated test storage directory before running. Running tests without `GMS_STORAGE_PATH` could pollute live rate-limit caches or mail spools.

---

## 4. Summary of Audit Findings by Severity

| ID | Title | Severity | Area | Recommended Mitigation Phase |
|---|---|---|---|---|
| **SEC-AUD-01** | Missing MySQL Append-Only Triggers on `audit_logs` | **High** | Audit Logging | Phase 1.9C / Schema Hardening |
| **SEC-TEST-01** | Destructive Test Suite Lacks Automated Isolation Harness | **High** | Test Infrastructure | Phase 1.9B / Test Harness |
| **SEC-AUTH-01** | Direct Reset Link Rendered On-Screen when Debug Enabled | **Medium** | Authentication | Phase 1.9D / Code Hardening |
| **SEC-APP-01** | Installer Script `setup.php` Present in Public Web Root | **Medium** | Application Security | Phase 1.9D / Release Cleanup |
| **SEC-FIN-01** | Partial Payment Double-Submit Concurrency Risk | **Medium** | Financial Correctness | Phase 1.9E / Ledger Hardening |
| **SEC-TEST-02** | Missing Automated Provisioning / Teardown for Test Database | **Medium** | Test Infrastructure | Phase 1.9B / Test Harness |
| **SEC-TEST-03** | Test Harness Does Not Enforce Isolated Storage Directory | **Medium** | Test Infrastructure | Phase 1.9B / Storage Isolation |
| **SEC-RBAC-01** | Customer Role Routed to Staff-Only Appointment Form | **Low/Medium** | RBAC / Workflow | Phase 1.9F / Workflow Polish |
| **SEC-RBAC-02** | Insecure Direct Object Reference in `NotificationService::markRead` | **Low** | RBAC / IDOR | Phase 1.9D / Service Hardening |
| **SEC-APP-02** | Static 2-Hour CSRF Token Validity Window | **Low** | Application Security | Phase 1.9D / Security Tuning |
| **SEC-BUS-01** | Orphaned Appointment State on Vehicle Deactivation | **Low** | Business Workflow | Phase 1.9F / Workflow Polish |
| **SEC-DB-01** | Missing Composite Index on `login_attempts` | **Low** | Database Architecture | Phase 1.9C / DB Optimization |
| **SEC-AUD-02** | Missing Automated Daily Audit Chain Verification Cron | **Low** | Audit Logging | Phase 1.9G / Operations |
