<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ACCOUNTANT DASHBOARD
 * Financial reporting, outstanding invoice aging ledger, payment reconciliation & operating expenses.
 */

$pdo = Database::pdo();
$garageName = Setting::get('garage_name', 'Alpha Garage');
$currency = Setting::get('currency', 'ETB');

// 1. Accountant Financial KPIs (Consolidated single-trip aggregations & sargable date bounds)
$totalInvoiced = (float) Database::value("SELECT COALESCE(SUM(grand_total), 0) FROM invoices");

$payTotals = Database::one(
    "SELECT 
        COALESCE(SUM(CASE WHEN kind = 'payment' THEN amount ELSE 0 END), 0) AS total_paid,
        COALESCE(SUM(CASE WHEN kind = 'refund' THEN amount ELSE 0 END), 0) AS total_refunded
     FROM payments"
) ?: ['total_paid' => 0.0, 'total_refunded' => 0.0];
$totalPaid = (float) ($payTotals['total_paid'] ?? 0.0);
$totalRefunded = (float) ($payTotals['total_refunded'] ?? 0.0);
$netCollected = $totalPaid - $totalRefunded;

$unpaidTotals = Database::one(
    "SELECT 
        COALESCE(SUM(balance), 0) AS outstanding,
        COUNT(*) AS unpaid_count
     FROM invoices 
     WHERE payment_status IN ('Unpaid', 'Partially Paid')"
) ?: ['outstanding' => 0.0, 'unpaid_count' => 0];
$outstandingBalance = (float) ($unpaidTotals['outstanding'] ?? 0.0);
$unpaidInvoiceCount = (int) ($unpaidTotals['unpaid_count'] ?? 0);

$todayPayments = (float) Database::value(
    "SELECT COALESCE(SUM(amount), 0) 
     FROM payments 
     WHERE kind = 'payment' AND paid_at >= CURDATE() AND paid_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)"
);

$expensesReady = false;
$totalExpenses = 0.0;
try {
    $totalExpenses = (float) Database::value("SELECT COALESCE(SUM(amount), 0) FROM expenses");
    $expensesReady = true;
} catch (Throwable $e) {
    $expensesReady = false;
}
$pendingVerificationCount = ManualPaymentService::getPendingCount();

// 2. Outstanding Invoices (Accounts Receivable Aging Queue)
$outstandingInvoices = Database::all(
    "SELECT i.*, c.full_name AS customer_name, c.phone AS customer_phone, v.plate_number, v.make, v.model
     FROM invoices i
     JOIN customers c ON c.id = i.customer_id
     JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.payment_status IN ('Unpaid', 'Partially Paid')
     ORDER BY i.issue_date ASC, i.id DESC
     LIMIT 10"
);

// 3. Recent Payment Ledger Entries
$recentPayments = Database::all(
    "SELECT p.*, i.invoice_number, c.full_name AS customer_name
     FROM payments p
     JOIN invoices i ON i.id = p.invoice_id
     JOIN customers c ON c.id = i.customer_id
     ORDER BY p.id DESC
     LIMIT 8"
);

// 4. Recent Expenses
$recentExpenses = [];
if ($expensesReady) {
    try {
        $recentExpenses = Database::all(
            "SELECT e.*, u.full_name AS recorded_by_name
             FROM expenses e
             LEFT JOIN users u ON u.id = e.created_by
             ORDER BY e.expense_date DESC, e.id DESC
             LIMIT 6"
        );
    } catch (Throwable $e) {
        $recentExpenses = [];
    }
}

// 5. Chart Metrics: Invoice payment status breakdown
$invStatusRows = Database::all(
    "SELECT payment_status, COUNT(*) AS count_val FROM invoices GROUP BY payment_status"
);

