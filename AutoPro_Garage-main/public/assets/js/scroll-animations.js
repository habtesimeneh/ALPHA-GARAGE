/**
 * scroll-animations.js — ALPHA GARAGE Global Scroll Animation System
 * Lightweight, Vanilla ES6+ Intersection Observer Engine
 *
 * Capabilities:
 * - Intersection Observer-driven reveals with progressive enhancement (.js-ready)
 * - Zero dependency, zero layout shift (CLS), zero framework overhead
 * - Bidirectional scroll handling without flutter
 * - Dynamic MutationObserver for AJAX dashboard reloads
 * - Accessible back-to-top button with smooth scroll
 * - Staggered count-up for statistics
 * - Full prefers-reduced-motion compliance
 */

(function () {
  'use strict';

  // 1. Accessibility & Feature Support Checks
  const hasIntersectionObserver = typeof window.IntersectionObserver === 'function';
  const motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
  let prefersReducedMotion = motionQuery.matches;

  motionQuery.addEventListener('change', (e) => {
    prefersReducedMotion = e.matches;
    if (prefersReducedMotion) {
      revealAllElements();
    }
  });

  // If IntersectionObserver is unavailable or reduced motion is active, do not hide elements
  if (!hasIntersectionObserver) {
    return;
  }

  // Activate CSS rules only now that JS is running and capable
  if (!prefersReducedMotion) {
    document.documentElement.classList.add('js-ready');
  }

  // 2. Selectors & Global Observer
  const REVEAL_SELECTOR = '.reveal, .reveal-up, .reveal-down, .reveal-left, .reveal-right, .reveal-scale, .reveal-fade, .reveal-zoom, .reveal-blur, .reveal-rotate, .reveal-stagger, [data-reveal], .app-body .content > .card, .app-body .content > .grid > .card';

  let lastScrollY = window.scrollY;
  let isScrollingDown = true;

  window.addEventListener('scroll', () => {
    const currentScrollY = window.scrollY;
    isScrollingDown = currentScrollY >= lastScrollY;
    lastScrollY = currentScrollY;
    updateBackToTop();
    updateScrollProgress();
  }, { passive: true });

  // Observer options: Trigger when element enters by 8% or -40px offset from bottom
  const observerOptions = {
    root: null,
    rootMargin: '0px 0px -40px 0px',
    threshold: [0, 0.12]
  };

  const revealObserver = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      const target = entry.target;
      const revealOnce = target.getAttribute('data-reveal-once') !== 'false';

      if (entry.isIntersecting) {
        target.classList.add('is-revealed');

        // Check for stat counters
        if (target.hasAttribute('data-counter') || target.classList.contains('stat-counter')) {
          animateCounter(target);
        }
        target.querySelectorAll('[data-counter], .stat-counter').forEach(animateCounter);

        if (revealOnce) {
          revealObserver.unobserve(target);
        }
      } else if (!revealOnce && !entry.isIntersecting && !isScrollingDown) {
        // Element left viewport scrolling up, allow re-entrance
        target.classList.remove('is-revealed');
      }
    });
  }, observerOptions);

  function observeElement(el) {
    if (!el || !(el instanceof Element)) return;
    if (prefersReducedMotion) {
      el.classList.add('is-revealed');
      return;
    }
    revealObserver.observe(el);
  }

  function scanAndObserve(root = document) {
    const elements = root.querySelectorAll(REVEAL_SELECTOR);
    elements.forEach(observeElement);
  }

  function revealAllElements() {
    document.querySelectorAll(REVEAL_SELECTOR).forEach((el) => {
      el.classList.add('is-revealed');
      revealObserver.unobserve(el);
    });
  }

  // 3. Stat Count-Up Animation
  function animateCounter(el) {
    if (!el || el.dataset.counterDone === 'true') return;

    const targetValue = parseFloat(el.getAttribute('data-counter') || el.textContent.replace(/[^0-9.]/g, ''));
    if (isNaN(targetValue) || targetValue <= 0) return;

    el.dataset.counterDone = 'true';

    if (prefersReducedMotion) {
      return;
    }

    const duration = 1200; // ms
    const startTime = performance.now();
    const isInteger = Number.isInteger(targetValue);
    const prefix = el.getAttribute('data-counter-prefix') || '';
    const suffix = el.getAttribute('data-counter-suffix') || '';

    function updateCounter(currentTime) {
      const elapsed = currentTime - startTime;
      const progress = Math.min(elapsed / duration, 1);
      // Ease out cubic
      const eased = 1 - Math.pow(1 - progress, 3);
      const currentVal = targetValue * eased;

      const formatted = isInteger
        ? Math.floor(currentVal).toLocaleString()
        : currentVal.toFixed(1);

      el.textContent = `${prefix}${formatted}${suffix}`;

      if (progress < 1) {
        requestAnimationFrame(updateCounter);
      } else {
        el.textContent = `${prefix}${isInteger ? targetValue.toLocaleString() : targetValue.toFixed(1)}${suffix}`;
      }
    }

    requestAnimationFrame(updateCounter);
  }

  // 4. Back To Top Floating Action Button
  let backToTopBtn = null;

  function ensureBackToTopButton() {
    backToTopBtn = document.querySelector('.back-to-top');
    if (!backToTopBtn) {
      backToTopBtn = document.createElement('button');
      backToTopBtn.className = 'back-to-top';
      backToTopBtn.type = 'button';
      backToTopBtn.setAttribute('aria-label', 'Back to top of page');
      backToTopBtn.setAttribute('title', 'Back to top');
      backToTopBtn.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <polyline points="18 15 12 9 6 15"></polyline>
        </svg>
      `;
      document.body.appendChild(backToTopBtn);
    }

    backToTopBtn.addEventListener('click', () => {
      window.scrollTo({
        top: 0,
        behavior: prefersReducedMotion ? 'auto' : 'smooth'
      });
      // Return focus to first header/main link for screen readers
      const topTarget = document.querySelector('a.skip-link, header a, nav a, h1');
      if (topTarget) {
        topTarget.focus({ preventScroll: true });
      }
    });
  }

  function updateBackToTop() {
    if (!backToTopBtn) return;
    const threshold = 350;
    const shouldShow = window.scrollY > threshold;
    backToTopBtn.classList.toggle('is-visible', shouldShow);
  }

  // 5. Scroll Progress Bar
  const progressBar = document.querySelector('.scroll-progress-bar');
  function updateScrollProgress() {
    if (!progressBar) return;
    const docHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
    if (docHeight <= 0) return;
    const scrollPct = Math.min(100, Math.max(0, (window.scrollY / docHeight) * 100));
    progressBar.style.width = `${scrollPct}%`;
  }

  // 6. Smooth Anchor Navigation
  function initSmoothAnchorScroll() {
    document.addEventListener('click', (e) => {
      const link = e.target.closest('a[href^="#"]');
      if (!link) return;

      const hash = link.getAttribute('href');
      if (!hash || hash === '#' || hash === '#!') return;

      const target = document.querySelector(hash);
      if (!target) return;

      e.preventDefault();
      target.scrollIntoView({
        behavior: prefersReducedMotion ? 'auto' : 'smooth',
        block: 'start'
      });

      // Update URL hash without jump
      if (history.pushState) {
        history.pushState(null, '', hash);
      }

      // Ensure keyboard focus moves to target
      target.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    });
  }

  // 7. Dynamic Content MutationObserver (AJAX Dashboard Updates)
  function initMutationObserver() {
    if (typeof window.MutationObserver !== 'function') return;

    const observer = new MutationObserver((mutations) => {
      let shouldScan = false;
      for (const mutation of mutations) {
        if (mutation.addedNodes.length > 0) {
          for (const node of mutation.addedNodes) {
            if (node.nodeType === Node.ELEMENT_NODE) {
              if (node.matches && node.matches(REVEAL_SELECTOR)) {
                observeElement(node);
              }
              if (node.querySelectorAll) {
                const nested = node.querySelectorAll(REVEAL_SELECTOR);
                if (nested.length > 0) {
                  nested.forEach(observeElement);
                }
              }
              shouldScan = true;
            }
          }
        }
      }
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  // 8. Public API
  window.GMSAnimation = {
    observe: observeElement,
    refresh: scanAndObserve,
    revealAll: revealAllElements,
    isReducedMotion: () => prefersReducedMotion
  };

  // 9. DOM Ready Initialization
  function init() {
    scanAndObserve();
    ensureBackToTopButton();
    updateBackToTop();
    initSmoothAnchorScroll();
    initMutationObserver();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
