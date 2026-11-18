// Net salary: the filter in net pay, the option "fără salariu estimat" and the note above a
// salary-filtered list. Runs at 320, 375 and 1280 against the fixture (tests/fixtures/build_db.php).
const { test, expect } = require('@playwright/test');

// The fixture of the salary spec: the shared one, plus postings with no salary figure (port 8825, playwright.config.js).
test.use({ baseURL: 'http://127.0.0.1:8825' });

const isNarrow = (page) => page.viewportSize().width < 1024;

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

async function openPanel(page) {
  if (isNarrow(page) && (await page.locator('#filter-toggle').getAttribute('aria-expanded')) !== 'true') {
    await page.locator('#filter-toggle').click();
  }
}

async function closePanel(page) {
  if (isNarrow(page) && (await page.locator('#filter-toggle').getAttribute('aria-expanded')) === 'true') {
    await page.keyboard.press('Escape');
  }
}

const resultCount = async (page) => parseInt(((await page.locator('#results .font-mono.font-medium').first().textContent()) || '0').replace(/\D/g, ''), 10);

test('a net bucket in the filter shows the note with its count; "Vezi-le" opens the postings with no estimate', async ({ page }) => {
  await page.goto('/posturi/');
  await openPanel(page);
  const details = page.locator('details[data-facet="salary_bucket"]');
  if (!(await details.evaluate((d) => d.open))) await details.locator('> summary').click();
  // The options: four net buckets (the ones that count above 0) and "fără salariu estimat"; no gross bucket.
  const values = await page.locator('input[name="salary_bucket[]"]').evaluateAll((els) => els.map((e) => e.value));
  expect(values.at(-1)).toBe('fara-estimare');
  expect(values.every((v) => v.startsWith('net-') || v === 'fara-estimare')).toBe(true);
  await expect(details).toContainText('fără salariu estimat');
  await page.locator('input[name="salary_bucket[]"][value="net-sub-3000"]').check();
  await expect(page).toHaveURL(/salary_bucket%5B%5D=net-sub-3000/);
  await closePanel(page);
  const note = page.locator('[data-salary-note]');
  await expect(note).toBeVisible();
  await expect(note).toContainText('Filtrul de salariu arată doar anunțurile cu salariu anunțat sau estimat.');
  const n = parseInt((await note.locator('[data-salary-note-count]').textContent()) || '0', 10);
  expect(n).toBeGreaterThan(0);
  await expect(note).toContainText(`${n} anunțuri care se potrivesc cu celelalte filtre nu au o estimare.`);
  // A row of the list says "lei net".
  await expect(page.locator('.pg-salary-value').first()).toContainText('lei net');
  await noOverflow(page, 'list with the note');
  // The link opens the same search with the postings with no estimate; its count is N.
  await note.getByRole('link', { name: 'Vezi-le' }).click();
  await expect(page).toHaveURL(/salary_bucket%5B%5D=fara-estimare/);
  expect(await resultCount(page)).toBe(n);
  await expect(page.locator('[data-salary-note]')).toContainText('Aceste anunțuri nu au un salariu anunțat și nu au putut fi estimate din grilă.');
  await expect(page.locator('[data-salary-note-link]')).toHaveCount(0);
  await expect(page.locator('.pg-salary-value')).toHaveCount(0);
  await noOverflow(page, 'list with no estimate');
});

test('the note follows the filter state after a swap', async ({ page }) => {
  await page.goto('/posturi/?salary_bucket=net-sub-3000');
  await expect(page.locator('[data-salary-note]')).toBeVisible();
  await openPanel(page);
  const details = page.locator('details[data-facet="salary_bucket"]');
  if (!(await details.evaluate((d) => d.open))) await details.locator('> summary').click();
  await page.locator('input[name="salary_bucket[]"][value="fara-estimare"]').check();
  await expect(page).toHaveURL(/salary_bucket%5B%5D=fara-estimare/);
  // The range stays checked (multi-select): a range with "fără" has no note.
  await expect(page.locator('[data-salary-note]')).toHaveCount(0);
  await page.goBack();
  await expect(page.locator('[data-salary-note-count]')).toBeVisible();
});

test('an old gross value still filters, with the chip saying "brut"; no horizontal overflow', async ({ page }) => {
  await page.goto('/posturi/?salary_bucket=sub-3000');
  await expect(page.locator('[data-active-chips]')).toContainText('sub 3.000 lei brut');
  await expect(page.locator('[data-salary-note]')).toBeVisible();
  await noOverflow(page, 'old gross value');
});

test('two ranges stay checked after the swap; the count is the sum of the option counts; a range and "fără" are both kept', async ({ page }) => {
  await page.goto('/posturi/');
  await openPanel(page);
  const details = page.locator('details[data-facet="salary_bucket"]');
  if (!(await details.evaluate((d) => d.open))) await details.locator('> summary').click();
  // The fixture lists the options that count above 0: take the first two ranges.
  const vals = (await page.locator('input[name="salary_bucket[]"]').evaluateAll((els) => els.map((e) => e.value))).filter((v) => v !== 'fara-estimare');
  expect(vals.length).toBeGreaterThanOrEqual(2);
  const [v1, v2] = vals;
  const optCount = async (v) => parseInt(((await page.locator(`input[name="salary_bucket[]"][value="${v}"]`).locator('xpath=..').textContent()) || '').match(/(\d[\d.]*)\s*$/)[1].replace(/\./g, ''), 10);
  const a = await optCount(v1);
  const b = await optCount(v2);
  await page.locator(`input[name="salary_bucket[]"][value="${v1}"]`).check();
  await expect(page).toHaveURL(new RegExp(`salary_bucket%5B%5D=${v1}`));
  await openPanel(page);
  await page.locator(`input[name="salary_bucket[]"][value="${v2}"]`).check();
  await expect(page).toHaveURL(new RegExp(`salary_bucket%5B%5D=${v2}`));
  await openPanel(page);
  await expect(page.locator(`input[name="salary_bucket[]"][value="${v1}"]`)).toBeChecked();
  await expect(page.locator(`input[name="salary_bucket[]"][value="${v2}"]`)).toBeChecked();
  await closePanel(page);
  expect(await resultCount(page)).toBe(a + b);
  // A range and "fără salariu estimat": both kept, no note.
  await page.goto('/posturi/?salary_bucket[]=net-sub-3000');
  await openPanel(page);
  await page.locator('input[name="salary_bucket[]"][value="fara-estimare"]').check();
  await expect(page).toHaveURL(/salary_bucket%5B%5D=fara-estimare/);
  await openPanel(page);
  await expect(page.locator('input[name="salary_bucket[]"][value="net-sub-3000"]')).toBeChecked();
  await expect(page.locator('input[name="salary_bucket[]"][value="fara-estimare"]')).toBeChecked();
  await closePanel(page);
  await expect(page.locator('[data-salary-note]')).toHaveCount(0);
});

test('a legacy scalar URL and the array URL give the same count; the legacy value has its chip', async ({ page }) => {
  await page.goto('/posturi/?salary_bucket=net-3000-4000');
  const n = await resultCount(page);
  await page.goto('/posturi/?salary_bucket[]=net-3000-4000');
  expect(await resultCount(page)).toBe(n);
  await page.goto('/posturi/?salary_bucket[]=net-peste-5000');
  await expect(page.locator('[data-active-chips]')).toContainText('peste 5.000 lei net');
});
