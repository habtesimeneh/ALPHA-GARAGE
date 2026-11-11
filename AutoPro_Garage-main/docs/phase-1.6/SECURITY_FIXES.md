# Phase 1.6 — Security Fixes

Date: 2026-11-09
Scope: pre-production cleanup only. No framework, database redesign, module rewrite or Phase 2 work.

## 1. Repository and web-root protection (R1)

**Problem:** MAMP served the project root, so `.git/config`, SQL files and documentation were reachable
over HTTP.

**Fix:** MAMP Apache configuration (`C:\MAMP\conf\apache\httpd.conf`) now denies:

- `.git`, `.svn`, `.hg` directories (case-insensitive, including URL-encoded variants);
- a direct rule for `AutoPro_Garage-main/.git`;
- file patterns `.env`, `.sql`, `.log`, `.md`, `.example`, `.lock`, `.patch`, `.orig`, `.bak`, `.dump`, `.cnf`.

The change is delimited by `#BEGIN ALPHA GARAGE PHASE 1.6 REPOSITORY PROTECTION` /
`#END …` comments so it can be removed cleanly. Original file saved as
`httpd.conf.phase1_6.orig` (SHA-256 `427435dd…86c1`). `httpd -t` reports `Syntax OK`.

The production recommendation remains: document root = `public/`, with no further restructuring made
unilaterally. The Phase 1.5 deployment guide already covers the production vhost.

**Verified:** `.git/config`, `.git/HEAD`, `%2egit`, `.GIT`, `database/*.sql`, `app/config/local.php`,
`.env.example`, `storage/private/*`, `storage/logs/*` and `docs/*.md` return **403**; `public/login.php`
returns **200**.

## 2. Password reset — absolute URL (R4)

**Problem:** reset links were root-relative and unusable from an e-mail client.

