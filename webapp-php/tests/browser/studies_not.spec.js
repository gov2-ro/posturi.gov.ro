// DATA-STUDIES-NOT: "Fără licență" also removes the levels above it. Shared fixture (port 8823).
// The chip reads "Fără licență și peste"; the control of the level says the same; no horizontal overflow.
const { test, expect } = require('@playwright/test');
const fs = require('fs');

const SHOTS = process.env.STUDIES_NOT_SHOTS || '';   // a directory for screenshots; none by default
const LIST = '/?status=all&studies_level_not%5B%5D=licenta';

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

const count = async (page) => Number((await page.locator('#results-status').textContent()).match(/[\d.]+/)[0].replace(/\./g, ''));

test('the chip says "Fără licență și peste" and the list is shorter; no horizontal overflow', async ({ page }) => {
  await page.goto('/?status=all');
  const all = await count(page);
  await page.goto(LIST);
  const chip = page.locator('[data-active-chips] [data-chip-param="studies_level_not"]');
  await expect(chip).toHaveCount(1);
  await expect(chip).toContainText('Fără licență și peste');
  await expect(chip).not.toContainText('Fără studii');
  expect(await count(page)).toBeLessThan(all);
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, 'horizontal overflow').toBeLessThanOrEqual(1);
  if (SHOTS) {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/studies-not-${page.viewportSize().width}.png` });
  }
});

test('the control of the level has the words of the chip', async ({ page }) => {
  await page.goto(LIST);
  if (page.viewportSize().width < 1024) await page.locator('#filter-toggle').click();
  const group = page.locator('#facet-list details[data-facet="studies_level"]').first();
  if (!(await group.evaluate((d) => d.open))) await group.locator('> summary').click();
  const control = group.locator('label.facet-not:has(input[value="licenta"])');
  await expect(control).toHaveAttribute('title', 'Fără licență și peste');
  await expect(control.locator('input')).toBeChecked();
  if (SHOTS) {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/studies-not-control-${page.viewportSize().width}.png` });
  }
});

test('a click on the chip removes the exclusion', async ({ page }) => {
  await page.goto('/?status=all');
  const all = await count(page);
  await page.goto(LIST);
  await page.locator('[data-active-chips] [data-chip-param="studies_level_not"]').click();
  await expect.poll(() => count(page), { timeout: 10_000 }).toBe(all);
  expect(new URL(page.url()).searchParams.getAll('studies_level_not[]')).toEqual([]);
});
