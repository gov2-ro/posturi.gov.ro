// UX-11 browser checks: the browser-local application-preparation checklist on the detail page.
//
// Serves its own fixture (tests/fixtures/make_checklist.php: the plain fixture plus postings
// 1101-1107) on its own PHP server and port. The revision case needs the SAME origin before and
// after a source change (localStorage is per origin), so there is one server and the database
// file under it is swapped between variant A and variant B with an atomic rename.
//
// Covers: check, reload, cross-tab, revised source (identical item keeps its state; changed, new
// and removed items are listed; dismiss), unknown documents, passed deadline, unknown revision,
// corrupt / wrong-version / invalid / unavailable / quota storage, clear with cancel and confirm,
// hostile labels (reader's and source's), duplicates, keyboard and live announcements, no-JS,
// privacy (no requests, no forms, preferences key untouched, URL unchanged), 320/375/1280.
const { test, expect } = require('@playwright/test');
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

const webappDir = path.resolve(__dirname, '..', '..');
const KEY = 'posturi.checklist.v1';
const PREFS = 'posturi.preferences.v1';
const URL1101 = 'https://posturi.gov.ro/anunt/fixture-1101';
const REV_A = 'a11010000000000000000000';
const REV_B = 'b11010000000000000000000';
const SESSION_ONLY = 'Disponibil doar în această sesiune; browserul nu a putut salva progresul.';
const UNREADABLE = 'Progresul salvat nu poate fi citit; nu a fost modificat.';
const NO_DOCS = 'Lista documentelor nu a fost extrasă automat. Consultați anunțul oficial pentru documentele cerute.';
const DISCLOSURE = 'Progresul se păstrează doar în acest browser.';

let server;
let base;
let live;
let dbA;
let dbB;

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); });
    s.on('error', reject);
  });
}

/** Put variant 'a' or 'b' under the running server (atomic: a request never sees half a file). */
function setDb(variant) {
  const tmp = live + '.swap';
  fs.copyFileSync(variant === 'a' ? dbA : dbB, tmp);
  fs.renameSync(tmp, live);
}

test.beforeAll(async () => {
  const stem = path.join(os.tmpdir(), `posturi-checklist-browser-${process.pid}`);
  live = `${stem}.sqlite`;
  dbA = `${stem}-a.sqlite`;
  dbB = `${stem}-b.sqlite`;
  execFileSync('php', ['tests/fixtures/make_checklist.php', dbA, 'a'], { cwd: webappDir });
  execFileSync('php', ['tests/fixtures/make_checklist.php', dbB, 'b'], { cwd: webappDir });
  setDb('a');
  const port = await freePort();
  base = `http://127.0.0.1:${port}`;
  server = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: webappDir, stdio: 'ignore',
    env: { ...process.env, POSTURI_DB: live, POSTURI_TODAY: '2026-10-03' },
  });
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(base + '/')).ok) return; } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('checklist fixture server did not start');
});

test.afterAll(() => {
  server?.kill();
  for (const f of [live, dbA, dbB]) { try { fs.unlinkSync(f); } catch { /* already gone */ } }
});

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  setDb('a');
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

const sec = (page) => page.locator('#pregatire-dosar');
const live_ = (page) => sec(page).locator('[data-cl-live]');
const box = (page, kind, label) =>
  sec(page).getByRole('checkbox', { name: `${kind === 'doc' ? 'Pregătit' : 'Notat'}: ${label}`, exact: true });
const doc = (page, label) => box(page, 'doc', label);
const stage = (page, label) => box(page, 'stage', label);
const stored = (page) => page.evaluate((k) => JSON.parse(localStorage.getItem(k)), KEY);
const storedRaw = (page) => page.evaluate((k) => localStorage.getItem(k), KEY);
const noOverflow = (page) => page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);

async function go(page, id) {
  await page.goto(`${base}/job/${id}/`);
  await expect(sec(page).locator('[data-cl-js]')).toBeVisible();
}
/** Write into storage; needs an origin, so visit a light page first. */
async function seed(page, entries) {
  await page.goto(`${base}/despre/`);
  await page.evaluate((e) => { for (const [k, v] of Object.entries(e)) localStorage.setItem(k, v); }, entries);
}

const A_DOCS = ['Cerere de înscriere', 'Copie după cartea de identitate', 'Copie după diploma de studii', 'Curriculum vitae', 'Adeverință medicală'];
const ST_DEADLINE = 'Termen de depunere a dosarului — 16.10.2026, ora 14:30';
const ST_WRITTEN = 'Probă scrisă — 28.10.2026, ora 10:00';
const ST_INTERVIEW_A = 'Interviu — 03.11.2026, ora 09:00';
const ST_INTERVIEW_B = 'Interviu — 05.11.2026, ora 09:00';

