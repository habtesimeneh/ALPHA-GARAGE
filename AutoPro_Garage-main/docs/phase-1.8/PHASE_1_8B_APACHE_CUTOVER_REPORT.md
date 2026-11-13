# ALPHA GARAGE — PHASE 1.8B
# FINAL MAMP APACHE VIRTUALHOST CUTOVER & VERIFICATION REPORT

**Execution & Verification Date:** November 13, 2026  
**Environment:** Windows 10/11 | MAMP 5.x | Apache 2.4.33 (Win64) | PHP 8.3.1 | MySQL 5.7.24  
**Project Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`  
**Target DocumentRoot:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`  
**Status:** **CUTOVER COMPLETE, RESTARTED & VERIFIED (28/28 TESTS PASS)**  
**Auditor / Agent:** Antigravity  

---

## 1. Executive Summary

Phase 1.8B has successfully executed and verified the permanent DocumentRoot cutover of the local MAMP Apache web server. The effective web root has been moved from the generic, multi-tenant directory `C:\MAMP\htdocs` to the dedicated application public directory `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`.

Following the owner's termination of the legacy orphaned Apache process (PID 10432 from Nov 9) and a clean restart via MAMP, a fresh Apache parent process (**PID 6176**) and worker process (**PID 8600**) were spawned on **November 13, 2026 at 01:37:37 AM**.

An exhaustive read-only audit comprising **28 distinct HTTP/HTTPS test vectors** was conducted against the live running server. Every single test passed with recorded empirical evidence:
- **Root & Application Access:** `https://localhost/` and `https://localhost/login.php` load directly with **HTTP 200**.
- **Static Asset Delivery:** CSS (`variables.css`, `luxury-auth.css`), JavaScript (`luxury.js`), and SVG assets load with **HTTP 200**.
- **Directory Traversal & Architecture Isolation:** Non-public application directories (`/storage/`, `/docs/`, `/tests/`, `/app/`, `/database/`) are completely unreachable from the web root and return **HTTP 404 Not Found**.
- **Directory Indexing Protection:** Browsing directories without an index file (`/assets/`, `/assets/css/`, `/assets/js/`) is blocked by `Options -Indexes` returning **HTTP 403 Forbidden**.
- **Source Code & Credential Protection:** Sensitive files (`.env.example`, `.git/`, `.git/HEAD`, `database.php`, `encryption.key`, `database.sql`, `app.log`) are completely protected with **HTTP 403** or **HTTP 404**.
- **Route Interception:** Unauthenticated attempts to access internal application modules (`/dashboard.php`, `/customers/`, `/appointments/`, `/vehicles/`, `/job-cards/`, `/invoices/`, `/services/`) are cleanly intercepted with **HTTP 302 Redirect** pointing directly to `/login.php`.
- **Legacy Path Decommissioning:** Old nested URLs (`/AutoPro_Garage-main/...`) return **HTTP 404 Not Found**.
- **Database & Audit Integrity:** Live database `garage_management_system` remains untouched; all 53 audit records verified with cryptographic hash chain intact (`AuditLogger::verifyChain() => ok: true`).

---

## 2. Configuration Files & Checksums

### 2.1 Pre-Cutover Configuration Backups
Timestamped backups were captured prior to making any configuration modifications and stored in `C:\Users\Temesgen\alpha-garage-backups\apache_config_20261111_054518\`:

| Original File | Backup Path | File Size | SHA-256 Checksum |
|---|---|---|---|
| `C:\MAMP\conf\apache\httpd.conf` | `apache_config_20261111_054518\httpd.conf` | 23,573 bytes | `8EE74EF4CA6324B93C1682208384D6FAB5A071C4593E33BB7F0001D82E71D6C6` |
| `C:\MAMP\conf\apache\gms-ssl.conf` | `apache_config_20261111_054518\gms-ssl.conf` | 993 bytes | `4529BDE780D400E3061C2EC11C88EB765413357BA464F7912BFA1959AAC74966` |

### 2.2 Applied Modular VirtualHost: `C:\MAMP\conf\apache\alphagarage-vhost.conf`
A dedicated modular configuration was created to encapsulate all Alpha Garage VirtualHost declarations on port 80 (HTTP) and port 443 (HTTPS):

```apache
# =============================================================================
# ALPHA GARAGE MANAGEMENT SYSTEM — SECURE LOCAL VIRTUAL HOST CONFIGURATION
# =============================================================================
# Scope: Ports 80 (HTTP) and 443 (HTTPS) bound strictly to public/
# Server: Apache 2.4.33 (Win64) on MAMP

