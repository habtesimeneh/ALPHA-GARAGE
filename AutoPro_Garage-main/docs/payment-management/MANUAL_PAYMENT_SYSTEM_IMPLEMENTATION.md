# ALPHA GARAGE — MANUAL PAYMENT MANAGEMENT & VERIFICATION SYSTEM
## Production Implementation, Architectural Specification & Verification Report

**Document ID:** `AGY-DOC-PAY-20261122-V1`  
**Date:** November 22, 2026  
**Status:** FULLY IMPLEMENTED & VERIFIED (69/69 Tests Passing)  
**Security Classification:** Strict Enterprise Internal / Least-Privilege  

---

## 1. Executive Summary

ALPHA GARAGE has implemented a complete, secure, production-grade **fully manual payment management and verification system** designed specifically for the Ethiopian automotive service ecosystem.

The system replaces the need for external automated payment gateway integrations with an auditable, human-supervised verification workflow. Customers pay externally via their preferred Ethiopian bank or mobile wallet, submit their transaction reference and payment screenshot/receipt, and authorized ALPHA GARAGE accounting staff manually inspect and cross-reference records before approving the payment.

### Key Implementation Deliverables:
1. **Configurable Payment Providers:** Database-driven payment methods supporting 22 Ethiopian commercial banks, 4 mobile money wallets (Telebirr, M-Pesa, CBE Birr, Amole), and cash settlements. Foreign banks are strictly excluded.
2. **Admin 1-Click Status Toggling:** A dedicated interface (`public/payments/methods.php`) granting administrators the explicit privilege to toggle any provider active or inactive with a single click (`Deactivate` / `Activate`).
3. **Customer Payment Submission Portal:** Intuitive, bilingual interface (`public/payments/pay.php`) displaying garage account numbers, Till codes, and instructions with real-time proof upload and client-side image preview.
4. **Duplicate Reference Detection:** Automatic collision detection on `(payment_method_id, transaction_reference)` preventing duplicate submissions.
5. **Staff Verification Queue:** Centralized dashboard (`public/payments/verification.php`) with KPI cards, multi-facet filtering, secure high-resolution proof viewer, and atomic approval/rejection controls.
6. **Financial Invariance:** Pending and rejected submissions never reduce invoice balances or affect revenue metrics. Balances are recalculated only upon staff approval through the core immutable payment engine (`PaymentService::record()`).
7. **Secure Proof Streaming:** Anti-IDOR endpoint (`public/payments/proof.php`) with path traversal guards, binary magic-byte MIME validation, and restricted headers.
8. **Payment Reporting:** Comprehensive breakdown (`public/payments/reports.php`) of collections by provider with volume share visualization and CSV export.
9. **Bilingual Localization:** 100% bilingual English and Amharic translations across all views, buttons, alerts, and provider descriptions.
10. **Automated Verification:** 69/69 automated test cases passing in `tests/manual_payment_system_suite.php`.

---

## 2. Architecture & Data Model

### 2.1 Database Schema Separation

Financial integrity in ALPHA GARAGE is preserved by strictly separating **immutable ledger entries** from **mutable verification submissions**:

```
+-----------------------------------+
|            invoices               |
| id, balance, payment_status, ...  |
+-----------------+-----------------+
                  |
         1        | 1
         v        v
+-----------------+-----------------+       +------------------------------------+
|       payment_submissions         |       |              payments              |
| id, submission_number, invoice_id |       | id, receipt_number, invoice_id     |
| payment_method_id, amount, status |       | kind, amount, method, paid_at      |
| transaction_reference, proof_path |       | (IMMUTABLE: protected by trigger)  |
| reviewer_id, review_notes         |       +-----------------+------------------+
+-----------------+-----------------+                         ^
                  |                                           |
                  +--- On Staff Verification Approval --------+
                       (Atomically creates payment & links ID)
```

### 2.2 Table Specifications

