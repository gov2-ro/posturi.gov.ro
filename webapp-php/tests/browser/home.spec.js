// UX-05 browser checks: the landing page on `/`, the unfiltered list on `/posturi/`
// (ADR 0004), the county map, the hero form, the navigation. Runs at 320, 375 and
// 1280 (playwright.config.js) against the fixture (tests/fixtures/build_db.php).
// Fixture clock 2026-10-03. The fixture has five professional domains (see DOMAINS_IN_FIXTURE
// in tests/home_test.php); a county selected on the map changes their counts and links.
const { test, expect } = require('@playwright/test');

const SECTIONS = ['h-hero', 'h-combo', 'h-dom', 'h-start', 'h-now', 'h-follow', 'h-tools'];

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
  page.on('dialog', (d) => { errs.push('dialog: ' + d.message()); d.dismiss(); });
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}
const data = (page) => page.evaluate(() => JSON.parse(document.getElementById('home-data').textContent));
const tile = (page, domain) => page.locator(`[data-home-tile][data-domain="${domain}"]`);
/** The canonical list URL (ADR 0001) of a domain from #home-data, with an optional county. */
const domainHref = (dom, county) => {
  const p = [];
  if (county) p.push(['judet', county]);
  Object.entries(dom.params).forEach(([field, vals]) => Object.values(vals).forEach((v) => p.push([field.replace(/\[\]$/, ''), v])));
  p.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : a[1] < b[1] ? -1 : 1));
  return '/?' + p.map(([k, v]) => `${encodeURIComponent(k)}%5B%5D=${encodeURIComponent(v)}`).join('&');
};
const domTotal = (dom) => Object.values(dom.counties).reduce((a, b) => a + b, 0);
// A domain with a themed page links to it (the county goes in the fragment); the others link to the list.
const THEMED = { sanatate: 'sanatate', administratie: 'administratie', financiar: 'financiar', social: 'social', educatie: 'social' };   // domain key => theme slug
const hrefOf = (d, key, county) => (THEMED[key] ? `/${THEMED[key]}/` + (county ? '#' + county : '') : domainHref(d.items[key], county));
const tileLink = (page, sector) => tile(page, sector).locator('a');
/** "(1.197)" → 1197 */
const paren = (text) => parseInt(text.replace(/\D/g, ''), 10);
/** "1.197 de posturi" → 1197 */
const num = (text) => parseInt(text.replace(/\./g, ''), 10);
/** posturi_label() of helpers.php */
const posts = (n) => (n === 1 ? '1 post' : (n > 0 && (n % 100 === 0 || n % 100 >= 20)) ? `${n} de posturi` : `${n} posturi`);
const listCount = async (page) => num(await page.locator('#results-status').textContent());

test('the seven sections are present in order; no horizontal overflow', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/^Anunțuri de angajare în administrația publică/);
  const ids = await page.locator('main section[aria-labelledby]').evaluateAll((els) => els.map((e) => e.getAttribute('aria-labelledby')));
  expect(ids).toEqual(SECTIONS);
  await expect(page.locator('section#domenii')).toHaveCount(1);
  await expect(page.locator('h1')).toHaveText('Anunțuri de angajare în administrația publică');
  // Document order is the visual order: each section starts below the one before it.
  const tops = await page.locator('main section[aria-labelledby]').evaluateAll((els) => els.map((e) => e.getBoundingClientRect().top + window.scrollY));
  tops.forEach((t, i) => { if (i) expect(t, `section ${SECTIONS[i]} below ${SECTIONS[i - 1]}`).toBeGreaterThan(tops[i - 1]); });
  await noOverflow(page, 'landing page');
  // Every link and button of the page is at least 44 px high, except the county cells and the link lists of the footer
  // (buttons in a map: at least 24 px each way, WCAG 2.5.8).
  // Inline text links in running lines (the examples and the starting-point groups) have their own checks.
  const small = await page.evaluate(() => Array.from(document.querySelectorAll('main a, main button, header a, footer a'))
    .filter((el) => !el.closest('[data-home-js]') || !el.matches('[data-county]'))
    .filter((el) => !el.matches('[data-home-link]') && !el.closest('[data-home-example], [data-home-theme-example]'))
    .filter((el) => !el.closest('footer ul'))   // the link lists of the footer have their own check (24 px, no overlap)
    .filter((el) => el.getClientRects().length && getComputedStyle(el).visibility !== 'hidden')
    .map((el) => ({ t: (el.textContent || '').trim().slice(0, 30), h: el.getBoundingClientRect().height }))
    .filter((o) => o.h < 43.5));
  expect(small, 'targets under 44 px').toEqual([]);
});

test('the four navigation links are visible and wrap at 320 px; "Salvate" has a bell and the saved count', async ({ page, viewport }) => {
  for (const url of ['/', '/posturi/']) {
    await page.goto(url);
    const links = page.locator('nav[aria-label="Navigare principală"] a');
    // No "Alerte" item in the masthead: /salvate/ carries the way to the alerts.
    await expect(links).toHaveText(['Posturi', /^Salvate/, 'Statistici', 'Despre']);
    await expect(links).toHaveCount(4);
    expect(await links.evaluateAll((els) => els.map((e) => e.getAttribute('href')))).toEqual(['/posturi/', '/salvate/', '/statistici/', '/despre/']);
    for (let i = 0; i < 4; i++) {
      await expect(links.nth(i)).toBeVisible();
      const b = await links.nth(i).boundingBox();
      expect(b.height).toBeGreaterThanOrEqual(44);
      expect(b.x + b.width, `link ${i} inside the viewport`).toBeLessThanOrEqual(viewport.width + 1);
      expect(b.x).toBeGreaterThanOrEqual(0);
    }
    // The bell is decoration, before the text of "Salvate"; none of the other items has an icon.
    const bell = links.nth(1).locator('svg');
    await expect(bell).toHaveCount(1);
    await expect(bell).toHaveAttribute('aria-hidden', 'true');
    await expect(bell.locator('use')).toHaveAttribute('href', '#i-bell');
    expect((await bell.boundingBox()).width).toBeGreaterThan(8);
    await expect(links.nth(0).locator('svg')).toHaveCount(0);
    await expect(page.locator('header a[href="/"]')).toContainText('posturi');
    await noOverflow(page, url + ' header');
  }
  // The saved count still updates in the item (saved.js).
  await page.goto('/posturi/');
  const count = page.locator('nav[aria-label="Navigare principală"] [data-saved-count]');
  await expect(count).toHaveText('(0)');
  await page.locator('[data-pref="save"]').first().click();
  await expect(count).toHaveText('(1)');
});

