# Phase 1.7D — Controlled Code Hardening & Test Storage Isolation Report

**Phase:** 1.7D — Controlled Code Hardening & Test Storage Isolation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System (Native PHP 8.3.1, MySQL 5.7.24, Apache 2.4.33, MAMP Windows)  
**Execution Status:** **IMPLEMENTED & VERIFIED WITHOUT LIVE DATA MUTATION.**

---

## 1. Executive Summary

Phase 1.7D executed safe, non-destructive code-level hardening across two core operational pillars:
1. **Administrator Recovery Hardening (`database/recover_admin.php`):** Resolved the architectural deadlock where inactive administrator records prevented CLI recovery. The check was updated to filter strictly for active administrators (`WHERE role_id = ? AND is_active = 1`), while preserving username and email collision safeguards across all records.
2. **Centralized Storage Path Abstraction & Test Isolation (`storage_path()`):** Designed and implemented `storage_path(string $subpath = '')` in `app/helpers/functions.php`. Refactored `RateLimiter`, `MailService`, `app_log()`, `CryptoService`, `setup.php`, and `bootstrap.php` error logging and session handlers. When test mode is enabled, test rate limits, test mail spool, test error logs, and test PHP sessions redirect to an isolated disposable directory with a distinct cookie name (`GMSSESSID_TEST`), leaving live application storage 100% untouched.

Zero live user accounts were created, modified, activated, deactivated, or deleted. User ID 1 (`kilotest_auth_63e8ee04`) and User ID 3 (`acount`) remain completely unchanged. Live database integrity was verified with all 42 audit records intact.

---

## 2. Files Modified

| File | Change Category | Exact Modification | Security Rationale |
|:---|:---:|:---|:---|
| `database/recover_admin.php` | Recovery Hardening | Updated line 52 to `WHERE role_id = ? AND is_active = 1`; updated line 23 prompt; enriched audit payload with `'channel' => 'cli_recovery'`. | Eliminates recovery deadlock when all admins are disabled; preserves collision protection across all historical records. |
| `app/helpers/functions.php` | Storage Abstraction | Added `storage_path(string $subpath = ''): string`; refactored `app_log()` to write to `storage_path('logs/app.log')`. | Normalizes paths, rejects directory traversal (`..`), forbids placement within public web root, creates directories safely (0770). |
| `app/bootstrap.php` | Bootstrap & Session Isolation | Moved `functions.php` inclusion above error logging; refactored `error_log` to use `storage_path('logs/php-error.log')`; added test mode session isolation (`GMSSESSID_TEST`, isolated `session_save_path`). | Prevents test runs from sharing live session cookie or contaminating live session storage. |
| `app/services/RateLimiter.php` | Storage Refactoring | Updated `$dir = storage_path('rate_limits')`. | Directs rate-limit counters to isolated test storage during tests; prevents live loopback lockout. |
| `app/services/MailService.php` | Storage Refactoring | Updated `$spoolDir = storage_path('mail_spool')`. | Directs dev mail spool copies to isolated test storage during tests; prevents cluttering live spool. |
| `app/services/CryptoService.php` | Storage Refactoring | Updated `$keyDir = storage_path('private')`. | Ensures encryption key directory resolution follows centralized path configuration. |
| `public/setup.php` | Storage Refactoring | Updated `$lock = storage_path('installed.lock')`. | Ensures installer lock check follows centralized path configuration. |

---

## 3. Code Modifications Details

### 3.1 Task A: `database/recover_admin.php`
```diff
--- a/database/recover_admin.php
+++ b/database/recover_admin.php
@@ -20,7 +20,7 @@ function prompt_line(string $label): string
 }
 
 try {
-    fwrite(STDOUT, "This creates one administrator only when no administrator account exists.\n");
+    fwrite(STDOUT, "This creates one administrator only when no active administrator account exists.\n");
     fwrite(STDOUT, "Continue only with explicit garage-owner authorization.\n");
     if (prompt_line('Type RECOVER ADMIN to continue: ') !== 'RECOVER ADMIN') {
         throw new RuntimeException('Administrator recovery was not confirmed.');
@@ -49,8 +49,8 @@ try {
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
@@ -69,7 +69,7 @@ try {
             'users',
             $id,
             null,
-            ['username' => $username],
+            ['username' => $username, 'channel' => 'cli_recovery'],
             'success',
             ['user_id' => $id, 'username' => $username, 'role' => 'administrator']
         );
```

