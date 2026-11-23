// ANOMALY-PUBLIC (2026-11-24): /anomalii/ — the explainer, the five groups, the table of contents
// (sticky sidebar from 1024 px, a "Cuprins" block below), the quote block and no horizontal scroll.
// Runs at 320, 375 and 1280 on the anomalies fixture (port 8831, playwright.config.js).
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8831' });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('/anomalii/: groups, caveat, quote with a marked match, no overflow', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/anomalii/');
  await expect(page.locator('h1')).toHaveText('Anomalii și abateri');
  await expect(page.locator('#explicatie')).toContainText('statistici din date publice, nu acuzații');
  const heads = page.locator('main h2');
  await expect(heads).toHaveText(['Posibile erori în datele noastre', 'Posibile erori în anunț', 'Anunțuri neobișnuite', 'Tipare la angajatori', 'Context']);
  await expect(page.locator('#check-R25')).toContainText('AVERTISMENT-R25');
  const quote = page.locator('#check-R25 blockquote.pg-quote');
  await expect(quote.locator('mark')).toHaveText('sex masculin');
  await expect(quote).toContainText('din textul anunțului');
  await expect(page.locator('#check-R09a blockquote mark')).toHaveText('2026-01-01 10:00');
  await expect(page.locator('body')).not.toContainText('99');
  await noOverflow(page, '/anomalii/');
  expect(errors).toEqual([]);
});

test('the table of contents: a sticky sidebar from 1024 px, a Cuprins block below', async ({ page }, info) => {
  await page.goto('/anomalii/');
  const wide = (page.viewportSize()?.width ?? 0) >= 1024;
  const side = page.locator('nav.pg-atoc-side');
  const block = page.locator('details.pg-atoc-small');
  if (wide) {
    await expect(side).toBeVisible();
    await expect(block).toBeHidden();
    await expect(side.getByRole('link', { name: /Eticheta GRUP-NEOBISNUIT/ })).toContainText('9');
    // Sticky: the sidebar stays in view after a scroll.
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight / 2));
    await expect.poll(async () => (await side.boundingBox()).y).toBeLessThan(40);
    expect((await side.boundingBox()).y).toBeGreaterThanOrEqual(0);
    // The section in view is marked.
    await side.getByRole('link', { name: /Eticheta GRUP-NEOBISNUIT/ }).click();
    await expect(side.locator('a[aria-current="true"]').first()).toBeVisible();
  } else {
    await expect(side).toBeHidden();
    await expect(block).toBeVisible();
    await expect(block.locator('summary')).toHaveText('Cuprins');
    await expect(block).not.toHaveAttribute('open', '');
    await block.locator('summary').click();
    await expect(block.getByRole('link', { name: /Eticheta GRUP-CONTEXT/ })).toBeVisible();
    await noOverflow(page, 'Cuprins open');
  }
});

test('the page of one check and the redirect from the old URL', async ({ page }) => {
  await page.goto('/statistici/anomalii/');
  await expect(page).toHaveURL(/\/anomalii\/$/);
  await page.goto('/anomalii/R14/');
  await expect(page.locator('h1')).toHaveText('Anomalii și abateri');
  await expect(page.locator('#check-R14')).toContainText('Eticheta GRUP-NEOBISNUIT');
  await expect(page.locator('#check-R14 li.pg-signal')).toHaveCount(9);
  await noOverflow(page, '/anomalii/R14/');
});
