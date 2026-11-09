# AutoPro Garage Management System — User Manual
## Step-by-Step Operational Guide for All System Roles

**System:** AutoPro Garage Management System with Secure Data Storage and Auditing  
**Platform:** MAMP for Windows / Native PHP 8.x / MariaDB  
**Audience:** Administrators, Service Advisors, Technicians, Storekeepers, and Cashiers  

---

## 1. System Access & Interface Tour

### 1.1 Accessing the Application
Open your web browser (Chrome, Edge, or Firefox) and navigate to:
```
http://localhost/garage-management-systems/public/login.php
```

### 1.2 Demo Credentials Quick-Reference

| Role | Username | Default Password | Primary Responsibilities |
|---|---|---|---|
| **Super Administrator** | `admin` | `Admin#2026!` | Full system administration, audit verification, user management |
| **Garage Manager** | `manager` | `Manager#2026!` | Workshop oversight, reports, inventory and financial supervision |
| **Receptionist** | `receptionist`| `Recept#2026!` | Customer onboarding, appointments, vehicle intake, job card creation |
| **Mechanic 1** | `mechanic1` | `Mechanic#2026!`| Engine & diagnostics technician, job card execution, parts requests |
| **Mechanic 2** | `mechanic2` | `Mechanic#2026!`| Suspension & electrical technician, repair completion |
| **Inventory Manager** | `inventory` | `Inventory#2026!`| Spare parts stock, supplier purchase orders, issuing parts to jobs |
| **Accountant** | `accountant` | `Account#2026!` | Commercial invoices, payment collection, receipts, financial reports |

> [!NOTE]
> All users are prompted to change default credentials upon initial deployment. Account lockouts occur automatically after 5 consecutive failed login attempts.

### 1.3 Interface Orientation
The AutoPro interface uses a modern, high-contrast Automotive Enterprise design:
- **Collapsible Sidebar (Left):** Contextual navigation menu tailored to your active role's permissions.
- **Top Header Bar:**
  - **Global Search:** Fast-lookup bar for customers, vehicles, and job numbers.
  - **Notification Center:** Bell icon with live unread counter displaying low-stock warnings, job status changes, and appointments.
  - **User Chip:** Displays your authenticated name, assigned role badge, and 1-click logout.
- **Main Workspace:** Filterable data tables, stat cards, metric trends, and action buttons.

---

## 2. Super Administrator & Garage Manager Guide

### 2.1 Executive Dashboard
Upon login, the dashboard presents real-time operational indicators:
- **Metric Cards:** Total active repair orders, vehicles in workshop today, low-stock inventory alerts, and monthly gross revenue.
- **7-Day Revenue Trend:** Interactive CSS bar chart tracking daily cash receipts.
- **Upcoming Appointments Widget:** Service appointments scheduled for today with 1-click job conversion.
- **Quick Actions:** Instant buttons to register a customer, book an appointment, or open a job card.

### 2.2 Managing System Users & Roles
1. Navigate to **Administration > Users** (`/public/users/index.php`).
2. Click **+ New User**.
3. Enter username, email, full legal name, phone number, and assign a **Security Role**.
4. Set an initial password complying with complexity rules (min 8 characters with upper, lower, number, and symbol).
5. Check **Force password change on next login** if desired.
6. To deactivate a departing staff member, click **Deactivate** on the user row. Deactivated accounts immediately fail authentication.

### 2.3 Forensic Audit Trail & Tamper Verification
1. Navigate to **Administration > Audit Log** (`/public/audit/index.php`).
2. Filter historic logs by **Actor**, **Action Code**, **Table**, or **Date Range**.
3. Click any record to inspect the exact `old_value` and `new_value` JSON payloads with sensitive credentials automatically redacted.
4. **Verifying Cryptographic Chain Integrity:**
   - Click the green **Verify Audit Chain** button at the top right.
   - The system checks every sequential record hash from Genesis (`id = 1`) to the current chain head.
   - A success banner will confirm: `"Audit chain verified intact (N entries checked)."`
   - If any manual database manipulation occurred, the system displays an alert with the exact ID of the corrupted record.
5. Click **Export CSV** to download a verifiable copy for external compliance audits.

### 2.4 Managing Service Catalog & Suppliers
- **Service Catalog (`/public/services/`):** Define standard labor packages (e.g. *Full Engine Diagnostic Scan*, *Brake Fluid Flush*) with category, base price, and estimated labor hours.
- **Suppliers (`/public/suppliers/`):** Maintain authorized parts vendors, contact persons, phone numbers, and physical addresses.

