# ALPHA GARAGE — PHASE 1.8B
# CONTROLLED MAMP APACHE VIRTUALHOST CUTOVER REPORT

**Execution Date:** November 11, 2026  
**Environment:** Windows 10/11 | MAMP 5.x | Apache 2.4.33 (Win64) | PHP 8.3.1 | MySQL 5.7.24  
**Project Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`  
**Target DocumentRoot:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`  
**Auditor / Agent:** Antigravity  

---

## 1. Executive Summary

Phase 1.8B implements the controlled cutover of the MAMP Apache web root from the generic multi-tenant folder `C:\MAMP\htdocs` to the dedicated application public directory `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`.

### Key Outcomes:
1. **Configuration On Disk: 100% READY & VERIFIED**  
   - Dedicated modular VirtualHost file created: `C:\MAMP\conf\apache\alphagarage-vhost.conf`.  
   - `httpd.conf` and `gms-ssl.conf` updated with strict DocumentRoot and directory controls.  
   - Pre-cutover backups verified and stored in timestamped directory outside web root.  
   - Syntax validation via `httpd.exe -t` confirmed: **`Syntax OK`**.  
   - Compiled VirtualHost dump via `httpd.exe -S` confirmed: `*:80` and `*:443` bound strictly to `.../public`.
2. **Critical Diagnostic Finding: Running Process Still Using Old In-Memory Configuration**  
   - Windows process inspection (`Get-Process httpd`) revealed that the active Apache process (**PID 10432**) and its worker process (**PID 13612**) were started on **November 9, 2026 at 8:41:16 AM** and have **never been restarted**.  
   - PID 10432 is still serving from the old in-memory configuration (`C:\MAMP\htdocs`).  
   - The user's browser tests returned 404 for `/database/` and 403 for `/.git/` because those requests hit `C:\MAMP\htdocs\database\` (which does not exist) and the Phase 1.6 global git block.  
   - The application was accessed via the old nested URL `/AutoPro_Garage-main/AutoPro_Garage-main/public/login.php`, while `https://localhost/login.php` returned HTTP 404 because the old in-memory process looks for `C:/MAMP/htdocs/login.php`.
3. **Database & Audit Integrity: 100% INTACT**  
   - Live database `garage_management_system` was strictly read-only during this phase.  
   - Inactive admin ID 1 (`kilotest_auth_63e8ee04`, `is_active = 0`) preserved.  
   - Active owner admin ID 4 (`barok_admin`, `is_active = 1`) preserved.  
   - All 53 audit records verified with cryptographic hash chain intact (`AuditLogger::verifyChain() => ok: true`).

---

## 2. Configuration Files & Backups

### 2.1 Pre-Cutover Configuration Backups
Timestamped backups were captured before modifying any Apache configuration. Stored outside `htdocs` in `C:\Users\Temesgen\alpha-garage-backups\apache_config_20261111_054518\`:

| Original File | Backup Path | File Size | SHA-256 Checksum |
|---|---|---|---|
| `C:\MAMP\conf\apache\httpd.conf` | `apache_config_20261111_054518\httpd.conf` | 23,573 bytes | `8EE74EF4CA6324B93C1682208384D6FAB5A071C4593E33BB7F0001D82E71D6C6` |
| `C:\MAMP\conf\apache\gms-ssl.conf` | `apache_config_20261111_054518\gms-ssl.conf` | 993 bytes | `4529BDE780D400E3061C2EC11C88EB765413357BA464F7912BFA1959AAC74966` |

### 2.2 Applied Configuration Files

#### 1. Dedicated Modular VirtualHost: `C:\MAMP\conf\apache\alphagarage-vhost.conf`
Defined VirtualHosts for port 80 and port 443 pointing strictly to the `public/` directory:
```apache
Listen 443

# Port 80 (HTTP)
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

# Port 443 (HTTPS)
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

#### 2. Main Apache Configuration: `C:\MAMP\conf\apache\httpd.conf`
- **Line 253:** `DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"`
- **Lines 278–293:** `<Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">` with `Options -Indexes +FollowSymLinks` and `DirectoryIndex index.php index.html`.
- **Line 640:** `Include "C:/MAMP/conf/apache/alphagarage-vhost.conf"`

---

## 3. On-Disk Compilation & Syntax Verification

Executing Apache binary validation against the active configuration:
```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -d "C:/MAMP/bin/apache" -f "C:\MAMP\conf\apache\httpd.conf" -t
```
**Result:**
```
Syntax OK
```

Executing VirtualHost inspection:
```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -d "C:/MAMP/bin/apache" -f "C:\MAMP\conf\apache\httpd.conf" -S
```
**Result:**
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

## 4. Diagnostic Finding: Running Process vs In-Memory Configuration

