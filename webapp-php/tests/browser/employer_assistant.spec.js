// UX-EMP-03: tuning of the employer posting assistant (/pentru-angajatori/anunt/).
// Runs at 320, 375 and 1280 (see playwright.config.js). The tests that need the sidebar skip below 1024 px.
const { test, expect } = require('@playwright/test');

const PAGE = '/pentru-angajatori/anunt/';
const DRAFTS = 'posturi.employer-drafts.v1';
const DEFAULT_DOCS = ['formular_inscriere', 'act_identitate', 'certificat_casatorie', 'acte_studii',
  'carnet_munca_adeverinta_vechime', 'cazier_judiciar', 'adeverinta_medicala', 'cv'];

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}
async function open(page) {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto(PAGE);
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  return errors;
}
const built = (page) => page.evaluate(() => window.employerBuilder.buildData(window.employerBuilder.current()));
const draftData = (page) => page.evaluate(() => window.employerBuilder.current());
const docBox = (page, code) => page.locator(`input[data-path="documents.standard"][value="${code}"]`);

test('the name, the links and the percent', async ({ page }) => {
  const errors = await open(page);
  await expect(page.locator('h1')).toHaveText('Asistent redactare anunțuri angajare');
  await expect(page).toHaveTitle(/Asistent redactare anunțuri angajare/);
  await expect(page.locator('meta[name="description"]')).toHaveAttribute('content', /Asistent pentru redactarea anunțurilor de angajare/);
  await expect(page.locator('#meter-h')).toHaveText('Cât de complet este anunțul');
  await expect(page.locator('[data-meter-score]')).toHaveText(/^\d+%$/);
  const score = await page.locator('[data-meter-score]').textContent();
  await expect(page.locator('[data-meter]')).toHaveAttribute('aria-valuetext', score);
  await expect(page.locator('[data-bar-score]')).toHaveText(score);
  await expect(page.locator('[data-meter]').locator('xpath=..')).toContainText('complet');
  for (const url of ['/angajatori/']) {
    await page.goto(url);
    await expect(page.locator('a[href="/pentru-angajatori/anunt/"]', { hasText: 'Asistent redactare anunțuri angajare' }).first()).toBeVisible();
  }
  await expect(page.locator('footer a[href="/pentru-angajatori/anunt/"]')).toHaveText('Asistent redactare anunțuri angajare');
  expect(errors).toEqual([]);
});

test('the weights of the 15 checks add up to 100', async ({ page }) => {
  await open(page);
  const sum = await page.evaluate(() => window.employerBuilder.QUALITY.reduce((a, q) => a + q[2], 0));
  expect(sum).toBe(100);
});

test('a new draft has the defaults, and the defaults count for the meter', async ({ page }) => {
  await open(page);
  await expect(page.locator('#f-status')).toHaveValue('vacant');
  await expect(page.locator('#f-duration')).toHaveValue('nedeterminata');
  await expect(page.locator('#f-schedule')).toHaveValue('norma_intreaga');
  for (const code of DEFAULT_DOCS) await expect(docBox(page, code), code).toBeChecked();
  for (const code of ['certificat_integritate', 'declaratie_conformitate', 'dosar_plic']) await expect(docBox(page, code), code).not.toBeChecked();
  await expect(page.locator('body')).toContainText('Obligatoriu în învățământ, sănătate, protecție socială și în posturile cu persoane vulnerabile (art. 35 alin. 1 lit. h)');
  const d = await built(page);
  expect(d.contract).toEqual({ post_status: 'vacant', duration: 'nedeterminata', schedule: 'norma_intreaga' });
  expect(d.documents.standard).toEqual(DEFAULT_DOCS);
  // studies 0, experience 0, documents 8, duration 5, schedule 5, text 6, specific 4, attachment 5 = 33
  const q = await page.evaluate((x) => window.employerBuilder.quality(x), d);
  expect(q.checks.documents).toBe(1);
  expect(q.checks.duration).toBe(1);
  expect(q.checks.schedule).toBe(1);
  await expect(page.locator('[data-meter-score]')).toHaveText(q.score + '%');
  // A new draft after a second click has them too.
  await page.getByRole('button', { name: 'Ciornă nouă' }).click();
  await expect(page.locator('#f-duration')).toHaveValue('nedeterminata');
});

