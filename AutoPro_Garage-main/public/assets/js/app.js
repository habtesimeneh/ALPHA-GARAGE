/* Global UI behaviour: toasts, sidebar, confirm modal, loading state, table filter. Vanilla ES6+. */
(function () {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => Array.from(r.querySelectorAll(s));

  /* ---- toasts ---- */
  function toast(message, type = 'info', ms = 6000) {
    const root = $('#toast-root'); if (!root) return;
    const t = document.createElement('div');
    t.className = 'toast ' + type; t.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const span = document.createElement('span'); span.textContent = message;
    const x = document.createElement('button'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); x.textContent = '×';
    x.addEventListener('click', () => t.remove());
    t.append(span, x); root.append(t);
    if (ms) setTimeout(() => t.remove(), ms);
  }
  window.GMS = { toast };
  try {
    const root = $('#toast-root');
    (JSON.parse(root.dataset.flash || '[]')).forEach(f => toast(f.message, f.type));
  } catch (e) { /* ignore malformed flash */ }

  /* ---- sidebar (mobile) ---- */
  const sb = $('#sidebar'), bd = $('#sidebar-backdrop'), mb = $('#menu-btn');
  const setSidebar = open => { if (!sb) return; sb.classList.toggle('open', open); bd && bd.classList.toggle('show', open); };
  const setSidebarState = open => {
    setSidebar(open);
    if (mb) {
      mb.setAttribute('aria-expanded', String(open));
      mb.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
  };
  mb && mb.setAttribute('aria-expanded', 'false');
  mb && mb.addEventListener('click', () => setSidebarState(!sb.classList.contains('open')));
  bd && bd.addEventListener('click', () => setSidebarState(false));
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      const wasOpen = !!sb && sb.classList.contains('open');
      setSidebarState(false);
      if (wasOpen && mb) mb.focus();
      closeModal();
    }
  });

  /* ---- persistent light/dark theme ---- */
  const themeToggles = $$('.theme-toggle');
  const THEME_KEY = 'gms.theme';
  const themeStore = {
    get() { try { return localStorage.getItem(THEME_KEY); } catch (e) { return null; } },
    set(value) { try { localStorage.setItem(THEME_KEY, value); } catch (e) { /* storage unavailable */ } }
  };
  const savedTheme = themeStore.get();
  const systemPrefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  const applyTheme = theme => {
    const dark = theme === 'dark';
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    themeToggles.forEach(toggle => {
      toggle.setAttribute('aria-pressed', String(dark));
      toggle.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
      toggle.title = dark ? 'Switch to light theme' : 'Switch to dark theme';
      const icon = $('span[aria-hidden="true"]', toggle);
      if (icon) icon.textContent = dark ? '☼' : '◐';
      const label = $('.theme-toggle-label', toggle);
      if (label) label.textContent = dark ? 'Light mode' : 'Dark mode';
    });
  };
  applyTheme(savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : (systemPrefersDark ? 'dark' : 'light'));
  themeToggles.forEach(toggle => toggle.addEventListener('click', () => {
    const theme = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    themeStore.set(theme);
    applyTheme(theme);
  }));


  /* ---- desktop sidebar collapse (remembered per browser) ---- */
  const tg = $('#sidebar-toggle');
  const KEY = 'gms.sidebar.collapsed';
  const store = { get() { try { return localStorage.getItem(KEY); } catch (e) { return null; } }, set(v) { try { localStorage.setItem(KEY, v); } catch (e) { /* storage unavailable */ } } };
  if (store.get() === '1') document.body.classList.add('sidebar-collapsed');
  tg && tg.addEventListener('click', () => { const c = document.body.classList.toggle('sidebar-collapsed'); store.set(c ? '1' : '0'); });

  /* ---- top-bar search: filters the rows of the first data table on the page ---- */
  const tsWrap = $('#topbar-search'), tsInput = $('#topbar-search-input');
  const pageTable = $('main .table tbody');
  if (tsWrap && tsInput && pageTable) {
    tsWrap.hidden = false;
    tsInput.addEventListener('input', () => {
      const q = tsInput.value.trim().toLowerCase();
      $$('tr', pageTable).forEach(r => { r.hidden = q !== '' && !r.textContent.toLowerCase().includes(q); });
    });
  }

  /* ---- profile dropdown: close on outside click / Escape ---- */
  const um = $('#user-menu');
  if (um) {
    document.addEventListener('click', e => { if (um.open && !um.contains(e.target)) um.open = false; });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && um.open) { um.open = false; $('summary', um).focus(); } });
  }

  /* ---- confirm modal ---- */
  const modal = $('#modal'); let pendingForm = null;
  function openModal(text, form) { if (!modal) return form.submit(); pendingForm = form; $('#modal-text').textContent = text; modal.hidden = false; $('#modal-ok').focus(); }
  function closeModal() { if (modal) { modal.hidden = true; pendingForm = null; } }
  $('#modal-cancel') && $('#modal-cancel').addEventListener('click', closeModal);
  $('#modal-ok') && $('#modal-ok').addEventListener('click', () => { const f = pendingForm; closeModal(); if (f) { f.dataset.confirmed = '1'; showLoader(); f.submit(); } });
  modal && modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

  /* ---- loading indicator ---- */
  const loader = $('#loader');
  function showLoader() { loader && (loader.hidden = false); }
  window.addEventListener('pageshow', () => { loader && (loader.hidden = true); $$('.is-loading').forEach(b => b.classList.remove('is-loading')); });

  document.addEventListener('submit', e => {
    const f = e.target;
    if (f.dataset.confirm && !f.dataset.confirmed) { e.preventDefault(); openModal(f.dataset.confirm, f); return; }
    if (e.defaultPrevented) return;                       // validation.js may have blocked it
    const btn = f.querySelector('button[type=submit]:not([data-no-loader])');
    if (btn) btn.classList.add('is-loading');
    if (!f.hasAttribute('data-no-loader')) setTimeout(showLoader, 250);
  });

  /* ---- instant client-side table filter (server-side search still applies for pagination) ---- */
  $$('input[data-table-filter]').forEach(inp => {
    const rows = $$('#' + inp.dataset.tableFilter + ' tbody tr');
    inp.addEventListener('input', () => {
      const q = inp.value.trim().toLowerCase();
      rows.forEach(r => { r.hidden = q !== '' && !r.textContent.toLowerCase().includes(q); });
    });
  });

  /* ---- auto-submit selects used as filters ---- */
  $$('select[data-autosubmit]').forEach(s => s.addEventListener('change', () => s.form.submit()));

  /* ---- multi-part issue estimate (server rechecks all prices and stock) ---- */
  $$('form[data-issue-parts]').forEach(form => {
    const updateIssueEstimate = () => {
      let total = 0;
      const selectedParts = new Set();
      let duplicate = false;
      $$('.issue-part-line', form).forEach(line => {
        const select = $('[data-issue-part]', line);
        const quantity = $('[data-issue-quantity]', line);
        const available = $('[data-issue-available]', line);
        const lineTotal = $('[data-issue-total]', line);
        const option = select.selectedOptions[0];
        const stock = Number(option.dataset.stock || 0);
        const price = Number(option.dataset.unitPrice || 0);
        const count = Number(quantity.value || 0);
        const selected = !!select.value;
        available.textContent = selected ? String(stock) : '—';
        quantity.max = selected ? String(stock) : '100000';
        const amount = selected && Number.isFinite(price * count) ? price * count : 0;
        lineTotal.textContent = amount.toFixed(2);
        total += amount;
        if (selectedParts.has(select.value)) duplicate = true;
        if (selected) selectedParts.add(select.value);
      });
      $('[data-issue-grand-total]', form).textContent = total.toFixed(2);
      return duplicate;
    };
    form.addEventListener('input', updateIssueEstimate);
    form.addEventListener('change', updateIssueEstimate);
    form.addEventListener('submit', event => {
      const duplicate = updateIssueEstimate();
      const selected = $$('[data-issue-part]', form).filter(select => select.value);
      if (!selected.length || duplicate) {
        event.preventDefault();
        toast(duplicate ? 'Choose each part only once per issue.' : 'Select at least one part to issue.', 'error');
      }
    });
    updateIssueEstimate();
  });

  /* ---- print buttons ---- */
  $$('[data-print]').forEach(b => b.addEventListener('click', () => window.print()));

  /* ---- label cells of stacked tables for mobile ---- */
  $$('table.stack').forEach(t => {
    const heads = $$('thead th', t).map(h => h.textContent.trim());
    $$('tbody tr', t).forEach(r => $$('td', r).forEach((td, i) => { if (heads[i]) td.dataset.label = heads[i]; }));
  });

  /* ---- dependent select: vehicles filtered by chosen customer ---- */
  const cust = $('#f_customer_id'), veh = $('#f_vehicle_id');
  if (cust && veh && veh.querySelector('option[data-customer]')) {
    const apply = () => {
      $$('option[data-customer]', veh).forEach(o => { const ok = o.dataset.customer === cust.value; o.hidden = !ok; o.disabled = !ok; });
      const sel = veh.selectedOptions[0];
      if (sel && sel.disabled) veh.value = '';
    };
    cust.addEventListener('change', apply); apply();
  }
  /* ---- password visibility toggle ---- */
  document.addEventListener('click', e => {
    const btn = e.target.closest('.toggle-password');
    if (!btn) return;
    const inp = document.getElementById(btn.dataset.target);
    if (!inp) return;
    const isPass = inp.type === 'password';
    inp.type = isPass ? 'text' : 'password';
    btn.textContent = isPass ? '🔒' : '👁';
    btn.setAttribute('aria-label', isPass ? 'Hide password' : 'Show password');
  });
})();