---

## 3. Receptionist & Service Advisor (Front Office) Guide

### 3.1 Step 1: Customer Onboarding
1. Navigate to **Customers** (`/public/customers/index.php`).
2. Search if the client already exists by phone number or name.
3. If new, click **+ Add Customer**.
4. Enter Full Name, Mobile Phone (primary key for customer SMS/calls), Email, and Physical Address.
5. Click **Save Customer**.

### 3.2 Step 2: Vehicle Registration
1. On the customer's detail page, click **+ Add Vehicle** (or navigate to **Vehicles > Register Vehicle**).
2. Select or verify the Owner.
3. Enter:
   - **Plate Number** (e.g. `ET-3-A12345`) — strictly unique.
   - **Make & Model** (e.g. `Toyota Hilux`).
   - **Vehicle Type** (Sedan, SUV, Truck, Van, Motorcycle, Commercial).
   - **Year & Color**.
   - **VIN / Chassis Number** (17 alphanumeric characters).
   - **Current Mileage (Odometer in km)**.
   - **Fuel Type** (Petrol, Diesel, Hybrid, Electric).
   - **Vehicle Notes / Special Instructions** (existing body scratches, client requests).
4. Click **Save Vehicle**.

### 3.3 Step 3: Booking & Converting Appointments
1. Navigate to **Appointments > Book Appointment** (`/public/appointments/form.php`).
2. Select Customer, Vehicle, and optional pre-selected Service Package.
3. Choose Date, Time, and record customer notes.
4. When the customer arrives at the garage on the appointment day:
   - Open the appointment record.
   - Click the blue **Convert to Job Card** button.
   - The system automatically pre-populates a new job card with the customer, vehicle, and service checklist.

### 3.4 Step 4: Creating a Workshop Job Card
1. Navigate to **Job Cards > + New Job Card**.
2. Select Customer and Vehicle.
3. Record:
   - **Current Mileage In**.
   - **Customer Reported Problem** (detailed symptoms described by driver).
   - **Initial Visual Condition** (scratches, dents, spare tire present, fuel gauge level).
   - **Estimated Completion Date**.
   - **Assigned Mechanic** (or leave Unassigned for workshop manager allocation).
4. Click **Create Job Card**. The system generates a unique tracking number (e.g. `JOB-202611-0001`).

### 3.5 Step 5: Vehicle Handover & Delivery
Once the invoice is paid and repairs pass final inspection:
1. Open the Job Card.
2. Under Status Transitions, select **Mark Delivered**.
3. The vehicle is released to the client, concluding the operational lifecycle.

---

## 4. Workshop Technician / Mechanic Guide

### 4.1 Mechanic Workspace
When logged in as a Mechanic (`mechanic1` or `mechanic2`):
1. Navigate to **Job Cards**. The table automatically filters to show only jobs assigned to you.
2. Select an active job card to view the vehicle's repair history and reported faults.

### 4.2 Recording Diagnosis & Technical Findings
1. On the Job Card view, click **Edit Details**.
2. Fill in:
   - **Diagnostic Findings:** Root cause identified via scan tool or physical inspection.
   - **Required Repairs:** Scope of corrective mechanical/electrical work.
   - **Estimated Labor Charge:** Labor cost to be billed.
3. Click **Update Job Card**.

### 4.3 Managing the Service Checklist
1. Under the **Workshop Service Checklist** section of the Job Card:
2. Select standard services from the dropdown (e.g., *Air Conditioning Re-gas*) and click **+ Add Service**.
3. As each maintenance task is executed on the workshop floor, update its status from `Pending` to `In Progress` to `Completed`.

### 4.4 Transitioning Job Status
Use the status change panel on the Job Card page:
- **Diagnosing:** Vehicle is on the lift undergoing fault isolation.
- **Waiting for Parts:** Technical diagnosis complete; awaiting spare parts from inventory.
- **In Progress:** Repair work actively underway.
- **Completed:** Repairs finished, quality inspection passed, vehicle ready for billing and customer collection.

---

## 5. Inventory Manager (Stores & Procurement) Guide

### 5.1 Spare Parts Master Catalog
1. Navigate to **Inventory** (`/public/inventory/index.php`).
2. Review stock items, bin locations, on-hand quantities, cost price, and selling price.
3. Items falling below **Minimum Stock Level** are highlighted with orange warning badges and trigger in-app notifications.

