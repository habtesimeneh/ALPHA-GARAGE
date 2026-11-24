# ALPHA GARAGE — PRIORITY 6
# PRODUCTION DEPLOYMENT PREPARATION & OPERATIONS PLAN

**Document Version:** 1.0.0  
**Phase:** Priority 6 (Production Deployment Preparation & Operations Plan)  
**Execution Date:** November 22, 2026  
**Author Profile:** Senior DevOps Engineer, PHP Deployment Specialist, DBA & Production Operations Lead  
**Target Application:** ALPHA GARAGE (`AutoPro_Garage-main/`)  
**Deployment Status:** DEPLOYMENT PLANNING & PREPARATION ONLY (Non-Destructive)  
**Preceding Audit Result:** Priority 5 — **PRODUCTION READY WITH CONDITIONS**

---

## 1. Executive Summary

This document establishes the definitive, end-to-end operational deployment runbook and infrastructure plan for launching **ALPHA GARAGE** into a live production environment.

Following the successful verification of critical security controls (Priority 1), architecture consolidation (Priority 2), database indexing and query optimization (Priority 3), UI/UX, accessibility, and bilingual localization (Priority 4), and the independent production readiness audit (Priority 5), this operations plan provides the necessary engineering procedures to translate the local Windows/MAMP development environment into a hardened, high-availability, enterprise-grade Linux production deployment.

### Key Deployment Objectives
1. **Complete Web Root Isolation:** Point the web server `DocumentRoot` exclusively to `AutoPro_Garage-main/public/`. All application logic, database schemas, migration runners, private keys, logs, and backups remain outside the web server's document root.
2. **Database Least-Privilege Architecture:** Isolate the web runtime database account (`garage_app`) from the CLI migration account (`garage_migrator`) using `database/least_privilege.sql`.
3. **Environment-Based Configuration:** Eliminate file-based credentials in production by injecting all secrets (`GMS_DB_*`, `GMS_ENCRYPTION_KEY`, `GMS_APP_URL`) via server environment variables.
4. **Disaster Recovery & High Availability:** Establish an automated RPO ≤ 24 hours, RTO ≤ 1 hour backup pipeline featuring encrypted offsite replication and verified linear hash-chain integrity.
5. **Zero-Downtime Rollback Strategy:** Utilize atomic directory symlinking (`current -> releases/YYYYMMDD_HHMMSS`) to enable instantaneous rollback in the event of unforeseen deployment regressions.

---

## 2. Verified Deployment Requirements

The following requirements were derived directly from the canonical ALPHA GARAGE source code (`AutoPro_Garage-main/`):

### 2.1 Server Runtime & PHP Environment
- **PHP Version:** **PHP 8.2 or 8.3** (Strictly ≥ 8.1.0). The codebase utilizes PHP 8.1+ language features including `declare(strict_types=1);`, `match` expressions, typed class properties, `readonly` variables, `str_contains()`, and the `never` return type.
- **Mandatory PHP Extensions:**
  - `pdo_mysql`: Database communication using PDO prepared statements.
  - `openssl`: AES-256-GCM authenticated encryption in `CryptoService.php`.
  - `mbstring`: Multibyte string operations, UTF-8 normalization, and Ethiopic Amharic text processing.
  - `session`: Native hardened session management.
  - `json`: JSON serialization for audit logs, chart payloads, and API endpoints.
  - `fileinfo`: Server-side MIME-type inspection for secure media streaming (`media-stream.php`).
  - `ctype`: Character type verification used in configuration and ID validation.
- **PHP Runtime Configuration (`php.ini`):**
  - `display_errors = Off` (Mandatory: prevents internal path disclosure).
  - `display_startup_errors = Off`
  - `log_errors = On`
  - `error_reporting = E_ALL`
  - `error_log = /var/log/php/alpha_garage_error.log` (or within `storage/logs/php-error.log`).
  - `session.use_strict_mode = 1`
  - `session.use_only_cookies = 1`
  - `session.cookie_httponly = 1`
  - `session.cookie_samesite = "Lax"`
  - `session.cookie_secure = 1` (Requires HTTPS).
  - `upload_max_filesize = 50M` (To support workshop diagnostic video uploads).
  - `post_max_size = 55M`
  - `memory_limit = 256M` (Exceeds the profiled 4.0 MB peak memory requirement).
  - `max_execution_time = 60`
  - `date.timezone = "Africa/Addis_Ababa"`

