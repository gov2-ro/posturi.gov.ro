// FIX-07 — browser regression suite for the PHP webapp.
//
// Serves the app on the built-in PHP router against the deterministic fixture
// database (POSTURI_DB) with a fixed clock (POSTURI_TODAY). No network, no
// production data. The fixture is built inside the webServer command itself:
// Playwright starts the server before any globalSetup hook runs.
//
// Run: npx playwright test --config webapp-php/tests/browser/playwright.config.js

const { defineConfig } = require('@playwright/test');
const os = require('os');
const path = require('path');

const webappDir = path.resolve(__dirname, '..', '..');
const fixture = path.join(os.tmpdir(), 'posturi-browser-fixture.sqlite');

module.exports = defineConfig({
  testDir: __dirname,
  timeout: 30_000,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: 'http://127.0.0.1:8823',
    trace: 'retain-on-failure',
  },
  webServer: {
    command: `php tests/fixtures/make.php ${fixture} && php -S 127.0.0.1:8823 router.php`,
    cwd: webappDir,
    url: 'http://127.0.0.1:8823/',
    reuseExistingServer: false,
    timeout: 20_000,
    env: {
      POSTURI_DB: fixture,
      POSTURI_TODAY: '2026-10-03',
    },
  },
  projects: [
    { name: 'mobile-320', use: { viewport: { width: 320, height: 568 } } },
    { name: 'mobile-375', use: { viewport: { width: 375, height: 667 } } },
    { name: 'desktop-1280', use: { viewport: { width: 1280, height: 900 } } },
  ],
});
