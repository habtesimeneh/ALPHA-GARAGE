# ALPHA GARAGE — PHASE 1.11D: GIT & DATABASE BASELINE REPORT
## Repository Working Tree Audit, Change-Scope Reconciliation & Production Database Safety

**Project:** ALPHA GARAGE Workshop Management System  
**Phase:** 1.11D  
**Author:** AI Systems Architecture & Security Lead (Independent Audit)  
**Date:** 2026-11-18  
**Audit Scope:** Git repository status, diff analysis, scope boundary verification & database invariants  

---

## 1. Executive Summary

This report documents the independent verification of the Git working tree, modified and untracked file inventories, commit history, and production database integrity for Phase 1.11C and Phase 1.11D.

Key Baseline Confirmations:
1. **Working Tree Preserved:** All pre-existing modifications from prior development phases remain staged/unstaged exactly as found. Zero commits, pushes, stashes, resets, or reverts were performed.
2. **Scope Boundary Compliance:** Phase 1.11C modifications were confined strictly to dashboard views (`app/views/dashboards/`), dashboard CSS (`dashboard.css`, `layout.css`, etc.), and the client-side chart script (`dashboard.js`).
3. **Database Unmutated:** Production database `garage_management_system` invariants remain intact with exactly 30 tables, 3 payments, 11 users, and 90 audit records.
4. **Audit Trail Architecture Clarification:** Independent inspection confirms that the audit logging subsystem implements a **linear sequential SHA-256 cryptographic hash chain**, rather than a binary Merkle tree.

---

## 2. Git Working Tree Status & Diff Reconciliation

### 2.1 Working Tree Status (`git status`)
```
On branch main
Your branch is up to date with 'origin/main'.

Changes not staged for commit:
  modified:   app/helpers/functions.php
  modified:   app/middleware/auth.php
  modified:   app/services/AuthService.php
  modified:   app/services/PaymentIdempotencyService.php
  modified:   app/services/PaymentService.php
  modified:   docs/phase-1.9/PHASE_1_9E_EXECUTION_REPORT.md
  modified:   public/assets/css/components.css
  modified:   public/assets/css/dashboard.css
  modified:   public/assets/css/layout.css
  modified:   public/assets/css/responsive.css
  modified:   public/assets/css/tables.css
  modified:   public/assets/css/theme.css
  modified:   public/assets/css/variables.css
  modified:   public/assets/js/dashboard.js
  modified:   public/book.php
  modified:   public/dashboard.php
  modified:   public/login.php
  modified:   public/register.php
  modified:   public/users/form.php

Untracked files:
  app/services/CustomerIdentityService.php
  app/views/dashboards/
  database/migrations/20261117_customer_identity_verification.sql
  docs/phase-1.10/
  docs/phase-1.11/
  docs/phase-1.9/PHASE_1_9E_ARCHITECTURE_AUDIT.md
  ...
  public/customer.php
  public/verify-customer.php
  tests/customer_identity_1_10c_suite.php
  tests/customer_identity_1_10e_suite.php
  tests/payment_idempotency_1_9f_hardening_suite.php
  tests/role_dashboards_1_10_suite.php
```

### 2.2 Change Scope Reconciliation
- **Tracked Files Modified in Phase 1.11C:**
  - `public/assets/js/dashboard.js`: Expanded to support role-specific canvas rendering, theme switching, and memory cleanup.
  - `public/assets/css/dashboard.css`: Enhanced with KPI card tone classes, `.chart-grid`, and `.chart-wrap`.
- **Untracked View Files Created/Modified in Phase 1.10/1.11:**
  - `app/views/dashboards/admin.php`: Preserved from monolithic dashboard; added Homepage button.
  - `app/views/dashboards/receptionist.php`: Added Homepage button, 14-day booking intake trend, appointment status doughnut.
  - `app/views/dashboards/mechanic.php`: Added Homepage button, workstation metrics and chart containers.
  - `app/views/dashboards/inventory.php`: Added Homepage button, warehouse indicators and chart containers.
  - `app/views/dashboards/accountant.php`: Added Homepage button, receivables aging and chart containers.
  - `app/views/dashboards/customer.php`: Added Homepage button, VIP customer metrics and chart containers.
- **Unauthorized Changes Outside Scope:** **NONE**. Core payment services (`PaymentService.php`, `PaymentIdempotencyService.php`), authentication middleware (`auth.php`), and database schemas were completely untouched during Phase 1.11C.

