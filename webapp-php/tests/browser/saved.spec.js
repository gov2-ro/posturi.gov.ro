// UX-09 browser checks: browser-local save/hide, /salvate/, storage failure modes,
// HTMX swaps and history, keyboard, widths. Runs at 320, 375 and 1280 (see
// playwright.config.js) against the deterministic fixture (tests/fixtures/build_db.php).
// Set UX09_SHOTS=<dir> to also write the review screenshots there.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const KEY = 'posturi.preferences.v1';
const SESSION_ONLY = 'Disponibil doar în această sesiune; browserul nu a putut salva preferințele.';
const PERSIST = 'Salvările și anunțurile ascunse rămân în acest browser. Nu se sincronizează între dispozitive și se pot pierde dacă ștergi datele browserului.';

const rec = (id, o = {}) => ({
  id, url: `https://posturi.gov.ro/anunt/fixture-${id}`, path: `/job/${id}-x/`, title: `Titlu ${id}`,
  employer: 'Angajator', place: 'Cluj', date: '2026-10-16', dateSource: 'anunt',
  savedAt: null, hiddenAt: null, snapshotAt: '2026-10-01T10:00:00.000Z', ...o,
});
const at = (i) => new Date(Date.UTC(2026, 9, 1, 10, 0, 0) + i * 1000).toISOString();

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

function shot(page, _testInfo, name) {
  const dir = process.env.UX09_SHOTS;
  if (!dir) return Promise.resolve();
  fs.mkdirSync(dir, { recursive: true });
  return page.screenshot({ path: path.join(dir, `${name}-${page.viewportSize().width}.png`), fullPage: true });
}

/** Write records (an array) or a raw string into storage; needs an origin, so visit a light page first. */
async function seed(page, recs, raw) {
  await page.goto('/despre/');
  await page.evaluate(([k, recsIn, rawIn]) => {
    if (rawIn !== null) { localStorage.setItem(k, rawIn); return; }
    localStorage.setItem(k, JSON.stringify({ version: 1, records: Object.fromEntries(recsIn.map((r) => [r.url, r])) }));
  }, [KEY, recs || [], raw ?? null]);
}
const stored = (page) => page.evaluate((k) => JSON.parse(localStorage.getItem(k)), KEY);
const storedRaw = (page) => page.evaluate((k) => localStorage.getItem(k), KEY);

const row = (page, id) => page.locator(`li[data-pref-id="${id}"], div[data-pref-id="${id}"]`).first();
const saveBtn = (page, id) => row(page, id).locator('[data-pref="save"]');
const hideBtn = (page, id) => row(page, id).locator('[data-pref="hide"]');

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

test('save on the list survives reload; detail and /salvate/ agree; unsave in another tab updates this one', async ({ page, context }, testInfo) => {
  await page.goto('/');
  await expect(page.locator('[data-saved-count]')).toHaveText('(0)');
  const save = saveBtn(page, 1001);
  await expect(save).toBeVisible();
  await expect(save).toHaveAttribute('aria-pressed', 'false');
  await expect(save).toHaveAttribute('aria-label', 'Salvează anunțul: Asistent medical');
  await save.click();
  await expect(save).toHaveAttribute('aria-pressed', 'true');
  await expect(save).toHaveAttribute('aria-label', 'Elimină din salvate: Asistent medical');
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  // First save explains where the data lives.
  await expect(page.locator('#pref-notice')).toBeVisible();
  await expect(page.locator('[data-notice-storage]')).toHaveText(PERSIST);

  const s = await stored(page);
  expect(Object.keys(s.records)).toEqual(['https://posturi.gov.ro/anunt/fixture-1001']);
  const r = s.records['https://posturi.gov.ro/anunt/fixture-1001'];
  expect(r).toMatchObject({ id: 1001, path: '/job/1001-asistent-medical-generalist/', title: 'Asistent medical',
    employer: 'Spitalul Clinic Județean Cluj', place: 'Cluj-Napoca, Cluj', date: '2026-10-16', dateSource: 'anunt', hiddenAt: null });
  expect(r.savedAt).toBeTruthy();
  expect(JSON.stringify(s)).not.toMatch(/body|attachment|candidat/i);

  await page.reload();
  await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#pref-notice')).toBeHidden();

  await page.goto('/job/1001-asistent-medical-generalist/');
  await expect(page.locator('[data-pref="save"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-pref="hide"]')).toHaveAttribute('aria-pressed', 'false');
  await noOverflow(page, 'detail');
  await shot(page, testInfo, 'detail');

  const other = await context.newPage();
  await other.goto('/salvate/');
  const item = other.locator('[data-saved-list] li');
  await expect(item).toHaveCount(1);
  await expect(item.locator('h2 a')).toHaveAttribute('href', '/job/1001-asistent-medical-generalist/');
  await expect(item).toContainText('Înscrieri deschise');
  await expect(other.locator('[data-count="saved"]')).toHaveText('(1)');

  // Unsave in the other tab; the first tab follows without a reload.
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  await other.locator('[data-pref="save"]').click();
  await expect(other.locator('[data-saved-list] li')).toHaveCount(0);
  await expect(page.locator('[data-saved-count]')).toHaveText('(0)');
  await expect(page.locator('[data-pref="save"]')).toHaveAttribute('aria-pressed', 'false');
});

