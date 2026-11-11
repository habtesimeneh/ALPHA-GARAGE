# Phase 1 — Database Changes

**Database:** `garage_management_system` (MySQL 5.7.24, InnoDB, utf8mb4)
**Window:** 2026-11-09
**Backup before any write:** `C:\Users\Temesgen\Documents\Qoder\2026-11-09\d7b76288\phase1-backups\20261109_020529\full_before.sql` (61 KB, 28 tables, schema + data + triggers + routines), plus source archive and 149-file hash manifest in the same directory.

No table was dropped, truncated, or recreated. No existing record was deleted or rewritten by a migration.

---

## 1. Migration State

`schema_migrations` now contains **9** rows. Checksums are SHA-256 of the migration files at apply time.

| Migration | Checksum | Applied |
|---|---|---|
| `alpha_garage_brand.sql` | `ef3e31c0f9dfcbd0581768a4d6e23e7727392265e5a038a1601295cd7a5e2c18` | 2026-11-08 |
| `contact_inquiries.sql` | `9bb69151b8543cf9e6517e11f56ca7a2674ac4a590efbedc5927ddb687015795` | 2026-11-08 |
| `add_remember_token_to_users.sql` | `d5982ad1cedbecd7b2ddc153b7bd7c3d01db52be315caaadd9377306687affaf` | 2026-11-08 |
| `enterprise_upgrade.sql` | `ba41a87e5586f47bb4063d399db754a8f4008f9863836a59017230b4a051ef5b` | 2026-11-09 (bookkeeping recorded in Phase 1) |
| `password_resets.sql` | `b3b61e3c031734c74236f0bb7436c5161f244ca0a78b77a2971c4f77e839a346` | 2026-11-09 (bookkeeping recorded in Phase 1) |
| `revoke_customer_staff_access.sql` | `5b05330f558bbd876e38d5c129b7c5a9578a660ab0bebeea68020e6776044a66` | 2026-11-09 (bookkeeping recorded in Phase 1) |
| `widen_login_attempt_username.sql` | `ab72a8c2aa27eb3e73ebdcc2257fa640e68bdeff7608760658a191aa836b643c` | 2026-11-09 (bookkeeping recorded in Phase 1) |
| `20261108_expense_ledger.sql` | `29e2365ae5d61428973d6c4cb3e6862a85b8d586f6851c648fc2542737840e3f` | 2026-11-09 (applied in Phase 1) |
| `20261109_job_card_priority.sql` | `8155fd8405ee26b963def2049da3d4a258f81487a90387113dcf90d49e64f316` | 2026-11-09 (applied in Phase 1) |

Timestamps are stored as `TIMESTAMP`; displayed values vary with the session time zone. Only the date is reported here.

### Why four rows were recorded without re-running them

The objects created by `enterprise_upgrade.sql`, `password_resets.sql`, `revoke_customer_staff_access.sql`, and `widen_login_attempt_username.sql` already existed in the live schema (columns, tables, permission rows, and the widened `login_attempts.username` type were inspected directly). Their bookkeeping was absent. Each was recorded with the exact checksum of its file; the runner then correctly skipped it. This avoided re-executing DDL/DML against an already-upgraded database.

---

## 2. Applied Migration 1 — `20261108_expense_ledger.sql`

### Schema

Created table `expenses`:

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED | PK, AUTO_INCREMENT |
| `category` | VARCHAR(40) | indexed |
| `description` | VARCHAR(255) | required |
| `amount` | DECIMAL(12,2) | CHECK `amount > 0` declared |
| `expense_date` | DATE | indexed |
| `reference` | VARCHAR(80) | nullable |
| `recorded_by` | INT UNSIGNED | FK → `users.id` ON DELETE SET NULL |
| `created_at` | TIMESTAMP | default CURRENT_TIMESTAMP |

Indexes: `idx_expense_date`, `idx_expense_category_date` (per migration file).

> MySQL 5.7 parses but does not enforce `CHECK` constraints. The application validates `amount > 0`; this limitation is now documented in `documentation/DATA_DICTIONARY.md`.

### Authorization data

