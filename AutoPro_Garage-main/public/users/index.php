<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('users.manage');
$rows = Database::all('SELECT u.*, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.is_active DESC, u.full_name');
ui_header('Users', 'users');
page_header('Users', 'Create accounts, assign roles, deactivate leavers', '<a class="btn btn-primary" href="' . e(url('users/form.php')) . '">' . e(t('+ Add user', '+ Add user')) . '</a>');
?>
<div class="table-wrap"><table class="table stack"><thead><tr><th><?= e(t('Name', 'Name')) ?></th><th><?= e(t('Username', 'Username')) ?></th><th><?= e(t('Email', 'Email')) ?></th><th><?= e(t('Role', 'Role')) ?></th><th><?= e(t('Last login', 'Last login')) ?></th><th><?= e(t('Status', 'Status')) ?></th><th></th></tr></thead><tbody>
<?php foreach ($rows as $u): ?><tr><td><strong><?= e($u['full_name']) ?></strong></td><td><?= e($u['username']) ?></td><td><?= e($u['email']) ?></td><td><?= e(t($u['role_label'], $u['role_label'])) ?></td><td class="nowrap"><?= e(fmt_datetime($u['last_login_at'])) ?></td><td><?= $u['is_active'] ? badge('Active') : badge('Inactive') ?></td><td><a class="btn btn-ghost btn-sm" href="<?= e(url('users/form.php?id=' . $u['id'])) ?>"><?= e(t('Edit', 'Edit')) ?></a></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php ui_footer();
