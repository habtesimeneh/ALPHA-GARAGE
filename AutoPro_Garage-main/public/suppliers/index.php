<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('suppliers.view');

$suppliers = SupplierService::all();
$canManage = can('suppliers.manage');

ui_header('Suppliers', 'suppliers');
page_header(
    'Parts Suppliers',
    'Manage external spare parts vendors, purchase contacts, and catalogs',
    $canManage ? '<a class="btn btn-primary" href="' . e(url('suppliers/form.php')) . '">+ New supplier</a>' : ''
);
?>

<div class="card card-flat">
  <div class="filters">
    <div class="field grow">
      <label for="sup-filter">Filter suppliers</label>
      <input type="search" id="sup-filter" class="input" placeholder="Type supplier name, code, contact or phone…" data-table-filter="sup-table" autocomplete="off">
    </div>
  </div>
</div>

<div class="table-wrap">
  <table class="table stack" id="sup-table">
    <thead>
      <tr>
        <th>Code</th>
        <th>Supplier Name</th>
        <th>Contact Person</th>
        <th>Phone</th>
        <th>Email</th>
        <th>Parts Supplied</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($suppliers)): ?>
        <tr><td colspan="8" class="empty">No suppliers registered yet.</td></tr>
      <?php else: foreach ($suppliers as $s): ?>
        <tr>
          <td><code><?= e($s['code']) ?></code></td>
          <td><strong><a href="<?= e(url('suppliers/view.php?id=' . $s['id'])) ?>"><?= e($s['name']) ?></a></strong></td>
          <td><?= e($s['contact_person'] ?: '—') ?></td>
          <td><?= e($s['phone'] ?: '—') ?></td>
          <td><?= e($s['email'] ?: '—') ?></td>
          <td><span class="badge"><?= (int) $s['parts_count'] ?> parts</span></td>
          <td><?= badge($s['is_active'] ? 'active' : 'inactive') ?></td>
          <td>
            <a class="btn btn-ghost btn-sm" href="<?= e(url('suppliers/view.php?id=' . $s['id'])) ?>">View</a>
            <?php if ($canManage): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('suppliers/form.php?id=' . $s['id'])) ?>">Edit</a>
              <?= post_button(url('suppliers/toggle.php'), ['id' => $s['id']], $s['is_active'] ? 'Deactivate' : 'Activate', 'btn btn-ghost btn-sm', $s['is_active'] ? 'Deactivate this supplier?' : 'Reactivate this supplier?') ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php ui_footer();