test('footer: four groups of links, then the disclaimer; no skin picker', async ({ page }) => {
  await page.goto('/');
  const foot = page.locator('footer');
  await expect(foot.locator('h2')).toHaveText(['Posturi', 'Pagini tematice', 'Urmărește', 'Proiect']);
  await expect(foot.locator('ul li')).toHaveCount(16);   // 12 fixed links + the four themed pages (their own group)
  const order = await foot.evaluate((f) => {
    const q = (s) => f.querySelector(s);
    const pos = (el) => el.getBoundingClientRect().top + window.scrollY;
    return [pos(q('h2')), pos(q('p'))];
  });
  expect(order[0]).toBeLessThan(order[1]);
  await expect(foot.locator('select, #skin-picker')).toHaveCount(0);
  await expect(foot).toContainText('nu este un proiect oficial');
});

test('hero form: empty goes to /posturi/; a filled form goes to the canonical list URL', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('button', { name: 'Caută', exact: true }).click();
  await expect(page).toHaveURL(/\/posturi\/$/);
  await expect(page.locator('#filter-form')).toBeVisible();

  await page.goto('/');
  await page.locator('#home-q').fill('  medic ');
  await page.getByRole('button', { name: 'Caută', exact: true }).click();
  await expect(page).toHaveURL(/\/\?q=medic$/);

  await page.goto('/');
  await page.locator('#home-judet').selectOption('cluj');
  await page.locator('#home-q').fill('asistent');
  await page.locator('#home-q').press('Enter');
  await expect(page).toHaveURL(/\/\?judet%5B%5D=cluj&q=asistent$/);
  await expect(page.locator('#filter-form')).toBeVisible();
});

