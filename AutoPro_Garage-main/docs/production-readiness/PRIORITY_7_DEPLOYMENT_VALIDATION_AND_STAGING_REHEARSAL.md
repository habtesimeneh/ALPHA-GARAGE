# ALPHA GARAGE — PRIORITY 7
# DEPLOYMENT PLAN VALIDATION & STAGING REHEARSAL

**Document Version:** 1.0.0  
**Phase:** Priority 7 (Deployment Plan Validation & Staging Rehearsal)  
**Execution Date:** November 22, 2026  
**Auditor Profile:** Independent Senior DevOps Engineer & Production Deployment Auditor  
**Target Architecture:** ALPHA GARAGE (`AutoPro_Garage-main/`)  
**Audit Mode:** STRICTLY READ-ONLY (No code, schema, config, or host mutations)  
**Staging Decision:** **APPROVED WITH CONDITIONS**

---

## 1. Executive Summary

An independent, rigorous, read-only audit of the **Priority 6 Production Deployment Plan** was conducted to validate its technical accuracy, safety, completeness, and executability prior to any staging or production rollout.

The evaluation analyzed the production deployment runbook against the actual ALPHA GARAGE codebase, configuration mechanisms, MySQL database permissions, cryptographic services, and rollback architectures.

### Key Validation Outcomes
1. **Architecture & Web Root Isolation:** Confirmed sound. Mapping Apache's `DocumentRoot` exclusively to `AutoPro_Garage-main/public/` completely conceals `app/`, `database/`, `storage/`, and `tests/` outside the web root, satisfying the fundamental security boundary.
2. **Critical Database Privilege Gap Identified:** Audit of `database/least_privilege.sql` against the active schema revealed that `garage_app` lacked explicit grants on three newer tables: `customer_link_tokens`, `payment_idempotency_keys`, and `videos`. Executing the original SQL file as written on a fresh server would cause runtime database permission exceptions on video galleries, customer identity verification, and idempotent payment processing.
3. **PHP-FPM Environment Variable Trap:** The Priority 6 plan relied on Apache `SetEnv` directives in the virtual host. Under standard Ubuntu Apache `mod_proxy_fcgi` and PHP-FPM architectures, `SetEnv` variables are either prefixed with `REDIRECT_` or stripped from the worker process environment, causing `getenv()` to return `false` and crashing database initialization. The configuration must be supplemented with `ProxyFCGISetEnvIf` or PHP-FPM pool directives (`env[...]`).
4. **Unauthenticated Backup Cipher Risk:** The proposed backup encryption command (`openssl enc -aes-256-cbc`) utilizes unauthenticated CBC mode, which provides zero integrity or tamper verification. A corrupted or ransomware-altered archive will decrypt without error until SQL parsing fails midway. An authenticated scheme (GPG with SHA-256 MDC or OpenSSL with Encrypt-then-MAC) is specified.
5. **Maintenance Mode Reality Check:** The proposed `touch maintenance.flag` command does not natively trigger maintenance mode in the application code. Web server rewrite rules must be explicitly added to Apache to intercept requests and serve a 503 Service Unavailable page during maintenance windows.
6. **Staging Rehearsal Verdict:** **APPROVED WITH CONDITIONS.** The deployment strategy is fundamentally well-engineered, and staging rehearsal is authorized subject to incorporating the 5 identified operational corrections into the staging environment.

---

## 2. Priority 6 Plan Verification & Gap Analysis

