# ALPHA GARAGE — PRODUCTION RELEASE CHECKLIST
## OPERATOR PRE-FLIGHT AND POST-DEPLOYMENT VERIFICATION GATE

**Application:** ALPHA GARAGE (`AutoPro_Garage-main`)  
**Release Target:** Ubuntu 24.04 LTS VPS / Isolated Live Staging  
**Version:** `v1.0.0-staging`

---

## 1. PRE-RELEASE PACKAGING GATE (OFF-SERVER)

- [ ] **Working Tree Cleanliness**: `git status` verifies no uncommitted code changes or rogue files.
- [ ] **FIND-16-01 Remediated**: `storage/backups/` and `storage/staging/` excluded in `AutoPro_Garage-main/.gitignore`.
- [ ] **Credential Eradication**: Zero hardcoded passwords present in source code or documentation.
- [ ] **No In-Browser Demo Buttons**: Zero quick login or automated credential population handlers exist.
- [ ] **Dead Code Cleared**: `auth.css`, legacy prototypes, and dev text artifacts completely eliminated.
- [ ] **All Test Suites Passing**:
  - [ ] Manual Payment System: 100/100 PASSED (100%)
  - [ ] Customer Booking: 44/44 PASSED (100%)
  - [ ] Priority 10 UI/UX: 44/44 PASSED (100%)
  - [ ] Scroll Animations: 142/142 PASSED (100%)
  - [ ] Security Hardening Unit: PASSED (100%)
  - [ ] Inventory Double-Entry Unit: PASSED (100%)
  - [ ] Auth NIST Password Unit: PASSED (100%)
- [ ] **Package Build**: `scripts/build_release_package.php` completed with 0 errors.
- [ ] **Archive Checksum**: SHA-256 matches `alpha-garage-v1.0.1-staging.sha256`.

---

## 2. SERVER INFRASTRUCTURE GATE (LINUX VPS)

- [ ] **Target OS**: Ubuntu 24.04 LTS LTS verified (`lsb_release -a`).
- [ ] **Web Server**: Apache 2.4 + PHP 8.3-FPM (`mpm_event` + `proxy_fcgi`) running.
- [ ] **Required PHP Modules**: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `gd`, `fileinfo`, `zip`, `xml`, `intl`.
- [ ] **Database Engine**: MySQL 8.0+ or MariaDB 10.11+ running with `utf8mb4_unicode_ci`.
- [ ] **Firewall (UFW)**: Only ports 22, 80, and 443 open.
- [ ] **Timezone**: Set to `Africa/Addis_Ababa` (UTC+03:00).
- [ ] **Filesystem Permissions**: Non-root ownership `www-data:www-data`, directories 755/750, files 644/640, storage 770.

---

## 3. DEPLOYMENT & MIGRATION GATE

- [ ] **DocumentRoot Isolation**: Apache virtual host DocumentRoot points strictly to `.../public`.
- [ ] **Internal Access Block**: Direct HTTP requests to `/.env`, `/storage/`, and `/app/` return HTTP 403 Forbidden.
- [ ] **Base Schema**: `database/database.sql` imported cleanly.
- [ ] **Forward Migrations**: `php database/migrate.php` applied all 17 migrations; recorded in `schema_migrations`.
- [ ] **Least Privilege Grants**: `database/least_privilege.sql` applied. Dual DB user separation enforced (`garage_app` vs `garage_migrator`).
- [ ] **Initial Administrator Provisioning**: Executed `php scripts/create_admin.php` via CLI; verified NIST-compliant admin account.
- [ ] **Automated Backups**: Cron job installed for daily `scripts/backup_database.sh`.

---

## 4. FUNCTIONAL SMOKE TESTING GATE (POST-DEPLOYMENT)

- [ ] **HTTPS Enforcement**: HTTP requests redirect to HTTPS 301.
- [ ] **Admin Authentication**: Master Administrator logs in; redirected to Admin Dashboard.
- [ ] **Customer Workflow**: Customer registration, vehicle entry, and appointment booking work cleanly.
- [ ] **Payment Verification**: Manual Ethiopian payment submission (CBE/Telebirr), receipt streaming, staff verification, and balance settlement succeed.
- [ ] **Audit Trail Immutability**: `audit_logs` record hash-chained entries without trigger errors.
- [ ] **Bilingual Display**: Switching between English and Amharic renders correctly across dashboards and payment forms.
