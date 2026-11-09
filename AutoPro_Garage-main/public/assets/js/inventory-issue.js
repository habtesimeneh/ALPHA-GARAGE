/* Inventory issue page: searchable job-card picker + searchable part comboboxes.
   Part comboboxes drive hidden/native selects so app.js estimator/server POST remain compatible. */
(function () {
  'use strict';
  function qs(s, r) { return (r || document).querySelector(s); }
  function qsa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function esc(s) { return String(s == null ? '' : s); }

  var dataEl = document.getElementById('issue-form-data');
  var data = {};
  try { data = JSON.parse((dataEl && dataEl.textContent) || '{}'); } catch (e) {}
  var jobs = data.jobs || [];
  var selectedJobId = String(data.selectedJobId || '');

  var hiddenJob = document.getElementById('f_issue_job_id');
  var root = qs('[data-combobox="issue-job"]');
  if (root && hiddenJob) {
    var input = qs('input[role="combobox"]', root);
    var panel = qs('.combobox-panel', root);
    var list = qs('[data-combobox-list]', root);
    var status = qs('[data-combobox-status]', root);
    var toggle = qs('.combobox-toggle', root);
    var err = document.getElementById('err_issue_job_id');
    var open = false;
    var selected = jobs.filter(function (j) { return String(j.id) === selectedJobId; })[0];
    if (selected) { input.value = selected.label; hiddenJob.value = String(selected.id); }

    function render(q) {
      list.innerHTML = '';
      var qq = esc(q).toLowerCase().trim();
      var filtered = jobs.filter(function (j) { return !qq || esc(j.label).toLowerCase().indexOf(qq) !== -1; });
      if (!filtered.length) {
        status.textContent = jobs.length ? 'No matching open job cards.' : 'No open job cards available to issue parts to.';
        status.hidden = false;
        status.className = 'combobox-status';
        return;
      }
      status.hidden = true;
      filtered.slice(0, 150).forEach(function (j) {
        var li = document.createElement('li');
        li.className = 'combobox-option' + (String(j.id) === String(hiddenJob.value) ? ' is-selected' : '');
        li.setAttribute('role', 'option');
        li.tabIndex = -1;
        li.textContent = j.label;
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(j); });
        list.appendChild(li);
      });
    }
    function openPanel() { open = true; panel.hidden = false; input.setAttribute('aria-expanded', 'true'); render(input.value); }
    function closePanel() { open = false; panel.hidden = true; input.setAttribute('aria-expanded', 'false'); }
    function choose(j) {
      hiddenJob.value = String(j.id);
      input.value = j.label;
      if (err) err.textContent = '';
      closePanel();
    }
    input.addEventListener('focus', openPanel);
    input.addEventListener('click', function () { if (open) closePanel(); else openPanel(); });
    input.addEventListener('input', function () {
      if (hiddenJob.value && selected && input.value !== selected.label) { hiddenJob.value = ''; }
      if (!open) openPanel();
      render(input.value);
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        closePanel();
        if (hiddenJob.value) {
          var s = jobs.filter(function (j) { return String(j.id) === String(hiddenJob.value); })[0];
          if (s) { input.value = s.label; selected = s; }
        } else {
          var exact = jobs.filter(function (j) { return esc(j.label).toLowerCase() === esc(input.value).toLowerCase(); })[0];
          if (exact) choose(exact); else if (input.value.trim()) input.value = '';
        }
      }, 150);
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') closePanel();
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault(); if (!open) openPanel();
        var opts = qsa('.combobox-option', list); if (!opts.length) return;
        var idx = opts.map(function (o) { return o.classList.contains('is-active'); }).indexOf(true);
        idx += ev.key === 'ArrowDown' ? 1 : -1;
        if (idx < 0) idx = opts.length - 1; if (idx >= opts.length) idx = 0;
        opts.forEach(function (o) { o.classList.remove('is-active'); }); opts[idx].classList.add('is-active'); opts[idx].scrollIntoView({ block: 'nearest' });
      }
      if (ev.key === 'Enter' && open) { var a = qs('.combobox-option.is-active', list) || qs('.combobox-option', list); if (a) { ev.preventDefault(); a.dispatchEvent(new MouseEvent('mousedown')); } }
    });
    if (toggle) toggle.addEventListener('click', function () { if (open) closePanel(); else { input.focus(); openPanel(); } });

    var form = input.closest('form');
    if (form) form.addEventListener('submit', function (e) {
      if (!hiddenJob.value) { e.preventDefault(); if (err) err.textContent = 'Select a job card.'; input.focus(); }
    });
  }

  // Part searchable dropdowns (keeps hidden/native select for app.js + form POST)
  qsa('[data-combobox="issue-part"]').forEach(function (combo) {
    var line = combo.getAttribute('data-line');
    var select = document.getElementById('issue-part-' + line);
    var input = qs('input[role="combobox"]', combo);
    var panel = qs('.combobox-panel', combo);
    var list = qs('[data-combobox-list]', combo);
    var status = qs('[data-combobox-status]', combo);
    var toggle = qs('.combobox-toggle', combo);
    if (!select || !input || !panel || !list) return;

    var options = qsa('option', select).slice(1).map(function (o) {
      return {
        value: o.value,
        label: o.getAttribute('data-label') || o.textContent || '',
        search: esc(o.getAttribute('data-search') || o.textContent).toLowerCase()
      };
    });
    var open = false;
    var activeIndex = -1;

    function syncFromSelect() {
      var opt = select.options[select.selectedIndex];
      input.value = (opt && opt.value) ? (opt.getAttribute('data-label') || opt.textContent || '') : '';
    }
    function setSelection(optData) {
      select.value = optData ? optData.value : '';
      input.value = optData ? optData.label : '';
      select.dispatchEvent(new Event('change', { bubbles: true }));
      closePanel();
    }
    function render(q) {
      list.innerHTML = '';
      var qq = esc(q).toLowerCase().trim();
      var filtered = options.filter(function (o) { return !qq || o.search.indexOf(qq) !== -1 || o.label.toLowerCase().indexOf(qq) !== -1; });
      activeIndex = -1;
      if (!filtered.length) {
        status.textContent = options.length ? 'No matching parts found.' : 'No available parts.';
        status.hidden = false;
        return;
      }
      status.hidden = true;
      filtered.slice(0, 120).forEach(function (o) {
        var li = document.createElement('li');
        li.className = 'combobox-option' + (o.value === select.value ? ' is-selected' : '');
        li.setAttribute('role', 'option');
        li.tabIndex = -1;
        li.textContent = o.label;
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); setSelection(o); });
        list.appendChild(li);
      });
    }
    function openPanel() { open = true; panel.hidden = false; input.setAttribute('aria-expanded', 'true'); render(input.value === (select.options[select.selectedIndex] && select.options[select.selectedIndex].getAttribute('data-label')) ? '' : input.value); }
    function closePanel() { open = false; panel.hidden = true; input.setAttribute('aria-expanded', 'false'); activeIndex = -1; }

    input.addEventListener('focus', openPanel);
    input.addEventListener('click', function () { if (open) closePanel(); else openPanel(); });
    input.addEventListener('input', function () {
      if (select.value) { select.value = ''; select.dispatchEvent(new Event('change', { bubbles: true })); }
      if (!open) openPanel();
      render(input.value);
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        closePanel();
        if (select.value) syncFromSelect();
        else {
          var exact = options.filter(function (o) { return o.label.toLowerCase() === esc(input.value).toLowerCase().trim(); })[0];
          if (exact) setSelection(exact); else input.value = '';
        }
      }, 150);
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { closePanel(); syncFromSelect(); }
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault(); if (!open) openPanel();
        var opts = qsa('.combobox-option', list); if (!opts.length) return;
        activeIndex += ev.key === 'ArrowDown' ? 1 : -1;
        if (activeIndex < 0) activeIndex = opts.length - 1; if (activeIndex >= opts.length) activeIndex = 0;
        opts.forEach(function (o) { o.classList.remove('is-active'); });
        opts[activeIndex].classList.add('is-active'); opts[activeIndex].scrollIntoView({ block: 'nearest' });
      }
      if (ev.key === 'Enter' && open) {
        var a = qs('.combobox-option.is-active', list) || qs('.combobox-option', list);
        if (a) { ev.preventDefault(); a.dispatchEvent(new MouseEvent('mousedown')); }
      }
    });
    if (toggle) toggle.addEventListener('click', function () { if (open) closePanel(); else { input.focus(); openPanel(); } });

    select.addEventListener('change', syncFromSelect);
    syncFromSelect();
  });

  // Stock warnings/units around app.js estimator
  function refreshWarnings() {
    qsa('[data-issue-line]').forEach(function (line) {
      var select = qs('[data-issue-part]', line);
      var qty = qs('[data-issue-quantity]', line);
      var unit = qs('[data-issue-unit]', line);
      var warn = qs('[data-issue-warn]', line);
      if (!select || !qty) return;
      var opt = select.options[select.selectedIndex];
      var stock = opt ? Number(opt.getAttribute('data-stock') || 0) : 0;
      var reorder = opt ? Number(opt.getAttribute('data-reorder') || 0) : 0;
      var val = Number(qty.value || 0);
      var text = opt ? (opt.getAttribute('data-unit') || (opt.textContent.match(/\d+\s+([^\s]+)\s+available/) || [])[1] || '') : '';
      if (unit) unit.textContent = select.value ? (text || 'units') : '—';
      if (warn) {
        if (select.value && val > stock) { warn.hidden = false; warn.textContent = 'Quantity exceeds available stock.'; warn.className = 'stock-warn is-error'; }
        else if (select.value && stock > 0 && stock <= reorder) { warn.hidden = false; warn.textContent = 'Low stock alert.'; warn.className = 'stock-warn is-warn'; }
        else { warn.hidden = true; warn.textContent = ''; }
      }
    });
  }
  document.addEventListener('input', function (e) { if (e.target && (e.target.matches('[data-issue-part],[data-issue-quantity]'))) setTimeout(refreshWarnings, 0); });
  document.addEventListener('change', function (e) { if (e.target && e.target.matches('[data-issue-part]')) setTimeout(refreshWarnings, 0); });
  refreshWarnings();
})();