test('sequential edits from two tabs do not overwrite each other', async ({ page, context }) => {
  await page.goto('/');
  const other = await context.newPage();
  await other.goto('/');
  await saveBtn(page, 1001).click();
  // Write behind this tab's back (no storage event reaches the writing document),
  // then mutate: the mutation must start from what is stored now.
  await other.evaluate((k) => {
    const s = JSON.parse(localStorage.getItem(k));
    s.records['https://posturi.gov.ro/anunt/fixture-2001'] = {
      id: 2001, url: 'https://posturi.gov.ro/anunt/fixture-2001', path: '/job/2001-x/', title: 'x', employer: '', place: '',
      date: '', dateSource: '', savedAt: '2026-10-02T10:00:00.000Z', hiddenAt: null, snapshotAt: '2026-10-02T10:00:00.000Z' };
    localStorage.setItem(k, JSON.stringify(s));
  }, KEY);
  await other.locator('li[data-pref-id="1002"] [data-pref="save"]').click();
  const keys = Object.keys((await stored(page)).records).sort();
  expect(keys).toEqual([2001, 1001, 1002].map((i) => `https://posturi.gov.ro/anunt/fixture-${i}`).sort());
});

test('hide, undo, show hidden, restore, and hiding a saved item keeps it saved', async ({ page }, testInfo) => {
  await page.goto('/');
  const feedBefore = await page.locator('[data-feed="posturi.json"]').getAttribute('href');
  const countBefore = await page.locator('#results-status').textContent();
  const listCountBefore = await page.locator('#results .border-b').first().textContent();
  await saveBtn(page, 1002).click();
  await hideBtn(page, 1002).click();
  await expect(row(page, 1002)).toBeHidden();
  // Undo is reachable outside the disappearing row and receives focus.
  await expect(page.locator('[data-notice-undo]')).toBeFocused();
  await expect(page.locator('#pref-live')).toContainText('Anunț ascuns');
  await expect(page.locator('[data-pref-banner-page]')).toContainText('1 anunț ascuns pe această pagină');
  await expect(page.locator('[data-pref-banner]')).toContainText('Numărul rezultatelor include anunțurile ascunse în acest browser.');
  await shot(page, testInfo, 'hidden-banner-notice');
  await noOverflow(page, 'notice visible');

  // Server scope is unchanged by browser-local state.
  expect(await page.locator('[data-feed="posturi.json"]').getAttribute('href')).toBe(feedBefore);
  expect(await page.locator('#results-status').textContent()).toBe(countBefore);
  expect(await page.locator('#results .border-b').first().textContent()).toBe(listCountBefore);

  // Undo restores the row; the saved flag was never touched.
  await page.locator('[data-notice-undo]').click();
  await expect(row(page, 1002)).toBeVisible();
  await expect(hideBtn(page, 1002)).toBeFocused();
  expect((await stored(page)).records['https://posturi.gov.ro/anunt/fixture-1002'].hiddenAt).toBeNull();

  // Hide again, then show hidden and restore from the row.
  await hideBtn(page, 1002).click();
  await expect(row(page, 1002)).toBeHidden();
  await page.locator('[data-pref-toggle-hidden]').click();
  await expect(row(page, 1002)).toBeVisible();
  await expect(row(page, 1002)).toContainText('Ascuns din rezultate');
  await expect(hideBtn(page, 1002)).toHaveAttribute('aria-label', 'Restabilește anunțul: Titlu 1002'.replace('Titlu 1002', await row(page, 1002).getAttribute('data-pref-title')));
  await expect(hideBtn(page, 1002)).toHaveAttribute('aria-pressed', 'true');
  await expect(saveBtn(page, 1002)).toHaveAttribute('aria-pressed', 'true');   // hiding did not unsave
  let r = (await stored(page)).records['https://posturi.gov.ro/anunt/fixture-1002'];
  expect(r.savedAt).toBeTruthy(); expect(r.hiddenAt).toBeTruthy();
  await page.locator('[data-pref-toggle-hidden]').click();                     // "Ascunde din nou"
  await expect(row(page, 1002)).toBeHidden();
  await page.locator('[data-pref-toggle-hidden]').click();
  await hideBtn(page, 1002).click();                                           // restore
  await expect(row(page, 1002)).toBeVisible();
  r = (await stored(page)).records['https://posturi.gov.ro/anunt/fixture-1002'];
  expect(r.hiddenAt).toBeNull();
  expect(r.savedAt).toBeTruthy();
});

