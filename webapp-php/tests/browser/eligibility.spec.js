// UX-10 browser checks: the "Pot aplica?" preparation panel on the detail page.
//
// Two layers, both without network or model calls:
//   1. the pure rule matrix: `static/eligibility.js` exports compare() through module.exports when it
//      is loaded by Node, so every comparison rule is exercised directly (desktop project only);
//   2. the panel in a real browser at 320/375/1280: keyboard operation, role switching, reset, the
//      source links, the no-JavaScript fallback and the privacy contract (no request, no storage,
//      no cookie, no URL change while the form is used).
//
// The shared browser fixture has none of the postings these checks need, so this spec serves its
// own fixture, built with `tests/fixtures/make_eligibility.php`, on its own PHP server and port
// (the same pattern as evidence.spec.js).
const { test, expect } = require('@playwright/test');
const { spawn, execFileSync } = require('child_process');
const net = require('net');
const os = require('os');
const path = require('path');

const rules = require('../../static/eligibility.js');

const webappDir = path.resolve(__dirname, '..', '..');
let server;
let base;

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); });
    s.on('error', reject);
  });
}

test.beforeAll(async () => {
  const fixture = path.join(os.tmpdir(), `posturi-eligibility-browser-${process.pid}.sqlite`);
  execFileSync('php', ['tests/fixtures/make_eligibility.php', fixture], { cwd: webappDir });
  const port = await freePort();
  base = `http://127.0.0.1:${port}`;
  server = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: webappDir, stdio: 'ignore',
    env: { ...process.env, POSTURI_DB: fixture, POSTURI_TODAY: '2026-10-03', POSTURI_ELIGIBILITY_PANEL: '1' },
  });
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(base + '/')).ok) return; } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('eligibility fixture server did not start');
});

test.afterAll(() => { server?.kill(); });

// ===========================================================================================
// 1. The pure rules
// ===========================================================================================

const LEVELS = ['generala', 'profesionala', 'liceala', 'postliceala', 'licenta', 'master', 'doctorat'];
const LABEL = { generala: 'Generală', profesionala: 'Profesională', liceala: 'Liceală', postliceala: 'Postliceală',
  licenta: 'Licență', master: 'Master', doctorat: 'Doctorat' };
const cfg = { levels: LEVELS.map((value) => ({ value, label: LABEL[value] })) };

const ev = (status, extra = {}) => ({ status, reason: null, anchor: null, ...extra });
const VERIFIED = ev('verified', { anchor: 'ev-1-education-0' });
const deepFreeze = (o) => { Object.values(o).forEach((v) => { if (v && typeof v === 'object') deepFreeze(v); }); return Object.freeze(o); };
const mkRole = (over = {}) => deepFreeze({
  ordinal: 1, implicit: false, title: 'Rol', study_level: 'licenta', isced: [], exp_years: null, exp_in_specialty: null,
  exp_none_required: null, evidence: { education: VERIFIED, experience: ev('verified', { anchor: 'ev-1-experience-0' }) },
  alternatives: { education: false, experience: false }, advantage: { education: false }, exp_specialty_conflict: false,
  credentials: [], ...over,
});
const cred = (over = {}) => ({ index: 0, label: 'Autorizație CECCAR', kind: 'autorizatie', evidence: ev('absent', { reason: 'no_evidence_field' }),
  alternatives: false, advantage: false, ...over });
const byId = (res, id) => res.rows.find((r) => r.id === id);
const ans = (over = {}) => ({ education: '', total: '', specialty: '', credentials: {}, ...over });

