/* UX-11 — browser-local application preparation checklist.
 *
 * The server renders the whole checklist as a read-only list (partials/checklist.php); this
 * script only adds check boxes, reader-added items (when the page renders their region) and
 * the revision review on top of it. All
 * state lives in this browser under ONE separate key, `posturi.checklist.v1`:
 *
 *   {version: 1, postings: {<official source URL>: {
 *       rev, updatedAt, seen: [fp...],            // revision the ids below belong to; fingerprints of that revision's items
 *       items:  {<id>: {fp, kind, label, checked: true, at}},   // checked source items only
 *       custom: [{id, label, checked, at}],       // reader-added items
 *       prev?:  {seen: [fp...], items: [{fp, kind, label, rev, at}]}   // unresolved progress from an earlier revision
 *   }}}
 *
 * Identity. The posting is keyed by its official source URL (as in UX-09). An item `id` is
 * `<rev12>:<kind>:<n>`: unique within one source revision and NOT durable across revisions.
 * `fp` is a fingerprint of the item's normalised content; it is only ever used as proof that an
 * item is identical in another revision, never as identity inside one. A checked state moves to
 * the current item that has the same fp and kind (one to one) and nowhere else; anything that
 * matches nothing is kept, labelled with the revision it came from, until the reader dismisses
 * it. With an unknown current revision no identity is claimed at all.
 *
 * Nothing here talks to the server: no fetch, no XHR, no form, nothing in the URL. Labels reach
 * the page through textContent only. `posturi.preferences.v1` (save / hide) is never read or
 * written.
 */
