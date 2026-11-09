(function () {
  'use strict';

  const explorer = document.querySelector('[data-specialty-explorer]');
  if (!explorer) return;

  const tabs = Array.from(explorer.querySelectorAll('[data-specialty]'));
  const panel = explorer.querySelector('[role="tabpanel"]');
  const kicker = explorer.querySelector('[data-specialty-kicker]');
  const title = explorer.querySelector('[data-specialty-title]');
  const description = explorer.querySelector('[data-specialty-description]');
  const image = explorer.querySelector('[data-specialty-image]');

  const specialties = {
    diagnostics: {
      kicker: 'Start with the evidence',
      title: 'Vehicle diagnostics',
      description: 'A technician can review warning indicators, listen to your observations, and inspect relevant systems to help identify possible causes before recommending next steps.',
      image: 'https://images.unsplash.com/photo-1486262715619-67b85e0b08d3?auto=format&fit=crop&w=1200&q=85',
      alt: 'Vehicle in an automotive workshop'
    },
    engine: {
      kicker: 'Build a maintenance picture',
      title: 'Engine and maintenance',
      description: 'Routine checks can include visible leaks, fluid condition, filters, belts, and other vehicle-specific maintenance items. Ask the team which checks fit your make and model.',
      image: 'https://images.unsplash.com/photo-1619642751034-765dfdf7c58e?auto=format&fit=crop&w=1200&q=85',
      alt: 'Automotive technician working carefully in a repair workshop'
    },
    brakes: {
      kicker: 'Pay attention to how it feels',
      title: 'Brakes and suspension',
      description: 'Changes such as vibration, pulling, unusual sounds, or a different pedal feel are useful details to share. An inspection can help determine which components need attention.',
      image: 'https://images.unsplash.com/photo-1503376780353-7e6692767b70?auto=format&fit=crop&w=1200&q=85',
      alt: 'Vehicle shown as an example while discussing handling and maintenance'
    },
    electrical: {
      kicker: 'Follow the warning signs',
      title: 'Electrical systems',
      description: 'A warning light, intermittent starting issue, or electrical accessory problem can have several possible causes. Share when it happens so the team can plan a focused inspection.',
      image: 'https://images.unsplash.com/photo-1580273916550-e323be2ae537?auto=format&fit=crop&w=1200&q=85',
      alt: 'Modern vehicle in a workshop setting'
    }
  };

  function activate(tab, moveFocus) {
    const specialty = specialties[tab.dataset.specialty];
    if (!specialty || !panel || !kicker || !title || !description || !image) return;

    tabs.forEach((item) => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', String(active));
      item.tabIndex = active ? 0 : -1;
    });

    panel.setAttribute('aria-labelledby', tab.id);
    kicker.textContent = specialty.kicker;
    title.textContent = specialty.title;
    description.textContent = specialty.description;
    image.alt = specialty.alt;
    image.src = specialty.image;

    if (moveFocus) tab.focus();
  }

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(tab, false));
    tab.addEventListener('keydown', (event) => {
      let nextIndex = index;
      if (event.key === 'ArrowRight' || event.key === 'ArrowDown') nextIndex = (index + 1) % tabs.length;
      else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') nextIndex = (index - 1 + tabs.length) % tabs.length;
      else if (event.key === 'Home') nextIndex = 0;
      else if (event.key === 'End') nextIndex = tabs.length - 1;
      else return;

      event.preventDefault();
      activate(tabs[nextIndex], true);
    });
  });
})();
