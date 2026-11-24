# ALPHA GARAGE — PHASE 1.11A
# DASHBOARD TESTING, ACCESSIBILITY & ACCEPTANCE PLAN

**Document Reference:** `docs/phase-1.11/PHASE_1_11A_TEST_AND_ACCEPTANCE_PLAN.md`  
**Phase:** Phase 1.11A — UI/UX Audit & Acceptance Test Plan  
**Target Architecture:** Native PHP 8.x, MySQL/MariaDB, HTML5, CSS3, Vanilla JS, Chart.js  
**Working Directory:** `c:/MAMP/htdocs/ALPHA-GARAGE/AutoPro_Garage-main`  
**Audit Authorization:** Strictly READ-ONLY (Specifications for Future Implementation Testing)  
**Status:** Completed  

---

## 1. OBJECTIVE & TESTING SCOPE

This comprehensive testing and verification plan establishes the rigorous quality gates, visual regression criteria, security preservation rules, and accessibility standards required before any future UI/UX implementation (Phase 1.11B+) can be accepted into ALPHA GARAGE.

### Scope of Testing:
1. **Six Role Dashboards:** Administrator, Receptionist, Mechanic, Inventory Manager, Accountant, Customer.
2. **Cross-Device & Viewport Responsiveness:** Desktop (1920px & 1366px), Tablet (1024px & 768px), and Mobile (414px & 375px).
3. **Accessibility Standards:** WCAG 2.1 AA contrast compliance, keyboard focus rings, semantic landmark structures, and screen-reader compatibility.
4. **Security & Boundary Invariants:** Strict preservation of server-side RBAC, mechanic workstation isolation, customer IDOR protection, and CSRF token verification.
5. **Administrator Dashboard Preservation:** 100% preservation of existing KPI algorithms, SQL queries, and Chart.js datasets.

---

## 2. CROSS-ROLE TESTING PROTOCOLS

```
+---------------------------------------------------------------------------------------------------+
|                                  ROLE DASHBOARD TEST SUITE MATRIX                                 |
+-------------------+--------------------+------------------------+---------------------------------+
| Dashboard Role    | Login Account      | Target Route           | Primary Verification Focus      |
+-------------------+--------------------+------------------------+---------------------------------+
| Administrator     | `admin`            | `dashboard.php`        | Financial KPIs, 7 Chart.js,     |
|                   |                    |                        | AJAX date filtering, targets    |
+-------------------+--------------------+------------------------+---------------------------------+
| Receptionist      | `receptionist`     | `dashboard.php`        | Today's schedule, intake queue, |
|                   |                    |                        | 1-click job card conversion     |
+-------------------+--------------------+------------------------+---------------------------------+
| Mechanic          | `mechanic1`        | `dashboard.php`        | Assigned queue IDOR isolation,  |
|                   |                    |                        | zero financial leakage          |
+-------------------+--------------------+------------------------+---------------------------------+
| Inventory Manager | `inventory`        | `dashboard.php`        | Reorder alerts, stock audit log,|
|                   |                    |                        | zero revenue/pricing leakage    |
+-------------------+--------------------+------------------------+---------------------------------+
| Accountant        | `accountant`       | `dashboard.php`        | Receivables aging queue, payment|
|                   |                    |                        | ledger, operating disbursements |
+-------------------+--------------------+------------------------+---------------------------------+
| Customer          | `customer` (user)  | `customer.php`         | Verified fleet list, repair     |
|                   |                    |                        | stage stepper, IDOR isolation   |
+-------------------+--------------------+------------------------+---------------------------------+
```

---

## 3. DETAILED TEST SCENARIOS

### 3.1 Administrator Dashboard Verification
- **Scenario ADM-01: Visual Preservation Invariant**
  - Verify that total revenue, net profit, profit margin, and expense figures match database queries exactly.
  - Assert that all 7 Chart.js charts render smoothly with zero JavaScript console warnings or errors.
  - Verify that AJAX date range filtering (`Today`, `Last 30 Days`, `Custom`) updates `.dashboard-data` without triggering a full page reload or breaking Chart.js canvas instances.
