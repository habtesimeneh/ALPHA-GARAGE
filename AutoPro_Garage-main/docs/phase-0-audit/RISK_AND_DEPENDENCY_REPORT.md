# RISK AND DEPENDENCY REPORT — Alpha Garage Management System

Audit date: **2026-11-09**
Basis: verified Phase 0 evidence only (static code audit + read-only database inspection + non-destructive unit tests).
Mode: **read-only** — no mitigation below has been executed.

---

## 1. Method and scoring

Each risk is scored on **Likelihood (L)** and **Impact (I)**, each 1–5:

| Score | Likelihood | Impact |
|---|---|---|
| 5 | Almost certain / already occurring | Critical: data loss, breach, unusable system |
| 4 | Likely | High: major module unavailable, financial error |
| 3 | Possible | Medium: degraded function, manual workaround |
| 2 | Unlikely | Low: minor inconvenience |
| 1 | Rare | Negligible |

**Exposure = L × I.**
Bands: **Critical 20–25 · High 12–19 · Medium 6–11 · Low 1–5.**

Risks marked **[CONFIRMED]** are observable facts, not speculation. Risks marked **[NOT VERIFIED]** depend on unverified state and must be confirmed in Phase 1.

---

## 2. Risk register

### 2.1 Deployment and schema risks

| ID | Risk | L | I | Exposure | Band | Evidence | Phase |
|---|---|---|---|---|---|---|---|
| R-01 | **Live schema is behind the code**: `expenses` table and `expenses.*` permissions are absent, making the module unreachable and financial KPIs incomplete | 5 | 4 | **20** | **Critical** | live DB: 27 tables, 38 permissions; `public/expenses/index.php:6`; `dashboard.php:164-169` | 1 |
| R-02 | **`job_cards.priority` absent**, so priority is silently dropped; code guards hide the failure | 5 | 2 | 10 | Medium | live `SHOW COLUMNS`; `JobService::hasPriorityColumn()` | 1 |
| R-03 | **Migration bookkeeping is unreadable** by the runtime account; applied set inferred only | 4 | 4 | **16** | **High** | `schema_migrations` → SQLSTATE 42000/1142 | 1 |
| R-04 | **Migration runner fragility**: historical `SQLSTATE HY000 2014` (unbuffered queries) during a run | 3 | 4 | 12 | **High** | `storage/logs/app.log` (2026-11-08) | 1 |
| R-05 | **Re-running `least_privilege.sql` resets account passwords** while trying to fix grants | 3 | 4 | 12 | **High** | warning in `20261108_expense_ledger.sql` header | 1 |
| R-06 | **Schema re-import loses/keeps unexpected data**: `DROP TABLE` list omits `contact_inquiries` | 3 | 2 | 6 | Medium | `database/database.sql:14`, `:568` | 1 |

### 2.2 Security and abuse risks

| ID | Risk | L | I | Exposure | Band | Evidence | Phase |
|---|---|---|---|---|---|---|---|
| R-07 | **Installer exposed**: `public/setup.php` remains web-reachable; safety relies on a lock check | 4 | 4 | **16** | **High** | `public/setup.php:11-13` | 1 |
| R-08 | **Registration/contact abuse**: no rate limit, CAPTCHA or email verification | 4 | 3 | 12 | **High** | `public/register.php`, `public/contact.php` | 1 |
| R-09 | **Customer identity is unlinked**; role has 0 permissions. Attempting a portal without linkage would invite IDOR | 5 | 4 | **20** | **Critical** (if portal built prematurely) | `public/register.php:88-100`; role counts | 1 (decision), 3 (build) |
| R-10 | **Crypto is design-only**: AES-GCM/blind index unused; PII plaintext at rest | 4 | 3 | 12 | **High** (if encryption is a compliance requirement) | `CryptoService` referenced only by `tests/` | 3/4 |
| R-11 | **Audit chain has no off-box anchor**: full-table deletion or DB loss is undetectable locally | 2 | 4 | 8 | Medium | `AuditLogger` design; head is local | 1 (runbook), 6 |
| R-12 | **Public site depends on a third party** (Unsplash) for imagery; offline/blocked host degrades UI | 3 | 2 | 6 | Medium | `img-src … images.unsplash.com` CSP; six images | 1 |
| R-13 | **`vehicles.view` is unscoped**: any holder can open any vehicle by ID (owner, VIN, notes) | 3 | 3 | 9 | Medium | `public/vehicles/view.php:6-10` | 3 |
| R-14 | **Payment immutability trigger presence unverified**; protection currently rests on grants + app design | 2 | 4 | 8 | Medium | `least_privilege.sql` defines `trg_payments_immutable`; live trigger not inspected | 1 |
| R-15 | **No HSTS configured at app level**; transport hardening depends on Apache | 2 | 3 | 6 | Medium | app sets Secure cookie only on HTTPS | 1/6 |

