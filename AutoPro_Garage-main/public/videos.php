<?php
declare(strict_types=1);

/**
 * ALPHA GARAGE — PUBLIC VIDEO GALLERY
 * Modern, responsive showcase gallery displaying high-definition workshop videos,
 * diagnostic walkthroughs, repair overhauls, and detailing showcases.
 * Full bilingual support (English & Amharic), instant search, and secure modal player.
 */

require __DIR__ . '/../app/bootstrap.php';

$garageName = Setting::get('garage_name', 'Alpha Garage');
$category = isset($_GET['cat']) ? trim((string) $_GET['cat']) : 'all';
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

$videos = VideoService::all(true, $category !== 'all' ? $category : null, $search);
$categories = VideoService::CATEGORIES;

$pageTitle = t('Video Gallery', 'Video Gallery') . ' &middot; ' . e($garageName);
?>
<!doctype html>
<html lang="<?= e(current_lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $pageTitle ?></title>
<link rel="icon" href="<?= e(asset('images/logo.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('css/variables.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/theme.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/landing.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/scroll-animations.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/videos.css')) ?>">
</head>
<body class="videos-page" data-base-url="<?= e(url('')) ?>">

<!-- -----------------------------------------------------------------------
     Header Navigation Bar
     ----------------------------------------------------------------------- -->
<nav class="luxury-navbar" aria-label="<?= e(t('Main Navigation', 'Main Navigation')) ?>">
  <div class="nav-container">
    <a href="<?= e(url('index.php')) ?>" class="nav-brand">
      <div class="nav-logo-icon">
        <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="36" height="36">
      </div>
      <div>
        <span class="nav-brand-title"><?= e($garageName) ?></span>
        <span class="nav-brand-sub"><?= e(t('Luxury Automotive Care', 'Luxury Automotive Care')) ?></span>
      </div>
    </a>

    <ul class="nav-menu-links" id="primary-navigation">
      <li><a href="<?= e(url('index.php')) ?>" class="nav-link"><?= e(t('Home', 'Home')) ?></a></li>
      <li><a href="<?= e(url('services.php')) ?>" class="nav-link"><?= e(t('Our Services', 'Our Services')) ?></a></li>
      <li><a href="<?= e(url('videos.php')) ?>" class="nav-link active" aria-current="page"><?= e(t('Videos', 'Videos')) ?></a></li>
      <li><a href="<?= e(url('about.php')) ?>" class="nav-link"><?= e(t('About & FAQ', 'About & FAQ')) ?></a></li>
      <li><a href="<?= e(url('contact.php')) ?>" class="nav-link"><?= e(t('Contact', 'Contact')) ?></a></li>
      <?php if (!current_user()): ?>
        <li class="mobile-nav-login"><a href="<?= e(url('login.php')) ?>" class="nav-link"><?= e(t('Client sign in', 'Client sign in')) ?></a></li>
      <?php endif; ?>
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
     Hero Section with Category Filter & Search
     ----------------------------------------------------------------------- -->