test('a draft of the current shape opens without a change', async ({ page }) => {
  await open(page);
  // The shape that UX-EMP-01 saved: no defaults, no meta.multi_localities, no meta.fee, one textarea of fields.
  const old = {
    id: 'old1', title: 'Referent', updatedAt: '2026-11-02T10:00:00.000Z',
    data: {
      occupation_title: 'Referent', vacancies_total: 1,
      contract: { duration: 'determinata', duration_months: 12, schedule: 'norma_partiala', hours_per_week: 20 },
      work_localities: ['Ciugud', 'Sebeș'],
      education: { minimum_level: 'licenta', fields_of_study: [{ label_ro: 'drept' }, { label_ro: 'ceva propriu' }] },
      experience: { years_minimum: 2, in_specialty: true },
      documents: { standard: ['cv'] },
      application: { channels: ['email', 'sediu'] },
      application_contact: { email: 'a@exemplu.example', address: 'Str. Mare 1' },
      application_fee: { amount: 40, details: 'La casierie.' },
    },
    meta: { institution: { name: 'Primăria Exemplu', county: 'Alba' }, department: 'Serviciul X', interview: true },
  };
  await page.evaluate(([k, d]) => localStorage.setItem(k, JSON.stringify({ version: 1, currentId: d.id, drafts: { [d.id]: d } })), [DRAFTS, old]);
  const before = await page.evaluate((k) => localStorage.getItem(k), DRAFTS);
  await page.reload();
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  await page.waitForTimeout(900);
  // Nothing was written: the saved draft is the same string.
  expect(await page.evaluate((k) => localStorage.getItem(k), DRAFTS)).toBe(before);
  // The values are on the page; the switches follow the values the draft holds.
  await expect(page.locator('#f-status')).toHaveValue('');
  await expect(page.locator('#f-duration')).toHaveValue('determinata');
  await expect(page.locator('#f-months')).toHaveValue('12');
  await expect(page.locator('#f-hours')).toHaveValue('20');
  await expect(page.locator('#f-localities')).toBeVisible();
  await expect(page.locator('#f-multi-loc')).toBeChecked();
  await expect(page.locator('input[data-path="meta.fee"]')).toBeChecked();
  await expect(page.locator('#f-fee')).toHaveValue('40');
  await expect(docBox(page, 'cv')).toBeChecked();
  await expect(docBox(page, 'cazier_judiciar')).not.toBeChecked();
  await expect(page.locator('[data-chip-list] li')).toHaveText(['drept×', 'ceva propriu×']);
  const d = await built(page);
  expect(d.contract).toEqual({ duration: 'determinata', duration_months: 12, schedule: 'norma_partiala', hours_per_week: 20 });
  expect(d.work_localities).toEqual(['Ciugud', 'Sebeș']);
  expect(d.application_fee).toEqual({ amount: 40, details: 'La casierie.' });
  expect(d.application_contact).toEqual({ email: 'a@exemplu.example', address: 'Str. Mare 1' });
  expect(d.education.fields_of_study).toEqual([{ label_ro: 'drept' }, { label_ro: 'ceva propriu' }]);
});

