# Phase 1.6 — Remaining Risks

Date: 2026-11-09
Status after Phase 1.6. Severity reflects production impact, not local-only convenience.

## 1. Resolved or mitigated in this phase

| ID | Risk | Status |
|---|---|---|
| R1 | `.git`, SQL dumps and docs reachable over HTTP under MAMP | **Resolved locally** — Apache denies dot-directories and sensitive extensions; production document root = `public/` remains the deployment rule |
| R4 | Password reset links were root-relative | **Resolved** — `app_url()` / `GMS_APP_URL` produces absolute links |
| R8 | No rate limit on reset requests | **Resolved** — 5 per 15 min per IP and per normalised e-mail, HTTP 429 + `Retry-After` |
| R12 | `expose_php=On` | **Resolved** — `expose_php=Off` verified via Apache |
| R13 | Session GC lifetime (24 min) shorter than idle timeout (30 min) | **Resolved** — `session.gc_maxlifetime=28800` |
| R24 | Local spool reported as successful delivery | **Resolved for reporting** — spool now returns `ok=false`, failures audited; SMTP delivery itself still unverified (§2.1) |
| R28 | Backups were manual and unencrypted | **Mitigated** — encrypted script + documented schedule; off-machine storage and scheduling still owner actions |
| — | Private key and local config inherited broad ACLs | **Mitigated** — inheritance removed; owner/SYSTEM/Administrators only |
| — | `database.sql` can silently target the live DB via `USE` | **Mitigated** — prominent warning added; backup script rejects dumps containing `USE`/`CREATE DATABASE`; test procedure strips them. This is the root cause of the incident in `PHASE_1_6_EXECUTION_REPORT.md` §5 |

## 2. Open risks

### 2.1 SMTP delivery is configured but unverified — **High before launch**

No SMTP credentials or mailbox exist in this environment. The application now reports failure truthfully,
but actual delivery, TLS enforcement, SPF/DKIM/DMARC alignment and bounce handling have not been tested.
`SMTP_ENCRYPTION=none` is accepted by configuration; production must use `tls` or `ssl`.

**Owner action:** provide a dedicated sender mailbox on the Alpha Garage domain, set `SMTP_*` and
`GMS_APP_URL` in the server environment, send a real reset, confirm SPF/DKIM/DMARC, and alert on
`password_reset_delivery_failed` audit events.

### 2.2 Administrator account `kilotest_auth_63e8ee04` is still enabled — **High**

R22 investigation is complete; no change was made because owner approval is required. Full analysis and the
exact one-line change are in `ADMIN_ACCOUNT_INVESTIGATION.md`.

**Owner action:** approve deactivation (recommended), or choose another option from §7.1 of that report.

### 2.3 Owner password provenance unverified (R23) — **Medium**

`acount` uses a bcrypt hash but the password's origin/strength is unknown. A remembered-session token is not
currently stored.

**Owner action:** rotate the password through `profile.php` and re-authenticate; optionally set
`must_change_password = 1`.

### 2.4 Production TLS and HSTS are not in place — **High before public exposure**

Local HTTPS uses a self-signed certificate; it is not publicly trusted and no HSTS header is sent (deliberate).
Production still needs DNS, a CA-issued certificate with automated renewal, an HTTPS vhost rooted at `public/`,
an HTTP→HTTPS redirect that leaves ACME challenges reachable, and staged HSTS after verification.

**Owner action:** execute `docs/phase-1.5/HTTPS_CONFIGURATION_GUIDE.md` §4–§6 and sign off before enabling HSTS.

### 2.5 Backups are not yet off-machine or scheduled — **High**

The script, encryption and restore drill are verified, but no off-machine destination, GPG key pair or daily
schedule exists. A host-level loss would still destroy all backups.

**Owner action:** follow `BACKUP_STRATEGY.md` §2–§4 and §7: create the offline GPG key, configure the
destination, enable the daily job, set lifecycle retention and run the first restore drill.

### 2.6 Reset delivery failures have no alerting — **Medium**

Failures are audited, but nobody is notified. A production outage of the mail provider would silently break
password recovery.

**Owner action:** monitor `password_reset_delivery_failed` (log/SIEM alert) and test the alert path.

### 2.7 Test tooling shares storage with the live app — **Medium**

The integration server used the same `storage/` tree (rate limits, mail spool, sessions) as the live app.
The test run's limiter keys and spool files were removed and the test database/account were dropped, but
running future tests alongside production could still throttle live users or leak spool content.

**Owner action / follow-up:** give the test harness its own `storage/` directory or bind it to a distinct
loopback address, and always clear test limiter keys.

### 2.8 Apache still serves the repository root locally — **Low (local), High if copied to production**

Phase 1.6 blocks sensitive paths at the MAMP layer. The durable fix is a vhost whose document root is
`public/`, so `app/`, `database/`, `docs/` and `storage/` are outside the served tree entirely.

### 2.9 No continuous audit-chain monitoring — **Medium**

Chains verify on demand (live 40/40, test 149/149) but nothing alerts on a break. The append-only triggers
protect rows; detection still depends on someone running the verifier.

**Owner action:** schedule `AuditLogger::verifyChain()` daily and alert on `ok=false`.

### 2.10 Log retention and personal data — **Low/Medium**

`storage/logs/app.log` and `php-error.log` may contain usernames, e-mail addresses from reset failures and
IP addresses. There is no rotation or retention policy configured.

**Owner action:** define retention (e.g. 90 days), rotate, and restrict access (already web-denied and
ACL-restricted on the host).

### 2.11 SMTP error text in audit records — **Low**

Delivery failures store a truncated (300-character) transport error. It never contains credentials, but may
include server hostnames or response codes.

**Accepted:** needed for diagnosis; reviewed as non-secret.

## 3. Owner approval queue

1. Deactivate `kilotest_auth_63e8ee04` (R22) — see `ADMIN_ACCOUNT_INVESTIGATION.md` §7.1.
2. Rotate the `acount` password and confirm it is the only intended administrator (R23).
3. Approve the production SMTP sender/domain and provide credentials out-of-band (never in Git).
4. Approve off-machine backup destination and GPG key ownership.
5. Approve the production TLS/HSTS rollout plan and certificate ownership.

## 4. Explicitly out of scope

Phase 2 work was not started. No framework migration, database redesign, module rewrite, UI overhaul or new
business feature was introduced.
