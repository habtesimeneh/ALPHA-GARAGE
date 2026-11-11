# Phase 1.7A — Administrator Identity Investigation

**Phase:** 1.7A — Read-Only Admin Identity Investigation & Local Release Preparation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System (PHP 8.3.1, MySQL 5.7.24, Apache 2.4.33, MAMP Windows)  
**Database:** `garage_management_system`  
**Execution Mode:** **READ-ONLY.** Zero live user accounts created, modified, activated, deactivated, or deleted. Zero password hashes or roles altered. Zero audit rows modified or removed.  
**Decision Status:** **AWAITING OWNER EXPLICIT APPROVAL.** No database mutation will occur without authorization.

---

## 1. Executive Summary

During Phase 1.5 and Phase 1.6 security reviews, anomalous administrator accounts were detected in the live database. In accordance with the Phase 1.7A Absolute Safety Rules, this read-only investigation inspected the live database schema, verified all accounts holding administrative privileges, examined all 40 audit records in the tamper-evident log, and analyzed login attempt histories.

### Key Findings
1. **Exactly Two Administrator Accounts Exist in the Live Database:**
   - **Account A (ID 1):** `kilotest_auth_63e8ee04` — Full Name: `Kilo Auth Test`, Email: `kil***@example.invalid`, Status: `Active (1)`, Role: `administrator` (40/40 permissions).
   - **Account B (ID 3):** `acount` — Full Name: `adirgeh`, Email: `adm***@gmail.com`, Status: `Active (1)`, Role: `administrator` (40/40 permissions).
2. **Zero Inactive Administrators:** Both accounts have `is_active = 1`.
3. **Account A is a Confirmed Automated Test Artifact:**
   - Uses an RFC 2606 reserved `.invalid` top-level domain.
   - Suffix `63e8ee04` matches automated authentication test scripts.
   - Has **never logged in** (`last_login_at` is `NULL`, 0 login attempts, 0 audit rows, 0 business transactions).
