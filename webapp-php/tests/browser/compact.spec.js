// Icon save/hide buttons and the compact view (horizontal filters + card grid).
// The compact view is lg+ only; at mobile widths the stored preference must change nothing.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const isDesktop = (vp) => vp.width >= 1024;
const row = (page, id) => page.locator(`li[data-pref-id="${id}"]`).first();

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}
function shot(page, name) {
  const dir = process.env.COMPACT_SHOTS;
  if (!dir) return Promise.resolve();
  fs.mkdirSync(dir, { recursive: true });
  return page.screenshot({ path: path.join(dir, `${name}-${page.viewportSize().width}.png`), fullPage: false });
}
const setLayout = (page, v) => page.addInitScript((val) => {
  try { if (val) localStorage.setItem('posturi.layout', val); } catch (e) { /* ignore */ }
}, v);

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

test('list icon buttons: small, inline after the title, 32px hit area, keyboard toggles announce', async ({ page }) => {
  await page.goto('/');
  const li = row(page, 1001);
  const save = li.locator('[data-pref="save"]');
  const hide = li.locator('[data-pref="hide"]');
  await expect(save).toBeVisible();
  // No visible words: only an icon. Name and tooltip come from attributes.
  await expect(save).toHaveText('');
  await expect(save).toHaveAttribute('title', 'Salvează anunțul');
  await expect(hide).toHaveAttribute('aria-label', 'Ascunde anunțul: Asistent medical');
  for (const b of [save, hide]) {
    const box = await b.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(32);
    expect(box.height).toBeGreaterThanOrEqual(32);
  }
  const svg = await save.locator('svg').boundingBox();
  expect(svg.width).toBeLessThanOrEqual(22);
  // Same line as the title (first line of a wrapping title).
  const t = await li.locator('h2 a').boundingBox();
  const s = await save.boundingBox();
  const mid = s.y + s.height / 2;
  expect(mid).toBeGreaterThanOrEqual(t.y);
  expect(mid).toBeLessThanOrEqual(t.y + t.height + 36);   // may wrap to the next line on a narrow screen

  await save.focus();
  await expect(save).toHaveCSS('outline-style', /.*/);       // focusable
  await page.keyboard.press('Enter');
  await expect(save).toHaveAttribute('aria-pressed', 'true');
  await expect(save).toHaveAttribute('aria-label', 'Elimină din salvate: Asistent medical');
  await expect(page.locator('#pref-live')).toContainText('Anunț salvat');
  await page.keyboard.press('Space');
  await expect(save).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('#pref-live')).toContainText('eliminat din salvate');
  await noOverflow(page, 'list with icons');
});

test('detail icon buttons: save, and hide marks the posting without removing the page', async ({ page }) => {
  await page.goto('/job/1001-asistent-medical-generalist/');
  const save = page.locator('[data-pref="save"]');
  const hide = page.locator('[data-pref="hide"]');
  await expect(save).toBeVisible();
  await expect(hide).toBeVisible();
  const h1 = await page.locator('h1').boundingBox();
  const hb = await hide.boundingBox();
  expect(hb.y).toBeLessThan(h1.y + h1.height + 8);          // beside / right after the heading
  await save.focus();
  await page.keyboard.press('Enter');
  await expect(save).toHaveAttribute('aria-pressed', 'true');
  await page.keyboard.press('Tab');
  await expect(hide).toBeFocused();
  await page.keyboard.press('Space');
  await expect(hide).toHaveAttribute('aria-pressed', 'true');
  await expect(hide).toHaveAttribute('aria-label', 'Restabilește anunțul: Asistent medical');
  await expect(page.locator('h1')).toBeVisible();            // the page stays
  await expect(page.locator('#pref-live')).toContainText('Anunț ascuns');
  await expect(page.locator('#pref-notice')).toBeVisible();
  await expect(page.locator('[data-pref-flag]')).toBeVisible();
  await page.locator('[data-notice-undo]').click();         // undo
  await expect(hide).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-pref-flag]')).toBeHidden();
  // Hide again, then restore with the same button; the state survives a reload.
  await hide.click();
  await page.reload();
  await expect(page.locator('[data-pref="hide"]')).toHaveAttribute('aria-pressed', 'true');
  await page.locator('[data-pref="hide"]').click();
  await expect(page.locator('[data-pref="hide"]')).toHaveAttribute('aria-pressed', 'false');
  await noOverflow(page, 'detail');
});

