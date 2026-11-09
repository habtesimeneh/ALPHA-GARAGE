# Database Data Dictionary
## Garage Management System with Secure Data Storage and Auditing

**System Database:** `garage_management_system`  
**Storage Engine:** MySQL / MariaDB InnoDB (`utf8mb4_unicode_ci`)  
**Architecture:** 3NF Relational Schema with Cryptographic Hash Chaining and Immutable Audit Triggers  
**Total Tables:** 28  

---

## 1. Table Inventory

| # | Table Name | Category | Primary Key | Description |
|---|---|---|---|---|
| 1 | `roles` | RBAC & Security | `id` | System security roles |
| 2 | `permissions` | RBAC & Security | `id` | Granular permission nodes |
| 3 | `role_permissions` | RBAC & Security | (`role_id`, `permission_id`) | Many-to-many role-to-permission assignments |
| 4 | `users` | Identity & Access | `id` | User accounts and authentication records |
| 5 | `mechanics` | Workshop Staff | `id` | Technician profiles, availability, and optional user links |
| 6 | `customers` | Client Management | `id` | Vehicle owners and contact information |
| 7 | `vehicles` | Fleet Assets | `id` | Customer vehicles, registration details, and mileage |
| 8 | `job_cards` | Workshop Operations | `id` | Repair orders, assignments, diagnosis, and workflow status |
| 9 | `job_updates` | Workshop Operations | `id` | Job notes, status changes, and labor records |
| 10 | `services` | Service Catalog | `id` | Standard labor and diagnostic services |
| 11 | `job_services` | Workshop Operations | `id` | Service tasks attached to job cards |
| 12 | `appointments` | Front Office | `id` | Customer service booking and scheduling records |
| 13 | `suppliers` | Procurement | `id` | Spare-parts supplier records |
| 14 | `spare_parts` | Inventory & Stores | `id` | Stock catalog, prices, supplier, and reorder threshold |
| 15 | `inventory_transactions` | Inventory & Stores | `id` | Append-only stock movements and balances |
| 16 | `job_parts` | Workshop Operations | `id` | Parts issued or returned against job cards |
| 17 | `part_requests` | Workshop Operations | `id` | Mechanic requests for inventory items |
| 18 | `invoices` | Billing & Finance | `id` | Job invoices, totals, tax, balance, and payment status |
| 19 | `invoice_items` | Billing & Finance | `id` | Itemized labor and parts invoice lines |
| 20 | `payments` | Billing & Finance | `id` | Recorded payments and authorized refunds |
| 21 | `expenses` | Billing & Finance | `id` | Operating costs for the cash-basis dashboard summary |
| 22 | `login_attempts` | Security & Auth | `id` | Login rate-limit records |
| 23 | `audit_logs` | Forensic Auditing | `id` | Audit events with SHA-256 hash chaining |
| 24 | `audit_chain_head` | Forensic Auditing | `id` | Audit-chain serialization state |
| 25 | `notifications` | User Communication | `id` | User alerts and workflow notifications |
| 26 | `password_resets` | Security & Auth | `id` | Single-use, time-limited password reset tokens |
| 27 | `garage_settings` | System Config | `setting_key` | Garage profile and application settings |
| 28 | `contact_inquiries` | Public Contact | `id` | Public website contact and support submissions |

---

## 2. Table-by-Table Data Dictionary

### 2.1 `roles`
*Role definitions for Role-Based Access Control (RBAC).*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique role identifier |
| `name` | VARCHAR(50) | NO | None | UNIQUE | Internal machine name (`super_admin`, `mechanic`, etc.) |
| `label` | VARCHAR(100) | NO | None | None | Human-readable title (`Super Administrator`, `Mechanic`) |
| `description` | VARCHAR(255) | YES | NULL | None | Detailed scope of the security role |
| `is_system` | TINYINT(1) | NO | 0 | None | Flag: 1 = core protected system role, cannot be deleted |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Audit timestamp of role creation |

---

