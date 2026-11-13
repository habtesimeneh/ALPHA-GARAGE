# ALPHA GARAGE — PHASE 1.9A
# COMPREHENSIVE SECURITY & OPERATIONAL RISK REGISTER

**Register Date:** November 13, 2026  
**Auditor:** Antigravity  
**Target Environment:** Local MAMP Development (Windows 10/11)  
**Project Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`  
**Status:** Post-Phase 1.8B Comprehensive Risk Assessment (Read-Only Phase)  

---

## 1. Risk Evaluation Methodology

Risks are assessed based on their potential impact on business continuity, confidentiality, ledger integrity, and legal/regulatory compliance:
- **Severity Levels:**
  - **Critical:** Immediate risk of system-wide compromise, unauthenticated account takeover, data destruction, or uncontrolled financial leakage.
  - **High:** Significant vulnerability or defect that violates core security requirements (e.g. absent audit triggers, destructive tests targeting live data).
  - **Medium:** Vulnerability requiring specific preconditions or configuration mistakes (e.g. debug mode exposure, setup script presence, rapid double-submit race).
  - **Low:** Minor flaw, edge-case architectural inconsistency, or missing operational convenience.
  - **Informational:** Architectural observation or enhancement recommendation.

---

## 2. Risk Heat Map & Matrix

```
                      I M P A C T
              Low           Medium          High
         +-------------+---------------+-------------+
    High |             | SEC-APP-01    | SEC-AUD-01  |
         |             | SEC-TEST-02   | SEC-TEST-01 |
         +-------------+---------------+-------------+
L Medium | SEC-RBAC-01 | SEC-AUTH-01   |             |
I        | SEC-RBAC-02 | SEC-FIN-01    |             |
K        |             | SEC-TEST-03   |             |
E        +-------------+---------------+-------------+
L    Low | SEC-BUS-01  | SEC-AUTH-02   |             |
         | SEC-DB-01   |               |             |
         | SEC-AUD-02  |               |             |
         +-------------+---------------+-------------+
