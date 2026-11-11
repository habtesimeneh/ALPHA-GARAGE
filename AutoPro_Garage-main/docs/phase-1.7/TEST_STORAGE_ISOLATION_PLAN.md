# Phase 1.7C — Test Storage & Runtime Isolation Plan

**Phase:** 1.7C — Admin Recovery Hardening & Local Web Root Isolation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System  
**Subsystems Evaluated:** `RateLimiter`, `MailService`, `app_log`, Session Handler, Integration Test Harness (`tests/run_tests.php`)  
**Execution Status:** **ARCHITECTURAL DESIGN ONLY.** No live code or storage directories modified.

---

## 1. Executive Summary

In current system behavior, both the live application and test execution harnesses share the identical physical storage directory located at `APP_ROOT . '/storage'`. 

When integration tests run:
1. Brute-force authentication tests generate rate-limit lockout records in `storage/rate_limits/`, which throttles the loopback IP (`127.0.0.1`) on the live application.
2. Password recovery tests write dummy email payloads into `storage/mail_spool/`, cluttering the live spool.
3. Test failures and trace messages write to `storage/logs/app.log` and `php-error.log`.
4. Tests and live users share the system's default PHP session store.

This plan details a centralized, zero-dependency storage isolation architecture that redirects all test-generated storage operations to an isolated, disposable test storage tree without disturbing live operations.

---

## 2. Current Architecture vs. Proposed Isolation Architecture

```
CURRENT SHARED COUPLING (HIGH RISK):
[Live Application]  ───► APP_ROOT/storage/rate_limits  ◄─── [Integration Tests]
[Live Application]  ───► APP_ROOT/storage/mail_spool    ◄─── [Integration Tests]
[Live Application]  ───► APP_ROOT/storage/logs          ◄─── [Integration Tests]
[Live Application]  ───► System Default Sessions        ◄─── [Integration Tests]

PROPOSED ISOLATED ARCHITECTURE (SAFE & INDEPENDENT):
[Live Application]  ───► storage_path() ───► APP_ROOT/storage/
                                                ├── rate_limits/
                                                ├── mail_spool/
                                                ├── logs/
                                                └── private/

[Integration Tests] ───► GMS_STORAGE_PATH ─► sys_get_temp_dir()/gms_test_storage/
                                                ├── rate_limits/ (Clean window)
                                                ├── mail_spool/  (Disposable)
                                                ├── logs/        (Isolated test log)
                                                └── sessions/    (No cookie collisions)
```

---

## 3. Storage Subsystem Inspection

### 3.1 RateLimiter (`app/services/RateLimiter.php`)
- **Current Code (Lines 12 & 22):**
  ```php
  private const DIR = '/storage/rate_limits';
  ...
  $dir = APP_ROOT . self::DIR;
  ```
- **Vulnerability:** Hardcoded path. Test rate-limit checks write SHA-256 hashed lock files directly into the live application directory.

### 3.2 MailService (`app/services/MailService.php`)
- **Current Code (Line 100):**
  ```php
  $spoolDir = APP_ROOT . '/storage/mail_spool';
  ```
- **Vulnerability:** Hardcoded path. When tests simulate password reset delivery, generated `.eml` files drop into the live spool directory.

### 3.3 Application & Error Logging
- **`app/helpers/functions.php` (Line 188):**
  ```php
  $dir = APP_ROOT . '/storage/logs';
  ```
- **`app/bootstrap.php` (Line 15):**
  ```php
  ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');
  ```
- **Vulnerability:** Test exceptions and assertions pollute live error logs.

### 3.4 PHP Session Storage
- **`app/bootstrap.php` (Lines 48–55):**
  ```php
  session_name($config['session']['name']); // Defaults to 'GMSSESSID'
  session_start();
  ```
- **Vulnerability:** Uses the default PHP session save directory. When tests run against `127.0.0.1:8080`, session cookie collisions or session file contamination can occur on localhost.

---

## 4. Proposed Storage Abstraction Architecture

To achieve clean separation with zero breaking changes, implement a central path resolver helper in `app/helpers/functions.php`.

