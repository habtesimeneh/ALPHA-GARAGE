# ALPHA GARAGE — PHASE 1.11D: CHART SQL & DATA VERIFICATION
## Independent Inspection of Query Logic, Aggregations, Boundaries & Payload Contracts

**Project:** ALPHA GARAGE Workshop Management System  
**Phase:** 1.11D  
**Author:** AI Systems Architecture & Security Lead (Independent Audit)  
**Date:** 2026-11-18  
**Audit Scope:** SQL queries, PDO parameterization, date arithmetic, aggregation accuracy & JSON contracts  

---

## 1. Executive Summary

This report documents the independent verification of every SQL query introduced or utilized for role-based dashboard charts across the ALPHA GARAGE platform.

All database queries executed across all six dashboard controllers were verified to be:
1. **100% Parameterized:** Utilizing PDO prepared statements with positional parameters (`?`) or strictly sanitized integer lists (`array_map('intval', ...)`).
2. **Zero N+1 Query Anti-Patterns:** All chart data is aggregated server-side via SQL `GROUP BY` or single-pass queries.
3. **Zero Fabricated/Mock Data:** All metrics originate from real database tables (`appointments`, `job_cards`, `spare_parts`, `inventory_transactions`, `invoices`, `payments`).
4. **Zero Join Duplications:** Joins are either absent (pure single-table aggregation) or properly partitioned.

However, an independent inspection revealed that while the SQL queries correctly calculate the underlying database metrics in PHP, the resulting JSON payloads for four dashboards diverge from what `public/assets/js/dashboard.js` parses, preventing data from reaching the chart canvases.

---

## 2. Query Inspection by Role

### 2.1 Receptionist Dashboard (`app/views/dashboards/receptionist.php`)

#### Query 1: Appointment Status Distribution
```sql
SELECT status, COUNT(*) as cnt 
FROM appointments 
GROUP BY status
```
- **Source Table:** `appointments`
- **Filters:** None (whole-garage operational intake).
- **Aggregation:** `COUNT(*)` grouped by `status`.
- **PHP Post-Processing:**
  ```php
  $apptStatusCounts = ['scheduled' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
  foreach ($apptStatusRows as $r) {
      if (isset($apptStatusCounts[$r['status']])) {
          $apptStatusCounts[$r['status']] = (int) $r['cnt'];
      }
  }
  ```
- **Payload Emitted:** `['apptStatus' => ['scheduled' => N, 'confirmed' => N, ...]]`
- **`dashboard.js` Integration:** Lines 273–284 consume `p.apptStatus` directly as key-value pairs matching status names. **STATUS: MATCHED & FUNCTIONAL**.

#### Query 2: 14-Day Booking Intake Trend
```sql
SELECT appointment_date, COUNT(*) as cnt
FROM appointments
WHERE appointment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND CURDATE()
GROUP BY appointment_date
ORDER BY appointment_date ASC
```
- **Source Table:** `appointments`
- **Date Range:** `DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND CURDATE()` (14 calendar days inclusive).
- **PHP Post-Processing:** Chronological loop filling zero-booking days from `$curDay = new DateTimeImmutable('-13 days')` to `'today'`.
- **Payload Emitted:** `['apptTrend' => [ ['label' => 'Nov 5', 'count' => N], ... ]]`
- **`dashboard.js` Integration:** Lines 295–306 map `p.apptTrend` with `r.label` and `r.count`. **STATUS: MATCHED & FUNCTIONAL**.

---

### 2.2 Mechanic Dashboard (`app/views/dashboards/mechanic.php`)

#### Query 1: Workstation Job Status Distribution
```sql
SELECT status, COUNT(*) AS count_val 
FROM job_cards 
WHERE mechanic_id = ? 
GROUP BY status
```
- **Source Table:** `job_cards`
- **Parameters:** `[$mechanicId]`
- **Authorization Boundary:** Strictly constrained to authenticated technician.
- **SQL Accuracy:** Correct.