### 5.2 Purchasing & Stock-In from Suppliers
1. Navigate to **Inventory > Stock Movement** (`/public/inventory/stock.php`).
2. Select **Transaction Type:** `Stock In (Purchase)`.
3. Choose the Spare Part.
4. Enter the incoming **Quantity**.
5. Select the registered **Supplier** from the vendor dropdown.
6. Enter invoice/delivery note number in **Reference / Notes**.
7. Click **Record Stock Movement**. The inventory quantity immediately increments and a double-entry audit ledger row is posted.

### 5.3 Issuing Parts to Active Job Cards
1. Open **Inventory > Issue parts** or use **+ Issue parts** on an active job card.
2. Select the target job card and one or more different parts (up to five per submission); enter a quantity for each selected part.
3. Review the displayed available stock and estimated line/overall totals, then submit. Estimates are recalculated from the current inventory price on the server.
4. The system checks every requested quantity and updates all selected parts in a single transaction. If any line is invalid or short of stock, none of the issue is saved.
5. On-hand stock is decremented, each price is locked into `job_parts`, and the issue is added to the job history and stock ledger.
6. Pending mechanic requests can be fulfilled from the same page. Repeated fulfillment is rejected after the request is handled.
7. If a job is cancelled before completion, all issued parts are automatically returned to inventory stock.

### 5.4 Stock Adjustments & Physical Inventory Counts
1. Navigate to **Inventory > Stock Movement**.
2. Select **Transaction Type:** `Adjustment`.
3. Enter the delta quantity (positive or negative) and mandatory justification (e.g., *"Annual inventory audit discrepancy"*).

---

## 6. Accountant & Cashier (Finance & Billing) Guide

### 6.1 Generating Commercial Invoices
1. Navigate to **Job Cards** and locate a job in `Completed` status.
2. Click **Generate Invoice** (or navigate to **Billing > + Create Invoice**).
3. The system automatically compiles:
   - Base labor charges.
   - Attached service catalog lines.
   - All issued spare parts and materials.
4. Review Subtotal, apply authorized **Discount Amount** if applicable, and verify standard **Tax Rate (15% VAT)**.
5. Click **Save & Issue Invoice**. The invoice status is set to `Unpaid`.

### 6.2 Recording Customer Payments
1. Open the target invoice (`/public/invoices/view.php?id=...`).
2. In the **Record Payment** panel, enter:
   - **Amount Paid** (supports partial or full settlement; overpayments are mathematically blocked).
   - **Payment Method:** `Cash`, `Bank Transfer`, `Telebirr`, `Check`, `Card`.
   - **Reference Number:** Bank transaction reference or Telebirr transaction code.
3. Click **Process Payment**.
   - Cumulative `amount_paid` updates.
   - Remaining `balance` recalculates.
   - If balance reaches `0.00`, status advances to `Paid`.
   - A printable payment receipt is instantly generated (`/public/payments/receipt.php`).

### 6.3 Processing Authorized Refunds
1. Open an invoice with recorded payments.
2. Click **Process Refund**.
3. Enter refund amount (cannot exceed net paid) and mandatory **Reason for Refund**.
4. The system issues a negative ledger entry, adjusts invoice balance, and logs a forensic audit record.

### 6.4 Recording Operating Expenses
1. Navigate to **Finance > Expenses** (accountant or administrator access).
2. Record the payment date, category, amount, description, and optional receipt/reference.
3. Expense entries are audit-logged and cannot be edited or deleted from this page. If an entry is incorrect, ask an administrator to arrange a documented correction.
4. Stock purchases entered through **Inventory > Record purchase (stock-in)** are added to dashboard expenses automatically. Do not enter them again in Expenses.
5. The dashboard compares recorded customer payments less refunds with these recorded expenses. Its profit/loss figure is a cash-basis management estimate and excludes any costs that have not been recorded.

### 6.5 Customer Financial Statements
1. Navigate to any Customer page (`/public/customers/view.php?id=...`).
2. Review the **Billing & Account Statement** section showing all lifetime jobs, invoices, payments, and outstanding balance.
3. Click **Print Statement** for an official letterhead document suitable for corporate fleet clients.

### 6.6 Financial & Operational Reports
Navigate to **Reports** (`/public/reports/index.php`) to view and print:
- **Revenue Summary:** Daily and monthly gross billings, tax collected, and payment channel breakdown.
- **Unpaid Invoices Ledger:** Aged debtor report of outstanding garage balances.
- **Inventory Valuation:** Current asset value of warehouse stock at cost vs retail.
- **Mechanic Productivity:** Jobs completed, labor billings generated, and turnaround times per technician.
- **Supplier Spend:** Total purchase spend aggregated by parts vendor.

---
*End of User Manual.*