4. **Account B is the Active Operating Administrator but Requires Owner Confirmation:**
   - Created via the first-run installer (`user_created` audit record #3 on 2026-11-08 14:57:34).
   - Shows genuine usage (17 successful logins, last login 2026-11-09 09:58:21 from `::1`).
   - The username (`acount`) is an informal or misspelled placeholder, while the full name (`adirgeh`) represents a real individual. Per safety directives, **account ownership cannot be inferred from username alone** and must be explicitly confirmed by the garage owner.
5. **CLI Owner Recovery is Currently Blocked:**
   - The emergency CLI recovery tool (`database/recover_admin.php`) refuses to execute if any administrator account already exists. Because ID 1 and ID 3 exist, emergency recovery will abort.

---

## 2. Database Schema Inspection

The database schema was inspected directly via PDO prepared queries against MySQL `information_schema` and table definitions:

### 2.1 Schema: `users` Table
```sql
CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id TINYINT UNSIGNED NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  remember_token CHAR(64) NULL,
  remember_expires_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY (role_id),
  KEY (remember_token),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.2 Schema: `roles` & `role_permissions` Tables
```sql
CREATE TABLE roles (
  id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(40) NOT NULL UNIQUE,
  label VARCHAR(60) NOT NULL,
  description VARCHAR(255) NULL
);

CREATE TABLE role_permissions (
  role_id TINYINT UNSIGNED NOT NULL,
  permission_id SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
);
```

### 2.3 Roles and Permission Distribution
Query: `SELECT r.id, r.name, r.label, COUNT(rp.permission_id) AS perm_count FROM roles r LEFT JOIN role_permissions rp ON r.id = rp.role_id GROUP BY r.id;`

| Role ID | Role Name | Label | Permissions Count | Notes |
|:---:|:---|:---|:---:|:---|
| **1** | `administrator` | Administrator | **40 of 40** | Full administrative and security authority |
| **2** | `receptionist` | Receptionist | 20 of 40 | Customer/job reception, appointment booking |
| **3** | `mechanic` | Mechanic | 7 of 40 | Job cards assigned, time/labor recording |
| **4** | `inventory_manager` | Inventory Manager | 9 of 40 | Parts catalog, stock in/out, purchase orders |
| **5** | `accountant` | Accountant | 9 of 40 | Invoicing, payments, expense records, financial reports |
| **6** | `customer` | Customer | 0 of 40 | Intentionally unprivileged (public portal scope) |

**Conclusion:** Only users assigned `role_id = 1` hold administrative privileges. No intermediate or customer role has been granted escalated or unexpected administrator permissions.

---

## 3. Complete Administrator Inventory

Query executed:
```sql
SELECT u.id, u.role_id, r.name AS role_name, u.username, u.full_name, u.email,
       u.is_active, u.must_change_password, u.created_at, u.updated_at,
       u.last_login_at, u.last_login_ip, u.remember_token
FROM users u
JOIN roles r ON u.role_id = r.id
WHERE r.name = 'administrator'
ORDER BY u.id ASC;
```

### Account Comparison Table

| Attribute | Account A (ID 1) | Account B (ID 3) |
|:---|:---|:---|
| **User ID** | `1` | `3` |
| **Username** | `kilotest_auth_63e8ee04` | `acount` |
| **Full Name** | `Kilo Auth Test` | `adirgeh` |
| **Email (Masked)** | `kil***@example.invalid` | `adm***@gmail.com` |
| **Role** | `administrator` (Role ID 1) | `administrator` (Role ID 1) |
| **Permissions** | 40 of 40 | 40 of 40 |
| **Active Status** | **1 (Active)** | **1 (Active)** |
| **Must Change Password** | `0` (No) | `0` (No) |
| **Account Created At** | `2026-11-08 14:54:00` | `2026-11-08 14:57:34` |
| **Account Updated At** | `2026-11-08 14:54:00` (Never updated) | `2026-11-09 09:58:21` |
| **Last Login Timestamp** | `NULL` (Never logged in) | `2026-11-09 09:58:21` |
| **Last Login IP** | `NULL` | `::1` (Local loopback) |
| **Remember Token** | `NULL` | `NULL` |
| **Password Hash Format** | Bcrypt (`$2y$10$`, 60 chars) | Bcrypt (`$2y$10$`, 60 chars) |
| **Test Account Indicator** | **YES — Definite test artifact** | **UNLIKELY — Production operating account (Needs verification)** |

---

## 4. In-Depth Account Analysis

### 4.1 Account 1: `kilotest_auth_63e8ee04` (User ID 1)

#### Technical Evidence
1. **Naming & Identity Patterns:**
   - Username starts with `kilotest_auth_` followed by an 8-character hexadecimal random string (`63e8ee04`).
   - The full name is explicitly set to `"Kilo Auth Test"`.
   - The email address uses the `.invalid` top-level domain (reserved by RFC 2606 for deliberately non-routable, invalid addresses). This prevents any outbound email or password reset delivery.
2. **Timeline Correlation:**
   - Created at `2026-11-08 14:54:00`.
   - 33 seconds later (`14:54:33`), the audit log records a failed login attempt for `kilotest_auth_155481c9` (User ID 2), followed at `14:54:34` by an attempt for `kilotest_no_such_155481c9`.
   - This directly confirms that User ID 1 was injected into the database during an automated test harness run in an earlier development phase.
3. **Usage and Authentication Records:**
   - **`users.last_login_at`:** `NULL`.
   - **`login_attempts` rows for `kilotest_auth_63e8ee04`:** **0**.
   - **`audit_logs` entries naming `kilotest_auth_63e8ee04`:** **0**.
   - **Business Data References:** `0` job cards, `0` vehicles, `0` invoices, `0` payments, `0` customer rows, `0` expenses reference user ID 1.
4. **Security Risk Assessment:**
   - An active administrator account with an unknown password generated during test execution constitutes a dormant attack vector.
   - If an unauthorized party gains access to test run console outputs, logs, or scripts containing the password, they could authenticate with full system control (40 permissions).

---

### 4.2 Account 3: `acount` (User ID 3)

#### Technical Evidence
1. **Naming & Identity Patterns:**
   - Username is `acount` (missing a 'c', likely created informally during initial setup).
   - Full name is `adirgeh` (an authentic personal name).
   - Email is `admin@gmail.com` (masked as `adm***@gmail.com`).
2. **Creation Event:**
   - Created at `2026-11-08 14:57:34`.
   - Explicitly captured in Audit Log record #3:
     - `action_type`: `user_created`
     - `module_name`: `users`
     - `record_id`: `3`
     - `user_id`: `3`
     - `username`: `acount`
     - `user_role`: `administrator`
     - `action_result`: `success`
     - `new_value`: `{"username":"acount","role":"administrator"}`
3. **Usage and Authentication Records:**
   - **`users.last_login_at`:** `2026-11-09 09:58:21`.
   - **`login_attempts` records:**
     - 10 successful logins using username `acount` between `2026-11-08 15:03:46` and `2026-11-08 15:20:00`.
     - 7 successful logins using email `admin@gmail.com` between `2026-11-08 15:12:55` and `2026-11-09 09:58:21`.
     - 0 failed logins for `acount`.
   - **`audit_logs` entries:**
     - 17 `login_success` records.
     - 9 `remember_me_enabled` records.
     - 3 `remember_me_restored` records.
     - 1 `logout` record.
     - 1 `audit_chain_verified` record (performed via web interface).
4. **Safety Rule Adherence:**
   - Despite being the active operating account, **we do not assume ownership**. The garage owner must formally review and verify whether this account is their personal credential or an engineer's setup account.

---

## 5. Audit Log Evidence & Historical Context

All 40 audit records in `audit_logs` were verified using `AuditLogger::verifyChain()`:
- **Chain Status:** `ok = true` (Tamper-evident SHA-256 hash chain intact, 40 of 40 records verified).
- **Triggers Active:** Append-only triggers `trg_audit_no_update` and `trg_audit_no_delete` are active in the database.

### 5.1 Historical Test User References in Audit Log
The audit log retains evidence of ephemeral test accounts created and deleted during earlier development phases before audit triggers were locked:
- **Audit #1 (`14:54:33`):** `login_failed` for User ID `2` (`kilotest_auth_155481c9`). User 2 does not exist in `users`.
- **Audit #2 (`14:54:34`):** `login_failed` for non-existent user `kilotest_no_such_155481c9`.
- **Audit #13 (`15:19:19`):** `login_failed` for User ID `4` (`kilotest_rm_4df48059`). User 4 does not exist in `users`.
- **Audit #19 (`15:19:35`):** `login_failed` for User ID `5` (`kilotest_rm_cf41b88e`). User 5 does not exist in `users`.
- **Audit #25 (`15:19:59`):** `login_failed` for User ID `6` (`kilotest_rm_f727e832`). User 6 does not exist in `users`.
- **Audit #40 (`2026-11-09 13:56:05`):** `login_failed` for username `admin` from `127.0.0.1` (accidental test probe disclosed in Phase 1.6 report).

These audit rows prove that earlier test scripts injected test users directly into the live database. `kilotest_auth_63e8ee04` (ID 1) is the sole lingering remnant that was never cleaned up.

---

## 6. Safe Owner Recovery Mechanism Review

The codebase contains an emergency CLI recovery script at `database/recover_admin.php`. Its design was evaluated:

### 6.1 Recovery Script Architecture
1. **Execution Gate:** CLI invocation only (`PHP_SAPI === 'cli'`); returns 404 over HTTP.
2. **Explicit Confirmation:** Prompts user to manually type `RECOVER ADMIN`.
3. **Input Validation:** Enforces strict regex validation on username, email format, and name length.
4. **Credential Security:** Generates a cryptographically strong, 36-byte random one-time password and enforces `must_change_password = 1`.
5. **Auditing:** Emits an audited event `administrator_recovered` into the tamper-evident chain.

### 6.2 The Recovery Deadlock (Critical Finding)
Lines 52–54 of `database/recover_admin.php` state:
```php
if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ?', [(int) $roleId]) > 0) {
    throw new RuntimeException('An administrator account already exists; recovery did not create another.');
}
```
**Impact:**
Because `kilotest_auth_63e8ee04` (ID 1) and `acount` (ID 3) both exist with `role_id = 1`, the recovery script immediately terminates with an exception. If the owner loses access to `acount`, this script **cannot** be used to recover access unless an existing administrator is deactivated or updated first.

---

## 7. Administrator Security Review Summary

| Metric / Check | Value / Finding | Risk Level |
|:---|:---|:---:|
| Total Administrator Accounts | **2** | Info |
| Active Administrators | **2** | Medium |
| Inactive Administrators | **0** | Info |
| Accounts with Test/Invalid Domains | **1** (`kilotest_auth_63e8ee04` @ `example.invalid`) | **High** |
| Accounts with Zero Login History | **1** (`kilotest_auth_63e8ee04`) | **High** |
| Accounts with Unknown Password Origin | **2** (Both ID 1 and ID 3) | **Medium** |
| Accounts with Unaudited Creation | **1** (`kilotest_auth_63e8ee04`) | **Medium** |
| Stored Remember-Me Tokens | **0** (Both currently NULL) | Low |
| Password Resets Outstanding | **0** (`password_resets` table is empty) | Low |
| Safe Owner Recovery Availability | **BLOCKED** (Exits if ANY admin exists) | **High** |

---

## 8. Recommendations & Owner Approval Queue

In strict accordance with the read-only directive, no account has been modified. The following options are submitted to the garage owner for decision:

### Decision Item 1: Deactivation of Test Account `kilotest_auth_63e8ee04` (ID 1)
- **Recommendation:** **Deactivate (do NOT delete).** Setting `is_active = 0` revokes all login privileges immediately while preserving database referential integrity, historical attribution, and audit records.
- **Proposed Action (Upon Owner Approval):**
  Execute via the administrative UI or via an audited application transaction:
  ```sql
  -- REQUIRES OWNER APPROVAL
  UPDATE users SET is_active = 0 WHERE id = 1 AND username = 'kilotest_auth_63e8ee04';
  ```

### Decision Item 2: Ownership Confirmation of `acount` (ID 3)
- **Recommendation:** Owner must confirm whether username `acount` / full name `adirgeh` belongs to them.
- If **CONFIRMED**:
  1. The owner should log in and immediately rotate the password via `profile.php`.
  2. Optionally update username to a clean standard identifier (e.g., `owner` or `admin_adirgeh`) and set personal email address.
  3. Flag `must_change_password = 1` if password change needs to be forced on next login.
- If **NOT OWNER**:
  1. Halt production release.
  2. Owner will provide authorized administrator credentials to provision a clean owner account.

### Decision Item 3: Remediation of Recovery Script Deadlock
- Propose updating `database/recover_admin.php` in a future phase so it checks for `is_active = 1` rather than raw existence, or adding an owner verification token bypass.
