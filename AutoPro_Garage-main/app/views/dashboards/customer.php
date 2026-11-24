<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER PORTAL DASHBOARD
 * Exclusive client portal for reviewing registered vehicles, service bookings,
 * active repair status, and billing history.
 * Strictly scoped to the authenticated customer account (complete IDOR isolation).
 */

require_once APP_ROOT . '/app/views/layout.php';

$pdo = Database::pdo();
$garageName = Setting::get('garage_name', 'Alpha Garage');
$currency = Setting::get('currency', 'ETB');

// Resolve customer profile via CustomerIdentityService (strictly enforces verified user_id linkage)
$customer = CustomerIdentityService::resolveCustomer($user);
$customerId = (int) $customer['id'];
$linkedCustomerIds = CustomerIdentityService::getLinkedCustomerIds((int) $user['id']);
if (!in_array($customerId, $linkedCustomerIds, true)) {
    $linkedCustomerIds[] = $customerId;
}
$inClause = implode(',', array_map('intval', $linkedCustomerIds));

// Fetch active services catalog for in-place booking modal
$allServices = Database::all('SELECT id, name, category, description, standard_price, estimated_hours FROM services WHERE is_active = 1 ORDER BY category ASC, name ASC');

// Handle in-place booking modal POST
if (is_post() && post('action') === 'book_service') {
    verify_csrf();
    $bookingCustId = (int) post('customer_id', (string) $customerId);
    if (!in_array($bookingCustId, $linkedCustomerIds, true)) {
        $bookingCustId = $customerId;
    }
    $vehicleMode = post('vehicle_mode', 'existing');
    $bookingVehId = 0;
    if ($vehicleMode === 'new') {
        $plate = strtoupper(trim(post('plate_number')));
        $make = trim(post('make'));
        $model = trim(post('model'));
        $year = (int) post('year', (string) date('Y'));
        if ($plate === '' || $make === '' || $model === '') {
            flash('danger', t('Please provide all vehicle details (plate, make, model).', 'Please provide all vehicle details (plate, make, model).'));
            redirect('customer.php#book-service');
        }
        $exVeh = Database::one('SELECT id, customer_id FROM vehicles WHERE plate_number = ?', [$plate]);
        if ($exVeh) {
            if (!in_array((int)$exVeh['customer_id'], $linkedCustomerIds, true)) {
                flash('danger', t('A vehicle with this plate number is already registered under another account.', 'A vehicle with this plate number is already registered under another account.'));
                redirect('customer.php#book-service');
            }
            $bookingVehId = (int)$exVeh['id'];
        } else {
            Database::run(
                'INSERT INTO vehicles (customer_id, plate_number, make, model, year, is_active) VALUES (?, ?, ?, ?, ?, 1)',
                [$bookingCustId, $plate, $make, $model, $year ?: null]
            );
            $bookingVehId = (int) Database::pdo()->lastInsertId();
        }
    } else {
        $bookingVehId = (int) post('vehicle_id');
        if ($bookingVehId <= 0) {
            flash('danger', t('Please select or register a vehicle for the appointment.', 'Please select or register a vehicle for the appointment.'));
            redirect('customer.php#book-service');
        }
        $vehCheck = Database::one('SELECT id, customer_id FROM vehicles WHERE id = ? AND is_active = 1', [$bookingVehId]);
        if (!$vehCheck || !in_array((int)$vehCheck['customer_id'], $linkedCustomerIds, true)) {
            flash('danger', t('Selected vehicle does not belong to your account.', 'Selected vehicle does not belong to your account.'));
            redirect('customer.php#book-service');
        }
        $bookingCustId = (int)$vehCheck['customer_id'];
    }

    $apptDate = trim(post('appointment_date'));
    $apptTime = trim(post('appointment_time'));
    $serviceId = (int) post('service_id') ?: null;
    $notes = trim(post('notes'));

    if ($apptDate === '' || $apptTime === '') {
        flash('danger', t('Please select a preferred date and time.', 'Please select a preferred date and time.'));
        redirect('customer.php#book-service');
    }

    if ($apptDate < date('Y-m-d')) {
        flash('danger', t('Appointments must be scheduled for today or a future date.', 'Appointments must be scheduled for today or a future date.'));
        redirect('customer.php#book-service');
    }

    try {
        $newApptId = AppointmentService::create([
            'customer_id'         => $bookingCustId,
            'vehicle_id'          => $bookingVehId,
            'service_id'          => $serviceId,
            'appointment_date'    => $apptDate,
            'appointment_time'    => $apptTime,
            'problem_description' => $notes !== '' ? $notes : null,
            'notes'               => $notes !== '' ? $notes : null,
            'status'              => 'confirmed',
        ]);
        flash('success', t('Your service appointment has been booked successfully! Our workshop reception team has been notified.', 'Your service appointment has been booked successfully! Our workshop reception team has been notified.'));
        redirect('customer.php#appointments');
    } catch (Throwable $e) {
        flash('danger', t($e->getMessage(), $e->getMessage()));
        redirect('customer.php#book-service');
    }
}