test('a click on a county button sets the counts and the links; a second click clears; the select follows', async ({ page }) => {
  await page.goto('/');
  const d = await data(page);
  const cluj = page.locator('button[data-county="cluj"]');
  const select = page.locator('#domenii-judet');
  const live = page.locator('[data-home-live]');
  await expect(cluj).toBeVisible();
  await expect(select).toBeVisible();

  // All counties: the totals.
  const orderAll = await page.locator('[data-home-tile]').evaluateAll((els) => els.map((e) => e.dataset.domain));
  await expect(tile(page, 'sanatate')).toBeVisible();
  expect(paren(await tile(page, 'sanatate').locator('[data-tile-count]').textContent())).toBe(domTotal(d.items.sanatate));
  await expect(tileLink(page, 'sanatate')).toHaveAttribute('href', hrefOf(d, 'sanatate'));

  await cluj.click();
  await expect(cluj).toHaveAttribute('aria-pressed', 'true');
  await expect(select).toHaveValue('cluj');
  await expect(live).toHaveText(`Cluj: ${posts(d.counties.cluj)} active`);
  // The tiles with a posting in Cluj show its count and link to county × domain; the others are hidden.
  for (const key of Object.keys(d.items)) {
    const n = (d.items[key].counties || {}).cluj || 0;
    if (n === 0) {
      await expect(tile(page, key), `${key} hidden in Cluj`).toBeHidden();
    } else {
      await expect(tile(page, key)).toBeVisible();
      expect(paren(await tile(page, key).locator('[data-tile-count]').textContent())).toBe(n);
      await expect(tileLink(page, key)).toHaveAttribute('href', hrefOf(d, key, 'cluj'));
    }
  }
  // Every county of the data: each visible tile has its count and its own link (family and cor).
  for (const county of Object.keys(d.counties)) {
    await select.selectOption(county);
    for (const key of Object.keys(d.items)) {
      const n = (d.items[key].counties || {})[county] || 0;
      if (n === 0) { await expect(tile(page, key), `${key} hidden in ${county}`).toBeHidden(); continue; }
      await expect(tileLink(page, key), `${key} in ${county}`).toHaveAttribute('href', hrefOf(d, key, county));
      expect(paren(await tile(page, key).locator('[data-tile-count]').textContent()), `${key} count in ${county}`).toBe(n);
    }
  }
  await select.selectOption('cluj');
  // The order of the tiles does not change.
  expect(await page.locator('[data-home-tile]').evaluateAll((els) => els.map((e) => e.dataset.domain))).toEqual(orderAll);
  // The selected cell has a ring that is not colour alone: an outline and a heavier border.
  const ring = await cluj.evaluate((el) => { const s = getComputedStyle(el); return { o: s.outlineStyle, ow: parseFloat(s.outlineWidth), bw: parseFloat(s.borderTopWidth) }; });
  expect(ring.o).toBe('solid');
  expect(ring.ow).toBeGreaterThanOrEqual(2);
  expect(ring.bw).toBeGreaterThanOrEqual(2);

  // The link is the canonical URL and shows as many postings as the tile says.
  const first = page.locator('[data-home-tile]:visible').first();
  const shown = paren(await first.locator('[data-tile-count]').textContent());
  await first.locator('a').click();
  await expect(page).toHaveURL(/\/\?(cor%5B%5D=\d&)*family%5B%5D=[^&]+&judet%5B%5D=cluj$|\/\?judet%5B%5D=cluj&family%5B%5D=[^&]+$/);
  expect(await listCount(page)).toBe(shown);

  // A second click on the same button clears the selection.
  await page.goto('/');
  await cluj.click();
  await cluj.click();
  await expect(cluj).toHaveAttribute('aria-pressed', 'false');
  await expect(select).toHaveValue('');
  await expect(tile(page, 'sanatate')).toBeVisible();
  await expect(tileLink(page, 'sanatate')).toHaveAttribute('href', hrefOf(d, 'sanatate'));
  await expect(live).toContainText('Toate județele:');

  // The select drives the map, and the map the select.
  await select.selectOption('bacau');
  await expect(page.locator('button[data-county="bacau"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(cluj).toHaveAttribute('aria-pressed', 'false');
  await expect(tile(page, 'sanatate')).toBeHidden();
  await expect(tile(page, 'administratie')).toBeVisible();
  await page.locator('button[data-county="cluj"]').click();
  await expect(select).toHaveValue('cluj');
  await noOverflow(page, 'landing page with a county');
});

test('the map: 42 counties with a label, fits the width, cells are tappable', async ({ page, viewport }) => {
  await page.goto('/');
  const cells = page.locator('button[data-county]');
  await expect(cells).toHaveCount(42);
  const rect = await page.locator('.pg-map').boundingBox();
  expect(rect.x + rect.width).toBeLessThanOrEqual(viewport.width + 1);
  const sizes = await cells.evaluateAll((els) => els.map((e) => { const b = e.getBoundingClientRect(); return [b.width, b.height]; }));
  for (const [w, h] of sizes) { expect(w).toBeGreaterThanOrEqual(24); expect(h).toBeGreaterThanOrEqual(24); }
  const labels = await cells.evaluateAll((els) => els.map((e) => [e.getAttribute('aria-label'), e.title, e.textContent.trim()]));
  for (const [a, t, c] of labels) { expect(a).toMatch(/^.+: \d[\d.]* (de )?posturi?$|^.+: 0 posturi$/); expect(t).toBe(a); expect(c).toMatch(/^[A-Z]{1,2}$/); }
  // The cells do not overlap each other.
  const boxes = await cells.evaluateAll((els) => els.map((e) => { const b = e.getBoundingClientRect(); return [b.left, b.top, b.right, b.bottom]; }));
  for (let i = 0; i < boxes.length; i++) for (let j = i + 1; j < boxes.length; j++) {
    const [a, b] = [boxes[i], boxes[j]];
    const overlap = a[0] < b[2] - 0.5 && b[0] < a[2] - 0.5 && a[1] < b[3] - 0.5 && b[1] < a[3] - 0.5;
    expect(overlap, `cells ${i} and ${j} overlap`).toBe(false);
  }
});

test('the text on each map step has at least 4.5:1 contrast', async ({ page }, testInfo) => {
  await page.goto('/');
  const ratios = await page.evaluate(() => {
    const cells = Array.from(document.querySelectorAll('button[data-county]')).slice(0, 4);
    const lin = (c) => { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); };
    const lum = (rgb) => 0.2126 * lin(rgb[0]) + 0.7152 * lin(rgb[1]) + 0.0722 * lin(rgb[2]);
    const parse = (s) => (s.match(/[\d.]+/g) || []).map(Number);
    return cells.map((c, step) => {
      c.setAttribute('data-step', String(step));
      const cs = getComputedStyle(c);
      const base = parse(cs.backgroundColor).slice(0, 3);
      const g = cs.backgroundImage.match(/rgba?\([^)]*\)/);
      const gp = g ? parse(g[0]) : [0, 0, 0, 0];
      const a = gp.length > 3 ? gp[3] : 1;
      const bg = base.map((b, i) => gp[i] * a + b * (1 - a));
      const fg = parse(cs.color).slice(0, 3);
      const [l1, l2] = [lum(fg), lum(bg)];
      return +((Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05)).toFixed(2);
    });
  });
  ratios.forEach((r, step) => expect(r, `step ${step}`).toBeGreaterThanOrEqual(4.5));
  testInfo.annotations.push({ type: 'contrast', description: JSON.stringify(ratios) });
  console.log('CONTRAST ' + JSON.stringify(ratios));
});

test('landing changes: no border on the figures panel and the example cards; the count is not underlined', async ({ page }) => {
  await page.goto('/');
  const borders = (sel) => page.locator(sel).evaluateAll((els) => els.map((el) => {
    const s = getComputedStyle(el);
    return ['Top', 'Right', 'Bottom', 'Left'].map((d) => parseFloat(s['border' + d + 'Width']) * (s['border' + d + 'Style'] === 'none' ? 0 : 1)).reduce((a, b) => a + b, 0);
  }));
  expect(await borders('[data-home-figures]')).toEqual([0]);
  const cards = await borders('[data-home-example]');
  expect(cards.length).toBeGreaterThan(0);
  cards.forEach((w) => expect(w).toBe(0));
  await expect(page.locator('#h-combo')).toHaveText('Exemple de căutare');
  // Domain items are plain lines: no box, no background.
  expect(await page.locator('[data-home-tile]').first().evaluate((el) => parseFloat(getComputedStyle(el).borderTopWidth))).toBe(0);
  await expect(page.locator('[data-home-tile].g2-tile')).toHaveCount(0);
  // Start links: the link is underlined, the count is not; each target is 44 px high.
  const link = page.locator('[data-home-link]').first();
  const count = page.locator('[data-home-count]').first();
  expect(await link.evaluate((el) => getComputedStyle(el).textDecorationLine)).toContain('underline');
  expect(await count.evaluate((el) => getComputedStyle(el).textDecorationLine)).not.toContain('underline');
  expect(await count.evaluate((el) => !!el.closest('a'))).toBe(false);
});