test('check survives reload; stage ticking never changes dates or the passed state', async ({ page }) => {
  await go(page, 1101);
  await expect(page.locator('#cl-heading')).toHaveText('Pregătirea dosarului');
  await expect(sec(page)).toContainText('Lista provine din extragerea automată a anunțului și poate fi incompletă.');
  await expect(sec(page).locator('[data-cl-storage]')).toContainText(DISCLOSURE);
  expect(await storedRaw(page)).toBeNull();                       // nothing is written by merely looking

  await doc(page, 'Cerere de înscriere').check();
  await stage(page, ST_WRITTEN).check();
  await expect(sec(page).locator('[data-cl-summary="document"]')).toHaveText('1 din 5 documente pregătite');
  const s = await stored(page);
  expect(s.version).toBe(1);
  const rec = s.postings[URL1101];
  expect(rec.rev).toBe(REV_A);
  expect(Object.keys(rec.items)).toEqual([`${REV_A.slice(0, 12)}:document:1`, `${REV_A.slice(0, 12)}:stage:2`]);
  const e = rec.items[`${REV_A.slice(0, 12)}:document:1`];
  expect(e).toMatchObject({ kind: 'document', label: 'Cerere de înscriere', checked: true });
  expect(e.fp).toMatch(/^[0-9a-f]{16}$/);
  expect(Date.parse(e.at)).not.toBeNaN();
  expect(rec.seen.length).toBe(8);
  expect(rec.custom).toEqual([]);

  await page.reload();
  await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
  await expect(stage(page, ST_WRITTEN)).toBeChecked();
  await expect(doc(page, 'Curriculum vitae')).not.toBeChecked();
  await expect(sec(page).locator('[data-cl-summary="document"]')).toHaveText('1 din 5 documente pregătite');
  // ticking a stage is a note, not a state change: the date and every label stay as the source gave them
  await expect(sec(page).locator('[data-cl-id$=":stage:2"] time')).toHaveText('28.10.2026, ora 10:00');
  await expect(sec(page).locator('[data-cl-passed-tag]')).toHaveCount(0);
  // "prepared" and "noted", never "submitted": the only control texts are these two words
  const words = new Set(await sec(page).locator('[data-cl-check-text]').allTextContents());
  expect([...words].sort()).toEqual(['Notat', 'Pregătit']);
  await doc(page, 'Cerere de înscriere').uncheck();
  expect((await stored(page)).postings[URL1101]?.items ?? {}).not.toHaveProperty(`${REV_A.slice(0, 12)}:document:1`);
});

test('a second tab sees the change through the storage event', async ({ page, context }) => {
  await go(page, 1101);
  const other = await context.newPage();
  await go(other, 1101);
  await doc(page, 'Curriculum vitae').check();
  await expect(doc(other, 'Curriculum vitae')).toBeChecked();
  await expect(sec(other).locator('[data-cl-summary="document"]')).toHaveText('1 din 5 documente pregătite');
  await doc(other, 'Curriculum vitae').uncheck();
  await expect(doc(page, 'Curriculum vitae')).not.toBeChecked();
  // sequential edits from two tabs do not overwrite each other
  await doc(page, 'Cerere de înscriere').check();
  await doc(other, 'Adeverință medicală').check();
  const items = Object.values((await stored(page)).postings[URL1101].items).map((x) => x.label).sort();
  expect(items).toEqual(['Adeverință medicală', 'Cerere de înscriere']);
  await other.close();
});

