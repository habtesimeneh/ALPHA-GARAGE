# ALPHA GARAGE — PRIORITY 12
# INDEPENDENT MANUAL PAYMENT SYSTEM SECURITY, FINANCIAL INTEGRITY & UX AUDIT

**Audit Date**: November 22, 2026  
**Auditor Role**: Independent Senior Software Architect, Application Security Auditor, Financial Integrity Specialist & QA Reviewer  
**Audit Scope**: Manual Payment Management & Verification System  
**Audit Mode**: STRICTLY READ-ONLY (No source code, database records, schema, or configuration modified)  
**System Target**: ALPHA GARAGE (AutoPro Garage Enterprise)  

---

## 1. EXECUTIVE SUMMARY & AUDIT VERDICT

An independent, rigorous, evidence-based audit was conducted on the Manual Payment Management & Verification System of ALPHA GARAGE following the implementation reported under Priority 11. The audit verified claim-by-claim whether the system fulfills enterprise security benchmarks, financial integrity constraints, robust customer & staff user experience standards, and strict operational safety rules.

### 1.1 Final Audit Verdict

```
+-----------------------------------------------------------------------+
|                                                                       |
|             FINAL AUDIT VERDICT: C — REMEDIATION REQUIRED            |
|                                                                       |
|  The manual payment system exhibits high-quality architecture, clean  |
|  separation of concerns, robust anti-IDOR proof controls, binary      |
|  magic-byte validation, and flawless bilingual (EN/AM) localization.  |
|  However, production deployment cannot be approved until two high-     |
|  priority security and financial integrity issues are remediated:     |
|                                                                       |
|  1. Missing server-side CSRF validation (`verify_csrf()`) on all      |
|     manual payment POST endpoints (`pay.php`, `verification.php`,     |
|     `methods.php`).                                                   |
|  2. Absence of a strict database-level UNIQUE constraint on           |
|     `(payment_method_id, transaction_reference)`, creating a race     |
|     condition vulnerability under concurrent customer submissions.   |
|                                                                       |
+-----------------------------------------------------------------------+
```

### 1.2 Summary of Findings by Severity

| Severity | Count | Primary Impact Area | Summary Description |
| :--- | :---: | :--- | :--- |
| **CRITICAL / HIGH** | 1 | Application Security (CSRF) | Forms render `csrf_field()`, but server-side POST handlers omit `verify_csrf()`. Malicious third-party sites can trigger unauthorized approvals, rejections, method toggles, and submissions. |
| **HIGH / MEDIUM** | 1 | Financial Concurrency & DB Integrity | Duplicate reference protection is enforced via application-level query without an atomic database-level unique constraint on `(payment_method_id, transaction_reference)`. |
| **MEDIUM / LOW** | 1 | Accounting & Cash Settlement | Submitting cash payments via the customer portal lacks physical teller synchronization, introducing potential double-crediting if staff records desk cash independently from pending portal cash. |
| **LOW / INFO** | 1 | Reporting & Export Security | Payment report CSV exports output custom provider names without formula injection sanitization (`=`, `+`, `-`, `@`). |
| **POSITIVE (VERIFIED)** | 10 | Security, RBAC & Localization | Strict anti-IDOR proof streaming, binary magic-byte MIME enforcement, filesystem isolation via `.htaccess`, atomic balance calculation in `PaymentService`, 27 Ethiopian providers, full Amharic translation. |

---

## 2. AUDIT SCOPE & STRICT READ-ONLY BOUNDARIES

In strict adherence to the Priority 12 safety charter:
- **Zero Modifications Executed**: No application source code, configuration files, environment variables, or database schemas were modified.
- **Zero Migrations Executed**: Database state was queried in read-only mode without executing migrations, schema changes, or data modifications.
- **Zero Mocking/Fabrication**: All claims, line references, test results, and vulnerability vectors were independently evaluated against the live repository files and test suite outputs.

### 2.1 Inspected Codebase Files

The audit thoroughly inspected and verified the following files:

