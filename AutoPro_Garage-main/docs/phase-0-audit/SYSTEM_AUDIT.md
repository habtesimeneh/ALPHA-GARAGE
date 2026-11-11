# SYSTEM AUDIT — Alpha Garage Management System

| Field | Value |
|---|---|
| Audit phase | Phase 0 — Complete System Audit & Technical Assessment |
| Audit date | 2026-11-09 |
| Project root | `C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main` |
| Runtime inspected | PHP 8.3.1 (MAMP, Windows), MySQL/MariaDB via `garage_app` |
| Mode | **Read-only.** No source, schema, configuration, dependency, UI or database state was changed. |
| Auditor roles | Senior PHP/MySQL Architect · Database Analyst · Security Auditor · QA Engineer |

---

## 1. Executive summary

The system is a **native PHP 8.1+ / MySQL garage-management application** with no framework, no Composer and no npm. It is substantially more mature than a typical prototype: it has a coherent three-tier layout, centralized bootstrap security, a complete RBAC model, a tamper-evident audit chain, integer-cent billing arithmetic, a double-entry inventory ledger, and a working job-card lifecycle.

Verified strengths:

- **99 of 99 PHP files pass `php -l`** (zero syntax errors).
- **3 non-destructive unit suites pass** (`auth_unit`, `inventory_unit`, `security_hardening_unit`).
- **Live database is reachable read-only**, contains **27 tables**, **2 administrator accounts**, **39 audit rows**, and the audit hash chain verifies as intact (`ok=true`, `checked=39`).
- Global CSRF verification runs on **every POST** before any page logic or JSON endpoint executes.
- All database access observed uses prepared statements; dynamic SQL clauses are built from fixed strings plus bound parameters.
- Secrets are externalized to git-ignored `app/config/local.php` / environment variables; `.gitignore` and example files are present.

The dominant risks are **deployment-state drift, not code quality**:

1. **Migrations are only partially applied.** `job_cards.priority` is absent (priority feature degrades), and the **`expenses` table plus `expenses.view` / `expenses.manage` permissions are absent**. Because the permission rows do not exist, the entire Expenses module is unreachable — including for the administrator — and financial dashboard KPIs degrade to "not available".
2. **`public/setup.php` is still web-accessible.** It self-locks once a user exists, but leaving an installer in production is unnecessary attack surface.
3. **Customer accounts have no portal and no data linkage.** Registration creates a `users` row and a separate `customers` row with no `customers.user_id` relationship; the `customer` role has **0 permissions**. A registered customer can sign in but can only see the public landing page.
4. **`suppliers/view.php` generates a broken inventory-history link** (`?id=` while `inventory/history.php` reads `part_id`).
5. **Public write endpoints (`register.php`, `contact.php`) have no rate limiting or CAPTCHA**, unlike login which has a lockout table.

There is **no evidence of SQL injection, stored XSS, CSRF bypass, or authentication bypass** in the inspected code paths. The audit chain, append-only triggers, payment immutability, prepared statements, output escaping helper `e()`, and OR-gated permission checks are correctly implemented.

**Phase 0 conclusion: the codebase is a viable foundation. Phase 1 should not add features. It should reconcile the database with the code, close deployment-surface gaps, and fix the small number of confirmed defects.**

---

## 2. Environment and technology stack

| Layer | Technology | Evidence |
|---|---|---|
| Language | PHP 8.1–8.3 features (`declare(strict_types=1)`, enums not used, typed properties, first-class callables not used) | all files |
| Web server | Apache (MAMP) with `.htaccess` denies for `app/`, `storage/`, `tests/`, `database/` | `.htaccess`, subdirectory `.htaccess` files |
| Database | MySQL/MariaDB, InnoDB, `utf8mb4_unicode_ci` | `database/database.sql:9-11` |
| Frontend | Server-rendered semantic HTML5, 17 CSS files, 10 vanilla ES6 modules, vendored Chart.js | `public/assets/` |
| Dependencies | **None declared.** No `composer.json`, no `package.json`, no lockfiles | project root |
| Session | Native PHP sessions, hardened cookie flags | `app/bootstrap.php:44-64` |
| Money | Integer cents in PHP, `DECIMAL(12,2)` in DB, ETB, 15% VAT | `app/helpers/functions.php:211`, settings |
| Mail | Socket SMTP client with STARTTLS/SSL; native `mail()`; dev spool file | `app/services/MailService.php` |

