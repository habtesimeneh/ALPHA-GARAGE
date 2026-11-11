# Phase 1.6 — Pre-Production Security Cleanup: Execution Report

Date: 2026-11-09
Application: Alpha Garage — native PHP 8.3.1 / MySQL 5.7.24 / Apache 2.4.33 (MAMP, Windows)
Scope: security cleanup only. No framework, Composer/npm, database redesign, module rewrite or Phase 2 work.

---

## 1. Summary

| # | Task | Status |
|---|---|---|
| 1 | R22 administrator account investigation | **Complete — read-only; awaiting owner approval** |
| 2 | R1 `.git` / repository exposure | **Complete and verified locally** |
| 3 | Password reset security (absolute URL, generic response, rate limit, tokens) | **Complete and verified** |
| 4 | E-mail/SMTP identity and truthful delivery reporting | **Complete; SMTP delivery unverified (no credentials)** |
| 5 | PHP/HTTPS configuration | **Complete locally; production TLS/HSTS pending owner** |
| 6 | Backups and private files | **Script + strategy complete and tested; scheduling/off-machine pending owner** |
| 7 | Verification on a dedicated test database | **Complete — 118/118 integration, 3/3 unit suites, lint 99/99** |

One serious operational incident occurred during test provisioning and was fully recovered; it is disclosed in
§5. No administrator account was changed, no audit record was altered or deleted, and the live database was
returned byte-identically to its pre-Phase-1.6 state.

## 2. Safety baseline (taken before any change)

- Full logical dump `full_before_phase1_6.sql` (63,810 bytes, 29 tables, 3 triggers, 40 audit rows),
  SHA-256 `0b1546002d79278874e9eb2ebc74d4f54def2099b8cb4a65f00ccbf330499f54`, verified before use and before
  restore.
- Per-table `CHECKSUM TABLE` baseline (`checksums_before.txt`), grants, row counts, schema fingerprint,
  admin/login/audit extracts, Git status/diff/HEAD, and source-file hashes.