#### Query 2: Workstation Urgent & High Priority Distribution
```sql
SELECT priority, COUNT(*) AS count_val 
FROM job_cards 
WHERE mechanic_id = ? 
  AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress') 
GROUP BY priority
```
- **Source Table:** `job_cards`
- **Parameters:** `[$mechanicId]`
- **Filters:** Open repair states only. Grouped by `priority`.
- **SQL Accuracy:** Correct.

#### Query 3: 6-Month Completion Throughput
```sql
SELECT DATE_FORMAT(COALESCE(actual_completion, date_received), '%Y-%m') AS ym, COUNT(*) AS count_val
FROM job_cards
WHERE mechanic_id = ? 
  AND status IN ('Completed','Delivered')
  AND COALESCE(actual_completion, date_received) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY ym
```
- **Source Table:** `job_cards`
- **Parameters:** `[$mechanicId]`
- **Date Boundary:** Rolling 6 months. Uses `COALESCE(actual_completion, date_received)`.
- **SQL Accuracy:** Correct.

#### Mechanic Payload Contract Analysis (FIND-111D-01A):
- **PHP Emitted Payload (`$mechanicChartPayload`):**
  ```json
  {
    "jobStatus": { "labels": ["In Progress", "Completed"], "data": [2, 5] },
    "priorityDist": { "labels": ["High", "Normal"], "data": [1, 1] },
    "monthlyTrend": { "labels": ["Jun 2026", "Jul 2026", ...], "data": [0, 2, ...] }
  }
  ```
- **`dashboard.js` Expected Payload (Lines 313–365):**
  - Line 314: `p.jobStatus[k]` expects `{ "In Progress": 2, "Completed": 5 }`. Because `p.jobStatus` has keys `"labels"` and `"data"`, `p.jobStatus[k]` evaluates to `undefined`. Result: defaults to `"No assigned jobs"`.
  - Line 335: `p.priorityDist[k]` expects `{ "High": 1, "Normal": 1 }`. Evaluates to `undefined`. Result: defaults to `"No active tasks"`.
  - Line 360: `p.monthlyTrend.length` expects array of `{ label, count }`. Evaluates to `undefined`. Result: defaults to `"No history"`.

---

### 2.3 Inventory Manager Dashboard (`app/views/dashboards/inventory.php`)

#### Query 1: Stock Health Quantities
```sql
SELECT COUNT(*) FROM spare_parts WHERE is_active = 1;
SELECT COUNT(*) FROM spare_parts WHERE is_active = 1 AND quantity <= reorder_level AND quantity > 0;
SELECT COUNT(*) FROM spare_parts WHERE is_active = 1 AND quantity = 0;
```
- **Source Table:** `spare_parts`
- **Logic:**
  - Depleted: `quantity = 0`.
  - Low Stock: `quantity <= reorder_level AND quantity > 0`.
  - Healthy: `$totalPartTypes - $lowStockCount - $outOfStockCount`.
- **SQL Accuracy:** Correct.

#### Query 2: Physical Units by Top Categories
```sql
SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS cat_name, COALESCE(SUM(quantity), 0) AS total_units
FROM spare_parts
WHERE is_active = 1
GROUP BY cat_name
ORDER BY total_units DESC
LIMIT 6
```
- **Source Table:** `spare_parts`
- **Financial Boundary:** Selects `SUM(quantity)` only. Zero cost or pricing columns.
- **SQL Accuracy:** Correct.

#### Query 3: Stock Movement Transactions by Type
```sql
SELECT txn_type, COUNT(*) AS count_val
FROM inventory_transactions
GROUP BY txn_type
ORDER BY count_val DESC
```
- **Source Table:** `inventory_transactions`
- **SQL Accuracy:** Correct.

#### Inventory Payload Contract Analysis (FIND-111D-01B):
- **PHP Emitted Payload (`$inventoryChartPayload`):**
  ```json
  {
    "stockHealth": { "labels": ["Healthy Stock", "Low Stock", "Out of Stock"], "data": [10, 2, 1] },
    "categories": { "labels": ["Brakes", "Filters"], "data": [45, 30] },
    "transactions": { "labels": ["Purchases", "Issues"], "data": [12, 8] }
  }
  ```
