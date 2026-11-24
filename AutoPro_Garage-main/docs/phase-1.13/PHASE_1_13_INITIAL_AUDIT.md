# ALPHA GARAGE — PHASE 1.13 INITIAL AUDIT
## COMPLETE BILINGUAL LOCALIZATION (AMHARIC 🇪🇹 + ENGLISH 🇬🇧)

**Audit Date:** November 21, 2026  
**Auditor:** DeepMind Antigravity AI Engine  
**Project:** ALPHA GARAGE (AutoPro Garage Management System)  
**System Architecture:** Native PHP 8.3 / MySQL / Vanilla ES6+ JavaScript / Chart.js 4.4 / CSS Custom Properties Design System  
**Status:** COMPLETE INITIAL READ-ONLY INSPECTION  

---

### 1. AUDIT PURPOSE & SCOPE

This initial audit establishes an exhaustive inventory of every user-facing string, component, layout template, view, form, table, modal, JavaScript script, and Chart.js visualization in the ALPHA GARAGE application.

The goal is to design and implement a production-grade bilingual system supporting:
- **English (`en`)**
- **Amharic (`am` / አማርኛ)**

Without modifying business logic, database schemas, financial calculations, RBAC permissions, audit chains, or session security.

---

### 2. PAGE INVENTORY

#### 2.1 Public Pages (11 Pages)
1. `public/index.php` — Luxury Automotive Landing Page & Hero
2. `public/home.php` — Alternative Marketing Homepage
3. `public/about.php` — About Us, Workshop Approach & Interactive FAQ
4. `public/services.php` — Automotive Service Catalog & Booking CTA
5. `public/contact.php` — Inquiry Form & Direct Dispatch Channels
6. `public/book.php` — Public/Client Appointment Booking Flow & Confirmation
7. `public/privacy.php` — Privacy Policy & Forensic Data Standards
8. `public/terms.php` — Terms of Service & Client Agreement
9. `public/login.php` — Luxury Authentication Gateway
10. `public/register.php` — Client Portal Registration Flow
11. `public/verify-customer.php` — Identity Verification Portal

#### 2.2 Authentication Subpages (2 Pages)
12. `public/forgot-password.php` — Single-use Token Password Recovery
13. `public/reset-password.php` — Cryptographic Password Reset Handler

#### 2.3 Core Role Dashboards (6 Views)
14. `app/views/dashboards/admin.php` — Administrator Financial Command Center
15. `app/views/dashboards/receptionist.php` — Front Desk Operations & Workshop Intake
16. `app/views/dashboards/mechanic.php` — Technician Workstation & Bay Queue
17. `app/views/dashboards/inventory.php` — Parts Catalog & Warehouse Control
18. `app/views/dashboards/accountant.php` — Accounts Receivable, Ledger & Cash Control
19. `app/views/dashboards/customer.php` — VIP Client Services & Repair Tracking Portal

#### 2.4 Staff Dashboard Subpages (47 Endpoints Across 15 Modules)
20. `public/appointments/index.php` — Appointment Intake Queue
21. `public/appointments/form.php` — Create / Reschedule Appointment Form
22. `public/appointments/action.php` — Confirm / Cancel Status Transitions
23. `public/customers/index.php` — Client Directory
24. `public/customers/form.php` — Customer Registration / Edit Form
25. `public/customers/view.php` — Customer Profile & Vehicle Dossier
26. `public/customers/toggle.php` — Customer Status Toggle
27. `public/vehicles/index.php` — Fleet & Vehicle Registry
28. `public/vehicles/form.php` — Vehicle Registration / Edit Form
29. `public/vehicles/view.php` — Vehicle Service History & Job Cards
30. `public/vehicles/toggle.php` — Vehicle Status Toggle
31. `public/job-cards/index.php` — Repair Orders & Workflow Board
32. `public/job-cards/form.php` — Repair Order Intake & Diagnosis Form
33. `public/job-cards/view.php` — Job Card Dossier, Parts & Labor Ledger
34. `public/job-cards/action.php` — Workflow Stage Transitions
35. `public/job-cards/data.php` — Dynamic JSON Data Endpoint
36. `public/services/index.php` — Workshop Service Menu & Pricing
37. `public/services/form.php` — Add / Edit Service Form
38. `public/services/toggle.php` — Service Active Status Toggle
39. `public/mechanics/index.php` — Technical Staff Directory
40. `public/mechanics/form.php` — Add / Edit Mechanic Profile Form
41. `public/suppliers/index.php` — Parts Suppliers & Vendors
42. `public/suppliers/form.php` — Supplier Contact & Terms Form
43. `public/suppliers/view.php` — Supplier Catalog & Purchase Orders
44. `public/suppliers/toggle.php` — Supplier Status Toggle
45. `public/inventory/index.php` — Warehouse Stock Directory
46. `public/inventory/form.php` — Add / Edit Part Record Form
47. `public/inventory/history.php` — Stock Movement Ledger
48. `public/inventory/issue.php` — Issue Parts to Job Card
49. `public/inventory/stock.php` — Restock / Inventory Adjustment
50. `public/inventory/toggle.php` — Part Active Status Toggle
51. `public/invoices/index.php` — Billing & Invoices Ledger
52. `public/invoices/create.php` — Generate Invoice from Job Card
53. `public/invoices/view.php` — Printable Tax Invoice Dossier
54. `public/invoices/action.php` — Invoice State Actions (Void, Issue)
55. `public/payments/index.php` — Payments & Receipts Ledger
56. `public/payments/record.php` — Idempotent Payment Collection Form
57. `public/payments/receipt.php` — Printable Payment Receipt
58. `public/payments/refund.php` — Payment Refund Handler
59. `public/expenses/index.php` — Operational Expense Log
60. `public/reports/index.php` — Analytics, Tax & Financial Reporting
61. `public/users/index.php` — User Account Management
62. `public/users/form.php` — Add / Edit User Form
63. `public/users/roles.php` — RBAC Roles & Permissions Matrix
64. `public/users/settings.php` — Garage Company Settings & Preferences
65. `public/audit-logs/index.php` — Cryptographic SHA-256 Merkle Audit Log
66. `public/notifications/index.php` — Alert Notifications Center
67. `public/profile.php` — User Profile & Password Change

