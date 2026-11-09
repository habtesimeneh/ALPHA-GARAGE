(() => {
  const hero = document.querySelector('.home-hero');
  if (!hero) return;

  const slides = Array.from(hero.querySelectorAll('.home-hero-slide'));
  const dots = Array.from(hero.querySelectorAll('.home-hero-dot'));
  const toggle = hero.querySelector('.home-hero-toggle');
  if (slides.length < 2 || dots.length !== slides.length || !toggle) return;

  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let activeIndex = 0;
  let paused = reducedMotion.matches;

  const showSlide = (index) => {
    activeIndex = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => slide.classList.toggle('is-active', i === activeIndex));
    dots.forEach((dot, i) => {
      dot.classList.toggle('is-active', i === activeIndex);
      if (i === activeIndex) dot.setAttribute('aria-current', 'true');
      else dot.removeAttribute('aria-current');
    });
  };

  const updateToggle = () => {
    toggle.textContent = paused ? 'Play slideshow' : 'Pause slideshow';
    toggle.setAttribute('aria-pressed', String(paused));
  };

  dots.forEach((dot, index) => {
    dot.addEventListener('click', () => showSlide(index));
  });

  toggle.addEventListener('click', () => {
    paused = !paused;
    updateToggle();
  });

  reducedMotion.addEventListener('change', (event) => {
    paused = event.matches;
    updateToggle();
  });

  updateToggle();
  window.setInterval(() => {
    if (!paused && document.visibilityState === 'visible') showSlide(activeIndex + 1);
  }, 6000);
})();
