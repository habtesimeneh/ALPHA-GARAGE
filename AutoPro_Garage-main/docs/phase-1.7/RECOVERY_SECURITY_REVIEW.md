# Phase 1.7C — Administrator Recovery Tool Security Review

**Phase:** 1.7C — Admin Recovery Hardening & Local Web Root Isolation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System (Native PHP 8.3.1, MySQL 5.7.24, Apache 2.4.33, MAMP Windows)  
**Target File:** `database/recover_admin.php`  
**Review Mode:** **READ-ONLY CODE & ARCHITECTURE AUDIT.** No live code, configuration, or database records were modified.

---

## 1. Executive Summary

An exhaustive security and architectural inspection of `database/recover_admin.php` was conducted to evaluate its resilience, authorization gating, concurrency controls, and failure modes.

### Key Audit Findings
1. **CLI Enforcement is Robust:** HTTP execution is doubly blocked by application-level runtime gating (`PHP_SAPI !== 'cli'`) and directory-level web server denial (`database/.htaccess` `Require all denied`).
2. **Current Recovery Logic Contains an Architectural Deadlock:**
   Line 52 checks:
   ```sql
   SELECT COUNT(*) FROM users WHERE role_id = ?
   ```
   This query counts **all** administrator records, regardless of whether `is_active` is `1` or `0`. If all administrators in the database are deactivated (e.g. following staff offboarding, incident response, or test account deactivation), this script terminates with an exception. **The owner cannot use this script to recover access if even one inactive administrator row remains in `users`.**
3. **Evaluating the Proposed Check:**
   Replacing the query with:
   ```sql
   SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1
   ```
   safely resolves the recovery deadlock while preserving the strict invariant that recovery cannot run when an active administrator exists.
4. **Concurrency & Duplication Protection:**
   The tool utilizes `Database::transaction()` and acquires an exclusive row lock on the `roles` table (`SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE`). Concurrent executions are serialized, and schema uniqueness constraints on `username` and `email` prevent duplicate entries.
5. **Credential Security & Audit Integrity:**
   The tool generates 288 bits of cryptographic entropy (`random_bytes(36)`), hashes passwords with Bcrypt (cost 10), sets `must_change_password = 1`, and logs an audited event (`administrator_recovered`) into the append-only SHA-256 hash chain.

---

## 2. In-Depth Evaluation by Security Criteria

### 2.1 CLI-Only Enforcement & HTTP Attack Surface
- **Code Implementation (Lines 4–7):**
  ```php
  if (PHP_SAPI !== 'cli') {
      http_response_code(404);
      exit("Not found.\n");
  }
  ```
- **Evaluation:**
  - `PHP_SAPI` reflects the Server API interface between the PHP engine and the operating system. In web environments (Apache `mod_php`, PHP-FPM, FastCGI, CGI), `PHP_SAPI` evaluates to `apache2handler`, `fpm-fcgi`, `cgi-fcgi`, etc.
  - In a standard CLI invocation, `PHP_SAPI` strictly equals `'cli'`.
  - When invoked over HTTP, the script immediately responds with HTTP 404 Not Found and halts execution before requiring `bootstrap.php` or establishing database connections.
- **Defense in Depth:**
  - `database/.htaccess` contains `Require all denied`.
  - Apache `httpd.conf` lines 305–319 deny direct access to database files (`.sql`).
  - **Conclusion:** Unauthorized users cannot execute this script through HTTP.

---

### 2.2 Active Administrator Detection & The Deadlock Problem
- **Current Code Implementation (Lines 52–54):**
  ```php
  if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ?', [(int) $roleId]) > 0) {
      throw new RuntimeException('An administrator account already exists; recovery did not create another.');
  }
  ```
- **The Problem:**
  - The query filters only on `role_id = ?`.
  - It treats inactive (`is_active = 0`) accounts identically to active (`is_active = 1`) accounts.
  - **Failure Scenario:**
    1. A system has an active test admin (ID 1) and an unverified admin (ID 3).
    2. To secure the system, test admin ID 1 is deactivated (`is_active = 0`).
    3. The owner discovers they do not have credentials for ID 3 and deactivates ID 3.
    4. Zero active administrators exist. No user can log in.
    5. The owner attempts to run `php database/recover_admin.php`.
    6. `SELECT COUNT(*) FROM users WHERE role_id = ?` returns `2`.
    7. The script throws `RuntimeException` and aborts.
    8. **The owner is permanently locked out without low-level MySQL database manipulation.**

---

### 2.3 Evaluation of Proposed Check (`is_active = 1`)
- **Proposed Query:**
  ```sql
  SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1
  ```
- **Security Implications & Risk Assessment:**

| Threat / Scenario | Evaluation | Risk Level | Mitigation in Place |
|:---|:---|:---:|:---|
| **Legitimate Recovery** | Allows recovery when 100% of existing admin accounts are disabled. | **Desirable** | Restores operational recoverability. |
| **Active Admin Exists** | Returns `COUNT > 0`, immediately aborting recovery. | **Safe** | Prevents unauthorized admin additions when the system is healthy. |
| **Rogue Admin Creation** | Attacker executes CLI tool to grant themselves admin. | **Low** | Requires OS shell access. If an attacker has OS CLI access, they already have access to `local.php` and the database. |
| **Username Collision** | Recovery username matches an existing disabled account. | **Safe** | Line 55 checks `SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(email) = ?` across ALL users regardless of active status. |
| **Email Collision** | Recovery email matches an existing disabled account. | **Safe** | Line 55 checks email across ALL users; `users.email` unique constraint prevents duplicate insert. |
| **Race Conditions** | Two operators run recovery simultaneously. | **Safe** | Row lock `SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE` serializes the transaction. |

