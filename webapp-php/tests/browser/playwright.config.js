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
// The themed pages (theme.spec.js): the shared fixture plus health postings (tests/fixtures/theme_db.php), on its own port.
const themeFixture = path.join(os.tmpdir(), 'posturi-browser-theme.sqlite');
// The occupation chips (chips.spec.js): 28 postings of one COR group (tests/fixtures/chips_db.php), on its own port.
const chipsFixture = path.join(os.tmpdir(), 'posturi-browser-chips.sqlite');
// The themes administratie and financiar (theme.spec.js): tests/fixtures/professional_db.php, on its own port.
const professionalFixture = path.join(os.tmpdir(), 'posturi-browser-professional.sqlite');
// Net salary (salary.spec.js): the shared fixture with postings that have no salary figure, on its own port.
const salaryFixture = path.join(os.tmpdir(), 'posturi-browser-salary.sqlite');
// Declared pay (declared_pay.spec.js): one posting with `baza` declaratii (tests/fixtures/build_db.php), on its own port.
const declaredFixture = path.join(os.tmpdir(), 'posturi-browser-declared.sqlite');
// UX-14: the private directory of the email alerts (a test key pair and pepper).
const alertsDir = path.join(os.tmpdir(), 'posturi-browser-alerts');

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
  webServer: [
    {
      command: `php tests/fixtures/make.php ${fixture} && php tests/fixtures/make_alerts.php ${alertsDir} '{"rate_per_hour":100000}' && php -S 127.0.0.1:8823 router.php`,
      cwd: webappDir,
      url: 'http://127.0.0.1:8823/',
      reuseExistingServer: false,
      timeout: 20_000,
      env: {
        POSTURI_DB: fixture,
        POSTURI_TODAY: '2026-10-03',
        ALERTS_DIR: alertsDir,
      },
    },
    {
      command: `php tests/fixtures/make.php ${themeFixture} theme && php -S 127.0.0.1:8824 router.php`,
      cwd: webappDir,
      url: 'http://127.0.0.1:8824/',
      reuseExistingServer: false,
      timeout: 20_000,
      env: {
        POSTURI_DB: themeFixture,
        POSTURI_TODAY: '2026-10-03',
      },
    },
    {
      command: `php tests/fixtures/make.php ${chipsFixture} chips && php -S 127.0.0.1:8826 router.php`,
      cwd: webappDir,
      url: 'http://127.0.0.1:8826/',
      reuseExistingServer: false,
      timeout: 20_000,
      env: {
        POSTURI_DB: chipsFixture,
        POSTURI_TODAY: '2026-10-03',
      },
    },
    {
      command: `php tests/fixtures/make.php ${professionalFixture} professional && php -S 127.0.0.1:8827 router.php`,
      cwd: webappDir,
      url: 'http://127.0.0.1:8827/',
      reuseExistingServer: false,
      timeout: 20_000,
      env: {
        POSTURI_DB: professionalFixture,
        POSTURI_TODAY: '2026-10-03',
      },
    },
    {
      command: `php tests/fixtures/make.php ${salaryFixture} salary && php -S 127.0.0.1:8825 router.php`,
      cwd: webappDir,
      url: 'http://127.0.0.1:8825/',
      reuseExistingServer: false,
      timeout: 20_000,
      env: {
        POSTURI_DB: salaryFixture,
        POSTURI_TODAY: '2026-10-03',
      },
    },
    {
      command: `php tests/fixtures/make.php ${declaredFixture} declared && php -S 127.0.0.1:8828 router.php`,
      cwd: webappDir,
      url: 'http://127.0.0.1:8828/',
      reuseExistingServer: false,
      timeout: 20_000,
      env: {
        POSTURI_DB: declaredFixture,
        POSTURI_TODAY: '2026-10-03',
      },
    },
  ],
  projects: [
    { name: 'mobile-320', use: { viewport: { width: 320, height: 568 } } },
    { name: 'mobile-375', use: { viewport: { width: 375, height: 667 } } },
    { name: 'desktop-1280', use: { viewport: { width: 1280, height: 900 } } },
  ],
});