### Application directory layout

```
app/
  bootstrap.php            config → autoload → error handler → headers/CSP → session → remember-me → CSRF
  config/                  app.php, database.php, local.php (git-ignored), mail.php
  helpers/functions.php    escaping, CSRF, validation, money, pagination, CSV, URL helpers
  middleware/              auth.php, permissions.php
  models/Database.php      PDO wrapper: prepared statements, strict SQL mode, re-entrant transactions
  services/                18 domain services (auth, jobs, inventory, invoices, payments, audit, reports, …)
  views/layout.php         app shell: permission-gated sidebar, bell, notifications, theme, modals
public/                    web root; 63 PHP route files
database/                  database.sql (authoritative schema + seeds), migrate.php, 9 migrations, least_privilege.sql
documentation/             13 design/manual documents
storage/                   logs, mail spool, private keys (denied by web server)
tests/                     3 unit suites (safe) + run_tests.php (destructive, gated)
```

---

## 3. Codebase inventory (measured)

| Metric | Value |
|---|---|
| PHP files (excluding `storage/`) | **99** |
| PHP lines of code | **11,358** |
| PHP lint failures | **0** |
| Public route files | **63** |
| Domain services | **18** |
| CSS files | **17** |
| JavaScript modules | **10** |
| SQL migrations | **9** |
| Base schema tables | **27** live (28 defined in `database.sql`, incl. `expenses`) |
| Baseline permissions | **38** live (40 seeded in `database.sql`) |
| Roles | **6** (administrator, receptionist, mechanic, inventory_manager, accountant, customer) |

---

## 4. Verification performed (and results)

All verification was **observational or non-mutating**. Nothing was installed, migrated, seeded, or edited outside `docs/phase-0-audit/`.

| # | Check | Method | Result |
|---|---|---|---|
| V1 | PHP syntax | `php -l` on all 99 files | **PASS** — 0 failures |
| V2 | Authentication logic | `tests/auth_unit.php` | **PASS** — "Authentication unit tests passed." |
| V3 | Inventory arithmetic | `tests/inventory_unit.php` | **PASS** — "Inventory unit tests passed." |
| V4 | Hardening helpers | `tests/security_hardening_unit.php` | **PASS** — "Security hardening unit tests passed." |
| V5 | DB connectivity | read-only PDO connection as `garage_app` | **PASS** |
| V6 | Table inventory | `SHOW TABLES` + per-table `COUNT(*)` | 27 tables; all operational tables empty |
| V7 | Audit chain | `AuditLogger::verifyChain()` | **PASS** — `ok=true`, 39/39 intact, head `last_id=39` |
| V8 | Route/asset integrity | scan every `url()`/`asset()` target | **PASS** — no missing files |
| V9 | Migration history | `SELECT * FROM schema_migrations` | **NOT VERIFIED** — `garage_app` has no SELECT grant (SQLSTATE 42000 / 1142) |
| V10 | Applied migration inference | column/table/permission presence | **PARTIAL** — see `DATABASE_STRUCTURE.md` §7 |
| V11 | Full integration suite | `tests/run_tests.php` | **NOT RUN** — destructive; requires `GMS_ALLOW_DESTRUCTIVE_TESTS=1`, a `*_test` database and admin/demo passwords |
| V12 | Browser/UI runtime | manual HTTP session | **NOT PERFORMED** — Phase 0 was a static/read-only code and DB audit |

> The three suites in V2–V4 are self-contained unit tests that do not truncate tables. `tests/run_tests.php` **was deliberately not executed** because it is destructive by design.

---

## 5. Architecture assessment

### 5.1 Bootstrap and request pipeline

`public/*.php` files consistently follow: `require bootstrap.php` → `require layout.php` → `require_login()` → `require_permission(...)` → handle `is_post()` → render.

The bootstrap order (`app/bootstrap.php`):

