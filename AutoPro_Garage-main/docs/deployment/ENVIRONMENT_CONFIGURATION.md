# ALPHA GARAGE — ENVIRONMENT CONFIGURATION GUIDE
## APPLICATION CONFIGURATION, LEAST-PRIVILEGE CREDENTIALS & SECRET MANAGEMENT

**Application:** ALPHA GARAGE (`AutoPro_Garage-main`)  
**Architecture:** Non-committed secrets, environment-variable precedence, least-privilege database separation.

---

## 1. CONFIGURATION LOADING HIERARCHY

ALPHA GARAGE employs a layered configuration system designed for zero accidental secret commits:

```text
[ Operating System / Web Server Environment (getenv) ]
                       │ (Highest Precedence)
                       ▼
[ /var/www/alpha-garage/shared/.env / local.php ]
                       │ (Server-specific, git-ignored)
                       ▼
[ app/config/database.php & app/config/app.php ]
                       │ (Default fallbacks)
                       ▼
[ Application Runtime Execution ]
```

If neither environment variables nor `app/config/local.php` are configured with valid credentials, `app/config/database.php` immediately throws a `RuntimeException` and halts execution, preventing unauthenticated database fallback.

---

## 2. PRODUCTION ENVIRONMENT MATRIX (`.env.production`)

```ini
# =====================================================================
# ALPHA GARAGE — Production Environment Configuration Template
# Store at /var/www/alpha-garage/shared/.env with chmod 600 permissions.
# =====================================================================

# Application Identity & Debugging
GMS_NAME="ALPHA GARAGE"
GMS_DEBUG=false
GMS_TIMEZONE="Africa/Addis_Ababa"
GMS_APP_URL="https://garage.example.com"

# Web Runtime Database Credentials (Least Privilege: No DROP, No ALTER, No GRANT)
GMS_DB_HOST="127.0.0.1"
GMS_DB_PORT=3306
GMS_DB_NAME="garage_management_system"
GMS_DB_USER="garage_app"
GMS_DB_PASS="REPLACE_WITH_HIGH_ENTROPY_APP_PASSWORD"
GMS_DB_TZ="+03:00"

# Administrative CLI Migration Credentials (CLI-Only, Never exposed to Apache runtime)
GMS_MIGRATION_DB_USER="garage_migrator"
GMS_MIGRATION_DB_PASS="REPLACE_WITH_HIGH_ENTROPY_MIGRATOR_PASSWORD"

# Master Encryption Key (32 bytes / 64 hex characters for AES-256-GCM)
# If omitted, auto-generated and stored in storage/private/encryption.key
GMS_ENCRYPTION_KEY="REPLACE_WITH_64_HEX_CHARACTERS_FOR_AES_256"

# Outbound Mail (Required for Production Password Resets & Alerts)
SMTP_HOST="smtp.mailgun.org"
SMTP_PORT=587
SMTP_USERNAME="alerts@garage.example.com"
SMTP_PASSWORD="REPLACE_WITH_SMTP_PASSWORD"
SMTP_FROM_EMAIL="no-reply@garage.example.com"
SMTP_FROM_NAME="ALPHA GARAGE"
SMTP_ENCRYPTION="tls"

# Session & Security Limits
GMS_SESSION_LIFETIME=1800
GMS_LOCKOUT_MINUTES=15
```

---

## 3. SECRET ROTATION & LEAST PRIVILEGE PROTOCOLS

1. **Dual DB User Separation**:
   - `garage_app`: Restricted runtime account. Can only perform DML operations (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) on operational tables. Has zero DDL privileges (`CREATE`, `DROP`, `ALTER`). Engine-level triggers block updating immutable payment columns and audit logs.
   - `garage_migrator`: CLI-only migration account used strictly during deployments to apply forward migrations in `database/migrations/`.
2. **Master Encryption Key Protection**:
   - Resides in `storage/private/encryption.key`.
   - Covered by `storage/private/.htaccess` (`Require all denied`).
   - File permissions set to `0600` owned by `www-data:www-data`.
3. **No Secrets in Version Control**:
   - Both root and project `.gitignore` files exclude `.env`, `.env.*`, `app/config/local.php`, `*.key`, and `storage/backups/`.
