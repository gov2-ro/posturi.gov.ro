// UX-FOLLOW-EMP and UX-SALVATE-FLUX browser checks: follow an employer, the three tabs of /salvate/,
// and the Flux tab (chips, stats, hide rules, empty states). Runs at 320, 375 and 1280 (see
// playwright.config.js) against the deterministic fixture (tests/fixtures/build_db.php), server day 2026-10-03.
//
// Fixture facts the checks rely on (open postings only):
//   Spitalul Clinic Județean Cluj  1001 1006 1007   all Cluj; domains sănătate, juridic, tehnic; net 2485, 3306, 1841
//   Primăria Comunei Găiceana      1002 1004        both Bacău; domains administrație, social; net 2017, 1724
//   Administrația Tunari           1005 1008 + 30   all Ilfov; 1005 and 1008 are „tehnic”; 1003 is closed
const { test, expect } = require('@playwright/test');

const E_CLUJ = 'spitalul-clinic-judetean-cluj';
const E_BACAU = 'primaria-comunei-gaiceana';
const E_ILFOV = 'administratia-de-salubrizare-tunari';
const N_CLUJ = 'Spitalul Clinic Județean Cluj';
const N_BACAU = 'Primăria Comunei Găiceana';
const N_ILFOV = 'Administrația de Salubrizare Tunari';
const K_CLUJ = 'judet%5B%5D=cluj';
const K_NOTHING = 'judet%5B%5D=cluj&q=nimic';

const EMP_KEY = 'posturi.employers.v1';
const SEARCH_KEY = 'posturi.searches.v1';
const PREF_KEY = 'posturi.preferences.v1';

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

const at = (i) => new Date(Date.UTC(2026, 9, 1, 10, 0, 0) + i * 1000).toISOString();

/** Write the three stores; needs an origin, so visit a light page first. */
async function seed(page, { employers = [], searches = [], saved = [], hidden = [] } = {}) {
  await page.goto('/despre/');
  await page.evaluate(([ek, sk, pk, emps, srch, sv, hid]) => {
    const iso = (i) => new Date(Date.UTC(2026, 9, 1, 10, 0, 0) + i * 1000).toISOString();
    localStorage.setItem(ek, JSON.stringify({ version: 1, employers: emps.map((e, i) => ({ slug: e.slug, name: e.name, addedAt: iso(i) })) }));
    localStorage.setItem(sk, JSON.stringify({
      version: 1, defaultId: null,
      searches: Object.fromEntries(srch.map((s, i) => [s.key, { key: s.key, qs: s.key, sort: '', label: s.label, savedAt: iso(i), openedAt: null }])),
    }));
    const rec = (id, o) => ({
      id, url: `https://posturi.gov.ro/anunt/fixture-${id}`, path: `/job/${id}-x/`, title: `Titlu ${id}`, employer: 'Angajator',
      place: 'Cluj', date: '2026-10-16', dateSource: 'anunt', savedAt: null, hiddenAt: null, snapshotAt: iso(0), ...o,
    });
    const records = {};
    sv.forEach((id, i) => { const r = rec(id, { savedAt: iso(i) }); records[r.url] = r; });
    hid.forEach((id, i) => { const r = rec(id, { hiddenAt: iso(i) }); records[r.url] = r; });
    localStorage.setItem(pk, JSON.stringify({ version: 1, records }));
  }, [EMP_KEY, SEARCH_KEY, PREF_KEY, employers, searches, saved, hidden]);
}
const storedEmployers = (page) => page.evaluate((k) => JSON.parse(localStorage.getItem(k)), EMP_KEY);

const cards = (page) => page.locator('[data-flux-list] > li');
const ids = async (page) => (await cards(page).evaluateAll((els) => els.map((e) => Number(e.getAttribute('data-pref-id')))));
const chip = (page, label) => page.locator('[data-flux-chips] button').filter({ hasText: label });
const stat = (page, name) => page.locator(`[data-flux-stats] [data-stat="${name}"]`);

// ---- Part A: follow an employer ---------------------------------------------------------------------