test('an all-hidden page keeps pagination and does not claim the search has no results', async ({ page }, testInfo) => {
  await page.goto('/');
  const ids = await page.locator('#results li[data-pref-id]').evaluateAll((els) => els.map((e) => Number(e.dataset.prefId)));
  expect(ids.length).toBe(25);
  const total = await page.locator('#results-status').textContent();
  await seed(page, ids.map((id, i) => rec(id, { hiddenAt: at(i) })));
  await page.goto('/');
  await expect(page.locator('#results li[data-pref-id]:visible')).toHaveCount(0);
  await expect(page.locator('[data-pref-banner-all]')).toContainText('Toate anunțurile de pe această pagină sunt ascunse');
  await expect(page.locator('[data-pref-banner-page]')).toContainText('25 de anunțuri ascunse pe această pagină');
  await expect(page.locator('nav[aria-label="Paginare rezultate"]')).toBeVisible();
  expect(await page.locator('#results-status').textContent()).toBe(total);
  await expect(page.locator('#results')).not.toContainText('Niciun rezultat');
  await shot(page, testInfo, 'all-hidden');
  await noOverflow(page, 'all hidden');

  // The next page is fetched by the server as usual: no gap filling, no extra requests.
  await page.locator('nav[aria-label="Paginare rezultate"] a[rel="next"]').click();
  await expect(page.locator('#results li[data-pref-id]:visible')).not.toHaveCount(0);
  await expect(page.locator('[data-pref-banner-all]')).toBeHidden();
  await expect(page.locator('[data-pref-banner]')).toContainText('Numărul rezultatelor include anunțurile ascunse');
  await page.goBack();
  await expect(page.locator('#results li[data-pref-id]:visible')).toHaveCount(0);   // reapplied on history restore
  await page.locator('[data-pref-toggle-hidden]').click();
  await expect(page.locator('#results li[data-pref-id]:visible')).toHaveCount(25);
});

test('HTMX pagination, back/forward and one listener per click', async ({ page, context }) => {
  await page.goto('/');
  await saveBtn(page, 1001).click();
  await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');
  const next = () => page.locator('nav[aria-label="Paginare rezultate"] a[rel="next"]');
  const prev = () => page.locator('nav[aria-label="Paginare rezultate"] a[rel="prev"]');
  // Several swaps: a duplicated listener would toggle twice per click.
  await next().click(); await expect(page).toHaveURL(/page=2/);
  await prev().click(); await expect(page).toHaveURL(/page=1|\/$/);
  await next().click(); await expect(page).toHaveURL(/page=2/);
  const id2 = await page.locator('#results li[data-pref-id]').first().getAttribute('data-pref-id');
  await saveBtn(page, id2).click();
  await expect(saveBtn(page, id2)).toHaveAttribute('aria-pressed', 'true');
  expect(Object.keys((await stored(page)).records).length).toBe(2);

  // Another tab changes page 1's state while this tab shows page 2; back must show it.
  const other = await context.newPage();
  await other.goto('/');
  await saveBtn(other, 1001).click();                    // unsave 1001
  await expect(saveBtn(other, 1001)).toHaveAttribute('aria-pressed', 'false');
  await page.goBack();
  await expect(page.locator('li[data-pref-id="1001"]')).toBeVisible();
  await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'false');
  await page.goForward();
  await expect(saveBtn(page, id2)).toHaveAttribute('aria-pressed', 'true');
  // Filtering swaps the list too; state follows the rows.
  await seed(page, [rec(1001, { hiddenAt: at(1) })]);
  await page.goto('/?judet%5B%5D=cluj');
  await expect(row(page, 1001)).toBeHidden();
  await page.locator('[data-pref-toggle-hidden]').click();
  await expect(row(page, 1001)).toBeVisible();
});