### 2.2 Database System Requirements
- **Database Engine:** **MySQL 8.0+** or **MariaDB 10.5+** (MySQL 5.7+ supported).
- **Default Storage Engine:** **InnoDB** (Mandatory for ACID compliance, transactions, foreign keys, and row-level locking).
- **Default Collation:** **`utf8mb4_unicode_ci`** or `utf8mb4_0900_ai_ci` with `utf8mb4` character set.
- **Transaction Isolation Level:** `READ COMMITTED` or `REPEATABLE READ`.
- **Database Timezone:** Standardized to Ethiopian Standard Time (`+03:00`).

### 2.3 Web Server Requirements (Apache or Nginx)
- **Apache 2.4+:**
  - Mandatory Modules: `mod_rewrite`, `mod_headers`, `mod_ssl`, `mod_deflate`, `mod_expires`.
  - Configuration Directive: `AllowOverride All` (if utilizing `.htaccess`) or direct virtual host block mapping.
- **Nginx 1.20+ (Alternative / Reverse Proxy):**
  - FastCGI process manager (`php-fpm`).
  - Configuration directive routing all dynamic traffic to `public/index.php`.

### 2.4 Storage & Filesystem Permissions
The application requires specific directory write access for the web server user (`www-data` on Ubuntu/Debian, `apache` on RHEL/CentOS):
- `storage/logs/`: Application error logs (`app.log`, `php-error.log`). **[WRITABLE]**
- `storage/private/`: Master encryption key (`encryption.key`). **[WRITABLE / RESTRICTED 0700]**
- `storage/rate_limits/`: File-based brute-force attempt counters. **[WRITABLE]**
- `storage/uploads/videos/`: Uploaded workshop video MP4/WebM files. **[WRITABLE]**
- `storage/uploads/thumbnails/`: Custom video thumbnail image files. **[WRITABLE]**
- `storage/sessions/`: File-based session storage (if used in test/isolated modes). **[WRITABLE]**
- `storage/mail_spool/`: Fallback mail spooling when SMTP is unconfigured. **[WRITABLE]**
- `app/`, `database/`, `public/`, `tests/`: **[READ-ONLY to the web server process]**

---

## 3. Hosting Options Analysis

| Hosting Architecture | Compatibility | Security & Isolation | Scalability | Complexity | Cost / Mo (Est.) | Fit for ALPHA GARAGE |
| :--- | :---: | :---: | :---: | :---: | :---: | :--- |
| **Shared Hosting** (cPanel/Plesk) | Low / Poor | Low (Shared DocumentRoot risks) | Minimal | Low | \$5 – \$15 | **NOT RECOMMENDED:** Cannot guarantee `DocumentRoot` isolation outside `public_html`; CLI migration scripts restricted; background worker limitations. |
| **Virtual Private Server (VPS)** (DigitalOcean, Linode, AWS Lightsail, Hetzner) | **High (100%)** | **High (Isolated OS, UFW firewall, dedicated DocumentRoot)** | Medium to High | Moderate | \$12 – \$30 | **RECOMMENDED (PRIMARY):** Full root/SSH control, native PHP-FPM/Apache tuning, granular file permissions, automated snapshot backups, cost-effective. |
| **Managed Cloud Server** (AWS EC2 / RDS, GCP Compute) | **High (100%)** | **Very High (VPC isolation, managed RDS database, IAM roles)** | Very High | Moderate to High | \$40 – \$90 | **RECOMMENDED (ENTERPRISE):** Best for multi-branch operations requiring automated database failover, read-replicas, and S3 asset offloading. |
| **Serverless / PaaS** (Heroku, AWS Lambda / Bref) | Medium | High | High | High | Variable | **NOT RECOMMENDED:** Ephemeral filesystems conflict with local storage uploads and linear SQLite/file session mechanisms without heavy architectural re-engineering. |

### Final Hosting Recommendation
Deploy on a **Virtual Private Server (VPS)** running **Ubuntu 24.04 LTS (or 22.04 LTS)** with:
- **CPU:** 2 vCPUs
- **RAM:** 4 GB (2 GB minimum)
- **Disk:** 50 GB NVMe SSD
- **Web Server:** Apache 2.4 with PHP 8.3-FPM (or Nginx + PHP 8.3-FPM)
- **Database:** Local MySQL 8.0 server listening strictly on `127.0.0.1` (or managed database instance)

---

## 4. Production Architecture & Directory Layout

To enforce absolute security, the production filesystem isolates all executable application code, database files, and private keys outside the web server's public document root.

