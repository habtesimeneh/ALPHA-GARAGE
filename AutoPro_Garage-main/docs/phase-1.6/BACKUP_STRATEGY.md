# Alpha Garage — Backup Strategy (Phase 1.6)

Status: implemented and verified locally on 2026-11-09. Off-machine replication and the
production schedule require owner infrastructure and are listed as owner actions.

## 1. What exists today

| Item | Finding |
|---|---|
| Automated backup job | **None before Phase 1.6.** Only ad-hoc manual dumps taken during earlier phases. |
| Backup script | `scripts/backup_database.sh` (new): dump → gzip → encrypt → SHA-256 → retention. |
| Tested output | `garage_management_system_20261109_082201.sql.gz.enc`, 11,824 bytes, SHA-256 verified, 29 `CREATE TABLE` statements. |
| Encryption at rest | Required by the script; refuses to run without GPG recipient or passphrase file. |
| Credentials | Read from a MySQL defaults file outside the repository (`~/.my.cnf`), never embedded. |
| Web exposure | Backups are written outside the web root; `storage/` is also denied by Apache. |
| Restore verification | Decrypt + gzip integrity + schema/data restore into a `_test` database (see §5). |

## 2. Daily job (production)

Schedule once per day, after the close of business, using an account that can read the
application database but is not the web server user.

```bash
GMS_BACKUP_DB=garage_management_system \
GMS_BACKUP_DIR=/srv/backups/garage \
GMS_BACKUP_RETENTION_DAYS=30 \
GMS_BACKUP_GPG_RECIPIENT=backups@example.com \
GMS_MYSQL_DEFAULTS=/etc/alpha-garage/backup.cnf \
/opt/alpha-garage/scripts/backup_database.sh
```

Windows / MAMP equivalent (Task Scheduler, daily):

```
Program:   C:\Program Files\Git\bin\bash.exe
Arguments: -lc "cd /c/MAMP/htdocs/AutoPro_Garage-main/AutoPro_Garage-main && GMS_BACKUP_GPG_RECIPIENT=... GMS_MYSQL_DEFAULTS=... bash scripts/backup_database.sh"
Start in:  C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main
```

Requirements:

- `GMS_BACKUP_GPG_RECIPIENT` — recommended. Use an **offline-generated GPG key pair**; the
  host only holds the public key, so a compromised server cannot decrypt old backups.
- `GMS_BACKUP_PASSPHRASE_FILE` — fallback only, for hosts without GPG. The passphrase file
  must live outside the repository and be readable only by the backup account. This is
  weaker: an attacker who gets the host gets the key.
- `GMS_MYSQL_DEFAULTS` — defaults file with `[client]` credentials; mode `0600`
  (Windows: ACL restricted to the backup user, SYSTEM and Administrators).
- The dump uses `--single-transaction --quick --routines --triggers --events --hex-blob`,
  so InnoDB tables stay consistent without locking the application.

## 3. Off-machine copy

Local backups do not survive disk loss, ransomware, or host compromise. After every
successful run, copy the `.gpg`/`.enc` file **and its `.sha256`** to an independent
location, for example:

- object storage with versioning and lifecycle rules (S3/B2/Azure Blob), or
- a backup server over SSH (`rsync -a --partial`), or
- an encrypted removable drive stored off-site.

Verify the copy (`sha256sum -c` at the destination) before relying on it. Keep at least
one generation on a different provider/region than the production host.

## 4. Retention

| Tier | Retention | Location |
|---|---|---|
| Daily | 30 days (configurable) | Local + off-machine |
| Monthly | 12 months | Off-machine only |
| Pre-release snapshot | 90 days after each production release | Off-machine only |

The script enforces only the local daily window (`GMS_BACKUP_RETENTION_DAYS`). Monthly and
pre-release retention belong to the off-machine storage lifecycle policy so they are not
deleted when the host is rebuilt.

## 5. Restore verification (run monthly and after any schema migration)

Never restore over the live database. Create or reuse an isolated database whose name ends
in `_test`:

> **Do not import `database/database.sql` into a named test database directly.** That file contains
> `CREATE DATABASE garage_management_system; USE garage_management_system;`, and the `USE` statement
> overrides a database name passed on the `mysql` command line. Strip those lines first
> (`sed -e '/^CREATE DATABASE/,/;/d' -e '/^USE /d' database/database.sql > test_schema.sql`) and
> confirm the result has no `USE`/`CREATE DATABASE` lines before importing.

```bash
openssl enc -d -aes-256-cbc -pbkdf2 -in backup.sql.gz.enc -pass file:/secure/pass | gzip -dc > /tmp/restore.sql
C:/MAMP/bin/mysql/bin/mysql.exe --defaults-extra-file=C:/secure/dba.cnf \
  -e "DROP DATABASE IF EXISTS garage_management_system_restore_test; CREATE DATABASE garage_management_system_restore_test CHARACTER SET utf8mb4;"
C:/MAMP/bin/mysql/bin/mysql.exe --defaults-extra-file=C:/secure/dba.cnf \
  garage_management_system_restore_test < /tmp/restore.sql
# Compare table counts and key row counts against the live database, then delete /tmp/restore.sql
```

Success criteria (all must hold):

1. `gzip -t` and the SHA-256 checksum pass.
2. The restore completes without errors.
3. Table count matches (29 tables at Phase 1.6).
4. `audit_logs` row count and `audit_chain_head` match the live database, and
   `AuditLogger::verifyChain()` reports `ok=true`.
5. Login with a known test account succeeds against the restored copy.
6. Delete the scratch dump from `/tmp`; it contains password hashes.

Phase 1.6 executed steps 1–4 against a dedicated `_test` database (see
`PHASE_1_6_EXECUTION_REPORT.md`); no live data was modified.

## 6. Secrets and permissions

- `storage/private/encryption.key` — 32 bytes, not tracked by Git, Apache returns **403**.
  Windows ACLs were tightened during Phase 1.6: inheritance removed, only the owner,
  `SYSTEM` and `BUILTIN\Administrators` retain access.
- `app/config/local.php` — database password, Git-ignored, Apache returns **403**, ACLs
  restricted as above.
- `storage/private/least_privilege.generated.sql` — contains live `IDENTIFIED BY` values,
  Git-ignored, Apache returns **403**. It must never be copied into `database/` or attached
  to a ticket.
- `storage/logs/`, `storage/mail_spool/`, `storage/rate_limits/`, `storage/backups/` — all
  Git-ignored and web-denied.
- Backups, dumps, `.my.cnf`, GPG private keys and passphrase files must never be committed
  to Git. `.gitignore` covers the in-repo storage paths; off-repo paths are the operator's
  responsibility.

## 7. Owner actions still required

1. Provision the off-machine destination and credentials.
2. Generate the backup GPG key pair offline and install only the public key on the host.
3. Enable the daily scheduled task/cron entry and confirm one successful run.
4. Set the monthly/pre-release lifecycle rules on the off-machine storage.
5. Run the first restore drill and sign off on the result.