- **`dashboard.js` Expected Payload (Lines 371–424):**
  - Line 374: `p.stockHealth.Healthy` expects `{ "Healthy": 10, "Low Stock": 2, "Out of Stock": 1 }`. Evaluates to `[0, 0, 0]`.
  - Line 398: `p.categories.units` and `p.categories.items` expected. Both evaluate to `undefined`.
  - Line 412: `p.transactions[k].qty` expects `{ "purchase": { "qty": 12 }, ... }`. Evaluates to `0`.

---

### 2.4 Accountant Dashboard (`app/views/dashboards/accountant.php`)

#### Query 1: Invoice Settlement Status
```sql
SELECT payment_status, COUNT(*) AS count_val 
FROM invoices 
GROUP BY payment_status
```
- **Source Table:** `invoices`
- **SQL Accuracy:** Correct.

#### Query 2: Receivables Aging Brackets
```sql
SELECT
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) <= 30 THEN balance ELSE 0 END), 0) AS age_30,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 31 AND 60 THEN balance ELSE 0 END), 0) AS age_60,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 61 AND 90 THEN balance ELSE 0 END), 0) AS age_90,
    COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) > 90 THEN balance ELSE 0 END), 0) AS age_over_90
FROM invoices
WHERE payment_status IN ('Unpaid', 'Partially Paid')
```
- **Source Table:** `invoices`
- **Boundary Verification:**
  - Day 30: included in `<= 30`.
  - Day 31: included in `31 AND 60`.
  - Day 60: included in `31 AND 60`.
  - Day 61: included in `61 AND 90`.
  - Day 90: included in `61 AND 90`.
  - Day 91: included in `> 90`.
- **Arithmetic:** Sums unpaid remainder (`balance`). Zero double counting.
- **SQL Accuracy:** Correct.

#### Query 3: Net Collections (6 Months)
```sql
SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym,
       COALESCE(SUM(CASE WHEN kind = 'payment' THEN amount ELSE -amount END), 0) AS net_rev
FROM payments
WHERE paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY ym
```
- **Source Table:** `payments`
- **Deduction Invariant:** Correctly subtracts refunds (`kind = 'refund'`).
- **SQL Accuracy:** Correct.

#### Query 4: Payment Tender Distribution
```sql
SELECT method, COUNT(*) AS count_val, COALESCE(SUM(amount), 0) AS sum_amount
FROM payments
WHERE kind = 'payment'
GROUP BY method
ORDER BY count_val DESC
```
- **Source Table:** `payments`
- **SQL Accuracy:** Correct.

#### Accountant Payload Contract Analysis (FIND-111D-01C):
- **PHP Emitted Payload (`$accountantChartPayload`):**
  ```json
  {
    "invoiceStatus": { "labels": ["Paid", "Unpaid"], "data": [3, 1] },
    "agingBrackets": { "labels": ["0–30 Days", ...], "data": [5000, 0, 0, 0] },
    "monthlyRevenue": { "labels": ["Jun 2026", ...], "data": [12000, ...] },
    "paymentMethods": { "labels": ["Cash", "Card"], "data": [2, 1] }
  }
  ```
- **`dashboard.js` Expected Payload (Lines 429–500):**
  - Line 432: `p.invoiceStatus[k]` expects `{ "Paid": 3, "Unpaid": 1 }`. Evaluates to `"No invoices"`.
  - Line 455: `Object.keys(p.agingBrackets)` expects dictionary `{ "0–30 Days": 5000, ... }`. Keys evaluate to `["labels", "data"]`.
  - Line 469: `p.monthlyRevenue && p.monthlyRevenue.length` expects array of `{ label, amount }`. Evaluates to `undefined`. Chart fails guard and does not draw.
  - Line 485: `p.paymentMethods && p.paymentMethods.length` expects array of `{ method, amount }`. Evaluates to `undefined`. Chart fails guard and does not draw.

---

### 2.5 Customer Dashboard (`app/views/dashboards/customer.php`)

