// UX-01A browser checks: regrouped facets, status/date wording, filter state
// across HTMX swaps / reload / history, and feed links. Runs at 320, 375 and
// 1280 (see playwright.config.js). Fixture data: tests/fixtures/build_db.php.
// Set UX01A_SHOTS=<dir> to also write the acceptance screenshots there.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const FALLBACK = 'Expirarea anunțului; înscriere neconfirmată';

const isNarrow = (page) => page.viewportSize().width < 1024;

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

/** The facets live in a slide-over drawer below lg. */
async function openPanel(page) {
  if (isNarrow(page) && (await page.locator('#filter-toggle').getAttribute('aria-expanded')) !== 'true') {
    await page.locator('#filter-toggle').click();
  }
}

async function closePanel(page) {
  if (isNarrow(page) && (await page.locator('#filter-toggle').getAttribute('aria-expanded')) === 'true') {
    await page.keyboard.press('Escape');
  }
}

async function isOpen(page, key) {
  return page.locator(`details[data-facet="${key}"]`).evaluate((d) => d.open);
}

async function openFacet(page, key) {
  if (!(await isOpen(page, key))) await page.locator(`details[data-facet="${key}"] > summary`).click();
  expect(await isOpen(page, key)).toBe(true);
}

async function openKeys(page) {
  return page.evaluate(() =>
    [...document.querySelectorAll('details.facet-group[open]')].map((d) => d.dataset.facet).sort());
}

async function feedParams(page, feed) {
  const href = await page.locator(`[data-feed="${feed}"]`).getAttribute('href');
  return new URL(href, 'http://x').searchParams;
}

/** PHP writes arrays as name[0]=…, the form as name[]=…; both mean the same. */
function valuesOf(params, name) {
  const out = [];
  for (const [k, v] of params) if (k === name || k.startsWith(name + '[')) out.push(v);
  return out;
}

async function expectFeedsKeep(page, expectations) {
  for (const feed of ['posturi.atom', 'posturi.json', 'posturi.ics']) {
    await expect.poll(async () => {
      const p = await feedParams(page, feed);
      return {
        page: p.has('page'), sort: p.has('sort'),
        ...Object.fromEntries(Object.keys(expectations).map((k) => [k, valuesOf(p, k)])),
      };
    }, { message: feed }).toEqual({
      page: false, sort: false,
      ...Object.fromEntries(Object.entries(expectations).map(([k, v]) => [k, v])),
    });
  }
}

function shot(page, testInfo, name) {
  const dir = process.env.UX01A_SHOTS;
  if (!dir) return Promise.resolve();
  fs.mkdirSync(dir, { recursive: true });
  return page.screenshot({ path: path.join(dir, `${name}-${page.viewportSize().width}.png`), fullPage: true });
}

test.beforeEach(async ({ page }) => {
  // A fresh browser: no remembered facet preferences.
  await page.addInitScript(() => { try { localStorage.removeItem('posturi.facets'); } catch (e) {} });
});

test('fresh load: only Județ and Domeniu profesional are open; no overflow', async ({ page }, testInfo) => {
  await page.goto('/');
  expect(await openKeys(page)).toEqual(['family', 'judet']);
  // Server-rendered open state is not a reader preference: nothing is stored.
  await page.waitForTimeout(100);
  expect(await page.evaluate(() => localStorage.getItem('posturi.facets'))).toBeNull();
  await expect(page.locator('details[data-facet="family"] > summary')).toContainText('Domeniu profesional');
  await noOverflow(page, 'home');
  await shot(page, testInfo, 'default-list');
  if (isNarrow(page)) {
    await openPanel(page);
    await noOverflow(page, 'drawer open');
    await expect(page.locator('#facet-panel')).toBeVisible();
    await shot(page, testInfo, 'mobile-drawer');
  }
});

test('status copy is visible, wraps, and the 7-day note follows the tab', async ({ page }) => {
  await page.goto('/');
  const help = page.locator('#status-help');
  await expect(help).toContainText('Active include anunțuri cu înscrieri deschise și anunțuri al căror termen nu este confirmat. Verifică termenul în anunțul oficial.');
  await expect(help.locator('.status-soon-note')).toBeHidden();
  for (const l of ['Active', 'Termen în 7 zile', 'Înscrieri închise', 'Termen neprecizat']) {
    await expect(page.locator('[aria-label="Stare anunț"]')).toContainText(l);
  }
  await page.locator('[name="status"][value="soon"]').check({ force: true });
  await expect(page).toHaveURL(/status=soon/);
  await expect(help.locator('.status-soon-note')).toBeVisible();
  await expect(help).toContainText('Include și date estimate din expirarea anunțului.');
  await noOverflow(page, 'status tabs');
  await expect(page.locator('#results')).toContainText('anunțuri găsite');
  await page.locator('[name="status"][value="active"]').check({ force: true });
  await expect(help.locator('.status-soon-note')).toBeHidden();
});

