/* ==========================================================================
   dashboard.js — Role-Based Chart.js Visualizations & AJAX Filter Handler
   Alpha Garage Design System: Deep Forest Green (#0D281E) & Warm Champagne Gold (#D4AF37)
   Supports: Administrator, Receptionist, Mechanic, Inventory Manager, Accountant, Customer
   ========================================================================== */
(function () {
  'use strict';

  var charts = {};

  var palette = {
    green: '#16834A', greenSoft: 'rgba(22,131,74,.22)',
    red: '#DC2626', redSoft: 'rgba(220,38,38,.18)',
    gold: '#D4AF37', goldSoft: 'rgba(212,175,55,.25)',
    goldDark: '#7A5C00',
    champagne: '#E8CD83', champagneSoft: 'rgba(232,205,131,.25)',
    forest: '#0D281E', forestSoft: 'rgba(13,40,30,.18)',
    emerald: '#153A2D', emeraldSoft: 'rgba(21,58,45,.22)',
    amber: '#D97706', amberSoft: 'rgba(217,119,6,.22)',
    slate: '#475569', slateSoft: 'rgba(71,85,105,.2)',
    gridLight: 'rgba(222,228,223,.65)', textLight: '#5A6A60',
    gridDark: 'rgba(30,78,61,.45)', textDark: '#CBD5CD'
  };

  function isDark() {
    return document.documentElement.getAttribute('data-theme') === 'dark';
  }

  function themeColors() {
    var d = isDark();
    return {
      grid: d ? palette.gridDark : palette.gridLight,
      text: d ? palette.textDark : palette.textLight,
      surface: d ? '#0D281E' : '#FFFFFF',
      border: d ? 'rgba(212,175,55,.3)' : 'rgba(13,40,30,.15)'
    };
  }

  function destroyAll() {
    Object.keys(charts).forEach(function (k) {
      try {
        if (charts[k] && typeof charts[k].destroy === 'function') {
          charts[k].destroy();
        }
      } catch (e) { /* ignore cleanup error */ }
      delete charts[k];
    });
  }

  function baseOpts() {
    var tc = themeColors();
    var d = isDark();
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: { duration: 400 },
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: {
            color: tc.text,
            boxWidth: 12,
            boxHeight: 12,
            usePointStyle: true,
            font: { family: 'system-ui, -apple-system, sans-serif', size: 12, weight: '600' }
          }
        },
        tooltip: {
          backgroundColor: d ? 'rgba(7,23,17,.95)' : 'rgba(13,40,30,.92)',
          titleColor: '#FFFFFF',
          bodyColor: '#F5F2E9',
          borderColor: 'rgba(212,175,55,.4)',
          borderWidth: 1,
          padding: 10,
          cornerRadius: 8,
          titleFont: { weight: '700' }
        }
      },
      scales: {
        x: {
          ticks: { color: tc.text, maxRotation: 0, autoSkip: true, font: { size: 11 } },
          grid: { color: tc.grid }
        },
        y: {
          ticks: { color: tc.text, font: { size: 11 } },
          grid: { color: tc.grid },
          beginAtZero: true
        }
      }
    };
  }

  function moneyTick(cur) {
    return function (v) {
      try {
        return cur + ' ' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
      } catch (e) {
        return cur + ' ' + v;
      }
    };
  }

  function tLabel(key) {
    if (typeof window !== 'undefined' && window.GMS_I18N && typeof window.GMS_I18N.t === 'function') {
      return window.GMS_I18N.t(key, key);
    }
    return key;
  }

  function translateMonthLabel(lbl) {
    if (!lbl || typeof lbl !== 'string') return lbl;
    var parts = lbl.trim().split(/\s+/);
    if (parts.length === 2 && parts[0] && parts[1]) {
      var trans = tLabel(parts[0]);
      return trans !== parts[0] ? trans + ' ' + parts[1] : lbl;
    }
    return tLabel(lbl);
  }

  function getPayload() {
    var el = document.getElementById('dashboard-data');
    if (!el) return null;
    try {
      return JSON.parse(el.getAttribute('data-chart-payload') || 'null');
    } catch (e) {
      return null;
    }
  }

  function safeNewChart(el, config, chartKey) {
    if (!el || typeof Chart === 'undefined') return null;
    try {
      if (typeof Chart.getChart === 'function') {
        var existing = Chart.getChart(el);
        if (existing && typeof existing.destroy === 'function') {
          existing.destroy();
        }
      }
    } catch (e) { /* ignore cleanup error */ }
    var chartInst = new Chart(el, config);
    if (chartKey) {
      charts[chartKey] = chartInst;
    }
    return chartInst;
  }

  /**
   * Reconciles diverse chart payloads into canonical { labels: string[], data: number[], isEmpty: boolean }
   * Accepts:
   *   1. Canonical contract: { labels: [...], data: [...] }
   *   2. Array of objects: [ { label: '...', count/value/amount/net/units: N }, ... ]
   *   3. Key-value dictionary: { 'Status': N, ... }
   */
  function normalizeChartData(raw) {
    if (!raw || typeof raw !== 'object') {
      return { labels: [], data: [], isEmpty: true };
    }

    // Case 1: Canonical structure { labels: [...], data: [...] }
    if (Array.isArray(raw.labels) && Array.isArray(raw.data)) {
      var minLen = Math.min(raw.labels.length, raw.data.length);
      var labels = [];
      var data = [];
      var hasNonZero = false;
      for (var i = 0; i < minLen; i++) {
        var lbl = raw.labels[i];
        labels.push(String(lbl !== undefined && lbl !== null ? lbl : ''));
        var val = Number(raw.data[i]);
        if (isNaN(val)) val = 0;
        data.push(val);
        if (val !== 0) hasNonZero = true;
      }
      return { labels: labels, data: data, isEmpty: labels.length === 0 || !hasNonZero };
    }

    // Case 2: Array of objects [{ label/method/name: '...', count/value/amount/net/units/data: N }]
    if (Array.isArray(raw)) {
      var aLabels = [];
      var aData = [];
      var aHasNonZero = false;
      for (var j = 0; j < raw.length; j++) {
        var item = raw[j];
        if (item && typeof item === 'object') {
          var itemLbl = item.label !== undefined ? item.label : (item.method !== undefined ? item.method : (item.name !== undefined ? item.name : ''));
          var v = item.count !== undefined ? item.count : (item.value !== undefined ? item.value : (item.amount !== undefined ? item.amount : (item.net !== undefined ? item.net : (item.units !== undefined ? item.units : (item.data !== undefined ? item.data : 0)))));
          var num = Number(v);
          if (isNaN(num)) num = 0;
          aLabels.push(String(itemLbl !== undefined && itemLbl !== null ? itemLbl : ''));
          aData.push(num);
          if (num !== 0) aHasNonZero = true;
        }
      }
      return { labels: aLabels, data: aData, isEmpty: aLabels.length === 0 || !aHasNonZero };
    }

    // Case 3: Key-value dictionary { 'Key': N, ... } or { 'Key': { qty: N }, ... }
    var keys = Object.keys(raw);
    var dLabels = [];
    var dData = [];
    var dHasNonZero = false;
    for (var k = 0; k < keys.length; k++) {
      var key = keys[k];
      var rawVal = raw[key];
      var dNum = 0;
      if (rawVal && typeof rawVal === 'object' && 'qty' in rawVal) {
        dNum = Number(rawVal.qty) || 0;
      } else {
        dNum = Number(rawVal) || 0;
      }
      if (isNaN(dNum)) dNum = 0;
      dLabels.push(String(key));
      dData.push(dNum);
      if (dNum !== 0) dHasNonZero = true;
    }
    return { labels: dLabels, data: dData, isEmpty: dLabels.length === 0 || !dHasNonZero };
  }

  if (typeof window !== 'undefined') {
    window.normalizeChartData = normalizeChartData;
  }

  function renderCharts() {
    if (typeof Chart === 'undefined') return;
    var p = getPayload();
    if (!p) return;

    destroyAll();
    var tc = themeColors();
    var sliceBorder = isDark() ? '#0D281E' : '#FFFFFF';

    // =========================================================================
    // 1. ADMINISTRATOR CHARTS
    // =========================================================================
    var labels = (p.financial || []).map(function (r) { return r.label; });

    // Revenue vs Expenses (Line)
    var c1 = document.getElementById('chart-revenue-expenses');
    if (c1 && p.financial && p.financial.length) {
      var o1 = baseOpts();
      o1.scales.y.ticks.callback = moneyTick(p.currency || 'ETB');
      charts.revExp = safeNewChart(c1, {
        type: 'line',
        data: {
          labels: labels,
          datasets: [
            { label: 'Revenue', data: p.financial.map(function(r){return r.revenue;}), borderColor: palette.green, backgroundColor: palette.greenSoft, tension: .35, fill: false, pointRadius: 3 },
            { label: 'Expenses', data: p.financial.map(function(r){return r.expenses;}), borderColor: palette.red, backgroundColor: palette.redSoft, tension: .35, fill: false, pointRadius: 3 }
          ]
        },
        options: o1
      }, 'revExp');
    }

    // Profit & Loss Bar
    var c2 = document.getElementById('chart-profit-loss');
    if (c2 && p.financial && p.financial.length) {
      var o2 = baseOpts();
      o2.scales.y.ticks.callback = moneyTick(p.currency || 'ETB');
      charts.pl = safeNewChart(c2, {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [
            { label: 'Revenue', data: p.financial.map(function(r){return r.revenue;}), backgroundColor: palette.green, borderRadius: 4 },
            { label: 'Expenses', data: p.financial.map(function(r){return r.expenses;}), backgroundColor: palette.red, borderRadius: 4 },
            { label: 'Net profit', data: p.financial.map(function(r){return r.profit;}), backgroundColor: p.financial.map(function(r){return r.profit >= 0 ? palette.gold : '#B91C1C';}), borderRadius: 4 }
          ]
        },
        options: o2
      }, 'pl');
    }

    // Revenue Net Area
    var c3 = document.getElementById('chart-revenue-area');
    if (c3 && p.revenue && p.revenue.length) {
      var o3 = baseOpts();
      o3.scales.y.ticks.callback = moneyTick(p.currency || 'ETB');
      charts.rev = safeNewChart(c3, {
        type: 'line',
        data: {
          labels: p.revenue.map(function(r){return r.label;}),
          datasets: [
            { label: 'Net revenue', data: p.revenue.map(function(r){return r.value;}), borderColor: palette.gold, backgroundColor: palette.goldSoft, fill: true, tension: .35, pointRadius: 2 }
          ]
        },
        options: o3
      }, 'rev');
    }

    // Expense Donut
    var c4 = document.getElementById('chart-expense-donut');
    if (c4 && p.expensesByCategory && p.expensesByCategory.length) {
      var expColors = ['#0D281E','#16834A','#D4AF37','#DC2626','#153A2D','#B45309','#0284C7','#64748B','#16A34A'];
      charts.expD = safeNewChart(c4, {
        type: 'doughnut',
        data: {
          labels: p.expensesByCategory.map(function(r){return r.label;}),
          datasets: [{ data: p.expensesByCategory.map(function(r){return r.value;}), backgroundColor: expColors, borderWidth: 2, borderColor: sliceBorder }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } },
            tooltip: {
              callbacks: {
                label: function (ctx) {
                  var t = p.expensesByCategory.reduce(function(s,r){return s+r.value;},0) || 1;
                  return ' ' + ctx.label + ': ' + (p.currency || 'ETB') + ' ' + Number(ctx.raw).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2}) + ' (' + Math.round(ctx.raw / t * 100) + '%)';
                }
              }
            }
          }
        }
      }, 'expD');
    }

    // Job Status Donut (Admin)
    var c5 = document.getElementById('chart-jobs-donut');
    var jobMap = { 'Pending': '#D97706', 'Diagnosing': '#0284C7', 'Waiting for Parts': '#B45309', 'In Progress': '#153A2D', 'Completed': '#16834A', 'Delivered': '#0D281E', 'Cancelled': '#DC2626' };
    if (c5 && p.jobStatus) {
      var jLabels = Object.keys(jobMap).filter(function(k){ return (p.jobStatus[k]||0) > 0; });
      var jVals = jLabels.map(function(k){ return p.jobStatus[k]; });
      if (!jLabels.length) { jLabels = ['No jobs']; jVals = [1]; }
      charts.jobs = safeNewChart(c5, {
        type: 'doughnut',
        data: {
          labels: jLabels,
          datasets: [{ data: jVals, backgroundColor: jLabels[0] === 'No jobs' ? ['#CBD5CD'] : jLabels.map(function(k){return jobMap[k];}), borderWidth: 2, borderColor: sliceBorder }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'jobs');
    }

    // Inventory Bar (Admin)
    var c6 = document.getElementById('chart-inventory-bar');
    if (c6 && p.inventoryByCategory && p.inventoryByCategory.length) {
      var o6 = baseOpts();
      o6.plugins.legend.display = false;
      charts.inv = safeNewChart(c6, {
        type: 'bar',
        data: {
          labels: p.inventoryByCategory.map(function(r){return r.label;}),
          datasets: [{ label: 'Units', data: p.inventoryByCategory.map(function(r){return r.units;}), backgroundColor: p.inventoryByCategory.map(function(r,i){ return r.units === 0 ? '#DC2626' : (i % 2 ? palette.gold : palette.green); }), borderRadius: 4 }]
        },
        options: o6
      }, 'inv');
    }

    // Top Services (Admin)
    var c7 = document.getElementById('chart-services');
    if (c7 && p.topServices && p.topServices.length) {
      var o7 = baseOpts();
      o7.indexAxis = 'y';
      o7.scales.x.ticks.callback = moneyTick(p.currency || 'ETB');
      charts.svc = safeNewChart(c7, {
        type: 'bar',
        data: {
          labels: p.topServices.map(function(r){return r.label;}),
          datasets: [
            { label: 'Revenue', data: p.topServices.map(function(r){return r.revenue;}), backgroundColor: palette.forest, borderRadius: 4 },
            { label: 'Requests', data: p.topServices.map(function(r){return r.count;}), backgroundColor: palette.gold, borderRadius: 4, xAxisID: 'xCount' }
          ]
        },
        options: (function () {
          o7.scales.xCount = { position: 'top', ticks: { color: tc.text }, grid: { drawOnChartArea: false }, beginAtZero: true, title: { display: true, text: 'Request count', color: tc.text } };
          o7.scales.x.title = { display: true, text: 'Revenue (' + (p.currency || 'ETB') + ')', color: tc.text };
          return o7;
        })()
      }, 'svc');
    }

    // =========================================================================
    // 2. RECEPTIONIST CHARTS
    // =========================================================================
    // Appointments Status Donut
    var cr1 = document.getElementById('chart-reception-appts-status');
    if (cr1 && p.apptStatus) {
      var aptColorMap = { 'scheduled': '#D97706', 'confirmed': '#0284C7', 'in_progress': '#153A2D', 'completed': '#16834A', 'cancelled': '#DC2626' };
      var aptLabelMap = { 'scheduled': 'Scheduled', 'confirmed': 'Confirmed', 'in_progress': 'In Progress', 'completed': 'Completed', 'cancelled': 'Cancelled' };
      var sRec = normalizeChartData(p.apptStatus);
      var rKeys = [];
      var rVals = [];
      for (var ir = 0; ir < sRec.labels.length; ir++) {
        if (sRec.data[ir] > 0) {
          rKeys.push(sRec.labels[ir]);
          rVals.push(sRec.data[ir]);
        }
      }
      if (!rKeys.length) { rKeys = ['none']; rVals = [1]; }
      charts.recStatus = safeNewChart(cr1, {
        type: 'doughnut',
        data: {
          labels: rKeys[0] === 'none' ? ['No appointments'] : rKeys.map(function(k){return aptLabelMap[k] || k;}),
          datasets: [{ data: rVals, backgroundColor: rKeys[0] === 'none' ? ['#CBD5CD'] : rKeys.map(function(k){return aptColorMap[k] || palette.slate;}), borderWidth: 2, borderColor: sliceBorder }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'recStatus');
    }

    // Appointment Booking Trend (Last 14 days)
    var cr2 = document.getElementById('chart-reception-appts-trend');
    if (cr2 && p.apptTrend) {
      var sRecTrend = normalizeChartData(p.apptTrend);
      var or2 = baseOpts();
      or2.plugins.legend.display = false;
      var trendLabels = sRecTrend.labels.length ? sRecTrend.labels : ['No bookings'];
      var trendData = sRecTrend.data.length ? sRecTrend.data : [0];
      charts.recTrend = safeNewChart(cr2, {
        type: 'bar',
        data: {
          labels: trendLabels,
          datasets: [{ label: 'Bookings', data: trendData, backgroundColor: palette.gold, borderRadius: 4 }]
        },
        options: or2
      }, 'recTrend');
    }

    // =========================================================================
    // 3. MECHANIC CHARTS
    // =========================================================================
    // Mechanic Job Status Donut
    var cm1 = document.getElementById('chart-mechanic-jobs-status');
    if (cm1 && p.jobStatus) {
      var sMechJobs = normalizeChartData(p.jobStatus);
      var mKeys = [];
      var mVals = [];
      for (var im1 = 0; im1 < sMechJobs.labels.length; im1++) {
        if (sMechJobs.data[im1] > 0) {
          mKeys.push(sMechJobs.labels[im1]);
          mVals.push(sMechJobs.data[im1]);
        }
      }
      if (!mKeys.length) { mKeys = ['No assigned jobs']; mVals = [1]; }
      charts.mechStatus = safeNewChart(cm1, {
        type: 'doughnut',
        data: {
          labels: mKeys,
          datasets: [{
            data: mVals,
            backgroundColor: mKeys[0] === 'No assigned jobs' ? ['#CBD5CD'] : mKeys.map(function(k){ return jobMap[k] || palette.slate; }),
            borderWidth: 2,
            borderColor: sliceBorder
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'mechStatus');
    }

    // Mechanic Priority Distribution Donut
    var cm2 = document.getElementById('chart-mechanic-priority-dist');
    if (cm2 && p.priorityDist) {
      var prioMap = { 'Urgent': '#DC2626', 'High': '#D97706', 'Normal': '#153A2D', 'Low': '#64748B' };
      var sMechPrio = normalizeChartData(p.priorityDist);
      var prKeys = [];
      var prVals = [];
      for (var im2 = 0; im2 < sMechPrio.labels.length; im2++) {
        if (sMechPrio.data[im2] > 0) {
          prKeys.push(sMechPrio.labels[im2]);
          prVals.push(sMechPrio.data[im2]);
        }
      }
      if (!prKeys.length) { prKeys = ['No active tasks']; prVals = [1]; }
      charts.mechPrio = safeNewChart(cm2, {
        type: 'doughnut',
        data: {
          labels: prKeys,
          datasets: [{
            data: prVals,
            backgroundColor: prKeys[0] === 'No active tasks' ? ['#CBD5CD'] : prKeys.map(function(k){ return prioMap[k] || palette.slate; }),
            borderWidth: 2,
            borderColor: sliceBorder
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'mechPrio');
    }

    // Mechanic Monthly Completion Trend
    var cm3 = document.getElementById('chart-mechanic-completion-trend');
    if (cm3 && p.monthlyTrend) {
      var om3 = baseOpts();
      om3.plugins.legend.display = false;
      var sMechTrend = normalizeChartData(p.monthlyTrend);
      var mTrendL = sMechTrend.labels.length ? sMechTrend.labels : ['No history'];
      var mTrendV = sMechTrend.data.length ? sMechTrend.data : [0];
      charts.mechTrend = safeNewChart(cm3, {
        type: 'bar',
        data: {
          labels: mTrendL,
          datasets: [{ label: 'Completed Jobs', data: mTrendV, backgroundColor: palette.green, borderRadius: 4 }]
        },
        options: om3
      }, 'mechTrend');
    }

    // =========================================================================
    // 4. INVENTORY MANAGER CHARTS
    // =========================================================================
    // Stock Health Donut
    var ci1 = document.getElementById('chart-inventory-stock-health');
    if (ci1 && p.stockHealth) {
      var sHealth = normalizeChartData(p.stockHealth);
      var shColorMap = { 'Healthy Stock': palette.green, 'Low Stock': palette.amber, 'Out of Stock': palette.red };
      var shLabels = [];
      var shVals = [];
      var shColors = [];
      for (var ih = 0; ih < sHealth.labels.length; ih++) {
        if (sHealth.data[ih] > 0) {
          shLabels.push(sHealth.labels[ih]);
          shVals.push(sHealth.data[ih]);
          shColors.push(shColorMap[sHealth.labels[ih]] || palette.slate);
        }
      }
      if (!shLabels.length) {
        shLabels = ['No stock data'];
        shVals = [1];
        shColors = ['#CBD5CD'];
      }
      charts.invHealth = safeNewChart(ci1, {
        type: 'doughnut',
        data: {
          labels: shLabels,
          datasets: [{ data: shVals, backgroundColor: shColors, borderWidth: 2, borderColor: sliceBorder }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'invHealth');
    }

    // Units by Category Bar
    var ci2 = document.getElementById('chart-inventory-category-units');
    if (ci2 && p.categories) {
      var sCat = normalizeChartData(p.categories);
      var oi2 = baseOpts();
      oi2.plugins.legend.display = false;
      var catLabels = sCat.labels.length ? sCat.labels : ['No parts cataloged'];
      var catVals = sCat.data.length ? sCat.data : [0];
      charts.invCat = safeNewChart(ci2, {
        type: 'bar',
        data: {
          labels: catLabels,
          datasets: [
            {
              label: 'Units in Stock',
              data: catVals,
              backgroundColor: catVals.map(function(u, idx){ return u === 0 ? palette.red : (idx % 2 ? palette.gold : palette.emerald); }),
              borderRadius: 4
            }
          ]
        },
        options: oi2
      }, 'invCat');
    }

    // Stock Movement Breakdown
    var ci3 = document.getElementById('chart-inventory-movement-trend');
    if (ci3 && p.transactions) {
      var sTxn = normalizeChartData(p.transactions);
      var oi3 = baseOpts();
      oi3.plugins.legend.display = false;
      var txnColors = [palette.green, palette.forest, palette.gold, palette.emerald, palette.red, palette.amber];
      var txnL = sTxn.labels.length ? sTxn.labels : ['No movements'];
      var txnV = sTxn.data.length ? sTxn.data : [0];
      charts.invTxn = safeNewChart(ci3, {
        type: 'bar',
        data: {
          labels: txnL,
          datasets: [{
            label: 'Units Moved',
            data: txnV,
            backgroundColor: txnL.map(function(_, i){ return txnColors[i % txnColors.length]; }),
            borderRadius: 4
          }]
        },
        options: oi3
      }, 'invTxn');
    }

    // =========================================================================
    // 5. ACCOUNTANT CHARTS
    // =========================================================================
    // Invoice Status Donut
    var ca1 = document.getElementById('chart-accountant-invoice-status');
    if (ca1 && p.invoiceStatus) {
      var invColors = { 'Paid': palette.green, 'Partially Paid': palette.amber, 'Unpaid': palette.red, 'Refunded': palette.slate };
      var sInv = normalizeChartData(p.invoiceStatus);
      var aKeys = [];
      var aVals = [];
      for (var ia = 0; ia < sInv.labels.length; ia++) {
        if (sInv.data[ia] > 0) {
          aKeys.push(sInv.labels[ia]);
          aVals.push(sInv.data[ia]);
        }
      }
      if (!aKeys.length) { aKeys = ['No invoices']; aVals = [1]; }
      charts.accInv = safeNewChart(ca1, {
        type: 'doughnut',
        data: {
          labels: aKeys,
          datasets: [{
            data: aVals,
            backgroundColor: aKeys[0] === 'No invoices' ? ['#CBD5CD'] : aKeys.map(function(k){ return invColors[k] || palette.slate; }),
            borderWidth: 2,
            borderColor: sliceBorder
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'accInv');
    }

    // Receivables Aging Brackets Bar
    var ca2 = document.getElementById('chart-accountant-aging-brackets');
    if (ca2 && p.agingBrackets) {
      var oa2 = baseOpts();
      oa2.plugins.legend.display = false;
      oa2.scales.y.ticks.callback = moneyTick(p.currency || 'ETB');
      var sAging = normalizeChartData(p.agingBrackets);
      var agingL = sAging.labels.length ? sAging.labels : ['0–30 Days', '31–60 Days', '61–90 Days', '90+ Days'];
      var agingV = sAging.data.length ? sAging.data : [0, 0, 0, 0];
      var agingColors = [palette.green, palette.amber, '#EA580C', palette.red];
      charts.accAging = safeNewChart(ca2, {
        type: 'bar',
        data: {
          labels: agingL,
          datasets: [{
            label: 'Outstanding Balance',
            data: agingV,
            backgroundColor: agingL.map(function(_, i){ return agingColors[i % agingColors.length]; }),
            borderRadius: 4
          }]
        },
        options: oa2
      }, 'accAging');
    }

    // Revenue Collection Monthly Trend
    var ca3 = document.getElementById('chart-accountant-revenue-trend');
    if (ca3 && p.monthlyRevenue) {
      var oa3 = baseOpts();
      oa3.plugins.legend.display = false;
      oa3.scales.y.ticks.callback = moneyTick(p.currency || 'ETB');
      var sRev = normalizeChartData(p.monthlyRevenue);
      var revL = sRev.labels.length ? sRev.labels : ['No history'];
      var revV = sRev.data.length ? sRev.data : [0];
      charts.accRev = safeNewChart(ca3, {
        type: 'line',
        data: {
          labels: revL,
          datasets: [{
            label: 'Collected Revenue',
            data: revV,
            borderColor: palette.gold,
            backgroundColor: palette.goldSoft,
            fill: true,
            tension: .35,
            pointRadius: 3
          }]
        },
        options: oa3
      }, 'accRev');
    }

    // Payment Methods Donut
    var ca4 = document.getElementById('chart-accountant-payment-methods');
    if (ca4 && p.paymentMethods) {
      var pmColors = [palette.green, palette.forest, palette.gold, palette.emerald, palette.amber, palette.slate];
      var sPay = normalizeChartData(p.paymentMethods);
      var pmKeys = [];
      var pmVals = [];
      for (var ip = 0; ip < sPay.labels.length; ip++) {
        if (sPay.data[ip] > 0) {
          pmKeys.push(sPay.labels[ip]);
          pmVals.push(sPay.data[ip]);
        }
      }
      if (!pmKeys.length) { pmKeys = ['No payments']; pmVals = [1]; }
      charts.accPay = safeNewChart(ca4, {
        type: 'doughnut',
        data: {
          labels: pmKeys,
          datasets: [{
            data: pmVals,
            backgroundColor: pmKeys[0] === 'No payments' ? ['#CBD5CD'] : pmKeys.map(function(_, i){ return pmColors[i % pmColors.length]; }),
            borderWidth: 2,
            borderColor: sliceBorder
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'accPay');
    }

    // =========================================================================
    // 6. CUSTOMER CHARTS
    // =========================================================================
    // Customer Repair Status Donut
    var cc1 = document.getElementById('chart-customer-repair-status');
    if (cc1 && p.jobStatus) {
      var sCustJob = normalizeChartData(p.jobStatus);
      var cKeys = [];
      var cVals = [];
      for (var ic = 0; ic < sCustJob.labels.length; ic++) {
        if (sCustJob.data[ic] > 0) {
          cKeys.push(sCustJob.labels[ic]);
          cVals.push(sCustJob.data[ic]);
        }
      }
      var isCustJobEmpty = !cKeys.length;
      if (isCustJobEmpty) { cKeys = ['No active repair orders']; cVals = [1]; }
      charts.custStatus = safeNewChart(cc1, {
        type: 'doughnut',
        data: {
          labels: cKeys.map(function(k){ return tLabel(k); }),
          datasets: [{
            data: cVals,
            backgroundColor: isCustJobEmpty ? ['#CBD5CD'] : cKeys.map(function(k){ return jobMap[k] || palette.slate; }),
            borderWidth: 2,
            borderColor: sliceBorder
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'bottom', labels: { color: tc.text, boxWidth: 12 } } }
        }
      }, 'custStatus');
    }

    // Customer Service History Bar
    var cc2 = document.getElementById('chart-customer-service-history');
    if (cc2 && p.history) {
      var sHist = normalizeChartData(p.history);
      var oc2 = baseOpts();
      oc2.plugins.legend.display = false;
      var histL = sHist.labels.length ? sHist.labels : ['No history'];
      var histV = sHist.data.length ? sHist.data : [0];
      charts.custHist = safeNewChart(cc2, {
        type: 'bar',
        data: {
          labels: histL.map(function(lbl){
            if (lbl === 'No history') return tLabel('No history');
            return translateMonthLabel(lbl);
          }),
          datasets: [{ label: tLabel('Service Visits'), data: histV, backgroundColor: palette.gold, borderRadius: 4 }]
        },
        options: oc2
      }, 'custHist');
    }
  }

  // =========================================================================
  // 7. AJAX FILTERS & EVENT LISTENERS
  // =========================================================================
  var refreshSeq = 0;
  function updateCustomDateFields() {
    var rangeSelect = document.getElementById('dashboard-range');
    var show = rangeSelect && rangeSelect.value === 'custom';
    document.querySelectorAll('.dashboard-custom-date').forEach(function (group) {
      group.hidden = !show;
      var input = group.querySelector('input');
      if (input) input.required = !!show;
    });
  }

  function refreshDashboard(form, pushHistory, targetUrl) {
    var current = document.getElementById('dashboard-data');
    var submit = form ? form.querySelector('[type="submit"]') : null;
    if (!current || !form) return;
    var url = new URL(targetUrl || form.action, window.location.href);
    if (!targetUrl) url.search = new URLSearchParams(new FormData(form)).toString();
    var seq = ++refreshSeq;
    current.setAttribute('aria-busy', 'true');
    if (submit) submit.disabled = true;

    fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text();
      })
      .then(function (html) {
        if (seq !== refreshSeq) return;
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var replacement = parsed.getElementById('dashboard-data');
        if (!replacement) throw new Error('No dashboard data in response');
        current.replaceWith(replacement);
        if (pushHistory) window.history.pushState({ dashboard: true }, '', url);
        updateCustomDateFields();
        renderCharts();
        var heading = replacement.querySelector('.dashboard-section-head h3, h3');
        if (heading) try { heading.focus(); } catch (e) {}
      })
      .catch(function (err) {
        if (seq !== refreshSeq) return;
        console.error(err);
        if (window.GMS) window.GMS.toast('Dashboard could not be refreshed. Please try again.', 'error');
      })
      .finally(function () {
        if (seq !== refreshSeq) return;
        var u = document.getElementById('dashboard-data');
        if (u) u.removeAttribute('aria-busy');
        var s = document.querySelector('.dashboard-filter-form [type="submit"]');
        if (s) s.disabled = false;
      });
  }

  document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'dashboard-range') {
      updateCustomDateFields();
      if (e.target.value !== 'custom') {
        var f = e.target.closest('form');
        if (f) refreshDashboard(f, true);
      }
    }
    if (e.target && e.target.id === 'dashboard-period') {
      var f2 = e.target.closest('form');
      if (f2) refreshDashboard(f2, true);
    }
  });

  document.addEventListener('submit', function (e) {
    var f = e.target.closest ? e.target.closest('.dashboard-filter-form') : null;
    if (!f) return;
    e.preventDefault();
    refreshDashboard(f, true);
  });

  window.addEventListener('popstate', function () {
    var f = document.querySelector('.dashboard-filter-form');
    if (f) refreshDashboard(f, false, window.location.href);
  });

  // Re-render charts on theme change (handles button toggle and attribute changes)
  var themeDebounceTimer = null;
  function handleThemeChange() {
    if (themeDebounceTimer) clearTimeout(themeDebounceTimer);
    themeDebounceTimer = setTimeout(function () {
      renderCharts();
    }, 60);
  }

  if (typeof MutationObserver !== 'undefined' && document.documentElement) {
    try {
      var themeObserver = new MutationObserver(function (mutations) {
        for (var m = 0; m < mutations.length; m++) {
          if (mutations[m].attributeName === 'data-theme') {
            handleThemeChange();
            break;
          }
        }
      });
      themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    } catch (e) { /* ignore */ }
  }

  document.addEventListener('click', function (e) {
    if (e.target && e.target.closest && e.target.closest('.theme-toggle')) {
      handleThemeChange();
    }
  });

  /* ==========================================================================
     Customer Dashboard: In-Place Service Booking Modal Controller
     ========================================================================== */
  function initCustomerBookingModal() {
    var backdrop = document.getElementById('bookingModalBackdrop');
    if (!backdrop) return;

    var closeBtn = document.getElementById('bookingModalClose');
    var cancelBtn = document.getElementById('btnCancelBooking');
    var toggleExisting = document.getElementById('btnToggleExisting');
    var toggleNew = document.getElementById('btnToggleNew');
    var grpExisting = document.getElementById('groupExistingVehicle');
    var grpNew = document.getElementById('groupNewVehicle');
    var modeInput = document.getElementById('modalVehicleMode');
    var vehicleSelect = document.getElementById('modalVehicleId');
    var plateInput = document.getElementById('modalPlateNumber');
    var makeInput = document.getElementById('modalMake');
    var modelInput = document.getElementById('modalModel');

    function openModal(preselectedVehicleId) {
      backdrop.hidden = false;
      backdrop.removeAttribute('hidden');
      backdrop.style.display = 'flex';
      document.body.style.overflow = 'hidden';

      if (preselectedVehicleId && vehicleSelect) {
        vehicleSelect.value = preselectedVehicleId;
      }

      if (vehicleSelect && vehicleSelect.options && vehicleSelect.options.length > 0) {
        setVehicleMode('existing');
      } else {
        setVehicleMode('new');
      }

      setTimeout(function () {
        if (modeInput && modeInput.value === 'new' && plateInput) {
          plateInput.focus();
        } else if (vehicleSelect) {
          vehicleSelect.focus();
        }
      }, 50);
    }

    function closeModal() {
      backdrop.hidden = true;
      backdrop.setAttribute('hidden', '');
      backdrop.style.display = 'none';
      document.body.style.overflow = '';
      if (window.location.hash === '#book-service' || window.location.hash === '#booking-form') {
        if (window.history && window.history.replaceState) {
          window.history.replaceState(null, '', window.location.pathname + window.location.search);
        }
      }
    }

    function setVehicleMode(mode) {
      if (!modeInput) return;
      modeInput.value = mode;

      if (mode === 'new') {
        if (toggleNew) toggleNew.classList.add('active');
        if (toggleExisting) toggleExisting.classList.remove('active');
        if (grpExisting) {
          grpExisting.style.display = 'none';
          grpExisting.setAttribute('hidden', '');
        }
        if (grpNew) {
          grpNew.style.display = 'block';
          grpNew.removeAttribute('hidden');
        }
        if (plateInput) plateInput.required = true;
        if (makeInput) makeInput.required = true;
        if (modelInput) modelInput.required = true;
        if (vehicleSelect) vehicleSelect.required = false;
      } else {
        if (toggleExisting) toggleExisting.classList.add('active');
        if (toggleNew) toggleNew.classList.remove('active');
        if (grpExisting) {
          grpExisting.style.display = 'block';
          grpExisting.removeAttribute('hidden');
        }
        if (grpNew) {
          grpNew.style.display = 'none';
          grpNew.setAttribute('hidden', '');
        }
        if (plateInput) plateInput.required = false;
        if (makeInput) makeInput.required = false;
        if (modelInput) modelInput.required = false;
        if (vehicleSelect) vehicleSelect.required = true;
      }
    }

    // Attach delegated click listener for open triggers
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest ? e.target.closest('[data-open-booking]') : null;
      if (trigger) {
        e.preventDefault();
        var vId = trigger.getAttribute('data-vehicle-id');
        openModal(vId);
        return;
      }

      var hashLink = e.target.closest ? e.target.closest('a[href*="#book-service"], a[href*="#booking-form"]') : null;
      if (hashLink) {
        var href = hashLink.getAttribute('href') || '';
        if (href.indexOf('customer.php#') !== -1 || href.startsWith('#')) {
          e.preventDefault();
          openModal();
          return;
        }
      }
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    backdrop.addEventListener('click', function (e) {
      if (e.target === backdrop) closeModal();
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !backdrop.hidden && backdrop.style.display !== 'none') {
        closeModal();
      }
    });

    if (toggleExisting) {
      toggleExisting.addEventListener('click', function () {
        setVehicleMode('existing');
      });
    }
    if (toggleNew) {
      toggleNew.addEventListener('click', function () {
        setVehicleMode('new');
      });
    }

    function checkTrigger() {
      var h = window.location.hash || '';
      var q = window.location.search || '';
      if (h === '#book-service' || h === '#booking-form' || h === '#book' || q.indexOf('open_booking=1') !== -1) {
        openModal();
      }
    }

    checkTrigger();
    window.addEventListener('hashchange', checkTrigger);

    if (typeof window !== 'undefined') {
      window.openCustomerBookingModal = openModal;
      window.closeCustomerBookingModal = closeModal;
    }
  }

  if (typeof window !== 'undefined') {
    window.renderDashboardCharts = renderCharts;
  }
  updateCustomDateFields();
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      renderCharts();
      initCustomerBookingModal();
    });
  } else {
    renderCharts();
    initCustomerBookingModal();
  }
})();