test.describe('rules (pure functions)', () => {
  test.beforeEach(async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1280', 'pure rules run once, in the desktop project');
  });

  test('education: every reader/required pair follows the order generala < ... < doctorat', () => {
    for (const required of LEVELS) {
      for (const mine of LEVELS) {
        const r = byId(rules.compare(mkRole({ study_level: required }), ans({ education: mine }), cfg), 'education');
        const want = LEVELS.indexOf(mine) >= LEVELS.indexOf(required) ? 'match' : 'missing';
        expect(r.state, `${mine} against ${required}`).toBe(want);
        expect(r.explanation).toContain(`Nivel: ${LABEL[mine]} — `);
        expect(r.explanation).toContain(LABEL[required]);
      }
    }
    const ok = byId(rules.compare(mkRole(), ans({ education: 'licenta' }), cfg), 'education');
    expect(ok.explanation).toBe('Nivel: Licență — cel puțin Licență');
  });

  test('education: "Nu știu", an unknown value and a missing requirement are unknown', () => {
    expect(byId(rules.compare(mkRole(), ans({ education: '' }), cfg), 'education'))
      .toMatchObject({ state: 'unknown', explanation: 'Nu ați indicat nivelul dvs. de studii.' });
    expect(byId(rules.compare(mkRole(), ans({ education: 'altceva' }), cfg), 'education').state).toBe('unknown');
    const none = byId(rules.compare(mkRole({ study_level: null }), ans({ education: 'doctorat' }), cfg), 'education');
    expect(none).toMatchObject({ state: 'unknown', explanation: 'Nivelul de studii nu a fost identificat.' });
    // An EQF number is not a study level: a role that only carries one states nothing comparable.
    expect(byId(rules.compare(mkRole({ study_level: null, eqf_level: 6 }), ans({ education: 'doctorat' }), cfg), 'education').state).toBe('unknown');
  });

  test('a requirement without verified evidence is unknown for every kind, whatever was answered', () => {
    for (const status of ['unverified', 'absent', 'none']) {
      const role = mkRole({
        study_level: 'generala', exp_years: 0, exp_none_required: 1,
        evidence: { education: ev(status), experience: ev(status) },
        credentials: [cred({ evidence: ev(status) })],
      });
      const res = rules.compare(role, ans({ education: 'doctorat', total: '40', specialty: '40', credentials: { 0: 'da' } }), cfg);
      for (const id of ['education', 'experience', 'cred-0']) {
        expect(byId(res, id).state, `${status}: ${id}`).toBe('unknown');
        expect(byId(res, id).explanation).toContain('Cerința nu are un citat verificat în sursă; verificați anunțul.');
      }
    }
  });

  test('the strictness is one switch: relaxing it compares on the extracted value, but alternatives and advantages stay unknown', () => {
    expect(rules.REQUIRE_VERIFIED_EVIDENCE).toBe(true);
    const loose = { ...cfg, requireVerified: false };
    const role = mkRole({ evidence: { education: ev('unverified'), experience: ev('absent') }, exp_years: 2, exp_in_specialty: 0,
      credentials: [cred(), cred({ index: 1, label: 'Permis B sau C', alternatives: true }), cred({ index: 2, label: 'Curs', advantage: true })] });
    const res = rules.compare(role, ans({ education: 'master', total: '3', credentials: { 0: 'da', 1: 'da', 2: 'da' } }), loose);
    expect(byId(res, 'education').state).toBe('match');
    expect(byId(res, 'experience').state).toBe('match');
    expect(byId(res, 'cred-0')).toMatchObject({ state: 'match' });
    expect(byId(res, 'cred-1').state).toBe('unknown');
    expect(byId(res, 'cred-1').explanation).toContain('variante alternative');
    expect(byId(res, 'cred-2').state).toBe('unknown');
    expect(byId(res, 'cred-2').explanation).toContain('avantaj');
    // Left at its default the same data is all unknown.
    expect(byId(rules.compare(role, ans({ education: 'master' }), cfg), 'education').state).toBe('unknown');
    expect(byId(rules.compare(role, ans({ credentials: { 0: 'nu' } }), { ...loose }), 'cred-0')).toMatchObject({ state: 'missing' });
  });

  test('alternatives and advantages are unknown even with verified evidence', () => {
    const alt = rules.compare(mkRole({ alternatives: { education: true, experience: true }, exp_years: 2, exp_in_specialty: 0 }),
      ans({ education: 'doctorat', total: '9' }), cfg);
    expect(byId(alt, 'education')).toMatchObject({ state: 'unknown', explanation: 'Anunțul indică variante alternative; verificați care vi se aplică.' });
    expect(byId(alt, 'experience').state).toBe('unknown');
    const adv = rules.compare(mkRole({ advantage: { education: true } }), ans({ education: 'doctorat' }), cfg);
    expect(byId(adv, 'education').state).toBe('unknown');
    expect(byId(adv, 'education').explanation).toContain('avantaj');
  });

  test('the field of study is its own requirement and is always unknown', () => {
    const role = mkRole({ isced: ['Economie', 'Drept'], study_level: 'licenta' });
    const res = rules.compare(role, ans({ education: 'doctorat' }), cfg);
    expect(byId(res, 'education').state).toBe('match');
    expect(byId(res, 'field')).toMatchObject({
      state: 'unknown',
      explanation: 'Domeniul cerut: Economie, Drept. Echivalența diplomei nu este verificată automat.',
    });
    expect(rules.compare(mkRole({ isced: [] }), ans(), cfg).rows.map((r) => r.id)).not.toContain('field');
  });

  test('experience: an explicit zero is a match, "not stated" is unknown', () => {
    const zero = byId(rules.compare(mkRole({ exp_years: 0, exp_none_required: 1 }), ans(), cfg), 'experience');
    expect(zero).toMatchObject({ state: 'match', explanation: 'Nu se cere experiență.' });
    expect(byId(rules.compare(mkRole({ exp_years: 0 }), ans(), cfg), 'experience').state).toBe('match');
    expect(byId(rules.compare(mkRole({ exp_years: null, exp_none_required: 1 }), ans(), cfg), 'experience').state).toBe('match');
    const absent = byId(rules.compare(mkRole({ exp_years: null, exp_none_required: null }), ans({ total: '30', specialty: '30' }), cfg), 'experience');
    expect(absent).toMatchObject({ state: 'unknown', explanation: 'Cerința de experiență nu a fost identificată.' });
    const contra = byId(rules.compare(mkRole({ exp_years: 3, exp_none_required: 1 }), ans({ total: '9' }), cfg), 'experience');
    expect(contra.state).toBe('unknown');
  });

  test('experience in the specialty compares specialty years only', () => {
    const role = mkRole({ exp_years: 5, exp_in_specialty: 1 });
    const x = (a) => byId(rules.compare(role, ans(a), cfg), 'experience');
    expect(x({ total: '9' })).toMatchObject({ state: 'unknown', explanation: 'Completați vechimea în specialitate.' });
    expect(x({ total: '9', specialty: '4,5' })).toMatchObject({ state: 'missing' });
    expect(x({ total: '9', specialty: '5' })).toMatchObject({ state: 'match', explanation: 'Vechime în specialitate: 5 ani — cel puțin 5 ani' });
    expect(x({ total: '30', specialty: '4.5' }).state).toBe('missing');
  });

  test('experience in general work compares total years only', () => {
    const role = mkRole({ exp_years: 2, exp_in_specialty: 0 });
    const x = (a) => byId(rules.compare(role, ans(a), cfg), 'experience');
    expect(x({})).toMatchObject({ state: 'unknown', explanation: 'Completați vechimea totală.' });
    expect(x({ specialty: '9' }).state).toBe('unknown');           // specialty years alone never answer a total-years requirement
    expect(x({ total: '1,5' }).state).toBe('missing');
    expect(x({ total: '2' })).toMatchObject({ state: 'match', explanation: 'Vechime totală: 2 ani — cel puțin 2 ani' });
  });

  test('experience with specialty not stated: match only on specialty years, missing only on a short total', () => {
    const role = mkRole({ exp_years: 3, exp_in_specialty: null });
    const x = (a) => byId(rules.compare(role, ans(a), cfg), 'experience');
    expect(x({ total: '5', specialty: '3' }).state).toBe('match');
    expect(x({ total: '2' }).state).toBe('missing');
    expect(x({ total: '5' })).toMatchObject({ state: 'unknown', explanation: 'Nu reiese dacă se cere vechime în specialitate.' });
    expect(x({ total: '5', specialty: '1' }).state).toBe('unknown');
    expect(x({})).toMatchObject({ state: 'unknown', explanation: expect.stringContaining('Completați vechimea totală') });
  });

  test('experience: bad input, specialty above total, and a specialty flag the sentence contradicts', () => {
    const role = mkRole({ exp_years: 2, exp_in_specialty: 0 });
    const x = (a, r = role) => byId(rules.compare(r, ans(a), cfg), 'experience');
    expect(x({ total: '-1' }).explanation).toContain('Introduceți anii ca număr');
    expect(x({ total: 'abc' }).state).toBe('unknown');
    expect(x({ total: '999' }).state).toBe('unknown');
    expect(x({ total: '3', specialty: '4' }).explanation).toContain('nu poate depăși');
    const conflict = mkRole({ exp_years: 2, exp_in_specialty: 0, exp_specialty_conflict: true });
    expect(x({ total: '9' }, conflict).state).toBe('unknown');
    expect(x({ total: '9' }, conflict).explanation).toContain('specialitate sau domeniu');
  });

  test('documents: an exact self-assertion, always unknown under the shipped policy, with the answer shown', () => {
    const role = mkRole({ credentials: [cred(), cred({ index: 1, label: 'Atestat de limba engleză' })] });
    const res = rules.compare(role, ans({ credentials: { 0: 'da', 1: 'nu' } }), cfg);
    expect(byId(res, 'cred-0').state).toBe('unknown');
    expect(byId(res, 'cred-0').explanation).toContain('Ați răspuns: Da.');
    expect(byId(res, 'cred-1').state).toBe('unknown');
    expect(byId(res, 'cred-1').explanation).toContain('Ați răspuns: Nu.');
    expect(byId(rules.compare(role, ans(), cfg), 'cred-0').explanation).toContain('Ați răspuns: Nu știu.');
    expect(byId(res, 'cred-0').label).toBe('Document: Autorizație CECCAR');
    // Relaxed policy: the three answers map to match / missing / unknown, and nothing is aliased.
    const loose = { ...cfg, requireVerified: false };
    const r2 = rules.compare(role, ans({ credentials: { 0: 'da', 1: 'nu' } }), loose);
    expect(byId(r2, 'cred-0').state).toBe('match');
    expect(byId(r2, 'cred-1').state).toBe('missing');
    expect(byId(rules.compare(role, ans({ credentials: { 0: 'nu_stiu' } }), loose), 'cred-0').state).toBe('unknown');
  });

  test('the general-conditions row is last and unknown; the tally is neutral; no verdict anywhere', () => {
    const role = mkRole({ exp_years: 0, exp_none_required: 1, isced: ['Drept'], credentials: [cred()] });
    const res = rules.compare(role, ans({ education: 'master' }), cfg);
    const last = res.rows[res.rows.length - 1];
    expect(last).toMatchObject({ id: 'general', state: 'unknown',
      label: 'Condiții generale (cetățenie, vârstă, stare de sănătate, cazier etc.) — verificați în anunț.' });
    expect(res.tally).toEqual({ match: 2, missing: 0, unknown: 3 });
    expect(rules.tallyText(res.tally)).toBe('2 îndeplinite · 0 lipsă · 3 de verificat');
    const text = JSON.stringify(res.rows);
    expect(text).not.toMatch(/eligibil|admis|poți aplica|puteți aplica|nu puteți|respins|calificat|dacă ați avea|ar trebui să/i);
  });

  test('compare never mutates its inputs and ignores everything outside the role it is given', () => {
    const role = mkRole({ credentials: [cred()] });
    const a = ans({ education: 'licenta', credentials: { 0: 'da' } });
    Object.freeze(a); Object.freeze(a.credentials);
    const first = JSON.stringify(rules.compare(role, a, cfg));
    expect(JSON.stringify(rules.compare(role, a, cfg))).toBe(first);
  });

  test('year formatting and parsing', () => {
    const t = [[0, '0 ani'], [0.5, '6 luni'], [1, '1 an'], [1.5, '1,5 ani'], [2, '2 ani'], [19, '19 ani'], [20, '20 de ani'], [101, '101 ani']];
    for (const [n, s] of t) expect(rules.yearsLabel(n), String(n)).toBe(s);
    expect(rules.parseYears('')).toEqual({ empty: true });
    expect(rules.parseYears(' 2,5 ')).toEqual({ value: 2.5 });
    expect(rules.parseYears('1e3')).toEqual({ invalid: true });
    expect(rules.parseYears('-1')).toEqual({ invalid: true });
    expect(rules.parseYears('81')).toEqual({ invalid: true });
  });
});