- MAMP `httpd.conf` and `php.ini` originals saved with SHA-256.
- Evidence bundle: `C:\Users\Temesgen\Documents\Qoder\2026-11-09\d7b76288\phase1_6-backups\20261109_080829\`.

## 3. Work completed

### 3.1 R22 — administrator investigation (no changes)

`kilotest_auth_63e8ee04` (ID 1) still exists and is enabled; `acount` (ID 3) is the intended owner account.
The test account has never authenticated: `last_login_at` NULL, 0 `login_attempts`, 0 audit events, no
remember token, no business rows. Full inventory, risk rating, options and the exact (not executed) SQL are in
`ADMIN_ACCOUNT_INVESTIGATION.md`. **No account was modified, disabled, renamed or deleted.**

### 3.2 R1 — repository exposure

MAMP Apache now denies `.git`/`.svn`/`.hg` directories (case-insensitive) and sensitive file patterns
(`.env`, `.sql`, `.log`, `.md`, `.example`, `.lock`, `.patch`, `.orig`, `.bak`, `.dump`, `.cnf`).
Verified over HTTP and HTTPS: `.git/config`, `.git/HEAD`, `%2egit`, `.GIT`, `database/least_privilege.sql`,
`app/config/local.php`, `.env.example`, `storage/private/*`, `storage/logs/*`, `docs/*.md` → **403**;
`public/login.php` → **200**. Rollback block is delimited in `httpd.conf`; `httpd -t` = Syntax OK.

### 3.3 Password reset

- Absolute URLs via new `app_url()` and `GMS_APP_URL`.
- 5 requests / 900 s per IP **and** per normalised e-mail; HTTP 429 + `Retry-After`.
- Generic responses preserved; 256-bit token, SHA-256 at rest, 30-minute expiry, single-use, prior tokens
  invalidated — unchanged.
- Verified: absolute link `http://127.0.0.1:19877/reset-password.php?token=<64 hex>` in the generated
  message; attempt 6 returned 429 with `Retry-After: 866`.

### 3.4 E-mail

- Removed the `smtp.gmail.com` and AutoPro sender defaults; sender is now validated and derived from
  `GMS_APP_URL`, `X-Mailer: Alpha-Garage-Mailer`, MIME boundary renamed.
- `send()` returns `ok=true` only on real SMTP/native delivery; the dev spool returns `ok=false` and states
  that nothing was delivered.
- Audit: `password_reset_requested` records `delivered: true|false`; failures create
  `password_reset_delivery_failed` (`action_result=failure`). No token or URL is stored.
- Configuration documented (`SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`,
  `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME`, `SMTP_ENCRYPTION`, `GMS_APP_URL`) with placeholders only.
- Verified without SMTP: `{"ok":false,"transport":"dev_spool","error":"Native mail() reported a delivery failure."}`
  and the matching audit event.

### 3.5 PHP / HTTPS

- `expose_php=Off`, `session.gc_maxlifetime=28800`; Apache restarted; values confirmed through Apache over
  HTTP and HTTPS.
- HTTPS cookie: `Secure; HttpOnly; SameSite=Lax`; HTTP cookie: `HttpOnly; SameSite=Lax`.
- No HSTS header is sent while the local certificate is self-signed. HTTP→HTTPS redirect is not forced
  locally; the production procedure remains in the Phase 1.5 HTTPS guide.

### 3.6 Backups and private files

- New `scripts/backup_database.sh`: mysqldump → gzip → GPG or OpenSSL AES-256 → SHA-256 sidecar → retention;
  refuses to run without encryption or with a test database name; rejects dumps containing `USE` or
  `CREATE DATABASE`.
- Verified with an encrypted dump of the live baseline and a restore drill into
  `garage_management_system_restore_test`: 29 tables, 2 users, 40 audit rows, 3 triggers, head hash
  `1518dc…930b`, `verifyChain()` 40/40.
- `BACKUP_STRATEGY.md` documents the daily job, off-machine copy, retention tiers, monthly restore drill and
  secret-handling rules.
- `storage/private/` and `app/config/local.php` ACL inheritance removed (owner/SYSTEM/Administrators only);
  app still reads them (login page 200). All key/log/SQL paths remain web-denied and untracked.

## 4. Verification results

| Check | Result |
|---|---|
| Integration suite against isolated `_test` DB | **118 passed, 0 failed** |
| Login/logout, RBAC, customer restrictions, IDOR, CSRF, injection/XSS | Passed within the 118 checks |
| Password reset end-to-end (generic response, hashed token, single use, new password works) | Passed |
| Reset rate limiting | 5 allowed, 6th HTTP 429 + `Retry-After` |
| Mail failure truthfulness | `ok=false`, failure audited |
| Audit append-only triggers and hash chain | Passed; test chain 149/149; live chain 40/40 after restore |
| Backup and restore drill | 29 tables, 3 triggers, 40/40 chain |
| Live DB unchanged by all tests | `CHECKSUM TABLE` identical to baseline (29/29) |
| HTTPS headers/cookies | Secure/HttpOnly/SameSite=Lax; no X-Powered-By; no HSTS |
| PHP lint | 99 files, 0 failures |
| Unit suites | `auth_unit`, `inventory_unit`, `security_hardening_unit` all passed |

**Could not be tested:** real SMTP delivery (no mailbox/credentials), production CA certificate/HSTS,
off-machine backup replication, and owner login (no owner password was requested or used).

## 5. Incident: accidental live import and recovery

**What happened:** to build the dedicated test database, `database/database.sql` was imported with
`mysql garage_management_system_test < database/database.sql`. The file begins with
`CREATE DATABASE garage_management_system; USE garage_management_system;`; the `USE` statement overrode the
command-line database. It dropped and recreated the live tables and aborted at `password_resets` (a table that
was not in its drop list), leaving the live database empty and missing `garage_settings`.

**Impact:** all live business tables, `users` and `audit_logs` were empty; the application was stopped
immediately. No external user accessed the system in that window (local-only deployment).

**Recovery:** the damaged state was dumped for evidence (`damage_state_after_accident.sql`), the owner
explicitly authorised a restore, Apache was stopped, and the verified pre-Phase-1.6 dump was imported into
`garage_management_system`. Verification:

- 29 tables, 3 triggers;
- row counts exactly match the baseline (users 2, audit_logs 40, garage_settings 10, permissions 40,
  role_permissions 85, roles 6, services 8, suppliers 4, login_attempts 22, migrations 9);
- `CHECKSUM TABLE` for all 29 tables is identical to the baseline;
- audit chain 40/40, head `last_id=40`, `last_hash=1518dc…930b`;
- both administrator accounts unchanged (`kilotest_auth_63e8ee04`, `acount`);
- application restarted, login page 200; a final checksum comparison after all tests is still identical.

**Prevention applied:** a destructive-use warning was added to `database/database.sql`; the test procedure
strips `CREATE DATABASE`/`USE` before import; the backup script refuses to keep dumps containing those
statements. The test database/account were dropped after verification.

## 6. Files changed (Phase 1.6)

Application repository:

| File | Change |
|---|---|
| `app/helpers/functions.php` | New `app_url()` absolute-URL helper (line 35) |
| `app/config/app.php` | `app_url` setting (line 11) |
| `app/config/local.php.example` | `app_url` and `mail` placeholders |
| `.env.example` | `GMS_APP_URL`, `SMTP_*` placeholders, Alpha Garage sender identity |
| `app/services/PasswordResetService.php` | Absolute reset URL; delivery outcome audit |
| `app/services/MailService.php` | Validated/configurable sender, truthful `ok`, escaping, branding |
| `public/forgot-password.php` | Dual rate limiting, HTTP 429/`Retry-After`, truthful alert |
| `database/database.sql` | Destructive-use warning header (no schema change) |
| `scripts/backup_database.sh` | New encrypted backup script |
| `docs/phase-1.6/*.md` | This report, admin investigation, security fixes, backup strategy, remaining risks |

Host configuration (outside the repository, originals preserved):

| File | Change |
|---|---|
| `C:\MAMP\conf\apache\httpd.conf` | Repository/sensitive-path denial block |
| `C:\MAMP\conf\php8.3.1\php.ini` | `expose_php=Off`, `session.gc_maxlifetime=28800` |
| `storage/private`, `app/config/local.php` | Windows ACL tightened |

## 7. Database changes

- No schema migration, table, column, role or permission change was introduced by Phase 1.6.
- One restore operation (incident recovery) returned the live database to its verified baseline.
- The dedicated test database and test login were created, used, and dropped; the restore-drill database was
  dropped. No audit row was deleted or modified at any point.

## 8. Failed or blocked items

| Item | Reason | Next step |
|---|---|---|
| SMTP live delivery test | No mailbox or credentials in this environment | Owner supplies sender credentials; test with a real reset |
| Deactivate R22 test admin | Explicitly blocked pending owner approval | Owner decision per `ADMIN_ACCOUNT_INVESTIGATION.md` §7.1 |
| Off-machine backup + schedule | No destination/credentials; owner infrastructure | Follow `BACKUP_STRATEGY.md` §7 |
| Production HTTPS/HSTS | No domain or CA certificate | Execute Phase 1.5 HTTPS guide |
| Owner-password rotation (R23) | Requires the owner | Rotate through `profile.php` |

## 9. Actions requiring owner approval

1. Deactivate, or otherwise handle, `kilotest_auth_63e8ee04` (R22).
2. Confirm `acount` is the only intended administrator and rotate its password.
3. Approve the production SMTP sender/domain and provide credentials out-of-band.
4. Approve the off-machine backup destination and GPG key ownership.
5. Approve the production TLS/HSTS rollout.

## 10. Next phase

Phase 2 has **not** been started.
