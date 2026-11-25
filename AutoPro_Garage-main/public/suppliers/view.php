<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('suppliers.view');

$id = get_int('id');
$supplier = $id ? SupplierService::find($id) : null;
if (!$supplier) { abort(404, 'Supplier not found.'); }

$canManage = can('suppliers.manage');

// Parts linked to this supplier
$parts = Database::all('SELECT * FROM spare_parts WHERE supplier_id = ? OR supplier = ? ORDER BY name ASC', [$id, $supplier['name']]);

// Recent purchase history
$purchases = Database::all(
    'SELECT it.*, sp.name AS part_name, sp.sku
     FROM inventory_transactions it
     JOIN spare_parts sp ON sp.id = it.part_id
     WHERE it.txn_type = "purchase" AND (it.supplier = ? OR it.supplier = ?)
     ORDER BY it.id DESC LIMIT 20',
    [$supplier['name'], $supplier['code']]
);

ui_header($supplier['name'], 'suppliers');
page_header(
    $supplier['name'],
    'Supplier Code: ' . $supplier['code'],
    $canManage ? '<a class="btn btn-primary" href="' . e(url('suppliers/form.php?id=' . $id)) . '">' . e(t('Edit Profile', 'Edit Profile')) . '</a>' : ''
);
?>

<div class="grid grid-2-1">
  <div>
    <div class="card">
      <div class="card-head"><h3><?= e(t('Vendor Profile', 'Vendor Profile')) ?></h3><?= badge($supplier['is_active'] ? 'active' : 'inactive') ?></div>
      <dl class="kv">
        <dt><?= e(t('Supplier Code', 'Supplier Code')) ?></dt><dd><code><?= e($supplier['code']) ?></code></dd>
        <dt><?= e(t('Company Name', 'Company Name')) ?></dt><dd><strong><?= e($supplier['name']) ?></strong></dd>
        <dt><?= e(t('Contact Person', 'Contact Person')) ?></dt><dd><?= e($supplier['contact_person'] ?: '—') ?></dd>
        <dt><?= e(t('Phone', 'Phone')) ?></dt><dd><?= e($supplier['phone'] ?: '—') ?></dd>
        <dt><?= e(t('Email', 'Email')) ?></dt><dd><?= e($supplier['email'] ?: '—') ?></dd>
        <dt><?= e(t('Address', 'Address')) ?></dt><dd><?= e($supplier['address'] ?: '—') ?></dd>
        <dt><?= e(t('Registered', 'Registered')) ?></dt><dd><?= fmt_datetime($supplier['created_at']) ?></dd>
      </dl>
    </div>

    <div class="card">
      <div class="card-head"><h3>Parts Catalog (<?= count($parts) ?>)</h3></div>
      <div class="table-wrap">
        <table class="table stack">
          <thead>
            <tr>
              <th>SKU</th>
              <th>Part Name</th>
              <th>Category</th>
              <th class="num">In Stock</th>
              <th class="num">Unit Cost</th>
              <th class="num">Unit Price</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($parts)): ?>
              <tr><td colspan="6" class="empty">No parts currently linked to this supplier.</td></tr>
            <?php else: foreach ($parts as $p): ?>
              <tr>
                <td><code><?= e($p['sku']) ?></code></td>
                <td><strong><a href="<?= e(url('inventory/history.php?part_id=' . $p['id'])) ?>"><?= e($p['name']) ?></a></strong></td>
                <td><span class="badge"><?= e($p['category'] ?: '—') ?></span></td>
                <td class="num font-mono"><?= (int) $p['quantity'] ?> <?= e($p['unit']) ?></td>
                <td class="num font-mono"><?= money($p['unit_cost']) ?></td>
                <td class="num font-mono"><?= money($p['unit_price']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-head"><h3>Recent Purchases (<?= count($purchases) ?>)</h3></div>
      <ul class="feed">
        <?php if (empty($purchases)): ?>
          <li class="empty feed-empty">No stock purchase records yet.</li>
        <?php else: foreach ($purchases as $pu): ?>
          <li>
            <span class="dot"></span>
            <div>
              <strong>+<?= (int) $pu['quantity'] ?> × <?= e($pu['part_name']) ?></strong><br>
              <small class="muted"><?= fmt_datetime($pu['created_at']) ?> · Ref: <?= e($pu['reference'] ?: 'None') ?></small>
            </div>
          </li>
        <?php endforeach; endif; ?>
      </ul>
    </div>
  </div>
</div>

<?php ui_footer();