// 6. Chart Metrics: Aging brackets of outstanding receivables (0-30, 31-60, 61-90, 90+ days)
$agingRow = Database::one(
    "SELECT
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) <= 30 THEN balance ELSE 0 END), 0) AS age_30,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 31 AND 60 THEN balance ELSE 0 END), 0) AS age_60,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) BETWEEN 61 AND 90 THEN balance ELSE 0 END), 0) AS age_90,
        COALESCE(SUM(CASE WHEN DATEDIFF(CURDATE(), issue_date) > 90 THEN balance ELSE 0 END), 0) AS age_over_90
     FROM invoices
     WHERE payment_status IN ('Unpaid', 'Partially Paid')"
);
$agingData = [
    round((float)($agingRow['age_30'] ?? 0), 2),
    round((float)($agingRow['age_60'] ?? 0), 2),
    round((float)($agingRow['age_90'] ?? 0), 2),
    round((float)($agingRow['age_over_90'] ?? 0), 2),
];

// 7. Chart Metrics: 6-month monthly net collections (payments minus refunds)
$acctMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $mKey = date('Y-m', strtotime("-$i months"));
    $mLabel = date('M Y', strtotime("-$i months"));
    $acctMonths[$mKey] = ['label' => $mLabel, 'net' => 0.0];
}
$revRows = Database::all(
    "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym,
            COALESCE(SUM(CASE WHEN kind = 'payment' THEN amount ELSE -amount END), 0) AS net_rev
     FROM payments
     WHERE paid_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym"
);
foreach ($revRows as $rr) {
    if (isset($acctMonths[$rr['ym']])) {
        $acctMonths[$rr['ym']]['net'] = round((float)$rr['net_rev'], 2);
    }
}

// 8. Chart Metrics: Payment tenders/methods distribution
$methodRows = Database::all(
    "SELECT method, COUNT(*) AS count_val, COALESCE(SUM(amount), 0) AS sum_amount
     FROM payments
     WHERE kind = 'payment'
     GROUP BY method
     ORDER BY count_val DESC"
);

$accountantChartPayload = [
    'currency' => $currency,
    'invoiceStatus' => [
        'labels' => !empty($invStatusRows) ? array_values(array_column($invStatusRows, 'payment_status')) : [],
        'data' => !empty($invStatusRows) ? array_values(array_map('intval', array_column($invStatusRows, 'count_val'))) : [],
    ],
    'agingBrackets' => [
        'labels' => $unpaidInvoiceCount > 0 ? ['0–30 Days', '31–60 Days', '61–90 Days', '90+ Days'] : [],
        'data' => $unpaidInvoiceCount > 0 ? array_values(array_map('floatval', $agingData)) : [],
    ],
    'monthlyRevenue' => [
        'labels' => array_values(array_column(array_values($acctMonths), 'label')),
        'data' => array_values(array_map('floatval', array_column(array_values($acctMonths), 'net'))),
    ],
    'paymentMethods' => [
        'labels' => !empty($methodRows) ? array_values(array_column($methodRows, 'method')) : [],
        'data' => !empty($methodRows) ? array_values(array_map('intval', array_column($methodRows, 'count_val'))) : [],
    ],
];

ui_header('Financial Dashboard', 'dashboard');
?>

