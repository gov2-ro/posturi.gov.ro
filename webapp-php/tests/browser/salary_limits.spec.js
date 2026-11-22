// SALARY-LABELS-LIMITS: the draft figure is a last resort and says so; a law figure has the side note;
// /despre/#ce-nu-stim lists what the estimate cannot know. Runs at 320, 375 and 1280 on the limits fixture
// (port 8829, playwright.config.js; postings 1001 and 1003).
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8829' });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('a draft figure is labelled "estimare aproximativă" in the list and on the detail page', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/?status=all');
  const row = page.locator('.pg-salary', { has: page.locator('[data-pay-kind="estimare aproximativă"]') });
  await expect(row).toHaveCount(1);
  await expect(row.locator('.pg-salary-kind')).toHaveText('(estimare aproximativă)');
  await expect(row).toHaveAttribute('title', /Legea în vigoare nu are un rând pentru această funcție\. Cifra vine din proiectul noii legi a salarizării, care nu este adoptat\./);
  await noOverflow(page, 'list');
  await page.goto('/job/1003/');
  await expect(page.locator('section h2', { hasText: 'Estimare aproximativă' })).toBeVisible();
  await expect(page.locator('[data-pay-project-figure]')).toContainText('Cifra vine din proiectul noii legi a salarizării, care nu este adoptat.');
  await expect(page.locator('[data-pay-draft-note]')).toHaveCount(0);
  await noOverflow(page, 'detail, draft figure');
  expect(errors).toEqual([]);
});

test('a law figure has one muted side note with the draft figure, linked to the estimator', async ({ page }) => {
  await page.goto('/job/1001/');
  const note = page.locator('[data-pay-draft-note]');
  await expect(note).toHaveCount(1);
  await expect(note).toContainText('Dacă proiectul noii legi ar fi adoptat: ~');
  await expect(note).toContainText('lei net (brut');
  await expect(note.locator('a')).toHaveAttribute('href', '/estimator-salariu/');
  await expect(page.locator('[data-pay-project-figure]')).toHaveCount(0);
  await noOverflow(page, 'detail, law figure');
});

test('/despre/#ce-nu-stim: the limits, the gap and the coverage', async ({ page }) => {
  await page.goto('/despre/#ce-nu-stim');
  await expect(page.locator('#ce-nu-stim')).toHaveText('Ce nu putem ști');
  await expect(page.locator('#ce-nu-stim + p + ul > li')).toHaveCount(7);
  await expect(page.locator('[data-limits-gap]')).toHaveText('14');
  await expect(page.locator('[data-limits-coverage]')).toContainText('Din 1.822 anunțuri, 44 au salariul anunțat');
  await expect(page.locator('[data-limits-coverage]')).toContainText('346');
  await noOverflow(page, '/despre/');
});