### Recommended Production Directory Tree (`/var/www/alpha-garage/`)

```text
/var/www/alpha-garage/
├── current -> /var/www/alpha-garage/releases/20261122_120000/   # Deployment symlink
├── shared/                                                      # Persistent shared state across deployments
│   ├── .env                                                     # Master environment secrets (0600, root:root)
│   └── storage/                                                 # Shared runtime storage
│       ├── logs/                                                # Application and error logs (0750, www-data:www-data)
│       ├── mail_spool/                                          # Outbound mail queue
│       ├── private/                                             # Master encryption key (0700, www-data:www-data)
│       │   └── encryption.key                                   # AES-256-GCM 32-byte key (0600)
│       ├── rate_limits/                                         # Brute-force tracking files (0750)
│       └── uploads/                                             # User-uploaded workshop media
│           ├── thumbnails/                                      # Uploaded video thumbnails
│           └── videos/                                          # Uploaded diagnostic videos
├── backups/                                                     # Local encrypted database dumps (0700, root:root)
└── releases/
    └── 20261122_120000/                                         # Immutable release snapshot
        ├── app/                                                 # Read-only application source
        │   ├── config/
        │   │   ├── app.php
        │   │   ├── database.php
        │   │   └── local.php.example
        │   ├── helpers/
        │   ├── middleware/
        │   ├── models/
        │   ├── services/
        │   ├── views/
        │   └── bootstrap.php
        ├── database/                                            # Read-only migrations & schemas
        │   ├── migrations/
        │   ├── database.sql
        │   ├── least_privilege.sql
        │   └── migrate.php
        ├── public/                                              # <--- APACHE DOCUMENTROOT POINTS HERE ONLY
        │   ├── assets/
        │   │   ├── css/
        │   │   ├── js/
        │   │   └── images/
        │   ├── index.php                                        # Main web entry point
        │   ├── customer.php                                     # Customer portal entry point
        │   ├── media-stream.php                                 # Secure video streaming controller
        │   ├── .htaccess                                        # Public access controls
        │   └── [submodules]/                                    # Module entry points (appointments, job-cards, etc.)
        └── storage -> /var/www/alpha-garage/shared/storage      # Symlink to persistent shared storage
```

### Filesystem Access Permissions Matrix

| Directory / File | Ownership | POSIX Permissions | Web Access | Purpose |
| :--- | :---: | :---: | :---: | :--- |
| `/var/www/alpha-garage/releases/` | `deploy:www-data` | `0755` (dirs), `0644` (files) | None | Read-only application release files |
| `/var/www/alpha-garage/current/public/` | `deploy:www-data` | `0755` (dirs), `0644` (files) | **PUBLIC (DocumentRoot)** | Static assets & public entry scripts |
| `/var/www/alpha-garage/shared/.env` | `root:www-data` | `0640` | None | Production environment credentials |
| `/var/www/alpha-garage/shared/storage/` | `www-data:www-data`| `0750` | None | Base runtime storage |
| `.../storage/private/encryption.key` | `www-data:www-data`| `0600` | None | Master AES-256-GCM encryption key |
| `.../storage/logs/` | `www-data:www-data`| `0750` | None | PHP and application log destination |
| `.../storage/uploads/` | `www-data:www-data`| `0750` | Served via `media-stream.php` | Media storage (direct execution blocked) |

---

## 5. Environment Configuration Plan

ALPHA GARAGE uses environment variables as the highest-priority configuration source (`getenv()`), falling back to `app/config/local.php` only when environment variables are unset.

### 5.1 Production Environment Variables Specification

