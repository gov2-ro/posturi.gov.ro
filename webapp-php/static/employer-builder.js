/*
 * UX-EMP-01 — the employer posting builder (/pentru-angajatori/anunt/).
 *
 * The page is a static shell. All state lives in this browser:
 *   posturi.employer.v1         { version:1, profile:{ institution, channels, documents } }
 *   posturi.employer-drafts.v1  { version:1, currentId, drafts:{ <id>:{ id, title, updatedAt, data, meta } } }
 *
 * `draft.data` has the shape of v5 data (schema_models.JobPostingExtractionV5), so the payload
 * (ADR 0002) needs no mapping. `draft.meta` holds what v5 has no field for: the institution,
 * the department and the "with interview" switch. Form fields bind to a path with `data-path`;
 * a path that starts with `meta.` reads and writes `draft.meta`.
 *
 * The stores follow static/saved.js: unreadable data is never written over. The feature shows
 * as unavailable instead.
 *
 * Counting rules for the calendar are in ONE place: addWorkingDays() and windowEnd().
 */
(function () {
  'use strict';

  var root = document.querySelector('[data-employer-builder]');
  if (!root) return;

  var PROFILE_KEY = 'posturi.employer.v1';
  var DRAFTS_KEY = 'posturi.employer-drafts.v1';
  var MAX_DRAFTS = 30;
  var SAVE_MS = 500;
  var EXPORT_FORMAT = 'posturi.employer.export.v1';
  var Doc = window.EmployerDoc;
  var Hol = window.RoHolidays || { isHoliday: function () { return false; }, knownYear: function () { return false; } };
  var CFG = {};
  try { CFG = JSON.parse(root.querySelector('script[data-config]').textContent) || {}; } catch (e) { CFG = {}; }
  var CLS = CFG.cls || {};

  // ---- calendar: counting rules (HG 1336/2022) --------------------------------
  // Art. 98(1) counts the first and the last day. Art. 98(3) moves a start on a non-working
  // day to the next working day. ASSUMPTION: the day of publication is day 1 of the art. 34
  // window. For every other "within N working days after X" rule, day 1 is the working day
  // after X. For art. 18(1), the test is at least N working days after the day of publication.

  function parseIso(iso) {
    var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso || '');
    if (!m) return null;
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
    return d.getUTCFullYear() === +m[1] && d.getUTCMonth() === +m[2] - 1 && d.getUTCDate() === +m[3] ? d : null;
  }
  function toIso(d) {
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getUTCFullYear() + '-' + p(d.getUTCMonth() + 1) + '-' + p(d.getUTCDate());
  }
  function plusDays(iso, n) {
    var d = parseIso(iso);
    d.setUTCDate(d.getUTCDate() + n);
    return toIso(d);
  }
  function isWorkingDay(iso) {
    var d = parseIso(iso);
    if (!d) return false;
    var dow = d.getUTCDay();
    return dow !== 0 && dow !== 6 && !Hol.isHoliday(iso);
  }
  function nextWorkingDay(iso) {
    var guard = 0;
    while (!isWorkingDay(iso) && guard++ < 30) iso = plusDays(iso, 1);
    return iso;
  }
  /** The date that is n working days after `iso` (n = 1: the next working day). */
  function addWorkingDays(iso, n) {
    var left = n, guard = 0;
    while (left > 0 && guard++ < 1000) {
      iso = plusDays(iso, 1);
      if (isWorkingDay(iso)) left--;
    }
    return iso;
  }
  /** The last day of a window of n working days that starts on `pub` (day 1; art. 98(1), (3)). */
  function windowEnd(pub, n) {
    return addWorkingDays(nextWorkingDay(pub), n - 1);
  }
  /** Working days from `start` to `end`, both counted. */
  function countInclusive(start, end) {
    var n = 0, d = nextWorkingDay(start), guard = 0;
    while (d <= end && guard++ < 1000) {
      n++;
      d = addWorkingDays(d, 1);
    }
    return n;
  }

  // Each stage: the rule that the helper proposes and checks. `after` names the stage that the
  // rule counts from; `max` is in working days (art.); `minFromPub` is a minimum from publication.
  var STAGES = [
    { stage: 'publicare', art: '' },
    { stage: 'depunere_dosare', art: 'art. 34' },
    { stage: 'selectie_dosare', art: 'art. 36 alin. 1', after: 'depunere_dosare', max: [2, 1] },
    { stage: 'rezultate_selectie_dosare', art: 'art. 37 alin. 1', after: 'selectie_dosare', max: [1, 1] },
    { stage: 'contestatii_selectie_dosare', art: 'art. 53', after: 'rezultate_selectie_dosare', max: [1, 1] },
    { stage: 'rezultate_contestatii_selectie_dosare', art: 'art. 54 alin. 1, art. 56 alin. 2', after: 'contestatii_selectie_dosare', max: [1, 1] },
    { stage: 'proba_scrisa', art: 'art. 18 alin. 1', after: 'rezultate_contestatii_selectie_dosare', minFromPub: [15, 10] },
    { stage: 'rezultate_proba_scrisa', art: 'art. 43 alin. 2', after: 'proba_scrisa', max: [1, 1] },
    { stage: 'contestatii_proba_scrisa', art: 'art. 53', after: 'rezultate_proba_scrisa', max: [1, 1] },
    { stage: 'rezultate_contestatii_proba_scrisa', art: 'art. 54 alin. 3', after: 'contestatii_proba_scrisa', max: [1, 1] },
    { stage: 'interviu', art: 'art. 41 alin. 5', after: 'proba_scrisa', max: [4, 4], interview: true },
    { stage: 'rezultate_interviu', art: 'art. 43 alin. 2', after: 'interviu', max: [1, 1], interview: true },
    { stage: 'contestatii_interviu', art: 'art. 53', after: 'rezultate_interviu', max: [1, 1], interview: true },
    { stage: 'rezultate_contestatii_interviu', art: 'art. 54 alin. 3', after: 'contestatii_interviu', max: [1, 1], interview: true },
    { stage: 'rezultate_finale', art: 'art. 56 alin. 3', after: '*last', max: [1, 1] }
  ];
  var STAGE_BY = {};
  STAGES.forEach(function (s) { STAGE_BY[s.stage] = s; });

  function stagesFor(meta) {
    var withInterview = !meta || meta.interview !== false;
    return STAGES.filter(function (s) { return withInterview || !s.interview; });
  }
  function lastBeforeFinal(stages) {
    var i = stages.length - 2;
    return stages[i].stage;
  }
  function afterOf(s, stages) {
    return s.after === '*last' ? lastBeforeFinal(stages) : s.after;
  }
  function statusIndex(status) { return status === 'temporar_vacant' ? 1 : 0; }

  /** { stage: iso } for a publication date and a post status, or null without a valid date. */
  function proposeCalendar(pub, status, withInterview) {
    if (!parseIso(pub)) return null;
    var i = statusIndex(status);
    var stages = stagesFor({ interview: withInterview });
    var out = {};
    stages.forEach(function (s) {
      var st = s.stage;
      if (st === 'publicare') out[st] = pub;
      else if (st === 'depunere_dosare') out[st] = windowEnd(pub, i ? 5 : 10);
      else {
        var prev = out[afterOf(s, stages)];
        if (s.minFromPub) {
          var floor = addWorkingDays(pub, s.minFromPub[i]);
          var later = addWorkingDays(prev, 1);
          out[st] = floor > later ? floor : later;
        } else out[st] = addWorkingDays(prev, s.max[i]);
      }
    });
    return out;
  }

  /** A warning (Romanian) for a date that breaks a legal minimum or maximum, or ''. */
  function checkStage(stage, events, pub, status, meta) {
    var s = STAGE_BY[stage];
    var date = events[stage];
    if (!s || !parseIso(date)) return '';
    var i = statusIndex(status);
    var stages = stagesFor(meta);
    if (stage === 'depunere_dosare') {
      if (!parseIso(pub)) return '';
      var need = i ? 5 : 10;
      var have = countInclusive(pub, date);
      return have < need ? 'Sunt doar ' + have + ' zile lucrătoare pentru depunere; minimul este ' + need + ' (art. 34).' : '';
    }
    if (!s.after) return '';
    var prevStage = afterOf(s, stages);
    var prev = events[prevStage];
    if (!parseIso(prev)) return '';
    var msgs = [];
    if (date < prev) msgs.push('Data este înaintea etapei „' + Doc.STAGE_LABELS[prevStage] + '”.');
    if (s.minFromPub && parseIso(pub)) {
      var min = addWorkingDays(pub, s.minFromPub[i]);
      if (date < min) msgs.push('Anunțul trebuie publicat cu cel puțin ' + s.minFromPub[i] + ' zile lucrătoare înainte de probă (' + s.art + '). Cea mai devreme dată: ' + Doc.dmy(min) + '.');
    }
    if (s.max) {
      var limit = addWorkingDays(prev, s.max[i]);
      if (date > limit) msgs.push('Depășește maximul de ' + s.max[i] + (s.max[i] === 1 ? ' zi lucrătoare' : ' zile lucrătoare') + ' (' + s.art + '). Cea mai târzie dată: ' + Doc.dmy(limit) + '.');
    }
    return msgs.join(' ');
  }

  // ---- data: prune, derive ----------------------------------------------------
  function clone(v) { return v === undefined ? undefined : JSON.parse(JSON.stringify(v)); }

  function prune(v) {
    if (Array.isArray(v)) {
      var a = v.map(prune).filter(function (x) { return x !== undefined; });
      return a.length ? a : undefined;
    }
    if (v && typeof v === 'object') {
      var o = {}, any = false;
      Object.keys(v).forEach(function (k) {
        var p = prune(v[k]);
        if (p !== undefined) { o[k] = p; any = true; }
      });
      return any ? o : undefined;
    }
    if (typeof v === 'string') { v = v.trim(); return v === '' ? undefined : v; }
    if (typeof v === 'number') return isFinite(v) ? v : undefined;
    if (v === true) return true;
    return undefined;
  }

  /** The v5 `data` of a draft: only what the employer filled, plus the derived keys. */
  function buildData(draft) {
    var d = clone(draft.data) || {};
    var meta = draft.meta || {};
    // The calendar: keep events with a date; the deadline comes from the submission row.
    var cal = d.competition_calendar;
    if (cal && Array.isArray(cal.events)) {
      var withInterview = meta.interview !== false;
      cal.events = cal.events.filter(function (e) {
        return e && e.date && parseIso(e.date) && (withInterview || !(STAGE_BY[e.stage] || {}).interview);
      }).map(function (e) {
        var o = { stage: e.stage, date: e.date };
        if (e.time) o.time = e.time;
        return o;
      });
      var dep = cal.events.filter(function (e) { return e.stage === 'depunere_dosare'; })[0];
      cal.application_deadline = dep ? dep.date : undefined;
      cal.application_deadline_time = dep && dep.time ? dep.time : undefined;
    }
    var s = d.baseSalary;
    if (s) {
      var hasMin = typeof s.minValue === 'number', hasMax = typeof s.maxValue === 'number';
      if (hasMin || hasMax) {
        if (!hasMin) s.minValue = s.maxValue;
        if (!hasMax) s.maxValue = s.minValue;
        s.currency = 'RON';
        s.unitText = 'MONTH';
      } else delete d.baseSalary;
    }
    if (d.experience && d.experience.none_required) delete d.experience.years_minimum;
    d = prune(d) || {};
    if (d.occupation_title) {
      var pos = { title: d.occupation_title };
      if (d.vacancies_total) pos.count = d.vacancies_total;
      d.positions = [pos];
    }
    d.recruitment = { kind: 'recrutare' };
    return d;
  }

  // ---- completeness (classify.QUALITY_CHECKS) ---------------------------------
  // Keys, labels and weights are copied from classify.QUALITY_CHECKS; test_employer_payload.py
  // compares them. `todo` and `sec` are ours: what to do in Romanian, and the form section.
  var QUALITY = [
    ['studies', 'Studiile cerute sunt precizate', 8, 'Alege studiile cerute', 3],
    ['experience', 'Experiența cerută este precizată', 7, 'Precizează experiența cerută sau bifează „fără experiență”', 3],
    ['documents', 'Lista documentelor de depus', 8, 'Bifează documentele din dosar', 6],
    ['calendar', 'Calendar cu cel puțin două date', 10, 'Completează cel puțin două date în calendar', 9],
    ['contact', 'Telefon sau e-mail de contact', 6, 'Adaugă un telefon sau un e-mail de contact', 8],
    ['duties', 'Atribuțiile postului', 3, 'Scrie atribuțiile postului', 5],
    ['bibliography', 'Bibliografie / tematică', 3, 'Adaugă bibliografia și tematica', 7],
    ['salary', 'Salariul este precizat', 10, 'Scrie salariul', 4],
    ['duration', 'Durata contractului', 5, 'Alege durata contractului', 2],
    ['schedule', 'Programul de lucru', 5, 'Alege programul de lucru', 2],
    ['confirmed', 'Termen de depunere precizat (nu doar expirarea)', 8, 'Stabilește ultima zi de depunere', 9],
    ['window', 'Cel puțin 10 zile pentru depunere', 12, 'Lasă cel puțin 10 zile între publicare și ultima zi de depunere', 9],
    // text and attachment: the .docx is the complete anunț, so both are 1 for builder output.
    // This is an assumption (brief UX-EMP-01 § 3).
    ['text', 'Text propriu, nu doar formule standard', 6, '', 0],
    ['specific', 'Cerințe concrete, nu doar calități generice', 4, 'Cerințele sunt prea generale', 3],
    ['attachment', 'Anunț complet în pagină sau atașat ca document', 5, '', 0]
  ];

  function filledStr(v) {
    if (typeof v === 'string') return ['', 'null', '-'].indexOf(v.trim()) < 0;
    if (Array.isArray(v)) return v.length > 0;
    if (v && typeof v === 'object') return Object.keys(v).length > 0;
    return v !== null && v !== undefined;
  }
  function daysBetween(a, b) {
    var da = parseIso(String(a || '').slice(0, 10)), db = parseIso(String(b || '').slice(0, 10));
    return da && db ? Math.round((db - da) / 86400000) : null;
  }

  /** { score, checks } for pruned v5 data. Mirrors classify.quality(). */
  function quality(d) {
    var edu = d.education || {}, exp = d.experience || {};
    var cal = d.competition_calendar || {};
    var dated = (cal.events || []).filter(function (e) { return e && e.date; });
    var skills = d.skill_list || [];
    var soft = skills.filter(function (k) { return k.type === 'transversal'; }).length;
    var ct = d.application_contact || {};
    var deadline = cal.application_deadline;
    var confirmed = !!deadline;
    var start = d.application && d.application.window_start;
    var win = daysBetween(start, deadline);
    var c = {
      studies: filledStr(edu.minimum_level),
      experience: exp.years_minimum !== undefined && exp.years_minimum !== null || !!exp.none_required,
      documents: filledStr(d.documents && d.documents.standard),
      calendar: dated.length >= 2,
      contact: !!(String(ct.phone || '').trim() || String(ct.email || '').trim()),
      duties: filledStr(d.responsibilities),
      bibliography: filledStr(d.bibliography_topics),
      salary: filledStr(d.baseSalary),
      duration: filledStr(d.contract && d.contract.duration),
      schedule: filledStr(d.contract && d.contract.schedule),
      confirmed: confirmed,
      window: !confirmed || win === null ? 0 : (win >= 10 ? 1 : win >= 7 ? 0.5 : 0),
      text: true,
      specific: !skills.length || soft <= skills.length / 2,
      attachment: true
    };
    var checks = {}, score = 0;
    QUALITY.forEach(function (q) {
      var v = c[q[0]];
      checks[q[0]] = typeof v === 'boolean' ? (v ? 1 : 0) : v;
      score += q[2] * checks[q[0]];
    });
    return { score: Math.round(score), checks: checks };
  }

  /** The elements that art. 18 alin. 2 a)-e) require. [letter, label, ok, section] */
  function mandatory(d, meta) {
    var ct = d.application_contact || {};
    var cal = d.competition_calendar || {};
    var docs = d.documents || {};
    return [
      ['a', 'Numărul, denumirea și structura postului, programul și durata contractului',
        !!(d.occupation_title && d.vacancies_total && meta.department && d.contract && d.contract.duration && d.contract.schedule), 2],
      ['b', 'Documentele din dosar, locul de depunere și datele de contact',
        !!(((docs.standard || []).length || (docs.specific || []).length) && (ct.address || ct.email) ), 6],
      ['c', 'Condițiile specifice (studii, experiență)',
        !!(d.education && d.education.minimum_level) && !!(d.experience), 3],
      ['d', 'Bibliografia și tematica', !!(d.bibliography_topics || []).length, 7],
      ['e', 'Calendarul, cu termenul de depunere', !!(cal.application_deadline && (cal.events || []).length >= 2), 9]
    ];
  }

  // ---- stores (the pattern of static/saved.js) --------------------------------
  function Store(key, empty, validate) {
    this.key = key; this.empty = empty; this.validate = validate;
    this.value = empty(); this.blocked = null; this.unavailable = false; this.dirty = false;
  }
  Store.prototype.load = function () {
    var raw;
    try { raw = window.localStorage.getItem(this.key); this.unavailable = false; }
    catch (e) { this.unavailable = true; return; }
    this.blocked = null;
    if (raw === null) { this.value = this.empty(); return; }
    var data;
    try { data = JSON.parse(raw); } catch (e) { this.blocked = 'corrupt'; return; }
    if (!data || typeof data !== 'object' || Array.isArray(data) || typeof data.version !== 'number') { this.blocked = 'corrupt'; return; }
    if (data.version !== 1) { this.blocked = 'version'; return; }
    var v = this.validate(data);
    if (!v) { this.blocked = 'corrupt'; return; }
    this.value = v;
  };
  Store.prototype.write = function () {
    if (this.blocked) return false;                          // never overwrite unreadable data
    var payload = JSON.stringify(this.value);
    try {
      window.localStorage.setItem(this.key, payload);
      if (window.localStorage.getItem(this.key) !== payload) throw new Error('write not persisted');
    } catch (e) { this.dirty = true; return false; }
    this.dirty = false; this.unavailable = false;
    return true;
  };
  /** Re-read storage, apply fn, write. Another tab's changes are kept. */
  Store.prototype.mutate = function (fn) {
    this.load();
    if (this.blocked) return false;
    fn(this.value);
    return this.write();
  };
  Store.prototype.reset = function () {
    this.blocked = null; this.value = this.empty();
    return this.write();
  };

  function isObj(v) { return v && typeof v === 'object' && !Array.isArray(v); }

  var profileStore = new Store(PROFILE_KEY, function () { return { version: 1, profile: null }; }, function (d) {
    if (d.profile !== null && !isObj(d.profile)) return null;
    return { version: 1, profile: d.profile };
  });
  var draftsStore = new Store(DRAFTS_KEY, function () { return { version: 1, currentId: null, drafts: {} }; }, function (d) {
    if (!isObj(d.drafts)) return null;
    var ok = Object.keys(d.drafts).every(function (k) {
      var x = d.drafts[k];
      return isObj(x) && x.id === k && isObj(x.data) && (x.meta === undefined || isObj(x.meta));
    });
    return ok ? { version: 1, currentId: typeof d.currentId === 'string' ? d.currentId : null, drafts: d.drafts } : null;
  });

  // ---- bindings: path <-> form field ------------------------------------------
  function getPath(obj, path) {
    var cur = obj, parts = path.split('.');
    for (var i = 0; i < parts.length; i++) {
      if (cur === null || cur === undefined) return undefined;
      cur = cur[parts[i]];
    }
    return cur;
  }
  function setPath(obj, path, val) {
    var parts = path.split('.'), cur = obj;
    for (var i = 0; i < parts.length - 1; i++) {
      if (!isObj(cur[parts[i]])) cur[parts[i]] = {};
      cur = cur[parts[i]];
    }
    cur[parts[parts.length - 1]] = val;
  }
  function target(path) {
    return path.indexOf('meta.') === 0 ? { obj: draft, path: path } : { obj: draft.data, path: path };
  }

  function lines(s) {
    return String(s || '').split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
  }
  var LANG_RE = /^(.*?)[\s,;:-]*\b([ABC][12])\s*$/i;
  var KINDS = {
    text: { get: function (el) { return el.value; }, set: function (el, v) { el.value = v === undefined || v === null ? '' : v; } },
    num: {
      get: function (el) { var n = parseFloat(el.value); return el.value.trim() === '' || isNaN(n) ? undefined : n; },
      set: function (el, v) { el.value = typeof v === 'number' ? String(v) : ''; }
    },
    int: {
      get: function (el) { var n = parseInt(el.value, 10); return el.value.trim() === '' || isNaN(n) ? undefined : n; },
      set: function (el, v) { el.value = typeof v === 'number' ? String(v) : ''; }
    },
    bool: { get: function (el) { return el.checked; }, set: function (el, v) { el.checked = v === true; } },
    lines: {
      get: function (el) { return lines(el.value); },
      set: function (el, v) { el.value = Array.isArray(v) ? v.join('\n') : ''; }
    },
    bullets: {
      get: function (el) { var l = lines(el.value.replace(/^\s*[-•*]\s*/gm, '')); return l.length ? '- ' + l.join('\n- ') : ''; },
      set: function (el, v) { el.value = typeof v === 'string' ? Doc.bulletLines(v).join('\n') : ''; }
    },
    labels: {
      get: function (el) { var k = el.dataset.key; return lines(el.value).map(function (l) { var o = {}; o[k] = l; return o; }); },
      set: function (el, v) { var k = el.dataset.key; el.value = Array.isArray(v) ? v.map(function (o) { return o[k]; }).join('\n') : ''; }
    },
    langs: {
      get: function (el) {
        return lines(el.value).map(function (l) {
          var m = LANG_RE.exec(l);
          return m && m[1] ? { language: m[1].trim(), cefr: m[2].toUpperCase(), required: true } : { language: l, required: true };
        });
      },
      set: function (el, v) {
        el.value = Array.isArray(v) ? v.map(function (o) { return o.language + (o.cefr ? ' ' + o.cefr : ''); }).join('\n') : '';
      }
    },
    creds: {
      get: function (el) { return lines(el.value).map(function (l) { return { label: l, kind: /permis/i.test(l) ? 'permis_conducere' : 'altele', required: true }; }); },
      set: function (el, v) { el.value = Array.isArray(v) ? v.map(function (o) { return o.label; }).join('\n') : ''; }
    }
  };

  function kindOf(el) { return el.dataset.kind || 'text'; }

  function readField(el) {
    var path = el.dataset.path;
    if (kindOf(el) === 'checks') {
      var out = [];
      root.querySelectorAll('[data-path="' + path + '"]').forEach(function (b) { if (b.checked) out.push(b.value); });
      return out;
    }
    return KINDS[kindOf(el)].get(el);
  }
  function writeField(el, v) {
    if (kindOf(el) === 'checks') { el.checked = Array.isArray(v) && v.indexOf(el.value) >= 0; return; }
    KINDS[kindOf(el)].set(el, v);
  }
  function fillForm(except) {
    root.querySelectorAll('[data-path]').forEach(function (el) {
      if (el === except) return;
      var t = target(el.dataset.path);
      writeField(el, getPath(t.obj, t.path));
    });
  }

  // ---- state ------------------------------------------------------------------
  var draft = null;          // the working copy of the current draft
  var saveTimer = null;
  var pending = false;
  var armed = null;          // the two-step confirm of a destructive action

  function newId() { return 'd' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6); }
  function nowIso() { return new Date().toISOString(); }

  function newDraft() {
    var prof = profileStore.value.profile || {};
    var inst = prof.institution || {};
    var data = {};
    if ((prof.channels || []).length) data.application = { channels: prof.channels.slice() };
    if ((prof.documents || []).length) data.documents = { standard: prof.documents.slice() };
    var contact = {};
    if (inst.phone) contact.phone = inst.phone;
    if (inst.email) contact.email = inst.email;
    if (inst.address) contact.address = inst.address;
    if (Object.keys(contact).length) data.application_contact = contact;
    return { id: newId(), title: '', updatedAt: nowIso(), data: data, meta: { institution: clone(inst), interview: true } };
  }

  // ---- status, messages -------------------------------------------------------
  var statusEl = root.querySelector('[data-status]');
  function say(msg) { if (statusEl) { statusEl.textContent = ''; setTimeout(function () { statusEl.textContent = msg; }, 20); } }

  function showStorageState() {
    var unavailable = root.querySelector('[data-unavailable]');
    var formWrap = root.querySelector('[data-form-wrap]');
    var warn = root.querySelector('[data-nostorage]');
    var pBlocked = root.querySelector('[data-profile-blocked]');
    var dBlocked = !!draftsStore.blocked;
    if (unavailable) unavailable.hidden = !dBlocked;
    if (formWrap) formWrap.hidden = dBlocked;
    if (warn) warn.hidden = !(draftsStore.unavailable || draftsStore.dirty) || dBlocked;
    if (pBlocked) pBlocked.hidden = !profileStore.blocked;
    var saveProfile = root.querySelector('[data-action="save-profile"]');
    if (saveProfile) saveProfile.disabled = !!profileStore.blocked;
  }

  // ---- saving drafts ----------------------------------------------------------
  function snapshot() {
    var d = clone(draft);
    d.title = (d.data.occupation_title || '').trim();
    return d;
  }
  function flush() {
    clearTimeout(saveTimer); saveTimer = null;
    if (!pending || !draft) return;
    pending = false;
    var snap = snapshot();
    var ok = draftsStore.mutate(function (v) {
      if (!v.drafts[snap.id] && Object.keys(v.drafts).length >= MAX_DRAFTS) return;
      v.drafts[snap.id] = snap; v.currentId = snap.id;
    });
    showStorageState();
    if (ok) renderList();
  }
  function touch() {
    draft.updatedAt = nowIso();
    pending = true;
    if (!saveTimer) saveTimer = setTimeout(flush, SAVE_MS);
  }

  // ---- render: meter, calendar, preview, list ---------------------------------
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function renderMeter(data) {
    var q = quality(data);
    var score = root.querySelector('[data-meter-score]');
    if (score) score.textContent = String(q.score);
    var bar = root.querySelector('[data-meter-bar]');
    if (bar) bar.style.width = q.score + '%';
    var meter = root.querySelector('[data-meter]');
    if (meter) meter.setAttribute('aria-valuenow', String(q.score));
    var ul = root.querySelector('[data-meter-missing]');
    if (ul) {
      ul.textContent = '';
      QUALITY.forEach(function (it) {
        var v = q.checks[it[0]];
        if (v >= 1 || !it[3]) return;
        var li = el('li', CLS.missingLi);
        var a = el('a', CLS.link, it[3] + (v === 0.5 ? ' (acum sunt mai puțin de 10 zile)' : ''));
        a.href = '#sec-' + it[4];
        li.appendChild(a);
        ul.appendChild(li);
      });
      if (!ul.children.length) ul.appendChild(el('li', CLS.missingLi, 'Nu mai lipsește nimic din lista de verificare.'));
    }
    var nudge = root.querySelector('[data-salary-nudge]');
    if (nudge) nudge.hidden = !!data.baseSalary;
    var mand = root.querySelector('[data-mandatory]');
    if (mand) {
      mand.textContent = '';
      mandatory(data, draft.meta || {}).forEach(function (m) {
        var li = el('li', CLS.missingLi);
        var a = el('a', m[2] ? CLS.okLink : CLS.link, (m[2] ? 'Completat' : 'Lipsește') + ' — art. 18 alin. 2 ' + m[0] + '): ' + m[1]);
        a.href = '#sec-' + m[3];
        li.appendChild(a);
        mand.appendChild(li);
      });
    }
    return q;
  }

  function eventsOf() {
    var cal = draft.data.competition_calendar;
    return cal && Array.isArray(cal.events) ? cal.events : [];
  }
  function eventMap() {
    var m = {};
    eventsOf().forEach(function (e) { if (e.date) m[e.stage] = e.date; });
    return m;
  }

  function renderCalendar() {
    var body = root.querySelector('[data-cal-body]');
    if (!body) return;
    var tpl = root.querySelector('template[data-cal-row]');
    var pub = getPath(draft.data, 'application.window_start');
    var status = getPath(draft.data, 'contract.post_status');
    var meta = draft.meta || {};
    var events = eventsOf();
    var map = eventMap();
    var proposed = meta.proposed || {};
    var year = parseIso(pub) ? parseIso(pub).getUTCFullYear() : null;
    var note = root.querySelector('[data-cal-year-note]');
    if (note) note.hidden = !(year && !Hol.knownYear(year));
    var empty = root.querySelector('[data-cal-empty]');
    if (empty) empty.hidden = events.length > 0;
    // Rebuild the rows only when the list of stages changes. A rebuild during typing
    // would take the focus from the date field.
    var sig = events.map(function (e) { return e.stage; }).join(',');
    if (body.dataset.sig !== sig) {
      body.textContent = '';
      body.dataset.sig = sig;
      events.forEach(function (e) {
        if (!STAGE_BY[e.stage]) return;
        var row = tpl.content.firstElementChild.cloneNode(true);
        row.dataset.stage = e.stage;
        row.querySelector('[data-cal-label]').textContent = Doc.STAGE_LABELS[e.stage] || e.stage;
        row.querySelector('[data-cal-art]').textContent = STAGE_BY[e.stage].art || '';
        row.querySelector('[data-ev-date]').setAttribute('aria-label', Doc.STAGE_LABELS[e.stage] + ' — data');
        row.querySelector('[data-ev-time]').setAttribute('aria-label', Doc.STAGE_LABELS[e.stage] + ' — ora');
        body.appendChild(row);
      });
    }
    events.forEach(function (e) {
      var row = body.querySelector('[data-stage="' + e.stage + '"]');
      if (!row) return;
      var date = row.querySelector('[data-ev-date]');
      var time = row.querySelector('[data-ev-time]');
      if (document.activeElement !== date) date.value = e.date || '';
      if (document.activeElement !== time) time.value = e.time || '';
      var tag = row.querySelector('[data-cal-tag]');
      if (e.date && proposed[e.stage] === e.date) tag.textContent = 'propunere';
      else if (e.date && proposed[e.stage]) tag.textContent = 'modificat';
      else tag.textContent = '';
      var warn = row.querySelector('[data-cal-warn]');
      var msg = e.date ? checkStage(e.stage, map, pub, status, meta) : '';
      warn.textContent = msg;
      warn.hidden = !msg;
    });
  }

  function setEvents(map, keepProposed) {
    var meta = draft.meta;
    var stages = stagesFor(meta);
    var old = {};
    eventsOf().forEach(function (e) { old[e.stage] = e; });
    var events = stages.map(function (s) {
      var o = { stage: s.stage, date: map && map[s.stage] ? map[s.stage] : '' };
      if (old[s.stage] && old[s.stage].time) o.time = old[s.stage].time;
      return o;
    });
    if (!isObj(draft.data.competition_calendar)) draft.data.competition_calendar = {};
    draft.data.competition_calendar.events = events;
    if (!keepProposed) meta.proposed = map || {};
  }

  function propose() {
    var pub = getPath(draft.data, 'application.window_start');
    var status = getPath(draft.data, 'contract.post_status');
    var map = proposeCalendar(pub, status, draft.meta.interview !== false);
    if (!map) { say('Alege mai întâi data publicării.'); return; }
    if (!status) { say('Alege mai întâi statutul postului: vacant sau temporar vacant.'); return; }
    setEvents(map);
    touch();
    refresh();
    say('Am propus calendarul. Poți schimba orice dată.');
  }

  function renderPreview(data) {
    var box = root.querySelector('[data-preview]');
    if (!box) return;
    box.textContent = '';
    Doc.sections(draft, data).forEach(function (b) {
      if (b.t === 'h1') box.appendChild(el('h3', CLS.pvH1, b.text));
      else if (b.t === 'h2') box.appendChild(el('h4', CLS.pvH2, b.text));
      else if (b.t === 'p') box.appendChild(el('p', CLS.pvP, b.text));
      else if (b.t === 'kv') {
        var dl = el('dl', CLS.pvDl);
        b.rows.forEach(function (r) { dl.appendChild(el('dt', CLS.pvDt, r[0])); dl.appendChild(el('dd', CLS.pvDd, r[1])); });
        box.appendChild(dl);
      } else if (b.t === 'ul') {
        var ul = el('ul', CLS.pvUl);
        b.items.forEach(function (i) { ul.appendChild(el('li', '', i)); });
        box.appendChild(ul);
      } else if (b.t === 'table') {
        var wrap = el('div', CLS.pvWrap);
        var t = el('table', CLS.pvTable);
        var tr = el('tr');
        b.head.forEach(function (h) { tr.appendChild(el('th', CLS.pvTh, h)); });
        t.appendChild(el('thead')).appendChild(tr);
        var tb = el('tbody');
        b.rows.forEach(function (r) {
          var row = el('tr');
          r.forEach(function (c) { row.appendChild(el('td', CLS.pvTd, c)); });
          tb.appendChild(row);
        });
        t.appendChild(tb);
        wrap.appendChild(t);
        box.appendChild(wrap);
      }
    });
  }

  function renderList() {
    var ul = root.querySelector('[data-draft-list]');
    var tpl = root.querySelector('template[data-draft-item]');
    if (!ul || !tpl) return;
    ul.textContent = '';
    var all = {};
    Object.keys(draftsStore.value.drafts).forEach(function (k) { all[k] = draftsStore.value.drafts[k]; });
    if (draft && !all[draft.id]) all[draft.id] = snapshot();
    var items = Object.keys(all).map(function (k) { return all[k]; }).sort(function (a, b) { return a.updatedAt < b.updatedAt ? 1 : -1; });
    var count = root.querySelector('[data-draft-count]');
    if (count) count.textContent = items.length + ' din ' + MAX_DRAFTS;
    items.forEach(function (d) {
      var li = tpl.content.firstElementChild.cloneNode(true);
      li.dataset.id = d.id;
      var title = d.title || (d.data && d.data.occupation_title) || 'Ciornă fără titlu';
      li.querySelector('[data-di-title]').textContent = title;
      var when = new Date(d.updatedAt);
      li.querySelector('[data-di-date]').textContent = isNaN(when) ? '' : when.toLocaleString('ro-RO', { dateStyle: 'short', timeStyle: 'short' });
      var open = li.querySelector('[data-action="open-draft"]');
      open.dataset.id = d.id;
      if (draft && d.id === draft.id) { li.querySelector('[data-di-current]').hidden = false; open.disabled = true; }
      li.querySelector('[data-action="duplicate-draft"]').dataset.id = d.id;
      li.querySelector('[data-action="delete-draft"]').dataset.id = d.id;
      ul.appendChild(li);
    });
  }

  var pageTimer = null;
  function refresh() {
    var data = buildData(draft);
    renderMeter(data);
    renderCalendar();
    clearTimeout(pageTimer);
    pageTimer = setTimeout(function () { renderPreview(buildData(draft)); }, 120);
  }

  // ---- events -----------------------------------------------------------------
  root.addEventListener('input', onInput);
  root.addEventListener('change', onInput);
  function onInput(ev) {
    var t = ev.target;
    if (!(t instanceof HTMLElement)) return;
    if (t.matches('[data-ev-date], [data-ev-time]')) {
      var row = t.closest('[data-stage]');
      var stage = row.dataset.stage;
      var e = eventsOf().filter(function (x) { return x.stage === stage; })[0];
      if (!e) return;
      if (t.matches('[data-ev-date]')) e.date = t.value; else e.time = t.value;
      touch(); refresh();
      return;
    }
    if (!t.dataset || !t.dataset.path) return;
    var tg = target(t.dataset.path);
    setPath(tg.obj, tg.path, readField(t));
    var path = t.dataset.path;
    // The first date or status with an empty calendar proposes the stages.
    if ((path === 'application.window_start' || path === 'contract.post_status') && !eventsOf().length) {
      var map = proposeCalendar(getPath(draft.data, 'application.window_start'), getPath(draft.data, 'contract.post_status'), draft.meta.interview !== false);
      if (map && getPath(draft.data, 'contract.post_status')) setEvents(map);
    }
    if (path === 'meta.interview' && eventsOf().length) {
      setEvents(eventMap(), true);
    }
    fillForm(t);
    touch(); refresh();
  }

  root.addEventListener('click', function (ev) {
    var b = ev.target.closest && ev.target.closest('[data-action]');
    if (!b || !root.contains(b)) return;
    var a = b.dataset.action, id = b.dataset.id;
    if (a !== 'delete-draft' && a !== 'reset-drafts') armed = null;
    ACTIONS[a] && ACTIONS[a](b, id);
  });

  function confirmTwice(b, key, askText, doIt) {
    if (armed === key) { armed = null; doIt(); return; }
    armed = key;
    var old = b.textContent;
    b.textContent = askText;
    setTimeout(function () { if (armed === key) { armed = null; b.textContent = old; } }, 4000);
  }

  function openDraft(id) {
    flush();
    draftsStore.load();
    var d = draftsStore.value.drafts[id];
    if (!d) return;
    draft = clone(d);
    if (!isObj(draft.meta)) draft.meta = {};
    draftsStore.mutate(function (v) { v.currentId = id; });
    fillForm(); refresh(); renderList();
    renderPreview(buildData(draft));
  }

  var ACTIONS = {
    'new-draft': function () {
      flush();
      draftsStore.load();
      if (draftsStore.blocked) return;
      if (Object.keys(draftsStore.value.drafts).length >= MAX_DRAFTS) { say('Ai ' + MAX_DRAFTS + ' ciorne. Șterge una înainte să faci alta.'); return; }
      draft = newDraft();
      pending = true; flush();
      fillForm(); refresh(); renderList();
      say('Ciornă nouă, completată din profilul instituției.');
    },
    'duplicate-draft': function (b, id) {
      flush();
      draftsStore.load();
      if (draftsStore.blocked) return;
      var src = draft && draft.id === id ? snapshot() : draftsStore.value.drafts[id];
      if (!src) return;
      if (Object.keys(draftsStore.value.drafts).length >= MAX_DRAFTS) { say('Ai ' + MAX_DRAFTS + ' ciorne. Șterge una înainte să faci alta.'); return; }
      var copy = clone(src);
      copy.id = newId(); copy.updatedAt = nowIso();
      draftsStore.mutate(function (v) { v.drafts[copy.id] = copy; v.currentId = copy.id; });
      draft = clone(copy);
      fillForm(); refresh(); renderList();
      say('Am duplicat ciorna.');
    },
    'delete-draft': function (b, id) {
      confirmTwice(b, 'del:' + id, 'Confirmă ștergerea', function () {
        flush();
        draftsStore.mutate(function (v) { delete v.drafts[id]; if (v.currentId === id) v.currentId = null; });
        if (draft && draft.id === id) {
          var rest = Object.keys(draftsStore.value.drafts);
          if (rest.length) { openDraft(rest[0]); }
          else { draft = newDraft(); fillForm(); refresh(); }
        }
        renderList();
        say('Am șters ciorna.');
      });
    },
    'open-draft': function (b, id) { openDraft(id); say('Ciorna este deschisă.'); },
    'save-profile': function () {
      var inst = clone(draft.meta.institution) || {};
      var prof = {
        institution: inst,
        channels: (getPath(draft.data, 'application.channels') || []).slice(),
        documents: (getPath(draft.data, 'documents.standard') || []).slice()
      };
      var ok = profileStore.mutate(function (v) { v.profile = prof; });
      say(ok ? 'Am salvat profilul instituției în acest browser.' : 'Nu am putut salva profilul.');
      showStorageState();
    },
    'propose': propose,
    'export-all': function () {
      flush();
      draftsStore.load(); profileStore.load();
      if (draftsStore.blocked) { say('Datele nu pot fi citite, deci nu le exportăm.'); return; }
      var out = {
        format: EXPORT_FORMAT, exportedAt: nowIso(),
        profile: profileStore.blocked ? null : profileStore.value.profile,
        drafts: Object.keys(draftsStore.value.drafts).map(function (k) { return draftsStore.value.drafts[k]; })
      };
      download(new Blob([JSON.stringify(out, null, 2)], { type: 'application/json' }), 'anunturi-ciorne-' + Doc.today() + '.json');
      say('Am descărcat profilul și ciornele.');
    },
    'download-docx': function (b) {
      var data = buildData(draft);
      b.disabled = true;
      Doc.buildDocx(draft, data).then(function (r) {
        download(r.blob, r.fileName);
        say('Am descărcat ' + r.fileName + '.');
      }).catch(function (e) {
        say('Nu am putut crea fișierul .docx. ' + (e && e.message ? e.message : ''));
      }).then(function () { b.disabled = false; });
    },
    'download-json': function () {
      var data = buildData(draft);
      var json = JSON.stringify(Doc.buildPayload(data, null, Doc.employerBlock(draft.meta)), null, 2);
      download(new Blob([json], { type: 'application/json' }), 'anunt-' + Doc.slug(data.occupation_title) + '-' + Doc.today() + '.json');
    },
    'reset-drafts': function (b) {
      confirmTwice(b, 'reset', 'Confirmă: șterge datele nefolosibile', function () {
        draftsStore.reset();
        init();
      });
    }
  };

  var importInput = root.querySelector('[data-import]');
  if (importInput) importInput.addEventListener('change', function () {
    var f = importInput.files && importInput.files[0];
    if (!f) return;
    var r = new FileReader();
    r.onload = function () { doImport(String(r.result)); importInput.value = ''; };
    r.onerror = function () { say('Nu am putut citi fișierul.'); };
    r.readAsText(f);
  });

  function doImport(text) {
    var d;
    try { d = JSON.parse(text); } catch (e) { say('Fișierul nu este JSON valid.'); return; }
    if (!isObj(d) || d.format !== EXPORT_FORMAT || !Array.isArray(d.drafts)) { say('Fișierul nu este un export al acestei pagini.'); return; }
    flush();
    draftsStore.load();
    if (draftsStore.blocked) { say('Datele din browser nu pot fi citite, deci nu importăm peste ele.'); return; }
    var added = 0, skipped = 0;
    draftsStore.mutate(function (v) {
      d.drafts.forEach(function (x) {
        if (!isObj(x) || !isObj(x.data)) { skipped++; return; }
        if (Object.keys(v.drafts).length >= MAX_DRAFTS) { skipped++; return; }
        var c = clone(x);
        if (typeof c.id !== 'string' || v.drafts[c.id]) c.id = newId();   // a draft with the same id stays; the imported one gets a new id
        if (!isObj(c.meta)) c.meta = {};
        c.updatedAt = typeof c.updatedAt === 'string' ? c.updatedAt : nowIso();
        v.drafts[c.id] = c; added++;
      });
    });
    if (isObj(d.profile) && !profileStore.blocked) {
      profileStore.mutate(function (v) { if (!v.profile) v.profile = d.profile; });
    }
    renderList();
    say('Am importat ' + added + ' ciorne.' + (skipped ? ' Am sărit ' + skipped + ' (limita este ' + MAX_DRAFTS + ' sau datele sunt greșite).' : ''));
  }

  function download(blob, name) {
    var url = URL.createObjectURL(blob);
    var a = document.createElement('a');
    a.href = url; a.download = name;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
  }

  window.addEventListener('pagehide', flush);
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') flush(); });

  // ---- start ------------------------------------------------------------------
  function init() {
    profileStore.load();
    draftsStore.load();
    showStorageState();
    draft = null;
    if (!draftsStore.blocked) {
      var v = draftsStore.value;
      var cur = v.currentId && v.drafts[v.currentId] ? v.currentId : Object.keys(v.drafts).sort(function (a, b) { return v.drafts[a].updatedAt < v.drafts[b].updatedAt ? 1 : -1; })[0];
      draft = cur ? clone(v.drafts[cur]) : newDraft();
      if (!isObj(draft.meta)) draft.meta = {};
      fillForm();
      refresh();
      renderList();
      renderPreview(buildData(draft));
    }
    root.dataset.ready = '1';
  }
  init();

  window.employerBuilder = {
    addWorkingDays: addWorkingDays, windowEnd: windowEnd, nextWorkingDay: nextWorkingDay, isWorkingDay: isWorkingDay,
    proposeCalendar: proposeCalendar, countInclusive: countInclusive,
    buildData: buildData, quality: quality, QUALITY: QUALITY,
    current: function () { return draft; }
  };
})();
