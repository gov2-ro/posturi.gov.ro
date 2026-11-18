/*
 * The weekly chart of a themed page (partials/theme_stats.php, partials/weekly_chart.php). Two things need script:
 *
 *  1. The occupation buttons. "Toate" and one button for each group. "Toate" is a stacked bar of 12 weeks: one segment for each group,
 *     from the bottom up, then "Alte ocupații" (the total minus the groups). A click on a group shows the bars of that group in its
 *     colour (and the line of text, the label): one vertical scale for all buttons, from 0 to the largest week of any of them. A
 *     sentence goes to a polite live region. One button is pressed at a time (aria-pressed). The colour classes: pg-c-0 pg-c-1 pg-c-2
 *     pg-c-3 pg-c-other pg-seg (assets/app.css).
 *  2. The county selection (static/home.js sends the event `posturi:county` with `detail.slug`, "" for all). Every series shows that
 *     county; a button whose series is 0 in every week of the county is hidden (and "Toate" is pressed when it was the pressed
 *     one). The five buttons are the same for every county.
 *
 *  3. The salary chart (partials/salary_chart.php): the counts, bars and links of the rows follow the county, from `salary` in the JSON block.
 *
 * The data are in the JSON block #theme-panel-data: {salary: {rows: [{key, counties, fields}]}, caption, weeks, total: [12], series: [{name, values}], counties: {slug: {t: [12 weeks] |
 * null, s: [[12 weeks] | null, ...]}}, weeks: [12 short dates]}. The server output is the whole country, so without script the chart
 * shows it. The geometry is the one of partials/weekly_chart.php.
 */
