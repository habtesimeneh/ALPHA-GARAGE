# ALPHA GARAGE — PHASE 1.10: EXISTING ROLE AUDIT
## Comprehensive Architectural Audit of Roles, Permissions, Routes, and Existing Dashboard

**Document Version:** 1.0.0  
**Phase:** 1.10  
**Audit Scope:** Database Roles, Permission Mappings, Existing Controllers, Dashboard Implementation  
**Auditor / Agent:** Antigravity  
**Audit Date:** November 17, 2026  

---

## 1. Executive Summary

This audit assesses the existing security architecture of ALPHA GARAGE regarding user roles, permissions, route authorization, and the current dashboard implementation. The system currently features 6 roles in the database, with granular permissions mapped via `role_permissions`. 

Prior to Phase 1.10, the application utilized a single monolithic `dashboard.php` designed as an Administrator / Command Center financial and operations analytics interface. Non-administrator staff members with `dashboard.view` permission accessed this same dashboard with partial feature degradation (e.g. empty financial widgets for mechanics, empty inventory for accountants), while customer accounts were outright blocked with HTTP 403 Forbidden.

Phase 1.10 introduces professional, secure, role-specific dashboards tailored exclusively to each role's business functions, strictly enforcing deny-by-default access control and horizontal data isolation (IDOR protection).

---

## 2. Database Roles & Actual Permission Audit

Empirical inspection of the database (`roles`, `permissions`, and `role_permissions`) reveals the exact system permissions:

### Role Breakdown

| Role ID | Role Name | System Label | Permission Count | Core Business Scope |
| :---: | :--- | :--- | :---: | :--- |
| **1** | `administrator` | Administrator | **40** | Unrestricted access across workshop, finance, inventory, users, settings, and audit logs. |
| **2** | `receptionist` | Receptionist | **20** | Customer reception, vehicle intake, appointment scheduling, job card creation, invoice creation, and payment recording. |
| **3** | `mechanic` | Mechanic | **7** | Execution of assigned repair jobs, diagnostic notes, labor logging, and spare part requests. |
| **4** | `inventory_manager` | Inventory Manager | **9** | Spare parts management, stock valuation, reorder alerts, supplier operations, and parts issuance. |
| **5** | `accountant` | Accountant | **9** | Financial reporting, invoice balance tracking, payment recording, and expense ledger auditing. |
| **6** | `customer` | Customer | **0** | Customer-facing service portal (`book.php`), vehicle tracking, service history, and personal invoice lookup. |

---

## 3. Detailed Role Permission Mapping

### 3.1 Receptionist (20 Permissions)
- **Appointments (2):** `appointments.manage`, `appointments.view`
- **Customers (2):** `customers.manage`, `customers.view`
- **Vehicles (2):** `vehicles.manage`, `vehicles.view`
- **Job Cards (4):** `jobs.assign`, `jobs.create`, `jobs.edit`, `jobs.view_all`
- **Invoices (3):** `invoices.create`, `invoices.edit`, `invoices.view`
- **Payments (2):** `payments.record`, `payments.view`
- **Workshop Entities (3):** `mechanics.view`, `services.view`, `suppliers.view`
- **Reports & Dashboard (2):** `dashboard.view`, `reports.view`
- *Strictly Excluded:* `audit.view`, `users.manage`, `roles.manage`, `settings.manage`, `expenses.manage`, `expenses.view`, `inventory.manage`, `payments.refund`.

### 3.2 Mechanic (7 Permissions)
- **Job Cards (3):** `jobs.progress`, `jobs.request_parts`, `jobs.view_assigned`
- **Workshop Views (3):** `inventory.view`, `services.view`, `vehicles.view`
- **Dashboard (1):** `dashboard.view`
- *Strictly Excluded:* `jobs.view_all`, `jobs.create`, `jobs.edit`, `jobs.assign`, `invoices.view`, `payments.view`, `customers.view`, `reports.view`, `expenses.view`.
- *Object Ownership Constraint:* Enforced by `JobService::canAccess()`, requiring `job_cards.mechanic_id === current_user()['mechanic_id']`.

### 3.3 Inventory Manager (9 Permissions)
- **Inventory (4):** `inventory.issue`, `inventory.manage`, `inventory.stock`, `inventory.view`
- **Job Cards (1):** `jobs.view_all` (needed to issue parts against open repair jobs)
- **Suppliers (2):** `suppliers.manage`, `suppliers.view`
- **Reports & Dashboard (2):** `dashboard.view`, `reports.inventory`
- *Strictly Excluded:* `invoices.view`, `payments.view`, `expenses.view`, `reports.financial`, `users.manage`, `audit.view`.

### 3.4 Accountant (9 Permissions)
- **Finance (4):** `invoices.view`, `payments.record`, `payments.view`, `reports.financial`
- **Expenses (2):** `expenses.manage`, `expenses.view`
- **Customers & Views (2):** `customers.view`, `reports.view`
- **Dashboard (1):** `dashboard.view`
- *Strictly Excluded:* `users.manage`, `audit.view`, `settings.manage`, `inventory.manage`, `jobs.edit`.

### 3.5 Customer (0 Staff Permissions)
- Zero staff permissions in `role_permissions`.
- Access is restricted strictly to customer-facing controllers (`book.php`, `profile.php`, and the new customer dashboard).
- *Strict Data Scoping:* Queries must enforce `customer_id = $customerId` resolved via `customers.created_by = $user['id']` or verified email link.

---

## 4. Current Dashboard (`public/dashboard.php`) Analysis

The existing `dashboard.php` file is an extensive financial analytics suite (231 lines of PHP and Chart.js code) displaying:
- Cash-basis Revenue vs. Expense calculations.
- Net profit, profit margin, monthly target progress bars.
- Financial series, revenue area charts, expense distribution donuts, and job status charts.
- Inventory valuation and stock indicators.

### Limitations for Non-Admin Roles
1. **Receptionist:** Overwhelmed with financial analytics while missing fast-path reception actions (e.g. today's scheduled arrivals, immediate vehicle intake).
2. **Mechanic:** Financial charts render empty states ("No payments permission", "Requires expense ledger"); lacks a focused priority work queue of assigned vehicles.
3. **Inventory Manager:** Unnecessarily executes revenue checks; lacks detailed supplier and restock quick actions.
4. **Accountant:** Displays workshop operations and job completion charts rather than focusing on the invoice aging ledger and payment reconciliation.
5. **Customer:** Completely blocked with HTTP 403 Forbidden because the customer role lacks `dashboard.view`.

---

## 5. Architectural Recommendations for Phase 1.10

1. **Retain Administrator Dashboard Untouched:** Keep the existing administrator financial & operations command center dashboard 100% identical in appearance and functionality.
2. **Modular Role-Based Architecture:** Move role-specific dashboard views into dedicated files in `app/views/dashboards/`:
   - `admin.php` (exact existing dashboard logic)
   - `receptionist.php` (reception intake, today's appointments, active job cards, quick actions)
   - `mechanic.php` (assigned work queue, priority jobs, update progress modal, zero unauthorized jobs)
   - `inventory.php` (critical stock alerts, movements, supplier actions, zero financial exposure)
   - `accountant.php` (outstanding invoices, payment ledger, expense summary, financial reports)
   - `customer.php` (my vehicles, service appointments, repair status, invoices, booking portal link)
3. **Secure Dispatching in `dashboard.php`:** Dispatch strictly using server-side `$user['role_name']` obtained from the session/database, rejecting unknown or unauthorized roles.
4. **Update `login.php` Routing:** Ensure all users, including customers, land on their appropriate dashboard upon successful authentication.