// ===========================================================================================
// 2. The panel in a browser
// ===========================================================================================

const noOverflow = (page) =>
  page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
const panelOf = (page) => page.locator('details[data-eligibility]');
const rowOf = (page, id) => page.locator(`details[data-eligibility] li[data-row-id="${id}"]`);

/** Load a posting and open the panel from the keyboard. */
async function openPanel(page, id) {
  await page.goto(`${base}/job/${id}/`);
  const summary = panelOf(page).locator('> summary');
  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(panelOf(page)).toHaveJSProperty('open', true);
  await expect(page.locator('[data-eligibility-app]')).toBeVisible();
}
const pickRole = (page, ordinalIndex) => page.selectOption('#elig-role', String(ordinalIndex));
const fillTotal = (page, v) => page.fill('#elig-total', v);
const fillSpec = (page, v) => page.fill('#elig-spec', v);
const education = (page, v) => page.selectOption('#elig-edu', v);
const credRadio = (page, roleIdx, credIdx, value) =>
  page.locator(`[data-credentials-for="${roleIdx}"] [data-credential="${credIdx}"] input[value="${value}"]`);

test.beforeEach(async ({ page }) => {
  const errs = [];
  page.on('pageerror', (e) => errs.push(String(e)));
  page.on('dialog', (d) => { errs.push('dialog: ' + d.message()); d.dismiss(); });
  page.__errs = errs;
});
test.afterEach(async ({ page }) => { expect(page.__errs, 'no uncaught error and no dialog').toEqual([]); });