| Priority 6 Component | Proposed Runbook Claim | Actual Codebase Reality / Audit Finding | Validation Status |
| :--- | :--- | :--- | :---: |
| **DocumentRoot Mapping** | Maps to `/var/www/alpha-garage/current/public` | Matches canonical public structure. Sensitive code remains outside web root. | **VERIFIED** |
| **Database Grants** | `least_privilege.sql` grants least privilege for all tables | Missing `videos`, `customer_link_tokens`, and `payment_idempotency_keys` in the repository SQL file. | **DEFECT IDENTIFIED** |
| **Environment Injection** | Injected via Apache `SetEnv` in vhost | Fails under `mod_proxy_fcgi` + PHP-FPM unless `ProxyFCGISetEnvIf` or FPM pool config is used. | **CORRECTION REQUIRED** |
| **Backup Encryption** | `openssl enc -aes-256-cbc` | CBC mode lacks integrity authentication; corrupted archives cannot be detected prior to restore. | **SECURITY RISK** |
| **Maintenance Mode** | `touch current/public/maintenance.flag` | No PHP code checks `maintenance.flag`; requires web server rewrite rules to function. | **GAP IDENTIFIED** |
| **Release Switching** | Atomic symlink update (`ln -sfn`) | Symlink switching works, but OPcache retains cached bytecode unless PHP-FPM is reloaded. | **CORRECTION REQUIRED** |
| **HSTS Rollout** | 1-year HSTS + `includeSubDomains; preload` | Immediate `preload` risks permanently bricking unconfigured subdomains. Phased rollout required. | **OPERATIONAL RISK** |

---

## 3. VPS Architecture Validation

### 3.1 Hardware Sizing & Capacity Analysis
The proposed baseline VPS configuration:
- **OS:** Ubuntu 24.04 LTS (Noble Numbat)
- **CPU:** 2 vCPUs
- **RAM:** 4 GB
- **Storage:** 50 GB NVMe SSD

**Evaluation against profiled runtime metrics:**
- In Priority 5, live profiling across all 6 dashboards demonstrated peak memory usage between **2.00 MB and 4.00 MB per request**, and database query times under **11 ms**.
- At a concurrent traffic load of 50 active workshop requests, PHP-FPM will consume approximately $50 \times 35\text{ MB} \approx 1.75\text{ GB}$ of RAM.
- MySQL 8.0 with a 512 MB InnoDB buffer pool will consume $\approx 800\text{ MB}$.
- Apache worker processes will consume $\approx 250\text{ MB}$.
- **Verdict:** 4 GB RAM provides ample headroom (approximately 1.2 GB free for OS disk caching and temporary backup operations).

### 3.2 PHP-FPM Pool Tuning (`/etc/php/8.3/fpm/pool.d/alpha-garage.conf`)
Default PHP-FPM settings (`pm = dynamic`, `max_children = 5`) are too conservative. The staging and production pool must be configured as follows:
```ini
[alpha-garage]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm-alpha-garage.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

pm = dynamic
pm.max_children = 40
pm.start_servers = 10
pm.min_spare_servers = 5
pm.max_spare_servers = 15
pm.max_requests = 1000

; Enforce memory limit and timeouts
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 60
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 55M
php_admin_flag[log_errors] = on
php_admin_value[error_log] = /var/www/alpha-garage/shared/storage/logs/php-fpm-error.log

; Ensure environment variables are not cleared
clear_env = no
```

---

## 4. Database Privilege Audit (`least_privilege.sql`)

### 4.1 The Missing Tables Discrepancy
An audit of `AutoPro_Garage-main/database/least_privilege.sql` reveals that lines 15–48 grant privileges on 24 tables. However, the application uses **32 tables**.
The following three tables are accessed by the web runtime user (`garage_app`) but are missing from `least_privilege.sql`:

1. **`videos`:**
   - **Accessed in:** `VideoService.php` (`SELECT`, `INSERT`, `UPDATE`, `DELETE`).
   - **Impact if missing:** Viewing videos fails; administrator video creation, publishing, and deletion throw `PDOException (Access denied)`.
2. **`customer_link_tokens`:**
   - **Accessed in:** `CustomerIdentityService.php` (`SELECT`, `INSERT`, `UPDATE`, `DELETE`).
   - **Impact if missing:** In-person customer profile linking and verification tokens fail completely.
3. **`payment_idempotency_keys`:**
   - **Accessed in:** `PaymentIdempotencyService.php` (`SELECT`, `INSERT`, `UPDATE`).
   - **Impact if missing:** Payment processing crashes with a database permission exception whenever an idempotency key is submitted.

### 4.2 Required Privilege Correction
The database administrator must execute the following supplemental grants when provisioning `garage_app`:
```sql
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.videos TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.customer_link_tokens TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.payment_idempotency_keys TO 'garage_app'@'localhost';
FLUSH PRIVILEGES;
```

---

## 5. Environment Variables & Secret Management Audit

