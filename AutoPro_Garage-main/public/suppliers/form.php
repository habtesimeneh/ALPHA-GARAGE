<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('suppliers.manage');

$id = get_int('id') ?: null;
$supplier = $id ? SupplierService::find($id) : null;
if ($id && !$supplier) { abort(404, 'Supplier not found.'); }

$errors = [];
$data = [
    'code' => $supplier['code'] ?? '',
    'name' => $supplier['name'] ?? '',
    'contact_person' => $supplier['contact_person'] ?? '',
    'phone' => $supplier['phone'] ?? '',
    'email' => $supplier['email'] ?? '',
    'address' => $supplier['address'] ?? '',
];

if (is_post()) {
    $data = [
        'code' => post('code'),
        'name' => post('name'),
        'contact_person' => post('contact_person'),
        'phone' => post('phone'),
        'email' => post('email'),
        'address' => post('address'),
    ];

    $errors = validate($data, [
        'code' => 'required|max:50',
        'name' => 'required|max:150',
        'phone' => 'phone',
        'email' => 'email',
    ]);

    if (!$errors) {
        try {
            $savedId = SupplierService::save($id, $data);
            flash('success', $id ? 'Supplier updated successfully.' : 'New supplier added successfully.');
            redirect('suppliers/view.php?id=' . $savedId);
        } catch (DomainException $e) {
            $errors['code'] = $e->getMessage();
        }
    }
}

ui_header($id ? 'Edit Supplier' : 'New Supplier', 'suppliers');
page_header($id ? 'Edit Supplier' : 'Add New Supplier', 'Vendor profile, contacts and inventory details');
?>

<div class="card form-card-680">
  <form method="post" data-validate autocomplete="off">
    <?= csrf_field() ?>
    <div class="form-grid">
      <div><?php form_input('code', 'Supplier Code', $data['code'], $errors, ['required' => true, 'placeholder' => 'e.g. SUP-BOSCH']); ?></div>
      <div><?php form_input('name', 'Company Name', $data['name'], $errors, ['required' => true, 'placeholder' => 'e.g. Bosch Auto Parts Ethiopia']); ?></div>
      <div><?php form_input('contact_person', 'Contact Person', $data['contact_person'], $errors, ['placeholder' => 'Representative name']); ?></div>
      <div><?php form_input('phone', 'Phone Number', $data['phone'], $errors, ['placeholder' => '+251 91 123 4567']); ?></div>
      <div class="full"><?php form_input('email', 'Email Address', $data['email'], $errors, ['type' => 'email', 'placeholder' => 'orders@supplier.example']); ?></div>
      <div class="full"><?php form_textarea('address', 'Physical Address / Warehouse', $data['address'], $errors, ['placeholder' => 'Street, Subcity, City...']); ?></div>
    </div>
    <div class="form-actions">
      <button class="btn btn-primary" type="submit"><?= e(t($id ? 'Save Changes' : 'Register Supplier', $id ? 'Save Changes' : 'Register Supplier')) ?></button>
      <a class="btn btn-ghost" href="<?= e(url('suppliers/')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a>
    </div>
  </form>
</div>

<?php ui_footer();
