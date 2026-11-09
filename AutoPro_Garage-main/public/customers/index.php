<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('customers.view');

$q = get('q'); $status = get('status', 'active');
$where = ['1=1']; $params = [];
if ($q !== '') {
    $where[] = '(c.full_name LIKE ? OR c.phone LIKE ? OR c.email LIKE ? OR CONCAT("CUS-", LPAD(c.id,5,"0")) LIKE ?)';
    $like = like_escape($q); array_push($params, $like, $like, $like, $like);
}
if ($status === 'active') { $where[] = 'c.is_active = 1'; } elseif ($status === 'inactive') { $where[] = 'c.is_active = 0'; }
$w = implode(' AND ', $where);
$pg = paginate((int) Database::value("SELECT COUNT(*) FROM customers c WHERE $w", $params), $GLOBALS['config']['per_page'], get_int('page') ?: 1);
$rows = Database::all("SELECT c.*, (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id = c.id) AS vehicles,
        (SELECT COUNT(*) FROM job_cards j WHERE j.customer_id = c.id) AS jobs
    FROM customers c WHERE $w ORDER BY c.full_name ASC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);

ui_header('Customers', 'customers');
page_header('Customers', 'Registered customers and their contact details',
    can('customers.manage') ? '<a class="btn btn-primary" href="' . e(url('customers/form.php')) . '">+ Add customer</a>' : '');
?>
<form class="filters" method="get">
  <div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Name, phone, email or customer ID"></div>
  <div class="field"><label for="status">Status</label>
    <select class="input" id="status" name="status" data-autosubmit><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $k => $l): ?><option value="<?= e($k) ?>"<?= $status === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-primary" type="submit">Search</button>
  <?php if ($q !== '' || $status !== 'active'): ?><a class="btn btn-ghost" href="<?= e(url('customers/')) ?>">Reset</a><?php endif; ?>
</form>
<div class="table-wrap"><table class="table stack">
  <thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Email</th><th class="num">Vehicles</th><th class="num">Jobs</th><th>Registered</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $c): ?>
    <tr><td class="mono">CUS-<?= str_pad((string) $c['id'], 5, '0', STR_PAD_LEFT) ?></td>
      <td><a href="<?= e(url('customers/view.php?id=' . $c['id'])) ?>"><strong><?= e($c['full_name']) ?></strong></a></td>
      <td class="nowrap"><?= e($c['phone']) ?></td><td><?= e($c['email'] ?: '—') ?></td>
      <td class="num"><?= (int) $c['vehicles'] ?></td><td class="num"><?= (int) $c['jobs'] ?></td>
      <td class="nowrap"><?= e(fmt_date($c['registered_at'])) ?></td>
      <td><?= $c['is_active'] ? badge('Active') : badge('Inactive') ?></td>
      <td class="nowrap"><a class="btn btn-ghost btn-sm" href="<?= e(url('customers/view.php?id=' . $c['id'])) ?>">View</a>
        <?php if (can('customers.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('customers/form.php?id=' . $c['id'])) ?>">Edit</a><?php endif; ?></td></tr>
  <?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="9" class="empty">No customers found.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'status' => $status]); ui_footer();