test('conditional fields: hide, keep the value, leave it out of the payload', async ({ page }) => {
  await open(page);
  const hidden = (sel) => expect(page.locator(sel)).toBeHidden();
  const shown = (sel) => expect(page.locator(sel)).toBeVisible();

  // Durata în luni <- duration = determinata
  await hidden('#f-months');
  await page.locator('#f-duration').selectOption('determinata');
  await shown('#f-months');
  await page.locator('#f-months').fill('12');
  expect((await built(page)).contract.duration_months).toBe(12);
  await page.locator('#f-duration').selectOption('nedeterminata');
  await hidden('#f-months');
  expect((await built(page)).contract.duration_months).toBeUndefined();
  expect((await draftData(page)).data.contract.duration_months).toBe(12);
  await page.locator('#f-duration').selectOption('determinata');
  await expect(page.locator('#f-months')).toHaveValue('12');
  expect((await built(page)).contract.duration_months).toBe(12);
  await page.locator('#f-duration').selectOption('nedeterminata');

  // Ore pe săptămână <- schedule = norma_partiala
  await hidden('#f-hours');
  await page.locator('#f-schedule').selectOption('norma_partiala');
  await page.locator('#f-hours').fill('20');
  expect((await built(page)).contract.hours_per_week).toBe(20);
  await page.locator('#f-schedule').selectOption('norma_intreaga');
  await hidden('#f-hours');
  expect((await built(page)).contract.hours_per_week).toBeUndefined();
  expect((await draftData(page)).data.contract.hours_per_week).toBe(20);

  // Experience <- "Fără experiență" not checked
  await shown('#f-exp');
  await page.locator('#f-exp').fill('3');
  await page.locator('input[data-path="experience.in_specialty"]').check();
  expect((await built(page)).experience).toEqual({ years_minimum: 3, in_specialty: true });
  await page.locator('input[data-path="experience.none_required"]').check();
  await hidden('#f-exp');
  await expect(page.locator('input[data-path="experience.in_specialty"]')).toBeHidden();
  expect((await built(page)).experience).toEqual({ none_required: true });
  await page.locator('input[data-path="experience.none_required"]').uncheck();
  await expect(page.locator('#f-exp')).toHaveValue('3');
  await expect(page.locator('input[data-path="experience.in_specialty"]')).toBeChecked();

  // Localități <- the checkbox
  await hidden('#f-localities');
  await page.locator('#f-multi-loc').check();
  await page.locator('#f-localities').fill('Ciugud\nSebeș');
  expect((await built(page)).work_localities).toEqual(['Ciugud', 'Sebeș']);
  await page.locator('#f-multi-loc').uncheck();
  await hidden('#f-localities');
  expect((await built(page)).work_localities).toBeUndefined();
  expect((await draftData(page)).data.work_localities).toEqual(['Ciugud', 'Sebeș']);
  expect((await draftData(page)).meta.multi_localities).toBe(false);
  await page.locator('#f-multi-loc').check();
  await expect(page.locator('#f-localities')).toHaveValue('Ciugud\nSebeș');

  // Depunere: address <- sediu / posta / online; e-mail <- email
  const ch = (c) => page.locator(`input[data-path="application.channels"][value="${c}"]`);
  await hidden('#f-ap-address'); await hidden('#f-ap-email');
  await expect(page.locator('[data-no-channel]')).toBeVisible();
  await ch('sediu').check();
  await shown('#f-ap-address'); await hidden('#f-ap-email');
  await expect(page.locator('[data-no-channel]')).toBeHidden();
  await page.locator('#f-ap-address').fill('Str. Mare 1');
  await ch('email').check();
  await shown('#f-ap-email');
  await page.locator('#f-ap-email').fill('depunere@exemplu.example');
  expect((await built(page)).application_contact).toEqual({ address: 'Str. Mare 1', email: 'depunere@exemplu.example' });
  await ch('sediu').uncheck();
  await hidden('#f-ap-address');
  expect((await built(page)).application_contact).toEqual({ email: 'depunere@exemplu.example' });
  await ch('online').check();
  await shown('#f-ap-address');
  await expect(page.locator('#f-ap-address')).toHaveValue('Str. Mare 1');
  await ch('online').uncheck();
  await ch('email').uncheck();
  await hidden('#f-ap-email');
  expect((await built(page)).application_contact).toBeUndefined();
  await ch('posta').check();
  await shown('#f-ap-address');

  // Taxa <- the switch
  await hidden('#f-fee'); await hidden('#f-fee-details');
  await page.locator('input[data-path="meta.fee"]').check();
  await page.locator('#f-fee').fill('40');
  await page.locator('#f-fee-details').fill('La casierie.');
  expect((await built(page)).application_fee).toEqual({ amount: 40, details: 'La casierie.' });
  await page.locator('input[data-path="meta.fee"]').uncheck();
  await hidden('#f-fee');
  expect((await built(page)).application_fee).toBeUndefined();
  await page.locator('input[data-path="meta.fee"]').check();
  await expect(page.locator('#f-fee')).toHaveValue('40');

  // The preview and the .json leave a hidden value out too.
  await page.locator('#f-duration').selectOption('determinata');
  await page.locator('#f-duration').selectOption('nedeterminata');
  await page.locator('input[data-path="meta.fee"]').uncheck();
  await page.waitForTimeout(300);
  await expect(page.locator('[data-preview]')).not.toContainText('Taxa de participare');
  await noOverflow(page, 'conditional fields');
});

