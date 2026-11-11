# Phase 1.7F — Owner Admin Account Creation & Post-Recovery Verification Report

**Phase:** 1.7F — Retire Test Admin and Create Owner Admin  
**Date:** 2026-11-11  
**Application:** Alpha Garage Management System (MAMP Windows, PHP 8.3.1, MySQL 5.7.24, Apache 2.4.33)  
**Live Database:** `garage_management_system` (Port 3306)  
**Execution Status:** **VERIFIED & OPERATIONAL (ALL INVARIANTS SATISFIED)**

---

## 1. Executive Summary

Phase 1.7F executed the controlled retirement of the automated test administrator (`kilotest_auth_63e8ee04`) and enabled the creation of the verified Owner Administrator account through the hardened CLI recovery pipeline.

Following preflight verification against an isolated test database and generation of a pre-transition database backup, User ID 1 was deactivated (`is_active = 0`), safely unblocking the recovery tool. The project owner executed the CLI recovery script locally in their terminal, creating account **User ID 4 (`barok_admin`)**.

Post-recovery verification conducted on the live database confirmed:
1. **User ID 1** remains present in the database with status `is_active = 0`, preserving all historical associations and audit links.
2. **User ID 4 (`barok_admin`)** exists exactly once as the sole active administrator (`is_active = 1`).
3. **Role & Permissions:** The account is mapped to Role ID 1 (`administrator`), granting 100% (40 out of 40) of all application permissions across all modules.
4. **Audit Chain Integrity:** Audit event #45 (`administrator_recovered`) was appended linearly. The cryptographic SHA-256 hash chain is intact across all 45 records.
5. **Security Gates:** The recovery tool is now automatically re-locked because an active administrator exists.

---

## 2. Pre-Transition Backup Reference

Before making any data changes, an authoritative full database backup was generated outside the public web root:

| Property | Value |
|:---|:---|
| **Backup Path** | `C:\Users\Temesgen\alpha-garage-backups\garage_management_system_20261111_015741.sql` |
| **Created Timestamp** | `2026-11-11 01:57:41` |
| **Filesize** | `63,725 bytes` |
| **Format** | Pristine UTF-8 MySQL dump (`--routines --triggers --single-transaction --hex-blob --quick`) |
| **SHA-256 Checksum** | `E72ADBF109A797657BE7721E5CDF9736CD381D371EE689FF23166CAAA43769A2` |
| **Access Control** | Windows ACL restricted (SYSTEM, Administrators, owner Temesgen only) |
| **Restorability Test** | Successfully imported into ephemeral database `garage_management_system_test` with 17/17 automated assertions passing |

---

## 3. Account Status & Identity Verification

Direct read-only inspection of the `users` table on `garage_management_system` confirms exactly two accounts:

### 3.1 New Owner Administrator Account (Active)
| Attribute | Verified Value |
|:---|:---|
| **User ID** | `4` |
| **Role** | `1` (`administrator`) |
| **Username** | `barok_admin` |
| **Full Name** | `Habtamu Simeneh` |
| **Email** | `baroksimeneh4@gmail.com` |
| **Phone** | `NULL` |
| **Active Status** | `1` (**Active**) |
| **Must Change Password** | `0` (Owner supplied custom permanent password during recovery) |
| **Created At** | `2026-11-11 02:33:51` |
| **Updated At** | `2026-11-11 02:33:51` |

### 3.2 Retired Test Administrator Account (Inactive)
| Attribute | Verified Value | Status |
|:---|:---|:---:|
| **User ID** | `1` | Preserved |
| **Role** | `1` (`administrator`) | Preserved |
| **Username** | `kilotest_auth_63e8ee04` | Preserved |
| **Full Name** | `Kilo Auth Test` | Preserved |
| **Email** | `kilotest_63e8ee04@example.invalid` | Preserved |
| **Active Status** | `0` (**Inactive**) | **DEACTIVATED** |
| **Created At** | `2026-11-08 03:54:00` | Preserved |
| **Updated At** | `2026-11-11 02:19:02` (Deactivation timestamp) | Recorded |

- **Account Immutability:** No other user rows were added, modified, or removed during this phase.

---

## 4. Role & Permission Verification

The administrator role assignment was verified against the application's RBAC matrix:

- **Role Assigned:** `administrator` (Role ID `1`)
- **System Permissions Count:** `40`
- **Assigned Permissions Count:** `40` (100% coverage)