<div class="hero reveal-up">
  <div>
    <h2><?= e(t('Welcome back', 'Welcome back')) ?>, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
    <p><?= e($user['role_label']) ?> · <?= e($garageName) ?> · <?= e(t('Accounts Receivable, Ledger & Cash Control', 'Accounts Receivable, Ledger & Cash Control')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-back-home btn-interactive" href="<?= e(url('index.php')) ?>" title="Back to public homepage">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <?= e(t('Back to Home', 'Back to Home')) ?>
    </a>
    <button class="btn btn-ghost theme-toggle dashboard-theme-toggle" type="button" aria-label="Switch theme" title="Switch theme"><span aria-hidden="true">◐</span><span class="theme-toggle-label"><?= e(t('Theme', 'Theme')) ?></span></button>
    <a class="btn btn-primary" href="<?= e(url('invoices/?status=outstanding')) ?>"><?= e(t('Outstanding invoices', 'Outstanding invoices')) ?></a>
    <?php if (can('payments.record')): ?><a class="btn btn-ghost" href="<?= e(url('payments/record.php')) ?>"><?= e(t('+ Record payment', '+ Record payment')) ?></a><?php endif; ?>
    <?php if ($expensesReady && can('expenses.manage')): ?><a class="btn btn-ghost" href="<?= e(url('expenses/')) ?>"><?= e(t('+ Record expense', '+ Record expense')) ?></a><?php endif; ?>
    <?php if (can('reports.financial')): ?><a class="btn btn-ghost" href="<?= e(url('reports/financial.php')) ?>"><?= e(t('Financial report', 'Financial report')) ?></a><?php endif; ?>
  </div>
</div>

<div class="dashboard-data" id="dashboard-data" data-chart-payload="<?= e(json_encode($accountantChartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">

<div class="dashboard-section-head">
  <div><span class="dashboard-eyebrow"><?= e(t('FINANCIAL LEDGER', 'FINANCIAL LEDGER')) ?></span><h3><?= e(t('Revenue & receivables position', 'Revenue & receivables position')) ?></h3></div>
  <span class="muted small"><?= e(t('Cash & accrual tracking', 'Cash & accrual tracking')) ?> · <?= e(date('F j, Y')) ?></span>
</div>

<div class="kpi-grid">
  <a class="kpi-card tone-green card-interactive reveal-up stagger-1" href="<?= e(url('payments/')) ?>">
    <div class="kpi-label"><?= e(t('Net Collected Revenue', 'Net Collected Revenue')) ?> (<?= e($currency) ?>)</div>
    <div class="kpi-value"><?= e($currency . ' ' . number_format($netCollected, 2)) ?></div>
    <div class="kpi-sub"><?= e(t('Total payments less refunds recorded', 'Total payments less refunds recorded')) ?></div>
    <div class="kpi-cmp trend-up"><?= e(t('Cash in bank / drawer', 'Cash in bank / drawer')) ?></div>
  </a>

  <a class="kpi-card tone-amber card-interactive reveal-up stagger-2" href="<?= e(url('invoices/?status=outstanding')) ?>">
    <div class="kpi-label"><?= e(t('Outstanding Receivables', 'Outstanding Receivables')) ?> (<?= e($currency) ?>)</div>
    <div class="kpi-value"><?= e($currency . ' ' . number_format($outstandingBalance, 2)) ?></div>
    <div class="kpi-sub"><?= $unpaidInvoiceCount ?> <?= e(t('unpaid / partially paid invoices', 'unpaid / partially paid invoices')) ?></div>
    <div class="kpi-cmp trend-down"><?= e(t('Pending collection', 'Pending collection')) ?></div>
  </a>

  <a class="kpi-card tone-blue card-interactive reveal-up stagger-3" href="<?= e(url('invoices/')) ?>">
    <div class="kpi-label"><?= e(t('Total Invoiced', 'Total Invoiced')) ?> (<?= e($currency) ?>)</div>
    <div class="kpi-value"><?= e($currency . ' ' . number_format($totalInvoiced, 2)) ?></div>
    <div class="kpi-sub"><?= e(t('Gross billed services and parts', 'Gross billed services and parts')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Lifetime invoiced', 'Lifetime invoiced')) ?></div>
  </a>

  <a class="kpi-card <?= $expensesReady ? 'tone-red' : 'tone-muted' ?> card-interactive reveal-up stagger-4" href="<?= e(url('expenses/')) ?>">
    <div class="kpi-label"><?= e(t('Total Expenses', 'Total Expenses')) ?> (<?= e($currency) ?>)</div>
    <div class="kpi-value"><?= $expensesReady ? e($currency . ' ' . number_format($totalExpenses, 2)) : 'Not available' ?></div>
    <div class="kpi-sub"><?= $expensesReady ? 'Recorded operating disbursements' : 'Expense ledger table not ready' ?></div>
    <div class="kpi-cmp trend-flat"><?= $expensesReady ? 'Disbursements' : 'Check config' ?></div>
  </a>
</div>

<div class="dashboard-section-head">
  <div><span class="dashboard-eyebrow"><?= e(t('FINANCIAL ANALYTICS', 'FINANCIAL ANALYTICS')) ?></span><h3><?= e(t('Receivables aging & revenue performance', 'Receivables aging & revenue performance')) ?></h3></div>
  <span class="muted small"><?= e(t('Real financial ledger records', 'Real financial ledger records')) ?> · <?= e(date('F Y')) ?></span>
</div>

<div class="chart-grid mb-4">
  <div class="card chart-card card-interactive reveal-up stagger-1">
    <div class="card-head">
      <h3><?= e(t('Invoice Settlement Status', 'Invoice Settlement Status')) ?></h3>
      <span class="muted small"><?= e(t('Distribution of billed invoices', 'Distribution of billed invoices')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-accountant-invoice-status" aria-label="Invoice payment status doughnut chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card card-interactive reveal-up stagger-2">
    <div class="card-head">
      <h3><?= e(t('Receivables Aging Brackets', 'Receivables Aging Brackets')) ?></h3>
      <span class="muted small"><?= e(t('Uncollected balances by age', 'Uncollected balances by age')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-accountant-aging-brackets" aria-label="Accounts receivable aging bar chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card card-interactive reveal-up stagger-3">
    <div class="card-head">
      <h3><?= e(t('6-Month Net Collections', '6-Month Net Collections')) ?></h3>
      <span class="muted small"><?= e(t('Monthly cash received minus refunds', 'Monthly cash received minus refunds')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-accountant-revenue-trend" aria-label="Monthly net revenue trend line chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card card-interactive reveal-up stagger-4">
    <div class="card-head">
      <h3><?= e(t('Payment Channel Distribution', 'Payment Channel Distribution')) ?></h3>
      <span class="muted small"><?= e(t('Transactions by payment tender', 'Transactions by payment tender')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-accountant-payment-methods" aria-label="Payment methods doughnut chart" role="img"></canvas>
    </div>
  </div>
</div>

<!-- Outstanding Invoices Awaiting Payment -->
<div class="card card-interactive reveal-up mb-4">
  <div class="card-head">
    <div>
      <h3><?= e(t('Outstanding Accounts Receivable', 'Outstanding Accounts Receivable')) ?></h3>
      <span class="muted small">Uncollected customer invoices sorted by age (oldest first)</span>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('invoices/?status=outstanding')) ?>"><?= e(t('View all pending', 'View all pending')) ?> (<?= $unpaidInvoiceCount ?>)</a>
  </div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th><?= e(t('Invoice No.', 'Invoice No.')) ?></th>
          <th><?= e(t('Issue Date', 'Issue Date')) ?></th>
          <th><?= e(t('Customer & Vehicle', 'Customer & Vehicle')) ?></th>
          <th><?= e(t('Total Amount', 'Total Amount')) ?></th>
          <th><?= e(t('Amount Paid', 'Amount Paid')) ?></th>
          <th><?= e(t('Balance Due', 'Balance Due')) ?></th>
          <th><?= e(t('Status', 'Status')) ?></th>
          <th><?= e(t('Action', 'Action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($outstandingInvoices): ?>
          <?php foreach ($outstandingInvoices as $inv): ?>
            <tr>
              <td class="nowrap">
                <a href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>">
                  <strong><?= e($inv['invoice_number']) ?></strong>
                </a>
              </td>
              <td class="nowrap"><?= e(fmt_date($inv['issue_date'])) ?></td>
              <td>
                <strong><?= e($inv['customer_name']) ?></strong>
                <div class="muted small"><?= e($inv['plate_number']) ?> · <?= e($inv['make'] . ' ' . $inv['model']) ?></div>
              </td>
              <td><?= e($currency . ' ' . number_format((float)$inv['grand_total'], 2)) ?></td>
              <td><?= e($currency . ' ' . number_format((float)$inv['amount_paid'], 2)) ?></td>
              <td>
                <strong class="text-amber"><?= e($currency . ' ' . number_format((float)$inv['balance'], 2)) ?></strong>
              </td>
              <td><?= badge($inv['payment_status']) ?></td>
              <td class="nowrap">
                <?php if (can('payments.record')): ?>
                  <a class="btn btn-primary btn-sm" href="<?= e(url('payments/record.php?invoice_id=' . $inv['id'])) ?>">Record payment &rarr;</a>
                <?php else: ?>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>">View</a>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="8" class="empty">All invoices are settled in full. Zero outstanding receivables!</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <!-- Recent Payments Audit -->
  <div class="card card-interactive reveal-up">
    <div class="card-head">
      <div>
        <h3><?= e(t('Recent Payment Receipts', 'Recent Payment Receipts')) ?></h3>
        <span class="muted small"><?= e(t('Latest cash, card & transfer credits', 'Latest cash, card & transfer credits')) ?></span>
      </div>
      <div style="display:flex;gap:8px;">
        <?php if (can('payments.verify')): ?>
          <a class="btn <?= $pendingVerificationCount > 0 ? 'btn-primary' : 'btn-ghost' ?> btn-sm" href="<?= e(url('payments/verification.php')) ?>">
            <?= e(t('Verification Queue', 'Verification Queue')) ?><?= $pendingVerificationCount > 0 ? " ($pendingVerificationCount)" : '' ?>
          </a>
        <?php endif; ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('payments/')) ?>"><?= e(t('Payments log', 'Payments log')) ?></a>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Receipt No.', 'Receipt No.')) ?></th>
            <th><?= e(t('Invoice', 'Invoice')) ?></th>
            <th><?= e(t('Customer', 'Customer')) ?></th>
            <th><?= e(t('Method', 'Method')) ?></th>
            <th><?= e(t('Amount', 'Amount')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentPayments): ?>
            <?php foreach ($recentPayments as $p): ?>
              <tr>
                <td class="nowrap"><strong><?= e($p['receipt_number']) ?></strong></td>
                <td><a href="<?= e(url('invoices/view.php?id=' . $p['invoice_id'])) ?>"><?= e($p['invoice_number']) ?></a></td>
                <td><?= e($p['customer_name']) ?></td>
                <td><?= e($p['method']) ?></td>
                <td class="nowrap">
                  <strong class="<?= $p['kind'] === 'refund' ? 'text-red' : 'text-green' ?>">
                    <?= $p['kind'] === 'refund' ? '−' : '+' ?> <?= e($currency . ' ' . number_format((float)$p['amount'], 2)) ?>
                  </strong>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="empty"><?= e(t('No payment transactions recorded yet.', 'No payment transactions recorded yet.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Expenses (if available) -->
  <div class="card card-interactive reveal-up">
    <div class="card-head">
      <div>
        <h3><?= e(t('Operating Expenses', 'Operating Expenses')) ?></h3>
        <span class="muted small"><?= e(t('Recorded disbursements and workshop overheads', 'Recorded disbursements and workshop overheads')) ?></span>
      </div>
      <?php if ($expensesReady): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('expenses/')) ?>"><?= e(t('Expense ledger', 'Expense ledger')) ?></a><?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Date', 'Date')) ?></th>
            <th><?= e(t('Category', 'Category')) ?></th>
            <th><?= e(t('Vendor / Notes', 'Vendor / Notes')) ?></th>
            <th><?= e(t('Amount', 'Amount')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($recentExpenses): ?>
            <?php foreach ($recentExpenses as $ex): ?>
              <tr>
                <td class="nowrap"><?= e(fmt_date($ex['expense_date'])) ?></td>
                <td><span class="badge"><?= e($ex['category'] ?: 'General') ?></span></td>
                <td><?= e(mb_strimwidth($ex['vendor'] ?: ($ex['description'] ?: '—'), 0, 35, '…')) ?></td>
                <td class="nowrap"><strong><?= e($currency . ' ' . number_format((float)$ex['amount'], 2)) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4" class="empty"><?= $expensesReady ? e(t('No operating expense entries found.', 'No operating expense entries found.')) : e(t('Expense ledger module is not enabled.', 'Expense ledger module is not enabled.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /.dashboard-data -->

<?php ui_footer();