### 2.2 `permissions`
*Granular security privileges protecting routes and domain actions.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique permission identifier |
| `code` | VARCHAR(80) | NO | None | UNIQUE | Unique dot-notation code (`invoices.pay`, `users.manage`) |
| `module` | VARCHAR(50) | NO | None | INDEX (`idx_perm_module`) | Module grouping (`billing`, `inventory`, `audit`, `users`) |
| `label` | VARCHAR(120) | NO | None | None | User-friendly capability description |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Timestamp of permission registration |

---

### 2.3 `role_permissions`
*Junction table associating security roles with permissions.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `role_id` | INT UNSIGNED | NO | None | PRIMARY KEY (Composite), FK -> `roles.id` ON DELETE CASCADE | Target role ID |
| `permission_id` | INT UNSIGNED | NO | None | PRIMARY KEY (Composite), FK -> `permissions.id` ON DELETE CASCADE | Granted permission ID |

---

### 2.4 `users`
*System accounts with security credentials, lockout state, and profile.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique user record identifier |
| `role_id` | INT UNSIGNED | NO | None | FK -> `roles.id`, INDEX (`idx_users_role`) | Assigned security role |
| `username` | VARCHAR(50) | NO | None | UNIQUE | Unique login name (alphanumeric, underscore) |
| `email` | VARCHAR(150) | NO | None | UNIQUE | Unique email address for alerts and password reset |
| `full_name` | VARCHAR(120) | NO | None | None | User's full display name |
| `phone` | VARCHAR(30) | YES | NULL | None | Contact telephone number |
| `password_hash` | VARCHAR(255) | NO | None | None | Bcrypt / Argon2id salted hash |
| `is_active` | TINYINT(1) | NO | 1 | None | Account status: 1 = Active, 0 = Suspended/Inactive |
| `force_password_change`| TINYINT(1) | NO | 0 | None | Requires credential change on subsequent login |
| `failed_login_count` | SMALLINT UNSIGNED| NO | 0 | None | Consecutive failed login attempts counter |
| `locked_until` | DATETIME | YES | NULL | None | Lockout expiration timestamp if threshold breached |
| `last_login_at` | DATETIME | YES | NULL | None | Timestamp of most recent successful authentication |
| `last_login_ip` | VARCHAR(45) | YES | NULL | None | IPv4 or IPv6 address of most recent login |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Account creation timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Timestamp of last profile or credential modification |

---

### 2.5 `mechanics`
*Automotive technicians and mechanics assigned to vehicle repair job cards.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique mechanic identifier |
| `user_id` | INT UNSIGNED | YES | NULL | UNIQUE, FK -> `users.id` ON DELETE SET NULL | Linked system user account (for mechanic dashboard login) |
| `full_name` | VARCHAR(120) | NO | None | None | Full legal technician name |
| `phone` | VARCHAR(30) | YES | NULL | None | Mobile phone number |
| `specialty` | VARCHAR(100) | YES | NULL | None | Technical expertise (e.g. Diagnostics, Transmission, Electrical) |
| `availability` | ENUM | NO | 'available' | 'available','busy','off_duty' | Current workshop availability state |
| `is_active` | TINYINT(1) | NO | 1 | None | 1 = active employee, 0 = resigned/deactivated |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Record creation timestamp |

---

### 2.6 `customers`
*Vehicle owners and corporate fleet clients.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique customer identifier |
| `full_name` | VARCHAR(120) | NO | None | INDEX (`idx_cust_name`) | Customer name or company entity name |
| `phone` | VARCHAR(30) | NO | None | INDEX (`idx_cust_phone`) | Primary contact number |
| `email` | VARCHAR(150) | YES | NULL | None | Customer billing/contact email |
| `address` | VARCHAR(255) | YES | NULL | None | Physical residential or business address |
| `is_active` | TINYINT(1) | NO | 1 | None | 1 = active client, 0 = inactive |
| `registered_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Date and time customer was first onboarded |
| `created_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Staff member who registered the customer |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Timestamp of last update |

