/* UX-09 — save and hide announcements without an account.
 *
 * Everything lives in this browser: one localStorage key, `posturi.preferences.v1`,
 * holds {version, records}, with records keyed by the OFFICIAL source URL (the
 * identity; the numeric id is only a lookup hint). Nothing here changes what the
 * server returns — result lists, facet counts and feeds keep their server meaning;
 * hidden rows are hidden locally, after the server has rendered them.
 *
 * One script, loaded once by the shared layout. Handlers are delegated on
 * `document`, so they survive HTMX swaps and history restores, and the state is
 * re-applied after each of those. Snapshot text only ever reaches the page
 * through textContent / setAttribute; links are accepted only when they point at
 * https://posturi.gov.ro (source) or the local job route (path).
 *
 * Notes (2026-10-17): a record may carry a reader's `note` (plain text, ≤ 4000
 * characters) and `notedAt`. A posting with a note counts as saved — it shows in
 * Salvate whether or not its bookmark is on — and /salvate/ exports saved and
 * noted postings as CSV, for feedback on the extraction before the next LLM run.
 */
(function () {
  'use strict';
  if (window.__posturiSaved) return;            // never register the listeners twice
  window.__posturiSaved = { key: 'posturi.preferences.v1', version: 1 };

  var KEY = 'posturi.preferences.v1';
  var VERSION = 1;
  var MAX = 500;
  var PAGE = 25;
  var ENDPOINT = '/preferinte-posturi.json';
  var FALLBACK_NOTE = 'Expirarea anunțului; înscriere neconfirmată';
  var NOTE_MAX = 4000;
  var STATUS_RO = {
    confirmed_open: 'Înscrieri deschise',
    unconfirmed: 'Termen neconfirmat',
    unknown: 'Termen neprecizat',
    closed: 'Înscrieri închise',
    // UX-03: answered from archived_postings for 90 days after a posting left the list.
    expired: 'Anunț expirat · înscrierile s-au încheiat',
    cancelled: 'Anunț anulat de instituție'
  };
  var SESSION_ONLY = 'Disponibil doar în această sesiune; browserul nu a putut salva preferințele.';

  // ---- state ---------------------------------------------------------------
  var S = {
    recs: Object.create(null),     // valid, retained records by source URL
    invalid: Object.create(null),  // entries that failed validation; written back untouched
    blocked: null,                 // null | 'corrupt' | 'version' — storage is never overwritten while set
    blockedRaw: null,
    unavailable: false,            // localStorage threw on read
    dirty: false                   // a write failed: the in-memory copy is newer than storage
  };
  var showHidden = false;          // the "Arată" toggle; session only
  var mutated = false;
  var resetPending = false;
  var noticeUndo = null;
  var SP = null;                   // /salvate/ page state
  var liveTimer = null;

  // ---- small helpers -------------------------------------------------------
  function qs(sel, root) { return (root || document).querySelector(sel); }
  function qsa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function count(o) { return Object.keys(o).length; }
  function clean(v, max) { return typeof v === 'string' ? v.slice(0, max || 300) : ''; }
  function isIso(s) { return typeof s === 'string' && s.length <= 40 && !isNaN(Date.parse(s)); }
  function plural(n) {
    var m = n % 100;
    return n === 1 ? '1 anunț ascuns' : (n + (m === 0 || m >= 20 ? ' de' : '') + ' anunțuri ascunse');
  }
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function fmtDate(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso || '');
    return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
  }
  function fmtDateTime(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ', ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  }

  /** Source links: https on posturi.gov.ro only, no credentials or port. */
  function safeSource(u) {
    if (typeof u !== 'string' || u.length > 1000) return null;
    try {
      var p = new URL(u);
      if (p.protocol !== 'https:' || p.hostname !== 'posturi.gov.ro' || p.username || p.password || p.port) return null;
      return p.href;
    } catch (e) { return null; }
  }
  /** Local paths: the job route, and the id in it must be this record's id. */
  function safePath(path, id) {
    var m = typeof path === 'string' && /^\/job\/([1-9]\d{0,9})(?:-[a-z0-9-]*)?\/$/.exec(path);
    return m && Number(m[1]) === id ? path : null;
  }

  function validateRecord(key, v) {
    if (!v || typeof v !== 'object' || Array.isArray(v)) return null;
    if (!Number.isInteger(v.id) || v.id < 1) return null;
    if (typeof v.url !== 'string' || v.url === '' || v.url.length > 1000 || v.url !== key) return null;
    if (typeof v.title !== 'string') return null;
    if (v.savedAt != null && !isIso(v.savedAt)) return null;
    if (v.hiddenAt != null && !isIso(v.hiddenAt)) return null;
    if (v.note != null && typeof v.note !== 'string') return null;
    if (v.notedAt != null && !isIso(v.notedAt)) return null;
    if (!isIso(v.snapshotAt)) return null;
    var ds = clean(v.dateSource, 20);
    return {
      id: v.id, url: v.url, path: clean(v.path, 200), title: clean(v.title),
      employer: clean(v.employer), place: clean(v.place),
      date: /^\d{4}-\d{2}-\d{2}$/.test(v.date || '') ? v.date : '',
      dateSource: (ds === 'concurs' || ds === 'anunt' || ds === 'expirare') ? ds : '',
      savedAt: v.savedAt || null, hiddenAt: v.hiddenAt || null, snapshotAt: v.snapshotAt,
      note: typeof v.note === 'string' ? v.note.slice(0, NOTE_MAX) : '', notedAt: v.note ? (v.notedAt || null) : null
    };
  }

  // ---- storage -------------------------------------------------------------
  function readStore() {
    var raw;
    try { raw = window.localStorage.getItem(KEY); S.unavailable = false; }
    catch (e) { S.unavailable = true; return; }
    if (S.dirty && !S.blocked) return;                         // our session copy is newer
    if (S.blocked && raw === S.blockedRaw) return;             // still the same bad data: keep the session copy
    S.blocked = null; S.blockedRaw = null;
    S.recs = Object.create(null); S.invalid = Object.create(null);
    if (raw === null) return;
    var data;
    try { data = JSON.parse(raw); } catch (e) { S.blocked = 'corrupt'; S.blockedRaw = raw; return; }
    if (!data || typeof data !== 'object' || Array.isArray(data) || typeof data.version !== 'number') {
      S.blocked = 'corrupt'; S.blockedRaw = raw; return;
    }
    if (data.version !== VERSION) { S.blocked = 'version'; S.blockedRaw = raw; return; }
    var r = data.records;
    if (!r || typeof r !== 'object' || Array.isArray(r)) { S.blocked = 'corrupt'; S.blockedRaw = raw; return; }
    Object.keys(r).forEach(function (k) {
      var rec = validateRecord(k, r[k]);
      if (!rec) S.invalid[k] = r[k];
      else if (kept(rec)) S.recs[k] = rec;                      // records with no flag and no note are dropped
    });
  }

  function writeStore() {
    if (S.blocked) { S.dirty = true; return false; }           // never overwrite unreadable data
    var out = Object.create(null);
    Object.keys(S.invalid).forEach(function (k) { out[k] = S.invalid[k]; });
    Object.keys(S.recs).forEach(function (k) { out[k] = S.recs[k]; });
    var payload = JSON.stringify({ version: VERSION, records: out });
    try {
      window.localStorage.setItem(KEY, payload);
      if (window.localStorage.getItem(KEY) !== payload) throw new Error('write not persisted');
    } catch (e) { S.dirty = true; return false; }
    S.dirty = false; S.unavailable = false;
    return true;
  }

  function persisted() { return !S.blocked && !S.unavailable && !S.dirty; }

  /** A record stays while it is saved, hidden, or carries a note. */
  function kept(r) { return !!(r.savedAt || r.hiddenAt || r.note); }
  /** "Saved" for every list and count: bookmarked, or noted. */
  function isSaved(r) { return !!(r.savedAt || r.note); }

  /** Re-read current storage, apply fn to it, write. fn returns an error code to abort. */
  function mutate(fn, quiet) {
    readStore();
    var err = fn(S.recs);
    if (err) return { error: err };
    Object.keys(S.recs).forEach(function (k) {
      if (!kept(S.recs[k])) delete S.recs[k];
    });
    var ok = writeStore();
    if (!quiet) mutated = true;
    return { persisted: ok };
  }

  function resetStore() {
    var fresh = JSON.stringify({ version: VERSION, records: {} });
    S.blocked = null; S.blockedRaw = null; S.recs = Object.create(null); S.invalid = Object.create(null);
    S.dirty = false;
    try { window.localStorage.setItem(KEY, fresh); S.unavailable = false; }
    catch (e) { S.dirty = true; }
    mutated = true;
  }

  // ---- announcements, notice, status ---------------------------------------
  function announce(msg) {
    var live = document.getElementById('pref-live');
    if (!live) return;
    live.textContent = '';
    clearTimeout(liveTimer);
    liveTimer = setTimeout(function () { live.textContent = msg; }, 30);
  }

  function hideNotice() {
    var n = document.getElementById('pref-notice');
    if (n) n.hidden = true;
    noticeUndo = null;
  }

  function showNotice(opts) {
    var n = document.getElementById('pref-notice');
    if (!n) return;
    qs('[data-notice-text]', n).textContent = opts.text;
    qs('[data-notice-storage]', n).hidden = !opts.storage;
    var undo = qs('[data-notice-undo]', n);
    undo.hidden = !opts.undo;
    noticeUndo = opts.undo || null;
    n.hidden = false;
    announce(opts.text);
    if (opts.focus) (opts.undo ? undo : n).focus();
  }

  function renderStatus() {
    var bar = qs('[data-pref-status]');
    if (!bar) return;
    var onPrefPage = !!qs('[data-pref-controls], [data-saved-page]');
    var savedPage = !!qs('[data-saved-page]');
    var show = false;
    while (bar.firstChild) bar.removeChild(bar.firstChild);
    function line(text) { var p = document.createElement('p'); p.textContent = text; bar.appendChild(p); return p; }

    if (S.blocked && (onPrefPage || mutated)) {
      show = true;
      line('Preferințele din acest browser nu pot fi citite (date corupte sau versiune necunoscută). Nu au fost modificate; ce faci acum rămâne doar în această sesiune.');
      var row = document.createElement('p');
      var b = document.createElement('button');
      b.type = 'button'; b.setAttribute('data-pref-reset', resetPending ? 'confirm' : 'ask');
      b.className = 'mt-1 min-h-[2rem] px-1 font-medium underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus';
      b.textContent = resetPending ? 'Confirmă: șterge preferințele salvate' : 'Resetează preferințele salvate';
      row.appendChild(b);
      if (resetPending) {
        var c = document.createElement('button');
        c.type = 'button'; c.setAttribute('data-pref-reset', 'cancel');
        c.className = b.className; c.textContent = 'Anulează';
        row.appendChild(c);
      }
      bar.appendChild(row);
    } else if ((S.unavailable || S.dirty) && (mutated || savedPage)) {
      show = true;
      line(SESSION_ONLY);
    }
    var inv = count(S.invalid);
    if (inv && !S.blocked && (onPrefPage || mutated)) {
      show = true;
      line(inv + (inv === 1 ? ' intrare invalidă a fost ignorată' : ' intrări invalide au fost ignorate') + '; rămân neatinse în browser.');
    }
    bar.hidden = !show;
  }

  // ---- applying state to rendered rows ------------------------------------
  function snapshotOf(el) {
    var d = el.dataset;
    var id = parseInt(d.prefId, 10);
    if (!(id >= 1) || !d.prefUrl) return null;
    return {
      id: id, url: d.prefUrl, path: clean(d.prefPath, 200), title: clean(d.prefTitle),
      employer: clean(d.prefEmployer), place: clean(d.prefPlace), date: clean(d.prefDate, 10),
      dateSource: clean(d.prefDateSource, 20)
    };
  }
  var SNAP_FIELDS = ['path', 'title', 'employer', 'place', 'date', 'dateSource'];

  /** Text buttons (/salvate/) carry a visible [data-pref-label]; icon buttons carry
   *  none, so the label goes to aria-label + title ("<action>: <posting title>"). */
  function setLabel(btn, text, tip) {
    if (btn.classList.contains('pref-btn')) {
      var el = btn.closest('[data-posting]');
      var t = el && el.dataset.prefTitle;
      var name = (tip || text) + (t ? ': ' + t : '');
      if (btn.getAttribute('aria-label') !== name) btn.setAttribute('aria-label', name);
      if (btn.getAttribute('title') !== (tip || text)) btn.setAttribute('title', tip || text);
      return;
    }
    var l = qs('[data-pref-label]', btn);
    if (l && l.textContent !== text) l.textContent = text;
  }

  function applyRows() {
    var diffs = [];
    qsa('[data-posting]').forEach(function (el) {
      qsa('[data-pref-controls]', el).forEach(function (c) { c.hidden = false; });
      if (el.hasAttribute('data-pref-managed')) return;      // /salvate/ items are drawn by renderSaved()
      var url = el.dataset.prefUrl;
      var rec = url ? S.recs[url] : undefined;
      var saved = !!(rec && rec.savedAt), hid = !!(rec && rec.hiddenAt);
      var sb = qs('[data-pref="save"]', el);
      if (sb) { sb.setAttribute('aria-pressed', saved ? 'true' : 'false'); setLabel(sb, saved ? 'Salvat' : 'Salvează', saved ? 'Elimină din salvate' : 'Salvează anunțul'); }
      // Hide state is shown on every posting (the detail page has one too); only
      // rows in a list (data-pref-hideable) actually disappear.
      var hbtn = qs('[data-pref="hide"]', el);
      if (hbtn) { hbtn.setAttribute('aria-pressed', hid ? 'true' : 'false'); setLabel(hbtn, hid ? 'Restabilește' : 'Ascunde', hid ? 'Restabilește anunțul' : 'Ascunde anunțul'); }
      var flag = qs('[data-pref-flag]', el);
      if (flag) flag.hidden = !hid;
      var nb = qs('[data-pref="note"]', el);
      if (nb) {
        var has = !!(rec && rec.note);
        nb.classList.toggle('pref-has-note', has);
        setLabel(nb, has ? 'Notiță' : 'Adaugă notiță', has ? 'Editează notița' : 'Adaugă o notiță');
      }
      renderNoteView(el, rec);
      if (el.hasAttribute('data-pref-hideable')) {
        el.setAttribute('data-pref-hidden', hid ? 'true' : 'false');
        el.hidden = hid && !showHidden;
      }
      if (rec) {
        var snap = snapshotOf(el);
        if (snap && snap.id === rec.id && SNAP_FIELDS.some(function (f) { return snap[f] !== rec[f]; })) diffs.push(snap);
      }
    });
    if (diffs.length && !S.blocked) refreshSnapshots(diffs);

    qsa('[data-pref-banner]').forEach(function (banner) {
      var scope = banner.parentElement;
      var rows = qsa('[data-posting][data-pref-hideable]', scope);
      var hiddenN = rows.filter(function (r) { return r.getAttribute('data-pref-hidden') === 'true'; }).length;
      var any = Object.keys(S.recs).some(function (k) { return S.recs[k].hiddenAt; });
      banner.hidden = !(any || hiddenN);
      var pageP = qs('[data-pref-banner-page]', banner);
      pageP.hidden = hiddenN === 0;
      qs('[data-pref-banner-count]', banner).textContent = plural(hiddenN);
      var tg = qs('[data-pref-toggle-hidden]', banner);
      tg.setAttribute('aria-pressed', showHidden ? 'true' : 'false');
      qs('[data-pref-toggle-label]', tg).textContent = showHidden ? 'Ascunde din nou' : 'Arată';
      qs('[data-pref-banner-all]', banner).hidden = !(rows.length > 0 && hiddenN === rows.length && !showHidden);
    });
  }

  /** Verified identity, new path/title/date: update the snapshot, keep the flags. */
  function refreshSnapshots(list) {
    mutate(function (recs) {
      var now = new Date().toISOString();
      list.forEach(function (s) {
        var rec = recs[s.url];
        if (!rec || rec.id !== s.id) return;
        SNAP_FIELDS.forEach(function (f) { rec[f] = s[f]; });
        rec.snapshotAt = now;
      });
    }, true);
  }

  function renderNav() {
    var n = qs('[data-saved-count]');
    if (!n) return;
    var saved = Object.keys(S.recs).filter(function (k) { return isSaved(S.recs[k]); }).length;
    n.textContent = '(' + saved + ')';
    n.hidden = !!S.blocked && saved === 0;
  }

  function applyAll() {
    applyRows();
    renderNav();
    renderStatus();
    if (SP) renderSaved();
  }

  function syncFromStorage() { readStore(); applyAll(); }

  // ---- toggling ------------------------------------------------------------
  function toggle(el, kind) {
    var url = el.dataset.prefUrl;
    var field = kind === 'save' ? 'savedAt' : 'hiddenAt';
    var out = { field: field, url: url, first: false, before: null, copy: null, after: null };
    var res = mutate(function (recs) {
      var rec = recs[url];
      var now = new Date().toISOString();
      if (!rec) {
        if (count(recs) >= MAX) return 'limit';
        var snap = snapshotOf(el);
        var v = snap && validateRecord(url, { id: snap.id, url: url, title: snap.title, snapshotAt: now });
        if (!v) return 'invalid';
        out.first = count(recs) === 0;
        snap.savedAt = null; snap.hiddenAt = null; snap.snapshotAt = now;
        rec = recs[url] = snap;
        out.copy = JSON.parse(JSON.stringify(rec));
      } else {
        out.copy = JSON.parse(JSON.stringify(rec));
      }
      out.before = rec[field];
      rec[field] = rec[field] ? null : now;
      out.after = rec[field];
    });
    out.error = res.error; out.persisted = res.persisted;
    return out;
  }

  function undoTo(info) {
    mutate(function (recs) {
      var rec = recs[info.url] || (info.copy ? JSON.parse(JSON.stringify(info.copy)) : null);
      if (!rec) return 'invalid';
      rec[info.field] = info.before;
      recs[info.url] = rec;
    });
    hideNotice();
    applyAll();
    announce('Acțiunea a fost anulată.');
    var back = qsa('[data-posting]').filter(function (e) { return e.dataset.prefUrl === info.url && !e.hidden; })[0];
    var btn = back && qs('[data-pref="' + (info.field === 'hiddenAt' ? 'hide' : 'save') + '"]', back);
    if (btn) btn.focus();
  }

  function onPref(btn) {
    var el = btn.closest('[data-posting]');
    if (!el) return;
    var kind = btn.getAttribute('data-pref');
    var title = el.dataset.prefTitle || 'anunț';
    var r = toggle(el, kind);
    if (r.error === 'limit') {
      showNotice({ text: 'Ai atins limita de ' + MAX + ' de anunțuri păstrate în acest browser. Elimină câteva din Salvate sau Ascunse, apoi încearcă din nou.', focus: true });
      return;
    }
    if (r.error) { announce('Anunțul nu a putut fi salvat local.'); return; }
    applyAll();
    var gone = el.hidden || !document.contains(el);
    var undo = function () { undoTo(r); };
    var text;
    if (kind === 'hide') {
      text = r.after ? 'Anunț ascuns: ' + title + '.' : 'Anunț restabilit: ' + title + '.';
    } else {
      text = r.after ? 'Anunț salvat: ' + title + '.' : 'Anunț eliminat din salvate: ' + title + '.';
    }
    // A hide on the detail page removes nothing from view, so say what happened
    // (and offer the undo) the way a disappearing row would.
    var detailHide = kind === 'hide' && !el.hasAttribute('data-pref-hideable');
    if (detailHide) {
      showNotice({ text: text, undo: undo, storage: r.first });
    } else if (gone) {
      showNotice({ text: text, undo: undo, storage: r.first, focus: true });
    } else if (r.first) {
      showNotice({ text: text, undo: undo, storage: true });
    } else {
      hideNotice();
      announce(text);
    }
    if (!r.persisted) renderStatus();
  }

  // ---- notes ---------------------------------------------------------------
  /** The note under a posting (lists and the detail page); /salvate/ draws its own. */
  function renderNoteView(el, rec) {
    if (el.hasAttribute('data-pref-managed')) return;
    var view = qs(':scope > [data-note-view]', el);
    var text = rec && rec.note ? rec.note : '';
    if (!text) { if (view) view.remove(); return; }
    if (!view) {
      view = document.createElement('p');
      view.setAttribute('data-note-view', '');
      view.className = 'note-view';
      var anchor = qs(':scope > [data-note-editor]', el);
      el.insertBefore(view, anchor || null);
    }
    view.textContent = '📝 ' + text;
  }

  function closeEditor(el, focusBtn) {
    var ed = qs(':scope > [data-note-editor]', el);
    if (ed) ed.remove();
    var nb = qs('[data-pref="note"]', el);
    if (nb) { nb.setAttribute('aria-expanded', 'false'); if (focusBtn) nb.focus(); }
  }

  function openEditor(el) {
    var nb = qs('[data-pref="note"]', el);
    if (qs(':scope > [data-note-editor]', el)) { closeEditor(el, true); return; }
    var rec = S.recs[el.dataset.prefUrl];
    var ed = document.createElement('div');
    ed.setAttribute('data-note-editor', '');
    ed.className = 'note-editor';
    var id = 'note-' + (el.dataset.prefId || Math.random().toString(36).slice(2));
    var label = document.createElement('label');
    label.setAttribute('for', id);
    label.className = 'note-label';
    label.textContent = 'Notița ta (rămâne în acest browser; o poți exporta din Salvate)';
    var ta = document.createElement('textarea');
    ta.id = id; ta.rows = 4; ta.maxLength = NOTE_MAX;
    ta.className = 'note-input';
    ta.value = rec && rec.note ? rec.note : '';
    var row = document.createElement('div');
    row.className = 'note-actions';
    var save = document.createElement('button');
    save.type = 'button'; save.setAttribute('data-note-save', ''); save.className = 'note-btn note-btn-primary';
    save.textContent = 'Salvează notița';
    var cancel = document.createElement('button');
    cancel.type = 'button'; cancel.setAttribute('data-note-cancel', ''); cancel.className = 'note-btn';
    cancel.textContent = 'Închide';
    row.appendChild(save); row.appendChild(cancel);
    ed.appendChild(label); ed.appendChild(ta); ed.appendChild(row);
    // The list's rows sit inside the HTMX filter form, which re-queries on any
    // `change`/`input` bubbling up to it: typing a note must not refresh the results.
    ['change', 'input'].forEach(function (t) { ed.addEventListener(t, function (e) { e.stopPropagation(); }); });
    el.appendChild(ed);
    if (nb) nb.setAttribute('aria-expanded', 'true');
    ta.focus();
  }

  function saveNote(el) {
    var ed = qs(':scope > [data-note-editor]', el);
    if (!ed) return;
    var text = qs('textarea', ed).value.replace(/\r\n?/g, '\n').trim().slice(0, NOTE_MAX);
    var url = el.dataset.prefUrl;
    var res = mutate(function (recs) {
      var rec = recs[url];
      var now = new Date().toISOString();
      if (!rec) {
        if (!text) return null;
        if (count(recs) >= MAX) return 'limit';
        var snap = snapshotOf(el);
        var v = snap && validateRecord(url, { id: snap.id, url: url, title: snap.title, snapshotAt: now });
        if (!v) return 'invalid';
        snap.savedAt = null; snap.hiddenAt = null; snap.snapshotAt = now;
        rec = recs[url] = snap;
      }
      rec.note = text;
      rec.notedAt = text ? now : null;
    });
    if (res.error === 'limit') {
      showNotice({ text: 'Ai atins limita de ' + MAX + ' de anunțuri păstrate în acest browser. Elimină câteva din Salvate sau Ascunse, apoi încearcă din nou.', focus: true });
      return;
    }
    if (res.error) { announce('Notița nu a putut fi salvată local.'); return; }
    closeEditor(el, true);
    applyAll();
    announce(text ? 'Notiță salvată. Anunțul apare în Salvate.' : 'Notiță ștearsă.');
    if (!res.persisted) renderStatus();
  }

  // ---- /salvate/ -----------------------------------------------------------
  /** Records for a view, newest first. 'savedAt' means saved or noted (notes count as saved). */
  function sortedBy(field) {
    var when = function (r) {
      if (field !== 'savedAt') return Date.parse(r[field]);
      return Math.max(r.savedAt ? Date.parse(r.savedAt) : 0, r.notedAt ? Date.parse(r.notedAt) : 0);
    };
    return Object.keys(S.recs).map(function (k) { return S.recs[k]; })
      .filter(function (r) { return field === 'savedAt' ? isSaved(r) : field === 'note' ? !!r.note : r[field]; })
      .sort(function (a, b) { return (when(b) - when(a)) || b.id - a.id; });
  }

  function savedHref(view, page) {
    var q = [];
    if (view === 'hidden') q.push('vedere=ascunse');
    if (view === 'notes') q.push('vedere=notite');
    if (page > 1) q.push('pagina=' + page);
    return '/salvate/' + (q.length ? '?' + q.join('&') : '');
  }

  function renderSaved() {
    var root = qs('[data-saved-page]');
    if (!root) return;
    var params = new URLSearchParams(window.location.search);
    var v = params.get('vedere');
    var view = v === 'ascunse' ? 'hidden' : v === 'notite' ? 'notes' : 'saved';
    var field = view === 'hidden' ? 'hiddenAt' : view === 'notes' ? 'note' : 'savedAt';
    var list = sortedBy(field);
    var savedN = sortedBy('savedAt').length, hiddenN = sortedBy('hiddenAt').length, notesN = sortedBy('note').length;

    qsa('[data-view-tab]', root).forEach(function (a) {
      if (a.getAttribute('data-view-tab') === view) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
    });
    qs('[data-count="saved"]', root).textContent = '(' + savedN + ')';
    qs('[data-count="hidden"]', root).textContent = '(' + hiddenN + ')';
    var nc = qs('[data-count="notes"]', root);
    if (nc) nc.textContent = '(' + notesN + ')';
    var ex = qs('[data-export-csv]', root);
    if (ex) ex.disabled = savedN === 0;

    var pages = Math.max(1, Math.ceil(list.length / PAGE));
    var page = Math.min(Math.max(1, parseInt(params.get('pagina'), 10) || 1), pages);
    var slice = list.slice((page - 1) * PAGE, page * PAGE);

    var ul = qs('[data-saved-list]', root);
    var hadFocus = ul.contains(document.activeElement);
    while (ul.firstChild) ul.removeChild(ul.firstChild);
    var empty = qs('[data-saved-empty]', root);
    empty.hidden = list.length > 0;
    qs('[data-empty-text]', root).textContent = view === 'hidden'
      ? 'Nu ai ascuns niciun anunț în acest browser.'
      : view === 'notes' ? 'Nu ai scris încă nicio notiță. Folosește butonul ✎ de lângă un anunț.'
      : (S.blocked ? 'Lista salvată nu poate fi citită din acest browser.' : 'Nu ai salvat încă niciun anunț.');
    var tpl = document.getElementById('saved-item-tpl');
    slice.forEach(function (rec) { ul.appendChild(buildItem(tpl, rec, view)); });

    var pager = qs('[data-saved-pager]', root);
    pager.hidden = pages <= 1;
    if (pages > 1) {
      qs('[data-pager-info]', root).textContent = ((page - 1) * PAGE + 1) + '–' + Math.min(page * PAGE, list.length) + ' din ' + list.length;
      var prev = qs('[data-pager-prev]', root), next = qs('[data-pager-next]', root);
      prev.hidden = page <= 1; next.hidden = page >= pages;
      prev.setAttribute('href', savedHref(view, page - 1));
      next.setAttribute('href', savedHref(view, page + 1));
    }
    if (hadFocus && !ul.contains(document.activeElement) && document.activeElement === document.body) {
      var n = document.getElementById('pref-notice');
      if (n && !n.hidden) (qs('[data-notice-undo]', n).hidden ? n : qs('[data-notice-undo]', n)).focus();
    }
    resolveSlice(slice);
  }

  function setText(root, f, text) {
    var el = qs('[data-f="' + f + '"]', root);
    el.textContent = text;
    return el;
  }

  function buildItem(tpl, rec, view) {
    var li = tpl.content.firstElementChild.cloneNode(true);
    var c = SP.cache[rec.url];
    var live = c && c.s === 'ok' ? c.d : null;
    var d = live ? { path: live.path, title: live.title, employer: live.employer, place: live.location,
                     date: live.deadline || '', dateSource: live.deadline_source || '' } : rec;
    li.setAttribute('data-pref-id', String(rec.id));
    li.setAttribute('data-pref-url', rec.url);
    li.setAttribute('data-pref-path', d.path || ''); li.setAttribute('data-pref-title', d.title || '');
    li.setAttribute('data-pref-employer', d.employer || ''); li.setAttribute('data-pref-place', d.place || '');
    li.setAttribute('data-pref-date', d.date || ''); li.setAttribute('data-pref-date-source', d.dateSource || '');

    var title = d.title || 'Anunț fără titlu';
    var link = qs('[data-f="link"]', li), plain = qs('[data-f="plain"]', li);
    var path = safePath(d.path, rec.id);
    if (path) { link.textContent = title; link.setAttribute('href', path); plain.hidden = true; }
    else { link.hidden = true; plain.textContent = title; }       // invalid link: text, never a URL

    setText(li, 'meta', [d.employer, d.place].filter(Boolean).join(' · ')).hidden = !(d.employer || d.place);
    var dl = setText(li, 'deadline', d.date ? (d.dateSource === 'expirare' ? 'Termen estimat: ' : 'Termen: ') + fmtDate(d.date) : 'Termen neprecizat');
    dl.hidden = false;
    var note = setText(li, 'note', d.date && d.dateSource === 'expirare' ? FALLBACK_NOTE : '');
    note.hidden = !(d.date && d.dateSource === 'expirare');

    var state = qs('[data-f="state"]', li);
    var retry = qs('[data-retry]', li);
    if (!c || c.s === 'loading') {
      state.textContent = 'Se actualizează…';
    } else if (c.s === 'ok') {
      state.textContent = STATUS_RO[live.status] || '';
      state.hidden = !state.textContent;
    } else if (c.s === 'stale') {
      state.textContent = 'Date salvate; actualizarea nu a reușit';
      retry.hidden = false;
    } else {
      state.textContent = 'Nu mai este disponibil în baza curentă · date salvate ' + fmtDateTime(rec.snapshotAt);
      var src = safeSource(rec.url);
      state.appendChild(document.createTextNode(' · '));
      if (src) {
        var a = document.createElement('a');
        a.setAttribute('href', src); a.setAttribute('rel', 'noopener'); a.setAttribute('target', '_blank');
        a.className = 'text-gov underline';
        a.textContent = 'anunțul oficial ↗';
        state.appendChild(a);
      } else {
        state.appendChild(document.createTextNode(rec.url));  // not a valid source link: plain text
      }
    }
    qs('[data-f="hidden-tag"]', li).hidden = !(view === 'saved' && rec.hiddenAt);
    var nt = qs('[data-f="user-note"]', li);
    if (nt) { nt.textContent = rec.note ? '📝 ' + rec.note : ''; nt.hidden = !rec.note; }
    var nb = qs('[data-pref="note"]', li);
    if (nb) setLabel(nb, rec.note ? 'Editează notița' : 'Adaugă notiță');

    var sb = qs('[data-pref="save"]', li), hb = qs('[data-pref="hide"]', li);
    setLabel(sb, rec.savedAt ? 'Elimină din salvate' : 'Salvează');
    sb.hidden = view === 'hidden';
    // A noted posting is in Salvate by its note; its bookmark can still be on or off.
    if (rec.note && !rec.savedAt) setLabel(sb, 'Salvează');
    setLabel(hb, rec.hiddenAt ? 'Restabilește' : 'Ascunde');
    qs('[data-f="sr-title"]', li).textContent = ' anunțul: ' + title;
    qs('[data-f="sr-title2"]', li).textContent = ' anunțul: ' + title;
    return li;
  }

  function postLookup(ids) {
    return fetch(ENDPOINT, {
      method: 'POST', credentials: 'omit', cache: 'no-store',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ ids: ids })
    }).then(function (res) {
      if (!res.ok) throw new Error('http ' + res.status);
      return res.json();
    }).then(function (j) {
      if (!j || !Array.isArray(j.items)) throw new Error('shape');
      return j.items.filter(function (i) { return i && Number.isInteger(i.id) && typeof i.url === 'string'; });
    });
  }

  function resolveSlice(slice) {
    var need = slice.filter(function (r) { return !SP.cache[r.url]; });
    if (!need.length) return;
    need.forEach(function (r) { SP.cache[r.url] = { s: 'loading' }; });
    var ids = [];
    need.forEach(function (r) { if (ids.indexOf(r.id) < 0) ids.push(r.id); });
    postLookup(ids).then(function (items) {
      var byId = Object.create(null);
      items.forEach(function (i) { byId[i.id] = i; });
      var fresh = [];
      need.forEach(function (r) {
        var d = byId[r.id];
        if (d && d.url === r.url) {          // verified identity: same source URL, not just the same id
          d = { id: d.id, url: d.url, path: clean(d.path, 200), title: clean(d.title), employer: clean(d.employer),
                location: clean(d.location), status: clean(d.status, 30), deadline: clean(d.deadline, 10),
                deadline_source: clean(d.deadline_source, 20) };
          SP.cache[r.url] = { s: 'ok', d: d };
          fresh.push({ id: r.id, url: r.url, path: safePath(d.path, r.id) || r.path, title: d.title || r.title,
                       employer: d.employer, place: d.location, date: d.deadline, dateSource: d.deadline_source });
        } else {
          SP.cache[r.url] = { s: 'missing' };   // never inferred to be expired or cancelled
        }
      });
      if (fresh.length) refreshSnapshots(fresh);
      renderSaved();
    }, function () {
      need.forEach(function (r) { SP.cache[r.url] = { s: 'stale' }; });
      renderSaved();
    });
  }

  function onRetry(btn) {
    var li = btn.closest('[data-posting]');
    if (!li) return;
    delete SP.cache[li.dataset.prefUrl];
    renderSaved();
    announce('Se reîncearcă actualizarea.');
  }

  function clearConfirm(kind) {
    var box = qs('[data-clear-confirm]');
    if (!box) return;
    SP.confirm = kind;
    var n = sortedBy(kind === 'saved' ? 'savedAt' : 'hiddenAt').length;
    qs('[data-clear-confirm-text]', box).textContent = kind === 'saved'
      ? 'Ștergi marcajul de salvare de pe cele ' + n + ' anunțuri din acest browser? Notițele și anunțurile ascunse nu se schimbă (un anunț cu notiță rămâne în listă).'
      : 'Restabilești toate cele ' + n + ' anunțuri ascunse? Anunțurile salvate nu se schimbă.';
    box.hidden = false;
    qs('[data-clear-no]', box).focus();
  }

  function clearDone(yes) {
    var box = qs('[data-clear-confirm]');
    var kind = SP.confirm;
    box.hidden = true;
    SP.confirm = null;
    var origin = qs('[data-clear="' + kind + '"]');
    if (yes) {
      var field = kind === 'saved' ? 'savedAt' : 'hiddenAt';
      mutate(function (recs) { Object.keys(recs).forEach(function (k) { recs[k][field] = null; }); });
      applyAll();
      announce(kind === 'saved' ? 'Lista de salvate a fost ștearsă.' : 'Toate anunțurile ascunse au fost restabilite.');
    }
    if (origin) origin.focus();
  }

  // ---- CSV export (saved + noted) --------------------------------------------
  function csvCell(v) {
    var t = v == null ? '' : String(v);
    return /[",\n\r;]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
  }

  function exportCsv() {
    var rows = sortedBy('savedAt');
    var origin = window.location.origin;
    var head = ['id', 'titlu', 'angajator', 'localitate', 'termen', 'stare', 'link', 'link_oficial',
                'salvat', 'notita', 'notita_la', 'ascuns'];
    var lines = [head.join(',')];
    rows.forEach(function (r) {
      var c = SP && SP.cache[r.url];
      var live = c && c.s === 'ok' ? c.d : null;
      var path = safePath(live ? live.path : r.path, r.id) || ('/job/' + r.id + '/');
      lines.push([
        r.id, live ? live.title : r.title, live ? live.employer : r.employer, live ? live.location : r.place,
        (live ? live.deadline : r.date) || '', live ? (live.status || '') : '',
        origin + path, safeSource(r.url) || r.url,
        r.savedAt || '', r.note || '', r.notedAt || '', r.hiddenAt ? 'da' : ''
      ].map(csvCell).join(','));
    });
    // BOM: spreadsheet apps then read the diacritics as UTF-8.
    var blob = new Blob(['\ufeff' + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    var d = new Date();
    a.href = URL.createObjectURL(blob);
    a.download = 'posturi-salvate-' + d.getFullYear() + pad(d.getMonth() + 1) + pad(d.getDate()) + '.csv';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 0);
    announce(rows.length + (rows.length === 1 ? ' anunț exportat.' : ' anunțuri exportate.'));
  }

  // ---- wiring (all delegated, registered once) -----------------------------
  document.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!(t instanceof Element)) return;
    var b;
    if ((b = t.closest('[data-pref="note"]'))) {
      ev.preventDefault();
      var host = b.closest('[data-posting]');
      if (host) openEditor(host);
      return;
    }
    if ((b = t.closest('[data-note-save]'))) { var h1 = b.closest('[data-posting]'); if (h1) saveNote(h1); return; }
    if ((b = t.closest('[data-note-cancel]'))) { var h2 = b.closest('[data-posting]'); if (h2) closeEditor(h2, true); return; }
    if ((b = t.closest('[data-pref]'))) { ev.preventDefault(); onPref(b); return; }
    if ((b = t.closest('[data-pref-toggle-hidden]'))) { showHidden = !showHidden; applyRows(); return; }
    if ((b = t.closest('[data-notice-undo]'))) { if (noticeUndo) noticeUndo(); return; }
    if ((b = t.closest('[data-notice-close]'))) { hideNotice(); return; }
    if ((b = t.closest('[data-pref-reset]'))) {
      var step = b.getAttribute('data-pref-reset');
      if (step === 'ask') { resetPending = true; renderStatus(); var c = qs('[data-pref-reset="confirm"]'); if (c) c.focus(); }
      else if (step === 'cancel') { resetPending = false; renderStatus(); }
      else { resetPending = false; resetStore(); applyAll(); announce('Preferințele au fost resetate.'); }
      return;
    }
    if (SP) {
      if ((b = t.closest('[data-retry]'))) { onRetry(b); return; }
      if ((b = t.closest('[data-export-csv]'))) { exportCsv(); return; }
      if ((b = t.closest('[data-clear]'))) { clearConfirm(b.getAttribute('data-clear')); return; }
      if ((b = t.closest('[data-clear-yes]'))) { clearDone(true); return; }
      if ((b = t.closest('[data-clear-no]'))) { clearDone(false); return; }
    }
  });

  document.addEventListener('keydown', function (ev) {
    var ed = ev.target instanceof Element && ev.target.closest('[data-note-editor]');
    if (ed) {
      var host = ed.closest('[data-posting]');
      if (ev.key === 'Escape') { ev.preventDefault(); closeEditor(host, true); return; }
      if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); saveNote(host); return; }
    }
    if (ev.key !== 'Escape') return;
    var n = document.getElementById('pref-notice');
    if (SP && SP.confirm) { clearDone(false); return; }
    if (n && !n.hidden && n.contains(document.activeElement)) hideNotice();
  });

  // The facet drawer, HTMX swaps and history restores replace DOM that this script
  // already decorated; state is re-read and re-applied every time.
  document.addEventListener('htmx:afterSwap', function () { applyAll(); });
  document.addEventListener('htmx:historyRestore', function () { hideNotice(); syncFromStorage(); });
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) syncFromStorage(); });
  window.addEventListener('storage', function (ev) { if (ev.key === KEY || ev.key === null) syncFromStorage(); });
  window.addEventListener('popstate', function () { if (SP) renderSaved(); });

  readStore();
  if (qs('[data-saved-page]')) SP = { cache: Object.create(null), confirm: null };
  applyAll();
})();
