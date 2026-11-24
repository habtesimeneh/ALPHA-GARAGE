#!/usr/bin/env bash
# Alpha Garage production backup utility:
# 1. Database dump -> gzip -> AES-256/GPG encrypt -> SHA-256 verification
# 2. Storage uploads (payment proofs & documents) -> tar.gz -> encrypt -> SHA-256 verification
# 3. Automated retention rotation
#
# Credentials are read from a MySQL defaults file outside the repository
# (default: /etc/mysql/backup.cnf or ~/.my.cnf). Never commit credentials.
#
# Required for encryption (choose one):
#   GMS_BACKUP_GPG_RECIPIENT   e-mail/key id of an offline GPG public key (recommended)
#   GMS_BACKUP_PASSPHRASE_FILE path to a passphrase file readable only by the backup user
#
# Optional:
#   GMS_BACKUP_DB              database name            (default: garage_management_system)
#   GMS_BACKUP_DIR             output directory         (default: ~/alpha-garage-backups or /var/backups/alpha-garage)
#   GMS_BACKUP_RETENTION_DAYS  local retention in days  (default: 30)
#   GMS_MYSQL_DEFAULTS         defaults file path       (default: /etc/mysql/backup.cnf or ~/.my.cnf)
#   GMS_MYSQLDUMP              mysqldump executable
#   GMS_UPLOADS_DIR            uploads directory path   (default: auto-detected)
#   GMS_ALLOW_UNENCRYPTED      set to 1 for unencrypted local dev/staging testing (default: 0)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DB_NAME="${GMS_BACKUP_DB:-garage_management_system}"
RETENTION_DAYS="${GMS_BACKUP_RETENTION_DAYS:-30}"
GPG_RECIPIENT="${GMS_BACKUP_GPG_RECIPIENT:-}"
PASSPHRASE_FILE="${GMS_BACKUP_PASSPHRASE_FILE:-}"
ALLOW_UNENCRYPTED="${GMS_ALLOW_UNENCRYPTED:-0}"

# 1. Determine Output Directory
OUT_DIR="${GMS_BACKUP_DIR:-}"
if [ -z "$OUT_DIR" ]; then
    if [ -d "/var/backups" ] && [ -w "/var/backups" ]; then
        OUT_DIR="/var/backups/alpha-garage"
    else
        OUT_DIR="$HOME/alpha-garage-backups"
    fi
fi

# 2. Determine MySQL Defaults File
DEFAULTS="${GMS_MYSQL_DEFAULTS:-}"
if [ -z "$DEFAULTS" ]; then
    if [ -r "/etc/mysql/backup.cnf" ]; then
        DEFAULTS="/etc/mysql/backup.cnf"
    elif [ -r "$HOME/.my.cnf" ]; then
        DEFAULTS="$HOME/.my.cnf"
    fi
fi

# 3. Determine mysqldump and php binaries
DUMP_BIN="${GMS_MYSQLDUMP:-}"
if [ -z "$DUMP_BIN" ]; then
    if [ -x "C:/MAMP/bin/mysql/bin/mysqldump.exe" ]; then
        DUMP_BIN="C:/MAMP/bin/mysql/bin/mysqldump.exe"
    else
        DUMP_BIN="$(command -v mysqldump || true)"
    fi
fi

PHP_BIN="${GMS_PHP:-}"
if [ -z "$PHP_BIN" ]; then
    if [ -x "C:/MAMP/bin/php/php8.3.1/php.exe" ]; then
        PHP_BIN="C:/MAMP/bin/php/php8.3.1/php.exe"
    else
        PHP_BIN="$(command -v php || true)"
    fi
fi

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ -n "$DUMP_BIN" ] || fail "mysqldump not found; set GMS_MYSQLDUMP."
[ -n "$DEFAULTS" ] && [ -r "$DEFAULTS" ] || fail "MySQL defaults file not readable: ${DEFAULTS:-none specified}"
[ "$DB_NAME" != "garage_management_system_test" ] || fail "Refusing to back up a test database with the production script."
case "$DB_NAME" in
    *[!A-Za-z0-9_]*) fail "Unsafe database name: $DB_NAME" ;;
esac

# 4. Determine Encryption Mode
if [ -n "$GPG_RECIPIENT" ]; then
    command -v gpg >/dev/null 2>&1 || fail "gpg is required when GMS_BACKUP_GPG_RECIPIENT is set."
    ENCRYPT_MODE="gpg"
elif [ -n "$PASSPHRASE_FILE" ]; then
    [ -r "$PASSPHRASE_FILE" ] || fail "Passphrase file not readable: $PASSPHRASE_FILE"
    if [ -n "$PHP_BIN" ] && [ -f "$SCRIPT_DIR/backup_crypto.php" ]; then
        ENCRYPT_MODE="gcm"
    else
        command -v openssl >/dev/null 2>&1 || fail "openssl or php is required for passphrase encryption."
        ENCRYPT_MODE="openssl"
    fi
elif [ "$ALLOW_UNENCRYPTED" = "1" ]; then
    ENCRYPT_MODE="none"
