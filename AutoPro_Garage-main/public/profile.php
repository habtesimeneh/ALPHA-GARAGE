<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';
$user = require_login(true);

$errors = [];
if (is_post()) {
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $row = Database::one('SELECT password_hash FROM users WHERE id = ?', [$user['id']]);
    if (!password_verify($current, $row['password_hash'])) { $errors['current_password'] = 'Current password is incorrect.'; }
    if ($new !== (string) ($_POST['confirm_password'] ?? '')) { $errors['confirm_password'] = 'Passwords do not match.'; }
    if ($p = AuthService::passwordProblems($new, $user['username'])) { $errors['new_password'] = 'Password needs: ' . implode(', ', $p) . '.'; }
    if (!$errors && password_verify($new, $row['password_hash'])) { $errors['new_password'] = 'Choose a password you have not used before.'; }
    if (!$errors) {
        Database::transaction(function () use ($user, $new) {
            Database::run('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE id = ?', [AuthService::hash($new), $user['id']]);
            AuditLogger::log('password_changed', 'users', (int) $user['id'], null, ['by' => 'self']);   // never logs the password itself
        });
        session_regenerate_id(true);
        flash('success', 'Password updated.');
        redirect('dashboard.php');
    } else {
        AuditLogger::logSafe('password_change_failed', 'users', (int) $user['id'], null, ['fields' => array_keys($errors)], 'failure');
    }
}
ui_header('My profile', '');
page_header('My profile', 'Account details and password');
?>
<div class="grid grid-2">
  <div class="card"><h3>Account</h3>
    <dl class="kv"><dt>Name</dt><dd><?= e($user['full_name']) ?></dd><dt>Username</dt><dd><?= e($user['username']) ?></dd><dt>Email</dt><dd><?= e($user['email']) ?></dd><dt>Role</dt><dd><?= e($user['role_label']) ?></dd></dl>
  </div>
  <div class="card"><h3>Change password</h3>
    <?php if ($user['must_change_password']): ?><div class="alert alert-warn">You must choose a new password before you can continue.</div><?php endif; ?>
    <form method="post" data-validate autocomplete="off"><?= csrf_field() ?>
      <?php form_input('current_password', 'Current password', '', $errors, ['type' => 'password', 'required' => true, 'autocomplete' => 'current-password']); ?>
      <?php form_input('new_password', 'New password', '', $errors, ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password', 'data-strength' => 'pw-hint']); ?>
      <p class="muted small" id="pw-hint"></p>
      <?php form_input('confirm_password', 'Confirm new password', '', $errors, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'data-match' => '#f_new_password']); ?>
      <button class="btn btn-primary" type="submit">Update password</button>
    </form>
  </div>
</div>
<?php ui_footer();
