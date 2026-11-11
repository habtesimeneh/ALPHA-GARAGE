# ALPHA GARAGE — Phase 1.5 Execution Report

**Phase:** 1.5 — Release Verification & HTTPS Hardening
**Date:** 2026-11-09
**Project root:** `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main`
**Git root:** `C:\MAMP\htdocs\AutoPro_Garage-main` (branch `main`, commit `2fc66f0`, uncommitted Phase 1/1.5 work preserved)
**Runtime:** PHP 8.3.1, MySQL 5.7.24, Apache 2.4.33 (Win64), OpenSSL 1.0.2u, MAMP on Windows 10
**Result:** **COMPLETE** — all Section 14 acceptance criteria verified. Production HTTPS is documented and prepared, but intentionally not activated because no production domain, CA-issued certificate or live production server was available. See `PHASE_1_5_REMAINING_RISKS.md`.

---

## 1. Scope and approach

Phase 1.5 verified the Phase 1 work end to end instead of rebuilding the application. The live database was never used for destructive testing, no Phase 2 work was started, and no UI redesign was performed. Verification was evidence-driven: every claim below was produced by a command or HTTP request that was actually executed; the raw evidence bundle is kept outside the web root at:

`C:\Users\Temesgen\Documents\Qoder\2026-11-09\d7b76288\phase1_5-backups\20261109_024836\`

That bundle contains the verified backup, restore evidence, integration-run logs, schema/grants/trigger captures, certificate evidence and the original `httpd.conf`. It must never be committed or copied into `htdocs`.

## 2. Inspection performed

- Read all Phase 0 audit reports (`docs/phase-0-audit/`) and all Phase 1 reports (`docs/phase-1/`).
- Inspected `app/bootstrap.php`, `app/config/app.php`, `app/config/database.php`, `app/config/local.php` (without printing secrets), the autoloader, error handling and security headers.
- Inspected authentication and sessions (`AuthService`, remember-me, lockout), CSRF (`csrf_field`, `verify_csrf`), rate limiting (`RateLimiter`), database access (`app/models/Database.php`) and the migration runner (`database/migrate.php`).
- Inspected Apache configuration (`C:\MAMP\conf\apache\httpd.conf`, `mod_ssl` at line 152), the `.htaccess` files in the project root, `app/`, `database/`, `storage/`, and the installer/recovery scripts.
- Inspected `tests/run_tests.php` and the three unit suites.
- Checked Git status: working tree already contained uncommitted Phase 1 changes; none were reverted or overwritten.

## 3. Safety controls

| Control | Result |
|---|---|
| Backup created and restore-verified before database work | Yes — see `BACKUP_RESTORE_VERIFICATION.md` |
| Integration tests run only on a database whose name ends in `_test` | Yes — `garage_management_system_test` |
| Test runner safeguards inspected | Refuses unless `GMS_ALLOW_DESTRUCTIVE_TESTS=1`, DB name ends `_test`, host is loopback |
| Dedicated test account | `garage_test_app` with privileges only on the `_test`/restore databases (dropped after verification) |
| Live database used for destructive tests | No |
| Live business records modified to simulate tests | No |
| Live migrations applied during Phase 1.5 | None |
| Append-only triggers / audit chain preserved | Yes — verified intact |
| Credentials / dumps / certificates in web root or Git | No |
| Phase 2 started | No |

## 4. Work performed (by directive section)

### §3 — Full destructive integration testing
- Provisioned `garage_management_system_test` from a **sanitized schema** (schema only, no live rows), applied the nine pending/current migrations, and seeded disposable test users and business records.
- Ran `tests/run_tests.php` through a loopback-only PHP server (`127.0.0.1:19877`) with `GMS_DB_*` pointing at the test database.
- Final run: **118 passed, 0 failed, exit code 0** (every workflow requested by the directive). Full details in `INTEGRATION_TEST_RESULTS.md`.
- Test database, restore database and test account were dropped after evidence capture. Only `garage_management_system` remains.

### §4 — Customer end-to-end testing
- Registered a disposable customer in the test environment, logged in, exercised permitted pages and probed staff/admin URLs directly.
- Result: customer login succeeds; profile and permitted pages work; every staff/admin module and object URL returns 403; cross-customer object access is denied by `require_permission`/IDOR checks.
- Documented honestly: the application has **no customer portal and no user↔customer identity link**; the residential “Book a service” call to action currently leads to a staff-only page that returns 403 for customers. Details in `INTEGRATION_TEST_RESULTS.md` and `PHASE_1_5_REMAINING_RISKS.md`.

### §5 — Local MAMP HTTPS
- Enabled the already-present `mod_ssl`, added a guarded include to `httpd.conf`, and created a localhost virtual host on port 443 for the existing document root.
- Generated a 2048-bit RSA / SHA-256 certificate with SANs `DNS:localhost`, `IP:127.0.0.1`, `IP:::1`, valid 2026-11-09 → 2029-02-11, stored **outside** the web root (`C:\MAMP\conf\gms-ssl\`).
- Verified TLS 1.2 negotiation, TLS 1.0 rejection, secure/HttpOnly/SameSite=Lax session cookies over HTTPS, no mixed content, working assets, and the expected self-signed browser warning.
- Full setup, trust, production checklist and rollback: `HTTPS_CONFIGURATION_GUIDE.md`.

### §6 — Production HTTPS readiness
No production domain or CA certificate exists, so no production HTTPS claim is made. A provider-neutral Apache deployment checklist (redirect, TLS policy, cookie behaviour, trusted-proxy rules, HSTS policy, certificate automation) is included in `HTTPS_CONFIGURATION_GUIDE.md`.

### §7 — PHP / session / CSRF / token security
- Confirmed runtime behaviour: session ID regenerated on login and remember-me restore, strict mode, cookie-only sessions, HttpOnly always, `Secure` automatically on HTTPS, SameSite=Lax, 30-minute idle + 8-hour absolute limits, global CSRF on every POST, remember-me tokens stored as SHA-256 hashes and rotated on use, reset tokens hashed and single-use.
- `display_errors` off, `log_errors` on, `allow_url_include=Off`, debug disabled by configuration.
- Residual configuration gaps (`expose_php=On`, `session.gc_maxlifetime=1440` lower than the 1800-second idle timeout) are recorded in `PHASE_1_5_REMAINING_RISKS.md`.

### §8 — Public endpoints and rate limiting
- Register: 5 requests / 900 s / IP → 6th request **429** with `Retry-After: 899`.
- Contact: 5 requests / 600 s / IP → 6th request **429** with `Retry-After: 599`.
- Honeypot submissions receive the generic success response, create no rows and do not consume the limiter.
- Spoofed `X-Forwarded-For` / `X-Real-IP` are ignored (`client_ip()` uses `REMOTE_ADDR`).
- Fail-open on storage failure was exercised deliberately: the request proceeds and an `app_log` line is written. This is a documented availability-first decision, not a silent weakening.

### §9 — Installer security
- `public/setup.php` checks `storage/installed.lock` first and returns **404** once installed, including alternate casing/query URLs and over both HTTP and HTTPS.
- `database/recover_admin.php` returns 404 to web requests (CLI-only) and refuses to create an administrator when one already exists.
- The installer was **not deleted**: it is the only copy and is already inert. The production removal plan (deploy `public/` only, keep CLI recovery) is documented in the HTTPS guide.

### §10 — Browser and HTTP testing
- Guest landing, login, registration, contact, administrator dashboard, supplier, inventory, expenses, invoices/payments, profile and logout exercised over HTTP, and the main paths over HTTPS.
- No redirect loops, broken assets, mixed content, or console errors were observed. 403/404/419/429/500 expectations matched the documented behaviour.
- The self-signed certificate triggers the browser warning that is expected for a local dev certificate; trust steps are in the HTTPS guide.

### §11 — Backup and restore
- New post-Phase 1 dump verified (29 tables, `expenses` present, `job_cards.priority` present), restored into `garage_management_system_restore_test`, compared by row counts (0 differences), `CHECKSUM TABLE` (0 of 29 differences) and schema fingerprint (identical). Audit chain verified in the restored copy and in the live database. Details in `BACKUP_RESTORE_VERIFICATION.md`.

### §12 — Fixes and change control
Only defects that blocked Phase 1.5 verification or HTTPS hardening were fixed. See the table below. Each fix was followed by lint and the full integration suite.

## 5. Files changed in Phase 1.5

| File | Change | Reason | Security / compatibility impact |
|---|---|---|---|
| `app/bootstrap.php` | Guard `define('APP_ROOT', ...)` with `if (!defined(...))` (line 4) | CLI tools (`database/recover_admin.php`) define `APP_ROOT` before requiring bootstrap, which emitted a warning and could interfere with output | Behaviour unchanged under the web server; removes a warning-only defect. No security relaxation. |
| `app/services/AuthService.php` | Remember-me hydration now writes `$user['permissions']` / `$user['mechanic_id']` (lines 132–135); added `session_regenerate_id(true)` after remember-me restore (line 141) | The old code wrote those fields into an undefined `$row`; a remembered user could log in with missing permissions/mechanic binding. Restoring from a cookie without a fresh session ID allowed session fixation | Corrects dormant privilege-state bug and closes a fixation path. Cookie token format, hashing and rotation are unchanged. |
| `app/services/PasswordResetService.php` | Cast record ID to `(int)` (line 63) | `AuditLogger::logSafe()` declares `?int $recordId`; under `strict_types` the string argument threw a `TypeError`, turning password-reset requests into HTTP 500 | Reset requests now audit and complete normally. No change to token generation, hashing, expiry or single-use rules. |
| `public/register.php` | Cast `$uid` to `(int)` in the registration audit call (line 123) | Same strict-type defect as above; registration could fail after creating the account | Registration reliably completes and audits. |
| `public/profile.php` | Password-change redirect is role-aware: customers go to `index.php`, staff to `dashboard.php` (line 23) | Customers changing their password were redirected to a dashboard they are not allowed to open (403) | Redirect-only change; authorization is untouched and still enforced server-side. |
| `tests/run_tests.php` | Dashboard assertions updated to the current analytics widgets; password-reset login checks now use real HTTP logins instead of `AuthService::attempt()` in CLI | Assertions targeted an older dashboard; CLI output marks headers as sent, so `session_regenerate_id()` cannot run there — the test failed for an environmental reason, not an application defect | Tests now verify the same security properties through the real request path. No coverage was removed. |
| `.gitignore` | Ignore `storage/private/encryption.key`, `storage/mail_spool/`, `storage/rate_limits/` | Runtime encryption key, generated mail and rate-limit state must never be committed | Prevents accidental secret/operational-data commits. No runtime behaviour change. |
| `.htaccess` | Deny dotfiles (`<FilesMatch "^\."> Require all denied`) | `.gitignore` was downloadable (200) from inside `htdocs`; dotfiles can hold configuration or secrets | Returns 403 for dotfiles while public pages remain 200. This does **not** cover the parent-level `.git` directory, which is outside this file's control (see §11 and R1). |

Phase 1 changes (CSRF hardening, rate limiter, installer lock-first 404, dashboard/database fixes) remain in the working tree from the previous phase and were verified here, not re-implemented.

## 6. Configuration changes (outside the repository)

| Item | Location | Notes |
|---|---|---|
| SSL virtual host | `C:\MAMP\conf\apache\gms-ssl.conf` | `Listen 443`, `VirtualHost *:443` for `localhost`, TLS 1.2 only, no HSTS |
| Guarded include | `C:\MAMP\conf\apache\httpd.conf` lines 622–624 | `#BEGIN ALPHA GARAGE PHASE 1.5 LOCAL HTTPS — REMOVE TO ROLL BACK` … `#END …` |
| Original httpd.conf | `…\phase1_5-backups\20261109_024836\httpd.conf.phase1_5.orig` | SHA-256 `aba969939aba4793ee8b45b6f29ad6eddeff3bb5fc8da8dc5611984769105d19` |
| Certificate | `C:\MAMP\conf\gms-ssl\localhost-cert.pem` | Self-signed, SHA-256 fingerprint `65:93:6D:…:F6:9A` |
| Private key | `C:\MAMP\conf\gms-ssl\localhost-key.pem` | Outside the web root; development only |
| OpenSSL request config | `C:\MAMP\conf\gms-ssl\gms-openssl.cnf` | SAN definitions |
| PHP configuration | No change | Existing `C:\MAMP\conf\php8.3.1\php.ini` inspected; residual gaps documented |