test('compact toggle: lg+ only, persists across reload and an HTMX filter change, cards in a grid', async ({ page, viewport }) => {
  await page.goto('/');
  const toggle = page.locator('#layout-toggle');
  if (!isDesktop(viewport)) {
    await expect(toggle).toBeHidden();
    return;
  }
  await expect(toggle).toBeVisible();
  await expect(toggle).toHaveText(/Vizualizare compactă/);
  await expect(toggle).toHaveAttribute('aria-pressed', 'false');
  expect(await page.evaluate(() => document.documentElement.dataset.layout)).toBeUndefined();
  const listCols = await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).display);
  expect(listCols).toBe('block');

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => localStorage.getItem('posturi.layout'))).toBe('compact');
  const cols = await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).gridTemplateColumns.split(' ').length);
  expect(cols).toBe(viewport.width >= 1280 ? 3 : 2);
  await noOverflow(page, 'compact 1280');
  await shot(page, 'compact');

  // Reload: applied before paint (check right after navigation commit) and still pressed.
  await page.reload();
  expect(await page.evaluate(() => document.documentElement.dataset.layout)).toBe('compact');
  await expect(page.locator('#layout-toggle')).toHaveAttribute('aria-pressed', 'true');

  // HTMX filter change keeps the layout and the cards.
  await page.locator('#facet-list > [data-facet="judet"] > summary').click();
  const box = page.locator('#facet-list > [data-facet="judet"] input[type="checkbox"]:not([disabled])').first();
  await box.check();
  await expect(page.locator('a[data-chip-param="judet"]')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.dataset.layout)).toBe('compact');
  const cols2 = await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).display);
  expect(cols2).toBe('grid');
  // The panel stays open so a second value can be ticked.
  await expect(page.locator('#facet-list > [data-facet="judet"]')).toHaveAttribute('data-pg-open', '');
  await expect(box).toBeChecked();
  await noOverflow(page, 'compact after filter');

  // Toggle off: back to the sidebar layout.
  await page.locator('#layout-toggle').click();
  expect(await page.evaluate(() => localStorage.getItem('posturi.layout'))).toBe('list');
  expect(await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).display)).toBe('block');
});

test('compact facet panels: one open at a time, Escape and outside click close, list-mode state untouched', async ({ page, viewport }) => {
  test.skip(!isDesktop(viewport), 'compact view is lg+ only');
  await setLayout(page, 'compact');
  await page.goto('/');
  const groups = page.locator('#facet-list > .facet-group');
  const n = await groups.count();
  expect(n).toBeGreaterThan(2);
  // Everything starts closed visually, even groups the server renders [open] (Județ, Domeniu).
  await expect(page.locator('#facet-list > .facet-group[data-pg-open]')).toHaveCount(0);
  await expect(page.locator('#facet-list > [data-facet="judet"] > .facet-body')).toBeHidden();
  // The search field is first in the row, groups follow on the same band.
  const q = await page.locator('#q').boundingBox();
  const g0 = await groups.first().boundingBox();
  expect(Math.abs(q.y - g0.y)).toBeLessThan(60);

  const judet = page.locator('#facet-list > [data-facet="judet"]');
  const family = page.locator('#facet-list > [data-facet="family"]');
  await judet.locator('> summary').click();
  await expect(judet.locator('> .facet-body')).toBeVisible();
  await expect(judet.locator('> summary')).toHaveAttribute('aria-expanded', 'true');
  const body = await judet.locator('> .facet-body').boundingBox();
  expect(body.width).toBeGreaterThanOrEqual(280);
  expect(body.x + body.width).toBeLessThanOrEqual(viewport.width);
  await shot(page, 'compact-facet-open');
  await noOverflow(page, 'panel open');

  await family.locator('> summary').click();                       // opening another closes the first
  await expect(family.locator('> .facet-body')).toBeVisible();
  await expect(judet.locator('> .facet-body')).toBeHidden();
  await expect(page.locator('#facet-list > .facet-group[data-pg-open]')).toHaveCount(1);

  await page.keyboard.press('Escape');                              // Escape closes and returns focus
  await expect(family.locator('> .facet-body')).toBeHidden();
  await expect(family.locator('> summary')).toBeFocused();

  await judet.locator('> summary').click();                        // click outside closes
  await expect(judet.locator('> .facet-body')).toBeVisible();
  await page.locator('#status-help').click();
  await expect(judet.locator('> .facet-body')).toBeHidden();

  // Keyboard: Enter on a focused summary opens, and the last pill's panel stays inside the viewport.
  const lastSel = '#facet-list > [data-facet="more"]';
  const last = page.locator(lastSel + ' > .facet-body');
  await page.locator(lastSel + ' > summary').focus();
  await page.keyboard.press('Enter');
  await expect(last).toBeVisible();
  const lb = await last.boundingBox();
  expect(lb.x).toBeGreaterThanOrEqual(0);
  expect(lb.x + lb.width).toBeLessThanOrEqual(viewport.width + 1);
  await noOverflow(page, 'last panel open');
  await page.keyboard.press('Escape');

  // None of that wrote the list-mode open/closed preferences.
  expect(await page.evaluate(() => localStorage.getItem('posturi.facets'))).toBeNull();
});

test('a stored compact preference changes nothing below lg', async ({ page, viewport }) => {
  test.skip(isDesktop(viewport), 'mobile check');
  await setLayout(page, 'compact');
  await page.goto('/');
  expect(await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).display)).toBe('block');
  await expect(page.locator('#facet-panel')).not.toBeInViewport();       // still the off-canvas drawer
  await expect(page.locator('#filter-toggle')).toBeVisible();
  await expect(page.locator('#layout-toggle')).toBeHidden();
  await page.locator('#filter-toggle').click();                           // drawer still works, summaries still native
  await expect(page.locator('#facet-panel')).toBeInViewport();
  await page.locator('#facet-list > [data-facet="judet"] > summary').click();
  await page.locator('#facet-list > [data-facet="judet"] > summary').click();
  await page.keyboard.press('Escape');
  await noOverflow(page, 'mobile with stored compact');
  await shot(page, 'list');
});

test('screenshots: list with icons and detail', async ({ page }) => {
  test.skip(!process.env.COMPACT_SHOTS, 'review screenshots only');
  await page.goto('/');
  await shot(page, 'list');
  await page.goto('/job/1001-asistent-medical-generalist/');
  await shot(page, 'detail');
});