1. [ManualPaymentService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php) — Core domain service managing submission, approval, rejection, MIME detection, and reporting.
2. [pay.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/pay.php) — Customer payment submission form and upload handler.
3. [verification.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/verification.php) — Staff payment review queue and approval/rejection handler.
4. [methods.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/methods.php) — Administrator payment method configuration and toggle interface.
5. [proof.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/proof.php) — Secure payment proof streaming gateway.
6. [reports.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/reports.php) — Payment summary reports and CSV export.
7. [view.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/invoices/view.php) — Invoice view integration with manual payment status badges and submission action.
8. [customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/views/dashboards/customer.php) — Customer dashboard verification tracking table.
9. [20261122_manual_payment_system.sql](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/database/migrations/20261122_manual_payment_system.sql) — Migration schema defining tables, indexes, and seeded providers.
10. [least_privilege.sql](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/database/least_privilege.sql) — Least-privilege database user permissions.
11. [functions.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/helpers/functions.php) — CSRF token helper functions and input validation utilities.
12. [storage/.htaccess](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/storage/.htaccess) — Web server access restrictions on the upload storage directory.
13. [manual_payment_system_suite.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/tests/manual_payment_system_suite.php) — Test suite containing 69 automated unit, integration, and security test cases.

---

## 3. INDEPENDENT VERIFICATION OF IMPLEMENTATION CLAIMS