// 1. Customer Scope Metrics
$vehicleCount = (int) Database::value(
    "SELECT COUNT(*) FROM vehicles WHERE customer_id IN ($inClause) AND is_active = 1"
);

$upcomingAppts = (int) Database::value(
    "SELECT COUNT(*) FROM appointments WHERE customer_id IN ($inClause) AND appointment_date >= CURDATE() AND status NOT IN ('completed','cancelled')"
);

$activeRepairs = (int) Database::value(
    "SELECT COUNT(*) FROM job_cards WHERE customer_id IN ($inClause) AND status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')"
);

$outstandingDue = (float) Database::value(
    "SELECT COALESCE(SUM(balance), 0) FROM invoices WHERE customer_id IN ($inClause) AND payment_status IN ('Unpaid','Partially Paid')"
);

// 2. Customer Registered Vehicles
$myVehicles = Database::all(
    "SELECT * FROM vehicles WHERE customer_id IN ($inClause) AND is_active = 1 ORDER BY id DESC"
);

// 3. Customer Appointments
$myAppointments = Database::all(
    "SELECT a.*, v.plate_number, v.make, v.model, s.name AS service_name
     FROM appointments a
     JOIN vehicles v ON v.id = a.vehicle_id
     LEFT JOIN services s ON s.id = a.service_id
     WHERE a.customer_id IN ($inClause)
     ORDER BY a.appointment_date DESC, a.appointment_time DESC
     LIMIT 8"
);

// 4. Live Repair Status (Customer's Job Cards)
$myJobCards = Database::all(
    "SELECT j.id, j.job_number, j.date_received, j.status, j.priority, j.reported_problem, j.diagnosis,
            v.plate_number, v.make, v.model
     FROM job_cards j
     JOIN vehicles v ON v.id = j.vehicle_id
     WHERE j.customer_id IN ($inClause)
     ORDER BY j.date_received DESC, j.id DESC
     LIMIT 8"
);

// 5. Customer Invoices
$myInvoices = Database::all(
    "SELECT i.*, v.plate_number, v.make, v.model
     FROM invoices i
     JOIN vehicles v ON v.id = i.vehicle_id
     WHERE i.customer_id IN ($inClause)
     ORDER BY i.issue_date DESC, i.id DESC
     LIMIT 8"
);

// 5b. Customer Payment Submissions
$mySubmissions = Database::all(
    "SELECT ps.*, pm.name AS method_name, i.invoice_number
     FROM payment_submissions ps
     JOIN payment_methods pm ON pm.id = ps.payment_method_id
     JOIN invoices i ON i.id = ps.invoice_id
     WHERE ps.customer_id IN ($inClause)
     ORDER BY ps.id DESC
     LIMIT 8"
);

// 6. Chart Metrics: Customer Repair Stages (Strictly scoped to customer_id IN (...))
$custJobRows = Database::all(
    "SELECT status, COUNT(*) AS count_val
     FROM job_cards
     WHERE customer_id IN ($inClause)
     GROUP BY status"
);

// 7. Chart Metrics: Past 6 months service visit frequency (appointments & completed repairs)
$custMonths = [];
for ($i = 5; $i >= 0; $i--) {
    $mKey = date('Y-m', strtotime("-$i months"));
    $mLabel = date('M Y', strtotime("-$i months"));
    $custMonths[$mKey] = ['label' => $mLabel, 'count' => 0];
}

// Count appointments per month
$custApptTrend = Database::all(
    "SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS ym, COUNT(*) AS count_val
     FROM appointments
     WHERE customer_id IN ($inClause)
       AND appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym"
);
foreach ($custApptTrend as $cat) {
    if (isset($custMonths[$cat['ym']])) {
        $custMonths[$cat['ym']]['count'] += (int)$cat['count_val'];
    }
}

// Count completed repairs per month
$custJobTrend = Database::all(
    "SELECT DATE_FORMAT(COALESCE(actual_completion, date_received), '%Y-%m') AS ym, COUNT(*) AS count_val
     FROM job_cards
     WHERE customer_id IN ($inClause)
       AND COALESCE(actual_completion, date_received) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY ym"
);
foreach ($custJobTrend as $cjt) {
    if (isset($custMonths[$cjt['ym']])) {
        $custMonths[$cjt['ym']]['count'] += (int)$cjt['count_val'];
    }
}

$hasCustomerHistory = !empty($custJobRows) || array_sum(array_column($custMonths, 'count')) > 0;