test('an employer page: follow, reload, unfollow; the navbar counts it; the alert link carries the employer key', async ({ page }) => {
  await page.goto(`/angajator/${E_CLUJ}/`);
  const btn = page.locator('[data-employer-follow]');
  await expect(btn).toBeVisible();
  await expect(btn).toHaveText('Urmărește angajatorul');
  await expect(btn).toHaveAttribute('aria-pressed', 'false');
  await expect(page.locator('[data-saved-count]')).toHaveText('(0)');

  await btn.click();
  await expect(btn).toHaveText('Nu mai urmări');
  await expect(btn).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-employer-msg]')).toContainText('Urmărești acest angajator');
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  const store = await storedEmployers(page);
  expect(store.version).toBe(1);
  expect(store.employers).toHaveLength(1);
  expect(store.employers[0]).toMatchObject({ slug: E_CLUJ, name: N_CLUJ });
  expect(store.employers[0].addedAt).toMatch(/^\d{4}-\d{2}-\d{2}T/);

  await page.reload();
  await expect(btn).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');

  await btn.click();
  await expect(btn).toHaveText('Urmărește angajatorul');
  await expect(page.locator('[data-employer-msg]')).toContainText('nu mai este urmărit');
  expect((await storedEmployers(page)).employers).toHaveLength(0);
  await expect(page.locator('[data-saved-count]')).toHaveText('(0)');

  // The alert link: the subscribe form, with the key `employer=<slug>` and the employer name as the label.
  const href = await page.locator('[data-employer-alert]').getAttribute('href');
  const u = new URL(href, 'http://x');
  expect(u.pathname).toBe('/alerte/abonare/');
  expect(u.searchParams.get('cheie')).toBe(`employer=${E_CLUJ}`);
  expect(u.searchParams.get('nume')).toBe(N_CLUJ);
  await page.locator('[data-employer-alert]').click();
  await expect(page.getByRole('heading', { name: 'Alertă pe email' })).toBeVisible();
  await expect(page.getByText(N_CLUJ).first()).toBeVisible();
  await expect(page.locator('input[name="cheie"]')).toHaveValue(`employer=${E_CLUJ}`);
  await noOverflow(page, 'subscribe form');
});

test('an employer page: the follow toggle fits and is reachable at every width', async ({ page }) => {
  await page.goto(`/angajator/${E_CLUJ}/`);
  await expect(page.locator('[data-employer-follow]')).toBeVisible();
  await noOverflow(page, 'employer page');
  const vw = page.viewportSize().width;
  for (const g of await page.locator('[data-employer-follow-block] .follow-group').all()) {
    const box = await g.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(vw + 1);
  }
});

test('the cap of 10: a full list refuses the 11th and keeps every old entry', async ({ page }) => {
  const ten = Array.from({ length: 10 }, (_, i) => ({ slug: `angajator-${i}`, name: `Angajator ${i}` }));
  await seed(page, { employers: ten });
  await page.goto(`/angajator/${E_CLUJ}/`);
  const btn = page.locator('[data-employer-follow]');
  await expect(page.locator('[data-saved-count]')).toHaveText('(10)');
  await btn.click();
  await expect(btn).toHaveAttribute('aria-pressed', 'false');
  const limit = page.locator('[data-employer-limit]');
  await expect(limit).toBeVisible();
  await expect(limit).toContainText('Urmărești deja 10 angajatori');
  await expect(limit.getByRole('link', { name: 'Salvate' })).toHaveAttribute('href', '/salvate/?vedere=liste');
  const store = await storedEmployers(page);
  expect(store.employers.map((e) => e.slug)).toEqual(ten.map((e) => e.slug));
  await noOverflow(page, 'cap message');
  // After an unfollow elsewhere the toggle works again, and the message goes away.
  await page.evaluate((k) => {
    const d = JSON.parse(localStorage.getItem(k));
    d.employers.pop();
    localStorage.setItem(k, JSON.stringify(d));
  }, EMP_KEY);
  await btn.click();
  await expect(btn).toHaveAttribute('aria-pressed', 'true');
  await expect(limit).toBeHidden();
});

test('without JavaScript the toggle stays hidden and the alert link still works', async ({ browser, baseURL }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL });
  const page = await ctx.newPage();
  await page.goto(`/angajator/${E_CLUJ}/`);
  await expect(page.locator('[data-employer-follow]')).toBeHidden();
  await expect(page.locator('[data-employer-alert]')).toBeVisible();
  await ctx.close();
});

