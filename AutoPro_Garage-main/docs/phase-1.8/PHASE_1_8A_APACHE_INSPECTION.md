# Phase 1.8A — MAMP Apache VirtualHost Security: Read-Only Inspection Report

**Phase:** 1.8A — MAMP Apache VirtualHost Security — Read-Only Inspection  
**Date:** 2026-11-11  
**Application:** Alpha Garage Management System  
**Environment:** Windows 10/11 x64, MAMP local development environment  
**Inspection Mode:** **100% READ-ONLY.** No files, code, configuration, or database records were modified. No processes were restarted.

---

## 1. Executive Summary

This read-only technical audit inspected the live Apache web server configuration on the local MAMP environment. 

### Key Findings
1. **DocumentRoot Overexposure:** Apache's current global DocumentRoot is set to `C:\MAMP\htdocs`. Because the project repository is nested at `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`, requests to `http://localhost/` and `https://localhost/` currently serve MAMP's stock welcome page (`C:\MAMP\htdocs\index.php`).
2. **Directory Browsing Exposure:** Navigating to `http://localhost/AutoPro_Garage-main/` exposes the top-level repository folder to directory listing. Non-public directories such as `docs/` have no `.htaccess` file and are completely exposed to HTTP requests.
3. **Reactive Protection vs. Architecture Protection:** Phase 1.6 added server-level regex filters (`<DirectoryMatch>` and `<FilesMatch>` in `httpd.conf`) to block `.git`, `.env`, `.sql`, and `.log` files. However, this is a reactive filter. The industry-standard architecture requires pointing `DocumentRoot` exclusively to `public/`, placing `app/`, `database/`, `docs/`, `storage/`, `tests/`, and `.git/` entirely outside the web-accessible tree.
4. **Zero Routing or Cookie Conflicts:** Inspection of `base_url()` in `app/helpers/functions.php` confirms that the application was engineered to dynamically adapt when `DocumentRoot` is set to `public/`. Session cookies and remember-me tokens use `path = '/'` and will remain 100% functional without code changes.

---

## 2. Server & Runtime Topology

| Property | Verified Value |
|:---|:---|
| **Project Root Path** | `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main` |
| **Target Public Web Root** | `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public` |
| **Apache Executable Path** | `C:\MAMP\bin\apache\bin\httpd.exe` |
| **Apache Version** | `Apache/2.4.33 (Win64)` |
| **Apache Build Date** | `Jul 6 2021 12:22:16` |
| **Server MPM** | `WinNT (threaded, 64-bit)` |
| **Main Configuration File** | `C:\MAMP\conf\apache\httpd.conf` |
| **Included SSL Config File** | `C:\MAMP\conf\apache\gms-ssl.conf` (Included on line 639 of `httpd.conf`) |
| **Active Apache PID** | `10432` |
| **Active MySQL PID** | `1384` (Port 3306) |

---

## 3. Network Listeners & VirtualHost Inspection

### 3.1 Network Port Listeners (`Get-NetTCPConnection`)
```
LocalAddress    LocalPort    OwningProcess    ProcessName    State
------------    ---------    -------------    -----------    ------
0.0.0.0         80           10432            httpd.exe      Listen
::              80           10432            httpd.exe      Listen
0.0.0.0         443          10432            httpd.exe      Listen
::              443          10432            httpd.exe      Listen
::              3306         1384             mysqld.exe     Listen
```

### 3.2 VirtualHost Dump (`httpd -S`)
```
VirtualHost configuration:
*:443                  localhost (C:/MAMP/conf/apache/gms-ssl.conf:6)
ServerRoot: "C:/MAMP/bin/apache"
Main DocumentRoot: "C:/MAMP/htdocs"
Main ErrorLog: "C:/MAMP/logs/apache_error.log"
Syntax OK
```

### 3.3 Analysis of Current Listener Configuration
- **Port 80 (HTTP):** Handled strictly by the global server configuration (`httpd.conf`). No `<VirtualHost *:80>` container currently exists. Global `DocumentRoot` is `"C:\MAMP\htdocs"`.
- **Port 443 (HTTPS):** Handled by a single `<VirtualHost *:443>` defined in `C:/MAMP/conf/apache/gms-ssl.conf`. Its `DocumentRoot` is currently set to `"C:\MAMP\htdocs"`.
- **Host Conflicts:** **None.** Only one VirtualHost is defined in Apache across all configuration files. No other web applications or hostnames are served.

---

## 4. SSL Module & Certificate Configuration

