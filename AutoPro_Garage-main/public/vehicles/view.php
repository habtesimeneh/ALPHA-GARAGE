<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('vehicles.view');

$id = get_int('id');
$v = Database::one('SELECT v.*, c.full_name AS owner, c.phone AS owner_phone FROM vehicles v JOIN customers c ON c.id = v.customer_id WHERE v.id = ?', [$id]);
if (!$v) { abort(404, 'Vehicle not found.'); }
$user = current_user();
// Repair history respects job visibility: mechanics only see jobs assigned to them.
$jobSql = 'SELECT j.id, j.job_number, j.date_received, j.status, j.reported_problem, j.labor_cost, m.full_name AS mechanic FROM job_cards j LEFT JOIN mechanics m ON m.id = j.mechanic_id WHERE j.vehicle_id = ?';
$jobParams = [$id];
if (can('jobs.view_all')) { /* all */ }
elseif (can('jobs.view_assigned') && $user['mechanic_id']) { $jobSql .= ' AND j.mechanic_id = ?'; $jobParams[] = $user['mechanic_id']; }
else { $jobSql .= ' AND 1=0'; }
$jobs = Database::all($jobSql . ' ORDER BY j.id DESC', $jobParams);

$actions = '';
if (can('vehicles.manage')) {
    $actions .= '<a class="btn btn-ghost" href="' . e(url('vehicles/form.php?id=' . $id)) . '">' . e(t('Edit', 'Edit')) . '</a>';
    $actions .= post_button(url('vehicles/toggle.php'), ['id' => $id, 'action' => $v['is_active'] ? 'deactivate' : 'activate'], $v['is_active'] ? 'Deactivate' : 'Reactivate',
        $v['is_active'] ? 'btn btn-danger' : 'btn btn-ghost', $v['is_active'] ? 'Deactivate this vehicle? Its repair history is kept.' : '');
}
if (can('jobs.create') && $v['is_active']) { $actions .= '<a class="btn btn-primary" href="' . e(url('job-cards/form.php?customer_id=' . $v['customer_id'] . '&vehicle_id=' . $id)) . '">' . e(t('+ Job card', '+ Job card')) . '</a>'; }
ui_header($v['plate_number'], 'vehicles');
page_header($v['plate_number'], $v['make'] . ' ' . $v['model'] . ($v['year'] ? ' · ' . $v['year'] : ''), $actions);
?>
<div class="grid grid-2">
  <div class="card"><h3><?= e(t('Vehicle Details', 'Vehicle Details')) ?></h3><dl class="kv"><dt><?= e(t('Plate', 'Plate')) ?></dt><dd><?= e($v['plate_number']) ?></dd><dt><?= e(t('Make / model', 'Make / model')) ?></dt><dd><?= e($v['make'] . ' ' . $v['model']) ?></dd><dt><?= e(t('Type', 'Type')) ?></dt><dd><?= e(t($v['vehicle_type'] ?? 'Sedan', $v['vehicle_type'] ?? 'Sedan')) ?></dd><dt><?= e(t('Year', 'Year')) ?></dt><dd><?= e($v['year'] ?: '—') ?></dd><dt><?= e(t('Color', 'Color')) ?></dt><dd><?= e($v['color'] ?: '—') ?></dd><dt><?= e(t('VIN', 'VIN')) ?></dt><dd class="mono"><?= e($v['vin'] ?: '—') ?></dd><dt><?= e(t('Fuel', 'Fuel')) ?></dt><dd><?= e(t($v['fuel_type'], $v['fuel_type'])) ?></dd><dt><?= e(t('Mileage', 'Mileage')) ?></dt><dd><?= e(number_format((int) $v['mileage'])) ?> km</dd><dt><?= e(t('Status', 'Status')) ?></dt><dd><?= $v['is_active'] ? badge('Active') : badge('Inactive') ?></dd><?php if (!empty($v['notes'])): ?><dt><?= e(t('Notes', 'Notes')) ?></dt><dd><?= nl2br(e($v['notes'])) ?></dd><?php endif; ?></dl></div>
  <div class="card"><h3><?= e(t('Owner', 'Owner')) ?></h3><dl class="kv"><dt><?= e(t('Name', 'Name')) ?></dt><dd><?= can('customers.view') ? '<a href="' . e(url('customers/view.php?id=' . $v['customer_id'])) . '">' . e($v['owner']) . '</a>' : e($v['owner']) ?></dd><?php if (can('customers.view')): ?><dt><?= e(t('Phone', 'Phone')) ?></dt><dd><?= e($v['owner_phone']) ?></dd><?php endif; ?></dl></div>
</div>
<div class="card"><div class="card-head"><h3><?= e(t('Repair history', 'Repair history')) ?></h3></div>
<div class="table-wrap"><table class="table stack"><thead><tr><th><?= e(t('Job card', 'Job card')) ?></th><th><?= e(t('Received', 'Received')) ?></th><th><?= e(t('Problem', 'Problem')) ?></th><th><?= e(t('Mechanic', 'Mechanic')) ?></th><th><?= e(t('Status', 'Status')) ?></th></tr></thead><tbody>
<?php foreach ($jobs as $j): ?><tr><td><a href="<?= e(url('job-cards/view.php?id=' . $j['id'])) ?>"><strong><?= e($j['job_number']) ?></strong></a></td><td><?= e(fmt_date($j['date_received'])) ?></td><td><?= e(mb_strimwidth($j['reported_problem'], 0, 90, '…')) ?></td><td><?= e($j['mechanic'] ?: t('Unassigned', 'Unassigned')) ?></td><td><?= badge($j['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$jobs): ?><tr><td colspan="5" class="empty"><?= e(t('No repairs recorded for this vehicle.', 'No repairs recorded for this vehicle.')) ?></td></tr><?php endif; ?>
</tbody></table></div></div>
<?php ui_footer();
