# DATABASE STRUCTURE — Alpha Garage Management System

| Field | Value |
|---|---|
| Database | `garage_management_system` |
| Engine / charset | InnoDB / `utf8mb4` / `utf8mb4_unicode_ci` |
| Authoritative schema file | `database/database.sql` (579 lines) |
| Audit date | 2026-11-09 |
| Access used | Read-only as `garage_app` (`SHOW TABLES`, `SHOW COLUMNS`, `SHOW INDEX`, `SELECT COUNT(*)`) |
| Mode | **No DDL/DML executed.** |

---

## 1. Live vs. defined: the one-paragraph truth

`database/database.sql` defines **28 tables**. The live database contains **27**. The missing table is `expenses`. Correspondingly, the live database has **38 permissions** while the schema seeds **40**; the two missing rows are `expenses.view` and `expenses.manage`. The live `job_cards` table also lacks the `priority` column added by `database/migrations/20261109_job_card_priority.sql`. Migration bookkeeping (`schema_migrations`) **cannot be read by the runtime account**, so the exact applied migration set is **NOT VERIFIED**; the state below is inferred from columns, tables, and permission rows.

---

## 2. Live table inventory (27)

Grouped by module:

| Group | Tables |
|---|---|
| RBAC & identity | `roles`, `permissions`, `role_permissions`, `users` |
| People | `mechanics`, `customers` |
| Fleet | `vehicles` |
| Workshop | `job_cards`, `job_updates`, `job_services`, `services`, `appointments` |
| Inventory | `suppliers`, `spare_parts`, `inventory_transactions`, `job_parts`, `part_requests` |
| Billing | `invoices`, `invoice_items`, `payments` |
| Security & audit | `login_attempts`, `audit_logs`, `audit_chain_head`, `password_resets` |
| Platform | `notifications`, `garage_settings`, `contact_inquiries` |

Defined but **absent live**: `expenses`.

---

## 3. Table-by-table structure

Notation: PK = primary key, FK = foreign key, UQ = unique, IX = non-unique index.

### 3.1 RBAC and identity

**`roles`** (6 rows)
| Column | Type | Constraints |
|---|---|---|
| id | TINYINT UNSIGNED AI | PK |
| name | VARCHAR(40) | NOT NULL, UQ |
| label | VARCHAR(60) | NOT NULL |
| description | VARCHAR(255) | NULL |

**`permissions`** (38 live / 40 defined)
| Column | Type | Constraints |
|---|---|---|
| id | SMALLINT UNSIGNED AI | PK |
| code | VARCHAR(60) | NOT NULL, UQ |
| module | VARCHAR(40) | NOT NULL |
| label | VARCHAR(120) | NOT NULL |

**`role_permissions`** (81 live rows)
| Column | Type | Constraints |
|---|---|---|
| role_id | TINYINT UNSIGNED | PK part, FK → `roles.id` CASCADE |
| permission_id | SMALLINT UNSIGNED | PK part, FK → `permissions.id` CASCADE |

**`users`** (2 rows)
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| role_id | TINYINT UNSIGNED | NOT NULL, FK → `roles.id`, IX |
| username | VARCHAR(50) | NOT NULL, UQ |
| full_name | VARCHAR(120) | NOT NULL |
| email | VARCHAR(150) | NOT NULL, UQ |
| phone | VARCHAR(30) | NULL |
| password_hash | VARCHAR(255) | NOT NULL (bcrypt/`PASSWORD_DEFAULT`) |
| is_active | TINYINT(1) | DEFAULT 1 |
| must_change_password | TINYINT(1) | DEFAULT 0 |
| last_login_at | DATETIME | NULL |
| last_login_ip | VARCHAR(45) | NULL |
| remember_token | CHAR(64) | NULL — **present live** (migration applied) |
| remember_expires_at | DATETIME | NULL — **present live** |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |

> `remember_token` also has an index (`idx_users_remember_token`) from the migration.

### 3.2 People and fleet