<main id="main-content">
  <section class="videos-hero reveal-up">
    <div class="videos-hero-inner">
      <div class="videos-eyebrow">
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M17 10.5V7c0-.55-.45-1-1-1H4c-.55 0-1 .45-1 1v10c0 .55.45 1 1 1h12c.55 0 1-.45 1-1v-3.5l4 4v-11l-4 4z"/></svg>
        <span><?= e(t('Workshop in Motion', 'Workshop in Motion')) ?></span>
      </div>
      <h1><?= e(t('Video Gallery', 'Video Gallery')) ?> &middot; <span><?= e($garageName) ?></span></h1>
      <p class="videos-hero-desc" data-auto-type="<?= e(t('Explore certified technicians, precision engine rebuilds, advanced diagnostics, and luxury detailing in action.', 'Explore certified technicians, precision engine rebuilds, advanced diagnostics, and luxury detailing in action.')) ?>">
        <?= e(t('Explore certified technicians, precision engine rebuilds, advanced diagnostics, and luxury detailing in action.', 'Explore certified technicians, precision engine rebuilds, advanced diagnostics, and luxury detailing in action.')) ?>
      </p>

      <div class="videos-filter-bar">
        <!-- Live Instant Search Box -->
        <div class="videos-search-box">
          <svg class="videos-search-icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27A6.47 6.47 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
          <input type="search" id="video-search-input" class="videos-search-input" placeholder="<?= e(t('Search videos by title or topic…', 'Search videos by title or topic…')) ?>" value="<?= e($search) ?>" autocomplete="off" aria-label="<?= e(t('Search videos', 'Search videos')) ?>">
        </div>

        <!-- Category Pills -->
        <div class="videos-category-pills" role="tablist">
          <button type="button" class="category-pill <?= $category === 'all' ? 'active' : '' ?>" data-category="all" role="tab">
            <?= e(t('All Videos', 'All Videos')) ?>
          </button>
          <?php foreach ($categories as $cat): ?>
            <button type="button" class="category-pill <?= $category === $cat ? 'active' : '' ?>" data-category="<?= e($cat) ?>" role="tab">
              <?= e(t($cat, $cat)) ?>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <!-- -----------------------------------------------------------------------
       Videos Grid Gallery
       ----------------------------------------------------------------------- -->
  <section class="videos-gallery-section">
    <div class="videos-count-badge">
      <div>
        <span><?= e(t('Displaying', 'Displaying')) ?></span>
        <strong id="visible-video-count"><?= count($videos) ?></strong>
        <span><?= e(t('featured videos', 'featured videos')) ?></span>
      </div>
      <div>
        <span><?= e(t('High Definition', 'High Definition')) ?> &middot; <?= e(t('1080p / 4K Playback', '1080p / 4K Playback')) ?></span>
      </div>
    </div>

    <div class="videos-grid" id="videos-grid">
      <?php if (empty($videos)): ?>
        <div class="videos-empty" id="videos-empty-state">
          <div class="videos-empty-icon">&#127916;</div>
          <h3><?= e(t('No videos found.', 'No videos found.')) ?></h3>
          <p><?= e(t('Check back soon for new service overhauls and technical demonstrations.', 'Check back soon for new service overhauls and technical demonstrations.')) ?></p>
        </div>
      <?php else: ?>
        <div class="videos-empty" id="videos-empty-state" hidden style="display:none !important;">
          <div class="videos-empty-icon">&#127916;</div>
          <h3><?= e(t('No videos found.', 'No videos found.')) ?></h3>
          <p><?= e(t('Try searching with different keywords or selecting another category.', 'Try searching with different keywords or selecting another category.')) ?></p>
        </div>

        <?php foreach ($videos as $idx => $v): ?>
          <article class="video-card card-interactive reveal-up stagger-<?= ($idx % 6) + 1 ?>"
                   data-video-id="<?= (int) $v['id'] ?>"
                   data-category="<?= e($v['category']) ?>"
                   data-title="<?= e($v['title']) ?>"
                   data-desc="<?= e($v['description'] ?? '') ?>"
                   data-source-type="<?= e($v['source_type']) ?>"
                   data-embed-url="<?= e($v['embed_url'] ?? '') ?>"
                   data-stream-url="<?= e($v['stream_url'] ?? '') ?>">
            
            <div class="video-thumb-wrap" data-play-trigger title="<?= e(t('Click to play', 'Click to play')) ?>: <?= e($v['title']) ?>">
              <?php if (!empty($v['thumbnail_url'])): ?>
                <img class="video-thumb-img" src="<?= e($v['thumbnail_url']) ?>" alt="<?= e($v['title']) ?>" loading="lazy">
              <?php else: ?>
                <div style="width:100%;height:100%;background:linear-gradient(135deg,#0d2319,#173a2b);display:flex;align-items:center;justify-content:center;color:#d4af37;font-size:2.5rem;">&#127916;</div>
              <?php endif; ?>

              <span class="video-cat-badge"><?= e(t($v['category'], $v['category'])) ?></span>

              <?php if (!empty($v['duration'])): ?>
                <span class="video-duration-badge"><?= e($v['duration']) ?></span>
              <?php endif; ?>

              <span class="video-source-badge">
                <?= $v['source_type'] === 'youtube' ? 'YouTube' : '4K Video' ?>
              </span>

              <div class="video-play-btn" aria-hidden="true">
                <div class="video-play-icon">
                  <svg viewBox="0 0 24 24" width="26" height="26"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>
                </div>
              </div>
            </div>

            <div class="video-card-body">
              <h3 class="video-card-title"><?= e($v['title']) ?></h3>
              <p class="video-card-desc"><?= e($v['description'] ?? '') ?></p>
              
              <div class="video-card-footer">
                <div class="video-meta-stat">
                  <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                  <span><?= number_format((int) $v['views_count']) ?> <?= e(t('Views', 'Views')) ?></span>
                </div>
                <button type="button" class="video-watch-cta btn-interactive" data-play-trigger aria-label="<?= e(t('Watch Video', 'Watch Video')) ?>: <?= e($v['title']) ?>">
                  <svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M8 5v14l11-7z"/></svg>
                  <span><?= e(t('Watch Video', 'Watch Video')) ?></span>
                </button>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </section>
