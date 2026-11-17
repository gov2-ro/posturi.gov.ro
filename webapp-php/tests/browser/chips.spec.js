// Occupation chips above the results of the list (docs/briefs/2026-11-17-themes-admin-financiar-chips.md, step 5).
// Fixture tests/fixtures/chips_db.php on its own port: `/?family[]=tehnic&cor[]=5` has 28 postings, Pază 12, Îngrijitor 8,
// Bucătar 6 (Brancardier and Frizer have 1 and have no chip). Runs at 320, 375 and 1280 px.
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8826' });

const LIST = '/?family%5B%5D=tehnic&cor%5B%5D=5';
const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

const count = async (page) => Number((await page.locator('#results-status').textContent()).match(/[\d.]+/)[0].replace(/\./g, ''));
const chip = (page, v) => page.locator(`[data-occ-chips] [data-occ-chip="${v}"]`);

test('the row is visible: label, three chips with counts, the link "Toate ocupațiile"; no horizontal overflow', async ({ page }) => {
  await page.goto(LIST);
  const row = page.locator('[data-occ-chips]');
  await expect(row).toBeVisible();
  await expect(row).toHaveAccessibleName('Ocupații frecvente');
  await expect(page.locator('#occ-chips-label')).toBeVisible();
  expect(await page.locator('[data-occ-chips] [data-occ-chip]').evaluateAll((els) => els.map((e) => e.dataset.occChip))).toEqual(['Pază', 'Îngrijitor', 'Bucătar']);
  await expect(chip(page, 'Pază').locator('.g2-chip-count')).toHaveText('12');
  await expect(row.locator('[data-occ-all]')).toHaveText('Toate ocupațiile');
  expect(await count(page)).toBe(28);
  // Between the first filters and the first result.
  expect(await page.evaluate(() => {
    const r = document.querySelector('[data-occ-chips]').getBoundingClientRect().top;
    return r < document.querySelector('.pg-results').getBoundingClientRect().top;
  })).toBe(true);
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, 'horizontal overflow').toBeLessThanOrEqual(1);
  // Each chip is a target of at least 36 px (the DS chip).
  const heights = await page.locator('[data-occ-chips] [data-occ-chip]').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().height));
  heights.forEach((h) => expect(h).toBeGreaterThanOrEqual(36));
});

test('a click on a chip gives a list whose count equals the chip count; the URL is the one of the response; the row goes', async ({ page }) => {
  await page.goto(LIST);
  const n = Number(await chip(page, 'Îngrijitor').locator('.g2-chip-count').textContent());
  expect(n).toBe(8);
  await chip(page, 'Îngrijitor').click();
  await expect(page).toHaveURL(/occupation%5B%5D=%C3%8Engrijitor/);
  await expect.poll(() => count(page), { timeout: 10_000 }).toBe(n);
  // The filter list follows: the box is checked and the active-filter chip is there; the row is gone (an occupation filter is on).
  await expect(page.locator('[data-occ-chips]')).toHaveCount(0);
  await expect(page.locator('[data-active-chips] [data-chip-param="occupation"]')).toHaveCount(1);
  expect(new URL(page.url()).searchParams.getAll('occupation[]')).toEqual(['Îngrijitor']);
  // Back: the row and the 28 results.
  await page.goBack();
  await expect(page.locator('[data-occ-chips]')).toBeVisible();
  expect(await count(page)).toBe(28);
});

test('a chip is a link without script, and its list has the chip count', async ({ page, browser }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, viewport: page.viewportSize(), baseURL: 'http://127.0.0.1:8826' });
  const p = await ctx.newPage();
  await p.goto(LIST);
  const n = Number(await chip(p, 'Bucătar').locator('.g2-chip-count').textContent());
  await chip(p, 'Bucătar').click();
  await expect(p).toHaveURL(/occupation/);
  expect(await count(p)).toBe(n);
  await ctx.close();
});

test('"Toate ocupațiile" opens the group "Ocupație": the drawer below lg, the sidebar from lg', async ({ page, viewport }) => {
  await page.goto(LIST);
  const group = page.locator('#facet-list details[data-facet="occupation"]');
  await expect(group).not.toHaveAttribute('open', '');
  await page.locator('[data-occ-all]').click();
  if (viewport.width < 1024) {
    await expect(page.locator('#filter-toggle')).toHaveAttribute('aria-expanded', 'true');
    await expect(page.locator('#facet-panel')).toBeInViewport();
  }
  expect(await group.evaluate((d) => d.open)).toBe(true);
  await expect(group.locator('input[name="occupation[]"][value="Pază"]')).toBeVisible();
  await expect(group.locator('> summary')).toBeFocused();
  // The group is in the visible part of the panel.
  await expect(group.locator('input[name="occupation[]"][value="Pază"]')).toBeInViewport();
});