Listen 443

# -----------------------------------------------------------------------------
# 1. Port 80 (HTTP) VirtualHost
# -----------------------------------------------------------------------------
<VirtualHost *:80>
    ServerName localhost
    ServerAlias 127.0.0.1 alphagarage.local

    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"

    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    ErrorLog "C:/MAMP/logs/alphagarage_http_error.log"
    CustomLog "C:/MAMP/logs/alphagarage_http_access.log" combined
</VirtualHost>

# -----------------------------------------------------------------------------
# 2. Port 443 (HTTPS) VirtualHost
# -----------------------------------------------------------------------------
<VirtualHost *:443>
    ServerName localhost
    ServerAlias 127.0.0.1 alphagarage.local

    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"

    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    SSLEngine on
    SSLCertificateFile "C:/MAMP/conf/gms-ssl/localhost-cert.pem"
    SSLCertificateKeyFile "C:/MAMP/conf/gms-ssl/localhost-key.pem"

    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLHonorCipherOrder On
    SSLCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES

    ErrorLog "C:/MAMP/logs/alphagarage_ssl_error.log"
    CustomLog "C:/MAMP/logs/alphagarage_ssl_access.log" combined
</VirtualHost>
```

### 2.3 Main Configuration Updates: `C:\MAMP\conf\apache\httpd.conf`
- **Line 253:** `DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"`
- **Lines 278–293:** Directory stanza updated with `Options -Indexes +FollowSymLinks` and `DirectoryIndex index.php index.html`.
- **Line 640:** `Include "C:/MAMP/conf/apache/alphagarage-vhost.conf"`

---

## 3. Apache Process Lifecycle & Runtime Verification

### 3.1 Active Process Inspection
Verified using PowerShell `Get-Process httpd`:
```powershell
Get-Process httpd -ErrorAction SilentlyContinue | Select-Object Id, ProcessName, StartTime, Path | Format-Table -AutoSize
```
**Recorded Output:**
```
  Id ProcessName StartTime             Path                            
  -- ----------- ---------             ----                            
6176 httpd       11/13/2026 1:37:37 AM C:\MAMP\bin\apache\bin\httpd.exe
8600 httpd       11/13/2026 1:37:42 AM C:\MAMP\bin\apache\bin\httpd.exe
```

- **PID 6176:** Apache parent process, started November 13, 2026 at 01:37:37 AM.
- **PID 8600:** Apache worker child process, started November 13, 2026 at 01:37:42 AM.
- **PID File (`C:\MAMP\bin\apache\logs\httpd.pid`):** Contains `6176`.
- **Legacy Processes (PID 10432 / 13612):** Completely terminated.

### 3.2 Network Port Ownership
Verified using `netstat -ano`:
```
  TCP    0.0.0.0:80             0.0.0.0:0              LISTENING       6176
  TCP    0.0.0.0:443            0.0.0.0:0              LISTENING       6176
  TCP    [::]:80                [::]:0                 LISTENING       6176
  TCP    [::]:443               [::]:0                 LISTENING       6176
```
Both HTTP (port 80) and HTTPS (port 443) are cleanly owned by Apache PID 6176.

### 3.3 Active VirtualHost Compilation Dump
Verified using `httpd.exe -S`:
```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -d "C:/MAMP/bin/apache" -f "C:\MAMP\conf\apache\httpd.conf" -S
```
**Recorded Output:**
```
VirtualHost configuration:
*:80                   localhost (C:/MAMP/conf/apache/alphagarage-vhost.conf:12)
*:443                  localhost (C:/MAMP/conf/apache/alphagarage-vhost.conf:32)
ServerRoot: "C:/MAMP/bin/apache"
Main DocumentRoot: "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
Main ErrorLog: "C:/MAMP/logs/apache_error.log"
PidFile: "C:/MAMP/bin/apache/logs/httpd.pid"
```