**`mechanics`**
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| user_id | INT UNSIGNED | NULL, UQ, FK → `users.id` SET NULL |
| full_name | VARCHAR(120) | NOT NULL |
| phone | VARCHAR(30) | NULL |
| specialty | VARCHAR(100) | NULL |
| availability | ENUM('available','busy','off_duty') | DEFAULT 'available' |
| is_active | TINYINT(1) | DEFAULT 1 |
| created_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |

**`customers`**
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| full_name | VARCHAR(120) | NOT NULL |
| phone | VARCHAR(30) | NOT NULL |
| email | VARCHAR(150) | NULL — **not unique** |
| address | VARCHAR(255) | NULL |
| is_active | TINYINT(1) | DEFAULT 1 |
| registered_at | TIMESTAMP | DEFAULT CURRENT_TIMESTAMP |
| created_by | INT UNSIGNED | NULL, FK → `users.id` SET NULL |
| updated_at | TIMESTAMP | ON UPDATE CURRENT_TIMESTAMP |
| — | — | IX `full_name`, IX `phone` |

> **No `user_id` column.** There is no relational path from a login account to "my vehicles/my appointments". This is the structural blocker for a customer portal.

**`vehicles`**
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| customer_id | INT UNSIGNED | NOT NULL, FK → `customers.id`, IX |
| plate_number | VARCHAR(20) | NOT NULL, UQ |
| make | VARCHAR(50) | NOT NULL |
| model | VARCHAR(50) | NOT NULL |
| year | SMALLINT UNSIGNED | NULL |
| color | VARCHAR(30) | NULL |
| vin | VARCHAR(17) | NULL, UQ |
| mileage | INT UNSIGNED | DEFAULT 0 |
| fuel_type | ENUM('Petrol','Diesel','Hybrid','Electric','LPG','Other') | DEFAULT 'Petrol' |
| vehicle_type | VARCHAR(40) | DEFAULT 'Sedan' |
| notes | TEXT | NULL |
| is_active | TINYINT(1) | DEFAULT 1 |
| created_at / updated_at | TIMESTAMP | defaults |

> `vin` is nullable but unique; MySQL allows multiple NULLs, so vehicles without a VIN are fine.

### 3.3 Workshop

**`job_cards`**
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| job_number | VARCHAR(30) | NOT NULL, UQ (`JOB-…`) |
| customer_id | INT UNSIGNED | NOT NULL, FK → `customers.id`, IX |
| vehicle_id | INT UNSIGNED | NOT NULL, FK → `vehicles.id`, IX |
| mechanic_id | INT UNSIGNED | NULL, FK → `mechanics.id` SET NULL, IX |
| date_received | DATE | NOT NULL, IX |
| reported_problem | TEXT | NOT NULL |
| initial_condition | TEXT | NULL |
| diagnosis | TEXT | NULL |
| required_repairs | TEXT | NULL |
| labor_cost | DECIMAL(12,2) | DEFAULT 0, CHECK ≥ 0 |
| estimated_completion | DATE | NULL |
| actual_completion | DATE | NULL |
| mileage_in | INT UNSIGNED | NULL |
| status | ENUM('Pending','Diagnosing','Waiting for Parts','In Progress','Completed','Delivered','Cancelled') | DEFAULT 'Pending', IX |
| created_by | INT UNSIGNED | NULL, FK → `users.id` SET NULL |
| created_at / updated_at | TIMESTAMP | defaults |
| **`priority`** | ENUM('Low','Normal','High','Urgent') | **ABSENT live** — defined only in migration `20261109` |

**`job_updates`** — append-style timeline
`id` PK · `job_id` FK CASCADE · `user_id` FK SET NULL · `update_type` ENUM('status','note','labor','assignment','parts') · `old_status`/`new_status` VARCHAR(30) · `note` TEXT · `hours` DECIMAL(6,2) · `amount` DECIMAL(12,2) · `created_at`; IX (`job_id`,`created_at`).

**`services`** (8 rows)
`id` PK · `code` UQ · `name` · `category` (IX) · `description` · `standard_price` DECIMAL(12,2) · `estimated_hours` DECIMAL(4,2) · `is_active` · timestamps.

