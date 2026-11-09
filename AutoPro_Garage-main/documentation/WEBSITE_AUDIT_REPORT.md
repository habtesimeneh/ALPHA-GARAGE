# AutoPro Garage Website Audit

**Review date:** 2026-11-07  
**Scope:** Repository-level review of public UX, backend flows, security controls, database design, and available tests. This was a static review, not a penetration test or a review of a running production deployment.

## Executive summary

The project has useful foundations: prepared PDO queries, password hashing, role checks, CSRF verification in the request bootstrap, session timeouts, an indexed relational schema, password-reset tokens stored as hashes, and an audit hash chain with database triggers. The automotive-services cards show catalog-backed pricing and estimated time. This follow-up implemented several high-priority findings: contact handling now validates input and reports persistence failures honestly; database connection credentials are environment-only; schema work is explicit; registration fails closed without a customer role; unscoped staff permissions were removed from the customer role; and fabricated service-catalog fallbacks were removed.

This remains a static source review, not a penetration test or a live deployment assessment. MySQL migration and least-privilege setup have not been executed against a disposable database. CSP compatibility is improved on several public/auth pages, but inline styles remain elsewhere. Do not treat the application as production-verified until remaining findings are tested and closed.

## Findings and recommended actions

| Priority | Area | Finding and evidence | Recommended action |
|---|---|---|---|
| **Resolved in source; integration test pending** | Contact/backend | `public/contact.php` previously called an undefined CSRF function. Bootstrap remains the single POST verifier; contact submission now validates fields and handles persistence failures without a false success. | Add and run a request-level test against a disposable database, including successful insert and database failure. |
| **Mitigated in source; deployment verification pending** | Database/security | `app/config/database.php` now requires environment credentials; runtime database creation and root/blank-password fallback paths were removed. A CLI-only migration runner and separate-account grant script were added. | Configure and verify distinct least-privilege runtime/migration accounts. Test baseline import, migration checksums, and grants against a disposable MySQL/MariaDB instance. |
| **Partially resolved; site-wide work remains** | UI/CSP | Inline styles were moved out of the landing/contact and public auth/reset pages, and hero slider backgrounds now use image elements. Other public and authenticated pages still contain inline styles; CSP behavior was not browser-tested. | Continue replacing inline styles and event handlers in every page served with the strict CSP. Verify the complete site in a CSP-enforcing browser; do not weaken the policy with broad `unsafe-inline`. |
| **Resolved in source; integration test pending** | Registration/authorization | Registration no longer falls back to receptionist or the first available role. Phone input is required and validated. | Test registration against a disposable database with and without a customer role; confirm failure is safe and observable. |
| **Resolved in source; migration verification pending** | Customer authorization | The customer role no longer receives the unscoped dashboard, vehicle, or appointment permissions. A forward migration revokes those grants from existing databases; customer logins now return to the public site. No customer portal or customer-to-user ownership mapping exists, so scoped repair-history access is not available. | Apply and verify `database/migrations/revoke_customer_staff_access.sql` on a disposable database. Do not grant customer access to staff records until ownership-scoped endpoints and tests exist. |
| **Resolved in source; integration test pending** | Contact/data integrity | Contact persistence failures now preserve form values and show an explicit error rather than claiming success. | Exercise validation, successful persistence, and database failure through a request-level test. |
| **Resolved in source; integration test pending** | Public catalog/data integrity | `public/index.php` no longer presents hard-coded sample services/prices when the catalog query fails. Empty and unavailable states are distinguished. | Verify the empty/error states in a browser and confirm query failures are logged without exposing internals. |
| **Medium** | Audit assurance | `database/database.sql` adds append-only triggers and `AuditLogger` chains row hashes. These are useful tamper-evidence controls, but a database administrator can alter triggers, rows, and the chain head; this is not independently immutable storage. | Avoid describing the logs as unconditionally immutable. Use a restricted database account and periodically export/sign or anchor audit checkpoints outside the application database. |
| **Medium — open** | Abuse prevention | Login attempts are rate-limited, but the reviewed contact and password-reset request paths have no comparable throttling. Repeated anonymous submissions can consume database and audit-log resources. | Add per-IP and per-identifier rate limits, sensible request-size limits, retention/cleanup, and monitoring for public form abuse. |
| **Low / follow-up** | UX/content | `public/index.php` is the primary luxury landing page, while `public/home.php` is a separate optional marketing page with different content and styles. The main page also contains fixed marketing statistics and claims that should be verified before publication. | Choose one canonical landing page, share service/contact data across pages, verify all public claims, and test navigation and booking flows on mobile. |
| **Low / follow-up** | Key management | `CryptoService` generates a local encryption key when no environment key is configured. The key is protected by `storage/private/.htaccess`, but losing the key makes existing ciphertext unrecoverable; backup and rotation procedures are not evident in the reviewed implementation. | Provision and back up the key through a controlled secrets process, document recovery/rotation, and verify web-root restrictions in the target server configuration. |

