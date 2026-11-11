# Phase 1.7A — Local Release Readiness & Web Root Exposure Report

**Phase:** 1.7A — Read-Only Admin Identity Investigation & Local Release Preparation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System  
**Web Server:** Apache 2.4.33 (MAMP, Windows x64)  
**Current Global DocumentRoot:** `C:\MAMP\htdocs`  
**Application Source Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`  
**Public Web Entry Path:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`  
**Status:** **READ-ONLY INSPECTION COMPLETED.** Proposed configurations documented below; **NOT applied automatically**.

---

## 1. Executive Summary

A thorough inspection of MAMP's Apache server configuration (`httpd.conf` and `gms-ssl.conf`) and live HTTP probes was performed to verify whether the application codebase and sensitive repository assets are exposed to web clients.

### Critical Assessment
1. **Local Exposure Status: PARTIALLY MITIGATED, ARCHITECTURALLY VULNERABLE.**
   - Sensitive directories (`app/`, `database/`, `storage/`, `tests/`) and dotfiles (`.git`) return `403 Forbidden` over HTTP and HTTPS due to defense-in-depth rules introduced in Phase 1.6.
   - **HOWEVER**, MAMP's primary `DocumentRoot` is configured globally as `C:\MAMP\htdocs`. As a result, the parent project folder `http://localhost/AutoPro_Garage-main/` produces an **open Apache directory index** (`200 OK`) disclosing the filesystem structure.
   - Relying on `.htaccess` deny rules and regex file matching inside a public document root is fragile. Any configuration error or disabled module could expose private source code, database dumps, and application secrets.
2. **Permanent Resolution Requirement:**
   - Configure a dedicated Apache VirtualHost that designates `.../AutoPro_Garage-main/public` as the **sole DocumentRoot**.
   - When `public/` is the document root, parent directories (`app/`, `database/`, `docs/`, `storage/`, and `.git`) reside physically outside the web root and are completely unaddressable by web clients.

---

## 2. Apache Configuration Inspection

### 2.1 Current MAMP Configuration (`C:\MAMP\conf\apache\httpd.conf`)
- **DocumentRoot (Line 253):** `DocumentRoot "C:\MAMP\htdocs"`
- **Global Directory (Line 278):**
  ```apache
  <Directory "C:\MAMP\htdocs">
      Options Indexes Includes FollowSymLinks ExecCGI
      AllowOverride All
      Require all granted
  </Directory>
  ```
  *Note:* `Options Indexes` allows directory listings when no index file is present.
- **Phase 1.6 Protection Block (Lines 305–319):**
  ```apache
  #BEGIN ALPHA GARAGE PHASE 1.6 REPOSITORY PROTECTION - REMOVE TO ROLL BACK
  <DirectoryMatch "(?i)(^|/)(\.git|\.svn|\.hg)(/|$)">
      Require all denied
  </DirectoryMatch>
  <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/.git">
      Require all denied
  </Directory>
  <FilesMatch "(?i)(^\.env|\.env$|\.sql$|\.log$|\.md$|\.example$|\.lock$|\.patch$|\.orig$|\.bak$|\.dump$|\.cnf$)">
      Require all denied
  </FilesMatch>
  #END ALPHA GARAGE PHASE 1.6 REPOSITORY PROTECTION
  ```

### 2.2 Current SSL Configuration (`C:\MAMP\conf\apache\gms-ssl.conf`)
- **DocumentRoot (Line 8):** `DocumentRoot "C:\MAMP\htdocs"`
- Mirrors the unisolated root of the HTTP server.

---

## 3. Web Root Access Verification (Live Probes)

Automated HTTP requests were executed against the running local Apache server on `http://localhost`. The results demonstrate current exposure boundaries:

| Probed URL Path | Expected Protection | Observed HTTP Status | Protection Mechanism | Status |
|:---|:---:|:---:|:---|:---:|
| `/AutoPro_Garage-main/.git/config` | Denied | **403 Forbidden** | `DirectoryMatch` in `httpd.conf` | **Pass** |
| `/AutoPro_Garage-main/.git/HEAD` | Denied | **403 Forbidden** | `DirectoryMatch` in `httpd.conf` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/.env.example` | Denied | **403 Forbidden** | `FilesMatch` in `httpd.conf` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/app/` | Denied | **403 Forbidden** | `app/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/app/bootstrap.php` | Denied | **403 Forbidden** | `app/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/app/config/local.php` | Denied | **403 Forbidden** | `app/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/database/` | Denied | **403 Forbidden** | `database/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/database/database.sql` | Denied | **403 Forbidden** | `database/.htaccess` + `FilesMatch` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/database/least_privilege.sql` | Denied | **403 Forbidden** | `database/.htaccess` + `FilesMatch` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/docs/` | Denied | **403 Forbidden** | `Options -Indexes` in project `.htaccess` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/docs/phase-1.6/REMAINING_RISKS.md` | Denied | **403 Forbidden** | `FilesMatch` (`.md$`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/documentation/` | Denied | **403 Forbidden** | `Options -Indexes` in project `.htaccess` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/storage/` | Denied | **403 Forbidden** | `storage/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/app.log` | Denied | **403 Forbidden** | `storage/.htaccess` + `FilesMatch` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/storage/private/` | Denied | **403 Forbidden** | `storage/private/.htaccess` | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/tests/` | Denied | **403 Forbidden** | `tests/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/tests/auth_unit.php` | Denied | **403 Forbidden** | `tests/.htaccess` (`Require all denied`) | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/index.php` | Redirect | **302 Found** (`Location: public/`) | Project root entrypoint | **Pass** |
| `/AutoPro_Garage-main/AutoPro_Garage-main/public/login.php` | Accessible | **200 OK** | Legitimate entrypoint | **Pass** |
| `/AutoPro_Garage-main/` | Denied / Hidden | **200 OK (Directory Index)** | **VULNERABLE — Exposes folder listing** | **FAIL** |

