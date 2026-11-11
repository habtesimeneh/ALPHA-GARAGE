# Phase 1.7C — Local MAMP Apache VirtualHost Isolation Plan

**Phase:** 1.7C — Admin Recovery Hardening & Local Web Root Isolation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System  
**Web Server:** Apache 2.4.33 (MAMP for Windows x64)  
**Target DocumentRoot:** `C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public`  
**Execution Status:** **PLANNING ONLY — NOT APPLIED AUTOMATICALLY.** Awaiting explicit owner approval.

---

## 1. Executive Summary

In current local MAMP development, Apache's DocumentRoot is globally configured as `C:\MAMP\htdocs`. This exposes the parent project folder `AutoPro_Garage-main` to directory browsing (`http://localhost/AutoPro_Garage-main/` returns 200 OK with directory index), relying solely on reactive `.htaccess` files and regex patterns to deny internal directories.

This specification details the safe virtual host isolation architecture that eliminates all web exposure of private files by making `public/` the **sole DocumentRoot** for both HTTP (Port 80) and local HTTPS (Port 443).

---

## 2. Architecture Comparison: Current vs. Proposed

```
CURRENT (FRAGILE):
C:\MAMP\htdocs                       <-- DocumentRoot
├── AutoPro_Garage-main\             <-- Visible via Directory Index (200 OK)
│   ├── .git\                        <-- Blocked by regex
│   └── AutoPro_Garage-main\
│       ├── app\                     <-- Blocked by app/.htaccess
│       ├── database\                <-- Blocked by database/.htaccess
│       ├── storage\                 <-- Blocked by storage/.htaccess
│       └── public\                  <-- Real web assets

PROPOSED (SECURE BY ARCHITECTURE):
C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public  <-- DocumentRoot
├── assets\
├── index.php
└── login.php

OUTSIDE DOCUMENT ROOT (IMPOSSIBLE TO SERVE OVER HTTP/S):
├── app\                             <-- Resides above DocumentRoot (404 if probed)
├── database\                        <-- Resides above DocumentRoot (404 if probed)
├── docs\                            <-- Resides above DocumentRoot (404 if probed)
├── storage\                         <-- Resides above DocumentRoot (404 if probed)
├── tests\                           <-- Resides above DocumentRoot (404 if probed)
└── ..\.git\                         <-- Resides above DocumentRoot (404 if probed)
```

---

## 3. Configuration Specifications

Two integration options are provided. **Option A (Dedicated VirtualHost Include)** is the recommended path because it keeps custom project configuration cleanly separated from MAMP's stock `httpd.conf`.

### Option A: Clean Dedicated VirtualHost File (Recommended)

Create `C:\MAMP\conf\apache\alphagarage-vhost.conf`:

```apache
# =============================================================================
# ALPHA GARAGE MANAGEMENT SYSTEM — SECURE LOCAL VIRTUAL HOST
# =============================================================================

# Port 80 HTTP VirtualHost
<VirtualHost *:80>
    ServerName localhost
    ServerAlias alphagarage.local 127.0.0.1
    
    # Strictly serve ONLY the public directory
    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
    
    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        # Disable directory indexing; enable symlinks and .htaccess
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    # Dedicated local access and error logs
    ErrorLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_http_error.log"
    CustomLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_http_access.log" combined
</VirtualHost>

# Port 443 HTTPS VirtualHost
<VirtualHost *:443>
    ServerName localhost
    ServerAlias alphagarage.local 127.0.0.1
    
    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
    
    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    # SSL Engine Configuration (Preserves existing Phase 1.5 local certificate)
    SSLEngine on
    SSLCertificateFile "C:/MAMP/conf/gms-ssl/localhost-cert.pem"
    SSLCertificateKeyFile "C:/MAMP/conf/gms-ssl/localhost-key.pem"
    
    # Secure protocols and cipher suites (TLS 1.2+ for OpenSSL 1.0.2u)
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLHonorCipherOrder On
    SSLCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES

    ErrorLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_ssl_error.log"
    CustomLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_ssl_access.log" combined
</VirtualHost>
```

To activate Option A:
1. Replace the existing `Include "C:/MAMP/conf/apache/gms-ssl.conf"` at the end of `C:\MAMP\conf\apache\httpd.conf` with:
   ```apache
   Include "C:/MAMP/conf/apache/alphagarage-vhost.conf"
   ```
2. Disable the default global DocumentRoot in `httpd.conf` by setting:
   ```apache
   # MAMP DOCUMENT_ROOT !!
   # DocumentRoot "C:\MAMP\htdocs"
   ```

---

### Option B: Direct Modification of `httpd.conf` and `gms-ssl.conf`

If the owner prefers modifying MAMP's existing files in place:

