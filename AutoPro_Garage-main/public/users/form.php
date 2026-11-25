<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$me = require_login();
require_permission('users.manage');

$id = get_int('id');
$u = $id ? Database::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
if ($id && !$u) { abort(404, 'User not found.'); }
$roles = Database::all('SELECT id, name, label FROM roles ORDER BY id');
$roleOpts = []; foreach ($roles as $r) { $roleOpts[$r['id']] = $r['label']; }
$v = $u ? ['full_name' => $u['full_name'], 'username' => $u['username'], 'email' => $u['email'], 'role_id' => $u['role_id'], 'is_active' => $u['is_active']]
        : ['full_name' => '', 'username' => '', 'email' => '', 'role_id' => '', 'is_active' => 1];
$errors = [];
if (is_post()) {
    $v = ['full_name' => post('full_name'), 'username' => $u ? $u['username'] : post('username'), 'email' => post('email'), 'role_id' => post('role_id'), 'is_active' => isset($_POST['is_active']) ? 1 : 0];
    $pw = (string) ($_POST['password'] ?? '');
    $errors = validate($v, ['full_name' => 'required|min:2|max:120', 'username' => 'required|min:3|max:50', 'email' => 'required|email|max:150', 'role_id' => 'required|int']);
    if (!isset($roleOpts[(int) $v['role_id']])) { $errors['role_id'] = 'Choose a role.'; }
    if (!$u && !preg_match('/^[A-Za-z0-9_.-]+$/', $v['username'])) { $errors['username'] = 'Letters, digits, dot, dash, underscore only.'; }
    if (!$errors && Database::value('SELECT id FROM users WHERE username = ? AND id <> ?', [$v['username'], $id])) { $errors['username'] = 'Username already taken.'; }
    if (!$errors && Database::value('SELECT id FROM users WHERE email = ? AND id <> ?', [$v['email'], $id])) { $errors['email'] = 'Email already used.'; }
    if (!$u || $pw !== '') { if ($p = AuthService::passwordProblems($pw, $v['username'])) { $errors['password'] = 'Password needs: ' . implode(', ', $p) . '.'; } }
    if ($u && (int) $u['id'] === (int) $me['id'] && (!$v['is_active'] || (int) $v['role_id'] !== (int) $u['role_id'])) { $errors['is_active'] = 'You cannot deactivate or change the role of your own account.'; }
    if (!$errors && $u) {   // never remove the last active administrator
        $adminId = (int) Database::value("SELECT id FROM roles WHERE name = 'administrator'");
        $isAdmin = (int) $u['role_id'] === $adminId && $u['is_active'];
        if ($isAdmin && ((int) $v['role_id'] !== $adminId || !$v['is_active']) && (int) Database::value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_active = 1', [$adminId]) <= 1) { $errors['role_id'] = 'At least one active administrator is required.'; }
    }
    if (!$errors) {
        $newId = Database::transaction(function () use ($u, $id, $v, $pw, $roles) {
            $roleName = '';
            foreach ($roles as $r) { if ((int) $r['id'] === (int) $v['role_id']) { $roleName = $r['name']; } }
            if ($u) {
                Database::run('UPDATE users SET full_name=?, email=?, role_id=?, is_active=? WHERE id=?', [$v['full_name'], $v['email'], $v['role_id'], $v['is_active'], $id]);
                if (strtolower(trim($v['email'])) !== strtolower(trim($u['email']))) {
                    CustomerIdentityService::invalidateTokensForUser((int) $id);
                }
                if ($pw !== '') { Database::run('UPDATE users SET password_hash=?, must_change_password=1 WHERE id=?', [AuthService::hash($pw), $id]); }
                $act = ((int) $u['role_id'] !== (int) $v['role_id']) ? 'user_role_changed' : ($u['is_active'] != $v['is_active'] ? ($v['is_active'] ? 'user_activated' : 'user_deactivated') : 'user_updated');
                AuditLogger::log($act, 'users', $id, ['full_name' => $u['full_name'], 'email' => $u['email'], 'role_id' => $u['role_id'], 'is_active' => $u['is_active']],
                    ['full_name' => $v['full_name'], 'email' => $v['email'], 'role_id' => $v['role_id'], 'is_active' => $v['is_active'], 'password_reset' => $pw !== '']);
                $uid = $id;
            } else {
                $uid = Database::insert('INSERT INTO users (role_id, username, full_name, email, password_hash, must_change_password) VALUES (?,?,?,?,?,1)', [$v['role_id'], $v['username'], $v['full_name'], $v['email'], AuthService::hash($pw)]);
                AuditLogger::log('user_created', 'users', $uid, null, ['username' => $v['username'], 'full_name' => $v['full_name'], 'email' => $v['email'], 'role' => $roleName]);
            }
            // keep the mechanics table aligned with the Mechanic role
            $m = Database::one('SELECT id FROM mechanics WHERE user_id = ?', [$uid]);
            if ($roleName === 'mechanic' && $v['is_active']) {
                if ($m) { Database::run('UPDATE mechanics SET is_active = 1, full_name = ? WHERE id = ?', [$v['full_name'], $m['id']]); }
                else { $mid = Database::insert('INSERT INTO mechanics (user_id, full_name) VALUES (?,?)', [$uid, $v['full_name']]); AuditLogger::log('mechanic_created', 'mechanics', $mid, null, ['user_id' => $uid]); }
            } elseif ($m) { Database::run('UPDATE mechanics SET is_active = 0 WHERE id = ?', [$m['id']]); }
            return $uid;
        });
        flash('success', $u ? 'User updated.' : 'User created. They must change the password at first sign-in.');
        redirect('users/');
    }
}
ui_header($u ? 'Edit user' : 'Add user', 'users');
page_header($u ? 'Edit user' : 'Add user', $u ? $u['username'] : 'The initial password must be changed at first login');
?>
<div class="card w-md"><form method="post" data-validate novalidate autocomplete="off"><?= csrf_field() ?><div class="form-grid">
<?php form_input('full_name', 'Full name', $v['full_name'], $errors, ['required' => true, 'maxlength' => 120]);
if ($u) { echo '<div class="field"><label>' . e(t('Username', 'Username')) . '</label><div class="input">' . e($u['username']) . '</div></div>'; } else { form_input('username', 'Username', $v['username'], $errors, ['required' => true, 'minlength' => 3, 'maxlength' => 50, 'pattern' => '[A-Za-z0-9_.\-]+', 'title' => 'Letters, digits, dot, dash, underscore']); }
form_input('email', 'Email', $v['email'], $errors, ['type' => 'email', 'required' => true, 'maxlength' => 150]); form_select('role_id', 'Role', ['' => t('— Select role —', '— Select role —')] + $roleOpts, $v['role_id'], $errors, ['required' => true]);
form_input('password', $u ? 'Reset password (leave blank to keep)' : 'Initial password', '', $errors, ['type' => 'password', 'autocomplete' => 'new-password'] + ($u ? [] : ['required' => true]) + ['data-strength' => 'pw-hint']); ?>
<div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="is_active" value="1" <?= $v['is_active'] ? 'checked' : '' ?>> <?= e(t('Account active', 'Account active')) ?></label><div class="field-error"><?= e($errors['is_active'] ?? '') ?></div></div></div>
<p class="muted small" id="pw-hint"></p>
<div class="form-actions"><button class="btn btn-primary" type="submit"><?= e(t('Save user', 'Save user')) ?></button><a class="btn btn-ghost" href="<?= e(url('users/')) ?>"><?= e(t('Cancel', 'Cancel')) ?></a></div></form></div>
<?php ui_footer();
