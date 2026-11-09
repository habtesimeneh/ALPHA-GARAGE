# System Architecture & Design Documentation

**Project:** Garage Management System with Secure Data Storage and Auditing  
**Platform:** MAMP for Windows (Apache, MySQL/MariaDB, PHP 8.x)  
**Security Baseline:** OWASP ASVS Level 2 compliance for authentication, session handling, access control, and auditing.

---

## 1. High-Level Architectural Model

The application follows a clean **Three-Tier Modular Architecture** separating Presentation, Business Logic Services, and Data Persistence without relying on bloated external dependencies.

```
+-----------------------------------------------------------------------+
|                         PRESENTATION LAYER                            |
|  - HTML5 Semantic Structure                                           |
|  - Modern Automotive Enterprise CSS3 Design System                    |
|    (Deep Navy #111C2E, Electric Blue #2563EB, Cyan #06B6D4)           |
|  - Vanilla JavaScript ES6+ (Fetch API, DOM Filters, Toast, Modals)    |
|  - Server-Side PHP Views & Layout Components                          |
+-----------------------------------------------------------------------+
                                   |  (HTTP Requests / Strict CSP / CSRF)
                                   v
+-----------------------------------------------------------------------+
|                        APPLICATION / LOGIC LAYER                      |
|  - Bootstrap & Security Headers Middleware                            |
|  - Centralized Session & Authentication Gatekeeper (AuthService)      |
|  - Role-Based Access Control & IDOR Guard (Permissions Middleware)    |
|  - Cryptographic Service (AES-256-GCM Authenticated Encryption)       |
|  - Business Services:                                                 |
|    * JobService (Work Order Lifecycle & Transition State Machine)     |
|    * CatalogService (Garage Services & Checklist Management)          |
|    * AppointmentService (Scheduling & 1-Click Conversion)             |
|    * InventoryService (Stock Ledger, Row-Locked Issuing)              |
|    * SupplierService (Vendor Management & Purchase Tracking)          |
|    * InvoiceService (Financial Calculations, VAT, Cent Precision)     |
|    * PaymentService (Immutable Receipts & Reasoned Refunds)           |
|    * NotificationService (In-App Alert Center & Role Broadcasting)    |
|    * AuditLogger (Cryptographic SHA-256 Hash Chaining)                |
+-----------------------------------------------------------------------+
                                   |  (PDO Prepared Statements / Strict SQL)
                                   v
+-----------------------------------------------------------------------+
|                          DATA PERSISTENCE LAYER                       |
|  - MySQL 8.x / MariaDB Engine (InnoDB utf8mb4_unicode_ci)             |
|  - Relational Schema Normalized to 3NF                                |
|  - Foreign Key Constraints & Check Constraints                        |
|  - Append-Only Database Triggers (trg_audit_no_update/delete)         |
|  - Concurrency Row Locking (SELECT ... FOR UPDATE)                    |
|  - Secure File Storage outside web root (storage/private/)            |
+-----------------------------------------------------------------------+
```

---

## 2. Core Business Workflows

### 2.1 Customer Check-in & Appointment Conversion
1. Customer brings vehicle or books ahead via Appointment module (`appointments/form.php`).
2. Receptionist selects or registers the Customer and Vehicle.
3. If an appointment exists, clicking **"Convert to Job Card"** automatically instantiates the Work Order prefilled with vehicle odometer, initial conditions, and requested catalog services.
4. The system issues a unique, sequential Job Card number (`JC-YYYY-XXXXX`).

### 2.2 Workshop Repair & Mechanic Assignment
1. Workshop supervisor or receptionist assigns a qualified technician (`mechanics` table).
2. The technician inspects the vehicle and records diagnosis and required repairs.
3. Standard services from the **Services Catalog** (e.g. Brake Replacement, Diagnostic Scan) are attached to the job checklist.
4. Technician requests spare parts from the warehouse directly through the Job Card view.
5. In-app notifications immediately alert the inventory manager of pending requests.

### 2.3 Spare Parts Issuance & Double-Entry Ledger
1. Inventory manager reviews the part request.
2. The transaction acquires an exclusive row-level lock (`SELECT ... FOR UPDATE`) on `spare_parts`.
3. If quantity is sufficient:
   - Stock is decremented.
   - A row is written to `job_parts`.
   - A permanent record is inserted into `inventory_transactions` with `balance_after`.
   - The part request status transitions to `issued`.
   - An audit record is chained in `audit_logs`.
4. Over-issuance and negative stock are physically impossible.

