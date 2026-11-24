# ALPHA GARAGE — PHASE 1.11E: CANONICAL CHART PAYLOAD CONTRACT
## Integration Specification for Role Dashboards & Chart.js Engine

**Project:** ALPHA GARAGE Workshop Management System  
**Phase:** 1.11E  
**Author:** AI Systems Architecture & Security Lead  
**Date:** 2026-11-18  
**Scope:** Universal Chart Data Contract, JSON Serialization Invariants, Normalization Architecture  
**Status:** Approved & Implemented  

---

## 1. Executive Summary

During Phase 1.11D independent technical audit, finding **FIND-111D-01** confirmed that four role-based dashboard views (`mechanic.php`, `inventory.php`, `accountant.php`, `customer.php`) serialized chart payloads using standard `{ labels: [...], data: [...] }` formats, while `public/assets/js/dashboard.js` expected heterogeneous associative dictionaries or arrays of objects.

This document establishes the **Universal Canonical Chart Payload Contract** for ALPHA GARAGE. It formally reconciles data producers (PHP dashboard views) and data consumers (JavaScript Chart.js engine) under a single, robust, strongly-typed integration standard.

---

## 2. Universal Canonical Contract Specification

All role-specific chart datasets adhere to the canonical schema:

```json
{
  "labels": ["String", "String", "..."],
  "data": [Number, Number, "..."]
}
```

### 2.1 Core Invariants

1. **Strict 1-to-1 Correspondence:** Every index `$i` in `labels` corresponds directly to value `$i` in `data`.
2. **Strict Length Matching:** `count(labels) === count(data)`.
3. **Strict Numeric Serialization:** All values in `data` are serialized as native numbers (`int` for counts and physical units, `float` for currency amounts), never numeric strings (e.g. `12` or `12.50`, not `"12"`).
4. **Valid Empty Arrays (Zero Fabricated Placeholders):** When an entity has zero records or is unlinked, the PHP serialization emits valid empty arrays:
   ```json
   {
     "labels": [],
     "data": []
   }
   ```
   No fabricated placeholder labels (e.g., `["No Assigned Jobs"]` with `[0]`) are permitted in database-derived payloads.
5. **Output Escaping & Security Flags:** All JSON payloads are serialized with:
   `json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)`
   preventing XSS injection across HTML `data-chart-payload` attributes.
6. **Sequential Zero-Indexed Lists:** All PHP arrays are wrapped with `array_values()` prior to serialization to guarantee encoding as JSON arrays `[...]` rather than associative objects `{"0": ...}`.

---

## 3. Chart-by-Chart Payload Specification

### 3.1 Receptionist Dashboard (2 Charts)

| Chart Identifier | Canvas ID | Payload Key | Canonical Structure | Value Type | Description |
|---|---|---|---|---|---|
| Appointment Status | `chart-reception-appts-status` | `apptStatus` | Associative dictionary preserved: `{"scheduled": N, "confirmed": N, ...}` | `int` | Real-time intake distribution across appointment statuses. |
| Booking Trend (14 Days) | `chart-reception-appts-trend` | `apptTrend` | Array of objects preserved: `[{"label": "Nov 5", "count": N}, ...]` | `int` | Chronological intake across rolling 14 calendar days. |

### 3.2 Mechanic Workstation Dashboard (3 Charts)

| Chart Identifier | Canvas ID | Payload Key | Canonical Structure | Value Type | Description |
|---|---|---|---|---|---|
| Job Card Status | `chart-mechanic-jobs-status` | `jobStatus` | `{ labels: string[], data: int[] }` | `int` | Active and completed repair cards assigned to the authenticated mechanic. Empty: `{ labels: [], data: [] }`. |
| Priority Distribution | `chart-mechanic-priority-dist` | `priorityDist` | `{ labels: string[], data: int[] }` | `int` | Urgent, High, and Normal repair orders in active progress. Empty: `{ labels: [], data: [] }`. |
| 6-Month Completion Trend | `chart-mechanic-completion-trend` | `monthlyTrend` | `{ labels: string[], data: int[] }` | `int` | Completed and delivered repairs across rolling 6 calendar months. Empty (if unassigned): `{ labels: [], data: [] }`. |

