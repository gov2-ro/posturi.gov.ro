/*
 * UX-SALVATE-FLUX — the „Flux” tab of /salvate/.
 *
 * One list of the OPEN postings from every source of the reader: the saved postings, the saved
 * searches and the followed employers. The three lists live in the browser (saved.js, searches.js,
 * employers.js). This file reads them, sends their keys, slugs and ids to /flux.json in ONE GET,
 * and draws the answer: stats, a chip for each source, and the cards.
 *
 * Nothing else leaves the browser, and nothing is stored here. The server answers with rows only;
 * the stats are computed here from the rows that the chips select. A posting that the reader hid
 * is dropped before anything is counted. Save, hide and note on a card are saved.js's own handlers:
 * the card is a normal `[data-posting]` row, so they work as on the list.
 *
 * The Flux asks again when the saved searches or the followed employers change (this tab, or
 * another one). It does not ask again when a posting is saved or hidden: a hidden posting leaves
 * the list at once, and a new bookmark shows in the chip „Salvate” at the next visit.
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-flux-panel]');
  if (!root || root.hidden) return;      // another tab is open

  var PAGE = 25;
  var TOP = 5;
  var NEW_DAYS = 3;
  var CLOSING_DAYS = 7;
  var MIN_SALARIES = 3;
  var FALLBACK_NOTE = 'Expirarea anunțului; înscriere neconfirmată';
  var PATH_RE = /^\/job\/([1-9]\d{0,9})(?:-[a-z0-9-]*)?\/$/;

  var st = {
    sources: [],        // [{kind, ref, label, href}] in request order
    url: null,          // the request now shown (or in flight)
    data: null,         // {today, rows, sources}
    selected: Object.create(null),   // source index -> true
    sort: 'deadline',
    page: 1,
    hiddenSig: '',
    seq: 0
  };

  function qs(sel, el) { return (el || root).querySelector(sel); }
  function qsa(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text != null) n.textContent = text;
    return n;
  }
  function num(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, '.'); }
  function anunturi(n) {
    if (n === 1) return '1 anunț';
    var m = n % 100;
    return num(n) + (m === 0 || m >= 20 ? ' de' : '') + ' anunțuri';
  }
  function fmtDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
  }
  function dayNo(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    return m ? Math.round(Date.UTC(+m[1], +m[2] - 1, +m[3]) / 86400000) : null;
  }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

  // ---- the reader's three lists --------------------------------------------------
  function gather() {
    var searches = [], employers = [], saved = [], hidden = Object.create(null);
    try { if (window.PosturiSearches) searches = window.PosturiSearches.list().slice(0, 4); } catch (e) { searches = []; }
    try { if (window.PosturiEmployers) employers = window.PosturiEmployers.list().slice(0, 10); } catch (e) { employers = []; }
    try {
      if (window.PosturiSaved) {
        var s = window.PosturiSaved.state();
        if (!s.blocked) { saved = s.saved.slice(0, 20); hidden = s.hidden; }
      }
    } catch (e) { saved = []; }
    return { searches: searches, employers: employers, saved: saved, hidden: hidden };
  }

  /** The request, and the sources in the order the server answers them (searches, employers, saved). */
  function plan(g) {
    var params = new URLSearchParams();
    var sources = [];
    g.searches.forEach(function (r) {
      params.append('s[]', r.key);
      sources.push({ kind: 'search', ref: r.key, label: r.label, href: '/?' + r.qs + (r.sort ? '&sort=' + encodeURIComponent(r.sort) : ''), open: 'deschide căutarea pentru toate' });
    });
    g.employers.forEach(function (r) {
      params.append('e[]', r.slug);
      sources.push({ kind: 'employer', ref: r.slug, label: r.name, href: '/angajator/' + encodeURIComponent(r.slug) + '/', open: 'deschide pagina angajatorului pentru toate' });
    });
    if (g.saved.length) {
      g.saved.forEach(function (r) { params.append('id[]', String(r.id)); });
      sources.push({ kind: 'saved', ref: 'saved', label: 'Salvate', href: '/salvate/?vedere=liste', open: 'deschide Liste pentru toate' });
    }
    return { url: sources.length ? '/flux.json?' + params.toString() : null, sources: sources };
  }

  function hiddenSig(h) { return Object.keys(h).sort().join('\n'); }

  // ---- load ----------------------------------------------------------------------
  function show(name) {
    qsa('[data-flux-empty]').forEach(function (n) { n.hidden = n.getAttribute('data-flux-empty') !== name; });
  }

  function status(text) { qs('[data-flux-status]').textContent = text; }

  function load(force) {
    var g = gather();
    var p = plan(g);
    if (!force && p.url === st.url && st.data) { st.sources = p.sources; return render(g); }   // same request; a label may differ
    st.url = p.url;
    st.sources = p.sources;
    st.data = null;
    st.selected = Object.create(null);
    st.page = 1;
    var seq = ++st.seq;
    qs('[data-flux-error]').hidden = true;
    if (!p.url) {
      qs('[data-flux-body]').hidden = true;
      qs('[data-flux-count]').textContent = '';
      status('');
      show('none');
      return;
    }
    show('');
    qs('[data-flux-body]').hidden = true;
    status('Se încarcă fluxul…');
    fetch(p.url, { method: 'GET', credentials: 'omit', cache: 'no-store', headers: { 'Accept': 'application/json' } })
      .then(function (res) {
        if (!res.ok) throw new Error('http ' + res.status);
        return res.json();
      })
      .then(function (j) {
        if (seq !== st.seq) return;
        if (!j || !Array.isArray(j.rows) || !Array.isArray(j.sources) || j.sources.length !== p.sources.length) throw new Error('shape');
        st.data = j;
        status('');
        render(gather());
      })
      .catch(function () {
        if (seq !== st.seq) return;
        st.url = null;       // the next change asks again
        status('');
        qs('[data-flux-error]').hidden = false;
      });
  }

  // ---- stats ---------------------------------------------------------------------
  function tally(rows, field) {
    var by = Object.create(null);
    rows.forEach(function (r) { if (r[field]) by[r[field]] = (by[r[field]] || 0) + 1; });
    return Object.keys(by).map(function (k) { return { name: k, n: by[k] }; })
      .sort(function (a, b) { return (b.n - a.n) || (a.name < b.name ? -1 : 1); });
  }

  function tile(title, big) {
    var t = el('div', 'g2-tile p-3');
    if (big != null) t.appendChild(el('p', 'font-display text-2xl font-semibold leading-tight text-ink', big));
    t.appendChild(el('h3', 'text-xs font-medium uppercase tracking-wide text-ink-muted', title));
    return t;
  }

  function topList(title, items) {
    var t = tile(title);
    var ul = el('ul', 'mt-2 space-y-1 text-sm');
    items.slice(0, TOP).forEach(function (it) {
      var li = el('li', 'flex justify-between gap-2');
      li.appendChild(el('span', 'min-w-0 break-words text-ink', cap(it.name)));
      li.appendChild(el('span', 'font-mono text-xs text-ink-muted', String(it.n)));
      ul.appendChild(li);
    });
    t.appendChild(ul);
    if (items.length > TOP) t.appendChild(el('p', 'mt-1 text-xs text-ink-muted', 'alte ' + (items.length - TOP)));
    return t;
  }

  function renderStats(rows, today) {
    var box = qs('[data-flux-stats]');
    while (box.firstChild) box.removeChild(box.firstChild);
    if (!rows.length) return;
    var t0 = dayNo(today);
    var fresh = 0, closing = 0;
    rows.forEach(function (r) {
      var p = dayNo(r.published_at), d = dayNo(r.apply_deadline);
      if (t0 !== null && p !== null && p <= t0 && t0 - p <= NEW_DAYS) fresh++;
      if (t0 !== null && d !== null && d >= t0 && d - t0 <= CLOSING_DAYS) closing++;
    });
    var counts = tile(rows.length === 1 ? 'anunț deschis' : 'anunțuri deschise', num(rows.length));
    counts.setAttribute('data-stat', 'counts');
    var lines = el('ul', 'mt-2 space-y-0.5 text-sm text-ink');
    if (fresh > 0) lines.appendChild(el('li', null, 'noi în ultimele ' + NEW_DAYS + ' zile: ' + fresh));
    if (closing > 0) lines.appendChild(el('li', null, 'se închid în ' + CLOSING_DAYS + ' zile: ' + closing));
    if (lines.firstChild) counts.appendChild(lines);
    box.appendChild(counts);

    var counties = tally(rows, 'judet');
    if (counties.length > 1) {
      var c = topList('Pe județ', counties);
      c.setAttribute('data-stat', 'county');
      box.appendChild(c);
    }
    var domains = tally(rows, 'domain');
    if (domains.length > 1) {
      var d = topList('Pe domeniu', domains);
      d.setAttribute('data-stat', 'domain');
      box.appendChild(d);
    }
    var pay = rows.map(function (r) { return r.salary && typeof r.salary.net === 'number' ? r.salary.net : null; })
      .filter(function (v) { return v !== null; }).sort(function (a, b) { return a - b; });
    if (pay.length >= MIN_SALARIES) {
      var k = pay.length;
      var med = k % 2 ? pay[(k - 1) / 2] : Math.round((pay[k / 2 - 1] + pay[k / 2]) / 2);
      var s = tile('Salariu net lunar', num(pay[0]) + ' – ' + num(med) + ' – ' + num(pay[k - 1]) + ' lei');
      s.setAttribute('data-stat', 'salary');
      s.appendChild(el('p', 'mt-1 text-xs text-ink-muted', 'minim – mediană – maxim, din ' + anunturi(k) + ' cu salariu anunțat sau estimat'));
      box.appendChild(s);
    }
  }

  // ---- chips, sort, notes ----------------------------------------------------------
  function selectedCount() { return Object.keys(st.selected).length; }

  function renderChips(visible) {
    var box = qs('[data-flux-chips]');
    while (box.firstChild) box.removeChild(box.firstChild);
    function chip(label, n, pressed, idx, empty, unknown) {
      var b = el('button', 'g2-chip' + (empty ? ' opacity-60' : ''));
      b.type = 'button';
      b.setAttribute('aria-pressed', pressed ? 'true' : 'false');
      if (idx === null) b.setAttribute('data-flux-all', '');
      else b.setAttribute('data-flux-chip', String(idx));
      if (empty) b.setAttribute('data-empty', '');
      b.appendChild(document.createTextNode(label + (unknown ? ' (indisponibilă)' : '')));
      b.appendChild(el('span', 'g2-chip-count', String(n)));
      box.appendChild(b);
    }
    chip('Toate', visible.length, selectedCount() === 0, null, false, false);
    st.sources.forEach(function (s, i) {
      var n = visible.filter(function (r) { return r.src.indexOf(i) !== -1; }).length;
      chip(s.label, n, !!st.selected[i], i, n === 0, !!(st.data.sources[i] && st.data.sources[i].unknown));
    });
  }

  function renderNotes(all) {
    var box = qs('[data-flux-notes]');
    while (box.firstChild) box.removeChild(box.firstChild);
    st.sources.forEach(function (s, i) {
      if (selectedCount() && !st.selected[i]) return;
      var d = st.data.sources[i];
      if (!d) return;
      if (d.unknown) {
        box.appendChild(el('p', null, '«' + s.label + '»: sursa nu mai există în baza curentă.'));
      } else if (d.truncated) {
        var got = all.filter(function (r) { return r.src.indexOf(i) !== -1; }).length;
        var p = el('p', null, '«' + s.label + '»: primele ' + got + ' — ');
        var a = el('a', 'text-gov underline', s.open);
        a.setAttribute('href', s.href);
        p.appendChild(a);
        box.appendChild(p);
      }
    });
  }

  // ---- cards ---------------------------------------------------------------------
  function sorted(rows) {
    var out = rows.slice();
    if (st.sort === 'new') {
      out.sort(function (a, b) {
        return String(b.published_at || '').localeCompare(String(a.published_at || '')) || b.id - a.id;
      });
    } else {
      out.sort(function (a, b) {
        var x = a.apply_deadline || '9999-99-99', y = b.apply_deadline || '9999-99-99';
        return x.localeCompare(y) || String(b.published_at || '').localeCompare(String(a.published_at || '')) || b.id - a.id;
      });
    }
    return out;
  }

  function sourceLabels(r) {
    var seen = Object.create(null), out = [];
    r.src.forEach(function (i) {
      var s = st.sources[i];
      if (s && !seen[s.label]) { seen[s.label] = 1; out.push(s.label); }
    });
    return out;
  }

  function buildCard(tpl, r) {
    var li = tpl.content.firstElementChild.cloneNode(true);
    var path = PATH_RE.test(r.url || '') && Number(PATH_RE.exec(r.url)[1]) === r.id ? r.url : '';
    var dateSource = r.deadline_source || '';
    li.setAttribute('data-pref-id', String(r.id));
    li.setAttribute('data-pref-url', r.source_url || '');
    li.setAttribute('data-pref-path', path);
    li.setAttribute('data-pref-title', r.title || '');
    li.setAttribute('data-pref-employer', r.employer || '');
    li.setAttribute('data-pref-place', r.place || '');
    li.setAttribute('data-pref-date', r.apply_deadline || '');
    li.setAttribute('data-pref-date-source', dateSource);
    var title = r.title || 'Anunț fără titlu';
    var link = qs('[data-f="link"]', li);
    link.textContent = title;
    if (path) link.setAttribute('href', path);
    qs('[data-f="meta"]', li).textContent = [r.employer, r.place].filter(Boolean).join(' · ');
    qs('[data-f="deadline"]', li).textContent = r.apply_deadline
      ? (dateSource === 'expirare' ? 'Termen estimat: ' : 'Termen: ') + fmtDate(r.apply_deadline)
      : 'Termen neprecizat';
    var note = qs('[data-f="note"]', li);
    note.textContent = r.apply_deadline && dateSource === 'expirare' ? FALLBACK_NOTE : '';
    note.hidden = !note.textContent;
    if (r.salary && r.salary.text) {
      var sal = qs('[data-f="salary"]', li);
      sal.textContent = 'Salariu: ' + r.salary.text + (r.salary.estimated ? ' (estimat)' : '');
      sal.hidden = false;
    }
    var tags = qs('[data-f="sources"]', li);
    sourceLabels(r).forEach(function (l) { tags.appendChild(el('span', 'g2-tag', l)); });
    qs('[data-f="sr-title"]', li).textContent = ' anunțul: ' + title;
    qs('[data-f="sr-title2"]', li).textContent = ' anunțul: ' + title;
    return li;
  }

  // ---- render --------------------------------------------------------------------
  function render(g) {
    if (!st.data) return;
    g = g || gather();
    st.hiddenSig = hiddenSig(g.hidden);
    var all = st.data.rows.filter(function (r) { return !g.hidden[r.source_url]; });
    var rows = selectedCount()
      ? all.filter(function (r) { return r.src.some(function (i) { return st.selected[i]; }); })
      : all;
    var body = qs('[data-flux-body]');
    body.hidden = false;
    show(all.length ? '' : 'closed');
    qs('[data-flux-count]').textContent = '(' + all.length + ')';
    renderChips(all);
    renderNotes(st.data.rows);
    qsa('[data-flux-sort]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-flux-sort') === st.sort ? 'true' : 'false'); });
    var has = all.length > 0;
    qs('[data-flux-stats]').hidden = !has;
    qs('[data-flux-list]').hidden = !has;
    qs('[data-flux-sort]').parentElement.hidden = !has;
    renderStats(rows, st.data.today);

    var list = sorted(rows);
    var pages = Math.max(1, Math.ceil(list.length / PAGE));
    st.page = Math.min(Math.max(1, st.page), pages);
    var ul = qs('[data-flux-list]');
    while (ul.firstChild) ul.removeChild(ul.firstChild);
    var tpl = document.getElementById('flux-item-tpl');
    list.slice((st.page - 1) * PAGE, st.page * PAGE).forEach(function (r) { ul.appendChild(buildCard(tpl, r)); });
    var pager = qs('[data-flux-pager]');
    pager.hidden = pages <= 1;
    if (pages > 1) {
      qs('[data-flux-pager-info]').textContent = ((st.page - 1) * PAGE + 1) + '–' + Math.min(st.page * PAGE, list.length) + ' din ' + list.length;
      qs('[data-flux-prev]').hidden = st.page <= 1;
      qs('[data-flux-next]').hidden = st.page >= pages;
    }
    if (window.PosturiSaved) window.PosturiSaved.apply();     // the save, hide and note state of the new cards
  }

  // ---- wiring ----------------------------------------------------------------------
  root.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!(t instanceof Element)) return;
    var b;
    if ((b = t.closest('[data-flux-chip]'))) {
      var i = b.getAttribute('data-flux-chip');
      if (st.selected[i]) delete st.selected[i]; else st.selected[i] = true;
      st.page = 1;
      render();
      var again = qs('[data-flux-chip="' + i + '"]');
      if (again) again.focus();
      return;
    }
    if (t.closest('[data-flux-all]')) {
      st.selected = Object.create(null);
      st.page = 1;
      render();
      var all = qs('[data-flux-all]');
      if (all) all.focus();
      return;
    }
    if ((b = t.closest('[data-flux-sort]'))) {
      st.sort = b.getAttribute('data-flux-sort');
      st.page = 1;
      render();
      var sb = qs('[data-flux-sort="' + st.sort + '"]');
      if (sb) sb.focus();
      return;
    }
    if (t.closest('[data-flux-prev]')) { st.page--; render(); qs('[data-flux-heading]').focus(); return; }
    if (t.closest('[data-flux-next]')) { st.page++; render(); qs('[data-flux-heading]').focus(); return; }
    if (t.closest('[data-flux-retry]')) { load(true); return; }
  });

  // The searches and the employers: ask again only when the request differs.
  document.addEventListener('posturi:searches-changed', function () { load(false); });
  document.addEventListener('posturi:employers-changed', function () { load(false); });
  // A posting hidden or restored (here, or in another tab): redraw without a new request.
  document.addEventListener('posturi:prefs-changed', function () {
    if (!st.data) return;
    var g = gather();
    if (hiddenSig(g.hidden) !== st.hiddenSig) render(g);
  });
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) load(false); });

  load(false);
})();
