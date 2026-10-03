// Browser-level checks for the PHP app: the fixture database (see
// tests/fixtures/build_db.php) provides the deadline matrix, hostile Markdown
// and multi-role rows. Runs at 320, 375 and 1280 via the playwright projects.
const { test, expect } = require('@playwright/test');

const ROW_1001 = 'a[href="/job/1001-asistent-medical-generalist/"]';

// ---- Every width: no horizontal overflow ----
test('no horizontal overflow', async ({ page }) => {
  await page.goto('/');
  const overflow = await page.evaluate(
    () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth
  );
  expect(overflow).toBeLessThanOrEqual(1);
  const detail = await page.goto('/job/1001-asistent-medical-generalist/');
  expect(detail.status()).toBe(200);
  const detailOverflow = await page.evaluate(
    () => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth
  );
  expect(detailOverflow).toBeLessThanOrEqual(1);
});

// ---- Every width: countdown and printed date agree on the result row ----
test('result row shows one deadline', async ({ page }) => {
  await page.goto('/');
  const row = page.locator('li', { has: page.locator(ROW_1001) });
  await expect(row).toContainText('13 zile');
  // The row also carries the published-date <time>; this is the deadline one.
  await expect(row.locator('time[datetime="2026-10-16"]')).toHaveText('16.10.2026');
  await expect(row).not.toContainText('10.11.2026');
});

// ---- Every width: hostile Markdown cannot execute (FIX-01 browser proof) ----
test('unsafe markdown body cannot execute', async ({ page }) => {
  const dialogs = [];
  const consoleErrors = [];
  page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  await page.goto('/job/1007-post-cu-text-nesigur/');

  // The harmless text and the legitimate link survive…
  await expect(page.locator('body')).toContainText('Text normal cu bold și diacritice');
  await expect(page.locator('a[href="https://posturi.gov.ro/documente/anunt-1007.pdf"]')).toBeVisible();

  // …the payload does not: no dialog fired, no executable attribute survived.
  expect(dialogs).toEqual([]);
  const badAnchors = await page.locator('a[href^="javascript:"]').count();
  const inlineHandlers = await page.locator('[onclick],[onerror],[onload]').count();
  expect(badAnchors).toBe(0);
  expect(inlineHandlers).toBe(0);
  // Ignore resource-404 noise (no favicon in this fixture); a real payload
  // would have produced a dialog above, which is the hard proof.
  const scriptErrors = consoleErrors.filter((t) => !t.includes('Failed to load resource'));
  expect(scriptErrors).toEqual([]);
});

// ---- Mobile only: drawer opens, filters through HTMX, Esc restores focus ----
test.describe('mobile drawer', () => {
  test.skip(({ viewport }) => viewport.width >= 1024, 'drawer is mobile-only');

  test('filter via drawer updates results and URL; Escape restores focus', async ({ page }) => {
    await page.goto('/');

    const toggle = page.locator('#filter-toggle');
    await toggle.click();
    // Focus moves into the drawer…
    await expect(page.locator('#filter-close')).toBeFocused();

    // …checking Cluj fires the HTMX form: results narrow, URL follows.
    await page.locator('details[data-facet="judet"]').click(); // facets are <details>
    await page.locator('input[name="judet[]"][value="cluj"]').check();
    await expect(page).toHaveURL(/judet%5B%5D=cluj/);
    await expect(page.locator('#results')).toContainText('posturi găsite');
    const rows = page.locator('#results li');
    const count = await rows.count();
    expect(count).toBeGreaterThan(0);
    for (let i = 0; i < count; i++) {
      await expect(rows.nth(i)).toContainText('Cluj');
    }

    // Escape closes the drawer and hands focus back to the trigger.
    await page.keyboard.press('Escape');
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await expect(toggle).toBeFocused();

    // Browser back returns to the unfiltered URL and list.
    await page.goBack();
    await expect(page).not.toHaveURL(/judet%5B%5D=cluj/);
    await expect(page.locator('#results')).toContainText('posturi găsite');
    await expect(page.locator('#results a[href="/job/1002-referent-debutant/"]')).toBeVisible();
  });
});

// ---- Desktop only: chip removal drives the same HTMX path ----
test.describe('desktop chips', () => {
  test.skip(({ viewport }) => viewport.width < 1024, 'chip test targets the desktop sidebar');

  test('chip removes its facet and updates results', async ({ page }) => {
    await page.goto('/?judet%5B%5D=cluj');
    const rows = page.locator('#results li');
    await expect(rows.first()).toContainText('Cluj');

    const chip = page.locator('[data-chip-param="judet"][data-chip-value="cluj"]');
    await expect(chip).toBeVisible();
    await chip.click();

    await expect(page).not.toHaveURL(/judet/);
    await expect(page.locator('input[name="judet[]"][value="cluj"]')).not.toBeChecked();
    // The unfiltered list includes a non-Cluj row again.
    await expect(page.locator('#results a[href="/job/1002-referent-debutant/"]')).toBeVisible();
  });
});
