# Phase 1.5 — Remaining Risks and Pre-Production Requirements

**Date:** 2026-11-09
**Scope:** issues that remain after Phase 1.5 verification. None of these blocked the Phase 1.5 acceptance criteria; all must be resolved or consciously accepted before public production use.

Severity key: **High** = fix before exposing the application publicly; **Medium** = fix before or shortly after launch; **Low** = hygiene/defence in depth; **Info** = documented behaviour or decision.

## Review summary (re-verified 2026-11-09)

All 21 original risks were re-checked against the current source and live state and remain accurate. The review found **seven additional gaps**, recorded as R22–R28 in §6:

| Priority | Risk | Why it matters |
|---|---|---|
| 1 | **R22** — active test administrator account in the live database | Full administrator access from a test artifact; no owner has used it, but it is still enabled |
| 2 | **R1** — `.git` reachable through the MAMP docroot | Repository metadata exposure on any deployment configured the same way |
| 3 | **R24** — password-reset mail silently spools when SMTP is unset | Users would never receive reset links in production |
| 4 | **R2** / **R14** — production HTTPS and supported runtime versions | Launch and long-term security prerequisites |
| 5 | **R28** — backups are manual only | No automated off-machine recovery point |

---

## 1. Deployment and hosting

### R1 — Git metadata is reachable through the local MAMP document root — **High**

`http://localhost/AutoPro_Garage-main/.git/config` and `.git/HEAD` return **200** because:

- the Git repository root is `C:\MAMP\htdocs\AutoPro_Garage-main`, one level **above** the project, and
- MAMP serves `C:\MAMP\htdocs` as its document root.

The project's own `.htaccess` cannot protect a parent directory. No live credentials or keys are tracked (`app/config/local.php`, certificates and dumps are untracked/ignored), but repository contents, commit history and remote configuration would be exposed on any server configured the same way.

**Fix before any non-local deployment:** serve only `public/` as the document root (application code outside it), or at minimum deny `\.git` at server level and move the repository outside the served tree. The production vhost template in `HTTPS_CONFIGURATION_GUIDE.md` §5.2 includes both controls. Locally, do not expose MAMP's `htdocs` beyond the machine's loopback/LAN trust boundary.

### R2 — Production HTTPS is prepared but not active — **High (production)**

No production domain, CA-issued certificate or live server exists. Local HTTPS uses a self-signed certificate that browsers do not trust.

**Before launch:** follow `HTTPS_CONFIGURATION_GUIDE.md` §5 — real DNS, trusted certificate with automated renewal, HTTPS vhost with `public/` as docroot, HTTP→HTTPS redirect (keeping the ACME challenge path reachable), then HSTS staged from a short `max-age`.

### R3 — TLS termination behind a proxy would leave cookies without `Secure` — **Medium**

The application sets `Secure` only when `$_SERVER['HTTPS']` is on and ignores `X-Forwarded-Proto`/`X-Forwarded-For` (deliberately, to prevent spoofing). If a load balancer or CDN terminates TLS:

- session and remember-me cookies will be sent without `Secure`;
- audit and rate-limit IPs would be the proxy address unless `mod_remoteip` is configured.

**Fix:** terminate TLS in Apache where possible. Otherwise restrict traffic to the proxy, configure `mod_remoteip` with explicit trusted proxy addresses, and make a reviewed application change for trusted HTTPS detection. Do not enable blind forwarded-header trust.

### R4 — Password-reset e-mail links are root-relative — **Medium (production)**

`PasswordResetService` builds `url('reset-password.php?token=…')`, which is a docroot-relative path (for example `/AutoPro_Garage-main/.../public/reset-password.php?token=…`). The token itself is strong (hashed, single-use, expiring), but most mail clients cannot resolve a relative link, and production links must be `https://`.

**Fix before production:** add an absolute application-URL setting (for example `GMS_APP_URL=https://YOUR_DOMAIN`) and build reset links from it, keeping the fallback for local development. Then re-run the password-reset workflow.

### R5 — Installer remains in the public directory (inert) — **Low**

