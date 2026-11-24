<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');
$services = [];
$catalogError = false;

try {
    $services = Database::all('SELECT id, name, category, description, standard_price, estimated_hours FROM services WHERE is_active = 1 ORDER BY category ASC, name ASC');
} catch (Throwable $e) {
    app_log('Public services page catalog query failed: ' . $e->getMessage());
    $catalogError = true;
}

$serviceImage = static function (string $category): string {
    $category = strtolower($category);
    if (str_contains($category, 'brake')) {
        return 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=1100&q=85';
    }
    if (str_contains($category, 'susp') || str_contains($category, 'wheel') || str_contains($category, 'align')) {
        return 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1100&q=85';
    }
    if (str_contains($category, 'maint') || str_contains($category, 'oil')) {
        return 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1100&q=85';
    }
    if (str_contains($category, 'elect') || str_contains($category, 'battery')) {
        return 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1100&q=85';
    }
    if (str_contains($category, 'air') || str_contains($category, 'a/c') || str_contains($category, 'heat')) {
        return 'https://images.unsplash.com/photo-1489824904134-891ab64532f1?auto=format&fit=crop&w=1100&q=85';
    }
    if (str_contains($category, 'trans')) {
        return 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=1100&q=85';
    }
    return 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=1100&q=85';
};

$serviceGuides = [
    [
        'title' => 'Diagnostics & inspections',
        'category' => 'Diagnostics',
        'image' => 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Vehicle prepared for a careful workshop inspection',
        'description' => 'Warning lights, new sounds, or changes in how a vehicle drives can have several possible causes. A focused diagnostic visit starts with your observations, then uses appropriate checks to help narrow down the issue.',
        'checks' => ['Discuss symptoms and when they occur', 'Inspect relevant systems and diagnostic codes', 'Explain findings and practical next steps'],
    ],
    [
        'title' => 'Routine maintenance',
        'category' => 'Maintenance',
        'image' => 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Vehicle receiving routine care in an automotive workshop',
        'description' => 'Regular maintenance helps you keep up with your vehicle maker’s service schedule. The right work depends on the model, mileage, history, and how the vehicle is used.',
        'checks' => ['Review mileage and service history', 'Discuss relevant fluids, filters, and inspections', 'Confirm the proposed work before it begins'],
    ],
    [
        'title' => 'Brake service',
        'category' => 'Brakes',
        'image' => 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Car in a professional workshop for vehicle care',
        'description' => 'Changes in braking feel, vibration, pulling, or unusual noises deserve attention. A brake inspection helps assess the relevant components and explain what may need servicing.',
        'checks' => ['Share changes in pedal feel or braking noise', 'Inspect applicable brake components', 'Review findings and recommended work with you'],
    ],
    [
        'title' => 'Electrical & battery checks',
        'category' => 'Electrical',
        'image' => 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Modern vehicle in a workshop setting',
        'description' => 'Starting trouble, warning lights, or an intermittent electrical fault can come from different parts of a vehicle. Targeted testing helps guide the next checks instead of guessing at a replacement.',
        'checks' => ['Describe when the issue happens', 'Check the battery and charging system as appropriate', 'Discuss test results and repair options'],
    ],
    [
        'title' => 'Steering, suspension & wheels',
        'category' => 'Suspension',
        'image' => 'https://images.unsplash.com/photo-1503736334956-4c8f8e92946d?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Car photographed on the road, representing steering and wheel care',
        'description' => 'Uneven tire wear, a change in handling, or a vehicle that pulls to one side can indicate that the wheels, steering, or suspension need a closer look.',
        'checks' => ['Describe handling changes and tire wear', 'Inspect relevant steering and suspension areas', 'Check alignment when suitable for the vehicle and concern'],
    ],
    [
        'title' => 'Air conditioning & comfort',
        'category' => 'AC & Heating',
        'image' => 'https://images.unsplash.com/photo-1489824904134-891ab64532f1?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Vehicle in a clean automotive service environment',
        'description' => 'Weak airflow or air that is not cooling as expected can have several causes. An inspection can help identify the next appropriate check before any service is recommended.',
        'checks' => ['Describe the airflow or temperature issue', 'Inspect relevant controls and system operation', 'Discuss findings before any service is authorized'],
    ],
    [
        'title' => 'Transmission & drivetrain',
        'category' => 'Transmission',
        'image' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?auto=format&fit=crop&w=1100&q=85',
        'alt' => 'Vehicle representing transmission and drivetrain service',
        'description' => 'Changes in shifting, vibration, or unusual drivetrain sounds should be discussed with a service advisor. Inspection and maintenance needs vary by vehicle and manufacturer guidance.',
        'checks' => ['Share when the change occurs and how it feels', 'Review the vehicle’s service information', 'Agree on diagnostic or maintenance steps together'],
    ],
];

