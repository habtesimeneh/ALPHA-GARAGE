/* Dashboard: Chart.js rendering + AJAX date filters (same-origin, CSP-safe external file). */
(function () {
  'use strict';
  var charts = {};
  var palette = {
    green: '#16834A', greenSoft: 'rgba(22,131,74,.18)',
    red: '#DC2626', redSoft: 'rgba(220,38,38,.14)',
    gold: '#D4AF37', goldSoft: 'rgba(212,175,55,.22)',
    forest: '#123B2A', blue: '#245D49',
    grid: 'rgba(148,163,184,.25)', text: '#475569'
  };
  function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
  function themeColors() {
    var d = isDark();
    return { grid: d ? 'rgba(148,163,184,.18)' : palette.grid, text: d ? '#A7B4C7' : palette.text };
  }
  function destroyAll() { Object.keys(charts).forEach(function(k){ try{ charts[k].destroy(); }catch(e){} delete charts[k]; }); }
  function baseOpts() {
    var tc = themeColors();
    return {
      responsive: true, maintainAspectRatio: false, animation: { duration: 450 },
      interaction: { mode: 'index', intersect: false },
      plugins: { legend: { labels: { color: tc.text, boxWidth: 12, usePointStyle: true } },
                 tooltip: { backgroundColor: 'rgba(16,41,31,.92)', titleColor:'#fff', bodyColor:'#E5EAF2', padding:10 } },
      scales: { x: { ticks: { color: tc.text, maxRotation: 0, autoSkip: true }, grid: { color: tc.grid } },
                y: { ticks: { color: tc.text }, grid: { color: tc.grid }, beginAtZero: true } }
    };
  }
  function moneyTick(cur) { return function(v){ try { return cur + ' ' + Number(v).toLocaleString(undefined,{maximumFractionDigits:0}); } catch(e){ return cur+' '+v; } }; }
  function getPayload() {
    var el = document.getElementById('dashboard-data');
    if (!el) return null;
    try { return JSON.parse(el.getAttribute('data-chart-payload') || 'null'); } catch(e){ return null; }
  }
  function hasData(arr, key) { return arr && arr.some(function(r){ return Number(key ? r[key] : r) !== 0; }); }

  function renderCharts() {
    if (typeof Chart === 'undefined') return;
    var p = getPayload(); if (!p) return;
    destroyAll();
    var tc = themeColors();
    var labels = (p.financial || []).map(function(r){ return r.label; });

    var c1 = document.getElementById('chart-revenue-expenses');
    if (c1 && p.financial && p.financial.length) {
      var o = baseOpts(); o.scales.y.ticks.callback = moneyTick(p.currency);
      charts.revExp = new Chart(c1, { type: 'line', data: { labels: labels, datasets: [
        { label: 'Revenue', data: p.financial.map(function(r){return r.revenue;}), borderColor: palette.green, backgroundColor: palette.greenSoft, tension:.35, fill:false, pointRadius:3 },
        { label: 'Expenses', data: p.financial.map(function(r){return r.expenses;}), borderColor: palette.red, backgroundColor: palette.redSoft, tension:.35, fill:false, pointRadius:3 }
      ]}, options: o });
    }

    var c2 = document.getElementById('chart-profit-loss');
    if (c2 && p.financial && p.financial.length) {
      var o2 = baseOpts(); o2.scales.y.ticks.callback = moneyTick(p.currency);
      charts.pl = new Chart(c2, { type: 'bar', data: { labels: labels, datasets: [
        { label: 'Revenue', data: p.financial.map(function(r){return r.revenue;}), backgroundColor: palette.green, borderRadius:4 },
        { label: 'Expenses', data: p.financial.map(function(r){return r.expenses;}), backgroundColor: palette.red, borderRadius:4 },
        { label: 'Net profit', data: p.financial.map(function(r){return r.profit;}), backgroundColor: p.financial.map(function(r){return r.profit>=0?palette.gold:'#B91C1C';}), borderRadius:4 }
      ]}, options: o2 });
    }

    var c3 = document.getElementById('chart-revenue-area');
    if (c3 && p.revenue && p.revenue.length) {
      var o3 = baseOpts(); o3.scales.y.ticks.callback = moneyTick(p.currency);
      charts.rev = new Chart(c3, { type: 'line', data: { labels: p.revenue.map(function(r){return r.label;}), datasets: [
        { label: 'Net revenue', data: p.revenue.map(function(r){return r.value;}), borderColor: palette.gold, backgroundColor: palette.goldSoft, fill:true, tension:.35, pointRadius:2 }
      ]}, options: o3 });
    }

    var c4 = document.getElementById('chart-expense-donut');
    if (c4 && p.expensesByCategory && p.expensesByCategory.length) {
      var colors = ['#123B2A','#16834A','#D4AF37','#DC2626','#245D49','#B45309','#0284C7','#64748B','#16A34A'];
      charts.expD = new Chart(c4, { type: 'doughnut', data: { labels: p.expensesByCategory.map(function(r){return r.label;}), datasets: [{ data: p.expensesByCategory.map(function(r){return r.value;}), backgroundColor: colors, borderWidth: 2, borderColor: isDark()?'#111B2B':'#fff' }] }, options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ color:tc.text, boxWidth:12 } }, tooltip:{ callbacks:{ label:function(ctx){ var t=p.expensesByCategory.reduce(function(s,r){return s+r.value;},0)||1; return ' '+ctx.label+': '+p.currency+' '+Number(ctx.raw).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})+' ('+Math.round(ctx.raw/t*100)+'%)'; } } } } } });
    }

    var c5 = document.getElementById('chart-jobs-donut');
    var jobMap = {'Pending':'#D97706','Diagnosing':'#0284C7','Waiting for Parts':'#B45309','In Progress':'#245D49','Completed':'#16834A','Delivered':'#123B2A','Cancelled':'#DC2626'};
    if (c5 && p.jobStatus) {
      var jLabels = Object.keys(jobMap).filter(function(k){ return (p.jobStatus[k]||0) > 0; });
      var jVals = jLabels.map(function(k){ return p.jobStatus[k]; });
      if (!jLabels.length) { jLabels=['No jobs']; jVals=[1]; }
      charts.jobs = new Chart(c5, { type: 'doughnut', data: { labels: jLabels, datasets: [{ data: jVals, backgroundColor: jLabels[0]==='No jobs' ? ['#CBD5E1'] : jLabels.map(function(k){return jobMap[k];}), borderWidth:2, borderColor:isDark()?'#111B2B':'#fff' }] }, options: { responsive:true, maintainAspectRatio:false, plugins:{ legend:{ position:'bottom', labels:{ color:tc.text, boxWidth:12 } } } } });
    }

    var c6 = document.getElementById('chart-inventory-bar');
    if (c6 && p.inventoryByCategory && p.inventoryByCategory.length) {
      var o6 = baseOpts(); o6.plugins.legend.display = false;
      charts.inv = new Chart(c6, { type:'bar', data:{ labels: p.inventoryByCategory.map(function(r){return r.label;}), datasets:[{ label:'Units', data:p.inventoryByCategory.map(function(r){return r.units;}), backgroundColor: p.inventoryByCategory.map(function(r,i){ return r.units===0?'#DC2626':(i%2?palette.gold:palette.green); }), borderRadius:4 }] }, options:o6 });
    }

    var c7 = document.getElementById('chart-services');
    if (c7 && p.topServices && p.topServices.length) {
      var o7 = baseOpts(); o7.indexAxis = 'y'; o7.scales.x.ticks.callback = moneyTick(p.currency);
      charts.svc = new Chart(c7, { type:'bar', data:{ labels: p.topServices.map(function(r){return r.label;}), datasets:[
        { label:'Revenue', data:p.topServices.map(function(r){return r.revenue;}), backgroundColor: palette.forest, borderRadius:4 },
        { label:'Requests', data:p.topServices.map(function(r){return r.count;}), backgroundColor: palette.gold, borderRadius:4, xAxisID:'xCount' }
      ]}, options:(function(){ o7.scales.xCount = { position:'top', ticks:{ color:tc.text }, grid:{ drawOnChartArea:false }, beginAtZero:true, title:{display:true,text:'Request count',color:tc.text} }; o7.scales.x.title={display:true,text:'Revenue ('+p.currency+')',color:tc.text}; return o7; })() });
    }
  }

  // AJAX filters
  var refreshSeq = 0;
  function updateCustomDateFields() {
    var rangeSelect = document.getElementById('dashboard-range');
    var show = rangeSelect && rangeSelect.value === 'custom';
    document.querySelectorAll('.dashboard-custom-date').forEach(function(group){ group.hidden = !show; var input = group.querySelector('input'); if (input) input.required = !!show; });
  }
  function refreshDashboard(form, pushHistory, targetUrl) {
    var current = document.getElementById('dashboard-data');
    var submit = form ? form.querySelector('[type="submit"]') : null;
    if (!current || !form) return;
    var url = new URL(targetUrl || form.action, window.location.href);
    if (!targetUrl) url.search = new URLSearchParams(new FormData(form)).toString();
    var seq = ++refreshSeq;
    current.setAttribute('aria-busy','true'); if (submit) submit.disabled = true;
    fetch(url, { credentials:'same-origin', headers:{ 'X-Requested-With':'XMLHttpRequest' } })
      .then(function(r){ if (!r.ok) throw new Error('HTTP '+r.status); return r.text(); })
      .then(function(html){
        if (seq !== refreshSeq) return;
        var parsed = new DOMParser().parseFromString(html, 'text/html');
        var replacement = parsed.getElementById('dashboard-data');
        if (!replacement) throw new Error('No dashboard data in response');
        current.replaceWith(replacement);
        if (pushHistory) window.history.pushState({dashboard:true}, '', url);
        updateCustomDateFields();
        renderCharts();
        var heading = replacement.querySelector('.dashboard-section-head h3, h3'); if (heading) try{ heading.focus(); }catch(e){}
      })
      .catch(function(err){ if (seq !== refreshSeq) return; console.error(err); if (window.GMS) window.GMS.toast('Dashboard could not be refreshed. Please try again.', 'error'); })
      .finally(function(){ if (seq !== refreshSeq) return; var u = document.getElementById('dashboard-data'); if (u) u.removeAttribute('aria-busy'); var s = document.querySelector('.dashboard-filter-form [type="submit"]'); if (s) s.disabled = false; });
  }

  document.addEventListener('change', function(e){
    if (e.target && e.target.id === 'dashboard-range') { updateCustomDateFields(); if (e.target.value !== 'custom') { var f = e.target.closest('form'); if (f) refreshDashboard(f,true); } }
    if (e.target && e.target.id === 'dashboard-period') { var f2 = e.target.closest('form'); if (f2) refreshDashboard(f2,true); }
  });
  document.addEventListener('submit', function(e){ var f = e.target.closest ? e.target.closest('.dashboard-filter-form') : null; if (!f) return; e.preventDefault(); refreshDashboard(f,true); });
  window.addEventListener('popstate', function(){ var f = document.querySelector('.dashboard-filter-form'); if (f) refreshDashboard(f,false,window.location.href); });
  // Re-render charts on theme change
  document.addEventListener('click', function(e){ if (e.target && e.target.closest && e.target.closest('.theme-toggle')) setTimeout(renderCharts, 50); });

  updateCustomDateFields();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', renderCharts); else renderCharts();
})();