test('the panel is collapsed by default, opens and closes from the keyboard, and says what it is', async ({ page }) => {
  await page.goto(`${base}/job/1040/`);
  const panel = panelOf(page);
  await expect(panel).not.toHaveJSProperty('open', true);
  await expect(panel.locator('> summary')).toContainText('Pot aplica? — verificare orientativă');
  await expect(page.locator('[data-eligibility-disclaimer]')).toBeHidden();

  await panel.locator('> summary').focus();
  await page.keyboard.press('Space');
  await expect(panel).toHaveJSProperty('open', true);
  await expect(page.locator('[data-eligibility-disclaimer]')).toContainText(
    'Un ajutor de pregătire, nu o decizie. Doar angajatorul, pe baza anunțului oficial, stabilește cine este admis.');
  await expect(page.locator('[data-eligibility-disclaimer] a')).toHaveAttribute('href', 'https://posturi.gov.ro/anunt/fixture-1040');
  await page.keyboard.press('Enter');
  await expect(panel).toHaveJSProperty('open', false);
  await page.keyboard.press('Enter');
  await expect(panel).toHaveJSProperty('open', true);

  // JavaScript replaced the no-JavaScript note with the form.
  await expect(page.locator('[data-eligibility-nojs]')).toBeHidden();
  await expect(page.locator('[data-eligibility-app]')).toBeVisible();
  expect(await noOverflow(page), 'no horizontal overflow').toBeLessThanOrEqual(1);
});

test('education: match, missing and unknown for one role', async ({ page }) => {
  await openPanel(page, 1040);
  const edu = rowOf(page, 'education');
  await expect(edu).toHaveAttribute('data-state', 'unknown');                    // nothing answered yet
  await expect(edu).toContainText('Nu ați indicat nivelul dvs. de studii.');
  await expect(edu).toContainText('Studii minime: Licență');

  await education(page, 'licenta');
  await expect(edu).toHaveAttribute('data-state', 'match');
  await expect(edu).toContainText('Nivel: Licență — cel puțin Licență');
  await expect(edu).toContainText('Îndeplinit');
  await education(page, 'master');
  await expect(edu).toHaveAttribute('data-state', 'match');
  await education(page, 'liceala');
  await expect(edu).toHaveAttribute('data-state', 'missing');
  await expect(edu).toContainText('Lipsește');
  await expect(edu).toContainText('se cere cel puțin Licență');
  await education(page, '');
  await expect(edu).toHaveAttribute('data-state', 'unknown');
  await expect(edu).toContainText('De verificat');

  await expect(page.locator('[data-tally]')).toHaveText(/^\d+ îndeplinite · \d+ lipsă · \d+ de verificat$/);
  // The panel never issues a verdict.
  await education(page, 'doctorat');
  await expect(panelOf(page)).not.toContainText(/eligibil|poți aplica|puteți aplica|respins/i);
});

