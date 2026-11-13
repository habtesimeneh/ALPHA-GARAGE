<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$garageName = 'Alpha Garage';
try {
    $garageName = Database::value("SELECT setting_value FROM garage_settings WHERE setting_key = 'garage_name'") ?: $garageName;
} catch (Throwable) {}

$successMessage = null;
$errorMessage = null;
$v = [
    'name' => '',
    'email' => '',
    'phone' => '',
    'channel' => 'Telegram',
    'subject' => '',
    'message' => ''
];

if (is_post()) {
    $v['name'] = trim((string) ($_POST['name'] ?? ''));
    $v['email'] = trim((string) ($_POST['email'] ?? ''));
    $v['phone'] = trim((string) ($_POST['phone'] ?? ''));
    $v['channel'] = trim((string) ($_POST['channel'] ?? 'Telegram'));
    $v['subject'] = trim((string) ($_POST['subject'] ?? ''));
    $v['message'] = trim((string) ($_POST['message'] ?? ''));

    if (trim((string) ($_POST['hp_website'] ?? '')) !== '') {
        // Honeypot: acknowledge silently so automated submissions do not learn the trap.
        $successMessage = 'Thank you! Your inquiry has been received. Our support team will respond shortly.';
        $v = ['name' => '', 'email' => '', 'phone' => '', 'channel' => 'Telegram', 'subject' => '', 'message' => ''];
    } elseif (!($limit = RateLimiter::check('contact|' . client_ip(), 5, 600))['allowed']) {
        http_response_code(429);
        header('Retry-After: ' . $limit['retry_after']);
        $errorMessage = 'Too many inquiries from this network. Please wait ' . max(1, (int) ceil($limit['retry_after'] / 60)) . ' minute(s) before trying again.';
    } else {
        $errors = ContactInquiryService::validate($v);
        if ($errors) {
            $errorMessage = 'Please review the form fields and correct the highlighted information.';
        } else {
            try {
                ContactInquiryService::create($v);
                $successMessage = 'Thank you! Your inquiry has been received. Our support team will respond via ' . $v['channel'] . ' shortly.';
                $v = ['name' => '', 'email' => '', 'phone' => '', 'channel' => 'Telegram', 'subject' => '', 'message' => ''];
            } catch (Throwable $e) {
                app_log('Contact inquiry submission error: ' . $e->getMessage());
                $errorMessage = 'We could not save your inquiry right now. Your information is still in the form; please try again shortly.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Contact &amp; Support &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/contact.css')) ?>">
</head>
<body class="landing-page contact-page">

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
      <li><a href="<?= e(url('index.php')) ?>" class="nav-link">Home</a></li>
      <li><a href="<?= e(url('about.php')) ?>" class="nav-link">About Us</a></li>
      <li><a href="<?= e(url('services.php')) ?>" class="nav-link">Our Services</a></li>
      <li><a href="<?= e(url('index.php#why-us')) ?>" class="nav-link">Why Choose Us</a></li>
      <li><a href="<?= e(url('contact.php')) ?>" class="nav-link active contact-nav-link">Contact</a></li>
      <?php if (!current_user()): ?><li class="mobile-nav-login"><a href="<?= e(url('login.php')) ?>" class="nav-link">Client sign in</a></li><?php endif; ?>
    </ul>

    <div class="nav-actions">
      <?php if (current_user() && can('dashboard.view')): ?>
        <a href="<?= e(url('dashboard.php')) ?>" class="btn-nav-cta">Dashboard</a>
      <?php elseif (current_user()): ?>
        <a href="<?= e(url('profile.php')) ?>" class="btn-nav-cta">My Account</a>
      <?php else: ?>
        <a href="<?= e(url('login.php')) ?>" class="btn-nav-login">Sign In</a>
        <a href="<?= e(url('register.php')) ?>" class="btn-nav-cta">Get Started</a>
      <?php endif; ?>
      <button class="nav-toggle-btn" type="button" aria-label="Open navigation menu" aria-controls="primary-navigation" aria-expanded="false"><span aria-hidden="true">&#9776;</span></button>
    </div>
  </div>
</nav>

<!-- -----------------------------------------------------------------------
     Inner Page Hero Header
     ----------------------------------------------------------------------- -->
<header class="inner-hero contact-hero">
  <img class="contact-hero-image" src="https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&amp;fit=crop&amp;w=2200&amp;q=85" alt="" fetchpriority="high">
  <div class="contact-hero-shade" aria-hidden="true"></div>
  <div class="container contact-hero-content">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
      <a href="<?= e(url('index.php')) ?>">Home</a>
      <span>&rsaquo;</span>
      <span>Client Support &amp; Concierge</span>
    </nav>
    <span class="contact-hero-kicker">ALPHA GARAGE <span aria-hidden="true">/</span> HERE TO HELP</span>
    <h1 class="inner-hero-title">Let’s get you back<br>on the road.</h1>
    <p class="inner-hero-lead">Questions about your vehicle or a service visit? Choose the way that works for you, and tell our team what you need.</p>
    <div class="contact-hero-actions">
      <a class="contact-hero-primary" href="<?= current_user() ? e(url('book.php')) : e(url('login.php?redirect=book.php')) ?>">Book a service <span aria-hidden="true">&rarr;</span></a>
      <a class="contact-hero-secondary" href="tel:+251911234567"><span aria-hidden="true">&#9742;</span> Call reception</a>
      <a class="contact-hero-inquiry" href="#contact-inquiry">Send an inquiry <span aria-hidden="true">&rarr;</span></a>
    </div>
    <div class="contact-hero-note"><span class="contact-status-dot" aria-hidden="true"></span> A real team, ready to listen</div>
  </div>
</header>

<!-- -----------------------------------------------------------------------
     Section 1: Contact Channels (Telegram, Phones, Email)
     ----------------------------------------------------------------------- -->
<section class="section section-dark contact-main-section">
  <div class="container">
    <div class="contact-section-heading">
      <span class="contact-section-kicker">A good conversation starts here</span>
      <h2>How can we help?</h2>
      <p>Pick a contact option or send us a note with the details of your vehicle and question.</p>
    </div>
    <div class="contact-channels-grid">
      
      <!-- 1. Telegram Channel & Direct Support -->
      <div class="channel-card channel-telegram">
        <span class="channel-badge badge-telegram">Active &bull; Live Support</span>
        <div class="channel-icon-wrap">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm4.64 6.8c-.15 1.58-.8 5.42-1.13 7.19-.14.75-.42 1-.68 1.03-.58.05-1.02-.38-1.58-.75-.88-.58-1.38-.94-2.23-1.5-.99-.65-.35-1.01.22-1.59.15-.15 2.71-2.48 2.76-2.69a.2.2 0 00-.05-.18c-.06-.05-.14-.03-.21-.02-.09.02-1.49.95-4.22 2.79-.4.27-.76.41-1.08.4-.36-.01-1.04-.2-1.55-.37-.63-.2-1.12-.31-1.08-.66.02-.18.27-.36.75-.55 2.92-1.27 4.86-2.11 5.83-2.51 2.78-1.16 3.35-1.36 3.73-1.36.08 0 .27.02.39.12.1.08.13.19.14.27-.01.06.01.24 0 .38z"/>
          </svg>
        </div>
        <h3 class="channel-title">Official Telegram Channel</h3>
        <p class="channel-desc">
          Join our official channel for priority service updates, maintenance advice, and message our live concierge team directly on Telegram.
        </p>
        <ul class="channel-info-list">
          <li><strong>Channel:</strong> @GarageLuxurySupport</li>
          <li><strong>Response Time:</strong> Under 15 Minutes</li>
          <li><strong>Direct Bot:</strong> @ApexGarageBot</li>
        </ul>
        <a href="https://t.me/GarageManagementSupport" target="_blank" rel="noopener" class="channel-btn channel-btn-telegram">
          Open Telegram Support &rarr;
        </a>
      </div>

      <!-- 2. Mobile Phone & 24/7 Roadside Hotline -->
      <div class="channel-card">
        <span class="channel-badge">24/7 Hotlines</span>
        <div class="channel-icon-wrap">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path>
          </svg>
        </div>
        <h3 class="channel-title">Mobile &amp; Emergency Phone</h3>
        <p class="channel-desc">
          Direct telephone lines to our reception desk, master service advisors, and emergency recovery units.
        </p>
        <ul class="channel-info-list">
          <li><strong>Reception:</strong> +251 911 234 567</li>
          <li><strong>Advisors:</strong> +251 922 345 678</li>
          <li><strong>24/7 Towing:</strong> +251 933 456 789</li>
        </ul>
        <a href="tel:+251911234567" class="channel-btn channel-btn-gold">
          Call Reception Now
        </a>
      </div>

      <!-- 3. Official Email Support -->
      <div class="channel-card">
        <span class="channel-badge">Encrypted Mail</span>
        <div class="channel-icon-wrap">
          <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path>
            <polyline points="22,6 12,13 2,6"></polyline>
          </svg>
        </div>
        <h3 class="channel-title">Official Email Support</h3>
        <p class="channel-desc">
          Send formal diagnostic inquiries, insurance claims, parts telemetry, or corporate fleet proposals.
        </p>
        <ul class="channel-info-list">
          <li><strong>Customer Care:</strong> support@garage.local</li>
          <li><strong>Service &amp; Parts:</strong> service@garage.local</li>
          <li><strong>Invoicing:</strong> billing@garage.local</li>
        </ul>
        <a href="mailto:support@garage.local" class="channel-btn channel-btn-gold">
          Send an Email
        </a>
      </div>

    </div>

    <!-- ---------------------------------------------------------------------
         Contact Form & Facility Information Grid
         --------------------------------------------------------------------- -->
    <div class="contact-grid-custom">
      
      <!-- Interactive Contact Form -->
      <div class="luxury-form-box" id="contact-inquiry">
        <h3 class="contact-form-title">Send Us an Inquiry</h3>
        <p class="contact-form-intro">
          Our master technicians and service managers respond within 2 hours during operational times.
        </p>

        <?php if ($successMessage): ?>
          <div class="contact-message contact-message-success" role="status">
            <strong>Message Received</strong><br>
            <?= e($successMessage) ?>
          </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
          <div class="contact-message contact-message-error" role="alert">
            <?= e($errorMessage) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="<?= e(url('contact.php')) ?>">
          <?= csrf_field() ?>

          <div hidden aria-hidden="true">
            <label for="hp_website">Website</label>
            <input id="hp_website" name="hp_website" type="text" tabindex="-1" autocomplete="off">
          </div>

          <div class="contact-form-row">
            <div class="luxury-form-group">
              <label for="name">Your Name *</label>
              <input class="luxury-input-elem" id="name" name="name" type="text" value="<?= e($v['name']) ?>" required maxlength="120" autocomplete="name" placeholder="e.g. Samuel Bekele">
            </div>
            <div class="luxury-form-group">
              <label for="email">Email Address *</label>
              <input class="luxury-input-elem" id="email" name="email" type="email" value="<?= e($v['email']) ?>" required maxlength="150" autocomplete="email" placeholder="e.g. samuel@example.com">
            </div>
          </div>

          <div class="contact-form-row">
            <div class="luxury-form-group">
              <label for="phone">Mobile Phone</label>
              <input class="luxury-input-elem" id="phone" name="phone" type="tel" value="<?= e($v['phone']) ?>" maxlength="40" autocomplete="tel" placeholder="e.g. +251 911 000 000">
            </div>
            <div class="luxury-form-group">
              <label for="channel">Preferred Contact Channel</label>
              <select class="luxury-select-elem" id="channel" name="channel">
                <option value="Telegram" <?= $v['channel'] === 'Telegram' ? 'selected' : '' ?>>Telegram Message</option>
                <option value="Phone Call" <?= $v['channel'] === 'Phone Call' ? 'selected' : '' ?>>Direct Phone Call</option>
                <option value="Email" <?= $v['channel'] === 'Email' ? 'selected' : '' ?>>Official Email</option>
                <option value="SMS" <?= $v['channel'] === 'SMS' ? 'selected' : '' ?>>SMS Text</option>
              </select>
            </div>
          </div>

          <div class="luxury-form-group">
            <label for="subject">Inquiry Subject *</label>
            <input class="luxury-input-elem" id="subject" name="subject" type="text" value="<?= e($v['subject']) ?>" required maxlength="150" placeholder="e.g. Schedule Major Service for Mercedes E350">
          </div>

          <div class="luxury-form-group">
            <label for="message">Detailed Message *</label>
            <textarea class="luxury-input-elem" id="message" name="message" rows="4" required maxlength="5000" placeholder="Describe vehicle symptoms, desired service date, or inquiry..."><?= e($v['message']) ?></textarea>
          </div>

          <button type="submit" class="channel-btn channel-btn-gold contact-submit">
            Submit Inquiry Securely &rarr;
          </button>
        </form>
      </div>

      <!-- Facility Details, Location & Working Hours -->
      <div class="contact-facility-column">
        
        <div class="luxury-form-box">
          <h3 class="facility-title">Workshop Location &amp; Facility</h3>
          <p class="facility-description">
            <strong><?= e($garageName) ?> Master Workshop</strong><br>
            Bole Sub-City, Next to Millennium Hall<br>
            Luxury Automotive Boulevard, Addis Ababa, Ethiopia
          </p>
          <div class="facility-directions">
            <div class="facility-directions-title">GPS Coordinates &amp; Directions</div>
            <div class="facility-directions-text">
              8&deg;59'54.2"N 38&deg;47'28.5"E &bull; Dedicated security gate, flatbed towing access, and customer lounge with high-speed fiber Wi-Fi.
            </div>
          </div>
        </div>

        <div class="luxury-form-box">
          <h3 class="facility-title">Operating Hours</h3>
          <div class="hours-pill">
            <span class="hours-label">Monday &ndash; Friday</span>
            <span class="hours-value">8:00 AM &ndash; 7:00 PM</span>
          </div>
          <div class="hours-pill">
            <span class="hours-label">Saturday</span>
            <span class="hours-value">8:30 AM &ndash; 5:00 PM</span>
          </div>
          <div class="hours-pill">
            <span class="hours-label">Sunday</span>
            <span class="hours-closed">Emergency Triage &amp; Towing Only</span>
          </div>
          <div class="hours-pill hours-emergency">
            <span class="hours-emergency-label">24/7 Roadside Rescue</span>
            <span class="hours-emergency-status">Always On Standby</span>
          </div>
        </div>

      </div>

    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Luxury Footer
     ----------------------------------------------------------------------- -->
<footer class="landing-footer">
  <div class="footer-top">
    <div>
      <div class="footer-brand"><?= e($garageName) ?></div>
      <p class="footer-desc">
        Pioneering precision automotive engineering, transparent digital maintenance, and cryptographic data security.
      </p>
      <div class="footer-telegram">
        Telegram: <a href="https://t.me/GarageManagementSupport" target="_blank" rel="noopener noreferrer" class="footer-telegram-link">@GarageLuxurySupport</a>
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
</body>
</html>