1. Environment/config (throws on missing DB credentials rather than falling back to insecure defaults).
2. Autoloader for `app/` classes.
3. Error/exception handlers — production returns a **generic** 500 page; details go to `storage/logs`.
4. Security headers: `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, restrictive `Permissions-Policy`, and a strict CSP with `script-src 'self'`, `frame-ancestors 'none'`, `base-uri 'self'` (`app/bootstrap.php:38-43`).
5. Hardened session cookie (`HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, `use_strict_mode`) then `session_start()`.
6. Remember-me restore.
7. **Global CSRF check for every POST** (`app/bootstrap.php:64` → `verify_csrf()`).

**Assessment:** Correct ordering. CSRF cannot be skipped by an individual endpoint. The CSP is unusually strong for a server-rendered app and forbids inline scripts — which the code honors.

### 5.2 Data access layer

`app/models/Database.php`:

- PDO with `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES=false` (server-side prepares), `STRINGIFY_FETCHES=false`.
- Per-connection `SET time_zone` and strict `sql_mode` (`STRICT_ALL_TABLES`, `NO_ZERO_DATE`, `NO_ENGINE_SUBSTITUTION`, `ERROR_FOR_DIVISION_BY_ZERO`).
- Re-entrant `transaction()` with savepoint semantics.
- Only `one/all/value/run/insert`; no raw query helper exposed to views.

**Assessment:** Strong. Real prepared statements remove the common emulation-based injection class. `ORDER BY`/dynamic fragments are always fixed literals chosen by code, not user input.

### 5.3 Service layer

Business rules live in 18 services rather than route files. Verified representative logic:

- `JobService`: explicit transition map (`app/services/JobService.php:7-14`), cancel-with-invoice block, deliver-without-invoice block, parts auto-return on cancel, object-level access checks.
- `InventoryService`: row locks (`FOR UPDATE`), integer quantity validation, opening-stock ledger entry, atomic multi-part issue (`issueMany`, max 5 unique parts), return on cancellation.
- `InvoiceService`: invoice only for `Completed`/`Delivered` (`:28`), one invoice per job (`job_card_id UNIQUE`), integer-cent recalculation, discount clamp, payment-aware sync refusal.
- `PaymentService`: overpayment rejected (`:17`), refund requires reason and cannot exceed net paid (`:26-40`), inserts only (no update/delete path).
- `AuditLogger`: chained SHA-256 append under `SELECT … FOR UPDATE` on a single head row (`:31`), field redaction (`:15`, `:129-130`), paged chain verification (`:64`).

**Assessment:** This is the strongest part of the system. Domain invariants are centralized and mostly tested.

### 5.4 Presentation layer

- `app/views/layout.php` gates every sidebar link by permission, renders low-stock bell via `InventoryService::lowStock(99)`, notification dropdown, theme toggle, and a confirm modal.
- All dynamic output observed uses `e()`.
- JavaScript is progressive enhancement: `validation.js`, `job-card-form.js`, `inventory-issue.js`, `dashboard.js` (Chart.js AJAX fragment), `luxury.js` (public site).

---

## 6. Security assessment

### 6.1 Authentication

