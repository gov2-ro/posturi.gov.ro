// FIX-09 browser checks for /statistici: the page labels the dataset of every figure (current
// export vs. historical aggregate), splits exported / confirmed open / uncertain / closed,
// keeps announced and estimated pay apart and names the source's category field for what it is.
//
// FIX-10 browser checks for /statistici: the "Costuri inferență" section reads the
// ledger-shaped llm_costs rows of the fixture (tests/fixtures/build_db.php): one legacy
// day (02.10, 3 postings, $0.01) and one ledger day (04.10: schema 5 calls $0.02 over
// 4 postings, one unpriced; occupations 2 calls $0.002). Runs at 320, 375 and 1280.
const { test, expect } = require('@playwright/test');

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

const costs = (page) => page.locator('section', { has: page.getByRole('heading', { name: 'Costuri inferență' }) });
const card = (section, label) => section.locator('.grid > div').filter({ has: section.page().getByText(label, { exact: true }) });

test('/statistici: inference costs are measured per step, legacy days marked as estimates', async ({ page }) => {
  const res = await page.goto('/statistici/');
  expect(res.status()).toBe(200);

  const overflow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(overflow, 'horizontal overflow on /statistici/').toBeLessThanOrEqual(1);

  const s = costs(page);
  await expect(s).toBeVisible();

  // $0.01 legacy + $0.02 schema + $0.002 occupations.
  await expect(card(s, 'Total')).toContainText('$0.032');
  // Schema spend only, over valid extractions (a re-extraction counts again): 100 × $0.03 / 7.
  const per100 = card(s, 'La 100 de extrageri');
  await expect(per100).toBeVisible();
  await expect(per100).toContainText('$0.429');
  await expect(per100).toContainText('7 extrageri; un anunț reextras se numără din nou');
  await expect(s).not.toContainText('procesate');

  const steps = s.locator('ul > li');
  await expect(steps).toHaveCount(2);
  await expect(steps.nth(0)).toContainText('Extracția structurată · 5 apeluri');
  await expect(steps.nth(0)).toContainText('$0.020');
  await expect(steps.nth(1)).toContainText('Normalizarea ocupațiilor · 2 apeluri');
  await expect(steps.nth(1)).toContainText('$0.002');

  await expect(s.getByText('04.10.2026 · 7 apeluri', { exact: true })).toBeVisible();
  await expect(s.getByText('02.10.2026 · 3 anunțuri (estimare)', { exact: true })).toBeVisible();

  await expect(s).toContainText('Apeluri fără cost cunoscut, neincluse în sume: 1.');
  await expect(s).not.toContainText('Estimare incompletă');
});


const section = (page, id) => page.locator('#' + id);
const num = (scope, label) => scope.locator('div', { hasText: new RegExp('^' + label + '$') }).first().locator('xpath=preceding-sibling::div[1]');

test('/statistici: current export and history are separate, labelled sections', async ({ page }) => {
  await page.goto('/statistici/');

  const overflow = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(overflow, 'horizontal overflow on /statistici/').toBeLessThanOrEqual(1);

  const scope = section(page, 'domeniu');
  await expect(scope).toBeVisible();
  await expect(scope).toContainText('Ce descriu cifrele de pe această pagină');
  await expect(scope).toContainText('doar anunțurile încă neexpirate și neanulate la data generării; generat la 03.10.2026, 12:00');
  await expect(scope).toContainText('Nu este arhiva sursei');

  const current = section(page, 'export-curent');
  await expect(current.getByRole('heading', { name: 'Export curent', level: 2 })).toBeVisible();
  // Exported / confirmed open / uncertain / closed are four figures, not one "active" total.
  await expect(num(current, 'Anunțuri în export')).toHaveText('38');
  await expect(num(current, 'Deschise, termen confirmat')).toHaveText('35');
  await expect(num(current, 'Termen neconfirmat')).toHaveText('2');
  await expect(num(current, 'Închise')).toHaveText('1');
  await expect(current).toContainText('Active în căutare (deschise confirmate + cu termen neconfirmat): 37 din 38');
  await expect(current).not.toContainText('Anunțuri totale');

  // Pay: announced and estimated are separate figures, the estimate says it is not an offer.
  await expect(num(current, 'Cu salariu anunțat')).toHaveText('0');
  await expect(num(current, 'Doar salariu estimat')).toHaveText('38');
  await expect(current).toContainText('nu oferit de angajator');

  // The source's category field is not an employer type.
  const cat = section(page, 'categorie-sursa');
  await expect(cat.getByRole('heading', { name: 'Categoria din anunț (câmp al sursei)' })).toBeVisible();
  await expect(cat).toContainText('nu un tip de angajator');
  await expect(cat).toContainText('Necunoscut (necompletat în sursă)');
  await expect(page.getByText('Tip angajator')).toHaveCount(0);

  // History: all of the database, its own totals, undated and cancelled said, not an open-stock curve.
  const hist = section(page, 'istoric');
  await expect(hist.getByRole('heading', { name: 'Istoric: anunțuri publicate pe lună', level: 2 })).toBeVisible();
  await expect(num(hist, 'Anunțuri publicate, în total')).toHaveText('152');
  await expect(num(hist, 'Din care anulate')).toHaveText('8');
  await expect(num(hist, 'Fără dată de publicare')).toHaveText('3');
  await expect(hist).toContainText('nu câte posturi erau deschise la o anumită dată');
  await expect(section(page, 'luna-istoric')).toContainText('2026-09');
  await expect(section(page, 'luna-istoric')).toContainText('70');
  // The slice-only monthly chart is replaced by the real history.
  await expect(page.locator('#luna-export')).toHaveCount(0);
  await expect(hist).not.toContainText('indisponibil');

  // The ledger-backed cost section says it covers the whole database.
  await expect(costs(page)).toContainText('nu doar anunțurile din exportul curent');
});

test('/statistici: bars stay inside their panels', async ({ page }) => {
  await page.goto('/statistici/');
  for (const id of ['categorie-sursa', 'luna-istoric', 'judete-istoric']) {
    const box = await section(page, id).boundingBox();
    const vw = page.viewportSize().width;
    expect(box.x, id + ' left edge').toBeGreaterThanOrEqual(0);
    expect(box.x + box.width, id + ' right edge').toBeLessThanOrEqual(vw + 1);
  }
});
