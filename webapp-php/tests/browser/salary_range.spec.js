// SALARY-GRAD-FOOD (ADR 0008 rule 11): the pay range on the detail page. Runs at 320, 375 and 1280 on the range
// fixture (port 8830, playwright.config.js): 1001 = an estimate with a range, 1003 = a stated pay with a range
// (the figures of posting 21359) and an extracted section "Salarizare" that the page must not repeat.
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8830' });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('a stated pay: net range, gross line with gradations and food, the note, no repeated "Salarizare"', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/job/1003/');
  const box = page.locator('[data-pay-stated]');
  await expect(box.locator('[data-pay-net]')).toContainText(/^de la [\d.]+ la [\d.]+ lei net/);
  await expect(box.locator('[data-pay-gross]')).toContainText('brut: 8.808–10.147 lei / lună, gradația 1–5, cu indemnizația de hrană de 347 lei');
  await expect(box.locator('[data-pay-gross]')).toContainText('În anunț: 7.870 lei.');
  await expect(box.locator('[data-pay-range-note]')).toHaveText('Gradația depinde de vechimea în muncă. Minimul vine din experiența cerută în anunț.');
  await expect(page.locator('h2', { hasText: /^\s*Salarizare/ })).toHaveCount(0);
  await expect(page.locator('body')).toContainText('Atribuțiile postului de test.');
  await noOverflow(page, 'detail, stated range');
  expect(errors).toEqual([]);
});

test('an estimate with a range replaces "la gradația 0" on its figures', async ({ page }) => {
  await page.goto('/job/1001/');
  await expect(page.locator('[data-pay-net]')).toContainText(/de la [\d.]+ la [\d.]+ lei net/);
  await expect(page.locator('[data-pay-gross]')).toContainText('brut: 5.000–6.200 lei / lună, gradația 1–5, cu indemnizația de hrană de 347 lei');
  await expect(page.locator('[data-pay-range-note]')).toHaveCount(1);
  await expect(page.locator('[data-pay-gross]')).not.toContainText('la gradația 0');
  await noOverflow(page, 'detail, estimate range');
});

test('the list row shows the range', async ({ page }) => {
  await page.goto('/?status=all');
  await expect(page.locator('.pg-salary[title*="brut: 8.808–10.147 lei"]')).toHaveCount(1);
  await noOverflow(page, 'list');
});
