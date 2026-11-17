/*
 * The landing page (pages/home.php, ADR 0004). Two things need script:
 *
 *  1. The hero form. An empty form opens /posturi/ (the unfiltered list); a
 *     filled one opens the canonical list URL of its fields. Without this
 *     script the server redirects a blank request (index.php).
 *  2. The county select and the county tile map in #domenii. Both are hidden
 *     in the HTML and shown here. A county selected on either one shows, on each
 *     professional-domain item, the count of that county in brackets, hides an item with no postings, and
 *     points the item's link at the list for that county and domain (family[], and cor[] for the split of "tehnic"). The counts are in
 *     the JSON block #home-data (active postings, the same predicate as the
 *     list). Without script the tiles show the totals and link to the domain.
 *
 * No personal state is read or written here.
 */
(function () {
  'use strict';

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

  /** The canonical query string of a `?…` string (ADR 0001), from static/searches.js when it is loaded. */
  function searchKey(search) {
    if (window.PosturiSearches && window.PosturiSearches.searchKey) return window.PosturiSearches.searchKey(search);
    var p = new URLSearchParams(search);
    var out = [];
    Array.from(new Set(Array.from(p.keys()))).sort().forEach(function (name) {
      var multi = name.slice(-2) === '[]';
      var base = multi ? name.slice(0, -2) : name;
      var vals = p.getAll(name).filter(function (v) { return v.trim() !== ''; });
      if (multi) vals = Array.from(new Set(vals)).sort();
      else vals = vals.slice(-1);
      vals.forEach(function (v) { out.push(encodeURIComponent(base) + (multi ? '%5B%5D=' : '=') + encodeURIComponent(v)); });
    });
    return out.join('&');
  }

  /** "N posturi" as the server writes it (posturi_label() in helpers.php). */
  function postsLabel(n) {
    var num = String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    if (n === 1) return '1 post';
    var mod = n % 100;
    return (n > 0 && (mod === 0 || mod >= 20)) ? num + ' de posturi' : num + ' posturi';
  }

  /** "1.197", as the server writes it (number_format in pages/home.php). */
  function numLabel(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }

  // ---- 1. hero form --------------------------------------------------------
  var form = qs('[data-home-search]');
  if (form) {
    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      var q = form.elements.q.value.trim();
      var judet = form.elements['judet[]'].value;
      var p = new URLSearchParams();
      if (q) p.set('q', q);
      if (judet) p.append('judet[]', judet);
      var key = searchKey(p.toString());
      window.location.assign(key ? '/?' + key : '/posturi/');
    });
  }

  // ---- 2. county select and map ---------------------------------------------
  var dataEl = document.getElementById('home-data');
  var data = null;
  if (dataEl) { try { data = JSON.parse(dataEl.textContent); } catch (e) { data = null; } }
  if (!data) return;

  var select = document.getElementById('domenii-judet');
  var cells = qsa('[data-county]');
  var tiles = qsa('[data-home-tile]');
  var live = qs('[data-home-live]');
  if (!select) return;
  qsa('[data-home-js]').forEach(function (el) { el.hidden = false; });

  function selectedName() {
    var opt = select.options[select.selectedIndex];
    return opt ? (opt.dataset.name || opt.textContent) : '';
  }

  /** The values of a JSON list (the server writes lists as objects, JSON_FORCE_OBJECT). */
  function list(o) { return o ? Object.keys(o).map(function (k) { return o[k]; }) : []; }

  function apply(slug, announce) {
    select.value = slug;
    cells.forEach(function (c) { c.setAttribute('aria-pressed', c.dataset.county === slug ? 'true' : 'false'); });
    tiles.forEach(function (t) {
      var dom = (data.domains || {})[t.dataset.domain] || {};
      var n = slug ? ((dom.counties || {})[slug] || 0) : parseInt(t.dataset.total, 10);
      t.hidden = n === 0;
      qs('[data-tile-count]', t).textContent = '\u00a0(' + numLabel(n) + ')';
      var p = new URLSearchParams();
      if (slug) p.append('judet[]', slug);
      list(dom.family).forEach(function (v) { p.append('family[]', v); });
      list(dom.cor).forEach(function (v) { p.append('cor[]', v); });
      qs('a', t).setAttribute('href', '/?' + searchKey(p.toString()));
    });
    if (announce && live) {
      var total = slug ? (data.counties[slug] || 0) : data.total;
      live.textContent = (slug ? selectedName() : 'Toate județele') + ': ' + postsLabel(total) + ' active';
    }
  }

  cells.forEach(function (c) {
    c.addEventListener('click', function () {
      var slug = c.dataset.county;
      apply(select.value === slug ? '' : slug, true);
    });
  });
  select.addEventListener('change', function () { apply(select.value, true); });
  apply(select.value, false);
})();
