// UX-15 browser checks: saved searches in the browser (ADR 0001). Key rule against
// tests/fixtures/search_keys.json, the list-page toggle and notice, the redirect
// on return, the /salvate/ "Căutări salvate" section, the navbar count, and a corrupt store. Runs at 320, 375
// and 1280 (playwright.config.js) against the fixture (tests/fixtures/build_db.php).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const KEY = 'posturi.searches.v1';
const PREFS = 'posturi.preferences.v1';
const TWO = '/?judet%5B%5D=ilfov&judet%5B%5D=cluj';
const TWO_KEY = 'judet%5B%5D=cluj&judet%5B%5D=ilfov';
const LIMIT_TEXT = 'Ai atins limita de 4 căutări salvate. Șterge una din Salvate, apoi încearcă din nou.';

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
  // No alert(), confirm() or prompt() anywhere: a dialog would fail the test.
  page.on('dialog', (d) => { errs.push('dialog: ' + d.message()); d.dismiss(); });
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

const toggle = (page) => page.locator('[data-search-toggle]');
const stored = (page) => page.evaluate((k) => JSON.parse(localStorage.getItem(k)), KEY);
const storedRaw = (page) => page.evaluate((k) => localStorage.getItem(k), KEY);

/** Put a raw value in storage; needs an origin, so visit a light page first. */
async function seedRaw(page, raw, key = KEY) {
  await page.goto('/despre/');
  await page.evaluate(([k, v]) => localStorage.setItem(k, v), [key, raw]);
}

const rec = (key, o = {}) => ({
  key, qs: key, sort: '', label: key, savedAt: '2026-10-01T10:00:00.000Z', openedAt: null, ...o,
});
async function seedSearches(page, recs, defaultId = null) {
  await seedRaw(page, JSON.stringify({
    version: 1, defaultId, searches: Object.fromEntries(recs.map((r) => [r.key, r])),
  }));
}

/**
 * The page as a whole overflows at 320 and 375 on the baseline (the footer skin
 * select; saved.spec.js "widths" fails there already), so check that this
 * feature's own elements fit the viewport instead.
 */
async function fits(page, selector, what) {
  const over = await page.evaluate((sel) => {
    const w = document.documentElement.clientWidth;
    return Array.from(document.querySelectorAll(sel)).filter((e) => e.getClientRects().length)
      .map((e) => Math.round(e.getBoundingClientRect().right - w)).filter((d) => d > 1);
  }, selector);
  expect(over, `overflow past the viewport: ${what}`).toEqual([]);
}

function shot(page, name) {
  const dir = process.env.UX15_SHOTS;
  if (!dir) return Promise.resolve();
  fs.mkdirSync(dir, { recursive: true });
  return page.screenshot({ path: path.join(dir, `${name}-${page.viewportSize().width}.png`), fullPage: true });
}

test('searchKey() matches the shared fixture', async ({ page }) => {
  const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '..', 'fixtures', 'search_keys.json'), 'utf8'));
  await page.goto('/');
  const got = await page.evaluate((cases) => cases.map((c) => window.PosturiSearches.searchKey(c.input)), fixture.cases);
  fixture.cases.forEach((c, i) => expect(got[i], c.name).toBe(c.key));
});

test('save two județe: pressed, kept on reload, follows HTMX filter changes', async ({ page }) => {
  await page.goto(TWO);
  await expect(toggle(page)).toBeVisible();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
  await expect(toggle(page)).toContainText('Salvează căutarea');
  await shot(page, 'toggle-off');
  await toggle(page).click();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
  await expect(toggle(page)).toContainText('Căutare salvată');
  await expect(page.locator('#pref-notice')).toContainText('Căutare salvată:');
  await expect(page.locator('[data-notice-link]')).toBeVisible();
  await expect(page.locator('[data-notice-link]')).toHaveAttribute('href', '/salvate/');
  await shot(page, 'toggle-on');

  const s = await stored(page);
  expect(Object.keys(s.searches)).toEqual([TWO_KEY]);
  expect(s.searches[TWO_KEY]).toMatchObject({ key: TWO_KEY, qs: TWO_KEY, sort: '', openedAt: null });
  expect(s.searches[TWO_KEY].label).toMatch(/Cluj/);
  expect(s.searches[TWO_KEY].label).toMatch(/Ilfov/);
  expect(s.searches[TWO_KEY].label.length).toBeLessThanOrEqual(80);
  expect(s.defaultId).toBeNull();

  await page.reload();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');

  // HTMX: another key, then back. The toggle is outside #results and still follows.
  await page.locator('input[name="q"]').first().fill('volum');
  await expect(page).toHaveURL(/q=volum/);
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
  await page.locator('[data-active-chips] [data-chip-param="q"]').first().click();
  await expect(page).not.toHaveURL(/q=volum/);
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');

  // History restore keeps it in step too.
  await page.goBack();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
  await page.goForward();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');

  // A second click removes it.
  await toggle(page).click();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
  expect(Object.keys((await stored(page)).searches)).toEqual([]);
  await fits(page, '[data-search-bar], [data-search-bar] *', 'toggle');
});

