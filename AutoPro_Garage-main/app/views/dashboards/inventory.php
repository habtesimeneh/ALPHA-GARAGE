<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — INVENTORY MANAGER DASHBOARD
 * Warehouse stock management, critical restock indicators, supply chain & parts issuance.
 * Strictly avoids financial revenue, invoice balances, or profit disclosure.
 */

$pdo = Database::pdo();
$garageName = Setting::get('garage_name', 'Alpha Garage');

// 1. Inventory KPIs (Consolidated single-trip operational aggregations)
$stockTotals = Database::one(
    "SELECT 
        COUNT(*) AS total_part_types,
        COALESCE(SUM(quantity), 0) AS total_units,
        COALESCE(SUM(CASE WHEN quantity <= reorder_level AND quantity > 0 THEN 1 ELSE 0 END), 0) AS low_stock,
        COALESCE(SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock
     FROM spare_parts 
     WHERE is_active = 1"
) ?: ['total_part_types' => 0, 'total_units' => 0, 'low_stock' => 0, 'out_of_stock' => 0];

$totalPartTypes = (int) ($stockTotals['total_part_types'] ?? 0);
$totalUnitsInStock = (int) ($stockTotals['total_units'] ?? 0);
$lowStockCount = (int) ($stockTotals['low_stock'] ?? 0);
$outOfStockCount = (int) ($stockTotals['out_of_stock'] ?? 0);
$supplierCount = (int) Database::value("SELECT COUNT(*) FROM suppliers WHERE is_active = 1");

// 2. Critical Stock Alerts (Out of Stock & Low Stock Items)
$criticalStock = Database::all(
    "SELECT id, sku, name, category, unit, quantity, reorder_level, supplier
     FROM spare_parts
     WHERE is_active = 1 AND quantity <= reorder_level
     ORDER BY quantity ASC, name ASC
     LIMIT 10"
);

// 3. Recent Stock Movements (Audit of Parts In & Out)
$recentMovements = Database::all(
    "SELECT it.*, sp.sku, sp.name AS part_name, sp.unit, u.full_name AS operator_name
     FROM inventory_transactions it
     JOIN spare_parts sp ON sp.id = it.part_id
     LEFT JOIN users u ON u.id = it.created_by
     ORDER BY it.id DESC
     LIMIT 10"
);

// 4. Workshop Repair Jobs Awaiting or In Progress (for parts coordination)
$jobsNeedingParts = Database::all(
    "SELECT j.id, j.job_number, j.status, j.priority, j.reported_problem,
            c.full_name AS customer_name, v.plate_number, v.make, v.model,
            (SELECT COUNT(*) FROM part_requests pr WHERE pr.job_id = j.id AND pr.status = 'pending') AS pending_requests
     FROM job_cards j
     JOIN customers c ON c.id = j.customer_id
     JOIN vehicles v ON v.id = j.vehicle_id
     WHERE j.status IN ('Waiting for Parts', 'In Progress', 'Diagnosing')
     ORDER BY CASE j.status WHEN 'Waiting for Parts' THEN 1 ELSE 2 END, j.id DESC
     LIMIT 6"
);

// 4b. Pending Mechanic Part Requests for Manager Approval or Removal
$pendingRequests = Database::all(
    "SELECT pr.*, sp.name AS part_name, sp.sku, sp.quantity AS in_stock, sp.unit,
            j.job_number, j.status AS job_status,
            c.full_name AS customer_name, v.plate_number, v.make, v.model,
            u.full_name AS requester_name
     FROM part_requests pr
     JOIN spare_parts sp ON sp.id = pr.part_id
     JOIN job_cards j ON j.id = pr.job_id
     JOIN customers c ON c.id = j.customer_id
     JOIN vehicles v ON v.id = j.vehicle_id
     LEFT JOIN users u ON u.id = pr.requested_by
     WHERE pr.status = 'pending'
     ORDER BY pr.id ASC
     LIMIT 50"
);
$pendingRequestCount = count($pendingRequests);

// 4b. Pending Mechanic Part Requests for Manager Approval or Removal
$pendingPartRequests = Database::all(
    "SELECT pr.*, sp.name AS part_name, sp.sku, sp.quantity AS in_stock, sp.unit,
            j.job_number, j.status AS job_status,
            c.full_name AS customer_name, v.plate_number, v.make, v.model,
            u.full_name AS requester_name
     FROM part_requests pr
     JOIN spare_parts sp ON sp.id = pr.part_id
     JOIN job_cards j ON j.id = pr.job_id
     JOIN customers c ON c.id = j.customer_id
     JOIN vehicles v ON v.id = j.vehicle_id
     LEFT JOIN users u ON u.id = pr.requested_by
     WHERE pr.status = 'pending'
     ORDER BY pr.id ASC
     LIMIT 50"
);
$pendingRequestCount = count($pendingPartRequests);

// 5. Chart Data: Stock health distribution
$healthyCount = max(0, $totalPartTypes - $lowStockCount - $outOfStockCount);

// 6. Chart Data: Units by category (top 6 categories)
$categoryRows = Database::all(
    "SELECT COALESCE(NULLIF(category, ''), 'Uncategorized') AS cat_name, COALESCE(SUM(quantity), 0) AS total_units
     FROM spare_parts
     WHERE is_active = 1
     GROUP BY cat_name
     ORDER BY total_units DESC
     LIMIT 6"
);

// 7. Chart Data: Stock movement transactions by type
$txnRows = Database::all(
    "SELECT txn_type, COUNT(*) AS count_val
     FROM inventory_transactions
     GROUP BY txn_type
     ORDER BY count_val DESC"
);

$txnLabels = [];
$txnData = [];
$txnMap = [
    'purchase' => 'Purchase / Stock In',
    'issue' => 'Issued to Jobs',
    'return' => 'Returned to Stock',
    'adjustment_in' => 'Adjustment (+)',
    'adjustment_out' => 'Adjustment (-)',
];
foreach ($txnRows as $tr) {
    $txnLabels[] = $txnMap[$tr['txn_type']] ?? ucfirst($tr['txn_type']);
    $txnData[] = (int)$tr['count_val'];
}

$inventoryChartPayload = [
    'stockHealth' => [
        'labels' => $totalPartTypes > 0 ? ['Healthy Stock', 'Low Stock', 'Out of Stock'] : [],
        'data' => $totalPartTypes > 0 ? [$healthyCount, $lowStockCount, $outOfStockCount] : [],
    ],
    'categories' => [
        'labels' => !empty($categoryRows) ? array_values(array_column($categoryRows, 'cat_name')) : [],
        'data' => !empty($categoryRows) ? array_values(array_map('intval', array_column($categoryRows, 'total_units'))) : [],
    ],
    'transactions' => [
        'labels' => !empty($txnLabels) ? array_values($txnLabels) : [],
        'data' => !empty($txnData) ? array_values(array_map('intval', $txnData)) : [],
    ],
];

ui_header('Inventory & Supply Dashboard', 'dashboard');
?>

<div class="hero reveal-up">
  <div>
    <h2><?= e(t('Welcome back', 'Welcome back')) ?>, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
    <p><?= e($user['role_label']) ?> · <?= e($garageName) ?> · <?= e(t('Parts Catalog & Supply Chain Logistics', 'Parts Catalog & Supply Chain Logistics')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-back-home btn-interactive" href="<?= e(url('index.php')) ?>" title="Back to public homepage">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <?= e(t('Back to Home', 'Back to Home')) ?>
    </a>
    <button class="btn btn-ghost theme-toggle dashboard-theme-toggle btn-interactive" type="button" aria-label="Switch theme" title="Switch theme"><span aria-hidden="true">◐</span><span class="theme-toggle-label"><?= e(t('Theme', 'Theme')) ?></span></button>
    <a class="btn btn-primary btn-interactive" href="<?= e(url('inventory/')) ?>"><?= e(t('Stock catalog', 'Stock catalog')) ?></a>
    <?php if (can('inventory.manage')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('inventory/form.php')) ?>"><?= e(t('+ Add spare part', '+ Add spare part')) ?></a><?php endif; ?>
    <?php if (can('suppliers.view')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('suppliers/')) ?>"><?= e(t('Suppliers', 'Suppliers')) ?></a><?php endif; ?>
  </div>
</div>

<div class="dashboard-data" id="dashboard-data" data-chart-payload="<?= e(json_encode($inventoryChartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('WAREHOUSE STATUS', 'WAREHOUSE STATUS')) ?></span><h3><?= e(t('Inventory stock indicators', 'Inventory stock indicators')) ?></h3></div>
  <span class="muted small"><?= e(t('Real-time parts inventory', 'Real-time parts inventory')) ?> · <?= e(date('F j, Y')) ?></span>
</div>

<div class="kpi-grid">
  <a class="kpi-card tone-blue reveal-up card-interactive stagger-1" href="<?= e(url('inventory/')) ?>">
    <div class="kpi-label"><?= e(t('Catalog Item Types', 'Catalog Item Types')) ?></div>
    <div class="kpi-value"><?= $totalPartTypes ?></div>
    <div class="kpi-sub"><?= number_format($totalUnitsInStock) ?> <?= e(t('physical units on shelf', 'physical units on shelf')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Active catalog', 'Active catalog')) ?></div>
  </a>

  <a class="kpi-card <?= $outOfStockCount > 0 ? 'tone-red' : 'tone-green' ?> reveal-up card-interactive stagger-2" href="<?= e(url('inventory/?stock=low')) ?>">
    <div class="kpi-label"><?= e(t('Out of Stock', 'Out of Stock')) ?></div>
    <div class="kpi-value"><?= $outOfStockCount ?></div>
    <div class="kpi-sub"><?= $outOfStockCount > 0 ? e(t('Urgent parts procurement needed', 'Urgent parts procurement needed')) : e(t('Zero depleted items', 'Zero depleted items')) ?></div>
    <div class="kpi-cmp <?= $outOfStockCount > 0 ? 'trend-down' : 'trend-flat' ?>"><?= $outOfStockCount > 0 ? e(t('Critical', 'Critical')) : e(t('Healthy', 'Healthy')) ?></div>
  </a>

  <a class="kpi-card <?= $lowStockCount > 0 ? 'tone-amber' : 'tone-green' ?> reveal-up card-interactive stagger-3" href="<?= e(url('inventory/?stock=low')) ?>">
    <div class="kpi-label"><?= e(t('Low Stock Alerts', 'Low Stock Alerts')) ?></div>
    <div class="kpi-value"><?= $lowStockCount ?></div>
    <div class="kpi-sub"><?= e(t('Items at or below reorder threshold', 'Items at or below reorder threshold')) ?></div>
    <div class="kpi-cmp <?= $lowStockCount > 0 ? 'trend-down' : 'trend-flat' ?>"><?= e(t('Reorder required', 'Reorder required')) ?></div>
  </a>

  <a class="kpi-card tone-gold reveal-up card-interactive stagger-4" href="<?= e(url('suppliers/')) ?>">
    <div class="kpi-label"><?= e(t('Active Suppliers', 'Active Suppliers')) ?></div>
    <div class="kpi-value"><?= $supplierCount ?></div>
    <div class="kpi-sub"><?= e(t('Verified parts supply partners', 'Verified parts supply partners')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Procurement vendors', 'Procurement vendors')) ?></div>
  </a>
</div>

<!-- Operations Approval & Removal Queue for Owner / Inventory Manager -->
<div class="dashboard-section-head reveal-up" id="approval-queue">
  <div>
    <span class="dashboard-eyebrow" style="color: #D97706;"><?= e(t('OWNER & MANAGER APPROVAL QUEUE', 'OWNER & MANAGER APPROVAL QUEUE')) ?></span>
    <h3><?= e(t('Pending Mechanic Part Requests', 'Pending Mechanic Part Requests')) ?> (<?= $pendingRequestCount ?>)</h3>
  </div>
  <?php if ($pendingRequestCount > 0): ?>
    <span class="badge badge-pending"><?= $pendingRequestCount ?> <?= e(t('decisions pending', 'decisions pending')) ?></span>
  <?php else: ?>
    <span class="badge badge-confirmed">✓ <?= e(t('All requests fulfilled', 'All requests fulfilled')) ?></span>
  <?php endif; ?>
</div>

<div class="card card-interactive reveal-up mb-4" style="border-top: 4px solid #D4AF37;">
  <div class="card-head">
    <div>
      <h3 style="display:flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#D4AF37" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= e(t('Operations Approval & Removal Queue', 'Operations Approval & Removal Queue')) ?>
      </h3>
      <span class="muted small"><?= e(t('Review technician requests: 1-click Approve to issue parts from warehouse, or Remove / Reject to decline.', 'Review technician requests: 1-click Approve to issue parts from warehouse, or Remove / Reject to decline.')) ?></span>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th><?= e(t('Job Card', 'Job Card')) ?></th>
          <th><?= e(t('Vehicle & Customer', 'Vehicle & Customer')) ?></th>
          <th><?= e(t('Requested Part', 'Requested Part')) ?></th>
          <th class="num"><?= e(t('Req Qty', 'Req Qty')) ?></th>
          <th class="num"><?= e(t('In Stock', 'In Stock')) ?></th>
          <th><?= e(t('Requested By', 'Requested By')) ?></th>
          <th><?= e(t('Action / Decision', 'Action / Decision')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($pendingRequests): ?>
          <?php foreach ($pendingRequests as $pr):
            $short = ((int)$pr['in_stock'] < (int)$pr['quantity']);
          ?>
            <tr>
              <td>
                <a href="<?= e(url('job-cards/view.php?id=' . $pr['job_id'])) ?>"><strong><?= e($pr['job_number']) ?></strong></a>
                <div class="muted small"><?= badge(t($pr['job_status'], $pr['job_status'])) ?></div>
              </td>
              <td>
                <strong><?= e($pr['plate_number']) ?></strong>
                <div class="muted small"><?= e($pr['make'] . ' ' . $pr['model']) ?> · <?= e($pr['customer_name']) ?></div>
              </td>
              <td>
                <strong><?= e($pr['part_name']) ?></strong> <span class="muted small"><code><?= e($pr['sku']) ?></code></span>
                <?php if ($pr['note']): ?>
                  <div class="muted small" style="font-style:italic;">“<?= e($pr['note']) ?>”</div>
                <?php endif; ?>
              </td>
              <td class="num"><strong><?= (int)$pr['quantity'] ?></strong> <?= e($pr['unit']) ?></td>
              <td class="num">
                <strong><?= (int)$pr['in_stock'] ?></strong> <?= e($pr['unit']) ?>
                <?= $short ? badge(t('Short', 'Short'), 'badge-out') : badge(t('Available', 'Available'), 'badge-confirmed') ?>
              </td>
              <td class="small">
                <?= e($pr['requester_name'] ?: '—') ?>
                <br><small class="muted"><?= fmt_datetime($pr['created_at']) ?></small>
              </td>
              <td class="nowrap">
                <?= post_button(url('inventory/issue.php'), ['do' => 'fulfil', 'request_id' => $pr['id'], 'job_id' => $pr['job_id'], 'return_url' => 'dashboard.php'], t('Approve & Issue', 'Approve & Issue'), 'btn btn-primary btn-sm', $short ? t('Stock looks short. Continue with issuance?', 'Stock looks short. Continue with issuance?') : '') ?>
                <?= post_button(url('inventory/issue.php'), ['do' => 'reject', 'request_id' => $pr['id'], 'job_id' => $pr['job_id'], 'return_url' => 'dashboard.php'], t('Remove / Reject', 'Remove / Reject'), 'btn btn-ghost btn-sm text-danger', t('Reject and remove this part request?', 'Reject and remove this part request?')) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7" class="empty">✓ <?= e(t('All mechanic part requests are currently reviewed and fulfilled.', 'All mechanic part requests are currently reviewed and fulfilled.')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('INVENTORY ANALYTICS', 'INVENTORY ANALYTICS')) ?></span><h3><?= e(t('Stock health & parts movements', 'Stock health & parts movements')) ?></h3></div>
  <span class="muted small"><?= e(t('Visual parts stock analysis · Real database inventory records', 'Visual parts stock analysis · Real database inventory records')) ?></span>
</div>

<div class="chart-grid mb-4">
  <div class="card chart-card reveal-up card-interactive stagger-1">
    <div class="card-head">
      <h3><?= e(t('Stock Health Distribution', 'Stock Health Distribution')) ?></h3>
      <span class="muted small"><?= e(t('Catalog items by stock level', 'Catalog items by stock level')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-inventory-stock-health" aria-label="Inventory stock health doughnut chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card reveal-up card-interactive stagger-2">
    <div class="card-head">
      <h3><?= e(t('Physical Units by Category', 'Physical Units by Category')) ?></h3>
      <span class="muted small"><?= e(t('Top inventory categories by quantity', 'Top inventory categories by quantity')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-inventory-category-units" aria-label="Inventory category units bar chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card chart-card-wide reveal-up card-interactive stagger-3">
    <div class="card-head">
      <h3><?= e(t('Movement Activity by Type', 'Movement Activity by Type')) ?></h3>
      <span class="muted small"><?= e(t('Receipts, job issuances, and audit adjustments', 'Receipts, job issuances, and audit adjustments')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-inventory-movement-trend" aria-label="Inventory movement transactions bar chart" role="img"></canvas>
    </div>
  </div>
</div>

<!-- Manager Operations & Part Request Approvals Queue -->
<div class="dashboard-section-head reveal-up" id="approval-queue">
  <div>
    <span class="dashboard-eyebrow" style="color: #D97706; font-weight:700; letter-spacing:0.05em;"><?= e(t('OWNER & MANAGER APPROVAL QUEUE', 'OWNER & MANAGER APPROVAL QUEUE')) ?></span>
    <h3><?= e(t('Pending Mechanic Part Requests', 'Pending Mechanic Part Requests')) ?> (<?= $pendingRequestCount ?>)</h3>
  </div>
  <span class="badge <?= $pendingRequestCount > 0 ? 'badge-cancelled' : 'badge-confirmed' ?>"><?= $pendingRequestCount ?> <?= e(t('decisions pending', 'decisions pending')) ?></span>
</div>

<div class="card card-interactive reveal-up mb-4" style="border-top: 4px solid #D4AF37;">
  <div class="card-head">
    <div>
      <h3 style="display:flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#D4AF37" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= e(t('Operations Approval & Removal Queue', 'Operations Approval & Removal Queue')) ?>
      </h3>
      <span class="muted small"><?= e(t('Review technician requests: 1-click Approve to issue parts from warehouse, or Remove / Reject to decline.', 'Review technician requests: 1-click Approve to issue parts from warehouse, or Remove / Reject to decline.')) ?></span>
    </div>
  </div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th><?= e(t('Job Card', 'Job Card')) ?></th>
          <th><?= e(t('Vehicle & Customer', 'Vehicle & Customer')) ?></th>
          <th><?= e(t('Requested Part', 'Requested Part')) ?></th>
          <th class="num"><?= e(t('Req Qty', 'Req Qty')) ?></th>
          <th class="num"><?= e(t('In Stock', 'In Stock')) ?></th>
          <th><?= e(t('Requested By', 'Requested By')) ?></th>
          <th><?= e(t('Action / Decision', 'Action / Decision')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($pendingPartRequests): ?>
          <?php foreach ($pendingPartRequests as $pr):
            $short = ((int)$pr['in_stock'] < (int)$pr['quantity']);
          ?>
            <tr>
              <td>
                <a href="<?= e(url('job-cards/view.php?id=' . $pr['job_id'])) ?>"><strong><?= e($pr['job_number']) ?></strong></a>
                <div class="muted small"><?= badge(t($pr['job_status'], $pr['job_status'])) ?></div>
              </td>
              <td>
                <strong><?= e($pr['plate_number']) ?></strong>
                <div class="muted small"><?= e($pr['make'] . ' ' . $pr['model']) ?> · <?= e($pr['customer_name']) ?></div>
              </td>
              <td>
                <strong><?= e($pr['part_name']) ?></strong> <span class="muted small"><code><?= e($pr['sku']) ?></code></span>
                <?php if ($pr['note']): ?>
                  <div class="muted small" style="font-style:italic;">“<?= e($pr['note']) ?>”</div>
                <?php endif; ?>
              </td>
              <td class="num"><strong><?= (int)$pr['quantity'] ?></strong> <?= e($pr['unit']) ?></td>
              <td class="num">
                <strong><?= (int)$pr['in_stock'] ?></strong> <?= e($pr['unit']) ?>
                <?= $short ? badge(t('Short', 'Short'), 'badge-out') : badge(t('Available', 'Available'), 'badge-confirmed') ?>
              </td>
              <td class="small">
                <?= e($pr['requester_name'] ?: '—') ?>
                <br><small class="muted"><?= fmt_datetime($pr['created_at']) ?></small>
              </td>
              <td class="nowrap">
                <?= post_button(url('inventory/issue.php'), ['do' => 'fulfil', 'request_id' => $pr['id'], 'job_id' => $pr['job_id'], 'return_url' => 'dashboard.php'], t('Approve & Issue', 'Approve & Issue'), 'btn btn-primary btn-sm', $short ? t('Stock looks short. Continue with issuance?', 'Stock looks short. Continue with issuance?') : '') ?>
                <?= post_button(url('inventory/issue.php'), ['do' => 'reject', 'request_id' => $pr['id'], 'job_id' => $pr['job_id'], 'return_url' => 'dashboard.php'], t('Remove / Reject', 'Remove / Reject'), 'btn btn-ghost btn-sm text-danger', t('Reject and remove this part request?', 'Reject and remove this part request?')) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7" class="empty">✓ <?= e(t('All mechanic part requests are currently reviewed and fulfilled.', 'All mechanic part requests are currently reviewed and fulfilled.')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <!-- Critical Restock Alerts -->
  <div class="card reveal-up card-interactive">
    <div class="card-head">
      <div>
        <h3><?= e(t('Critical Restock Alerts', 'Critical Restock Alerts')) ?></h3>
        <span class="muted small"><?= e(t('Parts depleted or below safety threshold', 'Parts depleted or below safety threshold')) ?></span>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/?stock=low')) ?>"><?= e(t('View all alerts', 'View all alerts')) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('SKU', 'SKU')) ?></th>
            <th><?= e(t('Part Name', 'Part Name')) ?></th>
            <th><?= e(t('In Stock', 'In Stock')) ?></th>
            <th><?= e(t('Reorder At', 'Reorder At')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th><?= e(t('Action', 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($criticalStock): ?>
            <?php foreach ($criticalStock as $cs): ?>
              <tr>
                <td class="nowrap"><code><?= e($cs['sku']) ?></code></td>
                <td>
                  <strong><?= e($cs['name']) ?></strong>
                  <div class="muted small"><?= e($cs['category'] ?: 'General') ?></div>
                </td>
                <td><strong><?= (int)$cs['quantity'] ?></strong> <span class="muted small"><?= e($cs['unit']) ?></span></td>
                <td><?= (int)$cs['reorder_level'] ?></td>
                <td>
                  <?php if ((int)$cs['quantity'] === 0): ?>
                    <span class="badge badge-cancelled"><?= e(t('Depleted', 'Depleted')) ?></span>
                  <?php else: ?>
                    <span class="badge badge-pending"><?= e(t('Low', 'Low')) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/history.php?part_id=' . $cs['id'])) ?>"><?= e(t('History', 'History')) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" class="empty"><?= e(t('All inventory items are currently above safety stock thresholds.', 'All inventory items are currently above safety stock thresholds.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Workshop Repairs Awaiting or In Progress -->
  <div class="card card-interactive reveal-up">
    <div class="card-head">
      <div>
        <h3><?= e(t('Workshop Job Coordination', 'Workshop Job Coordination')) ?></h3>
        <span class="muted small"><?= e(t('Active repair orders requiring spare parts', 'Active repair orders requiring spare parts')) ?></span>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/')) ?>"><?= e(t('Workshop jobs', 'Workshop jobs')) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Job No.', 'Job No.')) ?></th>
            <th><?= e(t('Vehicle & Customer', 'Vehicle & Customer')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th><?= e(t('Pending Requests', 'Pending Requests')) ?></th>
            <th><?= e(t('Action', 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($jobsNeedingParts): ?>
            <?php foreach ($jobsNeedingParts as $job): ?>
              <tr>
                <td class="nowrap">
                  <a href="<?= e(url('job-cards/view.php?id=' . $job['id'])) ?>"><strong><?= e($job['job_number']) ?></strong></a>
                </td>
                <td>
                  <strong><?= e($job['plate_number']) ?></strong>
                  <div class="muted small"><?= e($job['customer_name']) ?></div>
                </td>
                <td><?= badge($job['status']) ?></td>
                <td>
                  <?php if ($job['pending_requests'] > 0): ?>
                    <span class="badge badge-cancelled"><?= (int)$job['pending_requests'] ?> <?= e(t('part requests', 'part requests')) ?></span>
                  <?php else: ?>
                    <span class="muted small"><?= e(t('0 pending', '0 pending')) ?></span>
                  <?php endif; ?>
                </td>
                <td>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/view.php?id=' . $job['id'])) ?>"><?= e(t('Issue parts', 'Issue parts')) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="empty"><?= e(t('No workshop jobs currently waiting for parts.', 'No workshop jobs currently waiting for parts.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Recent Stock Movements Audit -->
<div class="card card-interactive reveal-up">
  <div class="card-head">
    <div>
      <h3><?= e(t('Recent Stock Movements', 'Recent Stock Movements')) ?></h3>
      <span class="muted small"><?= e(t('Audit trail of receipts, issues, and manual adjustments', 'Audit trail of receipts, issues, and manual adjustments')) ?></span>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('inventory/')) ?>"><?= e(t('Inventory ledger', 'Inventory ledger')) ?></a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('Date & Time', 'Date & Time')) ?></th>
          <th><?= e(t('Part Description', 'Part Description')) ?></th>
          <th><?= e(t('Movement Type', 'Movement Type')) ?></th>
          <th><?= e(t('Quantity', 'Quantity')) ?></th>
          <th><?= e(t('Balance After', 'Balance After')) ?></th>
          <th><?= e(t('Reference / Notes', 'Reference / Notes')) ?></th>
          <th><?= e(t('Operator', 'Operator')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recentMovements): ?>
          <?php foreach ($recentMovements as $mv): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_date($mv['created_at'])) ?> <small class="muted"><?= e(substr($mv['created_at'], 11, 5)) ?></small></td>
              <td>
                <code><?= e($mv['sku']) ?></code> <strong><?= e($mv['part_name']) ?></strong>
              </td>
              <td>
                <?php
                  $badgeCls = match($mv['txn_type']) {
                      'purchase', 'adjustment_in' => 'badge-confirmed',
                      'issue', 'adjustment_out' => 'badge-pending',
                      'return' => 'badge-info',
                      default => 'badge'
                  };
                  $badgeLbl = match($mv['txn_type']) {
                      'purchase' => t('Purchase / Stock In', 'Purchase / Stock In'),
                      'issue' => t('Issued to Job', 'Issued to Job'),
                      'return' => t('Returned from Job', 'Returned from Job'),
                      'adjustment_in' => t('Adjustment (+)', 'Adjustment (+)'),
                      'adjustment_out' => t('Adjustment (-)', 'Adjustment (-)'),
                      default => ucfirst($mv['txn_type'])
                  };
                ?>
                <span class="badge <?= $badgeCls ?>"><?= e($badgeLbl) ?></span>
              </td>
              <td class="nowrap"><strong><?= (int)$mv['quantity'] ?></strong> <?= e($mv['unit']) ?></td>
              <td class="nowrap"><?= (int)$mv['balance_after'] ?></td>
              <td><?= e($mv['notes'] ?: ($mv['reference'] ? t('Ref', 'Ref') . ': ' . $mv['reference'] : '—')) ?></td>
              <td><?= e($mv['operator_name'] ?: t('System', 'System')) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7" class="empty"><?= e(t('No inventory transaction records found.', 'No inventory transaction records found.')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /.dashboard-data -->

<?php ui_footer();