test('a corrupt employers store: no error, the toggle stays hidden, the value is kept', async ({ page }) => {
  await page.goto('/despre/');
  await page.evaluate((k) => localStorage.setItem(k, '{"version":1,"employers":['), EMP_KEY);
  await page.goto(`/angajator/${E_CLUJ}/`);
  await expect(page.locator('[data-employer-follow]')).toBeHidden();
  expect(await page.evaluate((k) => localStorage.getItem(k), EMP_KEY)).toBe('{"version":1,"employers":[');
  await expect(page.locator('[data-saved-count]')).toHaveText('(0)');
});

// ---- Part B: the tabs ---------------------------------------------------------------------------------

test('/salvate/ has three tabs: Flux is the default, Liste holds the lists in order, Ascunse is the old hidden view', async ({ page }) => {
  await seed(page, { employers: [{ slug: E_CLUJ, name: N_CLUJ }], searches: [{ key: K_CLUJ, label: 'Cluj' }], saved: [1001] });
  await page.goto('/salvate/');
  const tabs = page.locator('[data-view-tab]');
  await expect(tabs).toHaveCount(3);
  await expect(tabs.nth(0)).toHaveText('Flux');
  await expect(tabs.nth(1)).toHaveText('Liste');
  await expect(tabs.nth(2)).toContainText('Ascunse');
  await expect(page.locator('[data-view-tab="flux"]')).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('[data-flux-panel]')).toBeVisible();
  await expect(page.locator('[data-searches-panel]')).toBeHidden();
  await expect(page.locator('[data-employers-panel]')).toBeHidden();
  await expect(page.locator('[data-saved-list]')).toBeHidden();
  await noOverflow(page, 'Flux');

  await page.locator('[data-view-tab="saved"]').click();
  await expect(page).toHaveURL(/\/salvate\/\?vedere=liste$/);
  await expect(page.locator('[data-view-tab="saved"]')).toHaveAttribute('aria-current', 'page');
  await expect(page.locator('[data-flux-panel]')).toBeHidden();
  const order = await page.evaluate(() => ['[data-alerts-block]', '[data-searches-panel]', '[data-employers-panel]', '[data-saved-heading]', '#saved-tools']
    .map((s) => document.querySelector(s).getBoundingClientRect().top));
  expect([...order].sort((a, b) => a - b)).toEqual(order);
  await noOverflow(page, 'Liste');

  await page.locator('[data-view-tab="hidden"]').click();
  await expect(page).toHaveURL(/\/salvate\/\?vedere=ascunse$/);
  await expect(page.locator('[data-flux-panel]')).toBeHidden();
  await expect(page.locator('[data-searches-panel]')).toBeHidden();
  await expect(page.locator('[data-employers-panel]')).toBeHidden();
  await expect(page.locator('[data-saved-heading-text]')).toHaveText('Anunțuri ascunse');

  // Old links: ?vedere=notite and ?vedere=cautari open Liste.
  for (const v of ['notite', 'cautari']) {
    await page.goto(`/salvate/?vedere=${v}`);
    await expect(page.locator('[data-view-tab="saved"]')).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('[data-employers-panel]')).toBeVisible();
  }
});

test('Liste: „Angajatori urmăriți” lists name, unfollow, RSS and email alert; „Gestionare” clears them after a confirm', async ({ page }) => {
  await seed(page, { employers: [{ slug: E_CLUJ, name: N_CLUJ }, { slug: E_BACAU, name: N_BACAU }] });
  await page.goto('/salvate/?vedere=liste');
  await expect(page.locator('[data-count="employers"]')).toHaveText('(2)');
  await expect(page.locator('[data-saved-count]')).toHaveText('(2)');
  const rows = page.locator('[data-employers-list] > li');
  await expect(rows).toHaveCount(2);
  const row = page.locator(`[data-employer-slug="${E_CLUJ}"]`);
  await expect(row.locator('h3 a')).toHaveText(N_CLUJ);
  await expect(row.locator('h3 a')).toHaveAttribute('href', `/angajator/${E_CLUJ}/`);
  await expect(row.getByRole('button', { name: /Nu mai urmări/ })).toBeVisible();
  await expect(row.getByRole('link', { name: 'RSS' })).toHaveAttribute('href', `/posturi.rss?employer=${E_CLUJ}`);
  const alert = row.getByRole('link', { name: /Alertă pe email/ });
  const u = new URL(await alert.getAttribute('href'), 'http://x');
  expect(u.pathname).toBe('/alerte/abonare/');
  expect(u.searchParams.get('cheie')).toBe(`employer=${E_CLUJ}`);
  expect(u.searchParams.get('nume')).toBe(N_CLUJ);
  await noOverflow(page, 'employers section');

  await row.getByRole('button', { name: /Nu mai urmări/ }).click();
  await expect(rows).toHaveCount(1);
  await expect(page.locator('[data-saved-count]')).toHaveText('(1)');
  expect((await storedEmployers(page)).employers.map((e) => e.slug)).toEqual([E_BACAU]);

  // Gestionare: a confirm first; „Anulează” changes nothing.
  await page.locator('[data-clear-employers]').click();
  await expect(page.locator('[data-employers-confirm]')).toBeVisible();
  await expect(page.locator('[data-employers-confirm-text]')).toContainText('Ștergi angajatorul urmărit din acest browser');
  await expect(page.locator('[data-employers-no]')).toBeFocused();
  await page.locator('[data-employers-no]').click();
  await expect(rows).toHaveCount(1);
  await page.locator('[data-clear-employers]').click();
  await page.locator('[data-employers-yes]').click();
  await expect(rows).toHaveCount(0);
  await expect(page.locator('[data-employers-empty]')).toBeVisible();
  await expect(page.locator('[data-clear-employers]')).toBeDisabled();
  expect((await storedEmployers(page)).employers).toEqual([]);
});

