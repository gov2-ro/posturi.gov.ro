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

// 261116: for a search the block opens in #follow-dialog from "Urmărește".
async function openBlock(page) {
  const dlg = page.locator('#follow-dialog');
  if (!(await dlg.evaluate((n) => n.open))) await page.locator('[data-active-chips] [data-follow-open]').click();
  await expect(dlg).toHaveJSProperty('open', true);
  await expect(page.locator('#follow-dialog #urmareste')).toBeVisible();
}
async function closeBlock(page) {
  await page.keyboard.press('Escape');
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', false);
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
  await expectFeeds(page, { judet: ['ilfov'] });

  // HTMX: a keyword typed into the search box.
  await page.locator('input[name="q"]').first().fill('volum');
  await expect(page).toHaveURL(/q=volum/);
  await expectFeeds(page, { judet: ['ilfov'], q: ['volum'] });
  await expect(page.locator('#urmareste'), 'a search keeps the block for the dialog').toBeHidden();

  // Chip removal drops only that value.
  await page.locator('[data-chip-param="q"]').first().click();
  await expect(page).not.toHaveURL(/q=volum/);
  await expectFeeds(page, { judet: ['ilfov'], q: [] });

  // Pagination (Ilfov spans two pages): the page number never reaches a feed.
  await page.locator('#results nav a[aria-label="Pagina 2"]').click();
  await expect(page).toHaveURL(/page=2/);
  await expectFeeds(page, { judet: ['ilfov'] });

  // Status through the control, then history back twice.
  await page.locator('select#status').selectOption('closed');
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

test('no feed link carries a title, even from an old link; Google and webcal wrap the same ICS URL', async ({ page, baseURL }) => {
  await page.goto('/?judet%5B%5D=cluj&status=all&title=Vechi');
  await openBlock(page);
  await expect(page.locator('#feed-title')).toHaveCount(0);
  const ics = new URL(await feedHref(page, 'posturi.ics'), baseURL);
  for (const f of ['posturi.ics', 'posturi.rss', 'posturi.atom', 'posturi.json']) {
    expect((await feedParams(page, f)).has('title'), f).toBe(false);
  }
  const g = new URL(await page.locator('#gcal-link').getAttribute('href'));
  expect(g.origin + g.pathname).toBe('https://calendar.google.com/calendar/u/0/r/settings/addbyurl');
  expect(g.searchParams.get('url')).toBe(ics.href);
  const w = await page.locator('#webcal-link').getAttribute('href');
  expect(w).toBe(ics.href.replace(/^https?:/, 'webcal:'));
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
  await closeBlock(page);
  await page.locator('input[name="q"]').first().fill('');
  await expect(page).not.toHaveURL(/q=spital/);
  await expect(page.locator('#feed-copy-fallback')).toBeHidden();
});

test('keyboard: the dialog and the copy buttons work without a pointer', async ({ page }) => {
  await page.addInitScript(() => {
    window.__copied = null;
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (t) => (window.__copied = t, Promise.resolve()) } });
  });
  await page.goto('/?judet%5B%5D=cluj');
  const opener = page.locator('[data-active-chips] [data-follow-open]');
  await opener.focus();
  await page.keyboard.press('Enter');
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', true);
  await expect(page.locator('[data-follow-close]')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-feed="posturi.rss"]')).toBeFocused();
  await page.keyboard.press('Tab');
  await expect(page.locator('[data-copy="posturi.rss"]')).toBeFocused();
  await page.keyboard.press('Enter');
  await expect.poll(() => page.evaluate(() => window.__copied)).toContain('/posturi.rss?');
  await page.keyboard.press('Escape');
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', false);
  await expect(opener).toBeFocused();
});