test('"Șterge tot" leaves the URL /posturi/, and a reload shows the list', async ({ page }) => {
  await page.goto('/?judet%5B%5D=cluj&sector%5B%5D=sanatate');
  await expect(page.locator('#filter-form')).toBeVisible();
  await page.getByRole('link', { name: 'Șterge tot' }).click();
  await expect(page).toHaveURL(/\/posturi\/$/);
  await expect(page.locator('#filter-form')).toBeVisible();
  await page.reload();
  await expect(page).toHaveURL(/\/posturi\/$/);
  await expect(page.locator('#filter-form')).toBeVisible();
  await expect(page.locator('h1')).toHaveText('Posturi în sectorul public');
});

test('removing the last filter chip pushes /posturi/ (HX-Push-Url), and a reload stays on the list', async ({ page }) => {
  await page.goto('/?judet%5B%5D=cluj');
  await page.locator('[data-active-chips] [data-chip-param="judet"]').first().click();
  await expect(page).toHaveURL(/\/posturi\/$/);
  await expect(page.locator('[data-active-chips]')).toHaveCount(0);
  await page.reload();
  await expect(page.locator('#filter-form')).toBeVisible();
  // Typing into the search box and clearing it again goes back to /posturi/ too.
  await page.locator('input[name="q"]').first().fill('asistent');
  await expect(page).toHaveURL(/\?q=asistent/);
  await page.locator('input[name="q"]').first().fill('');
  await expect(page).toHaveURL(/\/posturi\/$/);
  // Pagination on the unfiltered list works from /posturi/.
  const next = page.locator('a[rel="next"]').first();
  if (await next.count()) {
    await next.click();
    await expect(page).toHaveURL(/[?&]page=2/);
    await expect(page.locator('#filter-form')).toBeVisible();
    await expect(page.locator('li[data-posting]').first()).toBeVisible();
  }
});

test('with a saved search in localStorage, bare / shows the landing page (no redirect)', async ({ page, context }) => {
  const key = 'judet%5B%5D=cluj';
  await page.goto('/despre/');
  await page.evaluate((k) => localStorage.setItem('posturi.searches.v1', JSON.stringify({
    version: 1, defaultId: k, searches: { [k]: { key: k, qs: k, sort: '', label: 'Cluj', savedAt: '2026-10-01T10:00:00.000Z', openedAt: null } },
  })), key);
  for (const attempt of [1, 2]) {
    const fresh = await context.newPage();       // a new page: an empty sessionStorage, as a new session
    fresh.on('pageerror', (e) => errors.get(page).push(e.message));
    await fresh.goto('/');
    await expect(fresh.locator('h1')).toHaveText('Anunțuri de angajare în administrația publică');
    await expect(fresh).toHaveURL(/\/$/);
    expect(await fresh.evaluate(() => sessionStorage.getItem('posturi.searchRedirect')), `visit ${attempt}`).toBeNull();
    await expect(fresh.locator('html')).toHaveCSS('visibility', 'visible');
    await fresh.close();
  }
});

test('without JavaScript: the select and the map are hidden, the tiles and the form work', async ({ browser, baseURL, viewport }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL, viewport });
  const page = await context.newPage();
  await page.goto('/');
  await expect(page.locator('#domenii-judet')).toBeHidden();
  await expect(page.locator('button[data-county]').first()).toBeHidden();
  await expect(tile(page, 'sanatate')).toBeVisible();
  await expect(tileLink(page, 'sanatate')).toHaveAttribute('href', '/sanatate/');
  // An empty form: the server sends the reader to the unfiltered list.
  await page.getByRole('button', { name: 'Caută', exact: true }).click();
  await expect(page).toHaveURL(/\/posturi\/$/);
  await expect(page.locator('#filter-form')).toBeVisible();
  // A search without a county: the blank county is dropped, and the list is not empty.
  await page.goto('/');
  await page.locator('#home-q').fill('asistent');
  await page.getByRole('button', { name: 'Caută', exact: true }).click();
  await expect(page).toHaveURL(/\/\?q=asistent$/);
  await expect(page.locator('li[data-posting]').first()).toBeVisible();
  await context.close();
});

test('/posturi/ is the list at each width: no overflow, the list script works', async ({ page }) => {
  await page.goto('/posturi/');
  await expect(page.locator('#filter-form')).toBeVisible();
  await expect(page.locator('#urmareste')).toBeVisible();     // "Urmărește anunțurile noi" on the unfiltered list
  await expect(page.locator('[data-feed="posturi.rss"]').first()).toHaveAttribute('href', '/posturi.rss');
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute('href', /\/posturi\/$/);
  await noOverflow(page, '/posturi/');
  await page.goto('/posturi/?q=asistent');
  await expect(page).toHaveURL(/\/\?q=asistent$/);          // 301 to the one URL of a filtered list
});

test('a domain tile opens a list with as many postings as the tile says', async ({ page }) => {
  await page.goto('/');
  const t = page.locator('[data-home-tile]').first();
  const shown = paren(await t.locator('[data-tile-count]').textContent());
  const themed = await t.getAttribute('data-theme-href');
  await t.locator('a').click();
  // A tile with a themed page opens that page, whose h1 carries the same count.
  if (themed) {
    await expect(page).toHaveURL(new RegExp(themed + '$'));
    expect(paren(await page.locator('h1 [data-title-count]').textContent())).toBe(shown);
    return;
  }
  await expect(page).toHaveURL(/\/\?(cor%5B%5D=\d&)*family%5B%5D=[^&]+$/);
  expect(await listCount(page)).toBe(shown);
});

