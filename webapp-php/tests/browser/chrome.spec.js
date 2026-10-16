// BETA-CHROME (D9–D10): masthead, posting disclaimer, the [?!] feedback panel and
// the first-visit welcome. Fixture clock 2026-10-03; the fixture database was
// built 2026-10-03 09:00 UTC, i.e. 12:00 in Bucharest — "azi".
const { test, expect } = require('@playwright/test');

const DETAIL = '/job/1001-asistent-medical-generalist/';

test('masthead: version and one relative database stamp, no disclaimer or feedback link', async ({ page, viewport }) => {
  await page.goto('/');
  const header = page.locator('header').first();
  await expect(header).toContainText('beta');
  await expect(header).not.toContainText('Acceptăm sugestii');
  await expect(header).not.toContainText('proiect oficial');
  const stamp = header.locator('time');
  if (viewport.width >= 640) {
    await expect(stamp).toHaveText('azi, 3 oct, 12:00');
    await expect(header).toContainText('Bază generată:');
  } else {
    await expect(stamp).toBeHidden();
  }
  const overflow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
});

test('every posting opens and closes with the robots disclaimer, linked to the official announcement', async ({ page }) => {
  await page.goto(DETAIL);
  const lines = page.locator('.robots-disclaimer');
  await expect(lines).toHaveCount(2);
  for (const i of [0, 1]) {
    await expect(lines.nth(i)).toContainText('Datele sunt preluate de pe posturi.gov.ro și restructurate cu');
    const href = await lines.nth(i).locator('a').getAttribute('href');
    expect(href).toMatch(/^https:\/\/posturi\.gov\.ro\//);
  }
});

test('[?!] opens the report panel, Escape closes it and returns focus, outside click closes it', async ({ page }) => {
  await page.goto('/');
  const fb = page.locator('#feedback');
  const button = fb.locator('summary');
  await expect(button).toBeVisible();
  await button.click();
  await expect(fb.locator('.feedback-panel')).toBeVisible();
  await expect(fb).toContainText('Raportează problemă?');
  await expect(fb.locator('a[href="https://forms.gle/96WusM2qr4pbUXhW7"]')).toBeVisible();
  await expect(fb.locator('a[href="https://github.com/gov2-ro/posturi.gov.ro/issues"]')).toBeVisible();
  await expect(fb.locator('a[href="mailto:midas@gov2.ro"]')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(fb.locator('.feedback-panel')).toBeHidden();
  await expect(button).toBeFocused();
  await button.click();
  await page.mouse.click(5, 300);
  await expect(fb.locator('.feedback-panel')).toBeHidden();
  const overflow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(1);
});

test('every FAQ link lands on an existing heading of /despre/', async ({ page }) => {
  await page.goto('/');
  const hrefs = await page.locator('#feedback .feedback-panel ul a[href^="/despre/#"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')));
  expect(hrefs.length).toBeGreaterThanOrEqual(5);
  await page.goto('/despre/');
  for (const h of hrefs) {
    await expect(page.locator('#' + h.split('#')[1])).toHaveCount(1);
  }
  await expect(page.locator('#confidentialitate')).toBeVisible();
  await expect(page.locator('#versiunea-datelor')).toHaveCount(1);
});

test('welcome: first visit, not the next page, again after 90 days away; "Am înțeles" hides it', async ({ page }) => {
  await page.goto('/');
  const welcome = page.locator('#welcome');
  await expect(welcome).toBeVisible();
  await page.goto('/despre/');
  await expect(welcome).toBeHidden();
  // 91 days away.
  await page.evaluate(() => localStorage.setItem('posturi.lastVisit', String(Date.now() - 91 * 864e5)));
  await page.goto('/');
  await expect(welcome).toBeVisible();
  await welcome.locator('[data-welcome-close]').click();
  await expect(welcome).toBeHidden();
  // 89 days away: no welcome.
  await page.evaluate(() => localStorage.setItem('posturi.lastVisit', String(Date.now() - 89 * 864e5)));
  await page.goto('/');
  await expect(welcome).toBeHidden();
});

test('welcome stays hidden, and the page works, when storage throws', async ({ page }) => {
  await page.addInitScript(() => {
    Object.defineProperty(window, 'localStorage', { get() { throw new Error('blocked'); } });
  });
  await page.goto('/');
  await expect(page.locator('#welcome')).toBeHidden();
  await expect(page.locator('h1, #results').first()).toBeVisible();
});

test('share image: one static Open Graph card, served', async ({ page, request }) => {
  await page.goto('/');
  const og = await page.locator('meta[property="og:image"]').getAttribute('content');
  expect(og).toMatch(/\/static\/og-image\.png$/);
  await expect(page.locator('meta[name="twitter:card"]')).toHaveAttribute('content', 'summary_large_image');
  const res = await request.get('/static/og-image.png');
  expect(res.status()).toBe(200);
  expect(res.headers()['content-type']).toBe('image/png');
});
