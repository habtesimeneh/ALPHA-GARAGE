# Administrator Account Investigation (R22)

**Phase:** 1.6 — Pre-Production Security Cleanup
**Date:** 2026-11-09
**Database:** `garage_management_system` (MySQL 5.7.24)
**Mode:** **Read-only.** No user row was created, updated, deactivated or deleted. No audit record was touched.
**Decision status:** **AWAITING OWNER APPROVAL.** No change will be made without it.

---

## 1. Question being answered

Phase 1.5 identified an active administrator account named `kilotest_auth_63e8ee04` in the live database and flagged it as risk **R22**. This investigation answers four questions:

1. Does the account still exist?
2. Which administrator accounts exist in total, and which one is the intended owner?
3. Has the test account ever been used (login attempts, sessions, audit activity)?
4. What is the safest recommended action?

---

## 2. Account inventory (live, observed 2026-11-09)

Query source: `users JOIN roles`, executed read-only as the DBA account. Duplicate evidence is stored in the Phase 1.6 evidence bundle (`admin_accounts.txt`).

| Field | Account A | Account B |
|---|---|---|
| ID | **1** | **3** |
| Username | `kilotest_auth_63e8ee04` | `acount` |
| Full name | `Kilo Auth Test` | `adirgeh` |
| E-mail | `kilotest_63e8ee04@example.invalid` | `admin@gmail.com` |
| Role | `administrator` | `administrator` |
| Permissions via role | 40 of 40 | 40 of 40 |
| `is_active` | **1 (enabled)** | **1 (enabled)** |
| `must_change_password` | 0 | 0 |
| Created (Africa/Addis_Ababa) | 2026-11-08 14:54:00 | 2026-11-08 14:57:34 |
| `updated_at` | same as creation (never modified) | 2026-11-08 22:58:21 |
| `last_login_at` | **NULL — never** | **2026-11-09 09:58:21** |
| `last_login_ip` | NULL | `::1` (loopback) |
| Remember-me token | none | none at present |
| Password hash | bcrypt (`$2y$`, cost 10), 60 chars | bcrypt (`$2y$`, cost 10), 60 chars |
| Other rows referencing this user | none | `user_created` audit #3 |

The database contains exactly **two** administrator accounts. No mechanic, customer or staff account is linked to either user row.

> Timestamp note: `users.created_at` is a `TIMESTAMP` shown by a `SYSTEM`-time-zone MySQL session, while `audit_logs.created_at` is a `DATETIME` written by PHP in `Africa/Addis_Ababa`. Both normalize to 2026-11-08 ~11:54 UTC. Times above are stated in the application time zone.

---

## 3. Identification of the intended owner administrator

**`acount` (ID 3) is the intended owner account.** Evidence:

- Full name and e-mail are a real person/domain (`adirgeh`, `admin@gmail.com`), unlike the generated `.invalid` test identity.
- It is the only account with an audited creation event: audit **#3** `user_created` at 2026-11-08 14:57:34, performed by the installer/first-run flow.
- It has genuine use: **17** `login_success` events, 9 `remember_me_enabled`, 3 `remember_me_restored`, 1 `logout`, 1 `audit_chain_verified`, and a last login on 2026-11-09 09:58:21.
- Its `updated_at` differs from `created_at`, showing normal account use.

**`kilotest_auth_63e8ee04` (ID 1) is a test artifact.** Evidence:

- Username suffix `63e8ee04` and the `Kilo Auth Test` full name match the naming pattern of automated authentication tests (`kilotest_*`).
- E-mail uses the reserved, non-deliverable `.invalid` TLD, so account recovery by e-mail is impossible by design.
- `updated_at` equals `created_at`; the row has never been modified.
- It was created at 14:54:00, seconds before the first recorded test login (`kilotest_auth_155481c9`, user ID 2, 14:54:33), which places its creation inside the earlier authentication-test window.

---

## 4. Has the test account ever been used?

**No.** Four independent checks agree:

| Check | Result |
|---|---|
| `users.last_login_at` / `last_login_ip` | `NULL` / `NULL` |
| `login_attempts` rows for `kilotest_auth_63e8ee04` | **0** |
| `audit_logs` rows with `username = 'kilotest_auth_63e8ee04'` | **0** |
| Sessions / remember tokens | none (`remember_token` NULL, expiry NULL) |
| Business rows created by user 1 (`created_by`, `recorded_by`, `updated_by`) | none — all operational tables are empty |

The account has therefore never authenticated, never performed an action, and never appeared in a rate-limit or audit record. Its exposure is the **enabled administrator credential itself**, not observed misuse.

---

## 5. Related historical test accounts (context)

The audit chain contains evidence of earlier disposable test administrators that no longer exist as `users` rows:

| User ID | Username in audit | Audit evidence | Current `users` row |
|---|---|---|---|
| 2 | `kilotest_auth_155481c9` | `login_failed` #1 | absent |
| 4 | `kilotest_rm_4df48059` | `login_failed` #13 | absent |
| 5 | `kilotest_rm_cf41b88e` | `login_failed` #19 | absent |
| 6 | `kilotest_rm_f727e832` | `login_failed` #25 | absent |

Two observations, stated as facts rather than conclusions:

1. **There is no audited `user_deleted` event** for any of these accounts. The audit chain records only authentication events for them.
2. **`users.AUTO_INCREMENT` is currently 4** while IDs 4–6 were once used. This implies the `users` table was recreated or its counter reset after those test rows were removed — consistent with earlier test-harness or schema-import activity, none of which occurred in Phase 1.5 or Phase 1.6.

