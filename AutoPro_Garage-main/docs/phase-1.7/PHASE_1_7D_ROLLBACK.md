# Phase 1.7D — Rollback & Recovery Procedures

**Phase:** 1.7D — Controlled Code Hardening & Test Storage Isolation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System (MAMP Windows, PHP 8.3.1, MySQL 5.7.24)  

---

## 1. Rollback Overview

Phase 1.7D modified application code only. **No live database records, schema, or audit events were altered.**

If the operator or project owner needs to revert the Phase 1.7D modifications, this document provides the exact commands and verification procedures to return the codebase to its exact pre-1.7D state.

---

## 2. Modified Files in Phase 1.7D

The following 7 files were modified in Phase 1.7D:

1. `AutoPro_Garage-main/database/recover_admin.php`
2. `AutoPro_Garage-main/app/helpers/functions.php`
3. `AutoPro_Garage-main/app/bootstrap.php`
4. `AutoPro_Garage-main/app/services/RateLimiter.php`
5. `AutoPro_Garage-main/app/services/MailService.php`
6. `AutoPro_Garage-main/app/services/CryptoService.php`
7. `AutoPro_Garage-main/public/setup.php`

---

## 3. Step-by-Step Code Rollback

### Step 3.1: Navigate to the Repository Root
Open PowerShell and navigate to the repository directory:
```powershell
cd C:\MAMP\htdocs\AutoPro_Garage-main
```

### Step 3.2: Revert Phase 1.7D Files Using Git
Run `git checkout` or `git restore` on the 7 modified files:

```powershell
git checkout HEAD -- `
    AutoPro_Garage-main/database/recover_admin.php `
    AutoPro_Garage-main/app/helpers/functions.php `
    AutoPro_Garage-main/app/bootstrap.php `
    AutoPro_Garage-main/app/services/RateLimiter.php `
    AutoPro_Garage-main/app/services/MailService.php `
    AutoPro_Garage-main/app/services/CryptoService.php `
    AutoPro_Garage-main/public/setup.php
```

Or using Git 2.23+ `restore`:
```powershell
git restore `
    AutoPro_Garage-main/database/recover_admin.php `
    AutoPro_Garage-main/app/helpers/functions.php `
    AutoPro_Garage-main/app/bootstrap.php `
    AutoPro_Garage-main/app/services/RateLimiter.php `
    AutoPro_Garage-main/app/services/MailService.php `
    AutoPro_Garage-main/app/services/CryptoService.php `
    AutoPro_Garage-main/public/setup.php
```

### Step 3.3: Verify Git Status
Verify that the 7 files are restored:
```powershell
git status
```
Confirm none of the 7 files appear as modified.

---

## 4. Live Database Backup Reference

Although Phase 1.7D executed zero live database queries, the authoritative pre-flight database backup remains secured outside the public web root:

| Property | Value |
|:---|:---|
| **Backup Path** | `C:\Users\Temesgen\alpha-garage-backups\garage_management_system_20261109_092150.sql` |
| **Created Timestamp** | `2026-11-09 09:21:50` |
| **Filesize** | `68,073 bytes` |
| **Access Control** | Windows ACL restricted (SYSTEM and Administrator access only) |
| **SHA-256** | Recorded in pre-flight log |

### Database Restoration Procedure (Emergency Only)
If an unauthorized or accidental database modification occurs:

1. Stop Apache in MAMP control panel.
2. In PowerShell, execute the restore using MAMP's MySQL binary:
```powershell
& "C:\MAMP\bin\mysql\bin\mysql.exe" -u root -proot garage_management_system < "C:\Users\Temesgen\alpha-garage-backups\garage_management_system_20261109_092150.sql"
```
3. Restart Apache.

---

## 5. Post-Rollback Verification

After performing the code rollback, execute the following validation checks to ensure application integrity:

### Check 5.1: PHP Syntax Linting
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\database\recover_admin.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\app\helpers\functions.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\app\bootstrap.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\app\services\RateLimiter.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\app\services\MailService.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\app\services\CryptoService.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -l AutoPro_Garage-main\public\setup.php
```
*Expected Result:* `No syntax errors detected` for all files.

### Check 5.2: Unit Test Suite
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" AutoPro_Garage-main\tests\auth_unit.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" AutoPro_Garage-main\tests\inventory_unit.php
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" AutoPro_Garage-main\tests\security_hardening_unit.php
```
*Expected Result:* All three unit suites report passed (exit code 0).

### Check 5.3: Database Integrity & Audit Verification
```powershell
& "C:\MAMP\bin\mysql\bin\mysql.exe" -u root -proot garage_management_system -e "SELECT id, username, is_active FROM users; SELECT COUNT(*) as total_audit, MAX(id) as max_id FROM audit_logs;"
```
*Expected Result:*
- Exactly 2 users: ID 1 (`kilotest_auth_63e8ee04`, `is_active = 1`) and ID 3 (`acount`, `is_active = 1`).
- Audit log row count: 42 rows.
