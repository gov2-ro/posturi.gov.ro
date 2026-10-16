// Icon save/hide buttons and the three result views: list (sidebar + rows), grid
// (`compact`: filter bar + cards) and table (filter bar + one line per posting).
// The grid and table views are lg+ only; at mobile widths the stored preference must change nothing.
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

const view = (page, mode) => page.locator(`#layout-switch [data-layout-mode="${mode}"]`);
async function expectView(page, mode) {
  for (const m of ['list', 'compact', 'table']) {
    await expect(view(page, m)).toHaveAttribute('aria-pressed', m === mode ? 'true' : 'false');
  }
  expect(await page.evaluate(() => document.documentElement.dataset.layout)).toBe(mode === 'list' ? undefined : mode);
}
const display = (page, sel) => page.locator(sel).first().evaluate((el) => getComputedStyle(el).display);

test('view switch: lg+ only, three views persist across reload and an HTMX filter change', async ({ page, viewport }) => {
  await page.goto('/');
  await expect(page.locator('#sort')).toBeVisible();
  expect(await page.locator('#sort').evaluate((el) => !!(el.compareDocumentPosition(document.querySelector('#results')) & Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
  expect(await page.locator('#urmareste').evaluate((el) => !!(document.querySelector('#results').compareDocumentPosition(el) & Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
  const sw = page.locator('#layout-switch');
  if (!isDesktop(viewport)) {
    await expect(sw).toBeHidden();
    return;
  }
  await expect(sw).toBeVisible();
  await expect(sw).toHaveAttribute('aria-label', 'Vizualizare');
  await expect(sw.locator('[data-layout-mode]')).toHaveText(['Listă', 'Grilă', 'Tabel']);
  await expectView(page, 'list');
  expect(await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).gridTemplateColumns.split(' ').length)).toBe(2);   // list view: two columns
  await expect(page.locator('.pg-thead')).toBeHidden();

  await view(page, 'compact').click();
  await expectView(page, 'compact');
  expect(await page.evaluate(() => localStorage.getItem('posturi.layout'))).toBe('compact');
  const cols = await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).gridTemplateColumns.split(' ').length);
  expect(cols).toBe(3);                                               // 19rem columns: three from 1024px up to the 1280px page
  await expect(view(page, 'compact')).toBeFocused();                 // the row moved into the bar; focus stayed
  await noOverflow(page, 'grid');
  await shot(page, 'compact');

  await view(page, 'table').click();
  await expectView(page, 'table');
  expect(await page.evaluate(() => localStorage.getItem('posturi.layout'))).toBe('table');
  await expect(page.locator('.pg-thead')).toBeVisible();
  await expect(page.locator('.pg-thead span')).toHaveText(['Post', 'Angajator', 'Localitate', 'Salariu', 'Termen']);
  expect(await display(page, 'ul.pg-results > li.pg-row')).toBe('grid');
  await noOverflow(page, 'table');
  await shot(page, 'table');

  // Reload: applied before paint (check right after navigation commit) and still pressed.
  await page.reload();
  expect(await page.evaluate(() => document.documentElement.dataset.layout)).toBe('table');
  await expectView(page, 'table');

  // HTMX filter change keeps the view and the table.
  await page.locator('#facet-list > [data-facet="judet"] > summary').click();
  const box = page.locator('#facet-list > [data-facet="judet"] input[type="checkbox"]:not([disabled])').first();
  await box.check();
  await expect(page.locator('a[data-chip-param="judet"]')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.dataset.layout)).toBe('table');
  expect(await display(page, 'ul.pg-results > li.pg-row')).toBe('grid');
  // The panel stays open so a second value can be ticked.
  await expect(page.locator('#facet-list > [data-facet="judet"]')).toHaveAttribute('data-pg-open', '');
  await expect(box).toBeChecked();
  await noOverflow(page, 'table after filter');
  await page.keyboard.press('Escape');

  // Back to the list: the sidebar, and view/sort under the status tabs again.
  await view(page, 'list').click();
  await expectView(page, 'list');
  expect(await page.evaluate(() => localStorage.getItem('posturi.layout'))).toBe('list');
  expect(await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).gridTemplateColumns.split(' ').length)).toBe(2);   // list view: two columns
  expect(await page.locator('#result-options').evaluate((el) => !!el.closest('.pg-main'))).toBe(true);
});