else
    fail "No encryption configured. Set GMS_BACKUP_GPG_RECIPIENT or GMS_BACKUP_PASSPHRASE_FILE (or GMS_ALLOW_UNENCRYPTED=1 for local development)."
fi

umask 077
mkdir -p "$OUT_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
BASE="$OUT_DIR/${DB_NAME}_${STAMP}"

encrypt_and_checksum() {
    local src="$1"
    local enc=""
    if [ "$ENCRYPT_MODE" = "gpg" ]; then
        enc="${src}.gpg"
        gpg --batch --yes --trust-model always --recipient "$GPG_RECIPIENT" \
            --output "$enc" --encrypt "$src"
        rm -f "$src"
    elif [ "$ENCRYPT_MODE" = "gcm" ]; then
        enc="${src}.enc"
        "$PHP_BIN" "$SCRIPT_DIR/backup_crypto.php" encrypt "$src" "$enc" "$PASSPHRASE_FILE"
        rm -f "$src"
    elif [ "$ENCRYPT_MODE" = "openssl" ]; then
        enc="${src}.enc"
        openssl enc -aes-256-cbc -pbkdf2 -iter 100000 -salt -in "$src" -out "$enc" \
            -pass "file:$PASSPHRASE_FILE"
        rm -f "$src"
        openssl dgst -sha256 -hmac "$(cat "$PASSPHRASE_FILE")" "$enc" | awk '{print $NF}' > "${enc}.hmac"
    else
        enc="$src"
    fi
    ( cd "$OUT_DIR" && sha256sum "$(basename "$enc")" > "$(basename "$enc").sha256" )
    echo "$enc"
}

# 5. Execute Database Dump
SQL_GZ="${BASE}.sql.gz"
"$DUMP_BIN" \
    --defaults-extra-file="$DEFAULTS" \
    --single-transaction \
    --quick \
    --routines \
    --triggers \
    --events \
    --hex-blob \
    --default-character-set=utf8mb4 \
    "$DB_NAME" | gzip -9 > "$SQL_GZ"

gzip -t "$SQL_GZ" || fail "gzip integrity check failed for $SQL_GZ"

# A dump must never carry CREATE DATABASE/USE: restoring such a file into the
# wrong database silently targets the database named inside it.
if gzip -dc "$SQL_GZ" | grep -qE '^(USE |CREATE DATABASE)'; then
    rm -f "$SQL_GZ"
    fail "Dump contains CREATE DATABASE/USE statements; refusing to keep it."
fi

ENC_DB_FILE="$(encrypt_and_checksum "$SQL_GZ")"
SIZE_DB="$(wc -c < "$ENC_DB_FILE" | tr -d ' ')"
printf '%s database backup ok: %s (%s bytes, %s)\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$ENC_DB_FILE" "$SIZE_DB" "$ENCRYPT_MODE" >> "$OUT_DIR/backup.log"
printf 'Database backup written: %s\n' "$ENC_DB_FILE"

# 6. Execute Uploads & Payment Proofs Archive
UPLOADS_DIR="${GMS_UPLOADS_DIR:-}"
if [ -z "$UPLOADS_DIR" ]; then
    if [ -d "/var/www/alpha-garage/shared/storage/uploads" ]; then
        UPLOADS_DIR="/var/www/alpha-garage/shared/storage/uploads"
    elif [ -d "$SCRIPT_DIR/../storage/uploads" ]; then
        UPLOADS_DIR="$SCRIPT_DIR/../storage/uploads"
    fi
fi

if [ -n "$UPLOADS_DIR" ] && [ -d "$UPLOADS_DIR" ]; then
    UPLOADS_TAR="${BASE}_uploads.tar.gz"
    UPLOADS_BASE="$(basename "$UPLOADS_TAR")"
    ( cd "$OUT_DIR" && tar -czf "$UPLOADS_BASE" -C "$(dirname "$UPLOADS_DIR")" "$(basename "$UPLOADS_DIR")" )
    gzip -t "$UPLOADS_TAR" || fail "gzip integrity check failed for $UPLOADS_TAR"
    ENC_UPLOADS_FILE="$(encrypt_and_checksum "$UPLOADS_TAR")"
    SIZE_UPLOADS="$(wc -c < "$ENC_UPLOADS_FILE" | tr -d ' ')"
    printf '%s uploads backup ok: %s (%s bytes, %s)\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$ENC_UPLOADS_FILE" "$SIZE_UPLOADS" "$ENCRYPT_MODE" >> "$OUT_DIR/backup.log"
    printf 'Uploads backup written: %s\n' "$ENC_UPLOADS_FILE"
fi

# 7. Retention Rotation
find "$OUT_DIR" -maxdepth 1 -type f \( -name "${DB_NAME}_*.gpg" -o -name "${DB_NAME}_*.enc" -o -name "${DB_NAME}_*.gz" -o -name "${DB_NAME}_*.sha256" \) \
    -mtime +"$RETENTION_DAYS" -print -delete >> "$OUT_DIR/backup.log" 2>&1 || true

printf 'ALPHA GARAGE Backup completed successfully at %s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
exit 0
