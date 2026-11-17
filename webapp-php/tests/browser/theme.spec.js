// Themed landing page (/sanatate/): sections, county selection, links. Runs at 320, 375 and 1280
// (playwright.config.js) against the theme fixture (tests/fixtures/theme_db.php) on its own port.
// Fixture clock 2026-10-03. Health postings: 6 (cluj 2, bacau 2, ilfov 2); specialties Cardiologie 3,
// Neurologie 2, Radiologie 1.
const { test, expect } = require('@playwright/test');

test.use({ baseURL: 'http://127.0.0.1:8824' });

// The county map is in the first screen (section h-theme); no section "Pe județe".
const SECTIONS = ['h-theme', 'h-specialitati', 'h-ocupatii', 'h-studii', 'h-noi', 'h-notificari'];
const HEALTH = 'family%5B%5D=s%C4%83n%C4%83tate';

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
  page.on('dialog', (d) => { errs.push('dialog: ' + d.message()); d.dismiss(); });
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

const data = (page) => page.evaluate(() => JSON.parse(document.getElementById('home-data').textContent));
const tile = (page, id) => page.locator(`[data-home-tile][data-domain="${id}"]`);
const paren = (text) => parseInt(text.replace(/\D/g, ''), 10);
const num = (text) => parseInt(text.replace(/\./g, ''), 10);
/** The canonical list URL (ADR 0001) of an item of #home-data, with an optional county. */
const itemHref = (item, county) => {
  const p = [];
  if (county) p.push(['judet', county]);
  Object.entries(item.params).forEach(([field, vals]) => Object.values(vals).forEach((v) => p.push([field.replace(/\[\]$/, ''), v])));
  p.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : a[1] < b[1] ? -1 : 1));
  return '/?' + p.map(([k, v]) => `${encodeURIComponent(k)}%5B%5D=${encodeURIComponent(v)}`).join('&');
};
/** The bars of the chart: for each week, its segments {h, c, t} (height, class, title). */
const bars = (page) => page.evaluate(() => [...document.querySelectorAll('[data-chart-bars] [data-bar]')].map((g) => [...g.querySelectorAll('rect')].map((r) => ({ h: parseFloat(r.getAttribute('height')), c: r.getAttribute('class'), t: r.querySelector('title').textContent }))));
const sig = async (page) => JSON.stringify(await bars(page));
const sum = (v) => v.reduce((a, b) => a + b, 0);
async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('the sections are present in order; no horizontal overflow', async ({ page }) => {
  await page.goto('/sanatate/');
  await expect(page).toHaveTitle(/^Posturi în sănătate/);
  await expect(page.locator('h1')).toHaveText('Posturi în sănătate (6)');
  const ids = await page.locator('main section[aria-labelledby]').evaluateAll((els) => els.map((e) => e.getAttribute('aria-labelledby')));
  expect(ids).toEqual(SECTIONS);
  // Each section starts below the one before it; the county section is shown by the script.
  const tops = await page.locator('main section[aria-labelledby]').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top + window.scrollY));
  tops.forEach((t, i) => { if (i) expect(t, `section ${SECTIONS[i]} below ${SECTIONS[i - 1]}`).toBeGreaterThan(tops[i - 1]); });
  await expect(page.locator('button[data-county]')).toHaveCount(42);
  await expect(page.locator('button[data-county]').first()).toBeVisible();
  await expect(page.getByText('Pe județe', { exact: true })).toHaveCount(0);
  // The link to all the postings of the theme, with its count in brackets outside the link; the last line.
  await expect(tile(page, 'all').locator('a')).toHaveText('Toate anunțurile din sănătate');
  expect(paren(await tile(page, 'all').locator('[data-tile-count]').textContent())).toBe(6);   // the count follows the link (and is in the title)
  await expect(page.locator('h1#h-theme')).toHaveText('Posturi în sănătate (6)');
  // The line of the other employers is directly before "Noi și pe terminate".
  await expect(page.locator('[data-domain="other"]')).toHaveCount(1);
  expect(await page.evaluate(() => document.querySelector('[data-domain="other"]').getBoundingClientRect().top < document.getElementById('h-noi').getBoundingClientRect().top)).toBe(true);
  await noOverflow(page, '/sanatate/');
  // The text of an item wraps; the count stays with its last word. Nothing of an item sticks out of the page.
  const wide = await page.evaluate(() => Array.from(document.querySelectorAll('main li, main a')).filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1).length);
  expect(wide).toBe(0);
});

test('the county selection updates the counts and the links', async ({ page }) => {
  await page.goto('/sanatate/');
  const d = await data(page);
  expect(d.total).toBe(6);
  const ids = Object.keys(d.items).filter((id) => id !== 'more:medspec');
  const live = page.locator('[data-home-live]');
  const pick = (slug) => page.locator(`button[data-county="${slug}"]`);
  // The server output: totals, and links to the base filter.
  for (const id of ids) {
    await expect(tile(page, id).locator('a')).toHaveAttribute('href', itemHref(d.items[id]));
  }
  // The map is the control: a click on a county.
  await expect(page.locator('[data-county-hint]')).toHaveCount(0);   // the line under the map is gone
  await pick('cluj').click();
  await expect(live).toHaveText('Cluj: 2 posturi active');
  await expect(pick('cluj')).toHaveAttribute('aria-pressed', 'true');
  for (const id of ids) {
    const n = (d.items[id].counties || {}).cluj || 0;
    if (n === 0) await expect(tile(page, id), `${id} hidden in cluj`).toBeHidden();
    else {
      expect(paren(await tile(page, id).locator('[data-tile-count]').textContent()), `${id} count in cluj`).toBe(n);
      await expect(tile(page, id).locator('a'), `${id} link in cluj`).toHaveAttribute('href', itemHref(d.items[id], 'cluj'));
    }
  }
  expect(await page.locator('[data-home-tile]:visible').count()).toBeGreaterThan(3);
  // Cardiologie: 2 in cluj (1001, 1006). Neurologie: none in cluj. The "all" link follows the county too.
  expect(paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent())).toBe(2);
  await expect(tile(page, 'medspec:Neurologie')).toBeHidden();
  await expect(tile(page, 'all').locator('a')).toHaveAttribute('href', `/?${HEALTH}&judet%5B%5D=cluj`);
  // A click on another county moves the selection.
  await page.locator('button[data-county="bacau"]').click();
  await expect(page.locator('h1#h-theme')).toHaveText('Posturi în sănătate → județul Bacău (2)');
  await expect(page.locator('button[data-county="bacau"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('button[data-county="cluj"]')).toHaveAttribute('aria-pressed', 'false');
  expect(paren(await tile(page, 'medspec:Neurologie').locator('[data-tile-count]').textContent())).toBe(2);
  await expect(tile(page, 'medspec:Radiologie')).toBeHidden();
  // A second click on the same cell clears it: the totals and the first links are back.
  await page.locator('button[data-county="bacau"]').click();
  await expect(page.locator('#home-judet')).toHaveValue('');
  await expect(live).toHaveText('Toate județele: 6 posturi active');
  for (const id of ids) {
    await expect(tile(page, id)).toBeVisible();
    await expect(tile(page, id).locator('a')).toHaveAttribute('href', itemHref(d.items[id]));
  }
  expect(paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent())).toBe(3);
  await noOverflow(page, 'after the selection');
});

