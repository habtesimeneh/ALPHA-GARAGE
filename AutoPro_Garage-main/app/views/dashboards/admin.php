<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — ADMINISTRATOR DASHBOARD
 * Command Center: Financial analytics, operations, KPI tracking & Chart.js visualizations
 */

$scopeAll = can('jobs.view_all');
$mine = (!$scopeAll && can('jobs.view_assigned')) ? (int) ($user['mechanic_id'] ?? 0) : null;
$jobWhere = $scopeAll ? '1=1' : ($mine ? 'mechanic_id = ' . $mine : '0=1');

$today = new DateTimeImmutable('today');
$range = DashboardAnalyticsService::range($_GET, $today);
$currency = DashboardAnalyticsService::currency();
$rangeStartSql = $range['startSql'];
$rangeEndSql = $range['endSql'];
$rangeEndExclusiveSql = $range['endExclusive'];
$prevStartSql = $range['prevStart'];
$period = $range['period'];
$rangeKey = $range['key'];
$rangeLabel = $range['label'];
$rangeError = $range['error'];

$canPayments = can('payments.view');
$canExpenses = can('expenses.view');
$canInvoices = can('invoices.view');
$canInventory = can('inventory.view');
$canJobs = $scopeAll || (bool) $mine;
$canAppts = can('appointments.view');
$canServices = can('services.view');
$canCustomers = can('customers.view');
$canVehicles = can('vehicles.view');

$revenue = $canPayments ? DashboardAnalyticsService::revenueTotals($rangeStartSql, $rangeEndExclusiveSql) : ['total'=>0.0,'received'=>0.0,'refunded'=>0.0,'invoiced'=>0.0];
$prevRevenue = $canPayments ? DashboardAnalyticsService::revenueTotals($prevStartSql, $rangeStartSql) : ['total'=>0.0];
$expenses = DashboardAnalyticsService::expenseTotals($rangeStartSql, $rangeEndExclusiveSql);
$prevExpenses = $expenses['ready'] ? DashboardAnalyticsService::expenseTotals($prevStartSql, $rangeStartSql) : ['total'=>0.0,'ready'=>false];
$netProfit = $revenue['total'] - ($expenses['ready'] ? $expenses['total'] : 0.0);
$prevNetProfit = $prevRevenue['total'] - (($prevExpenses['ready'] ?? false) ? $prevExpenses['total'] : 0.0);
$profitMargin = $expenses['ready'] ? DashboardAnalyticsService::profitMargin($revenue['total'], $netProfit) : null;
$prevMargin = ($expenses['ready'] && ($prevExpenses['ready'] ?? false)) ? DashboardAnalyticsService::profitMargin($prevRevenue['total'], $prevNetProfit) : null;

$pendingPayments = $canInvoices ? (float) Database::value(
    "SELECT COALESCE(SUM(balance),0) FROM invoices WHERE payment_status IN ('Unpaid','Partially Paid')",
    []
) : 0.0;
$pendingCount = $canInvoices ? (int) Database::value(
    "SELECT COUNT(*) FROM invoices WHERE payment_status IN ('Unpaid','Partially Paid')",
    []
) : 0;
$pendingVerificationCount = can('payments.verify') ? ManualPaymentService::getPendingCount() : 0;
$inv = $canInventory ? DashboardAnalyticsService::inventorySummary() : ['items'=>0,'available'=>0,'low'=>0,'out'=>0,'value'=>0.0,'top_used'=>[], 'by_category'=>[]];
$adminPendingParts = $canInventory ? Database::all(
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
     LIMIT 15"
) : [];
$jobCounts = $canJobs ? DashboardAnalyticsService::jobStatusCounts($jobWhere, [$rangeStartSql, $rangeEndExclusiveSql]) : [];
$activeJobs = ($jobCounts['Pending'] ?? 0) + ($jobCounts['Diagnosing'] ?? 0) + ($jobCounts['Waiting for Parts'] ?? 0) + ($jobCounts['In Progress'] ?? 0);
$completedJobs = ($jobCounts['Completed'] ?? 0) + ($jobCounts['Delivered'] ?? 0);
$eligibleJobs = max(0, array_sum($jobCounts) - ($jobCounts['Cancelled'] ?? 0));
$completionRate = $eligibleJobs > 0 ? round(($completedJobs / $eligibleJobs) * 100, 1) : 0.0;

$buckets = DashboardAnalyticsService::buckets($range['start'], $range['end'], $period);
$financialSeries = DashboardAnalyticsService::financialSeries($buckets, $period, $rangeStartSql, $rangeEndExclusiveSql);
$revenueSeries = $canPayments ? DashboardAnalyticsService::revenueSeries($buckets, $period, $rangeStartSql, $rangeEndExclusiveSql) : [];
$servicePerf = DashboardAnalyticsService::topServices($rangeStartSql, $rangeEndExclusiveSql);