**`job_services`** — service lines attached to a job
`id` PK · `job_id` FK CASCADE (IX) · `service_id` FK SET NULL · `service_name` snapshot · `price` DECIMAL(12,2) · `status` ENUM('Pending','In Progress','Completed','Cancelled') · `assigned_mechanic_id` FK SET NULL · `notes` · `created_at`.

**`appointments`**
`id` PK · `appointment_number` UQ · `customer_id` FK · `vehicle_id` FK · `service_id` FK SET NULL · `appointment_date` DATE (IX) · `appointment_time` TIME · `status` ENUM('scheduled','confirmed','in_progress','completed','cancelled') (IX) · `problem_description` TEXT · `converted_job_id` FK → `job_cards.id` SET NULL · `created_by` FK SET NULL · `created_at`.

### 3.4 Inventory

**`suppliers`** (4 seeded rows)
`id` PK · `code` UQ · `name` (IX) · `contact_person` · `phone` · `email` · `address` · `is_active` · timestamps.

**`spare_parts`**
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| sku | VARCHAR(40) | NOT NULL, UQ |
| name | VARCHAR(150) | NOT NULL, IX |
| category | VARCHAR(60) | NULL, IX |
| unit | VARCHAR(20) | DEFAULT 'pcs' |
| quantity | INT | DEFAULT 0, CHECK ≥ 0 — **cached balance** |
| reorder_level | INT | DEFAULT 5 |
| unit_cost | DECIMAL(12,2) | DEFAULT 0, CHECK ≥ 0 |
| unit_price | DECIMAL(12,2) | DEFAULT 0, CHECK ≥ 0 |
| supplier | VARCHAR(120) | NULL — legacy free text |
| supplier_id | INT UNSIGNED | NULL, FK → `suppliers.id` SET NULL |
| is_active | TINYINT(1) | DEFAULT 1 |
| created_at / updated_at | TIMESTAMP | defaults |

**`inventory_transactions`** — append-only ledger (double-entry style)
`id` BIGINT PK · `part_id` FK (IX with created_at) · `txn_type` ENUM('purchase','issue','return','adjustment_in','adjustment_out') (IX) · `quantity` INT UNSIGNED · `balance_after` INT (snapshot after txn) · `unit_cost` DECIMAL(12,2) · `supplier` VARCHAR(120) · `reference` · `job_card_id` FK SET NULL (IX) · `notes` · `created_by` FK SET NULL · `created_at`.

**`job_parts`**
`id` PK · `job_id` FK CASCADE (IX) · `part_id` FK · `quantity` INT UNSIGNED CHECK > 0 · `unit_price` DECIMAL(12,2) · `returned` TINYINT(1) DEFAULT 0 · `issued_by` FK SET NULL · `issued_at`.

**`part_requests`**
`id` PK · `job_id` FK CASCADE · `part_id` FK · `quantity` CHECK > 0 · `note` · `status` ENUM('pending','issued','rejected') (IX) · `requested_by` FK SET NULL · `handled_by` FK SET NULL · `handled_at` · `created_at`.

### 3.5 Billing

**`invoices`**
| Column | Type | Constraints |
|---|---|---|
| id | INT UNSIGNED AI | PK |
| invoice_number | VARCHAR(30) | NOT NULL, UQ (`INV-…`) |
| job_card_id | INT UNSIGNED | NOT NULL, **UQ (one invoice per job)**, FK |
| customer_id | INT UNSIGNED | NOT NULL, FK, IX |
| vehicle_id | INT UNSIGNED | NOT NULL, FK |
| issue_date | DATE | NOT NULL, IX |
| due_date | DATE | NULL |
| labor_total / parts_total / subtotal | DECIMAL(12,2) | DEFAULT 0 |
| discount | DECIMAL(12,2) | DEFAULT 0, CHECK ≥ 0 |
| tax_rate | DECIMAL(5,2) | DEFAULT 0 |
| tax_amount / grand_total | DECIMAL(12,2) | DEFAULT 0, grand_total CHECK ≥ 0 |
| amount_paid / balance | DECIMAL(12,2) | DEFAULT 0 |
| payment_status | ENUM('Unpaid','Partially Paid','Paid','Refunded') | DEFAULT 'Unpaid', IX |
| notes | VARCHAR(500) | NULL |
| created_by | FK SET NULL | |
| created_at / updated_at | TIMESTAMP | |

