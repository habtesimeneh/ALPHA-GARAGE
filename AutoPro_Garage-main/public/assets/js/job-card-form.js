/* Job Card form: searchable dropdowns, dependent vehicles, service selection, cost summary.
   Uses existing /job-cards/data.php endpoint (same-origin, session+permission protected). */
(function () {
  'use strict';
  var form = document.querySelector('form[data-jobcard-form]');
  if (!form) return;
  var cfgEl = document.getElementById('jobcard-form-config');
  var cfg = {};
  try { cfg = JSON.parse((cfgEl && cfgEl.textContent) || '{}'); } catch (e) {}
  var dataUrl = cfg.dataUrl || 'data.php';
  var currency = cfg.currency || 'ETB';
  var selected = cfg.selected || {};

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function esc(s) { return String(s == null ? '' : s); }
  function money(n) {
    var x = Number(n || 0);
    if (!isFinite(x)) x = 0;
    return currency + ' ' + x.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function setFieldError(name, msg) {
    var el = document.getElementById('err_' + name);
    if (el) el.textContent = msg || '';
    var hidden = document.getElementById('f_' + name);
    var root = hidden && hidden.closest('.field');
    if (root) root.classList.toggle('has-error', !!msg);
  }

  function fetchItems(action, params) {
    var url = new URL(dataUrl, window.location.href);
    url.searchParams.set('action', action);
    Object.keys(params || {}).forEach(function (k) {
      if (params[k] !== undefined && params[k] !== null && params[k] !== '') url.searchParams.set(k, params[k]);
    });
    return fetch(url.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Invalid response' }; }); })
      .then(function (j) { if (!j || j.ok !== true) throw new Error((j && j.error) || 'Load failed'); return j; });
  }

  var combos = {};

  function createCombo(name, options) {
    options = options || {};
    var root = qs('[data-combobox="' + name + '"]');
    if (!root) return null;
    var input = qs('input[role="combobox"]', root);
    var panel = qs('.combobox-panel', root);
    var list = qs('[data-combobox-list]', root);
    var status = qs('[data-combobox-status]', root);
    var toggle = qs('.combobox-toggle', root);
    var hidden = document.getElementById('f_' + name + (name === 'mechanic' ? '_id' : '_id')) || document.getElementById('f_' + name);
    if (name === 'customer') hidden = document.getElementById('f_customer_id');
    if (name === 'vehicle') hidden = document.getElementById('f_vehicle_id');
    if (name === 'mechanic') hidden = document.getElementById('f_mechanic_id');
    var items = [];
    var activeIndex = -1;
    var open = false;
    var timer = null;
    var currentValue = (selected[name + '_id'] || (hidden ? hidden.value : '') || '') + '';

    function render(filterText) {
      list.innerHTML = '';
      var q = (filterText || '').toLowerCase().trim();
      var filtered = items.filter(function (it) { return !q || esc(it.label).toLowerCase().indexOf(q) !== -1; });
      activeIndex = -1;
      if (!filtered.length) {
        status.textContent = options.emptyMessage || (items.length ? 'No matches found.' : 'No records available.');
        status.hidden = false;
        return;
      }
      status.hidden = true;
      filtered.forEach(function (it, idx) {
        var li = document.createElement('li');
        li.className = 'combobox-option' + (String(it.id) === currentValue ? ' is-selected' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('data-id', String(it.id));
        li.setAttribute('data-label', it.label || '');
        li.tabIndex = -1;
        li.textContent = it.label || ('#' + it.id);
        li.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(it); });
        li.addEventListener('click', function () { choose(it); });
        list.appendChild(li);
      });
    }

    function setStatus(msg, isError) {
      status.textContent = msg;
      status.hidden = !msg;
      status.className = 'combobox-status' + (isError ? ' is-error' : '');
      if (isError || !items.length) list.innerHTML = '';
    }

    function openPanel() {
      if (input.disabled) return;
      open = true;
      panel.hidden = false;
      input.setAttribute('aria-expanded', 'true');
      render(input.value);
      if (options.onOpen) options.onOpen();
    }
    function closePanel() {
      open = false;
      panel.hidden = true;
      input.setAttribute('aria-expanded', 'false');
    }

    function choose(it) {
      currentValue = String(it.id);
      if (hidden) hidden.value = currentValue;
      input.value = it.label || '';
      setFieldError(name === 'mechanic' ? 'mechanic_id' : name + '_id', '');
      closePanel();
      if (options.onChange) options.onChange(it);
    }

    function clear(silent) {
      currentValue = '';
      if (hidden) hidden.value = '';
      if (!silent) input.value = '';
      if (options.onClear) options.onClear();
    }

    function load(params) {
      if (input.disabled) return Promise.resolve();
      setStatus('Loading…');
      panel.hidden = false; open = true; input.setAttribute('aria-expanded', 'true');
      return fetchItems(options.action || name + 's', params || {})
        .then(function (j) {
          items = (j.items || []).map(function (it) {
            if (name === 'customer' && !it.label) it.label = (it.name || '') + (it.phone ? ' (' + it.phone + ')' : '');
            if (name === 'vehicle' && !it.label) it.label = (it.plate_number || '') + ' — ' + (it.make || '') + ' ' + (it.model || '');
            if (name === 'mechanic' && !it.label) it.label = it.name || it.full_name || '';
            return it;
          });
          if (!items.length) {
            setStatus(options.emptyMessage || 'No records available.');
          } else {
            render(input.value);
          }
          // restore selection label if present
          if (currentValue) {
            var found = items.filter(function (it) { return String(it.id) === currentValue; })[0];
            if (found) { input.value = found.label; }
            else if (options.clearInvalid !== false) { clear(true); }
          }
        })
        .catch(function (err) {
          setStatus('Could not load data. Please check your connection and try again.', true);
          if (window.GMS && GMS.toast) GMS.toast('Failed to load ' + name + ' list.', 'error');
        });
    }

    input.addEventListener('focus', function () { if (!items.length && !input.disabled) load(options.getParams ? options.getParams() : {}); else openPanel(); });
    input.addEventListener('click', function () { if (open) closePanel(); else if (!items.length && !input.disabled) load(options.getParams ? options.getParams() : {}); else openPanel(); });
    input.addEventListener('input', function () {
      if (hidden && hidden.value && input.value !== (items.filter(function (it) { return String(it.id) === String(hidden.value); })[0] || {}).label) {
        // user edited text after selecting — keep id but allow search; clear on blur if invalid
      }
      if (!open) openPanel();
      clearTimeout(timer);
      var q = input.value;
      timer = setTimeout(function () {
        render(q);
        if (options.remoteSearch && q.trim().length >= 1) load(Object.assign({}, options.getParams ? options.getParams() : {}, { q: q.trim() }));
      }, 180);
    });
    input.addEventListener('blur', function () {
      setTimeout(function () {
        closePanel();
        if (hidden && hidden.value) {
          var found = items.filter(function (it) { return String(it.id) === String(hidden.value); })[0];
          if (found) input.value = found.label;
        } else if (!options.allowFreeText) {
          // keep typed text for search feel? no: clear to avoid invalid display
          var exact = items.filter(function (it) { return esc(it.label).toLowerCase() === esc(input.value).toLowerCase(); })[0];
          if (exact) choose(exact); else input.value = '';
        }
      }, 160);
    });
    input.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { closePanel(); input.blur(); }
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        if (!open) { openPanel(); return; }
        ev.preventDefault();
        var opts = qsa('.combobox-option', list);
        if (!opts.length) return;
        activeIndex += ev.key === 'ArrowDown' ? 1 : -1;
        if (activeIndex < 0) activeIndex = opts.length - 1;
        if (activeIndex >= opts.length) activeIndex = 0;
        opts.forEach(function (o) { o.classList.remove('is-active'); });
        opts[activeIndex].classList.add('is-active');
        opts[activeIndex].scrollIntoView({ block: 'nearest' });
      }
      if (ev.key === 'Enter' && open) {
        var active = qs('.combobox-option.is-active', list) || qs('.combobox-option', list);
        if (active) { ev.preventDefault(); active.click(); }
      }
    });
    if (toggle) toggle.addEventListener('click', function () { if (open) closePanel(); else { input.focus(); if (!items.length) load(options.getParams ? options.getParams() : {}); else openPanel(); } });

    var api = { load: load, clear: clear, choose: choose, setItems: function (arr) { items = arr || []; render(''); }, getValue: function () { return currentValue; }, setDisabled: function (d) { input.disabled = !!d; if (d) closePanel(); } };
    combos[name] = api;
    return api;
  }

  // Services multi-select
  var serviceState = { items: [], selected: (selected.service_ids || []).map(String) };
  function loadServices() {
    var panel = qs('[data-combobox="service"] .combobox-panel');
    var status = qs('[data-combobox="service"] [data-combobox-status]');
    var list = qs('[data-combobox="service"] [data-combobox-list]');
    if (!panel) return;
    status.textContent = 'Loading services…'; status.hidden = false; status.className = 'combobox-status';
    fetchItems('services').then(function (j) {
      serviceState.items = j.items || [];
      renderServiceList('');
      renderSelectedServices();
      updateCostSummary();
    }).catch(function () { status.textContent = 'Could not load services.'; status.hidden = false; status.className = 'combobox-status is-error'; });
  }
  function renderServiceList(q) {
    var list = qs('[data-combobox="service"] [data-combobox-list]');
    var status = qs('[data-combobox="service"] [data-combobox-status]');
    if (!list) return;
    list.innerHTML = '';
    var qq = (q || '').toLowerCase().trim();
    var filtered = serviceState.items.filter(function (it) { return !qq || esc(it.label).toLowerCase().indexOf(qq) !== -1 || esc(it.category).toLowerCase().indexOf(qq) !== -1; });
    if (!filtered.length) { status.textContent = serviceState.items.length ? 'No matching services.' : 'No active services found.'; status.hidden = false; return; }
    status.hidden = true;
    filtered.forEach(function (it) {
      var li = document.createElement('li');
      var sel = serviceState.selected.indexOf(String(it.id)) !== -1;
      li.className = 'combobox-option' + (sel ? ' is-selected' : '');
      li.setAttribute('role', 'option');
      li.innerHTML = '<span>' + esc(it.label) + '</span>' + (sel ? ' <small class="muted">(added)</small>' : '');
      li.addEventListener('mousedown', function (ev) { ev.preventDefault(); if (!sel) addService(it); });
      list.appendChild(li);
    });
  }
  function addService(it) {
    var id = String(it.id);
    if (serviceState.selected.indexOf(id) !== -1) return;
    serviceState.selected.push(id);
    renderSelectedServices();
    renderServiceList(qs('[data-combobox="service"] input').value);
    updateCostSummary();
  }
  function removeService(id) {
    serviceState.selected = serviceState.selected.filter(function (x) { return x !== String(id); });
    renderSelectedServices();
    renderServiceList((qs('[data-combobox="service"] input') || {}).value || '');
    updateCostSummary();
  }
  function renderSelectedServices() {
    var wrap = document.getElementById('selected_services');
    var container = document.getElementById('service_ids_container');
    if (!wrap || !container) return;
    container.innerHTML = '';
    wrap.innerHTML = '';
    if (!serviceState.selected.length) { wrap.innerHTML = '<span class="muted small">No services selected.</span>'; return; }
    serviceState.selected.forEach(function (id) {
      var it = serviceState.items.filter(function (s) { return String(s.id) === String(id); })[0];
      var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'service_ids[]'; inp.value = id; container.appendChild(inp);
      var chip = document.createElement('span');
      chip.className = 'service-chip';
      chip.innerHTML = '<span>' + esc(it ? it.name : ('Service #' + id)) + (it ? ' · ' + money(it.price) : '') + '</span>';
      var btn = document.createElement('button'); btn.type = 'button'; btn.className = 'service-chip-remove'; btn.setAttribute('aria-label', 'Remove service'); btn.textContent = '×';
      btn.addEventListener('click', function () { removeService(id); });
      chip.appendChild(btn);
      wrap.appendChild(chip);
    });
  }
  function updateCostSummary() {
    var base = parseFloat((document.getElementById('f_labor_cost') || {}).value || '0');
    if (!isFinite(base)) base = 0;
    var svc = 0;
    serviceState.selected.forEach(function (id) {
      var it = serviceState.items.filter(function (s) { return String(s.id) === String(id); })[0];
      if (it) svc += parseFloat(it.price || 0) || 0;
    });
    var baseEl = document.getElementById('cost_base_labor');
    var svcEl = document.getElementById('cost_services');
    var totEl = document.getElementById('cost_total');
    if (baseEl) baseEl.textContent = money(base);
    if (svcEl) svcEl.textContent = money(svc);
    if (totEl) totEl.textContent = money(base + svc);
  }
  var labor = document.getElementById('f_labor_cost');
  if (labor) { labor.addEventListener('input', updateCostSummary); labor.addEventListener('change', updateCostSummary); }

  // Service combobox behavior
  var svcRoot = qs('[data-combobox="service"]');
  if (svcRoot) {
    var svcInput = qs('input', svcRoot);
    var svcPanel = qs('.combobox-panel', svcRoot);
    var svcToggle = qs('.combobox-toggle', svcRoot);
    function openSvc() { svcPanel.hidden = false; if (!serviceState.items.length) loadServices(); else renderServiceList(svcInput.value); }
    function closeSvc() { svcPanel.hidden = true; }
    svcInput.addEventListener('focus', openSvc);
    svcInput.addEventListener('input', function () { if (svcPanel.hidden) openSvc(); renderServiceList(svcInput.value); });
    svcInput.addEventListener('blur', function () { setTimeout(closeSvc, 160); });
    if (svcToggle) svcToggle.addEventListener('click', function () { if (svcPanel.hidden) { svcInput.focus(); openSvc(); } else closeSvc(); });
  }

  // Customer -> Vehicle dependency
  createCombo('customer', {
    action: 'customers',
    remoteSearch: true,
    emptyMessage: 'No active customers found. Register a customer first.',
    onChange: function () {
      setFieldError('vehicle_id', '');
      var veh = combos.vehicle;
      var vehId = document.getElementById('f_vehicle_id');
      var vehInput = qs('[data-combobox="vehicle"] input');
      if (vehId) vehId.value = '';
      if (vehInput) vehInput.value = '';
      if (veh) { veh.setItems([]); veh.setDisabled(false); veh.load({ customer_id: document.getElementById('f_customer_id').value }); }
    },
    onClear: function () {
      var veh = combos.vehicle;
      var vehId = document.getElementById('f_vehicle_id');
      var vehInput = qs('[data-combobox="vehicle"] input');
      if (vehId) vehId.value = '';
      if (vehInput) vehInput.value = '';
      if (veh) { veh.setItems([]); veh.setDisabled(true); var st = qs('[data-combobox="vehicle"] [data-combobox-status]'); if (st) { st.textContent = 'Select a customer first.'; st.hidden = false; st.className = 'combobox-status'; } qs('[data-combobox="vehicle"] [data-combobox-list]').innerHTML = ''; }
    }
  });

  createCombo('vehicle', {
    action: 'vehicles',
    remoteSearch: true,
    emptyMessage: 'No active vehicles found for this customer.',
    getParams: function () { return { customer_id: (document.getElementById('f_customer_id') || {}).value || '' }; },
    onOpen: function () {
      var cid = (document.getElementById('f_customer_id') || {}).value;
      if (!cid) { var st = qs('[data-combobox="vehicle"] [data-combobox-status]'); if (st) { st.textContent = 'Select a customer first.'; st.hidden = false; } }
    }
  });
  if (!(document.getElementById('f_customer_id') || {}).value && combos.vehicle) combos.vehicle.setDisabled(true);

  if (qs('[data-combobox="mechanic"]')) {
    createCombo('mechanic', { action: 'mechanics', remoteSearch: true, emptyMessage: 'No active mechanics found.', clearInvalid: true });
  }

  // Submit guard: ensure required IDs present
  form.addEventListener('submit', function (e) {
    var create = !!qs('[data-combobox="customer"]');
    if (!create) return;
    var ok = true;
    if (!(document.getElementById('f_customer_id') || {}).value) { setFieldError('customer_id', 'Customer is required.'); ok = false; }
    if (!(document.getElementById('f_vehicle_id') || {}).value) { setFieldError('vehicle_id', 'Vehicle is required.'); ok = false; }
    var cid = (document.getElementById('f_customer_id') || {}).value;
    var vid = (document.getElementById('f_vehicle_id') || {}).value;
    if (cid && vid && combos.vehicle) {
      // vehicle item list may not be loaded; server validates relationship.
    }
    if (!ok) { e.preventDefault(); window.GMS && GMS.toast && GMS.toast('Please select required customer and vehicle.', 'error'); }
  });

  updateCostSummary();
  // Preload lists lightly after paint
  setTimeout(function () {
    if (combos.customer && !combos.customer.getValue()) combos.customer.load();
    if (combos.mechanic) combos.mechanic.load();
    if (svcRoot) loadServices();
    if (combos.vehicle && (document.getElementById('f_customer_id') || {}).value) combos.vehicle.load({ customer_id: document.getElementById('f_customer_id').value });
  }, 60);
})();