### 3.3 Inventory Manager Dashboard (3 Charts)

| Chart Identifier | Canvas ID | Payload Key | Canonical Structure | Value Type | Description |
|---|---|---|---|---|---|
| Stock Health Quantities | `chart-inventory-stock-health` | `stockHealth` | `{ labels: string[], data: int[] }` | `int` | Physical counts of Healthy Stock, Low Stock, and Out of Stock parts. Empty: `{ labels: [], data: [] }`. |
| Units by Category | `chart-inventory-category-units` | `categories` | `{ labels: string[], data: int[] }` | `int` | On-shelf physical units across top 6 parts categories. Empty: `{ labels: [], data: [] }`. |
| Stock Movement Breakdown | `chart-inventory-movement-trend` | `transactions` | `{ labels: string[], data: int[] }` | `int` | Quantity of movements by type (Purchases, Issues, Returns, Adjustments). Empty: `{ labels: [], data: [] }`. |

### 3.4 Accountant Financial Dashboard (4 Charts)

| Chart Identifier | Canvas ID | Payload Key | Canonical Structure | Value Type | Description |
|---|---|---|---|---|---|
| Invoice Payment Status | `chart-accountant-invoice-status` | `invoiceStatus` | `{ labels: string[], data: int[] }` | `int` | Counts of Paid, Partially Paid, Unpaid, and Refunded invoices. Empty: `{ labels: [], data: [] }`. |
| Receivables Aging Brackets | `chart-accountant-aging-brackets` | `agingBrackets` | `{ labels: string[], data: float[] }` | `float` | Outstanding unpaid balances across 0–30, 31–60, 61–90, and 90+ days. Empty: `{ labels: [], data: [] }`. |
| Monthly Net Revenue | `chart-accountant-revenue-trend` | `monthlyRevenue` | `{ labels: string[], data: float[] }` | `float` | Net collected revenue (payments minus refunds) across rolling 6 months. |
| Payment Tenders | `chart-accountant-payment-methods` | `paymentMethods` | `{ labels: string[], data: int[] }` | `int` | Tender counts by payment method (Cash, Bank Transfer, POS/Card, etc.). Empty: `{ labels: [], data: [] }`. |

### 3.5 Customer Portal Dashboard (2 Charts)

| Chart Identifier | Canvas ID | Payload Key | Canonical Structure | Value Type | Description |
|---|---|---|---|---|---|
| Customer Repair Stages | `chart-customer-repair-status` | `jobStatus` | `{ labels: string[], data: int[] }` | `int` | Customer active repair stages strictly scoped to linked customer IDs. Empty: `{ labels: [], data: [] }`. |
| Service Visit History | `chart-customer-service-history` | `history` | `{ labels: string[], data: int[] }` | `int` | Frequency of service bookings and completed repair visits across rolling 6 months. Empty: `{ labels: [], data: [] }`. |

### 3.6 Administrator Command Center (7 Charts Preserved)

The Administrator Command Center payload structures remain 100% preserved and untouched to maintain full compatibility with existing analytics formulas, KPI aggregations, and AJAX period filters:

- `financial`: `[{ label, revenue, expenses, profit }, ...]`
- `revenue`: `[{ label, value }, ...]`
- `expensesByCategory`: `[{ label, value }, ...]`
- `jobStatus`: `{'Pending': N, 'In Progress': N, ...}`
- `inventoryByCategory`: `[{ label, units }, ...]`
- `topServices`: `[{ label, revenue, count }, ...]`

---

## 4. Centralized Normalization Architecture (`normalizeChartData`)

To guarantee fail-soft resilience, `public/assets/js/dashboard.js` introduces a centralized normalization helper:

