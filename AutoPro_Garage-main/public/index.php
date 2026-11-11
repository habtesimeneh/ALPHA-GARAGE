<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');
$garagePhone = Setting::get('garage_phone', '+251 11 661 2233');
$garageEmail = Setting::get('garage_email', 'service@autopro-luxury.com');
$garageAddress = Setting::get('garage_address', 'Bole Subcity, Airport Road, Addis Ababa, Ethiopia');
$isLoggedIn = current_user() !== null;

// Never present example prices as live catalog data when the database is unavailable.
$services = [];
$serviceCatalogError = false;
try {
    $services = Database::all('SELECT * FROM services WHERE is_active = 1 ORDER BY id ASC LIMIT 6');
} catch (Throwable $e) {
    app_log('Public service catalog query failed: ' . $e->getMessage());
    $serviceCatalogError = true;
}

// Background images for luxury automotive slider
$sliderImages = [
    'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=2000&q=80',
    'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=2000&q=80',
    'https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&fit=crop&w=2000&q=80',
    'https://images.unsplash.com/photo-1489824904134-891ab64532f1?auto=format&fit=crop&w=2000&q=80',
    'https://images.unsplash.com/photo-1511919884226-fd3cad34687c?auto=format&fit=crop&w=2000&q=80',
];

$serviceImageForCategory = static function (string $category): string {
    $category = strtolower($category);
    if (str_contains($category, 'brake')) {
        return 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($category, 'susp') || str_contains($category, 'wheel')) {
        return 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($category, 'maint') || str_contains($category, 'oil')) {
        return 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($category, 'elect') || str_contains($category, 'battery')) {
        return 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($category, 'ac') || str_contains($category, 'heat')) {
        return 'https://images.unsplash.com/photo-1489824904134-891ab64532f1?auto=format&fit=crop&w=900&q=80';
    }
    if (str_contains($category, 'trans')) {
        return 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=900&q=80';
    }
    return 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=900&q=80';
};
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($garageName) ?> &middot; Luxury Automotive Engineering &amp; Service</title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
</head>
<body class="landing-page">
<a class="skip-link" href="#main-content">Skip to main content</a>

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
    <a href="#hero" class="nav-brand">
      <div class="nav-logo-icon">
        <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36">
      </div>
      <div>
        <span class="nav-brand-title"><?= e($garageName) ?></span>
        <span class="nav-brand-sub">Luxury Automotive Care</span>
      </div>
    </a>

    <ul class="nav-menu-links" id="primary-navigation">
      <li><a href="#hero" class="nav-link">Home</a></li>
      <li><a href="<?= e(url('services.php')) ?>" class="nav-link">Our Services</a></li>
      <li><a href="#how-it-works" class="nav-link">How It Works</a></li>
      <li><a href="<?= e(url('about.php')) ?>" class="nav-link">About &amp; FAQ</a></li>
      <li><a href="#contact" class="nav-link">Contact</a></li>
      <?php if (!current_user()): ?>
        <li class="mobile-nav-login"><a href="<?= e(url('login.php')) ?>" class="nav-link">Client sign in</a></li>
      <?php endif; ?>
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
     Full-Screen Automotive Hero Section
     ----------------------------------------------------------------------- -->
