# ALPHA GARAGE — PHASE 1.9D: APPLICATION SECURITY REMEDIATION PLAN

**Target System:** ALPHA GARAGE Management System  
**Execution Environment:** PHP 8.3.1, MySQL 5.7.24 (MAMP Windows), Apache 2.4.33  
**Isolated Test Target:** `garage_management_system_test` on `127.0.0.1:8088`  
**Production Guard:** `garage_management_system` strictly READ-ONLY (100% Protected)  
**Date:** November 13, 2026  
**Status:** Approved & Implemented  

---

## 1. Executive Summary & Authorization Scope

Phase 1.9D addresses three distinct security vulnerabilities identified during the Phase 1.9A architectural audit and confirmed during Phase 1.9C regression testing. The remediation plan focuses on eliminating information leakage, reducing the public attack surface, and enforcing strict object-level access controls (IDOR prevention) for customer interactions.

### Authorized Remediation Targets

| Finding ID | Vulnerability Title | Severity | Core Vulnerability | Target Component |
|---|---|---|---|---|
| **SEC-AUTH-01** | Password Reset Information Disclosure | Medium | Recovery link exposed in HTTP response/session when debug mode was enabled; enumeration risk. | `app/services/PasswordResetService.php`, `public/forgot-password.php` |
| **SEC-APP-01** | Public Installer Exposure | Medium | Publicly accessible `setup.php` in web root capable of resetting database or exposing installation states. | `public/setup.php`, `public/login.php`, `scripts/legacy_setup.php` |
| **SEC-RBAC-01** | Customer Booking Flow & IDOR Boundaries | Medium | Customers attempting to book service were routed to staff-only `/appointments/form.php` (403 Forbidden); missing customer booking portal and IDOR boundaries. | `public/book.php`, `public/appointments/form.php`, navigation links |

---

## 2. Technical Remediation Specifications

### 2.1 Remediation SEC-AUTH-01: Password Reset Information Disclosure

#### Problem Statement
In `app/services/PasswordResetService.php`, lines 90–93 populated `$_SESSION['_dev_reset_link'] = $resetUrl;` whenever `$GLOBALS['config']['app']['debug']` was true. Concurrently, `public/forgot-password.php` extracted this session variable and rendered a bootstrap banner with the raw reset URL containing the plaintext recovery token. This violated CWE-200 (Information Exposure) and could allow unauthorized account takeovers if debug mode was inadvertently active or session tokens were intercepted. Furthermore, timing or response differences could facilitate user enumeration.

#### Remediation Architecture
1. **Complete Removal of Session Exposure:**
   - Deleted `$_SESSION['_dev_reset_link']` assignment in `PasswordResetService::initiateReset()`.
   - Verified that zero tokens or URLs are stored in session or returned to HTTP output.
2. **UI & Response Sanitization:**
   - Removed the `$devResetLink` variable and associated HTML alert block from `public/forgot-password.php`.
   - Maintained uniform generic feedback: `"If an account exists with this email address, password reset instructions have been sent."` regardless of whether the email exists.
3. **Cryptographic Integrity Preservation:**
   - Plaintext tokens are transmitted solely via authenticated mail delivery (using existing mail configuration).
   - Only 64-character SHA-256 hashes (`hash('sha256', $token)`) are stored in `password_resets`.
   - Single-use consumption and 1-hour expiration are preserved.

---

### 2.2 Remediation SEC-APP-01: Public Installer Exposure

#### Problem Statement
`public/setup.php` existed directly within the web-accessible DocumentRoot. Even if gated by checks, public accessibility of installation endpoints poses severe reconnaissance and denial-of-service risks (CWE-284). Additionally, `public/login.php` rendered a visible hyperlink pointing directly to `setup.php`.

#### Remediation Architecture
1. **Archive and Relocate Installer:**
   - Relocated installer logic to `scripts/legacy_setup.php` outside the Apache DocumentRoot (`C:\MAMP\htdocs\AutoPro_Garage-main\AutoPro_Garage-main\public`).
   - Injected strict execution guards into `scripts/legacy_setup.php`:
     ```php
     if (PHP_SAPI !== 'cli') {
         http_response_code(403);
         exit("Direct web access forbidden. Run via CLI: php scripts/legacy_setup.php\n");
     }
     ```
2. **Purge from Web Root:**
   - Permanently deleted `public/setup.php`.
   - Tested HTTP GET request to `/setup.php` to verify exact `404 Not Found` response.
3. **Interface Cleanup:**
   - Removed the `"First time? Setup database"` link from `public/login.php`.
   - Confirmed normal application authentication and routing operate independently without dependency on setup scripts.

---

### 2.3 Remediation SEC-RBAC-01: Customer Booking Flow & IDOR Boundaries

#### Problem Statement
Public marketing and navigation links (e.g., "Book Service" buttons on `public/index.php`, `public/services.php`, and `public/contact.php`) linked directly to `/appointments/form.php`. That endpoint strictly required the staff permission `appointments.manage`, which customer accounts (`role_name === 'customer'`) do not possess. Consequently, customers attempting to book were hit with an unhelpful `HTTP 403 Forbidden`. Furthermore, there was no customer-centric booking interface with object-level ownership checks (Insecure Direct Object Reference / IDOR).