test('Liste: an alert asked from an employer is marked in this browser', async ({ page }) => {
  await seed(page, { employers: [{ slug: E_CLUJ, name: N_CLUJ }] });
  await page.evaluate(() => localStorage.setItem('posturi.alerts.v1', JSON.stringify({ 'employer=spitalul-clinic-judetean-cluj': '2026-10-02T10:00:00.000Z' })));
  await page.goto('/salvate/?vedere=liste');
  await expect(page.locator(`[data-employer-slug="${E_CLUJ}"]`).getByText('alertă cerută')).toBeVisible();
});

// ---- Part C: the Flux tab -------------------------------------------------------------------------------

async function seedFlux(page, extra = {}) {
  await seed(page, {
    searches: [{ key: K_CLUJ, label: 'Cluj' }],
    employers: [{ slug: E_BACAU, name: N_BACAU }],
    saved: [1005, 1008],
    ...extra,
  });
}

test('Flux: one request, with only keys, slugs and ids; one list without duplicates', async ({ page }, testInfo) => {
  const four = [K_CLUJ, 'judet%5B%5D=bacau', 'judet%5B%5D=ilfov', K_NOTHING].map((key, i) => ({ key, label: `Căutarea ${i + 1}` }));
  const ten = [E_CLUJ, E_BACAU, E_ILFOV, ...Array.from({ length: 7 }, (_, i) => `angajator-${i}`)].map((slug) => ({ slug, name: slug }));
  await seed(page, { searches: four, employers: ten, saved: [1001, 1002, 1005, 1008] });
  const calls = [];
  page.on('request', (r) => { if (r.url().includes('/flux.json')) calls.push(r); });
  const posts = [];
  page.on('request', (r) => { if (r.method() === 'POST') posts.push(r.url()); });
  const done = page.waitForResponse((r) => r.url().includes('/flux.json'));
  await page.goto('/salvate/');
  const res = await done;
  await expect(cards(page).first()).toBeVisible();
  expect(res.status()).toBe(200);
  expect(res.headers()['cache-control']).toBe('no-store');
  expect(calls).toHaveLength(1);
  expect(calls[0].method()).toBe('GET');
  const u = new URL(calls[0].url());
  expect(u.searchParams.getAll('s[]')).toHaveLength(4);
  expect(u.searchParams.getAll('e[]')).toHaveLength(10);
  expect(u.searchParams.getAll('id[]').sort()).toEqual(['1001', '1002', '1005', '1008']);
  expect([...u.searchParams.keys()].every((k) => ['s[]', 'e[]', 'id[]'].includes(k))).toBe(true);
  expect(posts, 'no POST from the Flux').toEqual([]);
  // 1001 and 1002 are in the saved ids and in a source: still one card each.
  const answer = await res.json();
  const got = answer.rows.map((r) => r.id);
  expect(new Set(got).size, 'every posting once').toBe(got.length);
  expect(got).toContain(1001);
  expect(answer.rows.find((r) => r.id === 1001).src.length, '1001 is in several sources').toBeGreaterThan(1);
  await expect(page.locator('[data-flux-count]')).toHaveText(`(${got.length})`);
  const timing = calls[0].timing();
  console.log(`flux.json (${testInfo.project.name}, 4 searches + 10 employers + 4 ids): ${Math.round(timing.responseEnd - timing.requestStart)} ms, ${got.length} rows`);
});

