// FIX-10 browser checks for /statistici: the "Costuri inferență" section reads the
// ledger-shaped llm_costs rows of the fixture (tests/fixtures/build_db.php): one legacy
// day (02.10, 3 postings, $0.01) and one ledger day (04.10: schema 5 calls $0.02 over
// 4 postings, one unpriced; occupations 2 calls $0.002). Runs at 320, 375 and 1280.
const { test, expect } = require('@playwright/test');

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

const costs = (page) => page.locator('section', { has: page.getByRole('heading', { name: 'Costuri inferență' }) });
const card = (section, label) => section.locator('.grid > div').filter({ has: section.page().getByText(label, { exact: true }) });

test('/statistici: inference costs are measured per step, legacy days marked as estimates', async ({ page }) => {
  const res = await page.goto('/statistici/');
  expect(res.status()).toBe(200);

  const overflow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(overflow, 'horizontal overflow on /statistici/').toBeLessThanOrEqual(1);

  const s = costs(page);
  await expect(s).toBeVisible();

  // $0.01 legacy + $0.02 schema + $0.002 occupations.
  await expect(card(s, 'Total')).toContainText('$0.032');
  // Schema spend only, over valid extractions (a re-extraction counts again): 100 × $0.03 / 7.
  const per100 = card(s, 'La 100 de extrageri');
  await expect(per100).toBeVisible();
  await expect(per100).toContainText('$0.429');
  await expect(per100).toContainText('7 extrageri; un anunț reextras se numără din nou');
  await expect(s).not.toContainText('procesate');

  const steps = s.locator('ul > li');
  await expect(steps).toHaveCount(2);
  await expect(steps.nth(0)).toContainText('Extracția structurată · 5 apeluri');
  await expect(steps.nth(0)).toContainText('$0.020');
  await expect(steps.nth(1)).toContainText('Normalizarea ocupațiilor · 2 apeluri');
  await expect(steps.nth(1)).toContainText('$0.002');

  await expect(s.getByText('04.10.2026 · 7 apeluri', { exact: true })).toBeVisible();
  await expect(s.getByText('02.10.2026 · 3 anunțuri (estimare)', { exact: true })).toBeVisible();

  await expect(s).toContainText('Apeluri fără cost cunoscut, neincluse în sume: 1.');
  await expect(s).not.toContainText('Estimare incompletă');
});