**Verdict:** Replacing the check with `is_active = 1` is safe, effective, and necessary to eliminate the administrative lockout deadlock.

---

### 2.4 Operator Confirmation & Anti-Automation Guard
- **Code Implementation (Lines 23–27):**
  ```php
  fwrite(STDOUT, "This creates one administrator only when no administrator account exists.\n");
  fwrite(STDOUT, "Continue only with explicit garage-owner authorization.\n");
  if (prompt_line('Type RECOVER ADMIN to continue: ') !== 'RECOVER ADMIN') {
      throw new RuntimeException('Administrator recovery was not confirmed.');
  }
  ```
- **Evaluation:**
  - Prevents accidental execution by interactive shell users.
  - Requires exact character match (`RECOVER ADMIN`).
  - Does not support blind non-interactive piping unless the operator explicitly passes the confirmation string.

---

### 2.5 Input Validation & Sanitization
- **Code Implementation (Lines 29–41):**
  - Prompts for `full_name`, `username`, and `email`.
  - Normalizes email via `strtolower(trim($email))`.
  - Enforces `required|max:120` on `full_name`.
  - Enforces `required|min:3|max:50` on `username` and regex `^[A-Za-z0-9_.-]+$`.
  - Enforces `required|email|max:150` on `email`.
  - Rejects inputs immediately via `InvalidArgumentException` before touching database transactions.

---

### 2.6 Credential Generation & Password Security
- **Code Implementation (Lines 43–45):**
  ```php
  do {
      $password = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
  } while (AuthService::passwordProblems($password, $username));
  ```
- **Evaluation:**
  - Uses `random_bytes(36)` (CSPRNG via Windows CryptGenRandom / PHP 8 `Random`).
  - 36 bytes = 288 bits of cryptographic entropy.
  - Generates a 48-character URL-safe string.
  - Verifies compliance with `AuthService::passwordProblems()`.
  - Hashes using `AuthService::hash($password)`:
    - Algorithm: `PASSWORD_BCRYPT` (`$2y$`).
    - Cost: `10` (1,024 iterations).
  - Forces password rotation on initial login: `must_change_password = 1`.
  - Plaintext password is displayed to STDOUT once and never logged.

---

### 2.7 Concurrency & Duplicate Prevention
- **Code Implementation (Lines 47–61):**
  - Enclosed in `Database::transaction()`.
  - Exclusive Row Lock:
    ```php
    $roleId = Database::value("SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE");
    ```
    In MySQL InnoDB, `FOR UPDATE` on `roles` locks the row `name = 'administrator'`. Any concurrent execution attempting the same recovery halts and waits until the active transaction commits or rolls back.
  - Once the first transaction inserts the new administrator (`is_active = 1`), the second transaction unblocks, re-evaluates `COUNT(*) FROM users WHERE role_id = ? AND is_active = 1`, detects the new active admin, and throws `RuntimeException`.
  - Uniqueness constraints (`UNIQUE KEY username`, `UNIQUE KEY email`) in the database schema enforce database-level duplicate prevention.

---

### 2.8 Audit Logging & Chain Integrity
- **Code Implementation (Lines 67–75):**
  ```php
  AuditLogger::log(
      'administrator_recovered',
      'users',
      $id,
      null,
      ['username' => $username],
      'success',
      ['user_id' => $id, 'username' => $username, 'role' => 'administrator']
  );
  ```
- **Evaluation:**
  - Automatically joins the active transaction in `AuditLogger::log()`.
  - Locks `audit_chain_head` for update (`SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE`).
  - Reads previous row hash, computes SHA-256 hash of new row content + previous hash, and updates `audit_chain_head`.
  - If the database transaction rolls back, the audit record rolls back with it, preserving chain continuity.
  - Database append-only triggers (`trg_audit_no_update`, `trg_audit_no_delete`) protect the record from subsequent tampering.

---

### 2.9 Error Handling & Information Leakage
- **Code Implementation (Lines 82–86):**
  ```php
  } catch (Throwable $e) {
      app_log('CLI administrator recovery failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
      fwrite(STDERR, 'Administrator recovery failed: ' . $e->getMessage() . PHP_EOL);
      exit(1);
  }
  ```
- **Evaluation:**
  - Internal details (file paths, line numbers, exception classes) are logged securely to `storage/logs/app.log` (outside web root).
  - Standard error (`STDERR`) receives a sanitized message (`$e->getMessage()`).
  - Exits with status code `1` (POSIX failure).

---

## 3. Summary of Identified Security Deficiencies & Recommendations

| Item | Current Behavior | Security Assessment | Recommended Remediation |
|:---:|:---|:---|:---|
| **1. Admin Check** | `COUNT(*) FROM users WHERE role_id = ?` | **Critical Defect (Deadlock):** Prevents recovery when all admins are disabled. | Update query to: `WHERE role_id = ? AND is_active = 1`. |
| **2. Audit Actor Attribution** | Attributes recovery to the new user ID (`['user_id' => $id]`). | **Minor Ambiguity:** Makes the action look self-performed rather than system-recovered. | Pass actor as: `['user_id' => $id, 'username' => 'CLI Recovery (' . $username . ')', 'role' => 'administrator']` or record explicit CLI flag. |
| **3. Terminal Echo** | Prompts display typed username/email in console history. | **Low Risk:** Username/email are non-secret; password is generated, not typed. | Acceptable. Reiterate instruction to clear console history after recovery. |
| **4. Database Privileges** | Runs using application connection (`local.php`). | **Acceptable:** `garage_app` has `INSERT` privileges on `users` and `audit_logs`. | Retain application PDO connection. |
