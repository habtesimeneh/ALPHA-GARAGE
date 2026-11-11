# IMPLEMENTATION ROADMAP — Alpha Garage Management System

Audit date: **2026-11-09**
Status: **Planning only.** No phase below has been started. Phase 0 is complete; **Phase 1 requires explicit approval.**

---

## 0. How this roadmap is built

This roadmap is derived only from verified Phase 0 evidence. It follows five rules:

1. **No rewrite.** The existing architecture (bootstrap pipeline, `Database` wrapper, service layer, RBAC, audit chain) is sound and is reused.
2. **Reconcile before extending.** The live schema is behind the code. No feature work should begin on a schema that cannot be trusted.
3. **Security before surface area.** Close deployment and abuse gaps before adding public-facing features.
4. **Identity before portal.** A customer portal cannot exist until users are linked to customers and per-customer authorization is defined.
5. **Each phase is independently shippable and reversible.** No phase depends on a later phase.

Effort sizing: **S** ≈ 1–3 days · **M** ≈ 1–2 weeks · **L** ≈ 2–4 weeks · **XL** ≈ 4+ weeks (single experienced PHP/MySQL developer; adjust for team size).

---

## 1. Phase overview and order

| Phase | Name | Goal | Effort | Depends on |
|---|---|---|---|---|
| **0** | Complete System Audit | Establish verified baseline (this document set) | — | — |
| **1** | Foundation Stabilization | Make schema, deployment surface and documentation trustworthy | M | Phase 0 |
| **2** | Procurement & Inventory Completion | Close the purchasing loop (PO → receive → pay → return) | L | Phase 1 |
| **3** | Customer Identity & Portal | Link accounts to customers; scoped self-service | L | Phase 1 (and Phase 2 recommended) |
| **4** | Billing & Finance Completion | Reconciliation, statements, expense reporting, optional gateways | L | Phase 1; Phase 2 for supplier invoices |
| **5** | Workshop Quality & Operations | QC/inspection, delivery notes, barcode, scheduling depth | L | Phase 1; Phase 2 for parts traceability |
| **6** | Scale & Enterprise Readiness | Multi-branch, API, observability, HA, compliance exports | XL | Phases 1–5 |

**Recommended order: 1 → 2 → 4 → 3 → 5 → 6.**
Rationale: Phase 2 has the highest operational return and the lowest security complexity. Phase 4 completes the money loop already partly built. Phase 3 (portal) is deferred until identity and authorization scoping are designed deliberately. Phase 5 builds on parts traceability from Phase 2. Phase 6 is only meaningful once operational data exists.

---

## 2. Phase 0 — Complete System Audit (COMPLETE)

**Deliverables (created):**

- `docs/phase-0-audit/SYSTEM_AUDIT.md`
- `docs/phase-0-audit/DATABASE_STRUCTURE.md`
- `docs/phase-0-audit/EXISTING_FEATURES.md`
- `docs/phase-0-audit/IMPLEMENTATION_ROADMAP.md` (this file)
- `docs/phase-0-audit/RISK_AND_DEPENDENCY_REPORT.md`

**Exit criteria (met):**

- 99/99 PHP files lint clean.
- 3 non-destructive unit suites pass.
- Live DB inspected read-only; audit chain verified 39/39.
- No source, schema, config or dependency changed.
- All unverifiable items labelled **NOT VERIFIED**.

---

## 3. Phase 1 — Foundation Stabilization

**Objective:** make the deployed system match its code, remove unnecessary public surface, fix confirmed small defects, and establish the safety net needed for every later phase.

**Effort:** M (approximately 1–2 weeks).

### 3.1 Scope and features