---

### 2.7 `vehicles`
*Customer-owned vehicles serviced in the garage.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique vehicle identifier |
| `customer_id` | INT UNSIGNED | NO | None | FK -> `customers.id`, INDEX (`idx_veh_customer`) | Foreign key linking vehicle to its owner |
| `plate_number` | VARCHAR(20) | NO | None | UNIQUE | License registration plate number |
| `make` | VARCHAR(50) | NO | None | None | Vehicle manufacturer (e.g. Toyota, Ford, Hyundai) |
| `model` | VARCHAR(50) | NO | None | None | Vehicle model name (e.g. Corolla, Hilux, Tucson) |
| `year` | SMALLINT UNSIGNED| YES | NULL | None | Manufacturing model year |
| `color` | VARCHAR(30) | YES | NULL | None | Exterior body color |
| `vin` | VARCHAR(17) | YES | NULL | UNIQUE | 17-character ISO Vehicle Identification Number |
| `mileage` | INT UNSIGNED | NO | 0 | None | Current odometer reading in kilometers |
| `fuel_type` | ENUM | NO | 'Petrol' | 'Petrol','Diesel','Hybrid','Electric','LPG','Other' | Engine fuel system |
| `vehicle_type` | VARCHAR(40) | NO | 'Sedan' | None | Vehicle body type (Sedan, SUV, Truck, Van, Motorcycle, etc.) |
| `notes` | TEXT | YES | NULL | None | Special notes, existing body damage, or instructions |
| `is_active` | TINYINT(1) | NO | 1 | None | 1 = active, 0 = retired/decommissioned |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Registration timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Last modification timestamp |

---

### 2.8 `suppliers`
*External vendors supplying automotive parts and workshop consumables.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique supplier identifier |
| `code` | VARCHAR(50) | NO | None | UNIQUE | Unique vendor code (e.g. `SUP-BOSCH`) |
| `name` | VARCHAR(150) | NO | None | INDEX (`idx_sup_name`) | Vendor company trading name |
| `contact_person`| VARCHAR(120) | YES | NULL | None | Key sales representative or account manager |
| `phone` | VARCHAR(40) | YES | NULL | None | Supplier telephone number |
| `email` | VARCHAR(150) | YES | NULL | None | Supplier order email address |
| `address` | VARCHAR(255) | YES | NULL | None | Warehouse or office location |
| `is_active` | TINYINT(1) | NO | 1 | None | 1 = active supplier, 0 = inactive |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Supplier registration timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Last profile update timestamp |

---

### 2.9 `services`
*Master catalog of standardized garage labor and diagnostic services.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique service identifier |
| `code` | VARCHAR(40) | NO | None | UNIQUE | Standard service code (e.g. `SRV-ENG-001`) |
| `name` | VARCHAR(150) | NO | None | None | Service name (e.g. `Full Engine Diagnostic Scan`) |
| `category` | VARCHAR(60) | NO | None | INDEX (`idx_srv_cat`) | Service classification (Diagnostics, Brakes, AC, Electrical) |
| `description` | TEXT | YES | NULL | None | Scope of inspection, testing, and operations included |
| `standard_price`| DECIMAL(12,2)| NO | 0.00 | None | Base catalog price before job customization |
| `estimated_hours`| DECIMAL(4,2)| NO | 1.00 | None | Estimated labor duration in decimal hours |
| `is_active` | TINYINT(1) | NO | 1 | None | 1 = available for booking, 0 = discontinued |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Catalog entry timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Last modification timestamp |

---