test('hide works on employer pages', async ({ page }, testInfo) => {
  await page.goto('/angajator/spitalul-clinic-judetean-cluj/');
  await expect(row(page, 1001)).toBeVisible();
  await hideBtn(page, 1001).click();
  await expect(row(page, 1001)).toBeHidden();
  await expect(page.locator('[data-pref-banner-page]')).toContainText('1 anunț ascuns');
  await expect(page.locator('[data-notice-undo]')).toBeFocused();
  await shot(page, testInfo, 'employer-hidden');
  await noOverflow(page, 'employer');
  await page.locator('[data-notice-undo]').click();
  await expect(row(page, 1001)).toBeVisible();
  await saveBtn(page, 1006).click();
  await expect(saveBtn(page, 1006)).toHaveAttribute('aria-pressed', 'true');
});

test('/salvate/: empty state, sorting, batches of 25, per-item actions, tabs', async ({ page }, testInfo) => {
  await page.goto('/salvate/');
  await expect(page.locator('[data-saved-empty]')).toContainText('Nu ai salvat încă niciun anunț');
  await expect(page.locator('body')).toContainText(PERSIST);
  await shot(page, testInfo, 'saved-empty');
  await page.goto('/salvate/?vedere=ascunse');
  await expect(page.locator('[data-saved-empty]')).toContainText('Nu ai ascuns niciun anunț');

  // 26 saved (ids exist in the fixture), newest first; one saved item is also hidden.
  const recs = [];
  for (let i = 0; i < 26; i++) recs.push(rec(2001 + i, { savedAt: at(i), title: `Salvat ${i}` }));
  recs[3].hiddenAt = at(500);
  recs.push(rec(3001, { hiddenAt: at(900), title: 'Doar ascuns' }));
  await seed(page, recs);
  const lookups = [];
  page.on('request', (r) => { if (r.url().includes('preferinte-posturi')) lookups.push(JSON.parse(r.postData()).ids); });
  await page.goto('/salvate/');
  const items = page.locator('[data-saved-list] li');
  await expect(items).toHaveCount(25);
  await expect(items.first()).toHaveAttribute('data-pref-id', '2026');          // newest saved first
  await expect(page.locator('[data-count="saved"]')).toHaveText('(26)');
  await expect(page.locator('[data-count="hidden"]')).toHaveText('(2)');
  await expect(page.locator('[data-pager-info]')).toHaveText('1–25 din 26');
  await expect(page.locator('[data-saved-list] li[data-pref-id="2004"] [data-f="hidden-tag"]')).toBeVisible();   // saved and hidden are independent
  await expect(items.first()).toContainText('Înscrieri deschise');
  await expect(items.first().locator('[data-retry]')).toBeHidden();      // retry only after a failed lookup
  await expect.poll(() => lookups.length).toBeGreaterThan(0);
  expect(lookups.every((l) => l.length <= 25)).toBe(true);                       // only ids needed by this page
  await shot(page, testInfo, 'saved-26');
  await noOverflow(page, '/salvate/ page 1');

  await page.locator('[data-pager-next]').click();
  await expect(page).toHaveURL(/pagina=2/);
  await expect(page.locator('[data-saved-list] li')).toHaveCount(1);
  await expect(page.locator('[data-saved-list] li')).toHaveAttribute('data-pref-id', '2001');
  await expect(page.locator('[data-pager-next]')).toBeHidden();

  // Unsave from the list with an undo outside the item.
  await page.goto('/salvate/');
  await page.locator('[data-saved-list] li[data-pref-id="2026"] [data-pref="save"]').click();
  await expect(page.locator('[data-count="saved"]')).toHaveText('(25)');
  await expect(page.locator('[data-notice-undo]')).toBeFocused();
  await page.locator('[data-notice-undo]').click();
  await expect(page.locator('[data-count="saved"]')).toHaveText('(26)');

  // Hidden view: restore a hidden item without leaving the page.
  await page.goto('/salvate/?vedere=ascunse');
  await expect(page.locator('[data-saved-list] li')).toHaveCount(2);
  await expect(page.locator('[data-saved-list] li').first()).toHaveAttribute('data-pref-id', '3001');  // newest hidden first
  await page.locator('[data-saved-list] li[data-pref-id="3001"] [data-pref="hide"]').click();
  await expect(page.locator('[data-saved-list] li')).toHaveCount(1);
  expect(Object.keys((await stored(page)).records)).not.toContain('https://posturi.gov.ro/anunt/fixture-3001');   // neither flag: record deleted
});