`public/setup.php` is present but returns 404 because `storage/installed.lock` exists and users exist; `GMS_SETUP_TOKEN` adds a second gate. The only copy must not be deleted without a recovery plan.

**Production action:** move it out of the public web root after verified installation (retain it in the private deployment package). Keep `database/recover_admin.php` outside the web root — it is CLI-only and refuses to run while an administrator exists.

### R6 — Local Apache vhost mirrors MAMP's broad docroot — **Low (local only)**

The new `localhost:443` vhost reuses `DocumentRoot "C:\MAMP\htdocs"` and `Options Indexes` to avoid disturbing other local projects. This is acceptable for local verification but must not be copied to production, where the docroot must be the application's `public/` directory with `Options -Indexes`.

---

## 2. Application behaviour

### R7 — No customer portal and no user↔customer identity link — **Medium (product)**

Registration creates a `users` row (customer role) and a `customers` row (`created_by` = the new user), but no column links the login identity to the customer record. Customers can sign in, view the public landing page and change their password; they cannot use the "Book a service" call to action (`appointments/form.php` returns **403**) because the customer role intentionally has no staff permissions.

This is documented, not a regression: staff permissions were **not** granted to make tests pass. Phase 2/product decision required: add `users.customer_id` (or equivalent), a customer-scoped portal, and explicit customer permissions for booking/vehicle views.

### R8 — Forgot-password has no rate limit — **Medium**

Registration and contact forms are limited (5/15 min and 5/10 min per IP), but the password-reset request endpoint is not. It always returns a generic response, which prevents user enumeration, but it can be used to flood a mailbox or the mail transport, and it generates audit rows.

**Recommended:** 5 requests / 15 min per IP (and optionally per normalized e-mail), same `RateLimiter` pattern, generic response preserved, `Retry-After` on 429.

### R9 — Rate limiter fails open when storage is unavailable — **Medium (accepted decision)**

If `storage/rate_limits` is missing/unwritable, requests proceed and an `app_log` line is written. This prioritises availability and was verified deliberately; it also means an attacker who can break storage can bypass limits. Keep the storage directory owned and writable only by the web-server account, monitor the log message, and review whether authentication endpoints should fail closed in production.

### R10 — Fixed-window limiter allows boundary bursts — **Low**

A fixed window permits up to the limit at the end of one window plus the limit at the start of the next. Limits are low and per-IP; acceptable for this application. A sliding window or token bucket can replace it later if abuse is observed.

### R11 — Password policy has no breached-password or history check — **Low**

Minimum length is 10 with composition rules and a ban on username/email inclusion, but there is no offline breached-password list and limited history. Consider a local blocklist and last-N password history in a later phase.

---

## 3. PHP / server configuration

### R12 — `expose_php = On` — **Low**

PHP advertises its version in the `X-Powered-By` header. The application's own headers are otherwise minimal and the CSP is strict.

**Production action:** set `expose_php = Off` in `php.ini` (requires Apache restart).

### R13 — `session.gc_maxlifetime = 1440` is shorter than the idle timeout — **Low**

The application allows 30 minutes (1800 s) of inactivity, but PHP's garbage collector may delete session files after 24 minutes, logging users out earlier than the configured policy.

**Production action:** set `session.gc_maxlifetime` to at least 28800 (matching the 8-hour absolute life), or move sessions to a managed store. The application's own idle/absolute checks remain authoritative.

### R14 — Legacy runtime versions — **High (long-term production risk)**

Apache 2.4.33, OpenSSL 1.0.2u (end-of-life), MySQL 5.7.24 (end-of-life) and PHP 8.3.1 (an early 8.3 patch) are all older than current security releases. This is the existing MAMP stack and was not changed in Phase 1.5.

**Recommended before production:** run a supported PHP 8.3/8.4 patch level, a current Apache 2.4.x with a current OpenSSL, and a supported MySQL 8.x (or plan the migration). Re-run the integration suite after any upgrade.

### R15 — `app.debug` must stay off in production — **Low**