### Root Exposure Vulnerability Details
Navigating to `http://localhost/AutoPro_Garage-main/` returns:
```html
<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 3.2 Final//EN">
<html>
 <head><title>Index of /AutoPro_Garage-main</title></head>
 <body>
  <h1>Index of /AutoPro_Garage-main</h1>
  <ul>
   <li><a href="/"> Parent Directory</a></li>
   <li><a href="AutoPro_Garage-main/"> AutoPro_Garage-main/</a></li>
  </ul>
 </body>
</html>
```
Because the Git workspace resides at `C:\MAMP\htdocs\AutoPro_Garage-main`, directory indexing advertises the repository structure to anyone on the local network or loopback interface.

---

## 4. Proposed Safe Local Apache VirtualHost Configuration

To completely eliminate exposure risks without relying on reactive blocklists, a dedicated virtual host should be configured in Apache.

### 4.1 Architecture
- **Virtual Host Hostname:** `alphagarage.local` (or dedicated port, e.g., `8080` / `8443` or replacing `localhost`).
- **DocumentRoot:** `C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public`
- **Application Directory:** Resides one level above DocumentRoot.

```
C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\
├── app\          <-- OUTSIDE DOCUMENT ROOT (Inaccessible via HTTP)
├── database\     <-- OUTSIDE DOCUMENT ROOT (Inaccessible via HTTP)
├── docs\         <-- OUTSIDE DOCUMENT ROOT (Inaccessible via HTTP)
├── storage\      <-- OUTSIDE DOCUMENT ROOT (Inaccessible via HTTP)
├── tests\        <-- OUTSIDE DOCUMENT ROOT (Inaccessible via HTTP)
└── public\       <-- ONLY THIS DIRECTORY IS SERVED
    ├── assets\
    ├── index.php
    └── login.php
```

### 4.2 Proposed Configuration File (`C:\MAMP\conf\apache\extra\alphagarage-vhost.conf`)
*DO NOT APPLY AUTOMATICALLY. Proposed configuration for owner review:*

```apache
# =============================================================================
# ALPHA GARAGE MANAGEMENT SYSTEM — DEDICATED LOCAL VIRTUAL HOST
# =============================================================================

<VirtualHost *:80>
    ServerName alphagarage.local
    ServerAlias localhost
    
    # Strictly serve ONLY the public directory
    DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
    
    <Directory "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public">
        # Disable directory indexing; permit symlinks and .htaccess overrides
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>
    
    # Custom error and access logs outside web root
    ErrorLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_error.log"
    CustomLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_access.log" combined
</VirtualHost>

<VirtualHost *:443>
    ServerName alphagarage.local
    ServerAlias localhost
    
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
    
    ErrorLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_ssl_error.log"
    CustomLog "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/storage/logs/apache_ssl_access.log" combined
</VirtualHost>
```

---

## 5. Pre-Implementation Requirements & Backup Procedure

Before applying this Apache configuration, the following safety procedures must be executed:

### 5.1 Backup Requirements
1. **Back up existing Apache configuration files:**
   ```powershell
   Copy-Item "C:\MAMP\conf\apache\httpd.conf" "C:\MAMP\conf\apache\httpd.conf.bak.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
   Copy-Item "C:\MAMP\conf\apache\gms-ssl.conf" "C:\MAMP\conf\apache\gms-ssl.conf.bak.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
   ```
2. **Compute and record SHA-256 hashes:**
   Ensure baseline state can be validated byte-for-byte upon rollback.

### 5.2 Verification Before Applying
1. Test Apache configuration syntax before reloading:
   ```cmd
   "C:\MAMP\bin\apache\bin\httpd.exe" -t
   ```
2. Verify local DNS / hosts resolution:
   Add entry to `C:\Windows\System32\drivers\etc\hosts` if using `alphagarage.local`:
   `127.0.0.1  alphagarage.local`
3. Update `GMS_APP_URL` in `app/config/local.php` or system environment to reflect the virtual host URL (`http://alphagarage.local` or `https://localhost`).

---

## 6. Local Release Readiness Checklist

| Category | Item | Current State | Local Release Readiness |
|:---|:---|:---:|:---:|
| **Web Server** | Dedicated `public/` DocumentRoot | `C:\MAMP\htdocs` (Shared) | **Needs vhost cutover** |
| **Web Server** | Directory indexing disabled | Disabled via `.htaccess` in app; enabled in parent | **Mitigated in app, needs vhost** |
| **Web Server** | Dotfiles (`.git`) blocked | Blocked via `httpd.conf` | **Ready** |
| **Web Server** | SQL / Log / Env files blocked | Blocked via `httpd.conf` | **Ready** |
| **PHP Runtime** | PHP Version | 8.3.1 (MAMP) | **Ready** |
| **PHP Runtime** | `expose_php` | `Off` | **Ready** |
| **PHP Runtime** | Session lifetime / GC | `session.gc_maxlifetime = 28800` | **Ready** |
| **Database** | Live schema integrity | 29 tables, 40 permissions | **Ready** |
| **Database** | Audit chain integrity | 40/40 intact, append-only triggers active | **Ready** |
| **Security** | Rate Limiting on Login & Reset | Active (5 attempts per window) | **Ready** |
| **Security** | Admin Account Inventory | 2 accounts (1 test, 1 operating) | **Awaiting owner approval** |
| **Testing** | Test vs Live Storage Isolation | Shared `storage/` directory | **Requires separation** |
