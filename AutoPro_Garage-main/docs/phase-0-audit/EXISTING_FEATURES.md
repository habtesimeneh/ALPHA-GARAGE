# EXISTING FEATURES — Alpha Garage Management System

Audit date: **2026-11-09** · Mode: **read-only**

Status legend:

| Status | Meaning |
|---|---|
| **WORKING** | Implemented end-to-end in code; server-side rules present; no blocking defect found; unit-level verification passed where applicable. Browser-level confirmation may still be **NOT VERIFIED** (noted per item). |
| **PARTIALLY WORKING** | Implemented but degraded by missing schema/permissions, missing admin surface, external dependency, or a confirmed minor defect. |
| **BROKEN** | Present but produces a wrong/unusable result. |
| **MISSING** | No implementation exists. |
| **NOT VERIFIED** | Implementation exists but behaviour could not be exercised safely in Phase 0. |

---

## 1. Feature status matrix

| # | Module / capability | Status | Evidence |
|---|---|---|---|
| 1 | Staff login (username or email) | WORKING | `app/services/AuthService.php`; `tests/auth_unit.php` PASS |
| 2 | Logout and session destruction | WORKING | `public/logout.php`; `AuthService::destroySession()` |
| 3 | Idle (30 min) / absolute (8 h) session expiry + UA binding | WORKING | `app/middleware/auth.php:40-43` |
| 4 | Login lockout (5 per user+IP, 20 per IP / 15 min) | WORKING | `AuthService`, `login_attempts` (21 historical rows) |
| 5 | Remember-me (hashed token, rotation, 30 days) | WORKING | migration applied; `AuthService::restoreFromRememberCookie()` |
| 6 | Forced password change (`must_change_password`) | WORKING | `app/middleware/auth.php:55` |
| 7 | Self-service password reset | PARTIALLY WORKING | token flow correct (`PasswordResetService`); SMTP delivery **NOT VERIFIED**; only `dev_spool` was designed for local |
| 8 | Profile view / change own password | WORKING | `public/profile.php` |
| 9 | Role-based access control (38 permission codes) | WORKING | `app/middleware/permissions.php`; live DB |
| 10 | Role permission editor (admin role locked) | WORKING | `public/users/roles.php`; live behaviour |
| 11 | User management (create/edit/activate) | WORKING | `public/users/index.php`, `form.php` |
| 12 | Garage settings management | WORKING | `public/users/settings.php` (`settings.manage`) |
| 13 | Tamper-evident audit log + CSV export | WORKING | chain verified 39/39; `public/audit-logs/index.php:24-37` |
| 14 | Dashboard KPIs, charts, range comparison | PARTIALLY WORKING | charts need browser check; expenses KPIs degrade because ledger absent (`public/dashboard.php:164-169`) |
| 15 | Customer CRUD + deactivate/reactivate | WORKING | `public/customers/*` |
| 16 | Vehicle CRUD + deactivate/reactivate | WORKING | `public/vehicles/*` |
| 17 | Vehicle repair history | PARTIALLY WORKING | `public/vehicles/view.php` filters mechanics correctly; `vehicles.view` grants access to any vehicle record |
| 18 | Service catalogue (CRUD, pricing, active toggle) | WORKING | `public/services/*`; 8 seeded services |
| 19 | Supplier CRUD + detail view | PARTIALLY WORKING | CRUD works; **link to part history is broken** (`public/suppliers/view.php:70`) |
| 20 | Spare parts master + low-stock detection | WORKING | `InventoryService::lowStock`, layout bell |
| 21 | Stock purchase / adjustment with ledger | WORKING | `InventoryService::purchase/adjust`; `inventory/stock.php` |
| 22 | Issue parts to job (single and bulk, max 5) | WORKING | `InventoryService::issue/issueMany`; `tests/inventory_unit.php` PASS |
| 23 | Parts request workflow (pending → issued/rejected) | WORKING | `part_requests`; `job-cards/action.php` |
| 24 | Return parts on job cancellation | WORKING | `InventoryService::returnJobParts`; `JobService::changeStatus` |
| 25 | Inventory transaction history | PARTIALLY WORKING | page works; filter-by-part fails when reached from supplier page (`history.php` expects `part_id`) |
| 26 | Job card creation (customer/vehicle validation, services) | WORKING | `JobService::create` |
| 27 | Job card status workflow with enforced transitions | WORKING | `JobService::TRANSITIONS` (`:7-14`) |
| 28 | Mechanic assignment | WORKING | `jobs.assign`; `job-cards/action.php` |
| 29 | Labor logging (hundredths of hour × rate) | WORKING | `JobService::addLabor` |
| 30 | Job timeline / updates | WORKING | `job_updates`; `job-cards/view.php` |
| 31 | Mechanic object-level job visibility | WORKING | `JobService::canAccess`; `vehicles/view.php` history filter |
| 32 | Job priority (Low/Normal/High/Urgent) | PARTIALLY WORKING | UI/service guard exists; **column absent live** → feature silently off |
| 33 | Mechanic profiles and availability | WORKING | `public/mechanics/*` |
| 34 | Appointment booking + confirm/cancel | WORKING | `AppointmentService`, `appointments/action.php` |
| 35 | 1-click appointment → job card conversion | WORKING | `AppointmentService::convertToJob`; `appointments/action.php:26` |
| 36 | Invoice generation from job | WORKING | `InvoiceService::createForJob` (Completed/Delivered only) |
| 37 | Invoice discounting, tax (15%), totals in integer cents | WORKING | `InvoiceService::recalculate/setDiscount` |
| 38 | One invoice per job / re-sync rules | WORKING | `job_card_id UNIQUE`; `syncFromJob` refuses after payments |
| 39 | Record payment (partial, overpayment blocked) | WORKING | `PaymentService::record:7-21` |
| 40 | Refund (reason required, ≤ net paid) | WORKING | `PaymentService::refund:26-40` |
| 41 | Receipt printing | WORKING | `public/payments/receipt.php`; `invoices/view.php` print button (browser print **NOT VERIFIED**) |
| 42 | Payment immutability | WORKING (design) | insert-only app path; `garage_app` lacks UPDATE/DELETE; trigger presence **NOT VERIFIED** |
| 43 | Expense ledger (record/list expenses) | **MISSING in live deployment** | code + page exist; table and `expenses.*` permissions absent → 403 for everyone incl. admin |
| 44 | Reports (13 catalogue entries, date filters, CSV, print) | WORKING | `ReportService::REPORTS`; `public/reports/index.php` |
| 45 | In-app notifications (user and role broadcast) | WORKING | `NotificationService`; `notifications/index.php`; appointment booking broadcasts to receptionists |
| 46 | Email/SMS notifications for business events | **MISSING** | `MailService` used only by password reset; no SMS provider |
| 47 | Public landing / about / services / privacy / terms | PARTIALLY WORKING | pages render; remote Unsplash images (six) required; CSP permits only that image host |
| 48 | Public contact form | PARTIALLY WORKING | stores to `contact_inquiries` (`ContactInquiryService`); **no admin page reads inquiries**; no rate limit |
| 49 | Public self-registration | PARTIALLY WORKING | creates `users` + `customers`; **not linked**, no email verification, no rate limit, no CAPTCHA; customer role has 0 permissions |
| 50 | Customer portal (my vehicles / appointments / invoices) | **MISSING** | no `customers.user_id`; customer role 0 permissions; landing "Dashboard" link 403s |
| 51 | Field-level encryption / blind index | **MISSING in production** | `CryptoService` referenced only by tests; no key file; PII stored plaintext |
| 52 | Delivery note / gate pass printing | MISSING | no route or schema |
| 53 | Purchase orders / goods-received notes | MISSING | no table, no route |
| 54 | Supplier returns / credit notes | MISSING | only `return` of parts from jobs |
| 55 | Quotations / estimates | MISSING | no table, no route |
| 56 | Quality control / inspection checklists | MISSING | no table, no route |
| 57 | Barcode / QR scanning | MISSING | no dependency, table or route |
| 58 | Online payment gateways (Telebirr, Chapa, card) | MISSING | methods are free-text settings only; no gateway code |
| 59 | External REST/JSON API | MISSING | only internal `job-cards/data.php` for UI comboboxes |
| 60 | Multi-branch / multi-tenant | MISSING | no branch table or scoping column |
| 61 | Backup / restore automation | MISSING / **NOT VERIFIED** | no script or runbook found |
| 62 | Health/self-check utilities | PARTIALLY WORKING | `database/verify_auth_database.php`, `database/recover_admin.php` are manual CLI/admin utilities |
| 63 | Demo data seeding | WORKING | `app/services/DemoSeeder.php`, invoked from `setup.php` |
| 64 | Installer (`setup.php`) | WORKING but **risky** | self-locks on first user / `storage/installed.lock`; still publicly reachable |

