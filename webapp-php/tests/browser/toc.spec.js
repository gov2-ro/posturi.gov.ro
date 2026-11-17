// Brief 2026-11-17 (nav, table of contents, themed hero): the table of contents of Statistici and
// Despre, the alerts block of /salvate/, and the statistics line "Fără dată de publicare". Runs at
// 320, 375 and 1280 (playwright.config.js) against the fixture (tests/fixtures/build_db.php).
// The case "0 postings with no date" (no line) is in tests/stats_test.php: it needs its own database.
const { test, expect } = require('@playwright/test');

const errors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  errors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => { expect(errors.get(page), 'uncaught page errors').toEqual([]); });

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('/salvate/ has the alerts block with a link to /alerte/, for each reader (no script)', async ({ browser, baseURL }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL });
  const page = await ctx.newPage();
  await page.goto('/salvate/');
  const block = page.locator('[data-alerts-block]');
  await expect(block).toBeVisible();
  await expect(block.getByRole('heading', { name: 'Alerte pe email', level: 2 })).toBeVisible();
  await expect(block).toContainText('Primești pe email posturile noi dintr-o căutare salvată, o dată la 3 zile.');
  const link = block.getByRole('link', { name: 'Gestionează alertele' });
  await expect(link).toHaveAttribute('href', '/alerte/');
  expect((await link.boundingBox()).height).toBeGreaterThanOrEqual(44);
  await noOverflow(page, '/salvate/');
  await ctx.close();
  // The link works, and the footer keeps "Alerte pe email".
  const p2 = await browser.newPage({ baseURL });
  await p2.goto('/salvate/');
  await p2.locator('[data-alerts-block] a').click();
  await expect(p2).toHaveURL(/\/alerte\/$/);
  await p2.goto('/');
  await expect(p2.locator('footer a[href="/alerte/"]')).toHaveText('Alerte pe email');
  await p2.close();
});

