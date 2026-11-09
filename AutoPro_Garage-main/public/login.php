<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');
$dbError = null;
$installed = false;
$databaseFailureMessage = static function (Throwable $e): string {
    $driverCode = $e instanceof PDOException && isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
    if ($e instanceof RuntimeException && str_contains($e->getMessage(), 'Configure database credentials')) {
        return 'Database configuration is incomplete. Please contact the garage administrator.';
    }
    if ($e instanceof PDOException && ($e->getCode() === '42S02' || $driverCode === 1146)) {
        return 'The sign-in database is missing required tables. Please contact the garage administrator.';
    }
    if ($e instanceof PDOException && (in_array($driverCode, [1045, 2002, 2003], true) || $e->getCode() === '28000')) {
        return 'The sign-in database is unavailable or its account is not configured. Please contact the garage administrator.';
    }
    return 'Sign-in is temporarily unavailable. Please try again later or contact the garage administrator.';
};

try {
    $user = current_user();
    if ($user) {
        if ($user['role_name'] === 'customer') {
            redirect('index.php');
        }
        $redirect = get('redirect');
        redirect(!empty($redirect) && !str_starts_with($redirect, 'http') ? $redirect : 'dashboard.php');
    }
    $installed = (int) Database::value('SELECT COUNT(*) FROM users') > 0;
} catch (Throwable $e) {
    app_log('Database check in login failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $dbError = $databaseFailureMessage($e);
}

$error = '';
$username = '';
$redirectParam = get('redirect', '');

if (is_post() && !$dbError) {
    $username = post('username');
    $password = (string) ($_POST['password'] ?? '');
    $remember = (bool) ((int) post('remember') === 1);
    try {
        $res = AuthService::attempt($username, $password, $remember);
    } catch (Throwable $e) {
        app_log('Authentication attempt failed: ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $dbError = $databaseFailureMessage($e);
        $res = null;
    }
    if ($res !== null && $res['ok']) {
        flash('success', 'Welcome back to ' . $garageName . '.');
        if ($res['role_name'] === 'customer') {
            redirect('index.php');
        }
        $dest = post('redirect_url');
        redirect(!empty($dest) && !str_starts_with($dest, 'http') ? $dest : 'dashboard.php');
    }
    if ($res !== null) {
        $error = $res['error'];
    }
}

// Fullscreen high-quality automotive Unsplash images
$sliderImages = [
    'https://images.unsplash.com/photo-1617814076367-b759c7d7e738?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1486006920555-c77dce18193b?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1517524008697-84bbe3c3fd98?auto=format&fit=crop&w=1920&q=80',
];

$flashes = pull_flash();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign In &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/luxury-auth.css')) ?>">
</head>
<body class="luxury-auth-body">

<!-- Full-Screen Automotive Background Image Slider -->
<div class="luxury-bg-slider luxury-slider">
  <?php foreach ($sliderImages as $i => $imgUrl): ?>
    <div class="luxury-slide <?= $i === 0 ? 'active' : '' ?>">
      <img class="luxury-slide-image" src="<?= e($imgUrl) ?>" alt="" aria-hidden="true" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"<?= $i === 0 ? ' fetchpriority="high"' : '' ?>>
    </div>
  <?php endforeach; ?>
  <div class="luxury-bg-overlay"></div>
</div>

<!-- Centered Luxury Authentication Card -->
<div class="luxury-auth-centered-wrapper">
  <div class="luxury-auth-topbar">
    <a href="<?= e(url('index.php')) ?>" class="btn-luxury-back">
      <span class="btn-back-arrow">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="19" y1="12" x2="5" y2="12"></line>
          <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
      </span>
      <span class="btn-back-text">Back to Homepage</span>
    </a>
    <div class="luxury-badge-portal">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      <span>Secure Portal</span>
    </div>
  </div>

  <main class="luxury-auth-centered-card">
    <div class="luxury-card-brand">
      <div class="luxury-card-logo-wrap">
        <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="44" height="44">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#D4AF37" stroke-width="2">
          <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
        </svg>
      </div>
      <h1 class="luxury-card-brand-title"><?= e($garageName) ?></h1>
      <div class="luxury-card-brand-sub">
        <span class="typed-text" data-typewriter='["Premium Automotive Care.", "Expert Vehicle Maintenance.", "Certified Diagnostics.", "Forensic Data Security."]'>Premium Automotive Care.</span>
      </div>
    </div>

    <div class="luxury-form-header auth-form-header">
      <h2 class="luxury-form-title auth-form-title">Welcome Back</h2>
      <p class="luxury-form-desc">Sign in to access your garage account</p>
    </div>

    <?php foreach ($flashes as $f): ?>
      <div class="luxury-alert luxury-alert-<?= $f['type'] === 'success' ? 'success' : ($f['type'] === 'danger' ? 'danger' : 'info') ?>">
        <span><?= e($f['message']) ?></span>
      </div>
    <?php endforeach; ?>

    <?php if ($dbError): ?>
      <div class="luxury-alert luxury-alert-danger">
        <div>
          <strong>Service temporarily unavailable:</strong><br>
          <?= e($dbError) ?><br><br>
        </div>
      </div>
    <?php elseif (!$installed): ?>
      <div class="luxury-alert luxury-alert-info">
        <div>
          No accounts exist yet. <a href="<?= e(url('setup.php')) ?>" class="auth-setup-link">Run the first-time setup</a> to initialize your administrator.
        </div>
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="luxury-alert luxury-alert-danger" role="alert">
        <span><?= e($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="redirect_url" value="<?= e($redirectParam) ?>">

      <!-- Username or Email -->
      <div class="luxury-field">
        <label class="luxury-label" for="username">
          <span>Username or Email <span class="req">*</span></span>
        </label>
        <div class="luxury-input-wrapper">
          <input class="luxury-input" id="username" name="username" type="text"
                 value="<?= e($username) ?>" required maxlength="150" autofocus
                 autocomplete="username" placeholder="Enter username or email"
                 <?= $dbError ? 'disabled' : '' ?>>
        </div>
      </div>

      <!-- Password -->
      <div class="luxury-field">
        <div class="luxury-label">
          <label for="password">Password <span class="req">*</span></label>
          <a href="<?= e(url('forgot-password.php')) ?>" class="luxury-label-link">Forgot password?</a>
        </div>
        <div class="luxury-input-wrapper">
          <input class="luxury-input has-toggle" id="password" name="password"
                 type="password" required autocomplete="current-password"
                 placeholder="Enter your account password"
                 <?= $dbError ? 'disabled' : '' ?>>
          <button type="button" class="toggle-password" data-target="password"
                  aria-label="Toggle password visibility" title="Show password">👁</button>
        </div>
      </div>

      <!-- Submit Button -->
      <button class="btn-luxury-gold" type="submit" <?= $dbError ? 'disabled' : '' ?>>
        Sign In
      </button>

      <!-- Remember Me -->
      <div class="luxury-field">
        <label class="luxury-checkbox-label">
          <input type="checkbox" name="remember" value="1"
                 class="luxury-checkbox" <?= $dbError ? 'disabled' : '' ?>>
          <span>Remember me on this device</span>
        </label>
      </div>
    </form>

    <div class="luxury-form-footer">
      Don&rsquo;t have an account yet?
      <a href="<?= e(url('register.php')) ?>">Create an account</a>
    </div>
  </main>

  <footer class="auth-legal-footer">
    <a href="<?= e(url('terms.php')) ?>" class="auth-legal-link">Terms of Service</a> &bull;
    <a href="<?= e(url('privacy.php')) ?>" class="auth-legal-link">Privacy Policy</a> &bull;
    <a href="<?= e(url('contact.php')) ?>" class="auth-legal-link">Support &amp; Telegram</a>
  </footer>
</div>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
</body>
</html>
