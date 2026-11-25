<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — MECHANIC DASHBOARD
 * Workstation repair queue, assigned job progress, and priority maintenance tasks.
 * Strictly scoped to the authenticated mechanic's ID (horizontal IDOR isolation).
 */

$pdo = Database::pdo();
$garageName = Setting::get('garage_name', 'Alpha Garage');
$mechanicId = (int) ($user['mechanic_id'] ?? 0);

// Resolve mechanic profile details
$mechanicProfile = null;
if ($mechanicId > 0) {
    $mechanicProfile = Database::one("SELECT * FROM mechanics WHERE id = ? AND is_active = 1", [$mechanicId]);
}

// 1. Mechanic Workstation KPIs (Strictly scoped to mechanic_id)
$activeJobsCount = 0;
$urgentJobsCount = 0;
$completedJobsCount = 0;
$myActiveJobs = [];
$myCompletedJobs = [];

// Prepare 6-month array for throughput trend
$months = [];
for ($i = 5; $i >= 0; $i--) {
    $mKey = date('Y-m', strtotime("-$i months"));
    $mLabel = date('M Y', strtotime("-$i months"));
    $months[$mKey] = ['label' => $mLabel, 'count' => 0];
}
$mechStatusRows = [];
$mechPriorityRows = [];

if ($mechanicId > 0) {
    $activeJobsCount = (int) Database::value(
        "SELECT COUNT(*) FROM job_cards WHERE mechanic_id = ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')",
        [$mechanicId]
    );

    $urgentJobsCount = (int) Database::value(
        "SELECT COUNT(*) FROM job_cards WHERE mechanic_id = ? AND priority IN ('High','Urgent') AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')",
        [$mechanicId]
    );

    $completedJobsCount = (int) Database::value(
        "SELECT COUNT(*) FROM job_cards WHERE mechanic_id = ? AND status IN ('Completed','Delivered')",
        [$mechanicId]
    );

    // 2. Active Work Queue
    $myActiveJobs = Database::all(
        "SELECT j.id, j.job_number, j.date_received, j.estimated_completion, j.status, j.priority,
                j.reported_problem, j.diagnosis, j.labor_cost,
                v.plate_number, v.make, v.model, v.year, v.mileage,
                c.full_name AS customer_name
         FROM job_cards j
         JOIN vehicles v ON v.id = j.vehicle_id
         JOIN customers c ON c.id = j.customer_id
         WHERE j.mechanic_id = ? AND j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')
         ORDER BY CASE j.priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Normal' THEN 3 ELSE 4 END, j.date_received ASC",
        [$mechanicId]
    );

    // 3. Recently Completed Jobs
    $myCompletedJobs = Database::all(
        "SELECT j.id, j.job_number, j.date_received, j.actual_completion, j.status, j.priority, j.reported_problem,
                v.plate_number, v.make, v.model
         FROM job_cards j
         JOIN vehicles v ON v.id = j.vehicle_id
         WHERE j.mechanic_id = ? AND j.status IN ('Completed','Delivered')
         ORDER BY j.actual_completion DESC, j.id DESC
         LIMIT 5",
        [$mechanicId]
    );

    // 4. Chart Metrics: Status distribution
    $mechStatusRows = Database::all(
        "SELECT status, COUNT(*) AS count_val FROM job_cards WHERE mechanic_id = ? GROUP BY status",
        [$mechanicId]
    );

    // 5. Chart Metrics: Priority distribution of active jobs
    $mechPriorityRows = Database::all(
        "SELECT priority, COUNT(*) AS count_val FROM job_cards WHERE mechanic_id = ? AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress') GROUP BY priority",
        [$mechanicId]
    );

    // 6. Chart Metrics: 6-month completion trend
    $trendDb = Database::all(
        "SELECT DATE_FORMAT(COALESCE(actual_completion, date_received), '%Y-%m') AS ym, COUNT(*) AS count_val
         FROM job_cards
         WHERE mechanic_id = ? AND status IN ('Completed','Delivered')
           AND COALESCE(actual_completion, date_received) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY ym",
        [$mechanicId]
    );
    foreach ($trendDb as $r) {
        if (isset($months[$r['ym']])) {
            $months[$r['ym']]['count'] = (int)$r['count_val'];
        }
    }
}

