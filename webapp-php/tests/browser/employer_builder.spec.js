// UX-EMP-01: the employer posting builder (/pentru-angajatori/anunt/).
// Runs at 320, 375 and 1280 (see playwright.config.js). The suite also writes
//   <repo>/test-results/employer_quality.json   the JS completeness result per fixture
//   <repo>/test-results/employer_docx/<name>.docx   the downloaded file per fixture
// and webapp/tests/test_employer_payload.py reads both.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

const PAGE = '/pentru-angajatori/anunt/';
const DRAFTS = 'posturi.employer-drafts.v1';
const PROFILE = 'posturi.employer.v1';
const OUT = path.resolve(__dirname, '..', '..', '..', 'test-results');
const FIX_DIR = path.resolve(__dirname, '..', 'fixtures', 'employer_payloads');
const fixtures = fs.readdirSync(FIX_DIR).filter((f) => f.endsWith('.json')).sort()
  .map((f) => ({ name: f.replace(/\.json$/, ''), ...JSON.parse(fs.readFileSync(path.join(FIX_DIR, f), 'utf8')) }));

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

function seed(page, fx, id = 'fx1') {
  return page.evaluate(([key, fix, did]) => {
    const d = { id: did, title: fix.data.occupation_title || '', updatedAt: '2026-11-02T10:00:00.000Z', data: fix.data, meta: fix.meta };
    localStorage.setItem(key, JSON.stringify({ version: 1, currentId: did, drafts: { [did]: d } }));
  }, [DRAFTS, fx, id]);
}

async function open(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(PAGE);
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  return errors;
}

const settle = (page) => page.waitForTimeout(700);

test('every section keeps its values after a reload', async ({ page }) => {
  const errors = await open(page);
  await expect(page.locator('h1')).toHaveText('Anunț structurat');
  await noOverflow(page, 'empty form');

  const text = {
    '#f-inst-name': 'Primăria Exemplu', '#f-inst-cui': '123456', '#f-inst-address': 'Str. Mare 1', '#f-inst-locality': 'Exemplu',
    '#f-inst-phone': '0264 111 222', '#f-inst-email': 'hr@exemplu.example',
    '#f-title': 'Referent', '#f-vacancies': '2', '#f-dept': 'Serviciul Resurse Umane', '#f-hours': '40', '#f-location': 'Str. Mare 1, Exemplu',
    '#f-localities': 'Exemplu\nAlt Oraș',
    '#f-fields': 'drept\nștiințe economice', '#f-exp': '3', '#f-creds': 'permis categoria B', '#f-langs': 'engleză B2', '#f-qual': 'Operare PC.',
    '#f-sal-min': '5000', '#f-sal-max': '6500', '#f-duties': 'Primește dosare\nTine evidența',
    '#f-docs-extra': 'Declarație pe propria răspundere', '#f-bib': 'Legea 53/2003\nHG 1336/2022',
    '#f-ap-address': 'Str. Mare 1, camera 3', '#f-ap-email': 'depunere@exemplu.example', '#f-ap-phone': '0264 333 444', '#f-ap-name': 'Ion Ionescu',
    '#f-fee': '40', '#f-fee-details': 'La casierie.', '#f-pub': '2026-04-07',
  };
  const selects = { '#f-inst-county': 'Cluj', '#f-duration': 'nedeterminata', '#f-schedule': 'norma_intreaga', '#f-edu': 'licenta' };
  for (const [sel, v] of Object.entries(text)) await page.locator(sel).fill(v);
  for (const [sel, v] of Object.entries(selects)) await page.locator(sel).selectOption(v);
  await page.locator('#f-status').selectOption('vacant');
  await page.locator('input[data-path="documents.standard"][value="cv"]').check();
  await page.locator('input[data-path="documents.standard"][value="cazier_judiciar"]').check();
  await page.locator('input[data-path="application.channels"][value="email"]').check();
  await page.locator('input[data-path="experience.in_specialty"]').check();
  // The mirrored status select in the calendar section follows the first one.
  await expect(page.locator('#f-status-cal')).toHaveValue('vacant');
  await expect(page.locator('[data-cal-body] tr')).toHaveCount(15);
  await settle(page);

  await page.reload();
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  for (const [sel, v] of Object.entries(text)) await expect(page.locator(sel), sel).toHaveValue(v);
  for (const [sel, v] of Object.entries(selects)) await expect(page.locator(sel), sel).toHaveValue(v);
  await expect(page.locator('#f-status-cal')).toHaveValue('vacant');
  await expect(page.locator('input[data-path="documents.standard"][value="cv"]')).toBeChecked();
  await expect(page.locator('input[data-path="documents.standard"][value="acte_studii"]')).not.toBeChecked();
  await expect(page.locator('input[data-path="application.channels"][value="email"]')).toBeChecked();
  await expect(page.locator('input[data-path="experience.in_specialty"]')).toBeChecked();
  await expect(page.locator('[data-cal-body] tr')).toHaveCount(15);
  await expect(page.locator('[data-preview]')).toContainText('Referent');
  await noOverflow(page, 'filled form');
  // The salary field is filled: the nudge is gone.
  await expect(page.locator('[data-salary-nudge]')).toBeHidden();
  expect(errors).toEqual([]);
});