**Fix:** new `app_url()` helper in [app/helpers/functions.php:35](../../app/helpers/functions.php#L35).
Resolution order:

1. `GMS_APP_URL` environment variable;
2. `app_url` in `app/config/app.php` (default empty);
3. request host + scheme fallback.

Invalid values are ignored and logged. Credentials, query strings and fragments are rejected; a non-local
`http://` base logs a warning. `PasswordResetService` now builds the link with
`app_url('reset-password.php?token=…')` at
[app/services/PasswordResetService.php:54](../../app/services/PasswordResetService.php#L54).

`GMS_APP_URL` is documented in `.env.example` and `app/config/local.php.example`.

**Verified:** with `GMS_APP_URL=http://127.0.0.1:19877`, the generated message contains
`http://127.0.0.1:19877/reset-password.php?token=<64 hex>` (token redacted in evidence).

## 3. Password reset — rate limiting (R8)

**Problem:** unlimited reset requests allowed mail flooding and user-enumeration probing.

**Fix:** [public/forgot-password.php:21](../../public/forgot-password.php#L21) applies two independent
`RateLimiter` checks, 5 requests per 900 seconds:

- per client IP (`password_reset_ip|…`);
- per normalised e-mail (`password_reset_email|…`, lowercased and trimmed).

The IPv6/IPv4 address is taken from `REMOTE_ADDR` only (no spoofable forwarded header). On refusal the page
returns **HTTP 429** and a `Retry-After` header, and shows a generic retry message. Responses remain generic
so account existence is never disclosed; token generation, SHA-256 storage, 30-minute expiry and single-use
semantics are unchanged.

**Verified:** five requests allowed, sixth returned `HTTP 429` with `Retry-After: 866`; response body
"Too many password recovery requests…". Both limiter files were removed after the test so no local operator
was left throttled.

## 4. SMTP identity and truthful delivery (R24)

**Problem:** the mailer defaulted to `smtp.gmail.com` / an AutoPro sender, and the development spool returned
`ok=true`, making logs and audit records claim delivery that never happened.

**Fix in [app/services/MailService.php](../../app/services/MailService.php):**

- SMTP host default is now empty; encryption is validated (`tls`, `ssl`, `none`).
- `FROM_EMAIL` is validated with `filter_var`; an invalid or missing value falls back to
  `no-reply@<GMS_APP_URL host>` (`no-reply@localhost` as last resort), never an AutoPro address.
- `send()` returns `ok=true` **only** after SMTP or native `mail()` success.
  The local spool path returns `ok=false`, includes an `error`, and says "Email was NOT delivered".
- Mailer header renamed to `Alpha-Garage-Mailer`; MIME boundary renamed to `=_AlphaGarage_`.
- Reset e-mail HTML escapes the recipient name, reset URL and garage name; plain-text part keeps raw values.
- Delivery outcomes are audited in
  [app/services/PasswordResetService.php:57](../../app/services/PasswordResetService.php#L57):
  `password_reset_requested` includes `"delivered": true|false`, and a failed delivery writes
  `password_reset_delivery_failed` with `action_result=failure` and a 300-character error summary.
  Raw tokens and reset URLs are never written to the audit log.

**Configuration documented:** `SMTP_HOST`, `SMTP_PORT`, `SMTP_USERNAME`, `SMTP_PASSWORD`, `SMTP_FROM_EMAIL`,
`SMTP_FROM_NAME`, `SMTP_ENCRYPTION`, plus `GMS_APP_URL`. `.env.example` and `local.php.example` contain
placeholders only; no credentials are committed. The examples use the Alpha Garage identity.

**Verified:** without SMTP, `MailService::send()` returned
`{"ok":false,"transport":"dev_spool","error":"Native mail() reported a delivery failure."…}` and the audit
chain recorded the matching failure event. SMTP itself remains **untested** because no mailbox credentials
exist in this environment (documented as a risk).

## 5. PHP / session hardening (R12, R13)

**Problem:** `expose_php=On` advertised PHP; `session.gc_maxlifetime=1440` (24 min) was shorter than the
application's 30-minute idle window, letting valid sessions be garbage-collected early.

**Fix in `C:\MAMP\conf\php8.3.1\php.ini`** (original saved as `php.ini.phase1_6.orig`,
SHA-256 `5c03dc6e…95c8`):

- `expose_php = Off`
- `session.gc_maxlifetime = 28800` (matches the 8-hour absolute session lifetime)

Apache was restarted. Runtime cookie policy remains application-controlled:
`Secure` when HTTPS, `HttpOnly`, `SameSite=Lax`, strict mode, cookie-only sessions, 30-minute idle and
8-hour absolute limits.

**Verified through Apache over both HTTP and HTTPS:** no `X-Powered-By`; `gc_maxlifetime=28800`;
HTTPS cookie `Secure; HttpOnly; SameSite=Lax`; HTTP cookie `HttpOnly; SameSite=Lax`.

## 6. HTTPS and HSTS position (R5)

- Local HTTPS remains a self-signed SAN certificate for `localhost`/`127.0.0.1`/`::1`; it is **not** a
  publicly trusted certificate and is not presented as one.
- No `Strict-Transport-Security` header is sent. HSTS stays disabled until a trusted production certificate,
  a working HTTP→HTTPS redirect and owner sign-off exist; otherwise a self-signed HSTS response could pin
  browsers to an untrusted origin.
- The production redirect/TLS/HSTS procedure remains `docs/phase-1.5/HTTPS_CONFIGURATION_GUIDE.md`.

**Verified:** HTTPS login page 200; no HSTS header; HTTP access still available locally by design.

## 7. Private-file permissions (R28)

- `storage/private/` and `app/config/local.php` had inherited `Authenticated Users: Modify` /
  `Users: Read` ACLs. Inheritance was removed; only the owner, `SYSTEM` and `BUILTIN\Administrators`
  retain access. Apache runs as the owner and continues to work.
- Pre-change ACLs saved as `icacls_private_before.txt`.
- `encryption.key`, `least_privilege.generated.sql`, logs, spool and rate-limit storage confirm **403** over
  HTTP. None of these files is tracked by Git; the generated SQL contains live `IDENTIFIED BY` values and must
  never leave the host.

## 8. Backup automation

New `scripts/backup_database.sh` (see `BACKUP_STRATEGY.md`) provides an encrypted, checksummed dump with
retention. It reads credentials from a defaults file outside the repository and refuses to run without
encryption. After the live-restore incident it also refuses to keep any dump containing `CREATE DATABASE`
or `USE` statements, so a dump can never silently target the database named inside it.

## 9. Not changed

- No administrator account was modified (R22 awaits owner approval).
- No audit record was deleted or altered; both chains verify (live 40/40, test 149/149).
- No SMTP credentials were added, committed or logged.
- No production certificate or DNS was created.
- No Phase 2 work was started.
