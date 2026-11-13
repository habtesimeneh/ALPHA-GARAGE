# ALPHA GARAGE — PHASE 1.9C: SECURITY FINDINGS & ARCHITECTURAL OBSERVATIONS

## 1. Executive Summary

During the implementation and execution of the **Phase 1.9C Core Security & Authentication Regression Suite**, the application demonstrated robust defensive controls across all 54 evaluated attack surfaces. The core architectural protections—including password hashing, cryptographic audit hash chains, append-only database triggers, strict CSRF validation, session rotation, and role-based access control—are functioning as designed.

In accordance with strict Phase 1.9C instructions, **no production code or database schema was modified**. The findings below are documented for review, prioritization, and remediation in future phases.

---

## 2. Findings Catalog

| Finding ID | Title | Severity | Impact Area | Remediation Phase |
|---|---|---|---|---|
| **SEC-FIND-01** | Database Engine vs Application Runtime Timezone Discrepancy | Medium | Password Reset / Token Lifespan | Phase 1.10 Hardening |
| **SEC-FIND-02** | Windows Process Pipe Buffer Deadlock on Web Server Process | Low (Test Harness) | Automated Testing Infrastructure | Resolved in Phase 1.9C Harness |
| **SEC-FIND-03** | Probabilistic Housekeeping on Rate-Limit Storage Table | Low | DoS / Table Bloat Resistance | Phase 1.10 Maintenance |
| **SEC-FIND-04** | Customer Role Portal Separation & Authentication Boundary | Informational | Authorization & UX | Phase 2.0 Customer Portal |

---

## 3. Detailed Finding Descriptions

### SEC-FIND-01: Database Engine vs Application Runtime Timezone Discrepancy

- **Severity:** Medium
- **Component:** `app/services/PasswordResetService.php`, `app/models/Database.php`, `app/bootstrap.php`
- **Description:**
  `Database.php` executes `SET time_zone = '+03:00'` upon establishing a PDO connection (aligning with `Africa/Addis_Ababa`). In `PasswordResetService::validateToken()`, token validity is checked via:
  ```sql
  WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.is_active = 1
  ```
  However, when inserting reset tokens in `PasswordResetService::requestReset()`:
  ```php
  $expiresAt = date('Y-m-d H:i:s', time() + (self::TOKEN_LIFETIME_MINUTES * 60));
  ```
  If any CLI tool, background script, or alternative worker invokes this service without having executed `date_default_timezone_set($config['timezone'])` (or if PHP runtime defaults to UTC), `$expiresAt` will be calculated in UTC (e.g. 11:45), while MySQL evaluates `NOW()` in UTC+3 (e.g. 14:45). This causes newly created reset tokens to immediately fail the `expires_at > NOW()` query check.
- **Reproduction Steps:**
  1. Initialize PHP CLI with default timezone `UTC`.
  2. Connect to MySQL with session `time_zone = '+03:00'`.
  3. Insert a password reset token with expiration `date('Y-m-d H:i:s', time() + 1800)`.
  4. Query `SELECT * FROM password_resets WHERE expires_at > NOW()`.
  5. Result: 0 rows returned; token appears prematurely expired.
- **Recommended Remediation (Future Phase):**
  - Standardize token insertion to use MySQL's native interval calculation:
    ```sql
    INSERT INTO password_resets (user_id, token_hash, expires_at)
    VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))
    ```
  - Ensure all CLI entry points consistently require `app/bootstrap.php` or invoke `date_default_timezone_set()`.

---

### SEC-FIND-02: Windows Process Pipe Buffer Deadlock on Web Server Process

- **Severity:** Low (Test Infrastructure)
- **Component:** `tests/security_regression_suite.php`, `proc_open` on Windows
- **Description:**
  When spawning the PHP built-in web server (`php -S`) via `proc_open()` with stdout/stderr pipes (`['pipe', 'w']`), the Windows OS pipe buffer holds approximately 4 KB of output. Once the test suite generates more than ~10 HTTP requests, the server process blocks waiting for the pipe to be drained by the parent, while the parent blocks waiting for the cURL HTTP response, causing a permanent deadlock.
- **Resolution Applied in Phase 1.9C:**
  The test harness now redirects server standard output and error to an isolated log file (`storage/testing/logs/test-server.log`):
  ```php
  $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['file', $serverLog, 'a'],
      2 => ['file', $serverLog, 'a'],
  ];
  ```
  This eliminates pipe buffer limits entirely and preserves full HTTP diagnostic logs.

---

### SEC-FIND-03: Probabilistic Housekeeping on Rate-Limit Storage Table

- **Severity:** Low / Hardening
- **Component:** `app/services/AuthService.php` (`recordAttempt`)
- **Description:**
  In `AuthService.php`, expired login attempts (> 7 days) are purged probabilistically:
  ```php
  if (random_int(1, 50) === 1) {
      Database::run('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 86400 * 7)]);
  }
  ```
  While this avoids executing a `DELETE` query on every failed login, during sustained, high-volume brute-force attacks against multiple usernames, the table can accumulate thousands of rows before a cleanup is triggered.
- **Recommended Remediation (Future Phase):**
  - Add a composite index on `(attempted_at, success)` in `login_attempts` if not already present.
  - Implement a dedicated maintenance cron / scheduled task that purges expired attempts older than `lockout_minutes * 2` rather than keeping 7 days of non-successful attempts in the live transaction path.

---

### SEC-FIND-04: Customer Role Portal Separation & Authentication Boundary

- **Severity:** Informational
- **Component:** `app/middleware/auth.php`, `app/middleware/permissions.php`
- **Description:**
  The RBAC matrix properly prevents accounts with the `customer` role from accessing staff dashboards, financial reports, inventory, and administrative actions (tested in `RBAC-08`). However, because ALPHA GARAGE is currently an internal garage management system without a dedicated public customer portal, authenticated customer accounts are redirected to an authorization failure or a generic view.
- **Recommended Remediation (Future Phase):**
  - If customers should never log into the management system, reject customer authentication at `login.php` with an explicit notice, or restrict `login.php` to staff roles only.
  - If a customer portal is built in Phase 2.0, establish a dedicated `/portal/` route with strict customer-only permissions (viewing vehicle repair status, downloading completed invoices).

---

## 4. Verification of Core Defenses

The regression suite confirmed the following foundational security capabilities are operating at full effectiveness:

1. **Anti-Enumeration Protections:** Password recovery and authentication failure messages are strictly indistinguishable for valid vs invalid usernames.
2. **Cryptographic Integrity:** The tamper-evident SHA-256 hash chain verified intact across 330+ records, and database triggers strictly blocked unauthorized SQL `UPDATE` and `DELETE` attempts.
3. **Session Hardening:** All session cookies enforce `HttpOnly`, `SameSite=Lax`, and dynamic `Secure` flags; session IDs regenerate immediately post-authentication.
4. **Injection Neutralization:** Boolean SQLi, UNION-based SQLi, and script-based XSS payloads were completely neutralized by prepared statements and `e()` escaping.