<main id="main-content">
<section id="hero" class="landing-hero">
  <!-- Crossfading Automotive Background Slider -->
  <div class="luxury-slider" id="hero-slider">
    <?php foreach ($sliderImages as $i => $imgUrl): ?>
      <div class="luxury-slide <?= $i === 0 ? 'active' : '' ?>">
        <img class="luxury-slide-image" src="<?= e($imgUrl) ?>" alt="" aria-hidden="true" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>"<?= $i === 0 ? ' fetchpriority="high"' : '' ?>>
      </div>
    <?php endforeach; ?>
    <div class="luxury-slider-overlay"></div>
  </div>

  <div class="hero-content">
    <div class="hero-copy">
      <div class="hero-eyebrow">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="#D4AF37" aria-hidden="true"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon></svg>
        Expert care. Clear choices.
      </div>

      <h1 class="hero-title" aria-label="Confident care for every drive.">Confident care for <span class="hero-title-accent" data-typewriter='["every drive.", "every journey.", "your next trip.", "your Alpha Garage."]'>every drive.</span></h1>
      <p class="hero-lead">Find the right service, book your visit, and stay informed while your vehicle is in our care.</p>

      <div class="hero-ctas">
        <a href="<?= current_user() ? e(url('appointments/form.php')) : e(url('login.php?redirect=appointments/form.php')) ?>" class="btn-hero-primary">Book a service <span aria-hidden="true">&rarr;</span></a>
        <a href="<?= e(url('services.php')) ?>" class="btn-hero-secondary">Explore services</a>
      </div>

      <div class="hero-promises" aria-label="What to expect">
        <span><span aria-hidden="true">&#10003;</span> Clear service details</span>
        <span><span aria-hidden="true">&#10003;</span> Convenient online booking</span>
      </div>
    </div>

    <aside class="hero-booking-card" aria-label="Quick booking information">
      <span class="hero-card-kicker">Your next step</span>
      <h2>Let's get you back on the road.</h2>
      <p>Choose a service or talk with our team if you are not sure where to start.</p>
      <a class="hero-card-link" href="<?= current_user() ? e(url('appointments/form.php')) : e(url('login.php?redirect=appointments/form.php')) ?>">
        <span class="hero-card-link-icon" aria-hidden="true">&#128663;</span>
        <span><strong>Book online</strong><small>Request an appointment in a few steps</small></span>
        <span class="hero-card-arrow" aria-hidden="true">&rarr;</span>
      </a>
      <a class="hero-card-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $garagePhone)) ?>">
        <span class="hero-card-link-icon" aria-hidden="true">&#9742;</span>
        <span><strong>Call our team</strong><small><?= e($garagePhone) ?></small></span>
        <span class="hero-card-arrow" aria-hidden="true">&rarr;</span>
      </a>
      <a class="hero-card-help" href="<?= e(url('about.php')) ?>">New here? Visit our FAQ <span aria-hidden="true">&rarr;</span></a>
    </div>
  </div>
</section>

<section class="feature-marquee" aria-label="Alpha Garage service features">
  <div class="feature-marquee-heading"><span></span>THE ALPHA GARAGE DIFFERENCE<span></span></div>
  <div class="feature-marquee-window">
    <div class="feature-marquee-track">
      <ul class="feature-marquee-list">
        <li>Clear service details</li>
        <li>Standard prices to review</li>
        <li>Estimated service times</li>
        <li>Convenient online booking</li>
        <li>Helpful service guidance</li>
        <li>Vehicle-focused care</li>
        <li>Customer account access</li>
        <li>Easy ways to reach our team</li>
        <li>Responsive on every device</li>
        <li>Support from request to visit</li>
      </ul>
      <ul class="feature-marquee-list" aria-hidden="true">
        <li>Clear service details</li>
        <li>Standard prices to review</li>
        <li>Estimated service times</li>
        <li>Convenient online booking</li>
        <li>Helpful service guidance</li>
        <li>Vehicle-focused care</li>
        <li>Customer account access</li>
        <li>Easy ways to reach our team</li>
        <li>Responsive on every device</li>
        <li>Support from request to visit</li>
      </ul>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 1: Customer trust
     ----------------------------------------------------------------------- -->
