<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — CUSTOMER SERVICE BOOKING PORTAL
 * 
 * Provides an authorized, customer-facing booking workflow that:
 * - Allows authenticated customers to book service appointments.
 * - Restricts vehicle and appointment selection strictly to the customer's own records (IDOR protection).
 * - Enforces CSRF token validation and rate-limiting.
 * - Prevents customer access to staff-only appointment management.
 */

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();

// Garage name for branding
$garageName = Setting::get('garage_name', 'Alpha Garage');

// Resolve the associated customer record
$customer = Database::one(
    'SELECT id, full_name, phone, email, is_active FROM customers WHERE (created_by = ? OR (email IS NOT NULL AND LOWER(email) = ?)) AND is_active = 1 ORDER BY id DESC LIMIT 1',
    [(int) $user['id'], strtolower((string) ($user['email'] ?? ''))]
);

if (!$customer) {
    // Automatically link or initialize customer record for this registered account
    $phone = !empty($user['phone']) ? $user['phone'] : '+251 90 000 0000';
    $custId = Database::insert(
        'INSERT INTO customers (full_name, phone, email, created_by) VALUES (?, ?, ?, ?)',
        [$user['full_name'], $phone, $user['email'], (int) $user['id']]
    );
    $customer = [
        'id' => $custId,
        'full_name' => $user['full_name'],
        'phone' => $phone,
        'email' => $user['email'],
        'is_active' => 1,
    ];
}

$customerId = (int) $customer['id'];

// =========================================================================
// 1. VIEW SPECIFIC APPOINTMENT (WITH STRICT IDOR AUTHORIZATION CHECK)
// =========================================================================
$viewId = get_int('view');
$viewAppointment = null;

if ($viewId > 0) {
    $viewAppointment = Database::one(
        'SELECT a.*, v.plate_number, v.make, v.model, s.name AS service_name, s.standard_price
         FROM appointments a
         JOIN vehicles v ON v.id = a.vehicle_id
         LEFT JOIN services s ON s.id = a.service_id
         WHERE a.id = ?',
        [$viewId]
    );

    if (!$viewAppointment) {
        abort(404, 'The requested appointment record was not found.');
    }

    // IDOR Protection: Customers may only view their own appointments
    if ((int) $viewAppointment['customer_id'] !== $customerId && !can('appointments.view')) {
        AuditLogger::logSafe('idor_appointment_view_blocked', 'appointments', $viewId, null, [
            'actor_user_id' => $user['id'],
            'actor_customer_id' => $customerId,
            'target_customer_id' => $viewAppointment['customer_id'],
        ], 'denied');
        abort(403, 'You do not have authorization to view this appointment record.');
    }
}

// =========================================================================
// 2. RETRIEVE CUSTOMER VEHICLES & SERVICES
// =========================================================================
$myVehicles = Database::all(
    'SELECT id, plate_number, make, model FROM vehicles WHERE customer_id = ? AND is_active = 1 ORDER BY plate_number ASC',
    [$customerId]
);

$services = Database::all(
    'SELECT id, code, name, category, standard_price FROM services WHERE is_active = 1 ORDER BY category ASC, name ASC'
);

$errors = [];
$v = [
    'vehicle_mode' => 'existing',
    'vehicle_id' => count($myVehicles) > 0 ? (string) $myVehicles[0]['id'] : '',
    'plate_number' => '',
    'make' => '',
    'model' => '',
    'service_id' => (string) (get_int('service_id') ?: ''),
    'appointment_date' => date('Y-m-d', strtotime('+1 day')),
    'appointment_time' => '09:00',
    'problem_description' => '',
];

