# Phase 1.7A — Online Production Deployment Requirements & Architecture Guide

**Phase:** 1.7A — Read-Only Admin Identity Investigation & Local Release Preparation  
**Date:** 2026-11-09  
**Application:** Alpha Garage Management System  
**Scope:** Forward-looking infrastructure, security, runtime, and operational requirements for future online production deployment.  
**Safety Mandate:** **PLANNING ONLY.** No domains purchased, no hosting infrastructure provisioned, no secrets transmitted, and no live deployment executed.

---

## 1. Executive Summary

Transitioning Alpha Garage Management System from local MAMP development to an internet-facing production environment requires addressing architecture, security, and operational controls that are not enforced in a local sandbox.

This specification establishes the mandatory baseline requirements across twelve operational domains prior to any public network exposure.

---

## 2. Comprehensive Requirements Matrix

| Domain | Mandatory Requirement | Production Standard | Local State | Action Prior to Go-Live |
|:---|:---|:---|:---|:---|
| **1. Runtime** | Supported PHP Version | PHP 8.2+ or 8.3.x (Latest patch release) with OPcache | PHP 8.3.1 (MAMP) | Deploy official package on Linux LTS |
| **2. Database** | Supported MySQL Engine | MySQL 8.0+ or MariaDB 10.11+ LTS | MySQL 5.7.24 (EOL) | Upgrade to MySQL 8.0 to enforce CHECK constraints |
| **3. Web Server** | Production Server Architecture | Apache 2.4.58+ or Nginx 1.24+ | Apache 2.4.33 (MAMP) | Dedicated production web server with minimal modules |
| **4. Web Root** | Strict DocumentRoot Isolation | Dedicated `public/` directory ONLY | `C:\MAMP\htdocs` (Shared) | Web root points strictly to `public/` |
| **5. TLS / SSL** | Trusted CA Certificate | Let's Encrypt / DigiCert (TLS 1.2 & 1.3 only) | Self-Signed Localhost | Automated ACME certificate issuance & renewal |
| **6. Traffic** | Strict HTTPS Redirection | 301 Permanent Redirect (preserve ACME) | Optional / Unforced | Global HTTP to HTTPS redirect with HSTS staging |
| **7. Mail** | Verified Domain SMTP | Dedicated authenticated SMTP with SPF/DKIM/DMARC | Dev spool fallback | Configure verified sender mailbox & domain DNS records |
| **8. Secrets** | Secrets Outside Web Root | System environment variables or `.env` (0600) | `local.php` on disk | Secure vault or environment variables injection |
| **9. Backups** | Automated Encrypted Backups | Daily GPG / AES-256 automated dumps | Shell script prepared | Configure systemd timer / cron with offsite sync |
| **10. Storage** | Off-Machine Backup Custody | Encrypted S3 / Wasabi / Borg offsite copy | Local disk only | Automated offsite replica with 90-day retention |
| **11. Logs** | Log Rotation & SIEM Monitoring | Logrotate, disk quotas, alert on reset failures | Unbounded local files | Configure system logrotate & critical error alerting |
| **12. Audit** | Continuous Audit Monitoring | Daily automated hash chain verification job | Manual CLI verification | Cron job executing `verifyChain()` with email/SMS alerts |

---

## 3. Detailed Specifications

### 3.1 PHP Version & Runtime Compatibility
- **Target Version:** PHP 8.2 or PHP 8.3 latest stable release (e.g., PHP 8.3.13+).
- **Required Extensions:**
  - `pdo_mysql`: Database abstraction and prepared statements.
  - `openssl`: AES-256-GCM field encryption and TLS transport.
  - `mbstring`: Multibyte string processing for UTF-8 integrity.
  - `ctype`, `json`, `filter`: Core validation and serialization.
  - `session`: Secure session management.
- **Production `php.ini` Directives:**
  ```ini
  display_errors = Off
  display_startup_errors = Off
  log_errors = On
  error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
  expose_php = Off
  session.cookie_httponly = 1
  session.cookie_secure = 1
  session.cookie_samesite = "Lax"
  session.gc_maxlifetime = 28800
  session.use_strict_mode = 1
  opcache.enable = 1
  opcache.validate_timestamps = 0 ; Revalidate only on atomic deployment
  ```