#### Query 1: Customer Repair Stages
```sql
SELECT status, COUNT(*) AS count_val
FROM job_cards
WHERE customer_id IN ($inClause)
GROUP BY status
```
- **Source Table:** `job_cards`
- **Scoping:** Strictly `$inClause` built with `array_map('intval', ...)`.
- **SQL Accuracy:** Correct.

#### Query 2: Customer 6-Month Maintenance Activity
```sql
SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS count_val
FROM appointments
WHERE customer_id IN ($inClause)
  AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY ym;

SELECT DATE_FORMAT(COALESCE(actual_completion, date_received), '%Y-%m') AS ym, COUNT(*) AS count_val
FROM job_cards
WHERE customer_id IN ($inClause)
  AND COALESCE(actual_completion, date_received) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY ym;
```
- **Source Tables:** `appointments`, `job_cards`
- **Aggregation:** Combines appointment checkups and repair handoffs.
- **SQL Accuracy:** Correct.

#### Customer Payload Contract Analysis (FIND-111D-01D):
- **PHP Emitted Payload (`$customerChartPayload`):**
  ```json
  {
    "jobStatus": { "labels": ["Completed"], "data": [2] },
    "history": { "labels": ["Jun 2026", ...], "data": [1, ...] }
  }
  ```
- **`dashboard.js` Expected Payload (Lines 505–538):**
  - Line 507: `p.jobStatus[k]` expects `{ "Completed": 2 }`. Evaluates to `"No active repair orders"`.
  - Line 526: `p.history && p.history.length` expects array of `{ label, count }`. Evaluates to `undefined`. Chart fails guard and does not draw.

---

## 3. SQL & Data Verification Matrix Summary

| Dashboard | Metric / Chart | SQL Parameterization | Join / Boundary Logic | PHP Query Validity | JS Payload Alignment |
|---|---|---|---|---|---|
| Receptionist | Appointment Status | Prepared (Table) | Valid | **PASSED** | **ALIGNED** |
| Receptionist | 14-Day Intake Trend | Prepared (Date range) | 14 days inclusive | **PASSED** | **ALIGNED** |
| Mechanic | Job Status | `[$mechanicId]` | Scoped to tech | **PASSED** | **MISALIGNED (FIND-111D-01A)** |
| Mechanic | Priority Breakdown | `[$mechanicId]` | Active jobs only | **PASSED** | **MISALIGNED (FIND-111D-01A)** |
| Mechanic | 6-Month Throughput | `[$mechanicId]` | Rolling 6 mo | **PASSED** | **MISALIGNED (FIND-111D-01A)** |
| Inventory | Stock Health | Count queries | Real thresholds | **PASSED** | **MISALIGNED (FIND-111D-01B)** |
| Inventory | Category Units | Active stock only | Physical units | **PASSED** | **MISALIGNED (FIND-111D-01B)** |
| Inventory | Movements | Ledger audit | Grouped by type | **PASSED** | **MISALIGNED (FIND-111D-01B)** |
| Accountant | Invoice Status | Ledger audit | Grouped by status | **PASSED** | **MISALIGNED (FIND-111D-01C)** |
| Accountant | Receivables Aging | Unpaid balances | No gaps/overlap | **PASSED** | **MISALIGNED (FIND-111D-01C)** |
| Accountant | Net Revenue | Payments minus refunds | Rolling 6 mo | **PASSED** | **MISALIGNED (FIND-111D-01C)** |
| Accountant | Payment Methods | Payments only | Inward tender | **PASSED** | **MISALIGNED (FIND-111D-01C)** |
| Customer | Repair Stages | `customer_id IN (...)` | Scoped to user | **PASSED** | **MISALIGNED (FIND-111D-01D)** |
| Customer | Service History | `customer_id IN (...)` | Rolling 6 mo | **PASSED** | **MISALIGNED (FIND-111D-01D)** |

**Conclusion:** All SQL queries are mathematically sound, secure, parameterized, and correctly compute business metrics. The single defect is the serialization contract mismatch between the PHP views and `dashboard.js`.