test('the salary nudge shows while the salary is empty', async ({ page }) => {
  await open(page);
  await expect(page.locator('[data-salary-nudge]')).toBeVisible();
  await expect(page.locator('[data-salary-nudge]')).toHaveText('Candidații văd salariul înainte să aplice. Anunțurile fără salariu primesc mai puține dosare.');
  await page.locator('#f-sal-min').fill('4000');
  await expect(page.locator('[data-salary-nudge]')).toBeHidden();
});

test('profile: saved, and used by the next draft', async ({ page }) => {
  await open(page);
  await page.locator('#f-inst-name').fill('Consiliul Județean Exemplu');
  await page.locator('#f-inst-email').fill('cj@exemplu.example');
  await page.locator('input[data-path="application.channels"][value="sediu"]').check();
  await page.locator('input[data-path="documents.standard"][value="cv"]').check();
  await page.getByRole('button', { name: 'Salvează ca profil' }).click();
  const prof = await page.evaluate((k) => JSON.parse(localStorage.getItem(k)), PROFILE);
  expect(prof.version).toBe(1);
  expect(prof.profile.institution.name).toBe('Consiliul Județean Exemplu');
  expect(prof.profile.channels).toEqual(['sediu']);
  expect(prof.profile.documents).toEqual(['cv']);
  await page.getByRole('button', { name: 'Ciornă nouă' }).click();
  await expect(page.locator('#f-inst-name')).toHaveValue('Consiliul Județean Exemplu');
  await expect(page.locator('#f-ap-email')).toHaveValue('cj@exemplu.example');
  await expect(page.locator('input[data-path="application.channels"][value="sediu"]')).toBeChecked();
  await expect(page.locator('input[data-path="documents.standard"][value="cv"]')).toBeChecked();
  await expect(page.locator('#f-title')).toHaveValue('');
});

test('drafts: new, duplicate, delete; export, clear storage, import', async ({ page }, testInfo) => {
  await open(page);
  await page.locator('#f-title').fill('Primul post');
  await settle(page);
  await page.getByRole('button', { name: 'Ciornă nouă' }).click();
  await page.locator('#f-title').fill('Al doilea post');
  await settle(page);
  const items = page.locator('[data-draft-list] > li');
  await expect(items).toHaveCount(2);
  await expect(page.locator('[data-draft-list]')).toContainText('Primul post');
  await expect(page.locator('[data-draft-list]')).toContainText('Al doilea post');

  // Duplicate the current draft: a third one opens with the same title.
  await items.filter({ hasText: 'Al doilea post' }).first().getByRole('button', { name: 'Duplică' }).click();
  await expect(items).toHaveCount(3);
  await expect(page.locator('#f-title')).toHaveValue('Al doilea post');

  // Delete asks twice.
  const first = items.filter({ hasText: 'Primul post' }).first();
  await first.getByRole('button', { name: 'Șterge' }).click();
  await expect(items).toHaveCount(3);
  await first.getByRole('button', { name: 'Confirmă ștergerea' }).click();
  await expect(items).toHaveCount(2);
  await expect(page.locator('[data-draft-list]')).not.toContainText('Primul post');

  // Put the deleted one back through the export: export now, delete one more, clear, import.
  await page.locator('#f-title').fill('Post exportat');
  await settle(page);
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Exportă datele' }).click()]);
  const file = testInfo.outputPath('export.json');
  await dl.saveAs(file);
  const exported = JSON.parse(fs.readFileSync(file, 'utf8'));
  expect(exported.format).toBe('posturi.employer.export.v1');
  expect(exported.drafts).toHaveLength(2);

  await page.evaluate(() => localStorage.clear());
  await page.reload();
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  await expect(page.locator('#f-title')).toHaveValue('');
  await page.locator('[data-import]').setInputFiles(file);
  await expect(page.locator('[data-status]')).toContainText('Am importat 2 ciorne');
  await expect(page.locator('[data-draft-list]')).toContainText('Post exportat');
  await expect(page.locator('[data-draft-list]')).toContainText('Al doilea post');

  // Import again: the existing drafts stay, the imported ones get new ids.
  const before = await page.evaluate((k) => Object.keys(JSON.parse(localStorage.getItem(k)).drafts), DRAFTS);
  await page.locator('[data-import]').setInputFiles(file);
  await expect(page.locator('[data-status]')).toContainText('Am importat 2 ciorne');
  const after = await page.evaluate((k) => Object.keys(JSON.parse(localStorage.getItem(k)).drafts), DRAFTS);
  expect(after.length).toBe(before.length + 2);
  for (const id of before) expect(after).toContain(id);
});

