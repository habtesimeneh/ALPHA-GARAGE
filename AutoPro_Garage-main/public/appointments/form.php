<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
if (($user['role_name'] ?? '') === 'customer') {
    redirect('book.php');
}
require_permission('appointments.manage');

$customers = Database::all('SELECT id, full_name, phone FROM customers WHERE is_active = 1 ORDER BY full_name ASC');
$vehicles = Database::all('SELECT id, customer_id, plate_number, make, model FROM vehicles WHERE is_active = 1 ORDER BY plate_number ASC');
$services = Database::all('SELECT id, code, name, category, standard_price FROM services WHERE is_active = 1 ORDER BY category ASC, name ASC');

$custOpts = ['' => '— Choose a customer —'] + array_column($customers, 'full_name', 'id');
$srvOpts = ['' => '— General Checkup / Other —'];
foreach ($services as $s) {
    $srvOpts[$s['id']] = $s['name'] . ' (' . money($s['standard_price']) . ')';
}

$errors = [];
$data = [
    'customer_id' => get_int('customer_id') ?: '',
    'vehicle_id' => get_int('vehicle_id') ?: '',
    'service_id' => get_int('service_id') ?: '',
    'appointment_date' => date('Y-m-d', strtotime('+1 day')),
    'appointment_time' => '09:00',
    'problem_description' => '',
];

if (is_post()) {
    $data = [
        'customer_id' => post('customer_id'),
        'vehicle_id' => post('vehicle_id'),
        'service_id' => post('service_id'),
        'appointment_date' => post('appointment_date'),
        'appointment_time' => post('appointment_time'),
        'problem_description' => post('problem_description'),
    ];

    $errors = validate($data, [
        'customer_id' => 'required|int',
        'vehicle_id' => 'required|int',
        'appointment_date' => 'required|date',
        'appointment_time' => 'required',
    ]);

    if (!$errors) {
        try {
            $id = AppointmentService::create($data);
            flash('success', 'Appointment booked successfully.');
            redirect('appointments/');
        } catch (DomainException $e) {
            $errors['vehicle_id'] = $e->getMessage();
        }
    }
}

ui_header('Book Service Appointment', 'appointments');
page_header('Book Service Appointment', 'Schedule vehicle check-in and workshop slot');
?>

<div class="card form-card-720">
  <form method="post" data-validate autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div class="full">
        <?php form_select('customer_id', 'Customer', $custOpts, $data['customer_id'], $errors, ['required' => true, 'id' => 'f_customer_id']); ?>
      </div>

      <div class="full">
        <div class="field<?= !empty($errors['vehicle_id']) ? ' has-error' : '' ?>">
          <label for="f_vehicle_id"><?= e(t('Vehicle', 'Vehicle')) ?> <span class="req">*</span></label>
          <select class="input" id="f_vehicle_id" name="vehicle_id" required>
            <option value=""><?= e(t('— Select owner first —', '— Select owner first —')) ?></option>
            <?php foreach ($vehicles as $v): ?>
              <option value="<?= (int) $v['id'] ?>" data-customer="<?= (int) $v['customer_id'] ?>"<?= (string) $v['id'] === (string) $data['vehicle_id'] ? ' selected' : '' ?>>
                <?= e($v['plate_number']) ?> · <?= e($v['make'] . ' ' . $v['model']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field-error"><?= e($errors['vehicle_id'] ?? '') ?></div>
        </div>
      </div>

      <div class="full">
        <?php form_select('service_id', 'Preferred Service from Catalog', $srvOpts, $data['service_id'], $errors); ?>
      </div>

      <div>
        <?php form_input('appointment_date', 'Appointment Date', $data['appointment_date'], $errors, ['type' => 'date', 'min' => date('Y-m-d'), 'required' => true]); ?>
      </div>

      <div>
        <?php form_input('appointment_time', 'Appointment Time', $data['appointment_time'], $errors, ['type' => 'time', 'required' => true]); ?>
      </div>

      <div class="full">
        <?php form_textarea('problem_description', 'Customer Reported Issues / Special Instructions', $data['problem_description'], $errors, ['placeholder' => 'Any customer symptoms, noises or specific requests...']); ?>
      </div>
    </div>

    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t('Confirm Appointment', 'Confirm Appointment')) ?></button>
      <a class="btn btn-ghost" href="<?= e(url('appointments/')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a>
    </div>
  </form>
</div>

<?php ui_footer();
