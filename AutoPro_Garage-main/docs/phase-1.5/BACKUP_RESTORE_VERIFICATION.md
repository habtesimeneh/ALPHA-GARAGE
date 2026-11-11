# Phase 1.5 — Backup and Restore Verification

**Date:** 2026-11-09
**Live database:** `garage_management_system` (MySQL 5.7.24)
**Backup:** `full_after_phase1.sql`
**Restore target:** `garage_management_system_restore_test` (temporary, dropped after verification)
**Result:** backup valid; restore identical in tables, row counts, checksums and schema; audit chain valid.

---

## 1. Backup creation

| Item | Value |
|---|---|
| Backup directory | `C:\Users\Temesgen\Documents\Qoder\2026-11-09\d7b76288\phase1_5-backups\20261109_024836\` (outside the web root and outside Git) |
| Dump file | `full_after_phase1.sql` |
| Size | 63,527 bytes (non-empty) |
| SHA-256 | `43bf4c6e5b5a8467cfc28c8aec6641b1b9c579b106668fe820239815c03a5363` |
| Contents | Full structure + data for `garage_management_system` |
| Source state | Post-Phase-1 (all nine migrations applied, audit head 39) |
| Pre-Phase-1 backup | Retained (not overwritten) |

The dump was produced with the existing approved `mysqldump` process for this application, not with an ad-hoc schema reset. No table was dropped or truncated to create it.

## 2. Backup validation

- **Non-empty:** yes, 63,527 bytes; checksum recorded and re-verified.
- **Expected tables:** 29 `CREATE TABLE` statements, matching the 29 tables in `garage_management_system`.
- **Phase 1 objects present:**
  - `expenses` table present (0 rows — the live installation has no expenses yet).
  - `job_cards.priority` column present.
  - `password_resets`, `remember_token`/`remember_expires_at`, `contact_inquiries` and `widen_login_attempt_username` changes present through the applied migrations.
- **Migration ledger:** 9 rows in `schema_migrations`, all applied.
- **Triggers:** `trg_audit_no_update`, `trg_audit_no_delete`, `trg_payments_immutable` captured separately from the DBA account (the application account cannot see `information_schema.TRIGGERS`, which requires the `TRIGGER` privilege).
- **Sanitized companion dump:** `test_schema_sanitized.sql` (structure only, no live rows; SHA-256 `6c72d913c6225afb26c7cd2440d7153164a3c73c6f49582c058451782d6c8288`) was used to provision the integration test database, so live data never entered the test environment.

## 3. Test restore

The dump was restored into a **new temporary database**; the live database was never used as a restore target.

1. Verified the target name `garage_management_system_restore_test` ends in `_test`.
2. Created the database, imported `full_after_phase1.sql`.
3. Compared live and restored:
   - Table count: **29 / 29**
   - Exact row-count differences: **0**
   - `CHECKSUM TABLE` differences: **0 of 29**
   - Schema column fingerprint: **identical** (`schema_live.sha256` = `schema_restored.sha256` = `36d4ab289411d6de59a9da5121da53222fc816a34d227eab1e673cd0c4948951`)
4. Verified the restored database could be queried with the application's data model (counts, constraints and triggers behaved normally).
5. Verified the audit chain in the restored copy: `ok=true`, `checked=39`.
6. Dropped the scratch database after evidence capture.

### Row counts (live = restored)

| Table | Rows | Table | Rows |
|---|---:|---|---:|
| `users` | 2 | `roles` | 6 |
| `garage_settings` | 10 | `role_permissions` | 85 |
| `permissions` | 40 | `services` | 8 |
| `suppliers` | 4 | `schema_migrations` | 9 |
| `audit_logs` | 39 | `audit_chain_head` | 1 |
| `login_attempts` | 21 | `customers` | 0 |
| `vehicles` | 0 | `job_cards` | 0 |
| `invoices` | 0 | `payments` | 0 |
| `expenses` | 0 | `spare_parts` | 0 |
| `inventory_transactions` | 0 | all other business tables | 0 |

Every one of the 29 tables matched exactly; the table above highlights the non-empty ones.

## 4. Audit-chain result

- **Restored copy:** all 39 records verified (`ok=true`).
- **Live database at backup time:** `audit_chain_head.last_id = 39`,
  `last_hash = 460116942bfede84820327be789caeba4f848b2b1b7aecb1a4377d10c1916d02`.
- **Live database at the end of Phase 1.5:** `last_id = 40`,
  `last_hash = 1518dc423d1f49d07ad39c38b0e2f90ec92329aecf927eb3fbe21a3225ea930b`, `verifyChain(): ok=true, checked=40`.

The head advanced by one because of the disclosed failed-login probe described in `PHASE_1_5_EXECUTION_REPORT.md` §9. The append-only triggers correctly rejected any attempt to remove the record, and the chain hashes still verify. No audit record was deleted or modified.

## 5. Live database integrity after Phase 1.5

`CHECKSUM TABLE` was re-run on all 29 live tables and compared with the backup-time checksums (`checksums_live.txt` vs `checksums_live_final.txt`):

- **26 of 29 tables unchanged**, including every business table and all reference/config tables.
- Changed tables (all explained by the single probe):

| Table | Backup-time checksum | Final checksum | Explanation |
|---|---:|---:|---|
| `audit_logs` | 1792019281 | 3890158555 | One `login_failed` audit row (#40) |
| `audit_chain_head` | 3396654180 | 3785052530 | Head advanced 39 → 40 |
| `login_attempts` | 590135635 | 2044684807 | One failed-attempt row |

Final live counts: 29 tables; `users=2`, `audit_logs=40`, all business tables empty. No customer, vehicle, job card, invoice, payment, expense, part or inventory record was created, modified or deleted in the live database during Phase 1.5. The two user rows are administrator accounts: the owner account and a pre-existing test artifact created before Phase 1.5 (see R22 in `PHASE_1_5_REMAINING_RISKS.md`); the user-table checksum was identical to the backup, so neither was created by this phase.

## 6. Restore procedure for real recovery

If the live database must ever be restored, follow this order — never restore in place over a running installation:

1. Put the application into maintenance (stop Apache or block traffic).
2. Take a **new** safety dump of the current live database, even if it is believed corrupt; record its checksum.
3. Verify the backup file checksum before use:

   ```bat
   certutil -hashfile full_after_phase1.sql SHA256
   ```

4. Create a new database (for example `garage_recovery`), import the dump, and verify tables/row counts/audit chain.
5. Update `app/config/local.php` (or `GMS_DB_*`) to point at the verified database.
6. Smoke-test login, dashboard, invoices and audit verification.
7. Only then retire the old database, and keep it offline until the incident review is complete.

The restore was rehearsed in Phase 1.5 exactly this way and produced byte-identical table checksums.

## 7. Retention and security

- The dump contains password hashes, user e-mail addresses and operational data. It must be stored offline or in an encrypted, access-controlled location — never in `htdocs`, never in Git, never in a chat or ticket.
- The Phase 1.5 evidence directory also contains a test-only MySQL option file (`test_db.cnf`) and `test_env.sh` with **test-environment** credentials. They were used only for the isolated database; the database and account were dropped. Delete the evidence bundle once it is no longer needed.
- Test-generated mail (password-reset messages in `storage/mail_spool/`) was removed, and `storage/mail_spool/`, `storage/rate_limits/` and `storage/private/encryption.key` are now Git-ignored.
- Retain the pre-Phase-1 backup and this post-Phase-1 backup according to the garage's retention policy; at minimum keep one verified copy off-machine.
