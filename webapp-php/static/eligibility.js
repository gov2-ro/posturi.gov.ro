/* UX-10 — "Pot aplica?" preparation panel on the detail page.
 *
 * A deterministic, per-requirement comparison of what the reader says about themselves
 * against the stated requirements of ONE role. It is a preparation aid, never a verdict:
 * every requirement ends as match ("Îndeplinit"), missing ("Lipsește") or unknown
 * ("De verificat"), each with a concrete explanation, and nothing is ever summed into
 * "eligible" or "not eligible". Anything the data cannot establish is unknown.
 *
 * Privacy: the answers live in the form controls and nowhere else. This file never writes
 * to localStorage, sessionStorage, cookies or the URL, makes no request of any kind and
 * loads nothing. The requirements it compares against are embedded in the page by the
 * server (partials/eligibility_panel.php, <script id="eligibility-data">).
 *
 * Layout of the file: the pure comparison (compare() and its helpers, no DOM; exported
 * through module.exports so tests can run the rule matrix in Node), then the DOM wiring,
 * which only runs in a browser. Text reaches the page through textContent only; the row
 * markup (and every class on it) is cloned from <template>s the server renders.
 * The rules, and the ones deliberately left unresolved, are listed in
 * docs/specs/2026-10-10-follow-ups/04-eligibility-panel.md.
 */
