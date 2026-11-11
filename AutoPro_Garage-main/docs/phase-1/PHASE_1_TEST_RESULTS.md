# Phase 1 — Test Results

**Date:** 2026-11-09
**Environment:** MAMP, Apache local, PHP 8.3.1 CLI (`C:\MAMP\bin\php\php8.3.1\php.exe`), MySQL 5.7.24.
CLI commands include `-d extension_dir="C:/MAMP/bin/php/php8.3.1/ext" -d extension=pdo_mysql`; the auth and crypto suites additionally required `-d extension=mbstring` and `-d extension=openssl` (the MAMP web SAPI already has the full extension set).

All tests below were read-only or used rolled-back transactions. No destructive integration suite was run against the live database.

---

## 1. PHP Syntax Lint

Command pattern:

```bash
for f in $(find app public database tests -name '*.php'); do php -l "$f"; done
```

Result:

```
lint_total=99 lint_failures=0
```

All application, public, database, and test PHP files are syntactically valid.

---

## 2. Safe Unit Suites

| Command (relative to project root) | Result |
|---|---|
| `php tests/auth_unit.php` | `Authentication unit tests passed.` (exit 0) |
| `php tests/inventory_unit.php` | `Inventory unit tests passed.` (exit 0) |
| `php tests/security_hardening_unit.php` | `Security hardening unit tests passed.` (exit 0) |

Coverage exercised: password hashing/complexity, CSRF token validity/expiry, contact-inquiry validation, encryption-key validation, AES-256-GCM roundtrip, inventory input rejection (negative/oversized quantities, invalid adjustment direction, invalid issue payloads).

The first two runs without `mbstring`/`openssl` failed inside PHP itself (`undefined function mb_strlen()` / `openssl_encrypt()`), not in application logic. With MAMP's extensions enabled all three suites pass.

### Not run: `tests/run_tests.php`

This is a **destructive** integration suite that refuses to run unless the configured database name ends in `_test` and `GMS_ALLOW_DESTRUCTIVE_TESTS=1` is set. It was deliberately not executed against `garage_management_system`. Consequently, full end-to-end HTTP workflows (invoice math, multi-payment settlement, stock deduction via UI, IDOR responses) were **not re-verified in Phase 1**.

---

## 3. Migration Verification

Runner re-run (with approved DBA credentials supplied via environment; nothing printed):

```
Already applied: 20261108_expense_ledger.sql
Already applied: 20261109_job_card_priority.sql
Already applied: add_remember_token_to_users.sql
Already applied: alpha_garage_brand.sql
Already applied: contact_inquiries.sql
Already applied: enterprise_upgrade.sql
Already applied: password_resets.sql
Already applied: revoke_customer_staff_access.sql
Already applied: widen_login_attempt_username.sql
exit=0
```

State read through the application runtime:

- `schema_migrations` rows: **9**
- `expenses` columns: `id, category, description, amount, expense_date, reference, recorded_by, created_at`
- `job_cards.priority`: `enum('Low','Normal','High','Urgent')`, `NOT NULL`, default `Normal`
- Permissions: **40**
- Role permission counts: administrator 40, receptionist 20, mechanic 7, inventory_manager 9, accountant 9, customer 0
- Users: 2; audit rows: 39

---

## 4. Runtime Authorization and Schema Behavior (rollback-only)

Executed as `garage_app@localhost` through the application bootstrap:

```
runtime_user=garage_app@localhost
perm_count=40
expense_perms=expenses.manage,expenses.view
migrations_visible=9
expense_rows=0
dashboard_expense_ready=false        (CLI has no authenticated session)
expense_txn_rolled_back=true
expense_rows_after_rollback=0
append_only_UPDATE=DENIED(42000)
append_only_DELETE=DENIED(42000)
priority_persistence_rolled_back=true
job_cards_after_rollback=0
customers_after_rollback=0
vehicles_after_rollback=0
audit_chain_ok=true
audit_chain_checked=39
```

With a simulated administrator session (`$_SESSION['uid']` set before `current_user()` was first called):

```
user_role=administrator
can_dashboard=true
can_expenses=true
expense_ready=true
expense_total=0
```

This confirms the expenses KPI no longer degrades for authorized users; the earlier `ready=false` is expected without a session because `expenseTotals()` requires `can('expenses.view')`.