### 2.3 Data and financial risks

| ID | Risk | L | I | Exposure | Band | Evidence | Phase |
|---|---|---|---|---|---|---|---|
| R-16 | **Cached stock balance diverges** from `inventory_transactions.balance_after` if written outside services | 3 | 4 | 12 | **High** | `spare_parts.quantity` + ledger design | 1 (report), 2 (guardrails) |
| R-17 | **Financial totals rely on service code**, not generated columns; direct SQL can corrupt balances | 2 | 4 | 8 | Medium | `InvoiceService::recalculate` | 1 (reconciliation) |
| R-18 | **Dual supplier fields** (`supplier` text + `supplier_id`) can produce inconsistent reports | 3 | 2 | 6 | Medium | `database.sql:235-236` | 2 |
| R-19 | **Audit/log growth unbounded**; no retention or archival policy | 2 | 2 | 4 | Low | `audit_logs` append-only; no archival | 6 |
| R-20 | **No verified backup/restore**; recoverability unknown | 3 | 5 | **15** | **High** | no script/runbook found | 1 |

### 2.4 Operational and delivery risks

| ID | Risk | L | I | Exposure | Band | Evidence | Phase |
|---|---|---|---|---|---|---|---|
| R-21 | **Documentation drift** misleads future audits/onboarding (non-existent permissions, `manager` role, historical test claims) | 5 | 2 | 10 | Medium | `SECURITY_DESIGN.md:123-132`; `DATA_DICTIONARY.md:68`; `TESTING_REPORT.md:61` | 1 |
| R-22 | **No browser/E2E verification** during Phase 0; UI regressions could exist despite clean lint and unit tests | 4 | 3 | 12 | **High** | Phase 0 method limitation | 1 |
| R-23 | **Contact inquiries invisible in-app** (write-only form) | 5 | 2 | 10 | Medium | `ContactInquiryService` only; no admin route | 1 |
| R-24 | **Single-node assumptions** (file sessions, serialized audit head) limit horizontal scaling | 3 | 2 | 6 | Medium | session storage; `audit_chain_head FOR UPDATE` | 6 |
| R-25 | **Two administrators only**, no documented break-glass/recovery drill | 2 | 4 | 8 | Medium | live `users` count = 2; `recover_admin.php` exists | 1 |

---

## 3. Prioritized risk summary

**Critical / High (address before any feature work):**

1. **R-01** — schema drift disables expenses and distorts financial reporting.
2. **R-09** — unlinked customer identity; any premature portal creates horizontal-access risk.
3. **R-03 + R-04** — migration state cannot be proven; runner has a history of failure.
4. **R-07** — installer reachable in production.
5. **R-08** — unthrottled public write endpoints.
6. **R-20** — no verified backup/restore.
7. **R-16** — stock balance/ledger drift has no detection.
8. **R-22** — no browser-level verification yet.
9. **R-05** — privileged-grant fix can reset passwords if done carelessly.
10. **R-10** — encryption capability advertised by code but not active (compliance interpretation risk).

**Medium:** R-02, R-06, R-11, R-12, R-13, R-14, R-15, R-17, R-18, R-21, R-23, R-24, R-25.
**Low:** R-19.

---

## 4. Dependency map

### 4.1 Technical dependencies (verified in code)