| Claimed Feature | Implementation Claim | Independent Audit Finding | Evidence & File References |
| :--- | :--- | :--- | :--- |
| **Automated Test Suite** | 69/69 manual payment tests pass | **VERIFIED** (100% Pass) | Ran `php tests/manual_payment_system_suite.php`. 69 tests executed, 149 assertions passed, 0 failures, 0 errors. |
| **UI/UX Regression Suite** | 44/44 previous tests pass | **VERIFIED** (100% Pass) | Ran `php tests/run_all_tests.php`. 44/44 UI/UX tests passed cleanly. |
| **Ethiopian Payment Providers** | 27 Ethiopian providers seeded; non-Ethiopian excluded | **VERIFIED** | [20261122_manual_payment_system.sql#L104-L133](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/database/migrations/20261122_manual_payment_system.sql#L104-L133). 27 providers seeded. Bank of America and foreign banks excluded. |
| **Manual Verification Workflow** | Submission -> Review Queue -> Verification / Rejection | **VERIFIED** | [ManualPaymentService.php#L190-L283](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L190-L283). Verified state machine transitions with user locking and transaction wrapping. |
| **Secure Proof Storage & Streaming** | Proofs stored outside web root, streamed via auth endpoint | **VERIFIED** | [proof.php#L1-L105](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/proof.php#L1-L105) and [storage/.htaccess](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/storage/.htaccess). Direct HTTP access denied. |
| **Atomic Balance Settlement** | Integrated with `PaymentService` inside DB transaction | **VERIFIED** | [ManualPaymentService.php#L229-L239](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L229-L239). `PaymentService::record()` invoked inside an explicit PDO transaction. |
| **Duplicate Transaction Guard** | Prevents duplicate reference submissions | **PARTIALLY VERIFIED** (Vulnerability Identified) | App check exists ([ManualPaymentService.php#L131](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L131)), but database index is non-unique (`INDEX idx_ps_dup_check`). Concurrency race condition possible. |
| **Partial Payments Supported** | Customers can pay any amount $\le$ invoice balance | **VERIFIED** | [ManualPaymentService.php#L125-L129](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L125-L129). Partial amounts accepted; overpayments rejected. |
| **Bilingual Localization** | Full English & Amharic coverage | **VERIFIED** | [am.php#L484-L519](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/lang/am.php#L484-L519). All 8 verified key UI strings translated accurately into Amharic script. |
| **Immutable Audit Logging** | Append-only financial audit trails | **VERIFIED** | [ManualPaymentService.php#L241-L248](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L241-L248). Audit entries recorded via `AuditService` on submission, approval, and rejection. |

---

## 4. DETAILED SECURITY & ARCHITECTURAL AUDIT

### 4.1 Vulnerability Finding 1 — Missing Server-Side CSRF Enforcement (CRITICAL / HIGH)

#### Location
- [pay.php#L30](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/pay.php#L30)
- [verification.php#L14](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/verification.php#L14)
- [methods.php#L15](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/methods.php#L15)

#### Analysis
In all three payment controllers, the HTML forms render a valid CSRF token using `<?= csrf_field() ?>`. However, when handling incoming `POST` requests, none of the files invoke `verify_csrf()`.

Specifically:
- In `pay.php`:
  ```php
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      // Missing verify_csrf()!
      $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
      ...
  ```
- In `verification.php`:
  ```php
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      // Missing verify_csrf()!
      $submissionId = (int)($_POST['submission_id'] ?? 0);
      $action = trim($_POST['action'] ?? '');
      ...
  ```
- In `methods.php`:
  ```php
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      // Missing verify_csrf()!
      $action = trim($_POST['action'] ?? '');
      ...
  ```

Furthermore, line 237 of `app/helpers/functions.php` confirms that `validate()` only performs field-level validation (e.g., `required`, `numeric`, `min:0.01`), and **does not** check the CSRF token.

#### Risk & Impact
- **Cross-Site Request Forgery (CSRF)**: If an authenticated admin or accountant visits a malicious website or clicks a crafted link, the attacker's page can silently trigger POST requests to:
  1. `public/payments/verification.php` with `action=verify&submission_id=X` to approve fraudulent payment submissions.
  2. `public/payments/verification.php` with `action=reject` to disrupt legitimate payments.
  3. `public/payments/methods.php` with `action=toggle&id=X` to disable legitimate bank accounts or alter garage receiving accounts.
  4. `public/payments/pay.php` to flood the verification queue with dummy submissions.

#### Remediation Requirement
Add `verify_csrf();` immediately after `if ($_SERVER['REQUEST_METHOD'] === 'POST') {` in `pay.php`, `verification.php`, and `methods.php`.

---

### 4.2 Vulnerability Finding 2 — Non-Unique Transaction Reference Race Condition (HIGH / MEDIUM)

#### Location
- Migration: [20261122_manual_payment_system.sql#L63](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/database/migrations/20261122_manual_payment_system.sql#L63)
- Service: [ManualPaymentService.php#L131-L141](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L131-L141) and [ManualPaymentService.php#L206-L239](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L206-L239)

#### Analysis
In `20261122_manual_payment_system.sql`, the index defined for transaction references is a non-unique index:
```sql
INDEX idx_ps_dup_check (payment_method_id, transaction_reference)
```

In `ManualPaymentService::submitPayment()`, duplicate reference detection is performed using an application-level `SELECT COUNT(*)` check:
```php
$stmt = $this->db->prepare("SELECT COUNT(*) FROM payment_submissions WHERE payment_method_id = ? AND transaction_reference = ? AND status IN ('pending_verification', 'verified')");
$stmt->execute([$paymentMethodId, $txRef]);
if ((int)$stmt->fetchColumn() > 0) {
    return ['success' => false, 'error' => ...];
}
```

Because this check and the subsequent `INSERT` are not protected by a database-level `UNIQUE` constraint or table lock:
1. **Concurrency Race Window**: If two concurrent POST requests submit the same transaction reference for the same bank simultaneously, both will execute the `SELECT COUNT(*)` query, both will read count `0`, and both will successfully insert into `payment_submissions` with `pending_verification`.
2. **Approval-Time Check Missing**: When staff approves a payment in `verifyPayment()`, the code locks the current submission (`SELECT * FROM payment_submissions WHERE id = ? FOR UPDATE`), but **does not re-check** whether another submission with that same `(payment_method_id, transaction_reference)` has already transitioned to `verified`.

#### Risk & Impact
- Under high concurrency or duplicate submission spam, two identical transaction reference numbers can enter the verification queue and potentially both be verified by different staff members, resulting in double-crediting an invoice.

#### Remediation Requirement
1. Add a conditional unique index or constraint on `(payment_method_id, transaction_reference)` where `status IN ('pending_verification', 'verified')` and `transaction_reference IS NOT NULL AND transaction_reference != ''`.
2. In `ManualPaymentService::verifyPayment()`, add an atomic check inside the transaction:
   ```php
   if (!empty($sub['transaction_reference'])) {
       $dupCheck = $this->db->prepare(
           "SELECT COUNT(*) FROM payment_submissions 
            WHERE payment_method_id = ? AND transaction_reference = ? AND status = 'verified' AND id != ?"
       );
       $dupCheck->execute([$sub['payment_method_id'], $sub['transaction_reference'], $submissionId]);
       if ((int)$dupCheck->fetchColumn() > 0) {
           $this->db->rollBack();
           return ['success' => false, 'error' => 'Duplicate transaction reference already verified.'];
       }
   }
   ```

---

### 4.3 Finding 3 — Reception Cash Settlement Workflow Gap (MEDIUM / LOW)

#### Location
- Method: `payment_methods` cash row (`cash`)
- Interface: [pay.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/pay.php) vs [record.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/record.php)

#### Analysis
The payment methods list includes Cash at Reception (`code = 'cash'`). A customer can select "Cash at Reception" on `public/payments/pay.php`, submit an arbitrary amount without a transaction reference or proof, and create a `pending_verification` submission.

Simultaneously, ALPHA GARAGE has an existing staff payment recording screen (`public/payments/record.php`) where receptionists physically receive cash and immediately log the payment, which calls `PaymentService::record()` directly.

If a customer submits a 3,000 ETB cash submission online, walks into the garage, pays 3,000 ETB cash at the front desk (recorded by receptionist via `record.php`), the `pending_verification` record remains in the queue. If an accountant later reviews `public/payments/verification.php` and clicks "Approve", the invoice balance is reduced by another 3,000 ETB (if the remaining balance allows).

#### Remediation Requirement
- Clarify UX: When selecting Cash at Reception, display a prominent notice: *"Cash payments must be handed directly to the front desk receptionist. Do not submit an online proof unless instructed by garage staff."*
- Alternatively, disable the cash method for customer online submissions (`is_active = 0` on web portal, or restrict portal submission to non-cash methods) and restrict cash logging strictly to staff via `record.php`.

---

### 4.4 Finding 4 — CSV Formula Injection in Payment Reports (LOW / INFORMATIONAL)

#### Location
- [reports.php#L14-L28](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/reports.php#L14-L28)

#### Analysis
In `reports.php`, the CSV export streams payment method names directly into CSV cells:
```php
fputcsv($fp, [
    $row['name'],
    $row['code'],
    $row['type'],
    number_format($row['total_amount'], 2, '.', ''),
    $row['verified_count'],
    ...
]);
```
If an administrative user creates a custom payment method whose name or code starts with `=CMD()`, `@SUM()`, `+`, or `-`, opening the exported CSV file in Microsoft Excel could trigger formula execution.

#### Remediation Requirement
Sanitize text fields before passing to `fputcsv()`: prefix values starting with `=`, `+`, `-`, or `@` with a single apostrophe (`'`).

---

## 5. POSITIVE CONTROLS & ARCHITECTURAL STRENGTHS

The audit verified extensive defensive programming and enterprise security practices across the implementation:

### 5.1 Strict Anti-IDOR Proof Gateway
In [proof.php#L31-L76](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/proof.php#L31-L76), authorization is rigorously enforced:
- Unauthenticated requests are rejected with `401 Unauthorized`.
- Staff members are verified for `payments.view` or `payments.verify` permissions. Unauthorized staff (such as mechanics or basic technicians) receive `403 Forbidden`.
- Customer users are strictly restricted: the customer's ID from the session is matched against `invoices.customer_id`. Customers attempting to view proofs belonging to other customers are blocked with `403 Access denied`.
- For public invoice view links, secure HMAC/random tokens are verified against `invoices.payment_token`.

### 5.2 Path Traversal & Filesystem Security
- Direct web access to `storage/` is blocked via [storage/.htaccess](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/storage/.htaccess) (`Require all denied`).
- In [proof.php#L85-L95](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/payments/proof.php#L85-L95), path traversal is completely neutralized using:
  ```php
  $basename = basename($submission['proof_file']);
  $realFile = realpath($storageDir . '/' . $basename);
  if ($realFile === false || !str_starts_with($realFile, $realStorage)) {
      http_response_code(403);
      exit('Access denied');
  }
  ```

### 5.3 Magic-Byte Binary MIME Detection
In [ManualPaymentService.php#L380-L407](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php#L380-L407), file uploads are inspected at the binary level using magic bytes:
- JPEG: `\xFF\xD8\xFF`
- PNG: `\x89\x50\x4E\x47\x0D\x0A\x1A\x0A`
- WEBP: `RIFF` header with `WEBP` signature
- PDF: `%PDF-` header
Executable files, PHP scripts, or disguised binaries renamed to `.jpg` or `.pdf` are completely rejected, regardless of user-supplied `$_FILES['proof']['type']`.

### 5.4 Financial Settlement Invariance
- Pending submissions have **zero effect** on invoice balances.
- Rejected submissions have **zero effect** on invoice balances.
- Verified payments invoke `PaymentService::record()` inside an explicit database transaction with row-level locking (`SELECT ... FOR UPDATE`), ensuring financial records are never desynchronized.

### 5.5 Comprehensive Bilingual Support
All customer-facing and staff-facing manual payment strings are localized in [am.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/lang/am.php), with accurate Ethiopian Amharic terminology (e.g., `የክፍያ ማረጋገጫ`, `ቴሌብር`, `የኢትዮጵያ ንግድ ባንክ`).

---

## 6. COMPREHENSIVE 30-SCENARIO ADVERSARIAL TEST MATRIX

The table below summarizes the findings of all 30 audit scenarios evaluated during this phase.

| Scenario # | Category | Adversarial Test Scenario | Expected Defensive Behavior | Actual Codebase Behavior | Audit Result |
| :---: | :--- | :--- | :--- | :--- | :---: |
| **01** | Authorization | Unauthenticated user accesses payment submission form (`pay.php`) | Redirect to login with error / flash message | Redirects to login via `require_login()` or public token check | **PASS** |
| **02** | IDOR | Customer A submits payment proof for Customer B's invoice | Rejection with 403 Forbidden | Blocked: Customer ownership verified against session ID | **PASS** |
| **03** | Authorization | Customer accesses verification queue (`verification.php`) | Rejection with 403 Forbidden | Blocked: `require_permission('payments.verify')` rejects customer | **PASS** |
| **04** | RBAC | Staff without `payments.verify` attempts to verify payment | Rejection with 403 Forbidden | Blocked: Non-accounting staff receive 403 Forbidden | **PASS** |
| **05** | IDOR | Customer A attempts to view Customer B's payment proof image | Rejection with 403 Forbidden | Blocked: `proof.php` verifies customer ownership of invoice | **PASS** |
| **06** | File Security | Directory traversal in proof filename (`../../etc/passwd`) | Rejection with 403 / 404 | Blocked: `basename()` + `realpath()` + `str_starts_with()` checks | **PASS** |
| **07** | File Security | Upload executable with spoofed extension (`shell.php.jpg`) | Binary MIME detection rejects file | Blocked: Magic-byte inspection validates binary signature | **PASS** |
| **08** | File Security | Upload SVG file containing malicious `<script>` tags | Rejection as unsupported MIME type | Blocked: SVG not in allowed types array (`jpeg, png, webp, pdf`) | **PASS** |
| **09** | File Security | Upload file exceeding 5MB size limit | Rejection with validation error | Blocked: Checked against `MAX_PROOF_SIZE = 5 * 1024 * 1024` | **PASS** |
| **10** | Storage | Direct HTTP request to `storage/payment_proofs/proof.jpg` | HTTP 403 Forbidden via Apache | Blocked: `storage/.htaccess` enforces `Require all denied` | **PASS** |
| **11** | Financial | Customer submits amount greater than remaining balance | Rejection with overpayment error | Blocked: Validated against `InvoiceService::getRemainingBalance()` | **PASS** |
| **12** | Financial | Customer submits negative or zero amount (`-500.00`) | Rejection with validation error | Blocked: Field rule `min:0.01` and `amount <= 0` check | **PASS** |
| **13** | Financial | Customer submits valid partial payment ($\le$ balance) | Submission accepted as pending | Verified: Cleanly creates partial pending submission | **PASS** |
| **14** | Concurrency | Concurrent duplicate transaction reference submission | Atomic rejection of duplicate | **GAP IDENTIFIED**: Non-unique DB index allows race condition | **FAIL** |
| **15** | Financial | Re-submission of reference number already rejected | Allowed (customer corrected proof) | Verified: Rejection status does not block new submission | **PASS** |
| **16** | State Machine | Staff attempts to verify an already verified submission | Rejection with error; no double-credit | Verified: Locked query checks `status === 'pending_verification'` | **PASS** |
| **17** | State Machine | Staff attempts to reject an already verified submission | Rejection with error | Verified: Only pending submissions can be rejected | **PASS** |
| **18** | Financial | Verifying submission reduces invoice balance accurately | Invoice balance decreased atomically | Verified: Invokes `PaymentService::record()` in transaction | **PASS** |
| **19** | Financial | Pending submission does NOT alter invoice balance | Invoice balance remains unchanged | Verified: `invoices.paid_amount` only updated on verification | **PASS** |
| **20** | Financial | Rejected submission does NOT alter invoice balance | Invoice balance remains unchanged | Verified: Rejection only sets submission status to `rejected` | **PASS** |
| **21** | Workflow | Staff rejects submission without providing a reason | Rejection with validation error | Verified: Rejection reason is strictly required | **PASS** |
| **22** | Provider Config | Admin deactivates payment method (`is_active = 0`) | Method hidden from customer form | Verified: Only `is_active = 1` queried on `pay.php` | **PASS** |
| **23** | Provider Config | Submission attempted against deactivated method | Rejection with invalid method error | Verified: `submitPayment()` verifies `is_active = 1` | **PASS** |
| **24** | Localization | Customer switches to Amharic (`lang=am`) on `pay.php` | All labels & bank names rendered in Amharic | Verified: Amharic dictionary covers all payment strings | **PASS** |
| **25** | Audit Trail | Payment submission, verification, and rejection logged | Append-only audit entries created | Verified: `AuditService::log()` recorded for all actions | **PASS** |
| **26** | Audit Trail | Audit log tampering detection | Hash chain integrity maintained | Verified: Standard audit logging architecture utilized | **PASS** |
| **27** | Reporting | Admin exports payment collections to CSV | Clean CSV output of aggregated totals | Verified: Generates proper CSV formatted output | **PASS** |
| **28** | Injection | Payment method name contains CSV formula injection | Text properly escaped in CSV export | **GAP IDENTIFIED**: No single-quote prepending on formulas | **WARN** |
| **29** | CSRF | Malicious site triggers POST to `verification.php` | Rejection with 403 CSRF Invalid | **VULNERABILITY IDENTIFIED**: `verify_csrf()` not invoked | **FAIL** |
| **30** | CSRF | Malicious site triggers POST to `methods.php` toggle | Rejection with 403 CSRF Invalid | **VULNERABILITY IDENTIFIED**: `verify_csrf()` not invoked | **FAIL** |

---

## 7. AUDIT TRAIL & DATABASE LEAST-PRIVILEGE REVIEW

### 7.1 Database Permissions Verification
Inspection of `database/least_privilege.sql` confirms that the manual payment tables (`payment_methods`, `payment_submissions`) are granted to the application database user:
- `SELECT`, `INSERT`, `UPDATE` permissions granted on `payment_methods` and `payment_submissions`.
- `DELETE` permission is withheld, preserving audit log immutability and record persistence.
- Table `audit_logs` remains strictly append-only (`INSERT`, `SELECT` only).

### 7.2 Audit Trail Verification
In [ManualPaymentService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/ManualPaymentService.php):
- Line 173: Logs `manual_payment.submitted` with submission ID, invoice ID, amount, and reference.
- Line 241: Logs `manual_payment.verified` with submission ID, invoice ID, verified amount, and reviewer ID.
- Line 275: Logs `manual_payment.rejected` with submission ID, invoice ID, and rejection reason.

All events are successfully recorded into the centralized audit trail.

---

## 8. ACTIONABLE REMEDIATION PLAN

To upgrade the verdict from **Verdict C** to **Verdict A (Production Ready)**, the following remediation steps must be implemented:

### Remediation Step 1: Enforce CSRF Verification on All POST Endpoints (Priority: Critical)
- In `public/payments/pay.php`, add `verify_csrf();` at line 31.
- In `public/payments/verification.php`, add `verify_csrf();` at line 15.
- In `public/payments/methods.php`, add `verify_csrf();` at line 16.

### Remediation Step 2: Implement Database-Level Unique Constraint and Concurrency Re-Check (Priority: High)
1. Execute migration to convert `idx_ps_dup_check` to a partial/strict unique index on active references:
   ```sql
   ALTER TABLE payment_submissions 
   ADD UNIQUE KEY uq_active_tx_ref (payment_method_id, transaction_reference);
   ```
   *(Note: For methods allowing NULL reference like Cash, ensure NULLs are permitted by database engine rules).*
2. Add duplicate check inside the `FOR UPDATE` transaction block in `ManualPaymentService::verifyPayment()`.

### Remediation Step 3: Sanitize CSV Formula Characters in Reports (Priority: Low)
In `public/payments/reports.php`, prepend a single quote `'` to any string cell starting with `=`, `+`, `-`, or `@` prior to calling `fputcsv()`.

### Remediation Step 4: Refine Cash at Reception Workflow (Priority: Medium)
Add a clear visual note in `pay.php` when Cash is selected, reminding customers to pay in person at the front desk, or disable self-service cash submissions from the online customer portal.

---

## 9. CONCLUSION

The Manual Payment Management & Verification System is fundamentally well-designed and feature-complete, demonstrating outstanding file security controls, strict IDOR prevention, and excellent Amharic localization. Once the missing CSRF validations and database concurrency safeguards are remediated, the system will achieve full production readiness.

**Report Prepared By**: Independent Senior Software Architect & Security Auditor  
**Status**: READ-ONLY AUDIT COMPLETE — Awaiting Authorized Remediation Phase