**Counts:** WORKING ≈ 31 · PARTIALLY WORKING ≈ 12 · BROKEN (module-level) 0 · MISSING ≈ 15 · design-only/implicit 2. (These counts are indicative; the matrix is authoritative.)

> Note: the confirmed broken supplier-history link is counted inside "PARTIALLY WORKING — Supplier CRUD" and "Inventory history", not as a separate BROKEN module.

---

## 2. Module detail

### 2.1 Authentication & session (WORKING, with one partial)

- **Login** accepts username or email, normalizes email, always runs `password_verify` (dummy hash on unknown user) to equalize timing, and returns one generic error message.
- **Lockout** is evaluated before user lookup, so unknown usernames are also throttled per IP.
- **Session** stores `uid`, `started_at`, `last_activity`, `ua`, CSRF token and creation time. `session_regenerate_id(true)` runs before the authenticated state is installed; the session array is then replaced atomically.
- **Remember-me** rotates the token on every restore; the raw token never exists server-side.
- **Password reset** invalidates prior tokens, stores only SHA-256, and expires in 30 minutes. The dev link is surfaced only when mail transport is `dev_spool` **and** debug is enabled.
- **Partial:** real email/SMTP delivery was not tested; `MailService` supports STARTTLS/SSL and native `mail()` but its operational configuration is **NOT VERIFIED**.

