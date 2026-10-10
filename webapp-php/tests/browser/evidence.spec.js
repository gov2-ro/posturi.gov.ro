// UX-13 browser check: the source-evidence disclosure on the detail page opens from the
// keyboard (Enter and Space), is reachable with Tab, keeps the fact visible outside it and
// does not overflow at 320/375/1280. It also proves hostile quote text is inert.
//
// The shared browser fixture (make.php without arguments) has no evidence rows and adding
// the role cases to it would change counts other specs pin, so this spec serves its own
// fixture, built with `make.php <path> evidence`, on its own PHP server and port.
const { test, expect } = require('@playwright/test');
const { spawn, execFileSync } = require('child_process');
const net = require('net');
const os = require('os');
const path = require('path');

const webappDir = path.resolve(__dirname, '..', '..');
let server;
let base;

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.listen(0, '127.0.0.1', () => { const { port } = s.address(); s.close(() => resolve(port)); });
    s.on('error', reject);
  });
}

test.beforeAll(async () => {
  const fixture = path.join(os.tmpdir(), `posturi-evidence-browser-${process.pid}.sqlite`);
  execFileSync('php', ['tests/fixtures/make.php', fixture, 'evidence'], { cwd: webappDir });
  const port = await freePort();
  base = `http://127.0.0.1:${port}`;
  server = spawn('php', ['-S', `127.0.0.1:${port}`, 'router.php'], {
    cwd: webappDir, stdio: 'ignore',
    env: { ...process.env, POSTURI_DB: fixture, POSTURI_TODAY: '2026-10-03' },
  });
  for (let i = 0; i < 50; i++) {
    try { if ((await fetch(base + '/')).ok) return; } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('evidence fixture server did not start');
});

test.afterAll(() => { server?.kill(); });

const noOverflow = (page) =>
  page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);

test('a disclosure opens and closes from the keyboard, with the fact visible outside it', async ({ page }) => {
  const dialogs = [];
  page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
  await page.goto(base + '/job/1013/');

  const item = page.locator('li[data-evidence-kind="skill"]').first();         // "Excel", verified
  const details = item.locator('details[data-evidence="verified"]');
  const summary = details.locator('summary');

  // The fact is on the page, outside the disclosure, whether it is open or not.
  await expect(item.locator('span').first()).toHaveText('Excel');
  await expect(details).not.toHaveJSProperty('open', true);
  await expect(details.locator('blockquote')).toBeHidden();

  // Tab reaches a disclosure summary.
  let reached = false;
  for (let i = 0; i < 150 && !reached; i++) {
    await page.keyboard.press('Tab');
    reached = await page.evaluate(() => document.activeElement?.matches('details[data-evidence] > summary') ?? false);
  }
  expect(reached, 'a disclosure summary is reachable with Tab').toBe(true);

  await summary.focus();
  await page.keyboard.press('Enter');
  await expect(details).toHaveJSProperty('open', true);
  await expect(details.locator('blockquote')).toBeVisible();
  await expect(details.locator('blockquote')).toContainText('FLAT-SKILL-EVIDENCE-RAW');
  await expect(details).toContainText('Găsit în: textul anunțului');
  await expect(item.locator('span').first()).toHaveText('Excel');

  await page.keyboard.press('Space');
  await expect(details).toHaveJSProperty('open', false);
  await page.keyboard.press('Space');
  await expect(details).toHaveJSProperty('open', true);
  await page.keyboard.press('Enter');
  await expect(details).toHaveJSProperty('open', false);

  expect(dialogs, 'no script from a quote ran').toEqual([]);
});

test('every state opens, hostile text stays inert, nothing overflows', async ({ page }) => {
  const dialogs = [];
  const errors = [];
  page.on('dialog', async (d) => { dialogs.push(d.message()); await d.dismiss(); });
  page.on('pageerror', (e) => errors.push(String(e)));
  await page.goto(base + '/job/1013/');

  const all = page.locator('details[data-evidence]');
  expect(await all.count()).toBe(6);
  for (let i = 0; i < 6; i++) {
    const d = all.nth(i);
    await d.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(d).toHaveJSProperty('open', true);
  }
  // The hostile quote is text, not markup.
  await expect(page.locator('details[data-evidence="verified"]').first().locator('blockquote'))
    .toContainText('<script>alert("edu")</script>');
  expect(await page.locator('details[data-evidence] img, details[data-evidence] script').count()).toBe(0);
  await expect(page.locator('details[data-evidence="absent"]')).toContainText('Lipsa unui citat nu înseamnă că cerința nu există');
  await expect(page.locator('details[data-evidence="unverified"]').first()).toContainText('Text extras automat (neverificat):');
  expect(await noOverflow(page), 'no horizontal overflow with every disclosure open').toBeLessThanOrEqual(1);

  // Two roles: each role's disclosures, opened by keyboard, show that role's quote only.
  await page.goto(base + '/job/1009/');
  const role1 = page.locator('[data-role]').nth(0);
  const role2 = page.locator('[data-role]').nth(1);
  await role1.locator('details[data-evidence="verified"] > summary').first().focus();
  await page.keyboard.press('Enter');
  await role2.locator('details[data-evidence="verified"] > summary').first().focus();
  await page.keyboard.press('Space');
  await expect(role1.locator('blockquote').first()).toContainText('ROLE1-UNIQUE');
  await expect(role2.locator('blockquote').first()).toContainText('ROLE2-UNIQUE');
  await expect(role2).not.toContainText('ROLE1-');
  expect(await noOverflow(page)).toBeLessThanOrEqual(1);

  expect(dialogs).toEqual([]);
  expect(errors).toEqual([]);
});