### 5.1 The PHP-FPM / Apache `SetEnv` Propagation Trap
In Apache with `mod_proxy_fcgi` (standard on modern Linux distributions):
- `SetEnv VAR value` sets an internal Apache request note.
- When Apache rewrites or proxies requests to PHP-FPM over a Unix socket, FastCGI parameters are passed as CGI environment variables.
- Standard `SetEnv` variables are frequently prefixed as `REDIRECT_GMS_DB_HOST` in PHP's `$_SERVER` array and are **NOT visible to `getenv('GMS_DB_HOST')`**.
- Because `app/config/database.php` strictly calls `getenv($environmentName)`, database initialization fails.

### 5.2 The Verified Solution
To guarantee seamless variable propagation under PHP-FPM, use one of the following two verified approaches:

#### Approach A: Apache `ProxyFCGISetEnvIf` (Recommended in Virtual Host)
```apache
<VirtualHost *:443>
    ServerName garage.example.com
    DocumentRoot /var/www/alpha-garage/current/public

    # Forward FastCGI environment variables directly to PHP-FPM
    ProxyFCGISetEnvIf "true" GMS_DEBUG "false"
    ProxyFCGISetEnvIf "true" GMS_APP_URL "https://garage.example.com"
    ProxyFCGISetEnvIf "true" GMS_DB_HOST "127.0.0.1"
    ProxyFCGISetEnvIf "true" GMS_DB_PORT "3306"
    ProxyFCGISetEnvIf "true" GMS_DB_NAME "garage_management_system"
    ProxyFCGISetEnvIf "true" GMS_DB_USER "garage_app"
    ProxyFCGISetEnvIf "true" GMS_DB_PASS "ACTUAL_SECURE_PASSWORD"
    ProxyFCGISetEnvIf "true" GMS_DB_TZ "+03:00"
    ProxyFCGISetEnvIf "true" GMS_ENCRYPTION_KEY "64_HEX_CHAR_KEY"
</VirtualHost>
```

#### Approach B: Systemd Environment File (`/var/www/alpha-garage/shared/.env`)
Set `clear_env = no` in the PHP-FPM pool and load the environment file via systemd service override (`/etc/systemd/system/php8.3-fpm.service.d/override.conf`):
```ini
[Service]
EnvironmentFile=/var/www/alpha-garage/shared/.env
```

---

## 6. DocumentRoot & Directory Isolation Audit

### 6.1 Web Root Confinement
The proposed structure `/var/www/alpha-garage/current/public` is fully verified:
- `app/` is at `/var/www/alpha-garage/current/app` (1 level above `DocumentRoot`).
- `database/` is at `/var/www/alpha-garage/current/database` (1 level above `DocumentRoot`).
- `storage/` is symlinked to `/var/www/alpha-garage/shared/storage` (completely outside `DocumentRoot`).
- Even if Apache's `.htaccess` processing were disabled (`AllowOverride None`), sensitive source code, database dumps, and encryption keys **cannot be served over HTTP** because they reside entirely outside the directory tree served by Apache.

### 6.2 Symlink Security Directive
In `/etc/apache2/sites-available/alpha-garage.conf`, the directory configuration must specify:
```apache
<Directory /var/www/alpha-garage/current/public>
    Options -Indexes +FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```
*Note: If using `+SymLinksIfOwnerMatch` instead of `+FollowSymLinks`, the symlink `/var/www/alpha-garage/current` and the target directory `releases/YYYYMMDD_HHMMSS` must be owned by the exact same user (`deploy`), otherwise Apache returns HTTP 403 Forbidden.*

---

## 7. Release & Rollback Safety Analysis

### 7.1 OPcache Invalidation on Symlink Switch
- **The Problem:** PHP's Zend OPcache caches compiled bytecode based on file paths. When an atomic symlink is switched from Release A to Release B, OPcache may continue executing cached scripts from Release A until workers restart or cache entries expire.
- **The Fix:** The deployment and rollback runbooks must explicitly reload PHP-FPM immediately following the symlink change:
  ```bash
  sudo ln -sfn /var/www/alpha-garage/releases/${TARGET_RELEASE}/AutoPro_Garage-main /var/www/alpha-garage/current
  sudo systemctl reload php8.3-fpm
  ```

