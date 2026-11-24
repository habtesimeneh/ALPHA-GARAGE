<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$garageName = 'Alpha Garage';
try {
    $garageName = Database::value("SELECT setting_value FROM garage_settings WHERE setting_key = 'garage_name'") ?: $garageName;
} catch (Throwable) {}
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terms of Service &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/scroll-animations.css')) ?>">
</head>
<body class="landing-page">

<!-- -----------------------------------------------------------------------
     Sticky Luxury Navigation Bar
     ----------------------------------------------------------------------- -->
<nav class="luxury-navbar" aria-label="Main Navigation">
  <div class="top-announcement">
    <span class="announcement-mark" aria-hidden="true">&#10022;</span>
    <div class="announcement-message" aria-label="Welcome to Alpha Garage. Professional automotive care, clear service guidance, and convenient booking.">
      <div class="announcement-track">
        <span class="announcement-copy">Welcome to Alpha Garage <span aria-hidden="true">&#8226;</span> Professional automotive diagnostics and repairs <span aria-hidden="true">&#8226;</span> Clear service guidance from our team <span aria-hidden="true">&#8226;</span> Convenient online appointment booking <span aria-hidden="true">&#8226;</span> Care for every journey <span aria-hidden="true">&#8226;</span> Transparent updates throughout your visit <span aria-hidden="true">&#8226;</span> Experienced automotive professionals <span aria-hidden="true">&#8226;</span> Vehicle care tailored to your needs</span>
        <span class="announcement-copy" aria-hidden="true">Welcome to Alpha Garage <span aria-hidden="true">&#8226;</span> Professional automotive diagnostics and repairs <span aria-hidden="true">&#8226;</span> Clear service guidance from our team <span aria-hidden="true">&#8226;</span> Convenient online appointment booking <span aria-hidden="true">&#8226;</span> Care for every journey <span aria-hidden="true">&#8226;</span> Transparent updates throughout your visit <span aria-hidden="true">&#8226;</span> Experienced automotive professionals <span aria-hidden="true">&#8226;</span> Vehicle care tailored to your needs</span>
      </div>
    </div>
    <a href="<?= e(url('contact.php')) ?>">Contact us <span aria-hidden="true">&rarr;</span></a>
  </div>
  <div class="nav-container">
    <a href="<?= e(url('index.php')) ?>" class="nav-brand">
      <div class="nav-logo-icon"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36"></div>
      <div>
        <span class="nav-brand-title"><?= e($garageName) ?></span>
        <span class="nav-brand-sub">Luxury Automotive Care</span>
      </div>
    </a>

    <ul class="nav-menu-links" id="primary-navigation">
      <li><a href="<?= e(url('index.php')) ?>" class="nav-link"><?= e(t('Home', 'Home')) ?></a></li>
      <li><a href="<?= e(url('about.php')) ?>" class="nav-link"><?= e(t('About Us', 'About Us')) ?></a></li>
      <li><a href="<?= e(url('services.php')) ?>" class="nav-link"><?= e(t('Our Services', 'Our Services')) ?></a></li>
      <li><a href="<?= e(url('index.php#why-us')) ?>" class="nav-link"><?= e(t('Why Choose Us', 'Why Choose Us')) ?></a></li>
      <li><a href="<?= e(url('contact.php')) ?>" class="nav-link"><?= e(t('Contact', 'Contact')) ?></a></li>
      <?php if (!current_user()): ?><li class="mobile-nav-login"><a href="<?= e(url('login.php')) ?>" class="nav-link"><?= e(t('Client sign in', 'Client sign in')) ?></a></li><?php endif; ?>
    </ul>

    <div class="nav-actions">
      <?= lang_switcher_html() ?>
      <?php if (current_user() && can('dashboard.view')): ?>
        <a href="<?= e(url('dashboard.php')) ?>" class="btn-nav-cta"><?= e(t('Dashboard', 'Dashboard')) ?></a>
      <?php elseif (current_user()): ?>
        <a href="<?= e(url('profile.php')) ?>" class="btn-nav-cta"><?= e(t('My Account', 'My Account')) ?></a>
      <?php else: ?>
        <a href="<?= e(url('login.php')) ?>" class="btn-nav-login"><?= e(t('Sign In', 'Sign In')) ?></a>
        <a href="<?= e(url('register.php')) ?>" class="btn-nav-cta"><?= e(t('Get Started', 'Get Started')) ?></a>
      <?php endif; ?>
      <button class="nav-toggle-btn" type="button" aria-label="Open navigation menu" aria-controls="primary-navigation" aria-expanded="false"><span aria-hidden="true">&#9776;</span></button>
    </div>
  </div>