| # | Work item | Type | Reuse | Required change |
|---|---|---|---|---|
| 1.1 | **Schema baseline capture** | Ops | `mysqldump --no-data`, `database/verify_auth_database.php` | Produce a versioned baseline dump of the live schema *before* any migration |
| 1.2 | **Apply `20261108_expense_ledger.sql`** | Schema | migration file, `garage_migrator` | Create `expenses`; insert `expenses.view`/`expenses.manage`; grant admin + accountant; then `GRANT SELECT, INSERT … expenses TO garage_app` |
| 1.3 | **Apply `20261109_job_card_priority.sql`** | Schema | guarded migration | Add `job_cards.priority` with `IF` guard; verify form/service persistence |
| 1.4 | **Migration status tooling** | Ops | `database/migrate.php`, `schema_migrations` | Add a read-only status command/page executed as `garage_migrator`; do **not** grant `garage_app` access |
| 1.5 | **Lock down `public/setup.php`** | Security | existing lock logic | Remove from deployed tree **or** hard-block via web server after install; keep installer only for fresh-provisioning |
| 1.6 | **Fix supplier → part-history link** | Bug | — | Change `?id=` to `?part_id=` in `public/suppliers/view.php:70` |
| 1.7 | **Fix customer "Dashboard" link** | Bug | — | Hide the link unless `can('dashboard.view')` (`public/index.php:104`, `:599`) |
| 1.8 | **Public write rate limiting** | Security | `login_attempts` pattern, `Database` | Add per-IP throttling + honeypot to `register.php` and `contact.php`; optionally reCAPTCHA-compatible hook (no external dependency required) |
| 1.9 | **Self-host public imagery** | Privacy/availability | existing assets pipeline | Download the six Unsplash images into `public/assets/img/`; remove `images.unsplash.com` from CSP; add attribution if required |
| 1.10 | **Contact-inquiry admin surface** | Feature gap | `ContactInquiryService`, list-page patterns | Add a permission-gated `inquiries` screen (view, mark in-progress/resolved). *Small, but without it the form is write-only.* |
| 1.11 | **Documentation reconciliation** | Docs | `documentation/` | Correct stale permission names (`jobs.update_status`, `jobs.assign_mechanic`, `invoices.pay`, `invoices.refund`, `inventory.adjust`); remove `manager` role references; mark historical test reports as superseded |
| 1.12 | **Audit anchor + backup runbook** | Ops | `AuditLogger` | Document and schedule export of `audit_chain_head` (`last_id`, `last_hash`) and a DB backup/restore drill; no code change to the chain |
| 1.13 | **Non-destructive integration harness** | QA | `tests/run_tests.php` structure | Add a smoke suite that runs against a disposable `*_test` DB with explicit opt-in; keep the destructive suite gated |
| 1.14 | **Schema reconciliation report** | Ops | existing services | Read-only report comparing `spare_parts.quantity` vs latest ledger `balance_after`, and invoice totals vs items/payments |

### 3.2 Reuse

- `database/migrate.php` (checksum runner), `database/least_privilege.sql` (grants reference), `Database::transaction`, `AuditLogger`, `flash()`, layout/list-page conventions, `tests/` harness.

### 3.3 Dependencies

- A **privileged DB account** (`garage_migrator`) for 1.2–1.4.
- A decision on whether `setup.php` is deleted or server-blocked (1.5).
- No dependency on Phase 2+.

### 3.4 Risks

| ID | Risk | Mitigation |
|---|---|---|
| P1-R1 | Re-running `least_privilege.sql` resets account passwords | Use targeted `GRANT` only, exactly as the expense migration comment instructs |
| P1-R2 | Migration runner historically hit `SQLSTATE 2014` | Test the migration on a copy first; run one statement batch at a time if needed |
| P1-R3 | Removing `setup.php` breaks fresh installs | Keep a documented CLI/manual installer path or restrict by IP/host |
| P1-R4 | Rate limiting could lock out legitimate users | Use generous thresholds, IP+route scoping, and audit-only mode first |

### 3.5 Acceptance criteria

- [ ] `expenses` exists; administrator and accountant can open `/expenses/`; `garage_app` can `SELECT`/`INSERT` but not `UPDATE`/`DELETE`.
- [ ] `job_cards.priority` exists; creating a job with a priority persists and displays it.
- [ ] Migration status is readable without elevating `garage_app`.
- [ ] `setup.php` is unreachable in the deployed environment.
- [ ] Supplier → part history opens **filtered** to that part.
- [ ] A signed-in customer no longer sees a 403-producing Dashboard link.
- [ ] Registration and contact forms reject bursts above the threshold and log the event.
- [ ] No external image requests are made by any page; CSP no longer lists Unsplash.
- [ ] An administrator can read and triage contact inquiries in-app.
- [ ] Documentation contains no permission code that is absent from `permissions`.
- [ ] A restore drill from backup succeeds on a scratch database.
- [ ] All 99 files still lint clean; the three unit suites still pass; audit chain still verifies.

### 3.6 Explicitly out of scope for Phase 1

No new business modules, no portal, no procurement, no schema redesign beyond the two existing migrations and minimal supporting tables (inquiry status is already in schema).

---

## 4. Phase 2 — Procurement & Inventory Completion

**Objective:** close the loop from supplier quotation to stock receipt and supplier return, with payable tracking.

**Effort:** L.

### 4.1 Features

