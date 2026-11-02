/*
 * UX-15 — saved searches in the browser.
 *
 * A saved search is a filter combination of the list page. It lives in
 * localStorage under `posturi.searches.v1` and nowhere else: the server never
 * knows it (HTML pages are cached publicly). The identity rule is ADR 0001
 * (docs/decisions/0001-saved-search-identity.md); searchKey() below and
 * search_key() in helpers.php implement it, and both test suites read
 * tests/fixtures/search_keys.json.
 *
 * This store is separate from saved.js (`posturi.preferences.v1`). A fault here
 * must not touch saved jobs, so the two share no state and no code.
 *
 * The same file draws three things: the "Salvează căutarea" toggle and the
 * notice on the list page, and the "Căutări salvate" section on /salvate/.
 * Each write fires `posturi:searches-changed` on document; saved.js listens, to
 * keep the navbar count (saved jobs + saved searches) right.
 */
(function () {
  'use strict';

  var KEY = 'posturi.searches.v1';
  var VERSION = 1;
  var LIMIT = 4;
  var SORTS = ['', 'deadline', 'employer', 'salary'];
  var APPLIED = 'posturi.searchApplied';
  var LABEL_MAX = 80;       // default label, cut from the chips
  var LABEL_STORE_MAX = 200;
  var LIMIT_TEXT = 'Ai atins limita de ' + LIMIT + ' căutări salvate. Șterge una din Salvate, apoi încearcă din nou.';
  var CHANGED = 'posturi:searches-changed';
  var KEY_RE = /^[A-Za-z0-9_.~!'()*%=&-]+$/;
  var ISO_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/;

  /** Keep in step with MULTI_PARAMS in helpers.php (tests/searches_test.php checks it). */
  var MULTI_PARAMS = [
    'judet', 'level', 'type', 'categorie', 'employer_cat',
    'family', 'seniority', 'work_type', 'exp_level', 'studies_level', 'anomaly',
    'isced', 'skill', 'lang', 'credential', 'domain', 'stage', 'eqf',
    'occupation', 'funding', 'sector', 'duration', 'schedule', 'loc', 'recruitment',
    'regime', 'inst', 'cor', 'medspec',
    'family_not', 'occupation_not', 'inst_not', 'sector_not', 'regime_not', 'funding_not',
    'duration_not', 'isced_not', 'skill_not', 'lang_not', 'credential_not', 'domain_not', 'cor_not',
    'studies_level_not', 'exp_level_not'
  ];
  var DEFAULT_STATUS = 'active';
  var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august',
                'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function isIso(s) { return typeof s === 'string' && ISO_RE.test(s) && !isNaN(Date.parse(s)); }
  function enc(s) { return encodeURIComponent(s); }

  // ---- identity (ADR 0001) ---------------------------------------------------
  /** The search key of a `location.search` string. "" means no filters. */
  function searchKey(search) {
    var params = new URLSearchParams(search || '');
    var groups = Object.create(null);     // name -> {multi, values}
    var names = [];
    params.forEach(function (value, rawName) {
      var name = rawName;
      var bracket = name.slice(-2) === '[]';
      if (bracket) name = name.slice(0, -2);
      if (name === 'page' || name === 'sort' || name === 'title') return;
      if (!groups[name]) { groups[name] = { multi: false, values: [] }; names.push(name); }
      if (bracket || MULTI_PARAMS.indexOf(name) !== -1) groups[name].multi = true;
      groups[name].values.push(value);
    });
    var parts = [];
    names.sort().forEach(function (name) {
      var g = groups[name];
      if (g.values.every(function (v) { return v.trim() === ''; })) return;
      if (g.multi) {
        var seen = Object.create(null);
        g.values.filter(function (v) { if (v.trim() === '' || seen[v]) return false; seen[v] = 1; return true; }).sort()
          .forEach(function (v) { parts.push(enc(name) + '%5B%5D=' + enc(v)); });
      } else {
        var last = g.values[g.values.length - 1];
        if (name === 'status' && last === DEFAULT_STATUS) return;
        parts.push(enc(name) + '=' + enc(last));
      }
    });
    return parts.join('&');
  }

  // ---- storage ---------------------------------------------------------------
  function validRecord(key, v) {
    if (!v || typeof v !== 'object' || Array.isArray(v)) return null;
    if (typeof key !== 'string' || key.length > 1500 || !KEY_RE.test(key)) return null;
    if (v.key !== key || v.qs !== key) return null;
    var sort = v.sort == null ? '' : v.sort;
    if (SORTS.indexOf(sort) === -1) return null;
    if (typeof v.label !== 'string' || v.label.trim() === '') return null;
    if (!isIso(v.savedAt)) return null;
    if (v.openedAt != null && !isIso(v.openedAt)) return null;
    return {
      key: key, qs: key, sort: sort, label: v.label.trim().slice(0, LABEL_STORE_MAX),
      savedAt: v.savedAt, openedAt: v.openedAt || null
    };
  }

  /**
   * Read the store. status is 'ok', 'unavailable' (no storage), 'corrupt' or
   * 'version'. Only 'ok' allows a write, so unreadable data is never replaced.
   */
  function load() {
    var raw;
    try { raw = window.localStorage.getItem(KEY); }
    catch (e) { return { status: 'unavailable' }; }
    var st = { status: 'ok', recs: Object.create(null), invalid: Object.create(null), defaultId: null };
    if (raw === null) return st;
    var data;
    try { data = JSON.parse(raw); } catch (e) { return { status: 'corrupt' }; }
    if (!data || typeof data !== 'object' || Array.isArray(data) || typeof data.version !== 'number') return { status: 'corrupt' };
    if (data.version !== VERSION) return { status: 'version' };
    var s = data.searches;
    if (!s || typeof s !== 'object' || Array.isArray(s)) return { status: 'corrupt' };
    Object.keys(s).forEach(function (k) {
      var rec = validRecord(k, s[k]);
      if (rec) st.recs[k] = rec; else st.invalid[k] = s[k];
    });
    st.defaultId = typeof data.defaultId === 'string' ? data.defaultId : null;
    return st;
  }

  /** Write and read back. Returns true only when the value persisted. */
  function write(st) {
    var out = Object.create(null);
    Object.keys(st.invalid).forEach(function (k) { out[k] = st.invalid[k]; });
    Object.keys(st.recs).forEach(function (k) { out[k] = st.recs[k]; });
    var payload = JSON.stringify({ version: VERSION, defaultId: st.defaultId, searches: out });
    try {
      window.localStorage.setItem(KEY, payload);
      return window.localStorage.getItem(KEY) === payload;
    } catch (e) { return false; }
  }

  function newest(a, b) {
    return (Date.parse(b.savedAt) - Date.parse(a.savedAt)) || (a.key < b.key ? -1 : 1);
  }

  function copy(r) { return r ? { key: r.key, qs: r.qs, sort: r.sort, label: r.label, savedAt: r.savedAt, openedAt: r.openedAt } : null; }

  function mutate(fn) {
    var st = load();
    if (st.status !== 'ok') return { error: 'unavailable' };
    var err = fn(st);
    if (err) return { error: err };
    if (!write(st)) return { error: 'write' };
    changed();
    return { ok: true };
  }

  function changed() {
    try { document.dispatchEvent(new Event(CHANGED)); } catch (e) { /* ignore */ }
  }

  // ---- public API ------------------------------------------------------------
  var API = {
    searchKey: searchKey,
    limit: LIMIT,
    limitText: LIMIT_TEXT,
    status: function () { return load().status; },
    list: function () {
      var st = load();
      if (st.status !== 'ok') return [];
      return Object.keys(st.recs).map(function (k) { return copy(st.recs[k]); }).sort(newest);
    },
    get: function (key) {
      var st = load();
      return st.status === 'ok' ? copy(st.recs[key]) : null;
    },
    /** opts: {sort, label}. Result: {ok, record} or {error: 'unavailable'|'empty'|'limit'|'write'}. */
    save: function (key, opts) {
      opts = opts || {};
      var rec;
      var res = mutate(function (st) {
        if (!key || !KEY_RE.test(key)) return 'empty';
        if (st.recs[key]) { rec = st.recs[key]; return null; }
        if (Object.keys(st.recs).length >= LIMIT) return 'limit';
        var label = String(opts.label || '').trim().slice(0, LABEL_STORE_MAX) || 'Căutare salvată';
        var sort = SORTS.indexOf(opts.sort || '') === -1 ? '' : (opts.sort || '');
        rec = st.recs[key] = { key: key, qs: key, sort: sort, label: label, savedAt: new Date().toISOString(), openedAt: null };
        return null;
      });
      if (res.ok) res.record = copy(rec);
      return res;
    },
    remove: function (key) {
      return mutate(function (st) {
        delete st.recs[key];
        if (st.defaultId === key) st.defaultId = null;
        return null;
      });
    },
    removeAll: function () {
      return mutate(function (st) { st.recs = Object.create(null); st.defaultId = null; return null; });
    },
    rename: function (key, label) {
      return mutate(function (st) {
        var text = String(label || '').trim().slice(0, LABEL_STORE_MAX);
        if (!st.recs[key]) return 'missing';
        if (!text) return 'empty';
        st.recs[key].label = text;
        return null;
      });
    },
    /** `defaultId` if that record exists, else the newest `savedAt`. */
    defaultSearch: function () {
      var st = load();
      if (st.status !== 'ok') return null;
      if (st.defaultId && st.recs[st.defaultId]) return copy(st.recs[st.defaultId]);
      var all = Object.keys(st.recs).map(function (k) { return st.recs[k]; }).sort(newest);
      return copy(all[0]);
    },
    markOpened: function (key) {
      return mutate(function (st) {
        if (!st.recs[key]) return 'missing';
        st.recs[key].openedAt = new Date().toISOString();
        return null;
      });
    },
    sync: function () { syncList(); },
    render: function () { renderSavedPage(); }
  };
  window.PosturiSearches = API;

  function openHref(rec) {
    return '/?' + rec.qs + (rec.sort ? '&sort=' + enc(rec.sort) : '');
  }

  function sessionGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
  function sessionDel(k) { try { window.sessionStorage.removeItem(k); } catch (e) { /* ignore */ } }

  // ---- list page: toggle and notice -------------------------------------------
  /** The label from the active-filter chips; the zero-result relaxation chips sit outside this container. */
  function defaultLabel() {
    var box = qs('[data-active-chips]');
    var parts = box ? qsa('[data-chip-param] .font-medium', box).map(function (n) { return n.textContent.trim(); }).filter(Boolean) : [];
    var text = parts.join(' · ');
    if (text.length > LABEL_MAX) text = text.slice(0, LABEL_MAX).trim();
    if (text) return text;
    var d = new Date();
    return 'Căutare din ' + d.getDate() + ' ' + MONTHS[d.getMonth()];
  }

  function currentSort() {
    var s = new URLSearchParams(window.location.search).get('sort') || '';
    return SORTS.indexOf(s) === -1 ? '' : s;
  }

  var noticeShownFor = null;

  function syncList() {
    var bar = qs('[data-search-bar]');
    if (!bar) return;
    var btn = qs('[data-search-toggle]', bar);
    var key = searchKey(window.location.search);
    var st = load();
    var usable = st.status === 'ok' && key !== '';
    bar.hidden = !usable;
    btn.hidden = !usable;
    if (usable) {
      var saved = !!st.recs[key];
      btn.setAttribute('aria-pressed', saved ? 'true' : 'false');
      qs('[data-search-toggle-label]', btn).textContent = saved ? 'Căutare salvată' : 'Salvează căutarea';
    }
    syncNotice(st, key);
  }

  function syncNotice(st, key) {
    var box = qs('[data-search-notice]');
    if (!box) return;
    var applied = sessionGet(APPLIED);
    var rec = st.status === 'ok' && key ? st.recs[key] : null;
    if (applied && applied === key && rec) {
      qs('[data-search-notice-label]', box).textContent = rec.label;
      box.hidden = false;
      if (noticeShownFor !== key) { noticeShownFor = key; API.markOpened(key); }
      return;
    }
    box.hidden = true;
    noticeShownFor = null;
    if (applied) sessionDel(APPLIED);          // the reader moved to other filters
  }

  function say(text) {
    var m = qs('[data-search-msg]');
    if (m) m.textContent = text;
  }

  function onToggle() {
    var key = searchKey(window.location.search);
    if (!key) return;
    var btn = qs('[data-search-toggle]');
    var res;
    if (btn.getAttribute('aria-pressed') === 'true') {
      res = API.remove(key);
      say(res.ok ? 'Căutarea a fost ștearsă din salvate.' : 'Nu am putut șterge căutarea din acest browser.');
    } else {
      res = API.save(key, { sort: currentSort(), label: defaultLabel() });
      if (res.error === 'limit' && window.PosturiSaved && window.PosturiSaved.showNotice) {
        say('');
        window.PosturiSaved.showNotice({ text: LIMIT_TEXT, link: true, focus: true });
      } else if (res.ok && window.PosturiSaved && window.PosturiSaved.showNotice) {
        say('');
        window.PosturiSaved.showNotice({ text: 'Căutare salvată: ' + defaultLabel() + '.', link: true });
      } else {
        say(res.ok ? 'Căutarea a fost salvată.'
          : res.error === 'limit' ? LIMIT_TEXT
          : 'Nu am putut salva căutarea în acest browser.');
      }
    }
    syncList();
  }

  // ---- /salvate/ : the "Căutări salvate" section ------------------------------------------
  var editing = null;      // key of the row in rename mode
  var confirming = false;

  function fmtDate(iso) {
    var d = new Date(iso);
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
  }

  function announce(text) {
    var el = qs('[data-searches-status]');
    if (el) el.textContent = text;
  }

  function renderSavedPage() {
    var root = qs('[data-saved-page]');
    var panel = root && qs('[data-searches-panel]', root);
    if (!panel) return;
    var st = load();
    // Shown in the default view only; ?vedere=cautari (old links) is the default view.
    var v = new URLSearchParams(window.location.search).get('vedere');
    var view = v !== 'ascunse';   // ?vedere=notite (old links) shows the one page
    var list = API.list();
    var count = qs('[data-count="searches"]', root);
    if (count) count.textContent = st.status === 'ok' ? '(' + list.length + ')' : '';
    panel.hidden = !view;
    var clearBtn = qs('[data-clear-searches]', root);
    if (clearBtn) clearBtn.disabled = list.length === 0;
    if (!view) return;

    var ul = qs('[data-searches-list]', panel);
    while (ul.firstChild) ul.removeChild(ul.firstChild);
    var empty = qs('[data-searches-empty]', panel);
    empty.hidden = st.status === 'ok' && list.length > 0;
    qs('[data-searches-empty-text]', panel).textContent = st.status === 'ok'
      ? 'Nu ai căutări salvate. Filtrează lista și apasă «Salvează căutarea».'
      : 'Căutările salvate nu pot fi citite din acest browser.';
    var def = API.defaultSearch();
    var tpl = document.getElementById('search-item-tpl');
    list.forEach(function (rec) {
      var li = tpl.content.firstElementChild.cloneNode(true);
      li.setAttribute('data-search-key', rec.key);
      var link = qs('[data-f="link"]', li);
      var form = qs('[data-f="rename-form"]', li);
      link.textContent = rec.label;
      link.setAttribute('href', openHref(rec));
      qs('[data-f="meta"]', li).textContent = 'salvată ' + fmtDate(rec.savedAt);
      qs('[data-f="default"]', li).hidden = !(def && def.key === rec.key);
      qs('[data-f="rss"]', li).setAttribute('href', '/posturi.rss?' + rec.qs);
      var isEdit = editing === rec.key;
      link.hidden = isEdit;
      form.hidden = !isEdit;
      qs('[data-search-rename]', li).hidden = isEdit;
      if (isEdit) qs('input', form).value = rec.label;
      qs('[data-f="sr-rename"]', li).textContent = ': ' + rec.label;
      qs('[data-f="sr-delete"]', li).textContent = ': ' + rec.label;
      ul.appendChild(li);
    });
    if (editing) {
      var input = qs('[data-search-key="' + (window.CSS && CSS.escape ? CSS.escape(editing) : editing) + '"] input', ul);
      if (input && document.activeElement !== input) { input.focus(); input.select(); }
    }
  }

  function rowKey(el) {
    var li = el.closest('[data-search-key]');
    return li ? li.getAttribute('data-search-key') : null;
  }

  function finishRename(li, save) {
    var key = li.getAttribute('data-search-key');
    var value = qs('input', li).value;
    editing = null;
    if (save) {
      var res = API.rename(key, value);
      announce(res.ok ? 'Căutarea a fost redenumită.' : 'Nu am putut redenumi căutarea.');
    }
    renderSavedPage();
    var again = qs('[data-search-key="' + (window.CSS && CSS.escape ? CSS.escape(key) : key) + '"] [data-search-rename]');
    if (again) again.focus();
  }

  function clearConfirm(show) {
    var box = qs('[data-searches-confirm]');
    if (!box) return;
    confirming = show;
    box.hidden = !show;
    if (show) {
      qs('[data-searches-confirm-text]', box).textContent =
        'Ștergi cele ' + API.list().length + ' căutări salvate din acest browser? Anunțurile salvate nu se schimbă.';
      qs('[data-searches-no]', box).focus();
    } else {
      var origin = qs('[data-clear-searches]');
      if (origin && !origin.disabled) origin.focus();
    }
  }

  // ---- wiring (delegated, registered once) -------------------------------------
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!(t instanceof Element)) return;
    var b;
    if (t.closest('[data-search-toggle]')) { ev.preventDefault(); onToggle(); return; }
    if ((b = t.closest('[data-search-open]'))) {
      var k0 = rowKey(b);
      if (k0) API.markOpened(k0);
      return;
    }
    if ((b = t.closest('[data-search-rename]'))) {
      editing = rowKey(b);
      renderSavedPage();
      return;
    }
    if ((b = t.closest('[data-search-rename-save]'))) { finishRename(b.closest('[data-search-key]'), true); return; }
    if ((b = t.closest('[data-search-rename-cancel]'))) { finishRename(b.closest('[data-search-key]'), false); return; }
    if ((b = t.closest('[data-search-delete]'))) {
      var k = rowKey(b);
      var res = API.remove(k);
      announce(res.ok ? 'Căutarea a fost ștearsă.' : 'Nu am putut șterge căutarea.');
      renderSavedPage();
      var panel = qs('[data-searches-panel]');
      var next = panel && (qs('[data-search-open]', panel) || qs('[data-searches-heading]', panel));
      if (next) next.focus();
      return;
    }
    if (t.closest('[data-clear-searches]')) { clearConfirm(true); return; }
    if (t.closest('[data-searches-yes]')) {
      var r = API.removeAll();
      announce(r.ok ? 'Toate căutările salvate au fost șterse.' : 'Nu am putut șterge căutările.');
      clearConfirm(false);
      renderSavedPage();
      return;
    }
    if (t.closest('[data-searches-no]')) { clearConfirm(false); return; }
  });

  document.addEventListener('keydown', function (ev) {
    var form = ev.target instanceof Element && ev.target.closest('[data-f="rename-form"]');
    if (form) {
      var li = form.closest('[data-search-key]');
      if (ev.key === 'Enter') { ev.preventDefault(); finishRename(li, true); return; }
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); finishRename(li, false); return; }
    }
    if (ev.key === 'Escape' && confirming) clearConfirm(false);
  });

  function syncAll() { syncList(); renderSavedPage(); }

  // The facet drawer and history restores replace DOM; state is re-read each time.
  document.addEventListener('htmx:historyRestore', syncAll);
  window.addEventListener('popstate', syncAll);
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) syncAll(); });
  window.addEventListener('storage', function (ev) { if (ev.key === KEY || ev.key === null) syncAll(); });

  window.addEventListener('storage', function (ev) { if (ev.key === KEY || ev.key === null) changed(); });

  syncAll();
  changed();       // saved.js may have drawn the navbar count before this script ran
})();
