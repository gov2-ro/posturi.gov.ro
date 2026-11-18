// SALARY-EU-DETAIL: the detail page of a post hired for an EU/PNRR project (hourly pay, month pay, no pay)
// shows the box and the stated pay, never the grid figure, the pay meter or the estimator link; the list row
// follows; no horizontal overflow at 375 and 1280. Serves its own fixture (`make.php <path> project`: the
// shared fixture keeps its counts for the other specs), on its own PHP server and port.
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
  const fixture = path.join(os.tmpdir(), `posturi-project-browser-${process.pid}.sqlite`);
  execFileSync('php', ['tests/fixtures/make.php', fixture, 'project'], { cwd: webappDir });
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
  throw new Error('project fixture server did not start');
});

test.afterAll(() => { server?.kill(); });

const overflow = (page) =>
  page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);

for (const width of [375, 1280]) {
  for (const [id, figure] of [[1001, '50 lei/oră net · 84 ore/lună ≈ 4.200 lei net/lună'], [1002, '2.100 lei net/lună'], [1003, null]]) {
    test(`detail page of project post ${id} at ${width}px`, async ({ page }) => {
      const errors = [];
      page.on('pageerror', (e) => errors.push(e.message));
      await page.setViewportSize({ width, height: 900 });
      await page.goto(`${base}/job/${id}/`);
      await expect(page.locator('[data-pay-project-note]')).toHaveText('Post finanțat din fonduri europene: salariul depinde de bugetul proiectului.');
      if (figure) await expect(page.locator('[data-pay-project-figure]')).toHaveText(figure);
      else await expect(page.locator('[data-pay-project-figure]')).toHaveCount(0);
      await expect(page.locator('[data-pay-level]')).toHaveCount(0);
      await expect(page.locator('.pay-meter')).toHaveCount(0);
      await expect(page.locator('[data-salary-estimator-link]')).toHaveCount(0);
      await expect(page.locator('[data-pay-net]')).toHaveCount(0);
      expect(await overflow(page), 'horizontal overflow').toBeLessThanOrEqual(1);
      expect(errors).toEqual([]);
      if (process.env.PROJECT_SHOTS) await page.screenshot({ path: path.join(process.env.PROJECT_SHOTS, `fixture-${id}-${width}.png`), fullPage: false });
    });
  }
}

test('the list row of a project post, no overflow at 375', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 900 });
  await page.goto(`${base}/?status=all`);
  await expect(page.getByText('salariu din bugetul proiectului').first()).toBeVisible();
  expect(await overflow(page), 'horizontal overflow').toBeLessThanOrEqual(1);
});
