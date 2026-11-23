/*
 * UX-FOLLOW-EMP — followed employers in the browser.
 *
 * A reader follows an employer from /angajator/{slug}/. The list lives in
 * localStorage under `posturi.employers.v1` and nowhere else: the server never
 * knows it. It is separate from the saved searches (`posturi.searches.v1`, cap
 * 4) and from the saved postings (`posturi.preferences.v1`): a fault here must
 * not touch them, so the three stores share no state and no code.
 *
 * The file draws two things: the „Urmărește angajatorul” toggle on the employer
 * page, and the „Angajatori urmăriți” section on /salvate/ (Liste).
 * Each write fires `posturi:employers-changed` on document. saved.js listens,
 * to keep the navbar count right; flux.js listens too.
 *
 * The cap is 10. A full list refuses a new entry; it never drops an old one.
 */
(function () {
  'use strict';

  var KEY = 'posturi.employers.v1';
  var VERSION = 1;
  var LIMIT = 10;
  var NAME_MAX = 200;
  var SLUG_RE = /^[A-Za-z0-9._~-]{1,200}$/;
  var ISO_RE = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/;
  var CHANGED = 'posturi:employers-changed';
  var LIMIT_TEXT = 'Urmărești deja ' + LIMIT + ' angajatori. Șterge unul din Salvate, apoi încearcă din nou.';
  var MONTHS = ['ianuarie', 'februarie', 'martie', 'aprilie', 'mai', 'iunie', 'iulie', 'august',
                'septembrie', 'octombrie', 'noiembrie', 'decembrie'];

  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function enc(s) { return encodeURIComponent(s); }
  function isIso(s) { return typeof s === 'string' && ISO_RE.test(s) && !isNaN(Date.parse(s)); }

  // ---- storage ---------------------------------------------------------------
  function validRecord(v) {
    if (!v || typeof v !== 'object' || Array.isArray(v)) return null;
    if (typeof v.slug !== 'string' || !SLUG_RE.test(v.slug)) return null;
    if (typeof v.name !== 'string' || v.name.trim() === '') return null;
    if (!isIso(v.addedAt)) return null;
    return { slug: v.slug, name: v.name.trim().slice(0, NAME_MAX), addedAt: v.addedAt };
  }

  /**
   * Read the store. status is 'ok', 'unavailable' (no storage), 'corrupt' or 'version'.
   * Only 'ok' allows a write, so unreadable data is never replaced.
   */
  function load() {
    var raw;
    try { raw = window.localStorage.getItem(KEY); }
    catch (e) { return { status: 'unavailable' }; }
    var st = { status: 'ok', recs: [], invalid: [] };
    if (raw === null) return st;
    var data;
    try { data = JSON.parse(raw); } catch (e) { return { status: 'corrupt' }; }
    if (!data || typeof data !== 'object' || Array.isArray(data) || typeof data.version !== 'number') return { status: 'corrupt' };
    if (data.version !== VERSION) return { status: 'version' };
    if (!Array.isArray(data.employers)) return { status: 'corrupt' };
    var seen = Object.create(null);
    data.employers.forEach(function (v) {
      var rec = validRecord(v);
      if (!rec) st.invalid.push(v);
      else if (!seen[rec.slug]) { seen[rec.slug] = 1; st.recs.push(rec); }
    });
    return st;
  }

  /** Write and read back. Returns true only when the value persisted. */
  function write(st) {
    var payload = JSON.stringify({ version: VERSION, employers: st.invalid.concat(st.recs) });
    try {
      window.localStorage.setItem(KEY, payload);
      return window.localStorage.getItem(KEY) === payload;
    } catch (e) { return false; }
  }

  function copy(r) { return r ? { slug: r.slug, name: r.name, addedAt: r.addedAt } : null; }

  function changed() {
    try { document.dispatchEvent(new Event(CHANGED)); } catch (e) { /* ignore */ }
  }

  function mutate(fn) {
    var st = load();
    if (st.status !== 'ok') return { error: 'unavailable' };
    var err = fn(st);
    if (err) return { error: err };
    if (!write(st)) return { error: 'write' };
    changed();
    return { ok: true };
  }

  // ---- public API ------------------------------------------------------------
  var API = {
    limit: LIMIT,
    limitText: LIMIT_TEXT,
    status: function () { return load().status; },
    /** Newest first. */
    list: function () {
      var st = load();
      if (st.status !== 'ok') return [];
      return st.recs.map(copy).sort(function (a, b) { return Date.parse(b.addedAt) - Date.parse(a.addedAt) || (a.slug < b.slug ? -1 : 1); });
    },
    has: function (slug) {
      var st = load();
      return st.status === 'ok' && st.recs.some(function (r) { return r.slug === slug; });
    },
    /** Result: {ok} or {error: 'unavailable'|'invalid'|'limit'|'write'}. A full list refuses; it never drops an entry. */
    add: function (slug, name) {
      return mutate(function (st) {
        if (typeof slug !== 'string' || !SLUG_RE.test(slug)) return 'invalid';
        var label = String(name || '').trim().slice(0, NAME_MAX);
        if (!label) return 'invalid';
        if (st.recs.some(function (r) { return r.slug === slug; })) return null;
        if (st.recs.length >= LIMIT) return 'limit';
        st.recs.push({ slug: slug, name: label, addedAt: new Date().toISOString() });
        return null;
      });
    },
    remove: function (slug) {
      return mutate(function (st) {
        st.recs = st.recs.filter(function (r) { return r.slug !== slug; });
        return null;
      });
    },
    removeAll: function () {
      return mutate(function (st) { st.recs = []; return null; });
    },
    /** The subscribe-form link of an employer: the key is `employer=<slug>` (search_key() of that one filter). */
    alertHref: function (rec) {
      return '/alerte/abonare/?cheie=' + enc('employer=' + enc(rec.slug)) + '&sort=&nume=' + enc(rec.name);
    },
    render: function () { renderPanel(); }
  };
  window.PosturiEmployers = API;

  // ---- employer page: the toggle ----------------------------------------------
  function syncToggle() {
    var btn = qs('[data-employer-follow]');
    if (!btn) return;
    var st = load();
    var wrap = btn.closest('[data-employer-follow-wrap]') || btn;
    wrap.hidden = st.status !== 'ok';
    if (st.status !== 'ok') return;
    var on = API.has(btn.getAttribute('data-slug'));
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    qs('[data-employer-follow-label]', btn).textContent = on ? 'Nu mai urmări' : 'Urmărește angajatorul';
  }

  function say(text, limit) {
    var m = qs('[data-employer-msg]');
    if (m) m.textContent = text;
    var box = qs('[data-employer-limit]');
    if (box) box.hidden = !limit;
  }

  function onToggle(btn) {
    var slug = btn.getAttribute('data-slug');
    var res;
    if (btn.getAttribute('aria-pressed') === 'true') {
      res = API.remove(slug);
      say(res.ok ? 'Angajatorul nu mai este urmărit.' : 'Nu am putut șterge angajatorul din acest browser.', false);
    } else {
      res = API.add(slug, btn.getAttribute('data-name'));
      if (res.ok) say('Urmărești acest angajator. Îl găsești în Salvate.', false);
      else if (res.error === 'limit') say('', true);
      else say('Nu am putut salva angajatorul în acest browser.', false);
    }
    syncToggle();
  }

  // ---- /salvate/ : the "Angajatori urmăriți" section ----------------------------
  var confirming = false;

  function fmtDate(iso) {
    var d = new Date(iso);
    return d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear();
  }

  function announce(text) {
    var el = qs('[data-employers-status]');
    if (el) el.textContent = text;
  }

  function renderPanel() {
    var root = qs('[data-saved-page]');
    var panel = root && qs('[data-employers-panel]', root);
    if (!panel) return;
    var st = load();
    var list = API.list();
    var count = qs('[data-count="employers"]', root);
    if (count) count.textContent = st.status === 'ok' ? '(' + list.length + ')' : '';
    var clearBtn = qs('[data-clear-employers]', root);
    if (clearBtn) clearBtn.disabled = list.length === 0;
    var ul = qs('[data-employers-list]', panel);
    while (ul.firstChild) ul.removeChild(ul.firstChild);
    var empty = qs('[data-employers-empty]', panel);
    empty.hidden = st.status === 'ok' && list.length > 0;
    qs('[data-employers-empty-text]', panel).textContent = st.status === 'ok'
      ? 'Nu urmărești niciun angajator. Deschide pagina unui angajator și apasă «Urmărește angajatorul».'
      : 'Angajatorii urmăriți nu pot fi citiți din acest browser.';
    var tpl = document.getElementById('employer-item-tpl');
    // Written by /alerte/abonare/ after a sent request (UX-14), keyed by the search key. A local hint, not proof.
    var alertsAsked = {};
    try {
      var a = JSON.parse(window.localStorage.getItem('posturi.alerts.v1') || '{}');
      if (a && typeof a === 'object' && !Array.isArray(a)) alertsAsked = a;
    } catch (e) {}
    list.forEach(function (rec) {
      var li = tpl.content.firstElementChild.cloneNode(true);
      li.setAttribute('data-employer-slug', rec.slug);
      var link = qs('[data-f="link"]', li);
      link.textContent = rec.name;
      link.setAttribute('href', '/angajator/' + enc(rec.slug) + '/');
      qs('[data-f="meta"]', li).textContent = 'urmărit din ' + fmtDate(rec.addedAt);
      qs('[data-f="rss"]', li).setAttribute('href', '/posturi.rss?employer=' + enc(rec.slug));
      qs('[data-f="alerts"]', li).setAttribute('href', API.alertHref(rec));
      var asked = alertsAsked['employer=' + enc(rec.slug)];
      qs('[data-f="alert-asked"]', li).hidden = !asked;
      qs('[data-f="alert-asked-date"]', li).textContent = asked && !isNaN(Date.parse(asked)) ? fmtDate(asked) : '';
      qs('[data-f="sr-unfollow"]', li).textContent = ': ' + rec.name;
      qs('[data-f="sr-alerts"]', li).textContent = ': ' + rec.name;
      ul.appendChild(li);
    });
  }

  function clearConfirm(show) {
    var box = qs('[data-employers-confirm]');
    if (!box) return;
    confirming = show;
    box.hidden = !show;
    if (show) {
      var n = API.list().length;
      qs('[data-employers-confirm-text]', box).textContent =
        (n === 1 ? 'Ștergi angajatorul urmărit' : 'Ștergi cei ' + n + ' angajatori urmăriți') +
        ' din acest browser? Căutările și anunțurile salvate nu se schimbă.';
      qs('[data-employers-no]', box).focus();
    } else {
      var origin = qs('[data-clear-employers]');
      if (origin && !origin.disabled) origin.focus();
    }
  }

  // ---- wiring (delegated, registered once) -------------------------------------
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!(t instanceof Element)) return;
    var b;
    if ((b = t.closest('[data-employer-follow]'))) { ev.preventDefault(); onToggle(b); return; }
    if ((b = t.closest('[data-employer-unfollow]'))) {
      var li = b.closest('[data-employer-slug]');
      var res = API.remove(li.getAttribute('data-employer-slug'));
      announce(res.ok ? 'Angajatorul nu mai este urmărit.' : 'Nu am putut șterge angajatorul.');
      renderPanel();
      var panel = qs('[data-employers-panel]');
      var next = panel && (qs('[data-employer-unfollow]', panel) || qs('[data-employers-heading]', panel));
      if (next) next.focus();
      return;
    }
    if (t.closest('[data-clear-employers]')) { clearConfirm(true); return; }
    if (t.closest('[data-employers-yes]')) {
      var r = API.removeAll();
      announce(r.ok ? 'Toți angajatorii urmăriți au fost șterși.' : 'Nu am putut șterge angajatorii.');
      clearConfirm(false);
      renderPanel();
      return;
    }
    if (t.closest('[data-employers-no]')) { clearConfirm(false); return; }
  });

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && confirming) clearConfirm(false);
  });

  function syncAll() { syncToggle(); renderPanel(); }

  window.addEventListener('pageshow', function (ev) { if (ev.persisted) syncAll(); });
  window.addEventListener('storage', function (ev) {
    if (ev.key === KEY || ev.key === null) { syncAll(); changed(); }
  });

  syncAll();
  changed();       // saved.js may have drawn the navbar count before this script ran
})();
