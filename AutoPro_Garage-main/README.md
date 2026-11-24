# AutoPro Enterprise Garage Management System
## With Secure Data Storage and Forensic Auditing

[![PHP Version](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB%20(InnoDB)-00758F?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Environment](https://img.shields.io/badge/Platform-MAMP%20for%20Windows-0099E5?logo=windows&logoColor=white)](https://www.mamp.info/)
[![Security Standard](https://img.shields.io/badge/Security-OWASP%20Top%2010%20%7C%20AES--256--GCM-success)](documentation/SECURITY_DESIGN.md)
[![Audit Standard](https://img.shields.io/badge/Audit-SHA--256%20Merkle%20Hash%20Chain-blue)](documentation/AUDIT_DESIGN.md)

An enterprise-grade, role-based garage management platform engineered for **Group 1 ("Garage Management System with Secure Data Storage and Auditing")**. Built entirely with native, zero-dependency technologies: **PHP 8.x (native PDO)**, **MySQL / MariaDB (InnoDB utf8mb4)**, **HTML5**, **CSS3**, and **Vanilla JavaScript (ES6+)**.

---

## 1. System Highlights & Enterprise Capabilities

- **Automotive Enterprise UI/UX:** Role-adaptive, responsive operations dashboard with deep-green and warm-gold accents, accessible data visualizations, responsive tables, and printable document views.
- **Dashboard analytics:** Filter real appointment, job, payment, customer, service, and inventory data by date range; compare periods; and review SVG trends, status breakdowns, mechanic/service rankings, invoice activity, and operational alerts. A cash-basis financial chart compares payments, recorded operating expenses, and stock purchases; unrecorded costs are not estimated.
- **Expense tracking:** Accountants and administrators can record operating costs with date, category, amount, and reference. Expense entries are append-only and audit-logged; stock purchases are included automatically from the inventory ledger to avoid duplicate entry.
- **Forensic Tamper-Evident Audit Trail:** Every sensitive state mutation is recorded in `audit_logs` with a forward SHA-256 cryptographic hash chain, microsecond timestamps, client IP, and automated redaction of passwords and session tokens. Database triggers block `UPDATE` and `DELETE` at the engine level.
- **Authenticated Cryptographic Storage (`CryptoService.php`):** Server-side AES-256-GCM symmetric encryption with 96-bit random IVs and 128-bit authentication tags, paired with HMAC-SHA256 blind indexing for fast exact-match searches over encrypted data.
- **Front Office & Appointments:** Pre-booked customer service requests with status scheduling and 1-click conversion into active workshop job cards.
- **Fleet Asset Tracking:** Complete client vehicle profiles, VIN validation, license plate uniqueness checks, mileage tracking, fuel types, and vehicle types (Sedan, SUV, Truck, Van, Motorcycle, Commercial).
- **Digital Job Cards & Workshop Flow:** Full repair lifecycle (Pending → Diagnosing → Waiting for Parts → In Progress → Completed → Delivered), technician assignment, checklist tracking, and automatic parts return on cancellation.
- **Master Service Catalog:** Standard labor packages, diagnostic procedures, base pricing, and estimated labor hours attached to job cards.
- **Inventory & Supplier Management:** Parts catalog with SKU, reorder thresholds, low-stock notifications, double-entry stock ledger, procurement stock-in, and atomic multi-part issue to job cards.
- **Financial Billing & Customer Statements:** Multi-line itemized commercial invoices (labor + service checklist + issued parts), configurable 15% VAT, authorized discount calculations, partial payment receipts, and fleet client account statements.
- **In-App Notification Center:** Real-time bell dropdown with unread badges, broadcast operational notices, and low-stock alerts.

---

## 2. Technology Stack & Zero-Framework Constraint

In strict compliance with assignment instructions, **no external frameworks or third-party packages are used**:

| Layer | Implementation | Notes |
|---|---|---|
| **Backend** | Native PHP 8.1 / 8.2 / 8.3 | Zero Laravel / Symfony / CodeIgniter / ORMs |
| **Database** | MySQL 5.7+ / MariaDB 10.x | Native PDO, strict InnoDB, 28 tables, triggers |
| **Frontend** | Semantic HTML5 & CSS3 | Zero Bootstrap / Tailwind / UI frameworks |
| **Scripting** | Vanilla JavaScript (ES6+) | Zero jQuery / React / Vue / Node.js |
| **Security** | PHP OpenSSL & Native Hash | AES-256-GCM, Bcrypt, HMAC-SHA256 |
| **Runtime** | MAMP for Windows | Apache 2.4, MySQL on Port 3306 |

---

## 3. Quick Start on Windows MAMP (5 Minutes)

### Step 1: Copy Codebase to MAMP
Clone or copy the project folder into your MAMP web root:
```
C:\MAMP\htdocs\ALPHA-GARAGE\AutoPro_Garage-main\
```

### Step 2: Start MAMP Servers
Launch MAMP for Windows and start Apache and MySQL (both status indicators green). Ensure MySQL is running on port **3306**.

### Step 3: Import Database Schema
1. Open phpMyAdmin at `http://localhost/phpmyadmin/`.
2. Create database `garage_management_system` with collation `utf8mb4_unicode_ci`.
3. Import `database/database.sql` into the new, empty database. **This is a baseline schema script; never import it over an existing database containing data.**

### Step 4: Configure Database Access
The application does not load `.env.example` automatically. For local setup, copy `app/config/local.php.example` to the git-ignored `app/config/local.php` and set the database connection there, or configure `GMS_DB_HOST`, `GMS_DB_PORT`, `GMS_DB_NAME`, `GMS_DB_USER`, and `GMS_DB_PASS` in the web server environment. Environment variables take precedence. Do not use the MySQL root account for web requests.

After importing the baseline, create separate runtime and migration accounts using `database/least_privilege.sql`. Replace its password placeholders with strong, unique secrets before executing it as DBA. Configure `GMS_MIGRATION_DB_USER` and `GMS_MIGRATION_DB_PASS` only in the administrative CLI environment; never expose them to the web runtime.

Run forward-only schema changes from the project directory:
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" "database\migrate.php"
```

### Step 5: Launch the Application
Open your browser and navigate to:
```
http://localhost/ALPHA-GARAGE/
```
(Or directly: `http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/login.php`)

---

## 4. Secure Initial Administrator Provisioning

In accordance with strict security policies, **no default or hardcoded credentials are baked into production or staging environments**.

To provision the initial administrator account, execute the authorized CLI initialization command:
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" "scripts\provision_test_db.php"
```
Or execute a dedicated administrator creation script providing:
- **Administrator Username** (alphanumeric, min 4 characters)
- **Administrator Email** (valid corporate email)
- **Strong Master Password** (minimum 10 characters, mixed case, numbers, special characters)
- **Secure Bcrypt Hashing** (`PASSWORD_BCRYPT` with cost factor 12)

All subsequent users (Managers, Receptionists, Mechanics, Inventory Managers, Accountants) are created and managed by the authorized Administrator via the **Staff Management Console** (`public/users/index.php`). Self-service customer registration is available at `public/register.php`.

---

## 5. Automated Test Suite

An automated regression and security test suite (`tests/run_tests.php`) executes 85+ assertions:
- Database connectivity & schema integrity
- Password hashing & NIST complexity enforcement
- Account lockout & IP rate-limiting
- CSRF token validation & session hardening
- Granular RBAC permissions & mechanic IDOR isolation
- SQL injection immunity & XSS output escaping
- Customer/vehicle duplicate plate & VIN prevention
- Job card state machine & cancelled stock return
- Double-entry inventory ledger & negative stock prevention
- Billing math (labor + parts + services), 15% VAT, and refunds
- Database trigger immutability & SHA-256 hash chain tamper detection
- AES-256-GCM encryption roundtrip & ciphertext tampering resistance

To execute the test suite:
```powershell
& "C:\MAMP\bin\php\php8.3.1\php.exe" "tests\run_tests.php"
```
> **Warning:** This integration suite writes test data to its configured database. Run it only against a disposable test database, never production.

---

## 6. Comprehensive Project Documentation

The project includes an exhaustive documentation library located in `documentation/`:

| Document | Description |
|---|---|
| [**ASSIGNMENT_REQUIREMENTS.md**](documentation/ASSIGNMENT_REQUIREMENTS.md) | Requirement-to-feature mapping matrix across all 20 specification areas |
| [**SYSTEM_DESIGN.md**](documentation/SYSTEM_DESIGN.md) | High-level 3-tier architecture, component models, and operational workflows |
| [**DATABASE_DESIGN.md**](documentation/DATABASE_DESIGN.md) | Relational database schema, normalization principles (1NF-3NF), and Mermaid ERD |
| [**DATA_DICTIONARY.md**](documentation/DATA_DICTIONARY.md) | Table-by-table reference for the database tables, column types, nullability, and constraints |
| [**SECURITY_DESIGN.md**](documentation/SECURITY_DESIGN.md) | Defense-in-depth architecture, AES-256-GCM encryption, blind indexing, and OWASP Top 10 |
| [**AUDIT_DESIGN.md**](documentation/AUDIT_DESIGN.md) | Cryptographic SHA-256 hash chaining, database trigger immutability, and tamper detection |
| [**USER_MANUAL.md**](documentation/USER_MANUAL.md) | Role-by-role operational guide with step-by-step procedures for all staff |
| [**INSTALLATION_GUIDE.md**](documentation/INSTALLATION_GUIDE.md) | Complete local MAMP Windows installation, configuration, troubleshooting, and backup |
| [**TESTING_REPORT.md**](documentation/TESTING_REPORT.md) | Quality assurance test report, assertion matrix, penetration test findings, and sign-off |

---

## 7. Project Directory Structure

```text
garage-management-systems/
├── app/                                 # Protected Application Core (Non-web-accessible)
│   ├── bootstrap.php                    # Application initialization, security headers, CSRF
│   ├── config/                          # Configuration files (database and app)
│   ├── models/                          # PDO Database wrapper and Setting models
│   ├── services/                        # Business logic services (Auth, Job, Inventory, Invoice, etc.)
│   ├── middleware/                      # Authentication & RBAC permission guards
│   ├── helpers/                         # Validation, escaping, formatting, and pagination helpers
│   └── views/                           # Layout chrome, UI components, and navigation
├── database/                            # Database Schemas & Migrations
│   ├── database.sql                     # Full schema (28 tables, triggers, RBAC seed, catalog)
│   └── migrations/                      # Enterprise upgrade migration scripts
├── documentation/                       # Comprehensive System Documentation
│   ├── ASSIGNMENT_REQUIREMENTS.md
│   ├── SYSTEM_DESIGN.md
│   ├── DATABASE_DESIGN.md
│   ├── DATA_DICTIONARY.md
│   ├── SECURITY_DESIGN.md
│   ├── AUDIT_DESIGN.md
│   ├── USER_MANUAL.md
│   ├── INSTALLATION_GUIDE.md
│   ├── WEBSITE_AUDIT_REPORT.md
│   ├── IMPLEMENTATION_REPORT.md
│   └── TESTING_REPORT.md
├── public/                              # Public Web Root (Directly served by Apache)
│   ├── assets/                          # CSS design system, Vanilla JS, and SVG icons
│   ├── appointments/                    # Service appointments and job conversion
│   ├── audit/                           # Forensic audit explorer and chain verification
│   ├── customers/                       # Customer management and account statements
│   ├── inventory/                       # Spare parts catalog and stock movements
│   ├── invoices/                        # Commercial billing invoices and print views
│   ├── job-cards/                       # Workshop repair job cards and checklist
│   ├── mechanics/                       # Mechanic directory and availability
│   ├── notifications/                   # In-app notification center and read handlers
│   ├── payments/                        # Payment receipts and refund processing
│   ├── reports/                         # Operational and financial reporting
│   ├── services/                        # Master garage services catalog
│   ├── suppliers/                       # Parts vendors directory
│   ├── users/                           # Staff user administration and RBAC roles
│   ├── vehicles/                        # Fleet asset management
│   ├── dashboard.php                    # Role-adaptive executive dashboard
│   ├── login.php                        # Hardened login endpoint with rate limiting
│   └── setup.php                        # Locked after setup; remove from deployed public directories
├── storage/                             # Application Storage
│   ├── private/                         # Protected encryption keys (.htaccess protected)
│   └── logs/                            # Application and PHP error logs
└── tests/                               # Automated QA & Security Verification Suite
    └── run_tests.php                    # End-to-end integration test runner
```

---
*AutoPro Garage Management System — Group 1 Final Submission.*