test('a specialty link opens a list with the same count, also inside a county', async ({ page }) => {
  await page.goto('/sanatate/');
  const shown = paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent());
  expect(shown).toBe(3);
  await tile(page, 'medspec:Cardiologie').locator('a').click();
  await expect(page).toHaveURL(new RegExp(`/\\?${HEALTH}&medspec%5B%5D=Cardiologie$`));
  expect(num(await page.locator('#results-status').textContent())).toBe(shown);
  // Inside a county: the count of the link is the count of the list.
  await page.goto('/sanatate/');
  await page.locator('button[data-county="cluj"]').click();
  const inCluj = paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent());
  await tile(page, 'medspec:Cardiologie').locator('a').click();
  await expect(page).toHaveURL(new RegExp(`/\\?${HEALTH}&judet%5B%5D=cluj&medspec%5B%5D=Cardiologie$`));
  expect(num(await page.locator('#results-status').textContent())).toBe(inCluj);
  // An occupation and the other-employers line, the same way.
  await page.goto('/sanatate/');
  for (const id of ['occupation:Asistent medical', 'other']) {
    await page.goto('/sanatate/');
    const n = paren(await tile(page, id).locator('[data-tile-count]').textContent());
    await tile(page, id).locator('a').click();
    expect(num(await page.locator('#results-status').textContent()), id).toBe(n);
  }
});

test('the search form: three selects and the button; one specialty and a county open the list; an empty submit opens the base list', async ({ page }) => {
  await page.goto('/sanatate/');
  await expect(page.locator('[data-home-search] input[type="search"]')).toHaveCount(0);
  await expect(page.locator('[data-home-search] select')).toHaveCount(3);
  await page.locator('#home-judet').selectOption('bacau');
  await page.locator('#home-sel-0').selectOption('Cardiologie');
  await page.locator('[data-home-search] button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`/\\?${HEALTH}&judet%5B%5D=bacau&medspec%5B%5D=Cardiologie$`));
  await page.goto('/sanatate/');
  const onPage = paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent());
  await page.locator('#home-sel-0').selectOption('Cardiologie');
  await page.locator('[data-home-search] button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`/\\?${HEALTH}&medspec%5B%5D=Cardiologie$`));
  expect(num(await page.locator('#results-status').textContent().then((t) => t.match(/[\d.]+/)[0]))).toBe(onPage);
  await page.goto('/sanatate/');
  await page.locator('[data-home-search] button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`/\\?${HEALTH}$`));
});

test('the selects of the search form: one row of controls or two without overflow; full width at 320 px', async ({ page, viewport }) => {
  await page.goto('/sanatate/');
  const form = await page.locator('[data-home-search]').boundingBox();
  const ctl = await page.locator('[data-home-search] select, [data-home-search] button[type="submit"]').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, w: r.width }; }));
  expect(ctl).toHaveLength(4);
  ctl.forEach((c) => { expect(c.l).toBeGreaterThanOrEqual(form.x - 1); expect(c.r).toBeLessThanOrEqual(form.x + form.width + 1); });
  const rows = new Set(ctl.map((c) => Math.round(c.t))).size;
  expect(rows, 'at most two rows at 1280 px').toBeLessThanOrEqual(viewport.width >= 1024 ? 2 : 4);
  if (viewport.width <= 375) ctl.forEach((c) => expect(c.w, 'a control is full width').toBeGreaterThanOrEqual(form.width - 2));
  await noOverflow(page, 'the form');
});

test.describe('without script', () => {
  test.use({ javaScriptEnabled: false });
  test('the items show the totals and link to the base filter; the map and its line are not shown', async ({ page }) => {
    await page.goto('/sanatate/');
    await expect(page.locator('button[data-county]').first()).toBeHidden();
    expect(paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent())).toBe(3);
    await expect(tile(page, 'medspec:Cardiologie').locator('a')).toHaveAttribute('href', `/?${HEALTH}&medspec%5B%5D=Cardiologie`);
    await noOverflow(page, 'no script');
  });
});

test('the first screen has two columns from lg: the map is in it at 1280 px, and follows the left column below lg', async ({ page, viewport }) => {
  await page.goto('/sanatate/');
  const hero = page.locator('section[aria-labelledby="h-theme"]');
  const left = await hero.locator('h1').boundingBox();
  const form = await hero.locator('[data-home-search]').boundingBox();
  const all = await page.locator('[data-theme-stats]').boundingBox();
  const map = await hero.locator('.pg-map').boundingBox();
  const caption = await hero.getByText('Posturi active pe județe', { exact: true }).boundingBox();
  const legend = await hero.locator('.pg-map-swatch').first().boundingBox();
  if (viewport.width >= 1024) {
    expect(map.y, 'the top of the map is in the first screen').toBeLessThan(600);
    expect(map.y + map.height, 'the whole map is in the first screen').toBeLessThan(viewport.height);
    expect(map.x, 'the map is right of the form').toBeGreaterThan(form.x + form.width - 1);
    expect(caption.y).toBeLessThan(map.y);
    expect(legend.y).toBeGreaterThan(map.y + map.height - 1);
    expect(left.y).toBeLessThan(map.y + 40);
  } else {
    expect(map.y, 'below the left column').toBeGreaterThan(all.y + all.height - 1);
    expect(map.x).toBeLessThan(40);
  }
  // The line under the map, and no select outside the search form.
  await expect(hero.getByText('Alege un județ pe hartă')).toHaveCount(0);   // the removed line
  await expect(page.locator('select:not([data-home-search] select)')).toHaveCount(0);
  await expect(page.locator('#domenii-judet')).toHaveCount(0);
  await expect(page.locator('[data-home-search] select')).toHaveCount(3);
  await noOverflow(page, 'first screen');
});

