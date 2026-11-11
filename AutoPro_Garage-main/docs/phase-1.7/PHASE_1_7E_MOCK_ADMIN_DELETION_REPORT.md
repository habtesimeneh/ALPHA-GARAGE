# Phase 1.7E — Mock Admin Account Permanent Removal Report

**Phase:** 1.7E — Mock Admin Account Permanent Removal  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System (MAMP Windows, PHP 8.3.1, MySQL 5.7.24, Apache 2.4.33)  
**Live Database:** `garage_management_system` (Port 3306)  
**Execution Status:** **SUCCESSFULLY DELETED & VERIFIED UNDER STRICT TRANSACTIONAL INTEGRITY.**

---

## 1. Executive Summary

In accordance with explicit owner authorization, **User ID 3 (`acount` / `adirgeh` / `admin@gmail.com`)** was permanently removed from the live database `garage_management_system`.

Before execution, an exhaustive multi-table dependency investigation and foreign-key analysis confirmed that User ID 3 had zero business records (0 customers, 0 expenses, 0 invoices, 0 job cards, 0 payments, 0 part requests). The pre-deletion database backup was generated using native binary output, verified for integrity (SHA-256), and successfully test-restored into an ephemeral staging database.

The deletion was executed inside an atomic database transaction using the application's established PDO and audit logger infrastructure. Exactly one row was affected. An immutable `user_deleted` audit log record (#43) was appended, updating the cryptographic SHA-256 audit chain. Full post-deletion verification confirmed that User ID 1 (`kilotest_auth_63e8ee04`) remains active and untouched, referential integrity across all foreign keys is intact, and the audit chain verifies 100% valid across all 43 records.

---

## 2. Pre-Delete Dependency Analysis

Before executing any data modification, every table in the live database schema was inspected for foreign keys and direct references to User ID 3:

### 2.1 Foreign Key Constraint Review
| Dependent Table | Foreign Key Column | Constraint Name | `ON DELETE` Rule | User ID 3 References |
|:---|:---|:---|:---:|:---:|
| `customers` | `created_by` | `fk_cust_user` | `SET NULL` | **0** |
| `expenses` | `recorded_by` | `fk_expense_user` | `SET NULL` | **0** |
| `inventory_transactions` | `created_by` | `fk_it_user` | `SET NULL` | **0** |
| `invoices` | `created_by` | `fk_inv_user` | `SET NULL` | **0** |
| `job_cards` | `created_by` | `fk_job_user` | `SET NULL` | **0** |
| `job_parts` | `issued_by` | `fk_jp_user` | `SET NULL` | **0** |
| `job_updates` | `user_id` | `fk_ju_user` | `SET NULL` | **0** |
| `mechanics` | `user_id` | `fk_mech_user` | `SET NULL` | **0** |
| `part_requests` | `handled_by` | `fk_pr_hand` | `SET NULL` | **0** |
| `part_requests` | `requested_by` | `fk_pr_req` | `SET NULL` | **0** |
| `password_resets` | `user_id` | `fk_pr_user` | `CASCADE` | **0** |
| `payments` | `recorded_by` | `fk_pay_user` | `SET NULL` | **0** |

*Result:* Zero business records or foreign key dependencies referenced User ID 3.

### 2.2 Audit Log Table Isolation & Preservation Review
- **Foreign Key Check:** `audit_logs` has an index on `user_id` (`KEY idx_al_user`), but **NO foreign key constraint** referencing `users.id`.
- **Trigger Check:** The append-only database triggers `trg_audit_no_update` and `trg_audit_no_delete` remain fully active.
- **Preservation Strategy:** Because there is no foreign key on `audit_logs`, deleting User ID 3 from `users` did **not** trigger an `ON DELETE CASCADE` or `ON DELETE SET NULL` on `audit_logs`. The 34 historical audit records referencing User ID 3 (login attempts, session updates, etc.) remain permanently intact with their historical actor values preserved, and the SHA-256 hash chain was never broken.

---

## 3. Pre-Flight Backup Verification

A fresh, full database backup was generated outside the web root prior to deletion:

| Property | Value |
|:---|:---|
| **Backup Path** | `C:\Users\Temesgen\alpha-garage-backups\garage_management_system_20261109_225000.sql` |
| **Timestamp** | `2026-11-09 22:50:00` |
| **Filesize** | `63,534 bytes` |
| **Format** | Pristine UTF-8 MySQL dump (`--routines --triggers --single-transaction --hex-blob --quick`) |
| **SHA-256 Checksum** | `65BB21DA5AD33A5CB8C4EF56ECE64E6ED0F44F6A9C5A277E64023BFC9453CEF9` |
| **Access Control** | Restricted Windows ACL (SYSTEM, Administrators, owner Temesgen only) |
| **Restorability Test** | Successfully imported and validated in ephemeral database `garage_management_system_test` |

---

## 4. Deletion Operation & Transaction Details

The deletion was performed using a dedicated transactional script adhering strictly to the application's Database and AuditLogger conventions:

```php
$txResult = Database::transaction(function (PDO $pdo) use ($targetUser) {
    // 1. Lock and re-verify target row
    $st = $pdo->prepare("SELECT id, username, full_name, email, role_id, is_active FROM users WHERE id = 3 FOR UPDATE");
    $st->execute();
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u || $u['username'] !== 'acount' || $u['email'] !== 'admin@gmail.com') {
        throw new RuntimeException("Target user re-verification failed inside transaction.");
    }
    
    // 2. Perform deletion requiring exactly 1 affected row
    $del = $pdo->prepare("DELETE FROM users WHERE id = 3 AND username = 'acount' AND email = 'admin@gmail.com'");
    $del->execute();
    $affected = $del->rowCount();
    if ($affected !== 1) {
        throw new RuntimeException("Expected exactly 1 affected row, got $affected. Rolling back.");
    }
    
    // 3. Log audit event
    $auditId = AuditLogger::log(
        'user_deleted',
        'users',
        3,
        [
            'id' => 3,
            'username' => 'acount',
            'full_name' => 'adirgeh',
            'email' => 'admin@gmail.com',
            'role_id' => (int) $targetUser['role_id'],
            'is_active' => (int) $targetUser['is_active']
        ],
        [
            'deleted' => true,
            'authorization' => 'phase_1_7e_owner_authorized',
            'channel' => 'cli'
        ],
        'success',
        [
            'user_id' => null,
            'username' => 'owner_authorized_cli',
            'role' => 'administrator'
        ]
    );
    
    return ['deleted_rows' => $affected, 'audit_id' => $auditId];
});
```

### Transaction Execution Result
- **Affected Rows in `users`:** `1`
- **New Audit Log ID:** `#43`
- **Transaction Status:** **COMMITTED**

---

## 5. Account State Comparison (Before vs. After)

### Before Deletion
| ID | Username | Full Name | Email | Role | Active | Last Login |
|:---:|:---|:---|:---|:---:|:---:|:---|
| 1 | `kilotest_auth_63e8ee04` | *Test Admin* | `kilotest_63e8ee04@example.invalid` | `administrator` | 1 | 2026-11-08 03:54:00 |
| 3 | `acount` | `adirgeh` | `admin@gmail.com` | `administrator` | 1 | 2026-11-10 08:40:18 |

### After Deletion
| ID | Username | Full Name | Email | Role | Active | Last Login | Status |
|:---:|:---|:---|:---|:---:|:---:|:---|:---:|
| 1 | `kilotest_auth_63e8ee04` | *Test Admin* | `kilotest_63e8ee04@example.invalid` | `administrator` | 1 | 2026-11-08 03:54:00 | **Unchanged (Active)** |
| 3 | — | — | — | — | — | — | **PERMANENTLY DELETED** |

- **User ID 3:** Confirmed completely deleted (`SELECT COUNT(*) FROM users WHERE id = 3` returns `0`).
- **User ID 1:** Completely untouched, remains active (`is_active = 1`).
- **Total Users in Database:** Exactly `1`.

---

## 6. Audit Chain & Referential Integrity Verification

### 6.1 Audit Chain Verification
Executed via `AuditLogger::verifyChain()` on the live database post-deletion:
```json
{
  "ok": true,
  "checked": 43,
  "bad_id": null,
  "message": "All 43 audit records are intact."
}
```
- **Total Audit Logs:** `43` (42 historical records + 1 new deletion audit record).
- **Latest Audit Log (#43):**
  - **Action:** `user_deleted`
  - **Module:** `users`
  - **Record ID:** `3`
  - **Actor:** `owner_authorized_cli` (`administrator`)
  - **Previous Value:** `{"id":3,"username":"acount","full_name":"adirgeh","email":"admin@gmail.com","role_id":1,"is_active":1}`
  - **New Value:** `{"deleted":true,"authorization":"phase_1_7e_owner_authorized","channel":"cli"}`
  - **Prev Hash:** `73494223397d69ee0e17ca839ecd9e827d90ee7689ecde7d0298d26ea7da419e`
  - **Row Hash:** `4f27db67f7b2506135d72ab6228ae439cf76d132cc260a836376399eb55bd183`
- **Historical Records for User 3:** All 34 historical records preserved without modification.

### 6.2 Referential & Schema Integrity
- **Roles Count:** `6` (Unchanged)
- **Permissions Count:** `40` (Unchanged)
- **Role Permissions Count:** `85` (Unchanged)
- **Foreign Key Invariants:** All 12 foreign key tables verified with 0 broken or orphaned references.
- **Unit Test Verification:** `auth_unit.php`, `inventory_unit.php`, and `security_hardening_unit.php` all passed with exit code 0.

---

## 7. Remaining Administrator Access Risks & Safe Recovery Plan

> [!WARNING]
> **Critical Operational Notice:**
> Deleting User ID 3 removes the mock account, but **does not grant owner access to the system**.
> The only remaining account in the database is User ID 1 (`kilotest_auth_63e8ee04`), which is a test account whose plain-text password is unknown to the owner.

### Why Recovery Script Cannot Run Immediately:
In Phase 1.7D, `database/recover_admin.php` was hardened with:
```sql
SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1
```
Because User ID 1 is currently active (`is_active = 1`), `recover_admin.php` will correctly detect an existing active administrator and refuse to execute to prevent unauthorized or accidental duplicate admin creation.

### Recommended Safe Owner Recovery Procedure:
To establish verified, owner-controlled administrator access:

1. **Step 1 (Deactivate Test Admin):**  
   The project owner explicitly authorizes deactivating User ID 1:
   ```sql
   UPDATE users SET is_active = 0 WHERE id = 1 AND username = 'kilotest_auth_63e8ee04';
   ```
   *(This leaves 0 active administrators in the database, safely unblocking CLI recovery without deleting historical audit or user records).*

2. **Step 2 (Execute CLI Recovery):**  
   The operator runs the recovery script from the local console:
   ```powershell
   & "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" AutoPro_Garage-main\database\recover_admin.php
   ```

3. **Step 3 (Operator Confirmation & Account Creation):**  
   - Type `RECOVER ADMIN` when prompted.
   - Enter the owner's real username, full name, email address, and strong password.
   - The script creates the owner's administrator account with `is_active = 1` and `must_change_password = 1`, and logs the creation to the SHA-256 audit chain.

4. **Step 4 (First Sign-in):**  
   The owner signs in via the browser at `https://localhost/login.php` and updates their initial password.