### 2.2 Authorization (WORKING)

- 38 live permission codes; variadic OR semantics (`require_permission('jobs.view_all','jobs.view_assigned')`).
- Denials are audited (`authorization_denied`) before 403.
- `public/users/roles.php` cannot strip the administrator role; submitted permissions are intersected with the DB whitelist inside a transaction, with an audit diff.
- Mechanic scoping is enforced on job cards and on the vehicle repair-history query.

### 2.3 Dashboard (PARTIALLY WORKING)

- Permission-gated KPI cards, 30/90/365-day ranges, previous-period comparison, Chart.js payloads, and an AJAX fragment mode.
- `jobWhere` is built from `(int)` casts only.
- Revenue = payments − refunds, permission-gated; expenses come from the expense ledger **and** stock purchases (excluding "Opening stock").
- **Degraded:** with `expenses` absent and `expenses.view` missing, expense/profit KPIs render "Not available"/"Requires expense ledger" and the "Record expense" button is hidden. This is graceful, but the financial picture is incomplete.
- Browser rendering of charts is **NOT VERIFIED**.

### 2.4 Customers & vehicles (WORKING, one exposure)

- Full CRUD, active/inactive toggling (history preserved), phone/email/address, VIN, mileage, fuel type, vehicle type, notes.
- Customer view links to vehicles; vehicle view lists repair history filtered by mechanic role.
- **Residual exposure:** any user with `vehicles.view` can open any vehicle by ID and see owner name/phone/VIN/notes. There is no per-customer isolation because customer scoping does not exist. Acceptable for a single workshop with trusted staff; not acceptable for a customer portal without new scoping.

### 2.5 Job cards (WORKING; priority partial)

- Lifecycle: `Pending → Diagnosing → Waiting for Parts → In Progress → Completed → Delivered`, with `Cancelled` permitted from the first four states. `Delivered` and `Cancelled` are terminal; `Completed → In Progress` is allowed for rework.
- Guards: cannot cancel once an invoice exists; cannot deliver without an invoice; reopening an invoiced job is blocked; cancellation returns issued parts.
- Create validates vehicle ownership, active customer/vehicle/part flags, and service IDs; priority is applied only when the column exists.
- Actions are POST-only and re-check action-specific permissions after loading the job.
- **Partial:** priority cannot persist live because `job_cards.priority` is absent.

### 2.6 Inventory (WORKING; one broken cross-link)

- Parts master with SKU, category, unit, reorder level, cost/price, supplier FK.
- Purchases, adjustments (in/out), issues, and returns all write an `inventory_transactions` row with `balance_after` under row locks.
- `issueMany` is atomic and capped at 5 unique parts.
- Part requests are job-scoped; mechanics can request, inventory staff can issue/reject.
- Low-stock list powers the layout bell.
- **Broken cross-link:** supplier detail → part history passes `?id=` while the history page reads `part_id`, so the target is not filtered.

### 2.7 Billing (WORKING)

- Invoice can be created only for `Completed`/`Delivered`; exactly one per job.
- Line items are rebuilt from job services, residual labor, and non-returned issued parts.
- All math in integer cents; discount cannot exceed subtotal nor reduce the invoice below net paid.
- Payments are insert-only, positive, and capped at the outstanding balance. Refunds require a reason and cannot exceed net paid. Receipt numbers are generated per transaction.
- Immutability is enforced by app design **and** by `garage_app` grants. The DB trigger's live presence is **NOT VERIFIED**.

