# ALPHA GARAGE — PHASE 1.10C
# CUSTOMER IDENTITY VERIFICATION & SECURE ACCOUNT LINKING REMEDIATION REPORT

**Author:** Alpha Garage Security & Engineering Team  
**Date:** November 17, 2026  
**Status:** COMPLETE & VERIFIED  
**Target Vulnerability:** `FIND-110B-02` (Insecure Email-Matching Walk-In Customer Account Takeover)  
**Target Test Database:** `garage_management_system_test` (MIGRATED & TESTED)  
**Production Database:** `garage_management_system` (STRICTLY UNTOUCHED & PROTECTED)  

---

## 1. EXECUTIVE SUMMARY

During Phase 1.10B acceptance review, vulnerability `FIND-110B-02` was identified in the customer ownership resolution mechanism. Previous iterations utilized an insecure dual-clause SQL predicate:
```sql
WHERE created_by = :user_id OR (email IS NOT NULL AND LOWER(email) = LOWER(:user_email))
```
Because the application allowed self-registration without mandatory outbound email ownership verification prior to account creation, an unverified malicious actor could register an account using the known email address of an in-person "walk-in" customer (e.g., entered by a front-desk receptionist). Upon successful registration or login, the system automatically conferred full customer identity rights onto the newly registered user, exposing the victim's vehicles, appointments, service work orders (job cards), diagnostic notes, and financial invoices.

Phase 1.10C completely remediates this vulnerability by:
1. **Introducing Explicit, Immutable Foreign Key Ownership (`customers.user_id`):** Completely deprecating email-matching as an authorization predicate across registration, customer dashboard, appointment booking, and vehicle lookup workflows.
2. **Mandating Cryptographic Email Ownership Verification for Account Linking:** Walk-in records (`user_id IS NULL`) are never automatically linked upon registration. An authenticated user wishing to claim past walk-in history must trigger an explicit linking verification request.
3. **Implementing Single-Use, Expiring SHA-256 Token Architecture:** Verification tokens are generated with 256 bits of cryptographic entropy (`random_bytes(32)`), expire in 30 minutes, are stored exclusively as 64-character SHA-256 hashes in `customer_link_tokens`, and are locked via `SELECT ... FOR UPDATE` with atomic conditional state transitions (`UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL`).
4. **Enforcing Ambiguity Rejection:** If multiple walk-in customer records share an email address, automated linking is prohibited and routed to front-desk staff to prevent unauthorized record aggregation or collision.
5. **Guaranteeing Production Safety:** The live production database `garage_management_system` was left 100% untouched (30 tables, 0 migration changes, 3 payments, 90 audit logs, 11 users, and intact cryptographic Merkle chain). All migrations and tests executed exclusively against `garage_management_system_test`.

---

## 2. VULNERABILITY ROOT CAUSE & ANATOMY (`FIND-110B-02`)

### 2.1 The Vulnerability
In a traditional auto repair shop, customers frequently book services or walk in without having a pre-existing web portal account. Receptionists create customer records (`customers` table) with full names, phone numbers, vehicle license plates, and email addresses. In this state, `customers.created_by` equals the receptionist's `user_id`.

In Phase 1.10, customer authorization and dashboard scoping in `public/register.php`, `public/book.php`, and `app/views/dashboards/customer.php` relied on:
```php
$stmt = $pdo->prepare("SELECT * FROM customers WHERE (created_by = ? OR (email IS NOT NULL AND LOWER(email) = ?)) ORDER BY id ASC LIMIT 1");
```

### 2.2 Attack Scenario
1. Victim "Alice" visits Alpha Garage in person. Receptionist enters Alice's details (`alice@example.com`, vehicle `AA-12345`).
2. Attacker "Bob" learns or guesses Alice's email address (`alice@example.com`).
3. Bob navigates to `/register.php` and creates a customer account specifying `alice@example.com`. Because self-registration did not block portal access pending email confirmation, Bob was immediately logged in.
4. When Bob accessed `/customer.php` or `/book.php`, the query matched `LOWER(email) = 'alice@example.com'`.
5. Bob obtained immediate, unauthorized access to Alice's vehicle details, service history, repair notes, and billing records, and could book services against Alice's vehicle ID (IDOR).

---

## 3. REMEDIATION ARCHITECTURE & CODE DESIGN

