<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$garageName = 'Alpha Garage';
try {
    $garageName = Database::value("SELECT setting_value FROM garage_settings WHERE setting_key = 'garage_name'") ?: $garageName;
} catch (Throwable) {}

$faqs = [
    ['q' => 'What kinds of vehicles can I ask about?', 'a' => 'We welcome inquiries about a range of makes and models. Share your vehicle details and the issue you are experiencing so our team can confirm the right service before you book.'],
    ['q' => 'What happens after I request an appointment?', 'a' => 'Choose a service and preferred time through the booking flow. The garage team can review the request and follow up to confirm the visit and discuss the next steps.'],
    ['q' => 'Will I know the estimated cost before work begins?', 'a' => 'Ask for an estimate during your service consultation. The work required and any parts or additional costs should be discussed with you before you authorize repairs.'],
    ['q' => 'Can I track my service request?', 'a' => 'Sign in to your account to access the customer features currently available to you. If you need an update, contact the garage team with your appointment details.'],
    ['q' => 'How can I contact the garage?', 'a' => 'Use the contact page to send a service inquiry or find the available contact options.'],
    ['q' => 'How do I reset my password?', 'a' => 'Select “Forgot password?” on the sign-in page and follow the instructions. Password recovery depends on the email delivery settings for this installation.'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>About Us &middot; <?= e($garageName) ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/about.css')) ?>">
</head>
<body class="landing-page about-page">

<nav class="luxury-navbar" aria-label="Main Navigation">
  <div class="nav-container">
    <a href="<?= e(url('index.php')) ?>" class="nav-brand">
      <div class="nav-logo-icon"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36"></div>
      <div><span class="nav-brand-title"><?= e($garageName) ?></span><span class="nav-brand-sub">Luxury Automotive Care</span></div>
    </a>
    <ul class="nav-menu-links" id="primary-navigation">
      <li><a href="<?= e(url('index.php')) ?>" class="nav-link">Home</a></li>
      <li><a href="<?= e(url('about.php')) ?>" class="nav-link active" aria-current="page">About Us</a></li>
      <li><a href="<?= e(url('services.php')) ?>" class="nav-link">Our Services</a></li>
      <li><a href="<?= e(url('contact.php')) ?>" class="nav-link">Contact</a></li>
    </ul>
    <div class="nav-actions">
      <?php if (current_user() && can('dashboard.view')): ?><a href="<?= e(url('dashboard.php')) ?>" class="btn-nav-cta">Dashboard</a>
      <?php elseif (current_user()): ?><a href="<?= e(url('profile.php')) ?>" class="btn-nav-cta">My Account</a>
      <?php else: ?><a href="<?= e(url('login.php')) ?>" class="btn-nav-login">Sign In</a><a href="<?= e(url('register.php')) ?>" class="btn-nav-cta">Get Started</a><?php endif; ?>
      <button class="nav-toggle-btn" type="button" aria-label="Open navigation menu" aria-controls="primary-navigation" aria-expanded="false"><span aria-hidden="true">&#9776;</span></button>
    </div>
  </div>
</nav>

<header class="about-hero-modern">
  <img class="about-hero-image" src="https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&amp;fit=crop&amp;w=2200&amp;q=85" alt="" fetchpriority="high">
  <div class="about-hero-shade" aria-hidden="true"></div>
  <div class="about-hero-content">
    <span class="section-eyebrow">A better kind of workshop experience</span>
    <h1>Care for every mile.<br><span class="about-typewriter" data-typewriter='["Clear answers.", "Thoughtful service.", "Confidence on the road."]' aria-label="Clear answers, thoughtful service, confidence on the road.">Clear answers.</span></h1>
    <p>Meet the people, practical expertise, and customer-first approach behind <?= e($garageName) ?>.</p>
    <div class="about-hero-actions">
      <a href="<?= e(url('contact.php')) ?>" class="btn-hero-primary">Contact Support</a>
      <a href="<?= e(url('services.php')) ?>" class="btn-hero-secondary">Explore Services</a>
    </div>
    <a class="about-scroll-cue" href="#our-approach"><span aria-hidden="true">↓</span> Discover our approach</a>
  </div>
</header>

<section class="section section-dark about-story" id="our-approach">
  <div class="container">
    <div class="about-grid">
      <div class="about-image-wrap">
        <img class="about-image" src="https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&amp;fit=crop&amp;w=1100&amp;q=85" alt="Automotive workshop scene" loading="lazy">
        <div class="about-photo-caption"><span class="about-photo-caption-mark" aria-hidden="true">✦</span><span>Careful work. Clear communication.</span></div>
      </div>
      <div class="about-content">
        <span class="section-eyebrow">Our approach</span>
        <h2 class="section-title">Good service starts with listening.</h2>
        <p class="section-desc">Every vehicle arrives with its own story. We start by understanding your concerns, inspecting the details, and explaining the options in language you can use to make an informed decision.</p>
        <p class="section-desc">From the first conversation through the final check, our aim is a straightforward experience: thoughtful diagnostics, clear communication, and care tailored to your vehicle.</p>
        <a class="about-text-link" href="<?= e(url('contact.php')) ?>">Talk with our team <span aria-hidden="true">→</span></a>
      </div>
    </div>
  </div>
</section>

<section class="section section-emerald about-specialties" id="specialties">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">People behind the service</span>
      <h2 class="section-title">A closer look at workshop care</h2>
      <p class="section-desc">Explore common areas of vehicle care and what a technician may check. The right service depends on your vehicle and symptoms.</p>
    </div>
    <div class="about-specialty-layout" data-specialty-explorer>
      <div class="about-specialty-tabs" role="tablist" aria-label="Explore workshop specialties">
        <button class="about-specialty-tab is-active" id="specialty-tab-diagnostics" type="button" role="tab" aria-selected="true" aria-controls="specialty-panel" tabindex="0" data-specialty="diagnostics">
          <span class="about-specialty-index">01</span><span><strong>Diagnostics</strong><small>Find the cause, not just the symptom</small></span><span class="about-tab-arrow" aria-hidden="true">→</span>
        </button>
        <button class="about-specialty-tab" id="specialty-tab-engine" type="button" role="tab" aria-selected="false" aria-controls="specialty-panel" tabindex="-1" data-specialty="engine">
          <span class="about-specialty-index">02</span><span><strong>Engine &amp; maintenance</strong><small>Keep essential systems in check</small></span><span class="about-tab-arrow" aria-hidden="true">→</span>
        </button>
        <button class="about-specialty-tab" id="specialty-tab-brakes" type="button" role="tab" aria-selected="false" aria-controls="specialty-panel" tabindex="-1" data-specialty="brakes">
          <span class="about-specialty-index">03</span><span><strong>Brakes &amp; suspension</strong><small>Listen for changes in handling</small></span><span class="about-tab-arrow" aria-hidden="true">→</span>
        </button>
        <button class="about-specialty-tab" id="specialty-tab-electrical" type="button" role="tab" aria-selected="false" aria-controls="specialty-panel" tabindex="-1" data-specialty="electrical">
          <span class="about-specialty-index">04</span><span><strong>Electrical systems</strong><small>Trace warning lights and faults</small></span><span class="about-tab-arrow" aria-hidden="true">→</span>
        </button>
      </div>
      <article class="about-specialty-panel" id="specialty-panel" role="tabpanel" aria-labelledby="specialty-tab-diagnostics" tabindex="0">
        <div class="about-specialty-panel-image-wrap">
          <img class="about-specialty-image" src="https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&amp;fit=crop&amp;w=1200&amp;q=85" alt="Vehicle in an automotive workshop" loading="lazy" data-specialty-image>
          <span class="about-image-label"><span aria-hidden="true">●</span> Workshop focus</span>
        </div>
        <div class="about-specialty-copy">
          <span class="about-panel-kicker" data-specialty-kicker>Start with the evidence</span>
          <h3 data-specialty-title>Vehicle diagnostics</h3>
          <p data-specialty-description>A technician can review warning indicators, listen to your observations, and inspect relevant systems to help identify possible causes before recommending next steps.</p>
          <a class="about-text-link" href="<?= e(url('services.php')) ?>">View available services <span aria-hidden="true">→</span></a>
        </div>
      </article>
    </div>
    <noscript><p class="about-noscript-note">Interactive specialty details require JavaScript. You can still browse all available services on our services page.</p></noscript>
  </div>
</section>

<section class="section section-dark about-digital">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">A connected experience</span>
      <h2 class="section-title">Service details, thoughtfully organized.</h2>
      <p class="section-desc">Digital tools help keep the practical details of a service visit in one place for the team.</p>
    </div>
    <div class="modern-grid">
      <?php
      $features = [
        ['n'=>'01','t'=>'Appointment requests','d'=>'Share your vehicle, preferred time, and service concern so the team has helpful context before your visit.'],
        ['n'=>'02','t'=>'Service records','d'=>'Digital job details help organize vehicle concerns, work notes, and the next steps for a service visit.'],
        ['n'=>'03','t'=>'Clear conversations','d'=>'Keep questions and service updates centered on the work being discussed, rather than confusing jargon.'],
        ['n'=>'04','t'=>'A considered handover','d'=>'Review the work completed and ask any remaining questions with the team before heading back on the road.'],
      ];
      foreach ($features as $f): ?>
      <div class="modern-card about-digital-card">
        <span class="about-feature-number"><?= e($f['n']) ?></span>
        <h3 class="feature-title"><?= e($f['t']) ?></h3>
        <p class="feature-desc"><?= e($f['d']) ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-emerald about-journey">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">Your visit, step by step</span>
      <h2 class="section-title">A clear path from check-in to handover</h2>
      <p class="section-desc">Every repair is different, but a thoughtful process helps keep you informed along the way.</p>
    </div>
    <div class="about-process-grid">
      <article class="about-process-card"><span>01</span><h3>Tell us what you notice</h3><p>Describe the sound, warning light, or change you have experienced.</p></article>
      <article class="about-process-card"><span>02</span><h3>Inspect and explain</h3><p>Discuss the inspection and the options that may address the concern.</p></article>
      <article class="about-process-card"><span>03</span><h3>Agree on next steps</h3><p>Review the proposed work and costs before deciding how to proceed.</p></article>
      <article class="about-process-card"><span>04</span><h3>Review the handover</h3><p>Go over the service and any follow-up recommendations with the team.</p></article>
    </div>
  </div>
</section>

<section class="section section-dark about-promises">
  <div class="container">
    <div class="about-promise-grid">
      <div class="about-promise"><span aria-hidden="true">✦</span><strong>Clear explanations</strong><small>Understand the concern and the proposed options.</small></div>
      <div class="about-promise"><span aria-hidden="true">✦</span><strong>Informed decisions</strong><small>Know what is being discussed before authorizing work.</small></div>
      <div class="about-promise"><span aria-hidden="true">✦</span><strong>Careful attention</strong><small>Give your vehicle concerns the time and detail they deserve.</small></div>
      <div class="about-promise"><span aria-hidden="true">✦</span><strong>Helpful follow-up</strong><small>Ask questions and get guidance on your next steps.</small></div>
    </div>
  </div>
</section>

<section id="faq" class="section section-dark">
  <div class="container">
    <div class="section-header">
      <span class="section-eyebrow">Common Questions</span>
      <h2 class="section-title">Frequently Asked Questions</h2>
      <p class="section-desc">A few practical answers to help you plan your next visit.</p>
    </div>
    <div class="faq-accordion">
      <?php foreach ($faqs as $i => $faq): ?>
      <div class="faq-item <?= $i === 0 ? 'active' : '' ?>">
        <button class="faq-question" type="button" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
          <span><?= e($faq['q']) ?></span><span class="faq-icon">&darr;</span>
        </button>
        <div class="faq-answer"><div class="faq-answer-inner"><?= e($faq['a']) ?></div></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section-dark about-cta">
  <div class="container about-cta-inner">
    <span class="section-eyebrow">Let’s get you moving</span>
    <h2 class="section-title">Have a question about your vehicle?</h2>
    <p class="section-desc">Tell us what is going on and our team can help you find a useful next step.</p>
    <div class="about-hero-actions">
      <a href="<?= e(url('contact.php')) ?>" class="btn-hero-primary">Contact Support &amp; Telegram</a>
      <a href="<?= e(url('services.php')) ?>" class="btn-hero-secondary">Browse Services</a>
    </div>
  </div>
</section>

<footer class="landing-footer">
  <div class="footer-top">
    <div>
      <div class="footer-brand"><?= e($garageName) ?></div>
      <p class="footer-desc">Pioneering precision automotive engineering, transparent digital maintenance, and cryptographic data security.</p>
    </div>
    <details class="footer-group" open><summary class="footer-col-title">Quick Links</summary>
      <ul class="footer-links">
        <li><a href="<?= e(url('index.php')) ?>">Home</a></li>
        <li><a href="<?= e(url('about.php')) ?>">About Us &amp; FAQ</a></li>
        <li><a href="<?= e(url('contact.php')) ?>">Contact Support</a></li>
        <li><a href="<?= e(url('privacy.php')) ?>">Privacy Policy</a></li>
        <li><a href="<?= e(url('terms.php')) ?>">Terms of Service</a></li>
      </ul>
    </details>
    <details class="footer-group" open><summary class="footer-col-title">Client Access</summary>
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
<script src="<?= e(asset('js/about.js')) ?>"></script>
</body>
</html>
