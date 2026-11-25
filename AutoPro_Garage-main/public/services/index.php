<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('services.view');

$services = CatalogService::all();
$canManage = can('services.manage');

ui_header('Service Catalog', 'services');
page_header(
    'Garage Services',
    'Standard garage service catalog with pricing and estimated completion time',
    $canManage ? '<a class="btn btn-primary" href="' . e(url('services/form.php')) . '">' . e(t('+ New service', '+ New service')) . '</a>' : ''
);
?>

<div class="card card-flat">
  <div class="filters">
    <div class="field grow">
      <label for="srv-filter"><?= e(t('Filter services', 'Filter services')) ?></label>
      <input type="search" id="srv-filter" class="input" placeholder="<?= e(t('Type service name, category or code…', 'Type service name, category or code…')) ?>" data-table-filter="srv-table" autocomplete="off">
    </div>
  </div>
</div>

<div class="table-wrap">
  <table class="table stack" id="srv-table">
    <thead>
      <tr>
        <th><?= e(t('Code', 'Code')) ?></th>
        <th><?= e(t('Service Name', 'Service Name')) ?></th>
        <th><?= e(t('Category', 'Category')) ?></th>
        <th><?= e(t('Est. Hours', 'Est. Hours')) ?></th>
        <th class="num"><?= e(t('Price', 'Price')) ?> (<?= e(Setting::get('currency')) ?>)</th>
        <th><?= e(t('Status', 'Status')) ?></th>
        <?php if ($canManage): ?><th><?= e(t('Actions', 'Actions')) ?></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($services)): ?>
        <tr><td colspan="<?= $canManage ? 7 : 6 ?>" class="empty"><?= e(t('No services defined in the catalog yet.', 'No services defined in the catalog yet.')) ?></td></tr>
      <?php else: foreach ($services as $s): ?>
        <tr>
          <td><code><?= e($s['code']) ?></code></td>
          <td>
            <strong><?= e($s['name']) ?></strong>
            <?php if (!empty($s['description'])): ?><br><small class="muted"><?= e($s['description']) ?></small><?php endif; ?>
          </td>
          <td><span class="badge"><?= e(t(CatalogService::CATEGORIES[$s['category']] ?? $s['category'])) ?></span></td>
          <td><?= e(number_format((float)$s['estimated_hours'], 2)) ?> hrs</td>
          <td class="num font-mono"><strong><?= money($s['standard_price']) ?></strong></td>
          <td><?= badge($s['is_active'] ? 'active' : 'inactive') ?></td>
          <?php if ($canManage): ?>
            <td>
              <a class="btn btn-ghost btn-sm" href="<?= e(url('services/form.php?id=' . $s['id'])) ?>"><?= e(t('Edit', 'Edit')) ?></a>
              <?= post_button(url('services/toggle.php'), ['id' => $s['id']], $s['is_active'] ? 'Deactivate' : 'Activate', 'btn btn-ghost btn-sm', $s['is_active'] ? 'Deactivate this service?' : 'Reactivate this service?') ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php ui_footer();