test('the toggle is hidden with no filters', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('#results')).toBeVisible();
  await expect(toggle(page)).toBeHidden();
  await page.goto('/?q=&status=active&page=1&sort=deadline');
  await expect(toggle(page)).toBeHidden();
});

test('the same search in another order, with page, sort and status, shows pressed', async ({ page }) => {
  await page.goto(TWO);
  await toggle(page).click();
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
  await page.goto('/?sort=deadline&status=active&judet[]=cluj&q=&judet[]=ilfov&page=1');
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
  await page.goto('/?judet=cluj');
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
});

test('a saved search keeps its sort', async ({ page }) => {
  await page.goto(TWO + '&sort=deadline');
  await toggle(page).click();
  expect((await stored(page)).searches[TWO_KEY].sort).toBe('deadline');
});

test('limit of 4: the 5th save is refused with #pref-notice and nothing is written', async ({ page }) => {
  const many = Array.from({ length: 4 }, (_, i) => rec(`q=k${i}`, { savedAt: `2026-10-01T10:00:0${i}.000Z` }));
  await seedSearches(page, many);
  await page.goto(TWO);
  await toggle(page).click();
  await expect(page.locator('#pref-notice')).toBeVisible();
  await expect(page.locator('#pref-notice [data-notice-text]')).toHaveText(LIMIT_TEXT);
  await expect(page.locator('[data-search-msg]')).toHaveText('');
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'false');
  expect(Object.keys((await stored(page)).searches)).toHaveLength(4);
  await expect(page.locator('[data-saved-count]')).toHaveText('(4)');
});

test('navbar count is saved jobs + saved searches, across save, delete and tabs', async ({ page, context }) => {
  await page.goto(TWO);
  await expect(page.locator('[data-saved-count]')).toHaveText('(0)');
  await toggle(page).click();
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  await page.locator('[data-pref="save"]').first().click();
  await expect(page.locator('[data-saved-count]')).toHaveText('(2)');

  // Another tab: a delete there updates this one through the storage event.
  const other = await context.newPage();
  other.on('pageerror', (e) => pageErrors.get(page).push(e.message));
  await other.goto('/salvate/');
  await expect(other.locator('[data-saved-count]')).toHaveText('(2)');
  await other.locator('[data-search-delete]').first().click();
  await expect(other.locator('[data-saved-count]')).toHaveText('(1)');
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  await other.close();
});

test('navbar count: a corrupt searches store counts only the saved jobs, never NaN', async ({ page }) => {
  await seedRaw(page, '{"version":1,"searches":[');
  await page.goto('/');
  await page.locator('[data-pref="save"]').first().click();
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
});

test('first visit to / in a session opens the default search; the second does not', async ({ page, context }) => {
  await seedSearches(page, [
    rec('q=vechi', { label: 'Veche', savedAt: '2026-09-01T10:00:00.000Z' }),
    rec(TWO_KEY, { label: 'Cluj · Ilfov', sort: 'deadline', savedAt: '2026-10-01T10:00:00.000Z' }),
  ]);
  // A new page has the same localStorage and an empty sessionStorage: a fresh session.
  const fresh = await context.newPage();
  fresh.on('pageerror', (e) => pageErrors.get(page).push(e.message));
  await fresh.goto('/');
  await expect(fresh).toHaveURL(new RegExp('\\?' + TWO_KEY.replace(/[%[\]]/g, '\\$&') + '&sort=deadline$'));
  const notice = fresh.locator('[data-search-notice]');
  await expect(notice).toBeVisible();
  await expect(notice).toContainText('Afișăm căutarea ta salvată «Cluj · Ilfov».');
  await expect(notice.locator('a')).toHaveText('Vezi toate posturile');
  await expect(fresh.locator('[data-search-toggle]')).toHaveAttribute('aria-pressed', 'true');
  await shot(fresh, 'notice');
  await fits(fresh, '[data-search-notice], [data-search-bar] *', 'notice and toggle');
  // opened-at is recorded.
  expect((await stored(fresh)).searches[TWO_KEY].openedAt).not.toBeNull();
  expect((await stored(fresh)).searches['q=vechi'].openedAt).toBeNull();

  // Same session, back to /: the home page stays.
  await fresh.goto('/');
  await expect(fresh).toHaveURL(/\/$/);
  await expect(fresh.locator('[data-search-notice]')).toBeHidden();
  await fresh.goto('/');
  await expect(fresh).toHaveURL(/\/$/);

  // The "Vezi toate posturile" link works the same way.
  const again = await context.newPage();
  again.on('pageerror', (e) => pageErrors.get(page).push(e.message));
  await again.goto('/');
  await expect(again.locator('[data-search-notice]')).toBeVisible();
  await again.locator('[data-search-notice] a').click();
  await expect(again).toHaveURL(/\/$/);

  // Changing a filter removes the notice.
  const third = await context.newPage();
  third.on('pageerror', (e) => pageErrors.get(page).push(e.message));
  await third.goto('/');
  await expect(third.locator('[data-search-notice]')).toBeVisible();
  await third.locator('input[name="q"]').first().fill('volum');
  await expect(third).toHaveURL(/q=volum/);
  await expect(third.locator('[data-search-notice]')).toBeHidden();
  expect(await third.evaluate(() => sessionStorage.getItem('posturi.searchApplied'))).toBeNull();
  await fresh.close(); await again.close(); await third.close();
});