test('no checkbox "Arată toate județele"; the first option of the select clears the county everywhere', async ({ page }) => {
  await page.goto('/sanatate/');
  const d = await data(page);
  await expect(page.getByRole('checkbox')).toHaveCount(0);
  // The map sets the county; the first option of the select clears the map, the title, the links and the chart.
  const chartPts = await sig(page);
  await page.locator('button[data-county="cluj"]').click();
  expect(paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent())).toBe(2);
  expect(await sig(page)).not.toBe(chartPts);
  await page.locator('#home-judet').selectOption('');
  await expect(page.locator('button[data-county][aria-pressed="true"]')).toHaveCount(0);
  await expect(page.locator('#home-judet')).toHaveValue('');
  await expect(page.locator('h1#h-theme')).toHaveText('Posturi în sănătate (6)');
  await expect(page.locator('[data-home-live]')).toHaveText('Toate județele: 6 posturi active');
  expect(await sig(page)).toBe(chartPts);
  expect(paren(await tile(page, 'medspec:Cardiologie').locator('[data-tile-count]').textContent())).toBe(3);
  await expect(tile(page, 'all').locator('a')).toHaveAttribute('href', itemHref(d.items.all));
  // The label above the map is not visible, and the map has a name.
  const lab = await page.locator('#map-label').boundingBox();
  expect(lab.width * lab.height, 'the label is not visible').toBeLessThanOrEqual(4);
  await expect(page.getByRole('group', { name: 'Posturi active pe județe' })).toHaveCount(1);
  await expect(page.locator('[data-county-clear]'), 'the button after the title (no checkbox): hidden with no county').toBeHidden();
});

test('the title has the icon of the theme, as decoration', async ({ page }) => {
  await page.goto('/sanatate/');
  const icon = page.locator('h1 svg');
  await expect(icon).toHaveCount(1);
  await expect(icon).toHaveAttribute('aria-hidden', 'true');
  await expect(icon.locator('use')).toHaveAttribute('href', '#i-sector-sanatate');
  const ib = await icon.boundingBox();
  const tb = await page.locator('h1 > span').boundingBox();
  expect(ib.width).toBeGreaterThan(16);
  expect(ib.x + ib.width, 'the icon is before the text').toBeLessThanOrEqual(tb.x);
});

// ---- The statistics panel, the compact lists and the icons (ADR 0007) ----

test('the statistics panel: left column at lg, above the map below lg; no overflow', async ({ page, viewport }) => {
  await page.goto('/sanatate/');
  const panel = page.locator('[data-theme-stats]');
  await expect(panel).toBeVisible();
  const [p, m, form] = await Promise.all([
    panel.boundingBox(), page.locator('button[data-county]').first().boundingBox(), page.locator('[data-home-search]').boundingBox(),
  ]);
  expect(p.y, 'the panel is below the search form').toBeGreaterThan(form.y + form.height - 1);
  if (viewport.width >= 1024) {
    expect(p.x + p.width, 'the panel is in the left column').toBeLessThanOrEqual(m.x + 1);
    expect(p.y, 'beside the map, in the first screen').toBeLessThan(m.y + 700);
  } else {
    expect(p.y + p.height, 'the panel is above the map').toBeLessThanOrEqual(m.y);
    expect(p.x, 'it uses the page gutter').toBeLessThanOrEqual(24);
  }
  await expect(page.locator('table')).toHaveCount(0);
  await noOverflow(page, 'the panel');
  await expect(panel.locator('figure')).toHaveCount(1);
  await expect(page.locator('[data-fig]')).toHaveCount(0);   // no figures row
  await expect(page.getByText('posturi active', { exact: true })).toHaveCount(0);
});

test('the lists are compact: every link is at least 24 px high, and two targets do not touch', async ({ page }) => {
  await page.goto('/sanatate/');
  const boxes = await page.locator('#h-specialitati ~ ul a, #h-ocupatii ~ ul a, #h-studii ~ ul a')
    .evaluateAll((as) => as.map((a) => { const r = a.getBoundingClientRect(); return { l: r.left, r: r.right, t: r.top, b: r.bottom, h: r.height, name: a.textContent }; }));
  expect(boxes.length).toBeGreaterThan(8);
  for (const b of boxes) expect(b.h, `${b.name}: height`).toBeGreaterThanOrEqual(24);
  for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
    const a = boxes[i], c = boxes[j];
    const overlap = a.l < c.r && c.l < a.r && a.t < c.b && c.t < a.b;
    expect(overlap, `${a.name} and ${c.name} do not touch`).toBe(false);
  }
  // A row of one line is 28 px (the landing page has 44 px).
  const row = await page.locator('#h-studii ~ ul li').first().evaluate((li) => li.getBoundingClientRect().height);
  expect(row).toBeLessThanOrEqual(32);
});

test('a specialty icon renders at 40 px and has drawn content (a broken reference fails)', async ({ page }) => {
  await page.goto('/sanatate/');
  const svg = tile(page, 'medspec:Cardiologie').locator('svg');
  const box = await svg.boundingBox();
  expect([box.width, box.height]).toEqual([40, 40]);
  await expect(svg).toHaveAttribute('aria-hidden', 'true');
  await expect(svg).toHaveAttribute('focusable', 'false');
  // The file is loaded by the browser; getBBox of the <use> is empty when the symbol is not found.
  await expect.poll(() => svg.locator('use').evaluate((u) => { const b = u.getBBox(); return b.width * b.height; }), { timeout: 5000 }).toBeGreaterThan(100);
  // And pixels are drawn: a screenshot of the icon is not blank.
  const png = await svg.screenshot();
  const dark = await page.evaluate(async (b64) => {
    const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
    const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    const g = c.getContext('2d'); g.drawImage(img, 0, 0);
    const d = g.getImageData(0, 0, c.width, c.height).data; let n = 0;
    for (let i = 0; i < d.length; i += 4) if (d[i + 3] > 0 && d[i] + d[i + 1] + d[i + 2] < 600) n++;
    return n;
  }, png.toString('base64'));
  expect(dark, 'the icon has visible pixels').toBeGreaterThan(20);
  // Every used symbol of the page has content.
  const empty = await page.locator('main use').evaluateAll((us) => us.filter((u) => /health-icons/.test(u.getAttribute('href'))).filter((u) => { const b = u.getBBox(); return !(b.width > 0 && b.height > 0); }).length);
  expect(empty).toBe(0);
});