test('Flux: the chips, one for each source, with counts; a source with no open posting stays, greyed', async ({ page }) => {
  await seedFlux(page, { searches: [{ key: K_CLUJ, label: 'Cluj' }, { key: K_NOTHING, label: 'Nimic' }] });
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  await expect(page.locator('[data-flux-count]')).toHaveText('(7)');
  const chips = page.locator('[data-flux-chips] button');
  await expect(chips).toHaveCount(5);
  await expect(chip(page, 'Toate')).toContainText('7');
  await expect(chip(page, 'Toate')).toHaveAttribute('aria-pressed', 'true');
  await expect(chip(page, 'Cluj')).toContainText('3');
  await expect(chip(page, N_BACAU)).toContainText('2');
  await expect(chip(page, 'Salvate')).toContainText('2');
  await expect(chip(page, 'Nimic')).toContainText('0');
  await expect(chip(page, 'Nimic')).toHaveAttribute('data-empty', '');
  await expect(chip(page, 'Nimic')).toBeVisible();
  // Each card names its source.
  await expect(page.locator('[data-flux-list] li[data-pref-id="1001"] .g2-tag')).toHaveText(['Cluj']);
  await expect(page.locator('[data-flux-list] li[data-pref-id="1002"] .g2-tag')).toHaveText([N_BACAU]);
  await expect(page.locator('[data-flux-list] li[data-pref-id="1005"] .g2-tag')).toHaveText(['Salvate']);
  await noOverflow(page, 'chips');

  // Toggles: several at once are a union; „Toate” resets.
  await chip(page, 'Salvate').click();
  await expect(chip(page, 'Salvate')).toHaveAttribute('aria-pressed', 'true');
  await expect(chip(page, 'Toate')).toHaveAttribute('aria-pressed', 'false');
  expect((await ids(page)).sort()).toEqual([1005, 1008]);
  await chip(page, 'Cluj').click();
  expect((await ids(page)).sort()).toEqual([1001, 1005, 1006, 1007, 1008]);
  await chip(page, 'Salvate').click();
  expect((await ids(page)).sort()).toEqual([1001, 1006, 1007]);
  await chip(page, 'Toate').click();
  expect(await ids(page)).toHaveLength(7);
  await expect(chip(page, 'Toate')).toHaveAttribute('aria-pressed', 'true');
});

test('Flux: the stats follow the chips; each block hides when it tells nothing', async ({ page }) => {
  await seedFlux(page);
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  const counts = stat(page, 'counts');

  // All sources: 7 postings in 3 counties and 5 domains, 7 salaries.
  await expect(counts).toContainText('7');
  await expect(counts).toContainText('anunțuri deschise');
  await expect(counts).toContainText('noi în ultimele 3 zile: 2');
  await expect(counts).toContainText('se închid în 7 zile: 1');
  await expect(stat(page, 'county')).toBeVisible();
  await expect(stat(page, 'county')).toContainText('Cluj');
  await expect(stat(page, 'county')).toContainText('Bacău');
  await expect(stat(page, 'county')).toContainText('Ilfov');
  await expect(stat(page, 'domain')).toBeVisible();
  await expect(stat(page, 'domain')).toContainText('Tehnic');
  await expect(stat(page, 'salary')).toContainText('1.549 – 2.017 – 3.306 lei');
  await expect(stat(page, 'salary')).toContainText('din 7 anunțuri');

  // „Salvate” (1005, 1008): one county, one domain, two salaries, nothing new, nothing closing.
  await chip(page, 'Salvate').click();
  await expect(counts).toContainText('2');
  await expect(counts).toContainText('anunțuri deschise');
  await expect(counts).not.toContainText('noi în ultimele');
  await expect(counts).not.toContainText('se închid');
  await expect(stat(page, 'county')).toHaveCount(0);
  await expect(stat(page, 'domain')).toHaveCount(0);
  await expect(stat(page, 'salary')).toHaveCount(0);

  // The Cluj search (1001, 1006, 1007): one county (hidden), three domains, three salaries.
  await chip(page, 'Salvate').click();
  await chip(page, 'Cluj').click();
  await expect(counts).toContainText('3');
  await expect(stat(page, 'county')).toHaveCount(0);
  await expect(stat(page, 'domain')).toBeVisible();
  await expect(stat(page, 'domain')).not.toContainText('alte');
  await expect(stat(page, 'salary')).toContainText('1.841 – 2.485 – 3.306 lei');

  // One posting: „anunț deschis”, and no list of one.
  await page.reload();
  await chip(page, 'Salvate').click();
  await page.locator('[data-flux-list] li[data-pref-id="1005"] [data-pref="hide"]').click();
  await expect(counts).toContainText('1');
  await expect(counts).toContainText('anunț deschis');
  await noOverflow(page, 'stats');
});