### 4.1 Centralized `storage_path()` Helper
Add to `app/helpers/functions.php`:
```php
/**
 * Resolves storage paths, supporting environmental redirection for testing.
 * If GMS_STORAGE_PATH is set (e.g. during test runs), all storage writes redirect
 * to that directory. Otherwise, defaults to APP_ROOT/storage.
 */
function storage_path(string $subpath = ''): string
{
    static $root = null;
    if ($root === null) {
        $envPath = getenv('GMS_STORAGE_PATH');
        if ($envPath !== false && trim($envPath) !== '') {
            $root = rtrim(str_replace('\\', '/', trim($envPath)), '/');
        } else {
            $root = rtrim(str_replace('\\', '/', APP_ROOT), '/') . '/storage';
        }
    }
    return $subpath !== '' ? $root . '/' . ltrim($subpath, '/\\') : $root;
}
```

### 4.2 Updates to Subsystems

#### 1. RateLimiter (`app/services/RateLimiter.php`)
Replace line 22:
```php
// Before:
$dir = APP_ROOT . self::DIR;

// After:
$dir = storage_path('rate_limits');
```

#### 2. MailService (`app/services/MailService.php`)
Replace line 100:
```php
// Before:
$spoolDir = APP_ROOT . '/storage/mail_spool';

// After:
$spoolDir = storage_path('mail_spool');
```

#### 3. Application Logging (`app/helpers/functions.php`)
Replace line 188 in `app_log()`:
```php
// Before:
$dir = APP_ROOT . '/storage/logs';

// After:
$dir = storage_path('logs');
```

#### 4. Bootstrap Error Log & Session Isolation (`app/bootstrap.php`)
Update line 15:
```php
ini_set('error_log', storage_path('logs/php-error.log'));
```
And update session initialization:
```php
if (getenv('GMS_STORAGE_PATH')) {
    $sessDir = storage_path('sessions');
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0700, true);
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

## 5. Test Harness Execution Workflow

When integration tests are executed, a wrapper script (e.g. `scripts/run_isolated_tests.ps1`) manages the isolated storage lifecycle:

```powershell
# =============================================================================
# ALPHA GARAGE — ISOLATED TEST RUNNER PROTOCOL
# =============================================================================

# 1. Define dedicated ephemeral test storage in temp directory
$testStorageDir = [System.IO.Path]::Combine([System.IO.Path]::GetTempPath(), "gms_test_storage_" + (Get-Date -Format "yyyyMMdd_HHmmss"))
New-Item -ItemType Directory -Path "$testStorageDir\rate_limits" -Force | Out-Null
New-Item -ItemType Directory -Path "$testStorageDir\mail_spool" -Force | Out-Null
New-Item -ItemType Directory -Path "$testStorageDir\logs" -Force | Out-Null
New-Item -ItemType Directory -Path "$testStorageDir\sessions" -Force | Out-Null

Write-Host "Created ephemeral test storage at: $testStorageDir"

# 2. Export environment variables to isolate both database AND storage
$env:GMS_STORAGE_PATH = $testStorageDir
$env:GMS_DB_NAME = "garage_management_system_test"
$env:GMS_APP_URL = "http://127.0.0.1:19877"
$env:GMS_ALLOW_DESTRUCTIVE_TESTS = "1"
$env:BASE = "http://127.0.0.1:19877/"
$env:ADMIN_PASS = "TestAdminPass123!"
$env:DEMO_PASS = "TestDemoPass123!"

# 3. Launch ephemeral test web server
$serverProcess = Start-Process "C:\MAMP\bin\php\php8.3.1\php.exe" `
    -ArgumentList "-S 127.0.0.1:19877 -t C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public" `
    -PassThru -WindowStyle Hidden

try {
    Start-Sleep -Seconds 2
    
    # 4. Run tests
    & "C:\MAMP\bin\php\php8.3.1\php.exe" -c "C:\MAMP\conf\php8.3.1\php.ini" "C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\tests\run_tests.php"
} finally {
    # 5. Teardown: Stop server and delete test storage
    Stop-Process -Id $serverProcess.Id -Force -ErrorAction SilentlyContinue
    Remove-Item -Recurse -Force $testStorageDir -ErrorAction SilentlyContinue
    Write-Host "Teardown complete: ephemeral test server stopped and test storage purged."
}
```

---

## 6. Security & Stability Guarantees

1. **Live IP Throttling Prevented:**  
   Brute-force test runs populate only the ephemeral test storage. Live users on `127.0.0.1` are never throttled or locked out.
2. **Mail Spool Cleanliness:**  
   Simulated password recovery emails are created inside the temp spool and purged immediately on teardown.
3. **Log Separation:**  
   All test diagnostics remain in the temporary directory; live `app.log` retains only genuine application events.
4. **Zero Live Database Impact:**  
   Combining `GMS_STORAGE_PATH` with `GMS_DB_NAME=..._test` completely decouples the test runtime from production data and storage.