Inspection of `C:\MAMP\conf\apache\httpd.conf` and `C:\MAMP\conf\apache\gms-ssl.conf`:

### 4.1 Apache SSL Modules
- `LoadModule ssl_module modules/mod_ssl.so` (Line 152: Enabled)
- `LoadModule socache_shmcb_module modules/mod_socache_shmcb.so` (Line 201: Enabled)

### 4.2 Certificate Files & Validity
```
Certificate File:  C:\MAMP\conf\gms-ssl\localhost-cert.pem (1,468 bytes)
Private Key File:  C:\MAMP\conf\gms-ssl\localhost-key.pem  (1,704 bytes)
```
- **Subject:** `/C=ET/ST=Addis Ababa/L=Addis Ababa/O=Alpha Garage Local Development/OU=Phase 1.5 Local HTTPS/CN=localhost`
- **Issuer:** `/C=ET/ST=Addis Ababa/L=Addis Ababa/O=Alpha Garage Local Development/OU=Phase 1.5 Local HTTPS/CN=localhost`
- **Valid Period:** `Nov 9 2026 GMT` through `Feb 11 2029 GMT` (Active & Valid)
- **Protocol Policy:** `SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1` (TLS 1.2+ enforced; legacy SSL/TLS disabled)
- **Cipher Suite:** `SSLCipherSuite HIGH:!aNULL:!MD5:!RC4:!3DES` (Cryptographically strong)
- **Note:** Private key was inspected for existence and permissions only; private key material was not dumped or exposed.

---

## 5. Web Serving & Application Behavior

### 5.1 What Localhost Currently Serves
- `http://localhost/` ➔ Serves `C:\MAMP\htdocs\index.php` (MAMP default splash screen).
- `https://localhost/` ➔ Serves `C:\MAMP\htdocs\index.php` (MAMP default splash screen).
- To access Alpha Garage currently, the user must navigate to:
  `https://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/`

### 5.2 Application URL Helper Inspection (`app/helpers/functions.php`)
```php
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $public = realpath(APP_ROOT . '/public');
        $doc    = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
        $base   = '';
        if ($public && $doc && str_starts_with($public, $doc) && $public !== $doc) {
            $base = str_replace('\\', '/', substr($public, strlen($doc)));
        }
    }
    return $base;
}
```
- **When DocumentRoot is `C:\MAMP\htdocs`:**
  `$public !== $doc` evaluates to `true`. `$base` evaluates to `/AutoPro_Garage-main/AutoPro_Garage-main/public`.
- **When DocumentRoot is `.../public`:**
  `$public === $doc` evaluates to `true`. `$base` evaluates to `''` (empty string).
- **Result:** Navigation URLs (`url('login.php')`) dynamically output `/login.php` instead of `/AutoPro_Garage-main/AutoPro_Garage-main/public/login.php`. All internal links, stylesheets, and scripts will automatically adapt.

### 5.3 Cookie & Session Settings (`app/bootstrap.php` & `app/services/AuthService.php`)
- **Session Cookie:**
  ```php
  session_set_cookie_params([
      'lifetime' => 0, 
      'path' => '/', 
      'secure' => $https, 
      'httponly' => true, 
      'samesite' => 'Lax'
  ]);
  ```
- **Remember-Me Cookie:** Configured with `'path' => '/'`.
- **Result:** Because cookies were already configured with `path = '/'`, changing `DocumentRoot` will not invalidate session cookies or break active logins.

### 5.4 Rewrite Rules & Routing
- `public/.htaccess` contains:
  ```apache
  Options -Indexes
  DirectoryIndex index.php
  <FilesMatch "^\.">
    Require all denied
  </FilesMatch>
  ```
- **Result:** The application uses direct file routing (`login.php`, `index.php`, `appointments/form.php`). There are no URL rewriting directives (`RewriteRule`) or hardcoded rewrite bases that would conflict with DocumentRoot relocation.

---

## 6. Summary of Security Exposures in Current State

1. **Information Leakage via Directory Indexing:** `AutoPro_Garage-main` (the top-level folder) can be indexed by default if accessed directly.
2. **Exposed Documentation Directory:** `AutoPro_Garage-main/AutoPro_Garage-main/docs/` has no `.htaccess` file, exposing architecture, audit logs, and migration details.
3. **Reliance on Blacklist Filters:** If a new file extension (e.g., `.json`, `.csv`, `.yml`, `.sh`) is added to `storage/` or `app/`, it could be served if the server-level regex does not match it. Setting DocumentRoot to `public/` converts this to an architectural whitelist.