test('chips: a suggestion has an isced_field, free text has null, a chip can be removed', async ({ page }) => {
  await open(page);
  const input = page.locator('#f-fields');
  const chips = page.locator('[data-chip-list] li');
  expect(await page.locator('#fields-list option').count()).toBeGreaterThan(30);
  expect(await page.locator('#fields-list option').count()).toBeLessThanOrEqual(120);

  // A pick from the datalist: the browser fires an input event of this type.
  await input.evaluate((el) => {
    el.value = 'științe economice';
    el.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertReplacementText', data: 'științe economice' }));
  });
  await expect(chips).toHaveText(['științe economice×']);
  await expect(input).toHaveValue('');
  // Free text: Enter.
  await input.fill('arheologie subacvatică');
  await input.press('Enter');
  // A comma ends a chip too; an entry that equals a suggestion without diacritics takes the suggestion.
  await input.fill('stiinte juridice,');
  await expect(chips).toHaveCount(3);
  // A duplicate adds nothing.
  await input.fill('Științe Economice');
  await input.press('Enter');
  await expect(chips).toHaveCount(3);

  const fields = (await draftData(page)).data.education.fields_of_study;
  expect(fields).toEqual([
    { label_ro: 'științe economice', isced_field: '04_afaceri_administratie_drept' },
    { label_ro: 'arheologie subacvatică', isced_field: null },
    { label_ro: 'științe juridice', isced_field: '04_afaceri_administratie_drept' },
  ]);
  // The payload keeps the isced_field and leaves out the null.
  expect((await built(page)).education.fields_of_study).toEqual([
    { label_ro: 'științe economice', isced_field: '04_afaceri_administratie_drept' },
    { label_ro: 'arheologie subacvatică' },
    { label_ro: 'științe juridice', isced_field: '04_afaceri_administratie_drept' },
  ]);

  await page.getByRole('button', { name: 'Elimină „arheologie subacvatică”' }).click();
  await expect(chips).toHaveText(['științe economice×', 'științe juridice×']);
  // Reload: the chips come back.
  await page.waitForTimeout(700);
  await page.reload();
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  await expect(chips).toHaveText(['științe economice×', 'științe juridice×']);
  await noOverflow(page, 'chips');
});

