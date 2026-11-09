<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('inventory.stock');

$parts = Database::all('SELECT id, sku, name, quantity, unit, unit_cost FROM spare_parts WHERE is_active = 1 ORDER BY name LIMIT 3000');
$opts = ['' => '— Select part —']; foreach ($parts as $p) { $opts[$p['id']] = $p['name'] . ' (' . $p['sku'] . ') — ' . $p['quantity'] . ' ' . $p['unit']; }

$suppliers = Database::all('SELECT name, code FROM suppliers WHERE is_active = 1 ORDER BY name ASC');
$supOpts = ['' => '— Select registered supplier (or type below) —'];
foreach ($suppliers as $s) { $supOpts[$s['name']] = $s['name'] . ' (' . $s['code'] . ')'; }

$errors = []; $mode = get('mode', 'purchase') === 'adjust' ? 'adjust' : 'purchase';
if (is_post()) {
    $mode = post('mode') === 'adjust' ? 'adjust' : 'purchase';
    $pid = post_int('part_id');
    try {
        if (!isset($opts[$pid])) { throw new DomainException('Choose a part.'); }
        if ($mode === 'purchase') {
            $e = validate($_POST, ['quantity' => 'required|int|min_val:1|max_val:100000', 'unit_cost' => 'required|numeric|max_val:9999999999.99', 'supplier' => 'max:120', 'reference' => 'max:80', 'notes' => 'max:255']);
            if ($e) { throw new DomainException(reset($e)); }
            $sup = post('supplier_select') ?: post('supplier');
            InventoryService::purchase($pid, post_int('quantity'), post('unit_cost'), $sup, post('reference'), post('notes'));
            flash('success', 'Stock purchase recorded.');
        } else {
            $e = validate($_POST, ['quantity' => 'required|int|min_val:1|max_val:100000', 'direction' => 'required|in:in,out', 'reason' => 'required|max:255']);
            if ($e) { throw new DomainException(reset($e)); }
            InventoryService::adjust($pid, post('direction'), post_int('quantity'), post('reason'));
            flash('success', 'Stock adjusted.');
        }
        redirect('inventory/history.php?part_id=' . $pid);
    } catch (DomainException $e) { flash('error', $e->getMessage()); }
}
ui_header('Stock in / adjust', 'inventory');
page_header('Stock in & adjustments', 'Every change is written to the stock ledger and the audit log');
?>
<div class="tabs"><a class="tab<?= $mode === 'purchase' ? ' active' : '' ?>" href="?mode=purchase">Record purchase (stock-in)</a><a class="tab<?= $mode === 'adjust' ? ' active' : '' ?>" href="?mode=adjust">Stock adjustment (stock-out / correction)</a></div>
<div class="card w-md"><form method="post" data-validate novalidate><?= csrf_field() ?><input type="hidden" name="mode" value="<?= e($mode) ?>"><div class="form-grid">
<div class="full"><?php form_select('part_id', 'Part', $opts, post('part_id'), [], ['required' => true]); ?></div>
<?php form_input('quantity', 'Quantity', post('quantity'), [], ['type' => 'number', 'min' => 1, 'required' => true]);
if ($mode === 'purchase') {
    form_input('unit_cost', 'Unit cost', post('unit_cost'), [], ['type' => 'number', 'step' => '0.01', 'min' => 0, 'required' => true]);
    form_select('supplier_select', 'Registered Supplier', $supOpts, post('supplier_select'));
    form_input('supplier', 'Or Other Supplier Name', post('supplier'), [], ['maxlength' => 120]);
    form_input('reference', 'Invoice / PO reference', post('reference'), [], ['maxlength' => 80]);
    echo '<div class="full">'; form_input('notes', 'Notes', post('notes'), [], ['maxlength' => 255]); echo '</div>';
} else {
    form_select('direction', 'Direction', ['out' => 'Remove from stock (damage, loss, return to supplier)', 'in' => 'Add to stock (count correction)'], post('direction', 'out'));
    echo '<div class="full">'; form_input('reason', 'Reason (required)', post('reason'), [], ['required' => true, 'maxlength' => 255]); echo '</div>';
} ?></div>
<button class="btn btn-primary" type="submit">Save</button></form></div>
<?php ui_footer();
