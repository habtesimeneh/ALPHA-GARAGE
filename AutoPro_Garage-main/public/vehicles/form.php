<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('vehicles.manage');

$id = get_int('id');
$veh = $id ? Database::one('SELECT * FROM vehicles WHERE id = ?', [$id]) : null;
if ($id && !$veh) { abort(404, 'Vehicle not found.'); }
$fuels = ['Petrol', 'Diesel', 'Hybrid', 'Electric', 'LPG', 'Other'];
$types = ['Sedan', 'SUV', 'Truck', 'Van', 'Motorcycle', 'Coupe', 'Hatchback', 'Wagon', 'Convertible', 'Commercial', 'Other'];
$v = $veh ?: [
    'customer_id' => get_int('customer_id') ?: '',
    'plate_number' => '',
    'make' => '',
    'model' => '',
    'year' => '',
    'color' => '',
    'vin' => '',
    'mileage' => '0',
    'fuel_type' => 'Petrol',
    'vehicle_type' => 'Sedan',
    'notes' => ''
];
$errors = [];

if (is_post()) {
    $v = [
        'customer_id' => post('customer_id'),
        'plate_number' => strtoupper(preg_replace('/\s+/', ' ', post('plate_number'))),
        'make' => post('make'),
        'model' => post('model'),
        'year' => post('year'),
        'color' => post('color'),
        'vin' => strtoupper(post('vin')),
        'mileage' => post('mileage', '0'),
        'fuel_type' => post('fuel_type'),
        'vehicle_type' => post('vehicle_type', 'Sedan'),
        'notes' => post('notes')
    ];
    $errors = validate($v, [
        'customer_id' => 'required|int',
        'plate_number' => 'required|plate',
        'make' => 'required|max:50',
        'model' => 'required|max:50',
        'year' => 'int|min_val:1950|max_val:' . ((int) date('Y') + 1),
        'color' => 'max:30',
        'vin' => 'vin',
        'mileage' => 'required|int|min_val:0|max_val:3000000',
        'fuel_type' => 'required|in:' . implode(',', $fuels),
        'vehicle_type' => 'required|in:' . implode(',', $types),
        'notes' => 'max:2000'
    ]);
    if (!$errors) {
        $cust = Database::one('SELECT id, is_active FROM customers WHERE id = ?', [(int) $v['customer_id']]);
        if (!$cust || (!$cust['is_active'] && (!$veh || (int) $veh['customer_id'] !== (int) $cust['id']))) { $errors['customer_id'] = 'Choose an active customer.'; }
        if (Database::value('SELECT id FROM vehicles WHERE plate_number = ? AND id <> ?', [$v['plate_number'], $id])) { $errors['plate_number'] = 'This plate number is already registered.'; }
        if ($v['vin'] !== '' && Database::value('SELECT id FROM vehicles WHERE vin = ? AND id <> ?', [$v['vin'], $id])) { $errors['vin'] = 'This VIN is already registered.'; }
    }
    if (!$errors) {
        $vals = [(int) $v['customer_id'], $v['plate_number'], $v['make'], $v['model'], $v['year'] !== '' ? (int) $v['year'] : null, $v['color'] ?: null, $v['vin'] ?: null, (int) $v['mileage'], $v['fuel_type'], $v['vehicle_type'], $v['notes'] ?: null];
        $newId = Database::transaction(function () use ($id, $veh, $vals, $v) {
            if ($veh) {
                Database::run('UPDATE vehicles SET customer_id=?, plate_number=?, make=?, model=?, year=?, color=?, vin=?, mileage=?, fuel_type=?, vehicle_type=?, notes=? WHERE id=?', [...$vals, $id]);
                AuditLogger::log('vehicle_updated', 'vehicles', $id, array_diff_key($veh, array_flip(['created_at', 'updated_at'])), $v);
                return $id;
            }
            $nid = Database::insert('INSERT INTO vehicles (customer_id, plate_number, make, model, year, color, vin, mileage, fuel_type, vehicle_type, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)', $vals);
            AuditLogger::log('vehicle_created', 'vehicles', $nid, null, $v);
            return $nid;
        });
        flash('success', $veh ? 'Vehicle updated.' : 'Vehicle registered.');
        redirect('vehicles/view.php?id=' . $newId);
    }
}
$customers = Database::all('SELECT id, full_name, phone FROM customers WHERE is_active = 1 OR id = ? ORDER BY full_name LIMIT 2000', [(int) ($veh['customer_id'] ?? 0)]);
$custOptions = ['' => t('— Select customer —', '— Select customer —')];
foreach ($customers as $c) { $custOptions[$c['id']] = $c['full_name'] . ' (' . $c['phone'] . ')'; }

ui_header($veh ? 'Edit vehicle' : 'Register vehicle', 'vehicles');
page_header($veh ? 'Edit vehicle' : 'Register vehicle', 'One customer can own multiple vehicles');
?>
<div class="card w-md"><form method="post" data-validate novalidate><?= csrf_field() ?>
  <div class="form-grid">
    <div class="full"><?php form_select('customer_id', 'Owner', $custOptions, $v['customer_id'], $errors, ['required' => true]); ?></div>
    <?php form_input('plate_number', 'Plate number', $v['plate_number'], $errors, ['required' => true, 'maxlength' => 20, 'pattern' => '[A-Za-z0-9 \-]{2,20}', 'title' => 'Letters, digits, spaces and dashes']); ?>
    <?php form_input('vin', 'VIN (optional)', $v['vin'], $errors, ['maxlength' => 17, 'pattern' => '[A-HJ-NPR-Za-hj-npr-z0-9]{11,17}', 'title' => '11-17 characters, no I, O or Q']); ?>
    <?php form_input('make', 'Make', $v['make'], $errors, ['required' => true, 'maxlength' => 50]); ?>
    <?php form_input('model', 'Model', $v['model'], $errors, ['required' => true, 'maxlength' => 50]); ?>
    <?php form_input('year', 'Year', $v['year'], $errors, ['type' => 'number', 'min' => 1950, 'max' => (int) date('Y') + 1]); ?>
    <?php form_input('color', 'Color', $v['color'], $errors, ['maxlength' => 30]); ?>
    <?php form_input('mileage', 'Mileage (km)', $v['mileage'], $errors, ['type' => 'number', 'min' => 0, 'max' => 3000000, 'required' => true]); ?>
    <?php form_select('fuel_type', 'Fuel type', array_combine($fuels, $fuels), $v['fuel_type'], $errors, ['required' => true]); ?>
    <?php form_select('vehicle_type', 'Vehicle type', array_combine($types, $types), $v['vehicle_type'], $errors, ['required' => true]); ?>
    <div class="full"><?php form_textarea('notes', 'Vehicle notes / special instructions', $v['notes'] ?? '', $errors, ['rows' => 3, 'placeholder' => 'Any vehicle modifications, past damage, or handling notes...']); ?></div>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('Save vehicle', 'Save vehicle')) ?></button><a class="btn btn-ghost" href="<?= e(url($veh ? 'vehicles/view.php?id=' . $id : 'vehicles/')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a></div>
</form></div>
<?php ui_footer();