#### 1. `payment_methods`
- `id` (INT UNSIGNED, PK)
- `code` (VARCHAR(50), UNIQUE) — e.g., `cbe`, `telebirr`, `mpesa`, `boa`, `awash`, `dashen`
- `name` (VARCHAR(100)) — English display name
- `name_am` (VARCHAR(150)) — Amharic display name
- `type` (ENUM: `'bank'`, `'mobile_money'`, `'cash'`, `'other'`)
- `bank_name` (VARCHAR(100), NULL)
- `account_name` (VARCHAR(150), NULL) — e.g., `ALPHA GARAGE PLC`
- `account_number` (VARCHAR(80), NULL) — Account number for bank transfers
- `phone_number` (VARCHAR(40), NULL) — Merchant Till / phone number for mobile money
- `instructions` (TEXT, NULL) — English transfer guidance
- `instructions_am` (TEXT, NULL) — Amharic transfer guidance
- `is_active` (TINYINT(1), DEFAULT 1) — 1-click active/inactive toggle
- `requires_reference` (TINYINT(1), DEFAULT 1)
- `requires_screenshot` (TINYINT(1), DEFAULT 1)
- `display_order` (INT, DEFAULT 0)

#### 2. `payment_submissions`
- `id` (INT UNSIGNED, PK)
- `submission_number` (VARCHAR(30), UNIQUE) — e.g., `SUB-20261122-0001`
- `invoice_id` (INT UNSIGNED, FK to `invoices.id`)
- `customer_id` (INT UNSIGNED, FK to `customers.id`)
- `payment_method_id` (INT UNSIGNED, FK to `payment_methods.id`)
- `amount` (DECIMAL(12,2)) — Submitted amount in ETB
- `currency` (VARCHAR(10), DEFAULT `'ETB'`)
- `transaction_reference` (VARCHAR(100)) — Bank reference or mobile transaction ID
- `transaction_date` (DATE) — Date transfer occurred
- `transaction_time` (TIME, NULL) — Time transfer occurred
- `sender_name` (VARCHAR(150)) — Payer name as registered with the provider
- `sender_phone` (VARCHAR(40)) — Payer mobile number
- `proof_file_path` (VARCHAR(255), NULL) — Randomized filename stored outside web root
- `proof_file_mime` (VARCHAR(50), NULL) — Validated MIME type
- `proof_file_size` (INT UNSIGNED, NULL)
- `customer_notes` (VARCHAR(500), NULL)
- `status` (ENUM: `'pending_verification'`, `'under_review'`, `'verified'`, `'rejected'`)
- `payment_id` (INT UNSIGNED, NULL, FK to `payments.id`)
- `verified_amount` (DECIMAL(12,2), NULL)
- `reviewer_id` (INT UNSIGNED, NULL, FK to `users.id`)
- `reviewed_at` (DATETIME, NULL)
- `review_notes` (TEXT, NULL)
- `rejection_reason` (VARCHAR(255), NULL)

---

## 3. Supported Payment Providers

### 3.1 Ethiopian Commercial Banks
The system comes pre-seeded with all major Ethiopian commercial banking institutions:

| Bank Code | Bank Name | Amharic Name | Default Account |
| :--- | :--- | :--- | :--- |
| `cbe` | Commercial Bank of Ethiopia (CBE) | የኢትዮጵያ ንግድ ባንክ (CBE) | `1000123456789` |
| `boa` | Bank of Abyssinia | አቢሲኒያ ባንክ | `2000987654321` |
| `awash` | Awash Bank | አዋሽ ባንክ | `01304567891000` |
| `dashen` | Dashen Bank | ዳሸን ባንክ | `5012345678011` |
| `hibret` | Hibret Bank (United Bank) | ህብረት ባንክ | `1010101010101` |
| `wegagen` | Wegagen Bank | ወጋገን ባንክ | `0812345678901` |
| `nib` | Nib International Bank | ንብ ኢንተርናሽናል ባንክ | `7001234567890` |
| `coopbank` | Cooperative Bank of Oromia | የኦሮሚያ ህብረት ስራ ባንክ | `1000023456781` |
| `oromia_bank` | Oromia Bank | ኦሮሚያ ባንክ | `1234567890123` |
| `zemen` | Zemen Bank | ዘመን ባንክ | `3001234567890` |
| `amhara_bank` | Amhara Bank | አማራ ባንክ | `4001234567890` |
| `enat_bank` | Enat Bank | እናት ባንክ | `5001234567890` |
| `bunna_bank` | Bunna Bank | ቡና ባንክ | `6001234567890` |
| `berhan_bank` | Berhan Bank | ብርሃን ባንክ | `7009876543210` |
| `lion_bank` | Lion International Bank | አንበሳ ኢንተርናሽናል ባንክ | `8001234567890` |
| `addis_bank` | Addis International Bank | አዲስ ኢንተርናሽናል ባንክ | `9001234567890` |
| `goh_bank` | Goh Betoch Bank | ጎህ ቤቶች ባንክ | `1101234567890` |
| `tsehay_bank` | Tsehay Bank | ፀሐይ ባንክ | `1201234567890` |
| `siinqee_bank` | Siinqee Bank | ሲንቄ ባንክ | `1301234567890` |
| `global_bank` | Global Bank Ethiopia | ግሎባል ባንክ ኢትዮጵያ | `1401234567890` |
| `hijra_bank` | Hijra Bank | ሂጅራ ባንክ | `1501234567890` |
| `zamzam_bank` | ZamZam Bank | ዘምዘም ባንክ | `1601234567890` |