test('"Urmărește" opens the dialog; layout holds at this width', async ({ page }) => {
  await page.goto('/?judet%5B%5D=ilfov');
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', false);
  await expect(page.locator('#urmareste')).toBeHidden();
  await noOverflow(page, 'closed');
  await openBlock(page);
  await expect(page.locator('#urmareste-title')).toHaveText('Urmărește această căutare');
  await expect(page.locator('#feed-limits')).toContainText('cele mai noi 50 de anunțuri care corespund filtrelor');
  await expect(page.locator('#feed-limits')).toContainText('primele 200 cu dată disponibilă, în ordinea termenului');
  // Email straight from the search: the form carries this search's key.
  const mail = page.locator('[data-follow-email]');
  await expect(mail).toBeVisible();
  await expect(mail.locator('input[name="cheie"]')).toHaveValue('judet%5B%5D=ilfov');
  await expect(mail.locator('input[name="nume"]')).not.toHaveValue('');
  await noOverflow(page, 'open');
  await shot(page, 'subscribe-open');
  // A click on the backdrop closes it; the block goes back, hidden.
  await page.mouse.click(2, 2);
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', false);
  await expect(page.locator('#urmareste')).toBeHidden();
  await page.goto('/?judet%5B%5D=ilfov#urmareste-linkuri');
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', true);
});

test('landing: the block is in place and open; no group, no email form', async ({ page }) => {
  await page.goto('/posturi/');
  await expect(page.locator('#urmareste')).toBeVisible();
  await expect(page.locator('#urmareste-title')).toHaveText('Urmărește anunțurile noi');
  await expect(page.locator('[data-follow-email]')).toBeHidden();
  await expect(page.locator('[data-active-chips] [data-follow-group]')).toBeHidden();
  await expect(page.locator('#follow-fab')).toBeHidden();
  expect(await page.locator('#urmareste').evaluate((el) => !el.closest('.pg-main') && !el.closest('form'))).toBe(true);
  await noOverflow(page, 'landing');
});

test('search: the bottom group shows only while the top group is off screen', async ({ page }) => {
  await page.goto('/?status=all');
  await page.locator('input[name="q"]').first().fill('spital');
  await expect(page).toHaveURL(/q=spital/);
  // A short window, so the chips' group can leave the screen on a fixture page.
  await page.setViewportSize({ width: page.viewportSize().width, height: 420 });
  const top = page.locator('[data-active-chips] [data-follow-group]');
  await expect(top).toBeVisible();
  await top.scrollIntoViewIfNeeded();
  await expect(page.locator('#follow-fab')).toBeHidden();
  await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
  await expect(page.locator('#follow-fab')).toBeVisible();
  await noOverflow(page, 'bottom group');
  // Its save toggle and the top one share one state.
  await page.locator('#follow-fab [data-search-toggle]').click();
  await expect(page.locator('[data-active-chips] [data-search-toggle]')).toHaveAttribute('aria-pressed', 'true');
  // The save notice offers "Urmărește"; it opens the dialog and closes the notice.
  const notice = page.locator('#pref-notice');
  await expect(notice).toBeVisible();
  await expect(notice.locator('[data-notice-follow]')).toBeVisible();
  await notice.locator('[data-notice-follow]').click();
  await expect(page.locator('#follow-dialog')).toHaveJSProperty('open', true);
  await expect(notice).toBeHidden();
  await closeBlock(page);
  await top.scrollIntoViewIfNeeded();
  await expect(page.locator('#follow-fab')).toBeHidden();
});

test.describe('without JavaScript', () => {
  test.use({ javaScriptEnabled: false });

  test('links are server-rendered with the filters and the block is in place', async ({ page }) => {
    await page.goto('/?judet%5B%5D=cluj&judet%5B%5D=bacau&status=all&page=1&sort=deadline');
    await expect(page.locator('#urmareste')).toBeVisible();
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

  test('without JavaScript a search shows no save/follow group and no email form', async ({ page }) => {
    await page.goto('/?judet%5B%5D=cluj');
    await expect(page.locator('[data-follow-group]')).toHaveCount(2);
    for (const g of await page.locator('[data-follow-group]').all()) await expect(g).toBeHidden();
    await expect(page.locator('[data-follow-email]')).toBeHidden();
    await expect(page.locator('[data-feed="posturi.rss"]')).toBeVisible();
  });
});