test('clear saved / restore hidden need confirmation and touch only their own state', async ({ page }) => {
  await seed(page, [rec(2001, { savedAt: at(1) }), rec(2002, { savedAt: at(2), hiddenAt: at(3) }), rec(2003, { hiddenAt: at(4) })]);
  await page.evaluate(() => { localStorage.setItem('pg.skin', 'govuk'); localStorage.setItem('posturi.facets', '{"open":["judet"]}'); });
  await page.goto('/salvate/');
  await expect(page.locator('[data-saved-list] li')).toHaveCount(2);
  const clear = page.locator('[data-clear="saved"]');
  await clear.click();
  await expect(page.locator('[data-clear-confirm]')).toBeVisible();
  await expect(page.locator('[data-clear-confirm]')).toContainText('Ștergi toate cele 2 anunțuri salvate');
  await expect(page.locator('[data-clear-no]')).toBeFocused();
  await page.keyboard.press('Escape');                                       // cancel by keyboard
  await expect(page.locator('[data-clear-confirm]')).toBeHidden();
  await expect(clear).toBeFocused();
  expect(Object.values((await stored(page)).records).filter((r) => r.savedAt).length).toBe(2);
  await clear.click();
  await page.locator('[data-clear-no]').click();
  expect(Object.values((await stored(page)).records).filter((r) => r.savedAt).length).toBe(2);
  await clear.click();
  await page.locator('[data-clear-yes]').click();
  await expect(page.locator('[data-saved-empty]')).toBeVisible();
  let recs = (await stored(page)).records;
  expect(Object.values(recs).filter((r) => r.savedAt).length).toBe(0);
  expect(Object.values(recs).filter((r) => r.hiddenAt).length).toBe(2);       // hidden untouched
  expect(await page.evaluate(() => [localStorage.getItem('pg.skin'), localStorage.getItem('posturi.facets')])).toEqual(['govuk', '{"open":["judet"]}']);

  await page.locator('[data-clear="hidden"]').click();
  await expect(page.locator('[data-clear-confirm]')).toContainText('Restabilești toate cele 2 anunțuri ascunse');
  await page.locator('[data-clear-yes]').click();
  expect(await storedRaw(page)).toBe('{"version":1,"records":{}}');
  expect(await page.evaluate(() => [localStorage.getItem('pg.skin'), localStorage.getItem('posturi.facets')])).toEqual(['govuk', '{"open":["judet"]}']);
});

test('500 distinct records: the limit never evicts a save and says what to do', async ({ page }) => {
  const recs = [];
  for (let i = 0; i < 499; i++) recs.push(rec(5000 + i, { savedAt: at(i) }));
  await seed(page, recs);
  await page.goto('/');
  await saveBtn(page, 1001).click();                                        // 500th: allowed
  await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');
  expect(Object.keys((await stored(page)).records).length).toBe(500);
  await hideBtn(page, 1002).click();                                        // 501st: refused
  await expect(page.locator('#pref-notice')).toContainText('limita de 500');
  await expect(page.locator('#pref-notice')).toContainText('Elimină câteva');
  await expect(row(page, 1002)).toBeVisible();
  const s = await stored(page);
  expect(Object.keys(s.records).length).toBe(500);
  expect(s.records['https://posturi.gov.ro/anunt/fixture-5000'].savedAt).toBeTruthy();   // nothing evicted
  // Changing an existing record is still possible at the limit.
  await hideBtn(page, 1001).click();
  await expect(row(page, 1001)).toBeHidden();
  expect(Object.keys((await stored(page)).records).length).toBe(500);
});