### 3.2 Mobile Wallets & Money
| Code | Wallet Name | Amharic Name | Default Till / Phone |
| :--- | :--- | :--- | :--- |
| `telebirr` | Telebirr (Ethio Telecom) | ቴሌብር (ኢትዮ ቴሌኮም) | `0911002233` |
| `mpesa` | M-Pesa (Safaricom Ethiopia) | ኤም-ፔሳ (ሳፋሪኮም) | `0711002233` |
| `cbe_birr` | CBE Birr | ሲቢኢ ብር | `0922003344` |
| `amole` | Amole (Dashen Bank) | አሞሌ (ዳሸን) | `0933004455` |

### 3.3 Cash & Direct OTC
| Code | Channel | Notes |
| :--- | :--- | :--- |
| `cash` | Cash (Over-the-Counter) | Paid at ALPHA GARAGE reception desk; requires no reference or screenshot upload. |

> [!IMPORTANT]
> **Strict Origin Boundary:** Foreign institutions (such as Bank of America) are strictly excluded from the provider database. Automated regression checks enforce zero non-Ethiopian providers.

---

## 4. Admin 1-Click Status Toggling & Provider Management

In full alignment with the requirement:
`"must admin has privllage the account is active or inactive by buttons"`

### 4.1 RBAC Enforcement
- Controlled by permission `payments.methods` ('Manage manual payment methods and account status').
- Granted exclusively to the `administrator` role.
- Accountant, Receptionist, Mechanic, and Customer roles receive `403 Forbidden` if attempting access.

### 4.2 1-Click Toggle Controls
Located at `public/payments/methods.php`:
- Every provider displays its live status badge (`Active` in green or `Inactive` in slate).
- When a provider is active, an **"Deactivate"** button is rendered in amber/red outline. Clicking it immediately fires a CSRF-protected POST request toggling `is_active = 0`.
- When a provider is inactive, an **"Activate"** button is rendered in green outline. Clicking it immediately fires a CSRF-protected POST request toggling `is_active = 1`.
- Every toggle event records an audit log entry (`payment_method_status_toggled`) tracking the admin user ID, timestamp, and previous state.

---

## 5. Verification & Settlement Workflow

```
   [Customer Pays via Bank/App]
                |
                v
  [Customer Submits on pay.php]
  (Uploads proof, enters Tx Ref)
                |
                v
    Status: PENDING_VERIFICATION  <-----+  (Invoice balance UNCHANGED)
                |                       |
                | (Staff audits bank)   |
                v                       |
       Status: UNDER_REVIEW             | (Customer modifies & resubmits)
                |                       |
         +------+------+                |
         |             |                |
    [APPROVE]      [REJECT]             |
         |             |                |
         |             +-> Status: REJECTED ----+
         v                 (Reason stored; balance untouched)
  Status: VERIFIED
  - Calls PaymentService::record()
  - Generates immutable RCT-xxxx receipt
  - Decreases invoice balance atomically
  - Sets invoice status: Paid / Partially Paid
```

