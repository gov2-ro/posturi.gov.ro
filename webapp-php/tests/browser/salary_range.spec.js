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
  await expect(box.locator('[data-pay-range-note]')).toHaveText('Salariul crește cu vechimea în muncă: de la gradația 1 (3–5 ani) la gradația 5 (peste 20 de ani). Minimul vine din experiența cerută în anunț.');
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

// UX-SALARY-BOX: one fixed width, the explainer in plain words, no raw grid text, the salary line on the employer page.
test('the estimate box keeps its width when "Cum a fost calculat" opens; the panel has no grid text', async ({ page }) => {
  await page.goto('/job/1001/');
  const box = page.locator('[data-pay-estimate]');
  const before = (await box.boundingBox()).width;
  await page.locator('[data-pay-estimate] summary').click();
  const panel = page.locator('[data-pay-law-explainer]');
  await expect(panel).toBeVisible();
  expect((await box.boundingBox()).width).toBe(before);
  const text = await panel.innerText();
  expect(text).not.toMatch(/L153-|Selectorul|lege153-|Varianta/);
  expect(text).toContain('Titlul se potrivește cu 2 funcții din tabelul legii');
  await expect(panel).toHaveAttribute('data-pay-rows', /L153-/);
  await expect(panel.getByRole('link', { name: /Vezi-le în estimator/ })).toBeVisible();
  await expect(page.locator('[data-pay-disclaimer]')).toBeVisible();
  await noOverflow(page, 'detail, estimate box open');
});

test('the stated box has the same width as the estimate box', async ({ page }) => {
  await page.goto('/job/1003/');
  const w = (await page.locator('[data-pay-stated] > div').boundingBox()).width;
  await page.goto('/job/1001/');
  expect((await page.locator('[data-pay-estimate]').boundingBox()).width).toBe(w);
});

test('the employer page shows the salary line of its postings', async ({ page }) => {
  await page.goto('/angajator/administratia-de-salubrizare-tunari/');
  expect(await page.locator('.pg-salary').count()).toBeGreaterThan(0);
  await noOverflow(page, 'employer page');
});