`app/config/app.php` defaults to `false` and the bootstrap shows generic 500 pages in that mode. `GMS_DEBUG` can override it — ensure it is unset on production and that `storage/logs` is readable only by the service account.

---

## 4. Data and audit

### R16 — Live audit record #40 was written by an accidental probe — **Info**

During test-server bring-up, one failed administrator login hit the live database before the test environment override was applied: audit row #40 (`login_failed`, username `admin`, `127.0.0.1`, 2026-11-09 13:56:05) and one `login_attempts` row. No business record was affected; the append-only triggers prevented deletion; the chain verifies (`checked=40`). Disclosed, not hidden. No corrective write was attempted.

### R17 — Backups and evidence bundle contain sensitive material — **Medium (local data protection)**

The verified dump contains password hashes and user e-mails; the evidence bundle contains a test-only MySQL option file and `test_env.sh` with test-environment credentials. They are outside the web root and Git, but still on disk.

**Action:** restrict access to the folder, delete the test credentials file once it is no longer needed (the test database and account were dropped), and apply the garage's retention/encryption policy to backups. Never attach dumps to tickets or chats.

### R18 — Encryption key is now Git-ignored but still exists on disk — **Low**

`storage/private/encryption.key` is required for AES-256-GCM blind-index features and is now covered by `.gitignore`. If this key is ever exposed or the file leaks, encrypted sensitive values become recoverable and blind indexes reversible by dictionary attack.

**Action:** back the key up separately (it is needed to decrypt existing data), restrict its ACL to the service account, and rotate with a migration plan if compromise is suspected.

---

## 5. Verification gaps / process

### R19 — No automated CI pipeline — **Low**

Verification is manual (`tests/run_tests.php` against a disposable `_test` database plus three unit suites). Add a CI job that provisions a `_test` database, runs lint, the unit suites and the integration suite on every change; keep the destructive-run safeguards intact.

### R20 — Local certificate warning remains until trusted — **Info**

Browsers show a certificate warning until the certificate is added to the Windows trust store (`HTTPS_CONFIGURATION_GUIDE.md` §2.3). This is expected for a self-signed certificate and is not a production concern. HSTS is intentionally absent locally.

### R21 — Windows-specific tooling limits sanitized verification — **Info**

OpenSSL 1.0.2u lacks `x509 -ext` and cannot verify a `CA:FALSE` self-signed leaf; SANs were verified with `-text`, `-checkhost` and `-checkip`. Automated UI checks over HTTPS were limited by the self-signed warning; authenticated checks were performed with `curl` against a temporary ACL-restricted test-DB vhost (now removed).

---

## 6. Risks added during the risk-list review (2026-11-09)

### R22 — An active test administrator account remains in the live database — **High**

Re-verification of the live `users` table found:

| Field | Value |
|---|---|
| ID / username | `1` / `kilotest_auth_63e8ee04` |
| E-mail domain | `example.invalid` |
| Role | `administrator` (full permissions) |
| `is_active` | `1` |
| `must_change_password` | `0` |
| `created_at` | 2026-11-08 14:54 (before Phase 1.5) |
| `last_login_at` | never |

Audit records also reference related `kilotest_*` users (including a now-missing ID 2) from earlier verification work, and the account dates from the same window. This is a test artifact created in the live database during an earlier phase, not by Phase 1.5 — the `users` checksum was identical to the post-Phase-1 backup.

It is an enabled administrator with an unknown password. Phase 1.5 correctly did not delete it (the directive forbids deleting existing users without approval), so it remains a pre-production blocker.

**Action (owner authorization required):**
1. Confirm with the garage owner that `acount` is the only intended administrator.
2. Disable the test account (`UPDATE users SET is_active = 0 …`) or delete it, with explicit approval; do not delete audit records.
3. Review `login_attempts` and `audit_logs` for any activity by that account (none observed: never logged in).
4. Record the change in the deployment log.

### R23 — Administrator password provenance is unverified — **Medium**