Inserted two permission codes:

- `expenses.view`
- `expenses.manage`

Granted to roles `administrator` and `accountant` (4 new `role_permissions` rows). Result: `permissions` 38 → **40**, `role_permissions` 81 → **85**, total live tables 28 → **29**.

### Runtime privileges

`garage_app` received only:

```sql
GRANT SELECT, INSERT ON `garage_management_system`.`expenses` TO 'garage_app'@'localhost';
```

No `UPDATE`, no `DELETE`, preserving the append-only expense behavior. Verified at runtime: `UPDATE expenses …` and `DELETE FROM expenses …` fail with SQLSTATE 42000 (command denied).

### Migration-status visibility

```sql
GRANT SELECT ON `garage_management_system`.`schema_migrations` TO 'garage_app'@'localhost';
```

This restores read-only migration-status visibility without granting any write access.

`least_privilege.sql` was **not** rerun; these grants were applied explicitly.

---

## 3. Applied Migration 2 — `20261109_job_card_priority.sql`

Added to `job_cards`:

```sql
priority ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal' AFTER status
```

- Existing rows (none at the time of migration) would inherit `Normal`; new inserts default to `Normal`.
- No existing column was changed or removed.
- Verified by round-trip inside a rolled-back transaction: omitted priority stored `Normal`; explicit `High` stored `High`.
- Job-card lifecycle and permissions were untouched.

---

## 4. Data Preservation Evidence

A scratch database was restored from `full_before.sql` (database name rewritten in a temporary copy only) and compared with the live database after migration:

| Check | Result |
|---|---|
| Tables in backup | 28 |
| Tables live | 29 (`expenses` added) |
| Row-count differences | Exactly 3 expected: `permissions` 38→40, `role_permissions` 81→85, `schema_migrations` 3→9 |
| Content hashes of the other 25 tables | 25 checked, **0 mismatches** |
| Unaffected tables included | users, roles, customers, vehicles, job_cards (shared columns), invoices, invoice_items, payments, inventory_transactions, spare_parts, suppliers, services, audit_logs, audit_chain_head, garage_settings |
| Audit chain | `verifyChain()` → ok, 39 records |
| Users | 2 (unchanged) |

The scratch database was dropped after comparison.

---

## 5. Privilege Summary (verified)

- `garage_app` remains a per-table least-privilege account. Relevant additions: `SELECT, INSERT` on `expenses`; `SELECT` on `schema_migrations`.
- `garage_app` still has **no** `UPDATE`/`DELETE` on `expenses`, no `UPDATE`/`DELETE` on `audit_logs`, and only `UPDATE (receipt_number)` on `payments`.
- `garage_migrator` has `ALL PRIVILEGES` on `garage_management_system` only (no global privileges) and was not used for the apply; the approved local root DBA was used.
- `trg_payments_immutable` and audit append-only triggers were not modified.

---

## 6. Rollback Limits

- **No rollback was executed.** A rollback would be a forward-only corrective action, not an automated "down" migration.
- Dropping `expenses` would destroy expense records created after the change; archive data first if ever required.
- Dropping `job_cards.priority` would destroy recorded priority values.
- Restoring `full_before.sql` would revert the two migrations and is only appropriate after a verified failure, with downtime and application rollback.
- The pre-change backup **does not** contain `expenses` or the `priority` column; restoring it returns the database to the pre-Phase-1 schema.
- New expense records created after 2026-11-09 would need a separate dump/export if a restore of the pre-change backup is ever considered.

---

## 7. Verification Commands (summary)

- Runner idempotency: all nine files printed `Already applied`, exit 0.
- `SHOW COLUMNS FROM expenses` → 8 expected columns.
- `SHOW COLUMNS FROM job_cards LIKE 'priority'` → enum with default `Normal`.
- Permission count: 40; role counts: administrator 40, receptionist 20, mechanic 7, inventory_manager 9, accountant 9, customer 0.
- Rollback-only runtime checks for expenses insert/read and priority persistence.
- Data comparison against scratch restore as described in section 4.

Exact command output is in `PHASE_1_TEST_RESULTS.md`.
