# Phase 1.7C — Safe Administrator Recovery Implementation Plan

**Phase:** 1.7C — Admin Recovery Hardening & Local Web Root Isolation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System  
**Target Script:** `database/recover_admin.php`  
**Execution Status:** **PLANNING ONLY — NOT EXECUTED.** Changes documented below await owner approval.

---

## 1. Executive Summary

This plan specifies the architecture, code enhancements, and operator procedures for safe, out-of-band administrator recovery. 

When implemented, the hardened recovery tool will guarantee that the project owner can always provision an authorized administrator account whenever zero active administrators exist in the database, without risking account duplication, unauthorized web access, or audit chain corruption.

---

## 2. Recovery Process Architecture

The recovery tool adheres to eight mandatory security invariants:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       RECOVERY EXECUTION WORKFLOW                           │
├─────────────────────────────────────────────────────────────────────────────┤
│ 1. Operating System CLI Execution Only (PHP_SAPI === 'cli')                 │
│    └─► HTTP invocation returns 404 and terminates immediately               │
│                                                                             │
│ 2. Operator Confirmation Gate                                               │
│    └─► Prompts for exact string: 'RECOVER ADMIN'                            │
│                                                                             │
│ 3. Input Validation (CLI Stdin)                                             │
│    └─► Validates full name, username format, email domain syntax            │
│                                                                             │
│ 4. Transactional Lock & Active Administrator Check                          │
│    └─► SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE         │
│    └─► SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1       │
│    └─► Abort if active administrator count > 0                              │
│                                                                             │
│ 5. Collision Check (All Users)                                              │
│    └─► SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(email) = ?    │
│    └─► Abort if username or email matches ANY existing account              │
│                                                                             │
│ 6. Cryptographic Password Generation                                        │
│    └─► 36 random bytes (288-bit CSPRNG) -> Base64 URL-safe -> Bcrypt        │
│                                                                             │
│ 7. Atomic Insert & Audit Append                                             │
│    └─► INSERT user with is_active = 1, must_change_password = 1             │
│    └─► AuditLogger::log('administrator_recovered', 'users', $id, ...)       │
│                                                                             │
│ 8. Secure Output & Commit                                                   │
│    └─► Display credentials to STDOUT; zero plaintext in logs                │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Proposed Code Modifications (`database/recover_admin.php`)

### 3.1 Diff Analysis

```diff
--- a/database/recover_admin.php
+++ b/database/recover_admin.php
@@ -20,6 +20,11 @@
 }
 
 try {
+    fwrite(STDOUT, "============================================================\n");
+    fwrite(STDOUT, "ALPHA GARAGE MANAGEMENT SYSTEM — EMERGENCY ADMIN RECOVERY\n");
+    fwrite(STDOUT, "============================================================\n");
     fwrite(STDOUT, "This creates one administrator only when no administrator account exists.\n");
     fwrite(STDOUT, "Continue only with explicit garage-owner authorization.\n");
@@ -49,8 +54,8 @@
         if (!$roleId) {
             throw new RuntimeException('The administrator role is missing; restore the role schema before recovery.');
         }
-        if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ?', [(int) $roleId]) > 0) {
-            throw new RuntimeException('An administrator account already exists; recovery did not create another.');
+        if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [(int) $roleId]) > 0) {
+            throw new RuntimeException('An active administrator account already exists; recovery did not create another.');
         }
         if ((int) Database::value(
             'SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(email) = ?',
@@ -71,7 +76,7 @@
             null,
             ['username' => $username],
             'success',
-            ['user_id' => $id, 'username' => $username, 'role' => 'administrator']
+            ['user_id' => $id, 'username' => 'CLI Recovery (' . $username . ')', 'role' => 'administrator']
         );
         return $id;
     });
```

### 3.2 Key Enhancements Explained
1. **Active Administrator Isolation (`AND is_active = 1`):**  
   Resolves the deadlock identified in Phase 1.7A and 1.7B. If existing administrator accounts are disabled (`kilotest_auth_63e8ee04`), recovery can proceed to create a clean owner account.
2. **Explicit Collisions Protection:**  
   Retains `SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(email) = ?` across all accounts (active and inactive), ensuring no duplicate usernames or emails are inserted into the database.
3. **Audited Actor Distinction:**  
   Labels the actor in the audit entry as `'CLI Recovery (' . $username . ')'` so audit logs clearly identify that the user account was provisioned via emergency CLI recovery rather than an internal web form.

---

## 4. Complete Hardened Code Specification

When authorized by the project owner, `database/recover_admin.php` will be updated to:

