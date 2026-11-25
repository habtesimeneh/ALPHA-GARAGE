<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — RECEPTIONIST DASHBOARD
 * Front desk operations, customer intake, vehicle registration, and appointment scheduling.
 */

$pdo = Database::pdo();
$garageName = Setting::get('garage_name', 'Alpha Garage');

// 1. Reception KPI Metrics
$totalCustomers = (int) Database::value("SELECT COUNT(*) FROM customers WHERE is_active = 1");
$totalVehicles = (int) Database::value("SELECT COUNT(*) FROM vehicles WHERE is_active = 1");
$todayApptCount = (int) Database::value("SELECT COUNT(*) FROM appointments WHERE appointment_date = CURDATE()");
$pendingApptCount = (int) Database::value("SELECT COUNT(*) FROM appointments WHERE status = 'scheduled' AND appointment_date >= CURDATE()");
$activeJobCount = (int) Database::value("SELECT COUNT(*) FROM job_cards WHERE status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')");

// 2. Today's Appointments
$todayAppointments = Database::all(
    "SELECT a.*, c.full_name AS customer_name, c.phone AS customer_phone, v.plate_number, v.make, v.model, s.name AS service_name
     FROM appointments a
     JOIN customers c ON c.id = a.customer_id
     JOIN vehicles v ON v.id = a.vehicle_id
     LEFT JOIN services s ON s.id = a.service_id
     WHERE a.appointment_date = CURDATE()
     ORDER BY a.appointment_time ASC
     LIMIT 15"
);

// 3. Active Workshop Job Cards (intake & tracking)
$activeJobCards = Database::all(
    "SELECT j.id, j.job_number, j.date_received, j.status, j.priority, j.reported_problem,
            c.full_name AS customer_name, v.plate_number, v.make, v.model, m.full_name AS mechanic_name
     FROM job_cards j
     JOIN customers c ON c.id = j.customer_id
     JOIN vehicles v ON v.id = j.vehicle_id
     LEFT JOIN mechanics m ON m.id = j.mechanic_id
     WHERE j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')
     ORDER BY CASE j.priority WHEN 'Urgent' THEN 1 WHEN 'High' THEN 2 WHEN 'Normal' THEN 3 ELSE 4 END, j.id DESC
     LIMIT 10"
);

// 4. Recently Registered Customers
$recentCustomers = Database::all(
    "SELECT c.id, c.full_name, c.phone, c.email, c.registered_at,
            (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id AND v.is_active = 1) AS vehicle_count
     FROM customers c
     WHERE c.is_active = 1
     ORDER BY c.id DESC
     LIMIT 6"
);

// 5. Receptionist Chart Aggregations (Strictly Non-Financial & Authorized)
$apptStatusRows = Database::all("SELECT status, COUNT(*) as cnt FROM appointments GROUP BY status");
$apptStatusCounts = ['scheduled' => 0, 'confirmed' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($apptStatusRows as $r) {
    if (isset($apptStatusCounts[$r['status']])) {
        $apptStatusCounts[$r['status']] = (int) $r['cnt'];
    }
}

$apptTrendRows = Database::all(
    "SELECT appointment_date, COUNT(*) as cnt
     FROM appointments
     WHERE appointment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 13 DAY) AND CURDATE()
     GROUP BY appointment_date
     ORDER BY appointment_date ASC"
);
$trendMap = array_column($apptTrendRows, 'cnt', 'appointment_date');
$apptTrend = [];
$curDay = (new DateTimeImmutable('-13 days'))->setTime(0, 0, 0);
$endDay = (new DateTimeImmutable('today'))->setTime(23, 59, 59);
while ($curDay <= $endDay) {
    $dStr = $curDay->format('Y-m-d');
    $apptTrend[] = [
        'label' => $curDay->format('M j'),
        'count' => (int) ($trendMap[$dStr] ?? 0),
    ];
    $curDay = $curDay->modify('+1 day');
}

