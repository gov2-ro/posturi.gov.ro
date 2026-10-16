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

test('fresh load: desktop starts with every group collapsed, the drawer with Județ and Grupă ocupațională open; no overflow', async ({ page }, testInfo) => {
  await page.goto('/');
  expect(await openKeys(page)).toEqual(isNarrow(page) ? ['cor', 'judet'] : []);
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

test('status is a select beside the sort; its help and the 7-day note follow the choice', async ({ page }) => {
  await page.goto('/');
  const help = page.locator('#status-help');
  const sel = page.locator('select#status');
  await expect(sel).toBeVisible();
  for (const l of ['Active', 'Termen în 7 zile', 'Înscrieri închise', 'Termen neprecizat']) {
    await expect(sel).toContainText(l);
  }
  // Default (Active): the help stays out of the way.
  await expect(help).toBeHidden();
  await sel.selectOption('soon');
  await expect(page).toHaveURL(/status=soon/);
  await expect(help).toBeVisible();
  await expect(help).toContainText('Active include anunțuri cu înscrieri deschise și anunțuri al căror termen nu este confirmat. Verifică termenul în anunțul oficial.');
  await expect(help.locator('.status-soon-note')).toBeVisible();
  await expect(help).toContainText('Include și date estimate din expirarea anunțului.');
  await noOverflow(page, 'status select');
  await expect(page.locator('#results')).toContainText('anunțuri găsite');
  await page.locator('select#status').selectOption('active');
  await expect(help).toBeHidden();
  // The default status is not written into the address bar, nor are empty fields.
  await expect(page).not.toHaveURL(/status=/);
  await expect(page).not.toHaveURL(/[?&](q|sort|expires_after|expires_before)=(&|$)/);
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

test('bookmarked selections open their own group; legacy params ride along and are removable', async ({ page }, testInfo) => {
  // BETA-FILTERS: one flat list. anomaly and type lost their controls but still filter.
  await page.goto('/?status=all&anomaly%5B%5D=missing_contact&type%5B%5D=Temporar&exp_level%5B%5D=5plus');
  const open = await openKeys(page);
  expect(open).toContain('exp_level');
  for (const k of ['cor', 'judet']) {
    if (isNarrow(page)) expect(open, k).toContain(k); else expect(open, k).not.toContain(k);
  }
  expect(open.filter((k) => !['exp_level', 'cor', 'judet'].includes(k))).toEqual([]);
  await expect(page.locator('input[type="hidden"][name="anomaly[]"][value="missing_contact"]')).toHaveCount(1);
  await noOverflow(page, 'selection with legacy params');
  await openPanel(page);
  await shot(page, testInfo, 'selected-advanced');

  await closePanel(page);
  await page.locator('[data-chip-param="anomaly"]').click();
  await expect(page).not.toHaveURL(/anomaly/);
  await expect(page).toHaveURL(/type%5B%5D=Temporar/);   // the other hidden one survives the swap
  await expect(page.locator('input[name="anomaly[]"]')).toHaveCount(0);
  expect(await openKeys(page)).toContain('exp_level');
});

test('nested disclosures, focus and reader preferences survive an OOB swap', async ({ page }) => {
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'studies_level');
  const box = page.locator('input[name="studies_level[]"][value="licenta"]');
  await box.focus();
  await box.press('Space');
  await expect(page).toHaveURL(/studies_level%5B%5D=licenta/);
  await expect(box).toBeChecked();
  await expect(box).toBeFocused();
  expect(await isOpen(page, 'studies_level')).toBe(true);
  // A group the reader opened by hand (and that holds no selection) stays open…
  await openFacet(page, 'duration');
  // ?status=all leaves no option current (UX-01B), so this is a real change and a real
  // swap: pick Active (the drawer covers the select on phones, so close it first).
  // Active is the default, so the address bar simply loses `status`.
  await closePanel(page);
  await page.locator('select#status').selectOption('active');
  await expect(page).not.toHaveURL(/status=/);
  await openPanel(page);
  await expect.poll(() => isOpen(page, 'duration')).toBe(true);
  // …and one the reader closed stays closed, while a group with a selection stays open.
  await page.locator('details[data-facet="duration"] > summary').click();
  await page.selectOption('#sort', 'employer');
  await expect(page).toHaveURL(/sort=employer/);
  expect(await isOpen(page, 'duration')).toBe(false);
  expect(await isOpen(page, 'studies_level')).toBe(true);
  await noOverflow(page, 'after swaps');
});

test('selection survives reload and history; ancestors reopen', async ({ page }) => {
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'skill');
  await page.locator('input[name="skill[]"][value="Excel"]').check();
  await expect(page).toHaveURL(/skill%5B%5D=Excel/);
  await page.reload();
  await expect(page.locator('input[name="skill[]"][value="Excel"]')).toBeChecked();
  expect(await isOpen(page, 'skill')).toBe(true);
  const countAfter = (await page.locator('#results .font-mono.font-medium').first().textContent()).trim();

  await openPanel(page);
  await openFacet(page, 'studies_level');
  await page.locator('input[name="studies_level[]"][value="licenta"]').check();
  await expect(page).toHaveURL(/studies_level%5B%5D=licenta/);
  await page.goBack();
  await expect(page).not.toHaveURL(/studies_level/);
  await expect(page.locator('input[name="skill[]"][value="Excel"]')).toBeChecked();
  await expect(page.locator('input[name="studies_level[]"][value="licenta"]')).not.toBeChecked();
  expect((await page.locator('#results .font-mono.font-medium').first().textContent()).trim()).toBe(countAfter);
  await page.goForward();
  await expect(page).toHaveURL(/studies_level%5B%5D=licenta/);
  await expect(page.locator('input[name="studies_level[]"][value="licenta"]')).toBeChecked();
  expect(await isOpen(page, 'studies_level')).toBe(true);
});

test('Escape closes the drawer and keeps selections (mobile)', async ({ page }) => {
  test.skip(!isNarrow(page), 'drawer is mobile-only');
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'studies_level');
  await page.locator('input[name="studies_level[]"][value="licenta"]').check();
  await expect(page).toHaveURL(/studies_level%5B%5D=licenta/);
  // Let the swap settle (it hands focus back to the checkbox) before Escape.
  await expect(page.locator('input[name="studies_level[]"][value="licenta"]')).toBeFocused();
  await expect(page.locator('#filter-count')).toHaveText('2');   // the study-level chip and the legacy status=all (kept: the select shows "Toate")
  await page.keyboard.press('Escape');
  await expect(page.locator('#filter-toggle')).toHaveAttribute('aria-expanded', 'false');
  await expect(page.locator('#filter-toggle')).toBeFocused();
  await expect(page.locator('input[name="studies_level[]"][value="licenta"]')).toBeChecked();
});

test('unfamiliar and employer-scope values keep their controls', async ({ page }) => {
  await page.goto('/?status=all&duration%5B%5D=zz_nou');
  await openPanel(page);
  await expect(page.locator('input[name="duration[]"][value="zz_nou"]')).toBeChecked();
  expect(await isOpen(page, 'duration')).toBe(true);

  await page.goto('/?employer=spitalul-clinic-judetean-cluj');
  await expect(page.locator('#results li')).toHaveCount(3);
  // An unrelated form change must not drop the scope (it has no sidebar control).
  await page.selectOption('#sort', 'employer');
  await expect(page).toHaveURL(/sort=employer/);
  await expect(page).toHaveURL(/employer=spitalul-clinic-judetean-cluj/);
  await expect(page.locator('#results li')).toHaveCount(3);
  await expectFeedsKeep(page, { employer: ['spitalul-clinic-judetean-cluj'], status: [] });
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
  await expectFeedsKeep(page, { judet: ['ilfov'], status: [] });

  // Sort through the control.
  await page.selectOption('#sort', 'deadline');
  await expect(page).toHaveURL(/sort=deadline/);
  await expectFeedsKeep(page, { judet: ['ilfov'], status: [] });

  // History.
  await page.goBack();
  await page.goBack();
  await expect(page).not.toHaveURL(/page=2/);
  await expectFeedsKeep(page, { judet: ['ilfov'], status: [] });
  await page.goBack();
  await expect(page).toHaveURL(/skill%5B%5D=Excel/);
  await expectFeedsKeep(page, { judet: ['ilfov'], skill: ['Excel'] });

  // The calendar title is a calendar label: only iCal carries it.
  // UX-04-FEEDS moved the title box into the subscription disclosure.
  await page.locator('details#urmareste > summary').click();
  await page.locator('#feed-title').fill('Posturile mele');
  expect((await feedParams(page, 'posturi.ics')).get('title')).toBe('Posturile mele');
  expect((await feedParams(page, 'posturi.atom')).has('title')).toBe(false);
  expect((await feedParams(page, 'posturi.json')).has('title')).toBe(false);
});

test('a value can be excluded ("fără"), and wanting it clears the exclusion', async ({ page }) => {
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'skill');
  const not = page.locator('.facet-not:has(input[name="skill_not[]"][value="Excel"])');
  await not.click();
  await expect(page).toHaveURL(/skill_not%5B%5D=Excel/);
  await expect(page.locator('input[name="skill_not[]"][value="Excel"]')).toBeChecked();
  await expect(page.locator('.facet-row-not')).toHaveCount(1);
  // Checking the value itself turns the exclusion off in the same request.
  await page.locator('label:has(> input[name="skill[]"][value="Excel"])').click();
  await expect(page).toHaveURL(/skill%5B%5D=Excel/);
  await expect(page).not.toHaveURL(/skill_not/);
  await expect(page.locator('input[name="skill_not[]"][value="Excel"]')).not.toBeChecked();
  await noOverflow(page, 'exclusion switch');
});

test('occupation tree: a twisty opens subgroups, and an open branch survives a filter change', async ({ page }) => {
  await page.goto('/?status=all');
  await openPanel(page);
  await openFacet(page, 'cor');
  const twisty = page.locator('.facet-twisty[data-tree-key="cor:2"]');
  const kids = page.locator('[data-tree-children="cor:2"]');
  await expect(kids).toBeHidden();
  await twisty.click();
  await expect(twisty).toHaveAttribute('aria-expanded', 'true');
  await expect(kids).toBeVisible();
  await page.locator('label:has(> input[name="cor[]"][value="22"])').click();
  await expect(page).toHaveURL(/cor%5B%5D=22/);
  await expect(page.locator('[data-tree-children="cor:2"]')).toBeVisible();
  await expect(page.locator('input[name="cor[]"][value="22"]')).toBeChecked();
  await noOverflow(page, 'occupation tree');
});
