# ALPHA GARAGE — UBUNTU 24.04 LTS DEPLOYMENT RUNBOOK
## PRODUCTION & STAGING LINUX INFRASTRUCTURE SETUP GUIDE

**Target OS:** Ubuntu Server 24.04 LTS (Noble Numbat)  
**Web Server:** Apache 2.4 + PHP 8.3-FPM (`mpm_event` + `proxy_fcgi`)  
**Database:** MySQL 8.0+ or MariaDB 10.11+  
**Application:** ALPHA GARAGE (`AutoPro_Garage-main`)  
**Release Tag:** `alpha-garage-v1.0.3-staging`  
**Archive Checksum (SHA-256):** Verified via external sidecar `alpha-garage-v1.0.3-staging.sha256`  
**Architecture:** Non-root execution (`www-data`), least-privilege database accounts, atomic deployments.

---

## 1. SERVER PREREQUISITES & SYSTEM HARDENING

### 1.1 System Update & Essential Tools
Execute as root (`sudo`):
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y curl wget git unzip zip ufw software-properties-common fail2ban
```

### 1.2 Firewall Hardening (UFW)
```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp comment 'SSH'
sudo ufw allow 80/tcp comment 'HTTP'
sudo ufw allow 443/tcp comment 'HTTPS'
sudo ufw enable
```

### 1.3 Timezone Configuration
Ensure server clock aligns with East Africa Time (EAT) / Ethiopian operational timezone:
```bash
sudo timedatectl set-timezone Africa/Addis_Ababa
timedatectl
```

---

## 2. WEB SERVER & PHP-FPM INSTALLATION

### 2.1 Install Apache 2.4 & PHP 8.3
```bash
sudo apt install -y apache2 \
    php8.3-fpm \
    php8.3-mysql \
    php8.3-mbstring \
    php8.3-openssl \
    php8.3-curl \
    php8.3-gd \
    php8.3-fileinfo \
    php8.3-zip \
    php8.3-xml \
    php8.3-intl
```

### 2.2 Configure Apache Modules
Disable prefork, enable event MPM and proxy FastCGI:
```bash
sudo a2dismod php8.3 mpm_prefork
sudo a2enmod mpm_event proxy_fcgi setenvif rewrite headers ssl
sudo a2enconf php8.3-fpm
sudo systemctl restart apache2 php8.3-fpm
```

### 2.3 PHP-FPM Hardening (`/etc/php/8.3/fpm/php.ini`)
Apply the following critical settings:
```ini
; Error handling & Information Disclosure prevention
display_errors = Off
display_startup_errors = Off
log_errors = On
error_log = /var/log/php8.3-fpm.log
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT

; Sizing & Upload limits for payment receipts/videos
upload_max_filesize = 15M
post_max_size = 18M
memory_limit = 256M
max_execution_time = 60

; Security & Session
session.use_strict_mode = 1
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = "Lax"
expose_php = Off
date.timezone = "Africa/Addis_Ababa"
```
Reload PHP-FPM:
```bash
sudo systemctl restart php8.3-fpm
```

---

## 3. DATABASE SERVER SETUP & USER INITIALIZATION

### 3.1 Install MySQL Server
```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation
```

### 3.2 Initialize Application Database & Users
Log in as root DBA:
```bash
sudo mysql -u root -p
```

Execute SQL commands:
```sql
CREATE DATABASE IF NOT EXISTS garage_management_system
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

-- 1. Create migration administrator account (CLI migrations & schema modifications only)
CREATE USER IF NOT EXISTS 'garage_migrator'@'localhost' IDENTIFIED BY 'SECURE_LONG_RANDOM_MIGRATOR_PASSWORD';

-- 2. Grant administrative privileges to migrator on the application database
GRANT ALL PRIVILEGES ON garage_management_system.* TO 'garage_migrator'@'localhost';

-- 3. Create application runtime account (table privileges granted in Section 5 via least_privilege.sql)
CREATE USER IF NOT EXISTS 'garage_app'@'localhost' IDENTIFIED BY 'SECURE_LONG_RANDOM_APP_PASSWORD';