$revCmp = DashboardAnalyticsService::comparison($revenue['total'], $prevRevenue['total']);
$expCmp = DashboardAnalyticsService::comparison($expenses['total'], $prevExpenses['total']);
$profitCmp = DashboardAnalyticsService::comparison($netProfit, $prevNetProfit);
$marginCmp = ($profitMargin !== null && $prevMargin !== null) ? DashboardAnalyticsService::comparison($profitMargin, $prevMargin) : null;
$jobsCmp = $canJobs ? DashboardAnalyticsService::comparison((float)$activeJobs, (float)(int) Database::value("SELECT COUNT(*) FROM job_cards WHERE $jobWhere AND date_received >= ? AND date_received < ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')", [$prevStartSql, $rangeStartSql])) : null;
$completedCmp = $canJobs ? DashboardAnalyticsService::comparison((float)$completedJobs, (float)(int) Database::value("SELECT COUNT(*) FROM job_cards WHERE $jobWhere AND actual_completion >= ? AND actual_completion < ? AND status IN ('Completed','Delivered')", [$prevStartSql, $rangeStartSql])) : null;

$targets = DashboardAnalyticsService::targets();
// Targets compare to current calendar month actuals (reusing already calculated this_month totals where identical)
if ($rangeKey === 'this_month') {
    $monthRevenue = $revenue['total'];
    $monthExpenses = $expenses['ready'] ? $expenses['total'] : 0.0;
    $monthProfit = $netProfit;
} else {
    $monthStart = $today->modify('first day of this month')->format('Y-m-d');
    $monthEndEx = $today->modify('first day of next month')->format('Y-m-d');
    $monthRevenue = $canPayments ? DashboardAnalyticsService::revenueTotals($monthStart, $monthEndEx)['total'] : 0.0;
    $monthExpenses = $expenses['ready'] ? DashboardAnalyticsService::expenseTotals($monthStart, $monthEndEx)['total'] : 0.0;
    $monthProfit = $monthRevenue - $monthExpenses;
}

if (!function_exists('dash_meter')) {
    function dash_meter(float $pct): string {
        $q = (int) (round(max(0, min(100, $pct)) / 5) * 5);
        return 'meter-' . $q;
    }
}
if (!function_exists('dash_spark')) {
    function dash_spark(array $pts, string $tone = 'green'): string {
        if (!$pts) return '';
        $max = max(1.0, max($pts)); $min = min(0.0, min($pts)); $range = max(0.0001, $max - $min);
        $w=120; $h=28; $coords=[];
        foreach ($pts as $i=>$v){ $x = count($pts)==1 ? $w/2 : $i*($w/(count($pts)-1)); $y = $h - (($v-$min)/$range)*($h-4) - 2; $coords[] = round($x,1).','.round($y,1); }
        $color = ['green'=>'#16834A','red'=>'#DC2626','blue'=>'#245D49','gold'=>'#B38D16'][$tone] ?? '#245D49';
        return '<svg class="kpi-spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true"><polyline fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" points="' . e(implode(' ', $coords)) . '"/></svg>';
    }
}
if (!function_exists('dash_progress')) {
    function dash_progress(?float $actual, ?float $target, string $label, string $format = 'money', bool $inverted = false): string {
        $cur = DashboardAnalyticsService::currency();
        if ($target === null || $target <= 0) {
            return '<div class="dash-progress"><div class="dash-progress-head"><span>' . e(t($label, $label)) . '</span><span class="muted">' . e(t('Target not configured', 'Target not configured')) . '</span></div><div class="dash-progress-track"><div class="dash-progress-fill meter-0"></div></div></div>';
        }
        $pctRaw = ($actual / $target) * 100;
        $pct = max(0, min(100, $pctRaw));
        $over = $pctRaw > 100;
        $display = $format === 'percent' ? number_format((float)$actual,1) . '%' : $cur . ' ' . number_format((float)$actual,2);
        $tDisplay = $format === 'percent' ? number_format((float)$target,1) . '%' : $cur . ' ' . number_format((float)$target,2);
        $tone = $inverted ? ($pctRaw > 100 ? 'danger' : ($pctRaw > 80 ? 'warn' : 'ok')) : ($pctRaw >= 100 ? 'ok' : ($pctRaw >= 60 ? 'warn' : 'info'));
        $note = $over ? ($inverted ? t('Over budget', 'Over budget') : t('Target exceeded', 'Target exceeded')) : number_format($pctRaw,1) . '% ' . t('of target', 'of target');
        $meter = dash_meter($pct);
        return '<div class="dash-progress"><div class="dash-progress-head"><span>' . e(t($label, $label)) . '</span><span>' . e($display) . ' / ' . e($tDisplay) . '</span></div><div class="dash-progress-track"><div class="dash-progress-fill tone-' . $tone . ' ' . $meter . '"></div></div><small class="muted">' . e($note) . '</small></div>';
    }
}

