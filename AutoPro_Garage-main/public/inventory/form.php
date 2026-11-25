<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('inventory.manage');

$id = get_int('id');
$p = $id ? Database::one('SELECT * FROM spare_parts WHERE id = ?', [$id]) : null;
if ($id && !$p) { abort(404, t('Part not found.', 'Part not found.')); }
$v = $p ?: ['sku' => '', 'name' => '', 'category' => '', 'unit' => 'pcs', 'quantity' => '0', 'reorder_level' => '5', 'unit_cost' => '0.00', 'unit_price' => '0.00', 'supplier' => ''];
$errors = [];
if (is_post()) {
    $v = ['sku' => strtoupper(post('sku')), 'name' => post('name'), 'category' => post('category'), 'unit' => post('unit', 'pcs'), 'quantity' => post('quantity', '0'),
          'reorder_level' => post('reorder_level'), 'unit_cost' => post('unit_cost'), 'unit_price' => post('unit_price'), 'supplier' => post('supplier')];
    $errors = validate($v, ['sku' => 'required|max:40', 'name' => 'required|max:150', 'category' => 'max:60', 'unit' => 'required|max:20',
        'quantity' => 'int|min_val:0|max_val:2147483647', 'reorder_level' => 'required|int|min_val:0|max_val:2147483647',
        'unit_cost' => 'required|numeric|max_val:9999999999.99', 'unit_price' => 'required|numeric|max_val:9999999999.99', 'supplier' => 'max:120']);
    if (!$errors && Database::value('SELECT id FROM spare_parts WHERE sku = ? AND id <> ?', [$v['sku'], $id])) { $errors['sku'] = t('This SKU already exists.', 'This SKU already exists.'); }
    if (!$errors && to_cents($v['unit_price']) < to_cents($v['unit_cost'])) { flash('warning', t('Selling price is below cost.', 'Selling price is below cost.')); }
    if (!$errors) {
        try { $nid = InventoryService::savePart($id ?: null, $v); flash('success', $p ? t('Part updated.', 'Part updated.') : t('Part added.', 'Part added.')); redirect('inventory/'); }
        catch (DomainException $e) { $errors['sku'] = $e->getMessage(); }
    }
}
ui_header($p ? t('Edit part', 'Edit part') : t('Add part', 'Add part'), 'inventory');
page_header($p ? t('Edit part', 'Edit part') : t('Add spare part', 'Add spare part'), $p ? $p['name'] : t('Opening stock is recorded in the stock ledger', 'Opening stock is recorded in the stock ledger'));
?>
<div class="card w-md"><form method="post" data-validate novalidate><?= csrf_field() ?><div class="form-grid">
<?php form_input('sku', t('SKU', 'SKU'), $v['sku'], $errors, ['required' => true, 'maxlength' => 40]); form_input('name', t('Name', 'Name'), $v['name'], $errors, ['required' => true, 'maxlength' => 150]);
form_input('category', t('Category', 'Category'), $v['category'], $errors, ['maxlength' => 60]); form_input('unit', t('Unit', 'Unit'), $v['unit'], $errors, ['required' => true, 'maxlength' => 20]);
if (!$p) { form_input('quantity', t('Opening stock', 'Opening stock'), $v['quantity'], $errors, ['type' => 'number', 'min' => 0, 'max' => 2147483647]); } else { echo '<div class="field"><label>' . e(t('Current stock', 'Current stock')) . '</label><div class="input"><strong>' . (int) $p['quantity'] . '</strong> ' . e(t('(change via Stock in / adjust)', '(change via Stock in / adjust)')) . '</div></div>'; }
form_input('reorder_level', t('Reorder level', 'Reorder level'), $v['reorder_level'], $errors, ['type' => 'number', 'min' => 0, 'max' => 2147483647, 'required' => true]); form_input('unit_cost', t('Unit cost', 'Unit cost'), $v['unit_cost'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => '9999999999.99', 'required' => true]);
form_input('unit_price', t('Selling price', 'Selling price'), $v['unit_price'], $errors, ['type' => 'number', 'step' => '0.01', 'min' => 0, 'max' => '9999999999.99', 'required' => true]); form_input('supplier', t('Supplier', 'Supplier'), $v['supplier'], $errors, ['maxlength' => 120]); ?></div>
<div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('Save part', 'Save part')) ?></button><a class="btn btn-ghost" href="<?= e(url('inventory/')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a>
<?php if ($p): ?>
  <?= post_button(url('inventory/toggle.php'), ['id' => $id, 'action' => $p['is_active'] ? 'deactivate' : 'activate'], $p['is_active'] ? t('Deactivate', 'Deactivate') : t('Reactivate', 'Reactivate'), 'btn btn-ghost', $p['is_active'] ? t('Deactivate this part? It can no longer be requested or issued.', 'Deactivate this part? It can no longer be requested or issued.') : t('Reactivate this part?', 'Reactivate this part?')) ?>
  <?= post_button(url('inventory/delete.php'), ['id' => $id], t('Remove Part', 'Remove Part'), 'btn btn-danger', t('Permanently remove this spare part? This action cannot be undone.', 'Permanently remove this spare part? This action cannot be undone.')) ?>
<?php endif; ?></div></form></div>
<?php ui_footer();