### 3.1 Database Migration (`20261117_customer_identity_verification.sql`)
The migration was created in `database/migrations/20261117_customer_identity_verification.sql`:
```sql
-- 1. Add user_id column to customers table referencing users(id)
ALTER TABLE `customers`
    ADD COLUMN `user_id` INT UNSIGNED NULL DEFAULT NULL AFTER `id`,
    ADD CONSTRAINT `fk_customers_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    ADD INDEX `idx_customers_user_id` (`user_id`);

-- 2. Populate user_id for existing self-registered customer accounts
UPDATE `customers` c
JOIN `users` u ON c.created_by = u.id
SET c.user_id = u.id
WHERE u.role = 'customer' AND c.user_id IS NULL;

-- 3. Create secure token store for customer linking
CREATE TABLE IF NOT EXISTS `customer_link_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `customer_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL DEFAULT NULL,
    `ip_address` VARCHAR(45) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_clt_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_clt_customer_id` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
    INDEX `idx_clt_lookup` (`token_hash`, `used_at`, `expires_at`),
    INDEX `idx_clt_user_pending` (`user_id`, `used_at`, `expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 Service Layer (`app/services/CustomerIdentityService.php`)
A dedicated service encapsulating all identity, linking, and authorization rules:
- **`resolveCustomer(array $user): array`**: Resolves customer profile strictly by `user_id = $userId`. If no customer record exists, automatically initializes a new profile with `user_id = $userId` and `created_by = $userId`.
- **`getLinkedCustomerIds(int $userId): array`**: Returns all customer IDs explicitly linked to the user account. Used for authorization scoping across all queries.
- **`findUnlinkedWalkInRecord(string $email): ?array`**: Locates walk-in records where `email = $email AND user_id IS NULL`. Rejects ambiguous duplicate records (returns `null` if count > 1) with an audit warning.
- **`requestLinking(array $user, ?string $ipAddress): array`**: Generates a 256-bit cryptographically secure token (`bin2hex(random_bytes(32))`), stores only the SHA-256 hash, invalidates prior pending tokens for the user, and sends a verification link via `MailService`.
- **`confirmLinking(string $rawToken, int $authenticatedUserId): array`**:
  1. Validates token format (64-char hex).
  2. Computes `hash('sha256', $rawToken)`.
  3. Locks token record with `SELECT ... FOR UPDATE`.
  4. Validates single-use (`used_at IS NULL`) and non-expiration (`expires_at > NOW()`).
  5. Enforces ownership: `(int) $token['user_id'] === $authenticatedUserId`.
  6. Executes atomic conditional claim: `UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL`.
  7. Marks token consumed (`used_at = NOW()`) and records audit event in Merkle audit log.
- **`invalidateTokensForUser(int $userId): void`**: Invalidates all pending tokens when a user changes their email address.

### 3.3 Verification Controller (`public/verify-customer.php`)
Handles GET requests from email links and POST requests initiated from the customer dashboard:
- Requires authenticated session (`customer` role).
- Verifies CSRF protection for POST linking requests.
- Validates the token and links the account via `CustomerIdentityService::confirmLinking`.
- Redirects cleanly to `/customer.php` with appropriate flash notification.

### 3.4 Registration Hardening (`public/register.php`)
- Completely decoupled from walk-in customer claiming.
- Creates a dedicated customer row with `user_id = $uid, created_by = $uid`.
- Never overwrites or links walk-in records during registration.

### 3.5 Customer Dashboard & Booking Hardening (`public/customer.php`, `app/views/dashboards/customer.php`, `public/book.php`)
- **Strict Customer Scoping:** Queries for vehicles, appointments, job cards, and invoices use `WHERE customer_id IN (...)` using `$linkedCustomerIds`.
- **Non-Disclosing Walk-In Alert:** If an unlinked walk-in record matching the user's email exists, displays a neutral notice ("We found previous service records associated with your email address. For your security, a verification email is required to link them to your portal account.") with a "Send Verification Link" button. No vehicle plates, service dates, or invoice amounts are disclosed prior to linking.
- **IDOR Protection in Booking:** `public/book.php` verifies that any selected existing vehicle belongs strictly to `in_array($vehCheck['customer_id'], $linkedCustomerIds)`. Unauthorized access triggers an audit rejection and terminates with `abort(403)`.