---

## 4. Comprehensive Endpoint Security & Functionality Audit

All tests executed via `curl.exe` against the running server PID 6176 / 8600. Every single test recorded an empirical status code:

| # | Test Target | URL | Expected | Actual Code | Status | Verification Evidence / Details |
|---|---|---|---|---|---|---|
| 1 | HTTPS Public Root | `https://localhost/` | 200 | **HTTP 200** | **PASS** | Serves `public/index.php` directly (Alpha Garage Home) |
| 2 | HTTP Public Root | `http://localhost/` | 200 | **HTTP 200** | **PASS** | Serves `public/index.php` over HTTP |
| 3 | HTTPS Login Page | `https://localhost/login.php` | 200 | **HTTP 200** | **PASS** | Luxury login page rendered with CSRF token & security headers |
| 4 | HTTP Login Page | `http://localhost/login.php` | 200 | **HTTP 200** | **PASS** | Login page accessible over HTTP |
| 5 | CSS Variables Asset | `https://localhost/assets/css/variables.css` | 200 | **HTTP 200** | **PASS** | Design token styles loaded (4,994 bytes) |
| 6 | CSS Luxury Auth | `https://localhost/assets/css/luxury-auth.css` | 200 | **HTTP 200** | **PASS** | Authentication styles loaded (18,272 bytes) |
| 7 | JS Luxury Script | `https://localhost/assets/js/luxury.js` | 200 | **HTTP 200** | **PASS** | Luxury UI client script loaded (13,487 bytes) |
| 8 | SVG Logo Asset | `https://localhost/assets/images/logo.svg` | 200 | **HTTP 200** | **PASS** | SVG brand asset loaded (1,103 bytes) |
| 9 | Private `storage/` (HTTPS) | `https://localhost/storage/` | 404 | **HTTP 404** | **PASS** | Completely unreachable; not present inside `public/` |
| 10 | Private `storage/` (HTTP) | `http://localhost/storage/` | 404 | **HTTP 404** | **PASS** | Completely unreachable over HTTP |
| 11 | Private `docs/` (HTTPS) | `https://localhost/docs/` | 404 | **HTTP 404** | **PASS** | Completely unreachable; not present inside `public/` |
| 12 | Private `docs/` (HTTP) | `http://localhost/docs/` | 404 | **HTTP 404** | **PASS** | Completely unreachable over HTTP |
| 13 | Private `tests/` (HTTPS) | `https://localhost/tests/` | 404 | **HTTP 404** | **PASS** | Test scripts unreachable from web |
| 14 | Private `tests/` (HTTP) | `http://localhost/tests/` | 404 | **HTTP 404** | **PASS** | Test scripts unreachable over HTTP |
| 15 | Private `app/` (HTTPS) | `https://localhost/app/` | 404 | **HTTP 404** | **PASS** | Core PHP application code isolated from web |
| 16 | Private `app/` (HTTP) | `http://localhost/app/` | 404 | **HTTP 404** | **PASS** | Core PHP application code isolated over HTTP |
| 17 | Private `database/` (HTTPS) | `https://localhost/database/` | 404 | **HTTP 404** | **PASS** | Migration scripts and recovery CLI isolated |
| 18 | Private `database/` (HTTP) | `http://localhost/database/` | 404 | **HTTP 404** | **PASS** | Migration scripts isolated over HTTP |
| 19 | Git Folder Root | `https://localhost/.git/` | 403 | **HTTP 403** | **PASS** | Blocked by Phase 1.6 global `<LocationMatch>` rule |
| 20 | Git HEAD Metadata (HTTPS) | `https://localhost/.git/HEAD` | 403 | **HTTP 403** | **PASS** | Repository metadata access denied |
| 21 | Git HEAD Metadata (HTTP) | `http://localhost/.git/HEAD` | 403 | **HTTP 403** | **PASS** | Repository metadata access denied over HTTP |
| 22 | Sensitive Environment File | `https://localhost/.env.example` | 403 | **HTTP 403** | **PASS** | Denied by Phase 1.6 dotfile protection rules |
| 23 | Database SQL Dump | `https://localhost/database/database.sql` | 404 | **HTTP 404** | **PASS** | Schema dump cannot be downloaded via HTTP |
| 24 | Application Log File | `https://localhost/storage/logs/app.log` | 404 | **HTTP 404** | **PASS** | Runtime logs unreachable via HTTP |
| 25 | Encryption Key File | `https://localhost/storage/private/encryption.key` | 404 | **HTTP 404** | **PASS** | App encryption key unreachable via HTTP |
| 26 | Database Credentials File | `https://localhost/app/config/database.php` | 404 | **HTTP 404** | **PASS** | Database credentials unreachable via HTTP |
| 27 | Old Nested Directory | `https://localhost/AutoPro_Garage-main/` | 404 | **HTTP 404** | **PASS** | Decommissioned; old subdirectory path does not exist |
| 28 | Old Nested Login URL | `https://localhost/.../public/login.php` | 404 | **HTTP 404** | **PASS** | Decommissioned; obsolete path completely gone |