test('the chart: "Toate" is a stacked bar; a group shows its bars in its colour; the text and the live region say posts', async ({ page }) => {
  await page.goto('/sanatate/');
  const fig = page.locator('[data-chart]');
  const btns = fig.locator('button[data-chart-series]');
  await expect(btns).toHaveText(['Toate', 'Medic', 'Rezident', 'Asistent', 'Îngrijitor']);
  await expect(btns.first()).toBeVisible();
  for (const b of await btns.all()) { const r = await b.boundingBox(); expect(r.height, 'a target of at least 24 px').toBeGreaterThanOrEqual(24); }
  await expect(btns.first()).toHaveAttribute('aria-pressed', 'true');
  const stats = fig.locator('[data-chart-stats]'), live = fig.locator('[data-chart-live]'), other = fig.locator('[data-chart-other]');
  const total = await bars(page);
  const totalSig = await sig(page);
  const totalStats = await stats.textContent();
  expect(totalStats).toBe('min 0 · max 2 · ultima 0');
  // "Toate": 12 stacks. The posts of the fixture week by week (axis 0 to 2, so 30 high for a post): the segments of a week, with the
  // no gaps, add up to the height of the total bar. Mutation: drop "Alte ocupații" (week 1 has no bar and the sums fail).
  await expect(fig.locator('[data-chart-bars] [data-bar]')).toHaveCount(12);
  const posts = [1, 2, 0, 2, 1, 2, 1, 0, 1, 0, 2, 0];
  total.forEach((segs, i) => {
    expect(sum(segs.map((s) => s.h)), `week ${i}: the segments add up to the total bar`).toBeCloseTo(posts[i] * 30, 1);
    segs.forEach((s) => expect(s.t, 'each segment has a title: week, group, posts').toMatch(/^săpt\. \d+ [a-z]{3}, [^:]+: \d+ (de )?post(uri)?$/));
  });
  expect(total[5].map((s) => s.c), 'week 5: Medic (chart-2) under Asistent (chart-3)').toEqual(['pg-seg pg-c-1', 'pg-seg pg-c-2']);
  expect(total[1].map((s) => s.c), 'week 1: the postings of no group, "Alte ocupații"').toEqual(['pg-seg pg-c-other']);
  await expect(other).toBeVisible();
  await expect(other).toHaveText('Alte ocupații');
  expect(await other.evaluate((n) => n.tagName), 'the legend entry is not a button').not.toBe('BUTTON');
  // The squares: one for each group, none for "Toate"; the colour of the square is the colour of the bars of its group.
  await expect(btns.first().locator('.pg-chart-sq')).toHaveCount(0);
  await expect(fig.locator('button .pg-chart-sq')).toHaveCount(4);
  // Fills: "Alte ocupații" has its own fill (chart-1 at 30 %: not transparent, not white) that differs from each group colour; no segment has a
  // stroke, and segments touch (no gap). Mutations: fill none; the fill of chart-1; a stroke on .pg-seg; a 1 px gap in the script or the partial.
  const look = await fig.locator('[data-chart-bars] rect').evaluateAll((rs) => rs.map((r) => { const c = getComputedStyle(r); return { c: r.getAttribute('class'), fill: c.fill, stroke: c.stroke, sw: c.strokeWidth, y: parseFloat(r.getAttribute('y')), h: parseFloat(r.getAttribute('height')) }; }));
  look.forEach((r) => { expect(r.stroke, 'no border on a segment').toBe('none'); });
  const fills = Object.fromEntries(look.map((r) => [r.c.split(' ')[1], r.fill]));
  expect(fills['pg-c-other'], 'a fill, not transparent').toMatch(/^(rgba\(.*, 0\.3\)|color\(srgb .* \/ 0\.3\))$/);
  ['pg-c-0', 'pg-c-1', 'pg-c-2', 'pg-c-3'].forEach((k) => { if (fills[k]) expect(fills['pg-c-other'], `differs from ${k}`).not.toBe(fills[k]); });
  const sqOther = await other.locator('.pg-chart-sq').evaluate((n) => getComputedStyle(n).backgroundColor);
  expect(sqOther.replace(/[^\d.,]/g, ''), 'the legend square has the same fill').toBe(fills['pg-c-other'].replace(/[^\d.,]/g, ''));
  for (let w = 0; w < 12; w++) {
    const rects = await page.locator('[data-chart-bars] [data-bar]').nth(w).locator('rect').evaluateAll((rs) => rs.map((r) => [parseFloat(r.getAttribute('y')), parseFloat(r.getAttribute('height'))]));
    for (let k = 1; k < rects.length; k++) expect(rects[k][0] + rects[k][1], `week ${w}: segments touch`).toBeCloseTo(rects[k - 1][0], 1);
  }
  // Plain: one baseline, no grid, no line chart; a bar is half of its slot wide.
  await expect(fig.locator('svg line')).toHaveCount(1);
  await expect(fig.locator('svg polyline, svg path')).toHaveCount(0);
  const geo = await fig.locator('svg[role="img"]').evaluate((svg) => { const w = svg.getBoundingClientRect().width, r = svg.querySelector('rect').getBoundingClientRect().width; return r / (w / 12); });
  expect(geo, 'a bar is 50 % of its slot').toBeCloseTo(0.5, 1);
  await btns.nth(1).click();   // Medic: Medic specialist on 12 Aug and 2 Sep (fixture), 1 post each
  await expect(btns.nth(1)).toHaveAttribute('aria-pressed', 'true');
  await expect(btns.first()).toHaveAttribute('aria-pressed', 'false');
  expect(await fig.locator('button[aria-pressed="true"]').count(), 'one button is pressed').toBe(1);
  await expect(other, 'the legend entry belongs to "Toate"').toBeHidden();
  await expect(stats).toHaveText('min 0 · max 1 · ultima 0');
  await expect(live).toHaveText('Medic: 2 posturi în ultimele 12 săptămâni');
  // One series, in its colour. One axis for every button, from 0 to the largest week of any button (the total: 2): Medic's highest week
  // (1 post) is half of the axis (30 of 60), not the whole. Mutation: a scale for each series → 60.
  const medic = (await bars(page)).flat();
  expect(medic.length, 'two bars').toBe(2);
  medic.forEach((s) => { expect(s.c).toBe('pg-seg pg-c-1'); expect(s.h, 'half of the axis').toBe(30); expect(s.t).toMatch(/^săpt\. \d+ [a-z]{3}, Medic: 1 post$/); });
  const tallest = Math.max(...total.map((segs) => sum(segs.map((s) => s.h))));
  expect(tallest, 'the total reaches the top of the axis').toBe(60);
  const colours = await page.evaluate(() => ({ sq: getComputedStyle(document.querySelectorAll('[data-chart] button .pg-chart-sq')[0]).backgroundColor, bar: getComputedStyle(document.querySelector('[data-chart-bars] rect')).fill }));
  expect(colours.bar, 'the square has the colour of the bars').toBe(colours.sq);
  await expect(fig.locator('svg[role="img"]')).toHaveAttribute('aria-label', /Medic.*între 0 și 1 post/);
  await btns.first().click();
  expect(await sig(page), 'back to the stack').toBe(totalSig);
  await expect(stats).toHaveText(totalStats);
  await expect(live).toHaveText('Toate ocupațiile: 12 posturi în ultimele 12 săptămâni');
  await expect(other).toBeVisible();
  await noOverflow(page, 'the chart buttons');
});