| Variable Name | Purpose | Required / Optional | Expected Format / Example | Safe Injection Method |
| :--- | :--- | :---: | :--- | :--- |
| `GMS_NAME` | Display name of the garage | Optional | `"Alpha Garage"` | Virtual host configuration |
| `GMS_DEBUG` | Controls error display and stack traces | **MANDATORY** | `false` | Must be `false` in production |
| `GMS_TIMEZONE` | Application default timezone | Optional | `"Africa/Addis_Ababa"` | System / vhost variable |
| `GMS_APP_URL` | Canonical HTTPS base URL | **MANDATORY** | `https://garage.example.com` (no trailing slash) | Apache `SetEnv` or systemd environment |
| `GMS_DB_HOST` | MySQL database hostname | **MANDATORY** | `127.0.0.1` or `localhost` | Environment variable |
| `GMS_DB_PORT` | MySQL TCP port | Optional | `3306` (1–65535) | Environment variable |
| `GMS_DB_NAME` | MySQL database name | **MANDATORY** | `garage_management_system` | Alphanumeric + underscore |
| `GMS_DB_USER` | Runtime least-privilege DB user | **MANDATORY** | `garage_app` | Isolated web user |
| `GMS_DB_PASS` | Password for `garage_app` | **MANDATORY** | High-entropy string (≥32 chars) | Keep out of Git / command history |
| `GMS_DB_TZ` | Database connection session offset | Optional | `+03:00` | Format: `^[+-]\d{2}:\d{2}$` |
| `GMS_ENCRYPTION_KEY` | 256-bit AES-GCM master key | Recommended | 64 hexadecimal characters (32 raw bytes) | Injected via environment or file |
| `SMTP_HOST` | Outbound SMTP relay hostname | Required for mail | `smtp.mailgun.org` or `smtp.sendgrid.net` | Environment variable |
| `SMTP_PORT` | Outbound SMTP port | Required for mail | `587` (TLS) or `465` (SSL) | Environment variable |
| `SMTP_USERNAME` | SMTP authentication username | Required for mail | `postmaster@garage.example.com` | Environment variable |
| `SMTP_PASSWORD` | SMTP authentication secret | Required for mail | High-entropy secret | Environment variable |
| `SMTP_FROM_EMAIL` | Verified sender email address | Required for mail | `no-reply@garage.example.com` | Must match SPF/DKIM records |
| `SMTP_FROM_NAME` | Outbound sender name | Optional | `"Alpha Garage Notifications"` | Environment variable |
| `SMTP_ENCRYPTION` | Transport layer encryption | Required for mail | `tls` or `ssl` | Environment variable |

### 5.2 Apache Virtual Host Environment Injection (`/etc/apache2/sites-available/alpha-garage.conf`)

```apache
<VirtualHost *:443>
    ServerName garage.example.com
    ServerAdmin sysadmin@garage.example.com
    DocumentRoot /var/www/alpha-garage/current/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/garage.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/garage.example.com/privkey.pem
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384

    # Inject Production Environment Secrets
    SetEnv GMS_DEBUG "false"
    SetEnv GMS_APP_URL "https://garage.example.com"
    SetEnv GMS_TIMEZONE "Africa/Addis_Ababa"
    SetEnv GMS_DB_HOST "127.0.0.1"
    SetEnv GMS_DB_PORT "3306"
    SetEnv GMS_DB_NAME "garage_management_system"
    SetEnv GMS_DB_USER "garage_app"
    SetEnv GMS_DB_PASS "REPLACE_WITH_ACTUAL_APP_PASSWORD"
    SetEnv GMS_DB_TZ "+03:00"
    SetEnv GMS_ENCRYPTION_KEY "REPLACE_WITH_64_HEX_CHAR_ENCRYPTION_KEY"

    # Outbound SMTP Mail Configuration
    SetEnv SMTP_HOST "smtp.example.com"
    SetEnv SMTP_PORT "587"
    SetEnv SMTP_USERNAME "alerts@garage.example.com"
    SetEnv SMTP_PASSWORD "REPLACE_WITH_SMTP_PASSWORD"
    SetEnv SMTP_FROM_EMAIL "no-reply@garage.example.com"
    SetEnv SMTP_FROM_NAME "Alpha Garage"
    SetEnv SMTP_ENCRYPTION "tls"

    <Directory /var/www/alpha-garage/current/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Custom Logging
    ErrorLog ${APACHE_LOG_DIR}/alpha_garage_error.log
    CustomLog ${APACHE_LOG_DIR}/alpha_garage_access.log combined
</VirtualHost>
```

---

## 6. Database Deployment & Migration Plan

### 6.1 Database Initialization (Fresh Production Server)

#### Step 1: Create Database with Proper Collation
Execute via MySQL root / DBA console:
```sql
CREATE DATABASE IF NOT EXISTS garage_management_system
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;
```