test('Flux: the county and domain blocks list the postings of the sources by count', async ({ page }) => {
  await seed(page, { employers: [{ slug: E_CLUJ, name: N_CLUJ }, { slug: E_BACAU, name: N_BACAU }, { slug: E_ILFOV, name: N_ILFOV }] });
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  // 1001 sănătate, 1006 juridic, 1007 tehnic, 1008 tehnic, 1005 tehnic, 1002 administrație, 1004 social: 5 domains, no „alte”.
  const rows = stat(page, 'domain').locator('li');
  await expect(rows).toHaveCount(5);
  await expect(rows.first()).toContainText('Tehnic');
  await expect(rows.first()).toContainText('3');
  await expect(stat(page, 'domain')).not.toContainText('alte');
  await expect(stat(page, 'county').locator('li')).toHaveCount(3);
});

test('Flux: a block shows the top 5 and „alte N” (a made-up answer with 8 counties and 7 domains)', async ({ page }) => {
  const counties = ['Alba', 'Arad', 'Argeș', 'Bacău', 'Bihor', 'Brașov', 'Cluj', 'Iași'];
  const rows = counties.map((c, i) => ({
    id: 9000 + i, title: `Post ${i}`, url: `/job/${9000 + i}-post/`, source_url: `https://posturi.gov.ro/anunt/mock-${i}`,
    employer: 'Angajator', employer_slug: null, judet: c, locality: '', place: c, domain: i < 7 ? `domeniul ${i % 7}` : null,
    salary: null, published_at: '2026-10-01', apply_deadline: '2026-10-20', deadline_source: 'anunt', src: [0],
  }));
  // Two postings more in Cluj, so that the order is by count then name.
  rows.push({ ...rows[6], id: 9100, url: '/job/9100-post/', source_url: 'https://posturi.gov.ro/anunt/mock-100' });
  rows.push({ ...rows[6], id: 9101, url: '/job/9101-post/', source_url: 'https://posturi.gov.ro/anunt/mock-101' });
  await seed(page, { employers: [{ slug: E_CLUJ, name: N_CLUJ }] });
  await page.route('**/flux.json*', (route) => route.fulfill({
    status: 200, contentType: 'application/json',
    body: JSON.stringify({ today: '2026-10-03', rows, sources: [{ kind: 'employer', ref: E_CLUJ, total: rows.length, truncated: false }] }),
  }));
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  const county = stat(page, 'county');
  await expect(county.locator('li')).toHaveCount(5);
  await expect(county.locator('li').first()).toContainText('Cluj');
  await expect(county.locator('li').first()).toContainText('3');
  await expect(county).toContainText('alte 3');
  const domain = stat(page, 'domain');
  await expect(domain.locator('li')).toHaveCount(5);
  await expect(domain).toContainText('alte 2');
  await expect(stat(page, 'salary')).toHaveCount(0);
  await noOverflow(page, 'top 5');
});