test('revised source: identical items keep their state, changed/new/removed are listed, nothing is applied silently', async ({ page }) => {
  await go(page, 1101);
  for (const l of ['Cerere de înscriere', 'Copie după diploma de studii', 'Adeverință medicală']) await doc(page, l).check();
  await stage(page, ST_WRITTEN).check();
  await stage(page, ST_INTERVIEW_A).check();
  expect(Object.keys((await stored(page)).postings[URL1101].items).length).toBe(5);

  setDb('b');
  await page.reload();
  await expect(sec(page).locator('[data-cl-js]')).toBeVisible();
  // provably identical: same label, same date, same hour
  await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
  await expect(stage(page, ST_WRITTEN)).toBeChecked();
  // reworded, new and moved: not carried
  await expect(doc(page, 'Copie legalizată după diploma de studii')).not.toBeChecked();
  await expect(doc(page, 'Cazier judiciar')).not.toBeChecked();
  await expect(stage(page, ST_INTERVIEW_B)).not.toBeChecked();

  const review = sec(page).locator('[data-cl-review]');
  await expect(review).toBeVisible();
  await expect(review).toContainText('Anunțul s-a modificat de la ultima vizită');
  await expect(review.locator('[data-cl-review-added-list] li')).toHaveText([
    'Copie legalizată după diploma de studii', 'Cazier judiciar', ST_INTERVIEW_B]);
  await expect(review.locator('[data-cl-review-removed-list] li')).toHaveText([
    /^Copie după diploma de studii — din versiunea anterioară \(bifat la \d\d\.\d\d\.\d{4}\)$/,
    /^Adeverință medicală — din versiunea anterioară/,
    /^Interviu — 03\.11\.2026, ora 09:00 — din versiunea anterioară/]);
  // looking changed nothing: still revision A in storage
  expect((await stored(page)).postings[URL1101].rev).toBe(REV_A);

  // the first action brings the record to revision B; unresolved progress is kept with its old revision
  await doc(page, 'Cazier judiciar').check();
  const rec = (await stored(page)).postings[URL1101];
  expect(rec.rev).toBe(REV_B);
  expect(Object.values(rec.items).map((x) => x.label).sort()).toEqual(['Cazier judiciar', 'Cerere de înscriere', ST_WRITTEN].sort());
  expect(rec.prev.items.map((x) => x.label).sort()).toEqual(['Adeverință medicală', 'Copie după diploma de studii', ST_INTERVIEW_A].sort());
  expect(rec.prev.items.every((x) => x.rev === REV_A)).toBe(true);
  expect(rec.prev.seen.length).toBe(8);

  await page.reload();
  await expect(review).toBeVisible();                              // until the reader dismisses it
  await expect(sec(page).locator('[data-cl-notice]')).toBeHidden();   // and what was stored reads back as valid
  await expect(doc(page, 'Cazier judiciar')).toBeChecked();
  await sec(page).getByRole('button', { name: 'Am înțeles' }).click();
  await expect(review).toBeHidden();
  await expect(live_(page)).toHaveText('Modificările au fost confirmate.');
  expect((await stored(page)).postings[URL1101].prev).toBeUndefined();
  await page.reload();
  await expect(sec(page).locator('[data-cl-review]')).toBeHidden();
  await expect(doc(page, 'Cazier judiciar')).toBeChecked();
});

test('a revision that changes nothing in the list keeps every state and shows no review', async ({ page }) => {
  await go(page, 1102);
  const names = await sec(page).locator('[data-cl-list="document"] [data-cl-check]').count();
  expect(names).toBe(3);
  await sec(page).locator('[data-cl-list="document"] [data-cl-check]').first().check();
  // same posting, a new content hash, an identical list
  const raw = await storedRaw(page);
  await page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, raw.replace(/"rev":"[^"]+"/, '"rev":"older"')]);
  await page.reload();
  await expect(sec(page).locator('[data-cl-list="document"] [data-cl-check]').first()).toBeChecked();
  await expect(sec(page).locator('[data-cl-review]')).toBeHidden();
});

test('duplicate labels are separate items: ticking one does not tick the others', async ({ page }) => {
  await go(page, 1107);
  const boxes = sec(page).getByRole('checkbox', { name: 'Pregătit: Copie CI', exact: true });
  await expect(boxes).toHaveCount(3);
  await boxes.nth(0).check();
  await boxes.nth(2).check();
  await page.reload();
  await expect(boxes.nth(0)).toBeChecked();
  await expect(boxes.nth(1)).not.toBeChecked();
  await expect(boxes.nth(2)).toBeChecked();
  const ids = Object.keys((await stored(page)).postings['https://posturi.gov.ro/anunt/fixture-1107'].items);
  expect(new Set(ids).size).toBe(2);
});

test('unknown documents are said to be unknown; a passed deadline stays visible and ticking cannot reopen it', async ({ page }) => {
  await go(page, 1106);
  await expect(sec(page).locator('[data-cl-empty="document"]')).toHaveText(NO_DOCS);
  await expect(sec(page)).not.toContainText('nu sunt necesare');
  await expect(sec(page)).not.toContainText('fără documente');
  await expect(sec(page).locator('[data-cl-no-deadline]')).toBeVisible();
  await expect(sec(page).locator('[data-cl-official]')).toHaveAttribute('href', 'https://posturi.gov.ro/anunt/fixture-1106');

  await go(page, 1103);
  await expect(sec(page).locator('[data-cl-empty="document"]')).toHaveText(NO_DOCS);
  const dl = sec(page).locator('[data-cl-deadline]');
  await expect(dl.locator('[data-cl-passed-tag]')).toHaveText('Termen trecut');
  await expect(dl.locator('time')).toHaveText('30.09.2026, ora 12:00');
  await expect(sec(page).locator('[data-cl-id$=":stage:2"] [data-cl-passed-tag]')).toHaveCount(0);   // the interview is still ahead
  await stage(page, 'Termen de depunere a dosarului — 30.09.2026, ora 12:00').check();
  await expect(dl.locator('[data-cl-passed-tag]')).toHaveText('Termen trecut');
  await expect(dl).toHaveAttribute('data-cl-passed', '1');
  await page.reload();
  await expect(dl.locator('[data-cl-passed-tag]')).toHaveText('Termen trecut');
  expect(new Set(await sec(page).locator('[data-cl-check-text]').allTextContents())).toEqual(new Set(['Notat']));
});