### 7.2 Web Server Maintenance Mode Implementation
Because `AutoPro_Garage-main/public/index.php` does not natively check for a `maintenance.flag` file, the maintenance mode gate must be implemented in the Apache virtual host or `.htaccess`:
```apache
# Maintenance Mode Interceptor
RewriteEngine On
RewriteCond %{DOCUMENT_ROOT}/maintenance.flag -f
RewriteCond %{REQUEST_URI} !^/maintenance\.html$
RewriteCond %{REQUEST_URI} !\.(css|js|png|jpg|svg)$
RewriteRule ^ /maintenance.html [R=503,L]
ErrorDocument 503 /maintenance.html
Header always set Retry-After "300"
```
When an administrator executes `touch /var/www/alpha-garage/current/public/maintenance.flag`, Apache immediately intercepts all inbound traffic and returns HTTP 503 with a branded maintenance page. Removing the file restores immediate live access.

---

## 8. Backup Security & Cryptographic Review

### 8.1 The Unauthenticated CBC Cipher Vulnerability
The Priority 6 backup script proposed:
```bash
openssl enc -aes-256-cbc -salt -pbkdf2 -in dump.sql -out dump.sql.enc
```
**Cryptographic Flaws:**
1. **Lack of Authenticity:** AES-256-CBC does not compute an authentication tag. Ciphertext tampering, truncated files, or bit-flipping attacks cannot be detected prior to decryption.
2. **Silent Failure on Decryption:** OpenSSL CBC decryption of corrupted data may succeed without warning, outputting malformed SQL statements that fail midway through database restoration.

### 8.2 Recommended Authenticated Encryption Schemes

#### Option A: GnuPG Symmetric AES-256 with SHA-256 MDC (Recommended)
```bash
# Encrypt with built-in Modification Detection Code (MDC)
gpg --symmetric --cipher-algo AES256 --digest-algo SHA256 --batch --yes \
    --passphrase-file /etc/alpha-garage/backup.passphrase \
    --output /var/www/alpha-garage/backups/db_${TIMESTAMP}.sql.gz.gpg \
    /tmp/db_${TIMESTAMP}.sql.gz

# Decrypt (Fails immediately with exit code 2 if tampered or corrupted)
gpg --decrypt --batch --yes \
    --passphrase-file /etc/alpha-garage/backup.passphrase \
    --output /tmp/db_restore.sql.gz \
    /var/www/alpha-garage/backups/db_${TIMESTAMP}.sql.gz.gpg
```

#### Option B: Encrypt-then-MAC (OpenSSL AES-256-CBC + HMAC-SHA256)
If GPG is unavailable, compute an HMAC-SHA256 checksum over the encrypted file and verify the signature before attempting any database restore:
```bash
openssl dgst -sha256 -hmac "$(cat /etc/alpha-garage/backup.hmac)" dump.sql.gz.enc > dump.sql.gz.enc.sig
```

### 8.3 Ransomware Protection
Store daily backups in cloud object storage (e.g. AWS S3, Wasabi, or Cloudflare R2) with **Object Lock enabled in Compliance Mode** and a 30-day retention period. Even if the production server root account is compromised, the backup files cannot be deleted or overwritten.

---

## 9. HSTS & HTTPS Phased Rollout Plan

Enabling `max-age=31536000; includeSubDomains; preload` on Day 1 is an operational anti-pattern. If any subdomain lacks an SSL certificate or staging testing requires plain HTTP, the domain is permanently inaccessible.

### The 4-Stage HSTS Rollout

| Stage | Timing | HSTS Header Configuration | Purpose |
| :---: | :--- | :--- | :--- |
| **Stage 1** | Staging Rehearsal & Go-Live Day 1 | `Strict-Transport-Security "max-age=300"` (5 min) | Verify HTTPS redirection works cleanly; easily backed out if SSL issues arise. |
| **Stage 2** | Production Week 1 (7 Days) | `Strict-Transport-Security "max-age=86400"` (1 day) | Confirm stable traffic across desktop and mobile devices. |
| **Stage 3** | Production Month 1 (30 Days) | `Strict-Transport-Security "max-age=2592000; includeSubDomains"` | Enforce HTTPS across all subdomains. |
| **Stage 4** | Production Month 3 (Mature) | `Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"` | Submit to Google HSTS Preload list for permanent browser-level enforcement. |