test('a search saved later in the session does not move the reader', async ({ page }) => {
  await page.goto('/');                       // no saved search yet; the session flag is set
  await page.evaluate(() => window.PosturiSearches.save('q=nou', { label: 'Nou' }));
  await page.goto('/');
  await expect(page).toHaveURL(/\/$/);
});

test('defaultId wins over the newest search', async ({ page, context }) => {
  await seedSearches(page, [
    rec('q=vechi', { savedAt: '2026-09-01T10:00:00.000Z' }),
    rec('q=nou', { savedAt: '2026-10-01T10:00:00.000Z' }),
  ], 'q=vechi');
  const fresh = await context.newPage();
  await fresh.goto('/');
  await expect(fresh).toHaveURL(/\?q=vechi$/);
  await fresh.close();
});

test('/salvate/ Căutări salvate section: list, rename, open, delete, clear all', async ({ page }) => {
  await seedSearches(page, [
    rec('q=prima', { label: 'Prima', savedAt: '2026-09-01T10:00:00.000Z' }),
    rec(TWO_KEY, { label: 'Cluj · Ilfov', sort: 'deadline', savedAt: '2026-10-01T10:00:00.000Z' }),
    rec('q=treia', { label: 'A treia', savedAt: '2026-09-15T10:00:00.000Z' }),
  ]);
  await page.goto('/salvate/');
  await expect(page.locator('[data-count="searches"]')).toHaveText('(3)');
  await expect(page.locator('[data-view-tab="searches"]')).toHaveCount(0);
  await expect(page.locator('[data-saved-count]')).toHaveText('(3)');
  const rows = page.locator('[data-searches-list] > li');
  await expect(rows).toHaveCount(3);
  await expect(rows.nth(0).locator('[data-f="link"]')).toHaveText('Cluj · Ilfov');   // newest first
  await expect(rows.nth(0).locator('[data-f="default"]')).toBeVisible();
  await expect(rows.nth(1).locator('[data-f="default"]')).toBeHidden();
  await expect(rows.nth(0)).toContainText('salvată 1 octombrie 2026');
  await expect(rows.nth(0).locator('[data-f="link"]')).toHaveAttribute('href', `/?${TWO_KEY}&sort=deadline`);
  await expect(rows.nth(0).locator('[data-f="rss"]')).toHaveAttribute('href', `/posturi.rss?${TWO_KEY}`);
  await expect(page.locator('[data-saved-list] > li')).toHaveCount(0);
  await shot(page, 'searches-tab');
  await fits(page, '[data-view-tab], [data-searches-panel] *', 'searches section');

  // Rename: Esc cancels, Enter saves.
  await rows.nth(1).locator('[data-search-rename]').click();
  const input = rows.nth(1).locator('input');
  await expect(input).toBeFocused();
  await input.fill('Nu se salvează');
  await input.press('Escape');
  await expect(rows.nth(1).locator('[data-f="link"]')).toHaveText('A treia');
  await rows.nth(1).locator('[data-search-rename]').click();
  await rows.nth(1).locator('input').fill('Treia redenumită');
  await rows.nth(1).locator('input').press('Enter');
  await expect(rows.nth(1).locator('[data-f="link"]')).toHaveText('Treia redenumită');
  expect((await stored(page)).searches['q=treia'].label).toBe('Treia redenumită');
  await page.reload();
  await expect(rows.nth(1).locator('[data-f="link"]')).toHaveText('Treia redenumită');

  // Delete one.
  await rows.nth(2).locator('[data-search-delete]').click();
  await expect(rows).toHaveCount(2);
  await expect(page.locator('[data-count="searches"]')).toHaveText('(2)');
  expect(Object.keys((await stored(page)).searches).sort()).toEqual(['q=treia', TWO_KEY].sort());

  // Open: the link leads to the list with the search applied (toggle pressed).
  await rows.nth(0).locator('[data-f="link"]').click();
  await expect(page).toHaveURL(new RegExp('sort=deadline'));
  await expect(toggle(page)).toHaveAttribute('aria-pressed', 'true');
  expect((await stored(page)).searches[TWO_KEY].openedAt).not.toBeNull();

  // Clear all: an in-page confirmation, not a dialog.
  await page.goto('/salvate/');
  await page.locator('[data-clear-searches]').click();
  const box = page.locator('[data-searches-confirm]');
  await expect(box).toBeVisible();
  await box.locator('[data-searches-no]').click();
  await expect(box).toBeHidden();
  await expect(rows).toHaveCount(2);
  await page.locator('[data-clear-searches]').click();
  await box.locator('[data-searches-yes]').click();
  await expect(rows).toHaveCount(0);
  await expect(page.locator('[data-searches-empty-text]')).toHaveText('Nu ai căutări salvate. Filtrează lista și apasă «Salvează căutarea».');
  await expect(page.locator('[data-count="searches"]')).toHaveText('(0)');
  await shot(page, 'searches-empty');
});

