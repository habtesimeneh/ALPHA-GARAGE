<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('services.manage');

$id = get_int('id') ?: null;
$service = $id ? CatalogService::find($id) : null;
if ($id && !$service) { abort(404, 'Service not found.'); }

$errors = [];
$data = [
    'code' => $service['code'] ?? '',
    'name' => $service['name'] ?? '',
    'category' => $service['category'] ?? 'General Repair',
    'description' => $service['description'] ?? '',
    'standard_price' => $service ? (string)$service['standard_price'] : '0.00',
    'estimated_hours' => $service ? (string)$service['estimated_hours'] : '1.00',
];

if (is_post()) {
    $data = [
        'code' => post('code'),
        'name' => post('name'),
        'category' => post('category'),
        'description' => post('description'),
        'standard_price' => post('standard_price'),
        'estimated_hours' => post('estimated_hours'),
    ];

    $errors = validate($data, [
        'code' => 'required|max:40',
        'name' => 'required|max:150',
        'category' => 'required',
        'standard_price' => 'required|numeric',
        'estimated_hours' => 'required|numeric|min_val:0.25',
    ]);

    if (!$errors) {
        try {
            $savedId = CatalogService::save($id, $data);
            flash('success', $id ? 'Service updated successfully.' : 'New service created successfully.');
            redirect('services/');
        } catch (DomainException $e) {
            $errors['code'] = $e->getMessage();
        }
    }
}

ui_header($id ? 'Edit Service' : 'New Service', 'services');
page_header($id ? 'Edit Service' : 'Add New Service', 'Configure service details, labor pricing and estimated time');
?>

<div class="card form-card-680">
  <form method="post" data-validate autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><?php form_input('code', 'Service Code', $data['code'], $errors, ['required' => true, 'placeholder' => 'e.g. SRV-ENG-002']); ?></div>
      <div><?php form_select('category', 'Category', CatalogService::CATEGORIES, $data['category'], $errors, ['required' => true]); ?></div>
      <div class="full"><?php form_input('name', 'Service Name', $data['name'], $errors, ['required' => true, 'placeholder' => 'e.g. Radiator Flush & Coolant Replacement']); ?></div>
      <div class="full"><?php form_textarea('description', 'Description / Procedure Notes', $data['description'], $errors, ['placeholder' => 'Details of the standard service procedure...']); ?></div>
      <div><?php form_input('standard_price', 'Standard Price (' . Setting::get('currency') . ')', $data['standard_price'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => '0', 'required' => true]); ?></div>
      <div><?php form_input('estimated_hours', 'Estimated Hours', $data['estimated_hours'], $errors, ['type' => 'number', 'step' => '0.25', 'min' => '0.25', 'required' => true]); ?></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t($id ? 'Save Changes' : 'Create Service', $id ? 'Save Changes' : 'Create Service')) ?></button>
      <a class="btn btn-ghost" href="<?= e(url('services/')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a>
    </div>
  </form>
</div>

<?php ui_footer();