$receptionChartPayload = [
    'apptStatus' => $apptStatusCounts,
    'apptTrend' => $apptTrend,
];

ui_header('Reception Dashboard', 'dashboard');
?>

<div class="hero reveal-up">
  <div>
    <h2><?= e(t('Welcome back', 'Welcome back')) ?>, <?= e(explode(' ', $user['full_name'])[0]) ?></h2>
    <p><?= e($user['role_label']) ?> · <?= e($garageName) ?> · <?= e(t('Front Desk & Workshop Intake Command', 'Front Desk & Workshop Intake Command')) ?></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-back-home btn-interactive" href="<?= e(url('index.php')) ?>" title="Back to public homepage">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <?= e(t('Back to Home', 'Back to Home')) ?>
    </a>
    <button class="btn btn-ghost theme-toggle dashboard-theme-toggle btn-interactive" type="button" aria-label="Switch theme" title="Switch theme"><span aria-hidden="true">◐</span><span class="theme-toggle-label"><?= e(t('Theme', 'Theme')) ?></span></button>
    <?php if (can('appointments.manage')): ?><a class="btn btn-primary btn-interactive" href="<?= e(url('appointments/form.php')) ?>"><?= e(t('+ Book appointment', '+ Book appointment')) ?></a><?php endif; ?>
    <?php if (can('jobs.create')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('job-cards/form.php')) ?>"><?= e(t('+ New job card', '+ New job card')) ?></a><?php endif; ?>
    <?php if (can('customers.manage')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('customers/form.php')) ?>"><?= e(t('+ New customer', '+ New customer')) ?></a><?php endif; ?>
    <?php if (can('vehicles.manage')): ?><a class="btn btn-ghost btn-interactive" href="<?= e(url('vehicles/form.php')) ?>"><?= e(t('+ New vehicle', '+ New vehicle')) ?></a><?php endif; ?>
  </div>
</div>

<div class="dashboard-data" id="dashboard-data" data-chart-payload="<?= e(json_encode($receptionChartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('OPERATIONAL OVERVIEW', 'OPERATIONAL OVERVIEW')) ?></span><h3><?= e(t('Front desk & workshop activity', 'Front desk & workshop activity')) ?></h3></div>
  <span class="muted small"><?= e(t('Real-time status', 'Real-time status')) ?> · <?= e(date('l, F j, Y')) ?></span>
</div>