**Total Audit Score:** **28 / 28 Passed (0 Failed)**

---

## 5. Protected Route Interception & Directory Listing Verification

### 5.1 Unauthenticated Route Interception
Tested by sending unauthenticated GET requests to protected module entry points:

```powershell
$pages = @("/customers/", "/appointments/", "/vehicles/", "/job-cards/", "/invoices/", "/services/", "/dashboard.php")
```
**Recorded Output:**
```
/customers/          : 302 -> https://localhost/login.php
/appointments/       : 302 -> https://localhost/login.php
/vehicles/           : 302 -> https://localhost/login.php
/job-cards/          : 302 -> https://localhost/login.php
/invoices/           : 302 -> https://localhost/login.php
/services/           : 302 -> https://localhost/login.php
/dashboard.php       : 302 -> https://localhost/login.php
```
- Each module's `index.php` is properly parsed and executed by PHP 8.3.1.
- Authentication middleware successfully intercepts unauthenticated users and redirects them to the root login page (`https://localhost/login.php`).
- Security headers are properly transmitted:
  - `X-Frame-Options: DENY`
  - `X-Content-Type-Options: nosniff`
  - `Referrer-Policy: same-origin`
  - `Permissions-Policy: camera=(), microphone=(), geolocation=()`
  - `Content-Security-Policy: default-src 'self'; ...`
  - `Set-Cookie: GMSSESSID=...; path=/; secure; HttpOnly; SameSite=Lax`

### 5.2 Directory Listing (Indexing) Audit
Tested by requesting asset directories directly without index files:
- `https://localhost/assets/` → **HTTP 403 Forbidden**
- `https://localhost/assets/css/` → **HTTP 403 Forbidden**
- `https://localhost/assets/js/` → **HTTP 403 Forbidden**

`Options -Indexes` in `alphagarage-vhost.conf` prevents any directory traversal or directory listing.

---

## 6. Apache Log Review

### 6.1 `C:\MAMP\logs\alphagarage_ssl_access.log`
Dedicated log created and active. Records all 200, 302, 403, and 404 responses cleanly. Sample entries:
```
::1 - - [13/Nov/2026:01:54:50 -0800] "GET /assets/css/luxury-auth.css HTTP/1.1" 200 18272 "-" "curl/8.13.0"
::1 - - [13/Nov/2026:01:54:50 -0800] "GET /storage/ HTTP/1.1" 404 206 "-" "curl/8.13.0"
::1 - - [13/Nov/2026:01:54:51 -0800] "GET /.git/ HTTP/1.1" 403 214 "-" "curl/8.13.0"
::1 - - [13/Nov/2026:01:55:13 -0800] "GET /login.php HTTP/1.1" 200 6439 "-" "curl/8.13.0"
::1 - - [13/Nov/2026:01:56:43 -0800] "GET /customers/ HTTP/1.1" 302 - "-" "curl/8.13.0"
```