(function () {
  'use strict';
  var root = document.querySelector('[data-checklist]');
  var dataEl = document.getElementById('checklist-data');
  if (!root || !dataEl || window.__posturiChecklist) return;
  window.__posturiChecklist = { key: 'posturi.checklist.v1', version: 1 };

  var KEY = 'posturi.checklist.v1';
  var VERSION = 1;
  var MAX_POSTINGS = 200;
  var MAX_ITEMS = 100;
  var LABEL_MAX = 120;
  var SESSION_ONLY = 'Disponibil doar în această sesiune; browserul nu a putut salva progresul.';
  var UNREADABLE = 'Progresul salvat nu poate fi citit; nu a fost modificat. Ce bifezi acum rămâne doar în această sesiune.';
  var UNREADABLE_HERE = 'Progresul salvat pentru acest anunț nu poate fi citit; nu a fost modificat. Ce bifezi acum rămâne doar în această sesiune.';
  var NO_REV = 'Versiunea anunțului nu poate fi confirmată, deci bifele pentru documente și etape nu se salvează. Elementele adăugate de dvs. se salvează.';

  function qs(sel, el) { return (el || root).querySelector(sel); }
  function qsa(sel, el) { return Array.prototype.slice.call((el || root).querySelectorAll(sel)); }
  function clip(v, n) { return typeof v === 'string' ? v.slice(0, n) : ''; }
  function isIso(s) { return typeof s === 'string' && s.length <= 40 && !isNaN(Date.parse(s)); }
  function isFp(s) { return typeof s === 'string' && /^[0-9a-f]{16}$/.test(s); }
  function isId(s) { return typeof s === 'string' && /^[A-Za-z0-9]{1,16}:(document|stage):[0-9]{1,3}$/.test(s); }
  function isPlain(o) { return !!o && typeof o === 'object' && !Array.isArray(o); }
  function count(o) { return Object.keys(o).length; }

  // ---- the server's model --------------------------------------------------
  var model;
  try { model = JSON.parse(dataEl.textContent); } catch (e) { return; }
  if (!isPlain(model) || typeof model.source_url !== 'string' || !Array.isArray(model.items)) return;
  var URL_KEY = model.source_url;
  if (URL_KEY === '' || URL_KEY.length > 1000) return;          // no identity: stay a read-only list
  var REV = typeof model.source_revision === 'string' ? model.source_revision.slice(0, 100) : '';
  var ITEMS = model.items.filter(function (it) {
    return isPlain(it) && isId(it.id) && isFp(it.fp) && (it.kind === 'document' || it.kind === 'stage') && typeof it.label === 'string';
  });
  var BY_ID = Object.create(null);
  ITEMS.forEach(function (it) { BY_ID[it.id] = it; });
  var FPS = ITEMS.map(function (it) { return it.fp; });

  /** What a reader sees for an item: its label, and for a stage its date (and hour when stated). */
  function display(it) {
    var m = it.kind === 'stage' && typeof it.date === 'string' ? /^(\d{4})-(\d{2})-(\d{2})$/.exec(it.date) : null;
    if (!m) return it.label;
    var t = typeof it.time === 'string' && /^\d{2}:\d{2}$/.test(it.time) ? ', ora ' + it.time : '';
    return it.label + ' — ' + m[3] + '.' + m[2] + '.' + m[1] + t;
  }

  // ---- state ---------------------------------------------------------------
  var S = {
    postings: Object.create(null),   // valid retained postings by source URL (this page's one is the working copy)
    invalid: Object.create(null),    // entries that failed validation; written back untouched
    blocked: null,                   // null | 'corrupt' | 'version' — storage is never overwritten while set
    blockedRaw: null,
    unavailable: false,              // localStorage threw on read
    dirty: false                     // a write failed: the in-memory copy is newer than storage
  };
  var liveTimer = null;
  var lastNotice = '';

  // ---- validation (same shape as saved.js: reject, never repair) -------------
  function validEntry(e, withRev) {
    if (!isPlain(e) || !isFp(e.fp) || (e.kind !== 'document' && e.kind !== 'stage')) return null;
    if (typeof e.label !== 'string' || e.checked !== true || !isIso(e.at)) return null;
    var o = { fp: e.fp, kind: e.kind, label: clip(e.label, LABEL_MAX), checked: true, at: e.at };
    if (withRev) {
      if (typeof e.rev !== 'string' || e.rev.length > 100) return null;
      o.rev = e.rev;
    }
    return o;
  }
  function validFps(a) {
    if (!Array.isArray(a) || a.length > MAX_ITEMS) return null;
    for (var i = 0; i < a.length; i++) if (!isFp(a[i])) return null;
    return a.slice();
  }

  function validatePosting(key, v) {
    if (typeof key !== 'string' || key === '' || key.length > 1000) return null;
    if (!isPlain(v) || typeof v.rev !== 'string' || v.rev.length > 100 || !isIso(v.updatedAt)) return null;
    var seen = v.seen === undefined ? [] : validFps(v.seen);
    if (!seen) return null;
    if (!isPlain(v.items) || count(v.items) > MAX_ITEMS) return null;
    var items = Object.create(null);
    var ids = Object.keys(v.items);
    for (var i = 0; i < ids.length; i++) {
      var e = isId(ids[i]) ? validEntry(v.items[ids[i]], false) : null;
      if (!e) return null;
      items[ids[i]] = e;
    }
    if (!Array.isArray(v.custom) || v.custom.length > MAX_ITEMS) return null;
    var custom = [], used = Object.create(null);
    for (var j = 0; j < v.custom.length; j++) {
      var c = v.custom[j];
      if (!isPlain(c) || typeof c.id !== 'string' || !/^[a-z0-9]{1,24}$/.test(c.id) || used[c.id]) return null;
      if (typeof c.label !== 'string' || c.label.trim() === '' || typeof c.checked !== 'boolean' || !isIso(c.at)) return null;
      used[c.id] = true;
      custom.push({ id: c.id, label: clip(c.label, LABEL_MAX), checked: c.checked, at: c.at });
    }
    var prev = null;
    if (v.prev !== undefined && v.prev !== null) {
      if (!isPlain(v.prev) || !Array.isArray(v.prev.items) || v.prev.items.length > MAX_ITEMS) return null;
      var pseen = validFps(v.prev.seen === undefined ? [] : v.prev.seen);
      if (!pseen) return null;
      var pitems = [];
      for (var k = 0; k < v.prev.items.length; k++) {
        var pe = validEntry(v.prev.items[k], true);
        if (!pe) return null;
        pitems.push(pe);
      }
      prev = { seen: pseen, items: pitems };
    }
    return { rev: v.rev, updatedAt: v.updatedAt, seen: seen, items: items, custom: custom, prev: prev };
  }

  // ---- storage -------------------------------------------------------------
  function readStore() {
    var raw;
    try { raw = window.localStorage.getItem(KEY); S.unavailable = false; }
    catch (e) { S.unavailable = true; return; }
    if (S.dirty && !S.blocked) return;                         // our session copy is newer
    if (S.blocked && raw === S.blockedRaw) return;             // still the same bad data: keep the session copy
    S.blocked = null; S.blockedRaw = null;
    S.postings = Object.create(null); S.invalid = Object.create(null);
    if (raw === null) return;
    var data;
    try { data = JSON.parse(raw); } catch (e) { S.blocked = 'corrupt'; S.blockedRaw = raw; return; }
    if (!isPlain(data) || typeof data.version !== 'number') { S.blocked = 'corrupt'; S.blockedRaw = raw; return; }
    if (data.version !== VERSION) { S.blocked = 'version'; S.blockedRaw = raw; return; }
    if (!isPlain(data.postings)) { S.blocked = 'corrupt'; S.blockedRaw = raw; return; }
    Object.keys(data.postings).forEach(function (k) {
      var rec = validatePosting(k, data.postings[k]);
      if (rec) S.postings[k] = rec; else S.invalid[k] = data.postings[k];
    });
  }

  function serialise(rec) {
    var o = { rev: rec.rev, updatedAt: rec.updatedAt, seen: rec.seen, items: rec.items, custom: rec.custom };
    if (rec.prev) o.prev = rec.prev;
    return o;
  }

  function writeStore() {
    if (S.blocked || S.invalid[URL_KEY]) { S.dirty = true; return false; }   // never overwrite unreadable data
    var keys = Object.keys(S.postings);
    if (keys.length > MAX_POSTINGS) {                                         // drop the oldest, never this posting
      keys.filter(function (k) { return k !== URL_KEY; })
        .sort(function (a, b) { return Date.parse(S.postings[a].updatedAt) - Date.parse(S.postings[b].updatedAt); })
        .slice(0, keys.length - MAX_POSTINGS)
        .forEach(function (k) { delete S.postings[k]; });
    }
    var out = Object.create(null);
    Object.keys(S.invalid).forEach(function (k) { out[k] = S.invalid[k]; });
    Object.keys(S.postings).forEach(function (k) { out[k] = serialise(S.postings[k]); });
    var payload = JSON.stringify({ version: VERSION, postings: out });
    try {
      window.localStorage.setItem(KEY, payload);
      if (window.localStorage.getItem(KEY) !== payload) throw new Error('write not persisted');
    } catch (e) { S.dirty = true; return false; }
    S.dirty = false; S.unavailable = false;
    return true;
  }

  function prune() {
    Object.keys(S.postings).forEach(function (k) {
      var r = S.postings[k];
      if (!count(r.items) && !r.custom.length && !(r.prev && r.prev.items.length)) delete S.postings[k];
    });
  }

  /** Re-read current storage, apply fn to this page's postings, write. fn returns an error code to abort. */
  function mutate(fn) {
    readStore();
    var err = fn(S.postings);
    if (err) return { error: err };
    prune();
    return { persisted: writeStore() };
  }

  // ---- reconciling stored progress with the current revision ------------------
  /**
   * Which checked states belong to which current item, and what is left over. Pure: reads the
   * stored posting and the model, changes neither.
   *   carried[id]  stored entry that is provably the same item (same id AND fp in the same
   *                revision; otherwise same fp and kind, matched one to one)
   *   removed      stored checked entries that matched nothing
   *   added        current items whose fp the reader had not seen (null when there is nothing to compare)
   */
  function reconcile(rec) {
    var out = { carried: Object.create(null), removed: [], added: [], review: false };
    if (!rec || !REV) return out;
    var pool = [];
    Object.keys(rec.items).forEach(function (id) { pool.push({ id: id, e: rec.items[id], rev: rec.rev, used: false }); });
    if (rec.prev) rec.prev.items.forEach(function (e) { pool.push({ id: null, e: e, rev: e.rev, used: false }); });

    ITEMS.forEach(function (it) {                                  // same revision, same id, same fingerprint
      if (rec.rev !== REV) return;
      var hit = pool.filter(function (s) { return !s.used && s.id === it.id && s.e.fp === it.fp && s.e.kind === it.kind; })[0];
      if (hit) { hit.used = true; out.carried[it.id] = hit.e; }
    });
    ITEMS.forEach(function (it) {                                  // same fingerprint, one to one
      if (out.carried[it.id]) return;
      var hit = pool.filter(function (s) { return !s.used && s.e.fp === it.fp && s.e.kind === it.kind; })[0];
      if (hit) { hit.used = true; out.carried[it.id] = hit.e; }
    });
    out.removed = pool.filter(function (s) { return !s.used; }).map(function (s) {
      return { fp: s.e.fp, kind: s.e.kind, label: s.e.label, checked: true, rev: s.rev, at: s.e.at };
    });

    var seenOld = rec.prev ? rec.prev.seen : (rec.rev !== REV ? rec.seen : null);
    if (seenOld && seenOld.length) {
      var left = Object.create(null);
      seenOld.forEach(function (f) { left[f] = (left[f] || 0) + 1; });
      ITEMS.forEach(function (it) { if (left[it.fp] > 0) left[it.fp]--; else out.added.push(it); });
    }
    out.review = out.added.length > 0 || out.removed.length > 0;
    return out;
  }

  /** This posting's record, brought to the current revision. Only called inside mutate(). */
  function ensureRec(postings) {
    var now = new Date().toISOString();
    var rec = postings[URL_KEY];
    if (!rec) {
      rec = postings[URL_KEY] = { rev: REV, updatedAt: now, seen: REV ? FPS.slice() : [], items: Object.create(null), custom: [], prev: null };
      return rec;
    }
    if (!REV) return rec;                                          // unknown revision: source entries stay exactly as stored
    var r = reconcile(rec);
    var prevSeen = rec.prev ? rec.prev.seen : (rec.rev !== REV || r.removed.length ? rec.seen : []);
    var items = Object.create(null);
    ITEMS.forEach(function (it) {
      var e = r.carried[it.id];
      if (e) items[it.id] = { fp: it.fp, kind: it.kind, label: clip(display(it), LABEL_MAX), checked: true, at: e.at };
    });
    rec.items = items;
    rec.prev = r.review ? { seen: prevSeen, items: r.removed.slice(0, MAX_ITEMS) } : null;
    rec.rev = REV;
    rec.seen = FPS.slice();
    return rec;
  }

  // ---- announcements and notices ---------------------------------------------
  function announce(msg) {
    var live = qs('[data-cl-live]');
    if (!live) return;
    live.textContent = '';
    clearTimeout(liveTimer);
    liveTimer = setTimeout(function () { live.textContent = msg; }, 30);
  }

  function flash(msg) {
    var el = qs('[data-cl-flash]');
    el.textContent = msg || '';
    el.hidden = !msg;
    if (msg) announce(msg);
  }

  function renderNotice() {
    var msgs = [];
    if (S.blocked) msgs.push(UNREADABLE);
    else if (S.invalid[URL_KEY]) msgs.push(UNREADABLE_HERE);
    else if (S.unavailable || S.dirty) msgs.push(SESSION_ONLY);
    if (!REV) msgs.push(NO_REV);
    var text = msgs.join(' ');
    var el = qs('[data-cl-notice]');
    el.textContent = text;
    el.hidden = !text;
    if (text && text !== lastNotice) announce(text);
    lastNotice = text;
  }

  // ---- rendering ---------------------------------------------------------------
  function tpl(id) { return document.getElementById(id).content.firstElementChild.cloneNode(true); }

  function addCheck(slot, kind, name) {
    var c = tpl('cl-tpl-check');
    qs('[data-cl-check-text]', c).textContent = kind === 'document' ? 'Pregătit' : 'Notat';
    // The visible word is the label; the accessible name starts with it and says which item it is.
    var box = qs('[data-cl-check]', c);
    box.setAttribute('aria-label', (kind === 'document' ? 'Pregătit: ' : 'Notat: ') + name);
    slot.appendChild(c);
    return box;
  }

  function fill(list, lines, fn) {
    while (list.firstChild) list.removeChild(list.firstChild);
    lines.forEach(function (x) {
      var li = tpl('cl-tpl-line');
      li.textContent = fn(x);
      list.appendChild(li);
    });
  }

  function summary() {
    var docs = ITEMS.filter(function (it) { return it.kind === 'document'; });
    var el = qs('[data-cl-summary="document"]');
    if (!el) return;
    var done = docs.filter(function (it) { var b = rowBox(it.id); return b && b.checked; }).length;
    el.textContent = done + ' din ' + docs.length + (docs.length === 1 ? ' document pregătit' : ' documente pregătite');
    el.hidden = false;
  }

  function rowBox(id) {
    var li = qsa('[data-cl-item]').filter(function (l) { return l.getAttribute('data-cl-id') === id; })[0];
    return li ? qs('[data-cl-check]', li) : null;
  }

  function when(iso) {
    var d = new Date(iso);
    if (isNaN(d.getTime())) return '';
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return p(d.getDate()) + '.' + p(d.getMonth() + 1) + '.' + d.getFullYear();
  }

  function render() {
    var rec = S.postings[URL_KEY] || null;
    var r = reconcile(rec);

    ITEMS.forEach(function (it) {
      var b = rowBox(it.id);
      if (!b) return;
      b.checked = !!r.carried[it.id];
      b.disabled = !REV;
    });
    summary();

    // revision review
    var review = qs('[data-cl-review]');
    var showReview = !!(rec && REV && r.review);
    if (showReview && review.hidden) announce('Anunțul s-a modificat de la ultima vizită. Verificați elementele noi, modificate sau eliminate.');
    review.hidden = !showReview;
    if (showReview) {
      qs('[data-cl-review-added]').hidden = !r.added.length;
      fill(qs('[data-cl-review-added-list]'), r.added, function (it) { return display(it); });
      qs('[data-cl-review-removed]').hidden = !r.removed.length;
      fill(qs('[data-cl-review-removed-list]'), r.removed, function (e) {
        return e.label + ' — din versiunea anterioară' + (when(e.at) ? ' (bifat la ' + when(e.at) + ')' : '');
      });
    }

    // unknown current revision: stored progress is shown, never applied
    var unconfirmed = [];
    if (rec && !REV) {
      Object.keys(rec.items).forEach(function (id) { unconfirmed.push(rec.items[id]); });
      if (rec.prev) rec.prev.items.forEach(function (e) { unconfirmed.push(e); });
    }
    var uc = qs('[data-cl-unconfirmed]');
    uc.hidden = !unconfirmed.length;
    if (unconfirmed.length) fill(qs('[data-cl-unconfirmed-list]'), unconfirmed, function (e) { return e.label; });

    renderCustom(rec);
    renderNotice();
  }

  var focusAfter = null;     // {id}: put focus back on a rebuilt custom-item check box

  function renderCustom(rec) {
    var list = qs('[data-cl-custom-list]');
    if (!list) return;       // "Elementele dvs." off (inc/checklist.php): stored items stay untouched
    var had = list.contains(document.activeElement);
    while (list.firstChild) list.removeChild(list.firstChild);
    (rec ? rec.custom : []).forEach(function (c) {
      var li = tpl('cl-tpl-custom');
      li.setAttribute('data-cl-cid', c.id);
      qs('[data-cl-custom-label]', li).textContent = c.label;
      var box = addCheck(qs('[data-cl-custom-slot]', li), 'document', c.label);
      box.checked = !!c.checked;
      qs('[data-cl-remove]', li).setAttribute('aria-label', 'Elimină: ' + c.label);
      list.appendChild(li);
    });
    if (focusAfter) {
      var li2 = qsa('[data-cl-custom-item]').filter(function (l) { return l.getAttribute('data-cl-cid') === focusAfter.id; })[0];
      var target = li2 && qs('[data-cl-check]', li2);
      if (target) target.focus();
      focusAfter = null;
    } else if (had && !list.contains(document.activeElement) && (document.activeElement === document.body || !document.activeElement)) {
      qs('[data-cl-add-input]').focus();
    }
  }

  // ---- actions -------------------------------------------------------------------
  function limitReached(rec) { return count(rec.items) + rec.custom.length >= MAX_ITEMS; }

  function onSourceCheck(box) {
    var li = box.closest('[data-cl-item]');
    var it = li && BY_ID[li.getAttribute('data-cl-id')];
    if (!it || !REV) { render(); return; }
    var want = box.checked;
    var res = mutate(function (postings) {
      var rec = ensureRec(postings);
      if (want) {
        if (!rec.items[it.id] && limitReached(rec)) return 'limit';
        rec.items[it.id] = { fp: it.fp, kind: it.kind, label: clip(display(it), LABEL_MAX), checked: true, at: new Date().toISOString() };
      } else {
        delete rec.items[it.id];
      }
      rec.updatedAt = new Date().toISOString();
    });
    if (res.error === 'limit') {
      render();
      flash('Ai atins limita de ' + MAX_ITEMS + ' de elemente păstrate pentru acest anunț. Elimină câteva, apoi încearcă din nou.');
      return;
    }
    flash('');
    render();
    var name = clip(display(it), LABEL_MAX);
    var msg;
    if (it.kind === 'document') {
      var docs = ITEMS.filter(function (x) { return x.kind === 'document'; });
      var done = docs.filter(function (x) { var b = rowBox(x.id); return b && b.checked; }).length;
      msg = (want ? 'Pregătit: ' : 'Nepregătit: ') + name + '. ' + done + ' din ' + docs.length + ' documente pregătite.';
    } else {
      msg = (want ? 'Notat: ' : 'Notarea a fost eliminată: ') + name + '.';
    }
    announce(msg);
  }

  function onCustomCheck(box) {
    var li = box.closest('[data-cl-custom-item]');
    var id = li && li.getAttribute('data-cl-cid');
    var want = box.checked, label = '';
    mutate(function (postings) {
      var rec = ensureRec(postings);
      rec.custom.forEach(function (c) { if (c.id === id) { c.checked = want; label = c.label; } });
      rec.updatedAt = new Date().toISOString();
    });
    focusAfter = { id: id };
    render();
    announce((want ? 'Pregătit: ' : 'Nepregătit: ') + label + '.');
  }

  function newId() {
    return 'c' + Date.now().toString(36).slice(-8) + Math.random().toString(36).slice(2, 8);
  }

  function onAdd() {
    var input = qs('[data-cl-add-input]');
    var label = clip(input.value.replace(/\s+/g, ' ').trim(), LABEL_MAX);
    if (!label) { flash('Scrie întâi textul elementului.'); input.focus(); return; }
    var res = mutate(function (postings) {
      var rec = ensureRec(postings);
      if (limitReached(rec)) return 'limit';
      var id = newId();
      while (rec.custom.some(function (c) { return c.id === id; })) id = newId();
      rec.custom.push({ id: id, label: label, checked: false, at: new Date().toISOString() });
      rec.updatedAt = new Date().toISOString();
    });
    if (res.error === 'limit') {
      flash('Ai atins limita de ' + MAX_ITEMS + ' de elemente păstrate pentru acest anunț. Elimină câteva, apoi încearcă din nou.');
      return;
    }
    input.value = '';
    flash('');
    render();
    input.focus();
    announce('Element adăugat: ' + label + '.');
  }

  function onRemove(btn) {
    var li = btn.closest('[data-cl-custom-item]');
    var id = li && li.getAttribute('data-cl-cid');
    var label = '';
    mutate(function (postings) {
      var rec = ensureRec(postings);
      rec.custom = rec.custom.filter(function (c) { if (c.id === id) label = c.label; return c.id !== id; });
      rec.updatedAt = new Date().toISOString();
    });
    render();
    announce('Element eliminat: ' + label + '.');
  }

  function onDismiss() {
    mutate(function (postings) {
      var rec = ensureRec(postings);
      rec.prev = null;
      rec.updatedAt = new Date().toISOString();
    });
    render();
    announce('Modificările au fost confirmate.');
    var h = qs('[data-cl-add-input]');
    if (h) h.focus();
  }

  function clearAsk() {
    var box = qs('[data-cl-confirm]');
    box.hidden = false;
    qs('[data-cl-clear-no]').focus();
    announce('Confirmă ștergerea progresului pentru acest anunț.');
  }

  function clearDone(yes) {
    qs('[data-cl-confirm]').hidden = true;
    if (yes) {
      var unreadable = !!(S.blocked || S.invalid[URL_KEY]);
      mutate(function (postings) { delete postings[URL_KEY]; });
      render();
      announce(unreadable
        ? 'Progresul din această sesiune a fost șters; datele salvate nu au putut fi citite și au rămas neschimbate.'
        : 'Progresul pentru acest anunț a fost șters.');
    }
    var origin = qs('[data-cl-clear]');
    if (origin) origin.focus();
  }

  // ---- wiring (delegated on the section) -----------------------------------------
  root.addEventListener('change', function (ev) {
    var t = ev.target;
    if (!(t instanceof Element) || !t.matches('[data-cl-check]')) return;
    if (t.closest('[data-cl-custom-item]')) onCustomCheck(t); else onSourceCheck(t);
  });

  root.addEventListener('click', function (ev) {
    var t = ev.target;
    if (!(t instanceof Element)) return;
    var b;
    if ((b = t.closest('[data-cl-add]'))) { onAdd(); return; }
    if ((b = t.closest('[data-cl-remove]'))) { onRemove(b); return; }
    if ((b = t.closest('[data-cl-dismiss]'))) { onDismiss(); return; }
    if ((b = t.closest('[data-cl-clear]'))) { clearAsk(); return; }
    if ((b = t.closest('[data-cl-clear-yes]'))) { clearDone(true); return; }
    if ((b = t.closest('[data-cl-clear-no]'))) { clearDone(false); return; }
  });

  root.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && ev.target instanceof Element && ev.target.matches('[data-cl-add-input]')) {
      ev.preventDefault();                                          // no form, nothing is submitted
      onAdd();
      return;
    }
    if (ev.key === 'Escape' && !qs('[data-cl-confirm]').hidden) clearDone(false);
  });

  function sync() { readStore(); render(); }
  window.addEventListener('storage', function (ev) { if (ev.key === KEY || ev.key === null) sync(); });
  window.addEventListener('pageshow', function (ev) { if (ev.persisted) sync(); });

  // ---- start ---------------------------------------------------------------------
  qsa('[data-cl-item]').forEach(function (li) {
    var it = BY_ID[li.getAttribute('data-cl-id')];
    var slot = qs('[data-cl-slot]', li);
    if (it && slot) addCheck(slot, it.kind, display(it));
  });
  var js = qs('[data-cl-js]');
  js.hidden = false;
  readStore();
  render();
})();