</main>

<!-- -----------------------------------------------------------------------
     Video Lightbox Modal Player
     ----------------------------------------------------------------------- -->
<div class="video-modal" id="video-modal" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="video-modal-title">
  <div class="video-modal-dialog">
    <div class="video-modal-header">
      <h2 class="video-modal-title" id="video-modal-title"><?= e(t('Watch Video', 'Watch Video')) ?></h2>
      <button type="button" class="video-modal-close" id="video-modal-close" aria-label="<?= e(t('Close', 'Close')) ?>">&times;</button>
    </div>
    <div class="video-modal-body" id="video-modal-body">
      <!-- Dynamic Iframe or HTML5 Video element injected here -->
    </div>
    <div class="video-modal-footer">
      <p class="video-modal-desc" id="video-modal-desc"></p>
      <a href="<?= e(url('contact.php')) ?>" class="btn-hero-primary" style="padding:0.45rem 1rem;font-size:0.85rem;"><?= e(t('Book Service', 'Book Service')) ?> &rarr;</a>
    </div>
  </div>
</div>

<!-- -----------------------------------------------------------------------
     Luxury Footer
     ----------------------------------------------------------------------- -->
<footer class="luxury-footer reveal-fade">
  <div class="footer-top">
    <div>
      <div class="footer-brand-row">
        <div class="nav-logo-icon footer-logo-icon">
          <img src="<?= e(asset('images/logo.svg')) ?>" alt="" width="30" height="30">
        </div>
        <span class="footer-brand-name"><?= e($garageName) ?></span>
      </div>
      <p class="footer-description">
        <?= e(t('Excellence in every detail. Professional automotive diagnostics, certified repairs, and transparent digital service for discerning motorists.', 'Excellence in every detail. Professional automotive diagnostics, certified repairs, and transparent digital service for discerning motorists.')) ?>
      </p>
      <div class="footer-telegram">
        <?= e(t('Telegram', 'Telegram')) ?>: <a href="https://t.me/GarageManagementSupport" target="_blank" rel="noopener noreferrer" class="footer-telegram-link">@GarageLuxurySupport</a>
      </div>
    </div>

    <details class="footer-group" open>
      <summary class="footer-col-title"><?= e(t('Quick Links', 'Quick Links')) ?></summary>
      <ul class="footer-links">
        <li><a href="<?= e(url('index.php')) ?>"><?= e(t('Home', 'Home')) ?></a></li>
        <li><a href="<?= e(url('services.php')) ?>"><?= e(t('Our Services', 'Our Services')) ?></a></li>
        <li><a href="<?= e(url('videos.php')) ?>"><?= e(t('Videos', 'Videos')) ?></a></li>
        <li><a href="<?= e(url('about.php')) ?>"><?= e(t('About Us & FAQ', 'About Us & FAQ')) ?></a></li>
        <li><a href="<?= e(url('contact.php')) ?>"><?= e(t('Contact Support & Telegram', 'Contact Support & Telegram')) ?></a></li>
      </ul>
    </details>

    <details class="footer-group" open>
      <summary class="footer-col-title"><?= e(t('Client Access', 'Client Access')) ?></summary>
      <ul class="footer-links">
        <li><a href="<?= e(url('login.php')) ?>"><?= e(t('Client Sign In', 'Client Sign In')) ?></a></li>
        <li><a href="<?= e(url('register.php')) ?>"><?= e(t('Create Account', 'Create Account')) ?></a></li>
        <li><a href="<?= e(url('forgot-password.php')) ?>"><?= e(t('Forgot Password', 'Forgot Password')) ?></a></li>
        <?php if (current_user() && can('dashboard.view')): ?><li><a href="<?= e(url('dashboard.php')) ?>"><?= e(t('Workshop Dashboard', 'Workshop Dashboard')) ?></a></li><?php endif; ?>
      </ul>
    </details>
  </div>

  <div class="footer-bottom">
    <div>&copy; <?= e(date('Y')) ?> <?= e($garageName) ?>. <?= e(t('All rights reserved.', 'All rights reserved.')) ?></div>
    <div class="footer-bottom-note"><?= e(t('Professional Automotive Service & Repair', 'Professional Automotive Service & Repair')) ?></div>
  </div>
</footer>

<script src="<?= e(asset('js/luxury.js')) ?>"></script>
<script src="<?= e(asset('js/i18n.js')) ?>"></script>
<script src="<?= e(asset('js/scroll-animations.js')) ?>"></script>
<script src="<?= e(asset('js/videos.js')) ?>"></script>
</body>
</html>