No database schema change and no migration was applied to the live database during Phase 1.5. No production credentials were written to disk by this phase.

## 7. Tests and verification executed

| Check | Result |
|---|---|
| PHP syntax lint (`app/`, `public/`, `tests/`, `database/`) | **99 files, 0 failures** |
| Unit suites `auth_unit.php`, `inventory_unit.php`, `security_hardening_unit.php` | **3 / 3 passed** (exit 0 each) |
| Full integration suite (last run, run 5) | **118 passed, 0 failed, exit 0** |
| Live audit chain | **valid** — 40 records checked |
| Live business-table checksums vs. backup | **unchanged** (see §8) |
| HTTPS certificate hostname/IP | `-checkhost localhost` and `-checkip 127.0.0.1` pass |
| TLS 1.2 / TLS 1.0 | TLS 1.2 negotiates (`ECDHE-RSA-AES256-GCM-SHA384`); TLS 1.0 rejected with `protocol version` alert |
| Cookies | HTTP: `HttpOnly; SameSite=Lax`; HTTPS adds `secure` |
| Installer | HTTP/HTTPS/alternate URL → 404; CLI recovery refuses when an administrator exists |
| Dotfiles | `.gitignore` → 403 after the `.htaccess` fix; public pages still 200 |
| Public rate limits | 429 + `Retry-After` for register (899 s) and contact (599 s) |
| Browser checks | Landing, login, register, contact, dashboard, staff modules, profile, logout |

