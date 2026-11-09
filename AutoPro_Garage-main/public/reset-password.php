<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');

if (current_user()) {
    redirect('dashboard.php');
}

$rawToken = trim((string) ($_GET['token'] ?? ($_POST['token'] ?? '')));
$validReset = $rawToken ? PasswordResetService::validateToken($rawToken) : null;

$error = '';
if (!$validReset && $rawToken) {
    $error = 'This password reset link is invalid or has expired. Please request a new recovery link.';
} elseif (!$rawToken) {
    $error = 'Missing recovery token. Please use the secure link sent to your email.';
}

if (is_post() && $validReset) {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

    if ($password !== $passwordConfirm) {
        $error = 'Passwords do not match.';
    } else {
        $res = PasswordResetService::completeReset($rawToken, $password);
        if ($res['ok']) {
            flash('success', 'Your password has been reset successfully. Please sign in with your new password.');
            redirect('login.php');
        } else {
            $error = $res['error'] ?? 'Failed to update password.';
        }
    }
}

// Fullscreen high-quality automotive Unsplash images
$sliderImages = [
    'https://images.unsplash.com/photo-1617814076367-b759c7d7e738?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1486006920555-c77dce18193b?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1920&q=80',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Set New Password &middot; <?= e($garageName) ?></title>
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
    <a href="<?= e(url('login.php')) ?>" class="btn-luxury-back">
      <span class="btn-back-arrow">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
          <line x1="19" y1="12" x2="5" y2="12"></line>
          <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
      </span>
      <span class="btn-back-text">Return to Sign In</span>
    </a>
    <div class="luxury-badge-portal">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      <span>Credential Update</span>
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
        <span class="typed-text" data-typewriter='["Establish Your New Password.", "High-Entropy Credentials.", "Digital Protection."]'>Establish Your New Password.</span>
      </div>
    </div>

    <div class="luxury-form-header auth-form-header">
      <h2 class="luxury-form-title auth-form-title">Set New Password</h2>
      <?php if ($validReset): ?>
        <p class="luxury-form-desc">Resetting password for account <strong><?= e($validReset['username']) ?></strong></p>
      <?php else: ?>
        <p class="luxury-form-desc">Authorized credential recovery required</p>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <div class="luxury-alert luxury-alert-danger" role="alert">
        <div>
          <?= e($error) ?>
          <?php if (!$validReset): ?>
            <div class="reset-link-wrap">
              <a href="<?= e(url('forgot-password.php')) ?>" class="btn-luxury-ghost reset-link-button">Request New Recovery Link</a>
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($validReset): ?>
      <form method="post" autocomplete="off" novalidate>
        <?= csrf_field() ?>
        <input type="hidden" name="token" value="<?= e($rawToken) ?>">

        <!-- New Password -->
        <div class="luxury-field">
          <label class="luxury-label" for="password">
            <span>New Password <span class="req">*</span></span>
          </label>
          <div class="luxury-input-wrapper">
            <input class="luxury-input has-toggle" id="password" name="password"
                   type="password" required autocomplete="new-password"
                   placeholder="Enter new strong password"
                   data-strength-target="true" autofocus>
            <button type="button" class="toggle-password" data-target="password"
                    aria-label="Toggle password visibility" title="Show password">👁</button>
          </div>
          <div class="strength-meter-wrap">
            <div class="strength-bar-bg">
              <div id="password-strength-bar" class="strength-bar-fill"></div>
            </div>
            <div id="password-strength-label" class="strength-label"></div>
          </div>
        </div>

        <!-- Confirm Password -->
        <div class="luxury-field">
          <label class="luxury-label" for="password_confirm">
            <span>Confirm New Password <span class="req">*</span></span>
          </label>
          <div class="luxury-input-wrapper">
            <input class="luxury-input has-toggle" id="password_confirm" name="password_confirm"
                   type="password" required autocomplete="new-password"
                   placeholder="Re-enter your password">
            <button type="button" class="toggle-password" data-target="password_confirm"
                    aria-label="Toggle password visibility" title="Show password">👁</button>
          </div>
        </div>

        <button class="btn-luxury-gold auth-submit-spaced" type="submit">
          Update Password &amp; Sign In
        </button>
      </form>
    <?php endif; ?>

    <div class="luxury-form-footer reset-form-footer">
      Remembered your password?
      <a href="<?= e(url('login.php')) ?>">Sign In</a>
    </div>
  </main>
</div>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
</body>
</html>