test.describe('storage failures', () => {
  test('unavailable storage: the session still works, and says it is not persisted', async ({ page }) => {
    await page.addInitScript(() => {
      Object.defineProperty(window, 'localStorage', { get() { throw new DOMException('denied', 'SecurityError'); } });
    });
    await page.goto('/');
    await expect(saveBtn(page, 1001)).toBeVisible();
    await saveBtn(page, 1001).click();
    await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('[data-pref-status]')).toBeVisible();
    await expect(page.locator('[data-pref-status]')).toContainText(SESSION_ONLY);
    await hideBtn(page, 1002).click();
    await expect(row(page, 1002)).toBeHidden();
    await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  });

  test('quota error and silently dropped writes are never reported as saved', async ({ page }) => {
    await page.addInitScript(() => {
      const set = Storage.prototype.setItem;
      Storage.prototype.setItem = function (k, v) {
        if (k === 'posturi.preferences.v1') throw new DOMException('full', 'QuotaExceededError');
        return set.call(this, k, v);
      };
    });
    await page.goto('/');
    await saveBtn(page, 1001).click();
    await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');           // in-memory session
    await expect(page.locator('[data-pref-status]')).toContainText(SESSION_ONLY);
    expect(await storedRaw(page)).toBeNull();
  });

  test('a write that does not stick is detected by read-back', async ({ page }) => {
    await page.addInitScript(() => {
      const set = Storage.prototype.setItem;
      Storage.prototype.setItem = function (k, v) { if (k !== 'posturi.preferences.v1') return set.call(this, k, v); };
    });
    await page.goto('/');
    await saveBtn(page, 1001).click();
    await expect(page.locator('[data-pref-status]')).toContainText(SESSION_ONLY);
  });

  test('corrupt JSON: message, no overwrite, explicit confirmed reset', async ({ page }) => {
    await seed(page, [], '{not json');
    await page.goto('/');
    await expect(page.locator('[data-pref-status]')).toContainText('nu pot fi citite');
    await saveBtn(page, 1001).click();
    await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');           // session only
    expect(await storedRaw(page)).toBe('{not json');                                      // never silently overwritten
    await page.reload();
    expect(await storedRaw(page)).toBe('{not json');
    await page.locator('[data-pref-reset="ask"]').click();
    expect(await storedRaw(page)).toBe('{not json');                                      // asking is not resetting
    await page.locator('[data-pref-reset="cancel"]').click();
    expect(await storedRaw(page)).toBe('{not json');
    await page.locator('[data-pref-reset="ask"]').click();
    await page.locator('[data-pref-reset="confirm"]').click();
    await expect(page.locator('[data-pref-status]')).toBeHidden();
    expect(await storedRaw(page)).toBe('{"version":1,"records":{}}');
    await saveBtn(page, 1001).click();
    expect(Object.keys((await stored(page)).records).length).toBe(1);
  });

  test('unsupported version and wrong shapes are left alone', async ({ page }) => {
    for (const raw of ['{"version":2,"records":{}}', '[1,2]', '{"records":{}}', '"x"', '{"version":1,"records":[]}']) {
      await seed(page, [], raw);
      await page.goto('/salvate/');
      await expect(page.locator('[data-pref-status]')).toContainText('nu pot fi citite');
      await expect(page.locator('[data-saved-empty]')).toBeVisible();
      expect(await storedRaw(page)).toBe(raw);
    }
  });

  test('invalid entries are ignored and preserved; valid ones keep working', async ({ page }) => {
    const good = rec(1001, { savedAt: at(1) });
    const raw = JSON.stringify({ version: 1, records: {
      [good.url]: good,
      'https://posturi.gov.ro/anunt/bad-id': { ...rec(1002), id: -4, savedAt: at(2) },
      'https://posturi.gov.ro/anunt/bad-date': { ...rec(1003), savedAt: 'ieri' },
      'https://posturi.gov.ro/anunt/key-mismatch': { ...rec(1004, { savedAt: at(3) }) },
      'x': 42,
    } });
    await seed(page, [], raw);
    await page.goto('/');
    await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');
    await expect(saveBtn(page, 1002)).toHaveAttribute('aria-pressed', 'false');
    await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
    await expect(page.locator('[data-pref-status]')).toContainText('4 intrări invalide');
    await saveBtn(page, 1005).click();
    const s = await stored(page);
    expect(Object.keys(s.records)).toEqual(expect.arrayContaining([
      good.url, 'https://posturi.gov.ro/anunt/bad-id', 'https://posturi.gov.ro/anunt/bad-date', 'x', 'https://posturi.gov.ro/anunt/fixture-1005']));
    expect(s.records.x).toBe(42);
  });
});