$mechanicChartPayload = [
    'jobStatus' => [
        'labels' => !empty($mechStatusRows) ? array_values(array_column($mechStatusRows, 'status')) : [],
        'data' => !empty($mechStatusRows) ? array_values(array_map('intval', array_column($mechStatusRows, 'count_val'))) : [],
    ],
    'priorityDist' => [
        'labels' => !empty($mechPriorityRows) ? array_values(array_column($mechPriorityRows, 'priority')) : [],
        'data' => !empty($mechPriorityRows) ? array_values(array_map('intval', array_column($mechPriorityRows, 'count_val'))) : [],
    ],
    'monthlyTrend' => [
        'labels' => $mechanicId > 0 ? array_values(array_column(array_values($months), 'label')) : [],
        'data' => $mechanicId > 0 ? array_values(array_map('intval', array_column(array_values($months), 'count'))) : [],
    ],
];

ui_header('Mechanic Workstation', 'dashboard');
?>

<div class="hero reveal-up">
  <div>
    <h2><?= e(t('Welcome back', 'Welcome back')) ?>, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
    <p><?= e($user['role_label']) ?> · <?= e($garageName) ?> · <?= e(t('Workstation', 'Workstation')) ?>: <?= $mechanicProfile ? e($mechanicProfile['full_name'] . ' (' . t($mechanicProfile['specialty'] ?: 'Certified Technician', $mechanicProfile['specialty'] ?: 'Certified Technician') . ')') : e(t('Unassigned Workstation', 'Unassigned Workstation')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-back-home btn-interactive" href="<?= e(url('index.php')) ?>" title="Back to public homepage">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <?= e(t('Back to Home', 'Back to Home')) ?>
    </a>
    <button class="btn btn-ghost theme-toggle dashboard-theme-toggle btn-interactive" type="button" aria-label="Switch theme" title="Switch theme"><span aria-hidden="true">◐</span><span class="theme-toggle-label"><?= e(t('Theme', 'Theme')) ?></span></button>
    <a class="btn btn-primary btn-interactive" href="<?= e(url('job-cards/')) ?>"><?= e(t('My job cards', 'My job cards')) ?></a>
    <?php if (can('inventory.view')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('inventory/')) ?>"><?= e(t('Browse parts', 'Browse parts')) ?></a><?php endif; ?>
    <?php if (can('services.view')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('services/')) ?>"><?= e(t('Service catalog', 'Service catalog')) ?></a><?php endif; ?>
  </div>
</div>

<div class="dashboard-data" id="dashboard-data" data-chart-payload="<?= e(json_encode($mechanicChartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">

<?php if ($mechanicId < 1): ?>
  <div class="alert alert-warn reveal-fade" role="alert">
    <strong><?= e(t('Workstation Notice', 'Workstation Notice')) ?>:</strong> <?= e(t('Your user account is not currently linked to an active mechanic profile. Please request your garage administrator to assign your mechanic profile under Workshop Settings.', 'Your user account is not currently linked to an active mechanic profile. Please request your garage administrator to assign your mechanic profile under Workshop Settings.')) ?>
  </div>
<?php endif; ?>

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('WORKSTATION METRICS', 'WORKSTATION METRICS')) ?></span><h3><?= e(t('My repair queue & performance', 'My repair queue & performance')) ?></h3></div>
  <span class="muted small"><?= e(t('Assigned tasks strictly scoped to your workstation', 'Assigned tasks strictly scoped to your workstation')) ?></span>
</div>