FLUSH PRIVILEGES;
EXIT;
```

---

## 4. RELEASE EXTRACTION, DIRECTORY ARCHITECTURE & ENVIRONMENT CONFIGURATION

### 4.1 Deployment Layout Setup
Create atomic deployment directories:
```bash
sudo mkdir -p /var/www/alpha-garage/releases
sudo mkdir -p /var/www/alpha-garage/shared/storage/{logs,uploads,private,sessions,backups,mail_spool,rate_limits}
```

Layout Architecture:
```text
/var/www/alpha-garage/
├── releases/
│   └── alpha-garage-v1.0.3-staging/
│       ├── app/
│       ├── database/
│       ├── docs/
│       ├── documentation/
│       ├── public/          <-- APACHE DOCUMENT ROOT
│       │   ├── index.php
│       │   └── assets/
│       ├── scripts/
│       └── storage/ -> /var/www/alpha-garage/shared/storage/
├── shared/
│   ├── .env
│   ├── storage/
│   │   ├── logs/
│   │   ├── uploads/
│   │   ├── private/
│   │   ├── sessions/
│   │   ├── backups/
│   │   ├── mail_spool/
│   │   └── rate_limits/
│   └── .env
└── current -> /var/www/alpha-garage/releases/alpha-garage-v1.0.3-staging/
```

### 4.2 Transfer Archive & Verify Checksum
```bash
cd /tmp
# Verify archive integrity using the external detached SHA-256 sidecar file
sha256sum -c alpha-garage-v1.0.3-staging.sha256
```
Must output `alpha-garage-v1.0.3-staging.tar.gz: OK`.

### 4.3 Extract Archive & Create Current Symlink
```bash
sudo tar -xzf /tmp/alpha-garage-v1.0.3-staging.tar.gz -C /var/www/alpha-garage/releases/
sudo ln -sfn /var/www/alpha-garage/releases/alpha-garage-v1.0.3-staging /var/www/alpha-garage/current
```

### 4.4 Configure Shared Storage & Secrets (`.env`)
```bash
# Link shared storage into current release
sudo rm -rf /var/www/alpha-garage/current/storage
sudo ln -sfn /var/www/alpha-garage/shared/storage /var/www/alpha-garage/current/storage

# Initialize shared .env from example template
if [ ! -f /var/www/alpha-garage/shared/.env ]; then
    sudo cp /var/www/alpha-garage/current/.env.example /var/www/alpha-garage/shared/.env
fi
sudo ln -sfn /var/www/alpha-garage/shared/.env /var/www/alpha-garage/current/.env
```

Configure `/var/www/alpha-garage/shared/.env` with production secrets:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://garage.example.com

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=garage_management_system
DB_USER=garage_app
DB_PASS=SECURE_LONG_RANDOM_APP_PASSWORD

# Security encryption key (32 bytes hex)
APP_KEY=GENERATE_64_CHAR_HEX_KEY
```

### 4.5 Set Strict Filesystem Permissions
```bash
# Base ownership
sudo chown -R www-data:www-data /var/www/alpha-garage

# Standard directory permissions: 755
sudo find /var/www/alpha-garage -type d -exec chmod 755 {} \;

# Standard file permissions: 644
sudo find /var/www/alpha-garage -type f -exec chmod 644 {} \;

# Private storage & logs: strict 770
sudo chmod -R 770 /var/www/alpha-garage/shared/storage
sudo chmod 600 /var/www/alpha-garage/shared/.env
```

---

## 5. DATABASE SCHEMA IMPORT, FORWARD MIGRATIONS & LEAST PRIVILEGE CONFIGURATION

*Prerequisite: Release files extracted in `/var/www/alpha-garage/current` (Section 4).*

### 5.1 Import Base Schema
Imported using the `garage_migrator` administrative account:
```bash
mysql -u garage_migrator -p garage_management_system < /var/www/alpha-garage/current/database/database.sql
```

### 5.2 Execute Forward Migrations
Run the CLI migration runner using migrator credentials:
```bash
export GMS_MIGRATION_DB_USER="garage_migrator"
export GMS_MIGRATION_DB_PASS="SECURE_LONG_RANDOM_MIGRATOR_PASSWORD"
php /var/www/alpha-garage/current/database/migrate.php
```

### 5.3 Apply Least-Privilege Grants & Payment Immutability Triggers
Executed by root DBA to lock down runtime permissions and enforce append-only payment tables:
```bash
sudo mysql -u root -p < /var/www/alpha-garage/current/database/least_privilege.sql
```

### 5.4 Provision Initial Master Administrator
```bash
cd /var/www/alpha-garage/current
sudo -u www-data php scripts/create_admin.php \
    --username=admin \
    --email=admin@alphagarage.et \
    --name="Master Administrator" \
    --password="YOUR_STRONG_MASTER_PASSWORD"
```