### Verified Effective Permissions Matrix
| Module | Permission Code | Label |
|:---|:---|:---|
| **Administration** | `audit.view` | View and export audit logs |
| **Administration** | `roles.manage` | Manage role permissions |
| **Administration** | `settings.manage` | Manage garage settings |
| **Administration** | `users.manage` | Manage users |
| **Appointments** | `appointments.manage` | Create, update and convert appointments |
| **Appointments** | `appointments.view` | View service appointments |
| **Customers** | `customers.deactivate` | Deactivate / reactivate customers |
| **Customers** | `customers.manage` | Create and edit customers |
| **Customers** | `customers.view` | View customers |
| **Dashboard** | `dashboard.view` | View dashboard |
| **Expenses** | `expenses.manage` | Record operating expenses |
| **Expenses** | `expenses.view` | View recorded operating expenses |
| **Inventory** | `inventory.issue` | Issue parts to job cards |
| **Inventory** | `inventory.manage` | Create and edit spare parts |
| **Inventory** | `inventory.stock` | Record purchases and stock adjustments |
| **Inventory** | `inventory.view` | View spare parts |
| **Invoices** | `invoices.create` | Generate invoices |
| **Invoices** | `invoices.edit` | Edit invoice discount / sync from job |
| **Invoices** | `invoices.view` | View invoices |
| **Job cards** | `jobs.assign` | Assign mechanics |
| **Job cards** | `jobs.create` | Create job cards |
| **Job cards** | `jobs.edit` | Edit job card details and cancel jobs |
| **Job cards** | `jobs.progress` | Update status, notes and labor |
| **Job cards** | `jobs.request_parts` | Request spare parts |
| **Job cards** | `jobs.view_all` | View all job cards |
| **Job cards** | `jobs.view_assigned` | View only job cards assigned to me |
| **Mechanics** | `mechanics.manage` | Edit mechanic profiles |
| **Mechanics** | `mechanics.view` | View mechanics |
| **Payments** | `payments.record` | Record payments |
| **Payments** | `payments.refund` | Authorise refunds |
| **Payments** | `payments.view` | View payments |
| **Reports** | `reports.financial` | Financial reports |
| **Reports** | `reports.inventory` | Inventory reports |
| **Reports** | `reports.view` | Operational reports |
| **Services** | `services.manage` | Create and edit garage services |
| **Services** | `services.view` | View service catalog |
| **Suppliers** | `suppliers.manage` | Create and edit suppliers |
| **Suppliers** | `suppliers.view` | View suppliers |
| **Vehicles** | `vehicles.manage` | Create and edit vehicles |
| **Vehicles** | `vehicles.view` | View vehicles |

---

## 5. Audit Log & Cryptographic Chain Verification

### 5.1 Recovery Audit Event Payload (#45)
```
id:             45
user_id:        4
username:       barok_admin
user_role:      administrator
action_type:    administrator_recovered
module_name:    users
record_id:      4
previous_value: NULL
new_value:      {"username":"barok_admin","channel":"cli_recovery"}
action_result:  success
created_at:     2026-11-11 13:33:51
prev_hash:      16b59959086918b46015beafc23f8752d9e39f539d1c5f6ca0ced04b79b06f86
row_hash:       054db1f9daf1f93eeb9115c8b730a2a46007e48592945ce0f1e0fe5772490c74
```

### 5.2 Cryptographic Hash Chain Verification
Executed via `AuditLogger::verifyChain()` across the entire table:
```json
{
  "ok": true,
  "checked": 45,
  "bad_id": null,
  "message": "All 45 audit records are intact."
}
```
- **Total Audit Logs:** `45`
- **Chain Head (`audit_chain_head`):** `last_id = 45`, `last_hash = 054db1f9daf1f93eeb9115c8b730a2a46007e48592945ce0f1e0fe5772490c74`
- **Result:** **100% intact, zero tampering or chain breaks.**

---

## 6. Unit & Security Test Results

All non-destructive unit and security tests were re-executed against the application code:

| Test Suite | Command | Result |
|:---|:---|:---:|
| **Authentication Suite** | `php tests/auth_unit.php` | **PASS** (exit 0) |
| **Inventory Suite** | `php tests/inventory_unit.php` | **PASS** (exit 0) |
| **Security Hardening Suite** | `php tests/security_hardening_unit.php` | **PASS** (exit 0) |

---

## 7. Operational Status & Remaining Recommendations

### 7.1 Recovery Re-Locking Safeguard
Because User ID 4 is active (`is_active = 1`), any subsequent execution of `database/recover_admin.php` will fail with:
> `RuntimeException: An active administrator account already exists; recovery did not create another.`
This prevents duplicate administrator accounts or rogue recoveries while the system has an active owner admin.

### 7.2 Next Phase Recommendations
1. **First Browser Sign-In:** The owner should log in to the web interface at `https://localhost/login.php` with username `barok_admin` to verify interactive web session establishment.
2. **Local VirtualHost Cutover:** Apply the Apache VirtualHost configuration documented in `docs/phase-1.7/MAMP_VHOST_PLAN.md` to ensure `public/` is the DocumentRoot and private application folders (`app/`, `database/`, `storage/`, `.git/`) are inaccessible over HTTP.