## Review by area

### UI and user experience

- The service catalog is data-driven and responsive (three, two, then one column at the existing breakpoints). Cards now provide category, standard price, estimated time, and a service-specific booking link.
- Verify catalog prices and the fixed marketing claims in the database/admin configuration before publishing.
- Test keyboard focus, reduced-motion behavior, narrow-screen card heights, and the complete guest-to-booking path in a browser. This review did not have a running site to inspect visually.
- Resolve the competing `index.php` and optional `home.php` marketing experiences to avoid inconsistent content and maintenance.

### Backend and business workflows

- The main service query uses the existing PDO helper and returns a bounded set of active services.
- Contact-form validation and database-failure handling were corrected in source; request-level persistence tests are still required.
- Database credentials are required from the runtime environment, and request-time schema setup was removed. Configuration/connection failures now propagate; verify logging and error handling in the deployed runtime.
- Confirm every booking endpoint continues to enforce authentication, role permissions, customer/vehicle ownership, and valid service IDs; the landing page is not an authorization boundary.

### Security

- Positive controls observed: prepared statements with native prepares, password hashing and verification, server-side role checks, session hardening/timeouts, generic password-reset responses, hashed reset tokens, and CSRF verification in the bootstrap.
- Customer accounts no longer receive staff dashboard, vehicle, or appointment permissions. A customer portal remains unimplemented; do not restore these grants without data ownership scoping.
- Production risk remains until environment configuration and least-privilege grants are tested on the target server.
- The strict CSP and remaining inline styles are inconsistent on several pages. Fix markup/CSS rather than weakening the policy.
- Treat audit logs as tamper-evident rather than immutable unless checkpoints are anchored independently.
- `public/setup.php` is locked after setup but still directs administrators to remove it. Remove the endpoint from deployed public directories.

### Database

- The schema uses InnoDB, foreign keys, unique constraints, and indexes for many key operational lookups. It also models services, appointments, work orders, inventory, invoices, payments, and audit records.
- `database/migrate.php` is a CLI-only forward migration runner with checksum tracking. Its SQL splitter and migrations have not been tested against a disposable MySQL/MariaDB database; the baseline import remains destructive and is only for a new empty database.
- Add operational verification for backup frequency, restore drills, retention, and audit-data growth; these cannot be confirmed from the source review.

### Testing and operations

- `tests/run_tests.php` describes integration coverage for login/rate limiting, permissions, CSRF, SQL injection, XSS, inventory, billing, and audit-chain behavior.
- The test runner writes test data to its configured database, so it was **not run**. PHP syntax checks for the latest changes are pending; no browser/database integration test was performed.
- No production hosting configuration, TLS setup, live database privileges, monitoring, or backup/restore execution was available for verification.

## Work completed with this review

- Enhanced the “Specialized Automotive Services” cards with catalog category, standard price, estimated time, and service-specific booking links.
- Added a five-image automatically advancing hero slideshow with reduced-motion support and controls on the optional marketing page.
- Added CSRF token age validation, hardened contact input and failure handling, removed the registration role fallback, and removed fabricated catalog content.
- Required explicit database environment configuration, removed runtime schema creation/import, added a CLI migration runner and separate database-account grants, and revised installation instructions.
- Replaced several inline style blocks/attributes with external CSS and CSP-compatible hero images. Site-wide CSP compatibility remains incomplete.
- PHP syntax validation passed for the changed PHP files, and `tests/security_hardening_unit.php` passed with `mbstring` and `openssl` explicitly enabled. Database, migration, browser/CSP, and request-level tests were not run.

## Suggested remediation order

1. Run isolated contact, registration, and migration tests against a disposable database; confirm the runtime grant script supports every application query.
2. Finish removing inline styles from all CSP-protected pages and verify pages in a browser.
3. Add public contact/password-reset rate limiting and operational monitoring.
4. Perform a database restore drill and define an external audit-checkpoint process.