**`invoice_items`**
`id` PK · `invoice_id` FK CASCADE (IX) · `item_type` ENUM('labor','part') · `part_id` FK SET NULL · `description` VARCHAR(255) · `quantity` DECIMAL(10,2) · `unit_price` / `line_total` DECIMAL(12,2).

**`payments`** (immutable)
`id` PK · `receipt_number` UQ (`RCT-…` / `RFD-…`) · `invoice_id` FK · `kind` ENUM('payment','refund') · `amount` DECIMAL(12,2) CHECK > 0 · `method` VARCHAR(40) · `reference` · `paid_at` DATETIME (IX) · `notes` · `recorded_by` FK SET NULL · `created_at`; IX `invoice_id`.

> `database/least_privilege.sql` removes UPDATE/DELETE from `garage_app` on `payments`; a DB trigger (`trg_payments_immutable`, created by that script) enforces immutability independently of the app.

**`expenses`** (defined in `database.sql` and `20261108_expense_ledger.sql`, **absent live**)
`id` BIGINT PK · `category` VARCHAR(40) · `description` VARCHAR(255) · `amount` DECIMAL(12,2) CHECK > 0 · `expense_date` DATE (IX) · `reference` · `recorded_by` FK → `users.id` SET NULL (`fk_expense_user`) · `created_at`; composite IX (`category`,`expense_date`).

### 3.6 Security, audit, platform

**`login_attempts`**
`id` BIGINT PK · `username` VARCHAR(150) (**widened live**) · `ip_address` VARCHAR(45) · `success` TINYINT(1) · `attempted_at` DATETIME; IX (`username`,`attempted_at`), IX (`ip_address`,`attempted_at`).

**`audit_logs`** (append-only)
`id` BIGINT PK · `user_id` · `username` · `user_role` · `action_type` VARCHAR(60) (IX) · `module_name` VARCHAR(40) · `record_id` BIGINT · `previous_value` MEDIUMTEXT · `new_value` MEDIUMTEXT · `ip_address` · `user_agent` VARCHAR(255) · `action_result` ENUM('success','failure','denied') · `created_at` DATETIME (IX) · `prev_hash` CHAR(64) · `row_hash` CHAR(64); IX `user_id`, IX (`module_name`,`record_id`).

**`audit_chain_head`** — single row (`id=1`)
`last_id` BIGINT, `last_hash` CHAR(64). Seeded to `0` / 64 zeros. Live: `last_id=39`; head hash `460116…16d02`; 39/39 chain entries verified intact.

**`password_resets`**
`id` PK · `user_id` FK CASCADE (IX) · `token_hash` CHAR(64) UQ (IX) · `expires_at` DATETIME (IX) · `used_at` DATETIME NULL · `created_at`.

**`notifications`**
`id` BIGINT PK · `user_id` NULL (IX with is_read) · `role_name` NULL (IX with is_read) · `title` · `message` VARCHAR(500) · `link` · `is_read` · `created_at`.

**`garage_settings`** (10 rows, key/value)
`setting_key` VARCHAR(60) PK · `setting_value` TEXT · `updated_by` · `updated_at`.

**`contact_inquiries`** (appended to schema; 0 rows)
`id` PK · `name` · `email` · `phone` · `channel` DEFAULT 'Web Contact Form' · `subject` · `message` TEXT · `ip_address` · `status` ENUM('new','in_progress','resolved') DEFAULT 'new' · `created_at`.

---

## 4. Relationships (textual ER map)