<div class="kpi-grid">
  <div class="kpi-card tone-blue reveal-up card-interactive stagger-1">
    <div class="kpi-label"><?= e(t('Active Assigned Jobs', 'Active Assigned Jobs')) ?></div>
    <div class="kpi-value"><?= $activeJobsCount ?></div>
    <div class="kpi-sub"><?= e(t('Vehicles currently in your queue', 'Vehicles currently in your queue')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('In Progress / Diagnosing', 'In Progress / Diagnosing')) ?></div>
  </div>

  <div class="kpi-card <?= $urgentJobsCount > 0 ? 'tone-red' : 'tone-gold' ?> reveal-up card-interactive stagger-2">
    <div class="kpi-label"><?= e(t('High & Urgent Priority', 'High & Urgent Priority')) ?></div>
    <div class="kpi-value"><?= $urgentJobsCount ?></div>
    <div class="kpi-sub"><?= $urgentJobsCount > 0 ? e(t('Requires immediate diagnostic focus', 'Requires immediate diagnostic focus')) : e(t('No urgent bottlenecks', 'No urgent bottlenecks')) ?></div>
    <div class="kpi-cmp <?= $urgentJobsCount > 0 ? 'trend-down' : 'trend-flat' ?>"><?= $urgentJobsCount > 0 ? e(t('High priority', 'High priority')) : e(t('Normal workflow', 'Normal workflow')) ?></div>
  </div>

  <div class="kpi-card tone-green reveal-up card-interactive stagger-3">
    <div class="kpi-label"><?= e(t('Completed Repairs', 'Completed Repairs')) ?></div>
    <div class="kpi-value"><?= $completedJobsCount ?></div>
    <div class="kpi-sub"><?= e(t('Total vehicles serviced & completed', 'Total vehicles serviced & completed')) ?></div>
    <div class="kpi-cmp trend-up"><?= e(t('Lifetime completed', 'Lifetime completed')) ?></div>
  </div>
</div>

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('WORKSTATION ANALYTICS', 'WORKSTATION ANALYTICS')) ?></span><h3><?= e(t('Workload distribution & repair throughput', 'Workload distribution & repair throughput')) ?></h3></div>
  <span class="muted small"><?= e(t('Assigned vehicle distribution and monthly repair completions', 'Assigned vehicle distribution and monthly repair completions')) ?></span>
</div>