test('the limit is 30 drafts', async ({ page }) => {
  await open(page);
  await page.evaluate((k) => {
    const drafts = {};
    for (let i = 0; i < 30; i++) drafts['d' + i] = { id: 'd' + i, title: 'T' + i, updatedAt: new Date(2026, 10, 1, 10, i).toISOString(), data: {}, meta: {} };
    localStorage.setItem(k, JSON.stringify({ version: 1, currentId: 'd0', drafts }));
  }, DRAFTS);
  await page.reload();
  await page.getByRole('button', { name: 'Ciornă nouă' }).click();
  await expect(page.locator('[data-status]')).toContainText('Ai 30 ciorne');
  expect(await page.evaluate((k) => Object.keys(JSON.parse(localStorage.getItem(k)).drafts).length, DRAFTS)).toBe(30);
});

test('a corrupt drafts store is not overwritten, and the page says so', async ({ page }) => {
  await page.goto(PAGE);
  const bad = '{"version":1,"drafts":[oops';
  await page.evaluate(([k, v]) => localStorage.setItem(k, v), [DRAFTS, bad]);
  await page.reload();
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  await expect(page.locator('[data-unavailable]')).toBeVisible();
  await expect(page.locator('[data-unavailable]')).toContainText('Ciornele nu sunt disponibile');
  await expect(page.locator('[data-form-wrap]')).toBeHidden();
  await page.evaluate(() => window.dispatchEvent(new Event('pagehide')));
  expect(await page.evaluate((k) => localStorage.getItem(k), DRAFTS)).toBe(bad);

  // A store with another version is also left alone.
  const other = JSON.stringify({ version: 2, drafts: {} });
  await page.evaluate(([k, v]) => localStorage.setItem(k, v), [DRAFTS, other]);
  await page.reload();
  await expect(page.locator('[data-unavailable]')).toBeVisible();
  expect(await page.evaluate((k) => localStorage.getItem(k), DRAFTS)).toBe(other);

  // The reset button asks twice, then starts a clean store.
  const reset = page.getByRole('button', { name: 'Șterge datele nefolosibile' });
  await reset.click();
  expect(await page.evaluate((k) => localStorage.getItem(k), DRAFTS)).toBe(other);
  await page.getByRole('button', { name: 'Confirmă: șterge datele nefolosibile' }).click();
  await expect(page.locator('[data-unavailable]')).toBeHidden();
  await expect(page.locator('[data-form-wrap]')).toBeVisible();
});