`expenses` UPDATE/DELETE denial confirms the runtime account still cannot mutate append-only financial records.

---

## 5. Public Endpoint Rate Limiting (HTTP)

Server: `http://localhost/AutoPro_Garage-main/AutoPro_Garage-main/public/`

### Registration — 5 per 15 minutes per IP

| Attempt | HTTP status |
|---|---|
| 1–5 | 200 |
| 6 | **429** |

Response body contained:

```
Too many registration attempts from this network. Please try again in 15 minute(s).
```

Response headers on the blocked request:

```
HTTP/1.1 429 Too Many Requests
Retry-After: 886
```

### Contact — 5 per 10 minutes per IP

| Attempt | HTTP status |
|---|---|
| 1–5 | 200 |
| 6 | **429** |

Response body contained:

```
Too many inquiries from this network. Please wait 10 minute(s) before trying again.
```

All test submissions used invalid/empty required fields, so no user, customer, or inquiry was created. Rate-limit counter files created by the tests were removed afterwards; `storage/rate_limits/` is empty.

### Honeypot

A registration POST with `hp_website=bot` (and otherwise empty fields) returned:

```
HTTP/1.1 302 Found
Location: /AutoPro_Garage-main/AutoPro_Garage-main/public/login.php
```

No validation ran and no account was created. The honeypot is checked before the limiter, so it behaves the same even when the IP is already throttled.

---

## 6. Installer and Public Page Access

| Request | Result |
|---|---|
| `GET /public/setup.php` | **404** (lock file present) |
| `GET /public/register.php` | 200 |
| `GET /public/contact.php` | 200 |
| `GET /public/index.php` as guest | 200; no "Dashboard" link rendered; "Get Started" CTA present |
| `GET /public/dashboard.php` as guest | **302** → `login.php` |
| `GET /public/suppliers/view.php?id=1` as guest | **302** → `login.php` |

---

## 7. Supplier Inventory-History Link

Static contract verification:

- `public/suppliers/view.php:70` builds `inventory/history.php?part_id=<id>`.
- `public/inventory/history.php:8` reads the filter with `get_int('part_id')` and applies `t.part_id = ?`.

The link and destination parameter now match. A live authenticated HTTP click-through was not performed because no supplier page session was available during Phase 1; the contract was verified from source.

---

## 8. Data Preservation

Method: restore `full_before.sql` into a temporary scratch database (database name rewritten only in a temporary copy), then compare against live.

| Check | Result |
|---|---|
| Scratch tables restored | 28 |
| Row-count differences | 3 expected: `permissions` 38→40, `role_permissions` 81→85, `schema_migrations` 3→9 |
| Content hash comparison (shared columns) | **25 tables checked, 0 mismatches** |
| Tables checked included | users, roles, customers, vehicles, job_cards (shared columns), invoices, invoice_items, payments, inventory_transactions, spare_parts, suppliers, services, audit_logs, audit_chain_head, garage_settings |
| Scratch cleanup | Database dropped; temporary SQL copy deleted |

The three count differences are exactly the rows created by the two approved migrations and their bookkeeping (2 permissions, 4 role grants, 6 migration records), plus none in any business table.

---

## 9. Documentation Verification

- The permission matrix in `documentation/SECURITY_DESIGN.md` was regenerated from live `permissions` and `role_permissions` data (40 codes × 6 roles).
- `documentation/TESTING_REPORT.md` is marked historical / NOT VERIFIED and no longer claims production readiness.
- Published demo passwords were removed from `USER_MANUAL.md` and `INSTALLATION_GUIDE.md`.
- `DATA_DICTIONARY.md` now reflects 29 tables, the real `roles`/`permissions` columns, and the MySQL 5.7 CHECK limitation.

---

## 10. Explicitly NOT Verified in Phase 1

- Full destructive integration suite (`tests/run_tests.php`) against a dedicated `_test` database — not executed.
- Authenticated HTTP session for a real `customer` user — the live database has no customer user; customer behavior is verified from role-permission data (`customer` holds 0 permissions) and the `can('dashboard.view')` navigation guard.
- Legitimate contact-form submission end-to-end (would create a live inquiry) — validation and code path reviewed, insertion not executed against live.
- Rate-limiter fail-open behavior under an unwritable storage directory — code inspected; not simulated in production.
- Cross-browser UI rendering — no UI redesign was made; only conditional navigation and an inline alert were added.
