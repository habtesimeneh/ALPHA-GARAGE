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
<title>Privacy Policy &middot; <?= e($garageName) ?></title>
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
      <span>Legal &amp; Data Security</span>
    </nav>
    <h1 class="inner-hero-title">Privacy Policy &amp; Data Protection</h1>
    <p class="inner-hero-lead">
      We believe that luxury automotive service requires uncompromising respect for client privacy, cryptographic record protection, and complete transparency.
    </p>
  </div>
</header>

<!-- -----------------------------------------------------------------------
     Policy Document Content
     ----------------------------------------------------------------------- -->
<section class="section section-dark">
  <div class="container">
    <div class="legal-card reveal-up card-interactive">

      <div class="legal-section">
        <h2 class="legal-heading">1. Scope and Commitment</h2>
        <p class="legal-text">
          This Privacy Policy explains how <strong><?= e($garageName) ?></strong> collects, encrypts, processes, and safeguards client information across our physical workshop facilities, web client portals, and computerized Garage Management System.
        </p>
        <div class="legal-alert">
          <strong class="legal-promise-title">Our Zero-Sale Promise:</strong> We never sell, lease, or monetize your personal identity, contact details, or vehicle maintenance records to third-party data brokers or marketing advertisers.
        </div>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">2. Information We Collect</h2>
        <p class="legal-text">
          To provide precision automotive diagnostics and certified repairs, we process the following categories of data:
        </p>
        <ul class="legal-list">
          <li><strong>Client Identification:</strong> Full legal name, verified mobile telephone number, email address, and optional billing residency.</li>
          <li><strong>Vehicle Telemetry:</strong> Vehicle Identification Number (VIN), license plate registration, vehicle make/model/year, mileage telemetry, ECU diagnostic fault codes, and mechanical inspection photographs.</li>
          <li><strong>Job Cards &amp; Service History:</strong> Diagnostic assessments, parts consumed, assigned master technicians, labor hours, and historical maintenance logs.</li>
          <li><strong>Invoicing &amp; Transaction Details:</strong> Itemized estimates, tax invoices, payment methods, transaction timestamps, and payment statuses.</li>
          <li><strong>Application Security Telemetry:</strong> IP addresses, browser user-agents, authentication timestamps, and session identifiers collected strictly for brute-force prevention and session protection.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">3. Cryptographic Storage &amp; Architecture Standards</h2>
        <p class="legal-text">
          Our engineering infrastructure adheres to modern information security and zero-trust principles:
        </p>
        <ul class="legal-list">
          <li><strong>AES-256-GCM Encryption:</strong> Sensitive customer records and vehicle telemetry are encrypted at rest using industry-standard Galois/Counter Mode (GCM) ciphers.</li>
          <li><strong>SHA-256 Hash Chaining:</strong> All administrative modifications, parts allocations, and billing transactions are recorded in an append-only, tamper-evident audit ledger chained via SHA-256 cryptographic digests.</li>
          <li><strong>Role-Based Access Control (RBAC):</strong> Workshop staff are strictly restricted to data necessary for their role. Receptionists cannot alter mechanical inspection logs; mechanics cannot alter financial invoices.</li>
          <li><strong>Password Hashing:</strong> User credentials are protected using high-entropy PHP <code>password_hash()</code> with bcrypt or Argon2, incorporating cryptographically random salts.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">4. Cookies &amp; Native PHP Session Protection</h2>
        <p class="legal-text">
          Our application uses native server-side PHP sessions. We do not use third-party tracking pixels, advertising cookies, or intrusive behavioral trackers.
        </p>
        <ul class="legal-list">
          <li>Session cookies are issued with <code>HttpOnly</code> and <code>SameSite=Lax</code> (or <code>Strict</code>) attributes to prevent Cross-Site Scripting (XSS) and Cross-Site Request Forgery (CSRF).</li>
          <li>Session identifiers are automatically regenerated upon login and destroyed upon logout or inactivity expiration.</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">5. Client Rights and Data Access</h2>
        <p class="legal-text">
          As a registered client or vehicle owner, you maintain full control over your digital records:
        </p>
        <ul class="legal-list">
          <li><strong>Right to Digital Inspection:</strong> Access your full vehicle repair and invoice history at any time via your client dashboard.</li>
          <li><strong>Right to Rectification:</strong> Request correction of obsolete contact numbers, email addresses, or vehicle details.</li>
          <li><strong>Right to Export:</strong> Request an itemized digital transcript of all service history and parts replacements.</li>
          <li><strong>Account Closure:</strong> Request account deactivation and removal of non-statutory data (financial records are retained for statutory tax audit periods as mandated by Ethiopian law).</li>
        </ul>
      </div>

      <div class="legal-section">
        <h2 class="legal-heading">6. Data Protection Inquiries &amp; Telegram DPO</h2>
        <p class="legal-text">
          If you have questions regarding data privacy, security auditing, or wish to exercise your data rights, please contact our Data Protection Officer:
        </p>
        <ul class="legal-list">
          <li><strong>Email:</strong> privacy@garage.local</li>
          <li><strong>Verified Telegram:</strong> <a href="https://t.me/GarageManagementSupport" target="_blank" class="legal-link-luxury">@GarageLuxurySupport</a></li>
          <li><strong>Phone Support:</strong> +251 911 234 567</li>
          <li><strong>Online Form:</strong> <a href="<?= e(url('contact.php')) ?>" class="legal-link-champagne">Submit Inquiry via Contact Page</a></li>
        </ul>
      </div>

      <div class="legal-cta-wrap">
        <a href="<?= e(url('register.php')) ?>" class="btn-hero-primary legal-cta-link">
          Return to Account Registration &rarr;
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