```

---

## 3. Comprehensive Risk Register

| Risk ID | Title & Summary | Category | Severity | Likelihood | Impact | Existing Controls | Residual Risk | Recommended Remediation | Target Phase |
|---|---|---|---|---|---|---|---|---|---|
| **SEC-AUD-01** | **Missing MySQL Append-Only Triggers on `audit_logs`**<br>Triggers `trg_audit_no_update` and `trg_audit_no_delete` are absent from live MySQL. | Audit Logging | **High** | High | High | Runtime user `garage_app` has only `SELECT, INSERT` grants; hash-chain verification detects tampering. | Superuser, MAMP root, or backup restore could alter historic logs without trigger rejection. | Install triggers `trg_audit_no_update` and `trg_audit_no_delete` via atomic migration. | Phase 1.9C |
| **SEC-TEST-01** | **Destructive Test Execution Risk Against Live Database**<br>`tests/run_tests.php` deletes login attempts, drops triggers, and mutates data. | Test Infrastructure | **High** | Medium | High | Guard requires `GMS_ALLOW_DESTRUCTIVE_TESTS=1` and database name ending in `_test`. | Human error could set env var while pointed to live DB if credentials are misconfigured. | Build automated isolation wrapper that programmatically enforces separate DB credentials. | Phase 1.9B |
| **SEC-AUTH-01** | **Password Reset Link Exposure in Debug Mode**<br>`forgot-password.php` prints plaintext reset link on screen if `debug = true` and transport is `dev_spool`. | Authentication | **Medium** | Low | High | `app/config/app.php` sets `debug => false` by default; SMTP spool only active locally. | If `debug` is turned on in staging/production, any user can take over any account via web form. | Suppress UI rendering of `_dev_reset_link` unconditionally or restrict strictly to CLI. | Phase 1.9D |
| **SEC-APP-01** | **One-Time Installer `setup.php` Present in Web Root**<br>`public/setup.php` remains accessible over HTTP. | Application Security | **Medium** | Low | High | Script returns 404 if `storage/installed.lock` exists or `COUNT(users) > 0`. | If database was ever emptied or lock removed, an unauthorized admin account could be created. | Move `setup.php` outside web root or delete file post-installation per README instructions. | Phase 1.9D |
| **SEC-FIN-01** | **Partial Payment Double-Submit Concurrency Risk**<br>Rapid concurrent POST requests for partial payments could record duplicate entries. | Financial Correctness | **Medium** | Medium | Medium | Overpayment is blocked on full balance; invoice locked via `FOR UPDATE`; receipts immutable. | Rapid double-clicks on partial payments within the 2-hour CSRF window could record duplicate credits. | Implement client-side submit disabling and server-side idempotency tokens on payment forms. | Phase 1.9E |
| **SEC-TEST-02** | **Lack of Automated Test Database Provisioning & Teardown**<br>No single command to spin up, migrate, and tear down an ephemeral `*_test` DB. | Test Infrastructure | **Medium** | High | Low | Manual setup documented in README. | Developers may test against live DB out of convenience or leave dirty test data behind. | Create `scripts/provision_test_db.php` to automate full lifecycle. | Phase 1.9B |
| **SEC-TEST-03** | **Unenforced Test Storage Directory Isolation**<br>`run_tests.php` does not programmatically verify `GMS_STORAGE_PATH` before execution. | Test Infrastructure | **Medium** | High | Low | `storage_path()` supports `GMS_STORAGE_PATH` override. | Tests run without explicit env var could pollute production sessions and rate-limit caches. | Enforce `GMS_STORAGE_PATH` check inside `run_tests.php` startup guard. | Phase 1.9B |
| **SEC-RBAC-01** | **Customer Booking Link Routes to Staff-Only Form**<br>Logged-in customers on `index.php` clicking "Book Service" hit `appointments/form.php` (403). | RBAC / Workflow | **Low/Med** | High | Low | Server-side RBAC correctly blocks unauthorized access with 403 Forbidden. | Poor user experience; logged-in customers receive an intimidating access denied screen. | Create dedicated customer booking endpoint or adjust redirect for customer role. | Phase 1.9F |
| **SEC-RBAC-02** | **Insecure Direct Object Reference in `NotificationService::markRead`**<br>`markRead(int $id)` marks any notification read regardless of recipient. | RBAC / IDOR | **Low** | Medium | Low | Notifications are internal informational alerts; no sensitive data is leaked or deleted. | Any authenticated user can mark other users' or administrators' notifications as read. | Add user/role ownership constraint: `WHERE id = ? AND (user_id = ? OR role_name = ?)`. | Phase 1.9D |
| **SEC-APP-02** | **Static 2-Hour CSRF Token Session Lifespan**<br>CSRF token is not regenerated upon POST completion. | Application Security | **Low** | Medium | Low | Token is cryptographically random (256-bit), timing-safe verified, and bound to session. | Token reuse within the 2-hour window allows replay if a request is intercepted. | Implement optional per-form token regeneration on critical state changes. | Phase 1.9D |
| **SEC-BUS-01** | **Orphaned Confirmed Appointments on Vehicle Deactivation**<br>Deactivating a vehicle does not auto-cancel pending appointments. | Business Workflow | **Low** | Low | Low | `JobService::create()` blocks conversion of inactive vehicles with an exception. | Unhandled `DomainException` when receptionist converts appointment to job card. | Add vehicle status pre-check in `AppointmentService::convertToJob()` with user-friendly error. | Phase 1.9F |
| **SEC-DB-01** | **Missing Composite Index on `login_attempts`**<br>Lockout queries filter by `(username, ip_address, attempted_at)`. | Database Architecture | **Low** | Low | Low | Table is small and periodically pruned by housekeeping logic. | Potential table scan latency during sustained brute-force attack attempts. | Add composite index `idx_login_attempts_lookup (username, ip_address, attempted_at)`. | Phase 1.9C |
| **SEC-AUD-02** | **Missing Automated Daily Audit Hash-Chain Verification**<br>Verification is currently manual / on-demand via `AuditLogger::verifyChain()`. | Audit Logging | **Low** | Medium | Low | Live verification confirmed 53/53 records intact; append-only triggers proposed in 1.9C. | Tampering would remain undetected until an administrator manually loads the audit page. | Configure Windows Task Scheduler / cron job to run `verifyChain()` daily and alert on failure. | Phase 1.9G |
| **SEC-AUTH-02** | **MySQL-Backed Rate Limiting Bottleneck**<br>`login_attempts` uses MySQL tables rather than in-memory storage (e.g. Redis / APCu). | Authentication | **Low** | Low | Low | Cleaned up via probabilistic garbage collection (`random_int(1,50) === 1`). | High-volume DDoS could saturate MySQL connection pool. Acceptable for local/small garage scale. | Document requirements for Redis/APCu rate limiting prior to high-traffic production launch. | Phase 2.0 |

---

## 4. Confirmed Vulnerabilities vs. Unverified Assumptions

### 4.1 Confirmed Vulnerabilities (Empirically Verified)
1. **CONFIRMED:** `trg_audit_no_update` and `trg_audit_no_delete` triggers are **ABSENT** in the live database `garage_management_system` (`SHOW TRIGGERS` returned 0).
2. **CONFIRMED:** `PasswordResetService::requestReset()` stores raw reset link in `$_SESSION['_dev_reset_link']` if `debug=true`, and `public/forgot-password.php` renders it in an active HTML alert.
3. **CONFIRMED:** `public/setup.php` is located directly in the web root and is served over HTTP/HTTPS (currently returning 404 due to user count, but physically present).
4. **CONFIRMED:** `NotificationService::markRead()` updates notification records without user scoping.
5. **CONFIRMED:** `public/index.php` directs logged-in customers to `appointments/form.php`, which triggers a 403 Forbidden error because customers lack `appointments.manage`.

### 4.2 Unverified Assumptions (Requiring Isolated Test DB in Phase 1.9B)
1. **ASSUMPTION:** High-concurrency partial payments could race to produce duplicate receipt numbers. *(Requires multi-threaded curl load testing against an isolated test database in Phase 1.9E)*.
2. **ASSUMPTION:** Rounding behavior under fractional tax rates (e.g. 15.375%) handles half-cent rounding identically across all invoice totals. *(Requires parameterized financial unit tests in Phase 1.9E)*.
3. **ASSUMPTION:** Production SMTP delivery aligns with SPF/DKIM/DMARC policies. *(Unverified locally because no live external SMTP mailbox is configured)*.

---

## 5. Next Phase Authorization Gate

Phase 1.9A has maintained **strict read-only integrity**:
- Zero files in `app/`, `public/`, `database/`, or `tests/` were edited.
- Zero records in the live database `garage_management_system` were created, altered, or deleted.
- Live audit hash chain remains 100% valid (53 / 53 records verified).

All findings, test strategies, and risk items are fully documented and submitted for owner review.

**Awaiting Owner Sign-Off to proceed to Phase 1.9B (Test Infrastructure Isolation & Automated Test Database Provisioning).**
