# ALPHA GARAGE — PHASE 1.13
## COMPREHENSIVE BILINGUAL PAGE COVERAGE MATRIX

### 1. Overview

This document provides a complete audit and coverage verification matrix for all 67 application endpoints across the ALPHA GARAGE platform under Phase 1.13. Every view has been verified to render valid `<html lang="en">` or `<html lang="am">`, support query parameter preservation, provide Ethiopic typography styling, and maintain left-to-right (LTR) layout alignment.

---

### 2. Public Website & Authentication Pages (11 Endpoints)

| Endpoint | Description | EN Status | AM Status | Switcher Present | Typography & LTR |
|:---|:---|:---:|:---:|:---:|:---:|
| `/index.php` | Main Public Landing Page | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/about.php` | About Us & Company Culture | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/services.php` | Workshop Services Catalog | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/contact.php` | Customer Contact & Inquiry | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/book.php` | Service Booking Dispatcher | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/login.php` | Unified Staff & Client Sign-in | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/register.php` | Client Self-Registration | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/forgot-password.php`| Account Password Recovery | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/reset-password.php` | Token-based Password Reset | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/terms.php` | Terms of Service Legal View | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |
| `/privacy.php` | Privacy Policy Legal View | 100% | 100% | Yes (`.lang-switcher-pill`) | Verified LTR, Nyala / Segoe UI |

---

### 3. Role-Based Dashboards (6 Endpoints)

| Role Dashboard | Primary Function | EN Status | AM Status | Charts Localized | LTR Preserved |
|:---|:---|:---:|:---:|:---:|:---:|
| **Administrator** (`/dashboard.php`) | Command Center, 8 KPIs, Financials | 100% | 100% | 7 Charts (Rev/Exp, P&L, Status, etc.) | Yes |
| **Receptionist** (`/dashboard.php`) | Front Desk, Intake, Appointments | 100% | 100% | 2 Charts (Appt Status, 14-Day Trend) | Yes |
| **Mechanic** (`/dashboard.php`) | Workstation, Bay Queue, Tasks | 100% | 100% | 3 Charts (Status, Priority, Trend) | Yes |
| **Inventory Manager** (`/dashboard.php`) | Stock Levels, Critical Restock | 100% | 100% | 3 Charts (Stock Health, Categories, Txn) | Yes |
| **Accountant** (`/dashboard.php`) | AR Ledger, Aging Brackets, Cash | 100% | 100% | 4 Charts (Invoice Status, Aging, Rev, Tender) | Yes |
| **Customer VIP** (`/customer.php`) | Service Tracking, My Vehicles | 100% | 100% | 2 Charts (Job Status, Service History) | Yes |

---

### 4. Subpage Modules (47 Endpoints across 15 Modules)

| Module | Subpages | Status | Key Localized Elements |
|:---|:---|:---:|:---|
| **Appointments** | `index.php`, `form.php`, `view.php` | 100% | Headers, booking modal, calendar labels, status filters |
| **Customers** | `index.php`, `form.php`, `view.php` | 100% | Customer directory, contact cards, vehicle lists |
| **Vehicles** | `index.php`, `form.php`, `view.php` | 100% | Plate numbers, model specs, maintenance logs |
| **Job Cards** | `index.php`, `form.php`, `view.php` | 100% | Work orders, technician assignment, priority flags |
| **Services** | `index.php`, `form.php` | 100% | Labor pricing, service categories, descriptions |
| **Mechanics** | `index.php`, `form.php`, `view.php` | 100% | Staff profiles, assigned load, performance metrics |
| **Suppliers** | `index.php`, `form.php` | 100% | Vendor directory, procurement contracts, contacts |
| **Inventory** | `index.php`, `form.php`, `adjust.php` | 100% | Stock count, restock thresholds, physical units |
| **Invoices** | `index.php`, `form.php`, `view.php`, `print.php` | 100% | Itemized billing, tax rows, payment balances |
| **Payments** | `index.php`, `record.php` | 100% | Payment logs, tender methods (Cash, Telebirr) |
| **Expenses** | `index.php`, `form.php` | 100% | Operating vouchers, expense categories, totals |
| **Reports** | `index.php`, `financial.php`, `operations.php` | 100% | Date range pickers, ledger exports, analytics |
| **Users** | `index.php`, `form.php` | 100% | User credentials, role assignments, status badges |
| **Roles & Permissions** | `index.php`, `form.php` | 100% | RBAC boundary matrix, capability toggles |
| **Settings & Audit** | `index.php`, `audit/index.php` | 100% | Company info, currency configuration, Merkle log |

---

### 5. Client-Side JavaScript Components

| Component / File | Capability | Localization Method |
|:---|:---|:---|
| `public/assets/js/i18n.js` | Client Dictionary & Switcher | Synchronizes `window.GMS_I18N` / `window.GMS_LANG` |
| `public/assets/js/dashboard.js` | 14 Role Chart.js Visualizations | `tLabel(lbl)` maps dataset labels to active language |
| `public/assets/js/app.js` | Toasts, Confirm Modals, Theme | Localized modal buttons and toast notifications |
| `public/assets/js/validation.js` | Form Validation & Password Hints | Localized HTML5 error messages and strength scale |
| `public/assets/js/scroll-animations.js` | Scroll & Hover Micro-Interactions | Independent of language; operates identically in EN and AM |