test('the expiry fallback is qualified like the page does; a date-only deadline says so', async ({ page }) => {
  await go(page, 1102);
  const dl = sec(page).locator('[data-cl-deadline]');
  await expect(dl).toContainText('Termen estimat pentru depunerea dosarului');
  await expect(dl.locator('[data-cl-estimated]')).toHaveText('Expirarea anunțului; înscriere neconfirmată.');
  await expect(dl).toContainText('doar data; ora nu este precizată în date');
  await expect(dl.locator('time')).toHaveText('20.10.2026');
  await go(page, 1101);
  await expect(sec(page).locator('[data-cl-deadline]')).not.toContainText('doar data');
  await expect(sec(page).locator('[data-cl-deadline] [data-cl-estimated]')).toHaveCount(0);
});

test('unknown revision: no identity is claimed; stored progress is shown as unconfirmed and left alone', async ({ page }) => {
  const url = 'https://posturi.gov.ro/anunt/fixture-1104';
  const old = { fp: '0123456789abcdef', kind: 'stage', label: ST_WRITTEN, checked: true, at: '2026-10-01T10:00:00.000Z' };
  await seed(page, { [KEY]: JSON.stringify({ version: 1, postings: { [url]: {
    rev: 'zzzz', updatedAt: '2026-10-01T10:00:00.000Z', seen: ['0123456789abcdef'], items: { 'zzzz00000000:stage:1': old }, custom: [] } } }) });
  await go(page, 1104);
  await expect(sec(page).locator('[data-cl-notice]')).toContainText('Versiunea anunțului nu poate fi confirmată');
  const st = sec(page).getByRole('checkbox', { name: /^Notat: / });
  await expect(st.first()).toBeDisabled();
  await expect(st.first()).not.toBeChecked();
  const unconfirmed = sec(page).locator('[data-cl-unconfirmed]');
  await expect(unconfirmed).toBeVisible();
  await expect(unconfirmed).toContainText('neconfirmat pentru versiunea curentă');
  await expect(unconfirmed.locator('li')).toHaveText([ST_WRITTEN]);
  // reader-added items do not depend on the source revision
  await sec(page).getByLabel('Adaugă un element al dvs.').fill('Poză pașaport');
  await sec(page).getByRole('button', { name: 'Adaugă' }).click();
  const rec = (await stored(page)).postings[url];
  expect(rec.rev).toBe('zzzz');
  expect(rec.items['zzzz00000000:stage:1']).toMatchObject({ fp: old.fp, label: old.label });
  expect(rec.custom.map((c) => c.label)).toEqual(['Poză pașaport']);
});