test('each visible domain item is one line: a 32 px icon, the name as a link, the count in brackets outside it', async ({ page }) => {
  await page.goto('/');
  const items = await page.locator('[data-home-tile]:visible').evaluateAll((els) => els.map((el) => {
    const svg = el.querySelector('svg');
    const link = el.querySelector('a');
    const count = el.querySelector('[data-tile-count]');
    const r = svg.getBoundingClientRect();
    const box = el.getBoundingClientRect();
    return { sector: el.dataset.domain, w: r.width, h: r.height, color: getComputedStyle(svg).color, ink: getComputedStyle(el).color,
             muted: getComputedStyle(count).color, iconBeforeLink: r.right <= link.getBoundingClientRect().left + 1,
             centre: Math.abs((r.top + r.height / 2) - (box.top + box.height / 2)), height: box.height, linkH: link.getBoundingClientRect().height,
             countInLink: !!count.closest('a'), border: getComputedStyle(el).borderTopWidth, bg: getComputedStyle(el).backgroundColor,
             text: count.textContent.trim(), nameFont: parseFloat(getComputedStyle(link).fontSize), countFont: parseFloat(getComputedStyle(count).fontSize) };
  }));
  expect(items.length, 'visible sector items').toBeGreaterThan(0);
  for (const t of items) {
    expect(t.w, `${t.sector} icon width`).toBe(32);
    expect(t.h, `${t.sector} icon height`).toBe(32);
    expect(t.color, `${t.sector} icon colour is the text colour`).toBe(t.ink);
    expect(t.muted, `${t.sector} count colour is not the text colour`).not.toBe(t.ink);
    expect(t.iconBeforeLink, `${t.sector} icon before the name`).toBe(true);
    expect(t.centre, `${t.sector} icon vertically centred`).toBeLessThanOrEqual(2);
    expect(t.linkH, `${t.sector} link target`).toBeGreaterThanOrEqual(44);
    expect(t.countInLink, `${t.sector} count outside the link`).toBe(false);
    expect(t.text, `${t.sector} count text`).toMatch(/^\([\d.]+\)$/);
    expect(t.nameFont, `${t.sector} name is one step above the count`).toBeGreaterThan(t.countFont);
    expect(t.height, `${t.sector} is one line high`).toBeLessThanOrEqual(44.5);
    expect(t.border).toBe('0px');
  }
  await noOverflow(page, 'landing page with icons');
});

test('the county select shows the count of the map in each option; the domain counts follow the county', async ({ page }) => {
  await page.goto('/');
  const map = await page.locator('button[data-county]').evaluateAll((els) => Object.fromEntries(els.map((e) => [e.dataset.county, e.getAttribute('aria-label').split(': ')[1]])));
  const d = await data(page);
  for (const id of ['#home-judet', '#domenii-judet']) {
    const opts = await page.locator(`${id} option`).evaluateAll((els) => els.map((e) => ({ v: e.value, name: e.dataset.name, text: e.textContent.trim() })));
    expect(opts[0].text, `${id} first option has no count`).toBe('Toate județele');
    for (const o of opts.slice(1)) {
      expect(paren(o.text), `${id} ${o.v}: option count equals the map`).toBe(num(map[o.v]));
      expect(o.text.startsWith(`${o.name} (`), `${id} ${o.v}: name, then the count`).toBe(true);
    }
  }
  // The count of an option is the same after a county is chosen, and the live text has no bracket.
  // Poison each sector count, so that only a script that writes the count again can pass.
  await page.locator('[data-tile-count]').evaluateAll((els) => els.forEach((el) => { el.textContent = '(999)'; }));
  await page.locator('#domenii-judet').selectOption('cluj');
  for (const [key, dom] of Object.entries(d.items)) {
    if (dom.counties.cluj) await expect(tile(page, key).locator('[data-tile-count]')).toHaveText(new RegExp(`^\\s*\\(${dom.counties.cluj}\\)$`));
  }
  await expect(page.locator('[data-home-live]')).toHaveText(`Cluj: ${posts(d.counties.cluj)} active`);
  await expect(page.locator('#domenii-judet option[value="cluj"]')).toContainText(`(${d.counties.cluj})`);
});

test('the counts of the hero link and of the examples are in brackets, outside the link', async ({ page }) => {
  await page.goto('/');
  const all = page.locator('[data-home-all-count]');
  expect(await all.evaluate((el) => !!el.closest('a'))).toBe(false);
  expect((await all.textContent()).trim()).toMatch(/^\([\d.]+\)$/);
  await expect(page.locator('a', { hasText: 'Toate anunțurile' })).toHaveAttribute('href', '/posturi/');
  const ex = page.locator('[data-example-count]');
  expect(await ex.count()).toBeGreaterThan(0);
  expect(await ex.evaluateAll((els) => els.filter((el) => el.closest('a')).length)).toBe(0);
  await expect(page.locator('h3', { hasText: 'După județ' })).toHaveCount(0);
});

// ---- Follow-ups 1 and 2 (2026-11-17) ----

test('an example count follows the last word of its title, on the same line, at any width', async ({ page }) => {
  await page.goto('/');
  // The count must never start a line alone: its vertical centre is the centre of the last line of the title.
  // The container is narrowed step by step, so that some width puts the last word at the end of a line.
  const stranded = await page.evaluate(() => {
    const out = [];
    const items = Array.from(document.querySelectorAll('[data-home-example]'));
    for (let w = 160; w <= 460; w += 3) {
      for (const li of items) {
        li.style.width = w + 'px';
        const link = li.querySelector('h3 a');
        const count = li.querySelector('[data-example-count]');
        const lastLink = Array.from(link.getClientRects()).pop();
        const c0 = count.getClientRects()[0];
        if (Math.abs((lastLink.top + lastLink.height / 2) - (c0.top + c0.height / 2)) > 8) out.push(w + 'px: ' + link.textContent.trim().slice(0, 30));
      }
    }
    items.forEach((li) => { li.style.width = ''; });
    return out;
  });
  expect(stranded, 'a count that starts a line alone').toEqual([]);
  const strandedDomain = await page.evaluate(() => {
    const out = [];
    const items = Array.from(document.querySelectorAll('[data-home-tile]'));
    for (let w = 120; w <= 420; w += 3) {
      for (const li of items) {
        li.style.width = w + 'px';
        const link = li.querySelector('a');
        const count = li.querySelector('[data-tile-count]');
        const lastLink = Array.from(link.getClientRects()).pop();
        const c0 = count.getClientRects()[0];
        if (Math.abs((lastLink.top + lastLink.height / 2) - (c0.top + c0.height / 2)) > 8) out.push(w + 'px: ' + link.textContent.trim());
      }
    }
    items.forEach((li) => { li.style.width = ''; });
    return out;
  });
  expect(strandedDomain, 'a domain count that starts a line alone').toEqual([]);
  // At the real width too, and the title link keeps a 44 px target (padding on an inline link).
  const hs = await page.locator('[data-home-example] h3 a').evaluateAll((els) => els.map((el) => ({
    display: getComputedStyle(el).display, h: Math.min(...Array.from(el.getClientRects()).map((r) => r.height)) })));
  hs.forEach((o) => { expect(o.display).toBe('inline'); expect(o.h).toBeGreaterThanOrEqual(38); });
  await noOverflow(page, 'examples');
});