$bookingUrl = current_user()
    ? url('book.php')
    : url('login.php?redirect=book.php');
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Automotive Services &middot; <?= e($garageName) ?></title>
<meta name="description" content="Explore automotive diagnostics, maintenance, brake, electrical, suspension, air conditioning, and other vehicle services at <?= e($garageName) ?>.">
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/services.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/scroll-animations.css')) ?>">
</head>
<body class="landing-page services-page">
<a class="skip-link" href="#main-content"><?= e(t('Skip to main content', 'Skip to main content')) ?></a>

<nav class="luxury-navbar" aria-label="Main Navigation">
  <div class="top-announcement">
    <span class="announcement-mark" aria-hidden="true">&#10022;</span>
    <div class="announcement-message" aria-label="<?= e(t('Welcome to Alpha Garage', 'Welcome to Alpha Garage')) ?>. <?= e(t('Professional automotive care and clear service guidance.', 'Professional automotive care and clear service guidance.')) ?>">
      <div class="announcement-track">
        <span class="announcement-copy"><?= e(t('Welcome to Alpha Garage', 'Welcome to Alpha Garage')) ?> <span aria-hidden="true">&#8226;</span> <?= e(t('Clear service details', 'Clear service details')) ?> <span aria-hidden="true">&#8226;</span> <?= e(t('Vehicle care tailored to your needs', 'Vehicle care tailored to your needs')) ?></span>
        <span class="announcement-copy" aria-hidden="true"><?= e(t('Welcome to Alpha Garage', 'Welcome to Alpha Garage')) ?> <span aria-hidden="true">&#8226;</span> <?= e(t('Clear service details', 'Clear service details')) ?> <span aria-hidden="true">&#8226;</span> <?= e(t('Vehicle care tailored to your needs', 'Vehicle care tailored to your needs')) ?></span>
      </div>
    </div>
    <a href="<?= e(url('contact.php')) ?>"><?= e(t('Contact us', 'Contact us')) ?> <span aria-hidden="true">&rarr;</span></a>
  </div>
  <div class="nav-container">
    <a href="<?= e(url('index.php')) ?>" class="nav-brand">
      <div class="nav-logo-icon"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36"></div>
      <div><span class="nav-brand-title"><?= e($garageName) ?></span><span class="nav-brand-sub"><?= e(t('Luxury Automotive Care', 'Luxury Automotive Care')) ?></span></div>
    </a>
    <ul class="nav-menu-links" id="primary-navigation">
      <li><a href="<?= e(url('index.php')) ?>" class="nav-link"><?= e(t('Home', 'Home')) ?></a></li>
      <li><a href="<?= e(url('services.php')) ?>" class="nav-link active" aria-current="page"><?= e(t('Our Services', 'Our Services')) ?></a></li>
      <li><a href="<?= e(url('videos.php')) ?>" class="nav-link"><?= e(t('Videos', 'Videos')) ?></a></li>
      <li><a href="<?= e(url('about.php')) ?>" class="nav-link"><?= e(t('About Us', 'About Us')) ?></a></li>
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