$sparkRevenue = $canPayments ? dash_spark(DashboardAnalyticsService::sparkline('revenue', $today), 'green') : '';
$sparkExpenses = $expenses['ready'] ? dash_spark(DashboardAnalyticsService::sparkline('expenses', $today), 'red') : '';
$sparkProfit = $expenses['ready'] ? dash_spark(array_map(fn($r)=>($r['profit']??0), array_slice($financialSeries, -6) ?: $financialSeries), $netProfit >= 0 ? 'green' : 'red') : '';
$sparkJobs = $canJobs ? dash_spark(DashboardAnalyticsService::sparkline('jobs', $today), 'blue') : '';

// Chart payload for Chart.js (real DB data, JSON-safe)
$chartPayload = [
    'currency' => $currency,
    'rangeLabel' => $rangeLabel,
    'financial' => $financialSeries,
    'revenue' => $revenueSeries,
    'expensesByCategory' => $expenses['ready'] ? $expenses['by_category'] : [],
    'jobStatus' => $jobCounts,
    'inventoryByCategory' => array_map(fn($c)=>['label'=>$c['label'],'units'=>$c['units']], $inv['by_category']),
    'topServices' => $servicePerf,
];

// AJAX fragment refresh: return only dashboard-data inner content
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$customFromValue = $rangeKey === 'custom' ? $rangeStartSql : (is_string($_GET['from'] ?? null) ? $_GET['from'] : '');
$customToValue = $rangeKey === 'custom' ? $rangeEndSql : (is_string($_GET['to'] ?? null) ? $_GET['to'] : '');