#### 1. In `C:\MAMP\conf\apache\httpd.conf`:
Change line 253:
```apache
# Before:
DocumentRoot "C:\MAMP\htdocs"

# After:
DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
```
Change lines 278–303:
```apache
# Before:
<Directory "C:\MAMP\htdocs">
    Options  Indexes Includes FollowSymLinks ExecCGI
    AllowOverride All
    Require all granted
</Directory>

# After:
<Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php index.html
</Directory>
```

#### 2. In `C:\MAMP\conf\apache\gms-ssl.conf`:
Change line 8:
```apache
# Before:
DocumentRoot "C:\MAMP\htdocs"

# After:
DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
```
Change lines 10–14:
```apache
# Before:
<Directory "C:\MAMP\htdocs">
    Options Indexes Includes FollowSymLinks ExecCGI
    AllowOverride All
    Require all granted
</Directory>

# After:
<Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
    DirectoryIndex index.php index.html
</Directory>
```

---

## 4. Preservation of Runtimes, Database, and URLs

1. **PHP 8.3 Integration:**  
   The PHP module declaration in `httpd.conf` is completely untouched:
   ```apache
   PHPIniDir "C:\MAMP\conf\php8.3.1\php.ini"
   LoadModule php_module "C:\MAMP\bin\php\php8.3.1\php8apache2_4.dll"
   ```
   PHP continues to parse `.php` files seamlessly.
2. **MySQL Database Connection:**  
   The application database connection (`app/config/database.php`) communicates over TCP `127.0.0.1:3306` with credentials from `app/config/local.php`. Changing DocumentRoot has zero impact on PDO or database connectivity.
3. **Application URL Routing Compatibility:**  
   In `app/helpers/functions.php`, the `base_url()` helper is built to recognize when DocumentRoot matches `public/`:
   ```php
   function base_url(): string {
       ...
       if ($public && $doc && str_starts_with($public, $doc) && $public !== $doc) {
           $base = str_replace('\\', '/', substr($public, strlen($doc)));
       }
       return $base; // Returns empty string '' when $public === $doc!
   }
   ```
   When `public/` is DocumentRoot, `base_url()` automatically resolves to `''`, meaning links like `url('login.php')` seamlessly produce `/login.php` instead of long `/AutoPro_Garage-main/.../public/login.php` paths.

---

## 5. Step-by-Step Implementation Procedure

*DO NOT EXECUTE AUTOMATICALLY. Execute only after owner approval:*

### Step 1: Pre-Change Apache Configuration Backup
```powershell
$stamp = (Get-Date).ToString("yyyyMMdd_HHmmss")
Copy-Item "C:\MAMP\conf\apache\httpd.conf" "C:\MAMP\conf\apache\httpd.conf.bak.$stamp"
Copy-Item "C:\MAMP\conf\apache\gms-ssl.conf" "C:\MAMP\conf\apache\gms-ssl.conf.bak.$stamp"
Write-Output "Backed up httpd.conf and gms-ssl.conf with timestamp $stamp"
```

### Step 2: Apply the VirtualHost Configuration
Write `C:\MAMP\conf\apache\alphagarage-vhost.conf` or update `httpd.conf` / `gms-ssl.conf` as specified in Section 3.

### Step 3: Test Configuration Syntax
Run Apache's syntax checker before restarting:
```cmd
"C:\MAMP\bin\apache\bin\httpd.exe" -t
```
Must output: `Syntax OK`. If syntax errors appear, do not restart Apache.

### Step 4: Restart Apache
Restart the MAMP Apache service or restart via MAMP control panel.

### Step 5: Post-Cutover Verification Probes
Execute HTTP requests to verify new boundaries:
- `http://localhost/` -> Returns **200 OK** (Alpha Garage home page).
- `http://localhost/login.php` -> Returns **200 OK** (Sign-in page).
- `http://localhost/app/` -> Returns **404 Not Found** (Does not exist in DocumentRoot).
- `http://localhost/database/` -> Returns **404 Not Found**.
- `http://localhost/storage/` -> Returns **404 Not Found**.
- `http://localhost/.git/` -> Returns **404 Not Found**.
- `https://localhost/` -> Returns **200 OK** over HTTPS.

---

## 6. Rollback Procedure

If any issue occurs following the VirtualHost cutover:
1. Stop the Apache service.
2. Restore original configuration files from backups:
   ```powershell
   Copy-Item "C:\MAMP\conf\apache\httpd.conf.bak.<STAMP>" "C:\MAMP\conf\apache\httpd.conf" -Force
   Copy-Item "C:\MAMP\conf\apache\gms-ssl.conf.bak.<STAMP>" "C:\MAMP\conf\apache\gms-ssl.conf" -Force
   Remove-Item "C:\MAMP\conf\apache\alphagarage-vhost.conf" -ErrorAction SilentlyContinue
   ```
3. Test syntax: `"C:\MAMP\bin\apache\bin\httpd.exe" -t`
4. Start Apache. Verify `http://localhost/` returns MAMP default page.