<section class="section section-emerald trust-promise-section">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">Trusted customer care</span>
      <h2 class="section-title">Trust is earned at every visit</h2>
      <p class="section-desc">A thoughtful service experience starts with clear information, helpful people, and care that keeps you informed.</p>
    </div>

    <div class="journey-carousel testimonial-carousel trust-carousel" role="region" aria-roledescription="carousel" aria-label="The Alpha Garage customer experience" tabindex="0">
      <div class="journey-carousel-slides" aria-live="off">
        <article class="journey-card journey-slide is-active" role="group" aria-roledescription="slide" aria-label="1 of 3">
          <span class="trust-slide-kicker"><span aria-hidden="true">&#10022;</span> THE ALPHA GARAGE PROMISE</span>
          <span class="journey-icon trust-slide-icon" aria-hidden="true">&#128196;</span>
          <h3>Clear choices, before you book</h3>
          <p>Explore service details, standard pricing, and estimated time so you can choose your next step with confidence.</p>
          <a href="<?= e(url('services.php')) ?>">Explore our services <span aria-hidden="true">&rarr;</span></a>
        </article>
        <article class="journey-card journey-slide" role="group" aria-roledescription="slide" aria-label="2 of 3" hidden>
          <span class="trust-slide-kicker"><span aria-hidden="true">&#10022;</span> HERE WHEN YOU NEED US</span>
          <span class="journey-icon trust-slide-icon" aria-hidden="true">&#128172;</span>
          <h3>Helpful people, ready to listen</h3>
          <p>Not sure what your vehicle needs? Reach out to our team and get guidance on the best place to start.</p>
          <a href="<?= e(url('contact.php')) ?>">Talk with our team <span aria-hidden="true">&rarr;</span></a>
        </article>
        <article class="journey-card journey-slide request-journey-slide" role="group" aria-roledescription="slide" aria-label="3 of 3" hidden>
          <span class="trust-slide-kicker"><span aria-hidden="true">&#10022;</span> CARE THAT KEEPS YOU INFORMED</span>
          <span class="journey-icon trust-slide-icon" aria-hidden="true">&#128197;</span>
          <h3>A simple path from request to visit</h3>
          <p>Send your request online, hear from our team, and arrive knowing what to expect.</p>
          <ol class="request-journey-steps" aria-label="Appointment request steps">
            <li><span class="request-step-marker">01</span><span class="request-step-label">Request</span></li>
            <li><span class="request-step-marker">02</span><span class="request-step-label">We follow up</span></li>
            <li><span class="request-step-marker">03</span><span class="request-step-label">Visit the garage</span></li>
          </ol>
          <a href="<?= current_user() ? e(url('appointments/form.php')) : e(url('login.php?redirect=appointments/form.php')) ?>">Request a visit <span aria-hidden="true">&rarr;</span></a>
        </article>
      </div>
      <div class="journey-carousel-controls">
        <button class="journey-carousel-button journey-previous" type="button" aria-label="Previous customer promise">&#8592;</button>
        <div class="journey-carousel-dots" role="group" aria-label="Choose a customer promise">
          <button type="button" class="journey-carousel-dot is-active" aria-label="Show customer promise 1" aria-current="true"></button>
          <button type="button" class="journey-carousel-dot" aria-label="Show customer promise 2"></button>
          <button type="button" class="journey-carousel-dot" aria-label="Show customer promise 3"></button>
        </div>
        <button class="journey-carousel-button journey-next" type="button" aria-label="Next customer promise">&#8594;</button>
        <button class="journey-carousel-pause" type="button" aria-pressed="false">Pause</button>
      </div>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 2: About Our Garage & Experience
     ----------------------------------------------------------------------- -->
<section id="about" class="section section-dark">
  <div class="container">
    <div class="about-grid">
      <div class="about-image-wrap">
        <img class="about-image" src="https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&fit=crop&w=1200&q=80" alt="Vehicle ready for professional automotive care" loading="lazy">
        <div class="about-badge">
          <div class="about-badge-num">Your car</div>
          <div class="about-badge-label">Our care, every step</div>
        </div>
      </div>

      <div class="about-content">
        <span class="section-eyebrow">Automotive care, made personal</span>
        <h2 class="section-title">Expert help, without the guesswork</h2>
        <p class="section-desc">
          Tell us what your vehicle needs and our team will help you find the right service for your next visit.
        </p>
        <p class="section-desc">
          From your first question to your appointment request, we make it easy to understand the options and take the next step.
        </p>

        <div class="about-action">
          <a href="<?= e(url('about.php')) ?>" class="btn-hero-primary about-action-button">Meet the garage <span aria-hidden="true">&rarr;</span></a>
        </div>
      </div>
    </div>

    <!-- Helpful customer benefits -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-num" aria-hidden="true">&#128196;</div>
        <div class="stat-label">Service details before you book</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" aria-hidden="true">&#128197;</div>
        <div class="stat-label">Simple appointment requests</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" aria-hidden="true">&#128172;</div>
        <div class="stat-label">A team ready to answer questions</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" aria-hidden="true">&#128663;</div>
        <div class="stat-label">Support for your vehicle's needs</div>
      </div>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 3: Master Services Catalog
     ----------------------------------------------------------------------- -->