#### Remediation Architecture
1. **Dedicated Customer Booking Portal (`public/book.php`):**
   - Implemented `public/book.php` accessible exclusively to authenticated customers.
   - Unauthenticated visitors are redirected to `login.php?redirect=/book.php`.
   - Staff roles (Admin, Receptionist, Mechanic, Accountant) are redirected to `/dashboard.php`.
   - Customer profile resolution: Automatically resolves or provisions a linked `customers` record based on the authenticated user ID (`created_by = user_id`).
2. **Strict Insecure Direct Object Reference (IDOR) Protections:**
   - **Vehicle Ownership Verification:** When booking an appointment using an existing vehicle, `book.php` executes:
     ```php
     $vCheck = Database::row('SELECT id FROM vehicles WHERE id = ? AND customer_id = ? AND is_active = 1', [$vehicleId, $customerId]);
     if (!$vCheck) {
         http_response_code(403);
         exit("Access Denied: You do not own this vehicle.");
     }
     ```
     Any attempt by Customer B to schedule an appointment against Customer A's vehicle is rejected with HTTP 403 Forbidden.
   - **Appointment View Verification:** When inspecting a booking confirmation (`book.php?view={id}`), the query strictly binds both `id` and `customer_id`:
     ```php
     $appointment = Database::row('SELECT * FROM appointments WHERE id = ? AND customer_id = ?', [$viewId, $customerId]);
     if (!$appointment) {
         http_response_code(403);
         exit("Access Denied: Appointment not found or unauthorized.");
     }
     ```
     Any cross-customer view attempt is rejected with HTTP 403 Forbidden.
3. **Graceful Legacy Redirect in `public/appointments/form.php`:**
   - Preserves `appointments.manage` enforcement for staff.
   - Checks if the authenticated user has the `'customer'` role and gracefully redirects them with HTTP 302 to `/book.php`.
4. **Zero Staff Privileges for Customer Accounts:**
   - Customer accounts remain strictly blocked (HTTP 403) from `/appointments/`, `/invoices/`, `/customers/`, `/inventory/`, `/users/`, and `/audit-logs/`.

---

## 3. Remediation Test Matrix (17 Test Assertions)

The following verification suite (`tests/security_remediation_1_9d_suite.php`) was designed to rigorously validate all three remediations:

| Test ID | Security Finding | Assertion Description | Expected Result |
|---|---|---|---|
| **REM-AUTH-01** | SEC-AUTH-01 | Password reset response contains zero plaintext links or tokens in HTML | Token absent, generic notice rendered |
| **REM-AUTH-02** | SEC-AUTH-01 | Password reset returns identical confirmation for existing vs non-existing emails | Identical HTTP 200 message (no enumeration) |
| **REM-AUTH-03** | SEC-AUTH-01 | `$_SESSION['_dev_reset_link']` is never populated in runtime | Session key does not exist |
| **REM-AUTH-04** | SEC-AUTH-01 | Reset tokens stored strictly as 64-char SHA-256 hashes in database | Token hash length = 64, plaintext absent |
| **REM-AUTH-05** | SEC-AUTH-01 | Reset tokens remain single-use and invalid upon consumption | Second reset attempt rejected |
| **REM-APP-01** | SEC-APP-01 | GET `/setup.php` returns HTTP 404 Not Found from public web root | HTTP 404 Not Found |
| **REM-APP-02** | SEC-APP-01 | Normal login page is active and contains zero links to `setup.php` | HTTP 200, link absent |
| **REM-APP-03** | SEC-APP-01 | Unauthenticated route protection intact (dashboard redirects to login) | HTTP 302 to `login.php` |
| **REM-APP-04** | SEC-APP-01 | Legacy installer preserved exclusively in `scripts/` outside web root | File exists, CLI guard present |
| **REM-RBAC-01** | SEC-RBAC-01 | Authenticated customer accesses dedicated `/book.php` portal | HTTP 200, Booking Portal rendered |
| **REM-RBAC-02** | SEC-RBAC-01 | Customer can book appointment for own vehicle | HTTP 302 redirect to `book.php?view={id}` |
| **REM-RBAC-03** | SEC-RBAC-01 | Customer can view their own booking confirmation | HTTP 200, booking details displayed |
| **REM-RBAC-04** | SEC-RBAC-01 | IDOR Defense: Customer B cannot view Customer A appointment | HTTP 403 Forbidden |
| **REM-RBAC-05** | SEC-RBAC-01 | IDOR Defense: Customer B cannot book using Customer A vehicle | HTTP 403 Forbidden |
| **REM-RBAC-06** | SEC-RBAC-01 | Customer role has zero access to staff appointment board (`/appointments/`) | HTTP 403 Forbidden |
| **REM-RBAC-07** | SEC-RBAC-01 | Customer access to legacy `/appointments/form.php` redirects to `/book.php` | HTTP 302 redirect to `book.php` |
| **REM-RBAC-08** | SEC-RBAC-01 | Staff (Receptionist) preserves full appointment management access | HTTP 200 on `/appointments/` & `form.php` |

---

## 4. Safety Guardrails & Verification Environment

1. **Test Target Isolation:** All testing conducted against `garage_management_system_test` on `127.0.0.1:8088`.
2. **Storage Separation:** Test sessions and logs isolated to `storage/testing/`.
3. **Production Database Guard:** `garage_management_system` remained strictly read-only.
4. **Trigger Immutability:** Audit triggers (`trg_audit_no_update`, `trg_audit_no_delete`) active on both databases.
5. **Audit Trail Verification:** Complete SHA-256 hash chain verified before and after all test executions.