### 4.1 Process Evidence
```powershell
Get-Process httpd | Select-Object Id, ProcessName, StartTime, Path
```
| Process ID | Process Name | StartTime | Executable Path |
|---|---|---|---|
| **10432** | `httpd` | `11/9/2026 8:41:16 AM` | `C:\MAMP\bin\apache\bin\httpd.exe` |
| **13612** | `httpd` | `11/9/2026 8:41:27 AM` | `C:\MAMP\bin\apache\bin\httpd.exe` |

- `C:\MAMP\bin\apache\logs\httpd.pid` contains PID `10432`.
- `netstat -ano` confirms PID 10432 holds port 80 and port 443.
- The Apache processes have been running uninterrupted since **Monday Nov 9, 2026**.

### 4.2 Apache Error Log Evidence (`C:\MAMP\logs\apache_error.log`)
During the post-cutover verification test at `07:08:30` and `07:19:11`, requests to `https://localhost/login.php` triggered the following log entry:
```
[Wed Nov 11 07:08:30.959151 2026] [php:error] [pid 13612:tid 1332] [client ::1:64449] script 'C:/MAMP/htdocs/login.php' not found or unable to stat
[Wed Nov 11 07:19:11.130371 2026] [php:error] [pid 13612:tid 1332] [client ::1:64588] script 'C:/MAMP/htdocs/login.php' not found or unable to stat
```
**Conclusion:** The running Apache worker process (PID 13612) is still looking for files inside `C:/MAMP/htdocs/` instead of `.../public/`.

### 4.3 Access Log Evidence (`C:\MAMP\logs\access.log`)
The user's manual browser verification recorded the following access requests:
```
[11/Nov/2026:06:06:34 -0800] "GET /AutoPro_Garage-main/AutoPro_Garage-main/public/login.php HTTP/1.1" 200
[11/Nov/2026:06:08:09 -0800] "POST /AutoPro_Garage-main/AutoPro_Garage-main/public/login.php HTTP/1.1" 302
[11/Nov/2026:06:08:09 -0800] "GET /AutoPro_Garage-main/AutoPro_Garage-main/public/dashboard.php HTTP/1.1" 200
[11/Nov/2026:06:12:20 -0800] "GET /database/ HTTP/1.1" 404
[11/Nov/2026:06:13:07 -0800] "GET /storage/ HTTP/1.1" 404
[11/Nov/2026:06:13:17 -0800] "GET /docs/ HTTP/1.1" 404
[11/Nov/2026:06:13:31 -0800] "GET /tests/ HTTP/1.1" 404
[11/Nov/2026:06:16:01 -0800] "GET /.git/ HTTP/1.1" 403
```
- The user logged in through the **old nested path** (`/AutoPro_Garage-main/.../login.php`).
- The requests to `/database/`, `/storage/`, `/docs/`, `/tests/` returned 404 because `C:\MAMP\htdocs\` does not contain those folders (they reside inside `AutoPro_Garage-main\AutoPro_Garage-main\`).
- The request to `/.git/` returned 403 because of the Phase 1.6 global `<LocationMatch>` rule.

---

## 5. Live Endpoint Audit Matrix

Tested against the active server (`PID 10432`) on November 11, 2026:

| # | Endpoint / URL | Expected Post-Restart Result | Current Live Result (PID 10432) | Status Explanation |
|---|---|---|---|---|
| 1 | `https://localhost/` | HTTP 200 (Alpha Garage Home) | **HTTP 200 (MAMP PRO Welcome)** | Serves `C:/MAMP/htdocs/index.php` (old config) |
| 2 | `http://localhost/` | HTTP 200 (Alpha Garage Home) | **HTTP 200 (MAMP PRO Welcome)** | Serves `C:/MAMP/htdocs/index.php` (old config) |
| 3 | `https://localhost/login.php` | HTTP 200 (Login Page) | **HTTP 404 Not Found** | Looking for `C:/MAMP/htdocs/login.php` |
| 4 | `http://localhost/login.php` | HTTP 200 (Login Page) | **HTTP 404 Not Found** | Looking for `C:/MAMP/htdocs/login.php` |
| 5 | `https://localhost/assets/css/variables.css` | HTTP 200 (CSS File) | **HTTP 404 Not Found** | Looking for `C:/MAMP/htdocs/assets/...` |
| 6 | `https://localhost/assets/js/luxury.js` | HTTP 200 (JS File) | **HTTP 404 Not Found** | Looking for `C:/MAMP/htdocs/assets/...` |
| 7 | `https://localhost/assets/images/logo.svg` | HTTP 200 (SVG File) | **HTTP 404 Not Found** | Looking for `C:/MAMP/htdocs/assets/...` |
| 8 | `https://localhost/database/` | HTTP 404 Not Found | **HTTP 404 Not Found** | Folder not in `htdocs`; will remain 404 in `public/` |
| 9 | `http://localhost/database/` | HTTP 404 Not Found | **HTTP 404 Not Found** | Folder not in `htdocs`; will remain 404 in `public/` |
| 10 | `https://localhost/.git/` | HTTP 403 or 404 | **HTTP 403 Forbidden** | Blocked by Phase 1.6 `<LocationMatch>` |
| 11 | `http://localhost/.git/` | HTTP 403 or 404 | **HTTP 403 Forbidden** | Blocked by Phase 1.6 `<LocationMatch>` |
| 12 | `https://localhost/storage/` | HTTP 404 Not Found | **HTTP 404 Not Found** | Folder not in `htdocs`; will remain 404 in `public/` |
| 13 | `https://localhost/docs/` | HTTP 404 Not Found | **HTTP 404 Not Found** | Folder not in `htdocs`; will remain 404 in `public/` |
| 14 | `https://localhost/tests/` | HTTP 404 Not Found | **HTTP 404 Not Found** | Folder not in `htdocs`; will remain 404 in `public/` |
| 15 | `https://localhost/app/` | HTTP 404 Not Found | **HTTP 404 Not Found** | Folder not in `htdocs`; will remain 404 in `public/` |
| 16 | `https://localhost/AutoPro_Garage-main/.../login.php` | HTTP 404 Not Found | **HTTP 200 OK** | Still accessible under old path until restart |
| 17 | `https://localhost/AutoPro_Garage-main/` | HTTP 404 Not Found | **HTTP 200 OK** | Still accessible under old path until restart |