```
roles 1──n users
roles 1──n role_permissions n──1 permissions
users 1──1 mechanics (nullable)           users 1──n customers.created_by (nullable)
customers 1──n vehicles                   customers 1──n job_cards
vehicles  1──n job_cards                  vehicles  1──n appointments
mechanics 1──n job_cards (nullable)       mechanics 1──n job_services.assigned_mechanic_id
job_cards 1──n job_updates                job_cards 1──n job_services
job_cards 1──n job_parts n──1 spare_parts job_cards 1──n part_requests n──1 spare_parts
job_cards 1──1 invoices                   job_cards 0..1──1 appointments.converted_job_id
invoices  1──n invoice_items              invoices 1──n payments
suppliers 1──n spare_parts (nullable)     spare_parts 1──n inventory_transactions
users 1──n audit_logs (nullable)          audit_chain_head (singleton, no FK)
users 1──n password_resets                users 1──n notifications (nullable)
```

**Foreign-key count:** 40 defined across the schema; **39 observed live**. The single missing key is `fk_expense_user` — consistent with the absent `expenses` table.

---

## 5. Triggers

| Trigger | Table | Event | Behaviour |
|---|---|---|---|
| `trg_audit_no_update` | `audit_logs` | BEFORE UPDATE | `SIGNAL SQLSTATE '45000'` — rejects all updates |
| `trg_audit_no_delete` | `audit_logs` | BEFORE DELETE | `SIGNAL SQLSTATE '45000'` — rejects all deletes |
| `trg_payments_immutable` | `payments` | BEFORE UPDATE / DELETE | Defined in `database/least_privilege.sql`; live presence **NOT VERIFIED** beyond the grant restrictions observed |

---

## 6. Seed and configuration data

| Table | Live rows | Content |
|---|---|---|
| `roles` | 6 | administrator, receptionist, mechanic, inventory_manager, accountant, customer |
| `permissions` | **38** (40 defined) | all modules except `expenses.view`, `expenses.manage` |
| `role_permissions` | **81** | admin 38, receptionist 20, mechanic 7, inventory_manager 9, accountant 7, customer **0** |
| `garage_settings` | 10 | name "Alpha Garage", address, phone, email, currency ETB, tax 15, labor rate 350, due days 14, payment methods, invoice footer |
| `services` | 8 | diagnostics, oil/filter, brakes ×2, alignment, A/C, transmission, electrical |
| `suppliers` | 4 | Bosch, Denso, Brembo, Shell (Ethiopia examples) |
| `users` | 2 | both `administrator`; credentials not inspected; passwords hashed |
| `audit_logs` | 39 | chain valid |
| `login_attempts` | 21 | historical attempts |
| All operational tables | **0** | customers, vehicles, job_cards, job_updates, job_services, appointments, invoices, invoice_items, payments, spare_parts, inventory_transactions, job_parts, part_requests, notifications, contact_inquiries, password_resets |

**Environment status:** the database is an installed but **empty operational system** — configuration and demo catalogue only, no business transactions.

---

## 7. Migration status (inferred; bookkeeping NOT VERIFIED)

`schema_migrations` is not readable by `garage_app` (SQLSTATE 42000 / 1142). The table exists (the runner uses it), but its rows cannot be read at runtime. Status below is inferred from observable schema/permission state.

| Migration file | Applied? | Evidence |
|---|---|---|
| `enterprise_upgrade.sql` | **YES (inferred)** | `suppliers`, `services`, `job_services`, `appointments`, `notifications` exist; `vehicles.vehicle_type`/`notes` exist; enterprise permissions present; 8 services + 4 suppliers seeded |
| `contact_inquiries.sql` | **YES (inferred)** | table exists |
| `password_resets.sql` | **YES (inferred)** | table exists; `users.phone` exists; customer role exists |
| `alpha_garage_brand.sql` | **YES (inferred)** | `garage_name` = "Alpha Garage" |
| `revoke_customer_staff_access.sql` | **YES (inferred)** | customer role has 0 permissions |
| `add_remember_token_to_users.sql` | **YES (inferred)** | `users.remember_token`, `remember_expires_at`, index present |
| `widen_login_attempt_username.sql` | **YES (inferred)** | `login_attempts.username` is VARCHAR(150) |
| `20261108_expense_ledger.sql` | **NO** | `expenses` absent; `expenses.*` permissions absent; accountant has 7 not 9 permissions |
| `20261109_job_card_priority.sql` | **NO** | `job_cards.priority` absent |