for (const path of ['/statistici/', '/despre/']) {
  test.describe(path, () => {
    const nav = (page) => page.locator('nav[aria-label="Cuprins"]');

    test('each link points to an id that exists, in the order of the h2 headings; no overflow', async ({ page }) => {
      await page.goto(path);
      await expect(nav(page)).toHaveCount(1);
      const hrefs = await nav(page).locator('a').evaluateAll((els) => els.map((a) => a.getAttribute('href')));
      const heads = await page.locator('main h2').evaluateAll((els) => els.map((h) => ({ id: h.id, text: h.textContent.trim(), tab: h.getAttribute('tabindex') })));
      expect(hrefs, 'links = h2 ids, in order').toEqual(heads.map((h) => '#' + h.id));
      expect(heads.length).toBeGreaterThanOrEqual(3);
      heads.forEach((h) => expect(h.tab, `${h.id} takes focus`).toBe('-1'));
      // The text of a link is the text of its heading.
      expect(await nav(page).locator('a').allTextContents()).toEqual(heads.map((h) => h.text));
      for (const h of heads) expect(await page.locator('#' + h.id).count(), `#${h.id} is unique`).toBe(1);
      await noOverflow(page, path);
    });

    test('a click puts the heading in view, below the top edge, and gives it the focus', async ({ page }) => {
      await page.goto(path);
      const links = nav(page).locator('a');
      const n = await links.count();
      // The second section: a section near the end of the page cannot scroll to the top edge.
      const target = links.nth(Math.min(1, n - 1));
      const id = (await target.getAttribute('href')).slice(1);
      await target.click();
      await expect(page).toHaveURL(new RegExp('#' + id + '$'));
      await expect.poll(() => page.evaluate((i) => document.activeElement && document.activeElement.id === i, id)).toBe(true);
      await expect.poll(async () => {
        const b = await page.locator('#' + id).boundingBox();
        return b.y >= 0 && b.y + b.height <= (await page.viewportSize()).height;
      }, 'the heading is inside the window').toBe(true);
      const top = (await page.locator('#' + id).boundingBox()).y;
      expect(top, 'a margin above the heading').toBeGreaterThanOrEqual(8);
    });

    test('aria-current follows the scroll; without script the list has no mark', async ({ page, browser, baseURL }) => {
      await page.goto(path);
      const links = nav(page).locator('a');
      const ids = (await links.evaluateAll((els) => els.map((a) => a.getAttribute('href').slice(1))));
      const current = () => nav(page).locator('a[aria-current="true"]').evaluateAll((els) => els.map((a) => a.getAttribute('href').slice(1)));
      // At the top (above the first heading or at it) at most the first link is marked; one mark at most, always.
      expect((await current()).length).toBeLessThanOrEqual(1);
      for (const id of [ids[1], ids[ids.length - 1], ids[0]]) {
        await page.evaluate((i) => {
          const h = document.getElementById(i);
          window.scrollTo(0, h.getBoundingClientRect().top + window.scrollY - 24);
        }, id);
        await expect.poll(current, `scrolled to #${id}`).toEqual([id]);
      }
      // No script: the links work and none is marked.
      const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL });
      const p = await ctx.newPage();
      await p.goto(path);
      await expect(p.locator('nav[aria-label="Cuprins"] a')).toHaveCount(ids.length);
      await expect(p.locator('nav[aria-label="Cuprins"] a[aria-current]')).toHaveCount(0);
      await ctx.close();
    });

    test('lg: a sticky column beside the content; below lg: a block above it, not sticky', async ({ page, viewport }) => {
      await page.goto(path);
      const n = nav(page);
      const pos = await n.evaluate((el) => ({ position: getComputedStyle(el).position, left: el.getBoundingClientRect().left, width: el.getBoundingClientRect().width }));
      const h1 = await page.locator('main h1').boundingBox();
      const first = await page.locator('main h2').first().boundingBox();
      if (viewport.width >= 1024) {
        expect(pos.position).toBe('sticky');
        expect(pos.width).toBeGreaterThan(200);
        expect(pos.width).toBeLessThan(260);
        expect(first.x, 'the content is to the right of the column').toBeGreaterThan(pos.left + pos.width);
        // It stays in the window while the content scrolls.
        await page.evaluate(() => window.scrollTo(0, 1200));
        const b = await n.boundingBox();
        expect(b.y).toBeGreaterThanOrEqual(0);
        expect(b.y).toBeLessThan(100);
      } else {
        expect(pos.position).not.toBe('sticky');
        const b = await n.boundingBox();
        expect(b.y, 'above the content').toBeLessThan(first.y);
        expect(b.y).toBeGreaterThan(h1.y);
        const w = await page.evaluate(() => document.querySelector('nav[aria-label="Cuprins"]').getBoundingClientRect().right);
        expect(w).toBeLessThanOrEqual(viewport.width + 1);
      }
    });
  });
}

test('/despre/: each anchor that other pages link to still exists', async ({ page }) => {
  await page.goto('/despre/');
  for (const id of ['confidentialitate', 'confidentialitate-alerte', 'versiunea-datelor', 'cum-functioneaza', 'termene', 'salarii', 'contact']) {
    await expect(page.locator('#' + id), `#${id}`).toHaveCount(1);
  }
  await page.goto('/despre/#confidentialitate');
  await expect(page.locator('#confidentialitate')).toBeInViewport();
});

test('/statistici/: the line "Fără dată de publicare" is shown with a number above 0 (fixture: 3)', async ({ page }) => {
  await page.goto('/statistici/');
  const card = page.locator('[data-unknown-date]');
  await expect(card).toHaveCount(1);
  await expect(card).toContainText('Fără dată de publicare');
  await expect(card).toContainText('incluse în total, în nicio lună');
  await expect(card.locator('div').first()).toHaveText('3');
  // The old anchors stay.
  await expect(page.locator('#export-curent')).toHaveCount(1);
  await expect(page.locator('#istoric')).toHaveCount(1);
});
