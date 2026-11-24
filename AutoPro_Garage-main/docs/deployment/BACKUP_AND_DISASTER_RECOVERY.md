# ALPHA GARAGE — BACKUP & DISASTER RECOVERY RUNBOOK
## BUSINESS CONTINUITY, BACKUP STRATEGY & DISASTER RESTORATION

**Application:** ALPHA GARAGE (`AutoPro_Garage-main`)  
**Target Environments:** Staging & Linux Production VPS (Ubuntu 24.04 LTS)  
**Recovery Objectives:**
- **Recovery Point Objective (RPO):** < 1 hour (Maximum acceptable data loss)
- **Recovery Time Objective (RTO):** < 30 minutes (Maximum acceptable downtime)

---

## 1. BACKUP TOPOLOGY & ASSET INVENTORY

ALPHA GARAGE business operations depend on three critical asset classes:

| Asset Class | Storage Location | Backup Mechanism | Frequency | Encryption & Protection |
| :--- | :--- | :--- | :---: | :--- |
| **Relational Database** | MySQL 8.0+ / `garage_management_system` | `mysqldump --single-transaction --routines --triggers --events` | Hourly / Daily | Authenticated AES-256-GCM or GPG encrypted, SHA-256 verified |
| **Customer Payment Proofs & Media** | `storage/uploads/` (including `payment_proofs/`) | Tarball archive with timestamp | Hourly / Daily | Authenticated AES-256-GCM or GPG encrypted, SHA-256 verified |
| **Application Configuration & Keys** | `/var/www/alpha-garage/shared/.env`, `storage/private/` | Offline encrypted vault backup | On modification | Restricted `chmod 600`, stored off-host |

---

## 2. AUTOMATED BACKUP ENGINE (`scripts/backup_database.sh`)

The authoritative backup script resides at `scripts/backup_database.sh`. It enforces:
- ACID-consistent InnoDB database snapshot without locking (`--single-transaction`).
- Routines, triggers, events, and hex-blob serialization for binary integrity.
- Automatic archive of all uploaded customer payment receipts and attachments.
- Zero plaintext retention: immediate Authenticated AES-256-GCM (with 128-bit authentication tag and PBKDF2-SHA256 key derivation) or GPG encryption.
- Automated SHA-256 checksum generation for both database and uploads archives.
- Automated retention rotation purging snapshots older than the configured threshold (default: 30 days).

### 2.1 Configuration Environment Variables

| Variable | Description | Default |
| :--- | :--- | :--- |
| `GMS_BACKUP_DB` | Target MySQL database name | `garage_management_system` |
| `GMS_BACKUP_DIR` | Destination directory for encrypted backups | `/var/backups/alpha-garage` |
| `GMS_MYSQL_DEFAULTS` | Path to MySQL options file with credentials | `/etc/mysql/backup.cnf` or `~/.my.cnf` |
| `GMS_BACKUP_PASSPHRASE_FILE` | Path to file containing encryption passphrase | Required (or `GMS_BACKUP_GPG_RECIPIENT`) |
| `GMS_BACKUP_GPG_RECIPIENT` | GPG recipient email/key ID for asymmetric encryption | Optional |
| `GMS_UPLOADS_DIR` | Absolute path to shared uploads directory | Auto-detected from shared storage |
| `GMS_BACKUP_RETENTION_DAYS` | Number of days to retain local backups | `30` |

### 2.2 Security Hardening & Prerequisites

1. **MySQL Backup Authentication (`/etc/mysql/backup.cnf`):**
```ini
[client]
user = garage_migrator
password = "SECURE_LONG_RANDOM_MIGRATOR_PASSWORD"
host = 127.0.0.1
```
Set strict root ownership:
```bash
sudo chmod 600 /etc/mysql/backup.cnf
```

2. **Encryption Secret (`/etc/alpha-garage/backup.pass`):**
```bash
sudo mkdir -p /etc/alpha-garage
openssl rand -base64 48 | sudo tee /etc/alpha-garage/backup.pass > /dev/null
sudo chmod 600 /etc/alpha-garage/backup.pass
```