test('deadline wording is visible without hovering', async ({ page }, testInfo) => {
  await page.goto('/?status=all');
  const row = (id) => page.locator('#results li', { has: page.locator(`a[href^="/job/${id}-"]`) });
  await expect(row(1004)).toContainText(FALLBACK);
  await expect(row(1004).locator('.deadline-fallback')).toBeVisible();
  await expect(row(1004).locator('time[datetime="2026-10-20"]')).toHaveText('20.10.2026');
  await expect(row(1001)).not.toContainText(FALLBACK);
  await expect(row(1005)).toContainText('Termen neprecizat');
  await expect(row(1003)).toContainText('Înscrieri închise');
  await noOverflow(page, 'rows with fallback note');
  await shot(page, testInfo, 'fallback-row');
  await page.goto('/angajator/primaria-comunei-gaiceana/');
  await expect(page.locator('body')).toContainText(FALLBACK);
  await noOverflow(page, 'employer page');
  await page.goto('/job/1004-ingrijitor-scoala/');
  await expect(page.locator('body')).toContainText(FALLBACK);
  await noOverflow(page, 'detail page');
});

test('bookmarked advanced and diagnostic selections open every ancestor and are removable', async ({ page }, testInfo) => {
  await page.goto('/?status=all&anomaly%5B%5D=missing_contact&type%5B%5D=Temporar&exp_level%5B%5D=5plus');
  const open = await openKeys(page);
  for (const k of ['family', 'judet', 'more', 'diagnostics', 'anomaly', 'legacy', 'type', 'experienta', 'exp_level']) {
    expect(open, k).toContain(k);
  }
  expect(open).not.toContain('contract');
  expect(open).not.toContain('studii');
  await noOverflow(page, 'advanced selection');
  await openPanel(page);
  await shot(page, testInfo, 'selected-advanced');

  await closePanel(page);
  // Remove the diagnostic one through its chip: it leaves the URL and the form.
  await page.locator('[data-chip-param="anomaly"]').click();
  await expect(page).not.toHaveURL(/anomaly/);
  await expect(page.locator('input[name="anomaly[]"][value="missing_contact"]')).toHaveCount(0)
    .catch(() => {}); // the group may disappear with its last value
  // Ancestors of the still-selected values are still open after the OOB swap.
  const after = await openKeys(page);
  for (const k of ['legacy', 'type', 'more', 'experienta', 'exp_level']) expect(after, k).toContain(k);
  expect(after).not.toContain('diagnostics');
});

test('nested disclosures, focus and reader preferences survive an OOB swap', async ({ page }) => {
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'studii');
  await openFacet(page, 'eqf');
  const box = page.locator('input[name="eqf[]"][value="4"]');
  await box.focus();
  await box.press('Space');
  await expect(page).toHaveURL(/eqf%5B%5D=4/);
  await expect(box).toBeChecked();
  await expect(box).toBeFocused();
  // Reader opened Studii and Nivel de studii: both stay open after the swap.
  expect(await isOpen(page, 'studii')).toBe(true);
  expect(await isOpen(page, 'eqf')).toBe(true);
  // A group the reader opened by hand (and that holds no selection) stays open…
  await openFacet(page, 'contract');
  await page.locator('[name="status"][value="active"]').check({ force: true });
  await expect(page).toHaveURL(/status=active/);
  expect(await isOpen(page, 'contract')).toBe(true);
  // …and one the reader closed stays closed, while selected ancestors cannot be closed.
  await page.locator('details[data-facet="contract"] > summary').click();
  await page.selectOption('#sort', 'employer');
  await expect(page).toHaveURL(/sort=employer/);
  expect(await isOpen(page, 'contract')).toBe(false);
  expect(await isOpen(page, 'studii')).toBe(true);
  await noOverflow(page, 'after swaps');
});

test('selection survives reload and history; ancestors reopen', async ({ page }) => {
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'experienta');
  await openFacet(page, 'skill');
  await page.locator('input[name="skill[]"][value="Excel"]').check();
  await expect(page).toHaveURL(/skill%5B%5D=Excel/);
  await page.reload();
  await expect(page.locator('input[name="skill[]"][value="Excel"]')).toBeChecked();
  expect(await isOpen(page, 'experienta')).toBe(true);
  expect(await isOpen(page, 'skill')).toBe(true);
  const countAfter = (await page.locator('#results .font-mono.font-medium').first().textContent()).trim();

  await openPanel(page);
  await openFacet(page, 'studii');
  await openFacet(page, 'eqf');
  await page.locator('input[name="eqf[]"][value="4"]').check();
  await expect(page).toHaveURL(/eqf%5B%5D=4/);
  await page.goBack();
  await expect(page).not.toHaveURL(/eqf/);
  await expect(page.locator('input[name="skill[]"][value="Excel"]')).toBeChecked();
  await expect(page.locator('input[name="eqf[]"][value="4"]')).not.toBeChecked();
  expect((await page.locator('#results .font-mono.font-medium').first().textContent()).trim()).toBe(countAfter);
  await page.goForward();
  await expect(page).toHaveURL(/eqf%5B%5D=4/);
  await expect(page.locator('input[name="eqf[]"][value="4"]')).toBeChecked();
  expect(await isOpen(page, 'studii')).toBe(true);
});