<section id="services" class="section section-deep section-services home-services-showcase">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">Find the right care</span>
      <h2 class="section-title">Services made easier to choose</h2>
      <p class="section-desc">Compare service details, estimated time, and standard pricing, then book the option that fits your vehicle.</p>
    </div>

    <?php if ($serviceCatalogError): ?>
      <p class="catalog-state" role="status">Our service catalog is temporarily unavailable. Please contact us for current services and pricing.</p>
    <?php elseif (!$services): ?>
      <p class="catalog-state" role="status">No services are currently listed. Please contact us for assistance.</p>
    <?php else: ?>
    <div class="services-grid">
      <?php foreach ($services as $srv): ?>
        <?php
          $serviceId = isset($srv['id']) ? (int) $srv['id'] : 0;
          $servicePath = 'appointments/form.php' . ($serviceId > 0 ? '?service_id=' . $serviceId : '');
          $serviceBookingUrl = $isLoggedIn
              ? url($servicePath)
              : url('login.php?redirect=' . rawurlencode($servicePath));
        ?>
        <article class="service-card">
          <div class="service-card-image">
            <img src="<?= e($serviceImageForCategory((string) $srv['category'])) ?>" alt="" loading="lazy">
            <span class="service-category"><?= e($srv['category']) ?></span>
            <div class="service-icon-box" aria-hidden="true">
            <?php
              $cat = strtolower($srv['category']);
              if (str_contains($cat, 'diag')) echo '&#9881;';
              elseif (str_contains($cat, 'brake')) echo '&#128737;';
              elseif (str_contains($cat, 'maint') || str_contains($cat, 'oil')) echo '&#128295;';
              elseif (str_contains($cat, 'susp')) echo '&#9878;';
              elseif (str_contains($cat, 'ac') || str_contains($cat, 'heat')) echo '&#10052;';
              else echo '&#128663;';
            ?>
            </div>
          </div>
          <h3 class="service-title"><?= e($srv['name']) ?></h3>
          <p class="service-desc"><?= e($srv['description'] ?: 'Professional automotive service tailored to your vehicle and its needs.') ?></p>
          <div class="service-meta">
            <span><strong><?= e(money($srv['standard_price'])) ?></strong><small>Standard price</small></span>
            <span><strong><?= e($srv['estimated_hours']) ?> hrs</strong><small>Estimated time</small></span>
          </div>
          <a class="service-booking-link" href="<?= e($serviceBookingUrl) ?>">Book this service <span aria-hidden="true">&rarr;</span></a>
        </article>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="services-cta">
      <a href="<?= current_user() ? e(url('appointments/form.php')) : e(url('login.php?redirect=appointments/form.php')) ?>" class="btn-hero-primary">Book an Inspection</a>
      <a href="<?= e(url('services.php')) ?>" class="btn-hero-secondary">View all services <span aria-hidden="true">&rarr;</span></a>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 4: Why Choose Us
     ----------------------------------------------------------------------- -->
<section id="why-us" class="section section-emerald">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">The Alpha Garage Standard</span>
      <h2 class="section-title">Why Drivers Choose Alpha Garage</h2>
      <p class="section-desc">Helpful guidance, clear service information, and simple ways to take your next step.</p>
    </div>

    <div class="features-grid">
      <div class="feature-box">
        <div class="feature-icon">&#9889;</div>
        <h3 class="feature-title">A clear starting point</h3>
        <p class="feature-desc">Tell us what you have noticed and we will help you choose an appropriate service to start with.</p>
      </div>

      <div class="feature-box">
        <div class="feature-icon">&#128104;&#8205;&#128295;</div>
        <h3 class="feature-title">Helpful service team</h3>
        <p class="feature-desc">Contact our team with questions about your vehicle or the services listed in the catalog.</p>
      </div>

      <div class="feature-box">
        <div class="feature-icon">&#128737;</div>
        <h3 class="feature-title">Know what to expect</h3>
        <p class="feature-desc">Review standard pricing and estimated service time before sending an appointment request.</p>
      </div>

      <div class="feature-box">
        <div class="feature-icon">&#128172;</div>
        <h3 class="feature-title">Stay in the loop</h3>
        <p class="feature-desc">Use your account to follow your appointment and service information in one place.</p>
      </div>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 5: How Our Service Works (Process)
     ----------------------------------------------------------------------- -->