$customerChartPayload = [
    'jobStatus' => [
        'labels' => !empty($custJobRows) ? array_values(array_column($custJobRows, 'status')) : [],
        'data' => !empty($custJobRows) ? array_values(array_map('intval', array_column($custJobRows, 'count_val'))) : [],
    ],
    'history' => [
        'labels' => $hasCustomerHistory ? array_values(array_column(array_values($custMonths), 'label')) : [],
        'data' => $hasCustomerHistory ? array_values(array_map('intval', array_column(array_values($custMonths), 'count'))) : [],
    ],
];

ui_header(t('Customer Portal', 'Customer Portal'), 'dashboard');
?>

<div class="hero booking-portal-header reveal-up">
  <div>
    <h2><?= e(t('Welcome back', 'Welcome back')) ?>, <?= e(explode(' ', $customer['full_name'])[0]) ?></h2>
    <p><?= e($garageName) ?> · <?= e(t('VIP Client Services', 'VIP Client Services')) ?> · <?= e(t('Account', 'Account')) ?>: <code><?= e($user['username']) ?></code></p>
  </div>
  <div class="page-actions">
    <a class="btn btn-back-home btn-interactive" href="<?= e(url('index.php')) ?>" title="<?= e(t('Back to Home', 'Back to Home')) ?>">
      <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" style="vertical-align:text-bottom;margin-right:4px;"><path fill="currentColor" d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
      <?= e(t('Back to Home', 'Back to Home')) ?>
    </a>
    <button class="btn btn-ghost theme-toggle dashboard-theme-toggle btn-interactive" type="button" aria-label="Switch theme" title="Switch theme"><span aria-hidden="true">◐</span><span class="theme-toggle-label"><?= e(t('Theme', 'Theme')) ?></span></button>
    <a class="btn btn-primary btn-interactive btn-shimmer" href="<?= e(url('customer.php#book-service')) ?>" data-open-booking><?= e(t('+ Book New Service', '+ Book New Service')) ?></a>
    <a class="btn btn-ghost btn-interactive" href="<?= e(url('verify-customer.php')) ?>"><?= e(t('Link Past Records', 'Link Past Records')) ?></a>
  </div>
</div>

<div class="dashboard-data" id="dashboard-data" data-chart-payload="<?= e(json_encode($customerChartPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)) ?>">

<?php foreach (pull_flash() as $fl): ?>
  <div class="alert alert-<?= $fl['type'] === 'success' ? 'success' : 'danger' ?> reveal-fade mb-4" role="alert">
    <?= e(t($fl['message'], $fl['message'])) ?>
  </div>
<?php endforeach; ?>

<!-- In-Person Service History Linking Section (Neutral & Non-Disclosing — SEC-110D-04) -->
<div class="card record-link-card reveal-up mb-4">
  <div>
    <strong class="record-link-title">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z"/></svg>
      <?= e(t('In-Person Service Records:', 'In-Person Service Records:')) ?>
    </strong>
    <span class="muted small" style="display:block; margin-top: 4px;">
      <?= e(t('Previously visited Alpha Garage in person? Request a secure link to connect past service records and vehicles matching your verified email.', 'Previously visited Alpha Garage in person? Request a secure link to connect past service records and vehicles matching your verified email.')) ?>
    </span>
  </div>
  <form method="post" action="<?= e(url('verify-customer.php')) ?>" style="margin: 0;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="request_link">
    <button class="btn btn-primary btn-sm btn-interactive" type="submit" style="white-space: nowrap;"><?= e(t('Check & Link Records', 'Check & Link Records')) ?></button>
  </form>
</div>

<!-- Customer Portal KPI Grid -->
<div class="dashboard-section-head reveal-up">
  <div>
    <span class="dashboard-eyebrow"><?= e(t('ACCOUNT OVERVIEW', 'ACCOUNT OVERVIEW')) ?></span>
    <h3><?= e(t('Live fleet & service status', 'Live fleet & service status')) ?></h3>
  </div>
  <span class="muted small"><?= e(t('Real-time overview of your registered vehicles and workshop activity', 'Real-time overview of your registered vehicles and workshop activity')) ?></span>
</div>