| Control | Implementation | Verdict |
|---|---|---|
| Password hashing | `password_hash(PASSWORD_DEFAULT)`; rehash-on-login | PASS |
| Timing attack defense | `password_verify` always executed against a `DUMMY_HASH` when user not found | PASS |
| Lockout | 5 failures per username+IP and 20 per IP / 15 min (`login_attempts`) | PASS (code) |
| Session fixation | `session_regenerate_id(true)` before authenticated state is installed | PASS |
| Idle timeout | 30 min (`app/middleware/auth.php:40-43`) | PASS |
| Absolute lifetime | 8 h (`:43`) | PASS |
| Session binding | SHA-256 of User-Agent, `hash_equals`; **no IP binding** | PASS (documented trade-off: mobile IP changes won't lock users out) |
| Forced password change | `must_change_password` redirect (`:55`) | PASS |
| Remember-me | 256-bit token, SHA-256 stored, rotation on use, 30-day expiry | PASS |
| Login error messages | Generic "invalid credentials" for all failure modes | PASS |
| Registration password policy | min 10, upper, lower, digit, symbol, not username | PASS |
| Password reset | 256-bit token, SHA-256 at rest, 30 min, single use, prior tokens invalidated, generic response | PASS |
| Customer role access | `customer` role has **0 permissions**; staff pages deny | PASS |

### 6.2 Authorization

- Permission codes are `resource.action`; `can()`, `can_any()`, and variadic **OR** gate `require_permission(...$any)`.
- `deny()` writes an `authorization_denied` audit record, then aborts 403 — denials are auditable.
- Object-level checks exist for mechanics (`JobService::canAccess`): `jobs.view_all` OR (`jobs.view_assigned` AND matching `mechanic_id`).
- `public/job-cards/data.php` applies an internal gate (`jobs.edit` when an `id` is supplied, otherwise `jobs.create`) in addition to authentication.
- `public/job-cards/action.php` re-checks the specific permission per action (`jobs.progress`, `jobs.assign`, `jobs.request_parts`) after loading the job.
- `public/users/roles.php` locks the `administrator` role so the system cannot be left without an administrator; submitted codes are intersected with the DB whitelist.

**Verdict:** RBAC is consistently enforced server-side, including on JSON endpoints. No privilege-escalation path was found in inspected code.

### 6.3 CSRF, XSS, SQL injection

| Risk | Finding |
|---|---|
| CSRF | Global POST verification; per-session token with 2-hour TTL; failures audited (`csrf_validation_failed`) and return 419. **No bypass found.** |
| XSS | Output escaped with `e()`; CSP blocks inline script and external script origins. No unescaped user-data sink found in inspected templates. CSV export guards formula injection and prefixes BOM. |
| SQLi | Prepared statements throughout; `LIKE` inputs escaped via `like_escape()`; dynamic filters are fixed fragments. No concatenated user input found. |
| IDOR | Job cards and appointments enforce ownership/role checks. **Residual:** `vehicles/view.php` shows full vehicle + owner details to anyone holding `vehicles.view`; there is no per-customer scoping because customer scoping does not exist (see R-06). |

### 6.4 Cryptography at rest

`CryptoService` implements AES-256-GCM (`v1:iv:tag:ciphertext`) with a key from `GMS_ENCRYPTION_KEY` or an auto-generated `storage/private/encryption.key`, plus HMAC-SHA-256 blind indexes.

**Finding:** the service is referenced **only by tests** (`tests/run_tests.php`, `tests/security_hardening_unit.php`). It is **not wired into any production read/write path**. No `encryption.key` exists. Sensitive columns (phone, email, VIN) are stored in plaintext. This is a documented capability, not an active control. Do not claim field-level encryption in any compliance statement.

### 6.5 Audit trail

- Hash chain: each row stores `prev_hash` and `row_hash`; single-row `audit_chain_head` serializes appends with `FOR UPDATE`.
- Append-only enforced by DB triggers on UPDATE and DELETE (`trg_audit_no_update`, `trg_audit_no_delete`), independent of application code.
- Sensitive keys redacted before serialization.
- Runtime verification confirmed **39/39 records intact**.

**Residual risk:** deletion of the entire table by a privileged DBA is still possible; the chain detects tampering but does not prevent wholesale loss. Off-box export/backup of the chain head is not implemented.

### 6.6 Transport, headers, secrets

- CSP, `frame-ancestors 'none'`, `nosniff`, `no-store` on responses.
- HSTS is **not set** by the app (would be a web-server concern; **NOT VERIFIED** at the Apache level).
- Secrets: DB credentials and mail credentials come from environment or git-ignored `local.php`; `.env.example` and `local.php.example` contain placeholders only. No secret values were printed during the audit.
- The runtime DB account `garage_app` is least-privilege: it cannot read `schema_migrations`, has no UPDATE/DELETE on `payments`/`audit_logs`, and insert-only on `expenses`. This was observed directly (1142 errors) and is a **positive** control.

### 6.7 Public attack surface

| Endpoint | Finding |
|---|---|
| `public/setup.php` | **Present in web root.** Self-locks when any user exists or `storage/installed.lock` exists. Should be removed or server-blocked after install. |
| `public/register.php` | Open self-registration; validates and enforces policy; **no rate limit, no CAPTCHA, no email verification**. Creates `users` + `customers` rows without linking them. |
| `public/contact.php` | Stores inquiries; generic success message; **no rate limit**. |
| `public/forgot-password.php` | Anti-enumeration response; dev reset link exposed only when transport is `dev_spool` **and** debug is on. |
| `public/login.php` | Maps DB outage to a safe message; `redirect_url` guarded against absolute URLs. |
| Remote assets | Landing/auth pages load six images from `images.unsplash.com`; CSP permits that origin. Privacy/availability dependency; offline installs lose imagery. |

---

## 7. Data integrity and business logic

Verified correct:

- **Money:** PHP-side integer cents; DB `DECIMAL(12,2)`; 15% tax from settings; discount clamp; overpayment block; refund ≤ net paid.
- **Inventory:** quantity can never go negative at the service layer; every change writes `inventory_transactions` with `balance_after`; opening stock is ledgered; parts are returned when a job is cancelled.
- **Invoice/job coupling:** one invoice per job (`UNIQUE`), no delivery without an invoice, no cancellation after invoicing, no silent re-sync once payments exist.
- **Numbering:** `APT`, `JOB`, `INV`, `RCT`, `RFD` generated through a shared `Numbering` helper.
- **Appointments:** customer/vehicle ownership validated before insert; 1-click convert respects job rules.

Confirmed defects and inconsistencies:

1. **Broken link (confirmed):** `public/suppliers/view.php:70` uses `inventory/history.php?id=...`, but `public/inventory/history.php:7` reads `part_id`. Result: the history page opens unfiltered instead of filtered by part.
2. **Expenses unreachable (confirmed):** live DB lacks `expenses` and both `expenses.*` permissions, so `public/expenses/index.php` is denied at `require_permission('expenses.view')` for every user, including administrator.
3. **Priority column missing (confirmed):** `job_cards.priority` does not exist; `JobService` guards with `hasPriorityColumn()`, so the feature silently degrades.
4. **Customer linkage missing (confirmed):** no `customers.user_id`; `register.php` inserts both rows but never associates them. Portal features are impossible without a schema decision.
5. **Customer dashboard link (confirmed):** landing page renders a "Dashboard" link to any signed-in user (`public/index.php:104`, `:599`); the `customer` role has no `dashboard.view`, so the link lands on 403.
6. **Documentation drift (confirmed):** `documentation/SECURITY_DESIGN.md:123-132` lists permission codes that do not exist (`jobs.update_status`, `jobs.assign_mechanic`, `inventory.adjust`, `invoices.pay`, `invoices.refund`); `documentation/DATA_DICTIONARY.md:68` cites `invoices.pay`; `documentation/TESTING_REPORT.md:61` cites it too. Historical test results in that report are **NOT VERIFIED**.
7. **Schema duplication:** `spare_parts.quantity` is a cached balance that must agree with the last `inventory_transactions.balance_after`; no CHECK or trigger enforces agreement. The service layer keeps them consistent, but direct SQL could diverge.
8. **Legacy supplier text:** both `spare_parts.supplier` (VARCHAR) and `spare_parts.supplier_id` (FK) exist. Reports and purchase flows may read different fields.

---

## 8. Performance and scalability

| Area | Observation | Assessment |
|---|---|---|
| Indexes | Job status/mechanic/vehicle/customer/date, invoice status/customer/date, payment date, inventory part+date, audit module/record/date all indexed | Good for current scale |
| N+1 queries | Dashboard aggregates in SQL; list pages join; low-stock bell runs one query per layout render | Acceptable |
| Audit append | Global serialization via single-row `FOR UPDATE` | Correct but a write-rate ceiling; fine for one garage |
| Pagination | Shared `paginate()` helper, `per_page` = 15 | Good |
| Report export | CSV streamed via `send_csv` | Good |
| Session storage | Files | Fine for single node; blocks horizontal scaling |
| Encoding | `utf8mb4`, real prepares | Good |
| Front-end | Vanilla JS, vendored Chart.js, no build step | Fast; no supply-chain surface |

No premature optimization is warranted. The only architectural scaling limits are the serialized audit head and file-based sessions; neither matters at one-branch scale.

---

## 9. Deployment and operations

| Item | State |
|---|---|
| Migration runner | `database/migrate.php` — CLI-only, checksum table `schema_migrations`, forward-only, custom SQL splitter. Historical log shows `SQLSTATE HY000 2014` (unbuffered queries) failures during a migration run. **Current applied set could not be read** (`schema_migrations` not grantable to `garage_app`). |
| Least privilege | `database/least_privilege.sql` defines `garage_app` and `garage_migrator`. Live `garage_app` restrictions observed. |
| Installer | `public/setup.php` + `storage/installed.lock` |
| Backups | **NOT VERIFIED** — no backup script/runbook found in the repository |
| Logging | `storage/logs/app.log`, `php-error.log`; historical errors present but not reproducible in current code |
| Health checks | `database/verify_auth_database.php` (admin-facing utility) |
| Debug flag | `debug=false` in `app/config/app.php`; error display follows debug flag |
| Timezone | App `Africa/Addis_Ababa`; DB session `+03:00` |
| HTTPS | **NOT VERIFIED** at Apache level; app sets `Secure` cookie only when HTTPS is detected |

### Historical log findings (not reproduced)

`storage/logs/app.log` contains entries dated 2026-11-08 for:

- "Could not regenerate session ID after authentication" (older `AuthService` line numbers 46/49; current regeneration is at lines 86–88).
- `max(): Argument #1 … int given` at old `dashboard.php:659/662` (current `dashboard.php` is 231 lines and contains no such construct).
- Two `Database migration failed: SQLSTATE HY000 2014` entries.

These are recorded as **historical and unreproduced**. They are not counted as current defects, but the migration-runner fragility is a real operational risk (R-09).

---

## 10. Findings register

Severity: **Critical / High / Medium / Low / Info**. No Critical findings were verified.

| ID | Sev | Area | Finding | Evidence | Impact |
|---|---|---|---|---|---|
| F-01 | High | Deployment | `expenses` table and `expenses.*` permissions missing; module unreachable; financial KPIs degraded | live DB; `public/expenses/index.php:6`; `public/dashboard.php:164-169` | Missing functionality; misleading dashboard |
| F-02 | High | Deployment | `public/setup.php` reachable in production | `public/setup.php:11-13` | Installer surface; relies on lock file |
| F-03 | High | Data model | No `users`↔`customers` linkage; customer role has 0 permissions | `public/register.php:88-100`; role counts | No customer portal possible |
| F-04 | Medium | Bug | Supplier → inventory history link uses wrong parameter | `public/suppliers/view.php:70` vs `public/inventory/history.php:7` | Link silently unfiltered |
| F-05 | Medium | Deployment | `job_cards.priority` missing; priority feature degrades silently | live DB; `JobService::hasPriorityColumn()` | UX feature absent |
| F-06 | Medium | Abuse | No rate limit/CAPTCHA on registration and contact forms | `public/register.php`, `public/contact.php` | Spam/account flooding; DB growth |
| F-07 | Medium | Privacy/availability | Landing/auth pages depend on remote Unsplash images | `public/index.php`, `public/login.php`, CSP `img-src` | Offline breakage; third-party requests |
| F-08 | Medium | Documentation | Permission names and a role in docs do not match code (`jobs.update_status`, `invoices.pay`, `inventory.adjust`, `jobs.assign_mechanic`, `invoices.refund`) | `documentation/SECURITY_DESIGN.md:123-132`; `DATA_DICTIONARY.md:68`; `TESTING_REPORT.md:61` | Misleading audits/onboarding |
| F-09 | Medium | Ops | Migration history unreadable by runtime account; drift detected; runner previously hit error 2014 | 1142 on `schema_migrations`; `storage/logs/app.log` | Cannot prove schema version; upgrade risk |
| F-10 | Low | Auth UX | Landing page shows "Dashboard" to customers who get 403 | `public/index.php:104`, `:599` | Confusing dead link |
| F-11 | Low | Data integrity | Cached `spare_parts.quantity` can diverge from ledger if modified outside services | schema + `InventoryService` | Reporting drift risk |
| F-12 | Low | Schema legacy | Dual supplier representation (`supplier` text + `supplier_id`) | `database/database.sql:235-236` | Ambiguous reporting |
| F-13 | Low | Crypto | AES-GCM/blind-index service unused in production; sensitive fields plaintext | only referenced in `tests/` | Overstated security expectations |
| F-14 | Low | Audit | No off-box export of audit head; full-table deletion would not be detectable locally | `AuditLogger` design | Compliance gap |
| F-15 | Info | Code | 99/99 lint clean; 3 unit suites pass; audit chain intact; no SQLi/XSS/CSRF bypass found | V1–V8 | Positive assurance |
| F-16 | Info | Schema | `database.sql` `DROP TABLE` list omits `contact_inquiries` (table uses `IF NOT EXISTS`) | `database/database.sql:14`, `:568` | Re-import preserves inquiries (may be intentional) |

---

## 11. What could not be verified (NOT VERIFIED)

1. **Applied migration set.** `schema_migrations` returns 1142 for `garage_app`. Applied migrations were inferred from columns/tables/permissions only. Exact checksums and ordering are **NOT VERIFIED**.
2. **Full integration test suite.** `tests/run_tests.php` was not executed (destructive gate). End-to-end workflow results are **NOT VERIFIED**.
3. **Browser/UI behavior.** No HTTP session was driven in a browser during Phase 0. Responsive layout, carousels, AJAX dashboard refresh, combobox behavior, and print/PDF rendering are **NOT VERIFIED** visually.
4. **HTTPS/HSTS at the web server.** Not inspected at Apache configuration level.
5. **Email delivery.** SMTP connectivity and real inbox delivery were not exercised. Only code paths and dev-spool behavior were read.
6. **Backup/restore and disaster recovery.** No runbook or script found; recoverability is **NOT VERIFIED**.
7. **Concurrency/load behavior.** Row-lock correctness is reasoned from code, not load-tested.
8. **Database `EXPLAIN` plans at volume.** Tables are empty; real-world query plans are **NOT VERIFIED**.
9. **Historical test/documentation claims.** `documentation/TESTING_REPORT.md` results predate this audit and were not re-executed; they are **NOT VERIFIED**.
10. **Third-party service availability** (Unsplash, SMTP, Telebirr/Chapa gateways). No gateway integration exists in code — payment methods are recorded as text only.

---

## 12. Phase 0 constraint compliance

| Constraint | Status |
|---|---|
| No source file modified | **CONFIRMED** — only `docs/phase-0-audit/` was created |
| No PHP/HTML/CSS/JS/SQL/config changed | **CONFIRMED** |
| No migration created or executed | **CONFIRMED** |
| No table/record created, altered, dropped, truncated, or deleted | **CONFIRMED** — all DB access was `SHOW`, `SELECT`, `COUNT`, and `AuditLogger::verifyChain()` (read-only) |
| No credentials/config/env changed | **CONFIRMED** |
| No dependency installed/upgraded | **CONFIRMED** |
| No UI/layout change | **CONFIRMED** |
| No feature implemented | **CONFIRMED** |
| No Git push / architecture rebuild | **CONFIRMED** |
| Exactly five audit documents created | **CONFIRMED** — see folder listing |

---

## 13. Recommended Phase 1 focus (summary; detail in `IMPLEMENTATION_ROADMAP.md`)

1. Establish a trustworthy migration baseline and reconcile live schema with code (`expenses`, `priority`), granting `garage_app` the exact live permissions.
2. Remove or hard-block `public/setup.php` in deployed environments; keep it only for fresh installs.
3. Fix confirmed defects F-04 (supplier link) and F-10 (customer dashboard link).
4. Decide the customer identity model (link `users`↔`customers` or explicitly defer a portal) before any portal work.
5. Add rate limiting + bot protection to `register.php` and `contact.php`.
6. Align `documentation/` with the real permission catalogue, or mark the stale documents as superseded.
7. Add an audit-head export and a backup/restore runbook; make migration history readable by a dedicated admin tool.

No Phase 1 work has been started.
