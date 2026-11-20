// SALARY-DECLARATII (ADR 0008 rule 9): a posting with `baza` declaratii. The list row says where the figure
// comes from; the detail page shows the median, the band and the gross line, no "la gradația 0", and the four
// steps of the explainer in the disclosure "Cum a fost calculat"; the method section has its anchor.
// Runs at 320, 375 and 1280 on the declared fixture (port 8828, playwright.config.js; posting 1004).
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8828' });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('the detail page of a declared figure: figure, label, four steps, method link', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/job/1004/');
  await expect(page.locator('[data-pay-net]')).toContainText('≈ 6.850 lei net');
  await expect(page.locator('[data-pay-gross]')).toHaveText('circa 11.700 lei brut');
  await expect(page.locator('[data-pay-band]')).toHaveText('Cei mai mulți primesc între 6.000 și 7.600 lei net.');
  await expect(page.locator('body')).not.toContainText('la gradația 0');
  // The explainer sits in the existing disclosure, closed at first.
  const details = page.locator('details', { has: page.locator('[data-pay-explainer]') });
  await expect(details.locator('summary')).toContainText('Cum a fost calculat');
  await expect(page.locator('[data-pay-explainer]')).toBeHidden();
  await details.locator('summary').click();
  const steps = page.locator('[data-explainer-step]');
  await expect(steps).toHaveCount(4);
  await expect(steps.nth(0)).toContainText('Ce au declarat.');
  await expect(steps.nth(0)).toContainText('37 de angajați cu funcția Consilier superior');
  await expect(steps.nth(1)).toContainText('a crescut cu 15% (INS)');
  await expect(steps.nth(2)).toContainText('Aproximativ 6.850 lei net pe lună.');
  await expect(steps.nth(3)).toContainText('Eroarea tipică a fost de 5%.');
  await noOverflow(page, 'detail page, explainer open');
  // The link goes to the method section.
  await page.locator('[data-pay-method-link]').click();
  await expect(page).toHaveURL(/\/estimator-salariu\/#metoda-declaratii$/);
  await expect(page.locator('#metoda-declaratii h2')).toContainText('Metoda pe larg');
  await noOverflow(page, 'method section');
  expect(errors).toEqual([]);
});

test('the list row says where the figure comes from', async ({ page }) => {
  await page.goto('/?status=all');
  const row = page.locator('.pg-salary', { has: page.locator('[data-pay-kind="după declarații de avere"]') });
  await expect(row).toHaveCount(1);
  await expect(row.locator('.pg-salary-value')).toContainText('lei net');
  await expect(row.locator('.pg-salary-kind')).toHaveText('(după declarații de avere)');
  await noOverflow(page, 'list');
});
