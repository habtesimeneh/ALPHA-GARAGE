<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('vehicles.view');

$q = get('q'); $status = get('status', 'active'); $cid = get_int('customer_id');
$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = '(v.plate_number LIKE ? OR v.make LIKE ? OR v.model LIKE ? OR v.vin LIKE ? OR c.full_name LIKE ?)';
    $like = like_escape($q); array_push($params, $like, $like, $like, $like, $like);
}
if ($cid) { $where[] = 'v.customer_id = ?'; $params[] = $cid; }
if ($status === 'active') { $where[] = 'v.is_active = 1'; } elseif ($status === 'inactive') { $where[] = 'v.is_active = 0'; }
$w = implode(' AND ', $where);
$pg = paginate((int) Database::value("SELECT COUNT(*) FROM vehicles v JOIN customers c ON c.id = v.customer_id WHERE $w", $params), $GLOBALS['config']['per_page'], get_int('page') ?: 1);
$rows = Database::all("SELECT v.*, c.full_name AS owner FROM vehicles v JOIN customers c ON c.id = v.customer_id WHERE $w ORDER BY v.plate_number LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);

ui_header('Vehicles', 'vehicles');
page_header('Vehicles', 'All registered vehicles', can('vehicles.manage') ? '<a class="btn btn-primary" href="' . e(url('vehicles/form.php')) . '">+ Register vehicle</a>' : '');
?>
<form class="filters" method="get">
  <div class="field grow"><label for="q">Search by plate, make, model, VIN or owner</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="e.g. AA-12345"></div>
  <div class="field"><label for="status">Status</label><select class="input" id="status" name="status" data-autosubmit><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <?php if ($cid): ?><input type="hidden" name="customer_id" value="<?= $cid ?>"><?php endif; ?>
  <button class="btn btn-primary" type="submit">Search</button>
</form>
<div class="table-wrap"><table class="table stack"><thead><tr><th>Plate</th><th>Vehicle</th><th>Owner</th><th>Year</th><th>Fuel</th><th class="num">Mileage</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $v): ?>
  <tr><td><a href="<?= e(url('vehicles/view.php?id=' . $v['id'])) ?>"><strong><?= e($v['plate_number']) ?></strong></a></td>
  <td><?= e($v['make'] . ' ' . $v['model']) ?><div class="muted small"><?= e($v['color'] ?: '') ?></div></td>
  <td><?= can('customers.view') ? '<a href="' . e(url('customers/view.php?id=' . $v['customer_id'])) . '">' . e($v['owner']) . '</a>' : e($v['owner']) ?></td>
  <td><?= e($v['year'] ?: '—') ?></td><td><?= e($v['fuel_type']) ?></td><td class="num"><?= e(number_format((int) $v['mileage'])) ?> km</td>
  <td><?= $v['is_active'] ? badge('Active') : badge('Inactive') ?></td>
  <td class="nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('vehicles/view.php?id=' . $v['id'])) ?>">View</a><?php if (can('vehicles.manage')): ?> <a class="btn btn-ghost btn-sm" href="<?= e(url('vehicles/form.php?id=' . $v['id'])) ?>">Edit</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="empty">No vehicles found.</td></tr><?php endif; ?>
</tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'status' => $status, 'customer_id' => $cid ?: '']); ui_footer();