### 2.3 Automated Cron Schedule (`/etc/cron.d/alpha-garage-backups`)

```cron
# Alpha Garage Automated Daily Database & Uploads Backup at 02:00 EAT
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
GMS_MYSQL_DEFAULTS=/etc/mysql/backup.cnf
GMS_BACKUP_PASSPHRASE_FILE=/etc/alpha-garage/backup.pass
GMS_STORAGE_DIR=/var/www/alpha-garage/shared/storage
GMS_BACKUP_DIR=/var/backups/alpha-garage

0 2 * * * root /var/www/alpha-garage/current/scripts/backup_database.sh >> /var/log/alpha_garage_backup.log 2>&1
```

---

## 3. RESTORATION PROCEDURE (DISASTER RECOVERY)

In the event of hardware failure, catastrophic data corruption, or staging rehearsal:

### Step 1: Verify Archive Integrity via SHA-256
```bash
cd /var/backups/alpha-garage
sha256sum -c garage_management_system_20261123_020000.sql.gz.enc.sha256
sha256sum -c garage_management_system_20261123_020000_uploads.tar.gz.enc.sha256
```
Must output `OK`. If verification fails, stop immediately and inspect earlier snapshots.

### Step 2: Decrypt & Authenticate Backup Artifacts
Using the authorized passphrase file and the fail-closed `scripts/backup_crypto.php` utility (AES-256-GCM authenticated decryption):
```bash
# Decrypt and authenticate database dump (fails closed if tag mismatch, tampered, or wrong passphrase)
php /var/www/alpha-garage/current/scripts/backup_crypto.php decrypt \
    garage_management_system_20261123_020000.sql.gz.enc \
    db_restore.sql.gz \
    /etc/alpha-garage/backup.pass

# Decrypt and authenticate uploads archive
php /var/www/alpha-garage/current/scripts/backup_crypto.php decrypt \
    garage_management_system_20261123_020000_uploads.tar.gz.enc \
    uploads_restore.tar.gz \
    /etc/alpha-garage/backup.pass
```
*Note: If the backup was encrypted with an OpenSSL fallback (Encrypt-then-MAC), verify the HMAC sidecar first (`openssl dgst -sha256 -hmac ...`) before decrypting.*

### Step 3: Stop Web Server Ingress (Prevent Dirty Writes)
```bash
sudo a2dissite alpha-garage.conf
sudo systemctl reload apache2
```

### Step 4: Recreate Clean Database Target
```bash
mysql -u root -p -e "
DROP DATABASE IF EXISTS garage_management_system;
CREATE DATABASE garage_management_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"
```

### Step 5: Restore Database Schema & Records
```bash
gzip -dc db_restore.sql.gz | mysql -u root -p garage_management_system
```

### Step 6: Restore Uploaded Payment Proofs & Storage
```bash
tar -xzf uploads_restore.tar.gz -C /var/www/alpha-garage/shared/storage/
sudo chown -R www-data:www-data /var/www/alpha-garage/shared/storage/uploads
sudo chmod -R 770 /var/www/alpha-garage/shared/storage/uploads
rm -f db_restore.sql.gz uploads_restore.tar.gz
```

### Step 7: Post-Restoration Invariant Audit
Verify database state and cryptographic audit chain consistency:
```bash
php -r '
require "/var/www/alpha-garage/current/app/bootstrap.php";
$pdo = \Database::getInstance();
echo "Users: " . $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() . PHP_EOL;
echo "Invoices: " . $pdo->query("SELECT COUNT(*) FROM invoices")->fetchColumn() . PHP_EOL;
echo "Payments: " . $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn() . PHP_EOL;
echo "Audit Head Hash: " . $pdo->query("SELECT last_hash FROM audit_chain_head LIMIT 1")->fetchColumn() . PHP_EOL;
'
```

### Step 8: Re-enable Web Service
```bash
sudo a2ensite alpha-garage.conf
sudo systemctl reload apache2
```
Verify site status at `https://garage.example.com/login.php`.
