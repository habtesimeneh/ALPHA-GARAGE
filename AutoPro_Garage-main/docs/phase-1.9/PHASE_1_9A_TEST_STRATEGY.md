# ALPHA GARAGE — PHASE 1.9A
# COMPREHENSIVE TEST STRATEGY & ISOLATION PLAN

**Strategy Date:** November 13, 2026  
**Author:** Antigravity  
**Target Environment:** Local MAMP Development (Windows 10/11)  
**Live Database:** `garage_management_system` (Strictly Protected — Zero Test Execution)  
**Dedicated Test Database:** `garage_management_system_test` (Ephemeral / Isolated)  
**Document Status:** Test Planning Deliverable (Read-Only Phase)  

---

## 1. Testing Philosophy & Non-Negotiable Safety Principles

The ALPHA GARAGE system manages real automotive business data, vehicle histories, inventory ledgers, and financial records. Testing must adhere to the following absolute safety rules:

1. **Zero Live Database Touch:** Under no circumstances may integration or destructive test scripts run against the live database `garage_management_system`.
2. **Mandatory Storage Isolation:** Test execution must never write sessions, rate-limit state, logs, or encryption files to the live `storage/` directory. All tests must execute against a dedicated, isolated directory configured via `GMS_STORAGE_PATH`.
3. **Automated Lifecycle Management:** The test environment must follow an automated, repeatable lifecycle: **Provision -> Migrate -> Seed -> Execute -> Audit Verify -> Teardown**.
4. **Evidence-Based Pass Criteria:** No test shall be marked `PASS` based on assumptions or HTTP 200 codes alone. Tests must assert against explicit database state, return payloads, and audit-log records.
5. **No Framework / Dependency Bloat:** All testing utilities and runners must remain native PHP 8.3 and vanilla PowerShell, preserving the project's zero-dependency, framework-free architecture.

---

## 2. Test Architecture & Coverage Matrix

### 2.1 Existing vs. Missing Test Coverage

```
+-------------------------------------------------------------------------------+
| LEVEL 1: Pure Unit Tests (In-Memory, No DB, Safe Everywhere)                  |
| [X] Password hashing & policy (auth_unit.php)                                 |
| [X] CSRF token boundary calculation (auth_unit.php)                           |
| [X] Inventory negative/oversized rejection (inventory_unit.php)               |
| [X] AES-256-GCM encryption roundtrip & blind index (security_hardening_unit)  |
| [!] MISSING: Integer cents money calculation edge cases (half-cents, round)   |
| [!] MISSING: Job state machine transition validator unit tests                |
+-------------------------------------------------------------------------------+
                                        |
+-------------------------------------------------------------------------------+
| LEVEL 2: Read-Only Live Smoke Tests (Safe on Production / Live Environment)   |
| [X] HTTP / HTTPS response codes (200 on login, public root, assets)           |
| [X] Non-public directory isolation (404 on /storage/, /docs/, /app/, etc.)    |
| [X] Dotfile / metadata blocking (403 on .git, .env.example)                   |
| [X] Directory indexing prohibition (403 on /assets/, /assets/css/)            |
| [X] Live audit hash-chain verification (AuditLogger::verifyChain())           |
| [!] MISSING: Automated daily health-check smoke runner                        |
+-------------------------------------------------------------------------------+
                                        |
+-------------------------------------------------------------------------------+
| LEVEL 3: Isolated Integration Tests (Destructive / Dedicated Test DB ONLY)    |
| [X] Authentication & user lockout (run_tests.php)                             |
| [X] Role-based controller access gating (run_tests.php)                       |
| [X] Object-level IDOR mechanic isolation (run_tests.php)                      |
| [X] SQL injection & XSS payload rejection (run_tests.php)                     |
| [X] Stock deduction on part issuance & auto-restock on cancel (run_tests.php) |
| [X] Invoice subtotal, discount, tax, and multi-payment math (run_tests.php)   |
| [X] Cryptographic audit-chain tamper detection (run_tests.php)                |
| [!] MISSING: Storage directory isolation harness (GMS_STORAGE_PATH)           |
| [!] MISSING: Single-command test database provisioning script                 |
| [!] MISSING: Customer registration & role permission isolation tests          |
| [!] MISSING: Concurrent partial payment idempotency test                      |
| [!] MISSING: Notification state IDOR test                                     |
+-------------------------------------------------------------------------------+
```

