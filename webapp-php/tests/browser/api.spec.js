// API-01: the docs page /api/ renders at the three widths of playwright.config.js (320, 375 and 1280 px) with
// no sideways scroll of the page, a heading, the parameter table and the field table, and a footer link to it.
// The API itself is tested in tests/api_test.php.
const { test, expect } = require('@playwright/test');

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

test('/api/ renders with no sideways scroll; its tables scroll inside their box; the footer links to it', async ({ page }) => {
  await page.goto('/api/');
  await expect(page.getByRole('heading', { name: 'API public', level: 1 })).toBeVisible();
  const overflow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(overflow, 'horizontal overflow of the page').toBeLessThanOrEqual(1);
  await expect(page.locator('tr[data-param="judet"]')).toHaveCount(1);
  await expect(page.locator('tr[data-field="salary"]')).toHaveCount(1);
  // A code block never widens the page: it scrolls in its own box.
  const wide = await page.locator('pre').evaluateAll((els) => els.filter((e) => e.getBoundingClientRect().right > document.documentElement.clientWidth + 1).length);
  expect(wide, 'code blocks wider than the viewport').toBe(0);
  await expect(page.locator('footer a[href="/api/"]')).toHaveText('API');
});