<main id="main-content">
  <header class="services-hero">
    <img class="services-hero-image" src="https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&amp;fit=crop&amp;w=2200&amp;q=85" alt="" fetchpriority="high">
    <div class="services-hero-overlay" aria-hidden="true"></div>
    <div class="container services-hero-content reveal-up reveal-hero">
      <nav class="breadcrumb-nav" aria-label="Breadcrumb"><a href="<?= e(url('index.php')) ?>"><?= e(t('Home', 'Home')) ?></a><span aria-hidden="true">&rsaquo;</span><span><?= e(t('Our Services', 'Our Services')) ?></span></nav>
      <span class="services-eyebrow"><?= e(t('Thoughtful care for every mile', 'Thoughtful care for every mile')) ?></span>
      <h1><?= e(t('Understand your service.', 'Understand your service.')) ?><br><span class="hero-typewriter-highlight" data-typewriter='<?= json_encode([t('Choose with confidence.', 'Choose with confidence.'), t('Expert diagnostics & care.', 'Expert diagnostics & care.'), t('Transparent pricing always.', 'Transparent pricing always.'), t('Certified technician care.', 'Certified technician care.')], JSON_UNESCAPED_UNICODE) ?>'><?= e(t('Choose with confidence.', 'Choose with confidence.')) ?></span></h1>
      <p><?= e(t('Explore the work we offer, what a visit may involve, and what to share with our team. We’ll explain the options before you decide.', 'Explore the work we offer, what a visit may involve, and what to share with our team. We’ll explain the options before you decide.')) ?></p>
      <div class="services-hero-actions">
        <a class="services-button services-button-primary btn-interactive btn-shimmer" href="#service-catalog"><?= e(t('Explore services', 'Explore services')) ?> <span aria-hidden="true">&darr;</span></a>
        <a class="services-button services-button-secondary btn-interactive" href="<?= e(url('contact.php')) ?>"><?= e(t('Ask our team', 'Ask our team')) ?> <span aria-hidden="true">&rarr;</span></a>
      </div>
      <div class="services-hero-assurance"><span aria-hidden="true">✦</span> <?= e(t('Clear explanations', 'Clear explanations')) ?> <span aria-hidden="true">✦</span> <?= e(t('Work discussed before it begins', 'Work discussed before it begins')) ?></div>
    </div>
  </header>

  <section class="service-catalog-section" id="service-catalog">
    <div class="container">
      <div class="services-section-heading reveal-up">
        <span class="services-eyebrow"><?= e(t('The service catalog', 'The service catalog')) ?></span>
        <h2><?= e(t('Find the right place to start', 'Find the right place to start')) ?></h2>
        <p><?= e(t('These are the active services listed by the garage. Price and estimated time are shown when available in the catalog; your vehicle and final work may affect the estimate.', 'These are the active services listed by the garage. Price and estimated time are shown when available in the catalog; your vehicle and final work may affect the estimate.')) ?></p>
      </div>
      <?php if ($catalogError): ?>
        <div class="services-catalog-notice" role="status"><?= e(t('The live service list is temporarily unavailable. You can still read about common service areas below or contact the garage for current options.')) ?></div>
      <?php elseif (!$services): ?>
        <div class="services-catalog-notice" role="status"><?= e(t('There are no active services in the catalog right now.')) ?></div>
      <?php else: ?>
        <?php $categories = array_values(array_unique(array_map(static fn(array $service): string => (string) $service['category'], $services))); ?>
        <div class="services-filters reveal-fade" role="group" aria-label="Filter services by category">
          <button type="button" class="services-filter is-active" data-service-filter="all" aria-pressed="true"><?= e(t('All services', 'All services')) ?></button>
          <?php foreach ($categories as $category): ?>
            <button type="button" class="services-filter" data-service-filter="<?= e(strtolower($category)) ?>" aria-pressed="false"><?= e(t($category, $category)) ?></button>
          <?php endforeach; ?>
        </div>
        <div class="services-catalog-grid">
          <?php foreach ($services as $idx => $service): ?>
            <?php
              $serviceId = (int) $service['id'];
              $servicePath = 'book.php?service_id=' . $serviceId;
              $serviceBookingUrl = current_user()
                  ? url($servicePath)
                  : url('login.php?redirect=' . rawurlencode($servicePath));
              $stagger = 'stagger-' . (($idx % 6) + 1);
            ?>
            <article class="service-detail-card reveal-up card-interactive <?= $stagger ?>" data-service-card data-service-category="<?= e(strtolower((string) $service['category'])) ?>">
              <div class="service-detail-image-wrap">
                <img src="<?= e($serviceImage((string) $service['category'])) ?>" alt="" loading="lazy">
                <span class="service-detail-category"><?= e(t($service['category'], $service['category'])) ?></span>
              </div>
              <div class="service-detail-copy">
                <h3><?= e(t($service['name'], $service['name'])) ?></h3>
                <p><?= e(t($service['description'] ?: 'Our team can review your vehicle and explain the appropriate service options.', $service['description'] ?: 'Our team can review your vehicle and explain the appropriate service options.')) ?></p>
                <div class="service-detail-meta">
                  <span><small><?= e(t('Catalog price', 'Catalog price')) ?></small><strong><?= e(money($service['standard_price'])) ?></strong></span>
                  <span><small><?= e(t('Estimated time', 'Estimated time')) ?></small><strong><?= e($service['estimated_hours']) ?> <?= e(t('hrs', 'hrs')) ?></strong></span>
                </div>
                <a class="service-detail-link" href="<?= e($serviceBookingUrl) ?>"><?= e(t('Request this service', 'Request this service')) ?> <span aria-hidden="true">&rarr;</span></a>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="services-filter-empty" data-service-filter-empty hidden><?= e(t('No services match that category.', 'No services match that category.')) ?></p>
      <?php endif; ?>
    </div>
  </section>

  <section class="service-guides-section" id="service-guide">
    <div class="container">
      <div class="services-section-heading reveal-up">
        <span class="services-eyebrow"><?= e(t('What to expect', 'What to expect')) ?></span>
        <h2><?= e(t('A little more about vehicle care', 'A little more about vehicle care')) ?></h2>
        <p><?= e(t('Every vehicle is different. These guides describe common reasons to ask for help and the kinds of details that can make a conversation with a service advisor more useful.', 'Every vehicle is different. These guides describe common reasons to ask for help and the kinds of details that can make a conversation with a service advisor more useful.')) ?></p>
      </div>
      <div class="service-guide-grid">
        <?php foreach ($serviceGuides as $index => $guide): ?>
          <article class="service-guide-card reveal-up card-interactive <?= 'stagger-' . (($index % 4) + 1) ?>">
            <div class="service-guide-photo"><img src="<?= e($guide['image']) ?>" alt="<?= e($guide['alt']) ?>" loading="lazy"><span><?= e(sprintf('%02d', $index + 1)) ?></span></div>
            <div class="service-guide-copy">
              <span class="service-guide-category"><?= e(t($guide['category'], $guide['category'])) ?></span>
              <h3><?= e(t($guide['title'], $guide['title'])) ?></h3>
              <p><?= e(t($guide['description'], $guide['description'])) ?></p>
              <h4><?= e(t('A helpful visit may include', 'A helpful visit may include')) ?></h4>
              <ul><?php foreach ($guide['checks'] as $check): ?><li><?= e(t($check, $check)) ?></li><?php endforeach; ?></ul>
              <a href="<?= e(url('contact.php')) ?>"><?= e(t('Ask about this service', 'Ask about this service')) ?> <span aria-hidden="true">&rarr;</span></a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <section class="service-visit-section">
    <div class="container service-visit-layout">
      <div class="reveal-left">
        <span class="services-eyebrow"><?= e(t('Before your visit', 'Before your visit')) ?></span>
        <h2><?= e(t('Help us make the first conversation count.', 'Help us make the first conversation count.')) ?></h2>
        <p><?= e(t('A few details can help the team understand what you’re experiencing and discuss a suitable next step.', 'A few details can help the team understand what you’re experiencing and discuss a suitable next step.')) ?></p>
      </div>
      <ol class="service-visit-list reveal-stagger">
        <li class="card-interactive"><span>01</span><div><strong><?= e(t('Tell us the vehicle', 'Tell us the vehicle')) ?></strong><p><?= e(t('Share its make, model, year, and mileage if you know them.', 'Share its make, model, year, and mileage if you know them.')) ?></p></div></li>
        <li class="card-interactive"><span>02</span><div><strong><?= e(t('Describe what changed', 'Describe what changed')) ?></strong><p><?= e(t('Mention warning lights, sounds, smells, or when the issue occurs.', 'Mention warning lights, sounds, smells, or when the issue occurs.')) ?></p></div></li>
        <li class="card-interactive"><span>03</span><div><strong><?= e(t('Ask before approving work', 'Ask before approving work')) ?></strong><p><?= e(t('Review the proposed service and estimate with the team before repairs begin.', 'Review the proposed service and estimate with the team before repairs begin.')) ?></p></div></li>
      </ol>
    </div>
  </section>

  <section class="services-final-cta">
    <div class="container reveal-scale card-interactive">
      <span class="services-eyebrow"><?= e(t('Need help deciding?', 'Need help deciding?')) ?></span>
      <h2><?= e(t('Let’s find the right next step for your vehicle.', 'Let’s find the right next step for your vehicle.')) ?></h2>
      <p><?= e(t('Talk with our team or request an appointment when you’re ready.', 'Talk with our team or request an appointment when you’re ready.')) ?></p>
      <div class="services-hero-actions">
        <a class="services-button services-button-primary btn-interactive btn-shimmer" href="<?= e($bookingUrl) ?>"><?= e(t('Book a service', 'Book a service')) ?> <span aria-hidden="true">&rarr;</span></a>
        <a class="services-button services-button-secondary btn-interactive" href="<?= e(url('contact.php')) ?>"><?= e(t('Contact us', 'Contact us')) ?> <span aria-hidden="true">&rarr;</span></a>
      </div>
    </div>
  </section>