#### Step 2: Establish User Roles & Least-Privilege Boundaries
Execute the project's verified `database/least_privilege.sql` script:
```sql
-- Create runtime user (web traffic)
CREATE USER IF NOT EXISTS 'garage_app'@'localhost' IDENTIFIED BY 'STRONG_RANDOM_WEB_PASSWORD';

-- Create migration user (CLI migrations only)
CREATE USER IF NOT EXISTS 'garage_migrator'@'localhost' IDENTIFIED BY 'STRONG_RANDOM_MIGRATOR_PASSWORD';

-- Revoke baseline grants
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'garage_app'@'localhost';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'garage_migrator'@'localhost';

-- Apply granular grants from least_privilege.sql
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.users TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.roles TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.permissions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.role_permissions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.mechanics TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.customers TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.vehicles TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_cards TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_updates TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.services TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_services TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.appointments TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.suppliers TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.spare_parts TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.inventory_transactions TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.job_parts TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.part_requests TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.invoices TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.invoice_items TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.notifications TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.password_resets TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.garage_settings TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.videos TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.customer_link_tokens TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON garage_management_system.payment_idempotency_keys TO 'garage_app'@'localhost';
GRANT SELECT, INSERT, DELETE ON garage_management_system.login_attempts TO 'garage_app'@'localhost';
GRANT SELECT, INSERT ON garage_management_system.contact_inquiries TO 'garage_app'@'localhost';
GRANT SELECT, INSERT ON garage_management_system.expenses TO 'garage_app'@'localhost';
GRANT SELECT, INSERT ON garage_management_system.payments TO 'garage_app'@'localhost';
GRANT UPDATE (receipt_number) ON garage_management_system.payments TO 'garage_app'@'localhost';
GRANT SELECT, INSERT ON garage_management_system.audit_logs TO 'garage_app'@'localhost';
GRANT SELECT, UPDATE ON garage_management_system.audit_chain_head TO 'garage_app'@'localhost';

-- Migrator account privileges (DDL & migrations)
GRANT ALL PRIVILEGES ON garage_management_system.* TO 'garage_migrator'@'localhost';

FLUSH PRIVILEGES;
```

#### Step 3: Baseline Schema Import
Import the canonical baseline DDL into the empty database using the migrator account:
```bash
mysql -u garage_migrator -p garage_management_system < database/database.sql
```

#### Step 4: Apply Tracked Migrations
Execute the migration runner via PHP CLI:
```bash
GMS_DB_USER=garage_migrator GMS_DB_PASS=STRONG_RANDOM_MIGRATOR_PASSWORD php database/migrate.php
```

#### Step 5: Initial Administrator Creation
Bootstrap the first administrator account safely through the interactive CLI utility:
```bash
php database/recover_admin.php
```
*Follow the interactive prompt: Type `RECOVER ADMIN`, enter full name, username, email, and choose a strong password (minimum 10 characters with mixed-case, numbers, and symbols).*

---

## 7. Backup & Disaster Recovery Plan

### 7.1 Recovery Objectives
- **Recovery Point Objective (RPO):** **≤ 24 Hours** (Automated daily full backup + optional binary logs for point-in-time recovery to ≤ 15 minutes).
- **Recovery Time Objective (RTO):** **≤ 1 Hour** to full operational restoration following complete hardware failure.

### 7.2 Backup Architecture
1. **Automated Database Backup Script (`/usr/local/bin/backup-alpha-garage.sh`):**
   - Dumps `garage_management_system` using `mysqldump` with `--single-transaction --quick --triggers --routines`.
   - Encrypts dump with AES-256 via OpenSSL (`openssl enc -aes-256-cbc -salt -pbkdf2`).
   - Rotates local dumps: keeps 7 daily backups locally.
   - Syncs encrypted archive offsite to S3 / Backblaze B2 / remote SSH backup server.
2. **Media & Keys Backup:**
   - Daily rsync of `storage/uploads/` and `storage/private/encryption.key` to offsite encrypted storage.

### 7.3 Backup Cron Configuration (`/etc/cron.d/alpha-garage-backup`)
```cron
# Run daily database backup at 02:00 AM EAT
0 2 * * * root /usr/local/bin/backup-alpha-garage.sh >> /var/log/alpha_garage_backup.log 2>&1
```

### 7.4 Disaster Recovery Runbook

