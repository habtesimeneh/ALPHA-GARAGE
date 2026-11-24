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

// Resolve customer profile via CustomerIdentityService (strictly enforces verified user_id linkage)
$customer = CustomerIdentityService::resolveCustomer($user);
$customerId = (int) $customer['id'];
$linkedCustomerIds = CustomerIdentityService::getLinkedCustomerIds((int) $user['id']);
if (!in_array($customerId, $linkedCustomerIds, true)) {
    $linkedCustomerIds[] = $customerId;
}
$inClause = implode(',', array_map('intval', $linkedCustomerIds));

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
    if (!in_array((int) $viewAppointment['customer_id'], $linkedCustomerIds, true) && !can('appointments.view')) {
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
    "SELECT id, plate_number, make, model FROM vehicles WHERE customer_id IN ($inClause) AND is_active = 1 ORDER BY plate_number ASC"
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
// 3. PROCESS BOOKING SUBMISSION & CANCELLATION (POST)
// =========================================================================
if (is_post() && !empty($_POST['cancel_booking'])) {
    $cancelAptId = post_int('appointment_id');
    if ($cancelAptId <= 0) {
        flash('danger', t('Invalid appointment selection.', 'Invalid appointment selection.'));
        redirect('book.php');
    }

    $apt = AppointmentService::find($cancelAptId);
    if (!$apt) {
        abort(404, 'Appointment record not found.');
    }

    // Strict IDOR Ownership Check
    if (!in_array((int) $apt['customer_id'], $linkedCustomerIds, true)) {
        AuditLogger::logSafe('idor_appointment_cancel_blocked', 'appointments', $cancelAptId, null, [
            'actor_user_id' => $user['id'],
            'actor_customer_id' => $customerId,
            'target_customer_id' => $apt['customer_id'],
        ], 'denied');
        abort(403, 'You do not have authorization to cancel this appointment.');
    }

    // Eligibility check: must be scheduled or confirmed
    if (!in_array($apt['status'], ['scheduled', 'confirmed'], true)) {
        flash('danger', t('This appointment cannot be cancelled because it is already ' . $apt['status'] . '.', 'This appointment cannot be cancelled because it is already ' . $apt['status'] . '.'));
        redirect('book.php?view=' . $cancelAptId);
    }

    // Cannot cancel if already converted to job card
    if (!empty($apt['converted_job_id'])) {
        flash('danger', t('This appointment has already been converted to a workshop repair order and cannot be cancelled online. Please contact reception.', 'This appointment has already been converted to a workshop repair order and cannot be cancelled online. Please contact reception.'));
        redirect('book.php?view=' . $cancelAptId);
    }

    // Execute cancellation
    AppointmentService::updateStatus($cancelAptId, 'cancelled');
    AuditLogger::log('appointment_cancelled_by_customer', 'appointments', $cancelAptId, [
        'status' => $apt['status']
    ], [
        'status' => 'cancelled',
        'appointment_number' => $apt['appointment_number'],
    ]);

    flash('success', t('Your appointment has been cancelled successfully.', 'Your appointment has been cancelled successfully.'));
    redirect('book.php?view=' . $cancelAptId);
}

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

    if ($v['vehicle_mode'] === 'new') {
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
                if (in_array((int) $existingVeh['customer_id'], $linkedCustomerIds, true)) {
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
            if (!$vehCheck || !in_array((int) $vehCheck['customer_id'], $linkedCustomerIds, true)) {
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

// Retrieve customer's active and previous appointments across all linked customer IDs (SEC-110D-06)
$aptPlaceholders = implode(',', array_fill(0, count($linkedCustomerIds), '?'));
$customerBookings = Database::all(
    "SELECT a.*, v.plate_number, v.make, v.model, s.name AS service_name
     FROM appointments a
     JOIN vehicles v ON v.id = a.vehicle_id
     LEFT JOIN services s ON s.id = a.service_id
     WHERE a.customer_id IN ($aptPlaceholders)
     ORDER BY a.appointment_date DESC, a.appointment_time DESC
     LIMIT 10",
    $linkedCustomerIds
);
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Book Service &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<meta name="theme-color" content="#071711">
<?= css_links() ?>
</head>
<body class="luxury-portal-body">
<div id="toast-root" class="toast-root" aria-live="polite"></div>

<header class="public-nav-luxury">
  <div class="nav-container">
    <a href="<?= e(url('index.php')) ?>" class="nav-brand">
      <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36">
      <span><?= e($garageName) ?></span>
    </a>
    <nav class="nav-links">
      <a href="<?= e(url('index.php')) ?>"><?= e(t('Home', 'Home')) ?></a>
      <a href="<?= e(url('services.php')) ?>"><?= e(t('Services', 'Services')) ?></a>
      <a href="<?= e(url('videos.php')) ?>"><?= e(t('Videos', 'Videos')) ?></a>
      <a href="<?= e(url('contact.php')) ?>"><?= e(t('Contact', 'Contact')) ?></a>
      <a href="<?= e(url('customer.php')) ?>"><?= e(t('My Dashboard', 'My Dashboard')) ?></a>
      <a href="<?= e(url('book.php')) ?>" class="active"><?= e(t('Book Service', 'Book Service')) ?></a>
      <a href="<?= e(url('profile.php')) ?>"><?= e(t('Account', 'Account')) ?> (<?= e($user['username']) ?>)</a>
      <?= lang_switcher_html() ?>
      <button class="icon-btn theme-toggle" type="button" aria-label="Switch theme" aria-pressed="false" title="Switch theme">
        <span aria-hidden="true">◐</span>
      </button>
      <form method="post" action="<?= e(url('logout.php')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <button type="submit" class="btn-luxury-text"><?= e(t('Sign Out', 'Sign Out')) ?></button>
      </form>
    </nav>
  </div>
</header>

<main class="container py-4">
  <div class="booking-portal-header mb-4">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h1 class="h2 text-gold"><?= e(t('Service Booking Portal', 'Service Booking Portal')) ?></h1>
        <p class="text-muted"><?= e(t('Schedule your vehicle maintenance or repair visit with our certified technicians.', 'Schedule your vehicle maintenance or repair visit with our certified technicians.')) ?></p>
      </div>
      <?php if (can('appointments.manage')): ?>
        <a href="<?= e(url('appointments/')) ?>" class="btn btn-secondary btn-sm"><?= e(t('Staff Workshop Board', 'Staff Workshop Board')) ?> &rarr;</a>
      <?php endif; ?>
    </div>
  </div>

  <?php foreach (pull_flash() as $fl): ?>
    <div class="luxury-alert luxury-alert-<?= $fl['type'] === 'success' ? 'success' : 'danger' ?> mb-4" role="alert">
      <?= e(t($fl['message'], $fl['message'])) ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($errors['general'])): ?>
    <div class="luxury-alert luxury-alert-danger mb-4" role="alert">
      <?= e(t($errors['general'], $errors['general'])) ?>
    </div>
  <?php endif; ?>

  <?php if ($viewAppointment): ?>
    <!-- Booking Confirmation Card -->
    <div class="card p-4 mb-4 border-gold shadow-sm">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h4 text-gold mb-0"><?= e(t('Booking Details', 'Booking Details')) ?> &middot; <?= e($viewAppointment['appointment_number']) ?></h2>
        <span class="badge badge-<?= $viewAppointment['status'] === 'confirmed' ? 'success' : ($viewAppointment['status'] === 'in_progress' ? 'info' : 'warning') ?>">
          <?= e(t(ucfirst($viewAppointment['status']), ucfirst($viewAppointment['status']))) ?>
        </span>
      </div>
      <div class="row">
        <div class="col-md-6 mb-2">
          <strong><?= e(t('Vehicle', 'Vehicle')) ?>:</strong> <?= e($viewAppointment['make'] . ' ' . $viewAppointment['model']) ?> (<code><?= e($viewAppointment['plate_number']) ?></code>)
        </div>
        <div class="col-md-6 mb-2">
          <strong><?= e(t('Service', 'Service')) ?>:</strong> <?= e(t($viewAppointment['service_name'] ?: 'General Checkup', $viewAppointment['service_name'] ?: 'General Checkup')) ?>
        </div>
        <div class="col-md-6 mb-2">
          <strong><?= e(t('Scheduled Date', 'Scheduled Date')) ?>:</strong> <?= e($viewAppointment['appointment_date']) ?> <?= e(t('at', 'at')) ?> <?= e($viewAppointment['appointment_time']) ?>
        </div>
        <div class="col-md-6 mb-2">
          <strong><?= e(t('Customer Name', 'Customer Name')) ?>:</strong> <?= e($customer['full_name']) ?>
        </div>
        <?php if (!empty($viewAppointment['problem_description'])): ?>
          <div class="col-12 mt-2">
            <strong><?= e(t('Notes / Description', 'Notes / Description')) ?>:</strong>
            <p class="mb-0 text-muted"><?= e($viewAppointment['problem_description']) ?></p>
          </div>
        <?php endif; ?>
      </div>
      <div class="mt-3 d-flex gap-2 align-items-center">
        <a href="<?= e(url('book.php')) ?>" class="btn btn-primary btn-sm">&larr; <?= e(t('Book Another Appointment', 'Book Another Appointment')) ?></a>
        <?php if (in_array($viewAppointment['status'], ['scheduled', 'confirmed'], true) && empty($viewAppointment['converted_job_id'])): ?>
          <form method="post" action="<?= e(url('book.php')) ?>" class="inline-form" onsubmit="return confirm('<?= e(t('Are you sure you want to cancel this appointment?', 'Are you sure you want to cancel this appointment?')) ?>');">
            <?= csrf_field() ?>
            <input type="hidden" name="cancel_booking" value="1">
            <input type="hidden" name="appointment_id" value="<?= (int) $viewAppointment['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm"><?= e(t('Cancel Appointment', 'Cancel Appointment')) ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row">
    <!-- Booking Form Column -->
    <div class="col-lg-7">
      <div class="card p-4 shadow-sm mb-4">
        <h3 class="h4 mb-3 text-gold"><?= e(t('Schedule Your Appointment', 'Schedule Your Appointment')) ?></h3>
        
        <form method="post" autocomplete="off" novalidate>
          <?= csrf_field() ?>

          <!-- Section 1: Vehicle Information -->
          <div class="booking-section-header">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z"/></svg>
            <span><?= e(t('Vehicle Information', 'Vehicle Information')) ?></span>
          </div>

          <div class="mb-3">
            <label class="form-label font-weight-bold" for="portalVehicleId"><?= e(t('Select Vehicle', 'Select Vehicle')) ?> <span class="text-danger">*</span></label>
            <?php if (count($myVehicles) > 0): ?>
              <select class="input mb-2" id="portalVehicleId" name="vehicle_id">
                <?php foreach ($myVehicles as $veh): ?>
                  <option value="<?= e((string) $veh['id']) ?>" <?= (string) $veh['id'] === $v['vehicle_id'] ? 'selected' : '' ?>>
                    <?= e($veh['make'] . ' ' . $veh['model'] . ' (' . $veh['plate_number'] . ')') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <p class="small text-muted mb-2"><?= e(t('No vehicles currently registered on your account. Please enter your vehicle details below:', 'No vehicles currently registered on your account. Please enter your vehicle details below:')) ?></p>
            <?php endif; ?>
            <?php if (!empty($errors['vehicle_id'])): ?>
              <span class="text-danger small"><?= e(t($errors['vehicle_id'], $errors['vehicle_id'])) ?></span>
            <?php endif; ?>
          </div>

          <!-- Add New Vehicle Fields (if needed) -->
          <?php if (count($myVehicles) === 0): ?>
            <input type="hidden" name="vehicle_mode" value="new">
            <div class="row mb-3">
              <div class="col-md-4">
                <label class="form-label small" for="portalPlateNumber"><?= e(t('Plate Number', 'Plate Number')) ?> <span class="text-danger">*</span></label>
                <input type="text" class="input" id="portalPlateNumber" name="plate_number" value="<?= e($v['plate_number']) ?>" placeholder="e.g. 3-A12345" style="text-transform:uppercase;">
                <?php if (!empty($errors['plate_number'])): ?><span class="text-danger small"><?= e(t($errors['plate_number'], $errors['plate_number'])) ?></span><?php endif; ?>
              </div>
              <div class="col-md-4">
                <label class="form-label small" for="portalMake"><?= e(t('Make', 'Make')) ?> <span class="text-danger">*</span></label>
                <input type="text" class="input" id="portalMake" name="make" value="<?= e($v['make']) ?>" placeholder="e.g. Toyota">
                <?php if (!empty($errors['make'])): ?><span class="text-danger small"><?= e(t($errors['make'], $errors['make'])) ?></span><?php endif; ?>
              </div>
              <div class="col-md-4">
                <label class="form-label small" for="portalModel"><?= e(t('Model', 'Model')) ?> <span class="text-danger">*</span></label>
                <input type="text" class="input" id="portalModel" name="model" value="<?= e($v['model']) ?>" placeholder="e.g. Corolla">
                <?php if (!empty($errors['model'])): ?><span class="text-danger small"><?= e(t($errors['model'], $errors['model'])) ?></span><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>

          <!-- Section 2: Appointment Information -->
          <div class="booking-section-header">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11zM7 10h5v5H7z"/></svg>
            <span><?= e(t('Appointment Information', 'Appointment Information')) ?></span>
          </div>

          <div class="mb-3">
            <label class="form-label font-weight-bold" for="service_id"><?= e(t('Desired Service Package', 'Desired Service Package')) ?></label>
            <select class="input" id="service_id" name="service_id">
              <option value="">— <?= e(t('General Checkup / Workshop Inspection', 'General Checkup / Workshop Inspection')) ?> —</option>
              <?php foreach ($services as $srv): ?>
                <option value="<?= e((string) $srv['id']) ?>" <?= (string) $srv['id'] === $v['service_id'] ? 'selected' : '' ?>>
                  <?= e(t($srv['name'], $srv['name'])) ?> (<?= e(money($srv['standard_price'])) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="row mb-3">
            <div class="col-md-6">
              <label class="form-label font-weight-bold" for="appointment_date"><?= e(t('Preferred Date', 'Preferred Date')) ?> <span class="text-danger">*</span></label>
              <input type="date" class="input" id="appointment_date" name="appointment_date"
                     min="<?= e(date('Y-m-d')) ?>" value="<?= e($v['appointment_date']) ?>" required>
              <?php if (!empty($errors['appointment_date'])): ?>
                <span class="text-danger small"><?= e(t($errors['appointment_date'], $errors['appointment_date'])) ?></span>
              <?php endif; ?>
            </div>
            <div class="col-md-6">
              <label class="form-label font-weight-bold" for="appointment_time"><?= e(t('Preferred Time Slot', 'Preferred Time Slot')) ?> <span class="text-danger">*</span></label>
              <select class="input" id="appointment_time" name="appointment_time" required>
                <?php foreach (['08:30', '09:00', '10:00', '11:00', '13:30', '14:30', '15:30', '16:30'] as $slot): ?>
                  <option value="<?= $slot ?>" <?= $v['appointment_time'] === $slot ? 'selected' : '' ?>><?= $slot ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['appointment_time'])): ?>
                <span class="text-danger small"><?= e(t($errors['appointment_time'], $errors['appointment_time'])) ?></span>
              <?php endif; ?>
            </div>
          </div>

          <!-- Section 3: Additional Information -->
          <div class="booking-section-header">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
            <span><?= e(t('Additional Information', 'Additional Information')) ?></span>
          </div>

          <div class="mb-4">
            <label class="form-label font-weight-bold" for="problem_description"><?= e(t('Issue Description / Special Requests', 'Issue Description / Special Requests')) ?></label>
            <textarea class="input" id="problem_description" name="problem_description" rows="4"
                      placeholder="<?= e(t('Describe any symptoms, noises, or specific maintenance requested...', 'Describe any symptoms, noises, or specific maintenance requested...')) ?>"><?= e($v['problem_description']) ?></textarea>
            <span class="booking-field-hint"><?= e(t('Help our certified technicians prepare needed tools and parts prior to arrival.', 'Help our certified technicians prepare needed tools and parts prior to arrival.')) ?></span>
          </div>

          <button type="submit" class="btn btn-primary btn-block btn-interactive btn-shimmer py-3">
            <?= e(t('Confirm & Book Appointment', 'Confirm & Book Appointment')) ?>
          </button>
        </form>
      </div>
    </div>

    <!-- Customer Bookings Sidebar -->
    <div class="col-lg-5">
      <div class="card p-4 shadow-sm mb-4">
        <h3 class="h5 mb-3 text-gold"><?= e(t('My Recent Bookings', 'My Recent Bookings')) ?></h3>
        <?php if (empty($customerBookings)): ?>
          <p class="text-muted small mb-0"><?= e(t('You have no previous service bookings on file.', 'You have no previous service bookings on file.')) ?></p>
        <?php else: ?>
          <div class="booking-history-list">
            <?php foreach ($customerBookings as $bk): ?>
              <div class="booking-history-item">
                <div>
                  <strong><?= e($bk['appointment_number']) ?></strong> &middot; <small class="text-muted"><?= e($bk['appointment_date']) ?></small><br>
                  <small><?= e($bk['make'] . ' ' . $bk['model']) ?> &middot; <?= e(t($bk['service_name'] ?: 'Inspection', $bk['service_name'] ?: 'Inspection')) ?></small>
                </div>
                <div>
                  <span class="badge badge-<?= $bk['status'] === 'confirmed' ? 'success' : ($bk['status'] === 'in_progress' ? 'info' : 'secondary') ?>">
                    <?= e(t(ucfirst($bk['status']), ucfirst($bk['status']))) ?>
                  </span>
                  <a href="<?= e(url('book.php?view=' . $bk['id'])) ?>" class="btn btn-ghost btn-sm ml-2"><?= e(t('View', 'View')) ?></a>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="card card-support-callout shadow-sm mb-4">
        <h4 class="h6 font-weight-bold mb-2 text-gold"><?= e(t('Need Immediate Roadside Assistance?', 'Need Immediate Roadside Assistance?')) ?></h4>
        <p class="small text-muted mb-2"><?= e(t('For urgent repairs or towing, contact our 24/7 emergency dispatch directly.', 'For urgent repairs or towing, contact our 24/7 emergency dispatch directly.')) ?></p>
        <p class="font-weight-bold text-gold mb-0" style="font-size: 1.05rem;">&#9742; <?= e(Setting::get('garage_phone', '+251 11 000 0000')) ?></p>
      </div>
    </div>
  </div>
</main>

<footer class="public-footer mt-5 py-4 border-top">
  <div class="container text-center text-muted small">
    &copy; <?= date('Y') ?> <?= e($garageName) ?>. <?= e(t('All rights reserved.', 'All rights reserved.')) ?>
  </div>
</footer>

<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/i18n.js')) ?>"></script>
<script src="<?= e(asset('js/scroll-animations.js')) ?>"></script>
</body>
</html>