---

## 10. Staging Rehearsal Runbook (Step-by-Step)

The staging rehearsal must be executed in a sandboxed environment matching the production OS:

### Step 1: Environment Isolation
- Hostname: `staging.garage.example.com`
- Database: `garage_management_system_staging`
- Web Runtime Account: `garage_app_staging` (password distinct from production)
- Migration Account: `garage_migrator_staging` (password distinct from production)
- Encryption Key: Newly generated 64-character hex key (distinct from production)
- Public Base URL: `https://staging.garage.example.com`

### Step 2: Staging Database Setup
```bash
mysql -u root -p << 'EOF'
CREATE DATABASE garage_management_system_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'garage_app_staging'@'localhost' IDENTIFIED BY 'STAGING_APP_SECRET_XYZ';
CREATE USER 'garage_migrator_staging'@'localhost' IDENTIFIED BY 'STAGING_MIG_SECRET_XYZ';
GRANT ALL PRIVILEGES ON garage_management_system_staging.* TO 'garage_migrator_staging'@'localhost';
FLUSH PRIVILEGES;
EOF
```

### Step 3: Apply Corrected Database Grants
Execute the corrected grants including `videos`, `customer_link_tokens`, and `payment_idempotency_keys` for `garage_app_staging`.

### Step 4: Baseline Import & Migration Run
```bash
mysql -u garage_migrator_staging -p garage_management_system_staging < database/database.sql
GMS_DB_NAME=garage_management_system_staging \
GMS_DB_USER=garage_migrator_staging \
GMS_DB_PASS=STAGING_MIG_SECRET_XYZ \
php database/migrate.php
```

### Step 5: Bootstrap Staging Administrator
```bash
GMS_DB_NAME=garage_management_system_staging \
GMS_DB_USER=garage_migrator_staging \
GMS_DB_PASS=STAGING_MIG_SECRET_XYZ \
php database/recover_admin.php
# Create staging admin: "Staging Admin", username: "staging_admin"
```

### Step 6: Verify Staging Audit Chain Integrity
```bash
GMS_DB_NAME=garage_management_system_staging \
GMS_DB_USER=garage_app_staging \
GMS_DB_PASS=STAGING_APP_SECRET_XYZ \
php -r "require 'app/bootstrap.php'; var_dump(AuditLogger::verifyChain());"
# Output MUST state: All records are intact.
```

---

## 11. Failure Simulation Plan

The staging environment must safely simulate the following 10 failure conditions to verify resilience and logging:

| Failure Simulation | Simulation Method | Expected Application Behavior | Expected Log Entry | Security / Recovery Implication |
| :--- | :--- | :--- | :--- | :--- |
| **1. Database Down** | Stop MySQL service (`systemctl stop mysql`) | Generic 500 error page. No credentials or host paths leaked. | `PDOException: Connection refused` in `storage/logs/php-error.log` | Fail-closed confirmed. Internal details concealed from users. |
| **2. Missing Env Var** | Unset `GMS_DB_PASS` | Application aborts immediately with 500. | `RuntimeException: Configure database credentials...` | Prevents fallback to hardcoded or empty passwords. |
| **3. Read-Only Storage** | `chmod 0500 storage/logs` | Exception handler falls back to system error log. | System syslog records inability to write to application log. | Verify application doesn't crash silently. Restore with `chmod 0750`. |
| **4. Missing Extension** | Disable `openssl` in `php.ini` | `CryptoService` throws RuntimeException on initialization. | `Error: Call to undefined function openssl_encrypt()` | Critical failure. Deployment check must verify all 7 extensions. |
| **5. Failed Migration** | Inject intentional syntax error in test migration | `migrate.php` terminates with exit code 1; records rollback. | CLI outputs exact statement failure; schema remains uncorrupted. | Migration runner transactionality verified. |
| **6. Corrupted Encryption Key** | Truncate `GMS_ENCRYPTION_KEY` to 30 chars | `CryptoService::getKey()` throws RuntimeException immediately. | `GMS_ENCRYPTION_KEY must be exactly 64 hexadecimal characters.` | Prevents encrypting records with an invalid or weak key. |
| **7. Backup Failure** | Set backup destination partition to 100% full | Backup script aborts with non-zero exit code; triggers admin email. | `mysqldump: Error: write failed` logged to backup log. | Alerting verified. Automated monitoring intercepts failure. |
| **8. Restore from Bad Backup** | Flip random bits in encrypted backup file | GPG / HMAC decryption verification fails immediately; aborts restore. | `gpg: BAD signature` or `decryption failed: MAC check failed` | Corrupted data is intercepted BEFORE touching the database. |
| **9. Release Rollback** | Switch symlink back to previous release | Instantaneous reversion to previous release code. | Apache access log reflects clean requests; zero downtime. | OPcache reloads cleanly following PHP-FPM reload. |
| **10. HTTPS Misconfiguration** | Point browser to HTTP port 80 | Immediate 301 redirect to HTTPS. No cookies sent over plain HTTP. | Apache access log shows 301 response. | Cookie theft over unencrypted transport defeated. |