test('the figures row ends with "Statistici →" and a chart icon; the old link is gone', async ({ page, viewport }) => {
  await page.goto('/');
  const row = page.locator('[data-home-figures] > div').first();
  const link = row.locator('a[href="/statistici/"]');
  await expect(link).toHaveCount(1);
  await expect(link).toHaveText(/^\s*Statistici\s*→\s*$/);
  expect(await link.evaluate((el) => el.matches('a:last-child'))).toBe(true);
  await expect(link.locator('svg[aria-hidden="true"] use')).toHaveAttribute('href', '#i-chart-bar');
  await expect(page.locator('main a', { hasText: 'Mai multe statistici' })).toHaveCount(0);
  const figs = await row.locator('a').evaluateAll((els) => els.map((el) => { const b = el.getBoundingClientRect(); return [b.left, b.top + b.height / 2, b.right]; }));
  expect(figs.length).toBe(4);
  figs.forEach((f) => expect(f[2], 'inside the viewport').toBeLessThanOrEqual(viewport.width + 1));
  if (viewport.width >= 1024) figs.forEach((f) => expect(Math.abs(f[1] - figs[0][1]), 'one row at 1280 px').toBeLessThanOrEqual(2));
  expect(figs[3][0] > figs[2][0] || figs[3][1] > figs[2][1] + 4, 'the link is after the third figure, or below it').toBe(true);
  await noOverflow(page, 'figures row');
});

test('"După domeniu" jumps to the domain section, which is then at the top of the viewport and has focus', async ({ page }) => {
  await page.goto('/');
  const link = page.locator('[data-home-to-domains]');
  await expect(link).toHaveAttribute('href', '#domenii');
  await expect(page.locator('#domenii')).toHaveAttribute('tabindex', '-1');
  const sameLine = await page.evaluate(() => {
    const a = document.querySelector('[data-home-all-count]').getBoundingClientRect();
    const b = document.querySelector('[data-home-to-domains]').getBoundingClientRect();
    return Math.abs((a.top + a.height / 2) - (b.top + b.height / 2));
  });
  if ((page.viewportSize().width) >= 375) expect(sameLine, 'after "Toate anunțurile (N)", on the same line').toBeLessThanOrEqual(2);
  await link.click();
  await expect(page).toHaveURL(/#domenii$/);
  await page.waitForTimeout(300);
  const top = await page.locator('#domenii').evaluate((el) => el.getBoundingClientRect().top);
  expect(top, 'the section is at the top of the viewport, below no header').toBeGreaterThanOrEqual(0);
  expect(top).toBeLessThanOrEqual(24);
  expect(await page.evaluate(() => document.activeElement && document.activeElement.id)).toBe('domenii');
});

test('the title is one line at 1280 px and wraps at narrow widths; the hero form keeps one row at 1280 px', async ({ page, viewport }) => {
  await page.goto('/');
  const h1 = await page.locator('h1').evaluate((el) => ({ h: el.getBoundingClientRect().height, lh: parseFloat(getComputedStyle(el).lineHeight), fs: parseFloat(getComputedStyle(el).fontSize) }));
  if (viewport.width >= 1280) {
    expect(h1.h, 'one line high').toBeLessThanOrEqual(h1.lh + 1);
    const tops = await page.locator('[data-home-search] > *:not(label)').evaluateAll((els) => els.map((e) => Math.round(e.getBoundingClientRect().top)));
    expect(new Set(tops).size, `search, select and button on one row: ${tops}`).toBe(1);
  }
  if (viewport.width <= 375) expect(h1.h, 'wraps at narrow widths').toBeGreaterThan(h1.lh + 1);
  expect(h1.fs, 'a little smaller than the DS size').toBeLessThanOrEqual(36);
});

test('starting-point groups: a wrapped line starts at the left edge; each link is at least 24 px high and no two targets touch', async ({ page }) => {
  await page.goto('/');
  const r = await page.evaluate(() => {
    const out = { groups: 0, wrapped: 0, indented: [], minH: 99, touching: [], minGapX: 999, minGapY: 999, lineH: [] };
    document.querySelectorAll('#h-start ~ div').forEach((g) => {
      out.groups++;
      const left = g.getBoundingClientRect().left;
      const frags = [];
      g.querySelectorAll('li').forEach((li) => Array.from(li.getClientRects()).forEach((q) => frags.push(q)));
      const lines = {};
      frags.forEach((q) => { const k = Math.round(q.top / 6); (lines[k] = lines[k] || []).push(q); });
      const keys = Object.keys(lines).map(Number).sort((a, b) => a - b);
      out.lineH.push(keys.length);
      keys.slice(1).forEach((k) => { out.wrapped++; const m = Math.min(...lines[k].map((q) => q.left)) - left; if (m > 1) out.indented.push(m); });
      const as = [];
      g.querySelectorAll('a').forEach((a) => Array.from(a.getClientRects()).forEach((q) => { as.push(q); out.minH = Math.min(out.minH, q.height); }));
      for (let i = 0; i < as.length; i++) for (let j = i + 1; j < as.length; j++) {
        const [p, q] = [as[i], as[j]];
        const ox = Math.min(p.right, q.right) - Math.max(p.left, q.left);
        const oy = Math.min(p.bottom, q.bottom) - Math.max(p.top, q.top);
        if (ox > 0 && oy > 0) out.touching.push('overlap');
        else if (oy > 0 && ox <= 0) out.minGapX = Math.min(out.minGapX, -ox);
        else if (ox > 0 && oy <= 0) out.minGapY = Math.min(out.minGapY, -oy);
      }
    });
    return out;
  });
  console.log('GROUPS ' + JSON.stringify(r));
  expect(r.groups, 'groups').toBeGreaterThanOrEqual(3);
  expect(r.indented, 'a wrapped line that does not start at the left edge').toEqual([]);
  expect(r.minH, 'the smallest link fragment is at least 24 px high').toBeGreaterThanOrEqual(24);
  expect(r.touching, 'overlapping targets').toEqual([]);
  expect(r.minGapY, 'vertical gap between targets of two lines').toBeGreaterThanOrEqual(1);
  expect(r.minGapX, 'horizontal gap between targets on one line').toBeGreaterThanOrEqual(8);
});

// ---- Follow-up 3 (2026-11-17) ----

test('the "Județ" select of the domains has an accessible name but no visible label text', async ({ page }) => {
  await page.goto('/');
  const sel = page.locator('#domenii-judet');
  await expect(sel).toBeVisible();
  await expect(page.locator('#domenii').getByRole('combobox', { name: 'Județ', exact: true })).toHaveCount(1);
  const label = page.locator('label[for="domenii-judet"]');
  await expect(label).toHaveCount(1);
  // Visually hidden: a clipped box of 1 px, not display:none (which would drop the name).
  const box = await label.evaluate((el) => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return { w: r.width, h: r.height, display: s.display, pos: s.position }; });
  expect(box.display).not.toBe('none');
  expect(box.w).toBeLessThanOrEqual(1);
  expect(box.h).toBeLessThanOrEqual(1);
  // No visible text "Județ" next to the select.
  const visible = await sel.evaluate((el) => Array.from(el.parentElement.childNodes).filter((n) => n !== el && n.nodeType === 1 && n.tagName !== 'LABEL'
    && n.getBoundingClientRect().width > 1).map((n) => n.textContent.trim()));
  expect(visible).toEqual([]);
});

