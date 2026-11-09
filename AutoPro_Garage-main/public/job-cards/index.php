<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login();
require_permission('jobs.view_all', 'jobs.view_assigned');

$q = get('q'); $status = get('status'); $mech = get_int('mechanic_id'); $scope = get('scope');
$from = get('from'); $to = get('to');
$where = ['1=1']; $params = [];
if (!can('jobs.view_all')) {                       // mechanics are limited to their own jobs, enforced in SQL
    $where[] = 'j.mechanic_id = ?'; $params[] = (int) ($user['mechanic_id'] ?? 0);
}
if ($q !== '') {
    $where[] = '(j.job_number LIKE ? OR v.plate_number LIKE ? OR c.full_name LIKE ? OR j.reported_problem LIKE ?)';
    $like = like_escape($q); array_push($params, $like, $like, $like, $like);
}
if (in_array($status, JobService::STATUSES, true)) { $where[] = 'j.status = ?'; $params[] = $status; }
if ($scope === 'open') { $where[] = "j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')"; }
if ($mech && can('jobs.view_all')) { $where[] = 'j.mechanic_id = ?'; $params[] = $mech; }
if (!validate(['d' => $from], ['d' => 'date'])) { if ($from !== '') { $where[] = 'j.date_received >= ?'; $params[] = $from; } }
if (!validate(['d' => $to], ['d' => 'date'])) { if ($to !== '') { $where[] = 'j.date_received <= ?'; $params[] = $to; } }
$w = implode(' AND ', $where);
$base = 'FROM job_cards j JOIN customers c ON c.id = j.customer_id JOIN vehicles v ON v.id = j.vehicle_id LEFT JOIN mechanics m ON m.id = j.mechanic_id';
$pg = paginate((int) Database::value("SELECT COUNT(*) $base WHERE $w", $params), $GLOBALS['config']['per_page'], get_int('page') ?: 1);
$rows = Database::all("SELECT j.id, j.job_number, j.date_received, j.estimated_completion, j.status, j.reported_problem, c.full_name AS customer, v.plate_number, v.make, v.model, m.full_name AS mechanic
    $base WHERE $w ORDER BY j.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
$mechanics = can('jobs.view_all') ? Database::all('SELECT id, full_name FROM mechanics WHERE is_active = 1 ORDER BY full_name') : [];
$mineOnly = !can('jobs.view_all');

ui_header($mineOnly ? 'My jobs' : 'Job cards', 'jobs');
page_header($mineOnly ? 'My assigned jobs' : 'Job cards', $mineOnly ? 'Only jobs assigned to you are shown' : 'Digital repair job cards', can('jobs.create') ? '<a class="btn btn-primary" href="' . e(url('job-cards/form.php')) . '">+ New job card</a>' : '');
?>
<form class="filters" method="get">
  <div class="field grow"><label for="q">Search</label><input class="input" id="q" name="q" value="<?= e($q) ?>" placeholder="Job no., plate, customer or problem"></div>
  <div class="field"><label for="status">Status</label><select class="input" id="status" name="status" data-autosubmit><option value="">All statuses</option><?php foreach (JobService::STATUSES as $s): ?><option<?= $status === $s ? ' selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
  <?php if ($mechanics): ?><div class="field"><label for="mechanic_id">Mechanic</label><select class="input" id="mechanic_id" name="mechanic_id" data-autosubmit><option value="">All mechanics</option><?php foreach ($mechanics as $m): ?><option value="<?= (int) $m['id'] ?>"<?= $mech === (int) $m['id'] ? ' selected' : '' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  <div class="field"><label for="from">Received from</label><input class="input" type="date" id="from" name="from" value="<?= e($from) ?>"></div>
  <div class="field"><label for="to">to</label><input class="input" type="date" id="to" name="to" value="<?= e($to) ?>"></div>
  <?php if ($scope): ?><input type="hidden" name="scope" value="<?= e($scope) ?>"><?php endif; ?>
  <button class="btn btn-primary" type="submit">Filter</button><a class="btn btn-ghost" href="<?= e(url('job-cards/')) ?>">Reset</a>
</form>
<div class="table-wrap"><table class="table stack"><thead><tr><th>Job card</th><th>Received</th><th>Customer / vehicle</th><th>Problem</th><th>Mechanic</th><th>Est. completion</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $j): ?>
  <tr><td><a href="<?= e(url('job-cards/view.php?id=' . $j['id'])) ?>"><strong><?= e($j['job_number']) ?></strong></a></td><td class="nowrap"><?= e(fmt_date($j['date_received'])) ?></td>
  <td><?= e($j['customer']) ?><div class="muted small"><?= e($j['plate_number'] . ' · ' . $j['make'] . ' ' . $j['model']) ?></div></td>
  <td><?= e(mb_strimwidth($j['reported_problem'], 0, 70, '…')) ?></td><td><?= e($j['mechanic'] ?: 'Unassigned') ?></td><td class="nowrap"><?= e(fmt_date($j['estimated_completion'])) ?></td><td><?= badge($j['status']) ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="empty">No job cards match.</td></tr><?php endif; ?>
</tbody></table></div>
<?php pagination_links($pg, ['q' => $q, 'status' => $status, 'mechanic_id' => $mech ?: '', 'scope' => $scope, 'from' => $from, 'to' => $to]); ui_footer();