1. **Purchase orders (PO)** — draft → approved → ordered → partially/fully received → closed/cancelled; supplier, expected date, lines, totals.
2. **Goods-received notes (GRN)** — receive against PO lines, partial receipts, automatic `inventory_transactions` (`purchase`) with `balance_after`, variance handling.
3. **Supplier invoices / payables** — link received POs to supplier invoices, due dates, payment status (separate from customer payments).
4. **Supplier returns / credit notes** — return stock (`adjustment_out` or dedicated `supplier_return` type), credit against payable.
5. **Reorder workflow** — low-stock list → suggested PO quantities → one-click draft.
6. **Inventory valuation report** — weighted-average or FIFO cost (choose one and document it); current report uses unit cost only.
7. **Reconciliation guardrails** — read-only check from Phase 1 becomes part of the inventory dashboard.

### 4.2 Reuse

- `InventoryService` ledger and locking, `Numbering` helper, `suppliers` module, `lowStock`, `ReportService` pattern, audit chain, `to_cents`/`from_cents`.

### 4.3 Required changes

- New tables: `purchase_orders`, `purchase_order_items`, `goods_received_notes`, `grn_items`, `supplier_invoices`, `supplier_returns` (names indicative).
- New permissions: `procurement.view`, `procurement.manage`, `procurement.approve`, `procurement.receive`, `procurement.pay`.
- Extend `inventory_transactions.txn_type` with `supplier_return` (or reuse `adjustment_out` with a reference — decide and document).
- New reports: open POs, supplier aging, receipt variance.

### 4.4 Dependencies

- Phase 1 schema baseline and expense grant.
- A costing-method decision (weighted average vs FIFO) — **must be decided before writing receipts**, because historical valuation depends on it.

### 4.5 Risks

- **Stock double counting** if GRN writes both `spare_parts.quantity` and the ledger incorrectly. Mitigation: one ledger write path only, inside a transaction with `FOR UPDATE`.
- **Payables vs customer payments confusion** — keep separate tables and separate reports.
- **Approval bypass** — enforce `procurement.approve` server-side, not just in UI.
- Legacy `spare_parts.supplier` text vs `supplier_id` — standardize on `supplier_id` for new flows.

### 4.6 Acceptance criteria

- [ ] PO lifecycle enforced by an explicit transition map, tested.
- [ ] Partial receipt updates stock exactly once per line; ledger reconciles with `spare_parts.quantity`.
- [ ] Cancelling an unreceived PO changes no stock.
- [ ] Supplier return reduces stock and cannot exceed received quantity.
- [ ] Payables aging report matches supplier invoice records.
- [ ] All new actions are permission-gated and audited.

---

## 5. Phase 3 — Customer Identity & Portal

**Objective:** give registered customers a safe, scoped self-service experience without weakening staff security.

**Effort:** L (identity design is the critical path).

### 5.1 Features

1. **Identity linkage** — `customers.user_id` (nullable, unique) or `customer_users` link table; explicit backfill rules based on verified email/phone; one customer can own multiple vehicles.
2. **Customer-scoped authorization** — every portal query filters by the authenticated customer; no IDOR-by-increment.
3. **Customer portal pages** — my vehicles, my appointments (book/cancel), my job status, my invoices and receipts (read-only).
4. **Repair-history visibility** — customer sees only own vehicles/jobs, never internal notes flagged private.
5. **Notification preferences** — in-app only initially; email optional when transport is verified.
6. **Email verification** — required before portal access; resend with throttle.
7. **Account recovery UX** — align with existing `forgot-password` flow.

### 5.2 Reuse

- Existing `customers`, `vehicles`, `appointments`, `invoices`, `payments` read paths; `AppointmentService`; `AuthService`; `require_permission`; audit chain.

### 5.3 Required changes

- Schema: linkage column/table + uniqueness on normalized email (or a normalized-email index).
- Permissions: replace the empty customer role grant with scoped capabilities (e.g. `portal.access`, `portal.appointments`), **not** staff codes like `vehicles.view`.
- New authorization helper: `require_customer_owns('vehicle', $id)`.
- Portal layout separate from staff shell.
- Privacy copy updates (`privacy.php`) for account data.

### 5.4 Dependencies

- Phase 1 (stable schema, rate limiting).
- Phase 2 recommended so customers see accurate part/service status rather than incomplete data.

### 5.5 Risks

- **Horizontal privilege escalation** is the dominant risk. Mitigation: single scoping helper used by every portal query; negative tests for cross-customer access.
- **Data-matching errors** during backfill (shared phone/email). Mitigation: manual review queue; never auto-link on phone alone.
- **Staff/customer permission bleed** — never grant staff permission codes to customers; use dedicated `portal.*` codes.