---

## 6. APACHE VIRTUAL HOST CONFIGURATION

Create `/etc/apache2/sites-available/alpha-garage.conf`:

```apache
<VirtualHost *:80>
    ServerName garage.example.com
    ServerAdmin admin@alphagarage.et

    # Redirect all HTTP traffic to HTTPS
    RewriteEngine On
    RewriteCond %{HTTPS} off
    RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
</VirtualHost>

<VirtualHost *:443>
    ServerName garage.example.com
    ServerAdmin admin@alphagarage.et

    # Strict Web Root Isolation: Point ONLY to public/
    DocumentRoot /var/www/alpha-garage/current/public

    <Directory /var/www/alpha-garage/current/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Block access to VCS, sensitive files and environment configs
    <FilesMatch "^\.|\.(env|sql|log|sh|key)$">
        Require all denied
    </FilesMatch>

    # PHP-FPM Socket Integration
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>

    # SSL / TLS Configuration (Let's Encrypt / Certbot)
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/garage.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/garage.example.com/privkey.pem
    SSLProtocol all -SSLv3 -TLSv1 -TLSv1.1
    SSLCipherSuite ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384
    SSLHonorCipherOrder off
    SSLSessionTickets off

    # Security Headers
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains; preload"
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "same-origin"
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"

    # Logging
    ErrorLog ${APACHE_LOG_DIR}/alpha_garage_error.log
    CustomLog ${APACHE_LOG_DIR}/alpha_garage_access.log combined
</VirtualHost>
```

Enable site and reload Apache:
```bash
sudo a2ensite alpha-garage.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

---

## 7. AUTOMATED BACKUPS & DISASTER RECOVERY HARDENING (CRON)

### 7.1 Backup Credentials & Encryption Passphrase
1. **MySQL Backup Authentication (`/etc/mysql/backup.cnf`):**
```ini
[client]
user = garage_migrator
password = "SECURE_LONG_RANDOM_MIGRATOR_PASSWORD"
host = 127.0.0.1
```
Permissions:
```bash
sudo chmod 600 /etc/mysql/backup.cnf
```

2. **Authenticated Encryption Secret (`/etc/alpha-garage/backup.pass`):**
```bash
sudo mkdir -p /etc/alpha-garage
openssl rand -base64 48 | sudo tee /etc/alpha-garage/backup.pass > /dev/null
sudo chmod 600 /etc/alpha-garage/backup.pass
```

### 7.2 Crontab Schedule (`/etc/cron.d/alpha-garage-backups`)
```cron
# Alpha Garage Automated Daily Database & Uploads Backup at 02:00 EAT (AES-256-GCM authenticated)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
GMS_MYSQL_DEFAULTS=/etc/mysql/backup.cnf
GMS_BACKUP_PASSPHRASE_FILE=/etc/alpha-garage/backup.pass
GMS_STORAGE_DIR=/var/www/alpha-garage/shared/storage
GMS_BACKUP_DIR=/var/backups/alpha-garage

0 2 * * * root /var/www/alpha-garage/current/scripts/backup_database.sh >> /var/log/alpha_garage_backup.log 2>&1
```

---

## 8. DEPLOYMENT VERIFICATION CHECKLIST

1. [ ] Apache returns HTTP 200 on `https://garage.example.com/login.php`.
2. [ ] HTTP 301 redirects to HTTPS automatically.
3. [ ] Direct HTTP request to `https://garage.example.com/.env` returns **403 Forbidden**.
4. [ ] Direct HTTP request to `https://garage.example.com/storage/` returns **403 Forbidden**.
5. [ ] Direct HTTP request to `https://garage.example.com/app/` returns **404/403 Forbidden**.
6. [ ] Admin login successful; redirects to Admin Dashboard.
7. [ ] Customer appointment booking creates active job card.
8. [ ] Manual payment submission, proof streaming, and verification flow execute cleanly.
9. [ ] Audit log hash chaining updates without database trigger exceptions.

---

## 9. ATOMIC ROLLBACK PROCEDURE

In the event of an operational anomaly during a version cutover:
```bash
# Point symlink back to previous stable release
sudo ln -sfn /var/www/alpha-garage/releases/alpha-garage-v1.0.1-staging /var/www/alpha-garage/current
sudo systemctl reload apache2 php8.3-fpm
```