test('filter bar: search, filters, view and sort are one row of equal controls', async ({ page, viewport }) => {
  test.skip(!isDesktop(viewport), 'the bar is lg+ only');
  for (const mode of ['compact', 'table']) {
    await page.addInitScript((m) => localStorage.setItem('posturi.layout', m), mode);
    await page.goto('/?judet%5B%5D=cluj');
    const bar = page.locator('.pg-side');
    expect(await page.locator('#result-options').evaluate((el) => el.parentElement.classList.contains('pg-side')), mode).toBe(true);
    const ctl = ['#q', '#facet-list > .facet-group', '#facet-list > .pg-clear', '#layout-switch', '.pg-sort'];
    const boxes = [];
    for (const sel of ctl) for (const el of await page.locator(sel).all()) boxes.push([sel, await el.boundingBox()]);
    const b = await bar.boundingBox();
    for (const [sel, bb] of boxes) {
      expect(Math.round(bb.height), `${mode}: ${sel} height`).toBe(40);
      expect(bb.y, `${mode}: ${sel} inside the bar`).toBeGreaterThanOrEqual(b.y - 1);
      expect(bb.y + bb.height, `${mode}: ${sel} inside the bar`).toBeLessThanOrEqual(b.y + b.height + 1);
    }
    // Every control starts on a bar line (no stray offsets): its top is the top of some line.
    const tops = [...new Set(boxes.map(([, bb]) => Math.round(bb.y)))];
    expect(tops.length, `${mode}: rows ${tops}`).toBeLessThanOrEqual(3);
    // View and sort follow the last filter with the bar's own gap: no hole before them.
    const sw = await page.locator('#layout-switch').boundingBox();
    const prev = await page.locator('#layout-switch').evaluate((el) => {
      const items = [...document.querySelectorAll('#q, #facet-list > .facet-group, #facet-list > .pg-clear')];
      const r = items.map((i) => i.getBoundingClientRect()).filter((x) => x.height > 0).pop();
      return { right: r.right, y: r.y };
    });
    if (Math.abs(prev.y - sw.y) < 2) expect(sw.x - prev.right, `${mode}: gap before the view switch`).toBeLessThanOrEqual(9);
    else expect(Math.abs(sw.x - b.x), `${mode}: the view switch starts a line`).toBeLessThanOrEqual(1);
    // The status tabs start below the bar.
    const tabs = await page.locator('.status-block').boundingBox();
    expect(tabs.y, mode).toBeGreaterThanOrEqual(b.y + b.height + 8);
    await noOverflow(page, `bar ${mode}`);
  }
});

test('home shortcuts close the bar on a row of their own in the grid and table views, and go back in the list view', async ({ page, viewport }) => {
  test.skip(!isDesktop(viewport), 'the bar is lg+ only');
  await setLayout(page, 'compact');
  await page.goto('/');
  const sc = page.locator('#shortcuts');
  expect(await sc.evaluate((el) => el.parentElement.classList.contains('pg-side'))).toBe(true);
  expect(await sc.evaluate((el) => el === el.parentElement.lastElementChild), 'last in the bar').toBe(true);
  const bar = await page.locator('.pg-side').boundingBox();
  const own = await sc.boundingBox();
  const sort = await page.locator('.pg-sort').boundingBox();
  expect(own.y, 'a row below view and sort').toBeGreaterThanOrEqual(sort.y + sort.height);
  expect(Math.abs(own.x - bar.x), 'starts at the bar\'s left edge').toBeLessThanOrEqual(1);
  expect(Math.abs(own.width - bar.width), 'the whole bar width').toBeLessThanOrEqual(1);
  for (const el of await sc.locator('a').all()) {
    const bb = await el.boundingBox();
    expect(bb.y + bb.height, 'chip inside the bar').toBeLessThanOrEqual(bar.y + bar.height + 1);
  }
  const tabs = await page.locator('.status-block').boundingBox();
  expect(tabs.y).toBeGreaterThanOrEqual(bar.y + bar.height + 8);
  await noOverflow(page, 'shortcuts in the bar');
  await view(page, 'list').click();
  expect(await sc.evaluate((el) => !!el.closest('.pg-main'))).toBe(true);
  // In the list view the shortcuts precede the status tabs, as before.
  expect(await sc.evaluate((el) => !!(el.compareDocumentPosition(document.querySelector('.status-block')) & Node.DOCUMENT_POSITION_FOLLOWING))).toBe(true);
});

test('the total is said once, just before the results', async ({ page }) => {
  await page.goto('/');
  // Text nodes a reader sees (the sr-only live region is for screen readers and says it once more on change).
  const seen = (re) => page.evaluate((src) => {
    const re = new RegExp(src);
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    const hits = [];
    for (let n = w.nextNode(); n; n = w.nextNode()) {
      const el = n.parentElement;
      if (!re.test(n.textContent) || el.closest('.sr-only, script, template, [hidden]')) continue;
      hits.push(el);
    }
    const list = document.querySelector('ul.pg-results');
    return hits.map((el) => !!(el.compareDocumentPosition(list) & Node.DOCUMENT_POSITION_FOLLOWING));
  }, re.source);
  expect(await seen(/anunțuri active/)).toEqual([]);                    // the old corpus line above the shortcuts
  expect(await seen(/anunțuri găsite/)).toEqual([true]);                // once, and before the list
});