test.describe('the chart without script', () => {
  test.use({ javaScriptEnabled: false });
  test('the buttons are hidden and the chart shows the total', async ({ page }) => {
    await page.goto('/sanatate/');
    await expect(page.locator('[data-chart] button[data-chart-series]').first()).toBeHidden();
    await expect(page.locator('[data-chart-stats]')).toHaveText('min 0 · max 2 · ultima 0');
  });
});

test('the specialties are in 3 columns from 1024 px, with a 40 px icon; Ocupații has no icon', async ({ page, viewport }) => {
  await page.goto('/sanatate/');
  const cols = await page.locator('#h-specialitati ~ ul').evaluate((ul) => getComputedStyle(ul).gridTemplateColumns.split(' ').length);
  expect(cols).toBe(viewport.width >= 1024 ? 3 : viewport.width >= 640 ? 2 : 1);
  const icon = await tile(page, 'medspec:Cardiologie').locator('svg').boundingBox();
  expect([icon.width, icon.height]).toEqual([40, 40]);
  await expect(page.locator('#h-ocupatii ~ ul svg')).toHaveCount(0);
  await expect(page.locator('#h-ocupatii ~ ul li').first()).toBeVisible();
});

// ---- Follow-up 2: the form on one row, the heading, and one county control for the whole page ----

test('at lg the whole form is one row: the four controls have the same top (1280 and 1024 px); the button is an icon with the name "Caută"', async ({ page }) => {
  for (const w of [1280, 1024]) {
    await page.setViewportSize({ width: w, height: 900 });
    await page.goto('/sanatate/');
    const ctl = await page.locator('[data-home-search] select, [data-home-search] button[type="submit"]').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return { t: Math.round(r.top), l: r.left, r: r.right, w: r.width, h: r.height }; }));
    expect(ctl).toHaveLength(4);
    expect(new Set(ctl.map((c) => c.t)).size, `${w} px: the four controls have the same top`).toBe(1);
    ctl.slice(0, 3).forEach((c) => expect(c.w, 'a select is wide enough').toBeGreaterThan(100));
    expect(ctl[3].w, 'the button is the icon').toBeLessThan(70);
    expect(ctl[3].h, 'a target of at least 44 px').toBeGreaterThanOrEqual(44);
    expect(ctl[3].w).toBeGreaterThanOrEqual(44);
    await expect(page.locator('[data-home-search]').getByRole('button', { name: 'Caută' })).toHaveCount(1);
    await expect(page.locator('[data-home-search] button[type="submit"] svg use')).toHaveAttribute('href', '#i-search');
    await noOverflow(page, `the form at ${w}`);
  }
});

test('the heading of the chart is hidden but in the accessibility tree; the buttons are text only', async ({ page }) => {
  await page.goto('/sanatate/');
  const cap = page.locator('[data-chart] figcaption');
  const box = await cap.boundingBox();
  expect(box.width * box.height, 'not visible').toBeLessThanOrEqual(4);
  await expect(page.getByRole('figure', { name: 'Posturi publicate pe săptămână' })).toHaveCount(1);
  await expect(page.getByText('Toată țara. Anunțuri publicate', { exact: false })).toHaveCount(0);
  const b = page.locator('.pg-chart-btn').nth(1);
  const look = () => b.evaluate((e) => { const c = getComputedStyle(e); return { bg: c.backgroundColor, bw: [c.borderTopWidth, c.borderRightWidth, c.borderBottomWidth, c.borderLeftWidth].join(' '), shadow: c.boxShadow, color: c.color }; });
  const body = await page.locator('main p:not(.g2-text-meta)').first().evaluate((e) => getComputedStyle(e).color);
  expect(await look(), 'default').toMatchObject({ bg: 'rgba(0, 0, 0, 0)', bw: '0px 0px 0px 0px', shadow: 'none' });
  await b.hover();
  expect(await look(), 'hover').toMatchObject({ bg: 'rgba(0, 0, 0, 0)', bw: '0px 0px 0px 0px', shadow: 'none' });
  expect((await look()).color, 'the colour of normal text').toBe(body);
  // The pressed one is bold and underlined (not colour alone).
  await b.click();
  await page.mouse.move(0, 0);
  expect(await look(), 'pressed').toMatchObject({ bg: 'rgba(0, 0, 0, 0)', bw: '0px 0px 0px 0px', shadow: 'none' });
  const on = await b.evaluate((e) => { const c = getComputedStyle(e); return { w: c.fontWeight, u: c.textDecorationLine }; });
  expect(Number(on.w)).toBeGreaterThanOrEqual(700); expect(on.u).toContain('underline');
  // Two targets never touch.
  const boxes = await page.locator('.pg-chart-btn:visible').evaluateAll((els) => els.map((e) => { const r = e.getBoundingClientRect(); return [r.left, r.right, r.top, r.bottom]; }));
  for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
    const [a, c] = [boxes[i], boxes[j]];
    expect(a[0] < c[1] && c[0] < a[1] && a[2] < c[3] && c[2] < a[3], 'two targets touch').toBe(false);
  }
});