test('the hero county select is about 11rem wide and still shows "Toate județele" in full', async ({ page, viewport }) => {
  await page.goto('/');
  const r = await page.locator('#home-judet').evaluate((el) => {
    const c = document.createElement('canvas').getContext('2d');
    const s = getComputedStyle(el);
    c.font = `${s.fontWeight} ${s.fontSize} ${s.fontFamily}`;
    return { w: el.getBoundingClientRect().width, text: c.measureText('Toate județele').width, rem: parseFloat(getComputedStyle(document.documentElement).fontSize) };
  });
  if (viewport.width >= 1024) expect(r.w, 'about 11 rem').toBeLessThanOrEqual(11.5 * r.rem);
  expect(r.text + 40, 'text, padding and arrow fit').toBeLessThanOrEqual(r.w);
});

test('footer: the links of a list are at least 24 px high, do not overlap, and the rows are 28 to 32 px apart', async ({ page }) => {
  await page.goto('/');
  const lists = await page.locator('footer ul').evaluateAll((uls) => uls.map((ul) => Array.from(ul.querySelectorAll('a')).map((a) => { const r = a.getBoundingClientRect(); return { t: r.top, b: r.bottom, l: r.left, r: r.right, h: r.height, name: a.textContent.trim() }; })));
  expect(lists.length).toBe(4);
  for (const links of lists) {
    links.forEach((a, i) => {
      expect(a.h, `${a.name}: height`).toBeGreaterThanOrEqual(24);
      if (i) {
        const prev = links[i - 1];
        expect(a.t - prev.t, `${a.name}: distance from the row above`).toBeGreaterThanOrEqual(28);
        expect(a.t - prev.t, `${a.name}: distance from the row above`).toBeLessThanOrEqual(32);
        expect(a.t, `${a.name}: does not touch the link above`).toBeGreaterThan(prev.b);
      }
    });
  }
  // The links of the page outside the lists keep 44 px: the disclaimer link has its own padding.
  const src = await page.locator('footer p a').evaluate((a) => a.getBoundingClientRect().height);
  expect(src).toBeGreaterThanOrEqual(43.5);
});

test('footer: the GitHub mark is before "Cod sursă", decoration, in the text colour and drawn; the other links have no icon', async ({ page }) => {
  await page.goto('/');
  const link = page.locator('footer a[href="https://github.com/gov2-ro/posturi.gov.ro"]');
  await expect(link).toHaveText('Cod sursă');
  const svg = link.locator('svg');
  await expect(svg).toHaveCount(1);
  await expect(svg).toHaveAttribute('aria-hidden', 'true');
  await expect(svg).toHaveAttribute('focusable', 'false');
  const r = await svg.evaluate((e) => { const b = e.getBoundingClientRect(); const t = e.nextSibling; const a = e.parentElement.getBoundingClientRect();
    return { w: b.width, h: b.height, before: b.right <= a.right && b.left <= a.left + 1, color: getComputedStyle(e).color, text: getComputedStyle(e.parentElement).color, nextText: t && t.nodeType === 3 }; });
  expect(r.w).toBe(18); expect(r.h).toBe(18);
  expect(r.before, 'the icon is at the start of the link').toBe(true);
  expect(r.nextText, 'the text follows the icon').toBe(true);
  expect(r.color, 'the colour of the text').toBe(r.text);
  await expect.poll(() => svg.locator('use').evaluate((u) => { const b = u.getBBox(); return b.width * b.height; }), { timeout: 5000 }).toBeGreaterThan(50);
  await expect(page.locator('footer ul a svg')).toHaveCount(1);   // the other links: no icon
});

