// UX-04-FEEDS browser checks: the subscription block follows the filters through
// HTMX swaps, chips, pagination and history; copy, clipboard failure, keyboard,
// no-JS. Runs at 320, 375 and 1280 (playwright.config.js). Fixture data:
// tests/fixtures/build_db.php. Set UX04_SHOTS=<dir> to write screenshots there.
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const FEEDS = ['posturi.rss', 'posturi.atom', 'posturi.ics', 'posturi.json'];

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

async function openBlock(page) {
  const d = page.locator('details#urmareste');
  if (!(await d.evaluate((n) => n.open))) await d.locator('> summary').click();
  await expect(d).toHaveJSProperty('open', true);
}

async function feedHref(page, feed) {
  return page.locator(`[data-feed="${feed}"]`).getAttribute('href');
}

async function feedParams(page, feed) {
  return new URL(await feedHref(page, feed), 'http://x').searchParams;
}

/** PHP writes arrays as name[0]=…, the form as name[]=…; both mean the same. */
function valuesOf(params, name) {
  const out = [];
  for (const [k, v] of params) if (k === name || k.startsWith(name + '[')) out.push(v);
  return out;
}

async function expectFeeds(page, expectations) {
  for (const feed of FEEDS) {
    await expect.poll(async () => {
      const p = await feedParams(page, feed);
      return {
        page: p.has('page'), sort: p.has('sort'),
        title: p.has('title'),
        ...Object.fromEntries(Object.keys(expectations).map((k) => [k, valuesOf(p, k)])),
      };
    }, { message: feed }).toEqual({ page: false, sort: false, title: false, ...expectations });
  }
}

function shot(page, name) {
  const dir = process.env.UX04_SHOTS;
  if (!dir) return Promise.resolve();
  fs.mkdirSync(dir, { recursive: true });
  return page.screenshot({ path: path.join(dir, `${name}-${page.viewportSize().width}.png`), fullPage: true });
}

test('subscription links follow filters through HTMX, chips, pagination and history', async ({ page }) => {
  await page.goto('/?judet%5B%5D=ilfov&sort=deadline');
  await openBlock(page);
  await expectFeeds(page, { judet: ['ilfov'] });

  // HTMX: a keyword typed into the search box.
  await page.locator('input[name="q"]').first().fill('volum');
  await expect(page).toHaveURL(/q=volum/);
  await expectFeeds(page, { judet: ['ilfov'], q: ['volum'] });
  expect(await page.locator('details#urmareste').evaluate((n) => n.open), 'block stays open across a swap').toBe(true);

  // Chip removal drops only that value.
  await page.locator('[data-chip-param="q"]').first().click();
  await expect(page).not.toHaveURL(/q=volum/);
  await expectFeeds(page, { judet: ['ilfov'], q: [] });

  // Pagination (Ilfov spans two pages): the page number never reaches a feed.
  await page.locator('#results nav a[aria-label="Pagina 2"]').click();
  await expect(page).toHaveURL(/page=2/);
  await expectFeeds(page, { judet: ['ilfov'] });

  // Status through the control, then history back twice.
  await page.locator('label:has(input[name="status"][value="closed"])').click();
  await expect(page).toHaveURL(/status=closed/);
  await expectFeeds(page, { judet: ['ilfov'], status: ['closed'] });
  await page.goBack();
  await expect(page).not.toHaveURL(/status=closed/);
  await expectFeeds(page, { judet: ['ilfov'] });
  await page.goBack();
  await expect(page).not.toHaveURL(/page=2/);
  await expectFeeds(page, { judet: ['ilfov'] });

  // Empty results keep working links.
  await page.goto('/?q=zzzzqqqq');
  await openBlock(page);
  await expectFeeds(page, { q: ['zzzzqqqq'] });
});

test('the calendar title goes into the calendar links only; Google and webcal wrap the same ICS URL', async ({ page, baseURL }) => {
  await page.goto('/?judet%5B%5D=cluj&status=all');
  await openBlock(page);
  await page.locator('#feed-title').fill('Posturi Cluj, aprilie');
  const ics = new URL(await feedHref(page, 'posturi.ics'), baseURL);
  expect(ics.searchParams.get('title')).toBe('Posturi Cluj, aprilie');
  for (const f of ['posturi.rss', 'posturi.atom', 'posturi.json']) {
    expect((await feedParams(page, f)).has('title'), f).toBe(false);
  }
  const g = new URL(await page.locator('#gcal-link').getAttribute('href'));
  expect(g.origin + g.pathname).toBe('https://calendar.google.com/calendar/u/0/r/settings/addbyurl');
  expect(g.searchParams.get('url')).toBe(ics.href);
  const w = await page.locator('#webcal-link').getAttribute('href');
  expect(w).toBe(ics.href.replace(/^https?:/, 'webcal:'));
  // The title is a calendar label, not a filter: the address bar does not pick it up.
  expect(page.url()).not.toContain('title=');
});

