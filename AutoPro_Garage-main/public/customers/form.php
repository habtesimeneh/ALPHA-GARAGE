<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('customers.manage');

$id = get_int('id');
$cust = $id ? Database::one('SELECT * FROM customers WHERE id = ?', [$id]) : null;
if ($id && !$cust) { abort(404, 'Customer not found.'); }
$v = $cust ?: ['full_name' => '', 'phone' => '', 'email' => '', 'address' => ''];
$errors = [];

if (is_post()) {
    $v = ['full_name' => post('full_name'), 'phone' => post('phone'), 'email' => post('email'), 'address' => post('address')];
    $errors = validate($v, ['full_name' => 'required|min:2|max:120', 'phone' => 'required|phone', 'email' => 'email|max:150', 'address' => 'max:255']);
    if (!$errors) {
        $newId = Database::transaction(function () use ($id, $cust, $v) {
            if ($cust) {
                Database::run('UPDATE customers SET full_name=?, phone=?, email=?, address=? WHERE id=?', [$v['full_name'], $v['phone'], $v['email'] ?: null, $v['address'] ?: null, $id]);
                AuditLogger::log('customer_updated', 'customers', $id,
                    ['full_name' => $cust['full_name'], 'phone' => $cust['phone'], 'email' => $cust['email'], 'address' => $cust['address']], $v);
                return $id;
            }
            $nid = Database::insert('INSERT INTO customers (full_name, phone, email, address, created_by) VALUES (?,?,?,?,?)',
                [$v['full_name'], $v['phone'], $v['email'] ?: null, $v['address'] ?: null, current_user()['id']]);
            AuditLogger::log('customer_created', 'customers', $nid, null, $v);
            return $nid;
        });
        flash('success', $cust ? 'Customer updated.' : 'Customer registered.');
        redirect('customers/view.php?id=' . $newId);
    }
}
ui_header($cust ? 'Edit customer' : 'New customer', 'customers');
page_header($cust ? 'Edit customer' : 'Register customer', $cust ? 'CUS-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) : 'Add a new customer to the garage');
?>
<div class="card w-md"><form method="post" data-validate novalidate><?= csrf_field() ?>
  <div class="form-grid">
    <div class="full"><?php form_input('full_name', 'Full name', $v['full_name'], $errors, ['required' => true, 'maxlength' => 120]); ?></div>
    <?php form_input('phone', 'Phone', $v['phone'], $errors, ['required' => true, 'type' => 'tel', 'maxlength' => 20, 'pattern' => '\+?[0-9 ()\-]{7,20}', 'title' => 'Digits, spaces, dashes; optional leading +']); ?>
    <?php form_input('email', 'Email', $v['email'], $errors, ['type' => 'email', 'maxlength' => 150]); ?>
    <div class="full"><?php form_input('address', 'Address', $v['address'], $errors, ['maxlength' => 255]); ?></div>
  </div>
  <div class="form-actions"><button class="btn btn-primary" type="submit">Save customer</button><a class="btn btn-ghost" href="<?= e(url($cust ? 'customers/view.php?id=' . $id : 'customers/')) ?>">Cancel</a></div>
</form></div>
<?php ui_footer();