---

## 6. Live Database & Cryptographic Audit Verification

Verification conducted via PHP 8.3.1 PDO against database `garage_management_system`:

```
Active DB: garage_management_system
Users in database:
  ID: 1 | Username: kilotest_auth_63e8ee04 | Role: administrator (ID: 1) | Active: 0
  ID: 4 | Username: barok_admin | Role: administrator (ID: 1) | Active: 1
Total audit records: 53
Audit chain valid: YES
```

### Recent Audit Activity Breakdown:
- **Record #44:** `user_deactivated` (Test Admin ID 1 deactivated in Phase 1.7F).
- **Record #45:** `administrator_recovered` (Owner Admin ID 4 created in Phase 1.7F).
- **Records #46–50:** Verification authentication events.
- **Records #51–53:** Authentication events from browser testing:
  - #51: `login_failed` (baroksimeneh4@gmail.com) at 17:07:14 UTC.
  - #52: `login_failed` (baroksimeneh4@gmail.com) at 17:07:36 UTC.
  - #53: `login_success` (barok_admin) at 17:08:09 UTC.
- All 53 cryptographic hashes (`prev_hash` -> `row_hash`) are unbroken.

---

## 7. Resolution: Steps for the Owner to Restart Apache

Because this phase is strictly **READ-ONLY** and assistant execution of process termination / restarts is forbidden by safety rules, the owner must restart the Apache process using the following steps:

### Step 1: Terminate the Orphaned Apache Process
Open PowerShell as Administrator (or standard PowerShell) and run:
```powershell
Stop-Process -Name httpd -Force
```
*(Alternatively, open Windows Task Manager, find all `Apache HTTP Server` / `httpd.exe` processes under MAMP, right-click and select **End Task**).*

### Step 2: Restart Apache via MAMP
1. In the MAMP application window, if the servers are showing "Started", click **Stop Servers**.
2. Click **Start Servers**.

### Step 3: Verify the New Apache Process
Run this command in PowerShell to confirm a new PID and fresh start time:
```powershell
Get-Process httpd | Select-Object Id, ProcessName, StartTime
```
*(The StartTime should now reflect the current time).*

### Step 4: Verify the New URLs in Your Browser
Once Apache restarts with the new configuration, test:
1. **`https://localhost/login.php`** -> Should load the Alpha Garage luxury login page directly.
2. **`https://localhost/`** -> Should load Alpha Garage directly (no more MAMP PRO welcome page).
3. **`https://localhost/database/`** -> Should return **404 Not Found**.
4. **`https://localhost/.git/`** -> Should return **403 Forbidden** or **404 Not Found**.
5. **`https://localhost/storage/`** -> Should return **404 Not Found**.
6. **`https://localhost/AutoPro_Garage-main/`** -> Should return **404 Not Found** (old path no longer exists).

---

## 8. Rollback Procedure (If Ever Required)

To restore the pre-cutover configuration at any point:
1. Terminate `httpd.exe`:
   ```powershell
   Stop-Process -Name httpd -Force
   ```
2. Restore original `httpd.conf` and `gms-ssl.conf`:
   ```powershell
   Copy-Item "C:\Users\Temesgen\alpha-garage-backups\apache_config_20261111_054518\httpd.conf" "C:\MAMP\conf\apache\httpd.conf" -Force
   Copy-Item "C:\Users\Temesgen\alpha-garage-backups\apache_config_20261111_054518\gms-ssl.conf" "C:\MAMP\conf\apache\gms-ssl.conf" -Force
   Remove-Item "C:\MAMP\conf\apache\alphagarage-vhost.conf" -Force
   ```
3. Start servers in MAMP.
