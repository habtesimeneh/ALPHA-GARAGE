# ALPHA GARAGE — PHASE 1.10D
# INDEPENDENT CUSTOMER IDENTITY SECURITY AUDIT & FINAL ACCEPTANCE REPORT

**Audit Date:** November 17, 2026  
**Auditor:** Alpha Garage Security & Code Audit Team (Independent Review)  
**Target Phase:** Phase 1.10C Customer Identity Verification & Secure Account Linking  
**Target Vulnerability Remediated:** `FIND-110B-02` (Insecure Email-Matching Walk-In Customer Account Takeover)  
**Target Test Database:** `garage_management_system_test` (31 tables, verified)  
**Target Production Database:** `garage_management_system` (30 tables, 100% untouched)  
**Audit Decision:** **CONDITIONAL ACCEPTANCE** (Detailed in Section 10)  

---

## 1. EXECUTIVE SUMMARY

An independent, strictly read-only security audit was conducted on the Phase 1.10C implementation of customer identity verification and account linking. The primary objective was to verify whether vulnerability `FIND-110B-02` (identified during Phase 1.10B) was fully and safely remediated without introducing new account takeover, IDOR, token replay, concurrency race conditions, data exposure, or data integrity flaws.

### 1.1 Remediation Verification Status
The audit confirms that **`FIND-110B-02` is remediated**:
1. Self-registration in [register.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/register.php#L108-L114) no longer claims or overwrites existing walk-in customer records. Every registration strictly provisions a dedicated customer record with immutable explicit ownership (`customers.user_id = $uid`).
2. Legacy email-matching SQL fallbacks (`WHERE created_by = ? OR (email IS NOT NULL AND LOWER(email) = ?)`) have been **completely eliminated** from all customer portal queries, appointment booking queries, vehicle lookups, and invoice views.
3. Customer access across [customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/customer.php), [customer.php view](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/views/dashboards/customer.php#L18-L23), and [book.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php#L24-L29) is strictly bounded by `customers.user_id = $userId`.
4. Walk-in records (`customers.user_id IS NULL`) cannot be claimed without cryptographic proof of email ownership via a 256-bit time-limited verification token.
5. Cross-user token theft and replay are blocked by cryptographic token hashing (SHA-256), row-level database locking (`FOR UPDATE`), and strict ownership assertion `(int) $token['user_id'] === $authenticatedUserId`.

### 1.2 Identified Findings Overview
While the core account-takeover vector was remediated, the audit identified **two (2) Medium-severity**, **two (2) Low-severity**, **one (1) Test-quality**, and **one (1) Informational** findings in the newly added components:
- **SEC-110D-01 (Medium):** State-changing customer account linking is executed directly on an HTTP GET request in [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php#L39-L43) without CSRF protection or explicit user confirmation.
- **SEC-110D-02 (Medium):** Missing rate limiting on outbound verification email dispatch (`action=request_link`) in [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php#L23-L32), enabling email flooding / spamming.
- **SEC-110D-03 (Low):** Unauthenticated clicks on verification links drop the token parameter upon redirection to `login.php`.
- **SEC-110D-04 (Low):** Customer dashboard renders a prior service history alert that acts as an enumeration oracle revealing whether an in-person customer record exists for an email.
- **SEC-110D-05 (Test Quality):** The test suite bypassed the HTTP web layer for token confirmation, calling service methods directly and failing to detect SEC-110D-01.
- **SEC-110D-06 (Informational):** Incomplete `$inClause` scoping in bottom appointment table on `book.php`.

Due to `SEC-110D-01` and `SEC-110D-02`, the final determination is **CONDITIONAL ACCEPTANCE**. Full production deployment should proceed once these two items are addressed.

---

## 2. CODE PATHS & REPOSITORY FILES INSPECTED

The audit inspected the complete implementation across all relevant repository paths:

| File / Component | Purpose / Responsibilities Inspected |
| :--- | :--- |
| [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php) | Profile resolution, walk-in lookup, token issuance, confirmation logic, concurrency locking, token invalidation. |
| [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php) | Web controller for handling link dispatch (POST) and token confirmation (GET/POST). |
| [register.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/register.php#L108-L125) | User registration and isolated customer profile provisioning. |
| [customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/customer.php) & [customer.php view](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/views/dashboards/customer.php) | Customer portal dashboard, metrics, vehicle, appointment, job card, and invoice scoping. |
| [book.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php#L21-L188) | Service appointment booking, vehicle resolution, and IDOR validation. |
| [profile.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/profile.php) | User profile and password update flow. |
| [form.php (users)](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/users/form.php#L36-L40) | Administrative user update and email change token invalidation. |
| [dashboard.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/dashboard.php#L41-L44) | Dashboard dispatcher routing customer role to `customer.php`. |
| [form.php (customers)](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/customers/form.php#L19-L28) | Front-desk walk-in customer creation and editing. |
| [view.php (invoices)](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/invoices/view.php#L5-L7) | Staff-only invoice access control (`invoices.view`). |
| [MailService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/MailService.php#L63-L100) | Outbound SMTP and local spool mail delivery. |
| [20261117_customer_identity_verification.sql](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/database/migrations/20261117_customer_identity_verification.sql) | DDL schema migration and data backfill script. |
| [customer_identity_1_10c_suite.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/tests/customer_identity_1_10c_suite.php) | 15-test automated verification test suite. |

---

## 3. CRITICAL SECURITY VERIFICATION ANALYSIS

### 3.1 Token Security Analysis
- **Entropy & Randomness:** In [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L174), tokens are generated via `bin2hex(random_bytes(32))`. This provides 256 bits of cryptographic entropy, preventing brute-force predictability.
- **Hash-at-Rest Storage:** In [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L175-L189), the database stores only `token_hash = hash('sha256', $rawToken)`. Plaintext tokens never exist in `customer_link_tokens`.
- **Identity Binding:** The token record binds `user_id`, `customer_id`, and `email`.
- **Expiration Enforcement:** Tokens expire in 1800 seconds (30 minutes). Enforced in `confirmLinking` ([CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L269-L271)): `strtotime((string) $token['expires_at']) < time()`.
- **Single-Use Enforcement:** `used_at` timestamp is set to `NOW()` upon consumption. Replay attempts are rejected with error: *"This verification token has already been used and cannot be replayed."*
- **Cross-Account Binding:** Strict ownership check ([CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L274)): `(int) $token['user_id'] !== $authenticatedUserId` rejects cross-account consumption and logs an audit security denial.
- **Plaintext Leakage Audit:** Inspection of all logs, audit entries, and error messages confirmed **zero leakage** of raw token values. Audit entries store only `token_id` (integer) and `email`.

### 3.2 Verification Flow Analysis
- **Email Delivery:** Verified. Outbound email delivery is executed through `MailService::send(...)` with real SMTP socket support and local spool fallback.
- **Session Identity Matching:** The confirming user must have an active session whose `id` strictly matches the token's `user_id`. An unauthenticated user or different user cannot consume the token.
- **State-Changing GET Flaw:** Detailed in finding `SEC-110D-01`. Clicking an email link issues an HTTP GET to `verify-customer.php?token=...`, which directly invokes `confirmLinking()` without user confirmation or CSRF protection.

### 3.3 Atomic Linking and Concurrency Analysis
Inspection of database locking and transaction boundaries in [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L252-L347):
1. **Row Locks:**
   - `SELECT * FROM customer_link_tokens WHERE token_hash = ? FOR UPDATE` locks the token row.
   - `SELECT id, user_id, email, is_active FROM customers WHERE id = ? FOR UPDATE` locks the customer row.
2. **Conditional Atomic State Transition:**
   ```sql
   UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL
   ```
   If `$affected !== 1`, linking aborts with concurrency collision.
3. **Token Invalidation:**
   `UPDATE customer_link_tokens SET used_at = NOW() WHERE id = ?` occurs inside the same atomic transaction.
4. **Conclusion on Concurrency:** A single customer record cannot be claimed by two users. Two simultaneous requests for the same token serialize under `FOR UPDATE`; the first consumes the token, and the second aborts cleanly.

### 3.4 Email Change Invalidation Analysis
- **Administrative Email Change:** In [form.php (users)](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/users/form.php#L37-L39), changing a user's email invokes `CustomerIdentityService::invalidateTokensForUser((int) $id)`, marking all pending tokens `used_at = NOW()`.
- **Confirmation-Time Email Verification:** In [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L308-L311):
  ```php
  $currentUserEmail = Database::value('SELECT email FROM users WHERE id = ?', [$authenticatedUserId]);
  if (strtolower(trim((string) $customer['email'])) !== strtolower(trim((string) $currentUserEmail))) {
      return ['ok' => false, 'error' => 'Your account email no longer matches the customer record email.'];
  }
  ```
  Even if a token had not been invalidated, changing the user's account email before token confirmation breaks the email equality assertion and aborts linking.

### 3.5 Customer Data Ownership & IDOR Analysis
- **Query Scoping:** Every customer-facing query in `app/views/dashboards/customer.php` scopes vehicles, appointments, job cards, and invoices using:
  ```sql
  WHERE customer_id IN ($inClause)
  ```
  where `$inClause` is derived exclusively from `CustomerIdentityService::getLinkedCustomerIds((int) $user['id'])`.
- **IDOR Protections in `book.php`:**
  - Viewing appointment ([book.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php#L51-L58)): Asserts `in_array((int) $viewAppointment['customer_id'], $linkedCustomerIds, true)` or aborts HTTP 403.
  - Booking vehicle ([book.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php#L146-L155)): Asserts `in_array((int) $vehCheck['customer_id'], $linkedCustomerIds, true)` or aborts HTTP 403.
- **Ambiguous Walk-In Duplicate Records:** If reception created two walk-in records with the same email, [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L120-L122) flags `ambiguous = true`, blocking automated linking and directing the customer to reception for manual profile consolidation.

### 3.6 Database Migration Integrity Review
Inspection of [20261117_customer_identity_verification.sql](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/database/migrations/20261117_customer_identity_verification.sql):
- `customers.user_id`: Added as `INT UNSIGNED NULL` with FK `fk_cust_linked_user` referencing `users(id) ON DELETE SET NULL` and index `idx_cust_user_id`.
- Backfill safety: Backfill only links customer rows where `created_by` matches a user whose role is `customer` (`r.name = 'customer'`). Staff-created walk-in records (`created_by` = receptionist or admin) are left with `user_id = NULL`.
- `customer_link_tokens`: Correct foreign keys with `ON DELETE CASCADE`, unique constraint on `token_hash`, and indexes on `user_id`, `customer_id`, and `expires_at`.
- Rollback procedure:
  ```sql
  DROP TABLE IF EXISTS customer_link_tokens;
  ALTER TABLE customers DROP FOREIGN KEY fk_cust_linked_user, DROP INDEX idx_cust_user_id, DROP COLUMN user_id;
  ```

---

## 4. TEST QUALITY AUDIT

The implementation of all 15 tests in [customer_identity_1_10c_suite.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/tests/customer_identity_1_10c_suite.php) was examined:

| Test ID | Method / Layer | What Was Tested | Quality & Validity Assessment |
| :--- | :--- | :--- | :--- |
| **CID-01** | HTTP GET | Unverified account viewing `customer.php` | **Genuine HTTP Test:** Verifies walk-in vehicles and plates are absent from rendered HTML. |
| **CID-02** | HTTP GET | Direct appointment access (`book.php?view=ID`) | **Genuine HTTP Test:** Confirms HTTP 403 Forbidden on walk-in appointment access. |
| **CID-03** | HTTP POST | Service booking with walk-in vehicle ID | **Genuine HTTP Test:** Confirms HTTP 403 Forbidden on unauthorized vehicle booking. |
| **CID-04** | PHP Service | `CustomerIdentityService::requestLinking` | **Valid Service Test:** Verifies 256-bit token generation. |
| **CID-05** | Direct DB | `SELECT token_hash FROM customer_link_tokens` | **Valid DB Test:** Verifies only SHA-256 hash is stored. |
| **CID-06** | PHP Service | `CustomerIdentityService::confirmLinking` (cross-user) | **Valid Service Test:** Verifies cross-user token rejection. |
| **CID-07** | PHP Service | `CustomerIdentityService::confirmLinking` (claimant) | **Valid Service Test:** Confirms DB update of `customers.user_id`. |
| **CID-08** | HTTP GET | Post-verification `customer.php` | **Genuine HTTP Test:** Verifies linked vehicles render on dashboard. |
| **CID-09** | PHP Service | Token reuse on consumed token | **Valid Service Test:** Confirms replay rejection. |
| **CID-10** | PHP Service / DB | Expired token verification | **Valid Service Test:** Confirms expired token rejection. |
| **CID-11** | PHP Service | Duplicate walk-in email matching | **Valid Service Test:** Confirms ambiguity rejection. |
| **CID-12** | PHP Service / DB | User email change token invalidation | **Valid Service Test:** Confirms `used_at` set to `NOW()`. |
| **CID-13** | PHP Service / DB | Concurrent linking protection | **Partial Simulation:** Inserts token for already-linked customer and confirms rejection. Sequential rather than simultaneous race. |
| **CID-14** | HTTP GET | Existing customer account backward compat | **Genuine HTTP Test:** Verifies Alice retains VIP services portal access. |
| **CID-15** | PHP Service | Cryptographic Merkle audit chain verification | **Genuine Verification:** Confirms all 530+ test log records are tamper-free. |

**Test Suite Limitations Identified:**
- The test suite directly invokes PHP service methods (`confirmLinking`) for 5 of the 15 tests rather than exercising HTTP requests against `public/verify-customer.php`.
- As a result, the test suite did not detect that `public/verify-customer.php` executes `confirmLinking()` on an unconfirmed HTTP GET request (`SEC-110D-01`).

---

## 5. PRODUCTION DATABASE BASELINE RECONCILIATION

Strictly read-only inspection was executed directly on `garage_management_system` on port 3306.

```
Active Database: garage_management_system
Production Table Count: 30
customers.user_id exists: NO
customer_link_tokens exists: NO
Payments: 3
Audit Logs: 90
Users: 11
Audit Chain Status: VALID (All 90 audit records are intact)
```

### Production Safety Invariants Verified:
1. **Zero Production Schema Alterations:** `customers.user_id` does not exist in production. `customer_link_tokens` does not exist in production.
2. **Zero Production Data Writes:** Table row counts match the Phase 1.9F-B baseline (3 payments, 11 users, 90 audit logs).
3. **Intact Merkle Chain:** The cryptographic SHA-256 hash chain over all 90 production audit records is 100% intact.
4. **Complete Isolation:** Migration `20261117_customer_identity_verification.sql` was applied **strictly and exclusively** to `garage_management_system_test`.

---

## 6. FINDINGS BY SEVERITY

```
CRITICAL:      0
HIGH:          0
MEDIUM:        2  (SEC-110D-01, SEC-110D-02)
LOW:           2  (SEC-110D-03, SEC-110D-04)
INFORMATIONAL: 2  (SEC-110D-05, SEC-110D-06)
```

---

### FINDING SEC-110D-01: State-Changing Account Linking Executed on HTTP GET Without CSRF Protection
- **Severity:** **Medium**
- **File & Lines:** [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php#L34-L46)
- **Vulnerability Description:**
  Lines 34-46 handle token confirmation:
  ```php
  $rawToken = (string) (get('token') ?: post('token') ?: '');
  if ($rawToken !== '') {
      $confirm = CustomerIdentityService::confirmLinking($rawToken, (int) $user['id']);
      if ($confirm['ok']) {
          flash('success', $confirm['message']);
          redirect('customer.php');
      }
  ...
  ```
  The email link formatted as `verify-customer.php?token=XYZ` causes the user's browser to send an HTTP GET request. The controller immediately executes `CustomerIdentityService::confirmLinking(...)`, mutating database ownership without requiring an explicit confirmation step or a CSRF token.
- **Security Impact:**
  1. **Violation of RFC 7231 §4.2.1:** Safe HTTP methods (GET) must not cause server-side resource state mutations.
  2. **GET-Based Cross-Site Request Forgery:** If an attacker induces an authenticated user to load `https://.../verify-customer.php?token=...` via an `<img>` tag or browser prefetch, the action executes automatically.
  3. **Premature Token Consumption by Prefetchers:** Browser pre-rendering engines or corporate email link scanners that pre-fetch links in an authenticated browser session will consume the single-use token prematurely.
- **Recommended Remediation:**
  Split the flow:
  - On HTTP GET: Display a confirmation screen:
    *"You are linking walk-in service history for [email] to your portal account. Click 'Confirm & Link Service History' to proceed."*
  - On HTTP POST: Verify `verify_csrf()` and call `CustomerIdentityService::confirmLinking()`.

---

### FINDING SEC-110D-02: Missing Rate Limiting on Outbound Verification Link Dispatch
- **Severity:** **Medium**
- **File & Lines:** [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php#L22-L32)
- **Vulnerability Description:**
  In `verify-customer.php`, the POST handler for `action=request_link` verifies CSRF, but lacks rate-limiting:
  ```php
  if (is_post() && post('action') === 'request_link') {
      verify_csrf();
      $result = CustomerIdentityService::requestLinking($user);
  ```
- **Security Impact:**
  An authenticated user can submit `action=request_link` in an automated loop. Each request generates a new 256-bit token, invalidates prior tokens, creates database rows in `customer_link_tokens` and `audit_logs`, and dispatches an outbound email via `MailService::send()`. This allows:
  1. Mail bombing / flooding of the victim's email inbox.
  2. SMTP quota exhaustion and sender reputation degradation.
  3. Database bloating.
- **Recommended Remediation:**
  Enforce rate limiting:
  ```php
  $limit = RateLimiter::check('link_req|' . $user['id'], 3, 900);
  if (!$limit['allowed']) {
      flash('error', 'Too many linking requests. Please try again in ' . ceil($limit['retry_after'] / 60) . ' minute(s).');
      redirect('customer.php');
  }
  ```

---

### FINDING SEC-110D-03: Unauthenticated Verification Link Click Drops Token on Login Redirect
- **Severity:** **Low**
- **File & Lines:** [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php#L13), [auth.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/middleware/auth.php#L51-L54)
- **Vulnerability Description:**
  `verify-customer.php` invokes `$user = require_login();` at line 13. If the user clicks the link on a device or browser where they are not currently logged in, `require_login()` redirects to `login.php` without recording the `token` parameter in a session `return_to` variable. After authentication, the user lands on `dashboard.php` / `customer.php`, and the token is lost.
- **Security Impact:**
  Friction and UX failure: The user must re-open their email client and click the link a second time now that a session cookie exists.
- **Recommended Remediation:**
  In `verify-customer.php`, before calling `require_login()`, if `!current_user()`, store `$_SERVER['REQUEST_URI']` in `$_SESSION['return_to']`. Upon successful login on `login.php`, redirect to `$_SESSION['return_to']`.

---

### FINDING SEC-110D-04: Walk-In Service History Presence Disclosure (Account Enumeration Oracle)
- **Severity:** **Low**
- **File & Lines:** [customer.php view](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/views/dashboards/customer.php#L24-L26), [CustomerIdentityService.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/app/services/CustomerIdentityService.php#L98-L125)
- **Vulnerability Description:**
  When a user logs into `customer.php`, lines 24-26 call `findUnlinkedWalkInRecord($user['email'])`. If a matching unlinked walk-in record exists (`count === 1`), lines 134-146 render a prominent banner:
  *"Prior Service History Found: We detected in-person service records matching your account email..."*
  If no record exists, the banner does not render.
- **Security Impact:**
  An adversary registering an account with a target email address can confirm whether that individual has visited Alpha Garage in person by observing whether the banner appears.
- **Recommended Remediation:**
  Make linking an opt-in user action (e.g., a "Link Past In-Person Services" button in Account Settings), or return a generic message upon clicking: *"If matching service records exist, an ownership verification link has been sent to your email."*

---

### FINDING SEC-110D-05: Test Suite Bypasses HTTP Endpoint for Token Confirmation & Concurrency
- **Severity:** **Informational (Test Quality)**
- **File & Lines:** [customer_identity_1_10c_suite.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/tests/customer_identity_1_10c_suite.php#L339-L414)
- **Vulnerability Description:**
  Tests CID-06, CID-07, CID-09, CID-10, and CID-13 test verification logic by directly calling `CustomerIdentityService::confirmLinking(...)` in PHP rather than dispatching HTTP requests to `public/verify-customer.php`.
- **Impact:**
  The test suite does not exercise the HTTP controller, allowing `SEC-110D-01` (state change on GET) to pass unnoticed.
- **Recommended Remediation:**
  Add HTTP-level integration tests asserting that GET `verify-customer.php?token=...` renders a confirmation prompt and that POST with CSRF executes the linking.

---

### FINDING SEC-110D-06: Incomplete `$inClause` Scoping in Bottom Appointment Table on `book.php`
- **Severity:** **Informational**
- **File & Lines:** [book.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php#L179-L188)
- **Vulnerability Description:**
  In `public/book.php`, lines 179-188 query the customer's previous appointments:
  ```php
  $customerBookings = Database::all(
      'SELECT a.*, ... FROM appointments a WHERE a.customer_id = ? ...',
      [$customerId]
  );
  ```
  `$customerId` is the primary resolved customer profile. If the user linked a walk-in record (`$targetCustomerId`), appointments created under that walk-in record are visible on `customer.php` (which uses `$inClause`), but do not appear in the mini-table at the bottom of `book.php`.
- **Impact:**
  Minor display discrepancy. No security impact (IDOR checks at line 51 and line 147 correctly check `$linkedCustomerIds`).
- **Recommended Remediation:**
  Change `WHERE a.customer_id = ?` to `WHERE a.customer_id IN ($inClause)` in line 184.

---

## 7. FINAL ACCEPTANCE DECISION

Based on the code inspection, architectural analysis, and test quality assessment:

```
[ ] FULL ACCEPTANCE
[X] CONDITIONAL ACCEPTANCE
[ ] REJECTED — REMEDIATION REQUIRED
```

### Justification:
1. **Core Vulnerability Remediated:** The primary objective of Phase 1.10C—remediating `FIND-110B-02`—has been successfully achieved. Unverified self-registered accounts can no longer claim walk-in records via email matching, and all customer data queries enforce strict account ownership.
2. **Production Baseline Protected:** The live production database `garage_management_system` is 100% untouched and verified intact.
3. **Conditions for Final Promotion:** Before promoting Phase 1.10C to production, the engineering team must resolve:
   - **Condition 1:** Update [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php) to require a CSRF-protected POST confirmation rather than mutating ownership on an HTTP GET request (`SEC-110D-01`).
   - **Condition 2:** Add rate-limiting (`RateLimiter::check`) to `action=request_link` in [verify-customer.php](file:///c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main/public/verify-customer.php) (`SEC-110D-02`).
   - **Condition 3:** Preserve the verification token across unauthenticated login redirects (`SEC-110D-03`).