test.describe('storage failures', () => {
  test('corrupt JSON: message, never overwritten, the session still works', async ({ page }) => {
    const raw = '{"version":1,"postings":{"https://posturi.gov.ro/anunt/x":';
    await seed(page, { [KEY]: raw });
    await go(page, 1101);
    await expect(sec(page).locator('[data-cl-notice]')).toContainText(UNREADABLE);
    await doc(page, 'Cerere de înscriere').check();
    await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
    await sec(page).getByLabel('Adaugă un element al dvs.').fill('Ceva');
    await sec(page).getByRole('button', { name: 'Adaugă' }).click();
    await expect(sec(page).locator('[data-cl-custom-list] li')).toHaveCount(1);
    expect(await storedRaw(page)).toBe(raw);
    await page.reload();                                           // the session copy is gone, the bad data is still there untouched
    await expect(doc(page, 'Cerere de înscriere')).not.toBeChecked();
    expect(await storedRaw(page)).toBe(raw);
  });

  test('unknown version and wrong shapes are left alone', async ({ page }) => {
    for (const raw of ['{"version":2,"postings":{}}', '[]', '{"version":1}', '"x"', '{"version":1,"postings":[]}']) {
      await seed(page, { [KEY]: raw });
      await go(page, 1101);
      await expect(sec(page).locator('[data-cl-notice]')).toContainText(UNREADABLE);
      await doc(page, 'Curriculum vitae').check();
      expect(await storedRaw(page), raw).toBe(raw);
    }
  });

  test('an invalid entry for this posting is kept untouched; another posting\'s valid progress survives', async ({ page }) => {
    const other = 'https://posturi.gov.ro/anunt/fixture-1102';
    const good = { rev: 'rrr', updatedAt: '2026-10-01T10:00:00.000Z', seen: [], items: {}, custom: [{ id: 'c1', label: 'Alt anunț', checked: false, at: '2026-10-01T10:00:00.000Z' }] };
    const bad = { rev: 5, items: 'nope' };
    const raw = JSON.stringify({ version: 1, postings: { [URL1101]: bad, [other]: good } });
    await seed(page, { [KEY]: raw });
    await go(page, 1101);
    await expect(sec(page).locator('[data-cl-notice]')).toContainText('Progresul salvat pentru acest anunț nu poate fi citit; nu a fost modificat.');
    await doc(page, 'Cerere de înscriere').check();
    expect(await storedRaw(page)).toBe(raw);
    // the other posting is fine
    await go(page, 1102);
    await expect(sec(page).locator('[data-cl-custom-list] li')).toHaveCount(1);
    await expect(sec(page).locator('[data-cl-custom-label]')).toHaveText('Alt anunț');
    await sec(page).locator('[data-cl-list="document"] [data-cl-check]').first().check();
    const s = await stored(page);
    expect(s.postings[URL1101]).toEqual(bad);                      // the invalid entry is written back as it was
    expect(Object.keys(s.postings[other].items).length).toBe(1);
    expect(s.postings[other].custom.length).toBe(1);
  });

  test('storage that throws on read: session only, said so at once', async ({ page }) => {
    await page.addInitScript((k) => {
      const get = Storage.prototype.getItem;
      Storage.prototype.getItem = function (key) { if (key === k) throw new DOMException('denied', 'SecurityError'); return get.call(this, key); };
    }, KEY);
    await go(page, 1101);
    await expect(sec(page).locator('[data-cl-notice]')).toContainText(SESSION_ONLY);
    await doc(page, 'Cerere de înscriere').check();
    await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
  });

  test('quota exceeded: never reported as saved; the session copy keeps working', async ({ page }) => {
    await page.addInitScript((k) => {
      const set = Storage.prototype.setItem;
      Storage.prototype.setItem = function (key, v) {
        if (key === k) throw new DOMException('full', 'QuotaExceededError');
        return set.call(this, key, v);
      };
    }, KEY);
    await go(page, 1101);
    await expect(sec(page).locator('[data-cl-notice]')).toBeHidden();
    await doc(page, 'Cerere de înscriere').check();
    await expect(sec(page).locator('[data-cl-notice]')).toContainText(SESSION_ONLY);
    await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
    await doc(page, 'Curriculum vitae').check();
    await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
    await expect(sec(page).locator('[data-cl-summary="document"]')).toHaveText('2 din 5 documente pregătite');
    expect(await storedRaw(page)).toBeNull();
  });

  test('a write that does not stick is detected by read-back', async ({ page }) => {
    await page.addInitScript((k) => {
      const set = Storage.prototype.setItem;
      Storage.prototype.setItem = function (key, v) { if (key !== k) return set.call(this, key, v); };
    }, KEY);
    await go(page, 1101);
    await doc(page, 'Cerere de înscriere').check();
    await expect(sec(page).locator('[data-cl-notice]')).toContainText(SESSION_ONLY);
  });
});

test('clear asks in the page, cancel changes nothing, confirm removes only this posting', async ({ page }) => {
  const prefs = JSON.stringify({ version: 1, records: {} });
  await seed(page, { [PREFS]: prefs, 'pg.skin': 'govuk', 'posturi.facets': '{"open":["judet"]}' });
  await go(page, 1101);
  await doc(page, 'Cerere de înscriere').check();
  await stage(page, ST_WRITTEN).check();
  await sec(page).getByLabel('Adaugă un element al dvs.').fill('Poză');
  await sec(page).getByRole('button', { name: 'Adaugă' }).click();
  // another posting's progress, written behind this tab's back
  const other = 'https://posturi.gov.ro/anunt/fixture-1102';
  await page.evaluate(([k, o]) => {
    const s = JSON.parse(localStorage.getItem(k));
    s.postings[o] = { rev: 'rrr', updatedAt: '2026-10-01T10:00:00.000Z', seen: [], items: {}, custom: [{ id: 'c1', label: 'Alt anunț', checked: false, at: '2026-10-01T10:00:00.000Z' }] };
    localStorage.setItem(k, JSON.stringify(s));
  }, [KEY, other]);
  const before = await storedRaw(page);

  const clear = sec(page).getByRole('button', { name: 'Șterge progresul' });
  const confirm = sec(page).locator('[data-cl-confirm]');
  await expect(confirm).toBeHidden();
  await clear.click();
  await expect(confirm).toBeVisible();
  await expect(confirm).toContainText('Alte anunțuri și lista de salvate nu se schimbă.');
  await expect(sec(page).getByRole('button', { name: 'Anulează' })).toBeFocused();
  await page.keyboard.press('Escape');                             // cancel by keyboard
  await expect(confirm).toBeHidden();
  await expect(clear).toBeFocused();
  expect(await storedRaw(page)).toBe(before);
  await clear.click();
  await sec(page).getByRole('button', { name: 'Anulează' }).click();
  await expect(confirm).toBeHidden();
  expect(await storedRaw(page)).toBe(before);
  await expect(doc(page, 'Cerere de înscriere')).toBeChecked();

  await clear.click();
  await sec(page).getByRole('button', { name: 'Șterge', exact: true }).click();
  await expect(confirm).toBeHidden();
  await expect(clear).toBeFocused();
  await expect(live_(page)).toHaveText('Progresul pentru acest anunț a fost șters.');
  await expect(doc(page, 'Cerere de înscriere')).not.toBeChecked();
  await expect(stage(page, ST_WRITTEN)).not.toBeChecked();
  await expect(sec(page).locator('[data-cl-custom-list] li')).toHaveCount(0);
  const s = await stored(page);
  expect(Object.keys(s.postings)).toEqual([other]);
  expect(await page.evaluate((k) => localStorage.getItem(k), PREFS)).toBe(prefs);
  expect(await page.evaluate(() => localStorage.getItem('pg.skin'))).toBe('govuk');
  expect(await page.evaluate(() => localStorage.getItem('posturi.facets'))).toBe('{"open":["judet"]}');
});

