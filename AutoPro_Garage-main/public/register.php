<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');

$user = current_user();
if ($user) {
    if ($user['role_name'] === 'customer') {
        redirect('index.php');
    }
    redirect('dashboard.php');
}

$errors = [];
$v = [
    'full_name' => '',
    'email'     => '',
    'phone'     => '',
    'username'  => '',
];

if (is_post()) {
    $v = [
        'full_name' => post('full_name'),
        'email'     => strtolower(post('email')),
        'phone'     => post('phone'),
        'username'  => post('username'),
    ];

    if (trim((string) ($_POST['hp_website'] ?? '')) !== '') {
        // Honeypot: pretend success so automated registrations do not learn the trap.
        flash('success', 'Your account has been created successfully! Please sign in with your credentials.');
        redirect('login.php');
    }

    $limit = RateLimiter::check('register|' . client_ip(), 5, 900);
    if (!$limit['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $limit['retry_after']);
        $errors['form'] = 'Too many registration attempts from this network. Please try again in ' . max(1, (int) ceil($limit['retry_after'] / 60)) . ' minute(s).';
    }
}

if (is_post() && !$errors) {
    $password = (string) ($_POST['password'] ?? '');
    $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
    $terms = !empty($_POST['terms']);

    // 1. Validation rules
    $errors = validate($v, [
        'full_name' => 'required|max:120',
        'email'     => 'required|email|max:150',
        'phone'     => 'required|max:30',
        'username'  => 'required|min:3|max:50',
    ]);

    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $v['username'])) {
        $errors['username'] = 'Letters, digits, dot, dash and underscore only.';
    }
    if ($v['phone'] !== '' && !preg_match('/^\+?[0-9 ()-]{7,30}$/', $v['phone'])) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }

    if (!$terms) {
        $errors['terms'] = 'You must agree to the Terms of Service & Privacy Policy.';
    }

    // 2. Password complexity validation
    if ($password !== $passwordConfirm) {
        $errors['password_confirm'] = 'Passwords do not match.';
    }

    $problems = AuthService::passwordProblems($password, $v['username']);
    if ($problems) {
        $errors['password'] = 'Password needs: ' . implode(', ', $problems) . '.';
    }

    // 3. Duplicate checks
    if (empty($errors['username'])) {
        if (Database::value('SELECT id FROM users WHERE username = ?', [$v['username']])) {
            $errors['username'] = 'This username is already taken. Please choose another.';
        }
    }

    if (empty($errors['email'])) {
        if (Database::value('SELECT id FROM users WHERE LOWER(email) = ?', [$v['email']])) {
            $errors['email'] = 'An account with this email address already exists.';
        }
    }

    // 4. Persistence if valid
    if (!$errors) {
        $roleId = Database::value("SELECT id FROM roles WHERE name = 'customer'");
        if (!$roleId) {
            app_log('Customer registration blocked: required customer role is missing.');
            abort(500, 'Customer registration is temporarily unavailable. Please contact the garage.');
        }

        $newUserId = Database::transaction(function () use ($roleId, $v, $password) {
            $hash = AuthService::hash($password);
            
            $uid = Database::insert(
                'INSERT INTO users (role_id, username, full_name, email, phone, password_hash, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [(int) $roleId, $v['username'], $v['full_name'], $v['email'], $v['phone'] ?: null, $hash]
            );

            // Always create a dedicated customer profile with explicit immutable user_id ownership.
            // Walk-in records matching email remain unlinked until independently verified (Remediates FIND-110B-02).
            Database::insert(
                'INSERT INTO customers (full_name, phone, email, created_by, user_id) VALUES (?, ?, ?, ?, ?)',
                [$v['full_name'], $v['phone'] ?: '+251 90 000 0000', $v['email'], (int) $uid, (int) $uid]
            );

            AuditLogger::logSafe(
                'user_registered',
                'users',
                (int) $uid,
                null,
                ['username' => $v['username'], 'email' => $v['email'], 'role_id' => $roleId],
                'success',
                ['user_id' => (int) $uid, 'username' => $v['username']]
            );

            return $uid;
        });

        flash('success', 'Your account has been created successfully! Please sign in with your credentials.');
        redirect('login.php');
    }
}