test('list view: two columns beside the sidebar, the deadline beside the title on one line', async ({ page, viewport }) => {
  test.skip(!isDesktop(viewport), 'two columns from about 1050px up');
  await page.goto('/');
  const cols = await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).gridTemplateColumns.split(' ').length);
  expect(cols).toBe(2);
  const ys = await page.locator('ul.pg-results > li.pg-row').evaluateAll((els) => els.slice(0, 2).map((e) => Math.round(e.getBoundingClientRect().y)));
  expect(ys[0]).toBe(ys[1]);
  const li = row(page, 1001);
  const [title, dl, meta] = [await li.locator('.pg-title').boundingBox(), await li.locator('.pg-deadline').boundingBox(), await li.locator('.pg-meta').boundingBox()];
  expect(dl.x, 'deadline right of the title').toBeGreaterThan(title.x + title.width - 1);
  expect(dl.height, 'days and date on one line').toBeLessThanOrEqual(22);
  expect(meta.y, 'employer line below the title block').toBeGreaterThanOrEqual(title.y + title.height);
  expect(meta.x + meta.width, 'employer line runs under the deadline too').toBeGreaterThan(dl.x);
  await expect(li.locator('.pg-salary')).toContainText('💰 4.100–5.200 lei (estimat)');
  await noOverflow(page, 'two-column list');
});

test('grid cards: columns, title, employer, pay beside the deadline with the labels on its line', async ({ page, viewport }) => {
  test.skip(!isDesktop(viewport), 'the grid is lg+ only');
  await setLayout(page, 'compact');
  await page.goto('/');
  const li = row(page, 1001);
  const box = async (sel) => li.locator(sel).first().boundingBox();
  const [title, meta, pay, dl, badges] = [await box('.pg-title'), await box('.pg-meta'), await box('.pg-salary'), await box('.pg-deadline'), await box('.pg-badges')];
  expect(Math.abs(pay.y - dl.y), 'pay and deadline share a line').toBeLessThanOrEqual(4);
  expect(dl.x, 'deadline right of the pay').toBeGreaterThan(pay.x + pay.width);
  expect(meta.y - (title.y + title.height), 'employer line right under the title').toBeLessThanOrEqual(6);
  expect(pay.y - (meta.y + meta.height), 'pay right under the employer').toBeLessThanOrEqual(6);
  // 261016: no boxed badges; the source labels follow the pay on the same line of facts.
  expect(badges.y, 'labels start on the pay line').toBeLessThanOrEqual(pay.y + pay.height);
  await expect(li.locator('.pg-salary')).toContainText('💰 4.100–5.200 lei (estimat)');
  // The three cards of the first row sit side by side.
  const ys = await page.locator('ul.pg-results > li.pg-row').evaluateAll((els) => els.slice(0, 3).map((e) => Math.round(e.getBoundingClientRect().y)));
  expect(new Set(ys).size).toBe(1);
  await noOverflow(page, 'grid cards');
});

test('table view: one line per posting, cells in their columns, pay labelled', async ({ page, viewport }) => {
  test.skip(!isDesktop(viewport), 'the table is lg+ only');
  await setLayout(page, 'table');
  await page.goto('/');
  const head = await page.locator('.pg-thead span').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().x)));
  const li = row(page, 1001);
  const cells = {};
  for (const [k, sel] of Object.entries({ title: '.pg-title', employer: '.pg-employer', place: '.pg-place', salary: '.pg-salary', deadline: '.pg-deadline' })) {
    cells[k] = await li.locator(sel).boundingBox();
  }
  ['title', 'employer', 'place', 'salary', 'deadline'].forEach((k, i) => {
    expect(Math.abs(cells[k].x - head[i]), `${k} under its head`).toBeLessThanOrEqual(2);
  });
  // One line: every cell starts on the row's first line.
  for (const k of Object.keys(cells)) expect(Math.abs(cells[k].y - cells.title.y), `${k} on the row line`).toBeLessThanOrEqual(8);
  await expect(li.locator('.pg-salary')).toContainText('4.100–5.200 lei');
  await expect(li.locator('.pg-salary-kind')).toHaveText('(estimat)');
  await expect(li.locator('.pg-salary-label')).toBeHidden();
  await expect(li.locator('.pg-badges')).toBeHidden();
  await expect(li.locator('[data-pref="save"]')).toBeVisible();
  await noOverflow(page, 'table');
});

test('pay is on every result view, labelled as an estimate', async ({ page, viewport }) => {
  for (const mode of isDesktop(viewport) ? ['list', 'compact', 'table'] : ['list']) {
    await page.addInitScript((m) => localStorage.setItem('posturi.layout', m), mode);
    await page.goto('/');
    const sal = row(page, 1001).locator('.pg-salary');
    await expect(sal, mode).toBeVisible();
    await expect(sal, mode).toContainText('4.100–5.200 lei');
    await expect(sal, mode).toContainText('(estimat)');
  }
});

test('bar facet panels: one open at a time, Escape and outside click close, list-mode state untouched', async ({ page, viewport }) => {
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
  const lastSel = '#facet-list > [data-facet="termen"]';
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

test('a stored grid or table preference changes nothing below lg', async ({ page, viewport }) => {
  test.skip(isDesktop(viewport), 'mobile check');
  await setLayout(page, 'table');
  await page.goto('/');
  expect(await page.locator('ul.pg-results').evaluate((u) => getComputedStyle(u).display)).toBe('block');
  await expect(page.locator('#facet-panel')).not.toBeInViewport();       // still the off-canvas drawer
  await expect(page.locator('#filter-toggle')).toBeVisible();
  await expect(page.locator('#layout-switch')).toBeHidden();
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