**Operational note:** `database/migrations/20261108_expense_ledger.sql` explicitly warns that after applying it, an authorized DBA must `GRANT SELECT, INSERT ON …expenses TO 'garage_app'`, and that `least_privilege.sql` must **not** be re-run just for that grant because it resets account passwords.

**Runner note:** `database/migrate.php` is CLI-only, forward-only, checksum-based, and uses a custom SQL splitter. Historical log entries show `SQLSTATE[HY000] 2014` (unbuffered queries) during a prior run. Any future migration execution must be done with a privileged account (`garage_migrator`), not `garage_app`, and with the runner's statement handling reviewed.

---

## 8. Schema drift and integrity risks

| ID | Risk | Detail | Direction |
|---|---|---|---|
| D-01 | Missing table | `expenses` absent live; code exists and is permission-gated | Apply migration + grants, or remove the module |
| D-02 | Missing column | `job_cards.priority` absent; service guards with `hasPriorityColumn()` | Apply guarded migration |
| D-03 | Missing permissions | `expenses.view`, `expenses.manage` absent | Seeded by the same expense migration |
| D-04 | Unreadable bookkeeping | `garage_app` cannot `SELECT schema_migrations` | Read via `garage_migrator`/admin tool only |
| D-05 | Cached quantity | `spare_parts.quantity` duplicates ledger state; no trigger reconciles it | Add periodic reconciliation check (report, not mutation) |
| D-06 | Dual supplier fields | `supplier` text and `supplier_id` FK coexist | Standardize reads on `supplier_id`; keep text as snapshot |
| D-07 | Customer identity | No `customers.user_id`; `customers.email` not unique | Design a linkage table/column before portal work |
| D-08 | Re-import behaviour | `DROP TABLE` list omits `contact_inquiries`; table is `IF NOT EXISTS` | Decide whether inquiries should survive a schema re-import |
| D-09 | Audit deletion | Chain detects tampering but cannot detect full-table loss locally | Export head + periodic anchor off-box |
| D-10 | Invoice arithmetic trust | Totals are stored, not generated columns; correctness depends on `InvoiceService` | Keep all writes through the service; add a reconciliation report |
| D-11 | Timezone | DB session forced to `+03:00`, app `Africa/Addis_Ababa` | Consistent today; document for future deployments |

---

## 9. What could not be verified (NOT VERIFIED)

1. Exact applied migrations and checksums (`schema_migrations` unreadable by the runtime account).
2. Existence of `trg_payments_immutable` live (script defines it; trigger catalogue not inspected).
3. Column-level grants for `garage_app` beyond observed denials.
4. `EXPLAIN` plans and index usage under realistic data volumes (all operational tables empty).
5. Referential integrity of production data (no operational rows exist).
6. Backup/restore fidelity of the database.
7. Whether `information_schema` statistics (`TABLE_ROWS`) are accurate — per-table `COUNT(*)` was used instead.

---

## 10. Database recommendations for Phase 1 (planning only)

1. **Baseline first:** record the live schema (mysqldump `--no-data`) as the Phase 1 baseline before applying anything.
2. **Apply the two pending migrations** with `garage_migrator`, then issue the documented `expenses` grant to `garage_app`. Verify `job_cards.priority` and the two permission rows.
3. **Make migration state auditable:** provide a read-only admin status page or CLI report executed as `garage_migrator`; do not grant `garage_app` access to `schema_migrations`.
4. **Add a reconciliation report** (read-only) comparing `spare_parts.quantity` to the latest ledger `balance_after`, and invoice totals to `invoice_items` + `payments`.
5. **Resolve the customer identity model** before building any portal: add `customers.user_id` (nullable, unique) or a `customer_users` link table, backfill by verified email/phone, and enforce uniqueness on normalized email.
6. **Document the audit-anchor procedure:** periodic export of `audit_chain_head.last_id/last_hash` to immutable off-box storage.

No schema change was made during Phase 0.