### 3.6 Email Change Invalidation (`public/users/form.php`)
When an administrative or profile update changes a user's email address, `CustomerIdentityService::invalidateTokensForUser($id)` is invoked immediately, neutralizing any unconsumed tokens issued to the previous address.

---

## 4. SECURITY VERIFICATION & COMPLIANCE

| Security Threat / Invariant | Mitigation Mechanism | Verification Status |
| :--- | :--- | :--- |
| **Account Takeover via Self-Registration** | Registration creates isolated profile (`user_id = $uid`). Walk-in records are never claimed on registration. | **VERIFIED (PASS)** |
| **Walk-In Record IDOR Access** | All dashboard and booking queries scoped strictly to `customers.user_id = $userId`. | **VERIFIED (PASS)** |
| **Token Compromise / DB Leak** | Plaintext tokens never stored; stored as 64-char hex SHA-256 hashes with 30-minute expiration. | **VERIFIED (PASS)** |
| **Cross-User Token Hijacking** | Token verification strictly enforces `(int) $token['user_id'] === $authenticatedUserId`. | **VERIFIED (PASS)** |
| **Duplicate Walk-In Record Ambiguity** | Ambiguous duplicate walk-in emails reject automatic linking and log audit warning. | **VERIFIED (PASS)** |
| **Token Replay / Double Use** | Consumed tokens set `used_at = NOW()`; locked with `FOR UPDATE`; reuse returns 400. | **VERIFIED (PASS)** |
| **Concurrent Race Conditions** | Linking uses atomic conditional update: `UPDATE customers SET user_id = ? WHERE id = ? AND user_id IS NULL`. | **VERIFIED (PASS)** |
| **Information Disclosure / Enumeration** | Unlinked records display non-disclosing prompt; no vehicle or invoice data rendered. | **VERIFIED (PASS)** |
| **Email Modification Token Invalidation** | Email change triggers `invalidateTokensForUser($userId)` deleting pending tokens. | **VERIFIED (PASS)** |
| **Audit Hash-Chain Integrity** | Linking and rejection events logged to tamper-evident cryptographic Merkle audit log. | **VERIFIED (PASS)** |

---

## 5. AUTOMATED TEST SUITE RESULTS

### 5.1 Customer Identity & Account Linking Suite (`tests/customer_identity_1_10c_suite.php`)
```
=================================================================
ALPHA GARAGE — PHASE 1.10C CUSTOMER IDENTITY VERIFICATION SUITE
Target: garage_management_system_test on 127.0.0.1:3306
=================================================================

  [✔ PASS] CID-01: Registration creates isolated customer profile without claiming walk-in record (37.2ms)
  [✔ PASS] CID-02: Unverified customer dashboard shows zero walk-in vehicles, invoices, or appointments (38.8ms)
  [✔ PASS] CID-03: Unverified account booking service using walk-in vehicle ID returns HTTP 403 (22.2ms)
  [✔ PASS] CID-04: Non-disclosing banner displays when unlinked walk-in record exists (39.5ms)
  [✔ PASS] CID-05: Requesting verification link creates SHA-256 token expiring in 30 minutes (26.3ms)
  [✔ PASS] CID-06: Verification link confirms ownership and links customer record (user_id populated) (42.4ms)
  [✔ PASS] CID-07: Verified customer dashboard now displays linked vehicles and service history (44.9ms)
  [✔ PASS] CID-08: Reusing consumed verification token fails with validation error (used_at not null) (18.6ms)
  [✔ PASS] CID-09: Expired token cannot be used to link customer record (19.4ms)
  [✔ PASS] CID-10: Cross-user token theft blocked: User B cannot use token generated for User A (20.3ms)
  [✔ PASS] CID-11: Ambiguous duplicate walk-in records reject automated linking (requires staff review) (21.7ms)
  [✔ PASS] CID-12: Changing user email invalidates all pending verification tokens (18.6ms)
  [✔ PASS] CID-13: Concurrent linking requests: exactly one succeeds and second is rejected cleanly (34.8ms)
  [✔ PASS] CID-14: Existing verified customer account retains access after migration (backward compat) (34.3ms)
  [✔ PASS] CID-15: Cryptographic audit trail logs linking events with valid Merkle hash chain (25.1ms)

=================================================================
PHASE 1.10C TEST SUMMARY: 15 / 15 PASSED (100%)
Isolated Test Database: garage_management_system_test (PROTECTED)
=================================================================
```

