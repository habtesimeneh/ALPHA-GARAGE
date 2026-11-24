# ALPHA GARAGE — ENTERPRISE WORKSHOP MANAGEMENT SYSTEM
## With Forensic Auditing, Cryptographic Storage, and Bilingual Localization

[![PHP Version](https://img.shields.io/badge/PHP-8.1%20%7C%208.2%20%7C%208.3-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL%20%7C%20MariaDB%20(InnoDB)-00758F?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Platform](https://img.shields.io/badge/Platform-MAMP%20for%20Windows-0099E5?logo=windows&logoColor=white)](https://www.mamp.info/)
[![Security](https://img.shields.io/badge/Security-OWASP%20Top%2010%20%7C%20AES--256--GCM-success)](AutoPro_Garage-main/documentation/SECURITY_DESIGN.md)
[![Audit](https://img.shields.io/badge/Audit-SHA--256%20Merkle%20Hash%20Chain-blue)](AutoPro_Garage-main/documentation/AUDIT_DESIGN.md)
[![i18n](https://img.shields.io/badge/Localization-English%20%7C%20አማርኛ%20(Amharic)-gold)](AutoPro_Garage-main/docs/phase-1.13/PHASE_1_13_COMPLETION_REPORT.md)

---

## 1. Overview & Architecture

**ALPHA GARAGE** is an enterprise-grade automotive workshop and fleet service platform built with native, zero-dependency technologies (**PHP 8.x PDO**, **MySQL/MariaDB InnoDB**, **Semantic HTML5**, **Modern CSS3**, and **Vanilla ES6+ JavaScript**).

### Canonical Application Location
The complete, canonical enterprise application resides under:
```
AutoPro_Garage-main/
├── app/                  (Core config, helpers, i18n, middleware, models, services, views)
├── database/             (Canonical 28-table schema, migrations, CLI recovery)
├── docs/                 (Phase 0 to Phase 1.14 forensic reports & architecture audits)
├── documentation/        (Data dictionary, system design, security specifications)
├── public/               (Web root: landing page, portal, module controllers, assets)
├── storage/              (Encrypted keys, secure uploads, audit logs, sessions)
└── tests/                (Over 390 automated unit, regression, and security assertions)
```

Root URL access (`http://localhost/ALPHA-GARAGE/`) immediately forwards to the canonical public application entry point (`AutoPro_Garage-main/public/`).

---

## 2. Quick Browser Access Links

When running Apache in MAMP (Default Port 80):

| Module / Destination | Direct Browser Link | Description |
| :--- | :--- | :--- |
| **Main Public Landing Page** | [http://localhost/ALPHA-GARAGE/](http://localhost/ALPHA-GARAGE/) | Dynamic luxury automotive showcase, service catalog & appointment booking |
| **Client Sign-In** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/login.php](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/login.php) | Secure authentication portal for clients and staff |
| **Customer Registration** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/register.php](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/register.php) | Self-service registration with verified identity linking |
| **Service Booking Portal** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/book.php) | Interactive vehicle booking workflow for clients |
| **VIP Customer Portal** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/customer.php](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/customer.php) | Dedicated vehicle owner dashboard (repairs, invoices, fleet) |
| **Staff Dashboard Dispatcher**| [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/dashboard.php](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/dashboard.php) | Role-adaptive operations dashboard for all 5 staff roles |
| **Video Showroom Gallery** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/videos.php](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/videos.php) | Video gallery with YouTube embeds and HTML5 streaming |
| **Job Cards Operations** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/job-cards/](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/job-cards/) | Workshop repair orders with 7-stage finite state machine |
| **Inventory & Spare Parts** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/inventory/](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/inventory/) | Double-entry stock ledger, reorder levels & procurement |
| **Billing & Invoices** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/invoices/](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/invoices/) | Itemized invoices, 15% VAT, discounts & balance tracking |
| **Forensic Audit Logs** | [http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/audit-logs/](http://localhost/ALPHA-GARAGE/AutoPro_Garage-main/public/audit-logs/) | Tamper-evident SHA-256 Merkle chain verification & CSV export |

---

## 3. Role-Based Dashboards & Staff Roles

The platform automatically routes authenticated users to their dedicated dashboard based on their database role:

| Role | Username | Permissions & Scope |
| :--- | :--- | :--- |
| **Administrator** | `admin` | Full workshop command center, cash-basis net profit, settings & audit logs |
| **Receptionist** | `reception` | Customer intake, vehicle registry, appointment scheduling & conversion |
| **Mechanic** | `mechanic1` | Workstation queue, assigned repair orders (horizontal IDOR isolation) |
| **Inventory Manager** | `stock` | Warehouse stock health, reorder alerts, parts issue (zero revenue disclosure) |
| **Accountant** | `accounts` | Accounts receivable aging brackets, outstanding invoices & expense tracking |
| **Customer VIP** | Self-registered | Vehicle fleet registry, live repair progress, billing history & booking |

---

## 4. Key Enterprise Capabilities

- **Tamper-Evident Audit Trail**: Every sensitive state change is recorded in `audit_logs` with a forward SHA-256 Merkle cryptographic hash chain. MySQL database triggers block `UPDATE` and `DELETE` at the engine level.
- **Payment Idempotency Engine**: Enforces database-level uniqueness on idempotency keys (`PaymentIdempotencyService.php`), eliminating duplicate charges from rapid double-clicks or retries.
- **Authenticated Encryption (`CryptoService.php`)**: Server-side AES-256-GCM symmetric encryption with 96-bit random IVs and 128-bit authentication tags, paired with keyed blind indexing (HMAC-SHA-256) for searching.
- **Bilingual Localization (English & Amharic)**: Complete localization engine (`app/helpers/i18n.php`, `app/lang/am.php`) with 329+ canonical phrases, instant DOM translation bridge, and Ethiopic typography support.
- **Zero Framework Constraint**: Native PHP 8.x with strict PDO prepared statements (`PDO::ATTR_EMULATE_PREPARES => false`), zero external PHP packages, and zero third-party UI libraries.

---

## 5. Security & Verification Documentation

- **Priority 1 Remediation Report**: [`docs/security/PRIORITY_1_SECURITY_REMEDIATION_REPORT.md`](docs/security/PRIORITY_1_SECURITY_REMEDIATION_REPORT.md)
- **Priority 2 Architecture Report**: [`docs/architecture/PRIORITY_2_ARCHITECTURE_CONSOLIDATION_REPORT.md`](docs/architecture/PRIORITY_2_ARCHITECTURE_CONSOLIDATION_REPORT.md)
- **Security Specifications**: [`AutoPro_Garage-main/documentation/SECURITY_DESIGN.md`](AutoPro_Garage-main/documentation/SECURITY_DESIGN.md)
- **Database Architecture**: [`AutoPro_Garage-main/documentation/DATABASE_DESIGN.md`](AutoPro_Garage-main/documentation/DATABASE_DESIGN.md)
- **User Operations Manual**: [`AutoPro_Garage-main/documentation/USER_MANUAL.md`](AutoPro_Garage-main/documentation/USER_MANUAL.md)