### 2.10 `appointments`
*Customer pre-booked service appointments and scheduling queue.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique appointment identifier |
| `appointment_number`| VARCHAR(40)| NO | None | UNIQUE | Human-readable tracking number (e.g. `APT-202611-0001`) |
| `customer_id` | INT UNSIGNED | NO | None | FK -> `customers.id`, INDEX (`idx_apt_customer`) | Customer requesting appointment |
| `vehicle_id` | INT UNSIGNED | NO | None | FK -> `vehicles.id`, INDEX (`idx_apt_vehicle`) | Target vehicle to be inspected |
| `service_id` | INT UNSIGNED | YES | NULL | FK -> `services.id` ON DELETE SET NULL | Primary requested catalog service |
| `appointment_date`| DATE | NO | None | INDEX (`idx_apt_date`) | Scheduled service date |
| `appointment_time`| TIME | NO | '09:00:00' | None | Scheduled check-in time |
| `notes` | TEXT | YES | NULL | None | Customer comments or specific problem description |
| `status` | ENUM | NO | 'Scheduled' | 'Scheduled','Confirmed','In Progress','Completed','Cancelled','No Show' | Current appointment status |
| `job_card_id` | INT UNSIGNED | YES | NULL | FK -> `job_cards.id` ON DELETE SET NULL | Linked job card if converted |
| `created_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Staff member who scheduled the appointment |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Creation timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Status update timestamp |

---

### 2.11 `job_cards`
*Core workshop work orders managing vehicle repair lifecycles.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique job card ID |
| `job_number` | VARCHAR(30) | NO | None | UNIQUE | Official tracking number (e.g. `JOB-202611-0001`) |
| `customer_id` | INT UNSIGNED | NO | None | FK -> `customers.id`, INDEX (`idx_job_customer`) | Customer reference |
| `vehicle_id` | INT UNSIGNED | NO | None | FK -> `vehicles.id`, INDEX (`idx_job_vehicle`) | Serviced vehicle reference |
| `mechanic_id` | INT UNSIGNED | YES | NULL | FK -> `mechanics.id` ON DELETE SET NULL | Primary technician assigned |
| `date_received`| DATE | NO | None | INDEX (`idx_job_date_rcv`) | Date vehicle arrived at garage |
| `reported_problem`| TEXT | NO | None | None | Customer's initial problem description |
| `initial_condition`| TEXT | YES | NULL | None | Visual check, fuel level, existing scratches/dents |
| `diagnosis` | TEXT | YES | NULL | None | Mechanic's technical diagnostic findings |
| `required_repairs`| TEXT | YES | NULL | None | Remedial action plan and required operations |
| `labor_cost` | DECIMAL(12,2)| NO | 0.00 | None | Base labor charges allocated to job |
| `estimated_completion`| DATE | YES | NULL | None | Target vehicle release date |
| `actual_completion`| DATE | YES | NULL | None | Timestamp vehicle repair concluded |
| `mileage_in` | INT UNSIGNED | YES | NULL | None | Odometer reading when received into workshop |
| `status` | ENUM | NO | 'Pending' | 'Pending','Diagnosing','Waiting for Parts','In Progress','Completed','Delivered','Cancelled' | Workshop workflow state |
| `created_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Staff member creating work order |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Creation timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Last status or detail modification timestamp |

---

### 2.12 `job_services`
*Service catalog items attached and tracked against a job card.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique record ID |
| `job_id` | INT UNSIGNED | NO | None | FK -> `job_cards.id` ON DELETE CASCADE | Parent repair job card |
| `service_id` | INT UNSIGNED | YES | NULL | FK -> `services.id` ON DELETE SET NULL | Linked catalog service |
| `service_name` | VARCHAR(150) | NO | None | None | Snapshot service description at assignment |
| `price` | DECIMAL(12,2)| NO | 0.00 | None | Agreed billing price for this service line |
| `status` | ENUM | NO | 'Pending' | 'Pending','In Progress','Completed','Cancelled' | Execution state of this specific task |
| `assigned_mechanic_id`| INT UNSIGNED| YES | NULL | FK -> `mechanics.id` ON DELETE SET NULL | Specific mechanic performing this task |
| `notes` | VARCHAR(255) | YES | NULL | None | Mechanic progress or inspection notes |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Attachment timestamp |

---