test('reader-added items: text only, distinct from source rows, capped, removable', async ({ page }) => {
  await page.addInitScript(() => { window.__pwned = 0; });
  await go(page, 1101);
  const hostile = '<img src=x onerror="window.__pwned=1"> & "q" <script>window.__pwned=2</script>';
  const input = sec(page).getByLabel('Adaugă un element al dvs.');
  await input.fill(hostile);
  await sec(page).getByRole('button', { name: 'Adaugă' }).click();
  const item = sec(page).locator('[data-cl-custom-item]');
  await expect(item).toHaveCount(1);
  await expect(item.locator('[data-cl-custom-label]')).toHaveText(hostile);
  await expect(item).toContainText('Adăugat de dvs.');
  expect(await sec(page).locator('[data-cl-custom-list] img, [data-cl-custom-list] script').count()).toBe(0);
  expect(await page.evaluate(() => window.__pwned)).toBe(0);
  await expect(input).toHaveValue('');
  await expect(input).toBeFocused();
  await expect(live_(page)).toHaveText(`Element adăugat: ${hostile}.`);
  // source rows carry no "Adăugat de dvs." tag
  await expect(sec(page).locator('[data-cl-list="document"] li').first()).not.toContainText('Adăugat de dvs.');

  await item.getByRole('checkbox').check();
  expect((await stored(page)).postings[URL1101].custom[0]).toMatchObject({ label: hostile, checked: true });
  await page.reload();
  await expect(sec(page).locator('[data-cl-custom-item] [data-cl-custom-label]')).toHaveText(hostile);
  await expect(sec(page).locator('[data-cl-custom-item]').getByRole('checkbox')).toBeChecked();

  await input.fill('x'.repeat(300));
  await sec(page).getByRole('button', { name: 'Adaugă' }).click();
  const labels = (await stored(page)).postings[URL1101].custom.map((c) => c.label.length);
  expect(labels[1]).toBe(120);
  await input.fill('   ');
  await sec(page).getByRole('button', { name: 'Adaugă' }).click();
  await expect(sec(page).locator('[data-cl-flash]')).toHaveText('Scrie întâi textul elementului.');
  expect((await stored(page)).postings[URL1101].custom.length).toBe(2);

  await sec(page).locator('[data-cl-custom-item]').first().getByRole('button', { name: /^Elimină/ }).click();
  await expect(sec(page).locator('[data-cl-custom-item]')).toHaveCount(1);
  await expect(live_(page)).toHaveText(`Element eliminat: ${hostile}.`);
  expect((await stored(page)).postings[URL1101].custom.length).toBe(1);
});

test('limits: 100 items per posting and 200 postings, the oldest posting goes first', async ({ page }) => {
  const at = '2026-10-01T10:00:00.000Z';
  const custom = Array.from({ length: 100 }, (_, i) => ({ id: `c${i}`, label: `Element ${i}`, checked: false, at }));
  await seed(page, { [KEY]: JSON.stringify({ version: 1, postings: { [URL1101]: { rev: REV_A, updatedAt: at, seen: [], items: {}, custom } } }) });
  await go(page, 1101);
  await sec(page).getByLabel('Adaugă un element al dvs.').fill('unul în plus');
  await sec(page).getByRole('button', { name: 'Adaugă' }).click();
  await expect(sec(page).locator('[data-cl-flash]')).toContainText('Ai atins limita de 100');
  expect((await stored(page)).postings[URL1101].custom.length).toBe(100);
  await doc(page, 'Cerere de înscriere').click();                      // check() would insist the state changed
  await expect(doc(page, 'Cerere de înscriere')).not.toBeChecked();   // refused, not silently kept
  expect(Object.keys((await stored(page)).postings[URL1101].items).length).toBe(0);

  const postings = {};
  for (let i = 0; i < 200; i++) {
    postings[`https://posturi.gov.ro/anunt/other-${i}`] = { rev: 'r', updatedAt: new Date(Date.UTC(2026, 8, 1, 0, 0, i)).toISOString(), seen: [], items: {},
      custom: [{ id: 'c1', label: 'x', checked: false, at }] };
  }
  await seed(page, { [KEY]: JSON.stringify({ version: 1, postings }) });
  await go(page, 1101);
  await doc(page, 'Cerere de înscriere').check();
  const keys = Object.keys((await stored(page)).postings);
  expect(keys.length).toBe(200);
  expect(keys).toContain(URL1101);
  expect(keys).not.toContain('https://posturi.gov.ro/anunt/other-0');
  expect(keys).toContain('https://posturi.gov.ro/anunt/other-1');
});

