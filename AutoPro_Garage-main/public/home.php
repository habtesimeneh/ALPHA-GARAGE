<?php
declare(strict_types=1);
/**
 * Public marketing homepage (optional). The default entry point (index.php) still redirects to the
 * dashboard/login; link to this page from your site or change index.php if you want it as the landing page.
 * Testimonials, services and opening hours below are SAMPLE CONTENT — edit them to match your garage.
 */
require __DIR__ . '/../app/bootstrap.php';

$garage  = Setting::get('garage_name', 'Alpha Garage');
$address = Setting::get('garage_address', '');
$phone   = Setting::get('garage_phone', '');
$email   = Setting::get('garage_email', '');

$icon = fn(string $d) => '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="' . $d . '"/></svg>';
$services = [
    ['Engine diagnostics', 'Computer-assisted fault finding and precise repairs that get you back on the road quickly.', 'M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6 6 9 1.6 4.7C.4 7.1.9 10.1 2.9 12.1c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.3-2.3c.5-.4.5-1.1.1-1.4z'],
    ['Brakes & suspension', 'Inspection, pad and disc replacement, shocks and alignment for safe, confident driving.', 'M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm-2 16l-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8z'],
    ['Oil & fluids', 'Full-service oil changes, filters and fluid top-ups using quality, manufacturer-approved products.', 'M12 2.5S5 10.2 5 15a7 7 0 0 0 14 0c0-4.8-7-12.5-7-12.5z'],
    ['Tyres & wheels', 'Fitting, balancing, rotation and puncture repair with transparent pricing.', 'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zm0 14a4 4 0 1 1 0-8 4 4 0 0 1 0 8z'],
    ['Electrical & battery', 'Battery testing, alternator and starter service, lighting and wiring repairs.', 'M7 2v11h3v9l7-12h-4l4-8H7z'],
    ['Full vehicle service', 'Scheduled maintenance packages that keep your warranty valid and your car reliable.', 'M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99z'],
];
$testimonials = [
    ['They explained every repair before starting and the final bill matched the quote exactly. Highly recommended.', 'Sample Customer A', 'Fleet owner'],
    ['Fast, honest and tidy. My car was ready the same afternoon, and I got updates throughout the day.', 'Sample Customer B', 'Daily commuter'],
    ['Professional mechanics who actually know their craft. I bring both of my family cars here now.', 'Sample Customer C', 'Family driver'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0B2E1B">
<title><?= e($garage) ?> · Professional auto care</title>
<meta name="description" content="<?= e($garage) ?> — diagnostics, repairs and servicing by certified mechanics.">
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<?= css_links(['homepage']) ?>
</head>
<body class="home-body">
<header class="home-nav">
  <div class="container">
    <a class="home-brand" href="#top"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="38" height="38"><span><?= e($garage) ?></span></a>
    <input type="checkbox" id="home-toggle" class="home-toggle" aria-label="Toggle menu">
    <label for="home-toggle" class="home-menu-btn" aria-hidden="true"><svg viewBox="0 0 24 24" width="22" height="22"><path fill="currentColor" d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg></label>
    <nav class="home-links" aria-label="Site">
      <a href="<?= e(url('services.php')) ?>">Services</a><a href="#reviews">Reviews</a><a href="#contact">Contact</a>
      <a class="btn btn-gold btn-sm" href="<?= e(url('login.php')) ?>">Staff sign in</a>
    </nav>
  </div>
</header>

<main id="top">
  <section class="home-hero">
    <div class="home-hero-slides" aria-hidden="true">
      <img class="home-hero-slide is-active" src="https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&amp;fit=crop&amp;w=2000&amp;q=80" alt="" fetchpriority="high">
      <img class="home-hero-slide" src="https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&amp;fit=crop&amp;w=2000&amp;q=80" alt="" loading="lazy">
      <img class="home-hero-slide" src="https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&amp;fit=crop&amp;w=2000&amp;q=80" alt="" loading="lazy">
      <img class="home-hero-slide" src="https://images.unsplash.com/photo-1489824904134-891ab64532f1?auto=format&amp;fit=crop&amp;w=2000&amp;q=80" alt="" loading="lazy">
      <img class="home-hero-slide" src="https://images.unsplash.com/photo-1511919884226-fd3cad34687c?auto=format&amp;fit=crop&amp;w=2000&amp;q=80" alt="" loading="lazy">
    </div>
    <div class="container">
      <span class="eyebrow">Trusted auto care</span>
      <h1>Drive with <span>confidence</span>, every day.</h1>
      <p><?= e($garage) ?> combines certified mechanics, modern diagnostics and honest pricing to keep your vehicle safe, reliable and running at its best.</p>
      <div class="home-cta">
        <a class="btn btn-gold" href="#contact">Book a service</a>
        <a class="btn btn-ghost" href="<?= e(url('services.php')) ?>">Explore services</a>
      </div>
      <div class="home-stats">
        <div><strong>15+</strong><span>Years of experience</span></div>
        <div><strong>5,000+</strong><span>Vehicles serviced</span></div>
        <div><strong>30 days</strong><span>Labour guarantee</span></div>
      </div>
      <div class="home-hero-controls" aria-label="Hero slideshow controls">
        <div class="home-hero-dots" role="group" aria-label="Choose a hero image">
          <button class="home-hero-dot is-active" type="button" aria-label="Show image 1" aria-current="true"></button>
          <button class="home-hero-dot" type="button" aria-label="Show image 2"></button>
          <button class="home-hero-dot" type="button" aria-label="Show image 3"></button>
          <button class="home-hero-dot" type="button" aria-label="Show image 4"></button>
          <button class="home-hero-dot" type="button" aria-label="Show image 5"></button>
        </div>
        <button class="home-hero-toggle" type="button" aria-pressed="false">Pause slideshow</button>
      </div>
    </div>
  </section>

  <section class="home-section" id="services">
    <div class="container">
      <div class="section-head"><span class="eyebrow">What we do</span><h2>Complete care for your vehicle</h2><p>From routine maintenance to complex repairs, our team handles it all under one roof.</p></div>
      <div class="service-grid">
        <?php foreach ($services as [$name, $text, $path]): ?>
          <article class="service-card"><div class="service-icon"><?= $icon($path) ?></div><h3><?= e($name) ?></h3><p><?= e($text) ?></p></article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="home-section alt" id="reviews">
    <div class="container">
      <div class="section-head"><span class="eyebrow">Testimonials</span><h2>What our customers say</h2><p>Sample reviews — replace with your own customers&rsquo; feedback.</p></div>
      <div class="testimonial-grid">
        <?php foreach ($testimonials as [$quote, $who, $role]): ?>
          <figure class="testimonial m0"><div class="stars" aria-label="5 out of 5 stars">★★★★★</div><blockquote><?= e($quote) ?></blockquote>
            <footer><span class="avatar" aria-hidden="true"><?= e(mb_substr($who, -1)) ?></span><span><strong><?= e($who) ?></strong><small><?= e($role) ?></small></span></footer></figure>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="home-section" id="contact">
    <div class="container">
      <div class="section-head"><span class="eyebrow">Get in touch</span><h2>Visit or contact us</h2><p>Call, email or drop by the workshop — we&rsquo;re happy to help.</p></div>
      <div class="contact-wrap">
        <div class="contact-info">
          <h3><?= e($garage) ?></h3>
          <ul class="contact-list">
            <?php if ($address): ?><li><?= $icon('M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5z') ?><span><?= e($address) ?></span></li><?php endif; ?>
            <?php if ($phone): ?><li><?= $icon('M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.57 3.57.57a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1A17 17 0 0 1 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.25.2 2.45.57 3.57a1 1 0 0 1-.25 1.02l-2.2 2.2z') ?><span><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>"><?= e($phone) ?></a></span></li><?php endif; ?>
            <?php if ($email): ?><li><?= $icon('M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zm0 4-8 5-8-5V6l8 5 8-5v2z') ?><span><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></span></li><?php endif; ?>
          </ul>
        </div>
        <div class="contact-hours">
          <h3>Opening hours</h3>
          <table class="hours"><tbody>
            <tr><td>Monday – Friday</td><td>8:00 – 18:00</td></tr>
            <tr><td>Saturday</td><td>8:00 – 14:00</td></tr>
            <tr><td>Sunday</td><td>Closed</td></tr>
          </tbody></table>
          <p class="muted small">Sample hours — edit in <span class="mono">public/home.php</span>.</p>
          <?php if ($phone): ?><a class="btn btn-primary" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $phone)) ?>">Call now</a><?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</main>

<footer class="home-footer">
  <div class="container">
    <div class="cols">
      <div><a class="home-brand" href="#top"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="34" height="34"><span><?= e($garage) ?></span></a><p class="small">Professional vehicle care, built on trust and transparent pricing.</p></div>
      <div><h4>Explore</h4><a href="<?= e(url('services.php')) ?>">Services</a><a href="#reviews">Reviews</a><a href="#contact">Contact</a></div>
      <div><h4>Staff</h4><a href="<?= e(url('login.php')) ?>">Sign in</a></div>
    </div>
    <div class="legal"><span>&copy; <?= e(date('Y')) ?> <?= e($garage) ?>. All rights reserved.</span><span>Garage Management System</span></div>
  </div>
</footer>
<script src="<?= e(asset('js/homepage.js')) ?>" defer></script>
</body>
</html>