```
app/config/local.php or GMS_DB_* env
        └─> app/config/database.php ─> Database.php (PDO, strict mode)
                ├─> AuthService / auth middleware ─> permissions middleware ─> every route
                ├─> AuditLogger ─> audit_logs + audit_chain_head (+ append-only triggers)
                ├─> JobService ─> InventoryService (parts issue/return)
                │                     └─> Numbering, AuditLogger
                ├─> InvoiceService ─> JobService data + InventoryService data
                │        └─> PaymentService ─> Numbering, AuditLogger
                ├─> AppointmentService ─> JobService (convert)
                ├─> DashboardAnalyticsService ─> payments/refunds, expenses*, inventory, jobs
                └─> ReportService ─> all of the above (read-only)
```

\* `expenses` is **absent live**, so `DashboardAnalyticsService` runs in `ready=false` mode.

**Hard runtime dependencies:**

| Dependency | Required by | Failure mode |
|---|---|---|
| MySQL reachable as `garage_app` | every authenticated page | login shows safe DB-unavailable message; app logs error |
| `storage/` writable | sessions? (no — PHP session path), logs, mail spool, encryption key | logging/mail/key generation fails |
| `storage/installed.lock` or ≥1 user | `setup.php` lock | installer re-opens if both absent |
| SMTP / mail transport | password reset delivery | reset flow silently cannot deliver to user |
| `images.unsplash.com` | landing/auth imagery | degraded visuals; CSP blocks nothing else |

### 4.2 Feature dependency graph (for the roadmap)

```
Phase 1 (schema baseline, expenses grant, priority, setup lockdown, rate limits, link fixes)
   ├─> Phase 2 Procurement
   │      ├─> Phase 4 supplier payables/aging
   │      └─> Phase 5 barcode/lot traceability
   ├─> Phase 4 Billing & finance completion
   │      └─> Phase 6 compliance/tax exports
   ├─> Phase 3 Customer portal
   │      ├─> requires identity linkage + scoped authorization
   │      └─> benefits from Phase 2 accurate part status
   └─> Phase 5 QC/delivery/operations
          └─> Phase 6 multi-branch
```

### 4.3 Decisions that block downstream work

| Decision | Blocks | Why it must precede implementation |
|---|---|---|
| **Inventory costing method** (weighted average vs FIFO) | Phase 2 receipts, valuation, Phase 4 COGS | Historical valuation cannot be reconstructed consistently after the fact |
| **Customer identity model** (`customers.user_id` vs link table; email uniqueness) | Phase 3 entirely | Determines every portal query and backfill |
| **Customer email verification requirement** | Phase 3 access rules | Changes onboarding and recovery flows |
| **At-rest encryption scope** (which columns, key custody) | Phase 3/4 compliance claims | `CryptoService` exists but is unused; retrofitting encrypted columns changes queries and blind-index needs |
| **`setup.php` disposition** (delete vs server-block vs CLI-only installer) | Phase 1 deployment | Affects fresh-install runbook |
| **Gateway selection** (Telebirr/Chapa/card/none) | Phase 4 item 7 | Webhook, idempotency and reconciliation design depend on provider |
| **Branch model** (single vs multi) | Phase 6 | `branch_id` scoping is invasive if added late |
| **Audit retention period** | Phase 6 archival/export | Drives storage and compliance design |

---

## 5. Blockers by phase

| Phase | Hard blockers | Soft blockers |
|---|---|---|
| **1** | Privileged DB account; schema baseline dump | Decision on `setup.php`; backup destination |
| **2** | Costing-method decision; Phase 1 grants | Supplier cooperation for PO data model |
| **3** | Identity model decision; email delivery verification | Privacy policy update |
| **4** | Phase 1 expenses live; reconciliation acceptance criteria | Gateway contract (only if online payments) |
| **5** | Phase 1 priority column; vendored scanner library | QC template content from workshop lead |
| **6** | Phases 1–5 stable; infrastructure (Redis/queue/backups) | Organizational policy decisions |

---

## 6. Control effectiveness assessment