test('institution autocomplete: Alba, ciugud, keyboard and mouse; the category sets certificat_integritate', async ({ page }) => {
  await open(page);
  const requests = [];
  page.on('request', (r) => { if (r.url().includes('/static/institutii/')) requests.push(new URL(r.url()).pathname); });
  const outside = [];
  page.on('request', (r) => { if (!r.url().startsWith('http://127.0.0.1:8823')) outside.push(r.url()); });

  // The county comes first in the section.
  const order = await page.locator('#sec-1 [id^="f-inst-"]').evaluateAll((els) => els.map((e) => e.id));
  expect(order.slice(0, 2)).toEqual(['f-inst-county', 'f-inst-name']);

  // Without a county there are no suggestions, and typing is free.
  await page.locator('#f-inst-name').fill('ciugud');
  await expect(page.locator('[data-inst-list]')).toBeHidden();
  await page.locator('#f-inst-name').fill('');

  await page.locator('#f-inst-county').selectOption('Alba');
  const name = page.locator('#f-inst-name');
  await name.click();
  await name.pressSequentially('ciugud');
  const opts = page.locator('[data-inst-list] [role="option"]');
  await expect(opts.first()).toBeVisible();
  expect(await opts.count()).toBeLessThanOrEqual(8);
  await expect(name).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('[data-inst-list]')).toContainText('COMUNA CIUGUD');
  await expect(page.locator('[data-inst-list]')).toContainText('SCOALA GIMNAZIALA CIUGUD');

  // Keyboard: arrow, arrow, Escape closes, arrow again, Enter picks.
  await name.press('ArrowDown');
  await expect(opts.first()).toHaveAttribute('aria-selected', 'true');
  await expect(name).toHaveAttribute('aria-activedescendant', 'inst-opt-0');
  await name.press('Escape');
  await expect(page.locator('[data-inst-list]')).toBeHidden();
  await name.press('ArrowDown');
  await expect(opts.first()).toBeVisible();
  await name.press('ArrowDown');
  await name.press('Enter');
  await expect(name).toHaveValue(/CIUGUD/);
  await expect(page.locator('#f-inst-cui')).not.toHaveValue('');
  await expect(page.locator('#f-inst-locality')).toHaveValue('Ciugud');
  await expect(page.locator('#f-inst-address')).toHaveValue(/Ciugud/);
  await expect(page.locator('#f-inst-county')).toHaveValue('Alba');
  const picked = (await draftData(page)).meta.institution;
  expect(picked.category).toBeTruthy();
  const first = picked.category;

  // Administrație locală and Educație: only Educație ticks the integrity certificate.
  await name.fill('');
  await name.pressSequentially('comuna ciugud');
  await opts.filter({ hasText: /^COMUNA CIUGUD/ }).first().click();
  await expect(name).toHaveValue('COMUNA CIUGUD');
  expect((await draftData(page)).meta.institution.category).toBe('Administrație locală');
  await expect(docBox(page, 'certificat_integritate')).not.toBeChecked();
  await expect(page.locator('#f-inst-cui')).toHaveValue('4562516');

  await name.fill('');
  await name.pressSequentially('scoala gimnaziala ciugud');
  await opts.first().click();
  expect((await draftData(page)).meta.institution.category).toBe('Educație');
  await expect(docBox(page, 'certificat_integritate')).toBeChecked();
  expect((await built(page)).documents.standard).toContain('certificat_integritate');

  // Back to an institution of another category: the default list follows.
  await name.fill('');
  await name.pressSequentially('comuna ciugud');
  await opts.filter({ hasText: /^COMUNA CIUGUD/ }).first().click();
  await expect(docBox(page, 'certificat_integritate')).not.toBeChecked();

  // A list that the employer changed is not touched.
  await docBox(page, 'cv').uncheck();
  await name.fill('');
  await name.pressSequentially('scoala gimnaziala ciugud');
  await opts.first().click();
  await expect(docBox(page, 'certificat_integritate')).not.toBeChecked();

  // Every filled field stays editable, and free text is allowed.
  await page.locator('#f-inst-address').fill('Adresa mea');
  await expect(page.locator('#f-inst-address')).toHaveValue('Adresa mea');
  await name.fill('O instituție care nu e în listă');
  await expect(name).toHaveValue('O instituție care nu e în listă');
  expect((await draftData(page)).meta.institution.category).toBeUndefined();

  // The attribution and the issue link.
  await expect(page.locator('#inst-source')).toContainText('Date despre instituții: org.gov2.ro (EUPL), actualizate la 27.10.2026.');
  const href = await page.locator('[data-inst-issue]').getAttribute('href');
  expect(href).toContain('/gov2-ro/institutii-publice/issues/new?title=');
  expect(decodeURIComponent(href)).toContain('O instituție care nu e în listă');
  await expect(page.locator('[data-inst-issue]')).toHaveAttribute('target', '_blank');
  await expect(page.locator('[data-inst-issue]')).toHaveText('Instituția lipsește sau are date greșite?');

  // The only requests for institutions are county files, once each, and nothing leaves the site.
  expect(requests).toEqual(['/static/institutii/alba.json']);
  expect(outside).toEqual([]);
  expect(first).toBeTruthy();
});

