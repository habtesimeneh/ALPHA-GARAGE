#!/usr/bin/env bash
# Alpha Garage database backup: dump -> gzip -> encrypt -> checksum -> retention.
#
# Credentials are read from a MySQL defaults file outside the repository
# (default: ~/.my.cnf). Never commit that file.
#
# Required for encryption (choose one):
#   GMS_BACKUP_GPG_RECIPIENT   e-mail/key id of an offline GPG public key (recommended)
#   GMS_BACKUP_PASSPHRASE_FILE path to a passphrase file readable only by the backup user
#
# Optional:
#   GMS_BACKUP_DB              database name            (default: garage_management_system)
#   GMS_BACKUP_DIR             output directory         (default: ~/alpha-garage-backups)
#   GMS_BACKUP_RETENTION_DAYS  local retention in days  (default: 30)
#   GMS_MYSQL_DEFAULTS         defaults file path       (default: ~/.my.cnf)
#   GMS_MYSQLDUMP              mysqldump executable
set -euo pipefail

DB_NAME="${GMS_BACKUP_DB:-garage_management_system}"
OUT_DIR="${GMS_BACKUP_DIR:-$HOME/alpha-garage-backups}"
DEFAULTS="${GMS_MYSQL_DEFAULTS:-$HOME/.my.cnf}"
RETENTION_DAYS="${GMS_BACKUP_RETENTION_DAYS:-30}"
GPG_RECIPIENT="${GMS_BACKUP_GPG_RECIPIENT:-}"
PASSPHRASE_FILE="${GMS_BACKUP_PASSPHRASE_FILE:-}"

DUMP_BIN="${GMS_MYSQLDUMP:-}"
if [ -z "$DUMP_BIN" ]; then
    if [ -x "C:/MAMP/bin/mysql/bin/mysqldump.exe" ]; then
        DUMP_BIN="C:/MAMP/bin/mysql/bin/mysqldump.exe"
    else
        DUMP_BIN="$(command -v mysqldump || true)"
    fi
fi

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[ -n "$DUMP_BIN" ] || fail "mysqldump not found; set GMS_MYSQLDUMP."
[ -r "$DEFAULTS" ] || fail "MySQL defaults file not readable: $DEFAULTS"
[ "$DB_NAME" != "garage_management_system_test" ] || fail "Refusing to back up a test database with the production script."
case "$DB_NAME" in
    *[!A-Za-z0-9_]*) fail "Unsafe database name: $DB_NAME" ;;
esac

if [ -n "$GPG_RECIPIENT" ]; then
    command -v gpg >/dev/null 2>&1 || fail "gpg is required when GMS_BACKUP_GPG_RECIPIENT is set."
    ENCRYPT_MODE="gpg"
elif [ -n "$PASSPHRASE_FILE" ]; then
    [ -r "$PASSPHRASE_FILE" ] || fail "Passphrase file not readable: $PASSPHRASE_FILE"
    command -v openssl >/dev/null 2>&1 || fail "openssl is required for passphrase encryption."
    ENCRYPT_MODE="openssl"
else
    fail "No encryption configured. Set GMS_BACKUP_GPG_RECIPIENT or GMS_BACKUP_PASSPHRASE_FILE."
fi

umask 077
mkdir -p "$OUT_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
BASE="$OUT_DIR/${DB_NAME}_${STAMP}"
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

if [ "$ENCRYPT_MODE" = "gpg" ]; then
    ENC_FILE="${SQL_GZ}.gpg"
    gpg --batch --yes --trust-model always --recipient "$GPG_RECIPIENT" \
        --output "$ENC_FILE" --encrypt "$SQL_GZ"
else
    ENC_FILE="${SQL_GZ}.enc"
    openssl enc -aes-256-cbc -pbkdf2 -salt -in "$SQL_GZ" -out "$ENC_FILE" \
        -pass "file:$PASSPHRASE_FILE"
fi

rm -f "$SQL_GZ"
( cd "$OUT_DIR" && sha256sum "$(basename "$ENC_FILE")" > "$(basename "$ENC_FILE").sha256" )

SIZE="$(wc -c < "$ENC_FILE" | tr -d ' ')"
printf '%s backup ok: %s (%s bytes, %s)\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$ENC_FILE" "$SIZE" "$ENCRYPT_MODE" >> "$OUT_DIR/backup.log"

find "$OUT_DIR" -maxdepth 1 -type f \( -name "${DB_NAME}_*.gpg" -o -name "${DB_NAME}_*.enc" -o -name "${DB_NAME}_*.sha256" \) \
    -mtime +"$RETENTION_DAYS" -print -delete >> "$OUT_DIR/backup.log" 2>&1

printf 'Backup written: %s\n' "$ENC_FILE"
printf 'Copy it off this machine (rsync/scp/object storage) and verify the copy before the next run.\n'