// =========================================================================
// 3. PROCESS BOOKING SUBMISSION (POST)
// =========================================================================
if (is_post() && empty($_POST['cancel_booking'])) {
    $v['vehicle_mode'] = post('vehicle_mode') === 'new' ? 'new' : 'existing';
    $v['vehicle_id'] = post('vehicle_id');
    $v['plate_number'] = strtoupper(trim(post('plate_number')));
    $v['make'] = trim(post('make'));
    $v['model'] = trim(post('model'));
    $v['service_id'] = post('service_id');
    $v['appointment_date'] = post('appointment_date');
    $v['appointment_time'] = post('appointment_time');
    $v['problem_description'] = trim(post('problem_description'));

    // Validate date & time
    $today = date('Y-m-d');
    if (empty($v['appointment_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v['appointment_date'])) {
        $errors['appointment_date'] = 'Please select a valid booking date.';
    } elseif ($v['appointment_date'] < $today) {
        $errors['appointment_date'] = 'Appointments must be scheduled for today or a future date.';
    }

    if (empty($v['appointment_time'])) {
        $errors['appointment_time'] = 'Please select an appointment time slot.';
    }

    // Vehicle resolution and strict IDOR check
    $selectedVehicleId = null;

    if ($v['vehicle_mode'] === 'new' || count($myVehicles) === 0) {
        if (empty($v['plate_number'])) {
            $errors['plate_number'] = 'Vehicle license plate number is required.';
        }
        if (empty($v['make'])) {
            $errors['make'] = 'Vehicle make is required.';
        }
        if (empty($v['model'])) {
            $errors['model'] = 'Vehicle model is required.';
        }

        if (empty($errors['plate_number'])) {
            $existingVeh = Database::one('SELECT id, customer_id FROM vehicles WHERE plate_number = ?', [$v['plate_number']]);
            if ($existingVeh) {
                if ((int) $existingVeh['customer_id'] === $customerId) {
                    $selectedVehicleId = (int) $existingVeh['id'];
                } else {
                    $errors['plate_number'] = 'This license plate number is registered to another account.';
                }
            } else {
                $selectedVehicleId = (int) Database::insert(
                    'INSERT INTO vehicles (customer_id, plate_number, make, model) VALUES (?, ?, ?, ?)',
                    [$customerId, $v['plate_number'], $v['make'], $v['model']]
                );
            }
        }
    } else {
        $vehId = (int) $v['vehicle_id'];
        if ($vehId < 1) {
            $errors['vehicle_id'] = 'Please select a registered vehicle.';
        } else {
            // IDOR Protection: Confirm selected vehicle strictly belongs to current customer
            $vehCheck = Database::one('SELECT id, customer_id FROM vehicles WHERE id = ? AND is_active = 1', [$vehId]);
            if (!$vehCheck || (int) $vehCheck['customer_id'] !== $customerId) {
                AuditLogger::logSafe('idor_vehicle_booking_blocked', 'vehicles', $vehId, null, [
                    'actor_user_id' => $user['id'],
                    'actor_customer_id' => $customerId,
                    'target_vehicle_customer' => $vehCheck['customer_id'] ?? null,
                ], 'denied');
                abort(403, 'Security rejection: The selected vehicle does not belong to your account.');
            }
            $selectedVehicleId = $vehId;
        }
    }

    if (!$errors && $selectedVehicleId > 0) {
        try {
            $newAptId = AppointmentService::create([
                'customer_id' => $customerId,
                'vehicle_id' => $selectedVehicleId,
                'service_id' => !empty($v['service_id']) ? (int) $v['service_id'] : null,
                'appointment_date' => $v['appointment_date'],
                'appointment_time' => $v['appointment_time'],
                'problem_description' => $v['problem_description'],
            ]);

            flash('success', 'Your appointment has been booked successfully! Our workshop reception team has been notified.');
            redirect('book.php?view=' . $newAptId);
        } catch (DomainException $e) {
            $errors['general'] = $e->getMessage();
        }
    }
}

// Retrieve customer's active and previous appointments
$customerBookings = Database::all(
    'SELECT a.*, v.plate_number, v.make, v.model, s.name AS service_name
     FROM appointments a
     JOIN vehicles v ON v.id = a.vehicle_id
     LEFT JOIN services s ON s.id = a.service_id
     WHERE a.customer_id = ?
     ORDER BY a.appointment_date DESC, a.appointment_time DESC
     LIMIT 10',
    [$customerId]
);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book Service &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/luxury.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="luxury-portal-body">

<header class="public-nav-luxury">
  <div class="nav-container">
    <a href="<?= e(url('index.php')) ?>" class="nav-brand">
      <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36">
      <span><?= e($garageName) ?></span>
    </a>
    <nav class="nav-links">
      <a href="<?= e(url('index.php')) ?>">Home</a>
      <a href="<?= e(url('services.php')) ?>">Services</a>
      <a href="<?= e(url('contact.php')) ?>">Contact</a>
      <a href="<?= e(url('book.php')) ?>" class="active">Book Service</a>
      <a href="<?= e(url('profile.php')) ?>">Account (<?= e($user['username']) ?>)</a>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-luxury-text">Sign Out</button>
      </form>
    </nav>
  </div>
</header>

<main class="container py-4">
  <div class="booking-portal-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h1 class="h2 text-gold">Service Booking Portal</h1>
        <p class="text-muted">Schedule your vehicle maintenance or repair visit with our certified technicians.</p>
      </div>
      <?php if (can('appointments.manage')): ?>
        <a href="<?= e(url('appointments/')) ?>" class="btn btn-secondary btn-sm">Staff Workshop Board &rarr;</a>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach (pull_flash() as $fl): ?>
    <div class="luxury-alert luxury-alert-<?= $fl['type'] === 'success' ? 'success' : 'danger' ?> mb-4" role="alert">
      <?= e($fl['message']) ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($errors['general'])): ?>
    <div class="luxury-alert luxury-alert-danger mb-4" role="alert">
      <?= e($errors['general']) ?>
    </div>
  <?php endif; ?>

  <?php if ($viewAppointment): ?>
    <!-- Booking Confirmation Card -->
    <div class="card p-4 mb-4 border-gold shadow-sm">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 text-gold mb-0">Booking Details &middot; <?= e($viewAppointment['appointment_number']) ?></h2>
        <span class="badge badge-<?= $viewAppointment['status'] === 'confirmed' ? 'success' : ($viewAppointment['status'] === 'in_progress' ? 'info' : 'warning') ?>">
          <?= e(ucfirst($viewAppointment['status'])) ?>
        </span>
      </div>
      <div class="row">
        <div class="col-md-6 mb-2">
          <strong>Vehicle:</strong> <?= e($viewAppointment['make'] . ' ' . $viewAppointment['model']) ?> (<code><?= e($viewAppointment['plate_number']) ?></code>)
        </div>
        <div class="col-md-6 mb-2">
          <strong>Service:</strong> <?= e($viewAppointment['service_name'] ?: 'General Checkup') ?>
        </div>
        <div class="col-md-6 mb-2">
          <strong>Scheduled Date:</strong> <?= e($viewAppointment['appointment_date']) ?> at <?= e($viewAppointment['appointment_time']) ?>
        </div>
        <div class="col-md-6 mb-2">
          <strong>Customer Name:</strong> <?= e($customer['full_name']) ?>
        </div>
        <?php if (!empty($viewAppointment['problem_description'])): ?>
          <div class="col-12 mt-2">
            <strong>Notes / Description:</strong>
            <p class="mb-0 text-muted"><?= e($viewAppointment['problem_description']) ?></p>
          </div>
        <?php endif; ?>
      </div>
      <div class="mt-3">
        <a href="<?= e(url('book.php')) ?>" class="btn btn-primary btn-sm">&larr; Book Another Appointment</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- Booking Form Column -->
    <div class="col-lg-7">
      <div class="card p-4 shadow-sm mb-4">
        <h3 class="h4 mb-3 text-gold">Schedule Your Appointment</h3>
        
        <form method="post" autocomplete="off" novalidate>
          <?= csrf_field() ?>

          <!-- Vehicle Selection -->
          <div class="mb-3">
            <label class="form-label font-weight-bold">Select Vehicle <span class="text-danger">*</span></label>
            <?php if (count($myVehicles) > 0): ?>
              <select class="form-control mb-2" name="vehicle_id">
                <?php foreach ($myVehicles as $veh): ?>
                  <option value="<?= e((string) $veh['id']) ?>" <?= (string) $veh['id'] === $v['vehicle_id'] ? 'selected' : '' ?>>
                    <?= e($veh['make'] . ' ' . $veh['model'] . ' (' . $veh['plate_number'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <p class="small text-muted mb-2">No vehicles currently registered on your account. Please enter your vehicle details below:</p>
            <?php endif; ?>
            <?php if (!empty($errors['vehicle_id'])): ?>
              <span class="text-danger small"><?= e($errors['vehicle_id']) ?></span>
            <?php endif; ?>
          </div>

          <!-- Add New Vehicle Fields (if needed) -->
          <?php if (count($myVehicles) === 0): ?>
            <input type="hidden" name="vehicle_mode" value="new">
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label small">Plate Number <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="plate_number" value="<?= e($v['plate_number']) ?>" placeholder="e.g. 3-A12345">
                <?php if (!empty($errors['plate_number'])): ?><span class="text-danger small"><?= e($errors['plate_number']) ?></span><?php endif; ?>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Make <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="make" value="<?= e($v['make']) ?>" placeholder="e.g. Toyota">
                <?php if (!empty($errors['make'])): ?><span class="text-danger small"><?= e($errors['make']) ?></span><?php endif; ?>
              </div>
              <div class="col-md-4">
                <label class="form-label small">Model <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="model" value="<?= e($v['model']) ?>" placeholder="e.g. Corolla">
                <?php if (!empty($errors['model'])): ?><span class="text-danger small"><?= e($errors['model']) ?></span><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- Service Selection -->
          <div class="mb-3">
            <label class="form-label font-weight-bold" for="service_id">Desired Service Package</label>
            <select class="form-control" id="service_id" name="service_id">
              <option value="">— General Checkup / Workshop Inspection —</option>
              <?php foreach ($services as $srv): ?>
                <option value="<?= e((string) $srv['id']) ?>" <?= (string) $srv['id'] === $v['service_id'] ? 'selected' : '' ?>>
                  <?= e($srv['name']) ?> (<?= e(money($srv['standard_price'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Date and Time -->
          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label font-weight-bold" for="appointment_date">Preferred Date <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="appointment_date" name="appointment_date"
                     min="<?= e(date('Y-m-d')) ?>" value="<?= e($v['appointment_date']) ?>" required>
              <?php if (!empty($errors['appointment_date'])): ?>
                <span class="text-danger small"><?= e($errors['appointment_date']) ?></span>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label font-weight-bold" for="appointment_time">Preferred Time Slot <span class="text-danger">*</span></label>
              <select class="form-control" id="appointment_time" name="appointment_time" required>
                <?php foreach (['08:30', '09:00', '10:00', '11:00', '13:30', '14:30', '15:30', '16:30'] as $slot): ?>
                  <option value="<?= $slot ?>" <?= $v['appointment_time'] === $slot ? 'selected' : '' ?>><?= $slot ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['appointment_time'])): ?>
                <span class="text-danger small"><?= e($errors['appointment_time']) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Problem description -->
          <div class="mb-3">
            <label class="form-label font-weight-bold" for="problem_description">Issue Description / Special Requests</label>
            <textarea class="form-control" id="problem_description" name="problem_description" rows="3"
                      placeholder="Describe any symptoms, noises, or specific maintenance requested..."><?= e($v['problem_description']) ?></textarea>
          </div>

          <button type="submit" class="btn btn-primary btn-block py-2">
            Confirm &amp; Book Appointment
          </button>
        </form>
      </div>
    </div>

    <!-- Customer Bookings Sidebar -->
    <div class="col-lg-5">
      <div class="card p-4 shadow-sm mb-4">
        <h3 class="h5 mb-3 text-gold">My Recent Bookings</h3>
        <?php if (empty($customerBookings)): ?>
          <p class="text-muted small mb-0">You have no previous service bookings on file.</p>
        <?php else: ?>
          <div class="list-group list-group-flush">
            <?php foreach ($customerBookings as $bk): ?>
              <div class="list-group-item px-0 py-2 d-flex justify-content-between align-items-center">
                <div>
                  <strong><?= e($bk['appointment_number']) ?></strong> &middot; <small class="text-muted"><?= e($bk['appointment_date']) ?></small><br>
                  <small><?= e($bk['make'] . ' ' . $bk['model']) ?> &middot; <?= e($bk['service_name'] ?: 'Inspection') ?></small>
                </div>
                <div>
                  <span class="badge badge-<?= $bk['status'] === 'confirmed' ? 'success' : ($bk['status'] === 'in_progress' ? 'info' : 'secondary') ?>">
                    <?= e(ucfirst($bk['status'])) ?>
                  </span>
                  <a href="<?= e(url('book.php?view=' . $bk['id'])) ?>" class="btn btn-outline-secondary btn-sm ml-2">View</a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card p-3 shadow-sm bg-light">
        <h4 class="h6 font-weight-bold mb-2">Need Immediate Roadside Assistance?</h4>
        <p class="small text-muted mb-1">For urgent repairs or towing, contact our 24/7 emergency dispatch directly.</p>
        <p class="font-weight-bold text-gold mb-0">&#9742; <?= e(Setting::get('garage_phone', '+251 11 000 0000')) ?></p>
      </div>
    </div>
  </div>
</main>

<footer class="public-footer mt-5 py-4 border-top">
  <div class="container text-center text-muted small">
    &copy; <?= date('Y') ?> <?= e($garageName) ?>. All rights reserved.
  </div>
</footer>

</body>
</html>