---

## 12. Deployment Findings & Severity Classification

| Finding ID | Severity | Category | Finding Summary | Recommended Action | Staging Gate Status |
| :--- | :---: | :---: | :--- | :--- | :---: |
| **F-01** | **HIGH** | Database | `least_privilege.sql` lacks grants for `videos`, `customer_link_tokens`, and `payment_idempotency_keys`. | Add missing table grants to `least_privilege.sql` before execution. | **MANDATORY CONDITION** |
| **F-02** | **HIGH** | Environment | Apache `SetEnv` fails to propagate to PHP-FPM via `mod_proxy_fcgi`. | Use `ProxyFCGISetEnvIf` in vhost or configure PHP-FPM pool `env[...]`. | **MANDATORY CONDITION** |
| **F-03** | **MEDIUM** | Backup | `openssl enc -aes-256-cbc` lacks authentication/tamper detection. | Adopt GPG with AES-256/MDC or OpenSSL with HMAC-SHA256 signature. | **MANDATORY CONDITION** |
| **F-04** | **MEDIUM** | Operations | `maintenance.flag` is not natively checked by application code. | Configure Apache rewrite rules in virtual host to serve 503 maintenance page. | **MANDATORY CONDITION** |
| **F-05** | **MEDIUM** | Operations | OPcache retains stale bytecode across symlink switches unless FPM reloads. | Add `sudo systemctl reload php8.3-fpm` to deployment and rollback runbooks. | **MANDATORY CONDITION** |
| **F-06** | **LOW** | Security | Immediate 1-year HSTS with `preload` creates irreversible lockout risk. | Adopt 4-stage phased HSTS rollout starting with `max-age=300`. | **RECOMMENDED** |

---

## 13. Production Deployment Gate Checklist

Production deployment is strictly blocked until the following criteria are signed off:

- [ ] All 6 Priority 7 findings are incorporated into staging environment configuration.
- [ ] Staging database initialized using corrected `least_privilege.sql` grants.
- [ ] All 10 staging failure simulations pass with expected log outcomes.
- [ ] Staging smoke test passes 100% across all 6 role dashboards.
- [ ] Staging automated backup and restoration cycle completes with zero data corruption.
- [ ] Linear SHA-256 audit chain verified intact via `AuditLogger::verifyChain()`.
- [ ] DocumentRoot verified pointing exclusively to `AutoPro_Garage-main/public/`.
- [ ] Master 256-bit AES encryption key safely archived in an administrative password vault.
- [ ] Host firewall (UFW) verified blocking port 3306 and allowing only 22, 80, 443.
- [ ] Deployment Owner and Lead Architect sign off on production rollout.

---

## 14. Final Staging Decision

### **VERDICT: APPROVED WITH CONDITIONS**

**Justification:**
1. The core architectural model (DocumentRoot isolation outside the web root, integer currency handling, linear SHA-256 audit hashing, AES-256-GCM encryption, and atomic symlinked releases) is sound and verified.
2. The 6 operational discrepancies identified during this audit (missing table grants in `least_privilege.sql`, PHP-FPM environment propagation, authenticated backup encryption, maintenance mode rewrite rules, OPcache reload, and phased HSTS) are fully documented with precise engineering remedies.
3. Staging rehearsal is authorized immediately once these conditions are applied to the staging environment.