test('layout: sidebar at 1280, bottom bar below, no horizontal overflow', async ({ page }) => {
  await open(page);
  const w = page.viewportSize().width;
  const aside = page.locator('[data-sidebar]');
  const bar = page.locator('[data-bar]');
  await noOverflow(page, `empty form at ${w}`);
  if (w >= 1024) {
    await expect(bar).toBeHidden();
    expect(await aside.evaluate((n) => getComputedStyle(n).position)).toBe('sticky');
    // The sidebar is on the right of the form, and stays in the window when the page scrolls.
    const form = await page.locator('#builder-form').boundingBox();
    const side = await aside.boundingBox();
    expect(side.x).toBeGreaterThan(form.x + form.width - 1);
    await page.locator('#sec-5').scrollIntoViewIfNeeded();
    await page.evaluate(() => window.scrollBy(0, 100));
    const after = await aside.boundingBox();
    expect(after.y).toBeGreaterThanOrEqual(0);
    expect(after.y).toBeLessThan(40);
    // The order inside the sidebar: meter, missing items, art. 18(2), downloads.
    const heads = await aside.locator('h2, h3').allTextContents();
    expect(heads.map((x) => x.trim())).toEqual(['Cât de complet este anunțul', 'De completat', 'Elementele obligatorii ale anunțului (art. 18 alin. 2)', 'Descarcă']);
    // The preview is below the form, in full width.
    const abs = (sel) => page.locator(sel).evaluate((n) => { const r = n.getBoundingClientRect(); return { top: r.top + scrollY, bottom: r.bottom + scrollY, width: r.width }; });
    const f = await abs('#builder-form'), pv = await abs('[data-preview]'), dr = await abs('#drafts-h');
    expect(pv.top).toBeGreaterThan(f.bottom - 1);
    expect(pv.width).toBeGreaterThan(form.width + side.width);
    // Drafts are above the form.
    expect(dr.top).toBeLessThan(f.top);
  } else {
    await expect(bar).toBeVisible();
    expect(await aside.evaluate((n) => getComputedStyle(n).position)).toBe('static');
    await expect(bar).toContainText('Anunț complet în proporție de');
    await expect(bar).toContainText(/\d+%/);
    const link = bar.getByRole('link', { name: 'Vezi ce lipsește' });
    await expect(link).toHaveAttribute('href', '#meter-h');
    // The bar sits at the bottom of the window.
    const vh = page.viewportSize().height;
    const b = await bar.boundingBox();
    expect(Math.round(b.y + b.height)).toBe(vh);
    // The page leaves room for it: the padding at the bottom is at least the height of the bar.
    const pad = await page.locator('[data-employer-builder]').evaluate((n) => parseFloat(getComputedStyle(n).paddingBottom));
    expect(pad).toBeGreaterThanOrEqual(b.height);
    // The last block of the page is not under the bar when the page is at its end.
    await page.evaluate(() => window.scrollTo(0, document.scrollingElement.scrollHeight));
    const last = await page.locator('[data-preview]').boundingBox();
    expect(last.y + last.height).toBeLessThanOrEqual(vh - b.height + 1);
    // The link goes to the meter.
    await link.click();
    await expect(page.locator('#meter-h')).toBeInViewport();
  }
  await noOverflow(page, `layout at ${w}`);
  // A filled form, with every conditional field open, does not overflow either.
  await page.locator('#f-duration').selectOption('determinata');
  await page.locator('#f-schedule').selectOption('norma_partiala');
  await page.locator('#f-multi-loc').check();
  await page.locator('input[data-path="meta.fee"]').check();
  for (const c of ['sediu', 'email']) await page.locator(`input[data-path="application.channels"][value="${c}"]`).check();
  await page.locator('#f-fields').fill('un domeniu cu un nume destul de lung ca să treacă pe rândul următor');
  await page.locator('#f-fields').press('Enter');
  await noOverflow(page, `open conditionals at ${w}`);
});