#### Scenario A: Database Corruption or Accidental Data Deletion
1. Put web server in maintenance mode: `touch /var/www/alpha-garage/current/public/maintenance.flag`
2. Decrypt the latest verified backup:
   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 -in /var/www/alpha-garage/backups/db_20261122.sql.gz.enc -out /tmp/db_restore.sql.gz
   gunzip /tmp/db_restore.sql.gz
   ```
3. Restore database schema and data:
   ```bash
   mysql -u garage_migrator -p garage_management_system < /tmp/db_restore.sql
   rm /tmp/db_restore.sql
   ```
4. Verify linear hash-chain audit integrity:
   ```bash
   php -r "require '/var/www/alpha-garage/current/app/bootstrap.php'; var_dump(AuditLogger::verifyChain());"
   ```
5. Remove maintenance mode flag: `rm /var/www/alpha-garage/current/public/maintenance.flag`

#### Scenario B: Total Hardware Server Destruction
1. Provision a fresh Ubuntu 24.04 VPS.
2. Install LAMP stack: `apt install apache2 mysql-server php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-openssl`.
3. Re-create directory structure `/var/www/alpha-garage/{releases,shared,backups}`.
4. Clone code repository to a new release folder and symlink `shared/storage` and `shared/.env`.
5. Restore database from offsite encrypted backup.
6. Restore `storage/private/encryption.key` from secure vault (essential for decrypting customer data).
7. Issue SSL certificate via Certbot: `certbot --apache -d garage.example.com`.
8. Execute smoke test checklist.

---

## 8. HTTPS & Web Security Configuration

### 8.1 TLS / SSL Hardening
- **Protocol Support:** TLSv1.2 and TLSv1.3 only (Disable SSLv3, TLSv1.0, TLSv1.1).
- **Automated Certificate Renewal:** Install Certbot with automatic renewal crontab:
  ```bash
  sudo certbot --apache -d garage.example.com --non-interactive --agree-tos -m admin@garage.example.com
  ```
- **HSTS (HTTP Strict Transport Security):** Configured in Apache to force 1-year HTTPS adherence:
  ```apache
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
  ```

### 8.2 Firewall & Network Hardening (UFW)
Configure host firewall to deny all inbound traffic except essential services:
```bash
# Set defaults
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Allow SSH (use custom port if configured), HTTP, and HTTPS
sudo ufw allow 22/tcp
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp

# Ensure MySQL is NOT reachable from the public internet
sudo ufw deny 3306

