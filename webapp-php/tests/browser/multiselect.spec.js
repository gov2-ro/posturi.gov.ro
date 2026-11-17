// Multi-select in a filter list: three quick picks must all stay (brief 2026-11-17,
// step 4.6). The response is slowed to 800 ms and the picks are 150 ms apart.
const { test, expect } = require('@playwright/test');

const isNarrow = (page) => page.viewportSize().width < 1024;
const COUNTIES = ['bacau', 'cluj', 'ilfov'];
const countOf = (html) => Number((/(\d+)\s+anun[țt]/.exec(html.replace(/<[^>]+>/g, ' ')) || [])[1]);

const modes = ['list', 'compact', 'table'];
for (const mode of modes) {
  test(`three quick picks in one group all stay (${mode})`, async ({ page, request, viewport }) => {
    test.skip(mode !== 'list' && viewport.width < 1024, 'grid and table views are lg+ only');
    test.skip(mode === 'list' && viewport.width === 320, 'drawer is covered at 375');
    await page.addInitScript((m) => localStorage.setItem('posturi.layout', m), mode);
    const sent = [];
    await page.route(/\/\?/, async (route) => {
      if (route.request().headers()['hx-request']) {
        sent.push(route.request().url());
        await new Promise((r) => setTimeout(r, 800));
      }
      await route.continue();
    });
    await page.goto('/posturi/');
    const expected = countOf(await (await request.get('/?' + COUNTIES.map((c) => 'judet[]=' + c).join('&'))).text());
    expect(expected).toBeGreaterThan(0);

    if (isNarrow(page)) await page.locator('#filter-toggle').click();
    const group = page.locator('#facet-list > details[data-facet="judet"], #facet-list details[data-facet="judet"]').first();
    if (!(await group.evaluate((d) => d.open))) await group.locator('> summary').click();

    for (const c of COUNTIES) {
      await page.locator(`input[name="judet[]"][value="${c}"]`).click();
      await page.waitForTimeout(150);
    }
    // Settle: the last request, and its response, are done.
    await expect.poll(() => new URL(page.url()).searchParams.getAll('judet[]').sort().join(','), { timeout: 10_000 })
      .toBe(COUNTIES.join(','));
    await page.waitForTimeout(1500);
    for (const c of COUNTIES) {
      await expect(page.locator(`input[name="judet[]"][value="${c}"]`), `${c} stays checked`).toBeChecked();
    }
    expect(new URL(page.url()).searchParams.getAll('judet[]').sort()).toEqual(COUNTIES);
    await expect(page.locator('#results-status')).toHaveText(new RegExp('^' + expected + ' '));
    // The wait before a request: three picks 150 ms apart make one request, not three.
    expect(sent.length, `requests sent: ${sent.join(' | ')}`).toBe(1);
  });
}

// The response of a request that left before the next pick must not be applied: it
// would put back the old checkboxes and the old URL (stale-response guard).
test('a pick made while a request is in flight is not lost', async ({ page, viewport }) => {
  test.skip(viewport.width === 320, 'drawer is covered at 375');
  await page.route(/\/\?/, async (route) => {
    if (route.request().headers()['hx-request']) await new Promise((r) => setTimeout(r, 800));
    await route.continue();
  });
  await page.goto('/posturi/');
  if (isNarrow(page)) await page.locator('#filter-toggle').click();
  const group = page.locator('details[data-facet="judet"]').first();
  if (!(await group.evaluate((d) => d.open))) await group.locator('> summary').click();
  await page.locator('input[name="judet[]"][value="bacau"]').click();
  await page.waitForTimeout(900);            // request sent at 500 ms, its response is due at 1300 ms
  await page.locator('input[name="judet[]"][value="cluj"]').click();
  await expect.poll(() => new URL(page.url()).searchParams.getAll('judet[]').sort().join(','), { timeout: 10_000 })
    .toBe('bacau,cluj');
  await page.waitForTimeout(1500);
  for (const c of ['bacau', 'cluj']) await expect(page.locator(`input[name="judet[]"][value="${c}"]`)).toBeChecked();
});

// hx-sync="this:replace": a new request cancels the one in flight, so only the last one completes.
test('a new request replaces the request in flight', async ({ page, viewport }) => {
  test.skip(viewport.width !== 1280, 'one width is enough');
  const done = [];
  await page.route(/\/\?/, async (route) => {
    if (route.request().headers()['hx-request']) await new Promise((r) => setTimeout(r, 800));
    await route.continue().catch(() => {});
  });
  page.on('requestfinished', (r) => { if (r.headers()['hx-request']) done.push(r.url()); });
  await page.goto('/posturi/');
  const group = page.locator('details[data-facet="judet"]').first();
  if (!(await group.evaluate((d) => d.open))) await group.locator('> summary').click();
  await page.locator('input[name="judet[]"][value="bacau"]').click();
  await page.waitForTimeout(700);            // request 1 left at 500 ms, response due at 1300 ms
  await page.locator('input[name="judet[]"][value="cluj"]').click();   // request 2 leaves at 1200 ms
  await expect.poll(() => new URL(page.url()).searchParams.getAll('judet[]').sort().join(','), { timeout: 10_000 }).toBe('bacau,cluj');
  await page.waitForTimeout(1500);
  expect(done.length, done.join(' | ')).toBe(1);
});