### 2.13 `spare_parts`
*Warehouse inventory tracking spare parts, cost, prices, and quantities.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique inventory part ID |
| `part_code` | VARCHAR(50) | NO | None | UNIQUE | Part number / SKU (e.g. `PRT-OILF-001`) |
| `part_name` | VARCHAR(150) | NO | None | INDEX (`idx_part_name`) | Item commercial name |
| `category` | VARCHAR(60) | YES | NULL | INDEX (`idx_part_category`)| Category (Filters, Brakes, Suspension, Fluids) |
| `quantity` | INT UNSIGNED | NO | 0 | None | Current physical on-hand stock quantity |
| `min_stock_level`| INT UNSIGNED| NO | 5 | None | Low stock reorder threshold |
| `cost_price` | DECIMAL(12,2)| NO | 0.00 | None | Purchase acquisition cost |
| `selling_price`| DECIMAL(12,2)| NO | 0.00 | None | Retail customer billing price |
| `unit` | VARCHAR(20) | NO | 'pcs' | None | Unit of measure (`pcs`, `liters`, `sets`, `kg`) |
| `location` | VARCHAR(60) | YES | NULL | None | Warehouse bin/shelf location |
| `is_active` | TINYINT(1) | NO | 1 | None | 1 = active part, 0 = discontinued |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Record creation timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Timestamp of last stock/price change |

---

### 2.14 `job_parts`
*Requisitioned inventory items issued and fitted to a job card.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique requisition item ID |
| `job_id` | INT UNSIGNED | NO | None | FK -> `job_cards.id` ON DELETE CASCADE, INDEX (`idx_jp_job`) | Work order consuming the part |
| `part_id` | INT UNSIGNED | NO | None | FK -> `spare_parts.id`, INDEX (`idx_jp_part`) | Inventory item issued |
| `quantity` | INT UNSIGNED | NO | 1 | None | Number of units issued |
| `unit_price` | DECIMAL(12,2)| NO | None | None | Unit selling price snapshot at issue |
| `total_price` | DECIMAL(12,2)| NO | None | None | Computed line total (`quantity * unit_price`) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Issue timestamp |

---

### 2.15 `inventory_transactions`
*Double-entry stock movement ledger for audit and stock reconciliation.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Transaction ledger ID |
| `part_id` | INT UNSIGNED | NO | None | FK -> `spare_parts.id`, INDEX (`idx_it_part`) | Target inventory item |
| `job_id` | INT UNSIGNED | YES | NULL | FK -> `job_cards.id` ON DELETE SET NULL | Linked job card (if issued or returned) |
| `user_id` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Staff member executing the transaction |
| `transaction_type`| ENUM | NO | None | 'in','out','return','adjustment' | Direction of stock movement |
| `quantity` | INT | NO | None | None | Signed movement (+ for in/return, - for out) |
| `previous_quantity`| INT UNSIGNED| NO | None | None | Physical stock balance before movement |
| `new_quantity` | INT UNSIGNED| NO | None | None | Resulting physical stock balance after movement |
| `notes` | VARCHAR(255) | YES | NULL | None | Movement justification, PO reference, or job number |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | INDEX (`idx_it_created`) | Ledger posting timestamp |

---

