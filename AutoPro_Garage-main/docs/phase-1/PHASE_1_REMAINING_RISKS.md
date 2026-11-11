# Phase 1 — Remaining Risks

**Date:** 2026-11-09
**Status:** Phase 1 stabilized the foundation; the system is **not** declared production-ready. The following risks remain and are intentionally carried into a later phase.

---

## 1. High-Priority Operational Risks

### R1. Installer file still present on disk
- **Risk:** `public/setup.php` is shipped with the application. Phase 1 made it return 404 while `storage/installed.lock` exists or any user exists, and added an optional `GMS_SETUP_TOKEN`, but the file itself remains reachable if the lock is removed (for example by a partial deployment or a fresh copy of the source).
- **Impact:** An attacker who can cause the lock file to be missing and empty the users table could re-run setup.
- **Recommendation:** Delete `public/setup.php` from production after confirming administrator login; keep it only in a controlled installer bundle.

### R2. Rate limiter trusts `REMOTE_ADDR` and fails open
- **Risk:** `client_ip()` uses `REMOTE_ADDR` only. Behind a reverse proxy/load balancer, all clients may appear as one IP, causing legitimate users to share a throttle bucket; conversely, if a trusted-proxy layer is added later without adjusting this, limits may be wrong.
- **Risk:** `RateLimiter` deliberately fails open when `storage/rate_limits/` is unavailable, and stores one small file per action+IP with no automatic garbage collection.
- **Impact:** Over-blocking behind a proxy; unbounded (slow) file growth under large-scale abuse; limiter bypass if storage breaks.
- **Recommendation:** If a proxy is introduced, add a trusted-proxy allowlist and only then honor `X-Forwarded-For`. Add periodic cleanup of counters older than 1 day. Consider a database-backed limiter if public traffic becomes large.

### R3. Destructive integration suite never runs against live
- **Risk:** `tests/run_tests.php` intentionally refuses non-`_test` databases. Phase 1 therefore did not re-execute end-to-end payment, invoice, stock, and IDOR flows.
- **Impact:** Regressions in those flows would not have been caught by Phase 1 verification.
- **Recommendation:** Stand up a dedicated `garage_management_system_test` database, import the schema, run setup with demo data, and execute the suite there before any release. This is the main outstanding verification gap.

---

## 2. Security Risks

### R4. No authenticated customer end-to-end test
- The live database has no `customer` user. Customer access was verified by role-permission data (0 permissions) and the `can('dashboard.view')` navigation guard, not by a logged-in customer session.
- **Impact:** A future change to session or permission loading could regress customer behavior without Phase 1 noticing.
- **Recommendation:** Create a disposable customer account in a test database and walk the registration → login → index → profile flow.

### R5. MySQL 5.7 does not enforce `CHECK` constraints
- `expenses.amount > 0` (and similar declared checks) are parsed but not enforced by MySQL 5.7.24.
- Application validation covers the known insert path; a direct DB client with insert rights could still write invalid values.
- **Impact:** Data-quality risk if inserts bypass the application.
- **Recommendation:** Upgrade to MySQL 8+ where CHECK is enforced, or add triggers/application guards for every write path.

### R6. Public pages depend on remote Unsplash images
- Registration and contact pages load hero images from `images.unsplash.com`, allowed by the CSP.
- **Impact:** Third-party availability/privacy dependency and unnecessary external requests on public forms.
- **Recommendation:** Host approved images locally in a later asset pass.

### R7. Session/CSRF behavior is not load-tested
- CSRF tokens expire after 2 hours; the global `verify_csrf()` runs on every POST. Long-lived forms (registration, contact) left open longer than the token lifetime will fail with HTTP 419 after submission.
- **Impact:** A user who leaves the form open for over 2 hours loses the submission with an error page.
- **Recommendation:** Surface a friendlier "session expired, reload" message on the public forms or extend the token window specifically for them.

---

## 3. Data and Schema Risks

### R8. Four migration records were backfilled
- `enterprise_upgrade.sql`, `password_resets.sql`, `revoke_customer_staff_access.sql`, and `widen_login_attempt_username.sql` were recorded after verifying their objects already existed.
- **Impact:** If any of those files is edited in the future, the runner will abort on a checksum mismatch (by design). The recorded checksum reflects the file content at Phase 1 time.
- **Recommendation:** Treat applied migrations as immutable. Document any corrective change as a new migration.

### R9. Pre-change backup does not contain the new schema
- `full_before.sql` has 28 tables and no `expenses` or `job_cards.priority`.
- **Impact:** Restoring it reverts both migrations and any expense data created afterwards.
- **Recommendation:** Take a fresh verified backup after Phase 1 acceptance and retain both backups until a later release is proven.

### R10. Registration customer linking can race
- Registration checks for an existing customer by email/phone before insert, inside a transaction. Two simultaneous registrations with the same contact data could still create duplicate customer rows if no unique constraint exists on those columns.
- **Impact:** Duplicate customer records, not data corruption.
- **Recommendation:** Review uniqueness constraints and add a race-safe constraint or locking strategy in a later phase.

### R11. Expense ledger is append-only by grant, not by trigger
- `garage_app` cannot UPDATE/DELETE `expenses`, but no trigger enforces immutability for other privileged accounts.
- **Impact:** A DBA or future over-privileged account could alter expense history without an audit trail.
- **Recommendation:** Consider an append-only trigger or an audit trigger for expenses if financial integrity requirements grow.

---

## 4. Documentation Risks

### R12. Historical reports still contain superseded figures
- `documentation/TESTING_REPORT.md` retains its original per-suite narrative (including "25 tables"); it is now explicitly marked historical / NOT VERIFIED, and the sign-off is marked superseded.
- **Impact:** A reader who skims past the notice may quote old numbers.
- **Recommendation:** Replace the historical report with a generated report from a real test run in a later phase.

### R13. Permission matrix maintenance
- The corrected matrix was generated from live data on 2026-11-09. Any future role/permission change must update it manually.
- **Recommendation:** Add a small read-only script that prints the matrix from `permissions`/`role_permissions`, and run it when roles change.

---

## 5. Environment / Deployment Risks

- **R14. Debug settings:** Production must run with `display_errors=0` and application `debug=false`; Phase 1 did not change environment configuration. Verify before deployment.
- **R15. Backup custody:** The Phase 1 backup directory contains a DBA defaults file with restricted ACLs. It must stay outside the web root and must not be copied into the repository.
- **R16. No staging environment:** All verification was performed against the local MAMP instance. A staging environment with a copy of production data is still needed for release testing.

---

## 6. Phase 2 Entry Conditions

Before starting Phase 2 or any release activity:

1. Delete `public/setup.php` in the production copy (or keep it only in the installer bundle).
2. Create and verify a post-Phase-1 backup.
3. Stand up a `*_test` database and run the destructive integration suite there.
4. Exercise registration and contact forms in a browser, including a valid submission on a disposable environment.
5. Decide the trusted-proxy/IP policy if the application will sit behind a load balancer.
6. Re-confirm the administrator login before removing any recovery path.

No Phase 2 work has been started.