(function () {
  'use strict';
  var panel = document.querySelector('[data-theme-stats]');
  var el = document.getElementById('theme-panel-data');
  if (!panel || !el) return;
  var data;
  try { data = JSON.parse(el.textContent); } catch (e) { return; }
  var counties = data.counties || {};

  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function num(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function round2(x) { return Math.round(x * 100) / 100; }
  /** "N posturi", with "de" from 20 on (the Romanian rule, as posturi_label() in helpers.php). */
  function posts(n) {
    var mod = n % 100;
    return num(n) + ((n > 0 && (mod === 0 || mod >= 20)) ? ' de posturi' : (n === 1 ? ' post' : ' posturi'));
  }
  var county = '';
  var active = -1;   // -1 = "Toate", else the index of the series

  // ---- chart ----
  var fig = panel.querySelector('[data-chart]');
  var bars = fig && fig.querySelector('[data-chart-bars]');
  var otherLegend = fig && fig.querySelector('[data-chart-other]');
  var stats = fig && fig.querySelector('[data-chart-stats]');
  var svg = fig && fig.querySelector('[data-chart-svg]');
  var live = fig && fig.querySelector('[data-chart-live]');
  var buttons = fig ? qsa('[data-chart-series]', fig) : [];
  // The geometry of partials/weekly_chart.php: 12 slots in a 320 x 64 box, a bar is half of its slot, the baseline is at y = 63 and the
  // top of the axis at y = 3.
  var W = 320, BASE = 63, PLOT = 60, NS = 'http://www.w3.org/2000/svg';
  var CLS = [0, 1, 2, 3];   // the colour class of group i: the local categorical palette, in order (assets/app.css, --c-cat-1 to -4)

  function zeros() { return (data.total || []).map(function () { return 0; }); }
  function seriesOf(index) {
    if (!county) return index < 0 ? data.total : data.series[index].values;
    var c = counties[county];
    var v = c ? (index < 0 ? c.t : (c.s || [])[index]) : null;
    return v || zeros();
  }
  function sum(v) { return v.reduce(function (a, b) { return a + b; }, 0); }

  /** The top of the axis: the largest week of the total and of each series, in the selected county. The same for every button. */
  function axisMax() {
    var top = Math.max.apply(null, seriesOf(-1));
    (data.series || []).forEach(function (s, i) { top = Math.max(top, Math.max.apply(null, seriesOf(i))); });
    return top;
  }

  /** The segments of one week, bottom up: [class, name, value]. "Toate": the groups, then the rest. A group: that group alone. */
  function segments(index, w) {
    if (index >= 0) return [['pg-seg pg-c-' + CLS[index], data.series[index].name, seriesOf(index)[w]]];
    var segs = [], rest = seriesOf(-1)[w];
    (data.series || []).forEach(function (s, i) {
      var v = seriesOf(i)[w];
      rest -= v;
      segs.push(['pg-seg pg-c-' + CLS[i], s.name, v]);
    });
    segs.push(['pg-seg pg-c-other', 'Alte ocupații', rest]);
    return segs;
  }

  function svgEl(name, attrs) {
    var n = document.createElementNS(NS, name);
    Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); });
    return n;
  }

  function draw(index, vals, name) {
    var min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
    // One axis for every button, from 0 to axisMax() (partials/weekly_chart.php draws "Toate" on the same axis): a series of
    // 3 a week stays low next to a total of 60. The text keeps the min and max of the series itself.
    var top = axisMax(), scale = top > 0 ? PLOT / top : 0, slot = W / vals.length;
    while (bars.firstChild) bars.removeChild(bars.firstChild);
    vals.forEach(function (_, w) {
      var g = svgEl('g', { 'data-bar': '' }), segs = segments(index, w).filter(function (sg) { return sg[2] > 0; }), cum = 0;
      segs.forEach(function (sg, k) {
        var h = sg[2] * scale, y = BASE - (cum + sg[2]) * scale;
        cum += sg[2];
        var r = svgEl('rect', { x: round2(w * slot + slot / 4), y: round2(y), width: round2(slot / 2), height: round2(h), 'class': sg[0] });
        var t = document.createElementNS(NS, 'title');
        t.textContent = 'săpt. ' + (data.weeks || [])[w] + (sg[1] ? ', ' + sg[1] : '') + ': ' + posts(sg[2]);
        r.appendChild(t);
        g.appendChild(r);
      });
      bars.appendChild(g);
    });
    stats.textContent = 'min ' + num(min) + ' · max ' + num(max) + ' · ultima ' + num(vals[vals.length - 1]);
    svg.setAttribute('aria-label', data.caption + (name ? ' (' + name + ')' : '') + ', ultimele 12 săptămâni complete: între ' + num(min) + ' și ' + posts(max));
  }

  function showChart(announce) {
    // A button whose series is 0 in every week of the county is hidden; if it was pressed, "Toate" is.
    buttons.forEach(function (b) {
      var i = parseInt(b.dataset.chartSeries, 10);
      b.hidden = i >= 0 && county !== '' && sum(seriesOf(i)) === 0;
    });
    var pressed = buttons.filter(function (b) { return parseInt(b.dataset.chartSeries, 10) === active; })[0];
    if (active >= 0 && (!pressed || pressed.hidden)) active = -1;
    var s = active < 0 ? null : data.series[active];
    var vals = seriesOf(active);
    draw(active, vals, s ? s.name : '');
    if (otherLegend) otherLegend.hidden = active >= 0;   // "Alte ocupații" is a segment of "Toate" only
    buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(parseInt(b.dataset.chartSeries, 10) === active)); });
    if (announce && live) live.textContent = (s ? s.name : 'Toate ocupațiile') + ': ' + posts(sum(vals)) + ' în ultimele 12 săptămâni';
  }

  // ---- salary chart (partials/salary_chart.php) ----
  // data.salary = {rows: [{key, counties: {slug: n}, fields: {field: [values]}}]}. The server draws the whole country. For a county the
  // count of each row, the width of the bars (the longest fills the track, a count above 0 is at least 2 px) and the link (the filter of
  // the row and `judet[]`) change. The row "fără salariu estimat" is one of the rows. The bar of a row of count 0 has no width.
  // The salary chart is a section lower on the page (2026-11-18), not in the panel.
  var salFig = document.querySelector('[data-salary-chart]');
  var salRows = salFig && data.salary ? data.salary.rows || [] : [];
  var salTotals = {};   // the national count of each row, from the server markup
  qsa('[data-salary-row]', salFig || document).forEach(function (li) {
    var c = li.querySelector('[data-salary-count]');
    salTotals[li.getAttribute('data-salary-row')] = c ? parseInt(String(c.textContent).replace(/\./g, ''), 10) || 0 : 0;
  });

  /** The canonical query string (ADR 0001), from static/searches.js when it is loaded; else the same rules as static/home.js. */
  function searchKey(search) {
    if (window.PosturiSearches && window.PosturiSearches.searchKey) return window.PosturiSearches.searchKey(search);
    var p = new URLSearchParams(search), out = [];
    Array.from(new Set(Array.from(p.keys()))).sort().forEach(function (name) {
      var multi = name.slice(-2) === '[]', base = multi ? name.slice(0, -2) : name;
      var vals = p.getAll(name).filter(function (v) { return v.trim() !== ''; });
      vals = multi ? Array.from(new Set(vals)).sort() : vals.slice(-1);
      vals.forEach(function (v) { out.push(encodeURIComponent(base) + (multi ? '%5B%5D=' : '=') + encodeURIComponent(v)); });
    });
    return out.join('&');
  }
  /** "anunț" / "anunțuri" / "de anunțuri" (as the partial writes it). */
  function unit(n) {
    var mod = n % 100;
    return n === 1 ? 'anun\u021b' : ((n > 0 && (mod === 0 || mod >= 20)) ? 'de anun\u021buri' : 'anun\u021buri');
  }

  function showSalary() {
    if (!salFig) return;
    var ns = salRows.map(function (r) { return county ? ((r.counties || {})[county] || 0) : (salTotals[r.key] || 0); });
    var max = Math.max.apply(null, ns.concat([1]));
    salRows.forEach(function (r, i) {
      var li = salFig.querySelector('[data-salary-row="' + r.key + '"]');
      if (!li) return;
      var n = ns[i], bar = li.querySelector('[data-salary-bar]'), cnt = li.querySelector('[data-salary-count]'), u = li.querySelector('[data-salary-unit]'), a = li.querySelector('a');
      if (bar) { bar.style.width = 'calc((100% - 4rem) * ' + (Math.round(10000 * n / max) / 10000) + ')'; bar.style.minWidth = n > 0 ? '2px' : ''; }
      if (cnt) cnt.textContent = num(n);
      if (u) u.textContent = ' ' + unit(n);
      if (a) {
        var p = new URLSearchParams();
        if (county) p.append('judet[]', county);
        Object.keys(r.fields || {}).forEach(function (f) { (r.fields[f] || []).forEach(function (v) { p.append(f, v); }); });
        a.setAttribute('href', '/?' + searchKey(p.toString()));
      }
    });
  }

  function setCounty(slug) {
    county = slug || '';
    if (bars && stats && svg && data.series) showChart(false);
    showSalary();
  }

  if (fig && bars && stats && svg && data.series) {
    qsa('[data-chart-js]', fig).forEach(function (n) { n.hidden = false; });
    buttons.forEach(function (b) {
      b.addEventListener('click', function () { active = parseInt(b.dataset.chartSeries, 10); showChart(true); });
    });
  }
  document.addEventListener('posturi:county', function (ev) { setCounty(ev.detail && ev.detail.slug); });
  // home.js has already applied the county that the form shows (a value that the browser restored): take it.
  var sel = document.querySelector('[data-home-search] select[name="judet[]"]');
  if (sel && sel.value) setCounty(sel.value);
})();
