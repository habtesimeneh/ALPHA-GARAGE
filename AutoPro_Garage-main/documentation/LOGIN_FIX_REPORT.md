# Garage Authentication Repair Report

**Review date:** 2026-11-08  
**Scope:** Login failure investigation, authentication code, database configuration, session/CSRF behavior, and available tests. This was not a live authenticated database test.

## Root cause

The sign-in page checks database availability before displaying the form. `public/login.php` calls `Database::value('SELECT COUNT(*) FROM users')`; its exception handler then displays the service-unavailable message.

The application log records that this check failed because `GMS_DB_HOST` was missing. A fresh configuration check confirmed that all five required `GMS_DB_*` variables are absent and `app/config/local.php` does not exist. MySQL is listening on `127.0.0.1:3306`, but this only verifies that a TCP port is reachable; it does not verify a database, credentials, grants, or schema. Therefore no password or account lookup was reached. During follow-up, `database/least_privilege.sql` was also found to contain a malformed `garage_app` password statement split across lines, so its saved value was not usable as SQL. That value must be treated as exposed and replaced.

## Status summary

| Area | Status | Evidence / limitation |
|---|---|---|
| Root-cause identification | **FIXED** | The missing database configuration is confirmed by the private application log, environment check, missing local config file, and login execution path. |
| Local database configuration support | **FIXED in source** | `app/config/database.php` now supports git-ignored `app/config/local.php`; explicit environment values take precedence. No credentials were invented or added. |
| Least-privilege SQL account script | **PARTIALLY FIXED** | Repaired the malformed account statements and added password-reset statements using placeholders. A database administrator must replace the placeholders with fresh distinct secrets and execute the script. |
| Authentication diagnostics | **FIXED in source** | Login database/authentication exceptions are logged with class, message, file, and line; the browser receives a generic message. Passwords and session tokens are not logged. |
| Credential verification and session flow | **PARTIALLY FIXED** | Password hashing/verification, active-account check, prepared login query, rate limiting, session regeneration, and session initialization remain enforced. The successful DB-backed flow could not be exercised. |
| Username/email handling | **FIXED in source; migration pending** | Login now accepts the full 150-character email length, stores normalized rate-limit identifiers, and returns the verified role to avoid stale cached-user redirect decisions. Existing databases need the width migration below. |
| CSRF and session cookies | **PARTIALLY FIXED** | Bootstrap verifies CSRF on every POST; the token is time-limited. Session cookies remain HTTPS-aware, HttpOnly, SameSite=Lax. Request-level tests were not run against Apache. |
| Administrator recovery | **IMPLEMENTED; not executed** | Added CLI-only `database/recover_admin.php`. It requires explicit confirmation, creates an administrator only if none exists, uses the existing role/user schema, hashes a random one-time password, and forces a password change. Live administrator presence is unknown. |
| Database/schema and least privilege | **NOT TESTED** | Database name, credentials, grants, authentication tables, roles, and migrations could not be queried without DB credentials. |
| Valid admin/receptionist/customer login | **NOT TESTED** | No authorized test database credentials or valid test-account passwords were available. |
| Full authentication integration suite | **NOT TESTED** | It writes/deletes test rows. It now refuses to run unless pointed at a local database ending in `_test` and explicitly enabled. No such configured test DB was available. |

## Files changed

- `app/config/database.php`, `app/config/local.php.example` — added the supported, git-ignored local MAMP configuration option; environment variables still take precedence.
- `database/least_privilege.sql` — repaired malformed account SQL and made reruns set fresh credentials explicitly. It contains placeholders, not actual secrets.
- `public/login.php` — logs database/auth failures privately and keeps safe user-facing errors; routes customers based on the verified role; removed the misleading remember-me control because persistent login is not implemented.
- `app/services/AuthService.php` — supports full-length email identifiers, normalizes rate-limit keys, returns the authenticated role, verifies role linkage, checks session ID regeneration, and commits successful-login database updates atomically before setting the authenticated session.
- `database/database.sql` — baseline `login_attempts.username` capacity now matches the maximum email identifier length.
- `database/migrations/widen_login_attempt_username.sql` — forward migration for existing installations; it does not drop tables or delete data.
- `database/recover_admin.php` — guarded CLI-only administrator recovery.
- `tests/auth_unit.php` — isolated password hashing, password policy, CSRF-token, and DB-config parsing checks.
- `tests/run_tests.php` — removed hardcoded test passwords, requires supplied test credentials, restricts destructive tests to an explicit local `*_test` database, and includes email and receptionist login checks.
- `documentation/INSTALLATION_GUIDE.md` — documents local DB config and administrator recovery.

## Tests executed

- PHP syntax checks for the modified login, authentication service, database config, recovery script, and authentication test runner: **passed**.
- `php -c C:\MAMP\conf\php8.3.1\php.ini tests\auth_unit.php`: **passed**.
- `php -c C:\MAMP\conf\php8.3.1\php.ini tests\security_hardening_unit.php`: **passed**.
- Confirmed the selected MAMP Apache configuration loads `mbstring`, `pdo_mysql`, and OpenSSL. Running PHP CLI without MAMP's `php.ini` initially lacked these extensions; tests were rerun with the configured INI and passed.
- Confirmed MySQL TCP port 3306 is reachable; this is not a successful PDO/database authentication test.
- Full web/database login, migration, recovery, rate-limit, authorization, logout, and schema tests: **not run**, because database credentials/configuration are absent.

## Required local completion steps

1. Treat the previously entered `garage_app` password as exposed. Generate fresh, distinct app and migration passwords.
2. Replace the two password placeholders in `database/least_privilege.sql` with those new values and run the script in phpMyAdmin as a database administrator. It grants privileges but does not drop tables or delete data.
3. Copy `app/config/local.php.example` to `app/config/local.php`; set its `pass` to the new `garage_app` password. Do not use MySQL `root` for the application.
4. If this is an existing database, back it up and run the project's CLI migration runner with the authorized migration account. This applies `widen_login_attempt_username.sql` without dropping records.
5. Reload sign-in. If the users table is empty, use the public first-time setup page. If users exist but no administrator exists, follow the CLI recovery instructions in the installation guide.
6. Run the authentication integration suite only on a dedicated local `*_test` database with `GMS_ALLOW_DESTRUCTIVE_TESTS=1`, `ADMIN_PASS`, and `DEMO_PASS` set to the test account passwords.

**Do not claim sign-in is restored until a valid test account successfully authenticates against the configured database.**