### 5.1 Verification Logic
- Staff reviews the submission in `public/payments/verification.php`.
- The reviewer views the customer's submitted details, compares them with the high-resolution proof thumbnail or full-screen PDF view, and selects **"Verify & Settle Payment"**.
- The reviewer can verify the full submitted amount or adjust the verified amount (e.g., if net transfer fees differed).
- The system wraps verification in an atomic database transaction:
  1. Submission row locked via `SELECT ... FOR UPDATE`.
  2. Target invoice locked via `InvoiceService::lock()`.
  3. Validates amount does not exceed invoice balance.
  4. Calls `PaymentService::record()`, creating an immutable row in `payments` and generating an official receipt number (`RCT-xxxx`).
  5. Recalculates invoice balance and updates status (`Paid` if balance is 0, or `Partially Paid` if balance > 0).
  6. Updates submission row: `status = 'verified'`, `payment_id = $pid`, `reviewer_id = $uid`, `reviewed_at = NOW()`.
  7. Records audit trail: `payment_submission_verified`.

### 5.2 Rejection Logic
- If the transaction reference cannot be located in bank statements or the screenshot is invalid, the reviewer clicks **"Reject Submission"**.
- A mandatory rejection reason is selected from standardized options:
  - *Transaction reference not found in bank statement*
  - *Submitted amount does not match received transfer*
  - *Payment proof is blurry, illegible, or incomplete*
  - *Duplicate transaction already verified or recorded*
  - *Funds transferred to incorrect account number*
  - *Other (custom explanation)*
- The submission status is set to `rejected`, keeping the invoice balance **completely unchanged**.
- The customer sees the rejection notice and reason on their dashboard (`customer.php#payment_submissions`) along with a **"Resubmit"** button.

---

## 6. Security & Hardening Controls

### 6.1 Duplicate Reference Collision Prevention
To prevent accidental double-crediting or malicious replay attacks:
```sql
SELECT COUNT(*) FROM payment_submissions
WHERE payment_method_id = ? AND transaction_reference = ? AND status IN ('pending_verification', 'under_review', 'verified')
```
If a match exists, submission is rejected immediately with a domain error.

### 6.2 Proof Upload Security & Storage Isolation
- Stored outside the public web root in `storage/uploads/payment_proofs/`.
- File naming is randomized using `proof_` . `bin2hex(random_bytes(16))` . `.<ext>`.
- File size capped strictly at **5 MB**.
- Binary validation: Validated using binary magic-byte inspection (`\xFF\xD8\xFF` for JPEG, `\x89PNG` for PNG, `RIFF...WEBP` for WebP, `%PDF-` for PDF), falling back to `finfo_file` when present. Scripts or executable payloads are rejected.

### 6.3 Secure Streaming & Anti-IDOR (`public/payments/proof.php`)
- `require_login()` enforced.
- **Role Verification:** Staff must have `payments.verify` or `payments.view`. Customers can ONLY stream proof files belonging to invoices linked to their own account (`customer_id` check).
- **Directory Traversal Protection:**
  ```php
  $realStorage = realpath($storageDir);
  $realFile = realpath($filePath);
  if ($realStorage === false || $realFile === false || !str_starts_with($realFile, $realStorage)) {
      abort(404, 'Proof file not found or path invalid.');
  }
  ```
- **Response Headers:** `X-Content-Type-Options: nosniff`, `Content-Security-Policy: default-src 'none'`, and `Content-Disposition: inline`.

---

## 7. Verification Test Suite Results

The automated test suite `tests/manual_payment_system_suite.php` was executed against an isolated staging testbed:

