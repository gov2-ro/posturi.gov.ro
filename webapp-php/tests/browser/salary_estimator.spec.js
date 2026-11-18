// UX-SALARY-ESTIMATOR: /estimator-salariu/ — search, pick, change the gradation; the link from a detail page;
// no horizontal overflow; no page errors. Runs at 320, 375 and 1280 on the salary fixture (port 8825,
// playwright.config.js): the shared fixture plus three postings with a law estimate (tests/fixtures/make.php).
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8825' });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

const gross = async (page, block) => (await page.locator(`[data-salary-${block}] [data-pay-gross]`).textContent()) || '';

test('search → pick → change the gradation: the figures change', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/estimator-salariu/');
  await expect(page.locator('h1')).toHaveText('Estimator de salariu');
  await noOverflow(page, 'start');
  await page.fill('#se-q', 'consilier');
  await page.getByRole('button', { name: 'Caută funcția' }).click();
  await expect(page).toHaveURL(/q=consilier/);
  await expect(page.locator('[data-salary-step="1"]')).toBeVisible();
  await noOverflow(page, 'step 1');
  await page.locator('[data-salary-step="1"] a', { hasText: 'Consilier' }).first().click();
  await expect(page.locator('[data-salary-step="2"]')).toBeVisible();
  await expect(page.locator('[data-salary-law] h3')).toHaveText('Legea 153/2017 (în vigoare)');
  await expect(page.locator('[data-salary-draft] h3')).toHaveText('După noua lege (proiect, neadoptat)');
  // Choose the grade, the level and the gradation, then calculate.
  await page.selectOption('#se-nivel', 'central');
  await page.selectOption('#se-grad', 'superior');
  await page.selectOption('#se-g', '0');
  await page.getByRole('button', { name: 'Calculează' }).click();
  await expect(page.locator('[data-salary-law] [data-pay-gross]')).toContainText('6.580 lei');
  const net0 = await page.locator('[data-salary-law] [data-pay-net]').textContent();
  const draft0 = await gross(page, 'draft');
  await page.selectOption('#se-g', '3');
  await page.getByRole('button', { name: 'Calculează' }).click();
  await expect(page).toHaveURL(/g=3/);
  await expect(page.locator('[data-salary-law] [data-pay-gross]')).toContainText('7.799 lei');
  expect(await page.locator('[data-salary-law] [data-pay-net]').textContent()).not.toBe(net0);
  expect(await gross(page, 'draft')).not.toBe(draft0);
  await expect(page.locator('[data-salary-note]')).toContainText('Sporurile nu sunt incluse');
  await noOverflow(page, 'step 2');
  expect(errors).toEqual([]);
});

test('a local post shows a range up to the cap of the chosen locality', async ({ page }) => {
  await page.goto('/estimator-salariu/?rand=L153-t2%211&nivel=local');
  await expect(page.locator('[data-pay-local]')).toBeVisible();
  await expect(page.locator('[data-salary-law] [data-pay-gross]')).toContainText('5.641–13.283 lei');
  await page.selectOption('#se-cap', { label: 'comună, până la 3.000 de locuitori' });
  await page.getByRole('button', { name: 'Calculează' }).click();
  await expect(page.locator('[data-salary-law] [data-pay-gross]')).toContainText('5.641–7.970 lei');
  await noOverflow(page, 'local result');
});

test('the link on the detail page opens the right row', async ({ page }) => {
  await page.goto('/job/1001/');
  await page.getByRole('link', { name: 'Calculează cu altă gradație' }).click();
  await expect(page).toHaveURL(/estimator-salariu\/\?rand=L153-t3%211/);
  await expect(page.locator('[data-salary-law] [data-pay-gross]')).toContainText('5.474 lei');
  await expect(page.locator('#se-unit')).toHaveValue('a.1');
  await noOverflow(page, 'direct entry');
});

test('the tool is linked from the footer and the home page', async ({ page }) => {
  await page.goto('/');
  await expect(page.locator('footer a[href="/estimator-salariu/"]')).toBeVisible();
  await expect(page.locator('main a[href="/estimator-salariu/"]').first()).toBeVisible();
  await noOverflow(page, 'home');
});