test('calendar: publication on 2026-04-07 (vacant) skips 2026-04-10 and 2026-04-13', async ({ page }) => {
  await open(page);
  await page.locator('#f-pub').fill('2026-04-07');
  await page.locator('#f-status').selectOption('vacant');
  const date = (stage) => page.locator(`[data-cal-body] tr[data-stage="${stage}"] [data-ev-date]`);
  await expect(date('depunere_dosare')).toHaveValue('2026-04-22');
  await expect(date('selectie_dosare')).toHaveValue('2026-04-24');
  await expect(date('proba_scrisa')).toHaveValue('2026-04-30');
  await expect(date('rezultate_finale')).toHaveValue('2026-05-13');
  const all = await page.locator('[data-cal-body] [data-ev-date]').evaluateAll((els) => els.map((e) => e.value));
  expect(all).not.toContain('2026-04-10');
  expect(all).not.toContain('2026-04-13');
  await expect(page.locator('[data-cal-body] [data-cal-warn]:visible')).toHaveCount(0);
  await expect(page.locator('tr[data-stage="depunere_dosare"] [data-cal-tag]')).toHaveText('propunere');
  await expect(page.locator('tr[data-stage="depunere_dosare"] [data-cal-art]')).toHaveText('art. 34');

  // A date that breaks a minimum shows a warning; the download stays possible.
  await date('depunere_dosare').fill('2026-04-17');
  await expect(page.locator('tr[data-stage="depunere_dosare"] [data-cal-warn]')).toContainText('minimul este 10 (art. 34)');
  await expect(page.locator('tr[data-stage="depunere_dosare"] [data-cal-tag]')).toHaveText('modificat');
  await expect(page.getByRole('button', { name: 'Descarcă anunțul (.docx)' })).toBeEnabled();
  await date('proba_scrisa').fill('2026-04-20');
  await expect(page.locator('tr[data-stage="proba_scrisa"] [data-cal-warn]')).toContainText('cel puțin 15 zile lucrătoare');

  // Temporarily vacant: 5 and 10 working days.
  await page.locator('#f-status-cal').selectOption('temporar_vacant');
  await page.getByRole('button', { name: 'Propune calendarul' }).click();
  await expect(date('depunere_dosare')).toHaveValue('2026-04-15');
  await expect(date('proba_scrisa')).toHaveValue('2026-04-23');
  await noOverflow(page, 'calendar');
});

test('calendar: counting rule, one function (Node-level checks in the page)', async ({ page }) => {
  await open(page);
  const r = await page.evaluate(() => {
    const b = window.employerBuilder;
    return {
      nextOfSat: b.nextWorkingDay('2026-04-04'),
      afterFriday: b.addWorkingDays('2026-04-03', 1),
      overEaster: b.addWorkingDays('2026-04-09', 1),
      overEaster2: b.addWorkingDays('2026-04-09', 2),
      window10: b.windowEnd('2026-04-07', 10),
      windowFromSaturday: b.windowEnd('2026-04-04', 10),
      window5: b.windowEnd('2026-04-07', 5),
      count: b.countInclusive('2026-04-07', '2026-04-22'),
      holiday2027: b.isWorkingDay('2027-05-03'),
      rusalii2026: b.isWorkingDay('2026-06-01'),
      jan7: b.isWorkingDay('2026-01-07'),
      unknownYearWeekend: b.isWorkingDay('2030-01-05'),
      unknownYearHoliday: b.isWorkingDay('2030-01-01'),
    };
  });
  expect(r).toEqual({
    nextOfSat: '2026-04-06', afterFriday: '2026-04-06',
    overEaster: '2026-04-14',   // 10 Apr (Vinerea Mare) is not a working day
    overEaster2: '2026-04-15',
    window10: '2026-04-22', windowFromSaturday: '2026-04-21', window5: '2026-04-15',
    count: 10, holiday2027: false, rusalii2026: false, jan7: false,
    unknownYearWeekend: false, unknownYearHoliday: true,
  });
  // Outside 2026-2027 the page says that the holidays are unknown.
  await page.locator('#f-pub').fill('2030-03-04');
  await page.locator('#f-status').selectOption('vacant');
  await expect(page.locator('[data-cal-year-note]')).toBeVisible();
  await expect(page.locator('[data-cal-year-note]')).toContainText('Zilele libere legale nu sunt cunoscute pentru acest an');
});

