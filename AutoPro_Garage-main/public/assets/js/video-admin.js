/**
 * ALPHA GARAGE — VIDEO ADMIN JAVASCRIPT
 * Strictly CSP-compliant (no inline event handlers or inline scripts).
 * Handles admin in-page video previews, YouTube ID extraction & live preview,
 * category auto-submit filter, and upload/embed source toggling.
 */
(function () {
  'use strict';

  // --- Section 1: Admin Video Management Index Page ---
  var catFilterSelect = document.getElementById('vid-cat');
  if (catFilterSelect && catFilterSelect.form) {
    catFilterSelect.addEventListener('change', function () {
      catFilterSelect.form.submit();
    });
  }

  var previewModal = document.getElementById('admin-preview-modal');
  var previewContainer = document.getElementById('admin-preview-container');
  var previewTitle = document.getElementById('admin-preview-title');
  var previewDesc = document.getElementById('admin-preview-desc');

  function openAdminPreview(item) {
    if (!previewModal || !previewContainer) return;
    if (previewTitle) previewTitle.textContent = item.title || 'Video Preview';
    if (previewDesc) previewDesc.textContent = item.desc || '';
    previewContainer.innerHTML = '';

    if (item.source === 'youtube' && item.embed) {
      var ifr = document.createElement('iframe');
      ifr.src = item.embed;
      ifr.style.position = 'absolute';
      ifr.style.inset = '0';
      ifr.style.width = '100%';
      ifr.style.height = '100%';
      ifr.style.border = 'none';
      ifr.setAttribute('allowfullscreen', 'true');
      ifr.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
      ifr.setAttribute('title', item.title || 'Preview');
      previewContainer.appendChild(ifr);
    } else if (item.source === 'upload' && item.stream) {
      var vid = document.createElement('video');
      vid.controls = true;
      vid.autoplay = true;
      vid.playsInline = true;
      vid.style.position = 'absolute';
      vid.style.inset = '0';
      vid.style.width = '100%';
      vid.style.height = '100%';
      var src = document.createElement('source');
      src.src = item.stream;
      vid.appendChild(src);
      previewContainer.appendChild(vid);
    }

    previewModal.style.display = 'flex';
  }

  function closeAdminPreview() {
    if (previewModal) previewModal.style.display = 'none';
    if (previewContainer) previewContainer.innerHTML = '';
  }

  document.querySelectorAll('[data-admin-preview-trigger]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      var title = btn.getAttribute('data-title') || '';
      var desc = btn.getAttribute('data-desc') || '';
      var source = btn.getAttribute('data-source') || '';
      var embed = btn.getAttribute('data-embed') || '';
      var stream = btn.getAttribute('data-stream') || '';
      openAdminPreview({
        title: title,
        desc: desc,
        source: source,
        embed: embed,
        stream: stream
      });
    });
  });

  document.querySelectorAll('[data-admin-preview-close]').forEach(function (btn) {
    btn.addEventListener('click', function (e) {
      e.preventDefault();
      closeAdminPreview();
    });
  });

  if (previewModal) {
    previewModal.addEventListener('click', function (e) {
      if (e.target === previewModal) {
        closeAdminPreview();
      }
    });
  }

  window.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeAdminPreview();
    }
  });

  // --- Section 2: Admin Video Create / Edit Form Page ---
  var sourceRadios = document.querySelectorAll('input[name="source_type"]');
  var ytBox = document.getElementById('youtube-box');
  var upBox = document.getElementById('upload-box');

  function toggleSource(type) {
    if (!ytBox || !upBox) return;
    if (type === 'youtube') {
      ytBox.style.display = 'block';
      upBox.style.display = 'none';
    } else {
      ytBox.style.display = 'none';
      upBox.style.display = 'block';
    }
  }

  sourceRadios.forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (radio.checked) {
        toggleSource(radio.value);
      }
    });
  });

  function updateYoutubePreview(url) {
    var wrap = document.getElementById('yt-preview-wrap');
    var thumb = document.getElementById('yt-preview-thumb');
    var idDisplay = document.getElementById('yt-preview-id');
    if (!wrap || !thumb || !idDisplay) return;

    var id = null;
    var trimmed = (url || '').trim();
    if (/^[a-zA-Z0-9_-]{11}$/.test(trimmed)) {
      id = trimmed;
    } else {
      var match = trimmed.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?|shorts)\/|.*[?&]v=)|youtu\.be\/)([a-zA-Z0-9_-]{11})/i);
      if (match) id = match[1];
    }

    if (id) {
      idDisplay.textContent = id;
      thumb.src = 'https://img.youtube.com/vi/' + encodeURIComponent(id) + '/hqdefault.jpg';
      wrap.style.display = 'flex';
    } else {
      wrap.style.display = 'none';
    }
  }

  var ytInput = document.getElementById('f_youtube_url');
  if (ytInput) {
    ytInput.addEventListener('input', function () {
      updateYoutubePreview(ytInput.value);
    });
    if (ytInput.value) {
      updateYoutubePreview(ytInput.value);
    }
  }
})();
