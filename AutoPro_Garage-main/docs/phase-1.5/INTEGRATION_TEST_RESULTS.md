# Phase 1.5 — Integration Test Results

**Date:** 2026-11-09
**Runner:** `tests/run_tests.php` (destructive integration suite)
**Environment:** isolated loopback PHP server + dedicated MySQL test database
**Final result:** **118 passed, 0 failed — exit code 0**

---

## 1. Test database

| Item | Value |
|---|---|
| Database name | `garage_management_system_test` |
| Server | MySQL 5.7.24 on `127.0.0.1:3306` |
| Character set | `utf8mb4` |
| Account | `garage_test_app`@`localhost`, privileges only on `garage_management_system_test` and `garage_management_system_restore_test` |
| Schema source | Sanitized **schema-only** dump of the post-Phase-1 database (no live rows) |
| Migrations applied | `enterprise_upgrade.sql`, `password_resets.sql`, `revoke_customer_staff_access.sql`, `widen_login_attempt_username.sql` (the other five were already present in the schema dump) |
| Seed data | Disposable test administrator, demo users, customers, vehicles, job cards, invoices, payments, parts — created by `seed_test.php` |
| Triggers | `trg_audit_no_update`, `trg_audit_no_delete`, `trg_payments_immutable` present |
| Final cleanup | Database and account **dropped** after evidence capture; only `garage_management_system` remains |

The test account password and the seeded user passwords are not reproduced in this document. They were supplied at run time through environment variables from the Phase 1.5 evidence bundle.

## 2. Safeguards verified before running

`tests/run_tests.php` refuses to run unless all of the following hold:

```php
if (getenv('GMS_ALLOW_DESTRUCTIVE_TESTS') !== '1'
    || !str_ends_with($dbConfig['name'], '_test')
    || !in_array($dbConfig['host'], ['localhost', '127.0.0.1', '::1'], true)) {
    fwrite(STDERR, "Refusing destructive tests ...\n");
    exit(2);
}
```

In addition:

- The HTTP server was started **after** exporting `GMS_DB_HOST`, `GMS_DB_PORT`, `GMS_DB_NAME=garage_management_system_test`, `GMS_DB_USER`, `GMS_DB_PASS` for that process only.
- The database name was re-checked in MySQL before the run and ended in `_test`.
- The suite requires `ADMIN_PASS` and `DEMO_PASS` chosen for this database; it deletes rows such as `login_attempts` and writes disposable business records.
- The live database was never referenced by the test server. (One early misconfigured instance is disclosed in `PHASE_1_5_EXECUTION_REPORT.md` §9.)

## 3. Commands executed (credentials omitted)

```bash
# 1. Import sanitized schema and apply migrations (test database only)
mysql --defaults-extra-file=test_db.cnf garage_management_system_test < test_schema_sanitized.sql
php -c "C:/MAMP/conf/php8.3.1/php.ini" database/migrate.php   # GMS_DB_* -> _test

# 2. Seed disposable users and business data
php -c "C:/MAMP/conf/php8.3.1/php.ini" seed_test.php

# 3. Isolated web server for the suite (test DB environment exported first)
php -c "C:/MAMP/conf/php8.3.1/php.ini" -S 127.0.0.1:19877 -t public

# 4. Full suite
GMS_ALLOW_DESTRUCTIVE_TESTS=1 BASE=http://127.0.0.1:19877/ \
  php -c "C:/MAMP/conf/php8.3.1/php.ini" tests/run_tests.php
```

The complete output of every run is stored in `integration_run1.txt` … `integration_run5.txt` in the Phase 1.5 evidence bundle.

## 4. Workflow results

| Suite section | Checks | Outcome |
|---|---|---|
| 1. Authentication | 12 | Anonymous redirect, generic wrong-password message, no user enumeration, auditing, dashboard rendering, security headers, GET-logout refusal, e-mail login |
| 2. Login rate limiting | 2 | Lockout after 5 failures even with the correct password; lockout audited |
| 3. CSRF protection | 3 | Missing token → 419, forged token → 419, failures audited |
| 4. Role-based access control | 13 | Receptionist, mechanic, accountant and inventory-manager boundaries enforced server-side, including POST requests; denials audited |
| 5. IDOR (object level) | 4 | Mechanic can open own job only; guessed IDs and status changes denied; list filtered |
| 6. SQL injection & XSS | 6 | Injection does not leak rows or error; stored `<script>`/`onerror` payloads are HTML-escaped; creation audited |
| 7. Inventory, jobs, invoices, payments | 28 | Status-transition guards; automatic stock deduction; over-issue blocked with rollback; ledger entries; bulk-issue rollback; invoice totals (subtotal 2252.50, tax 322.88, total 2475.38); partial and multiple payments; overpayment blocked; refund rules; cancellation restores stock |
| 8. Audit append-only + tamper evidence | 9 | Hash chain verified for all records present at that point (390); UPDATE/DELETE triggers block mutation; historic tampering detected at record #21 and chain re-verifies after restore; no secrets in audit rows |
| 9. Setup lock & module pages | 22 | `setup.php` 404 after installation; audit/revenue CSV exports; 20 administrator module pages return 200; operating expense audited |
| 10. Enterprise security & modules | 11 | AES-256-GCM encrypt/decrypt and tamper rejection; deterministic blind index; supplier, catalog, appointment→job-card conversion and notifications |
| 11. Password recovery | 9 | Generic response, SHA-256 token storage, invalid/used token rejection, successful reset, new password works over HTTP, old password fails |
| **Total** | **118** | **0 failed** |

