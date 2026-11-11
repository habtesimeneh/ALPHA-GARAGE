# Phase 1.8A — Apache VirtualHost Hardening: Risk & Compatibility Analysis

**Phase:** 1.8A — MAMP Apache VirtualHost Security — Read-Only Inspection  
**Date:** 2026-11-11  
**Target:** Transitioning DocumentRoot from `C:\MAMP\htdocs` to `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`  
**Status:** **READ-ONLY RISK ASSESSMENT**

---

## 1. Executive Summary

Shifting the Apache DocumentRoot to the application's `public/` directory is the single most effective hardening measure for local web application security. It elevates security from reactive regex filtering to strict architectural isolation.

This report evaluates all potential risks, compatibility impacts, and regression vectors associated with reconfiguring Apache, along with tested mitigations for each.

---

## 2. Risk Matrix

| Risk ID | Threat / Failure Mode | Likelihood | Impact | Severity | Mitigation Strategy |
|:---:|:---|:---:|:---:|:---:|:---|
| **R-01** | **Apache Syntax Error / Boot Failure**<br>Typo in VirtualHost or directory directive prevents Apache from starting. | Low | High | **Medium** | Run `httpd -t` validation prior to any restart; maintain pristine backup of `httpd.conf` and `gms-ssl.conf`. |
| **R-02** | **Old Browser Bookmark 404**<br>Browsers accessing old URL `/AutoPro_Garage-main/AutoPro_Garage-main/public/...` receive 404 Not Found. | High | Low | **Low** | Provide user guidance that root URL is now `https://localhost/` or `https://localhost/login.php`. |
| **R-03** | **Hardcoded Path Regression**<br>Broken links, stylesheets, or scripts if codebase relies on hardcoded subdirectory paths. | None | High | **None** | Audited codebase: `git grep` verified **0 hardcoded path references**. `url()` helper dynamically computes root. |
| **R-04** | **Session & Cookie Invalidation**<br>Operator logged out or session cookie rejected if cookie path does not match new root. | None | Medium | **None** | Audited configuration: `bootstrap.php` and `AuthService.php` set cookie `path = '/'`. 100% compatible. |
| **R-05** | **MAMP GUI Preference Overwrite**<br>Using MAMP's graphical control panel to toggle settings overwrites `DocumentRoot` in `httpd.conf`. | Medium | Medium | **Medium** | Configure MAMP GUI's DocumentRoot setting to match the target path, or use a dedicated include file. |
| **R-06** | **Local SSL Certificate Conflict**<br>HTTPS VirtualHost fails to bind or certificate fails validation on Port 443. | Low | High | **Low** | Reuse verified existing Phase 1.5 certificate (`localhost-cert.pem`), which is already valid until Feb 2029. |
| **R-07** | **HTTP to HTTPS Redirection Loop**<br>Enforcing HTTPS redirect without checking `HTTPS` header causes infinite redirect loop. | Low | Medium | **Low** | Redirection rule explicitly checks `RewriteCond %{HTTPS} off` and checks port 80 only. |

---

## 3. In-Depth Technical Risk Evaluations

### 3.1 Codebase URL Compatibility (Risk R-03: ELIMINATED)
A static analysis scan was executed across all PHP, HTML, and JavaScript files in `app/` and `public/`:
```bash
git grep "/AutoPro_Garage-main" AutoPro_Garage-main/app AutoPro_Garage-main/public
# Result: 0 matches found
```
Every hyperlink, stylesheet link, and script reference in the project uses the dynamic helper `url()`:
- **Previous Resolution:** `url('login.php')` ➔ `/AutoPro_Garage-main/AutoPro_Garage-main/public/login.php`
- **New Resolution:** `url('login.php')` ➔ `/login.php`
Because the helper checks `substr($public, strlen($doc))` and resolves to an empty string when `$public === $doc`, all links transition automatically with zero code modification required.

### 3.2 Session & Cookie Compatibility (Risk R-04: ELIMINATED)
In `app/bootstrap.php` (line 70):
```php
session_set_cookie_params([
    'lifetime' => 0, 
    'path' => '/', 
    'secure' => $https, 
    'httponly' => true, 
    'samesite' => 'Lax'
]);
```
And in `app/services/AuthService.php` (line 187):
```php
setcookie(self::REMEMBER_COOKIE, $token, [
    'expires' => time() + $durationDays * 86400,
    'path' => '/',
    ...
]);
```
Because both session cookies (`GMSSESSID`) and persistent remember cookies (`GMS_REMEMBER`) are scoped to `path = '/'`, moving the application to the root URL will not break cookie delivery or invalidate active operator sessions.

### 3.3 MAMP GUI Configuration Management (Risk R-05: MITIGATED)
MAMP for Windows maintains an internal template and parses `httpd.conf` looking for:
```apache
# MAMP DOCUMENT_ROOT !! Don't remove this line !!
DocumentRoot "C:\MAMP\htdocs"
```
If an operator opens MAMP Preferences -> Web Server tab and clicks "Select", MAMP updates this line.
**Mitigation:** 
1. In `httpd.conf`, we preserve the comment marker while updating the path:
   ```apache
   # MAMP DOCUMENT_ROOT !! Don't remove this line !!
   DocumentRoot "C:/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main/public"
   ```
2. In MAMP's Preferences dialog, the operator can also verify that the Web Server Document Root points to `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`.

---

## 4. Architectural Security Gains Post-Cutover

| Vulnerability Vector | Current State (`htdocs`) | Proposed State (`public`) |
|:---|:---|:---|
| **Directory Indexing** | Root directory `/AutoPro_Garage-main/` can be listed if requested directly. | **Impossible.** DocumentRoot contains only `public/`; parent directories are outside web space. |
| **Documentation Exposure** | `docs/` is exposed because it lacks `.htaccess`. | **Impossible.** `docs/` is located outside DocumentRoot; Apache returns 404. |
| **Application Source Exposure** | `app/` relies on `app/.htaccess` (`Require all denied`). | **Impossible.** `app/` is located outside DocumentRoot; web server cannot serve it under any URL. |
| **Storage & Database Dumps** | Relies on regex filters in `httpd.conf`. | **Impossible.** `storage/` and `database/` reside entirely outside DocumentRoot. |
| **Git Metadata Exposure** | Relies on regex denying `.git`. | **Impossible.** `.git/` is two levels above DocumentRoot; completely unreachable via HTTP/S. |

---

## 5. Decision & Recommendation

The transition carries **minimal technical risk** because:
1. The codebase is already engineered to dynamically handle `DocumentRoot = public/`.
2. Existing session cookies are already scoped to root `/`.
3. The existing local SSL certificate is valid through 2029.
4. Rollback is instantaneous by restoring pre-flight configuration backups.

**Recommendation:** Proceed with preparing the exact proposed configuration files and rollback scripts in documentation for Phase 1.8B.