test('the same level in a different field is never settled by the level: the field row stays unknown, per role', async ({ page }) => {
  await openPanel(page, 1041);
  await education(page, 'licenta');
  await fillSpec(page, '3'); await fillTotal(page, '4');
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'match');
  const field = rowOf(page, 'field');
  await expect(field).toHaveAttribute('data-state', 'unknown');
  await expect(field).toContainText('Domeniul cerut: Drept. Echivalența diplomei nu este verificată automat.');
  await expect(field).not.toContainText('Inginerie');

  await pickRole(page, 1);
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');   // same EQF, same answer
  await expect(field).toHaveAttribute('data-state', 'unknown');
  await expect(field).toContainText('Domeniul cerut: Inginerie civilă. Echivalența diplomei nu este verificată automat.');
  await expect(field).not.toContainText('Drept');
});

test('experience: an explicit zero is a match, a missing requirement is unknown', async ({ page }) => {
  await openPanel(page, 1042);
  const exp = rowOf(page, 'experience');
  await expect(page.locator('#elig-role option')).toHaveCount(4);

  await pickRole(page, 0);                                       // Muncitor: "nu se cere experiență", verified
  await expect(exp).toHaveAttribute('data-state', 'match');
  await expect(exp).toContainText('Nu se cere experiență.');
  await expect(exp).toContainText('Experiență: fără experiență');

  await pickRole(page, 1);                                       // Paznic: no experience block at all
  await expect(exp).toHaveAttribute('data-state', 'unknown');
  await expect(exp).toContainText('Cerința de experiență nu a fost identificată.');
  await fillTotal(page, '30'); await fillSpec(page, '30');
  await expect(exp).toHaveAttribute('data-state', 'unknown');    // answers cannot invent a requirement
  await expect(exp).toContainText('Cerința de experiență nu a fost identificată.');
});

test('experience in the specialty, in general, and not stated either way', async ({ page }) => {
  await openPanel(page, 1040);                                   // role 1: 5 years in the specialty
  const exp = rowOf(page, 'experience');
  await expect(exp).toHaveAttribute('data-state', 'unknown');
  await expect(exp).toContainText('Experiență: min. 5 ani, în specialitate');
  await expect(exp).toContainText('Completați vechimea în specialitate.');
  await fillTotal(page, '8');
  await expect(exp).toContainText('Completați vechimea în specialitate.');
  await fillSpec(page, '4.5');
  await expect(exp).toHaveAttribute('data-state', 'missing');
  await fillSpec(page, '5');
  await expect(exp).toHaveAttribute('data-state', 'match');
  await expect(exp).toContainText('Vechime în specialitate: 5 ani — cel puțin 5 ani');
  await fillSpec(page, '9');                                      // more specialty years than total
  await expect(exp).toHaveAttribute('data-state', 'unknown');
  await expect(exp).toContainText('Vechimea în specialitate nu poate depăși vechimea totală');

  await openPanel(page, 1044);                                   // role 1: 2 years of general experience
  await expect(rowOf(page, 'experience')).toContainText('Completați vechimea totală.');
  await fillTotal(page, '1.5');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'missing');
  await fillTotal(page, '2');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'match');
  await expect(rowOf(page, 'experience')).toContainText('Vechime totală: 2 ani — cel puțin 2 ani');

  await openPanel(page, 1042);                                   // role 3: 3 years, specialty not stated
  await pickRole(page, 2);
  await fillTotal(page, '2');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'missing');
  await fillTotal(page, '6');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'experience')).toContainText('Nu reiese dacă se cere vechime în specialitate.');
  await fillSpec(page, '3');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'match');
});

test('evidence that was not verified makes the requirement unknown whatever is answered', async ({ page }) => {
  await openPanel(page, 1042);
  await pickRole(page, 1);                                       // Paznic: education quote not found in the source
  await education(page, 'doctorat');
  const edu = rowOf(page, 'education');
  await expect(edu).toHaveAttribute('data-state', 'unknown');
  await expect(edu).toContainText('Cerința nu are un citat verificat în sursă; verificați anunțul.');
  await expect(edu).toContainText('Studii minime: Liceală');
});

test('alternatives, a contradicted specialty flag and advantages are unknown', async ({ page }) => {
  await openPanel(page, 1042);
  await education(page, 'doctorat'); await fillTotal(page, '9'); await fillSpec(page, '9');
  await pickRole(page, 2);                                       // Specialist: "studii ... sau echivalente"
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'education')).toContainText('Anunțul indică variante alternative; verificați care vi se aplică.');
  await pickRole(page, 3);                                       // Analist: "în specialitate ori în domeniul conex"
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'experience')).toContainText('variante alternative');

  await openPanel(page, 1044);
  await fillTotal(page, '9');
  await pickRole(page, 1);                                       // Coordonator: flag says "not specialty", sentence says "specialitatea"
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'experience')).toContainText('specialitate sau domeniu');
  await pickRole(page, 2);                                       // Operator: advantage education, advantage + alternative documents
  await education(page, 'doctorat');
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'education')).toContainText('avantaj');
  // Documents carry no evidence field, so their rows say that first; "avantaj" / "variante alternative"
  // for a document surface only when the policy switch is relaxed (covered by the pure rules above).
  await expect(rowOf(page, 'cred-0')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'cred-1')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'cred-1')).toContainText('Document: Permis categoria B sau C');
});

