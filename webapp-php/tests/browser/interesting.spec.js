// UX-INTERESTING: "Posturi interesante" — the cards on the home page, the line to /anomalii/, the filter group
// and its chip. Runs at 320, 375 and 1280 on the interesting fixture (port 8832, playwright.config.js).
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8832' });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('home: the card of "multe-posturi", base font size, 44 px targets, the link to the anomalies', async ({ page }) => {
  await page.goto('/');
  const card = page.locator('[data-home-example]', { has: page.locator('a[href*="interes%5B%5D=multe-posturi"]') });
  await expect(card).toHaveCount(1);
  const link = card.locator('h3 a');
  await expect(link).toHaveText('Multe posturi la același anunț');
  // Every card link is at the base font size (16 px), the count is smaller.
  const sizes = await page.locator('[data-home-example] h3 a').evaluateAll((els) => els.map((el) => parseFloat(getComputedStyle(el).fontSize)));
  expect(sizes.length).toBeGreaterThan(3);
  sizes.forEach((s) => expect(s).toBe(16));
  const themeSize = await page.locator('[data-home-theme-example] h3 a').evaluateAll((els) => els.map((el) => parseFloat(getComputedStyle(el).fontSize)));
  themeSize.forEach((s) => expect(s).toBe(16));
  const count = await card.locator('[data-example-count]').evaluate((el) => parseFloat(getComputedStyle(el).fontSize));
  expect(count).toBeLessThan(16);
  // The title link keeps a 44 px target: inline, with padding.
  const h = await link.evaluate((el) => ({ display: getComputedStyle(el).display, h: Math.min(...Array.from(el.getClientRects()).map((r) => r.height)) }));
  expect(h.display).toBe('inline');
  expect(h.h).toBeGreaterThanOrEqual(38);
  // A value with fewer than 5 open postings has no card.
  await expect(page.locator('a[href*="interes%5B%5D=debutant-platit"]')).toHaveCount(0);
  // The line after the cards.
  const line = page.locator('[data-home-anomalies-link]');
  await expect(line).toContainText('Vezi și: anomalii și abateri statistice');
  const a = line.locator('a');
  await expect(a).toHaveAttribute('href', '/anomalii/');
  expect((await a.boundingBox()).height).toBeGreaterThanOrEqual(43.5);
  await noOverflow(page, 'home');
});

test('the card opens the list with the filter on, the chip and the group', async ({ page }) => {
  await page.goto('/');
  await page.locator('a[href*="interes%5B%5D=multe-posturi"]').first().click();
  await expect(page).toHaveURL(/interes/);
  const chip = page.locator('[data-chip-param="interes"][data-chip-value="multe-posturi"]');
  await expect(chip).toBeVisible();
  await expect(chip).toHaveAttribute('title', /posturi la același anunț/);
  const group = page.locator('details[data-facet="interes"]');
  await expect(group).toHaveAttribute('data-selected', '1');
  await expect(group.locator('input[value="multe-posturi"]')).toBeChecked();
  await noOverflow(page, 'list with the filter');
});

test('the filter group lists the values with counts; a click filters the list', async ({ page }) => {
  await page.goto('/posturi/');
  const group = page.locator('details[data-facet="interes"]');
  await expect(group).toHaveCount(1);
  // Below lg the groups are in a drawer.
  const toggle = page.locator('#filter-toggle');
  if (await toggle.isVisible()) await toggle.click();
  await group.locator('summary').click();
  const row = group.locator('label', { has: page.locator('input[value="multe-posturi"]') });
  await expect(row).toHaveAttribute('title', /posturi la același anunț/);
  const n = parseInt((await row.locator('span').last().textContent()).trim(), 10);
  expect(n).toBeGreaterThan(0);
  await row.locator('input').check();
  if (await toggle.isVisible()) await page.keyboard.press('Escape');
  await expect(page.locator('[data-chip-param="interes"]')).toBeVisible();
  await expect(page.locator('#results-status')).toContainText(String(n));
  await noOverflow(page, 'list');
});
