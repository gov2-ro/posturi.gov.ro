// UX-EMP-04: the title picker of the employer posting assistant (/pentru-angajatori/anunt/).
// Runs at 320, 375 and 1280 (see playwright.config.js).
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');

const PAGE = '/pentru-angajatori/anunt/';
const SUGG = JSON.parse(fs.readFileSync(path.resolve(__dirname, '..', '..', 'static', 'ocupatii.json'), 'utf8'));

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
const draftData = (page) => page.evaluate(() => window.employerBuilder.current());
const payloadOf = (page) => page.evaluate(() => {
  const b = window.employerBuilder.current();
  const d = window.employerBuilder.buildData(b);
  return window.EmployerDoc.buildPayload(d, null, window.EmployerDoc.employerBlock(b.meta), window.EmployerDoc.occupationBlock(b.meta, d));
});
const dialog = (page) => page.locator('[data-title-dialog]');
const opener = (page) => page.getByRole('button', { name: 'Alege din listă' });

async function pickCiugud(page) {
  await page.locator('#f-inst-county').selectOption('Alba');
  const name = page.locator('#f-inst-name');
  await name.click();
  await name.pressSequentially('comuna ciugud');
  await page.locator('[data-inst-list] [role="option"]').filter({ hasText: /^COMUNA CIUGUD/ }).first().click();
  await expect(name).toHaveValue('COMUNA CIUGUD');
}

test('the dialog: kind list, search, grade, title, payload, edit, keyboard', async ({ page }) => {
  const errors = await open(page);
  await noOverflow(page, 'before');
  const requests = [];
  page.on('request', (r) => { if (r.url().includes('/static/ocupatii.json')) requests.push(r.url()); });

  await pickCiugud(page);
  expect((await draftData(page)).meta.institution.kind).toBe('primarie');

  // Open: the heading names the primării and the first row is the top occupation of by_kind.primarie.
  expect(requests).toHaveLength(0);                      // loaded on the first opening only
  await opener(page).click();
  await expect(dialog(page)).toBeVisible();
  await expect(dialog(page).locator('h3')).toHaveText('Cele mai frecvente la Primării și consilii locale');
  const top = SUGG.occupations[SUGG.by_kind.primarie[0][0]];
  const rows = dialog(page).locator('[data-td-top] button');
  expect(await rows.count()).toBeLessThanOrEqual(12);
  await expect(rows.first()).toContainText(top.label);
  await expect(rows.first()).toContainText('COR ' + top.cor);
  await expect(rows.first()).toContainText(`în ${SUGG.by_kind.primarie[0][1]} anunțuri`);
  expect(requests).toHaveLength(1);
  const box = await dialog(page).boundingBox();
  const vp = page.viewportSize();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(vp.width);
  expect(box.y + box.height).toBeLessThanOrEqual(vp.height);
  await noOverflow(page, 'dialog open');

  // Search, fold and all words; at most 20 results.
  await dialog(page).locator('[data-td-q]').fill('referent spec');
  const res = dialog(page).locator('[data-td-results] button');
  await expect(res.first()).toContainText('Referent de specialitate');
  expect(await res.count()).toBeLessThanOrEqual(20);
  await dialog(page).locator('[data-td-q]').fill('REFERENT SPÉCIALITATE');
  await expect(res.first()).toContainText('Referent de specialitate');
  await dialog(page).locator('[data-td-q]').fill('referent spec');
  await res.filter({ hasText: /^Referent de specialitate/ }).first().click();

  // Grade: pick "gradul I A", the preview spells it IA.
  const grade = dialog(page).locator('[data-td-grade]');
  await expect(grade).toBeVisible();
  await expect(grade).toBeFocused();
  await expect(grade.locator('option').first()).toHaveText('fără grad / treaptă');
  await grade.selectOption('gradul I A');
  await expect(dialog(page).locator('[data-td-preview]')).toHaveText('Referent de specialitate, gradul IA');
  await noOverflow(page, 'grade picked');

  // "Renunță" changes nothing; open again and use.
  await dialog(page).getByRole('button', { name: 'Renunță' }).click();
  await expect(dialog(page)).toBeHidden();
  await expect(page.locator('#f-title')).toHaveValue('');
  expect((await draftData(page)).meta.occupation).toBeUndefined();
  await opener(page).click();
  await dialog(page).locator('[data-td-q]').fill('referent spec');
  await res.filter({ hasText: /^Referent de specialitate/ }).first().click();
  await dialog(page).locator('[data-td-grade]').selectOption('gradul I A');
  await dialog(page).getByRole('button', { name: 'Folosește denumirea' }).click();
  await expect(dialog(page)).toBeHidden();
  expect(requests).toHaveLength(1);

  await expect(page.locator('#f-title')).toHaveValue('Referent de specialitate, gradul IA');
  const meta = (await draftData(page)).meta.occupation;
  expect(meta).toMatchObject({ label: 'Referent de specialitate', grade: 'gradul I A' });
  expect(meta.cor).toMatch(/^\d{6}$/);
  const p = await payloadOf(page);
  expect(p.occupation).toEqual({ cor_code: meta.cor, label: 'Referent de specialitate', grade: 'gradul I A' });
  expect(p.data.occupation_title).toBe('Referent de specialitate, gradul IA');
  expect(Object.keys(p)).toEqual(['format', 'schema', 'generator', 'created', 'employer', 'occupation', 'data']);
  // The preview shows the title, not the COR code.
  await expect(page.locator('[data-preview]')).toContainText('Referent de specialitate, gradul IA');
  await expect(page.locator('[data-preview]')).not.toContainText(meta.cor);

  // The title stays editable: after a change the block is gone.
  await page.locator('#f-title').fill('Consilier');
  expect((await payloadOf(page)).occupation).toBeUndefined();
  expect('occupation' in (await payloadOf(page))).toBe(false);
  // The pick survives a reload; the title is still "Consilier", so there is still no block.
  await page.waitForTimeout(700);
  await page.reload();
  await expect(page.locator('[data-employer-builder][data-ready]')).toHaveCount(1);
  expect((await payloadOf(page)).occupation).toBeUndefined();
  await page.locator('#f-title').fill('Referent de specialitate, debutant');
  expect((await payloadOf(page)).occupation.label).toBe('Referent de specialitate');
  await noOverflow(page, 'after');
  expect(errors).toEqual([]);
});

