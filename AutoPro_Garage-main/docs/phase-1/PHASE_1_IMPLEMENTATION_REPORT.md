# Phase 1 — Foundation Stabilization: Implementation Report

**Project:** Alpha Garage / AutoPro Garage Management System (native PHP 8.x + MySQL, MAMP on Windows)
**Execution window:** 2026-11-09
**Scope executed:** Phase 1 only. Phase 2 was not started.
**Operator role:** Senior PHP/MySQL Architect, Database Migration Engineer, Security Engineer, QA Engineer.

---

## 1. Objective and Outcome

Phase 1 aligned the live database, application code, security configuration, and documentation without rebuilding the application.

| Area | Result |
|---|---|
| Safety checkpoint and verified backup | Completed before any database write |
| Migration reconciliation and application of verified missing migrations | Completed (2 missing migrations applied; 4 already-applied migrations recorded) |
| Supplier inventory-history link defect | Fixed |
| Customer dashboard 403 defect | Fixed by permission-gated navigation (no new grants) |
| Installer exposure | Fixed with lock-first HTTP 404 and optional `GMS_SETUP_TOKEN` |
| Public endpoint abuse protection | Added server-side rate limiting + honeypot to registration and contact forms |
| Documentation consistency | Reconciled roles, permission codes, migration state, and historical test claims |
| Automated verification | Completed; evidence in `PHASE_1_TEST_RESULTS.md` |
| Production readiness claim | **Not made.** Residual risks are listed in `PHASE_1_REMAINING_RISKS.md`. |

No customers, vehicles, job cards, invoices, payments, inventory records, users, roles, permissions, settings, or audit records were deleted or rewritten by Phase 1. No table was dropped, truncated, or recreated. `database/least_privilege.sql` was not rerun.

---

## 2. Pre-change Safety Checkpoint

