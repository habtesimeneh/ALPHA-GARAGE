# ALPHA GARAGE — RELEASE PACKAGE MANIFEST
## STAGING & PRODUCTION DEPLOYMENT PACKAGE SPECIFICATION

**Package Identifier:** `alpha-garage-v1.0.3-staging`  
**Build Date:** November 23, 2026  
**Build Engine:** `scripts/build_release_package.php`  
**Target Environment:** Ubuntu 24.04 LTS / Apache 2.4 / PHP 8.3-FPM / MySQL 8.0+  
**Archive Format:** Deterministic Gzipped Tarball (`.tar.gz`)  
**Package Size (Uncompressed):** ~2.06 MB (2,058,185 bytes)  
**Package Size (Compressed):** ~520 KB  
**Checksum Verification:** Delivered via external detached sidecar file `alpha-garage-v1.0.3-staging.sha256` alongside the release archive (avoids self-referential checksum paradox).  
**Reproducibility:** Bit-for-bit verified across independent builds with normalized timestamps (epoch `1700000000`).

---

## 1. PACKAGE CONTENTS INVENTORY

The release package contains exactly **208 production-ready files** structured strictly for zero-trust deployment:

| Directory Root | Files Included | Purpose & Detailed Inventory Classification |
| :--- | :---: | :--- |
| **`app/`** | 42 | Core business logic: Models (`Database.php`), Domain Services (AuthService, PaymentService, etc.), Middleware (`auth.php`, `permissions.php`), Helpers (`functions.php`), Localization dictionaries (`lang/en.php`, `lang/am.php`), Configuration templates (`app.php`, `database.php`), and Role Dashboard Views. `DemoSeeder.php` strictly omitted. |
| **`database/`** | 21 | Complete database definitions classified as:<br>• **15 Migration Scripts:** `20261108_expense_ledger.sql`, `20261109_job_card_priority.sql`, `20261113_payment_idempotency.sql`, `20261117_customer_identity_verification.sql`, `20261121_create_videos_table.sql`, `20261122_manual_payment_system.sql`, `20261122_payment_system_enhancements.sql`, `20261122_priority3_performance_indexes.sql`, `add_remember_token_to_users.sql`, `alpha_garage_brand.sql`, `contact_inquiries.sql`, `enterprise_upgrade.sql`, `password_resets.sql`, `revoke_customer_staff_access.sql`, `widen_login_attempt_username.sql`.<br>• **1 Base Schema:** `database.sql`<br>• **1 Security SQL:** `least_privilege.sql`<br>• **1 Migration Runner:** `migrate.php`<br>• **2 Database Utilities:** `recover_admin.php`, `verify_auth_database.php`<br>• **1 Directory Protection:** `.htaccess` |
| **`public/`** | 113 | Webroot assets: Application controller entrypoints, compiled CSS styling (`assets/css/`), modular JavaScript UI drivers (`assets/js/`), media streamers, and Apache `.htaccess`. |
| **`scripts/`** | 3 | Administrative CLI utilities: `create_admin.php` (zero-trust master administrator initialization), `backup_database.sh` (POSIX automated backup runner), and `backup_crypto.php` (fail-closed AES-256-GCM authenticated encryption engine). |
| **`docs/`** | 5 | Operator deployment runbooks and checklists (`docs/deployment/`):<br>1. `UBUNTU_24_04_DEPLOYMENT_RUNBOOK.md`<br>2. `BACKUP_AND_DISASTER_RECOVERY.md`<br>3. `PRODUCTION_RELEASE_CHECKLIST.md`<br>4. `ENVIRONMENT_CONFIGURATION.md`<br>5. `RELEASE_PACKAGE_MANIFEST.md`<br>*(Internal security assessments and phase reports strictly excluded per FIND-20-03).* |
| **`documentation/`** | 6 | Clean end-user and system architecture manuals:<br>1. `INSTALLATION_GUIDE.md`<br>2. `USER_MANUAL.md`<br>3. `SYSTEM_DESIGN.md`<br>4. `DATABASE_DESIGN.md`<br>5. `SECURITY_DESIGN.md`<br>6. `DATA_DICTIONARY.md`<br>*(Student assignment sheets and report presentations strictly excluded).* |
| **`storage/` (Skeleton)** | 14 | Pre-configured runtime storage directories with strict root and per-directory `.htaccess` denial:<br>• Root `storage/.htaccess` (1 file)<br>• `logs/` (`.gitkeep` + `.htaccess` = 2)<br>• `uploads/` (`.gitkeep` + `.htaccess` = 2)<br>• `private/` (`.htaccess` only, no `.gitkeep` for secrets protection = 1)<br>• `sessions/` (`.gitkeep` + `.htaccess` = 2)<br>• `backups/` (`.gitkeep` + `.htaccess` = 2)<br>• `mail_spool/` (`.gitkeep` + `.htaccess` = 2)<br>• `rate_limits/` (`.gitkeep` + `.htaccess` = 2)<br>*(Total: 14 files; clarifies FIND-20-08).* |
| **Root Configurations** | 4 | `.env.example`, `README.md`, root `.htaccess`, and fallback `index.php`. |