</nav>

<!-- -----------------------------------------------------------------------
     Inner Page Hero Header
     ----------------------------------------------------------------------- -->
<header class="inner-hero reveal-up reveal-hero">
  <div class="container">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
      <a href="<?= e(url('index.php')) ?>">Home</a>
      <span>&rsaquo;</span>
      <span>Legal &amp; Service Standards</span>
    </nav>
    <h1 class="inner-hero-title">Terms of Service &amp; Repair Standards</h1>
    <p class="inner-hero-lead">
      These terms govern your automotive service intake, diagnostic approvals, parts warranty, digital account security, and payment agreements.
    </p>
  </div>
</header>

<!-- -----------------------------------------------------------------------
     Terms Content Card
     ----------------------------------------------------------------------- -->
<section class="section section-dark">
  <div class="container">
    <div class="legal-card reveal-up card-interactive">

      <div class="legal-section">
        <h2 class="legal-heading">1. Introduction and Agreement</h2>
        <p class="legal-text">
          By registering an account on our Garage Management System, booking a service appointment, or surrendering a vehicle to <strong><?= e($garageName) ?></strong>, you agree to be bound by these Terms of Service. If you do not agree to any portion of these terms, please contact our management team prior to commissioning service.
        </p>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">2. Vehicle Intake, Inspection &amp; Road Testing Authorization</h2>
        <p class="legal-text">
          Upon arrival at our workshop facility:
        </p>
        <ul class="legal-list">
          <li><strong>Digital Intake Inspection:</strong> A digital intake report is created documenting current fuel levels, odometer reading, pre-existing exterior scratches/dents, and dashboard warning indicators.</li>
          <li><strong>Road Testing Permission:</strong> You authorize our certified technicians to operate your vehicle on public roads for mandatory diagnostic verification, performance telemetry, and post-repair quality assurance.</li>
          <li><strong>Valuables Disclaimer:</strong> Please remove all loose personal belongings and valuables prior to vehicle drop-off. <?= e($garageName) ?> is not liable for unsecured personal property left inside vehicles.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">3. Estimates, Diagnostic Authorization &amp; Job Cards</h2>
        <p class="legal-text">
          We operate on complete pricing transparency:
        </p>
        <ul class="legal-list">
          <li><strong>Pre-Service Estimates:</strong> Diagnostic teardown is followed by an itemized digital estimate outlining required spare parts, labor hours, and applicable VAT.</li>
          <li><strong>Customer Consent:</strong> No repair or part replacement exceeding the agreed diagnostic fee will be initiated without your express electronic or written authorization.</li>
          <li><strong>Supplemental Findings:</strong> If hidden mechanical damage is discovered during teardown, work on that sub-assembly will pause until an updated supplementary estimate is approved by you.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">4. Genuine Spare Parts &amp; Comprehensive Warranty</h2>
        <p class="legal-text">
          We guarantee the authenticity and longevity of our workmanship:
        </p>
        <ul class="legal-list">
          <li><strong>OEM Verification:</strong> All replacement components installed by our facility are genuine Original Equipment Manufacturer (OEM) parts or certified premium performance replacements.</li>
          <li><strong>12-Month / 20,000 km Warranty:</strong> All qualified mechanical and electrical repairs are covered against manufacturing defect and workmanship failure for 12 months or 20,000 km (whichever occurs first).</li>
          <li><strong>Client-Supplied Parts:</strong> While we can install client-supplied parts upon request, our warranty is strictly limited to the labor operation and does not cover the third-party part itself.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">5. Payment Terms, Storage &amp; Collection</h2>
        <p class="legal-text">
          Upon receipt of the digital completion notice via SMS, Telegram, or email:
        </p>
        <ul class="legal-list">
          <li><strong>Settlement of Account:</strong> Full payment of the digital invoice must be completed prior to vehicle release, unless a prior corporate fleet credit agreement has been formalized.</li>
          <li><strong>Complimentary Holding:</strong> Completed vehicles may remain in our secure facility free of charge for up to <strong>72 hours</strong> following service completion notice.</li>
          <li><strong>Storage Fees:</strong> Vehicles remaining unclaimed after 72 hours without prior written agreement may incur a daily security and storage charge.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">6. Client Digital Account Security</h2>
        <p class="legal-text">
          Registered portal users are responsible for safeguarding their login credentials. Passwords must comply with complexity rules (minimum 8 characters with numbers and symbols). You agree to immediately notify our support desk if you suspect unauthorized access to your account.
        </p>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">7. Customer Support &amp; Dispute Resolution</h2>
        <p class="legal-text">
          These terms are governed by the commercial laws of the Federal Democratic Republic of Ethiopia. In the unlikely event of a service dispute, both parties agree to resolve matters amicably through consultation with our Service Director.
        </p>
        <ul class="legal-list">
          <li><strong>Telegram Support:</strong> <a href="https://t.me/GarageManagementSupport" target="_blank" class="legal-link-luxury">@GarageLuxurySupport</a></li>
          <li><strong>Customer Hotline:</strong> +251 911 234 567</li>
          <li><strong>Service Inquiries:</strong> <a href="<?= e(url('contact.php')) ?>" class="legal-link-champagne">Contact Page Form</a></li>
        </ul>
      </div>

      <div class="legal-cta-wrap">
        <a href="<?= e(url('register.php')) ?>" class="btn-hero-primary legal-cta-link">
          Agree and Continue to Registration &rarr;
        </a>
      </div>

    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Luxury Footer
     ----------------------------------------------------------------------- -->