```php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

define('APP_ROOT', dirname(__DIR__));
require APP_ROOT . '/app/bootstrap.php';

function prompt_line(string $label): string
{
    fwrite(STDOUT, $label);
    $value = fgets(STDIN);
    if ($value === false) {
        throw new RuntimeException('Unable to read administrator details from the console.');
    }
    return trim($value);
}

try {
    fwrite(STDOUT, "============================================================\n");
    fwrite(STDOUT, "ALPHA GARAGE MANAGEMENT SYSTEM — EMERGENCY ADMIN RECOVERY\n");
    fwrite(STDOUT, "============================================================\n");
    fwrite(STDOUT, "This creates one administrator only when no active administrator exists.\n");
    fwrite(STDOUT, "Continue only with explicit garage-owner authorization.\n\n");
    if (prompt_line('Type RECOVER ADMIN to continue: ') !== 'RECOVER ADMIN') {
        throw new RuntimeException('Administrator recovery was not confirmed.');
    }

    $fullName = prompt_line('Administrator full name: ');
    $username = prompt_line('Administrator username: ');
    $email = strtolower(prompt_line('Administrator email: '));
    $errors = validate(
        ['full_name' => $fullName, 'username' => $username, 'email' => $email],
        ['full_name' => 'required|max:120', 'username' => 'required|min:3|max:50', 'email' => 'required|email|max:150']
    );
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $username)) {
        $errors['username'] = 'Use letters, digits, dot, dash and underscore only.';
    }
    if ($errors) {
        throw new InvalidArgumentException(implode(' ', array_values($errors)));
    }

    do {
        $password = rtrim(strtr(base64_encode(random_bytes(36)), '+/', '-_'), '=');
    } while (AuthService::passwordProblems($password, $username));

    $userId = Database::transaction(function () use ($fullName, $username, $email, $password): int {
        $roleId = Database::value("SELECT id FROM roles WHERE name = 'administrator' FOR UPDATE");
        if (!$roleId) {
            throw new RuntimeException('The administrator role is missing; restore the role schema before recovery.');
        }
        if ((int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [(int) $roleId]) > 0) {
            throw new RuntimeException('An active administrator account already exists; recovery did not create another.');
        }
        if ((int) Database::value(
            'SELECT COUNT(*) FROM users WHERE username = ? OR LOWER(email) = ?',
            [$username, $email]
        ) > 0) {
            throw new RuntimeException('The username or email is already in use; recovery did not change existing accounts.');
        }

        $id = Database::insert(
            'INSERT INTO users (role_id, username, full_name, email, password_hash, is_active, must_change_password)
             VALUES (?, ?, ?, ?, ?, 1, 1)',
            [(int) $roleId, $username, $fullName, $email, AuthService::hash($password)]
        );
        AuditLogger::log(
            'administrator_recovered',
            'users',
            $id,
            null,
            ['username' => $username],
            'success',
            ['user_id' => $id, 'username' => 'CLI Recovery (' . $username . ')', 'role' => 'administrator']
        );
        return $id;
    });

    fwrite(STDOUT, "\n[SUCCESS] Administrator account created.\n");
    fwrite(STDOUT, "Username: $username\n");
    fwrite(STDOUT, "Email:    $email\n");
    fwrite(STDOUT, "One-time password: $password\n\n");
    fwrite(STDOUT, "INSTRUCTIONS:\n");
    fwrite(STDOUT, "1. Sign in immediately at /login.php\n");
    fwrite(STDOUT, "2. You will be prompted to set a new permanent password.\n");
    fwrite(STDOUT, "3. Clear your terminal scrollback/history to protect this one-time secret.\n");
} catch (Throwable $e) {
    app_log('CLI administrator recovery failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fwrite(STDERR, 'Administrator recovery failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
```

---

## 5. Owner Step-by-Step Recovery Instructions

When emergency recovery is needed (e.g. following the deactivation of all prior administrators):

### Step 1: Open PowerShell as Local Administrator
Navigate to the project repository:
```powershell
cd C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main
```

### Step 2: Confirm MAMP Database is Running
Verify that MySQL is listening on port 3306.

### Step 3: Execute the Recovery Command
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" database\recover_admin.php
```

### Step 4: Complete the Interactive Prompts
1. At the prompt `Type RECOVER ADMIN to continue: `, type `RECOVER ADMIN` and press Enter.
2. Enter your full name (e.g. `Garage Owner`).
3. Enter your chosen administrator username (e.g. `owner_admin`).
4. Enter your real email address (e.g. `owner@yourdomain.com`).

### Step 5: Sign In and Rotate Password
1. Copy the generated one-time password displayed in the terminal.
2. Open your browser and navigate to `http://localhost/login.php` (or `http://alphagarage.local/login.php`).
3. Sign in with the username and one-time password.
4. The system will detect `must_change_password = 1` and require setting your personal permanent password.
5. In PowerShell, clear your screen and history:
   ```powershell
   Clear-History
   cls
   ```

---

## 6. Verification and Rollback Strategy

### Verification Protocol
1. Verify database integrity after recovery:
   ```sql
   SELECT id, username, email, is_active, must_change_password FROM users WHERE role_id = 1;
   ```
2. Verify audit log integrity:
   ```powershell
   & "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" -r "
       require 'C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/app/bootstrap.php';
       print_r(AuditLogger::verifyChain());
   "
   ```
   Must output: `ok = 1`, `bad_id = null`.

### Rollback Protocol
If the recovery script fails during execution:
- The entire operation runs inside `Database::transaction()`. Any failure automatically rolls back the insert and audit record. No partial rows can remain.
- The pre-change database backup (`C:\Users\Temesgen\alpha-garage-backups\garage_management_system_20261109_092150.sql`) remains available for instant full restore if ever required.
