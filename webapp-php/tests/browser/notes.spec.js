// Reader notes (2026-10-17): a note per posting, kept in the browser beside the
// save/hide state (static/saved.js). A noted posting counts as saved, /salvate/ has a
// Notițe view, and saved + noted postings export as CSV. Runs at 320, 375 and 1280.
const { test, expect } = require('@playwright/test');
const fs = require('fs');

const KEY = 'posturi.preferences.v1';
const stored = (page) => page.evaluate((k) => JSON.parse(localStorage.getItem(k) || 'null'), KEY);
const row = (page, id) => page.locator(`li[data-pref-id="${id}"], div[data-pref-id="${id}"]`).first();
const noteBtn = (page, id) => row(page, id).locator('[data-pref="note"]');

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

test('a note from the list: editor, save, shown under the row, counted as saved', async ({ page }) => {
  await page.goto('/');
  const btn = noteBtn(page, 1001);
  await expect(btn).toBeVisible();
  await expect(btn).toHaveAttribute('aria-expanded', 'false');
  await btn.click();
  const ta = row(page, 1001).locator('[data-note-editor] textarea');
  await expect(ta).toBeFocused();
  await expect(btn).toHaveAttribute('aria-expanded', 'true');
  // Rows live inside the HTMX filter form: typing a note must not re-query the list.
  const queries = [];
  page.on('request', (r) => { if (r.url().includes('org.htmx.cache-buster')) queries.push(r.url()); });
  await ta.fill('Termenul din listă e greșit: în anunț scrie 20.10.\nVerifică și bibliografia, "Legea 53".');
  await ta.blur();
  await page.waitForTimeout(600);
  expect(queries, 'no results request while typing').toEqual([]);
  await ta.focus();
  await row(page, 1001).locator('[data-note-save]').click();
  await expect(row(page, 1001).locator('[data-note-editor]')).toHaveCount(0);
  await expect(btn).toBeFocused();
  await expect(row(page, 1001).locator('[data-note-view]')).toContainText('Termenul din listă e greșit');
  await expect(page.locator('#pref-live')).toContainText('Notiță salvată');
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  // The bookmark is untouched: the note alone puts the posting in Salvate.
  await expect(row(page, 1001).locator('[data-pref="save"]')).toHaveAttribute('aria-pressed', 'false');
  const r = Object.values((await stored(page)).records)[0];
  expect(r.id).toBe(1001);
  expect(r.note).toContain('"Legea 53"');
  expect(r.savedAt).toBeNull();
  expect(Date.parse(r.notedAt)).toBeGreaterThan(0);
  await noOverflow(page, 'list with a note');
  // Survives a reload and an HTMX filter change.
  await page.reload();
  await expect(row(page, 1001).locator('[data-note-view]')).toContainText('Legea 53');
});

test('Escape closes without saving; Ctrl+Enter saves; an emptied note is removed', async ({ page }) => {
  await page.goto('/job/1002-referent-debutant/');
  const btn = noteBtn(page, 1002);
  await btn.click();
  const ta = row(page, 1002).locator('[data-note-editor] textarea');
  await ta.fill('nu se salvează');
  await page.keyboard.press('Escape');
  await expect(row(page, 1002).locator('[data-note-editor]')).toHaveCount(0);
  await expect(btn).toBeFocused();
  expect(await stored(page)).toBeNull();

  await btn.click();
  await row(page, 1002).locator('[data-note-editor] textarea').fill('Cerința de studii lipsește din extragere.');
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+Enter' : 'Control+Enter');
  await expect(row(page, 1002).locator('[data-note-view]')).toContainText('Cerința de studii lipsește');

  await btn.click();
  await expect(row(page, 1002).locator('[data-note-editor] textarea')).toHaveValue('Cerința de studii lipsește din extragere.');
  await row(page, 1002).locator('[data-note-editor] textarea').fill('   ');
  await row(page, 1002).locator('[data-note-save]').click();
  await expect(row(page, 1002).locator('[data-note-view]')).toHaveCount(0);
  expect(Object.keys((await stored(page)).records)).toEqual([]);   // no flag, no note: dropped
  await noOverflow(page, 'detail page');
});

test('/salvate/: noted postings listed, a Notițe view, edit in place, CSV export', async ({ page }) => {
  await page.goto('/');
  // One bookmarked, one noted only.
  await row(page, 1006).locator('[data-pref="save"]').click();
  await noteBtn(page, 1001).click();
  await row(page, 1001).locator('[data-note-editor] textarea').fill('Salariu: 4.100, nu 5.200; vezi anexa, rândul 3.');
  await row(page, 1001).locator('[data-note-save]').click();

  await page.goto('/salvate/');
  await expect(page.locator('[data-saved-list] li')).toHaveCount(2);
  await expect(page.locator('[data-count="notes"]')).toHaveText('(1)');
  const noted = page.locator('[data-saved-list] li[data-pref-id="1001"]');
  await expect(noted.locator('[data-f="user-note"]')).toContainText('vezi anexa');
  await page.locator('[data-view-tab="notes"]').click();
  await expect(page).toHaveURL(/vedere=notite/);
  await expect(page.locator('[data-saved-list] li')).toHaveCount(1);

  // Edit in place on /salvate/.
  await noted.locator('[data-pref="note"]').click();
  await noted.locator('[data-note-editor] textarea').fill('Salariu greșit (anexa, rândul 3).');
  await noted.locator('[data-note-save]').click();
  await expect(page.locator('[data-saved-list] li[data-pref-id="1001"] [data-f="user-note"]')).toContainText('rândul 3');

  // CSV: both saved and noted, UTF-8 with a BOM, quoted where needed.
  await page.goto('/salvate/');
  const [dl] = await Promise.all([page.waitForEvent('download'), page.locator('[data-export-csv]').click()]);
  expect(dl.suggestedFilename()).toMatch(/^posturi-salvate-\d{8}\.csv$/);
  const csv = fs.readFileSync(await dl.path(), 'utf8');
  expect(csv.charCodeAt(0)).toBe(0xfeff);
  const lines = csv.slice(1).trim().split('\r\n');
  expect(lines[0]).toBe('id,titlu,angajator,localitate,termen,stare,link,link_oficial,salvat,notita,notita_la,ascuns');
  expect(lines).toHaveLength(3);
  const l1001 = lines.find((l) => l.startsWith('1001,'));
  expect(l1001).toContain('"Salariu greșit (anexa, rândul 3)."');
  expect(l1001).toContain('/job/1001-');
  expect(l1001).toContain('https://posturi.gov.ro/anunt/fixture-1001');
  expect(lines.find((l) => l.startsWith('1006,'))).toBeTruthy();
  await noOverflow(page, 'salvate');
});

test('table view: the note and its editor span the row', async ({ page, viewport }) => {
  test.skip(viewport.width < 1024, 'the table is lg+ only');
  await page.addInitScript(() => localStorage.setItem('posturi.layout', 'table'));
  await page.goto('/');
  await noteBtn(page, 1001).click();
  const ed = row(page, 1001).locator('[data-note-editor]');
  const [eb, rb] = [await ed.boundingBox(), await row(page, 1001).boundingBox()];
  expect(eb.width).toBeGreaterThan(rb.width * 0.8);
  await noOverflow(page, 'table with an editor');
});