---

## 3. Dedicated Test Environment Architecture

### 3.1 Test Database Specifications
- **Database Name:** `garage_management_system_test`  
  *(Must end strictly with `_test` to satisfy the safety guard in `tests/run_tests.php`).*
- **Database User:** `garage_test_runner` (or least-privilege `garage_app` granted temporary rights on `*_test`).
- **Host:** Strictly bound to `127.0.0.1` / `localhost`.
- **Port:** `3306` (MAMP MySQL).

### 3.2 Test Storage Directory Specifications
- **Dedicated Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\storage\testing\`  
  *(Alternatively: `C:\Users\Temesgen\alpha-garage-test-storage` outside the project tree).*
- **Subdirectories Auto-Created by `storage_path()`:**
  - `storage/testing/logs/`
  - `storage/testing/rate_limits/`
  - `storage/testing/sessions/`
  - `storage/testing/mail_spool/`
  - `storage/testing/private/`
- **Environment Variable Binding:**
  ```powershell
  $env:GMS_STORAGE_PATH = "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\storage\testing"
  $env:GMS_TEST_MODE = "1"
  $env:GMS_ALLOW_DESTRUCTIVE_TESTS = "1"
  ```

---

## 4. Test Suite Categorization & Recommended Test Cases

### Suite A: Authentication & Session Security Tests
| Test ID | Test Case Name | Input / Vector | Expected Result | Assertion / Verification |
|---|---|---|---|---|
| **TC-AUTH-01** | Valid Admin Login | Valid username + password | HTTP 302 -> `/dashboard.php` | Session regenerated, `login_success` audited |
| **TC-AUTH-02** | Valid Email Login | Valid email + password | HTTP 302 -> `/dashboard.php` | Authentication succeeds via email identifier |
| **TC-AUTH-03** | Wrong Password Rejection | Valid username + wrong pass | HTTP 200 (Form redisplayed) | "Invalid username or password", `login_failed` audited |
| **TC-AUTH-04** | Timing-Equalized Unknown User | Non-existent username | HTTP 200 (Identical error) | Dummy hash executed; response time indistinguishable |
| **TC-AUTH-05** | User Lockout Threshold | 5 consecutive bad logins | HTTP 200 ("Too many failed attempts") | `isLocked() === true`, `login_blocked_rate_limit` audited |
| **TC-AUTH-06** | Session Fixation Protection | Capture session ID before & after | Session ID changes | Old session ID destroyed; new ID created |
| **TC-AUTH-07** | Session User-Agent Hijacking | Alter User-Agent header in session | HTTP 302 -> `/login.php` | Session destroyed; re-login forced |
| **TC-AUTH-08** | Idle Session Timeout | Advance time > 1800s | HTTP 302 -> `/login.php` | Flash warning: "Your session expired" |
| **TC-AUTH-09** | Remember-Me Cookie Lifecycle | Login with remember=1 | Cookie `GMS_REMEMBER` set | SHA-256 hash stored in DB; rotated on subsequent visit |
| **TC-AUTH-10** | Single-Use Password Reset Token | Complete reset with raw token | HTTP 302 -> `/login.php` | Token marked `used_at`; cannot be used a second time |

### Suite B: Role-Based Access Control (RBAC) & IDOR Tests
| Test ID | Test Case Name | Role / Persona | Endpoint / Action | Expected Result |
|---|---|---|---|---|
| **TC-RBAC-01** | Admin Full Clearance | Administrator | `/audit-logs/`, `/users/`, `/reports/` | HTTP 200 on all administrative pages |
| **TC-RBAC-02** | Receptionist Audit Block | Receptionist | `GET /audit-logs/` | **HTTP 403 Forbidden** (`audit.view` denied) |
| **TC-RBAC-03** | Receptionist User Admin Block| Receptionist | `GET /users/` | **HTTP 403 Forbidden** (`users.manage` denied) |
| **TC-RBAC-04** | Mechanic Financial Block | Mechanic | `GET /invoices/`, `GET /payments/` | **HTTP 403 Forbidden** (`invoices.view` denied) |
| **TC-RBAC-05** | Mechanic IDOR Job Isolation | Mechanic 1 | `GET /job-cards/view.php?id={mech2_job}` | **HTTP 403 Forbidden** (Object access denied) |
| **TC-RBAC-06** | Mechanic IDOR Status Tamper | Mechanic 1 | `POST /job-cards/action.php` (mech2 job) | **HTTP 403 Forbidden** (Status change blocked) |
| **TC-RBAC-07** | Accountant Expense Clearance | Accountant | `GET /expenses/` | HTTP 200 OK |
| **TC-RBAC-08** | Accountant Customer Create Block | Accountant | `POST /customers/form.php` | **HTTP 403 Forbidden** (`customers.manage` denied) |
| **TC-RBAC-09** | Customer Dashboard Denial | Customer | `GET /dashboard.php` | **HTTP 403 Forbidden** (`dashboard.view` denied) |
| **TC-RBAC-10** | Notification State IDOR Fix | User A | `POST /notifications/mark_read.php` (User B id)| **HTTP 403** or Unchanged if not owner |

### Suite C: Injection, Input Validation & Security Headers Tests
| Test ID | Test Case Name | Payload / Vector | Target Endpoint | Expected Result |
|---|---|---|---|---|
| **TC-INJ-01** | SQLi Search Parameter | `' OR '1'='1' -- ` | `GET /customers/?q=...` | HTTP 200, "No customers found" (0 SQL errors) |
| **TC-INJ-02** | UNION SQL Injection | `x%' UNION SELECT 1.. -- ` | `GET /customers/?q=...` | HTTP 200, 0 leak of unauthorized rows |
| **TC-INJ-03** | Stored XSS in Customer Name| `<script>alert('xss')</script>` | `POST /customers/form.php` | Stored, but escaped as `&lt;script&gt;` on view |
| **TC-INJ-04** | Stored XSS in Address Attribute| `"><img src=x onerror=alert(1)>`| `POST /customers/form.php` | Escaped via `e()`; no script execution |
| **TC-INJ-05** | CSV Formula Injection | `=CMD|' /C calc'!A0` | CSV export endpoints | Prepended with `'` by `csv_safe()` |
| **TC-INJ-06** | CSRF Token Missing | POST request without `_csrf` | All state-changing endpoints | **HTTP 419 Session Expired** |
| **TC-INJ-07** | CSRF Token Forged | POST with `_csrf=deadbeef` | All state-changing endpoints | **HTTP 419 Session Expired** |
| **TC-INJ-08** | Security Headers Validation | Inspect response headers | `/`, `/login.php`, `/dashboard.php` | CSP, X-Frame-Options: DENY, nosniff present |