<div class="kpi-grid">
  <a class="kpi-card tone-green reveal-up card-interactive stagger-1" href="<?= e(url('customers/')) ?>">
    <div class="kpi-label"><?= e(t('Registered Customers', 'Registered Customers')) ?></div>
    <div class="kpi-value"><?= $totalCustomers ?></div>
    <div class="kpi-sub"><?= e(t('Active client accounts', 'Active client accounts')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Workshop directory', 'Workshop directory')) ?></div>
  </a>

  <a class="kpi-card tone-blue reveal-up card-interactive stagger-2" href="<?= e(url('vehicles/')) ?>">
    <div class="kpi-label"><?= e(t('Registered Vehicles', 'Registered Vehicles')) ?></div>
    <div class="kpi-value"><?= $totalVehicles ?></div>
    <div class="kpi-sub"><?= e(t('Vehicles under care', 'Vehicles under care')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Fleet inventory', 'Fleet inventory')) ?></div>
  </a>

  <a class="kpi-card tone-gold reveal-up card-interactive stagger-3" href="<?= e(url('appointments/')) ?>">
    <div class="kpi-label"><?= e(t("Today's Appointments", "Today's Appointments")) ?></div>
    <div class="kpi-value"><?= $todayApptCount ?></div>
    <div class="kpi-sub"><?= $pendingApptCount ?> <?= e(t('upcoming scheduled appointments', 'upcoming scheduled appointments')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Customer schedule', 'Customer schedule')) ?></div>
  </a>

  <a class="kpi-card tone-amber reveal-up card-interactive stagger-4" href="<?= e(url('job-cards/?scope=open')) ?>">
    <div class="kpi-label"><?= e(t('Active Job Cards', 'Active Job Cards')) ?></div>
    <div class="kpi-value"><?= $activeJobCount ?></div>
    <div class="kpi-sub"><?= e(t('Workshop floor repair operations', 'Workshop floor repair operations')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('In diagnosis / repair', 'In diagnosis / repair')) ?></div>
  </a>
</div>

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('APPOINTMENT ANALYTICS', 'APPOINTMENT ANALYTICS')) ?></span><h3><?= e(t('Booking trends & status distribution', 'Booking trends & status distribution')) ?></h3></div>
  <span class="muted small"><?= e(t('Visual workload analysis · Real database records', 'Visual workload analysis · Real database records')) ?></span>
</div>

<div class="chart-grid mb-4">
  <div class="card chart-card reveal-up card-interactive stagger-1">
    <div class="card-head">
      <h3><?= e(t('Appointment Status', 'Appointment Status')) ?></h3>
      <span class="muted small"><?= e(t('Workshop booking lifecycle', 'Workshop booking lifecycle')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-reception-appts-status" aria-label="Appointment status doughnut chart" role="img"></canvas>
    </div>
  </div>

  <div class="card chart-card reveal-up card-interactive stagger-2">
    <div class="card-head">
      <h3><?= e(t('14-Day Booking Activity', '14-Day Booking Activity')) ?></h3>
      <span class="muted small"><?= e(t('Daily customer appointment intake', 'Daily customer appointment intake')) ?></span>
    </div>
    <div class="chart-wrap">
      <canvas id="chart-reception-appts-trend" aria-label="Appointment 14-day booking trend bar chart" role="img"></canvas>
    </div>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <!-- Today's Scheduled Appointments -->
  <div class="card reveal-up card-interactive">
    <div class="card-head">
      <div>
        <h3><?= t("Today's Service Schedule", "Today's Service Schedule") ?></h3>
        <span class="muted small"><?= e(t('Arrivals and scheduled services for today', 'Arrivals and scheduled services for today')) ?></span>
      </div>
      <?php if (can('appointments.view')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('appointments/')) ?>"><?= e(t('View all', 'View all')) ?></a><?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Time', 'Time')) ?></th>
            <th><?= e(t('Customer & Vehicle', 'Customer & Vehicle')) ?></th>
            <th><?= e(t('Service', 'Service')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th><?= e(t('Action', 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($todayAppointments): ?>
            <?php foreach ($todayAppointments as $apt): ?>
              <tr>
                <td class="nowrap"><strong><?= e(substr($apt['appointment_time'], 0, 5)) ?></strong></td>
                <td>
                  <strong><?= e($apt['customer_name']) ?></strong>
                  <div class="muted small"><?= e($apt['plate_number']) ?> · <?= e($apt['make'] . ' ' . $apt['model']) ?></div>
                </td>
                <td><?= e(t($apt['service_name'] ?: 'General Service', $apt['service_name'] ?: 'General Service')) ?></td>
                <td><?= badge($apt['status']) ?></td>
                <td>
                  <?php if (can('appointments.manage')): ?>
                    <a class="btn btn-ghost btn-sm" href="<?= e(url('appointments/form.php?id=' . $apt['id'])) ?>"><?= e(t('Manage', 'Manage')) ?></a>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="empty"><?= e(t('No appointments scheduled for today.', 'No appointments scheduled for today.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Active Job Cards Under Repair -->
  <div class="card reveal-up card-interactive">
    <div class="card-head">
      <div>
        <h3><?= e(t('Active Workshop Repairs', 'Active Workshop Repairs')) ?></h3>
        <span class="muted small"><?= e(t('Live job cards sorted by priority', 'Live job cards sorted by priority')) ?></span>
      </div>
      <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/?scope=open')) ?>"><?= e(t('View all', 'View all')) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Job No.', 'Job No.')) ?></th>
            <th><?= e(t('Vehicle & Customer', 'Vehicle & Customer')) ?></th>
            <th><?= e(t('Priority', 'Priority')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th><?= e(t('Mechanic', 'Mechanic')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($activeJobCards): ?>
            <?php foreach ($activeJobCards as $jc): ?>
              <tr>
                <td class="nowrap"><a href="<?= e(url('job-cards/view.php?id=' . $jc['id'])) ?>"><strong><?= e($jc['job_number']) ?></strong></a></td>
                <td>
                  <strong><?= e($jc['plate_number']) ?></strong>
                  <div class="muted small"><?= e($jc['customer_name']) ?> · <?= e($jc['make'] . ' ' . $jc['model']) ?></div>
                </td>
                <td>
                  <?php if ($jc['priority'] === 'Urgent'): ?>
                    <span class="badge badge-cancelled"><?= e(t('Urgent', 'Urgent')) ?></span>
                  <?php elseif ($jc['priority'] === 'High'): ?>
                    <span class="badge badge-pending"><?= e(t('High', 'High')) ?></span>
                  <?php else: ?>
                    <span class="badge"><?= e(t($jc['priority'], $jc['priority'])) ?></span>
                  <?php endif; ?>
                </td>
                <td><?= badge($jc['status']) ?></td>
                <td><?= e($jc['mechanic_name'] ? $jc['mechanic_name'] : t('Unassigned', 'Unassigned')) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="empty"><?= e(t('No active repair job cards at this time.', 'No active repair job cards at this time.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Recently Registered Customers & Fast Vehicle Intake -->
<div class="card reveal-up card-interactive">
  <div class="card-head">
    <div>
      <h3><?= e(t('Recent Client Registrations', 'Recent Client Registrations')) ?></h3>
      <span class="muted small"><?= e(t('Newly enrolled customer profiles', 'Newly enrolled customer profiles')) ?></span>
    </div>
    <a class="btn btn-ghost btn-sm" href="<?= e(url('customers/')) ?>"><?= e(t('Customer directory', 'Customer directory')) ?></a>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th><?= e(t('Customer Name', 'Customer Name')) ?></th>
          <th><?= e(t('Contact Phone', 'Contact Phone')) ?></th>
          <th><?= e(t('Email Address', 'Email Address')) ?></th>
          <th><?= e(t('Vehicles', 'Vehicles')) ?></th>
          <th><?= e(t('Registered Date', 'Registered Date')) ?></th>
          <th><?= e(t('Quick Actions', 'Quick Actions')) ?></th>
        </tr>
      </thead>
      <tbody>
        <?php if ($recentCustomers): ?>
          <?php foreach ($recentCustomers as $c): ?>
            <tr>
              <td><a href="<?= e(url('customers/view.php?id=' . $c['id'])) ?>"><strong><?= e($c['full_name']) ?></strong></a></td>
              <td><?= e($c['phone']) ?></td>
              <td><?= e($c['email'] ?: '—') ?></td>
              <td><span class="badge"><?= (int)$c['vehicle_count'] ?> <?= e(t($c['vehicle_count'] == 1 ? 'vehicle' : 'vehicles', $c['vehicle_count'] == 1 ? 'vehicle' : 'vehicles')) ?></span></td>
              <td class="nowrap"><?= e(fmt_date($c['registered_at'])) ?></td>
              <td>
                <div class="btn-group">
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('vehicles/form.php?customer_id=' . $c['id'])) ?>"><?= e(t('+ Vehicle', '+ Vehicle')) ?></a>
                  <a class="btn btn-ghost btn-sm" href="<?= e(url('job-cards/form.php?customer_id=' . $c['id'])) ?>"><?= e(t('+ Job', '+ Job')) ?></a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr><td colspan="6" class="empty"><?= e(t('No customer records found.', 'No customer records found.')) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

</div><!-- /.dashboard-data -->

<?php ui_footer();