### 5.6 Acceptance criteria

- [ ] Every portal endpoint is covered by a cross-tenant negative test.
- [ ] Customer A cannot read Customer B's vehicle, job, invoice or receipt by changing an ID.
- [ ] Portal shows only data belonging to the authenticated customer.
- [ ] Customer role still cannot access any staff route.
- [ ] Email verification and throttling verified end-to-end in a test environment.

---

## 6. Phase 4 — Billing & Finance Completion

**Objective:** turn the existing invoice/payment core into a complete, auditable finance workflow.

**Effort:** L.

### 6.1 Features

1. **Expense management UI hardening** — categories, recurring expenses, receipt/attachment reference, monthly expense report (module becomes reachable after Phase 1).
2. **Financial reconciliation** — invoice totals vs items vs payments vs refunds; daily cash reconciliation by method; variance report.
3. **Customer statements** — period statement of invoices/payments/balance; print/CSV.
4. **Aged receivables** — 0–30/31–60/61–90/90+ buckets.
5. **Credit notes / write-offs** — controlled, permission-gated, fully audited; never mutate existing payments.
6. **Tax reporting** — VAT summary from invoice tax fields; configurable rate already in settings.
7. **Optional online payments** — only if a gateway is contractually chosen (Telebirr/Chapa/card); webhook idempotency, signature verification, reconciliation to `payments`.
8. **Refund approval workflow** — optional second-approver step for refunds above a threshold.

### 6.2 Reuse

- `InvoiceService` (integer-cent math, rebuild rules), `PaymentService` (immutability, overpayment block), `ReportService`, `send_csv`, existing receipt/print pages.

### 6.3 Required changes

- New permissions: `payments.approve_refund`, `reports.tax`, `finance.reconcile`.
- New tables only where unavoidable (credit notes, gateway events); keep `payments` immutable.
- Report additions; dashboard refinements once expenses are live.

### 6.4 Dependencies

- Phase 1 (expenses ledger + grants).
- Phase 2 for supplier payables (kept separate from customer receivables).
- Gateway credentials and compliance review if item 7 is pursued.

### 6.5 Risks

- **Mutating historical payments** — forbidden; use credit notes/refunds only.
- **Gateway double-crediting** — idempotency keys and unique external references.
- **Rounding drift** — keep integer cents end-to-end; never introduce float arithmetic.

### 6.6 Acceptance criteria

- [ ] Reconciliation report proves invoice totals = items, and paid = payments − refunds, for every invoice.
- [ ] Statements and aged receivables match ledger totals.
- [ ] Credit notes are append-only and audited; original payments unchanged.
- [ ] VAT report matches invoice tax fields.
- [ ] (If gateways) replayed webhooks do not duplicate payments.

---

## 7. Phase 5 — Workshop Quality & Operations

**Objective:** formalize quality control, handover and parts traceability.

**Effort:** L.

### 7.1 Features

1. **QC / inspection checklists** — per-service or per-job templates, pass/fail items, inspector, timestamp; blocks `Completed → Delivered` until QC passes (configurable).
2. **Delivery note / gate pass** — printable handover with vehicle condition, mileage out, parts fitted, warranty text.
3. **Mileage-out capture** — already partly present (`mileage_in`); add `mileage_out` and validate monotonicity.
4. **Barcode / QR** — generate labels for SKUs and job cards; scan into issue/receive flows (requires a JS scanner library vendored locally, respecting CSP).
5. **Scheduling depth** — bay/capacity view, appointment conflict detection, mechanic availability integration.
6. **Warranty tracking** — labor warranty (settings already mention 30 days) and parts warranty per job.
7. **Technician time tracking** — optional clock-on/clock-off per job service.

### 7.2 Reuse

- `job_services`, `job_updates`, `JobService` transitions, `InventoryService` issue/receive, print patterns from invoices/receipts, `services.estimated_hours`.

### 7.3 Required changes

- New tables: `inspection_templates`, `inspection_results`, `delivery_notes`, `warranty_records` (names indicative).
- New column: `job_cards.mileage_out`.
- New permissions: `jobs.qc`, `jobs.deliver`, `inventory.scan`.
- Vendor a scanner library locally (CSP `script-src 'self'`).

### 7.4 Dependencies

- Phase 1 (priority column, stable schema).
- Phase 2 for lot/part traceability if barcode is applied to stock movements.

### 7.5 Risks

- **Blocking delivery on QC** can halt operations if templates are misconfigured — make the gate configurable and provide an audited override.
- **Scanner library** must be vendored (no CDN; CSP forbids it).
- **Scope creep** — time tracking is optional and should be deferred if not contractually required.