<footer class="landing-footer reveal-fade">
  <div class="footer-top">
    <div>
      <div class="footer-brand"><?= e($garageName) ?></div>
      <p class="footer-desc">
        Pioneering precision automotive engineering, transparent digital maintenance, and cryptographic data security.
      </p>
      <div class="legal-footer-contact">
        Telegram: <a href="https://t.me/GarageManagementSupport" target="_blank">@GarageLuxurySupport</a>
      </div>
    </div>

    <details class="footer-group" open>
      <summary class="footer-col-title">Quick Links</summary>
      <ul class="footer-links">
        <li><a href="<?= e(url('index.php')) ?>">Home</a></li>
        <li><a href="<?= e(url('about.php')) ?>">About Us &amp; FAQ</a></li>
        <li><a href="<?= e(url('contact.php')) ?>">Contact Support</a></li>
        <li><a href="<?= e(url('privacy.php')) ?>">Privacy Policy</a></li>
        <li><a href="<?= e(url('terms.php')) ?>">Terms of Service</a></li>
      </ul>
    </details>

    <details class="footer-group" open>
      <summary class="footer-col-title">Client Access</summary>
      <ul class="footer-links">
        <li><a href="<?= e(url('login.php')) ?>">Client Sign In</a></li>
        <li><a href="<?= e(url('register.php')) ?>">Create Account</a></li>
        <li><a href="<?= e(url('forgot-password.php')) ?>">Forgot Password</a></li>
        <?php if (current_user() && can('dashboard.view')): ?><li><a href="<?= e(url('dashboard.php')) ?>">Workshop Dashboard</a></li><?php endif; ?>
      </ul>
    </details>

  </div>

  <div class="footer-bottom">
    <div>&copy; <?= e(date('Y')) ?> <?= e($garageName) ?>. All rights reserved.</div>
    <div class="footer-bottom-note">Professional Automotive Service &amp; Repair</div>
  </div>
</footer>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
<script src="<?= e(asset('js/i18n.js')) ?>"></script>
<script src="<?= e(asset('js/scroll-animations.js')) ?>"></script>
</body>
</html>
