# Phase 1.8A — Proposed Apache VirtualHost Configuration

**Phase:** 1.8A — MAMP Apache VirtualHost Security — Read-Only Inspection  
**Date:** 2026-11-11  
**Target DocumentRoot:** `C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public`  
**Status:** **PROPOSED CONFIGURATION SPECIFICATION ONLY — NOT APPLIED**

---

## 1. Overview

This document specifies the exact configuration files, VirtualHost blocks, and validation procedures to isolate the web root to `public/`.

Two implementation approaches are provided:
- **Approach 1 (Recommended — Dedicated Include File):** Consolidates HTTP and HTTPS VirtualHosts into `C:\MAMP\conf\apache\alphagarage-vhost.conf`. Keeps project-specific virtual host rules modular and leaves MAMP's stock `httpd.conf` clean.
- **Approach 2 (In-Place Modification):** Modifies the existing `httpd.conf` and `gms-ssl.conf` directly in place.

---

## 2. Approach 1: Dedicated VirtualHost File (Recommended)

### 2.1 Proposed File: `C:\MAMP\conf\apache\alphagarage-vhost.conf`
```apache
# =============================================================================
# ALPHA GARAGE MANAGEMENT SYSTEM — SECURE LOCAL VIRTUAL HOST CONFIGURATION
# =============================================================================
# Scope: Ports 80 (HTTP) and 443 (HTTPS) bound strictly to public/
# Server: Apache 2.4.33 (Win64) on MAMP

# -----------------------------------------------------------------------------
# 1. Port 80 (HTTP) VirtualHost
# -----------------------------------------------------------------------------
<VirtualHost *:80>
    ServerName localhost
    ServerAlias 127.0.0.1 alphagarage.local

    # Restrict web access exclusively to the public directory
    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"

    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        # Disable directory browsing; allow symlinks; enable .htaccess overrides
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    # Log files
    ErrorLog "C:/MAMP/logs/alphagarage_http_error.log"
    CustomLog "C:/MAMP/logs/alphagarage_http_access.log" combined
</VirtualHost>

# -----------------------------------------------------------------------------
# 2. Port 443 (HTTPS) VirtualHost
# -----------------------------------------------------------------------------
<VirtualHost *:443>
    ServerName localhost
    ServerAlias 127.0.0.1 alphagarage.local

    # Restrict web access exclusively to the public directory
    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"

    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    # SSL Engine Configuration (Using existing verified Phase 1.5 certificate)
    SSLEngine on
    SSLCertificateFile "C:/MAMP/conf/gms-ssl/localhost-cert.pem"
    SSLCertificateKeyFile "C:/MAMP/conf/gms-ssl/localhost-key.pem"

    # Cryptographic hardening: TLS 1.2+ only, strong ciphers
    SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
    SSLHonorCipherOrder On
    SSLCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES

    # Log files
    ErrorLog "C:/MAMP/logs/alphagarage_ssl_error.log"
    CustomLog "C:/MAMP/logs/alphagarage_ssl_access.log" combined
</VirtualHost>
```

### 2.2 Integration into `C:\MAMP\conf\apache\httpd.conf`
In `C:\MAMP\conf\apache\httpd.conf`:
1. Change line 253 to update the global fallback DocumentRoot:
   ```apache
   # MAMP DOCUMENT_ROOT !! Don't remove this line !!
   DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
   ```
2. Replace line 639 (`Include "C:/MAMP/conf/apache/gms-ssl.conf"`) with:
   ```apache
   Include "C:/MAMP/conf/apache/alphagarage-vhost.conf"
   ```

---

## 3. Approach 2: Direct In-Place Modification

If the owner prefers modifying MAMP's existing configuration files directly:

### 3.1 Modifications to `C:\MAMP\conf\apache\httpd.conf`
```diff
--- a/C:/MAMP/conf/apache/httpd.conf
+++ b/C:/MAMP/conf/apache/httpd.conf
@@ -252,13 +252,13 @@ ServerName www.yourserver.com
 # MAMP DOCUMENT_ROOT !! Don't remove this line !!
-DocumentRoot "C:\MAMP\htdocs"
+DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"

-<Directory "C:\MAMP\htdocs">
-    Options  Indexes Includes FollowSymLinks ExecCGI
+<Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
+    Options -Indexes +FollowSymLinks
     AllowOverride All
     Require all granted
+    DirectoryIndex index.php index.html
 </Directory>
```

### 3.2 Modifications to `C:\MAMP\conf\apache\gms-ssl.conf`
```diff
--- a/C:/MAMP/conf/apache/gms-ssl.conf
+++ b/C:/MAMP/conf/apache/gms-ssl.conf
@@ -7,8 +7,8 @@ Listen 443
 <VirtualHost *:443>
     ServerName localhost
-    DocumentRoot "C:\MAMP\htdocs"
+    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"

-    <Directory "C:\MAMP\htdocs">
-        Options Indexes Includes FollowSymLinks ExecCGI
+    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
+        Options -Indexes +FollowSymLinks
         AllowOverride All
         Require all granted
+        DirectoryIndex index.php index.html
     </Directory>
```

---

## 4. Pre-Activation Validation Commands

Before restarting Apache, run the following syntax and virtual host dump tests:

### Test 1: Syntax Validation
```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -f "C:\MAMP\conf\apache\httpd.conf" -t
```
*Expected Output:* `Syntax OK`

### Test 2: VirtualHost Dump
```powershell
& "C:\MAMP\bin\apache\bin\httpd.exe" -f "C:\MAMP\conf\apache\httpd.conf" -S
```
*Expected Output:*
```
VirtualHost configuration:
*:80                   localhost (C:/MAMP/conf/apache/alphagarage-vhost.conf:9)
*:443                  localhost (C:/MAMP/conf/apache/alphagarage-vhost.conf:29)
Main DocumentRoot: "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
```

---

## 5. Post-Activation Verification Test Plan

Once Apache is restarted with the new configuration, the following test requests verify complete isolation:

| Target URL | Expected HTTP Code | Security Invariant Verified |
|:---|:---:|:---|
| `http://localhost/` | `200 OK` (Home) | Public index serves at root HTTP. |
| `https://localhost/` | `200 OK` (Home) | Public index serves at root HTTPS. |
| `https://localhost/login.php` | `200 OK` | Direct login script accessible. |
| `https://localhost/app/` | `404 Not Found` | `app/` is outside DocumentRoot. |
| `https://localhost/database/` | `404 Not Found` | `database/` is outside DocumentRoot. |
| `https://localhost/docs/` | `404 Not Found` | Documentation reports cannot be browsed. |
| `https://localhost/storage/` | `404 Not Found` | Logs and sessions cannot be accessed. |
| `https://localhost/.git/HEAD` | `404 Not Found` | Repository metadata is completely inaccessible. |
| `https://localhost/assets/css/variables.css` | `200 OK` | Static CSS assets load properly. |