---

### 3.2 Database Engine Compatibility
- **Target Engine:** MySQL 8.0.35+ or MariaDB 10.11+ LTS.
- **Why MySQL 5.7 (Current MAMP) is Inadequate for Production:**
  - MySQL 5.7 reached End-of-Life (EOL) in October 2023. No security patches are published.
  - MySQL 5.7 parses `CHECK` constraints (e.g., `amount > 0`) but ignores them during writes. MySQL 8.0 strictly enforces `CHECK` constraints.
- **Database Configuration (`my.cnf`):**
  - Character Set: `utf8mb4`
  - Collation: `utf8mb4_unicode_ci`
  - SQL Mode: `STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION`
  - Time Zone: `+03:00` (consistent with East Africa Time / application configuration).
  - Network Binding: `bind-address = 127.0.0.1` (or private VPC network; never expose port 3306 publicly).

---

### 3.3 Web Server & DocumentRoot Architecture
- **Strict DocumentRoot Rule:**
  The server's DocumentRoot must point exclusively to the `public/` directory. Under no circumstances should the project repository root or parent directories be served.
- **Apache 2.4 Production VirtualHost Template:**
  ```apache
  <VirtualHost *:80>
      ServerName garage.yourdomain.com
      ServerAdmin admin@yourdomain.com

      # Allow ACME HTTP-01 challenges for Let's Encrypt
      RewriteEngine On
      RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
      RewriteRule ^(.*)$ https://%{HTTP_HOST}$1 [R=301,L]

      DocumentRoot /var/www/alphagarage/public
      <Directory /var/www/alphagarage/public>
          Options -Indexes +FollowSymLinks
          AllowOverride All
          Require all granted
      </Directory>
  </VirtualHost>

  <VirtualHost *:443>
      ServerName garage.yourdomain.com
      ServerAdmin admin@yourdomain.com
      DocumentRoot /var/www/alphagarage/public

      <Directory /var/www/alphagarage/public>
          Options -Indexes +FollowSymLinks
          AllowOverride All
          Require all granted
      </Directory>

      SSLEngine on
      SSLCertificateFile /etc/letsencrypt/live/garage.yourdomain.com/fullchain.pem
      SSLCertificateKeyFile /etc/letsencrypt/live/garage.yourdomain.com/privkey.pem

      # Modern TLS Configuration (TLS 1.2 and 1.3 only)
      SSLProtocol all -SSLv2 -SSLv3 -TLSv1 -TLSv1.1
      SSLHonorCipherOrder off
      SSLSessionTickets off

      # HTTP Strict Transport Security (Staged: start at 1 day, move to 1 year after verification)
      Header always set Strict-Transport-Security "max-age=86400"

      ErrorLog /var/log/apache2/alphagarage_error.log
      CustomLog /var/log/apache2/alphagarage_access.log combined
  </VirtualHost>
  ```

---

### 3.4 Email Delivery & SMTP Authentication
- **Problem with Dev Fallback:** The local development spool (`storage/mail_spool/`) writes emails to disk. In production, this results in failed password resets and notification delivery failure.
- **Required Production SMTP Configuration:**
  - Dedicated authenticated mailbox on the garage domain (e.g., `notifications@alphagarage.et` or transactional service like Postmark/Sendgrid).
  - **DNS Alignment:**
    - `SPF`: `v=spf1 include:_spf.yourmailprovider.com ~all`
    - `DKIM`: Public 2048-bit DKIM key published in domain DNS.
    - `DMARC`: `v=DMARC1; p=reject; rua=mailto:dmarc-reports@yourdomain.com`
  - Transport Encryption: `tls` (Port 587) or `ssl` (Port 465). Plaintext (`none`) must be rejected.