<section id="how-it-works" class="section section-dark workflow-showcase">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">Transparent Workflow</span>
      <h2 class="section-title">How Our Service Works</h2>
      <p class="section-desc">See how to choose a service, send an appointment request, and keep in touch with our team.</p>
    </div>

    <div class="steps-grid">
      <div class="step-card">
        <div class="step-number">01</div>
        <h3 class="step-title">Schedule Online</h3>
        <p class="feature-desc">Book your appointment online with your preferred time slot and initial symptoms description.</p>
      </div>

      <div class="step-card">
        <div class="step-number">02</div>
        <h3 class="step-title">Digital Intake &amp; Scan</h3>
        <p class="feature-desc">Share your vehicle details and the concerns you would like our team to review.</p>
      </div>

      <div class="step-card">
        <div class="step-number">03</div>
        <h3 class="step-title">Precision Repair</h3>
        <p class="feature-desc">Our team reviews the service request and works with you on the next steps.</p>
      </div>

      <div class="step-card">
        <div class="step-number">04</div>
        <h3 class="step-title">Quality Handover</h3>
        <p class="feature-desc">We will help coordinate the follow-up and answer any questions about your service.</p>
      </div>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 6: Helpful next steps
     ----------------------------------------------------------------------- -->
<section class="section section-deep service-journey-section">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">A smooth start</span>
      <h2 class="section-title">A better service visit starts here</h2>
      <p class="section-desc">Start online or contact our team. We will help you find the right next step.</p>
    </div>

    <div class="journey-carousel" role="region" aria-roledescription="carousel" aria-label="Helpful steps for your visit" tabindex="0">
      <div class="journey-carousel-slides" aria-live="off">
      <article class="journey-card journey-slide is-active" role="group" aria-roledescription="slide" aria-label="1 of 3">
        <span class="journey-number">01</span>
        <span class="journey-icon" aria-hidden="true">&#128269;</span>
        <h3>Choose a service</h3>
        <p>Review the catalog and find the service that best matches what your vehicle needs.</p>
        <a href="<?= e(url('services.php')) ?>">Browse services <span aria-hidden="true">&rarr;</span></a>
      </article>
      <article class="journey-card journey-slide" role="group" aria-roledescription="slide" aria-label="2 of 3" hidden>
        <span class="journey-number">02</span>
        <span class="journey-icon" aria-hidden="true">&#128197;</span>
        <h3>Request a visit</h3>
        <p>Sign in or create an account to send an appointment request online.</p>
        <a href="<?= current_user() ? e(url('appointments/form.php')) : e(url('register.php')) ?>">Get started <span aria-hidden="true">&rarr;</span></a>
      </article>
      <article class="journey-card journey-slide" role="group" aria-roledescription="slide" aria-label="3 of 3" hidden>
        <span class="journey-number">03</span>
        <span class="journey-icon" aria-hidden="true">&#128222;</span>
        <h3>Talk to our team</h3>
        <p>Ask a question before booking. We are happy to help you decide what to do next.</p>
        <a href="<?= e(url('contact.php')) ?>">Contact support <span aria-hidden="true">&rarr;</span></a>
      </article>
      </div>
      <div class="journey-carousel-controls">
        <button class="journey-carousel-button journey-previous" type="button" aria-label="Previous step">&#8592;</button>
        <div class="journey-carousel-dots" role="group" aria-label="Choose a step">
          <button type="button" class="journey-carousel-dot is-active" aria-label="Show step 1" aria-current="true"></button>
          <button type="button" class="journey-carousel-dot" aria-label="Show step 2"></button>
          <button type="button" class="journey-carousel-dot" aria-label="Show step 3"></button>
        </div>
        <button class="journey-carousel-button journey-next" type="button" aria-label="Next step">&#8594;</button>
        <button class="journey-carousel-pause" type="button" aria-pressed="false">Pause</button>
      </div>
    </div>
  </div>