### 2.8 Expenses (MISSING in the live deployment)

- Code is complete: `public/expenses/index.php` validates category/description/amount/date (no future dates), inserts via transaction, audits `expense_recorded`, and lists filtered expenses.
- `DashboardAnalyticsService::expenseTotals` already supports an optional ledger with a `ready` flag.
- **Blocker:** live DB has no `expenses` table and no `expenses.*` permissions. `require_permission('expenses.view')` denies every user, including administrator. The module is therefore **unreachable**.

### 2.9 Reports (WORKING)

- 13 reports: daily/monthly revenue, outstanding invoices, customer report, vehicle repair history, mechanic performance, completed jobs, inventory, parts usage, services performance, suppliers & purchasing, appointments, audit activity.
- Each report has its own permission; the page OR-gates entry and re-checks per report; dates are validated and swapped if reversed; CSV export is audited (`report_exported`).
- Report CSV/print output is **NOT VERIFIED** in a browser.

### 2.10 Audit (WORKING)

- Hash-chained append with a single serialization row; sensitive keys redacted; paged verification.
- Live verification: **39/39 intact**, head `last_id=39`.
- CSV export is permission-gated (`audit.view`) and itself audited (`audit_log_exported`).
- Residual gaps: no off-box anchor; full-table deletion is undetectable locally.

### 2.11 Public website (PARTIALLY WORKING)

- Landing, about, services, privacy, terms, contact, login, register, password reset.
- Six images are fetched from `images.unsplash.com`; CSP allows that host but nothing else external. Offline/air-gapped installs lose imagery.
- Contact inquiries are stored but **no administrative screen lists them** — they are only reachable via direct SQL. This is a functional gap, not a security one.
- The landing header/footer render a "Dashboard" link to any signed-in user (`public/index.php:104`, `:599`); customers (0 permissions) receive 403.

### 2.12 Customer accounts (MISSING as a portal)

- `public/register.php` creates a `users` row in the `customer` role and a matching `customers` row (matched by email, else phone, else new), but **never stores a relationship**.
- The `customer` role was deliberately stripped of staff permissions by `revoke_customer_staff_access.sql`.
- Consequence: a registered customer can authenticate but has no scoped screens. A portal cannot be built safely until identity linkage and per-customer authorization exist.

### 2.13 Installer and utilities

- `public/setup.php` creates the first administrator and optionally seeds demo data; it locks when users exist or `storage/installed.lock` is present.
- `database/recover_admin.php` and `database/verify_auth_database.php` are manual administrative scripts.
- **Risk:** `setup.php` remains in the public web root in a deployed system.

---

## 3. Feature-level defects (confirmed)

| Defect | File | Effect |
|---|---|---|
| Supplier → part history uses `?id=` but target reads `part_id` | `public/suppliers/view.php:70` vs `public/inventory/history.php:7` | Opens unfiltered history |
| Customer sees "Dashboard" link that 403s | `public/index.php:104`, `:599` | Dead-end navigation |
| Expenses module unreachable | `public/expenses/index.php:6` + live schema | 403 for all users |
| Priority cannot persist | `job_cards.priority` absent | Feature silently disabled |
| Contact inquiries have no admin UI | `ContactInquiryService` only | Submissions invisible in app |
| Registration does not link user↔customer | `public/register.php:88-100` | No portal possible |
| No rate limiting on public writes | `register.php`, `contact.php` | Spam/abuse exposure |
| Remote image dependency | landing/auth pages | Offline degradation |

---

## 4. Explicitly absent capabilities

Purchase orders / GRN · supplier returns / credit notes · quotations / estimates · QC / inspection checklists · barcode/QR · delivery notes / gate passes · online payment gateways · SMS · email notifications for business events · customer portal · multi-branch · external API · automated backups · production field-level encryption · customer-scoped data access.

---

## 5. Verification status of features

- **Server-side logic:** verified by reading every service and route involved; three unit suites pass.
- **Database state:** verified read-only (27 tables, seeds, permissions, audit chain).
- **Browser interaction:** **NOT VERIFIED** for all screens (no HTTP session was driven during Phase 0).
- **Email/SMS delivery:** **NOT VERIFIED**.
- **Print/PDF output:** **NOT VERIFIED**.
- **Concurrent behaviour under load:** **NOT VERIFIED**.