<div class="chart-grid mb-4">
  <div class="card chart-card reveal-up card-interactive stagger-1">
    <div class="card-head">
      <h3><?= e(t('Assigned Job Status', 'Assigned Job Status')) ?></h3>
      <span class="muted small"><?= e(t('Workstation load across all stages', 'Workstation load across all stages')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-mechanic-jobs-status" aria-label="Mechanic job status doughnut chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card reveal-up card-interactive stagger-2">
    <div class="card-head">
      <h3><?= e(t('Active Task Urgency', 'Active Task Urgency')) ?></h3>
      <span class="muted small"><?= e(t('Priority distribution of open jobs', 'Priority distribution of open jobs')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-mechanic-priority-dist" aria-label="Mechanic priority distribution doughnut chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card chart-card-wide reveal-up card-interactive stagger-3">
    <div class="card-head">
      <h3><?= e(t('6-Month Repair Throughput', '6-Month Repair Throughput')) ?></h3>
      <span class="muted small"><?= e(t('Monthly completed vehicle handoffs', 'Monthly completed vehicle handoffs')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-mechanic-completion-trend" aria-label="Mechanic monthly completion trend bar chart" role="img"></canvas>
    </div>
  </div>
</div>

<!-- Active Work Queue -->
<div class="card mb-4 reveal-up card-interactive">
  <div class="card-head">
    <div>
      <h3><?= e(t('My Active Repair Queue', 'My Active Repair Queue')) ?></h3>
      <span class="muted small"><?= e(t('Vehicles assigned to you requiring diagnosis, parts, or repair', 'Vehicles assigned to you requiring diagnosis, parts, or repair')) ?></span>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/?scope=open')) ?>"><?= e(t('Open full list', 'Open full list')) ?></a>
  </div>
  <div class="table-wrap">
    <table class="table stack">
      <thead>
        <tr>
          <th><?= e(t('Job Card', 'Job Card')) ?></th>
          <th><?= e(t('Priority', 'Priority')) ?></th>
          <th><?= e(t('Vehicle', 'Vehicle')) ?></th>
          <th><?= e(t('Customer', 'Customer')) ?></th>
          <th><?= e(t('Reported Problem / Diagnosis', 'Reported Problem / Diagnosis')) ?></th>
          <th><?= e(t('Status', 'Status')) ?></th>
          <th><?= e(t('Actions', 'Actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($myActiveJobs): ?>
          <?php foreach ($myActiveJobs as $job): ?>
            <tr>
              <td class="nowrap">
                <a href="<?= e(url('job-cards/view.php?id=' . $job['id'])) ?>">
                  <strong><?= e($job['job_number']) ?></strong>
                </a>
                <div class="muted small"><?= e(fmt_date($job['date_received'])) ?></div>
              </td>
              <td>
                <?php if ($job['priority'] === 'Urgent'): ?>
                  <span class="badge badge-cancelled"><?= e(t('Urgent', 'Urgent')) ?></span>
                <?php elseif ($job['priority'] === 'High'): ?>
                  <span class="badge badge-pending"><?= e(t('High', 'High')) ?></span>
                <?php else: ?>
                  <span class="badge"><?= e(t($job['priority'], $job['priority'])) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= e($job['plate_number']) ?></strong>
                <div class="muted small"><?= e($job['make'] . ' ' . $job['model']) ?> <?= $job['year'] ? '· ' . e((string)$job['year']) : '' ?></div>
              </td>
              <td><?= e($job['customer_name']) ?></td>
              <td>
                <div><strong><?= e(mb_strimwidth($job['reported_problem'], 0, 80, '…')) ?></strong></div>
                <?php if (!empty($job['diagnosis'])): ?>
                  <div class="muted small">Diag: <?= e(mb_strimwidth($job['diagnosis'], 0, 70, '…')) ?></div>
                <?php endif; ?>
              </td>
              <td><?= badge($job['status']) ?></td>
              <td class="nowrap">
                <a class="btn btn-primary btn-sm" href="<?= e(url('job-cards/view.php?id=' . $job['id'])) ?>"><?= e(t('Work on job', 'Work on job')) ?> &rarr;</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="7" class="empty"><?= e(t('No active repair jobs assigned to your workstation at this time.', 'No active repair jobs assigned to your workstation at this time.')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Recently Completed Repairs by this Mechanic -->
<div class="card reveal-up card-interactive">
  <div class="card-head">
    <div>
      <h3><?= e(t('Recently Completed Work', 'Recently Completed Work')) ?></h3>
      <span class="muted small"><?= e(t('Your latest successful repair handoffs', 'Your latest successful repair handoffs')) ?></span>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/?status=Completed')) ?>"><?= e(t('Completed history', 'Completed history')) ?></a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('Job No.', 'Job No.')) ?></th>
          <th><?= e(t('Vehicle', 'Vehicle')) ?></th>
          <th><?= e(t('Problem Addressed', 'Problem Addressed')) ?></th>
          <th><?= e(t('Completion Date', 'Completion Date')) ?></th>
          <th><?= e(t('Status', 'Status')) ?></th>
          <th><?= e(t('Action', 'Action')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($myCompletedJobs): ?>
          <?php foreach ($myCompletedJobs as $cj): ?>
            <tr>
              <td><a href="<?= e(url('job-cards/view.php?id=' . $cj['id'])) ?>"><strong><?= e($cj['job_number']) ?></strong></a></td>
              <td><strong><?= e($cj['plate_number']) ?></strong> <span class="muted small"><?= e($cj['make'] . ' ' . $cj['model']) ?></span></td>
              <td><?= e(mb_strimwidth($cj['reported_problem'], 0, 60, '…')) ?></td>
              <td class="nowrap"><?= e($cj['actual_completion'] ? fmt_date($cj['actual_completion']) : t('Recently', 'Recently')) ?></td>
              <td><?= badge($cj['status']) ?></td>
              <td><a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/view.php?id=' . $cj['id'])) ?>"><?= e(t('Review', 'Review')) ?></a></td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6" class="empty"><?= e(t('No completed job history recorded yet.', 'No completed job history recorded yet.')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /.dashboard-data -->

<?php ui_footer();