```javascript
function normalizeChartData(raw) {
  if (!raw || typeof raw !== 'object') {
    return { labels: [], data: [], isEmpty: true };
  }

  // Case 1: Canonical structure { labels: [...], data: [...] }
  if (Array.isArray(raw.labels) && Array.isArray(raw.data)) {
    var minLen = Math.min(raw.labels.length, raw.data.length);
    var labels = [];
    var data = [];
    var hasNonZero = false;
    for (var i = 0; i < minLen; i++) {
      var lbl = raw.labels[i];
      labels.push(String(lbl !== undefined && lbl !== null ? lbl : ''));
      var val = Number(raw.data[i]);
      if (isNaN(val)) val = 0;
      data.push(val);
      if (val !== 0) hasNonZero = true;
    }
    return { labels: labels, data: data, isEmpty: labels.length === 0 || !hasNonZero };
  }

  // Case 2: Array of objects [{ label/method/name: '...', count/value/amount/net/units/data: N }]
  if (Array.isArray(raw)) {
    var aLabels = [];
    var aData = [];
    var aHasNonZero = false;
    for (var j = 0; j < raw.length; j++) {
      var item = raw[j];
      if (item && typeof item === 'object') {
        var itemLbl = item.label !== undefined ? item.label : (item.method !== undefined ? item.method : (item.name !== undefined ? item.name : ''));
        var v = item.count !== undefined ? item.count : (item.value !== undefined ? item.value : (item.amount !== undefined ? item.amount : (item.net !== undefined ? item.net : (item.units !== undefined ? item.units : (item.data !== undefined ? item.data : 0)))));
        var num = Number(v);
        if (isNaN(num)) num = 0;
        aLabels.push(String(itemLbl !== undefined && itemLbl !== null ? itemLbl : ''));
        aData.push(num);
        if (num !== 0) aHasNonZero = true;
      }
    }
    return { labels: aLabels, data: aData, isEmpty: aLabels.length === 0 || !aHasNonZero };
  }

  // Case 3: Key-value dictionary { 'Key': N, ... } or { 'Key': { qty: N }, ... }
  var keys = Object.keys(raw);
  var dLabels = [];
  var dData = [];
  var dHasNonZero = false;
  for (var k = 0; k < keys.length; k++) {
    var key = keys[k];
    var rawVal = raw[key];
    var dNum = 0;
    if (rawVal && typeof rawVal === 'object' && 'qty' in rawVal) {
      dNum = Number(rawVal.qty) || 0;
    } else {
      dNum = Number(rawVal) || 0;
    }
    if (isNaN(dNum)) dNum = 0;
    dLabels.push(String(key));
    dData.push(dNum);
    if (dNum !== 0) dHasNonZero = true;
  }
  return { labels: dLabels, data: dData, isEmpty: dLabels.length === 0 || !dHasNonZero };
}
```

### 4.1 Safety Guarantees Provided by `normalizeChartData`

1. **Format Agnosticism:** Seamlessly deserializes canonical `{ labels, data }`, arrays of objects, and associative dictionaries.
2. **Type Coercion:** Explicitly forces values to numbers; handles strings like `"12"` or `"12.5"` and coerces `NaN`/`null`/`undefined` to `0`.
3. **Length Bounding:** Prevents index out-of-bounds errors by clamping to `Math.min(labels.length, data.length)`.
4. **Empty State Detection:** Identifies empty or all-zero datasets via `isEmpty: boolean`.

---

## 5. Visual Empty-State Handling Standards

When datasets are empty:
- **Doughnut Charts (`type: 'doughnut'`):** Render a single neutral placeholder arc with value `[1]`, color `#CBD5CD`, and an appropriate role-specific empty label:
  - Mechanic Jobs: `['No assigned jobs']`
  - Mechanic Priority: `['No active tasks']`
  - Inventory Stock Health: `['No stock data']`
  - Accountant Invoices: `['No invoices']`
  - Accountant Payment Methods: `['No payments']`
  - Customer Repairs: `['No active repair orders']`
  - Receptionist Appointments: `['No appointments']`
- **Bar & Line Charts (`type: 'bar'`, `type: 'line'`):** Display standard baseline axes with fallback label `['No history']` or `['No parts cataloged']` and value `[0]`, preventing rendering errors and maintaining layout symmetry.

---

## 6. Conclusion

The Universal Canonical Chart Payload Contract resolves finding **FIND-111D-01** at the architectural level. Producer PHP views emit clean, minimal, strictly typed canonical arrays, while the consumer chart engine reliably normalizes, validates, and renders them.