</section>

<!-- -----------------------------------------------------------------------
     Section 7: Contact & Location
     ----------------------------------------------------------------------- -->
<section id="contact" class="section section-dark">
  <div class="container">
    <div class="contact-grid">
      <div>
        <span class="section-eyebrow">Get in Touch</span>
        <h2 class="section-title">Visit Our Premier Facility</h2>
        <p class="section-desc">We welcome you to visit our clean, modern workshop. Our service advisors are ready to consult on your vehicle's specific requirements.</p>

        <div class="contact-info-list">
          <div class="contact-item">
            <div class="contact-icon">&#128205;</div>
            <div>
              <div class="contact-title">Workshop Location</div>
              <p class="contact-text"><?= e($garageAddress) ?></p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon">&#128222;</div>
            <div>
              <div class="contact-title">Direct Telephone</div>
              <p class="contact-text"><a class="contact-detail-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $garagePhone)) ?>"><?= e($garagePhone) ?></a></p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon">&#9993;</div>
            <div>
              <div class="contact-title">Customer Inquiries</div>
              <p class="contact-text"><a class="contact-detail-link" href="mailto:<?= e($garageEmail) ?>"><?= e($garageEmail) ?></a></p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon">&#128344;</div>
            <div>
              <div class="contact-title">Operating Hours</div>
              <p class="contact-text">Monday &ndash; Saturday: 8:00 AM &ndash; 6:30 PM &middot; Sunday: Emergency Service</p>
            </div>
          </div>

          <div class="contact-item">
            <div class="contact-icon contact-icon-telegram">&#9992;</div>
            <div>
              <div class="contact-title">Telegram Support</div>
              <p class="contact-text"><a href="https://t.me/GarageManagementSupport" target="_blank" rel="noopener noreferrer" class="contact-telegram-link">@GarageLuxurySupport</a> &middot; Instant Dispatch</p>
            </div>
          </div>
        </div>
      </div>

      <div class="contact-cta-panel">
        <h3 class="contact-cta-title">Ready for Premium Care?</h3>
        <p class="contact-cta-description">Create a client account or sign in to schedule your vehicle's upcoming maintenance appointment.</p>
        <div class="contact-cta-links">
          <a href="<?= e(url('contact.php')) ?>" class="btn-hero-primary contact-cta-link">Open Full Contact &amp; Support Page</a>
          <a href="<?= e(url('register.php')) ?>" class="btn-hero-secondary contact-cta-link">Register an Account</a>
          <a href="<?= e(url('login.php')) ?>" class="btn-hero-secondary contact-cta-link">Client Sign In</a>
        </div>
      </div>
    </div>
  </div>
</section>
</main>

<!-- -----------------------------------------------------------------------
     Section 8: Luxury Footer
     ----------------------------------------------------------------------- -->
<footer class="luxury-footer">
  <div class="footer-top">
    <div>
      <div class="footer-brand-row">
        <div class="nav-logo-icon footer-logo-icon">
          <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="30" height="30">
        </div>
        <span class="footer-brand-name"><?= e($garageName) ?></span>
      </div>
      <p class="footer-description">
        Excellence in every detail. Professional automotive diagnostics, certified repairs, and transparent digital service for discerning motorists.
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
        <li><a href="<?= e(url('contact.php')) ?>">Contact Support &amp; Telegram</a></li>
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

<div class="mobile-quick-actions">
  <button class="mobile-quick-actions-toggle" type="button" aria-expanded="false" aria-controls="mobile-quick-actions-options">
    Call or book a service <span aria-hidden="true">&#8593;</span>
  </button>
  <div class="mobile-quick-actions-options" id="mobile-quick-actions-options" hidden>
    <a class="mobile-call-action" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $garagePhone)) ?>">
      <span aria-hidden="true">&#9742;</span> Call <?= e($garagePhone) ?>
    </a>
    <a class="mobile-book-action" href="<?= current_user() ? e(url('appointments/form.php')) : e(url('login.php?redirect=appointments/form.php')) ?>">
      Book a service <span aria-hidden="true">&rarr;</span>
    </a>
  </div>
</div>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
</body>
</html>