test('Flux: the list sorts by deadline (closest first, none last) or by newest; 25 to a page', async ({ page }) => {
  await seed(page, { employers: [{ slug: E_BACAU, name: N_BACAU }, { slug: E_ILFOV, name: N_ILFOV }] });
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  await expect(page.locator('[data-flux-sort="deadline"]')).toHaveAttribute('aria-pressed', 'true');
  // 36 postings: 1002 closes today, then the volume rows (10-05 …), 1004 on 10-20, 1008 on 10-21, 1005 without a date.
  await expect(cards(page)).toHaveCount(25);
  expect((await ids(page))[0]).toBe(1002);
  await expect(page.locator('[data-flux-pager]')).toBeVisible();
  await expect(page.locator('[data-flux-pager-info]')).toHaveText('1–25 din 34');
  await expect(page.locator('[data-flux-prev]')).toBeHidden();
  await page.locator('[data-flux-next]').click();
  await expect(cards(page)).toHaveCount(9);
  await expect(page.locator('[data-flux-pager-info]')).toHaveText('26–34 din 34');
  expect((await ids(page)).slice(-1)[0]).toBe(1005);
  await expect(page.locator('[data-flux-next]')).toBeHidden();

  await page.locator('[data-flux-sort="new"]').click();
  await expect(page.locator('[data-flux-sort="new"]')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-flux-pager-info]')).toHaveText('1–25 din 34');
  expect((await ids(page)).slice(0, 3)).toEqual([1002, 1004, 1008]);   // 09-30, 09-28, 09-26
  await noOverflow(page, 'pager');
});

test('Flux: a hidden posting is removed before anything is counted; hide and undo redraw the list and the stats', async ({ page }) => {
  await seedFlux(page, { hidden: [1002] });
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  expect(await ids(page)).not.toContain(1002);
  await expect(page.locator('[data-flux-count]')).toHaveText('(6)');
  await expect(chip(page, N_BACAU)).toContainText('1');
  await expect(stat(page, 'counts')).toContainText('6');
  await expect(stat(page, 'counts')).not.toContainText('se închid');   // 1002 was the one closing in 7 days

  // Hide a card from the Flux: it leaves and the stats follow.
  const card = page.locator('[data-flux-list] li[data-pref-id="1006"]');
  await card.locator('[data-pref="hide"]').click();
  await expect(page.locator('[data-flux-list] li[data-pref-id="1006"]')).toHaveCount(0);
  await expect(page.locator('[data-flux-count]')).toHaveText('(5)');
  await expect(chip(page, 'Cluj')).toContainText('2');
  await expect(page.locator('#pref-notice')).toBeVisible();
  await page.locator('[data-notice-undo]').click();
  await expect(page.locator('[data-flux-list] li[data-pref-id="1006"]')).toHaveCount(1);
  await expect(page.locator('[data-flux-count]')).toHaveText('(6)');
  // The hidden posting stays in the Ascunse tab.
  await page.goto('/salvate/?vedere=ascunse');
  await expect(page.locator('[data-saved-list] li[data-pref-id="1002"]')).toHaveCount(1);
});

test('Flux: save and note work on a card as on the list', async ({ page }) => {
  await seedFlux(page);
  await page.goto('/salvate/');
  const card = page.locator('[data-flux-list] li[data-pref-id="1001"]');
  await expect(card).toBeVisible();
  const save = card.locator('[data-pref="save"]');
  await expect(save).toContainText('Salvează');
  await save.click();
  await expect(save).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-saved-count]')).toHaveText('(5)');   // 2 saved + 1 search + 1 employer, plus this one
  const rec = await page.evaluate((k) => JSON.parse(localStorage.getItem(k)).records['https://posturi.gov.ro/anunt/fixture-1001'], PREF_KEY);
  expect(rec.savedAt).toBeTruthy();
  expect(rec.title).toBe('Asistent medical');            // the card's own snapshot
  expect(rec.employer).toBe(N_CLUJ);
  await expect(page.locator('[data-flux-list] li[data-pref-id="1001"]')).toHaveCount(1);   // saving does not redraw or remove the card
  await card.locator('[data-pref="note"]').click();
  await card.locator('textarea').fill('de sunat luni');
  await card.locator('[data-note-save]').click();
  await expect(card.locator('[data-note-view]')).toContainText('de sunat luni');
});