Both live administrator accounts have `must_change_password = 0`, and the owner account (`acount`) last logged in on 2026-11-09. Because the live database was used for testing in earlier phases, it cannot be assumed that every administrator password is unique, strong and known only to its owner. Passwords were **not** tested against the live database (that would create lockout/audit noise).

**Action:** the owner should sign in, change the password through `profile.php`, and confirm no shared credentials remain. Consider forcing a change for any other administrator.

### R24 — Password-reset mail silently falls back to a local spool when SMTP is unset — **Medium (production)**

`MailService::config()` defaults to `smtp.gmail.com` with empty credentials; when credentials are missing it tries native `mail()`, then writes the message to `storage/mail_spool/` and reports `ok=true` with transport `dev_spool`. On a production host without a configured mail transport, reset e-mails are never delivered even though the UI reports success. The default `From` address is `noreply@autopro-garage.com`, a domain unrelated to Alpha Garage, which would also fail SPF/DKIM/DMARC alignment.

**Action:** set `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`, `SMTP_FROM_NAME` and `SMTP_ENCRYPTION` in the server environment; use a sender on a domain with SPF/DKIM/DMARC; send a real reset e-mail end to end; monitor the spool directory as an alert condition in production.

### R25 — No rotation for application logs — **Low**

`app_log()` appends to `storage/logs/app.log`; PHP errors append to `storage/logs/php-error.log`. There is no size limit or rotation, so logs grow indefinitely and can eventually exhaust disk or complicate incident review.

**Action:** rotate at the OS/web-server level (for example weekly with retention), keep logs outside the web root, and restrict them to the service account. `storage/.htaccess` already denies web access.

### R26 — No retention policy for audit and inquiry data — **Low**

`audit_logs` is append-only with no archival path, and `contact_inquiries` stores customer PII indefinitely. The audit chain must be preserved, but a growth and privacy policy is still needed.

**Action:** define an archival/export policy that preserves chain integrity (archive full segments with their hashes, never delete individual rows), and define a retention period for contact inquiries consistent with the garage's privacy notice.

### R27 — Local MySQL root uses MAMP's default administrative credential — **Low (local only)**

Administrative verification used the local MAMP root account, which is configured with MAMP's default credential. This is normal for a local development sandbox but must not be carried into production, and port 3306 should not be reachable from untrusted networks.

**Action:** production must use the least-privilege application and migrator accounts (the Phase 1 grant set), not root; bind MySQL to loopback or a private network; rotate the local root password if this machine is shared.

### R28 — Backups are manual only — **Medium (operations)**

Phase 1.5 produced and verified a backup, but the repository contains no scheduled backup job or off-machine copy. Recovery depends on someone remembering to run it.

**Action:** schedule daily (or at least daily-incremental + weekly-full) `mysqldump` backups to storage outside the web root, copy them off-machine/encrypted, alert on failure, and rehearse a restore quarterly using `BACKUP_RESTORE_VERIFICATION.md` §6.

---

## 7. Recommended order before Phase 2 / production

1. **R22 + R23** — disable the test administrator with owner approval; verify every remaining administrator's password. Do this before any network exposure.
2. **R1** — serve only `public/` (or deny `.git` at server level); confirm with the probe URLs.
3. **R24 + R4 + R8** — working SMTP/sender domain, absolute HTTPS reset links, and a reset-request rate limit; re-run the reset workflow.
4. **R2 + R3** — real certificate, redirect, TLS termination/proxy policy, staged HSTS.
5. **R12 + R13** — production `php.ini` hardening (`expose_php=Off`, `gc_maxlifetime`).
6. **R28 + R17 + R18** — scheduled off-machine backups, evidence/key custody and retention.
7. **R7** — decide and implement customer identity linking/portal scope (Phase 2 product work).
8. **R14** — plan the supported runtime upgrade and re-run all suites afterwards.
9. **R19/R25/R26** — CI regression job, log rotation and data retention policy.
10. Re-run `tests/run_tests.php` on a fresh `_test` database after every change; keep the disposal and live-DB safeguards from `INTEGRATION_TEST_RESULTS.md`.