test('hostile source text is inert: the page, the embedded JSON and the stored labels', async ({ page }) => {
  await page.addInitScript(() => { window.__pwned = 0; });
  await go(page, 1105);
  expect(await page.evaluate(() => window.__pwned)).toBe(0);
  const labels = await sec(page).locator('[data-cl-list="document"] [data-cl-label]').allTextContents();
  expect(labels[0]).toBe('</script><script>window.__pwned=1</script>');
  expect(labels[1]).toBe('Cod "citat" & \'apostrof\' <b>bold</b>');
  expect(labels[2].length).toBeLessThanOrEqual(300);
  expect(labels[2].endsWith('…')).toBe(true);
  await expect(sec(page).locator('[data-cl-list="stage"] [data-cl-label]').nth(1)).toHaveText('</script><img src=x onerror=alert(2)>');
  expect(await sec(page).locator('img, script, b').count()).toBe(0);
  const model = await page.evaluate(() => JSON.parse(document.getElementById('checklist-data').textContent));
  expect(model.items[0].label).toBe('</script><script>window.__pwned=1</script>');
  await doc(page, '</script><script>window.__pwned=1</script>').check();
  expect(Object.values((await stored(page)).postings['https://posturi.gov.ro/anunt/fixture-1105'].items)[0].label)
    .toBe('</script><script>window.__pwned=1</script>');
  expect(await page.evaluate(() => window.__pwned)).toBe(0);
});

test('keyboard: Tab reaches the boxes, Space ticks, Enter adds; every change is announced', async ({ page }) => {
  await go(page, 1101);
  let reached = false;
  for (let i = 0; i < 200 && !reached; i++) {
    await page.keyboard.press('Tab');
    reached = await page.evaluate(() => document.activeElement?.matches('#pregatire-dosar [data-cl-check]') ?? false);
  }
  expect(reached, 'a check box is reachable with Tab').toBe(true);
  await expect(doc(page, 'Cerere de înscriere')).toBeFocused();
  await page.keyboard.press('Space');
  await expect(doc(page, 'Cerere de înscriere')).toBeChecked();
  await expect(live_(page)).toHaveText('Pregătit: Cerere de înscriere. 1 din 5 documente pregătite.');
  await page.keyboard.press('Space');
  await expect(live_(page)).toHaveText('Nepregătit: Cerere de înscriere. 0 din 5 documente pregătite.');
  await stage(page, ST_WRITTEN).focus();
  await page.keyboard.press('Space');
  await expect(live_(page)).toHaveText(`Notat: ${ST_WRITTEN}.`);
  await page.keyboard.press('Space');
  await expect(live_(page)).toHaveText(`Notarea a fost eliminată: ${ST_WRITTEN}.`);

  await sec(page).getByLabel('Adaugă un element al dvs.').focus();
  await page.keyboard.type('Traducere legalizată');
  const url = page.url();
  await page.keyboard.press('Enter');
  await expect(live_(page)).toHaveText('Element adăugat: Traducere legalizată.');
  expect(page.url()).toBe(url);
  await expect(sec(page).locator('[data-cl-custom-label]')).toHaveText('Traducere legalizată');
  // the live region is a polite status region
  await expect(live_(page)).toHaveAttribute('aria-live', 'polite');
  await expect(live_(page)).toHaveAttribute('role', 'status');
  // every control has an accessible name
  const unnamed = await sec(page).locator('input, button').evaluateAll((els) =>
    els.filter((e) => !(e.getAttribute('aria-label') || e.labels?.length || e.textContent.trim())).length);
  expect(unnamed).toBe(0);
});