### 6.2 `C:\MAMP\logs\alphagarage_http_access.log`
Dedicated HTTP log active. Sample entries:
```
::1 - - [13/Nov/2026:01:54:49 -0800] "GET / HTTP/1.1" 200 34656 "-" "curl/8.13.0"
::1 - - [13/Nov/2026:01:54:49 -0800] "GET /login.php HTTP/1.1" 200 6439 "-" "curl/8.13.0"
::1 - - [13/Nov/2026:01:54:50 -0800] "GET /storage/ HTTP/1.1" 404 206 "-" "curl/8.13.0"
```

### 6.3 `C:\MAMP\logs\alphagarage_ssl_error.log`
Only expected `AH01630` access denial events from the probe tests against `/.git` and `/.env.example`:
```
[Fri Nov 13 01:54:51.063528 2026] [authz_core:error] [pid 8600:tid 1468] [client ::1:53913] AH01630: client denied by server configuration: C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public/.git
[Fri Nov 13 01:54:51.317914 2026] [authz_core:error] [pid 8600:tid 1468] [client ::1:53916] AH01630: client denied by server configuration: C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public/.env.example
```
**Zero server crashes, zero missing module warnings, zero unhandled PHP fatal errors.**

---

## 7. Database & Cryptographic Audit Verification

Verification conducted via PHP 8.3.1 PDO against live database `garage_management_system`:

```
Active DB: garage_management_system
Users in database:
  ID: 1 | Username: kilotest_auth_63e8ee04 | Role: administrator (ID: 1) | Active: 0
  ID: 4 | Username: barok_admin           | Role: administrator (ID: 1) | Active: 1
Total audit records: 53
Audit chain valid: YES
```

### Audit Record Verification:
- **Record #44:** `user_deactivated` (Test Admin ID 1 deactivated in Phase 1.7F).
- **Record #45:** `administrator_recovered` (Owner Admin ID 4 created in Phase 1.7F).
- **Records #46–53:** Authentication events from verification testing (unsuccessful password checks, valid session creation).
- **Cryptographic Chaining:** Verified through `AuditLogger::verifyChain()`. All 53 hashes (`prev_hash` to `row_hash`) are cryptographically linked and intact.

---

## 8. Remaining Risks & Production Readiness Assessment

| Risk Category | Current Status | Risk Level | Mitigation & Future Phase Action |
|---|---|---|---|
| **Local Self-Signed SSL** | Local certificate valid for localhost development | Low (Dev only) | For production online deployment, replace `localhost-cert.pem` with a valid CA certificate (e.g., Let's Encrypt / Certbot). |
| **HSTS Header** | Intentionally disabled on localhost | Low | Do not enable HSTS on localhost to prevent permanent browser caching of self-signed certs. Enable HSTS during production cloud deployment. |
| **HTTP to HTTPS Redirect** | HTTP on port 80 currently serves the application | Low (Dev only) | In production, configure an unconditional 301/308 redirect from HTTP to HTTPS. |
| **Pre-Cutover Backups** | Stored in `alpha-garage-backups` | None | Stored outside `htdocs` in user home directory; safe from web traversal. |

---

## 9. Rollback Instructions (Reference Only)

In the event a rollback to the pre-cutover state is ever needed:
```powershell
Stop-Process -Name httpd -Force
Copy-Item "C:\Users\Temesgen\alpha-garage-backups\apache_config_20261111_054518\httpd.conf" "C:\MAMP\conf\apache\httpd.conf" -Force
Copy-Item "C:\Users\Temesgen\alpha-garage-backups\apache_config_20261111_054518\gms-ssl.conf" "C:\MAMP\conf\apache\gms-ssl.conf" -Force
Remove-Item "C:\MAMP\conf\apache\alphagarage-vhost.conf" -Force
```
Restart MAMP servers to restore `C:\MAMP\htdocs` as DocumentRoot.