### 2.16 `invoices`
*Commercial billing invoices generated for repair jobs.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique invoice ID |
| `invoice_number`| VARCHAR(30) | NO | None | UNIQUE | Official invoice code (e.g. `INV-202611-0001`) |
| `job_id` | INT UNSIGNED | NO | UNIQUE | FK -> `job_cards.id`, INDEX (`idx_inv_job`) | 1-to-1 link to job card |
| `customer_id` | INT UNSIGNED | NO | None | FK -> `customers.id`, INDEX (`idx_inv_cust`) | Billing customer ID |
| `invoice_date` | DATE | NO | None | INDEX (`idx_inv_date`) | Issuance date |
| `due_date` | DATE | NO | None | None | Payment due date |
| `subtotal` | DECIMAL(12,2)| NO | 0.00 | None | Sum of labor, service catalog lines, and parts |
| `tax_rate` | DECIMAL(5,2) | NO | 15.00 | None | Percentage VAT / Tax rate applied |
| `tax_amount` | DECIMAL(12,2)| NO | 0.00 | None | Computed tax amount `(subtotal - discount) * tax_rate / 100` |
| `discount_amount`| DECIMAL(12,2)| NO | 0.00 | None | Total authorized discount applied |
| `grand_total` | DECIMAL(12,2)| NO | 0.00 | None | Net payable: `subtotal - discount + tax` |
| `amount_paid` | DECIMAL(12,2)| NO | 0.00 | None | Cumulative payments received minus refunds |
| `balance` | DECIMAL(12,2)| NO | 0.00 | None | Outstanding unpaid balance `grand_total - amount_paid` |
| `payment_status`| ENUM | NO | 'Unpaid' | 'Unpaid','Partially Paid','Paid','Overdue' | Current financial settlement state |
| `notes` | TEXT | YES | NULL | None | Payment terms, bank wiring notes, customer memo |
| `created_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Accountant or manager issuing invoice |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Invoice generation timestamp |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Last balance / payment update timestamp |

---

### 2.17 `invoice_items`
*Itemized billable rows composing an invoice.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique line item ID |
| `invoice_id` | INT UNSIGNED | NO | None | FK -> `invoices.id` ON DELETE CASCADE, INDEX (`idx_ii_invoice`) | Parent invoice reference |
| `item_type` | ENUM | NO | None | 'labor','part','service','other' | Classification of billable line |
| `item_reference_id`| INT UNSIGNED| YES | NULL | None | ID of underlying `spare_parts` or `services` |
| `description` | VARCHAR(255) | NO | None | None | Text displayed on printable invoice |
| `quantity` | DECIMAL(10,2)| NO | 1.00 | None | Quantity or labor hours billed |
| `unit_price` | DECIMAL(12,2)| NO | 0.00 | None | Unit rate charged |
| `total_price` | DECIMAL(12,2)| NO | 0.00 | None | Extended price (`quantity * unit_price`) |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Record timestamp |

---

### 2.18 `payments`
*Financial receipts recorded against invoices, including authorized refunds.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique payment receipt ID |
| `payment_number`| VARCHAR(30) | NO | None | UNIQUE | Receipt reference (e.g. `REC-202611-0001`) |
| `invoice_id` | INT UNSIGNED | NO | None | FK -> `invoices.id`, INDEX (`idx_pay_inv`) | Target invoice settled |
| `payment_date` | DATE | NO | None | INDEX (`idx_pay_date`) | Transaction date |
| `amount` | DECIMAL(12,2)| NO | None | None | Positive for receipts; negative for authorized refunds |
| `payment_method`| ENUM | NO | None | 'Cash','Bank Transfer','Check','Telebirr','Card','Other' | Settlement channel |
| `reference_number`| VARCHAR(100)| YES | NULL | None | Bank slip, Check #, or Telebirr transaction ID |
| `notes` | TEXT | YES | NULL | None | Accounting notes or refund justification |
| `received_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Staff member accepting funds |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Ledger timestamp |

---

### 2.19 `notifications`
*In-app messaging center for operational notifications and user alerts.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique notification ID |
| `user_id` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE CASCADE, INDEX (`idx_notif_user`)| Target user (NULL for role broadcast) |
| `role_id` | INT UNSIGNED | YES | NULL | FK -> `roles.id` ON DELETE CASCADE, INDEX (`idx_notif_role`)| Target role broadcast (e.g. all mechanics) |
| `title` | VARCHAR(150) | NO | None | None | Subject / headline |
| `message` | TEXT | NO | None | None | Notification body text |
| `type` | ENUM | NO | 'info' | 'info','success','warning','danger' | Severity & visual badge theme |
| `link` | VARCHAR(255) | YES | NULL | None | Action URL (e.g. `job-cards/view.php?id=12`) |
| `is_read` | TINYINT(1) | NO | 0 | INDEX (`idx_notif_read`) | Read state: 0 = Unread, 1 = Read |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | INDEX (`idx_notif_time`) | Issuance timestamp |