## 8. Live database integrity

Final fingerprint (`garage_management_system`, MySQL 5.7.24): 29 tables; `users=2`, `audit_logs=40`, `audit_chain_head.last_id=40`, chain verification `ok=true, checked=40`. No `customers`, `vehicles`, `job_cards`, `invoices`, `payments`, `expenses`, `spare_parts` or `inventory_transactions` rows were created by Phase 1.5. The live installation has no business data, but it does contain two administrator accounts — one of them is a pre-existing test artifact created before Phase 1.5, tracked as **R22** in `PHASE_1_5_REMAINING_RISKS.md`.

Backup-time vs. final `CHECKSUM TABLE` comparison: **26 of 29 tables identical**, including every business table (`users`, `garage_settings`, `services`, `suppliers`, `permissions`, `roles`, `role_permissions`, `schema_migrations`, and all empty business tables). The three differences are explained in §9:

| Table | Why it changed |
|---|---|
| `audit_logs` | One audit record (#40) written by the accidental live login probe |
| `audit_chain_head` | Chain head advanced from 39 to 40 as a consequence |
| `login_attempts` | One failed-attempt row written by the same probe |

## 9. Incident disclosure

While bringing up the isolated test server, the first instance was started **without** the test database environment overrides and therefore connected to `garage_management_system`. A single administrator login probe with a wrong password was sent before this was noticed.

- Effect: audit record **#40** (`login_failed`, username `admin`, IP `127.0.0.1`, `2026-11-09 13:56:05` Africa/Addis_Ababa) and **one** `login_attempts` row.
- No business table, user record, credential, customer, invoice, payment or inventory row was created, changed or deleted.
- The audit triggers correctly prevented deleting the record; the chain remains valid (`checked=40`).
- The server was stopped, restarted with explicit `GMS_DB_*` test overrides, and the live head 39→40 change is disclosed here and in `BACKUP_RESTORE_VERIFICATION.md`.
- No attempt was made to hide or rewrite the record. This is the only unexpected live write in the phase.

## 10. Acceptance criteria

| # | Criterion | Status |
|---|---|---|
| 1 | Full integration suite passes against a dedicated `_test` database | **PASS** — 118/118, exit 0 |
| 2 | Live database confirmed untouched by destructive tests | **PASS** — no destructive test ran against it |
| 3 | Customer authorization verified or documented as not implemented | **PASS** — verified restrictions; missing portal/linkage documented |
| 4 | Local MAMP HTTPS works with a correctly configured certificate | **PASS** — SAN correct, TLS 1.2, expected self-signed warning |
| 5 | HTTP-to-HTTPS redirect works where configured | **PASS (N/A locally)** — not enabled by design so HTTP fallback remains; production redirect documented |
| 6 | No mixed-content errors remain | **PASS** — all assets/forms same-origin over HTTPS |
| 7 | Session and remember-me cookie security verified | **PASS** — regeneration, rotation, Secure/HttpOnly/SameSite |
| 8 | Production HTTPS configuration documented | **PASS** — provider-neutral checklist supplied |
| 9 | Installer production-removal plan verified | **PASS** — lock-first 404, CLI-only recovery, removal plan documented |
| 10 | Post-Phase-1 backup and test restore succeed | **PASS** — row counts/checksums/schema identical |
| 11 | Audit-chain integrity remains valid | **PASS** — 40 records, `ok=true` |
| 12 | All changed PHP files pass syntax checks | **PASS** — 99 files, 0 failures |
| 13 | All existing safe unit suites pass | **PASS** — 3/3 |
| 14 | All documentation updated | **PASS** — this set of five documents |
| 15 | No live business data lost or modified unexpectedly | **PASS** — all business tables unchanged; the single failed-login probe is disclosed in §9 |

## 11. Limitations and non-blocking findings

1. **Production HTTPS is not active.** No domain, CA certificate or production server exists; nothing in this phase claims otherwise.
2. **`.git` is reachable through MAMP's document root.** `http://localhost/AutoPro_Garage-main/.git/config` returns 200 because the Git root is the parent of the project and `C:\MAMP\htdocs` is the docroot. The project's `.htaccess` cannot protect its parent. Remediation: serve only `public/` (or place the repository outside `htdocs`) and deny `\.git` at server level. Not changed here because it is a machine-level MAMP deployment choice; detailed in `PHASE_1_5_REMAINING_RISKS.md`.
3. **No customer portal / identity link.** Registration creates a `users` row and a `customers` row but does not link them; the booking CTA returns 403 for customers. Documented, not implemented.
4. **Forgot-password has no rate limit**, unlike register/contact. Recommended before production.
5. **Password-reset e-mails contain a root-relative URL** (`url()` builds a docroot-relative path). This works for local testing but must become an absolute `https://` URL before production.
6. **`expose_php=On`** advertises the PHP version via headers; set to `Off` in production.
7. **`session.gc_maxlifetime=1440`** is shorter than the 1800-second idle timeout; sessions can be garbage-collected before the intended idle expiry.
8. **Self-signed certificate** is a development certificate only; it is not publicly trusted and HSTS is deliberately not enabled locally.
9. **A pre-existing test administrator is still active in the live database** (`kilotest_auth_63e8ee04`, created before Phase 1.5, never used). It was not deleted because the directive forbids deleting existing users without approval; it is the top pre-production action (R22 in `PHASE_1_5_REMAINING_RISKS.md`).
10. **Password-reset mail falls back to a local spool when SMTP is not configured**, and the default sender domain is unrelated to Alpha Garage (R24).

## 12. Rollback

- **Apache / HTTPS:** restore `C:\MAMP\conf\apache\httpd.conf` from the recorded backup (SHA-256 above) or remove lines 622–624, delete `C:\MAMP\conf\apache\gms-ssl.conf`, optionally delete `C:\MAMP\conf\gms-ssl\`, then restart Apache. HTTP on port 80 is unaffected.
- **Code:** every Phase 1.5 change is confined to the eight files listed in §5; `git diff` shows the exact hunks. No schema migration needs reverting.
- **Database:** no live schema or business-data change was made, so no database rollback is required. The test databases and test account were already dropped.

## 13. Status

**Phase 1.5: COMPLETE.** All Section 14 criteria are satisfied for the local verification scope. The project is verified, locally HTTPS-capable, backed up and documented, and ready for the next phase. Before any network exposure, disable the pre-existing test administrator (R22) and remediate the `.git` docroot exposure (R1). Production HTTPS activation, password-reset delivery, scheduled backups and the remaining gaps are tracked as pre-production work in `PHASE_1_5_REMAINING_RISKS.md`.