These rows are **historical evidence and must not be removed**. The audit log is append-only and its integrity is intact (40 of 40 records verified during this investigation).

Other audit facts:

- Audit **#40** is the disclosed Phase 1.5 accidental `login_failed` probe for username `admin` from `127.0.0.1`. It is unrelated to account A, which has never been attempted.
- Total `login_failed` events: **7** (4 historical `kilotest_*` test users, 2 `kilotest_no_such_*`/anonymous, 1 `admin` probe).
- Total `login_success` events: **17**, all belonging to `acount`.
- `password_resets` is empty, so neither account has ever used the reset flow.

---

## 6. Risk assessment

| Attribute | Assessment |
|---|---|
| Likelihood of misuse | **Low locally** (loopback-only deployment, no network exposure), **High once exposed** if the password is weak or known from test logs |
| Impact if misused | **Critical** — full 40-permission administrator role: user/role management, settings, billing, audit read/export, data mutation |
| Current compensating controls | Account is enabled; login lockout (5 per user+IP / 20 per IP per 15 min) and full auditing are active; e-mail domain is non-deliverable so reset-based takeover is not possible; the application is not publicly exposed |
| Residual concern | An enabled administrator whose password provenance is unknown is a standing credential. Test harnesses frequently use trivial passwords; that value may exist in logs or notes outside the database. |
| Severity | **High** (unchanged from R22) |

Deleting the row is **not** required to close the risk and would destroy attribution; deactivation is the lower-impact control. Because the account owns no business records, deactivation has no data-integrity side effects.

---

## 7. Recommended action (requires owner approval)

**Recommendation: deactivate — do not delete — after explicit owner approval.**

| Option | Effect | Reversible | Audit impact | Recommendation |
|---|---|---|---|---|
| **A. Deactivate** (`is_active = 0`) | Login impossible; 40 permissions retained on paper but unusable; row and ID preserved | Yes (`is_active = 1`) | Should be recorded as an audited `user_updated` event | **Recommended** |
| B. Delete the row | Row removed; audit rows keep the username text but lose any future attribution | No | No deletion audit exists in the app; would require a DBA action | Not recommended |
| C. Change its password and disable | Same as A plus invalidates any leaked password | Partly | Should be audited | Acceptable if the owner prefers a password scrub |
| D. Leave unchanged | Status quo risk | n/a | none | Not acceptable before any network exposure |

### 7.1 Owner decisions requested

1. **Approve deactivation of user ID 1 (`kilotest_auth_63e8ee04`)?** (Yes / No / Delete instead)
2. **Confirm `acount` (ID 3) is the only intended administrator.**
3. **Approve forcing a password change on `acount`** (`must_change_password = 1`) and changing it through `profile.php`, given R23 (password provenance unverified)?
4. **Approve disabling/rotating the remembered-session tokens** for `acount` if any exist at the time (none currently stored).

### 7.2 Exact change that would be applied after approval (NOT executed)

Preferred path — through the application's user-management screen so the change is audited (`user_updated`) and the audit chain stays authoritative. Equivalent direct SQL, only if the UI path is unavailable:

```sql
-- NOT EXECUTED. Requires explicit owner approval.
UPDATE users SET is_active = 0 WHERE id = 1 AND username = 'kilotest_auth_63e8ee04';
```

If performed directly, the deployment log must record the actor, timestamp and reason, because a direct SQL update bypasses the application audit writer. No audit row will be deleted or modified in any case.

### 7.3 Verification that would follow the change

1. Confirm `is_active = 0` and `updated_at` changed for user 1 only.
2. Confirm both administrator rows still exist and `acount` can sign in.
3. Confirm a login attempt as `kilotest_auth_63e8ee04` is rejected with the generic invalid-credentials message.
4. Re-run `AuditLogger::verifyChain()` and confirm `ok=true`.
5. Record the result in the deployment log and in this report.

---

## 8. Evidence index

Stored outside the web root in the Phase 1.6 evidence bundle (`…\d7b76288\phase1_6-backups\20261109_080829\`):

| File | Contents |
|---|---|
| `admin_accounts.txt` | Full account inventory query |
| `login_attempts_all.txt` | All 22 login-attempt rows |
| `audit_account_events.txt` | Audit rows for auth/user events |
| `audit_kilotest_references.txt` | Audit rows mentioning test/owner identities |
| `audit_all_rows.txt` | All 40 audit rows (IDs, users, actions, IPs, times) |
| `audit_user_management.txt` | User-management and remember-me events |
| `admin_time_and_usage.txt` | Time-zone normalization and direct-usage counts |
| `account_summary_counts.txt` | Failed-login, reset-token and administrator counts |
| `fingerprint_before.txt` | Chain verification: `ok=true`, `checked=40` |

---

## 9. Status

- Investigation complete and reproducible.
- **No account was modified, disabled, renamed or deleted.**
- **No audit record was created, changed or removed.**
- The audit chain verified **40/40** before and after this investigation.
- The live-database restore performed later on 2026-11-09 (see `PHASE_1_6_EXECUTION_REPORT.md` §5) reloaded
  both account rows byte-identically from the pre-Phase-1.6 dump; no account was modified by that operation.
- **Execution stops here pending owner approval of §7.1.**
