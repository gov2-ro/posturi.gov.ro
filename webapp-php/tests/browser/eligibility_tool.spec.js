// 261016: "Pot aplica?" as a tool (/pot-aplica/): a browser-local profile that
// becomes the list's own exclusion filters. Runs at 320, 375 and 1280.
const { test, expect } = require('@playwright/test');

async function noOverflow(page, what) {
  const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
  expect(o, `horizontal overflow: ${what}`).toBeLessThanOrEqual(1);
}

test('profile → filtered list; kept in the browser; cleared on request', async ({ page }) => {
  const errors = [];
  page.on('pageerror', (e) => errors.push(e.message));
  await page.goto('/pot-aplica/');
  await expect(page.locator('h1')).toHaveText('Pot aplica?');
  await noOverflow(page, 'tool');
  await page.selectOption('#pf-studies', 'liceala');
  await page.fill('#pf-exp', '2');
  await page.locator('input[name="pf_judet"][value="cluj"]').check();
  await page.locator('input[name="pf_licence"][value="no"]').check();
  await page.locator('input[name="pf_lang"][value="en"]').check();
  await expect(page.locator('input[name="pf_lang_any"]')).not.toBeChecked();
  await page.getByRole('button', { name: 'Arată anunțurile potrivite' }).click();
  await expect(page).toHaveURL(/\/\?/);
  const url = new URL(page.url());
  const all = (k) => url.searchParams.getAll(k);
  expect(all('studies_level_not[]')).toEqual(['postliceala', 'licenta', 'master', 'doctorat']);
  expect(all('exp_level_not[]')).toEqual(['3-5', '5plus']);
  expect(all('judet[]')).toEqual(['cluj']);
  expect(all('credential_not[]')).toEqual(['permis_conducere']);
  expect(all('lang_not[]')).not.toContain('en');
  expect(all('lang_not[]')).toContain('fr');
  // The list takes the parameters as filters: chips, and the "fără" switches on.
  await expect(page.locator('[data-chip-param="studies_level_not"]').first()).toBeVisible();
  await expect(page.locator('input[name="credential_not[]"][value="permis_conducere"]')).toBeChecked();

  // Back on the tool, the answers are still there; "Șterge" forgets them.
  await page.goto('/pot-aplica/');
  await expect(page.locator('#pf-studies')).toHaveValue('liceala');
  await expect(page.locator('#pf-exp')).toHaveValue('2');
  await page.locator('[data-profile-clear]').click();
  await expect(page.locator('#pf-studies')).toHaveValue('');
  expect(await page.evaluate(() => localStorage.getItem('posturi.profile.v1'))).toBeNull();
  expect(errors).toEqual([]);
});

test('the tool is linked from the nav (sm+) and from "Începe cu" (every width), and the nav still fits', async ({ page, viewport }) => {
  await page.goto('/');
  if (viewport.width >= 640) await expect(page.locator('header a[href="/pot-aplica/"]')).toBeVisible();
  await expect(page.locator('#shortcuts a[href="/pot-aplica/"]')).toBeVisible();
  await noOverflow(page, 'nav with the tool link');
});