**Total Packaged Files:** 208

---

## 2. EXCLUSION POLICY AUDIT (FIND-20-03)

The following files and folders were explicitly audited and omitted from the production release:

1. **Internal Security & Vulnerability Audits (`docs/security/`)**:
   - `docs/security/VULNERABILITY_ASSESSMENT_REPORT.md`
   - `docs/security/SECURITY_HARDENING_STANDARDS.md`
2. **Production Readiness Phase Audits (`docs/production-readiness/`)**:
   - `docs/production-readiness/STAGING_DEPLOYMENT_AND_REHEARSAL_REPORT.md`
   - `docs/production-readiness/PRODUCTION_READINESS_AUDIT.md`
   - `docs/production-readiness/COMPREHENSIVE_DISASTER_RECOVERY_RUNBOOK.md`
3. **Internal Priority Reports & Phase Documentation (`docs/phases/`, `docs/phase*`, `docs/payment-management/`)**:
   - `docs/payment-management/PRIORITY_12_FINAL_COMPLETION_REPORT.md`
   - `docs/phases/PRIORITY_18_INDEPENDENT_AUDIT_REPORT.md`
   - `docs/phases/PRIORITY_19_COMPLETION_REPORT.md`
   - `docs/phases/PRIORITY_20_INDEPENDENT_RELEASE_CANDIDATE_VERIFICATION_AUDIT.md`
   - `docs/phases/PRIORITY_21_RELEASE_CANDIDATE_REMEDIATION_AND_PACKAGE_ISOLATED_REHEARSAL.md`
   - `docs/phases/PRIORITY_22_INDEPENDENT_RELEASE_CANDIDATE_VERIFICATION_AUDIT.md`
4. **Academic Coursework & Submissions (`documentation/`)**:
   - `documentation/ASSIGNMENT_REQUIREMENTS.md`
   - `documentation/ALPHA_GARAGE_COMPLETE_DOCUMENTATION.md`
   - `documentation/PROJECT_PORTFOLIO_PRESENTATION.md`
   - `documentation/SUBMISSION_CHECKLIST.md`
   - `documentation/FINAL_SUBMISSION_PORTFOLIO.md`
5. **Development Test Suites (`tests/`)**:
   - All unit, integration, and browser screenshot test suites.
6. **Machine Secrets & Environment Overrides**:
   - `app/config/local.php`
   - `storage/private/encryption.key`
   - `.env` files.
7. **Demo Seeding Services**:
   - `app/services/DemoSeeder.php`

---

## 3. SECURITY & CREDENTIAL SCAN RESULTS

A multi-pattern static analysis pass was executed across all packaged files prior to archive generation:

- **Hardcoded Passwords & Secrets**: 0 Detected.
- **In-Browser Demo Buttons & Test Helpers**: 0 Detected.
- **Arbitrary Web Setup Scripts (`setup.php`)**: 0 Detected.
- **Webroot Exposure of Internals (`app/`, `storage/`, `database/`)**: Prevented by Apache `.htaccess` deny rules.

---

## 4. ARCHIVE VERIFICATION & EXTRACTION PROCEDURE

To verify the integrity of the downloaded archive before extraction:

```bash
# Verify checksum using the detached external sidecar file:
sha256sum -c alpha-garage-v1.0.3-staging.sha256
```

Expected output:
```text
alpha-garage-v1.0.3-staging.tar.gz: OK
```

Extraction:
```bash
tar -xzf alpha-garage-v1.0.3-staging.tar.gz -C /var/www/alpha-garage/releases/
```