## 5. Failures encountered and fixes

| Run | Symptom | Root cause | Fix | Verification |
|---|---|---|---|---|
| 1 | Aborted at section 1 with HTTP 500 | Test web server started without the `GMS_DB_*` test overrides / database wiring not yet correct | Restarted the server with explicit test-database environment and re-ran | Run 2 progressed through all sections |
| 2 | `Dashboard renders real data and analytics for administrator` failed | Assertions referenced widgets from an older dashboard (`Workshop Status Overview`, `Customer Growth`, …) that no longer exist; the page itself returned 200 with real analytics | Updated the assertions to the current, equivalent markers (`Active Job Cards`, `Completed Jobs`, `Revenue vs Expenses`, `Profit & Loss Analysis`, chart canvases, `Pending Payments`, `Inventory Value`) — same intent, no weakening | Passed in runs 3–5 |
| 2 | Aborted at section 11 (reset request) with 500 | `PasswordResetService` passed a string record ID to `AuditLogger::logSafe(?int)`; `strict_types` made it a fatal `TypeError` | Cast to `(int)` in `app/services/PasswordResetService.php:63` | Reset section passes |
| 2 | Registration could fail after account creation (found during customer E2E) | Same strict-type defect with `$uid` in `public/register.php` | Cast to `(int)` at line 123 | Customer registration completes |
| 3 | Aborted after “Used token cannot be reused” with 500 | The final login checks called `AuthService::attempt()` from CLI; earlier test output marks headers as sent, so `session_regenerate_id()` cannot run there | Replaced the two CLI checks with real HTTP logins through the same request path users take | Passed in runs 4–5 |
| — | Remembered sessions had no permissions/mechanic binding | Hydration wrote to an undefined `$row` variable in `AuthService::restoreFromRememberCookie()` | Corrected to `$user[...]` and added session-ID regeneration | Covered by session checks in run 5 |

No security check was removed, skipped or relaxed to obtain a pass.

## 6. Customer end-to-end verification (test environment)

| Step | Result |
|---|---|
| Register disposable customer via `register.php` | Account created (`users` row, customer role) and a `customers` row created with `created_by` = new user ID |
| Identity linking | **Not implemented** — there is no `users.customer_id` (or reverse) link; the two records are independent |
| Log in | Redirects to `index.php` (customer landing), not the staff dashboard |
| Customer dashboard / landing | 200 |
| Direct access to staff/admin modules | 403 for every module and object URL tested (customers, vehicles, job cards, invoices, payments, expenses, inventory, reports, users, audit logs, suppliers, appointments) |
| Cross-customer object access | Denied (403) — object-level authorization is permission-based |
| Profile page | 200; password change works |
| Password-change redirect | Fixed to return customers to `index.php` instead of a 403 dashboard |
| “Book a service” CTA | Leads to `appointments/form.php`, which returns **403** for the customer role (no customer permission was granted) |
| Logout | POST + CSRF succeeds, session invalidated, subsequent protected requests redirect to login |

## 7. Public endpoint / rate limiter verification

Performed in the isolated environment only (never against the live database).

| Scenario | Observed |
|---|---|
| Register, requests 1–5 | Allowed through the limiter (normal validation applied) |
| Register, request 6 within 15 min | **429**, `Retry-After: 899` |
| Contact, requests 1–5 | Allowed through the limiter (normal validation applied) |
| Contact, request 6 within 10 min | **429**, `Retry-After: 599` |
| Honeypot field populated (register/contact) | Generic success response; no database row; limiter not consumed |
| Forged `X-Forwarded-For` / `X-Real-IP` | Ignored — counting keyed on `REMOTE_ADDR` |
| Rate-limiter storage made unwritable | Request proceeds (fail-open) and a line is written via `app_log` |

Limiter files are SHA-256-named, written with `flock`, and keyed by `action|client_ip`; the counters are per fixed window and expire by timestamp. The `storage/rate_limits` directory is now ignored by Git.

## 8. Final output and cleanup

```
...
11. Password Recovery System (Request, Hashing, Single-Use & Reset)
  ✔ Test user provisioned for recovery
  ✔ Reset request returns generic success message
  ✔ Password reset record saved in database with SHA-256 hash
  ✔ Random invalid token is rejected
  ✔ Valid raw token matches database record
  ✔ Password reset completion succeeds
  ✔ Used token cannot be reused
  ✔ Login with NEW password succeeds
  ✔ Login with OLD password fails

Result: 118 passed, 0 failed
```

- Exit code: **0** (`integration_exit=0`).
- Test server stopped; port 19877 no longer listening.
- `garage_management_system_test`, `garage_management_system_restore_test` and `garage_test_app`@`localhost` dropped after evidence capture.
- Test-generated mail-spool files removed; rate-limit state directory empty.
- Live database: no destructive test touched it. The only live writes were the disclosed failed-login probe (audit #40 + one `login_attempts` row); all business tables remain byte-identical to the backup-time checksums.