test('partial licences: Da on one, Nu on another stay unknown, with the answers shown', async ({ page }) => {
  await openPanel(page, 1040);
  const group0 = page.locator('[data-credentials-for="0"] [data-credential="0"]');
  await expect(group0.locator('legend')).toHaveText('Dețin: Autorizație CECCAR');
  await expect(page.locator('[data-credentials-for="0"] [data-credential="1"] legend')).toHaveText('Dețin: Atestat de limba engleză');
  for (const id of ['cred-0', 'cred-1']) {
    await expect(rowOf(page, id)).toContainText('Ați răspuns: Nu știu.');
  }
  await credRadio(page, 0, 0, 'da').check();
  await credRadio(page, 0, 1, 'nu').check();
  await expect(rowOf(page, 'cred-0')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'cred-0')).toContainText('Ați răspuns: Da.');
  await expect(rowOf(page, 'cred-1')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'cred-1')).toContainText('Ați răspuns: Nu.');
  await expect(rowOf(page, 'cred-0')).toContainText('Cerința nu are un citat verificat în sursă');
  await expect(rowOf(page, 'cred-0')).toContainText('Document: Autorizație CECCAR');
  // Exactly one radio of a group is on.
  await expect(group0.locator('input:checked')).toHaveCount(1);
});

test('two contrasting roles: switching changes the rows, clears the document answers and keeps the reader\'s own facts', async ({ page }) => {
  await openPanel(page, 1040);
  await education(page, 'licenta'); await fillSpec(page, '6'); await fillTotal(page, '6');
  await credRadio(page, 0, 0, 'da').check();
  await credRadio(page, 0, 1, 'da').check();
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'match');
  await expect(rowOf(page, 'field')).toContainText('Economie');
  await expect(rowOf(page, 'cred-0')).toContainText('Autorizație CECCAR');
  await expect(page.locator('[data-note]')).toHaveText('');

  await pickRole(page, 1);                                       // Referent
  await expect(page.locator('[data-note]')).toHaveText('Răspunsurile pentru documente au fost resetate pentru noul post.');
  await expect(rowOf(page, 'field')).toHaveCount(0);             // role 2 lists no field
  await expect(rowOf(page, 'education')).toContainText('Studii minime: Liceală');
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');   // licență >= liceală: the answer stayed
  await expect(rowOf(page, 'experience')).toContainText('Nu se cere experiență.');
  await expect(rowOf(page, 'cred-0')).toContainText('Document: Permis categoria B');
  await expect(rowOf(page, 'cred-0')).toContainText('Ați răspuns: Nu știu.');
  await expect(rowOf(page, 'cred-1')).toHaveCount(0);
  await expect(page.locator('#elig-edu')).toHaveValue('licenta');
  await expect(page.locator('#elig-total')).toHaveValue('6');
  await expect(page.locator('[data-credentials-for="0"]')).toBeHidden();
  await expect(page.locator('[data-credentials-for="1"]')).toBeVisible();
  // Nothing of role 1 leaks into role 2's rows.
  const text2 = await page.locator('[data-results]').innerText();
  expect(text2).not.toMatch(/CECCAR|Economie|engleză|în specialitate/);

  await credRadio(page, 1, 0, 'da').check();
  await pickRole(page, 0);                                       // back: answers were cleared, not carried or restored
  await expect(page.locator('[data-note]')).toHaveText('Răspunsurile pentru documente au fost resetate pentru noul post.');
  for (const [r, c] of [[0, 0], [0, 1], [1, 0]]) {
    await expect(credRadio(page, r, c, 'nu_stiu')).toBeChecked();
  }
  await expect(rowOf(page, 'cred-0')).toContainText('Ați răspuns: Nu știu.');
  await expect(rowOf(page, 'education')).toContainText('Studii minime: Licență');

  // Typing again removes the stale note.
  await fillTotal(page, '7');
  await expect(page.locator('[data-note]')).toHaveText('');
});

test('reset clears everything', async ({ page }) => {
  await openPanel(page, 1040);
  await pickRole(page, 1);
  await education(page, 'master'); await fillTotal(page, '12'); await fillSpec(page, '3');
  await credRadio(page, 1, 0, 'da').check();
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');

  await page.getByRole('button', { name: 'Resetează răspunsurile' }).click();
  await expect(page.locator('#elig-role')).toHaveValue('0');
  await expect(page.locator('#elig-edu')).toHaveValue('');
  await expect(page.locator('#elig-total')).toHaveValue('');
  await expect(page.locator('#elig-spec')).toHaveValue('');
  for (const [r, c] of [[0, 0], [0, 1], [1, 0]]) await expect(credRadio(page, r, c, 'nu_stiu')).toBeChecked();
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'unknown');
  await expect(rowOf(page, 'education')).toContainText('Studii minime: Licență');
  await expect(rowOf(page, 'cred-0')).toContainText('Document: Autorizație CECCAR');
  await expect(page.locator('[data-note]')).toHaveText('Răspunsurile au fost șterse.');
});

