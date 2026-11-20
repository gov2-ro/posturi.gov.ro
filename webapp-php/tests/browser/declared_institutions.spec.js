// SALARY-DECLARATII-PAGE (ADR 0008 rule 9): the index /salarii-declarate/ (search, county filter, paging), the page of one
// institution, and the section on the employer page. At 320, 375 and 1280 px: no horizontal scroll, and the stacked table
// shows a label above each value on a narrow screen. Runs on the declared fixture (port 8828, playwright.config.js).
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8828' });

const WIDTHS = [320, 375, 1280];

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

for (const width of WIDTHS) {
  test.describe(`at ${width}px`, () => {
    test.use({ viewport: { width, height: 800 } });

    test('the index: search, county filter, paging', async ({ page }) => {
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      await page.goto('/salarii-declarate/');
      await expect(page.locator('h1')).toHaveText('Salarii declarate');
      await expect(page.locator('[data-declared-item]')).toHaveCount(50);
      await expect(page.locator('[data-declared-count]')).toHaveText('62 de instituții');
      await noOverflow(page, 'index');
      // Search.
      await page.fill('#dc-q', 'gaiceana');
      await page.locator('[data-declared-form] button[type=submit]').click();
      await expect(page).toHaveURL(/\/salarii-declarate\/\?q=gaiceana/);
      await expect(page.locator('[data-declared-item]')).toHaveCount(1);
      await expect(page.locator('[data-declared-item]')).toContainText('Comuna Găiceana');
      // County filter.
      await page.goto('/salarii-declarate/');
      await page.selectOption('#dc-judet', 'cluj');
      await page.locator('[data-declared-form] button[type=submit]').click();
      await expect(page).toHaveURL(/judet=cluj/);
      await expect(page.locator('[data-declared-count]')).toHaveText('31 de instituții');
      // Empty search: one sentence and a link to clear.
      await page.goto('/salarii-declarate/?q=zzzzzz');
      await expect(page.locator('[data-declared-empty]')).toContainText('Nicio instituție nu corespunde căutării.');
      await page.locator('[data-declared-empty] a').click();
      await expect(page).toHaveURL(/\/salarii-declarate\/$/);
      // Paging.
      await page.locator('[data-declared-pages] a[rel=next]').click();
      await expect(page).toHaveURL(/pagina=2/);
      await expect(page.locator('[data-declared-item]')).toHaveCount(12);
      await noOverflow(page, 'index page 2');
      expect(errors).toEqual([]);
    });

    test('the page of one institution: scope line, stacked table, explainer', async ({ page }) => {
      await page.goto('/salarii-declarate/comuna-gaiceana-bacau/');
      await expect(page.locator('h1')).toHaveText('Comuna Găiceana');
      await expect(page.locator('[data-declared-scope]')).toContainText('Personalul contractual');
      await expect(page.locator('[data-declared-row]')).toHaveCount(3);
      await expect(page.locator('[data-declared-note]')).toContainText('Eroare tipică: 7 %.');
      await noOverflow(page, 'institution page');
      if (width < 720) {
        // Stacked: the header row is hidden, each cell shows its label above the value (no sideways scroll).
        const first = page.locator('[data-declared-row]').first().locator('td').nth(1);
        const content = await first.evaluate((el) => getComputedStyle(el, '::before').content);
        expect(content).toContain('Net pe lună, tipic');
        const wrap = await page.locator('.g2-table-wrap').first().evaluate((el) => el.scrollWidth - el.clientWidth);
        expect(wrap, 'the stacked table does not scroll sideways').toBeLessThanOrEqual(1);
      }
      const details = page.locator('[data-declared-how]');
      await expect(page.locator('[data-pay-explainer]')).toBeHidden();
      await details.locator('summary').click();
      await expect(page.locator('[data-explainer-step]')).toHaveCount(4);
      await noOverflow(page, 'institution page, explainer open');
      await expect(page.locator('[data-declared-employer-link]')).toHaveText('Posturi la acest angajator →');
      await page.locator('[data-declared-employer-link]').click();
      await expect(page).toHaveURL(/\/angajator\/primaria-comunei-gaiceana\/$/);
    });

    test('the employer page: the section and the link to the institution page', async ({ page }) => {
      await page.goto('/angajator/primaria-comunei-gaiceana/');
      const section = page.locator('[data-employer-declared]');
      await expect(section).toBeVisible();
      await expect(section.locator('[data-declared-row]')).toHaveCount(3);
      await noOverflow(page, 'employer page');
      await section.locator('[data-declared-page-link]').click();
      await expect(page).toHaveURL(/\/salarii-declarate\/comuna-gaiceana-bacau\/$/);
    });
  });
}

test('the 404 of an unknown institution', async ({ page }) => {
  const r = await page.goto('/salarii-declarate/nu-exista/');
  expect(r.status()).toBe(404);
});

test('the method section links to the index', async ({ page }) => {
  await page.goto('/estimator-salariu/');
  await page.locator('[data-declared-index-link]').click();
  await expect(page).toHaveURL(/\/salarii-declarate\/$/);
});