const panelData = (page) => page.evaluate(() => JSON.parse(document.getElementById('theme-panel-data').textContent));
const stat = (v) => `min ${Math.min(...v)} · max ${Math.max(...v)} · ultima ${v[v.length - 1]}`;

test('a click on the map sets the select, the title and the chart; a change of the select marks the map; clearing restores the whole country', async ({ page }) => {
  await page.goto('/sanatate/');
  const d = await panelData(page);
  const h = await data(page);
  const h1 = page.locator('h1#h-theme');
  const national = { stats: await page.locator('[data-chart-stats]').textContent(), pts: await sig(page) };
  const sel = page.locator('#home-judet');
  await page.locator('button[data-county="bacau"]').click();
  await expect(sel).toHaveValue('bacau');                         // the map sets the select
  await expect(h1).toHaveText(`Posturi în sănătate → județul Bacău (${h.items.all.counties.bacau})`);
  const c = d.counties.bacau;
  await expect(page.locator('[data-chart-stats]')).toHaveText(stat(c.t));
  expect(await sig(page)).not.toBe(national.pts);
  // The stack of the county: the segments of each week add up to the county total, on the axis of the county (0 to its largest week).
  (await bars(page)).forEach((segs, w) => expect(sum(segs.map((x) => x.h)), `bacau, week ${w}`).toBeCloseTo(c.t[w] * 60 / Math.max(...c.t), 1));
  // A series of the county: its line of text is the series of the JSON.
  const withData = d.series.findIndex((s, i) => (c.s[i] || []).some((v) => v > 0));
  await page.locator('[data-chart-series]').nth(withData + 1).click();
  await expect(page.locator('[data-chart-stats]')).toHaveText(stat(c.s[withData]));
  // A change of the select marks the tile of the map and filters the chart.
  await sel.selectOption('ilfov');
  await expect(page.locator('button[data-county="ilfov"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('button[data-county="bacau"]')).toHaveAttribute('aria-pressed', 'false');
  await expect(h1).toHaveText(`Posturi în sănătate → județul Ilfov (${h.items.all.counties.ilfov})`);
  // The first option clears both.
  await sel.selectOption('');
  await expect(page.locator('button[data-county][aria-pressed="true"]')).toHaveCount(0);
  await page.locator('[data-chart-series="-1"]').click();
  await expect(page.locator('[data-chart-stats]')).toHaveText(national.stats);
  expect(await sig(page)).toBe(national.pts);
});

test('the five buttons stay; a button with a zero series for the county is hidden, and "Toate" is pressed when it was the active one', async ({ page }) => {
  await page.goto('/sanatate/');
  const d = await panelData(page);
  const zero = (slug, i) => !((d.counties[slug].s || [])[i] || []).some((v) => v > 0);
  const slug = Object.keys(d.counties).find((s) => d.series.some((_, i) => zero(s, i)));
  expect(slug, 'the fixture has a county with a zero series').toBeTruthy();
  const hide = d.series.findIndex((_, i) => zero(slug, i));
  const btn = page.locator('[data-chart-series]');
  await btn.nth(hide + 1).click();
  await expect(btn.nth(hide + 1)).toHaveAttribute('aria-pressed', 'true');
  await page.locator(`button[data-county="${slug}"]`).click();
  await expect(btn.nth(hide + 1)).toBeHidden();
  await expect(btn.first()).toHaveAttribute('aria-pressed', 'true');
  for (let i = 0; i < d.series.length; i++) {
    if (zero(slug, i)) await expect(btn.nth(i + 1)).toBeHidden(); else await expect(btn.nth(i + 1)).toBeVisible();
  }
  await expect(btn).toHaveCount(d.series.length + 1);   // the buttons are the same for every county
  await page.locator('#home-judet').selectOption('');
  await expect(btn.nth(hide + 1)).toBeVisible();
});

test('the title count equals the list of the link to all with the county; the live region announces once; the form sends the county that is shown', async ({ page, request }) => {
  await page.goto('/sanatate/');
  await page.evaluate(() => {
    window.__mut = { county: 0, chart: 0 };
    new MutationObserver((m) => { window.__mut.county += m.length; }).observe(document.querySelector('[data-home-live]'), { childList: true, characterData: true, subtree: true });
    new MutationObserver((m) => { window.__mut.chart += m.length; }).observe(document.querySelector('[data-chart-live]'), { childList: true, characterData: true, subtree: true });
  });
  await page.locator('button[data-county="ilfov"]').click();
  await expect(page.locator('[data-home-live]')).toContainText('Ilfov');
  expect(await page.evaluate(() => window.__mut), 'one announcement, and the chart says nothing').toEqual({ county: 1, chart: 0 });
  // The link to all carries the county; its count is the count of the county, outside the link; its list has that count.
  const href = await tile(page, 'all').locator('a').getAttribute('href');
  expect(href).toContain('judet%5B%5D=ilfov');
  const body = await (await request.get(href)).text();
  const m = body.match(/<span class="font-mono font-medium text-ink">([\d.]+)<\/span>\s*anun/);
  const n = m ? num(m[1]) : (/id="results-status"[^>]*>0 anun/.test(body) ? 0 : -1);
  expect(n, 'the list of the link').toBeGreaterThan(0);
  expect(paren(await page.locator('[data-title-count]').textContent()), 'the count of the title').toBe(n);
  expect(paren(await tile(page, 'all').locator('[data-tile-count]').textContent()), 'the count after the link').toBe(n);
  await expect(tile(page, 'other').locator('a')).toHaveAttribute('href', /judet%5B%5D=ilfov/);
  // The form sends the county that the reader sees.
  await page.locator('[data-home-search] button[type="submit"]').click();
  await expect(page).toHaveURL(new RegExp(`/\\?${HEALTH}&judet%5B%5D=ilfov$`));
});

test('the options of Specialitate and Ocupație do not change with the county', async ({ page }) => {
  await page.goto('/sanatate/');
  const opts = () => page.locator('#home-sel-0 option, #home-sel-1 option').allTextContents();
  const before = await opts();
  await page.locator('button[data-county="bacau"]').click();
  expect(await opts()).toEqual(before);
});

test.describe('the page without script shows the whole country', () => {
  test.use({ javaScriptEnabled: false });
  test('the title count and the chart are national', async ({ page }) => {
    await page.goto('/sanatate/');
    await expect(page.locator('h1#h-theme')).toHaveText('Posturi în sănătate (6)');
    await expect(page.locator('[data-chart-stats]')).toHaveText('min 0 · max 2 · ultima 0');
  });
});

test('the title shows the selected county: "Posturi în sănătate → Suceava"; the title of the document does not change', async ({ page }) => {
  await page.goto('/sanatate/');
  const h1 = page.locator('h1#h-theme');
  const title = await page.title();
  await expect(h1).toHaveText('Posturi în sănătate (6)');
  // The count: muted, normal weight, smaller than the title, and not in the icon.
  const st = await page.evaluate(() => { const h = getComputedStyle(document.getElementById('h-theme')); const c = getComputedStyle(document.querySelector('[data-title-count]'));
    return { hw: Number(h.fontWeight), cw: Number(c.fontWeight), hs: parseFloat(h.fontSize), cs: parseFloat(c.fontSize), hc: h.color, cc: c.color, inSvg: !!document.querySelector('[data-title-count]').closest('svg') }; });
  expect(st.cw).toBe(400); expect(st.hw).toBeGreaterThan(400);
  expect(st.cs).toBeLessThan(st.hs); expect(st.cc).not.toBe(st.hc); expect(st.inSvg).toBe(false);
  await page.locator('button[data-county="bacau"]').click();
  await expect(h1).toHaveText('Posturi în sănătate → județul Bacău (2)');
  const wb = await h1.locator('.sr-only').boundingBox();
  expect(wb.width * wb.height, 'the word "județul" is not visible').toBeLessThanOrEqual(4);
  await expect(page.getByRole('heading', { level: 1, name: 'Posturi în sănătate județul Bacău (2)' })).toHaveCount(1);   // the arrow is not in the name
  await expect(h1.locator('[aria-hidden="true"]').filter({ hasText: '→' })).toHaveCount(1);
  expect(await page.title(), 'the <title> is the same').toBe(title);
  await page.locator('#home-judet').selectOption('ilfov');
  await expect(h1).toHaveText('Posturi în sănătate → județul Ilfov (2)');
  await page.locator('#home-judet').selectOption('');
  await expect(h1).toHaveText('Posturi în sănătate (6)');
  await expect(h1.locator('[aria-hidden="true"]').filter({ hasText: '→' })).toHaveCount(0);
});

test('the link to all postings is after the line of the other employers, not in the first screen; its count equals its list, without and with a county', async ({ page, request, viewport }) => {
  await page.goto('/sanatate/');
  const hero = await page.locator('section[aria-labelledby="h-theme"]').boundingBox();
  const all = tile(page, 'all'), other = tile(page, 'other');
  await expect(all.locator('a')).toHaveText('Toate anunțurile din sănătate');
  const [a, o] = [await all.boundingBox(), await other.boundingBox()];
  expect(a.y, 'not in the first screen').toBeGreaterThan(hero.y + hero.height - 1);
  if (viewport.width >= 1024) {
    expect(Math.abs(a.y - o.y), 'one row').toBeLessThanOrEqual(2);
    expect(a.x, 'the link to all is the right column').toBeGreaterThan(o.x + 100);
  } else {
    expect(a.y, 'they stack').toBeGreaterThanOrEqual(o.y + o.height - 1);
  }
  const count = async (href) => { const body = await (await request.get(href)).text(); const m = body.match(/<span class="font-mono font-medium text-ink">([\d.]+)<\/span>\s*anun/); return m ? num(m[1]) : 0; };
  for (const link of [all, other]) expect(paren(await link.locator('[data-tile-count]').textContent())).toBe(await count(await link.locator('a').getAttribute('href')));
  await page.locator('button[data-county="bacau"]').click();
  for (const link of [all, other]) {
    const href = await link.locator('a').getAttribute('href');
    expect(href).toContain('judet%5B%5D=bacau');
    expect(paren(await link.locator('[data-tile-count]').textContent()), 'the count of the county').toBe(await count(href));
  }
  expect(paren(await all.locator('[data-tile-count]').textContent())).toBe(2);
});

// ---- The themes /administratie/ and /financiar/ (tests/fixtures/professional_db.php, own port) ----
// Administrație: 10 postings (cluj 4, ilfov 6); Tip funcție: execuție 7, conducere 3; Angajatori: scoala 5, primarie 3, spital 2.
// Financiar: 12 postings (cluj 2, ilfov 10); Tip funcție: execuție 10, conducere 2; Programe: EDUSAL 3, FOREXEBUG 2, SIIIR 1.
const PROFESSIONAL = {
  administratie: {
    h1: 'Posturi în administrație', n: 10, base: 'family%5B%5D=administra%C8%9Bie',
    sections: ['h-theme', 'h-tip-functie', 'h-ocupatii', 'h-angajatori', 'h-domenii', 'h-studii', 'h-noi', 'h-notificari'],
    level: ['Funcții de execuție', 'Funcții de conducere'], inst: ['scoala', 'primarie', 'spital'], cluj: 4,
  },
  financiar: {
    h1: 'Posturi financiar-contabile', n: 12, base: 'family%5B%5D=financiar',
    sections: ['h-theme', 'h-tip-functie', 'h-ocupatii', 'h-angajatori', 'h-programe', 'h-studii', 'h-noi', 'h-notificari'],
    level: ['Funcții de execuție', 'Funcții de conducere'], inst: ['scoala', 'spital', 'primarie', 'cultura'], cluj: 2,
  },
};
for (const [slug, c] of Object.entries(PROFESSIONAL)) {
  test.describe(`/${slug}/`, () => {
    test.use({ baseURL: 'http://127.0.0.1:8827' });

    test('the sections are present in order, the items are in order of count; no horizontal overflow', async ({ page }) => {
      await page.goto(`/${slug}/`);
      await expect(page.locator('h1#h-theme')).toHaveText(`${c.h1} (${c.n})`);
      const ids = await page.locator('main section[aria-labelledby]').evaluateAll((els) => els.map((e) => e.getAttribute('aria-labelledby')));
      expect(ids).toEqual(c.sections);
      const tops = await page.locator('main section[aria-labelledby]').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top + window.scrollY));
      tops.forEach((t, i) => { if (i) expect(t, `section ${c.sections[i]} below ${c.sections[i - 1]}`).toBeGreaterThan(tops[i - 1]); });
      // order => count: the largest first, not the order of the labels.
      const level = await page.locator('section[aria-labelledby="h-tip-functie"] li[data-home-tile] a').allTextContents();
      expect(level).toEqual(c.level);
      const inst = await page.locator('section[aria-labelledby="h-angajatori"] li[data-home-tile]').evaluateAll((els) => els.map((e) => e.dataset.domain.replace('inst:', '')));
      expect(inst).toEqual(c.inst);
      await expect(page.locator('[data-domain="other"]')).toHaveCount(0);
      await noOverflow(page, `/${slug}/`);
      const wide = await page.evaluate(() => Array.from(document.querySelectorAll('main li, main a')).filter((el) => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1).length);
      expect(wide, 'items that stick out of the page').toBe(0);
    });

    test('an item opens a list with the same count, also inside a county', async ({ page }) => {
      for (const id of ['level:Funcții de conducere', 'inst:scoala']) {
        await page.goto(`/${slug}/`);
        const n = paren(await tile(page, id).locator('[data-tile-count]').textContent());
        await tile(page, id).locator('a').click();
        await expect(page).toHaveURL(new RegExp(`/\\?${c.base}`));
        expect(num(await page.locator('#results-status').textContent().then((t) => t.match(/[\d.]+/)[0])), id).toBe(n);
      }
      await page.goto(`/${slug}/`);
      await page.locator('button[data-county="cluj"]').click();
      await expect(page.locator('h1#h-theme')).toHaveText(new RegExp(`\\(${c.cluj}\\)$`));
      const inCluj = paren(await tile(page, 'level:Funcții de execuție').locator('[data-tile-count]').textContent());
      await tile(page, 'level:Funcții de execuție').locator('a').click();
      expect(num(await page.locator('#results-status').textContent().then((t) => t.match(/[\d.]+/)[0]))).toBe(inCluj);
    });

    test('the select Instituție shows the text and sends the value', async ({ page }) => {
      await page.goto(`/${slug}/`);
      const opt = page.locator('#home-sel-1 option[value="primarie"]');
      await expect(opt).toHaveText('Primării și consilii locale');
      await page.locator('#home-sel-1').selectOption('primarie');
      await page.locator('[data-home-search] button[type="submit"]').click();
      await expect(page).toHaveURL(new RegExp(`/\\?${c.base}&inst%5B%5D=primarie$`));
    });
  });
}