test('the whole panel works from the keyboard', async ({ page }) => {
  await openPanel(page, 1040);

  // Tab order after the summary: the official link, then the form in reading order, then the results.
  const seen = [];
  for (let i = 0; i < 14; i++) {
    await page.keyboard.press('Tab');
    seen.push(await page.evaluate(() => {
      const a = document.activeElement;
      if (!a) return '';
      if (a.matches('input[type=radio]')) return 'radio:' + a.value;
      return a.getAttribute('data-field') || (a.matches('a') ? 'link' : '') || a.getAttribute('data-action') || a.tagName;
    }));
    if (seen[seen.length - 1] === 'reset') break;
  }
  expect(seen.slice(0, 5)).toEqual(['link', 'role', 'education', 'total', 'specialty']);
  expect(seen).toContain('radio:nu_stiu');
  expect(seen[seen.length - 1]).toBe('reset');
  // One tab stop per radio group (the checked one), not one per radio.
  expect(seen.filter((s) => s.startsWith('radio:'))).toEqual(['radio:nu_stiu', 'radio:nu_stiu']);

  // Selects: the keyboard alone changes the value and the results follow.
  await page.locator('#elig-edu').focus();
  await page.keyboard.type('Licen');                                    // type-ahead on the focused select
  await expect(page.locator('#elig-edu')).toHaveValue('licenta');
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');

  // Numbers: typing.
  await page.locator('#elig-spec').focus();
  await page.keyboard.type('5');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'match');

  // Radios: arrows move within the group and check; Space checks the focused one.
  await credRadio(page, 0, 0, 'nu_stiu').focus();
  await page.keyboard.press('ArrowUp');
  await expect(credRadio(page, 0, 0, 'nu')).toBeChecked();
  await page.keyboard.press('ArrowUp');
  await expect(credRadio(page, 0, 0, 'da')).toBeChecked();
  await expect(rowOf(page, 'cred-0')).toContainText('Ați răspuns: Da.');
  await credRadio(page, 0, 1, 'nu_stiu').focus();
  await page.keyboard.press('ArrowUp');
  await page.keyboard.press('Space');
  await expect(credRadio(page, 0, 1, 'nu')).toBeChecked();

  // Reset with Enter, then Space.
  await page.locator('[data-action="reset"]').focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#elig-edu')).toHaveValue('');
  await expect(credRadio(page, 0, 0, 'nu_stiu')).toBeChecked();
  await education(page, 'liceala');
  await page.locator('[data-action="reset"]').focus();
  await page.keyboard.press('Space');
  await expect(page.locator('#elig-edu')).toHaveValue('');

  // The live regions exist and are polite.
  await expect(page.locator('[data-results-region]')).toHaveAttribute('aria-live', 'polite');
  await expect(page.locator('[data-note]')).toHaveAttribute('aria-live', 'polite');
  expect(await noOverflow(page), 'no horizontal overflow with the panel in use').toBeLessThanOrEqual(1);
});

test('"vezi sursa" opens the matching disclosure of the SAME role, from the keyboard, without touching the address', async ({ page }) => {
  await openPanel(page, 1040);
  const startUrl = page.url();
  const link = rowOf(page, 'education').getByRole('link', { name: /vezi sursa/ });
  await expect(link).toHaveAttribute('href', '#ev-1-education-0');
  await expect(page.locator('#ev-1-education-0')).not.toHaveJSProperty('open', true);
  await link.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#ev-1-education-0')).toHaveJSProperty('open', true);
  await expect(page.locator('#ev-1-education-0 blockquote')).toContainText('ELIG-R1');
  await expect(page.locator('#ev-1-education-0 > summary')).toBeFocused();
  expect(page.url(), 'the address bar is untouched').toBe(startUrl);

  await pickRole(page, 1);
  const link2 = rowOf(page, 'education').getByRole('link', { name: /vezi sursa/ });
  await expect(link2).toHaveAttribute('href', '#ev-2-education-0');
  await link2.click();
  await expect(page.locator('#ev-2-education-0 blockquote')).toContainText('ELIG-R2');
  await expect(page.locator('#ev-2-education-0')).not.toContainText('ELIG-R1');
  // Documents have a disclosure too (an honest "no quote"), and the general row has none.
  await expect(rowOf(page, 'cred-0').getByRole('link', { name: /vezi sursa/ })).toHaveAttribute('href', '#ev-2-credential-0');
  await expect(rowOf(page, 'general').getByRole('link', { name: /vezi sursa/ })).toHaveCount(0);
  expect(page.url()).toBe(startUrl);
});

test('an undecomposed posting is one implicit role: no role selector, its own flat facts', async ({ page }) => {
  await openPanel(page, 1013);
  await expect(page.locator('#elig-role')).toHaveCount(0);
  await education(page, 'master');
  await fillSpec(page, '3'); await fillTotal(page, '3');
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');
  await expect(rowOf(page, 'experience')).toHaveAttribute('data-state', 'match');
  await expect(rowOf(page, 'field')).toContainText('Economie');
  await expect(rowOf(page, 'cred-0')).toContainText('Document: Permis categoria B');
  await expect(rowOf(page, 'cred-0')).toHaveAttribute('data-state', 'unknown');
  // The evidence of this posting's flat block is the link target.
  await expect(rowOf(page, 'education').getByRole('link', { name: /vezi sursa/ })).toHaveAttribute('href', '#ev-0-education-0');
});