// Fullscreen high-quality automotive Unsplash images
$sliderImages = [
    'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1617814076367-b759c7d7e738?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1486006920555-c77dce18193b?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1920&q=80',
    'https://images.unsplash.com/photo-1517524008697-84bbe3c3fd98?auto=format&fit=crop&w=1920&q=80',
];
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Create Account &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/luxury-auth.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/scroll-animations.css')) ?>">
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
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" 
        stroke="currentColor" stroke-width="2.5" 
        stroke-linecap="round" stroke-linejoin="round">
          <line x1="19" y1="12" x2="5" y2="12"></line>
          <polyline points="12 19 5 12 12 5"></polyline>
        </svg>
      </span>
      <span class="btn-back-text"><?= e(t('Back to Homepage', 'Back to Homepage')) ?></span>
    </a>
    <?= lang_switcher_html() ?>
    <div class="luxury-badge-portal">
      <svg width="13" height="13" viewBox="0 0 24 24" 
      fill="none" stroke="currentColor" stroke-width="2">
        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
      </svg>
      <span><?= e(t('Client Portal', 'Client Portal')) ?></span>
    </div>
  </div>

  <main class="luxury-auth-centered-card card-wide reveal-scale card-interactive">
    <div class="luxury-card-brand">
      <div class="luxury-card-logo-wrap">
        <img src="<?= e(asset('images/logo.svg')) ?>" alt="logo image" width="44" height="44">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#D4AF37" stroke-width="2">
          <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>
        </svg>
      </div>
      <h1 class="luxury-card-brand-title"><?= e($garageName) ?></h1>
      <div class="luxury-card-brand-sub">
        <span class="typed-text" data-typewriter='["Join Our Elite Client Network.", "Fast Appointment Booking.", "Digital Maintenance Logs.", "Transparent Pricing."]'>Join Our Elite Client Network.</span>
      </div>
    </div>

    <div class="luxury-form-header auth-form-header">
      <h2 class="luxury-form-title auth-form-title"><?= e(t('Create an Account', 'Create an Account')) ?></h2>
      <p class="luxury-form-desc"><?= e(t('Register with :garage for precision service', 'Register with :garage for precision service', ['garage' => $garageName])) ?></p>
    </div>

    <?php if (!empty($errors['form'])): ?>
      <div class="luxury-alert luxury-alert-danger" role="alert"><?= e($errors['form']) ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off" novalidate>
      <?= csrf_field() ?>

      <div hidden aria-hidden="true">
        <label for="hp_website">Website</label>
        <input id="hp_website" name="hp_website" type="text" tabindex="-1" autocomplete="off">
      </div>

      <!-- Full Name -->
      <div class="luxury-field">
        <label class="luxury-label" for="full_name">
          <span><?= e(t('Full Name', 'Full Name')) ?> <span class="req">*</span></span>
        </label>
        <div class="luxury-input-wrapper">
          <input class="luxury-input" id="full_name" name="full_name" type="text"
                 value="<?= e($v['full_name']) ?>" required maxlength="120"
                 placeholder="e.g. Dawit Tadesse">
        </div>
        <?php if (!empty($errors['full_name'])): ?>
          <small class="auth-field-error"><?= e($errors['full_name']) ?></small>
        <?php endif; ?>
      </div>

      <!-- Email & Phone Grid -->
      <div class="auth-form-grid">
        <div class="luxury-field">
          <label class="luxury-label" for="email">
            <span><?= e(t('Email', 'Email')) ?> <span class="req">*</span></span>
          </label>
          <div class="luxury-input-wrapper">
            <input class="luxury-input" id="email" name="email" type="email"
                   value="<?= e($v['email']) ?>" required maxlength="150"
                   placeholder="name@example.com">
          </div>
          <?php if (!empty($errors['email'])): ?>
            <small class="auth-field-error"><?= e($errors['email']) ?></small>
          <?php endif; ?>
        </div>

        <div class="luxury-field">
          <label class="luxury-label" for="phone">
            <span><?= e(t('Phone', 'Phone')) ?> <span class="req">*</span></span>
          </label>
          <div class="luxury-input-wrapper">
            <input class="luxury-input" id="phone" name="phone" type="tel"
                   value="<?= e($v['phone']) ?>" maxlength="30"
                   placeholder="+251 9..." required>
          </div>
          <?php if (!empty($errors['phone'])): ?>
            <small class="auth-field-error"><?= e($errors['phone']) ?></small>
          <?php endif; ?>
        </div>
      </div>

      <!-- Username -->
      <div class="luxury-field">
        <label class="luxury-label" for="username">
          <span><?= e(t('Username', 'Username')) ?> <span class="req">*</span></span>
        </label>
        <div class="luxury-input-wrapper">
          <input class="luxury-input" id="username" name="username" type="text"
                 value="<?= e($v['username']) ?>" required minlength="3" maxlength="50"
                 placeholder="<?= e(t('Choose a unique username', 'Choose a unique username')) ?>">
        </div>
        <?php if (!empty($errors['username'])): ?>
          <small class="auth-field-error"><?= e($errors['username']) ?></small>
        <?php endif; ?>
      </div>

      <!-- Password & Strength Meter -->
      <div class="luxury-field">
        <label class="luxury-label" for="password">
          <span><?= e(t('Password', 'Password')) ?> <span class="req">*</span></span>
        </label>
        <div class="luxury-input-wrapper">
          <input class="luxury-input has-toggle" id="password" name="password"
                 type="password" required autocomplete="new-password"
                 minlength="10" placeholder="<?= e(t('Minimum 10 characters with numbers & symbols', 'Minimum 10 characters with numbers & symbols')) ?>"
                 data-strength-target="true">
          <button type="button" class="toggle-password" data-target="password"
                  aria-label="Toggle password visibility" title="Show password">👁</button>
        </div>
        <div class="strength-meter-wrap">
          <div class="strength-bar-bg">
            <div id="password-strength-bar" class="strength-bar-fill"></div>
          </div>
          <div id="password-strength-label" class="strength-label"></div>
        </div>
        <?php if (!empty($errors['password'])): ?>
          <small class="auth-field-error"><?= e($errors['password']) ?></small>
        <?php endif; ?>
      </div>

      <!-- Confirm Password -->
      <div class="luxury-field">
        <label class="luxury-label" for="password_confirm">
          <span><?= e(t('Confirm Password', 'Confirm Password')) ?> <span class="req">*</span></span>
        </label>
        <div class="luxury-input-wrapper">
          <input class="luxury-input has-toggle" id="password_confirm" name="password_confirm"
                 type="password" required autocomplete="new-password"
                 placeholder="<?= e(t('Re-enter your password', 'Re-enter your password')) ?>">
          <button type="button" class="toggle-password" data-target="password_confirm"
                  aria-label="Toggle password visibility" title="Show password">👁</button>
        </div>
        <?php if (!empty($errors['password_confirm'])): ?>
          <small class="auth-field-error"><?= e($errors['password_confirm']) ?></small>
        <?php endif; ?>
      </div>

      <!-- Terms & Privacy Agreement -->
      <div class="luxury-field auth-terms-field">
        <label class="luxury-checkbox-label auth-terms-label">
          <input type="checkbox" name="terms" value="1" class="luxury-checkbox auth-terms-checkbox" required>
          <span class="auth-terms-copy">
            <?= e(t('I have read and agree to the', 'I have read and agree to the')) ?> 
            <a href="<?= e(url('terms.php')) ?>" target="_blank" rel="noopener" class="auth-terms-link"><?= e(t('Terms of Service', 'Terms of Service')) ?></a> 
            <?= e(t('and', 'and')) ?> 
            <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener" class="auth-terms-link"><?= e(t('Privacy Policy', 'Privacy Policy')) ?></a>.
          </span>
        </label>
        <?php if (!empty($errors['terms'])): ?>
          <small class="auth-field-error"><?= e($errors['terms']) ?></small>
        <?php endif; ?>
      </div>

      <!-- Submit Button -->
      <button class="btn-luxury-gold btn-interactive btn-shimmer" type="submit">
        <?= e(t('Create Account', 'Create Account')) ?>
      </button>
    </form>

    <div class="luxury-form-footer">
      <?= e(t('Already have an account?', 'Already have an account?')) ?>
      <a href="<?= e(url('login.php')) ?>"><?= e(t('Sign In', 'Sign In')) ?></a>
    </div>
  </main>

  <footer class="auth-legal-footer">
    <a href="<?= e(url('terms.php')) ?>" target="_blank" rel="noopener" class="auth-legal-link"><?= e(t('Terms of Service', 'Terms of Service')) ?></a> &bull;
    <a href="<?= e(url('privacy.php')) ?>" target="_blank" rel="noopener" class="auth-legal-link"><?= e(t('Privacy Policy', 'Privacy Policy')) ?></a> &bull;
    <a href="<?= e(url('contact.php')) ?>" class="auth-legal-link"><?= e(t('Support & Telegram', 'Support & Telegram')) ?></a>
  </footer>
</div>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
<script src="<?= e(asset('js/i18n.js')) ?>"></script>
<script src="<?= e(asset('js/scroll-animations.js')) ?>"></script>
</body>
</html>