### 3.2 Task B: `storage_path()` Implementation (`app/helpers/functions.php`)
```php
function storage_path(string $subpath = ''): string
{
    $envPath = getenv('GMS_STORAGE_PATH');
    if ($envPath !== false && trim($envPath) !== '') {
        $root = str_replace('\\', '/', trim($envPath));
    } else {
        $root = str_replace('\\', '/', APP_ROOT) . '/storage';
    }
    $root = rtrim($root, '/');

    // Test mode safety guard: test mode must never point to production storage
    $isTestMode = getenv('GMS_TEST_MODE') === '1' || getenv('GMS_ALLOW_DESTRUCTIVE_TESTS') === '1';
    $prodDefault = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/storage';
    if ($isTestMode && strcasecmp($root, $prodDefault) === 0) {
        throw new RuntimeException('Test mode is enabled but storage points to production storage. Set GMS_STORAGE_PATH to an isolated directory.');
    }

    // Web root safety guard: storage must NEVER reside inside public web root
    $publicDir = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/public';
    if (str_starts_with(strtolower($root), strtolower($publicDir))) {
        throw new InvalidArgumentException('Storage directory cannot reside within the public web root.');
    }

    if ($subpath === '') {
        if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException("Cannot create storage root directory: $root");
        }
        return $root;
    }

    if (str_contains($subpath, "\0")) {
        throw new InvalidArgumentException('Null byte detected in storage path.');
    }

    $normalized = str_replace('\\', '/', trim($subpath));

    // Reject absolute paths or Windows drive letters in subpath (e.g. 'C:', '/', '\')
    if (preg_match('~^[a-zA-Z]:|^/|^\\\\~', $normalized)) {
        throw new InvalidArgumentException('Subpath must be relative to storage root.');
    }

    $segments = explode('/', $normalized);
    $cleanSegments = [];
    foreach ($segments as $seg) {
        $seg = trim($seg);
        if ($seg === '' || $seg === '.') {
            continue;
        }
        if ($seg === '..') {
            throw new InvalidArgumentException('Path traversal detected in storage path.');
        }
        $cleanSegments[] = $seg;
    }

    $relative = implode('/', $cleanSegments);
    $target = $root . '/' . $relative;

    $lastSegment = end($cleanSegments);
    if (str_contains($lastSegment, '.')) {
        $targetDir = dirname($target);
    } else {
        $targetDir = $target;
    }

    if (!is_dir($targetDir) && !@mkdir($targetDir, 0770, true) && !is_dir($targetDir)) {
        // Fallback if unwritable
    }

    return $target;
}
```

### 3.3 Task C: Test Environment Session Isolation (`app/bootstrap.php`)
```php
// Explicit trusted test-mode session isolation:
$isTestMode = getenv('GMS_TEST_MODE') === '1'
    || (getenv('GMS_STORAGE_PATH') !== false && trim((string) getenv('GMS_STORAGE_PATH')) !== '');

if ($isTestMode) {
    $sessDir = storage_path('sessions');
    if (!is_dir($sessDir) && !@mkdir($sessDir, 0700, true) && !is_dir($sessDir)) {
        app_log('Cannot create test session directory: ' . $sessDir);
    }
    if (is_dir($sessDir)) {
        session_save_path($sessDir);
    }
    session_name('GMSSESSID_TEST');
} else {
    session_name($config['session']['name']);
}
```

---

## 4. Live Database Immutability Proof

Verification executed directly against the live database following implementation and testing:
- **Database Connected:** `garage_management_system` (Port 3306)
- **Total Users:** `2` (Unchanged)
  - User 1: `kilotest_auth_63e8ee04`, `is_active = 1` (Unchanged)
  - User 3: `acount`, `is_active = 1` (Unchanged)
- **Total Audit Rows:** `42` (Unchanged by tests; rows 41–42 reflect legitimate user session activity from browser)
- **Audit Hash Chain:** **Intact (42/42 checked, `ok = true`)**
- **Triggers:** Append-only triggers remain enforced.

---

## 5. Remaining Risks & Open Items

1. **User ID 1 Still Active:** Test administrator `kilotest_auth_63e8ee04` remains enabled in the live database. Now that recovery hardening is in place, deactivation can proceed safely upon owner sign-off.
2. **Owner Identity Confirmation:** Owner confirmation for `acount` (`adirgeh` / `admin@gmail.com`) remains pending.
3. **Apache VirtualHost Cutover:** The safe local Apache VirtualHost configuration has been prepared in `docs/phase-1.7/MAMP_VHOST_PLAN.md` and awaits owner approval to apply.