test('fixtures: the form turns each fixture into the same data, scores them, and downloads a .docx with the payload', async ({ page }, testInfo) => {
  await open(page);
  fs.mkdirSync(path.join(OUT, 'employer_docx'), { recursive: true });
  const quality = { weights: null, fixtures: {} };
  for (const fx of fixtures) {
    await seed(page, fx);
    await page.reload();
    await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);

    // buildData(draft) returns the fixture: the fixtures are in the builder's own canonical form.
    const built = await page.evaluate(() => window.employerBuilder.buildData(window.employerBuilder.current()));
    expect(built, fx.name).toEqual(fx.data);
    const q = await page.evaluate((d) => window.employerBuilder.quality(d), fx.data);
    quality.fixtures[fx.name] = q;
    quality.weights = await page.evaluate(() => window.employerBuilder.QUALITY.map((x) => [x[0], x[1], x[2]]));

    const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descarcă anunțul (.docx)' }).click()]);
    expect(dl.suggestedFilename()).toMatch(/^anunt-[a-z0-9-]+-\d{4}-\d{2}-\d{2}\.docx$/);
    const file = path.join(OUT, 'employer_docx', `${fx.name}.docx`);
    await dl.saveAs(file);

    const unzip = (p) => execFileSync('unzip', ['-p', file, p], { encoding: 'utf8', maxBuffer: 20 * 1024 * 1024 });
    const item = unzip('customXml/item1.xml');
    expect(item).toContain('xmlns="https://posturi.gov2.ro/ns/posting/v1"');
    const inner = item.replace(/^[\s\S]*?<posting[^>]*>/, '').replace(/<\/posting>\s*$/, '')
      .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
    const payload = JSON.parse(inner);
    expect(payload.format).toBe('posturi.posting.v1');
    expect(payload.schema).toBe('v5');
    expect(payload.data, fx.name).toEqual(fx.data);
    expect(payload.employer, fx.name).toEqual(fx.employer);
    expect('employer' in payload, fx.name).toBe(!!fx.employer);

    const custom = unzip('docProps/custom.xml');
    const m = /posturi\.posting[\s\S]*?<vt:lpwstr>([^<]+)<\/vt:lpwstr>/.exec(custom);
    expect(m, `${fx.name}: custom property`).not.toBeNull();
    expect(m[1]).toBe('posturi.posting.v1;sha256=' + crypto.createHash('sha256').update(Buffer.from(inner, 'utf8')).digest('hex'));
    expect(m[1].length).toBeLessThan(255);

    expect(unzip('customXml/itemProps1.xml')).toContain('ds:schemaRef ds:uri="https://posturi.gov2.ro/ns/posting/v1"');
    expect(unzip('customXml/_rels/item1.xml.rels')).toContain('customXmlProps');
    expect(unzip('word/_rels/document.xml.rels')).toContain('relationships/customXml');
    const ct = unzip('\\[Content_Types\\].xml');
    expect(ct).toContain('/customXml/itemProps1.xml');
    expect(ct).toContain('customXmlProperties+xml');
    // The text of the anunț is in the body.
    expect(unzip('word/document.xml')).toContain(fx.data.occupation_title.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'));
  }
  fs.writeFileSync(path.join(OUT, 'employer_quality.json'), JSON.stringify(quality, null, 2));
});

test('the .json download holds the payload alone', async ({ page }, testInfo) => {
  await open(page);
  await seed(page, fixtures.find((f) => f.name === 'full'));
  await page.reload();
  const [dl] = await Promise.all([page.waitForEvent('download'), page.getByRole('button', { name: 'Descarcă datele (.json)' }).click()]);
  const file = testInfo.outputPath('payload.json');
  await dl.saveAs(file);
  const p = JSON.parse(fs.readFileSync(file, 'utf8'));
  expect(Object.keys(p).sort()).toEqual(['created', 'data', 'employer', 'format', 'generator', 'schema']);
  expect(p.data).toEqual(fixtures.find((f) => f.name === 'full').data);
  expect(p.employer).toEqual(fixtures.find((f) => f.name === 'full').employer);
});

test('the page states the scope and the PDF warning', async ({ page }) => {
  await open(page);
  await expect(page.locator('body')).toContainText('un singur post pentru fiecare anunț');
  await expect(page.locator('body')).toContainText('Datele rămân doar în acest browser. Pe un calculator comun, șterge-le când termini.');
  await expect(page.locator('body')).toContainText('Datele structurate se pierd dacă salvezi anunțul ca PDF. Publică fișierul .docx.');
  await noOverflow(page, 'page');
});

test('links to the builder: footer, /angajatori/', async ({ page }) => {
  await page.goto('/angajatori/');
  await expect(page.locator('main a[href="/pentru-angajatori/anunt/"], body > div a[href="/pentru-angajatori/anunt/"]').first()).toBeVisible();
  await expect(page.locator('footer a[href="/pentru-angajatori/anunt/"]')).toHaveText('Pentru angajatori: scrie un anunț structurat');
  await noOverflow(page, '/angajatori/');
  const res = await page.request.get('/pentru-angajatori/anunt');
  expect(res.status()).toBe(200);
});