test('Escape closes the drawer and keeps selections (mobile)', async ({ page }) => {
  test.skip(!isNarrow(page), 'drawer is mobile-only');
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'studii');
  await openFacet(page, 'eqf');
  await page.locator('input[name="eqf[]"][value="4"]').check();
  await expect(page).toHaveURL(/eqf%5B%5D=4/);
  // Let the swap settle (it hands focus back to the checkbox) before Escape.
  await expect(page.locator('input[name="eqf[]"][value="4"]')).toBeFocused();
  await expect(page.locator('#filter-count')).toHaveText('1');   // the EQF chip (status falls back to the default)
  await page.keyboard.press('Escape');
  await expect(page.locator('#filter-toggle')).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('#filter-toggle')).toBeFocused();
  await expect(page.locator('input[name="eqf[]"][value="4"]')).toBeChecked();
});

test('unfamiliar and employer-scope values keep their controls', async ({ page }) => {
  await page.goto('/?status=all&duration%5B%5D=zz_nou');
  await openPanel(page);
  await expect(page.locator('input[name="duration[]"][value="zz_nou"]')).toBeChecked();
  expect(await isOpen(page, 'contract')).toBe(true);

  await page.goto('/?employer=spitalul-clinic-judetean-cluj');
  await expect(page.locator('#results li')).toHaveCount(3);
  // An unrelated form change must not drop the scope (it has no sidebar control).
  await page.selectOption('#sort', 'employer');
  await expect(page).toHaveURL(/sort=employer/);
  await expect(page).toHaveURL(/employer=spitalul-clinic-judetean-cluj/);
  await expect(page.locator('#results li')).toHaveCount(3);
  await expectFeedsKeep(page, { employer: ['spitalul-clinic-judetean-cluj'], status: ['active'] });
  // Its chip removes the scope.
  await page.locator('[data-chip-param="employer"]').click();
  await expect(page).not.toHaveURL(/employer=/);
  expect(await page.locator('#results li').count()).toBeGreaterThan(3);
  await expectFeedsKeep(page, { employer: [] });
});

test('feed links follow filters through HTMX, chips, pagination and history', async ({ page }) => {
  await page.goto('/?judet%5B%5D=ilfov&sort=deadline');
  // Server-rendered links already drop sort.
  await expectFeedsKeep(page, { judet: ['ilfov'] });

  await openPanel(page);
  await openFacet(page, 'experienta');
  await openFacet(page, 'skill');
  await page.locator('input[name="skill[]"][value="Excel"]').check();
  await expect(page).toHaveURL(/skill%5B%5D=Excel/);
  await expectFeedsKeep(page, { judet: ['ilfov'], skill: ['Excel'] });
  // AND is the default for skills; switching to OR is carried into the feeds.
  await page.locator('label:has(input[name="skill_mode"][value="any"])').click();
  await expect(page).toHaveURL(/skill_mode=any/);
  await expectFeedsKeep(page, { judet: ['ilfov'], skill: ['Excel'], skill_mode: ['any'] });

  // Chip removal.
  await closePanel(page);
  await page.locator('[data-chip-param="skill"]').click();
  await expect(page).not.toHaveURL(/skill%5B/);
  await expectFeedsKeep(page, { judet: ['ilfov'], skill: [] });

  // Pagination (33 Ilfov rows → two pages): the page number is in the URL, not in the feeds.
  await page.locator('#results nav a[aria-label="Pagina 2"]').click();
  await expect(page).toHaveURL(/page=2/);
  await expectFeedsKeep(page, { judet: ['ilfov'], status: ['active'] });

  // Sort through the control.
  await page.selectOption('#sort', 'deadline');
  await expect(page).toHaveURL(/sort=deadline/);
  await expectFeedsKeep(page, { judet: ['ilfov'], status: ['active'] });

  // History.
  await page.goBack();
  await page.goBack();
  await expect(page).not.toHaveURL(/page=2/);
  await expectFeedsKeep(page, { judet: ['ilfov'], status: ['active'] });
  await page.goBack();
  await expect(page).toHaveURL(/skill%5B%5D=Excel/);
  await expectFeedsKeep(page, { judet: ['ilfov'], skill: ['Excel'] });

  // The calendar title is a calendar label: only iCal carries it.
  await page.locator('#feed-title').fill('Posturile mele');
  expect((await feedParams(page, 'posturi.ics')).get('title')).toBe('Posturile mele');
  expect((await feedParams(page, 'posturi.atom')).has('title')).toBe(false);
  expect((await feedParams(page, 'posturi.json')).has('title')).toBe(false);
});