- **Scenario ADM-02: Target Progress Calculation**
  - Verify that progress meters cleanly communicate monthly target comparisons with explicit labels.
- **Scenario ADM-03: Responsive Chart Grid**
  - Verify that at viewports below 900px, 2-column chart grids cleanly collapse into a single vertical column (`.chart-grid { grid-template-columns: 1fr; }`).

### 3.2 Receptionist Dashboard Verification
- **Scenario REC-01: Front Desk Operational Speed**
  - Confirm that the top action bar provides immediate links to `+ Book appointment`, `+ New job card`, `+ New customer`, and `+ New vehicle`.
  - Verify that clicking "Convert to Job Card" on an arrived appointment pre-populates customer name, vehicle ID, and service selection without data loss.
- **Scenario REC-02: Tablet Table Density**
  - Test viewing the schedule on a tablet (768px–1024px). Ensure tables do not cause dual horizontal scrollbars simultaneously.
- **Scenario REC-03: Contact Integration**
  - Verify customer phone numbers render as active `tel:` links allowing instant click-to-dial on mobile/softphone clients.

### 3.3 Mechanic Workstation Verification
- **Scenario MECH-01: Horizontal IDOR Isolation**
  - Sign in as `mechanic1` (Abel Girma) and verify that all jobs in the active repair queue have `mechanic_id = 1`.
  - Assert that job cards assigned to `mechanic2` (Yonas Haile) or unassigned tickets are completely absent from the queue.
  - Attempt direct HTTP GET request to `job-cards/view.php?id={other_mechanic_job}` and assert HTTP 403 Forbidden is returned.
- **Scenario MECH-02: Complete Financial Redaction**
  - Verify that billing amounts, invoice totals, parts profit margins, and labor rates do not appear anywhere in the mechanic HTML DOM or JavaScript payloads.
- **Scenario MECH-03: Diagnostic Visibility**
  - Verify that reported customer complaints and preliminary diagnostic notes are legible without truncation that cuts off critical safety details.

### 3.4 Inventory Manager Dashboard Verification
- **Scenario INV-01: Stock Alert Accuracy**
  - Verify that items with `quantity = 0` display the high-contrast `Depleted` badge and sort to the top of the table.
  - Verify that items with `quantity <= reorder_level` display the `Low Stock` warning badge.
- **Scenario INV-02: 1-Click Restock Trigger**
  - Verify that clicking "+ Restock" opens the procurement/purchase entry form with part SKU pre-selected.
- **Scenario INV-03: Revenue Redaction**
  - Confirm that parts sales revenue, customer markup, and invoice settlement figures are completely excluded from the inventory dashboard.

### 3.5 Accountant Dashboard Verification
- **Scenario ACC-01: Receivables Aging Queue**
  - Verify that outstanding unpaid invoices sort by issue date ascending, placing oldest delinquent accounts at the top.
  - Assert that aging brackets (Current, 30–60 Days, 61–90 Days, 90+ Days) calculate accurately from `CURDATE() - issue_date`.
- **Scenario ACC-02: Payment Method Breakdown**
  - Confirm that cash, bank transfer, card, and mobile payments (Telebirr, CBE Birr) are clearly categorized.
- **Scenario ACC-03: Graceful Degradation Without Expense Ledger**
  - When the optional `expenses` table is unavailable, verify the dashboard renders an informative setup prompt without throwing unhandled PDO exceptions.

### 3.6 Customer VIP Portal Verification
- **Scenario CUST-01: Clean Asset Loading (Zero 404s)**
  - Open customer portal in browser developer tools (Network tab).
  - Assert that **zero HTTP 404 errors** are logged (verifying removal of broken `luxury.css` and `app.css` links).
  - Verify that all shared CSS assets (`variables.css`, `layout.css`, `components.css`, `tables.css`, `dashboard.css`, `responsive.css`, `theme.css`) load with HTTP 200/304 status.
