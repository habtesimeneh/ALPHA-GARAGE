<?php
declare(strict_types=1);
/**
 * One-time installer: creates the first administrator (and optionally demo data).
 * It locks itself: it refuses to run once any user exists or storage/installed.lock is present.
 * After setup, DELETE this file from public/ (the README explains why).
 */
require __DIR__ . '/../app/bootstrap.php';
require_once APP_ROOT . '/app/views/layout.php';

$lock = APP_ROOT . '/storage/installed.lock';
try {
    $hasUsers = (int) Database::value('SELECT COUNT(*) FROM users') > 0;
} catch (Throwable $e) {
    app_log('setup: ' . $e->getMessage());
    abort(500, 'Cannot reach the database. Configure app/config/local.php from local.php.example (or set the GMS_DB_* environment variables), start MySQL, and import database/database.sql into an empty database first.');
}
if ($hasUsers || is_file($lock)) {
    abort(403, 'Setup is already complete and has been locked. Delete public/setup.php.');
}

$errors = [];
$v = ['full_name' => '', 'username' => 'admin', 'email' => ''];
if (is_post()) {
    $v = ['full_name' => post('full_name'), 'username' => post('username'), 'email' => post('email')];
    $pw = (string) ($_POST['password'] ?? '');
    $errors = validate($v, ['full_name' => 'required|max:120', 'username' => 'required|min:3|max:50', 'email' => 'required|email|max:150']);
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $v['username'])) { $errors['username'] = 'Use letters, digits, dot, dash and underscore only.'; }
    if ($pw !== (string) ($_POST['password2'] ?? '')) { $errors['password2'] = 'Passwords do not match.'; }
    if ($problems = AuthService::passwordProblems($pw, $v['username'])) { $errors['password'] = 'Password needs: ' . implode(', ', $problems) . '.'; }
    $demo = isset($_POST['demo']);
    if ($demo && AuthService::passwordProblems((string) ($_POST['demo_password'] ?? ''))) { $errors['demo_password'] = 'Choose a strong demo password (same rules).'; }

    if (!$errors) {
        Database::transaction(function () use ($v, $pw, $demo) {
            $roleId = (int) Database::value("SELECT id FROM roles WHERE name = 'administrator'");
            $uid = Database::insert('INSERT INTO users (role_id, username, full_name, email, password_hash) VALUES (?,?,?,?,?)',
                [$roleId, $v['username'], $v['full_name'], $v['email'], AuthService::hash($pw)]);
            AuditLogger::log('initial_admin_created', 'users', $uid, null, ['username' => $v['username'], 'role' => 'administrator'], 'success',
                ['user_id' => $uid, 'username' => $v['username'], 'role' => 'administrator']);
            if ($demo) { DemoSeeder::run((string) $_POST['demo_password'], $uid); }
        });
        @file_put_contents($lock, 'installed ' . date('c'));
        flash('success', 'Setup complete. Sign in with the administrator account you just created.' . ($demo ? ' Demo data loaded.' : ''));
        redirect('login.php');
    }
}
ui_header('First-time setup', '', ['bare' => true]);
?>
<div class="auth-split">
  <aside class="auth-aside" aria-label="About this system">
    <div class="auth-brand"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="40" height="40"><span><?= e(Setting::get('garage_name', 'Alpha Garage')) ?></span></div>
    <div>
      <h1>Welcome. Let&rsquo;s set up your <span>garage</span>.</h1>
      <p>Create the first administrator account. The installer locks itself as soon as an account exists.</p>
      <ul class="auth-points"><li>Track every repair from check-in to delivery</li><li>Real-time spare-parts stock and reorder alerts</li><li>Role-based access with a full audit trail</li></ul>
    </div>
    <small>&copy; <?= e(date('Y')) ?> <?= e(Setting::get('garage_name', 'Alpha Garage')) ?></small>
  </aside>
  <div class="auth-main">
<div class="auth-card auth-wide">
  <div class="logo"><img src="<?= e(asset('images/logo.svg')) ?>" alt=""><div><h2 class="m0">First-time setup</h2><span class="muted">Create the administrator account</span></div></div>
  <form method="post" data-validate autocomplete="off">
    <?= csrf_field() ?>
    <?php form_input('full_name', 'Full name', $v['full_name'], $errors, ['required' => true, 'maxlength' => 120]); ?>
    <div class="form-grid">
      <?php form_input('username', 'Username', $v['username'], $errors, ['required' => true, 'minlength' => 3, 'maxlength' => 50, 'pattern' => '[A-Za-z0-9_.\-]+', 'title' => 'Letters, digits, dot, dash, underscore']); ?>
      <?php form_input('email', 'Email', $v['email'], $errors, ['type' => 'email', 'required' => true, 'maxlength' => 150]); ?>
      <?php form_input('password', 'Password', '', $errors, ['type' => 'password', 'required' => true, 'minlength' => 10, 'autocomplete' => 'new-password', 'data-strength' => 'pw-hint', 'help' => 'Min 10 chars with upper, lower, digit and symbol.']); ?>
      <?php form_input('password2', 'Confirm password', '', $errors, ['type' => 'password', 'required' => true, 'autocomplete' => 'new-password', 'data-match' => '#f_password']); ?>
    </div>
    <p class="muted small" id="pw-hint"></p>
    <div class="card card-flat">
      <label class="check"><input type="checkbox" name="demo" value="1" <?= isset($_POST['demo']) ? 'checked' : '' ?>> <strong>Also load realistic demo data</strong></label>
      <p class="muted small">Creates demo staff accounts (receptionist, 2 mechanics, inventory manager, accountant — usernames <span class="mono">reception, mechanic1, mechanic2, stock, accounts</span>), customers, vehicles, parts, jobs, invoices and payments. Use it for testing/presentations only.</p>
      <?php form_input('demo_password', 'Password for the demo staff accounts', '', $errors, ['type' => 'password', 'autocomplete' => 'new-password', 'help' => 'Only used if the box above is ticked.']); ?>
    </div>
    <button class="btn btn-primary btn-block" type="submit">Create administrator</button>
  </form>
</div>
  </div>
</div>
<?php ui_footer();