(function (root) {
  'use strict';

  /* POLICY SWITCH. When true (the shipped policy), a requirement whose source evidence is not
   * `verified` — unverified, absent, or no evidence row at all — can only ever be "De verificat",
   * for every kind (education, experience, documents), whatever the reader answered. That is
   * why documents/licences are always "De verificat" today: the extraction has no evidence field
   * for them. Setting it to false lets a requirement without a verified quote be compared on
   * the extracted value alone, which for an undecomposed posting means trusting a regex
   * inference. It changes what the panel claims, so it needs pax's review; compare() takes
   * `cfg.requireVerified` so tests can exercise both settings. */
  var REQUIRE_VERIFIED_EVIDENCE = true;

  var EPS = 1e-9;
  var MAX_YEARS = 80;

  var MSG = {
    notVerified: 'Cerința nu are un citat verificat în sursă; verificați anunțul.',
    alternatives: 'Anunțul indică variante alternative; verificați care vi se aplică.',
    advantage: 'Anunțul prezintă această cerință ca avantaj, nu drept condiție; verificați anunțul.',
    eduUnidentified: 'Nivelul de studii nu a fost identificat.',
    eduNoAnswer: 'Nu ați indicat nivelul dvs. de studii.',
    expUnidentified: 'Cerința de experiență nu a fost identificată.',
    expNone: 'Nu se cere experiență.',
    expConflict: 'Datele extrase despre experiență se contrazic (un minim de ani și „fără experiență”); verificați anunțul.',
    expNeedSpecialty: 'Completați vechimea în specialitate.',
    expNeedTotal: 'Completați vechimea totală.',
    expNeedAny: 'Completați vechimea totală și, dacă o aveți, vechimea în specialitate.',
    expUnclearSpecialty: 'Nu reiese dacă se cere vechime în specialitate.',
    expSpecialtyFlag: 'Anunțul vorbește de specialitate sau domeniu, dar extragerea nu a marcat vechimea ca fiind în specialitate; verificați anunțul.',
    expBadNumber: 'Introduceți anii ca număr, 0 sau mai mult (de exemplu 2 sau 1,5).',
    expInconsistent: 'Vechimea în specialitate nu poate depăși vechimea totală; verificați răspunsurile.',
    credNone: 'Nu ați precizat dacă dețineți documentul cerut.',
    credYes: 'Ați declarat că dețineți documentul cerut.',
    credNo: 'Ați declarat că nu dețineți documentul cerut.',
    generalLabel: 'Condiții generale (cetățenie, vârstă, stare de sănătate, cazier etc.) — verificați în anunț.',
    generalWhy: 'Nu sunt câmpuri comparate de această verificare.',
    roleReset: 'Răspunsurile pentru documente au fost resetate pentru noul post.',
    allReset: 'Răspunsurile au fost șterse.'
  };

  var SAID = { da: 'Da', nu: 'Nu', nu_stiu: 'Nu știu' };

  // ---- pure helpers ----------------------------------------------------------
  function isNum(v) { return typeof v === 'number' && isFinite(v); }
  function verified(ev) { return !!ev && ev.status === 'verified'; }

  /** Rank of a study level in cfg.levels (lowest first), or -1 when it is not one of them. */
  function rankOf(cfg, value) {
    var levels = (cfg && cfg.levels) || [];
    for (var i = 0; i < levels.length; i++) if (levels[i].value === value) return i;
    return -1;
  }
  function levelLabel(cfg, value) {
    var levels = (cfg && cfg.levels) || [];
    for (var i = 0; i < levels.length; i++) if (levels[i].value === value) return levels[i].label;
    return String(value);
  }

  /** Romanian year count: 0.5 -> "6 luni", 1 -> "1 an", 2 -> "2 ani", 20 -> "20 de ani", 1.5 -> "1,5 ani". */
  function yearsLabel(n) {
    if (n === 0) return '0 ani';
    if (n > 0 && n < 1) {
      var months = Math.max(1, Math.round(n * 12));
      return months === 1 ? '1 lună' : months + ' luni';
    }
    if (n === Math.floor(n)) {
      if (n === 1) return '1 an';
      var mod = n % 100;
      return (mod === 0 || mod >= 20) ? n + ' de ani' : n + ' ani';
    }
    return String(Math.round(n * 10) / 10).replace('.', ',') + ' ani';
  }

  /** The reader's number of years from raw input text: {empty}, {invalid} or {value}. */
  function parseYears(raw) {
    var s = String(raw === null || raw === undefined ? '' : raw).trim().replace(',', '.');
    if (s === '') return { empty: true };
    if (!/^\d+(\.\d+)?$/.test(s)) return { invalid: true };
    var n = parseFloat(s);
    return isFinite(n) && n <= MAX_YEARS ? { value: n } : { invalid: true };
  }

  /** The first reason a requirement cannot be compared at all, or null. Order: evidence, alternatives, advantage. */
  function gate(ev, alternatives, advantage, requireVerified) {
    if (requireVerified && !verified(ev)) return MSG.notVerified;
    if (alternatives) return MSG.alternatives;
    if (advantage) return MSG.advantage;
    return null;
  }

  function row(id, kind, label, state, explanation, ev) {
    return { id: id, kind: kind, label: label, state: state, explanation: explanation, anchor: (ev && ev.anchor) || null };
  }

  // ---- the rules -------------------------------------------------------------
  function educationRows(role, ans, cfg, rv) {
    var req = role.study_level;
    var ev = role.evidence && role.evidence.education;
    var out = [];
    var reqRank = req ? rankOf(cfg, req) : -1;
    var r;
    if (reqRank < 0) {
      r = row('education', 'education', 'Studii', 'unknown', MSG.eduUnidentified, ev);
    } else {
      var label = 'Studii minime: ' + levelLabel(cfg, req);
      var g = gate(ev, role.alternatives && role.alternatives.education, role.advantage && role.advantage.education, rv);
      var mine = ans.education || '';
      var myRank = mine ? rankOf(cfg, mine) : -1;
      if (g) {
        r = row('education', 'education', label, 'unknown', g, ev);
      } else if (myRank < 0) {
        r = row('education', 'education', label, 'unknown', MSG.eduNoAnswer, ev);
      } else if (myRank >= reqRank) {
        r = row('education', 'education', label, 'match', 'Nivel: ' + levelLabel(cfg, mine) + ' — cel puțin ' + levelLabel(cfg, req), ev);
      } else {
        r = row('education', 'education', label, 'missing', 'Nivel: ' + levelLabel(cfg, mine) + ' — se cere cel puțin ' + levelLabel(cfg, req), ev);
      }
    }
    out.push(r);
    // A field of study is a separate requirement, never settled by the level and never by an EQF
    // number: whether a diploma is accepted for the post needs a reviewed equivalence table.
    var fields = (role.isced || []).filter(function (f) { return typeof f === 'string' && f !== ''; });
    if (fields.length) {
      out.push(row('field', 'field', 'Domeniul de studii: ' + fields.join(', '), 'unknown',
        'Domeniul cerut: ' + fields.join(', ') + '. Echivalența diplomei nu este verificată automat.', ev));
    }
    return out;
  }

  function experienceRow(role, ans, rv) {
    var ev = role.evidence && role.evidence.experience;
    var years = isNum(role.exp_years) ? role.exp_years : null;
    var noneFlag = role.exp_none_required === 1;
    var inSpec = role.exp_in_specialty === 1 ? 1 : (role.exp_in_specialty === 0 ? 0 : null);

    if (years === null && !noneFlag) {
      return row('experience', 'experience', 'Experiență', 'unknown', MSG.expUnidentified, ev);
    }
    if (noneFlag && years !== null && years > 0) {
      return row('experience', 'experience', 'Experiență: min. ' + yearsLabel(years), 'unknown', MSG.expConflict, ev);
    }
    var none = years === 0 || (years === null && noneFlag);   // an explicit zero, distinct from "not stated"
    var label = none ? 'Experiență: fără experiență'
      : 'Experiență: min. ' + yearsLabel(years) + (inSpec === 1 ? ', în specialitate' : '');
    var g = gate(ev, role.alternatives && role.alternatives.experience, false, rv);
    if (g) return row('experience', 'experience', label, 'unknown', g, ev);
    if (none) return row('experience', 'experience', label, 'match', MSG.expNone, ev);

    // The extraction's "not in the specialty" is its default when the sentence is silent; if the
    // sentence itself speaks of specialty or field the flag cannot be trusted, so it is unknown.
    if (inSpec === 0 && role.exp_specialty_conflict) {
      return row('experience', 'experience', label, 'unknown', MSG.expSpecialtyFlag, ev);
    }
    var T = parseYears(ans.total);
    var S = parseYears(ans.specialty);
    if (T.invalid || S.invalid) return row('experience', 'experience', label, 'unknown', MSG.expBadNumber, ev);
    if (T.value !== undefined && S.value !== undefined && S.value > T.value + EPS) {
      return row('experience', 'experience', label, 'unknown', MSG.expInconsistent, ev);
    }
    var need = yearsLabel(years);
    function verdict(kind, mine) {
      var head = (kind === 'specialty' ? 'Vechime în specialitate: ' : 'Vechime totală: ') + yearsLabel(mine);
      return mine + EPS >= years
        ? row('experience', 'experience', label, 'match', head + ' — cel puțin ' + need, ev)
        : row('experience', 'experience', label, 'missing', head + ' — se cere cel puțin ' + need, ev);
    }
    if (inSpec === 1) {
      return S.empty ? row('experience', 'experience', label, 'unknown', MSG.expNeedSpecialty, ev) : verdict('specialty', S.value);
    }
    if (inSpec === 0) {
      return T.empty ? row('experience', 'experience', label, 'unknown', MSG.expNeedTotal, ev) : verdict('total', T.value);
    }
    // Specialty not stated either way: only specialty years can establish a match, only a short
    // total can establish a miss (no kind of experience can then reach the minimum).
    if (S.value !== undefined && S.value + EPS >= years) return verdict('specialty', S.value);
    if (T.value !== undefined && T.value + EPS < years) return verdict('total', T.value);
    if (T.empty && S.empty) return row('experience', 'experience', label, 'unknown', MSG.expNeedAny, ev);
    return row('experience', 'experience', label, 'unknown', MSG.expUnclearSpecialty, ev);
  }

  function credentialRow(cred, k, ans, rv) {
    var said = (ans.credentials && ans.credentials[k]) || 'nu_stiu';
    if (!SAID[said]) said = 'nu_stiu';
    var label = 'Document: ' + cred.label;
    var g = gate(cred.evidence, cred.alternatives, cred.advantage, rv);
    if (g) return row('cred-' + k, 'credential', label, 'unknown', g + ' Ați răspuns: ' + SAID[said] + '.', cred.evidence);
    // Exact self-assertion about the source's own label: no aliasing, no similarity.
    if (said === 'da') return row('cred-' + k, 'credential', label, 'match', MSG.credYes, cred.evidence);
    if (said === 'nu') return row('cred-' + k, 'credential', label, 'missing', MSG.credNo, cred.evidence);
    return row('cred-' + k, 'credential', label, 'unknown', MSG.credNone, cred.evidence);
  }

  /**
   * Compare one role with the reader's answers. Uses only `role` — never another role's data.
   *
   * @param role     one entry of the embedded data's `roles`
   * @param answers  {education: 'licenta'|'', total: '3', specialty: '', credentials: {0: 'da'|'nu'|'nu_stiu'}}
   * @param cfg      {levels: [{value, label}] lowest first, requireVerified?: boolean}
   * @return {rows: [{id, kind, label, state, explanation, anchor}], tally: {match, missing, unknown}}
   */
  function compare(role, answers, cfg) {
    cfg = cfg || {};
    var rv = cfg.requireVerified === undefined ? REQUIRE_VERIFIED_EVIDENCE : !!cfg.requireVerified;
    var ans = answers || {};
    var rows = educationRows(role, ans, cfg, rv);
    rows.push(experienceRow(role, ans, rv));
    (role.credentials || []).forEach(function (c, k) { rows.push(credentialRow(c, k, ans, rv)); });
    rows.push({ id: 'general', kind: 'general', label: MSG.generalLabel, state: 'unknown', explanation: MSG.generalWhy, anchor: null });
    var tally = { match: 0, missing: 0, unknown: 0 };
    rows.forEach(function (r) { tally[r.state]++; });
    return { rows: rows, tally: tally };
  }

  function tallyText(t) {
    return t.match + ' îndeplinite · ' + t.missing + ' lipsă · ' + t.unknown + ' de verificat';
  }

  var api = {
    compare: compare, tallyText: tallyText, yearsLabel: yearsLabel, parseYears: parseYears,
    REQUIRE_VERIFIED_EVIDENCE: REQUIRE_VERIFIED_EVIDENCE, MESSAGES: MSG
  };
  if (typeof module === 'object' && module && module.exports) module.exports = api;
  if (typeof document === 'undefined') return;

  // ---- the panel -------------------------------------------------------------
  function boot() {
    var panel = document.querySelector('[data-eligibility]');
    var dataEl = document.getElementById('eligibility-data');
    if (!panel || !dataEl || panel.getAttribute('data-eligibility-ready')) return;
    var data;
    try { data = JSON.parse(dataEl.textContent); } catch (e) { return; }   // leave the no-JavaScript note in place
    if (!data || !Array.isArray(data.roles) || !data.roles.length || !Array.isArray(data.levels)) return;

    var app = panel.querySelector('[data-eligibility-app]');
    var nojs = panel.querySelector('[data-eligibility-nojs]');
    var roleSel = panel.querySelector('[data-field="role"]');
    var eduSel = panel.querySelector('[data-field="education"]');
    var totalIn = panel.querySelector('[data-field="total"]');
    var specIn = panel.querySelector('[data-field="specialty"]');
    var note = panel.querySelector('[data-note]');
    var tally = panel.querySelector('[data-tally]');
    var list = panel.querySelector('[data-results]');
    if (!app || !eduSel || !totalIn || !specIn || !tally || !list) return;
    var cfg = { levels: data.levels };
    var timer = null;

    function currentIndex() {
      var i = roleSel ? Number(roleSel.value) : 0;
      return i >= 0 && i < data.roles.length ? i : 0;
    }
    function credentialBox(i) { return app.querySelector('[data-credentials-for="' + i + '"]'); }

    function readAnswers() {
      var creds = {};
      var box = credentialBox(currentIndex());
      if (box) {
        Array.prototype.forEach.call(box.querySelectorAll('[data-credential]'), function (fs) {
          var on = fs.querySelector('input[type="radio"]:checked');
          creds[Number(fs.getAttribute('data-credential'))] = on ? on.value : 'nu_stiu';
        });
      }
      return { education: eduSel.value, total: totalIn.value, specialty: specIn.value, credentials: creds };
    }

    function say(text) {            // the node is replaced, so an identical message is announced again
      while (note && note.firstChild) note.removeChild(note.firstChild);
      if (note && text) note.appendChild(document.createTextNode(text));
    }

    function makeRow(r) {
      var tpl = panel.querySelector('template[data-row-template="' + r.state + '"]');
      var li = tpl.content.firstElementChild.cloneNode(true);
      li.setAttribute('data-row-id', r.id);
      li.setAttribute('data-state', r.state);
      li.querySelector('[data-slot="label"]').textContent = r.label;
      li.querySelector('[data-slot="explain"]').textContent = r.explanation;
      var target = r.anchor ? document.getElementById(r.anchor) : null;
      var src = li.querySelector('[data-slot="source"]');
      if (target) {
        var a = src.querySelector('a');
        a.setAttribute('href', '#' + r.anchor);
        a.setAttribute('aria-label', 'vezi sursa: ' + r.label);
        src.hidden = false;
      }
      li.setAttribute('data-sig', [r.state, r.label, r.explanation, target ? r.anchor : ''].join('\u0001'));
      return li;
    }

    function paint(rows) {          // rows are replaced only where they changed, so a live region speaks only the change
      var cur = Array.prototype.slice.call(list.children);
      var same = cur.length === rows.length && rows.every(function (r, i) { return cur[i].getAttribute('data-row-id') === r.id; });
      if (!same) {
        while (list.firstChild) list.removeChild(list.firstChild);
        rows.forEach(function (r) { list.appendChild(makeRow(r)); });
        return;
      }
      rows.forEach(function (r, i) {
        var fresh = makeRow(r);
        if (fresh.getAttribute('data-sig') !== cur[i].getAttribute('data-sig')) list.replaceChild(fresh, cur[i]);
      });
    }

    function render() {
      var res = compare(data.roles[currentIndex()], readAnswers(), cfg);
      var text = tallyText(res.tally);
      if (tally.textContent !== text) tally.textContent = text;
      paint(res.rows);
    }

    function showCredentials() {
      var idx = String(currentIndex());
      Array.prototype.forEach.call(app.querySelectorAll('[data-credentials-for]'), function (box) {
        box.hidden = box.getAttribute('data-credentials-for') !== idx;
      });
    }
    function clearCredentialAnswers() {
      Array.prototype.forEach.call(app.querySelectorAll('input[type="radio"][value="nu_stiu"]'), function (r) { r.checked = true; });
    }

    function onRoleChange() {
      // Education and experience are about the reader and stay; the documents were answered
      // against the previous role's own list, so they are cleared, never carried over.
      clearCredentialAnswers();
      showCredentials();
      say(MSG.roleReset);
      render();
    }
    function reset() {
      if (roleSel) roleSel.value = '0';
      eduSel.value = '';
      totalIn.value = '';
      specIn.value = '';
      clearCredentialAnswers();
      showCredentials();
      say(MSG.allReset);
      render();
    }

    app.addEventListener('change', function (ev) {
      var t = ev.target;
      if (t === roleSel) return onRoleChange();
      if (t === totalIn || t === specIn) clearTimeout(timer);
      say('');
      render();
    });
    app.addEventListener('input', function (ev) {
      var t = ev.target;
      if (t !== totalIn && t !== specIn) return;      // selects and radios report through `change`
      clearTimeout(timer);
      timer = setTimeout(function () { say(''); render(); }, 250);
    });
    app.addEventListener('click', function (ev) {
      var t = ev.target;
      if (!t || !t.closest) return;
      if (t.closest('[data-action="reset"]')) { ev.preventDefault(); reset(); return; }
      var a = t.closest('a[data-source-link]');
      if (!a) return;
      var target = document.getElementById((a.getAttribute('href') || '').slice(1));
      if (!target) return;
      ev.preventDefault();                              // no #fragment in the address bar
      if (target.tagName === 'DETAILS') target.open = true;
      var summary = target.querySelector('summary') || target;
      summary.scrollIntoView({ block: 'center' });
      summary.focus();
    });

    showCredentials();
    render();
    if (nojs) nojs.hidden = true;
    app.hidden = false;
    panel.setAttribute('data-eligibility-ready', '1');
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})(typeof window !== 'undefined' ? window : this);