| Control | Status | Comment |
|---|---|---|
| Global CSRF on POST | **Effective** | Enforced centrally in bootstrap; failures audited |
| Prepared statements | **Effective** | `ATTR_EMULATE_PREPARES=false`; no concatenated user input found |
| Output escaping | **Effective** | `e()` used across inspected templates; strict CSP |
| RBAC server-side | **Effective** | Variadic OR gate; object-level job checks; JSON endpoints gated |
| Denial auditing | **Effective** | `deny()` logs before 403 |
| Audit hash chain | **Effective (local)** | 39/39 verified; no off-box anchor |
| Append-only audit triggers | **Effective** | DB-level, independent of app |
| Payment immutability | **Effective (layered)** | App insert-only + grants; trigger presence **NOT VERIFIED** |
| Least-privilege DB account | **Effective** | 1142 errors observed on `schema_migrations`; no UPDATE/DELETE on sensitive tables |
| Login lockout | **Effective** | Before user lookup; per-user+IP and per-IP |
| Session hardening | **Effective** | Strict mode, regeneration, UA binding, idle/absolute limits |
| Public form throttling | **Missing** | Registration/contact unprotected |
| Field-level encryption | **Inactive** | Service exists; not wired |
| Backup/restore | **NOT VERIFIED** | No evidence found |
| Browser/E2E verification | **Missing** | Phase 0 limitation |

---

## 7. NOT VERIFIED register

The following could not be established and must not be assumed:

1. Exact applied migrations and checksums (`schema_migrations` inaccessible to `garage_app`).
2. Presence of `trg_payments_immutable` in the live database.
3. Column-level grants for `garage_app` beyond observed denials.
4. Browser rendering, responsive layout, AJAX dashboard refresh, comboboxes, print/PDF output.
5. SMTP delivery and email content rendering.
6. Backup/restore fidelity and disaster recovery timing.
7. HTTPS/HSTS configuration at the Apache layer.
8. Behaviour under concurrent writes and realistic data volumes.
9. `EXPLAIN` plans (operational tables are empty).
10. Historical assertions in `documentation/TESTING_REPORT.md` (not re-executed).
11. Third-party availability (Unsplash, SMTP, any payment provider).
12. The destructive test suite `tests/run_tests.php` (intentionally not run).
13. Whether the two live administrator accounts are intended, uniquely owned, and have strong passwords (not inspected).
14. Whether `storage/private/encryption.key` exists on a production host (it does not in this workspace).

---

## 8. Early-warning indicators (recommended monitoring)

| Indicator | Why | Threshold (suggested) |
|---|---|---|
| `AuditLogger::verifyChain()` result | Detects audit tampering/corruption | Any `ok=false` → page on-call |
| `audit_chain_head.last_id` monotonic growth | Detects silent truncation | Non-monotonic or reset → alert |
| Latest `inventory_transactions.balance_after` vs `spare_parts.quantity` | Detects ledger drift | Any mismatch → daily report |
| `login_attempts` rate | Credential stuffing / lockouts | Spike above baseline → alert |
| Failed `csrf_validation_failed` audits | Attack or broken form | Spike → investigate |
| `contact_inquiries` / `users` growth rate | Spam/abuse | Abnormal growth → throttle |
| Migration checksum mismatch | Drift between code and DB | Any mismatch → block deploy |
| DB connection errors (`1045`, `2002`) | Credential/availability incident | Any → page on-call |
| Disk usage of `storage/logs` and DB | Unbounded audit/log growth | >80% → archive |

---

## 9. Assumptions (to confirm with the owner)

1. Single-branch operation is acceptable for Phases 1–5.
2. ETB and 15% VAT remain the operating currency/tax configuration.
3. Payments are recorded manually; no gateway is contractually required yet.
4. Staff are trusted with unscoped vehicle access (`vehicles.view`) in the near term.
5. The two existing administrator accounts are intentional and controlled.
6. MySQL/MariaDB remains the only datastore; no external queue/cache is available before Phase 6.
7. The application will continue to run as native PHP without a framework or Composer; no rewrite is planned.
8. Phase 1 may change schema only via the two already-written migrations plus grants; new tables wait for their phase.

If any assumption is false, the affected risk score and phase ordering must be revisited before implementation.

---

## 10. Phase 0 closing statement

- Five audit documents were created under `docs/phase-0-audit/`.
- No existing source file was modified.
- No migration was created or executed.
- No database table or record was created, altered, dropped, truncated, or deleted.
- No credential, configuration, dependency, UI or layout was changed.
- Nothing was pushed to Git.

**Phase 1 has not started and will not start without explicit approval.**