test('keyboard: open, Tab stays inside the dialog, Escape, focus back on the button', async ({ page }) => {
  await open(page);
  await opener(page).focus();
  await page.keyboard.press('Enter');
  await expect(dialog(page)).toBeVisible();
  for (let i = 0; i < 40; i++) {
    await page.keyboard.press('Tab');
    const inside = await page.evaluate(() => !!document.activeElement.closest('dialog[open]'));
    expect(inside, `Tab ${i}`).toBe(true);
  }
  await page.keyboard.press('Escape');
  await expect(dialog(page)).toBeHidden();
  await expect(opener(page)).toBeFocused();
});

test('no institution picked: the dialog shows the list for all postings', async ({ page }) => {
  await open(page);
  await opener(page).click();
  await expect(dialog(page).locator('h3')).toHaveText('Cele mai frecvente');
  const top = SUGG.occupations[SUGG.by_kind['*'][0][0]];
  await expect(dialog(page).locator('[data-td-top] button').first()).toContainText(top.label);
  expect(await dialog(page).locator('[data-td-top] button').count()).toBe(12);
  // An occupation with no grades hides the grade select.
  const noGrade = SUGG.occupations.find((o) => !o.grades.length && o.n > 0);
  await dialog(page).locator('[data-td-q]').fill(noGrade.label);
  await dialog(page).locator('[data-td-results] button').filter({ hasText: new RegExp('^' + noGrade.label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')) }).first().click();
  await expect(dialog(page).locator('[data-td-grade]')).toBeHidden();
  await expect(dialog(page).locator('[data-td-preview]')).toHaveText(noGrade.label);
  await noOverflow(page, 'no institution');
});
