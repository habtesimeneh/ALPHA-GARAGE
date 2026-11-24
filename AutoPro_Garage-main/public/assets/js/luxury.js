/**
 * luxury.js — Luxury Automotive Interactive Engine
 * Handles:
 * - Crossfade automotive background image slider
 * - Smooth auto-typing text animation with reduced-motion fallback
 * - Password visibility toggles & real-time strength meter
 * - Sticky navigation scroll effects & mobile drawer
 */

(function () {
  'use strict';

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ==========================================================================
     1. Auto-Typing Text Animation (Typewriter)
     ========================================================================== */
  function initTypewriter(el, phrases, typeSpeed = 65, deleteSpeed = 35, pauseTime = 2200) {
    if (!el || !phrases || !phrases.length) return;

    if (prefersReducedMotion) {
      el.textContent = phrases[0];
      return;
    }

    let phraseIndex = 0;
    let charIndex = 0;
    let isDeleting = false;

    el.innerHTML = '<span class="typewriter-text"></span><span class="typewriter-cursor" aria-hidden="true">|</span>';
    const textSpan = el.querySelector('.typewriter-text');

    function tick() {
      const currentPhrase = phrases[phraseIndex];

      if (isDeleting) {
        charIndex--;
        textSpan.textContent = currentPhrase.substring(0, charIndex);
      } else {
        charIndex++;
        textSpan.textContent = currentPhrase.substring(0, charIndex);
      }

      let delta = isDeleting ? deleteSpeed : typeSpeed;

      if (!isDeleting && charIndex === currentPhrase.length) {
        delta = pauseTime;
        isDeleting = true;
      } else if (isDeleting && charIndex === 0) {
        isDeleting = false;
        phraseIndex = (phraseIndex + 1) % phrases.length;
        delta = 450;
      }

      setTimeout(tick, delta);
    }

    tick();
  }

  /* ==========================================================================
     2. Crossfade Automotive Background Slider
     ========================================================================== */
  function initSlider(container, interval = 4800) {
    if (!container) return;
    const slides = container.querySelectorAll('.luxury-slide');
    if (slides.length <= 1) return;

    let currentIndex = 0;

    if (prefersReducedMotion) {
      slides[0].classList.add('active');
      return;
    }

    setInterval(() => {
      slides[currentIndex].classList.remove('active');
      currentIndex = (currentIndex + 1) % slides.length;
      slides[currentIndex].classList.add('active');
    }, interval);
  }

  /* ==========================================================================
     3. Password Strength Meter
     ========================================================================== */
  function evaluatePasswordStrength(password) {
    let score = 0;
    if (!password) return { score: 0, label: '', color: '' };
    if (password.length >= 8) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;

    switch (score) {
      case 1:
        return { score: 1, label: 'Weak', class: 'str-weak' };
      case 2:
        return { score: 2, label: 'Fair', class: 'str-fair' };
      case 3:
        return { score: 3, label: 'Good', class: 'str-good' };
      case 4:
        return { score: 4, label: 'Strong', class: 'str-strong' };
      default:
        return { score: 0, label: '', class: '' };
    }
  }

  function initPasswordMeter() {
    const pwInput = document.querySelector('input[data-strength-target]');
    const bar = document.getElementById('password-strength-bar');
    const label = document.getElementById('password-strength-label');
    if (!pwInput || !bar) return;

    pwInput.addEventListener('input', () => {
      const val = pwInput.value;
      const res = evaluatePasswordStrength(val);

      bar.className = 'strength-bar-fill ' + (res.class || '');
      bar.style.width = res.score ? (res.score * 25) + '%' : '0%';
      if (label) {
        label.textContent = res.label ? `Strength: ${res.label}` : '';
      }
    });
  }

  /* ==========================================================================
     4. Password Visibility Toggles
     ========================================================================== */
  function initPasswordToggles() {
    document.querySelectorAll('.toggle-password').forEach((btn) => {
      btn.addEventListener('click', () => {
        const targetId = btn.getAttribute('data-target');
        const input = document.getElementById(targetId);
        if (!input) return;

        if (input.type === 'password') {
          input.type = 'text';
          btn.textContent = '🔒';
          btn.setAttribute('title', 'Hide password');
        } else {
          input.type = 'password';
          btn.textContent = '👁';
          btn.setAttribute('title', 'Show password');
        }
      });
    });
  }

  /* ==========================================================================
     5. Sticky Header Scroll Effect & Mobile Nav
     ========================================================================== */
  function initNavigation() {
    const header = document.querySelector('.luxury-navbar');
    if (header) {
      const handleScroll = () => {
        if (window.scrollY > 40) {
          header.classList.add('scrolled');
        } else {
          header.classList.remove('scrolled');
        }
      };
      window.addEventListener('scroll', handleScroll, { passive: true });
      handleScroll();
    }

    const navToggle = document.querySelector('.nav-toggle-btn');
    const navMenu = document.querySelector('.nav-menu-links');
    if (navToggle && navMenu) {
      const closeMenu = () => {
        navMenu.classList.remove('open');
        navToggle.setAttribute('aria-expanded', 'false');
        navToggle.setAttribute('aria-label', 'Open navigation menu');
      };

      navToggle.addEventListener('click', () => {
        const isExpanded = navToggle.getAttribute('aria-expanded') === 'true';
        if (isExpanded) {
          closeMenu();
        } else {
          navMenu.classList.add('open');
          navToggle.setAttribute('aria-expanded', 'true');
          navToggle.setAttribute('aria-label', 'Close navigation menu');
        }
      });

      // Close menu on link click
      navMenu.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
          closeMenu();
        });
      });

      document.addEventListener('click', (event) => {
        if (!navMenu.contains(event.target) && !navToggle.contains(event.target)) {
          closeMenu();
        }
      });

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && navToggle.getAttribute('aria-expanded') === 'true') {
          closeMenu();
          navToggle.focus();
        }
      });
    }
  }

  function initJourneyCarousel() {
    document.querySelectorAll('.journey-carousel').forEach((carousel) => {
      const slides = Array.from(carousel.querySelectorAll('.journey-slide'));
      const dots = Array.from(carousel.querySelectorAll('.journey-carousel-dot'));
      const previous = carousel.querySelector('.journey-previous');
      const next = carousel.querySelector('.journey-next');
      const pause = carousel.querySelector('.journey-carousel-pause');
      if (slides.length < 2 || dots.length !== slides.length || !previous || !next || !pause) return;

      let index = 0;
      let timer = null;
      let paused = prefersReducedMotion;
      pause.setAttribute('aria-pressed', String(paused));
      pause.textContent = paused ? 'Play' : 'Pause';

      const show = (nextIndex) => {
        index = (nextIndex + slides.length) % slides.length;
        slides.forEach((slide, slideIndex) => {
          const isActive = slideIndex === index;
          slide.hidden = !isActive;
          slide.classList.toggle('is-active', isActive);
        });
        dots.forEach((dot, dotIndex) => {
          const isActive = dotIndex === index;
          dot.classList.toggle('is-active', isActive);
          if (isActive) {
            dot.setAttribute('aria-current', 'true');
          } else {
            dot.removeAttribute('aria-current');
          }
        });
      };

      const stop = () => {
        window.clearInterval(timer);
        timer = null;
      };

      const start = () => {
        stop();
        if (paused || document.hidden || carousel.matches(':hover, :focus-within')) return;
        timer = window.setInterval(() => show(index + 1), 6000);
      };

      previous.addEventListener('click', () => {
        show(index - 1);
        start();
      });
      next.addEventListener('click', () => {
        show(index + 1);
        start();
      });
      dots.forEach((dot, dotIndex) => {
        dot.addEventListener('click', () => {
          show(dotIndex);
          start();
        });
      });
      pause.addEventListener('click', () => {
        paused = !paused;
        pause.setAttribute('aria-pressed', String(paused));
        pause.textContent = paused ? 'Play' : 'Pause';
        start();
      });

      carousel.addEventListener('mouseenter', stop);
      carousel.addEventListener('mouseleave', start);
      carousel.addEventListener('focusin', stop);
      carousel.addEventListener('focusout', (event) => {
        if (!carousel.contains(event.relatedTarget)) start();
      });
      document.addEventListener('visibilitychange', start);
      carousel.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft') {
          event.preventDefault();
          show(index - 1);
          start();
        } else if (event.key === 'ArrowRight') {
          event.preventDefault();
          show(index + 1);
          start();
        }
      });
      start();
    });
  }

  function initFooterAccordions() {
    const groups = Array.from(document.querySelectorAll('.footer-group'));
    if (!groups.length) return;

    const mobile = window.matchMedia('(max-width: 768px)');
    const sync = () => {
      groups.forEach((group) => {
        group.open = !mobile.matches;
      });
    };

    sync();
    mobile.addEventListener('change', sync);
  }

  function initMobileQuickActions() {
    const toggle = document.querySelector('.mobile-quick-actions-toggle');
    const options = document.getElementById('mobile-quick-actions-options');
    if (!toggle || !options) return;

    const close = () => {
      toggle.setAttribute('aria-expanded', 'false');
      options.hidden = true;
    };

    toggle.addEventListener('click', () => {
      const expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!expanded));
      options.hidden = expanded;
    });

    options.querySelectorAll('a').forEach((link) => {
      link.addEventListener('click', close);
    });

    document.addEventListener('click', (event) => {
      if (!toggle.contains(event.target) && !options.contains(event.target)) close();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
        close();
        toggle.focus();
      }
    });
  }

  /* ==========================================================================
     DOM Ready Initialization
     ========================================================================== */
  document.addEventListener('DOMContentLoaded', () => {
    // 1. Sliders
    document.querySelectorAll('.luxury-slider').forEach((slider) => {
      initSlider(slider);
    });

    // 2. Typewriter Elements (All elements with [data-typewriter])
    const typewriterEls = document.querySelectorAll('[data-typewriter]');
    typewriterEls.forEach((typewriterEl) => {
      let phrases = [];
      try {
        phrases = JSON.parse(typewriterEl.getAttribute('data-typewriter'));
      } catch (e) {
        phrases = [
          'Premium Automotive Care.',
          'Expert Vehicle Maintenance.',
          'Professional Garage Services.',
          'Your Vehicle, Our Priority.',
          'Driven by Excellence.'
        ];
      }
      if (phrases && phrases.length) {
        initTypewriter(typewriterEl, phrases);
      }
    });

    // 3. Password meter & toggles
    initPasswordMeter();
    initPasswordToggles();

    // 4. Navigation
    initNavigation();

    // 5. Rotating page highlights and visit guidance
    initJourneyCarousel();
    initFooterAccordions();
    initMobileQuickActions();

    // 6. FAQ Accordion
    initFaqAccordion();
  });

  /* ==========================================================================
     5. FAQ Accordion Component
     ========================================================================== */
  function initFaqAccordion() {
    const faqItems = document.querySelectorAll('.faq-item');
    if (!faqItems.length) return;

    faqItems.forEach((item) => {
      const btn = item.querySelector('.faq-question');
      if (!btn) return;

      btn.addEventListener('click', () => {
        const isActive = item.classList.contains('active');
        
        const parentAccordion = item.closest('.faq-accordion');
        if (parentAccordion) {
          parentAccordion.querySelectorAll('.faq-item.active').forEach((other) => {
            if (other !== item) {
              other.classList.remove('active');
              const otherButton = other.querySelector('.faq-question');
              if (otherButton) otherButton.setAttribute('aria-expanded', 'false');
            }
          });
        }
        item.classList.toggle('active', !isActive);
        btn.setAttribute('aria-expanded', String(!isActive));
      });
    });
  }
})();