test('privacy: no requests, no forms, nothing in the URL, the preferences key untouched', async ({ page }) => {
  const prefs = JSON.stringify({ version: 1, records: { 'https://posturi.gov.ro/anunt/fixture-1001': {
    id: 1001, url: 'https://posturi.gov.ro/anunt/fixture-1001', path: '/job/1001-x/', title: 'T', employer: '', place: '', date: '', dateSource: '',
    savedAt: '2026-10-01T10:00:00.000Z', hiddenAt: null, snapshotAt: '2026-10-01T10:00:00.000Z' } } });
  await seed(page, { [PREFS]: prefs });
  await page.addInitScript(() => {
    window.__net = 0;
    const f = window.fetch; window.fetch = function (...a) { window.__net++; return f.apply(this, a); };
    const o = XMLHttpRequest.prototype.open; XMLHttpRequest.prototype.open = function (...a) { window.__net++; return o.apply(this, a); };
    if (navigator.sendBeacon) navigator.sendBeacon = function () { window.__net++; return false; };
  });
  await go(page, 1101);
  await page.waitForLoadState('networkidle');
  const url = page.url();
  const reqs = [];
  page.on('request', (r) => reqs.push(`${r.method()} ${r.url()}`));
  await doc(page, 'Cerere de înscriere').check();
  await stage(page, ST_WRITTEN).check();
  await sec(page).getByLabel('Adaugă un element al dvs.').fill('Mapă cu șină');
  await page.keyboard.press('Enter');
  await sec(page).getByRole('button', { name: 'Șterge progresul' }).click();
  await sec(page).getByRole('button', { name: 'Anulează' }).click();
  await doc(page, 'Cerere de înscriere').uncheck();
  await page.waitForTimeout(300);
  expect(reqs, 'no request is made by any interaction').toEqual([]);
  expect(await page.evaluate(() => window.__net)).toBe(0);
  expect(page.url()).toBe(url);
  expect(await page.evaluate((k) => localStorage.getItem(k), PREFS)).toBe(prefs);
  expect(await sec(page).locator('form').count()).toBe(0);
  expect(await sec(page).locator('input[name], button[type="submit"]').count()).toBe(0);
  expect(await page.evaluate(() => document.getElementById('cl-add-input').hasAttribute('name'))).toBe(false);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('a read-only list with dates, the passed state and the official link; no controls', async ({ page }) => {
    await page.goto(`${base}/job/1101/`);
    await expect(sec(page).locator('[data-cl-official]')).toHaveAttribute('href', URL1101);
    await expect(sec(page).locator('[data-cl-list="document"] li')).toHaveCount(5);
    await expect(sec(page).locator('[data-cl-list="stage"] li')).toHaveCount(3);
    await expect(sec(page).locator('[data-cl-list="document"] li').first()).toContainText('Cerere de înscriere');
    await expect(sec(page).locator('[data-cl-list="stage"] li').first()).toContainText('16.10.2026, ora 14:30');
    await expect(sec(page).locator('[data-cl-js]')).toBeHidden();
    await expect(sec(page).locator('input:visible')).toHaveCount(0);
    await expect(sec(page).locator('button:visible')).toHaveCount(0);
    await expect(sec(page)).toContainText('Lista provine din extragerea automată a anunțului și poate fi incompletă.');
    await expect(sec(page)).toContainText('necesită JavaScript', { useInnerText: true });   // the <noscript> note renders without scripting
    await page.goto(`${base}/job/1103/`);
    await expect(sec(page).locator('[data-cl-passed-tag]')).toHaveText('Termen trecut');
    await expect(sec(page).locator('[data-cl-empty="document"]')).toHaveText(NO_DOCS);
  });
});

test('widths: nothing overflows, touch targets are large on phones', async ({ page }, testInfo) => {
  for (const id of [1101, 1105, 1102]) {
    await go(page, id);
    expect(await noOverflow(page), `overflow on ${id}`).toBeLessThanOrEqual(1);
  }
  // with the review box, custom items and the confirm box all open
  await go(page, 1101);
  for (const l of ['Copie după diploma de studii', 'Adeverință medicală']) await doc(page, l).check();
  await stage(page, ST_INTERVIEW_A).check();
  await sec(page).getByLabel('Adaugă un element al dvs.').fill('Un element cu un text destul de lung ca să treacă pe mai multe rânduri pe un ecran îngust');
  await page.keyboard.press('Enter');
  setDb('b');
  await page.reload();
  await expect(sec(page).locator('[data-cl-review]')).toBeVisible();
  await sec(page).getByRole('button', { name: 'Șterge progresul' }).click();
  await expect(sec(page).locator('[data-cl-confirm]')).toBeVisible();
  expect(await noOverflow(page), 'overflow with review, custom items and confirm open').toBeLessThanOrEqual(1);
  const h = (await doc(page, 'Cerere de înscriere').locator('xpath=ancestor::label').boundingBox()).height;
  if (testInfo.project.name.startsWith('mobile')) expect(h, 'touch target height').toBeGreaterThanOrEqual(43);
  for (const b of await sec(page).locator('button:visible').all()) {
    const bb = await b.boundingBox();
    if (testInfo.project.name.startsWith('mobile')) expect(bb.height, await b.textContent()).toBeGreaterThanOrEqual(43);
  }
  if (process.env.UX11_SHOTS) {
    fs.mkdirSync(process.env.UX11_SHOTS, { recursive: true });
    await sec(page).screenshot({ path: path.join(process.env.UX11_SHOTS, `checklist-${page.viewportSize().width}.png`) });
  }
});
