<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');

if (current_user()) {
    redirect('dashboard.php');
}

$message = '';
$isSuccess = false;
$devResetLink = null;
$email = '';

if (is_post()) {
    $email = post('email');
    $normalizedEmail = strtolower(trim($email));

    // Throttle per IP and per normalized address; both buckets are hashed on disk.
    $ipLimit    = RateLimiter::check('password_reset_ip|' . client_ip(), 5, 900);
    $emailLimit = ['allowed' => true, 'retry_after' => 0];
    if ($normalizedEmail !== '' && filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        $emailLimit = RateLimiter::check('password_reset_email|' . $normalizedEmail, 5, 900);
    }

    if (!$ipLimit['allowed'] || !$emailLimit['allowed']) {
        $retryAfter = max((int) $ipLimit['retry_after'], (int) $emailLimit['retry_after'], 1);
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        $message   = 'Too many password recovery requests. Please try again in '
                   . max(1, (int) ceil($retryAfter / 60)) . ' minute(s).';
        $isSuccess = false;
    } else {
        $result    = PasswordResetService::requestReset($email);
        $message   = $result['message'];
        $isSuccess = (bool) $result['ok'];

        if (!empty($_SESSION['_dev_reset_link'])) {
            $devResetLink = $_SESSION['_dev_reset_link'];
            unset($_SESSION['_dev_reset_link']);
        }
    }
}

// Fullscreen high-quality automotive Unsplash images
$sliderImages = [
    'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1617814076367-b759c7d7e738?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1486006920555-c77dce18193b?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1920&q=80',
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Password Recovery &middot; <?= e($garageName) ?></title>
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
      <span class="btn-back-text">Back to Sign In</span>
    </a>
    <div class="luxury-badge-portal">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      <span>Recovery Portal</span>
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
        <span class="typed-text" data-typewriter='["Cryptographic Account Security.", "Single-Use Recovery Tokens.", "Zero-Knowledge Protection."]'>Cryptographic Account Security.</span>
      </div>
    </div>

    <div class="luxury-form-header auth-form-header">
      <h2 class="luxury-form-title auth-form-title">Reset Password</h2>
      <p class="luxury-form-desc">Enter your account email to receive a recovery link</p>
    </div>

    <?php if ($message): ?>
      <div class="luxury-alert <?= $isSuccess ? 'luxury-alert-success' : 'luxury-alert-danger' ?>" role="alert">
        <div>
          <strong><?= $isSuccess ? 'Request Processed' : 'Request Not Processed' ?></strong><br>
          <?= e($message) ?>
        </div>
      </div>

      <?php if ($devResetLink): ?>
        <div class="luxury-alert luxury-alert-info reset-link-alert">
          <div>
            <strong>Development Helper (SMTP Spool):</strong><br>
            <small>Direct recovery link generated in development:</small><br>
            <a href="<?= e($devResetLink) ?>" class="reset-link-action">
              &rarr; Click here to open password reset link
            </a>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>

      <div class="luxury-field">
        <label class="luxury-label" for="email">
          <span>Email Address <span class="req">*</span></span>
        </label>
        <div class="luxury-input-wrapper">
          <input class="luxury-input" id="email" name="email" type="email"
                 value="<?= e($email) ?>" required maxlength="150" autofocus
                 placeholder="Enter your registered account email">
        </div>
      </div>

      <button class="btn-luxury-gold auth-submit-spaced" type="submit">
        Send Recovery Link
      </button>
    </form>

    <div class="luxury-form-footer reset-form-footer">
      <a href="<?= e(url('login.php')) ?>">&larr; Back to Sign In</a>
      <a href="<?= e(url('register.php')) ?>">Create Account</a>
    </div>
  </main>
</div>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
</body>
</html>