- **Required Production Environment Variables:**
  ```ini
  SMTP_HOST=smtp.yourdomain.com
  SMTP_PORT=587
  SMTP_USERNAME=notifications@yourdomain.com
  SMTP_PASSWORD=STRONG_OUT_OF_BAND_SECRET
  SMTP_ENCRYPTION=tls
  SMTP_FROM_EMAIL=notifications@yourdomain.com
  SMTP_FROM_NAME="Alpha Garage Management"
  GMS_APP_URL=https://garage.yourdomain.com
  ```

---

### 3.5 Secrets Management & Configuration Isolation
- **Rule:** Never store live secrets or API credentials in Git.
- **Storage Strategy:**
  - Host environment variables via systemd unit file (`EnvironmentFile=/etc/alphagarage.env` with file permissions `0600 root:www-data`).
  - Or an externalized `app/config/local.php` that resides outside the Git repository with `chmod 0640` and ownership restricted to `www-data`.
- **Encryption Key:**
  - The blind-index and sensitive data encryption key (`storage/private/encryption.key`) must be securely generated (`random_bytes(32)`) and backed up in an offline password manager/vault. Losing this key results in permanent loss of encrypted data.

---

### 3.6 Automated Backups & Disaster Recovery
- **Backup Script:** Utilize and productionize `scripts/backup_database.sh`.
- **Workflow:**
  1. `mysqldump` with options `--single-transaction --quick --routines --triggers --no-autocommit`.
  2. Filter: Verify that dumps contain no dangerous `USE` or `CREATE DATABASE` statements.
  3. Gzip compression.
  4. Encryption: Asymmetric GPG encryption with the garage owner's public key (or OpenSSL AES-256-CBC with an offline passphrase).
  5. SHA-256 checksum sidecar generation.
- **Automated Schedule (Cron/Systemd Timer):**
  - Daily at 02:00 AM server time.
  - Retention tier: 7 daily backups, 4 weekly backups, 12 monthly backups.
- **Off-Machine Replication:**
  - Automated transfer to an immutable cloud bucket (AWS S3 with Object Lock or Wasabi/B2) via `rclone` or AWS CLI.
  - Quarterly restore rehearsed into an isolated restore sandbox database.

---

### 3.7 Continuous Monitoring & Operational Health
1. **Audit Hash-Chain Watchdog:**
   - Execute a nightly cron job:
     ```bash
     0 3 * * * php /var/www/alphagarage/scripts/verify_audit_chain.php || notify-admin
     ```
   - Triggers an immediate critical alert if `ok !== true` or hash chain breaks.
2. **Security Event Monitoring:**
   - Monitor `audit_logs` for repeated `login_blocked_rate_limit`, `csrf_validation_failed`, and `password_reset_delivery_failed`.
   - Alert administrator if reset delivery fails more than 3 times in 1 hour.
3. **Log Rotation:**
   - Configure `/etc/logrotate.d/alphagarage`:
     ```
     /var/www/alphagarage/storage/logs/*.log {
         weekly
         missingok
         rotate 12
         compress
         delaycompress
         notifempty
         create 0660 www-data www-data
     }
     ```

---

## 4. Pre-Production Go-Live Checklist

Prior to opening network firewall ports to the public:
- [ ] Owner has formally approved administrator identity and rotated password.
- [ ] Test administrator `kilotest_auth_63e8ee04` is deactivated (`is_active = 0`).
- [ ] Web server DocumentRoot points strictly to `public/`.
- [ ] Direct web access to `http://domain/.git`, `app/`, `database/`, `storage/`, `docs/` returns 404/403.
- [ ] Trusted CA SSL certificate is installed and auto-renews.
- [ ] HTTP-to-HTTPS 301 redirection is verified with curl.
- [ ] `expose_php = Off` and `session.cookie_secure = 1` are confirmed in HTTP response headers.
- [ ] SMTP delivery of password reset link is tested end-to-end to an external mailbox.
- [ ] SPF, DKIM, and DMARC DNS validation passes.
- [ ] Production database uses least-privilege user (no DDL/drop grants for standard app user).
- [ ] Automated backup script runs, encrypts, and syncs off-site successfully.
- [ ] Nightly audit chain verification cron job is active.
- [ ] `public/setup.php` is deleted or inaccessible in the production tree.
