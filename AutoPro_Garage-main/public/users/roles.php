<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
require_login();
require_permission('roles.manage');

$roles = Database::all('SELECT * FROM roles ORDER BY id');
$rid = get_int('role_id') ?: post_int('role_id') ?: (int) $roles[0]['id'];
$role = null; foreach ($roles as $r) { if ((int) $r['id'] === $rid) { $role = $r; } }
if (!$role) { abort(404, 'Role not found.'); }
$perms = Database::all('SELECT * FROM permissions ORDER BY module, code');
$current = array_column(Database::all('SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?', [$rid]), 'code');
$locked = $role['name'] === 'administrator';

if (is_post()) {
    if ($locked) { flash('error', 'The Administrator role always keeps every permission.'); redirect('users/roles.php?role_id=' . $rid); }
    $valid = array_column($perms, 'code');
    $new = array_values(array_intersect($valid, (array) ($_POST['perm'] ?? [])));
    Database::transaction(function () use ($rid, $current, $new) {
        Database::run('DELETE FROM role_permissions WHERE role_id = ?', [$rid]);
        foreach ($new as $code) { Database::run('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE code = ?', [$rid, $code]); }
        AuditLogger::log('role_permissions_changed', 'roles', $rid, ['permissions' => $current], ['permissions' => $new, 'added' => array_values(array_diff($new, $current)), 'removed' => array_values(array_diff($current, $new))]);
    });
    flash('success', 'Permissions saved. They apply to users of this role immediately.');
    redirect('users/roles.php?role_id=' . $rid);
}
$byModule = []; foreach ($perms as $p) { $byModule[$p['module']][] = $p; }
ui_header('Roles & access', 'roles');
page_header('Roles & permissions', 'Permissions are enforced on the server for every request');
?>
<div class="tabs"><?php foreach ($roles as $r): ?><a class="tab<?= (int) $r['id'] === $rid ? ' active' : '' ?>" href="?role_id=<?= (int) $r['id'] ?>"><?= e($r['label']) ?></a><?php endforeach; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="role_id" value="<?= $rid ?>">
<?php if ($locked): ?><div class="alert alert-info">The Administrator role is locked so the system can never be left without an administrator.</div><?php endif; ?>
<?php foreach ($byModule as $mod => $list): ?><div class="card"><h3><?= e($mod) ?></h3><div class="perm-grid"><?php foreach ($list as $p): ?>
<label class="check"><input type="checkbox" name="perm[]" value="<?= e($p['code']) ?>" <?= in_array($p['code'], $current, true) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>> <span><?= e($p['label']) ?> <span class="muted small mono"><?= e($p['code']) ?></span></span></label><?php endforeach; ?></div></div><?php endforeach; ?>
<?php if (!$locked): ?><button class="btn btn-primary" type="submit">Save permissions</button><?php endif; ?></form>
<?php ui_footer();