---

### 2.20 `audit_logs`
*Append-only cryptographic security and operational audit trail.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | BIGINT UNSIGNED| NO | AUTO_INCREMENT | PRIMARY KEY | Monotonically increasing sequence number |
| `event_time` | DATETIME(6) | NO | None | INDEX (`idx_audit_time`) | High-resolution microsecond timestamp |
| `user_id` | INT UNSIGNED | YES | NULL | INDEX (`idx_audit_user`) | Authenticated user ID (NULL for unauthenticated guest) |
| `username` | VARCHAR(50) | YES | NULL | None | Snapshot of actor username |
| `ip_address` | VARCHAR(45) | NO | None | INDEX (`idx_audit_ip`) | IPv4 or IPv6 client address |
| `user_agent` | VARCHAR(255) | YES | NULL | None | Browser/client User-Agent string |
| `action` | VARCHAR(60) | NO | None | INDEX (`idx_audit_action`) | Semantic event code (`login_failed`, `job_status_changed`) |
| `table_name` | VARCHAR(50) | YES | NULL | INDEX (`idx_audit_table`) | Target database table modified |
| `record_id` | VARCHAR(50) | YES | NULL | None | Primary key of target record |
| `old_value` | LONGTEXT | YES | NULL | None | Pre-modification state in JSON format (redacted of secrets) |
| `new_value` | LONGTEXT | YES | NULL | None | Post-modification state in JSON format (redacted of secrets) |
| `prev_hash` | CHAR(64) | NO | None | None | SHA-256 hash of previous audit record in the chain |
| `record_hash` | CHAR(64) | NO | None | UNIQUE, INDEX (`idx_audit_hash`)| SHA-256 HMAC digest of this record combined with `prev_hash` |

*Security Invariants:*
- `trg_audit_no_update`: Trigger throws SQLSTATE '45000' on any UPDATE.
- `trg_audit_no_delete`: Trigger throws SQLSTATE '45000' on any DELETE.

---

### 2.21 `audit_chain_head`
*Single-row mutex table ensuring serialized atomic hash generation.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | TINYINT UNSIGNED| NO | 1 | PRIMARY KEY, CHECK (`id = 1`) | Single row lock anchor |
| `last_hash` | CHAR(64) | NO | 64 zeros (`000...000`)| None | Hash of the most recently appended audit record |
| `last_id` | BIGINT UNSIGNED| NO | 0 | None | Sequence ID of the most recently appended audit record |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Timestamp of last chain advance |

---

### 2.22 `login_attempts`
*Authentication rate-limiting and brute-force mitigation log.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique attempt ID |
| `ip_address` | VARCHAR(45) | NO | None | INDEX (`idx_la_ip_time`) | Originating client IP |
| `username` | VARCHAR(50) | YES | NULL | INDEX (`idx_la_user_time`)| Targeted account username |
| `success` | TINYINT(1) | NO | 0 | None | 1 = Successful login, 0 = Bad credentials |
| `attempted_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | INDEX (`idx_la_time`) | Attempt timestamp |

---

### 2.23 `password_resets`
*Single-use cryptographic tokens for self-service password recovery.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Unique request ID |
| `user_id` | INT UNSIGNED | NO | None | FK -> `users.id` ON DELETE CASCADE | Target user requesting recovery |
| `token_hash` | CHAR(64) | NO | None | UNIQUE, INDEX (`idx_pr_hash`) | SHA-256 digest of 64-char hex random token |
| `expires_at` | DATETIME | NO | None | INDEX (`idx_pr_exp`) | Token expiry (1 hour after generation) |
| `used_at` | DATETIME | YES | NULL | None | Timestamp when token was consumed |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Request issuance timestamp |

---

### 2.24 `garage_settings`
*Garage profile and application configuration values.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `setting_key` | VARCHAR(60) | NO | None | PRIMARY KEY | Unique setting identifier |
| `setting_value` | TEXT | NO | None | None | Stored garage or application setting |
| `updated_by` | INT UNSIGNED | YES | NULL | None | User who last changed the setting |
| `updated_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP ON UPDATE | None | Last update time |