test('Flux: empty states — no source, and sources without an open posting', async ({ page }) => {
  await seed(page, {});
  await page.goto('/salvate/');
  const none = page.locator('[data-flux-empty="none"]');
  await expect(none).toBeVisible();
  await expect(none).toContainText('Nu ai nicio sursă');
  await expect(none.getByRole('link', { name: 'Caută anunțuri' })).toHaveAttribute('href', '/posturi/');
  await expect(none.getByRole('link', { name: 'Vezi angajatorii' })).toHaveAttribute('href', '/angajatori/');
  await expect(page.locator('[data-flux-body]')).toBeHidden();
  await noOverflow(page, 'empty: none');

  // A source that selects no open posting: the chip stays, the message points to Liste.
  await seed(page, { searches: [{ key: K_NOTHING, label: 'Nimic' }] });
  await page.goto('/salvate/');
  const closed = page.locator('[data-flux-empty="closed"]');
  await expect(closed).toBeVisible();
  await expect(closed).toContainText('niciun anunț deschis');
  await expect(closed.getByRole('link', { name: 'Vezi listele tale' })).toHaveAttribute('href', '/salvate/?vedere=liste');
  await expect(chip(page, 'Nimic')).toContainText('0');
  await expect(page.locator('[data-flux-list]')).toBeHidden();
  await expect(page.locator('[data-flux-stats]')).toBeHidden();
  await noOverflow(page, 'empty: closed');

  // Every posting hidden is the same state.
  await seed(page, { saved: [1005] });
  await page.evaluate((k) => {
    const d = JSON.parse(localStorage.getItem(k));
    d.records['https://posturi.gov.ro/anunt/fixture-1005'].hiddenAt = new Date().toISOString();
    localStorage.setItem(k, JSON.stringify(d));
  }, PREF_KEY);
  await page.goto('/salvate/');
  await expect(page.locator('[data-flux-empty="closed"]')).toBeVisible();
});

test('Flux: an unknown employer or a closed saved id is a source with nothing in it, not an error', async ({ page }) => {
  await seed(page, { employers: [{ slug: 'nu-exista', name: 'Angajator plecat' }, { slug: E_CLUJ, name: N_CLUJ }], saved: [1003] });
  await page.goto('/salvate/');
  await expect(cards(page).first()).toBeVisible();
  expect((await ids(page)).sort()).toEqual([1001, 1006, 1007]);
  await expect(chip(page, 'Angajator plecat')).toContainText('indisponibilă');
  await expect(chip(page, 'Angajator plecat')).toContainText('0');
  await expect(chip(page, 'Salvate')).toContainText('0');           // 1003 is closed
  await expect(page.locator('[data-flux-notes]')).toContainText('nu mai există în baza curentă');
});

test('Flux: a failed request shows a retry', async ({ page }) => {
  await seedFlux(page);
  let fail = true;
  await page.route('**/flux.json*', (route) => (fail ? route.fulfill({ status: 500, body: '{}' }) : route.continue()));
  await page.goto('/salvate/');
  await expect(page.locator('[data-flux-error]')).toBeVisible();
  fail = false;
  await page.locator('[data-flux-retry]').click();
  await expect(cards(page).first()).toBeVisible();
  await expect(page.locator('[data-flux-error]')).toBeHidden();
});

test('Flux without JavaScript: the noscript text, no list', async ({ browser, baseURL }) => {
  const ctx = await browser.newContext({ javaScriptEnabled: false, baseURL });
  const page = await ctx.newPage();
  await page.goto('/salvate/');
  await expect(page.locator('[data-saved-page] noscript').first()).toContainText('poate fi citită doar cu JavaScript activat', { useInnerText: true });
  await expect(page.locator('[data-flux-list] > li')).toHaveCount(0);
  await ctx.close();
});

test('Flux: a saved search renamed in another tab updates its chip; no new request for a label change', async ({ page, context }) => {
  await seedFlux(page);
  const calls = [];
  page.on('request', (r) => { if (r.url().includes('/flux.json')) calls.push(r.url()); });
  await page.goto('/salvate/');
  await expect(chip(page, 'Cluj')).toBeVisible();
  const other = await context.newPage();
  other.on('pageerror', (e) => pageErrors.get(page).push(e.message));
  await other.goto('/salvate/?vedere=liste');
  await other.locator('[data-search-rename]').first().click();
  await other.locator('[data-search-key] input').fill('Medici Cluj');
  await other.locator('[data-search-rename-save]').click();
  await expect(chip(page, 'Medici Cluj')).toBeVisible();
  expect(calls).toHaveLength(1);
  // A new employer in the other tab changes the request: the Flux asks again.
  await other.evaluate((k) => {
    const d = JSON.parse(localStorage.getItem(k));
    d.employers.push({ slug: 'spitalul-clinic-judetean-cluj', name: 'Spitalul Clinic Județean Cluj', addedAt: new Date().toISOString() });
    localStorage.setItem(k, JSON.stringify(d));
  }, EMP_KEY);
  await expect(chip(page, N_CLUJ)).toBeVisible();
  expect(calls).toHaveLength(2);
  await other.close();
});