### Suite D: Business Workflow & Financial Integrity Tests
| Test ID | Test Case Name | Business Scenario | Operational Verification |
|---|---|---|---|
| **TC-FIN-01** | Work Order -> Invoice Flow | Complete job card JC-001 | Invoice generated with status `Unpaid`, matching labor + parts |
| **TC-FIN-02** | Subtotal & Tax Calculation | $1000 labor + $250.50 parts, 15% tax | Subtotal: $1250.50, Tax: $187.58, Grand: $1438.08 |
| **TC-FIN-03** | Discount Limit Enforcement | Attempt discount > subtotal | Blocked with `DomainException` ("Cannot exceed subtotal") |
| **TC-FIN-04** | Partial Payment Accounting | Pay $500 on $1438.08 balance | Status becomes `Partially Paid`, balance updated to $938.08 |
| **TC-FIN-05** | Overpayment Rejection | Attempt payment > current balance | Blocked with `DomainException` ("Exceeds outstanding balance") |
| **TC-FIN-06** | Full Payment Settlement | Settle remaining balance | Status becomes `Paid`, balance becomes $0.00 |
| **TC-FIN-07** | Authorized Refund Re-opening| Refund $100 on paid invoice | Net paid becomes $1338.08, status reverts to `Partially Paid`, balance $100 |
| **TC-FIN-08** | Over-Refund Rejection | Attempt refund > net paid | Blocked with `DomainException` ("Cannot exceed net paid") |
| **TC-FIN-09** | Stock Depletion on Part Issue | Issue 3 units of part with 5 stock | Available stock drops to 2; `inventory_transactions` logged |
| **TC-FIN-10** | Stock Depletion Overflow Block | Issue 4 units of part with 2 stock | Blocked with `DomainException`; stock remains 2 |
| **TC-FIN-11** | Cancelled Job Auto-Restock | Cancel job with 2 issued parts | Parts marked `returned = 1`; stock restored to original count |