</main>

<footer class="services-footer reveal-fade">
  <div class="container services-footer-inner">
    <a href="<?= e(url('index.php')) ?>" class="services-footer-brand"><img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="42" height="42"><span><?= e($garageName) ?><small><?= e(t('Luxury Automotive Care', 'Luxury Automotive Care')) ?></small></span></a>
    <nav aria-label="Footer navigation"><a href="<?= e(url('index.php')) ?>"><?= e(t('Home', 'Home')) ?></a><a href="<?= e(url('services.php')) ?>"><?= e(t('Services', 'Services')) ?></a><a href="<?= e(url('videos.php')) ?>"><?= e(t('Videos', 'Videos')) ?></a><a href="<?= e(url('about.php')) ?>"><?= e(t('About', 'About')) ?></a><a href="<?= e(url('contact.php')) ?>"><?= e(t('Contact', 'Contact')) ?></a></nav>
    <span>&copy; <?= e(date('Y')) ?> <?= e($garageName) ?></span>
  </div>
</footer>
<script src="<?= e(asset('js/luxury.js')) ?>"></script>
<script src="<?= e(asset('js/services.js')) ?>"></script>
<script src="<?= e(asset('js/i18n.js')) ?>"></script>
<script src="<?= e(asset('js/scroll-animations.js')) ?>"></script>
</body>
</html>