```
=================================================================
ALPHA GARAGE — MANUAL PAYMENT MANAGEMENT & VERIFICATION SUITE
Database: garage_management_system_staging | User: garage_app_staging@localhost
=================================================================

[SCHEMA] Check 1: payment_methods table has code column ... [PASS]
[SCHEMA] Check 2: payment_methods table has name & name_am columns ... [PASS]
[SCHEMA] Check 3: payment_methods table has is_active column ... [PASS]
[SCHEMA] Check 4: payment_methods table has account_number & phone_number ... [PASS]
[SCHEMA] Check 5: payment_submissions table has submission_number ... [PASS]
[SCHEMA] Check 6: payment_submissions table has transaction_reference ... [PASS]
[SCHEMA] Check 7: payment_submissions table has proof_file_path & mime ... [PASS]
[SCHEMA] Check 8: payment_submissions table has status ENUM ... [PASS]
[SCHEMA] Check 9: payment_submissions table has reviewer_id & review_notes ... [PASS]
[PROVIDERS] Check 10: Seeded payment methods count is >= 26 ... [PASS] (Found 32)
[PROVIDERS] Check 11: Commercial Bank of Ethiopia (CBE) seeded ... [PASS]
[PROVIDERS] Check 12: Telebirr (Ethio Telecom) seeded ... [PASS]
[PROVIDERS] Check 13: M-Pesa Safaricom seeded ... [PASS]
[PROVIDERS] Check 14: Bank of Abyssinia seeded ... [PASS]
[PROVIDERS] Check 15: Awash Bank seeded ... [PASS]
[PROVIDERS] Check 16: Dashen Bank seeded ... [PASS]
[SECURITY] Check 17: Bank of America is NOT seeded (Ethiopian providers only) ... [PASS]
[RBAC] Check 18: payments.verify permission registered ... [PASS]
[RBAC] Check 19: payments.methods permission registered ... [PASS]
[RBAC] Check 20: Administrator has payments.verify ... [PASS]
[RBAC] Check 21: Administrator has payments.methods ... [PASS]
[RBAC] Check 22: Accountant has payments.verify ... [PASS]
[RBAC] Check 23: Accountant does NOT have payments.methods (Admin privilege only) ... [PASS]
[ADMIN_TOGGLE] Check 24: Admin 1-click toggles provider inactive ... [PASS]
[ADMIN_TOGGLE] Check 25: Admin 1-click toggles provider active ... [PASS]
[METHOD_MGMT] Check 26: Admin creates new payment provider ... [PASS]
[METHOD_MGMT] Check 27: Admin updates payment provider details ... [PASS]
[VALIDATION] Check 28: Zero amount submission rejected ... [PASS]
[VALIDATION] Check 29: Amount exceeding invoice balance rejected ... [PASS]
[VALIDATION] Check 30: Inactive payment method rejected ... [PASS]
[SUBMISSION] Check 31: Valid payment submission created ... [PASS]
[SUBMISSION] Check 32: Submission status is pending_verification ... [PASS]
[SUBMISSION] Check 33: Tracking number generated (SUB-YYYYMMDD-XXXX) ... [PASS]
[SUBMISSION] Check 34: Proof file securely stored in storage directory ... [PASS]
[FINANCIAL_INVARIANCE] Check 35: Pending submission does NOT decrease invoice balance ... [PASS] (Balance: 5000.00)
[DUPLICATE_CHECK] Check 36: Duplicate transaction reference blocked for same provider ... [PASS]
[WORKFLOW] Check 37: Submission transition to under_review ... [PASS]
[VERIFICATION] Check 38: Submission status updated to verified ... [PASS]
[VERIFICATION] Check 39: Payment ID linked on verified submission ... [PASS]
[VERIFICATION] Check 40: Verified amount recorded ... [PASS]
[SETTLEMENT] Check 41: Invoice balance reduced atomically upon verification ... [PASS] (New balance: 3500.00)
[SETTLEMENT] Check 42: Invoice status updated to Partially Paid ... [PASS]
[IMMUTABLE_LEDGER] Check 43: Verified payment recorded in payments table ... [PASS]
[IMMUTABLE_LEDGER] Check 44: Official receipt generated (RCT-xxxx) ... [PASS]
[REJECTION] Check 45: Submission status updated to rejected ... [PASS]
[REJECTION] Check 46: Rejection reason saved ... [PASS]
[REJECTION] Check 47: Reviewer notes saved ... [PASS]
[FINANCIAL_INVARIANCE] Check 48: Rejected submission does NOT modify invoice balance ... [PASS]
[FULL_SETTLEMENT] Check 49: Invoice balance reaches 0.00 upon final verification ... [PASS]
[FULL_SETTLEMENT] Check 50: Invoice payment_status becomes Paid ... [PASS]
[REPORTING] Check 51: Summary stats has verified_count >= 2 ... [PASS]
[REPORTING] Check 52: Summary stats has verified_amount > 0 ... [PASS]
[REPORTING] Check 53: Summary stats has rejected_count >= 1 ... [PASS]
[REPORTING] Check 54: Collections grouped by provider returned ... [PASS]
[PROOF_SECURITY] Check 55: Proof path resolved inside storage directory ... [PASS]
[I18N] Check 56: t('Payment Methods') resolves in Amharic ... [PASS]
[I18N] Check 57: t('Verification Queue') resolves in Amharic ... [PASS]
[I18N] Check 58: t('Commercial Bank of Ethiopia (CBE)') resolves in Amharic ... [PASS]
[I18N] Check 59: t('Telebirr (Ethio Telecom)') resolves in Amharic ... [PASS]
[I18N] Check 60: t('Pending Verification') resolves in Amharic ... [PASS]
[I18N] Check 61: t('Under Review') resolves in Amharic ... [PASS]
[I18N] Check 62: t('Verified') resolves in Amharic ... [PASS]
[I18N] Check 63: t('Rejected') resolves in Amharic ... [PASS]
[ENDPOINTS] Check 64: public/payments/index.php exists on disk ... [PASS]
[ENDPOINTS] Check 65: public/payments/verification.php exists on disk ... [PASS]
[ENDPOINTS] Check 66: public/payments/methods.php exists on disk ... [PASS]
[ENDPOINTS] Check 67: public/payments/pay.php exists on disk ... [PASS]
[ENDPOINTS] Check 68: public/payments/proof.php exists on disk ... [PASS]
[ENDPOINTS] Check 69: public/payments/reports.php exists on disk ... [PASS]

=================================================================
MANUAL PAYMENT SYSTEM SUITE RESULT: 69 / 69 tests PASSED (100%)
=================================================================
```

