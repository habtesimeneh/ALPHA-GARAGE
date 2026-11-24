# ALPHA GARAGE — PHASE 1.10: RBAC PERMISSION MATRIX
## Comprehensive Authorization Matrix by Role, Route, and Entity Action

**Document Version:** 1.0.0  
**Phase:** 1.10  
**Audit Target:** All 6 Application Roles  
**Date:** November 17, 2026  

---

## 1. Role and Capability Overview

| Role | Database Name | System Scope | Dashboard Interface | Primary Permissions |
| :--- | :--- | :--- | :--- | :--- |
| **Administrator** | `administrator` | Global System | Financial & Operations Command Center | All 40 system permissions |
| **Receptionist** | `receptionist` | Front Desk & Intake | Front Desk Operations Dashboard | 20 permissions (appointments, customers, vehicles, jobs, invoices, payments) |
| **Mechanic** | `mechanic` | Workshop Floor | Mechanic Workstation Dashboard | 7 permissions (assigned jobs, progress, parts requests, vehicles, inventory view) |
| **Inventory Manager** | `inventory_manager` | Warehouse & Parts | Inventory & Supply Chain Dashboard | 9 permissions (stock, issue, suppliers, inventory reports, jobs view_all) |
| **Accountant** | `accountant` | Financial & Ledger | Financial Accounting Dashboard | 9 permissions (invoices, payments, expenses, financial reports, customer view) |
| **Customer** | `customer` | Customer Portal | Luxury Customer Vehicle Portal | 0 staff permissions (strictly self-scoped data access) |

---

## 2. Granular Module & Action Authorization Matrix

The table below documents every protected capability across the application modules, specifying access per role:

| Module / Route | Required Permission | Admin | Receptionist | Mechanic | Inventory Mgr | Accountant | Customer |
| :--- | :--- | :---: | :---: | :---: | :---: | :---: | :---: |
| **Dashboard** | | | | | | | |
| `GET /dashboard.php` | Role-based Dispatch | **YES** | **YES** | **YES** | **YES** | **YES** | **YES** |
| Admin Command Center | `dashboard.view` + admin | **YES** | NO | NO | NO | NO | NO |
| Receptionist Dashboard | `dashboard.view` + receptionist | NO | **YES** | NO | NO | NO | NO |
| Mechanic Dashboard | `dashboard.view` + mechanic | NO | NO | **YES** | NO | NO | NO |
| Inventory Dashboard | `dashboard.view` + inventory_mgr | NO | NO | NO | **YES** | NO | NO |
| Accountant Dashboard | `dashboard.view` + accountant | NO | NO | NO | NO | **YES** | NO |
| Customer Portal Dashboard | Customer session | NO | NO | NO | NO | NO | **YES** |
| **Appointments** | | | | | | | |
| `GET /appointments/` | `appointments.view` | **YES** | **YES** | NO | NO | NO | NO |
| `POST /appointments/form.php` | `appointments.manage` | **YES** | **YES** | NO | NO | NO | NO |
| `GET /book.php` | Authenticated Customer | **YES** | **YES** | NO | NO | NO | **YES** |
| `POST /book.php` | Customer Session (Self-only) | **YES** | **YES** | NO | NO | NO | **YES** |
| **Customers** | | | | | | | |
| `GET /customers/` | `customers.view` | **YES** | **YES** | NO | NO | **YES** | NO |
| `POST /customers/form.php` | `customers.manage` | **YES** | **YES** | NO | NO | NO | NO |
| `POST /customers/action.php` | `customers.deactivate` | **YES** | NO | NO | NO | NO | NO |
| **Vehicles** | | | | | | | |
| `GET /vehicles/` | `vehicles.view` | **YES** | **YES** | **YES** | NO | NO | NO |
| `POST /vehicles/form.php` | `vehicles.manage` | **YES** | **YES** | NO | NO | NO | NO |
| **Job Cards** | | | | | | | |
| `GET /job-cards/` (All) | `jobs.view_all` | **YES** | **YES** | NO | **YES** | NO | NO |
| `GET /job-cards/` (Assigned) | `jobs.view_assigned` | **YES** | **YES** | **YES** | **YES** | NO | NO |
| `POST /job-cards/form.php` | `jobs.create` / `jobs.edit` | **YES** | **YES** | NO | NO | NO | NO |
| `POST /job-cards/action.php` (Assign) | `jobs.assign` | **YES** | **YES** | NO | NO | NO | NO |
| `POST /job-cards/action.php` (Progress) | `jobs.progress` + Ownership | **YES** | **YES** | **YES** | NO | NO | NO |
| `POST /job-cards/action.php` (Req Parts) | `jobs.request_parts` | **YES** | NO | **YES** | NO | NO | NO |
| **Inventory & Spare Parts** | | | | | | | |
| `GET /inventory/` | `inventory.view` | **YES** | NO | **YES** | **YES** | NO | NO |
| `POST /inventory/form.php` | `inventory.manage` | **YES** | NO | NO | **YES** | NO | NO |
| `POST /inventory/adjust.php` | `inventory.stock` | **YES** | NO | NO | **YES** | NO | NO |
| `POST /inventory/issue.php` | `inventory.issue` | **YES** | NO | NO | **YES** | NO | NO |
| **Suppliers** | | | | | | | |
| `GET /suppliers/` | `suppliers.view` | **YES** | **YES** | NO | **YES** | NO | NO |
| `POST /suppliers/form.php` | `suppliers.manage` | **YES** | NO | NO | **YES** | NO | NO |
| **Invoices** | | | | | | | |
| `GET /invoices/` | `invoices.view` | **YES** | **YES** | NO | NO | **YES** | NO |
| `POST /invoices/create.php` | `invoices.create` | **YES** | **YES** | NO | NO | NO | NO |
| `POST /invoices/edit.php` | `invoices.edit` | **YES** | **YES** | NO | NO | NO | NO |
| **Payments** | | | | | | | |
| `GET /payments/` | `payments.view` | **YES** | **YES** | NO | NO | **YES** | NO |
| `POST /payments/record.php` | `payments.record` | **YES** | **YES** | NO | NO | **YES** | NO |
| `POST /payments/refund.php` | `payments.refund` | **YES** | NO | NO | NO | NO | NO |
| **Expenses** | | | | | | | |
| `GET /expenses/` | `expenses.view` | **YES** | NO | NO | NO | **YES** | NO |
| `POST /expenses/` | `expenses.manage` | **YES** | NO | NO | NO | **YES** | NO |
| **Reports** | | | | | | | |
| `GET /reports/` (General) | `reports.view` | **YES** | **YES** | NO | NO | **YES** | NO |
| `GET /reports/financial.php` | `reports.financial` | **YES** | NO | NO | NO | **YES** | NO |
| `GET /reports/inventory.php` | `reports.inventory` | **YES** | NO | NO | **YES** | NO | NO |
| **Administration** | | | | | | | |
| `GET /users/` | `users.manage` | **YES** | NO | NO | NO | NO | NO |
| `GET /users/roles.php` | `roles.manage` | **YES** | NO | NO | NO | NO | NO |
| `GET /users/settings.php` | `settings.manage` | **YES** | NO | NO | NO | NO | NO |
| `GET /audit-logs/` | `audit.view` | **YES** | NO | NO | NO | NO | NO |

---

## 3. Object-Level Access (IDOR) Rules

1. **Mechanic Job Access:**
   - Enforced by `JobService::canAccess($job, $user)`:
     ```php
     if (in_array('jobs.view_all', $user['permissions'], true)) { return true; }
     return in_array('jobs.view_assigned', $user['permissions'], true)
         && $user['mechanic_id'] !== null
         && (int) $job['mechanic_id'] === (int) $user['mechanic_id'];
     ```
   - Mechanics can only view, record diagnosis, or update progress on jobs assigned directly to their `mechanic_id`.

2. **Customer Data Scoping:**
   - Enforced by matching `customer_id`:
     ```php
     $customerId = (int) $customer['id'];
     ```
   - All customer dashboard queries (`vehicles`, `appointments`, `job_cards`, `invoices`) append `WHERE customer_id = ?` with the authenticated customer's ID.
   - Any attempt to view an appointment, vehicle, or invoice belonging to a different customer results in an audit-logged IDOR rejection (`HTTP 403 Forbidden`).