test.describe('/financiar/ programs', () => {
  test.use({ baseURL: 'http://127.0.0.1:8827' });
  test('the programs are in order of count; "FOREXEBUG" counts both spellings and its list has the same count', async ({ page }) => {
    await page.goto('/financiar/');
    const ids = await page.locator('section[aria-labelledby="h-programe"] li[data-home-tile]').evaluateAll((els) => els.map((e) => e.dataset.domain));
    expect(ids).toEqual(['skill:EDUSAL', 'skill:FOREXEBUG', 'skill:SIIIR']);
    expect(paren(await tile(page, 'skill:FOREXEBUG').locator('[data-tile-count]').textContent())).toBe(2);
    await tile(page, 'skill:FOREXEBUG').locator('a').click();
    expect(num(await page.locator('#results-status').textContent().then((t) => t.match(/[\d.]+/)[0]))).toBe(2);
  });
});

// ---- The button "Toate județele" after the title ----
test.describe('the button "Toate județele"', () => {
  const clear = (page) => page.locator('[data-county-clear]');

  test('hidden with no county; visible after a map click and after a select change; a click clears the county', async ({ page }) => {
    await page.goto('/sanatate/');
    await expect(clear(page)).toBeHidden();
    const nationalStats = await page.locator('[data-chart-stats]').textContent();
    // After a click on the map.
    await page.locator('button[data-county="cluj"]').click();
    await expect(clear(page)).toBeVisible();
    await expect(clear(page)).toHaveText('Toate județele');
    await expect(page.locator('h1#h-theme')).toHaveText(/→.*Cluj.*\(2\)$/);
    await expect(page).toHaveURL(/#cluj$/);
    // Not in the heading, and the icon is decoration.
    await expect(page.locator('h1 button')).toHaveCount(0);
    await expect(clear(page).locator('svg[aria-hidden="true"]')).toHaveCount(1);
    await clear(page).click();
    await expect(clear(page)).toBeHidden();
    await expect(page.locator('h1#h-theme')).toHaveText('Posturi în sănătate (6)');
    await expect(page.locator('#home-judet')).toHaveValue('');
    await expect(page.locator('button[data-county][aria-pressed="true"]')).toHaveCount(0);
    expect(new URL(page.url()).hash).toBe('');
    await expect(page.locator('[data-chart-stats]')).toHaveText(nationalStats);
    await expect(page.locator('#home-judet')).toBeFocused();
    // After a change of the select.
    await page.locator('#home-judet').selectOption('bacau');
    await expect(clear(page)).toBeVisible();
    await page.locator('#home-judet').selectOption('');
    await expect(clear(page)).toBeHidden();
  });

  test('a reload with a county in the fragment shows the button; no horizontal overflow', async ({ page }) => {
    await page.goto('/sanatate/#cluj');
    await expect(clear(page)).toBeVisible();
    await noOverflow(page, '/sanatate/#cluj');
    // On one line from sm, below the title on a narrow screen: the button never sticks out of the page.
    const r = await clear(page).evaluate((b) => { const r = b.getBoundingClientRect(); return { right: r.right, w: document.documentElement.clientWidth }; });
    expect(r.right).toBeLessThanOrEqual(r.w);
  });

  test('without script the button is not shown', async ({ browser, baseURL, viewport }) => {
    const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL, viewport });
    const p = await ctx.newPage();
    await p.goto('/sanatate/');
    await expect(clear(p)).toBeHidden();
    await ctx.close();
  });
});
