/**
 * ALPHA GARAGE — VIDEO GALLERY SCRIPT
 * Manages video playback modal, real-time category filtering,
 * instant live search, and asynchronous view-count updates.
 */
(function () {
  'use strict';

  var modal = document.getElementById('video-modal');
  var modalTitle = document.getElementById('video-modal-title');
  var modalBody = document.getElementById('video-modal-body');
  var modalDesc = document.getElementById('video-modal-desc');
  var modalCloseBtn = document.getElementById('video-modal-close');
  var searchInput = document.getElementById('video-search-input');
  var categoryPills = document.querySelectorAll('.category-pill');
  var videoCards = document.querySelectorAll('.video-card');
  var countDisplay = document.getElementById('visible-video-count');
  var emptyState = document.getElementById('videos-empty-state');

  var currentCategory = 'all';
  var currentSearch = '';

  // Open Video Lightbox
  function openVideoModal(card) {
    if (!modal || !modalBody) return;

    var title = card.getAttribute('data-title') || '';
    var desc = card.getAttribute('data-desc') || '';
    var sourceType = card.getAttribute('data-source-type') || 'youtube';
    var embedUrl = card.getAttribute('data-embed-url') || '';
    var streamUrl = card.getAttribute('data-stream-url') || '';
    var videoId = card.getAttribute('data-video-id') || '';

    if (modalTitle) modalTitle.textContent = title;
    if (modalDesc) modalDesc.textContent = desc;

    modalBody.innerHTML = '';

    if (sourceType === 'youtube' && embedUrl) {
      var iframe = document.createElement('iframe');
      iframe.src = embedUrl;
      iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
      iframe.setAttribute('allowfullscreen', 'true');
      iframe.setAttribute('title', title);
      modalBody.appendChild(iframe);
    } else if (sourceType === 'upload' && streamUrl) {
      var video = document.createElement('video');
      video.controls = true;
      video.autoplay = true;
      video.playsInline = true;
      video.className = 'uploaded-player';

      var source = document.createElement('source');
      source.src = streamUrl;
      video.appendChild(source);
      modalBody.appendChild(video);
      video.play().catch(function () {});
    }

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    // Increment view count asynchronously
    if (videoId) {
      try {
        var baseUri = document.body.getAttribute('data-base-url') || '';
        var trackUrl = (baseUri ? baseUri.replace(/\/+$/, '') : '') + '/videos/view.php?id=' + encodeURIComponent(videoId);
        fetch(trackUrl, { method: 'POST', credentials: 'same-origin' }).catch(function () {});
      } catch (err) {}
    }
  }

  // Close Video Lightbox
  function closeVideoModal() {
    if (!modal || !modalBody) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    modalBody.innerHTML = ''; // Stops iframe / video playback immediately
  }

  if (modalCloseBtn) {
    modalCloseBtn.addEventListener('click', closeVideoModal);
  }

  if (modal) {
    modal.addEventListener('click', function (e) {
      if (e.target === modal) {
        closeVideoModal();
      }
    });
  }

  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal && modal.classList.contains('active')) {
      closeVideoModal();
    }
  });

  // Attach card click handlers
  videoCards.forEach(function (card) {
    var triggers = card.querySelectorAll('[data-play-trigger]');
    triggers.forEach(function (btn) {
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        openVideoModal(card);
      });
    });
  });

  // Filter logic
  function applyFilters() {
    var visibleCount = 0;
    var searchLower = currentSearch.toLowerCase().trim();

    videoCards.forEach(function (card) {
      var cat = (card.getAttribute('data-category') || '').toLowerCase();
      var title = (card.getAttribute('data-title') || '').toLowerCase();
      var desc = (card.getAttribute('data-desc') || '').toLowerCase();

      var matchesCategory = currentCategory === 'all' || cat === currentCategory.toLowerCase();
      var matchesSearch = !searchLower || title.indexOf(searchLower) !== -1 || desc.indexOf(searchLower) !== -1;

      if (matchesCategory && matchesSearch) {
        card.style.display = '';
        visibleCount++;
      } else {
        card.style.display = 'none';
      }
    });

    if (countDisplay) {
      countDisplay.textContent = visibleCount;
    }

    if (emptyState) {
      if (visibleCount === 0) {
        emptyState.removeAttribute('hidden');
        emptyState.style.setProperty('display', 'block', 'important');
      } else {
        emptyState.setAttribute('hidden', '');
        emptyState.style.setProperty('display', 'none', 'important');
      }
    }
  }

  // Category pills click
  categoryPills.forEach(function (pill) {
    pill.addEventListener('click', function (e) {
      e.preventDefault();
      categoryPills.forEach(function (p) { p.classList.remove('active'); });
      pill.classList.add('active');
      currentCategory = pill.getAttribute('data-category') || 'all';
      applyFilters();
    });
  });

  // Live search input
  if (searchInput) {
    searchInput.addEventListener('input', function () {
      currentSearch = searchInput.value || '';
      applyFilters();
    });
  }

  // Initial deep link: support ?play=ID or #play-ID
  function checkInitialPlay() {
    var match = window.location.search.match(/[?&]play=(\d+)/);
    if (!match && window.location.hash) {
      match = window.location.hash.match(/#play-(\d+)/);
    }
    if (match && match[1]) {
      var targetCard = document.querySelector('.video-card[data-video-id="' + match[1] + '"]');
      if (targetCard) {
        openVideoModal(targetCard);
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', checkInitialPlay);
  } else {
    checkInitialPlay();
  }
})();