---

### 2.25 `job_updates`
*Job-card status, note, labor, assignment, and parts history.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Job update identifier |
| `job_id` | INT UNSIGNED | NO | None | FK -> `job_cards.id` ON DELETE CASCADE, INDEX (`idx_ju_job`) | Related job card |
| `user_id` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | User who recorded the update |
| `update_type` | ENUM | NO | None | `status`, `note`, `labor`, `assignment`, `parts` | Type of job event |
| `old_status` | VARCHAR(30) | YES | NULL | None | Previous status, when applicable |
| `new_status` | VARCHAR(30) | YES | NULL | None | New status, when applicable |
| `note` | TEXT | YES | NULL | None | Event details |
| `hours` | DECIMAL(6,2) | YES | NULL | None | Labor hours, when applicable |
| `amount` | DECIMAL(12,2) | YES | NULL | None | Labor amount, when applicable |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Event timestamp |

---

### 2.26 `expenses`
*Operating expense records entered by authorized finance users. Inventory stock-in purchases are summarized from `inventory_transactions` and must not be duplicated here.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | BIGINT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Expense record identifier |
| `category` | VARCHAR(40) | NO | None | None | Operating expense category |
| `description` | VARCHAR(255) | NO | None | None | Human-readable purpose |
| `amount` | DECIMAL(12,2) | NO | None | CHECK (`amount` > 0) | Recorded expense amount |
| `expense_date` | DATE | NO | None | INDEX (`idx_expense_date`) | Date paid and financial reporting date |
| `reference` | VARCHAR(80) | YES | NULL | None | Receipt or payment reference |
| `recorded_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | User who entered the record |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Record creation time |

---

### 2.27 `part_requests`
*Mechanic requests for warehouse parts and fulfillment/rejection state.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Request identifier |
| `job_id` | INT UNSIGNED | NO | None | FK -> `job_cards.id` ON DELETE CASCADE | Related job card |
| `part_id` | INT UNSIGNED | NO | None | FK -> `spare_parts.id` | Requested inventory part |
| `quantity` | INT UNSIGNED | NO | None | CHECK (`quantity` > 0) | Requested quantity |
| `note` | VARCHAR(255) | YES | NULL | None | Request details |
| `status` | ENUM | NO | `pending` | `pending`, `issued`, `rejected` | Request workflow state |
| `requested_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | Requesting user |
| `handled_by` | INT UNSIGNED | YES | NULL | FK -> `users.id` ON DELETE SET NULL | User who handled the request |
| `handled_at` | DATETIME | YES | NULL | None | Fulfillment or rejection time |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Request timestamp |

---

### 2.28 `contact_inquiries`
*Public website contact and support submissions.*

| Column Name | Data Type | Nullable | Default | Constraints | Description |
|---|---|---|---|---|---|
| `id` | INT UNSIGNED | NO | AUTO_INCREMENT | PRIMARY KEY | Inquiry identifier |
| `name` | VARCHAR(120) | NO | None | None | Contact name |
| `email` | VARCHAR(150) | NO | None | None | Contact email |
| `phone` | VARCHAR(40) | YES | NULL | None | Contact phone |
| `channel` | VARCHAR(40) | NO | `Web Contact Form` | None | Inquiry source |
| `subject` | VARCHAR(150) | NO | None | None | Inquiry subject |
| `message` | TEXT | NO | None | None | Inquiry content |
| `ip_address` | VARCHAR(45) | YES | NULL | None | Submission IP address |
| `status` | ENUM | NO | `new` | `new`, `in_progress`, `resolved` | Inquiry workflow status |
| `created_at` | TIMESTAMP | NO | CURRENT_TIMESTAMP | None | Submission time |

---
*End of Data Dictionary.*