test.describe('resolving today’s data', () => {
  test('a failed lookup shows the cached snapshot with retry and clears nothing', async ({ page }) => {
    await seed(page, [rec(1001, { savedAt: at(1), title: 'Titlu salvat', path: '/job/1001-asistent-medical-generalist/' })]);
    await page.route('**/preferinte-posturi.json', (r) => r.abort());
    await page.goto('/salvate/');
    const item = page.locator('[data-saved-list] li');
    await expect(item).toContainText('Date salvate; actualizarea nu a reușit');
    await expect(item).toContainText('Titlu salvat');
    await expect(item).not.toContainText('Nu mai este disponibil');
    await expect(item.locator('[data-retry]')).toBeVisible();
    expect(Object.keys((await stored(page)).records).length).toBe(1);
    await page.unroute('**/preferinte-posturi.json');
    await item.locator('[data-retry]').click();
    await expect(page.locator('[data-saved-list] li')).toContainText('Înscrieri deschise');
    await expect(page.locator('[data-saved-list] li h2')).toContainText('Asistent medical');
    // HTTP errors and non-JSON are failures too, not "missing".
    await page.route('**/preferinte-posturi.json', (r) => r.fulfill({ status: 500, body: 'oops' }));
    await page.reload();
    await expect(page.locator('[data-saved-list] li')).toContainText('Date salvate; actualizarea nu a reușit');
  });

  test('identity is the source URL: refreshed slug/deadline, removed id, and an id reused by another source', async ({ page }, testInfo) => {
    await seed(page, [
      rec(1001, { savedAt: at(3), path: '/job/1001-vechi/', title: 'Titlu vechi', date: '2026-10-01', dateSource: 'concurs' }),
      rec(1002, { savedAt: at(2), url: 'https://posturi.gov.ro/anunt/alt-anunt', title: 'Alt anunț salvat' }),
      rec(9999, { savedAt: at(1), title: 'Anunț dispărut' }),
    ]);
    await page.goto('/salvate/');
    const a = page.locator('[data-saved-list] li[data-pref-id="1001"]');
    await expect(a.locator('h2 a')).toHaveAttribute('href', '/job/1001-asistent-medical-generalist/');
    await expect(a).toContainText('Termen: 16.10.2026');
    await expect.poll(async () => (await stored(page)).records['https://posturi.gov.ro/anunt/fixture-1001'].path).toBe('/job/1001-asistent-medical-generalist/');
    const s = (await stored(page)).records;
    expect(s['https://posturi.gov.ro/anunt/fixture-1001'].date).toBe('2026-10-16');
    expect(s['https://posturi.gov.ro/anunt/fixture-1001'].savedAt).toBe(at(3));       // flags kept

    for (const [id, title] of [[1002, 'Alt anunț salvat'], [9999, 'Anunț dispărut']]) {
      const li = page.locator(`[data-saved-list] li[data-pref-id="${id}"]`);
      await expect(li).toContainText('Nu mai este disponibil în baza curentă');
      await expect(li).toContainText('date salvate 01.10.2026');
      await expect(li).toContainText(title);
      await expect(li).not.toContainText(/expirat|anulat|închis/i);                    // never inferred
      await expect(li).not.toContainText('Referent');                                  // 1002's live title must not attach
      await expect(li.locator('[data-f="state"] a')).toHaveAttribute('href', new RegExp('^https://posturi\\.gov\\.ro/anunt/'));
    }
    // Unmatched snapshots are retained untouched.
    expect(s['https://posturi.gov.ro/anunt/alt-anunt'].title).toBe('Alt anunț salvat');
    expect(s['https://posturi.gov.ro/anunt/fixture-9999'].title).toBe('Anunț dispărut');
    await shot(page, testInfo, 'saved-unavailable');
    await noOverflow(page, 'saved with unavailable items');
  });

  test('hostile snapshot text and links cannot create markup or executable links', async ({ page }) => {
    await page.addInitScript(() => { window.__pwned = 0; });
    const evil = '<img src=x onerror="window.__pwned=1"><script>window.__pwned=2</script>';
    await seed(page, [
      rec(8001, { savedAt: at(3), url: 'javascript:alert(1)', path: 'javascript:alert(2)', title: evil, employer: evil, place: evil }),
      rec(8002, { savedAt: at(2), url: 'https://evil.example/anunt/1', path: '//evil.example/job/8002-x/', title: 'Sursă străină' }),
      rec(8003, { savedAt: at(1), url: 'https://posturi.gov.ro.evil.example/x', path: '/job/9-x/', title: 'Subdomeniu fals' }),
      rec(8004, { savedAt: at(0), url: 'https://user:pw@posturi.gov.ro/x', path: '/job/8004-x/../../admin', title: 'Credențiale' }),
    ]);
    await page.goto('/salvate/');
    const items = page.locator('[data-saved-list] li');
    await expect(items).toHaveCount(4);
    await expect(items.first()).toContainText('<img src=x onerror="window.__pwned=1">');   // literal text
    await expect(page.locator('[data-saved-list] img, [data-saved-list] script')).toHaveCount(0);
    await expect(page.locator('[data-saved-list] a[href^="javascript" i]')).toHaveCount(0);
    expect(await page.evaluate(() => window.__pwned)).toBe(0);
    const hrefs = await page.locator('[data-saved-list] a[href]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
    expect(hrefs).toEqual([]);                                                              // every invalid link is plain text
    await expect(items.nth(1)).toContainText('https://evil.example/anunt/1');
    // Removing a hostile record still works.
    await items.first().locator('[data-pref="save"]').click();
    await expect(items).toHaveCount(3);
  });
});

test('keyboard: buttons by Tab/Enter/Space, focus after hide, Escape, live announcements', async ({ page }) => {
  await page.goto('/');
  await page.locator('li[data-pref-id="1001"] h2 a').focus();
  let found = false;
  for (let i = 0; i < 8 && !found; i++) {
    await page.keyboard.press('Tab');
    found = await page.evaluate(() => document.activeElement?.matches('li[data-pref-id="1001"] [data-pref="save"]'));
  }
  expect(found, 'save button reachable by Tab from the title').toBe(true);
  await page.keyboard.press('Enter');
  await expect(saveBtn(page, 1001)).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('#pref-live')).toContainText('Anunț salvat');
  await page.keyboard.press('Tab');
  await expect(hideBtn(page, 1001)).toBeFocused();
  await page.keyboard.press('Space');
  await expect(row(page, 1001)).toBeHidden();
  await expect(page.locator('[data-notice-undo]')).toBeFocused();
  await expect(page.locator('#pref-live')).toContainText('Anunț ascuns');
  await page.keyboard.press('Enter');                                    // undo
  await expect(row(page, 1001)).toBeVisible();
  await expect(hideBtn(page, 1001)).toBeFocused();
  await hideBtn(page, 1001).press('Enter');
  await expect(page.locator('[data-notice-undo]')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.locator('#pref-notice')).toBeHidden();
  // Buttons are real buttons, and none sits inside the title link.
  expect(await page.locator('h2 button, a button').count()).toBe(0);
  expect(await page.locator('[data-pref]').evaluateAll((bs) => bs.every((b) => b.tagName === 'BUTTON' && b.type === 'button'))).toBe(true);
});

