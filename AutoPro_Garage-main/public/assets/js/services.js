(function () {
  'use strict';

  const filters = Array.from(document.querySelectorAll('[data-service-filter]'));
  const cards = Array.from(document.querySelectorAll('[data-service-card]'));
  const emptyState = document.querySelector('[data-service-filter-empty]');

  if (!filters.length || !cards.length) return;

  filters.forEach((filter) => {
    filter.addEventListener('click', () => {
      const selectedCategory = filter.dataset.serviceFilter;
      let visibleCount = 0;

      filters.forEach((item) => {
        const selected = item === filter;
        item.classList.toggle('is-active', selected);
        item.setAttribute('aria-pressed', String(selected));
      });

      cards.forEach((card) => {
        const visible = selectedCategory === 'all' || card.dataset.serviceCategory === selectedCategory;
        card.hidden = !visible;
        if (visible) visibleCount++;
      });

      if (emptyState) emptyState.hidden = visibleCount > 0;
    });
  });
})();