- Git repository confirmed on branch `main` (one commit, only `docs/` untracked at start). No uncommitted user work was overwritten.
- Verified full database backup created outside the web root:
  `C:\Users\Temesgen\Documents\Qoder\2026-11-09\d7b76288\phase1-backups\20261109_020529\`
  - `full_before.sql` — schema, data, triggers, routines (28 tables at that point)
  - `source_before.tar.gz` — source checkpoint
  - `source_hashes_before.txt` — 149-file hash manifest
  - `schema_migrations.txt`, `grants_app.txt`, `grants_migrator.txt`, `triggers_before.txt`, `fingerprint_before.txt`, `effect_verification.txt`
  - `my.cnf` — restricted-ACL DBA defaults file used only for backup/restore operations; credentials were never printed or committed.
- Restore drill performed into a scratch database **before** any live write: 28 tables, 2 users, 39 audit rows, 38 permissions, 3 migration records recovered successfully; scratch database dropped afterwards.
- Live schema, row counts, grants, and triggers were recorded before changes.

---

## 3. Database Migration Reconciliation

The migration runner (`database/migrate.php`) only records files it applies, so absence of a row was not treated as proof that an object was missing. Each of the nine migration files was checked against the live schema.

| Migration | State found | Action |
|---|---|---|
| `alpha_garage_brand.sql` | Recorded and applied | None |
| `contact_inquiries.sql` | Recorded and applied | None |
| `add_remember_token_to_users.sql` | Recorded and applied | None |
| `enterprise_upgrade.sql` | Applied, **not recorded** | Recorded with the file's exact SHA-256 checksum |
| `password_resets.sql` | Applied, **not recorded** | Recorded with the file's exact SHA-256 checksum |
| `revoke_customer_staff_access.sql` | Applied, **not recorded** | Recorded with the file's exact SHA-256 checksum |
| `widen_login_attempt_username.sql` | Applied, **not recorded** | Recorded with the file's exact SHA-256 checksum |
| `20261108_expense_ledger.sql` | **Missing** | Applied after backup and explicit approval |
| `20261109_job_card_priority.sql` | **Missing** | Applied after backup and explicit approval |

- Only the two missing migrations were executed; no complete schema import was performed.
- The DBA connection used was the approved local root DBA. The dedicated `garage_migrator` account was inspected (it holds `ALL PRIVILEGES` on `garage_management_system`) but was not needed.
- Re-running the runner after migration prints `Already applied: …` for all nine files and exits 0 (idempotent).
- Full details and rollback limits are in `PHASE_1_DATABASE_CHANGES.md`.

---

## 4. Defect Fixes

### 4.1 Supplier inventory-history link (defect A)
`public/suppliers/view.php` linked part names to `inventory/history.php?part_id=…` while the original link used a parameter the history page does not read, producing an unfiltered or broken result. The link now uses `part_id`, matching `public/inventory/history.php:8` (`get_int('part_id')`). No other behavior changed.

### 4.2 Customer dashboard 403 (defect B)
The public pages offered a "Dashboard" link to every authenticated user. Customers hold zero staff permissions, so `dashboard.php` correctly returned 403. Rather than granting dashboard permissions to customers, every public navigation/footer link is now conditional:

```php
<?php if (current_user() && can('dashboard.view')): ?> … Dashboard … <?php endif; ?>
```

Authenticated non-staff users see "My Account" (`profile.php`) instead. Applied consistently to `index.php`, `about.php`, `contact.php`, `privacy.php`, `terms.php`, and `services.php`. No roles or permissions were changed.

### 4.3 Installer exposure (defect C)
`public/setup.php` is now inert in normal production:

1. If `storage/installed.lock` exists → HTTP 404 immediately (created during Phase 1: `installed 2026-11-09 (Phase 1 stabilization)`).
2. If `GMS_SETUP_TOKEN` is set, a matching `?token=` value is required (`hash_equals`); otherwise 404.
3. If any user row exists → 404.
4. Database errors during the user-count probe return a generic 503 and log internally (no connection details shown).

The documented fresh-install path remains possible by deliberately removing the lock file and running setup before any user exists. Existing administrators and login were not modified.

### 4.4 Public form abuse protection
New `app/services/RateLimiter.php`:

- Fixed-window, per-key limiter using `storage/rate_limits/`, keyed by `sha256(action|client_ip)` so user-controlled values never become file names.
- `flock(LOCK_EX)` around read/modify/write; stores only `{start,count}`.
- Fails open with an `app_log` entry if storage is unavailable, so the public site cannot be taken down by the limiter.
- Returns `allowed`, `remaining`, `retry_after`.

Integration:

| Endpoint | Limit | Denied response |
|---|---|---|
| `public/register.php` | 5 POSTs / 15 min / IP | HTTP 429 + `Retry-After` + inline alert; no validation or insert |
| `public/contact.php` | 5 POSTs / 10 min / IP | HTTP 429 + `Retry-After` + error banner; no validation or insert |

Both forms also include a hidden `hp_website` honeypot. Submissions that fill it are silently acknowledged without creating a customer, user, or inquiry. CSRF verification still runs globally in `app/bootstrap.php` before any of this logic; normal submissions are unaffected.

### 4.5 Registration insert check
The registration transaction already assigns the new user ID before inserting the linked `customers` row (`$uid = Database::insert(...)`), so `created_by` is populated correctly. No change was required after inspection.

---

## 5. Files Changed

**New**
- `app/services/RateLimiter.php`
- `storage/installed.lock` (runtime lock; git-ignored)
- `docs/phase-1/PHASE_1_IMPLEMENTATION_REPORT.md`
- `docs/phase-1/PHASE_1_DATABASE_CHANGES.md`
- `docs/phase-1/PHASE_1_TEST_RESULTS.md`
- `docs/phase-1/PHASE_1_REMAINING_RISKS.md`

**Modified (application)**
- `public/suppliers/view.php`
- `public/index.php`
- `public/about.php`
- `public/contact.php`
- `public/privacy.php`
- `public/register.php`
- `public/services.php`
- `public/setup.php`
- `public/terms.php`

**Modified (documentation)**
- `documentation/SECURITY_DESIGN.md` — replaced the invalid permission matrix with the verified 40-code × 6-role matrix; corrected CSRF/header claims; documented public-form throttling.
- `documentation/DATA_DICTIONARY.md` — corrected table count, `roles`/`permissions` columns, real permission-code examples, `expenses` CHECK limitation, added `schema_migrations`.
- `documentation/TESTING_REPORT.md` — marked historical results NOT VERIFIED, corrected permission codes, removed the production-ready sign-off claim.
- `documentation/USER_MANUAL.md` — removed published demo passwords; documented the real six roles.
- `documentation/INSTALLATION_GUIDE.md` — removed default credentials, corrected paths/CLI flags, documented installer lock/token behavior.
- `documentation/AUDIT_DESIGN.md` — corrected the role that holds `audit.view`.
- `documentation/DATABASE_DESIGN.md` — added the `customer` role to the role list.

No UI redesign, no architecture change, no dependency installation, no schema-file edits, and no security control was disabled.

---

## 6. Verification Summary

Full commands and outputs are in `PHASE_1_TEST_RESULTS.md`. Headline results:

- PHP syntax lint: **99 files, 0 failures**.
- Safe unit suites: `auth_unit.php`, `inventory_unit.php`, `security_hardening_unit.php` — all pass (with `mbstring`/`openssl` CLI extensions enabled).
- Migration runner re-run: all 9 migrations reported already applied.
- Rate limiting over HTTP: 6th registration and 6th contact attempt returned **429** with `Retry-After`; counters cleaned afterwards.
- Honeypot: bot-shaped registration POST returned 302 to `login.php` without creating a user.
- Installer: `setup.php` returns **404**; public pages return **200**.
- Guest access: `dashboard.php` and `suppliers/view.php` redirect to login (**302**).
- Expenses runtime (rollback-only): insert/read round-trip, rollback, UPDATE/DELETE denied with SQLSTATE 42000, dashboard KPI `ready=true` for an administrator session.
- Priority runtime (rollback-only): default `Normal` and explicit `High` persisted, then rolled back.
- Audit chain: `AuditLogger::verifyChain()` → `ok=true`, 39 records checked.
- Data preservation: backup restored to a scratch database; all 25 unaffected tables produced identical content hashes; the only count differences were the expected new permissions (38→40), role grants (81→85), and migration rows (3→9).

---

## 7. Manual / Operational Steps

1. **Recommended:** after confirming administrator login works, remove `public/setup.php` from production entirely. It is currently locked (404) but no longer needed.
2. Keep `storage/installed.lock` in place. If `GMS_SETUP_TOKEN` is used for a future reinstall, remove it after setup.
3. Confirm the web-server user can write `storage/rate_limits/` (the directory is created automatically; HTTP tests confirmed writes succeed).
4. Retain the Phase 1 backup set until a later verified backup replaces it.
5. Do **not** rerun `database/least_privilege.sql`; it resets account passwords and grants were already verified.
6. If the site is placed behind a reverse proxy/load balancer, revisit `client_ip()`/rate-limit keys (see remaining risks).

---

## 8. Git Recommendation

Commit the 16 modified files, the new `app/services/RateLimiter.php`, and `docs/phase-1/` in a single reviewed commit. Do not commit the backup directory, `my.cnf`, or storage runtime files.

Suggested message:

```
Phase 1: stabilize schema, security, and documentation

- reconcile and record migrations; apply expenses and job priority
- fix supplier history link and dashboard navigation for customers
- lock installer and throttle public registration/contact forms
- reconcile security/data/testing docs with verified live state
```

---

## 9. Stop Point

Phase 1 is complete. No Phase 2 work has been started, and no production-readiness claim is made.