ui_header('Dashboard', 'dashboard');
?>
<div class="hero reveal-up">
  <div>
    <h2><?= e(t('Welcome back', 'Welcome back')) ?>, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
    <p><?= e($user['role_label']) ?> · <?= e(Setting::get('garage_name')) ?> · <?= e(t('Financial & Operations Command Center', 'Financial & Operations Command Center')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-back-home btn-interactive" href="<?= e(url('index.php')) ?>" title="Back to public homepage">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <?= e(t('Back to Home', 'Back to Home')) ?>
    </a>
    <button class="btn btn-ghost theme-toggle dashboard-theme-toggle btn-interactive" type="button" aria-label="Switch theme" aria-pressed="false" title="Switch theme"><span aria-hidden="true">◐</span><span class="theme-toggle-label"><?= e(t('Theme', 'Theme')) ?></span></button>
    <?php if (can('payments.methods')): ?>
      <a class="btn btn-ghost btn-interactive" href="<?= e(url('payments/methods.php')) ?>" title="<?= e(t('Configure garage bank accounts and Telebirr', 'የጋራዡን የባንክ እና ቴሌብር ሂሳቦች ያዋቅሩ')) ?>">
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M11.5 1L2 6v2h19V6m-17 4v7h3v-7m4 0v7h3v-7m4 0v7h3v-7M2 19v2h19v-2H2z"/></svg>
        <?= e(t('Bank & Telebirr Accounts', 'የባንክ እና ቴሌብር ሂሳቦች')) ?>
      </a>
    <?php endif; ?>
    <?php if (can('jobs.create')): ?><a class="btn btn-primary btn-interactive" href="<?= e(url('job-cards/form.php')) ?>"><?= e(t('+ New job card', '+ New job card')) ?></a><?php endif; ?>
    <?php if ($canExpenses && can('expenses.manage')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('expenses/')) ?>"><?= e(t('+ Record expense', '+ Record expense')) ?></a><?php endif; ?>
  </div>
</div>

<div class="dashboard-data" id="dashboard-data" data-chart-payload="<?= e(json_encode($chartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">
<form method="get" action="<?= e(url('dashboard.php')) ?>" class="dashboard-filter-form reveal-fade" data-no-loader>
  <div class="dashboard-filter-heading">
    <div><span class="dashboard-eyebrow"><?= e(t('BUSINESS ANALYTICS', 'BUSINESS ANALYTICS')) ?></span><strong><?= e(t('Reporting period', 'Reporting period')) ?></strong></div>
    <span class="muted small"><?= e($rangeStartSql) ?> to <?= e($rangeEndSql) ?> · <?= e($rangeLabel) ?></span>
  </div>
  <div class="dashboard-filter-controls">
    <div class="field"><label for="dashboard-range"><?= e(t('Date range', 'Date range')) ?></label><select class="input" id="dashboard-range" name="range"><?php foreach (['today'=>'Today','last_7_days'=>'Last 7 days','last_30_days'=>'Last 30 days','this_month'=>'This month','last_month'=>'Last month','this_year'=>'This year','custom'=>'Custom date range'] as $k=>$l): ?><option value="<?= e($k) ?>"<?= $rangeKey===$k?' selected':'' ?>><?= e(t($l, $l)) ?></option><?php endforeach; ?></select></div>
    <div class="field dashboard-custom-date"<?= $rangeKey!=='custom'?' hidden':'' ?>><label for="dashboard-from"><?= e(t('From', 'From')) ?></label><input class="input" id="dashboard-from" type="date" name="from" value="<?= e($customFromValue) ?>" max="<?= e($today->format('Y-m-d')) ?>"></div>
    <div class="field dashboard-custom-date"<?= $rangeKey!=='custom'?' hidden':'' ?>><label for="dashboard-to"><?= e(t('To', 'To')) ?></label><input class="input" id="dashboard-to" type="date" name="to" value="<?= e($customToValue) ?>" max="<?= e($today->format('Y-m-d')) ?>"></div>
    <div class="field"><label for="dashboard-period"><?= e(t('Chart grouping', 'Chart grouping')) ?></label><select class="input" id="dashboard-period" name="period"><?php foreach (['daily'=>'Daily','weekly'=>'Weekly','monthly'=>'Monthly','yearly'=>'Yearly'] as $k=>$l): ?><option value="<?= e($k) ?>"<?= $period===$k?' selected':'' ?>><?= e(t($l, $l)) ?></option><?php endforeach; ?></select></div>
    <button class="btn btn-primary dashboard-filter-submit btn-interactive" type="submit"><?= e(t('Update dashboard', 'Update dashboard')) ?></button>
  </div>
  <?php if ($rangeError!==''): ?><p class="dashboard-filter-error" role="alert"><?= e($rangeError) ?></p><?php endif; ?>
</form>

<div class="dashboard-section-head reveal-up"><div><span class="dashboard-eyebrow"><?= e(t('FINANCIAL KPIs', 'FINANCIAL KPIs')) ?></span><h3 tabindex="-1"><?= e(t('Revenue, expenses & profit', 'Revenue, expenses & profit')) ?></h3></div><span class="muted small"><?= e(t('Real database records · cash-basis management view', 'Real database records · cash-basis management view')) ?></span></div>
<div class="kpi-grid">
<?php if ($canPayments): ?>
  <a class="kpi-card tone-green reveal-up card-interactive stagger-1" href="<?= e(url('payments/?from='.$rangeStartSql.'&to='.$rangeEndSql)) ?>"><div class="kpi-label"><?= e(t('Total Revenue', 'Total Revenue')) ?> (<?= e($currency) ?>)</div><div class="kpi-value"><?= e($currency.' '.number_format($revenue['total'],2)) ?></div><div class="kpi-sub"><?= e(t('Collected payments less refunds', 'Collected payments less refunds')) ?> · <?= e($rangeLabel) ?></div><div class="kpi-cmp <?= e($revCmp['class']) ?>"><?= e($revCmp['text']) ?></div><?= $sparkRevenue ?></a>
<?php endif; ?>
<?php if ($canExpenses): ?>
  <a class="kpi-card tone-red reveal-up card-interactive stagger-2" href="<?= e(url('expenses/')) ?>"><div class="kpi-label"><?= e(t('Total Expenses', 'Total Expenses')) ?> (<?= e($currency) ?>)</div><div class="kpi-value"><?= $expenses['ready'] ? e($currency.' '.number_format($expenses['total'],2)) : e(t('Not available', 'Not available')) ?></div><div class="kpi-sub"><?= $expenses['ready'] ? e(t('Operating costs + stock purchases (excl. opening stock)', 'Operating costs + stock purchases (excl. opening stock)')) : e(t('Apply expense-ledger migration / grant permission', 'Apply expense-ledger migration / grant permission')) ?></div><?php if ($expenses['ready']): ?><div class="kpi-cmp <?= e($expCmp['class']) ?>"><?= e($expCmp['text']) ?></div><?= $sparkExpenses ?><?php endif; ?></a>
<?php endif; ?>
<?php if ($canPayments): ?>
  <a class="kpi-card <?= $expenses['ready'] ? ($netProfit>=0?'tone-green':'tone-red') : 'tone-muted' ?> reveal-up card-interactive stagger-3" href="<?= e(url('expenses/')) ?>"><div class="kpi-label"><?= e(t('Net Profit / Loss', 'Net Profit / Loss')) ?> (<?= e($currency) ?>)</div><div class="kpi-value"><?= $expenses['ready'] ? e($currency.' '.number_format($netProfit,2)) : e(t('Requires expense ledger', 'Requires expense ledger')) ?></div><div class="kpi-sub"><?= $expenses['ready'] ? e(t('Revenue − recorded expenses (cash-basis estimate)', 'Revenue − recorded expenses (cash-basis estimate)')) : e(t('Expenses not yet recorded in ledger', 'Expenses not yet recorded in ledger')) ?></div><?php if ($expenses['ready']): ?><div class="kpi-cmp <?= e($profitCmp['class']) ?>"><?= e($profitCmp['text']) ?></div><?= $sparkProfit ?><?php endif; ?></a>
<?php endif; ?>
  <div class="kpi-card tone-gold reveal-up card-interactive stagger-4"><div class="kpi-label"><?= e(t('Profit Margin', 'Profit Margin')) ?> (%)</div><div class="kpi-value"><?= ($profitMargin !== null) ? e(number_format($profitMargin,1).'%') : ($expenses['ready'] ? 'N/A' : '—') ?></div><div class="kpi-sub"><?= ($profitMargin !== null) ? e(t('Net profit ÷ collected revenue', 'Net profit ÷ collected revenue')) : ($expenses['ready'] ? e(t('No revenue in period', 'No revenue in period')) : e(t('Expense ledger not ready', 'Expense ledger not ready'))) ?></div><?php if ($marginCmp): ?><div class="kpi-cmp <?= e($marginCmp['class']) ?>"><?= e($marginCmp['text']) ?></div><?php endif; ?></div>
<?php if ($canInvoices): ?>
  <a class="kpi-card tone-amber reveal-up card-interactive stagger-5" href="<?= e(url('invoices/?status=outstanding')) ?>"><div class="kpi-label"><?= e(t('Pending Payments', 'Pending Payments')) ?> (<?= e($currency) ?>)</div><div class="kpi-value"><?= e($currency.' '.number_format($pendingPayments,2)) ?></div><div class="kpi-sub"><?= $pendingCount ?> <?= e(t('unpaid/partially paid invoices (outstanding balance)', 'unpaid/partially paid invoices (outstanding balance)')) ?></div><div class="kpi-cmp trend-flat"><?= e(t('Not collected cash', 'Not collected cash')) ?></div></a>
<?php endif; ?>
<?php if ($pendingVerificationCount > 0): ?>
  <a class="kpi-card tone-amber reveal-up card-interactive" style="border-left:4px solid #F59E0B;" href="<?= e(url('payments/verification.php')) ?>"><div class="kpi-label"><?= e(t('Verification Queue', 'የማረጋገጫ ረድፍ')) ?></div><div class="kpi-value" style="color:#F59E0B;"><?= $pendingVerificationCount ?></div><div class="kpi-sub"><?= e(t('Customer submissions awaiting review', 'Customer submissions awaiting review')) ?></div><div class="kpi-cmp trend-down"><?= e(t('Needs Audit', 'Needs Audit')) ?></div></a>
<?php endif; ?>
<?php if ($canInventory): ?>
  <a class="kpi-card tone-blue reveal-up card-interactive stagger-6" href="<?= e(url('inventory/')) ?>"><div class="kpi-label"><?= e(t('Inventory Value', 'Inventory Value')) ?> (<?= e($currency) ?>)</div><div class="kpi-value"><?= e($currency.' '.number_format($inv['value'],2)) ?></div><div class="kpi-sub"><?= e(t('Active stock at recorded unit cost', 'Active stock at recorded unit cost')) ?> · <?= $inv['items'] ?> <?= e(t('item types', 'item types')) ?></div><div class="kpi-cmp trend-flat"><?= $inv['available'] ?> <?= e(t('in stock', 'in stock')) ?> · <?= $inv['low'] ?> <?= e(t('low', 'low')) ?> · <?= $inv['out'] ?> <?= e(t('out', 'out')) ?></div></a>
<?php endif; ?>
<?php if ($canJobs): ?>
  <a class="kpi-card tone-blue reveal-up card-interactive stagger-7" href="<?= e(url('job-cards/?scope=open')) ?>"><div class="kpi-label"><?= e(t('Active Job Cards', 'Active Job Cards')) ?></div><div class="kpi-value"><?= $activeJobs ?></div><div class="kpi-sub"><?= e(t('Open jobs received in selected period', 'Open jobs received in selected period')) ?></div><?php if ($jobsCmp): ?><div class="kpi-cmp <?= e($jobsCmp['class']) ?>"><?= e($jobsCmp['text']) ?></div><?php endif; ?><?= $sparkJobs ?></a>
  <a class="kpi-card tone-green reveal-up card-interactive stagger-8" href="<?= e(url('job-cards/?status=Completed')) ?>"><div class="kpi-label"><?= e(t('Completed Jobs', 'Completed Jobs')) ?></div><div class="kpi-value"><?= $completedJobs ?></div><div class="kpi-sub"><?= e(t('Completed/delivered', 'Completed/delivered')) ?> · <?= e(t('completion rate', 'completion rate')) ?> <?= e(number_format($completionRate,1)) ?>%</div><?php if ($completedCmp): ?><div class="kpi-cmp <?= e($completedCmp['class']) ?>"><?= e($completedCmp['text']) ?></div><?php endif; ?></a>
<?php endif; ?>
</div>

<div class="dash-progress-grid card reveal-up card-interactive">
  <div class="card-head"><h3><?= e(t('Financial & operations progress', 'Financial & operations progress')) ?></h3><span class="muted small"><?= e(t('Monthly targets compare to current calendar month actuals', 'Monthly targets compare to current calendar month actuals')) ?></span></div>
  <div class="dash-progress-list">
    <?= dash_progress($canPayments ? $monthRevenue : null, $targets['monthly_revenue_target'], t('Monthly revenue target', 'Monthly revenue target')) ?>
    <?= dash_progress($expenses['ready'] ? $monthProfit : null, $targets['monthly_profit_target'], t('Monthly profit target', 'Monthly profit target')) ?>
    <?= dash_progress($expenses['ready'] ? $monthExpenses : null, $targets['monthly_expense_budget'], t('Expense budget utilization', 'Expense budget utilization'), 'money', true) ?>
    <?= dash_progress($completionRate, 100.0, t('Job completion rate (selected period)', 'Job completion rate (selected period)'), 'percent') ?>
    <?= dash_progress($inv['items'] > 0 ? (($inv['items'] - $inv['out']) / $inv['items'] * 100) : null, $inv['items'] > 0 ? 100.0 : null, t('Inventory availability', 'Inventory availability'), 'percent') ?>
  </div>
  <?php if (can('settings.manage')): ?><p class="muted small dash-settings-note"><?= e(t('Configure targets in', 'Configure targets in')) ?> <a href="<?= e(url('users/settings.php')) ?>"><?= e(t('Garage Settings', 'Garage Settings')) ?></a>.</p><?php endif; ?>
</div>

<?php if (can('payments.methods')):
  $topMethods = Database::all(
      "SELECT id, name, name_am, code, type, bank_name, account_name, account_number, phone_number, till_number, merchant_number, is_active
       FROM payment_methods
       WHERE code IN ('cbe', 'telebirr', 'awash', 'dashen', 'boa', 'cash')
       ORDER BY display_order ASC"
  );
?>
<div class="card reveal-up card-interactive" style="border:1px solid rgba(212,175,55,0.35);background:var(--card-bg,#1E293B);margin-bottom:28px;">
  <div class="card-head" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
    <div>
      <span class="dashboard-eyebrow" style="color:var(--gold,#D4AF37);font-size:0.75rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;">
        <?= e(t('PAYMENT CONFIGURATION', 'የክፍያ ማዋቀሪያ')) ?>
      </span>
      <h3 style="margin:2px 0 0 0;"><?= e(t('Garage Bank & Telebirr Accounts', 'የጋራዡ የባንክ እና የቴሌብር ሂሳቦች')) ?></h3>
      <span class="muted small"><?= e(t('Directly edit garage account numbers, merchant till codes, and account holder names.', 'የጋራዡን የባንክ ሂሳብ ቁጥሮች፣ የነጋዴ ቲል ኮዶችን እና የሂሳብ ባለቤት ስሞችን በቀጥታ ያስተካክሉ።')) ?></span>
    </div>
    <div style="display:flex;gap:8px;">
      <a class="btn btn-primary btn-sm btn-interactive" href="<?= e(url('payments/methods.php')) ?>">
        <?= e(t('Manage All Payment Providers →', 'ሁሉንም የክፍያ መንገዶች አስተዳድር →')) ?>
      </a>
    </div>
  </div>
  <div class="grid-3" style="gap:16px;margin-top:16px;">
    <?php foreach ($topMethods as $tm): 
      $rawId = $tm['account_number'] ?: ($tm['merchant_number'] ?: ($tm['till_number'] ?: ($tm['phone_number'] ?: '')));
      $idLabel = $tm['type'] === 'bank' ? t('Account No:', 'የሂሳብ ቁጥር:') : ($tm['type'] === 'mobile_money' ? t('Phone / Till:', 'ስልክ / ቲል:') : t('Payment Channel:', 'የክፍያ ቻናል:'));
    ?>
    <div style="background:rgba(255,255,255,0.03);border:1px solid rgba(255,255,255,0.08);border-radius:8px;padding:14px;position:relative;display:flex;flex-direction:column;justify-content:space-between;">
      <div>
        <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
          <strong style="font-size:1rem;color:var(--text,#fff);">
            <?= e(current_lang() === 'am' && !empty($tm['name_am']) ? $tm['name_am'] : $tm['name']) ?>
          </strong>
          <span class="badge <?= !empty($tm['is_active']) ? 'badge-active' : 'badge-inactive' ?>" style="font-size:0.75rem;">
            <?= !empty($tm['is_active']) ? e(t('Active', 'ንቁ')) : e(t('Inactive', 'ቦዝኗል')) ?>
          </span>
        </div>
        <div style="font-size:0.85rem;margin-bottom:4px;" class="muted">
          <span><?= e(t('Account Name:', 'የሂሳብ ባለቤት:')) ?></span>
          <strong style="color:var(--text,#fff);"><?= e($tm['account_name'] ?: 'ALPHA GARAGE PLC') ?></strong>
        </div>
        <div style="font-size:0.85rem;margin-bottom:8px;">
          <span class="muted"><?= e($idLabel) ?></span>
          <span class="mono" style="font-weight:700;color:var(--gold,#D4AF37);letter-spacing:0.5px;">
            <?= e($rawId ?: '—') ?>
          </span>
        </div>
      </div>
      <div style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(255,255,255,0.06);display:flex;justify-content:flex-end;">
        <a href="<?= e(url('payments/methods.php?edit=' . (int)$tm['id'])) ?>" class="btn btn-ghost btn-sm" style="padding:4px 10px;font-size:0.8rem;">
          ✏️ <?= e(t('Edit Account', 'ሂሳብ አስተካክል')) ?>
        </a>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="dashboard-section-head reveal-up"><div><span class="dashboard-eyebrow"><?= e(t('CHARTS', 'CHARTS')) ?></span><h3><?= e(t('Interactive financial analytics', 'Interactive financial analytics')) ?></h3></div><span class="muted small">Chart.js · updates with date filters (no full reload)</span></div>
<div class="chart-grid">
  <div class="card chart-card chart-card-wide reveal-up card-interactive stagger-1"><div class="card-head"><h3><?= e(t('Revenue vs Expenses', 'Revenue vs Expenses')) ?></h3><span class="muted small"><?= e($rangeLabel) ?> · <?= e(ucfirst($period)) ?></span></div><div class="chart-wrap chart-wrap-lg"><canvas id="chart-revenue-expenses" aria-label="Revenue versus expenses line chart" role="img"></canvas></div><?php if (!$canPayments): ?><p class="analytics-empty">No payments permission.</p><?php endif; ?></div>
  <div class="card chart-card reveal-up card-interactive stagger-2"><div class="card-head"><h3><?= e(t('Profit & Loss Analysis', 'Profit & Loss Analysis')) ?></h3><span class="muted small"><?= e(t('Revenue, expenses, net profit', 'Revenue, expenses, net profit')) ?></span></div><div class="chart-wrap"><canvas id="chart-profit-loss" aria-label="Profit and loss bar chart" role="img"></canvas></div><?php if (!($expenses['ready'] && $canPayments)): ?><p class="analytics-empty">Requires payments permission and expense-ledger migration.</p><?php endif; ?></div>
  <div class="card chart-card reveal-up card-interactive stagger-3"><div class="card-head"><h3><?= e(t('Revenue Performance', 'Revenue Performance')) ?></h3><span class="muted small"><?= e(t('Net collected revenue trend', 'Net collected revenue trend')) ?></span></div><div class="chart-wrap"><canvas id="chart-revenue-area" aria-label="Revenue area chart" role="img"></canvas></div></div>
  <div class="card chart-card reveal-up card-interactive stagger-4"><div class="card-head"><h3><?= e(t('Expense Distribution', 'Expense Distribution')) ?></h3><span class="muted small"><?= e(t('By category', 'By category')) ?> · <?= e($rangeLabel) ?></span></div><div class="chart-wrap"><canvas id="chart-expense-donut" aria-label="Expense distribution doughnut chart" role="img"></canvas></div><?php if (!($expenses['ready'] && !empty($expenses['by_category']))): ?><p class="analytics-empty"><?= e(t('No expense records in this period.', 'No expense records in this period.')) ?></p><?php endif; ?></div>
  <div class="card chart-card reveal-up card-interactive stagger-5"><div class="card-head"><h3><?= e(t('Job Card Analysis', 'Job Card Analysis')) ?></h3><span class="muted small"><?= e(t('Status distribution', 'Status distribution')) ?> · <?= e($rangeLabel) ?></span></div><div class="chart-wrap"><canvas id="chart-jobs-donut" aria-label="Job card status doughnut chart" role="img"></canvas></div></div>
  <div class="card chart-card reveal-up card-interactive stagger-6"><div class="card-head"><h3><?= e(t('Inventory Analytics', 'Inventory Analytics')) ?></h3><span class="muted small"><?= e(t('Units by category (active stock)', 'Units by category (active stock)')) ?></span></div><div class="chart-wrap"><canvas id="chart-inventory-bar" aria-label="Inventory by category bar chart" role="img"></canvas></div><?php if (empty($inv['by_category'])): ?><p class="analytics-empty"><?= e(t('No active inventory records.', 'No active inventory records.')) ?></p><?php endif; ?></div>
  <div class="card chart-card chart-card-wide reveal-up card-interactive stagger-7"><div class="card-head"><h3><?= e(t('Service Performance', 'Service Performance')) ?></h3><span class="muted small"><?= e(t('Most requested services and revenue', 'Most requested services and revenue')) ?> · <?= e($rangeLabel) ?></span></div><div class="chart-wrap"><canvas id="chart-services" aria-label="Service performance horizontal bar chart" role="img"></canvas></div><?php if (empty($servicePerf)): ?><p class="analytics-empty"><?= e(t('No job service records in this period.', 'No job service records in this period.')) ?></p><?php endif; ?></div>
</div>

<?php if ($canInventory): ?>
<div class="dashboard-section-head reveal-up"><div><span class="dashboard-eyebrow"><?= e(t('INVENTORY', 'INVENTORY')) ?></span><h3><?= e(t('Stock indicators & alerts', 'Stock indicators & alerts')) ?></h3></div><a class="small" href="<?= e(url('inventory/')) ?>"><?= e(t('Manage inventory', 'Manage inventory')) ?></a></div>
<div class="inventory-status-grid reveal-stagger">
  <div class="inv-stat card-interactive"><strong><?= $inv['items'] ?></strong><span><?= e(t('Total inventory items', 'Total inventory items')) ?></span></div>
  <div class="inv-stat status-ok card-interactive"><strong><?= $inv['available'] ?></strong><span><?= e(t('Available stock types', 'Available stock types')) ?></span></div>
  <a class="inv-stat status-warn card-interactive" href="<?= e(url('inventory/?stock=low')) ?>"><strong><?= $inv['low'] ?></strong><span><?= e(t('Low stock', 'Low stock')) ?></span></a>
  <a class="inv-stat status-bad card-interactive" href="<?= e(url('inventory/?stock=low')) ?>"><strong><?= $inv['out'] ?></strong><span><?= e(t('Out of stock', 'Out of stock')) ?></span></a>
  <div class="inv-stat card-interactive"><strong><?= e($currency.' '.number_format($inv['value'],2)) ?></strong><span><?= e(t('Inventory valuation', 'Inventory valuation')) ?></span></div>
</div>
<?php if ($adminPendingParts): ?>
<div class="card reveal-up card-interactive mb-4" style="border-top: 4px solid #D4AF37;">
  <div class="card-head">
    <div>
      <h3 style="display:flex; align-items:center; gap:8px;">
        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#D4AF37" stroke-width="2" aria-hidden="true"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <?= e(t('Operations Approval & Removal Queue', 'Operations Approval & Removal Queue')) ?>
      </h3>
      <span class="muted small"><?= e(t('Review technician requests: 1-click Approve to issue parts from warehouse, or Remove / Reject to decline.', 'Review technician requests: 1-click Approve to issue parts from warehouse, or Remove / Reject to decline.')) ?></span>
    </div>
    <span class="badge badge-pending"><?= count($adminPendingParts) ?> <?= e(t('decisions pending', 'decisions pending')) ?></span>
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
        <?php foreach ($adminPendingParts as $pr):
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
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php $lowList = InventoryService::lowStock(8); if ($lowList): ?>
<div class="card reveal-up card-interactive"><div class="card-head"><h3><?= e(t('Inventory alerts', 'Inventory alerts')) ?></h3><span class="muted small"><?= e(t('Click an item to review stock', 'Click an item to review stock')) ?></span></div>
<div class="inv-alert-list"><?php foreach ($lowList as $p):
  $qty=(int)$p['quantity']; $reorder=max(1,(int)$p['reorder_level']); $pct=min(100,round(($qty/$reorder)*100));
  $cls = $qty===0 ? 'status-bad' : 'status-warn'; $label = $qty===0 ? t('Out of stock', 'Out of stock') : t('Low stock', 'Low stock');
?><a class="inv-alert <?= $cls ?>" href="<?= e(url('inventory/history.php?part_id='.$p['id'])) ?>"><div class="inv-alert-main"><strong><?= e($p['name']) ?></strong><span class="muted small"><?= e($p['sku']) ?> · <?= $qty ?> <?= e($p['unit']) ?> <?= e(t('left (reorder at', 'left (reorder at')) ?> <?= $reorder ?>)</span></div><span class="badge <?= $qty===0?'badge-cancelled':'badge-pending' ?>"><?= e($label) ?></span><div class="inv-progress"><div class="inv-progress-fill meter-<?= (int)(round(max(0,min(100,$pct))/5)*5) ?>"></div></div></a><?php endforeach; ?></div></div>
<?php endif; ?>
<?php if (!empty($inv['top_used'])): ?><div class="card reveal-up card-interactive"><div class="card-head"><h3><?= e(t('Most frequently used spare parts', 'Most frequently used spare parts')) ?></h3><span class="muted small"><?= e(t('Based on issued (non-returned) job parts', 'Based on issued (non-returned) job parts')) ?></span></div><ul class="ranking-list"><?php foreach ($inv['top_used'] as $tp): ?><li><span><strong><?= e($tp['label']) ?></strong></span><b><?= (int)$tp['value'] ?> <?= e(t('issued', 'issued')) ?></b></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php endif; ?>