test('/salvate/ is one page: Căutări salvate first, Anunțuri salvate after; ?vedere=cautari is the same', async ({ page }) => {
  await seedSearches(page, [rec('q=prima', { label: 'Prima' })]);
  for (const url of ['/salvate/', '/salvate/?vedere=cautari']) {
    await page.goto(url);
    const heads = page.locator('[data-saved-page] h2');
    await expect(heads.nth(0)).toContainText('Căutări salvate');
    await expect(heads.nth(1)).toContainText('Anunțuri salvate');
    await expect(page.locator('[data-view-tab="saved"]')).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('[data-searches-list] > li')).toHaveCount(1);
    await expect(page.locator('[data-saved-empty]')).toBeVisible();
    const a = await page.locator('[data-searches-panel]').boundingBox();
    const b = await page.locator('[data-saved-heading]').boundingBox();
    expect(a.y).toBeLessThan(b.y);
  }
  await expect(page.locator('[data-saved-page] p.bg-info')).toContainText('4 căutări și 20 de anunțuri');
  // Only the hidden view is separate; ?vedere=notite (old links) is the one page.
  await page.goto('/salvate/?vedere=ascunse');
  await expect(page.locator('[data-searches-panel]')).toBeHidden();
  await page.goto('/salvate/?vedere=notite');
  await expect(page.locator('[data-searches-panel]')).toBeVisible();
  await expect(page.locator('[data-view-tab="notes"]')).toHaveCount(0);
});

test('a corrupt store: no error, button hidden, value kept, saved jobs still work', async ({ page }) => {
  const CORRUPT = '{"version":1,"searches":[';
  await seedRaw(page, CORRUPT);
  await page.goto(TWO);
  await expect(page.locator('#results')).toBeVisible();
  await expect(toggle(page)).toBeHidden();
  await page.goto('/');
  await expect(page).toHaveURL(/\/$/);
  expect(await storedRaw(page)).toBe(CORRUPT);

  // The tab says so, and writes nothing.
  await page.goto('/salvate/');
  await expect(page.locator('[data-searches-empty-text]')).toHaveText('Căutările salvate nu pot fi citite din acest browser.');
  expect(await storedRaw(page)).toBe(CORRUPT);

  // Saved jobs are a different store and keep working.
  await page.goto('/');
  const save = page.locator('[data-pref="save"]').first();
  await save.click();
  await expect(save).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate((k) => JSON.parse(localStorage.getItem(k)).version, PREFS)).toBe(1);
  expect(await storedRaw(page)).toBe(CORRUPT);
});

test('another version in the store: kept as is, feature unavailable', async ({ page }) => {
  const V2 = JSON.stringify({ version: 2, searches: { x: 1 } });
  await seedRaw(page, V2);
  await page.goto(TWO);
  await expect(page.locator('#results')).toBeVisible();
  await expect(toggle(page)).toBeHidden();
  expect(await storedRaw(page)).toBe(V2);
});

test('invalid records are dropped on read and kept in storage', async ({ page }) => {
  await seedRaw(page, JSON.stringify({
    version: 1, defaultId: null,
    searches: { 'q=ok': rec('q=ok'), 'q=bad': { key: 'q=bad', qs: 'other', sort: '', label: 'x', savedAt: 'no' } },
  }));
  await page.goto('/salvate/');
  await expect(page.locator('[data-count="searches"]')).toHaveText('(1)');
  await page.goto(TWO);
  await toggle(page).click();
  const s = await stored(page);
  expect(Object.keys(s.searches).sort()).toEqual(['q=bad', 'q=ok', TWO_KEY].sort());
});