### 7.6 Acceptance criteria

- [ ] QC result is required (when enabled) before delivery; override is permissioned and audited.
- [ ] Delivery note prints with correct vehicle, mileage, parts and warranty text.
- [ ] Barcode scan issues/receives the correct part and writes one ledger row.
- [ ] Appointment conflict detection prevents double-booking a bay/mechanic.

---

## 8. Phase 6 — Scale & Enterprise Readiness

**Objective:** support growth beyond a single branch while preserving the security model.

**Effort:** XL.

### 8.1 Features

1. **Multi-branch** — `branches` table, `branch_id` scoping on users, jobs, inventory, invoices; branch-level reporting; strict cross-branch denial.
2. **External API** — versioned, token/OAuth2-authenticated, rate-limited, scoped endpoints; reuse services, never raw SQL.
3. **Observability** — structured logs, request IDs, error aggregation, audit-chain health check, slow-query review.
4. **High availability** — move sessions to Redis/DB, read replicas for reporting, queue for mail/notifications.
5. **Compliance exports** — audit archive with external anchoring, retention policy, GDPR-style subject export/erasure workflow (respecting append-only audit).
6. **Disaster recovery** — automated backups, PITR, documented RTO/RPO, periodic restore drills.
7. **Performance** — query plans at volume, archival of closed jobs, summary tables for reports.

### 8.2 Reuse

- All services, audit chain, RBAC, report catalogue, `Database` wrapper.

### 8.3 Required changes

- Schema: branch scoping and archival tables.
- Cross-cutting: branch-aware authorization helper; API authentication layer.
- Infrastructure: Redis/queue, backup automation, monitoring.

### 8.4 Dependencies

- Phases 1–5 complete and stable.
- Organizational decisions: branch model, data-retention policy, API consumers.

### 8.5 Risks

- **Cross-branch data leakage** — the highest-severity risk in this phase; enforce at the query-helper level and test negatively.
- **API surface expansion** — each endpoint needs authorization, rate limiting, and audit.
- **Session migration** — must preserve idle/absolute timeout and UA binding semantics.

### 8.6 Acceptance criteria

- [ ] No query can cross a branch boundary without explicit permission.
- [ ] API endpoints are authenticated, scoped, rate-limited, audited, and covered by contract tests.
- [ ] Audit health and error rate are monitored; alerts fire on chain verification failure.
- [ ] Restore drill meets documented RTO/RPO.

---

## 9. Traceability: Phase 0 findings → phases

| Finding | Phase |
|---|---|
| F-01 expenses missing | **Phase 1** (1.2) |
| F-02 `setup.php` public | **Phase 1** (1.5) |
| F-03 customer linkage / 0 perms | **Phase 3** (design decision recorded in Phase 1) |
| F-04 supplier link bug | **Phase 1** (1.6) |
| F-05 priority column missing | **Phase 1** (1.3) |
| F-06 public form abuse | **Phase 1** (1.8) |
| F-07 remote images | **Phase 1** (1.9) |
| F-08 documentation drift | **Phase 1** (1.11) |
| F-09 migration history unreadable | **Phase 1** (1.4) |
| F-10 customer dashboard 403 link | **Phase 1** (1.7) |
| F-11 cached quantity drift | **Phase 1** (1.14) + **Phase 2** (guardrails) |
| F-12 dual supplier fields | **Phase 2** |
| F-13 crypto unused | **Phase 3/4** (decide if at-rest encryption is required) |
| F-14 audit anchor gap | **Phase 1** (runbook) + **Phase 6** (automation) |
| F-16 `contact_inquiries` re-import | **Phase 1** (1.10, decide retention) |

---

## 10. Global definition of done (all phases)

Every phase must satisfy:

1. Server-side authorization on every new route/action; deny-by-default.
2. All writes inside transactions where multiple rows change; monetary values in integer cents.
3. Audit entries for create/update/delete/approve/refund/export.
4. No raw SQL string concatenation with user input.
5. CSP unchanged or strengthened; no inline scripts; no new external origins without review.
6. `php -l` clean across all files; unit + integration tests green; audit chain still verifies.
7. Documentation updated in the same change set (no drift).
8. Migration is forward-only, guarded, reversible by backup, and applied with `garage_migrator`.
9. Browser smoke test of the golden path and at least one negative permission test.
10. No feature is considered done until its **negative** access path is tested.

---

## 11. Approval gate

Phase 0 ends here.

**Phase 1 has not started.** No source file, schema, migration, configuration or dependency was changed during Phase 0. Await explicit approval before beginning Phase 1.
