/*
 * The weekly chart of a themed page (partials/theme_stats.php, partials/weekly_chart.php). Two things need script:
 *
 *  1. The occupation buttons. "Toate" and one button for each occupation; a click shows that series in the same chart (line, area,
 *     line of text, label; one vertical scale for all buttons, from 0 to the largest week of any of them) and writes a sentence to a polite live region. One button is pressed at a
 *     time (aria-pressed).
 *  2. The county selection (static/home.js sends the event `posturi:county` with `detail.slug`, "" for all). Every series shows that
 *     county; a button whose series is 0 in every week of the county is hidden (and "Toate" is pressed when it was the pressed
 *     one). The five buttons are the same for every county.
 *
 * The data are in the JSON block #theme-panel-data: {caption, total: [12], series: [{name, values}], counties: {slug: {t: [12 weeks] |
 * null, s: [[12 weeks] | null, ...]}}}. The server output is the whole country, so without script the chart shows it. The geometry is
 * the one of partials/weekly_chart.php.
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
  function round1(x) { return Math.round(x * 10) / 10; }
  /** "N anunțuri", with "de" from 20 on (the Romanian rule, as posturi_label() in helpers.php). */
  function ads(n) {
    var mod = n % 100;
    return num(n) + ((n > 0 && (mod === 0 || mod >= 20)) ? ' de anunțuri' : (n === 1 ? ' anunț' : ' anunțuri'));
  }
  var county = '';
  var active = -1;   // -1 = "Toate", else the index of the series

  // ---- chart ----
  var fig = panel.querySelector('[data-chart]');
  var line = fig && fig.querySelector('[data-chart-line]');
  var area = fig && fig.querySelector('[data-chart-area]');
  var stats = fig && fig.querySelector('[data-chart-stats]');
  var svg = fig && fig.querySelector('[data-chart-svg]');
  var live = fig && fig.querySelector('[data-chart-live]');
  var buttons = fig ? qsa('[data-chart-series]', fig) : [];
  var W = 320, H = 64, P = 4;

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

  function draw(vals, name) {
    var min = Math.min.apply(null, vals), max = Math.max.apply(null, vals);
    // One axis for every button, from 0 to axisMax() (partials/weekly_chart.php draws the total on the same axis): a series of
    // 3 a week stays low next to a total of 60. The text keeps the min and max of the series itself.
    var top = axisMax();
    var pts = vals.map(function (v, i) {
      var x = i / (vals.length - 1) * W;
      var y = top === 0 ? H / 2 : P + (1 - v / top) * (H - 2 * P);
      return round1(x) + ',' + round1(y);
    });
    line.setAttribute('points', pts.join(' '));
    area.setAttribute('d', 'M0,' + H + ' L' + pts.join(' L') + ' L' + W + ',' + H + ' Z');
    stats.textContent = 'min ' + num(min) + ' · max ' + num(max) + ' · ultima ' + num(vals[vals.length - 1]);
    svg.setAttribute('aria-label', data.caption + (name ? ' (' + name + ')' : '') + ', ultimele 12 săptămâni complete: între ' + num(min) + ' și ' + num(max));
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
    draw(vals, s ? s.name : '');
    buttons.forEach(function (b) { b.setAttribute('aria-pressed', String(parseInt(b.dataset.chartSeries, 10) === active)); });
    if (announce && live) live.textContent = (s ? s.name : 'Toate ocupațiile') + ': ' + ads(sum(vals)) + ' în ultimele 12 săptămâni';
  }

  function setCounty(slug) {
    county = slug || '';
    if (line && area && stats && svg && data.series) showChart(false);
  }

  if (fig && line && area && stats && svg && data.series) {
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