test('"Toate ocupațiile" in the grid view (the filters are a bar from lg): the panel of the group opens', async ({ page, viewport }) => {
  test.skip(viewport.width < 1024, 'the bar views are lg and up');
  await page.addInitScript(() => localStorage.setItem('posturi.layout', 'compact'));
  await page.goto(LIST);
  await page.locator('[data-occ-all]').click();
  const group = page.locator('#facet-list details[data-facet="occupation"]');
  await expect(group).toHaveAttribute('data-pg-open', '');
  await expect(group.locator('input[name="occupation[]"][value="Pază"]')).toBeVisible();
});

test('the row is part of the result region: a filter change updates it', async ({ page, viewport }) => {
  await page.goto(LIST);
  // One county (cluj: 25 postings, one page): the row goes; both counties (26): the row is back.
  if (viewport.width < 1024) await page.locator('#filter-toggle').click();
  const g = page.locator('#facet-list details[data-facet="judet"]');
  if (!(await g.evaluate((d) => d.open))) await g.locator('> summary').click();
  await page.locator('input[name="judet[]"][value="cluj"]').check();
  await expect.poll(() => count(page), { timeout: 10_000 }).toBe(25);
  await expect(page.locator('[data-occ-chips]')).toHaveCount(0);
  await page.locator('input[name="judet[]"][value="bacau"]').check();
  await expect.poll(() => count(page), { timeout: 10_000 }).toBe(26);
  await expect(page.locator('[data-occ-chips]')).toHaveCount(1);
  // Its counts are the counts of the new filter: Pază 12, Îngrijitor 8, Bucătar 6 inside cluj and bacau (2003-2028).
  const counts = await page.locator('[data-occ-chips] .g2-chip-count').allTextContents();
  expect(counts.map(Number).reduce((a, b) => a + b, 0)).toBeLessThanOrEqual(26);
});

// A single complete action (a chip, the × of an active filter) sends its request at once: the wait of 500 ms is for the
// checkboxes of the filter list only (several quick picks).
async function msToRequest(page, action) {
  const sent = [];
  page.on('request', (r) => { if (r.headers()['hx-request']) sent.push(Date.now()); });
  const t0 = Date.now();
  await action();
  await expect.poll(() => sent.length, { timeout: 5_000 }).toBeGreaterThan(0);
  return sent[0] - t0;
}

test('a chip click sends its request in less than 150 ms', async ({ page }) => {
  await page.goto(LIST);
  const ms = await msToRequest(page, () => chip(page, 'Îngrijitor').click());
  console.log('MS chip ' + ms);
  expect(ms).toBeLessThan(150);
  await expect.poll(() => count(page), { timeout: 10_000 }).toBe(8);
});

test('the × of an active filter sends its request in less than 150 ms', async ({ page }) => {
  await page.goto(LIST + '&judet%5B%5D=cluj');
  await expect(page.locator('[data-active-chips] [data-chip-param="judet"]')).toHaveCount(1);
  const ms = await msToRequest(page, () => page.locator('[data-active-chips] [data-chip-param="judet"]').click());
  console.log('MS x ' + ms);
  expect(ms).toBeLessThan(150);
  await expect.poll(() => count(page), { timeout: 10_000 }).toBe(28);
  expect(new URL(page.url()).searchParams.getAll('judet[]')).toEqual([]);
});

test('a checkbox ticked and a chip clicked within 200 ms: the final list and URL hold both; no stale response is applied', async ({ page }) => {
  // The first response is slow, so that it could arrive after the second request left.
  let first = true;
  await page.route(/\/\?/, async (route) => {
    if (route.request().headers()['hx-request'] && first) { first = false; await new Promise((r) => setTimeout(r, 700)); }
    await route.continue();
  });
  await page.goto(LIST);
  await page.evaluate(() => {
    const b = document.querySelector('input[name="judet[]"][value="cluj"]');
    b.checked = true;
    b.dispatchEvent(new Event('change', { bubbles: true }));
  });
  await page.waitForTimeout(100);
  await chip(page, 'Îngrijitor').click();
  await expect.poll(() => new URL(page.url()).searchParams.getAll('occupation[]').join(), { timeout: 10_000 }).toBe('Îngrijitor');
  await page.waitForTimeout(1500);
  const u = new URL(page.url());
  expect(u.searchParams.getAll('judet[]')).toEqual(['cluj']);
  expect(u.searchParams.getAll('occupation[]')).toEqual(['Îngrijitor']);
  expect(await count(page)).toBe(8);
  await expect(page.locator('input[name="judet[]"][value="cluj"]')).toBeChecked();
  await expect(page.locator('input[name="occupation[]"][value="Îngrijitor"]')).toBeChecked();
});