### Suite E: Cryptographic Audit Trail & Trigger Enforcement Tests
| Test ID | Test Case Name | Test Vector | Expected Verification Result |
|---|---|---|---|
| **TC-AUD-01** | Linear Hash Chaining Verification | Run `AuditLogger::verifyChain()` | `ok: true`, all records pass cryptographic linkage |
| **TC-AUD-02** | Historical Row Tamper Detection | Modify `new_value` of historic row | `verifyChain()` returns `ok: false`, flags exact `bad_id` |
| **TC-AUD-03** | Historical Row Deletion Detection| Delete historic record from chain | `verifyChain()` flags broken `prev_hash` on subsequent row |
| **TC-AUD-04** | MySQL Trigger UPDATE Block | Attempt `UPDATE audit_logs SET ...` | MySQL raises SQLSTATE 45000 ("append-only") |
| **TC-AUD-05** | MySQL Trigger DELETE Block | Attempt `DELETE FROM audit_logs ...` | MySQL raises SQLSTATE 45000 ("append-only") |
| **TC-AUD-06** | Sensitive Data Redaction | Log event containing `password` | Value stored as `[REDACTED]` in JSON payload |

---

## 5. Safe Execution Sequence for Phases 1.9B – 1.9H

To guarantee that the live database `garage_management_system` remains untouched and that all verification is conducted with rigorous isolation, the following phased roadmap is recommended:

```
+-----------------------------------------------------------------------------------+
| PHASE 1.9B: Automated Test Harness & Environment Isolation                        |
| - Develop scripts/provision_test_db.php (Creates & seeds *_test database)         |
| - Implement test storage directory isolation (storage/testing)                    |
| - Verify GMS_TEST_MODE guards prevent live DB connections                         |
+-----------------------------------------------------------------------------------+
                                          |
+-----------------------------------------------------------------------------------+
| PHASE 1.9C: Schema Hardening & Append-Only Trigger Installation                   |
| - Install missing trg_audit_no_update and trg_audit_no_delete triggers on live DB |
| - Verify triggers prevent accidental or malicious updates to audit records        |
| - Add composite index on login_attempts for optimized query performance           |
+-----------------------------------------------------------------------------------+
                                          |
+-----------------------------------------------------------------------------------+
| PHASE 1.9D: Code Hardening & Vulnerability Remediation                            |
| - Suppress plaintext password reset link display even if debug mode is on         |
| - Fix NotificationService::markRead() ownership IDOR                              |
| - Safe removal / migration of public/setup.php installer                          |
+-----------------------------------------------------------------------------------+
                                          |
+-----------------------------------------------------------------------------------+
| PHASE 1.9E: Financial Concurrency & Ledger Hardening                              |
| - Implement payment idempotency tokens / duplicate submit guards                  |
| - Comprehensive integer cents rounding verification under extreme tax rates       |
+-----------------------------------------------------------------------------------+
                                          |
+-----------------------------------------------------------------------------------+
| PHASE 1.9F: Customer Portal & RBAC Workflow Alignment                             |
| - Fix index.php service booking link for logged-in customers                      |
| - Resolve appointment status handling on deactivated customer/vehicle entities    |
+-----------------------------------------------------------------------------------+
                                          |
+-----------------------------------------------------------------------------------+
| PHASE 1.9G: Full Integration & Regression Execution                               |
| - Execute 100% of test suites against the isolated *_test environment             |
| - Verify zero regressions across all 6 roles and all 29 database tables           |
| - Verify teardown cleanly cleans up the test database and testing storage         |
+-----------------------------------------------------------------------------------+
                                          |
+-----------------------------------------------------------------------------------+
| PHASE 1.9H: Release Readiness Certification & Handover                            |
| - Final live read-only smoke audit and cryptographic audit chain verification     |
| - Handover report for production deployment readiness                             |
+-----------------------------------------------------------------------------------+
```