test('privacy: nothing is requested, stored, set as a cookie or put in the address while the form is used', async ({ page, context }) => {
  await page.goto(`${base}/job/1040/`);
  await page.waitForLoadState('networkidle');
  const storage = () => page.evaluate(() => ({
    local: JSON.stringify(Object.entries(localStorage).sort()),
    session: JSON.stringify(Object.entries(sessionStorage).sort()),
    href: location.href,
    cookie: document.cookie,
  }));
  const before = await storage();
  const cookiesBefore = JSON.stringify(await context.cookies());
  const requests = [];
  page.on('request', (r) => requests.push(`${r.method()} ${r.url()}`));

  await panelOf(page).locator('> summary').focus();
  await page.keyboard.press('Enter');
  await education(page, 'licenta');
  await fillTotal(page, '12'); await fillSpec(page, '7');
  await credRadio(page, 0, 0, 'da').check();
  await pickRole(page, 1);
  await credRadio(page, 1, 0, 'nu').check();
  await rowOf(page, 'education').getByRole('link', { name: /vezi sursa/ }).click();
  await page.locator('[data-action="reset"]').click();
  await education(page, 'master'); await fillTotal(page, '3');
  await expect(rowOf(page, 'education')).toHaveAttribute('data-state', 'match');

  const after = await storage();
  expect(requests, 'no network request while the form is used').toEqual([]);
  expect(after.local, 'localStorage unchanged').toBe(before.local);
  expect(after.session, 'sessionStorage unchanged').toBe(before.session);
  expect(after.href, 'location unchanged').toBe(before.href);
  expect(after.cookie, 'no cookie').toBe(before.cookie);
  expect(JSON.stringify(await context.cookies())).toBe(cookiesBefore);

  // And by construction: no form owner to submit, no named control but the radio groups, no autofill memory.
  const shape = await page.evaluate(() => {
    const panel = document.querySelector('details[data-eligibility]');
    const named = [...panel.querySelectorAll('[name]')].map((el) => `${el.tagName}:${el.type}`);
    const controls = [...panel.querySelectorAll('input, select, textarea')];
    return {
      forms: panel.querySelectorAll('form').length,
      owners: controls.filter((c) => c.form).length,
      named: [...new Set(named)],
      noAutocomplete: controls.filter((c) => c.getAttribute('autocomplete') !== 'off').length,
      submitters: panel.querySelectorAll('[type=submit]').length,
    };
  });
  expect(shape).toEqual({ forms: 0, owners: 0, named: ['INPUT:radio'], noAutocomplete: 0, submitters: 0 });
});

test('hostile text in a role title or a document label stays text', async ({ page }) => {
  await page.goto(`${base}/job/1043/`);
  await page.locator('details[data-eligibility] > summary').focus();
  await page.keyboard.press('Enter');
  expect(await page.evaluate(() => window.__pwned), 'no injected script ran').toBeUndefined();
  const shape = await page.evaluate(() => {
    const panel = document.querySelector('[data-eligibility-section]');
    return {
      scripts: [...panel.querySelectorAll('script')].map((s) => s.type),
      imgs: panel.querySelectorAll('img').length,
      json: JSON.parse(document.getElementById('eligibility-data').textContent).roles.map((r) => r.title),
      option: panel.querySelector('#elig-role option').textContent,
      legend: panel.querySelector('[data-credentials-for="0"] legend').textContent,
    };
  });
  expect(shape.scripts).toEqual(['application/json']);
  expect(shape.imgs).toBe(0);
  expect(shape.json[0]).toBe('</script><script>window.__pwned=1</script> & "Rol" \'unu\'');
  expect(shape.option).toBe('1. </script><script>window.__pwned=1</script> & "Rol" \'unu\'');
  expect(shape.legend).toBe('Dețin: </script><img src=x onerror=window.__pwned=2> "Atestat" & \'altul\'');
  await expect(rowOf(page, 'cred-0')).toContainText('Document: </script><img src=x onerror=window.__pwned=2>');
  expect(await page.evaluate(() => window.__pwned)).toBeUndefined();
});

test('every row of every role fits the viewport', async ({ page }) => {
  await openPanel(page, 1042);
  await education(page, 'master'); await fillTotal(page, '6'); await fillSpec(page, '3');
  for (let i = 0; i < 4; i++) {
    await pickRole(page, i);
    await expect(rowOf(page, 'general')).toBeVisible();
    expect(await noOverflow(page), `role ${i + 1}`).toBeLessThanOrEqual(1);
  }
  await openPanel(page, 1044);
  await pickRole(page, 2);
  expect(await noOverflow(page)).toBeLessThanOrEqual(1);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('the panel is a note, the source requirements stay on the page, and nothing can be answered', async ({ page }) => {
    await page.goto(`${base}/job/1040/`);
    const panel = panelOf(page);
    await panel.locator('> summary').click();                    // <details> opens natively
    await expect(panel).toHaveAttribute('open', '');             // JavaScript is off: the attribute is the state
    await expect(page.locator('[data-eligibility-nojs]')).toBeVisible();
    await expect(page.locator('[data-eligibility-nojs]')).toHaveText(
      'Comparația funcționează în browser, cu JavaScript activat. Cerințele din sursă sunt listate mai sus.');
    await expect(page.locator('[data-eligibility-app]')).toBeHidden();
    await expect(page.locator('[data-eligibility-disclaimer]')).toContainText('nu o decizie');
    // The requirements the note points to are on the page, outside the panel.
    const outside = page.locator('section:has(h2:has-text("Cerințe structurate"))');
    await expect(outside).toContainText('Inspector superior');
    await expect(outside).toContainText('Documente: Autorizație CECCAR');
    await expect(outside).toContainText('Experiență: min. 5 ani, în specialitate');
    expect(await noOverflow(page)).toBeLessThanOrEqual(1);
  });
});
