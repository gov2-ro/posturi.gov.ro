/*
 * The landing page (pages/home.php, ADR 0004) and the themed pages (pages/theme.php). Two things need script:
 *
 *  1. The search form. An empty form opens /posturi/ (the unfiltered list); a
 *     filled one opens the canonical list URL of its fields (the hidden fields of a
 *     theme filter included). Without this script the server redirects a blank
 *     request (index.php).
 *  2. The county select and the county tile map. Both are hidden in the HTML and shown
 *     here. A county selected on either one shows, on each item ([data-home-tile]: a
 *     professional domain, a specialty, ...), the count of that county in brackets, hides
 *     an item with no postings, and points the item's link at the list of the item's
 *     filter and that county. The filter of each item and its counts are in the JSON
 *     block #home-data: items[id] = {params: {field: [values]}, counties: {slug: n}}
 *     (active postings, the same predicate as the list). An item with no count element
 *     only gets the county in its link. Without script the items show the totals.
 *     A themed page has the select of its search form: the map and that select are one control. The checkbox
 *     [data-county-all] ("Arată toate județele") is checked when no county is selected and clears the county when it is checked;
 *     the <h1> gets " → county" ([data-county-title]) and the count of that county ([data-title-count]). Each selection
 *     sends the event `posturi:county` ({slug}) to static/theme-panel.js, which filters the chart.
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
      var p = new URLSearchParams();
      new FormData(form).forEach(function (v, k) {
        if (typeof v === 'string' && v.trim() !== '') p.append(k, v.trim());
      });
      var key = searchKey(p.toString());
      window.location.assign(key ? '/?' + key : '/posturi/');
    });
  }

  // ---- 2. county select and map ---------------------------------------------
  var dataEl = document.getElementById('home-data');
  var data = null;
  if (dataEl) { try { data = JSON.parse(dataEl.textContent); } catch (e) { data = null; } }
  if (!data) return;

  // The county select: the one of the domains section (landing page) or, on a themed page, the one of the search form. The map and
  // the select are one control: a click on the map sets the select, a change of the select marks the map and filters the page.
  var select = document.getElementById('domenii-judet') || qs('[data-home-search] select[name="judet[]"]');
  var cells = qsa('[data-county]');
  var tiles = qsa('[data-home-tile]');
  var live = qs('[data-home-live]');
  var allBox = qs('[data-county-all]');            // the checkbox "Arată toate județele" under the map of a themed page
  var title = qs('[data-county-title]');           // inside the <h1> of a themed page
  var titleCount = qs('[data-title-count]');       // the count of active postings after the title
  var current = select ? select.value : '';
  if (!select && !cells.length) return;
  qsa('[data-home-js]').forEach(function (el) { el.hidden = false; });

  function selectedName() {
    if (select) {
      var opt = select.options[select.selectedIndex];
      return opt ? (opt.dataset.name || opt.textContent) : '';
    }
    var cell = qs('[data-county="' + current + '"]');
    return cell ? (cell.getAttribute('aria-label') || '').replace(/:.*$/, '') : '';
  }

  /** The values of a JSON list (the server writes lists as objects, JSON_FORCE_OBJECT). */
  function list(o) { return o ? Object.keys(o).map(function (k) { return o[k]; }) : []; }

  function apply(slug, announce) {
    current = slug;
    if (select) select.value = slug;
    if (allBox) allBox.checked = !slug;
    if (titleCount) {
      var all = (data.items || {}).all || {};
      titleCount.textContent = '\u00a0(' + numLabel(slug ? ((all.counties || {})[slug] || 0) : data.total) + ')';
    }
    if (title) {
      // "Posturi în sănătate → Suceava": the arrow is decoration; the word "județul" is for assistive technology only.
      title.textContent = '';
      if (slug) {
        var arrow = document.createElement('span'); arrow.setAttribute('aria-hidden', 'true'); arrow.textContent = ' \u2192 ';
        var word = document.createElement('span'); word.className = 'sr-only'; word.textContent = ' jude\u021bul ';
        title.appendChild(arrow); title.appendChild(word); title.appendChild(document.createTextNode(selectedName()));
      }
    }
    cells.forEach(function (c) { c.setAttribute('aria-pressed', c.dataset.county === slug ? 'true' : 'false'); });
    tiles.forEach(function (t) {
      var item = (data.items || {})[t.dataset.domain] || {};
      var count = qs('[data-tile-count]', t);
      if (count) {
        var n = slug ? ((item.counties || {})[slug] || 0) : parseInt(t.dataset.total, 10);
        t.hidden = n === 0;
        count.textContent = '\u00a0(' + numLabel(n) + ')';
      }
      var p = new URLSearchParams();
      if (slug) p.append('judet[]', slug);
      var params = item.params || {};
      Object.keys(params).forEach(function (field) {
        list(params[field]).forEach(function (v) { p.append(field, v); });
      });
      qs('a', t).setAttribute('href', '/?' + searchKey(p.toString()));
    });
    // A row of two or more domains (partials/count_item.php, `parts`) hides when it has no visible domain.
    qsa('[data-home-group]').forEach(function (g) {
      g.hidden = qsa('[data-home-tile]', g).every(function (t) { return t.hidden; });
    });
    // The county is in the URL fragment (/#cluj), so the reader can share the selection. No county: no fragment.
    if (announce && window.history && history.replaceState) {
      history.replaceState(null, '', location.pathname + location.search + (slug ? '#' + slug : ''));
    }
    if (announce && live) {
      var total = slug ? (data.counties[slug] || 0) : data.total;
      live.textContent = (slug ? selectedName() : 'Toate județele') + ': ' + postsLabel(total) + ' active';
    }
    // The statistics panel of a themed page (static/theme-panel.js) follows the selection.
    document.dispatchEvent(new CustomEvent('posturi:county', { detail: { slug: slug } }));
  }

  cells.forEach(function (c) {
    c.addEventListener('click', function () {
      var slug = c.dataset.county;
      apply(current === slug ? '' : slug, true);
    });
  });
  // The checkbox is checked when no county is selected. Checking it clears the county; a click while none is selected leaves it checked.
  if (allBox) allBox.addEventListener('change', function () {
    if (allBox.checked) apply('', true); else allBox.checked = !current;
  });
  if (select) select.addEventListener('change', function () { apply(select.value, true); });

  /** The county slug of the URL fragment (/#cluj), or '' when the fragment is not a county of the map or the select. */
  function hashCounty() {
    var h = decodeURIComponent(location.hash.slice(1));
    if (!/^[a-z-]+$/.test(h)) return '';
    if (select) return Array.prototype.some.call(select.options, function (o) { return o.value === h; }) ? h : '';
    return qs('[data-county="' + h + '"]') ? h : '';
  }
  // A county in the fragment selects that county and, on the landing page, scrolls to the domains section.
  function fromHash(scroll) {
    var h = hashCounty();
    if (!h) return false;
    apply(h, false);
    var section = document.getElementById('domenii');
    if (scroll && section) section.scrollIntoView();
    return true;
  }
  window.addEventListener('hashchange', function () { fromHash(true); });
  if (!fromHash(true)) apply(current, false);
})();