test('a county in the URL fragment selects it and scrolls to the domains; a selection writes the fragment', async ({ page }) => {
  await page.goto('/#cluj');
  await expect(page.locator('#domenii-judet')).toHaveValue('cluj');
  await expect(page.locator('[data-county="cluj"]')).toHaveAttribute('aria-pressed', 'true');
  const top = await page.locator('#domenii').evaluate((el) => el.getBoundingClientRect().top);
  expect(Math.abs(top)).toBeLessThan(40);
  // A fragment that is not a county changes nothing (a new load: a fragment change on the same page keeps the county).
  await page.goto('about:blank');
  await page.goto('/#nu-exista');
  await expect(page.locator('#domenii-judet')).toHaveValue('');
  // A click on the map writes the fragment; a second click on the same county clears it.
  await page.goto('/');
  await page.locator('[data-county="cluj"]').click();
  await expect(page).toHaveURL(/\/#cluj$/);
  await page.locator('[data-county="cluj"]').click();
  await expect(page).toHaveURL(/\/$/);
});

test('a domain item with a themed page links to it; with a county the link has the fragment, and the themed page selects that county; the other items keep judet[]', async ({ page }) => {
  await page.goto('/');
  // A county where a themed item has postings (the JSON block of the page).
  const county = await page.evaluate(() => Object.keys(JSON.parse(document.getElementById('home-data').textContent).items.administratie.counties)[0]);
  expect(county).toBeTruthy();
  const themed = page.locator('[data-home-tile][data-theme-href]');
  expect(await themed.count(), 'themed items on the page').toBeGreaterThanOrEqual(2);
  for (const li of await themed.all()) {
    await expect(li.locator('a')).toHaveAttribute('href', (await li.getAttribute('data-theme-href')));
  }
  const plain = page.locator('[data-home-tile]:not([data-theme-href])');
  expect(await plain.count(), 'items with the list link').toBeGreaterThanOrEqual(1);
  await page.locator(`[data-county="${county}"]`).click();
  for (const li of await themed.all()) {
    if (await li.isHidden()) continue;   // no posting in that county
    await expect(li.locator('a')).toHaveAttribute('href', (await li.getAttribute('data-theme-href')) + '#' + county);
  }
  for (const li of await plain.all()) {
    if (await li.isHidden()) continue;
    await expect(li.locator('a')).toHaveAttribute('href', new RegExp('^/\\?.*judet%5B%5D=' + county));
  }
  // A second click on the same county clears it: the link is the page again.
  await page.locator(`[data-county="${county}"]`).click();
  for (const li of await themed.all()) await expect(li.locator('a')).toHaveAttribute('href', (await li.getAttribute('data-theme-href')));
  // The link with the county opens the themed page with that county selected.
  await page.locator(`[data-county="${county}"]`).click();
  let target = null;
  for (const li of await themed.all()) if (await li.isVisible()) { target = li; break; }   // a themed item with postings in the county
  expect(target, 'a visible themed item in the county').not.toBeNull();
  const slug = await target.getAttribute('data-theme-href');
  await target.locator('a').click();
  await expect(page).toHaveURL(new RegExp(slug + '#' + county + '$'));
  await expect(page.locator('#home-judet')).toHaveValue(county);
});

// The shared fixture has fewer than 4 weeks with postings, so the chart is hidden there; the theme fixture (health postings in 10 weeks) shows it.
test.describe('the landing chart', () => {
test.use({ baseURL: 'http://127.0.0.1:8824' });
test('the weekly chart: 12 thin bars of one colour, posts, a title on each, a baseline and no grid; the estimate is said; no overflow', async ({ page }) => {
  await page.goto('/');
  const fig = page.locator('figure:has(svg[role="img"] g[data-bar])');
  await expect(fig.locator('figcaption')).toHaveText('Posturi publicate pe săptămână');
  await expect(fig.getByText('Estimare: un anunț')).toHaveCount(0);
  await expect(fig.locator('svg[role="img"] g[data-bar]'), 'twelve weeks').toHaveCount(12);
  // The landing chart has no groups: one bar for each week with posts, all of the colour of the first series. Mutation: a line (polyline) or a grid.
  const bars = await fig.locator('svg rect').evaluateAll((rs) => rs.map((r) => ({ c: r.getAttribute('class'), h: parseFloat(r.getAttribute('height')), t: r.querySelector('title').textContent, w: r.getBoundingClientRect().width })));
  expect(bars.length, 'at least 4 weeks with posts').toBeGreaterThanOrEqual(4);
  bars.forEach((b) => { expect(b.c).toBe('pg-seg'); expect(b.t).toMatch(/^săpt\. \d+ [a-z]{3}: \d+ (de )?post(uri)?$/); });
  expect(Math.max(...bars.map((b) => b.h)), 'the tallest bar reaches the top of the axis (0 to the largest week)').toBe(60);
  const slot = (await fig.locator('svg[role="img"]').boundingBox()).width / 12;
  bars.forEach((b) => expect(b.w / slot, 'a bar is half of its slot: thin, with space between').toBeCloseTo(0.5, 1));
  await expect(fig.locator('svg line'), 'one baseline, no grid').toHaveCount(1);
  await expect(fig.locator('svg polyline, svg path')).toHaveCount(0);
  await expect(fig.locator('svg[role="img"]')).toHaveAttribute('aria-label', /^Posturi publicate pe săptămână, ultimele 12 săptămâni complete: între \d+ și [\d.]+ (de )?post(uri)?$/);
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, 'no horizontal overflow').toBeLessThanOrEqual(1);
});
});