### 2.4 Completion, Billing & Invoicing
1. Technician records final labor hours and updates status to `Completed`.
2. Receptionist or accountant clicks **"Generate Invoice"**.
3. `InvoiceService` pulls attached catalog services, labor charges, and spare parts.
4. Calculations are computed in integer cents (e.g. `$labor + $parts - $discount + $tax`).
5. An invoice number (`INV-YYYY-XXXXX`) is generated and linked 1-to-1 with the job card.
6. The work order transitions to `Delivered` once payment is collected.

### 2.5 Payments & Refund Integrity
1. Customer pays via Cash, Bank Transfer, Telebirr, Chapa, or Card.
2. Each payment writes an immutable record to `payments` with a unique receipt number (`RCT-YYYY-XXXXX`).
3. Over-payments are rejected.
4. If an error occurred, corrections are executed via an authorized refund (`RFD-YYYY-XXXXX`) with a mandatory audit reason.

---

## 3. Modular File Structure

```text
garage-management-systems/
├── app/
│   ├── config/
│   │   ├── app.php                # App settings, timezone, security parameters
│   │   ├── database.php           # PDO connection parameters with env overrides
│   │   └── local.php.example      # Local machine configuration template
│   ├── helpers/
│   │   └── functions.php          # Escaping, URL routing, flash, CSRF, money, validation
│   ├── middleware/
│   │   ├── auth.php               # Session validation, inactivity expiration
│   │   └── permissions.php        # Server-side RBAC and authorization gates
│   ├── models/
│   │   ├── Database.php           # PDO singleton, prepared runners, transaction manager
│   │   └── Setting.php            # Garage settings cache
│   ├── services/
│   │   ├── AppointmentService.php # Booking and 1-click work order conversion
│   │   ├── AuditLogger.php        # Append-only SHA-256 hash-chain logger
│   │   ├── AuthService.php        # Bcrypt auth, rate-limiting, timing protection
│   │   ├── CatalogService.php     # Service catalog & job checklist management
│   │   ├── CryptoService.php      # AES-256-GCM authenticated encryption service
│   │   ├── DemoSeeder.php         # Realistic seed data generator
│   │   ├── InventoryService.php   # Stock ledger, row-locked issue/adjust/purchase
│   │   ├── InvoiceService.php     # Financial billing, tax, integer-cent calculations
│   │   ├── JobService.php         # Work order state machine and assignment
│   │   ├── NotificationService.php# In-app notification center & role broadcasts
│   │   ├── Numbering.php          # Sequential alphanumeric business reference numbers
│   │   ├── PaymentService.php     # Immutable payments & authorized refunds
│   │   ├── ReportService.php      # 12 real-time analytical operational reports
│   │   └── SupplierService.php    # Vendor catalog and purchasing records
│   ├── views/
│   │   └── layout.php             # Unified shell, sidebar, topbar, modals, inputs
│   └── bootstrap.php              # Global initialization, headers, session, CSRF
│
├── database/
│   ├── database.sql               # Complete unified schema, constraints, triggers, seeds
│   ├── least_privilege.sql        # Restricted MySQL user grant script
│   └── migrations/
│       └── enterprise_upgrade.sql # Schema migration adding services, suppliers, appointments
│
├── public/
│   ├── assets/                    # Static CSS, JS, SVG assets (strictly same-origin)
│   ├── appointments/              # Appointment booking, filters, conversion
│   ├── audit-logs/                # Audit log viewer, filters, chain verifier, CSV
│   ├── customers/                 # Customer profiles, vehicles, statements
│   ├── inventory/                 # Stock catalog, issue, ledger history, adjustments
│   ├── invoices/                  # Invoice viewer, printable invoice, item breakdowns
│   ├── job-cards/                 # Work order management, checklist, diagnosis, timeline
│   ├── mechanics/                 # Technician profiles, workload, availability
│   ├── notifications/             # Notification center and mark read handlers
│   ├── payments/                  # Payment recording, receipts, refunds
│   ├── reports/                   # Interactive reports, print, CSV export
│   ├── services/                  # Garage services catalog & pricing CRUD
│   ├── suppliers/                 # Parts suppliers directory, profiles, purchases
│   ├── users/                     # User administration, RBAC permissions, settings
│   ├── dashboard.php              # Role-tailored metrics, charts, upcoming visits
│   ├── login.php                  # Split-screen responsive login with toggle
│   ├── logout.php                 # CSRF-protected session termination
│   ├── profile.php                # User self-service profile and password change
│   └── setup.php                  # Safe first-time administrator installer
│
├── storage/
│   ├── logs/                      # Application error log (php-error.log, app.log)
│   └── private/                   # Master encryption keys, inaccessible to web (.htaccess)
│
├── tests/
│   └── run_tests.php              # 10-section automated security & functional test suite
├── .env.example
├── README.md
└── index.php                      # Safe root redirect to public/
```