test('copy uses the current absolute URL; a clipboard failure shows a selected, selectable URL', async ({ page, baseURL }) => {
  await page.addInitScript(() => {
    window.__copied = null;
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: (t) => (window.__fail ? Promise.reject(new Error('denied')) : (window.__copied = t, Promise.resolve())) },
    });
  });
  await page.goto('/?judet%5B%5D=cluj&judet%5B%5D=bacau&q=spital%20%26%20ro&status=all');
  await openBlock(page);
  const rssBtn = page.locator('[data-copy="posturi.rss"]');
  await expect(rssBtn).toBeVisible();
  await rssBtn.click();
  const href = await feedHref(page, 'posturi.rss');
  await expect.poll(() => page.evaluate(() => window.__copied)).toBe(new URL(href, baseURL).href);
  const copied = new URL(await page.evaluate(() => window.__copied));
  expect(valuesOf(copied.searchParams, 'judet')).toEqual(['cluj', 'bacau']);
  expect(copied.searchParams.get('q')).toBe('spital & ro');
  await expect(page.locator('#feed-copy-status')).toContainText('Link copiat');
  await expect(page.locator('#feed-copy-fallback')).toBeHidden();

  // Failure: the URL is shown, readonly, focused and fully selected.
  await page.evaluate(() => { window.__fail = true; });
  await page.locator('[data-copy="posturi.atom"]').click();
  const input = page.locator('#feed-copy-url');
  await expect(input).toBeVisible();
  await expect(input).toHaveValue(new URL(await feedHref(page, 'posturi.atom'), baseURL).href);
  await expect(input).toHaveAttribute('readonly', '');
  await expect(input).toBeFocused();
  const sel = await input.evaluate((n) => [n.selectionStart, n.selectionEnd, n.value.length]);
  expect(sel[0]).toBe(0);
  expect(sel[1]).toBe(sel[2]);
  await expect(page.locator('#feed-copy-status')).toContainText('adresa este selectată');
  await noOverflow(page, 'fallback visible');
  await shot(page, 'copy-fallback');

  // Changing the filters clears a stale manual URL.
  await page.locator('input[name="q"]').first().fill('');
  await expect(page).not.toHaveURL(/q=spital/);
  await expect(page.locator('#feed-copy-fallback')).toBeHidden();
});

test('keyboard: the disclosure and the copy buttons work without a pointer', async ({ page }) => {
  await page.addInitScript(() => {
    window.__copied = null;
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (t) => (window.__copied = t, Promise.resolve()) } });
  });
  await page.goto('/?judet%5B%5D=cluj');
  const summary = page.locator('details#urmareste > summary');
  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('details#urmareste')).toHaveJSProperty('open', true);
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-feed="posturi.rss"]')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-copy="posturi.rss"]')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.__copied)).toContain('/posturi.rss?');
  await summary.focus();
  await page.keyboard.press('Space');
  await expect(page.locator('details#urmareste')).toHaveJSProperty('open', false);
});

test('export line link opens the block; layout holds at this width', async ({ page }) => {
  await page.goto('/?judet%5B%5D=ilfov');
  await expect(page.locator('details#urmareste')).toHaveJSProperty('open', false);
  await noOverflow(page, 'closed');
  await page.locator('[data-open-subscribe]').click();
  await expect(page.locator('details#urmareste')).toHaveJSProperty('open', true);
  await expect(page.locator('#feed-limits')).toContainText('cele mai noi 50 de anunțuri care corespund filtrelor');
  await expect(page.locator('#feed-limits')).toContainText('primele 200 cu dată disponibilă, în ordinea termenului');
  await page.locator('#feed-title').fill('x'.repeat(80));
  await noOverflow(page, 'open with long title');
  await shot(page, 'subscribe-open');
  await page.goto('/?judet%5B%5D=ilfov#urmareste-linkuri');
  await expect(page.locator('details#urmareste')).toHaveJSProperty('open', true);
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('links are server-rendered with the filters and the disclosure still toggles', async ({ page }) => {
    await page.goto('/?judet%5B%5D=cluj&judet%5B%5D=bacau&status=all&page=1&sort=deadline');
    await page.locator('details#urmareste > summary').click();
    await expect(page.locator('details#urmareste')).toHaveJSProperty('open', true);
    for (const f of FEEDS) {
      const p = await feedParams(page, f);
      expect(valuesOf(p, 'judet'), f).toEqual(['cluj', 'bacau']);
      expect(p.get('status'), f).toBe('all');
      expect(p.has('page') || p.has('sort'), f).toBe(false);
    }
    await expect(page.locator('[data-copy="posturi.rss"]')).toBeHidden();
    await expect(page.locator('#gcal-link')).toBeVisible();
    await noOverflow(page, 'no-JS open');
  });

  test('the export anchor reaches the links without JavaScript', async ({ page }) => {
    await page.goto('/?judet%5B%5D=cluj');
    await page.locator('[data-open-subscribe]').click();
    await expect(page.locator('details#urmareste')).toHaveJSProperty('open', true);
    await expect(page.locator('[data-feed="posturi.rss"]')).toBeVisible();
  });
});