# Enable firewall
sudo ufw enable
```

### 8.3 SSH Hardening (`/etc/ssh/sshd_config`)
- `PermitRootLogin prohibit-password` (or `no`).
- `PasswordAuthentication no` (Enforce SSH Ed25519 public key authentication).
- `MaxAuthTries 3`
- Install `fail2ban` to ban IP addresses after repeated failed SSH login attempts.

---

## 9. Step-by-Step Deployment Runbook

Follow this exact sequence when performing the live production deployment:

### Phase 1: Target Server Preparation
1. **Provision Server:** Boot fresh Ubuntu 24.04 LTS VPS instance.
2. **Apply OS Patches:** `sudo apt update && sudo apt upgrade -y`.
3. **Install Packages:**
   ```bash
   sudo apt install -y apache2 mysql-server git ufw fail2ban certbot python3-certbot-apache \
       php8.3 php8.3-cli php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-openssl php8.3-json php8.3-fileinfo
   ```
4. **Configure UFW Firewall:** Allow 22, 80, 443; deny 3306; enable.

### Phase 2: Directory & Storage Setup
5. **Create Directory Architecture:**
   ```bash
   sudo mkdir -p /var/www/alpha-garage/{releases,shared,backups}
   sudo mkdir -p /var/www/alpha-garage/shared/storage/{logs,mail_spool,private,rate_limits,uploads/videos,uploads/thumbnails}
   ```
6. **Set Initial Permissions:**
   ```bash
   sudo chown -R www-data:www-data /var/www/alpha-garage/shared/storage
   sudo chmod -R 0750 /var/www/alpha-garage/shared/storage
   sudo chmod 0700 /var/www/alpha-garage/shared/storage/private
   ```

### Phase 3: Database Provisioning
7. **Run MySQL Secure Installation:** `sudo mysql_secure_installation`.
8. **Create Database & Accounts:**
   Execute MySQL root console commands detailed in Section 6.1 (create `garage_management_system`, create `garage_app`, create `garage_migrator`).

### Phase 4: Application Code Deployment
9. **Clone / Upload Source:**
   ```bash
   TIMESTAMP=$(date +%Y%m%d_%H%M%S)
   RELEASE_DIR="/var/www/alpha-garage/releases/${TIMESTAMP}"
   sudo git clone --branch main https://github.com/organization/ALPHA-GARAGE.git ${RELEASE_DIR}
   ```
10. **Link Persistent Storage:**
    ```bash
    sudo rm -rf ${RELEASE_DIR}/AutoPro_Garage-main/storage
    sudo ln -s /var/www/alpha-garage/shared/storage ${RELEASE_DIR}/AutoPro_Garage-main/storage
    ```
11. **Configure Production Secrets:**
    Populate `/etc/apache2/sites-available/alpha-garage.conf` with real environment variables (Section 5.2).

### Phase 5: Database Migrations & Initial Admin
12. **Import Baseline Schema:**
    ```bash
    cd ${RELEASE_DIR}/AutoPro_Garage-main
    mysql -u garage_migrator -p garage_management_system < database/database.sql
    ```
13. **Run Migration Runner:**
    ```bash
    GMS_DB_USER=garage_migrator GMS_DB_PASS=MIGRATOR_SECRET php database/migrate.php
    ```
14. **Bootstrap Administrator:**
    ```bash
    php database/recover_admin.php
    ```

### Phase 6: Web Server Configuration & SSL
15. **Enable Apache Modules & Site:**
    ```bash
    sudo a2enmod rewrite headers ssl expires
    sudo a2ensite alpha-garage.conf
    sudo a2dissite 000-default.conf
    ```
16. **Obtain SSL Certificate:**
    ```bash
    sudo certbot --apache -d garage.example.com
    ```
17. **Atomic Symlink Activation:**
    ```bash
    sudo ln -sfn ${RELEASE_DIR}/AutoPro_Garage-main /var/www/alpha-garage/current
    sudo systemctl restart apache2
    ```

### Phase 7: Post-Deployment Smoke Verification
18. Execute the automated smoke test suite (Section 11).

---

## 10. Rollback Plan

If critical regressions or runtime blockers are encountered post-deployment, execute this rollback procedure:

### Zero-Downtime Application Rollback
1. **Identify Previous Release:**
   ```bash
   PREVIOUS_RELEASE=$(ls -td /var/www/alpha-garage/releases/* | sed -n '2p')
   ```
2. **Switch Symlink Atomically:**
   ```bash
   sudo ln -sfn ${PREVIOUS_RELEASE}/AutoPro_Garage-main /var/www/alpha-garage/current
   sudo systemctl reload apache2
   ```
3. **Verify Restored Application:**
   Navigate to `https://garage.example.com` and verify login and dashboard accessibility.

### Database Rollback (If Migration Introduced Incompatible DDL)
1. **Place App in Maintenance Mode:**
   ```bash
   touch /var/www/alpha-garage/current/public/maintenance.flag
   ```
2. **Restore Pre-Deployment Snapshot:**
   ```bash
   mysql -u garage_migrator -p garage_management_system < /var/www/alpha-garage/backups/pre_deploy_snapshot.sql
   ```
3. **Re-verify Hash-Chain:**
   ```bash
   php -r "require '/var/www/alpha-garage/current/app/bootstrap.php'; var_dump(AuditLogger::verifyChain());"
   ```
4. **Remove Maintenance Flag:**
   ```bash
   rm /var/www/alpha-garage/current/public/maintenance.flag
   ```

*Safety Rule: Never delete the active release directory during a rollback; re-point the symlink first to preserve diagnostic logs.*

---

## 11. Production Smoke Test Plan

Execute this verification checklist immediately following deployment:

| Category | Verification Item | Verification Command / URL | Expected Outcome | Status |
| :--- | :--- | :--- | :--- | :---: |
| **HTTP / SSL** | HTTPS Redirection | `curl -I http://garage.example.com` | HTTP 301/302 Redirect to `https://` | [ ] |
| **Security Headers** | CSP, X-Frame-Options | `curl -I https://garage.example.com` | `X-Frame-Options: DENY`, CSP present | [ ] |
| **Public Site** | Homepage Load | `GET /` | HTTP 200, Luxury brand styling renders | [ ] |
| **Bilingual Toggle** | Amharic Mode | `GET /?lang=am` | `<html lang="am">`, Ethiopic text visible | [ ] |
| **Authentication** | Admin Login | `POST /login.php` | HTTP 302 to `dashboard.php`, session established | [ ] |
| **Session Hardening** | Cookie Flags | Inspect browser cookies | `GMSSESSID` has `Secure`, `HttpOnly`, `SameSite=Lax` | [ ] |
| **Role Dashboard** | Admin Command Center | `GET /dashboard.php` | Financial charts, KPIs, zero PHP errors | [ ] |
| **Role Dashboard** | Receptionist | Login as `receptionist` | Service schedule renders, no revenue leak | [ ] |
| **Role Dashboard** | Mechanic | Login as `mechanic` | Workstation queue renders, financial tabs hidden | [ ] |
| **Role Dashboard** | Customer Portal | Login as customer | VIP portal renders, vehicles listed | [ ] |
| **Audit Chain** | Cryptographic Chain | CLI diagnostic | `All 195+ audit records are intact.` | [ ] |
| **Error Leakage** | 404 & 500 Handling | `GET /non-existent-page.php` | Clean branded 404 page, no file paths leaked | [ ] |
| **Print Engine** | Print Stylesheet | Print Preview on invoice | Monochrome clean layout, sidebars hidden | [ ] |

---

## 12. Monitoring & Ongoing Operations

Implement the following non-invasive operational monitoring:

### 12.1 Log Monitoring & Alerting
- **Apache Error Log (`/var/log/apache2/alpha_garage_error.log`):** Monitored for segmentation faults or configuration warnings.
- **Application Log (`storage/logs/app.log`):** Monitored for `AUDIT WRITE FAILED`, `DatabaseException`, or authentication anomalies.
- **Logrotate Configuration (`/etc/logrotate.d/alpha-garage`):**
  ```text
  /var/www/alpha-garage/shared/storage/logs/*.log {
      daily
      missingok
      rotate 14
      compress
      delaycompress
      notifempty
      create 0640 www-data www-data
  }
  ```

### 12.2 Uptime & Certificate Monitoring
- **External Ping / HTTP Check:** Free tier UptimeRobot or Better Uptime pinging `https://garage.example.com/` every 5 minutes.
- **SSL Certificate Expiry Alerts:** Alerts triggered 30 days and 7 days prior to certificate expiration.

### 12.3 System Resource Thresholds
- **Disk Usage:** Alert when root or storage partition exceeds 80% capacity.
- **CPU / RAM:** Alert if sustained CPU > 90% for > 15 minutes or available RAM < 500 MB.

---

## 13. Deployment Risk Register

| Risk ID | Risk Description | Severity | Likelihood | Mitigation Strategy |
| :--- | :--- | :---: | :---: | :--- |
| **R-01** | `DocumentRoot` configured incorrectly, exposing `app/` and `storage/`. | **HIGH** | Low | Virtual host explicitly maps to `/public`. Test by requesting `https://garage.example.com/app/bootstrap.php` (must return 403 or 404). |
| **R-02** | Master encryption key lost during server re-provisioning. | **HIGH** | Low | Store a copy of `storage/private/encryption.key` in an isolated administrative password vault. Without this key, encrypted records cannot be read. |
| **R-03** | File permission issues preventing uploads or logs. | **MEDIUM** | Medium | Explicitly execute `chown -R www-data:www-data storage/` during Phase 2 of deployment runbook. |
| **R-04** | Email delivery failure for password resets. | **LOW** | Medium | Verify outbound SMTP credentials using Mailgun / SendGrid before opening public registration. |
| **R-05** | Rate-limit flat files filling disk. | **LOW** | Low | Implement nightly cron cleanup deleting rate limit files older than 48 hours. |

---

## 14. Pre-Deployment Sign-Off Checklist

Before initiating the live deployment commands, ensure all prerequisites are verified:

- [ ] Target VPS provisioned and updated (`apt update && apt upgrade`).
- [ ] Dedicated MySQL database user credentials generated (`garage_app` and `garage_migrator`).
- [ ] Master 256-bit encryption key generated and stored in a secure password vault.
- [ ] Outbound SMTP credentials verified and SPF/DKIM DNS records verified.
- [ ] Domain DNS A-record pointed to the VPS public IP address.
- [ ] Pre-deployment database backup generated (if migrating existing data).
- [ ] Administrator contact information prepared for initial account creation.

---

## 15. Post-Deployment Sign-Off Checklist

Following live deployment, verify that all systems are operational:

- [ ] Web server DocumentRoot verified pointing strictly to `public/`.
- [ ] Direct HTTP requests to `/app/` and `/database/` return HTTP 403 Forbidden.
- [ ] HTTPS active with green padlock (A+ rating on SSL Labs).
- [ ] Initial administrator successfully created via `recover_admin.php`.
- [ ] Admin login successful; dashboard renders all analytics without errors.
- [ ] English and Amharic language toggling verified across all pages.
- [ ] Automated daily backup script installed and verified via dry run.
- [ ] Linear SHA-256 audit chain verified intact via `AuditLogger::verifyChain()`.
- [ ] Monitoring and uptime checks activated.

---

**End of Production Deployment Preparation & Operations Plan.**