<div class="kpi-grid">
  <div class="kpi-card tone-blue card-interactive reveal-up stagger-1">
    <div class="kpi-label"><?= e(t('Registered Vehicles', 'Registered Vehicles')) ?></div>
    <div class="kpi-value"><?= $vehicleCount ?></div>
    <div class="kpi-sub"><?= e(t('Vehicles in your garage fleet', 'Vehicles in your garage fleet')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Active vehicles', 'Active vehicles')) ?></div>
  </div>

  <div class="kpi-card tone-gold card-interactive reveal-up stagger-2">
    <div class="kpi-label"><?= e(t('Upcoming Appointments', 'Upcoming Appointments')) ?></div>
    <div class="kpi-value"><?= $upcomingAppts ?></div>
    <div class="kpi-sub"><?= e(t('Scheduled workshop visits', 'Scheduled workshop visits')) ?></div>
    <div class="kpi-cmp trend-flat"><?= e(t('Confirmed / Scheduled', 'Confirmed / Scheduled')) ?></div>
  </div>

  <div class="kpi-card <?= $activeRepairs > 0 ? 'tone-amber' : 'tone-green' ?> card-interactive reveal-up stagger-3">
    <div class="kpi-label"><?= e(t('Active Repairs', 'Active Repairs')) ?></div>
    <div class="kpi-value"><?= $activeRepairs ?></div>
    <div class="kpi-sub"><?= e($activeRepairs > 0 ? t('Vehicles currently in workshop', 'Vehicles currently in workshop') : t('All repairs complete', 'All repairs complete')) ?></div>
    <div class="kpi-cmp <?= $activeRepairs > 0 ? 'trend-down' : 'trend-up' ?>"><?= e($activeRepairs > 0 ? t('Under repair', 'Under repair') : t('Ready', 'Ready')) ?></div>
  </div>

  <div class="kpi-card <?= $outstandingDue > 0 ? 'tone-amber' : 'tone-green' ?> card-interactive reveal-up stagger-4">
    <div class="kpi-label"><?= e(t('Outstanding Balance', 'Outstanding Balance')) ?></div>
    <div class="kpi-value"><?= e($currency . ' ' . number_format($outstandingDue, 2)) ?></div>
    <div class="kpi-sub"><?= e($outstandingDue > 0 ? t('Pending invoice payments', 'Pending invoice payments') : t('All accounts settled', 'All accounts settled')) ?></div>
    <div class="kpi-cmp <?= $outstandingDue > 0 ? 'trend-down' : 'trend-up' ?>"><?= e($outstandingDue > 0 ? t('Payment due', 'Payment due') : t('Settled', 'Settled')) ?></div>
  </div>
</div>

<div class="dashboard-section-head reveal-up">
  <div><span class="dashboard-eyebrow"><?= e(t('SERVICE ANALYTICS', 'SERVICE ANALYTICS')) ?></span><h3><?= e(t('Your vehicle service history', 'Your vehicle service history')) ?></h3></div>
  <span class="muted small"><?= e(t('Repair stages and 6-month scheduled maintenance timeline', 'Repair stages and 6-month scheduled maintenance timeline')) ?></span>
</div>

<?php if ($hasCustomerHistory): ?>
  <div class="chart-grid mb-4">
    <div class="card chart-card card-interactive reveal-up stagger-1">
      <div class="card-head">
        <h3><?= e(t('Repair Order Status', 'Repair Order Status')) ?></h3>
        <span class="muted small"><?= e(t('Distribution across repair stages', 'Distribution across repair stages')) ?></span>
      </div>
      <div class="chart-wrap">
        <canvas id="chart-customer-repair-status" aria-label="Customer repair status doughnut chart" role="img"></canvas>
      </div>
    </div>

    <div class="card chart-card card-interactive reveal-up stagger-2">
      <div class="card-head">
        <h3><?= e(t('6-Month Service Activity', '6-Month Service Activity')) ?></h3>
        <span class="muted small"><?= e(t('Monthly workshop checkups & repairs', 'Monthly workshop checkups & repairs')) ?></span>
      </div>
      <div class="chart-wrap">
        <canvas id="chart-customer-service-history" aria-label="Customer service activity bar chart" role="img"></canvas>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card chart-card card-interactive reveal-up mb-4" style="text-align: center; padding: 2.5rem 1.5rem;">
    <div style="font-size: 2.2rem; margin-bottom: 0.5rem;" aria-hidden="true">🚗</div>
    <h3 style="margin-bottom: 0.25rem;"><?= e(t('No Service History Yet', 'No Service History Yet')) ?></h3>
    <p class="text-muted" style="max-width: 480px; margin: 0 auto 1.25rem;">
      <?= e(t('Once your vehicle is serviced or diagnosed at Alpha Garage, your real-time repair progress and maintenance history charts will appear here.', 'Once your vehicle is serviced or diagnosed at Alpha Garage, your real-time repair progress and maintenance history charts will appear here.')) ?>
    </p>
    <div><a href="<?= e(url('customer.php#book-service')) ?>" class="btn btn-primary btn-sm btn-interactive" data-open-booking><?= e(t('Schedule First Service', 'Schedule First Service')) ?></a></div>
  </div>
<?php endif; ?>

<div class="grid grid-2 mb-4">
  <!-- Registered Vehicles Fleet -->
  <div class="card card-interactive reveal-up" id="vehicles">
    <div class="card-head">
      <div>
        <h3><?= e(t('My Vehicles', 'My Vehicles')) ?></h3>
        <span class="muted small"><?= e(t('Vehicles registered for maintenance', 'Vehicles registered for maintenance')) ?></span>
      </div>
      <a class="btn btn-ghost btn-sm btn-interactive" href="<?= e(url('customer.php#book-service')) ?>" data-open-booking><?= e(t('+ Book service', '+ Book service')) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Plate No.', 'Plate No.')) ?></th>
            <th><?= e(t('Make & Model', 'Make & Model')) ?></th>
            <th><?= e(t('Year', 'Year')) ?></th>
            <th><?= e(t('Fuel', 'Fuel')) ?></th>
            <th><?= e(t('Action', 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($myVehicles): ?>
            <?php foreach ($myVehicles as $veh): ?>
              <tr>
                <td><strong><?= e($veh['plate_number']) ?></strong></td>
                <td><?= e($veh['make'] . ' ' . $veh['model']) ?></td>
                <td><?= e((string)($veh['year'] ?: '—')) ?></td>
                <td><span class="badge"><?= e(t($veh['fuel_type'] ?: 'Petrol', $veh['fuel_type'] ?: 'Petrol')) ?></span></td>
                <td>
                  <a class="btn btn-primary btn-sm btn-interactive" href="<?= e(url('book.php?vehicle_id=' . $veh['id'])) ?>" data-open-booking data-vehicle-id="<?= (int)$veh['id'] ?>"><?= e(t('Book visit', 'Book visit')) ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="empty"><?= e(t('No vehicles registered yet.', 'No vehicles registered yet.')) ?> <a href="<?= e(url('book.php')) ?>"><?= e(t('Register a vehicle with your first booking', 'Register a vehicle with your first booking')) ?></a>.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Upcoming & Recent Appointments -->
  <div class="card card-interactive reveal-up" id="appointments">
    <div class="card-head">
      <div>
        <h3><?= e(t('Service Appointments', 'Service Appointments')) ?></h3>
        <span class="muted small"><?= e(t('Scheduled checkups and workshop bookings', 'Scheduled checkups and workshop bookings')) ?></span>
      </div>
      <a class="btn btn-ghost btn-sm btn-interactive" href="<?= e(url('customer.php#book-service')) ?>" data-open-booking><?= e(t('Book visit', 'Book visit')) ?></a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Booking #', 'Booking #')) ?></th>
            <th><?= e(t('Date & Time', 'Date & Time')) ?></th>
            <th><?= e(t('Vehicle', 'Vehicle')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th><?= e(t('Action', 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($myAppointments): ?>
            <?php foreach ($myAppointments as $apt): ?>
              <tr>
                <td class="nowrap"><strong><?= e($apt['appointment_number']) ?></strong></td>
                <td class="nowrap">
                  <?= e(fmt_date($apt['appointment_date'])) ?>
                  <div class="muted small"><?= e(substr($apt['appointment_time'], 0, 5)) ?></div>
                </td>
                <td>
                  <?= e($apt['plate_number']) ?>
                  <div class="muted small"><?= e(t($apt['service_name'] ?: 'General Service', $apt['service_name'] ?: 'General Service')) ?></div>
                </td>
                <td><?= badge($apt['status']) ?></td>
                <td>
                  <a class="btn btn-ghost btn-sm btn-interactive" href="<?= e(url('book.php?view=' . $apt['id'])) ?>"><?= e(t('View', 'View')) ?></a>
                  <?php if (in_array($apt['status'], ['scheduled', 'confirmed'], true) && empty($apt['converted_job_id'])): ?>
                    <form method="post" action="<?= e(url('book.php')) ?>" class="inline-form" style="display:inline-block;" onsubmit="return confirm('<?= e(t('Are you sure you want to cancel this appointment?', 'Are you sure you want to cancel this appointment?')) ?>');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="cancel_booking" value="1">
                      <input type="hidden" name="appointment_id" value="<?= (int) $apt['id'] ?>">
                      <button type="submit" class="btn btn-ghost btn-sm btn-interactive text-danger" title="<?= e(t('Cancel Booking', 'Cancel Booking')) ?>"><?= e(t('Cancel', 'Cancel')) ?></button>
                    </form>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="5" class="empty"><?= e(t('No service appointments on record.', 'No service appointments on record.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="grid grid-2 mb-4">
  <!-- Active & Recent Repair Orders -->
  <div class="card card-interactive reveal-up" id="repairs">
    <div class="card-head">
      <div>
        <h3><?= e(t('Workshop Repair Status', 'Workshop Repair Status')) ?></h3>
        <span class="muted small"><?= e(t('Live progress of your vehicle service orders', 'Live progress of your vehicle service orders')) ?></span>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Job Card', 'Job Card')) ?></th>
            <th><?= e(t('Vehicle', 'Vehicle')) ?></th>
            <th><?= e(t('Reported Issue', 'Reported Issue')) ?></th>
            <th><?= e(t('Progress Stage', 'Progress Stage')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($myJobCards): ?>
            <?php foreach ($myJobCards as $jc): ?>
              <tr>
                <td class="nowrap">
                  <strong><?= e($jc['job_number']) ?></strong>
                  <div class="muted small"><?= e(fmt_date($jc['date_received'])) ?></div>
                </td>
                <td>
                  <strong><?= e($jc['plate_number']) ?></strong>
                  <div class="muted small"><?= e($jc['make'] . ' ' . $jc['model']) ?></div>
                </td>
                <td><?= e(mb_strimwidth($jc['reported_problem'], 0, 45, '…')) ?></td>
                <td><?= badge($jc['status']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="4" class="empty"><?= e(t('No vehicle repair job cards found for your account.', 'No vehicle repair job cards found for your account.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Customer Billing & Invoices -->
  <div class="card card-interactive reveal-up" id="invoices">
    <div class="card-head">
      <div>
        <h3><?= e(t('Billing & Invoices', 'Billing & Invoices')) ?></h3>
        <span class="muted small"><?= e(t('Service statements and settlement receipts', 'Service statements and settlement receipts')) ?></span>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Invoice No.', 'Invoice No.')) ?></th>
            <th><?= e(t('Issue Date', 'Issue Date')) ?></th>
            <th><?= e(t('Total', 'Total')) ?></th>
            <th><?= e(t('Balance Due', 'Balance Due')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th style="text-align:right;"><?= e(t('Action', 'Action')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if ($myInvoices): ?>
            <?php foreach ($myInvoices as $inv): ?>
              <tr>
                <td class="nowrap"><strong><a href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>"><?= e($inv['invoice_number']) ?></a></strong></td>
                <td class="nowrap"><?= e(fmt_date($inv['issue_date'])) ?></td>
                <td><?= e($currency . ' ' . number_format((float)$inv['grand_total'], 2)) ?></td>
                <td>
                  <strong class="<?= (float)$inv['balance'] > 0 ? 'text-amber' : 'text-green' ?>">
                    <?= e($currency . ' ' . number_format((float)$inv['balance'], 2)) ?>
                  </strong>
                </td>
                <td><?= badge($inv['payment_status']) ?></td>
                <td style="text-align:right;">
                  <?php if ((float)$inv['balance'] > 0 && $inv['payment_status'] !== 'Refunded'): ?>
                    <a href="<?= e(url('payments/pay.php?invoice_id=' . $inv['id'])) ?>" class="btn btn-outline btn-sm"
                       style="border-color:var(--gold,#D4AF37);color:var(--gold,#D4AF37);font-weight:600;padding:3px 10px;">
                      💳 <?= e(t('Pay Invoice', 'Pay Invoice')) ?>
                    </a>
                  <?php else: ?>
                    <a href="<?= e(url('invoices/view.php?id=' . $inv['id'])) ?>" class="btn btn-ghost btn-sm">
                      <?= e(t('View', 'View')) ?>
                    </a>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" class="empty"><?= e(t('No invoices issued for your account yet.', 'No invoices issued for your account yet.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Customer Payment Submissions & Live Status Tracking -->
  <div class="card card-interactive reveal-up" id="payment_submissions" style="margin-top:20px;">
    <div class="card-head">
      <div>
        <h3><?= e(t('Payment Verification Tracking', 'Payment Verification Tracking')) ?></h3>
        <span class="muted small"><?= e(t('Track manual bank transfers, Telebirr submissions, and staff verification status', 'Track manual bank transfers, Telebirr submissions, and staff verification status')) ?></span>
      </div>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th><?= e(t('Submission #', 'Submission #')) ?></th>
            <th><?= e(t('Invoice', 'Invoice')) ?></th>
            <th><?= e(t('Provider', 'Provider')) ?></th>
            <th><?= e(t('Reference', 'Reference')) ?></th>
            <th><?= e(t('Amount', 'Amount')) ?></th>
            <th><?= e(t('Status', 'Status')) ?></th>
            <th style="text-align:right;"><?= e(t('Details', 'Details')) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php if (!empty($mySubmissions)): ?>
            <?php foreach ($mySubmissions as $sub): ?>
              <tr>
                <td class="nowrap mono"><strong><?= e($sub['submission_number']) ?></strong></td>
                <td><a href="<?= e(url('invoices/view.php?id=' . $sub['invoice_id'])) ?>"><strong><?= e($sub['invoice_number']) ?></strong></a></td>
                <td><?= e($sub['method_name']) ?></td>
                <td class="mono small"><?= e($sub['transaction_reference']) ?></td>
                <td><strong><?= e($currency . ' ' . number_format((float)$sub['amount'], 2)) ?></strong></td>
                <td>
                  <?php
                  $sSt = $sub['status'];
                  if ($sSt === 'pending_verification') {
                      echo '<span class="badge badge-pending">' . e(t('Pending Verification', 'Pending Verification')) . '</span>';
                  } elseif ($sSt === 'under_review') {
                      echo '<span class="badge" style="background:rgba(59,130,246,0.15);color:#3B82F6;border:1px solid rgba(59,130,246,0.3);">' . e(t('Under Review', 'Under Review')) . '</span>';
                  } elseif ($sSt === 'verified') {
                      echo '<span class="badge badge-paid">' . e(t('Verified', 'Verified')) . '</span>';
                  } else {
                      echo '<span class="badge badge-rejected">' . e(t('Rejected', 'Rejected')) . '</span>';
                      if (!empty($sub['rejection_reason'])) {
                          echo '<div class="muted small text-danger" style="margin-top:2px;">' . e($sub['rejection_reason']) . '</div>';
                      }
                  }
                  ?>
                </td>
                <td style="text-align:right;">
                  <?php if ($sSt === 'rejected'): ?>
                    <a href="<?= e(url('payments/pay.php?invoice_id=' . $sub['invoice_id'])) ?>" class="btn btn-outline btn-sm"
                       style="border-color:var(--gold,#D4AF37);color:var(--gold,#D4AF37);padding:2px 8px;font-size:0.8rem;">
                      <?= e(t('Resubmit', 'Resubmit')) ?>
                    </a>
                  <?php elseif (!empty($sub['proof_file_path'])): ?>
                    <a href="<?= e(url('payments/proof.php?id=' . $sub['id'])) ?>" target="_blank" class="btn btn-ghost btn-sm"
                       style="padding:2px 8px;font-size:0.8rem;">
                      <?= e(t('Proof', 'Proof')) ?>
                    </a>
                  <?php else: ?>
                    <span class="muted small">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="7" class="empty"><?= e(t('No payment submissions recorded yet.', 'No payment submissions recorded yet.')) ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /.dashboard-data -->

<!-- Luxury In-Place Booking Modal -->
<div class="booking-modal-backdrop" id="bookingModalBackdrop" <?= (!empty($_GET['open_booking'])) ? '' : 'hidden' ?>>
  <div class="booking-modal-dialog" id="bookingModalDialog" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
    <div class="booking-modal-header">
      <div>
        <h3 class="booking-modal-title" id="bookingModalTitle"><?= e(t('Book Premium Service Visit', 'Book Premium Service Visit')) ?></h3>
        <p class="booking-modal-sub"><?= e(t('Schedule certified technician inspection & maintenance', 'Schedule certified technician inspection & maintenance')) ?></p>
      </div>
      <button type="button" class="booking-modal-close" id="bookingModalClose" aria-label="<?= e(t('Close', 'Close')) ?>">&times;</button>
    </div>
    <form method="post" action="<?= e(url('customer.php')) ?>" id="inplaceBookingForm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="book_service">
      <input type="hidden" name="customer_id" value="<?= $customerId ?>">
      <input type="hidden" name="vehicle_mode" id="modalVehicleMode" value="<?= !empty($myVehicles) ? 'existing' : 'new' ?>">

      <!-- Section 1: Vehicle Information -->
      <div class="booking-section-header">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>
        <span><?= e(t('Vehicle Information', 'Vehicle Information')) ?></span>
      </div>

      <?php if (!empty($myVehicles)): ?>
      <div class="vehicle-toggle-bar">
        <button type="button" class="vehicle-toggle-btn active" id="btnToggleExisting">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l5.59-5.59L19 10l-7 7z"/></svg>
          <?= e(t('My Registered Vehicle', 'My Registered Vehicle')) ?>
        </button>
        <button type="button" class="vehicle-toggle-btn" id="btnToggleNew">
          <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
          <?= e(t('+ Add New Vehicle', '+ Add New Vehicle')) ?>
        </button>
      </div>

      <div class="booking-field-group" id="groupExistingVehicle">
        <label class="booking-field-label" for="modalVehicleId"><?= e(t('Select Your Vehicle', 'Select Your Vehicle')) ?> <span class="req">*</span></label>
        <select class="input" id="modalVehicleId" name="vehicle_id">
          <?php foreach ($myVehicles as $v): ?>
            <option value="<?= (int)$v['id'] ?>"><?= e($v['plate_number'] . ' — ' . $v['make'] . ' ' . $v['model'] . ' (' . ($v['year'] ?: 'N/A') . ')') ?></option>
          <?php endforeach; ?>
        </select>
        <span class="booking-field-hint"><?= e(t('Choose from vehicles linked to your VIP client profile.', 'Choose from vehicles linked to your VIP client profile.')) ?></span>
      </div>
      <?php endif; ?>

      <div class="booking-field-group" id="groupNewVehicle" <?= !empty($myVehicles) ? 'hidden style="display:none;"' : '' ?>>
        <div class="form-row">
          <div class="booking-field-group">
            <label class="booking-field-label" for="modalPlateNumber"><?= e(t('License Plate', 'License Plate')) ?> <span class="req">*</span></label>
            <input class="input" type="text" id="modalPlateNumber" name="plate_number" placeholder="e.g. AA 2 B 12345" style="text-transform:uppercase;">
          </div>
          <div class="booking-field-group">
            <label class="booking-field-label" for="modalMake"><?= e(t('Make', 'Make')) ?> <span class="req">*</span></label>
            <input class="input" type="text" id="modalMake" name="make" placeholder="e.g. Toyota, Mercedes-Benz">
          </div>
        </div>
        <div class="form-row">
          <div class="booking-field-group">
            <label class="booking-field-label" for="modalModel"><?= e(t('Model', 'Model')) ?> <span class="req">*</span></label>
            <input class="input" type="text" id="modalModel" name="model" placeholder="e.g. Land Cruiser, C-Class">
          </div>
          <div class="booking-field-group">
            <label class="booking-field-label" for="modalYear"><?= e(t('Year', 'Year')) ?></label>
            <input class="input" type="number" id="modalYear" name="year" min="1980" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>">
          </div>
        </div>
      </div>

      <!-- Section 2: Appointment & Service Details -->
      <div class="booking-section-header">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
        <span><?= e(t('Appointment Information', 'Appointment Information')) ?></span>
      </div>

      <div class="booking-field-group">
        <label class="booking-field-label" for="modalServiceId"><?= e(t('Requested Service Package', 'Requested Service Package')) ?></label>
        <select class="input" id="modalServiceId" name="service_id">
          <option value=""><?= e(t('-- General Inspection & Diagnosis --', '-- General Inspection & Diagnosis --')) ?></option>
          <?php foreach ($allServices as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name'] . ' (' . $currency . ' ' . number_format((float)$s['standard_price'], 2) . ')') ?></option>
          <?php endforeach; ?>
        </select>
        <span class="booking-field-hint"><?= e(t('Select a standard maintenance package or request general technician diagnosis.', 'Select a standard maintenance package or request general technician diagnosis.')) ?></span>
      </div>

      <div class="form-row">
        <div class="booking-field-group">
          <label class="booking-field-label" for="modalApptDate"><?= e(t('Preferred Date', 'Preferred Date')) ?> <span class="req">*</span></label>
          <input class="input" type="date" id="modalApptDate" name="appointment_date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
        </div>
        <div class="booking-field-group">
          <label class="booking-field-label" for="modalApptTime"><?= e(t('Preferred Time', 'Preferred Time')) ?> <span class="req">*</span></label>
          <select class="input" id="modalApptTime" name="appointment_time" required>
            <option value="08:30:00">08:30 AM (Morning Slot)</option>
            <option value="10:00:00">10:00 AM (Morning Slot)</option>
            <option value="11:30:00">11:30 AM (Morning Slot)</option>
            <option value="14:00:00" selected>02:00 PM (Afternoon Slot)</option>
            <option value="15:30:00">03:30 PM (Afternoon Slot)</option>
            <option value="17:00:00">05:00 PM (Late Slot)</option>
          </select>
        </div>
      </div>

      <!-- Section 3: Issue Description & Special Requests -->
      <div class="booking-section-header">
        <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
        <span><?= e(t('Additional Information', 'Additional Information')) ?></span>
      </div>

      <div class="booking-field-group">
        <label class="booking-field-label" for="modalNotes"><?= e(t('Notes / Specific Concerns', 'Notes / Specific Concerns')) ?></label>
        <textarea class="input" id="modalNotes" name="notes" rows="4" placeholder="<?= e(t('Describe any symptoms, dashboard warning lights, or requested checks...', 'Describe any symptoms, dashboard warning lights, or requested checks...')) ?>"></textarea>
        <span class="booking-field-hint"><?= e(t('Help our certified technicians prepare needed tools and parts prior to arrival.', 'Help our certified technicians prepare needed tools and parts prior to arrival.')) ?></span>
      </div>

      <div class="booking-modal-actions">
        <button type="button" class="btn btn-ghost btn-interactive" id="btnCancelBooking"><?= e(t('Cancel', 'Cancel')) ?></button>
        <button type="submit" class="btn btn-primary btn-interactive btn-shimmer" id="btnSubmitBooking"><?= e(t('Confirm & Book Service', 'Confirm & Book Service')) ?></button>
      </div>
      <div style="margin-top: 1.25rem; text-align: center;">
        <a href="<?= e(url('book.php')) ?>" class="small muted" style="color: var(--gold-champagne, #D4AF37); text-decoration: underline; font-weight: 500;">
          <?= e(t('Or open full booking page', 'Or open full booking page')) ?> &rarr;
        </a>
      </div>
    </form>
  </div>
</div>

<!-- Includes ui_footer() rendering scroll-animations.js and dashboard.js -->
<?php ui_footer(); ?>
