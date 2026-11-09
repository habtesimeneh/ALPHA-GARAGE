# Assignment Requirements & Requirement-to-Feature Mapping

**Project Title:** Garage Management System with Secure Data Storage and Auditing  
**Architecture:** Native PHP 8.x, MySQL/MariaDB (InnoDB), HTML5, CSS3, Vanilla JavaScript (ES6+), MAMP on Windows  
**Framework Restrictions:** Zero external frameworks (No Laravel, Symfony, React, Vue, jQuery, Bootstrap, or ORM)

---

## 1. Executive Summary & Group 1 Scope

The system is an enterprise-grade automotive workshop management software built specifically to support the end-to-end operational, financial, and inventory workflows of modern vehicle service facilities. In addition to full operational CRUD, the platform incorporates advanced relational database integrity, authenticated AES-256-GCM server-side encryption for sensitive records, and a cryptographic SHA-256 hash-chained append-only audit trail protected at both application and database engine levels.

---

## 2. Requirement-to-Feature Mapping

| # | Master Requirement Area | Specified Requirement | Implemented Architecture / Feature | Status |
|---|-------------------------|-----------------------|------------------------------------|--------|
| **1** | **System Architecture** | Native PHP 8.x, PDO, MySQL, No frameworks | Pure PHP 8 native OOP services & helpers, strict typing (`declare(strict_types=1)`), PDO prepared statements, vanilla JS. | **COMPLETE** |
| **2** | **Authentication** | Password security, session hardening, brute-force defense | `password_hash()` with `PASSWORD_DEFAULT` (bcrypt), `password_verify()`, dummy timing-equalization hash, session regeneration on login, 30-min inactivity timeout, IP & username rate limiting with 15-min lockout. | **COMPLETE** |
| **3** | **Access Control (RBAC)** | Multi-role authorization with server-side gates | 5 distinct roles (`administrator`, `receptionist`, `mechanic`, `inventory_manager`, `accountant`) with granular permission codes enforced server-side. Object-level IDOR validation ensures mechanics access only assigned jobs. | **COMPLETE** |
| **4** | **Cryptographic Auditing** | Tamper-evident append-only audit log with verifiable hash chain | `audit_logs` + `audit_chain_head` tables. Every entry records actor, action, previous/new JSON diff, IP, user-agent, timestamp, `prev_hash`, and `row_hash` = SHA-256(`prev_hash` + row content). Database triggers `trg_audit_no_update` and `trg_audit_no_delete` block modification even for root. Built-in verification algorithm verifies chain continuity and alerts on tampering. | **COMPLETE** |
| **5** | **Sensitive Data Storage** | Server-side authenticated encryption | `CryptoService.php` using AES-256-GCM authenticated encryption with 96-bit random IVs, 128-bit authentication tags, and key storage outside public web directory (`storage/private/encryption.key`). Keyed HMAC blind indexing for confidential search lookups. | **COMPLETE** |
| **6** | **Customer Management** | Registration, profiles, vehicle history, repair history, account balances | `public/customers/` CRUD with duplicate phone/email detection, soft-deactivation (retention rules), full vehicle and repair history, and printable customer financial statement. | **COMPLETE** |
| **7** | **Vehicle Management** | Vehicle tracking, plate numbers, VIN, ownership, mileage | `public/vehicles/` CRUD with plate and VIN uniqueness, vehicle type categorization, mileage history updates on check-in, and linked job cards. | **COMPLETE** |
| **8** | **Service Catalog** | Standard services, pricing, estimated completion hours | `public/services/` catalog with categories (Diagnostics, Maintenance, Brakes, Engine, AC, etc.), standard pricing, estimated hours, and activation toggles. | **COMPLETE** |
| **9** | **Appointments & Scheduling** | Customer service booking and calendar slots | `public/appointments/` with scheduling by date and time, customer/vehicle linking, and **1-click conversion to active Job Cards**. | **COMPLETE** |
| **10** | **Work Order / Job Cards** | Complete workshop workflow: Pending → Diagnosing → Waiting for Parts → In Progress → Completed → Delivered / Cancelled | `public/job-cards/` with automated job numbers (`JC-YYYY-XXXXX`), status transition constraints, technician diagnosis, labor recording (hours × rate), part requests, and multi-service checklist (`job_services`). | **COMPLETE** |
| **11** | **Technician Management** | Mechanics registry, specialty, availability, workload | `public/mechanics/` with workload metrics, status tracking (`available`, `busy`, `off_duty`), and mechanic-filtered views. | **COMPLETE** |
| **12** | **Suppliers Management** | Vendor profiles, contact details, purchase history | `public/suppliers/` with vendor codes, contact persons, phone, email, linked spare parts catalog, and purchase order tracking. | **COMPLETE** |
| **13** | **Spare Parts & Inventory** | Stock ledger, purchase receipts, adjustments, low-stock alerts, row-locking | `public/inventory/` with double-entry stock transactions (`inventory_transactions`), row-locked issuing (`SELECT ... FOR UPDATE`), over-issue prevention, reorder alerts, and technician part requests. | **COMPLETE** |
| **14** | **Invoicing & Billing** | Automatic generation, labor/parts/services breakdown, tax, discounts | `public/invoices/` auto-generated from completed work orders. Server-side integer-cent arithmetic (no float rounding errors), configurable VAT rate and due days. | **COMPLETE** |
| **15** | **Payment Management** | Cash, Bank Transfer, Telebirr, Chapa, Card, receipts, refunds | `public/payments/` with immutable payment rows, printable receipt numbers (`RCT-YYYY-XXXXX`), partial payments, balance tracking, and authorized reasoned refund ledger (`RFD-YYYY-XXXXX`). | **COMPLETE** |
| **16** | **In-App Notifications** | Notification center for assignments, completions, low stock, payments | `NotificationService.php` and `public/notifications/` with topbar bell badge, unread count, live dropdown alerts, and role-based broadcasting. | **COMPLETE** |
| **17** | **Reports & Analytics** | Revenue, mechanic performance, inventory valuation, audit logs, appointments | `public/reports/` with 12 real-time reports, date filtering, instant client-side searching, printable layouts, and RFC-4180 CSV export with formula injection protection. | **COMPLETE** |
| **18** | **Enterprise UI/UX** | Modern automotive aesthetic (Deep Navy `#111C2E`, Electric Blue `#2563EB`, Cyan `#06B6D4`) | Responsive sidebar with collapse state persistence, split-screen login, accessible WCAG AA contrast, loading spinners, confirmation modals, and toast notifications. | **COMPLETE** |
| **19** | **Testing & QA Suite** | Automated testing harness | `tests/run_tests.php` verifying authentication, rate-limiting, CSRF, server-side RBAC, IDOR, SQL injection, XSS, inventory concurrency, invoice math, audit hash-chain verification, crypto round-trip, and new enterprise services. | **COMPLETE** |
| **20** | **Deployment & MAMP** | Windows MAMP setup and database creation | Step-by-step documentation, SQL schema, migration scripts, `.env.example`, and least-privilege database user script. | **COMPLETE** |

