<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('mechanics.view');

$rows = Database::all("SELECT m.*, u.username,
    (SELECT COUNT(*) FROM job_cards j WHERE j.mechanic_id = m.id AND j.status IN ('Pending','Diagnosing','Waiting for Parts','In Progress')) AS active_jobs,
    (SELECT COUNT(*) FROM job_cards j WHERE j.mechanic_id = m.id AND j.status IN ('Completed','Delivered')) AS done_jobs
    FROM mechanics m LEFT JOIN users u ON u.id = m.user_id ORDER BY m.is_active DESC, m.full_name");
ui_header('Mechanics', 'mechanics');
page_header('Mechanics', 'Workshop staff, availability and workload. Mechanic accounts are created under Users.');
?>
<div class="table-wrap"><table class="table stack"><thead><tr><th>Name</th><th>Specialty</th><th>Phone</th><th>Login</th><th>Availability</th><th class="num">Active jobs</th><th class="num">Completed</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $m): ?>
<tr><td><strong><?= e($m['full_name']) ?></strong></td><td><?= e($m['specialty'] ?: '—') ?></td><td><?= e($m['phone'] ?: '—') ?></td><td><?= e($m['username'] ?: '—') ?></td>
<td><?= badge(ucwords(str_replace('_', ' ', $m['availability']))) ?></td><td class="num"><a href="<?= e(url('job-cards/?mechanic_id=' . $m['id'] . '&scope=open')) ?>"><?= (int) $m['active_jobs'] ?></a></td><td class="num"><?= (int) $m['done_jobs'] ?></td>
<td><?= $m['is_active'] ? badge('Active') : badge('Inactive') ?></td><td><?php if (can('mechanics.manage')): ?><a class="btn btn-ghost btn-sm" href="<?= e(url('mechanics/form.php?id=' . $m['id'])) ?>">Edit</a><?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9" class="empty">No mechanics yet. Create a user with the Mechanic role.</td></tr><?php endif; ?>
</tbody></table></div>
<?php ui_footer();