test('widths: no horizontal overflow on list, saved, detail, employer; nav count fits', async ({ page }, testInfo) => {
  const recs = [];
  for (let i = 0; i < 12; i++) recs.push(rec(2001 + i, { savedAt: at(i), title: 'Un titlu foarte lung de anunț care trebuie să se rupă corect pe ecrane înguste ' + i, employer: 'Administrația de Salubrizare Tunari și Primăria' }));
  recs.push(rec(1002, { hiddenAt: at(50) }));
  await seed(page, recs);
  for (const [url, name] of [['/', 'list'], ['/salvate/', 'saved'], ['/salvate/?vedere=ascunse', 'saved-hidden'],
    ['/job/1001-asistent-medical-generalist/', 'detail'], ['/angajator/spitalul-clinic-judetean-cluj/', 'employer']]) {
    await page.goto(url);
    await expect(page.locator('[data-saved-count]')).toHaveText('(12)');
    await noOverflow(page, name);
    await expect(page.locator('header nav a[href="/salvate/"]')).toBeVisible();
    await shot(page, testInfo, `layout-${name}`);
  }
  await page.goto('/');
  await expect(page.locator('[data-pref-banner-page]')).toContainText('1 anunț ascuns');
  await noOverflow(page, 'list with banner');
  await hideBtn(page, 1001).click();
  await noOverflow(page, 'list with notice');
  const box = await page.locator('#pref-notice').boundingBox();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(page.viewportSize().width);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });
  test('normal links stay, controls are absent, and the page says why', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('li[data-pref-id="1001"] h2 a')).toBeVisible();
    await expect(page.locator('[data-pref="save"]').first()).toBeHidden();
    await expect(page.locator('main, body').first()).toBeVisible();
    expect(await page.evaluate(() => document.body.innerText)).toContain('necesită JavaScript');
    await page.goto('/salvate/');
    expect(await page.evaluate(() => document.body.innerText)).toContain('poate fi citită doar cu JavaScript activat');
    await expect(page.locator('[data-saved-list] li')).toHaveCount(0);
    expect(await page.evaluate(() => document.body.innerText)).toContain(PERSIST);
  });
});