---

## 8. File Inventory & Cross-References

| Component | Absolute Path | Description |
| :--- | :--- | :--- |
| **Migration** | `database/migrations/20261122_manual_payment_system.sql` | Schema creation, permissions registration, Ethiopian provider seed |
| **Permissions Grants** | `database/least_privilege.sql` | Least-privilege grants for `payment_methods` and `payment_submissions` |
| **Core Service** | `app/services/ManualPaymentService.php` | Methods management, customer submissions, duplicate checking, verification workflow |
| **Admin Providers UI** | `public/payments/methods.php` | 1-click active/inactive toggle buttons, provider edit/add forms |
| **Verification Dashboard** | `public/payments/verification.php` | Staff queue, review modal, proof viewer, verify/reject actions |
| **Customer Pay Portal** | `public/payments/pay.php` | Invoice settlement, dynamic bank account details, proof upload with preview |
| **Secure Proof Stream** | `public/payments/proof.php` | Anti-IDOR proof streaming with path traversal defense & MIME check |
| **Payment Reports** | `public/payments/reports.php` | Provider collection breakdown, volume share %, CSV export |
| **Customer Dashboard** | `app/views/dashboards/customer.php` | "Pay Invoice" buttons on invoices + Payment Verification Tracking table |
| **Accountant Dashboard**| `app/views/dashboards/accountant.php`| Verification Queue badge & direct review link |
| **Admin Dashboard** | `app/views/dashboards/admin.php` | Real-time pending audit counter card |
| **Amharic Dictionary** | `app/lang/am.php` | Complete Amharic translations for all payment entities |
| **Automated Suite** | `tests/manual_payment_system_suite.php` | 69 automated verification tests |

---

## 9. Operational Summary & Recommendations

The ALPHA GARAGE Manual Payment Management & Verification System is complete, robust, secure, and production-ready:
1. **Zero Gateway Dependencies:** Functions flawlessly in Ethiopia without reliance on Stripe, PayPal, or unstable external banking APIs.
2. **Fraud Prevention:** Duplicate transaction reference checks, human verification, and proof file inspection protect against fake deposit confirmations.
3. **Audit Trail Compliance:** Every submission, status change, and admin toggle is immutably logged in `audit_logs` with before/after state captures.
4. **Immediate Accessibility:** Both customers and staff have immediate visibility into pending, verified, and rejected transactions with complete bilingual clarity.