---

### 3. JAVASCRIPT & FRONTEND STRING AUDIT

| Script | Purpose | Hardcoded English Strings Found |
|---|---|---|
| `public/assets/js/app.js` | UI Shell, Modals, Toasts | "Please confirm", "Cancel", "Confirm", "Dismiss", "Switch to light theme", "Switch to dark theme", "Light mode", "Dark mode", "Close menu", "Open menu", "Choose each part only once per issue.", "Select at least one part to issue." |
| `public/assets/js/validation.js` | Client-side validation | "This field is required.", "Please enter a valid value.", "Please match the requested format.", "This value is too short.", "This value is too long.", "Passwords do not match.", "Please correct the highlighted fields.", "Very weak", "Weak", "Fair", "Good", "Strong", "Very strong" |
| `public/assets/js/dashboard.js` | 14 Role Chart.js charts | "Revenue", "Expenses", "Net Profit", "Cash Flow", "Pending", "Diagnosing", "Waiting for Parts", "In Progress", "Completed", "Delivered", "Cancelled", "Healthy", "Low Stock", "Out of Stock", "Unpaid", "Partially Paid", "Paid", "Overdue" |
| `public/assets/js/luxury.js` | Landing page sliders & typing | Typewriter phrase variations |
| `public/assets/js/scroll-animations.js` | Scroll animations & back-to-top | "Back to top" aria-label |

---

### 4. CHART.JS AUDIT (14 ROLE CHARTS)

| Role | Chart ID | Data Contract | Existing Labels Requiring Localization |
|---|---|---|---|
| Admin | `chart-revenue-expenses` | Multi-dataset | "Revenue", "Expenses" |
| Admin | `chart-profit-loss` | Bar | "Net Profit" |
| Admin | `chart-revenue-area` | Area | "Monthly Revenue" |
| Admin | `chart-expense-donut` | Donut | Expense categories |
| Admin | `chart-jobs-donut` | Donut | Job statuses |
| Admin | `chart-inventory-bar` | Horizontal Bar | Categories |
| Admin | `chart-services` | Bar | Service names |
| Receptionist | `chart-reception-appts-status` | Donut | "Scheduled", "Confirmed", "In Progress", "Completed", "Cancelled" |
| Receptionist | `chart-reception-appts-trend` | Line | "Appointments (14 Days)" |
| Mechanic | `chart-mechanic-jobs-status` | Donut | "Pending", "Diagnosing", "Waiting for Parts", "In Progress" |
| Mechanic | `chart-mechanic-priority-dist` | Bar | "Low", "Medium", "High", "Urgent" |
| Mechanic | `chart-mechanic-completion-trend` | Line | "Completed Repairs" |
| Inventory | `chart-inventory-stock-health` | Donut | "Healthy (> Min)", "Low Stock (≤ Min)", "Out of Stock (0)" |
| Inventory | `chart-inventory-category-units` | Bar | Part categories |
| Inventory | `chart-inventory-movement-trend` | Bar | "Inbound (Restock)", "Outbound (Issued)" |
| Accountant | `chart-accountant-invoice-status` | Donut | "Paid", "Partially Paid", "Unpaid", "Overdue" |
| Accountant | `chart-accountant-aging-brackets` | Bar | "0–30 Days", "31–60 Days", "61–90 Days", "90+ Days" |
| Accountant | `chart-accountant-revenue-trend` | Line | "Net Collected Revenue" |
| Accountant | `chart-accountant-payment-methods` | Donut | "Cash", "Bank Transfer", "Telebirr", "CBE Birr", "Card" |
| Customer | `chart-customer-repair-status` | Donut | Customer repair stages |
| Customer | `chart-customer-service-history` | Line | "Service Visits (Past 6 Months)" |

---

### 5. DIRECTION & TYPOGRAPHY FINDINGS

- **LTR Direction Strictness**: Amharic is an Afroasiatic Ethiopic script written strictly left-to-right (LTR). No RTL inversion will be applied; the sidebar, grid systems, tables, and topbars remain identical.
- **Font Stack**: Windows, macOS, and Linux support Ethiopic scripts natively through `Nyala`, `Segoe UI`, `Noto Sans Ethiopic`, and `Abyssinica SIL`.
- **CSS Tokens**: Setting `--font` and `--font-display` under `html[lang="am"]` automatically cascades across all CSS rules without requiring individual component rewrites.

---

### 6. TECHNICAL RISKS & MITIGATIONS

1. **Risk:** Overwriting canonical chart payloads broke during earlier phases.  
   **Mitigation:** Retain `{ labels: [...], data: [...] }` payload contract strictly. Chart localization will happen inside `dashboard.js` client-side label translation mapper, leaving backend numeric queries untouched.
2. **Risk:** Unsafe translation string interpolation causing XSS.  
   **Mitigation:** `__e()` escapes all interpolated parameters using `htmlspecialchars(..., ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')`.
3. **Risk:** Loss of query parameters upon language toggle.  
   **Mitigation:** `lang_switch_url($lang)` parses `$_GET` and preserves all other keys (`from`, `to`, `q`, `page`, `status`).
4. **Risk:** Breaking existing automated test assertions.  
   **Mitigation:** Default language remains `en` when no preference is set, ensuring 100% backward compatibility with all regression test suites.