<?php if (!$expenses['ready'] && $canExpenses): ?>
<div class="alert alert-warn" role="status"><strong>Expense ledger not ready.</strong> Apply <code>database/migrations/20261108_expense_ledger.sql</code> and grant the runtime account <code>SELECT, INSERT</code> on <code>expenses</code>. Profit/loss and expense charts will use real ledger data once enabled. See documentation/INSTALLATION_GUIDE.md.</div>
<?php endif; ?>

<p class="analytics-limit-note"><strong><?= e(t('Financial definitions (real records only):', 'Financial definitions (real records only):')) ?></strong> <?= e(t('Total Revenue = payments received minus refunds (collected cash). Total Expenses = manual expense ledger entries + inventory stock purchases (opening stock excluded). Net Profit/Loss is a cash-basis management estimate, not audited accrual profit. Pending Payments = outstanding invoice balances (invoiced amounts not yet collected). No sample or fabricated values are displayed.', 'Total Revenue = payments received minus refunds (collected cash). Total Expenses = manual expense ledger entries + inventory stock purchases (opening stock excluded). Net Profit/Loss is a cash-basis management estimate, not audited accrual profit. Pending Payments = outstanding invoice balances (invoiced amounts not yet collected). No sample or fabricated values are displayed.')) ?></p>
</div><!-- /dashboard-data -->

<?php ui_footer();