- **Scenario CUST-02: Strict Data Ownership Scoping**
  - Verify that the customer portal strictly renders vehicles and appointments matching the user's verified `user_id` and explicitly linked IDs.
  - Assert that attempting to view or book against an unlinked vehicle ID returns HTTP 403.
- **Scenario CUST-03: Neutral Account Linking (Anti-Enumeration)**
  - Verify that submitting a service history link request displays the exact same neutral confirmation message whether 0, 1, or duplicate walk-in records exist.
- **Scenario CUST-04: Mobile Vehicle Table Stacking**
  - Resize viewport to 375px (iPhone standard).
  - Assert that the "My Vehicles" and "Service Appointments" tables cleanly stack into labelled cards using `data-label` attributes without horizontal viewport overflow.

---

## 4. ACCESSIBILITY & VIEWPORT QUALITY AUDIT CHECKLIST

```
+---------------------------------------------------------------------------------------------------+
|                                 ACCESSIBILITY & RESPONSIVE CHECKLIST                              |
+--------------------+---------------------------------------------+--------------------------------+
| Verification Item  | Target Standard                             | Pass Criteria                  |
+--------------------+---------------------------------------------+--------------------------------+
| Text Contrast      | WCAG 2.1 AA (4.5:1 normal, 3.0:1 large)     | Gold text NEVER on white cards |
| Focus Indicators   | Visible outline on keyboard focus           | 3px focus ring on all inputs   |
| Status Badges      | Dual Visual Cues (Text + Symbol)            | Icons: ✓, ▲, ✕, ●, –           |
| Screen Reader ARIA | Semantic Landmarks & Form Associations      | aria-label on icon buttons     |
| Off-Canvas Drawer  | Keyboard trap & Escape dismissal            | Modal traps focus when open    |
| Viewport 1366px    | Standard Desktop Laptop                     | Zero horizontal scroll         |
| Viewport 1024px    | Tablet Landscape                            | Clean 2-column or stacked grid |
| Viewport 768px     | Tablet Portrait (iPad)                      | Sidebar drawer transforms      |
| Viewport 375px     | Mobile Screen (iPhone SE / Android)         | Tables stack with data-label   |
+--------------------+---------------------------------------------+--------------------------------+
```

---

## 5. SECURITY & FUNCTIONALITY PRESERVATION CRITERIA

Before accepting any future UI/UX implementation:

1. **CSRF Validation:**
   Every newly introduced button, modal confirmation, or status change must be submitted as an HTTP POST accompanied by `<?= csrf_field() ?>` and validated via `verify_csrf()`.
2. **Strict Output Escaping:**
   All dynamic strings rendered in templates (customer names, plate numbers, problem notes, error messages) must pass through `e()` (HTML special characters escaping) to prevent XSS.
3. **No Client-Side Authorization:**
   Navigation items, status actions, and form inputs must never rely solely on CSS `display: none` or hidden attributes for security. The destination PHP controllers must enforce server-side `require_permission()` and ownership checks.
4. **Audit Hash Chain Integrity:**
   Dynamic state updates must continue to invoke `AuditLogger::log()` and satisfy `AuditLogger::verifyChain()` across all operations.

---

## 6. FINAL ACCEPTANCE SIGN-OFF CRITERIA

Phase 1.11B+ implementation may only receive final approval when:
1. All 6 dashboards conform visually to the **Deep Dark Forest Green & Warm Gold** design system.
2. The Customer Dashboard is fully integrated into the shared application shell without 404 asset errors.
3. All tables stack cleanly on mobile viewports (<640px) with complete `data-label` annotations.
4. WCAG 2.1 AA contrast passes 100% across both light and dark themes.
5. All 125 automated unit, safety, regression, and role tests continue to pass with a 100% success rate.
6. The live production database `garage_management_system` remains 100% untouched and pristine.

---
*Testing & acceptance plan authored under read-only audit authorization.*