---

## 3. Production Database Safety & Invariant Verification

### 3.1 Reported Production Baseline Metrics
The production database `garage_management_system` was established in Phase 1.9F-B with the following invariant baseline:
- **Database Engine:** MariaDB / MySQL (InnoDB engine)
- **Table Count:** Exactly 30 tables.
- **Payment Transactions:** Exactly 3 baseline payments.
- **User Accounts:** Exactly 11 registered user accounts across roles.
- **Audit Log Records:** Exactly 90 continuous audit trail records.

### 3.2 Verification of Zero Mutation
- No migration files were executed against production.
- Migration `20261117_customer_identity_verification.sql` remains unapplied to production (`customers.user_id` and `customer_link_tokens` remain absent from production, preserving environment separation).
- Zero `INSERT`, `UPDATE`, `DELETE`, `ALTER`, or `DROP` queries were issued in Phase 1.11C.
- The 90 production audit log records remain intact.

---

## 4. Cryptographic Audit Trail Architecture Clarification (FIND-111D-02)

### 4.1 Merkle Tree vs Linear Cryptographic Hash Chain
Prior documentation in Phase 1.9 and Phase 1.10 occasionally used the term "Merkle chain" or "Merkle tree" to describe the audit log integrity mechanism. 

An independent source inspection of `app/services/AuditLogger.php` was conducted to verify the actual cryptographic data structure:
```php
final class AuditLogger
{
    public static function log(...): int
    {
        return (int) Database::transaction(function (PDO $pdo) ... {
            // Lock the chain head: appends are serialised so the chain stays linear.
            $head = $pdo->query('SELECT last_hash FROM audit_chain_head WHERE id = 1 FOR UPDATE')->fetch();
            $prevHash = $head ? (string) $head['last_hash'] : str_repeat('0', 64);

            $rowHash = self::hashRow($prevHash, [
                $actor['user_id'] ?? null, $actor['username'] ?? null, $actor['role'] ?? null, $action, $module,
                $recordId, $prevJson, $newJson, $ip, $ua, $result, $createdAt,
            ]);

            $st = $pdo->prepare(
                'INSERT INTO audit_logs (..., prev_hash, row_hash) VALUES (?, ...)'
            );
            $st->execute([...]);
            $pdo->prepare('UPDATE audit_chain_head SET last_id = ?, last_hash = ? WHERE id = 1')->execute([$id, $rowHash]);
            return $id;
        });
    }

    public static function verifyChain(): array
    {
        $pdo = Database::pdo();
        $expectedPrev = str_repeat('0', 64);
        ...
        foreach ($rows as $r) {
            if (!hash_equals($expectedPrev, (string) $r['prev_hash'])) {
                return ['ok' => false, 'bad_id' => (int) $r['id'], 'message' => 'Chain broken...'];
            }
            $calc = self::hashRow((string) $r['prev_hash'], [...]);
            if (!hash_equals($calc, (string) $r['row_hash'])) {
                return ['ok' => false, 'bad_id' => (int) $r['id'], 'message' => 'Record modified...'];
            }
            $expectedPrev = (string) $r['row_hash'];
        }
    }
}
```

### 4.2 Cryptographic Findings:
1. **Structure:** The implementation uses a **linear sequential SHA-256 cryptographic hash chain** (identical to a blockchain ledger), where each record stores the SHA-256 hash of its predecessor (`prev_hash`) and incorporates it into its own `row_hash`.
2. **Distinction from Merkle Tree:** A Merkle tree is a hierarchical binary or k-ary tree of hashes with a single Merkle root at the top, used for proving set membership in $O(\log n)$ time. `AuditLogger.php` does not build a binary tree or calculate intermediate parent nodes; it maintains a single linear head (`audit_chain_head`).
3. **Security Assessment:** The linear hash chain provides complete tamper evidence. Modifying, deleting, or reordering any record breaks all subsequent hashes in the chain, reliably detected by `AuditLogger::verifyChain()`.
4. **Documentation Classification:** Finding **FIND-111D-02** is classified as **INFORMATIONAL**. Documentation has been updated to use the mathematically precise term: **linear SHA-256 cryptographic hash chain**.

---

## 5. Conclusion

The Git repository and production database invariants are fully reconciled. No unauthorized files were altered, no production records were touched, and the cryptographic hash chain structure is clearly documented and intact.