### 5.2 Full Regression Test Suite Execution Summary
All existing test suites were executed to verify zero regression across the system:

| Test Suite File | Component / Focus | Assertions / Tests | Status |
| :--- | :--- | :--- | :--- |
| `tests/customer_identity_1_10c_suite.php` | Customer Identity & Linking Remediation | 15 / 15 | **PASSED (100%)** |
| `tests/role_dashboards_1_10_suite.php` | Role-Based Dashboards & RBAC Separation | 23 / 23 | **PASSED (100%)** |
| `tests/payment_idempotency_1_9f_hardening_suite.php` | Concurrency & Crash-Recovery Hardening | 12 / 12 | **PASSED (100%)** |
| `tests/payment_idempotency_1_9e_suite.php` | Financial Idempotency & Balance Integrity | 14 / 14 | **PASSED (100%)** |
| `tests/security_remediation_1_9d_suite.php` | Phase 1.9D Security Remediations | 17 / 17 | **PASSED (100%)** |
| `tests/security_regression_suite.php` | Full Security & Session Regression | 54 / 54 | **PASSED (100%)** |
| `scripts/run_isolated_tests.php` | Core Isolated Runner (Auth, Safety, Chain) | 5 / 5 suites | **PASSED (100%)** |
| **TOTAL** | **Comprehensive System Health** | **140 Assertions** | **100% PASSED** |

---

## 6. PRODUCTION DATABASE SAFETY VERIFICATION

Strict read-only verification was executed on the live production database `garage_management_system` following all testing.

```
Active Database: garage_management_system
Production Table Count: 30
customers.user_id exists: NO
customer_link_tokens exists: NO
Payments: 3
Audit Logs: 90
Users: 11
Audit Chain: VALID (All 90 audit records are intact)
```

**Confirmation:**
1. Zero schema alterations were applied to `garage_management_system`.
2. Zero records were inserted, modified, or deleted in `garage_management_system`.
3. The cryptographic SHA-256 Merkle audit trail of 90 records remains 100% intact.
4. Production migration `20261117_customer_identity_verification.sql` is staged and ready for controlled production deployment when authorized.

---

## 7. MODIFIED & CREATED FILES MANIFEST

### 7.1 New Deliverables
- `database/migrations/20261117_customer_identity_verification.sql`: Schema migration for `customers.user_id` and `customer_link_tokens`.
- `app/services/CustomerIdentityService.php`: Core identity, token generation, linking, and authorization service.
- `public/verify-customer.php`: Controller for customer email token verification.
- `tests/customer_identity_1_10c_suite.php`: Comprehensive automated test suite (15 assertions).
- `docs/phase-1.10/PHASE_1_10C_CUSTOMER_IDENTITY_REMEDIATION.md`: This comprehensive remediation and safety report.

### 7.2 Modified Files
- `public/register.php`: Creates dedicated customer profile with explicit ownership; removes automatic walk-in claim.
- `public/book.php`: Replaced email-based customer resolution with `CustomerIdentityService::resolveCustomer` and `$linkedCustomerIds`; enforced strict vehicle ownership check.
- `app/views/dashboards/customer.php`: Uses `CustomerIdentityService::resolveCustomer` and `$linkedCustomerIds`; scopes all queries; displays non-disclosing walk-in link prompt.
- `public/users/form.php`: Added `CustomerIdentityService::invalidateTokensForUser($id)` upon user email modification.
- `public/dashboard.php`: Safely redirects `customer` role to `/customer.php` via HTTP 302.

---

## 8. RESIDUAL RISKS & RECOMMENDATIONS

1. **Production Deployment Sequence:** When promoting Phase 1.10C to production, execute `20261117_customer_identity_verification.sql` during a scheduled maintenance window. Existing customer accounts with `role = 'customer'` will automatically have `customers.user_id` populated with their corresponding `users.id`.
2. **Transactional Mail Delivery:** In production, ensure SMTP credentials in `app/config/local.php` are configured so that verification emails reach walk-in customers promptly.
3. **Staff Training for Receptionists:** Front-desk staff should be informed that if a customer reports duplicate walk-in profiles preventing automated linking (indicated by the ambiguity safeguard), staff can merge or correct customer profiles from the administrative interface.
