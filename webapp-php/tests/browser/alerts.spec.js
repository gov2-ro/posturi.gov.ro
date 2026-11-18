// UX-14 browser checks: email alerts. Subscribe from /salvate/, confirm, manage,
// delete one and delete all, and "Abonamentele mele" with a one-hour link. The
// outbox is read through bin/alerts.php (tests/fixtures/alerts_outbox.php opens
// the sealed rows with the test key), so no email is involved. Runs at 320, 375
// and 1280 against the fixture and a fresh private directory (playwright.config.js).
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const os = require('os');
const path = require('path');

const webappDir = path.resolve(__dirname, '..', '..');
const alertsDir = path.join(os.tmpdir(), 'posturi-browser-alerts');
const KEY = 'posturi.searches.v1';
const CLUJ = 'judet%5B%5D=cluj';
const ILFOV = 'judet%5B%5D=ilfov';

const pageErrors = new WeakMap();
test.beforeEach(async ({ page }) => {
  const errs = [];
  pageErrors.set(page, errs);
  page.on('pageerror', (e) => errs.push(e.message));
  page.on('dialog', (d) => { errs.push('dialog: ' + d.message()); d.dismiss(); });
});
test.afterEach(async ({ page }) => {
  expect(pageErrors.get(page), 'uncaught page errors').toEqual([]);
});

/** The decrypted outbox rows of one address; the rows are cleared once read. */
function outboxFor(email) {
  const out = execFileSync('php', ['tests/fixtures/alerts_outbox.php', '--clear'], {
    cwd: webappDir, env: { ...process.env, ALERTS_DIR: alertsDir }, encoding: 'utf8',
  });
  return JSON.parse(out).filter((r) => r.email === email);
}

const rec = (key, label) => ({ key, qs: key, sort: 'deadline', label, savedAt: '2026-10-01T10:00:00.000Z', openedAt: null });

async function seedSearches(page, recs) {
  await page.goto('/despre/');
  await page.evaluate(([k, v]) => localStorage.setItem(k, v), [KEY, JSON.stringify({
    version: 1, defaultId: null, searches: Object.fromEntries(recs.map((r) => [r.key, r])),
  })]);
}

async function fits(page, selector, what) {
  const over = await page.evaluate((sel) => {
    const w = document.documentElement.clientWidth;
    return Array.from(document.querySelectorAll(sel)).filter((e) => e.getClientRects().length)
      .map((e) => Math.round(e.getBoundingClientRect().right - w)).filter((d) => d > 1);
  }, selector);
  expect(over, `overflow past the viewport: ${what}`).toEqual([]);
}

test('subscribe from /salvate/, confirm, manage, delete', async ({ page }, testInfo) => {
  const email = `ana-${testInfo.project.name}-${Date.now()}@example.com`;
  await seedSearches(page, [rec(CLUJ, 'Cluj, medici'), rec(ILFOV, 'Ilfov')]);
  await page.goto('/salvate/');
  const row = page.locator('[data-search-key]').filter({ hasText: 'Cluj, medici' });
  const button = row.getByRole('link', { name: /Alertă pe email/ });
  await expect(button).toBeVisible();
  await button.click();

  // The form: the search, the address, the honeypot (hidden), the consent text.
  await expect(page).toHaveURL(/\/alerte\/abonare\/\?cheie=/);
  await expect(page.getByRole('heading', { name: 'Alertă pe email' })).toBeVisible();
  await expect(page.getByText('Cluj, medici')).toBeVisible();
  await expect(page.locator('[aria-hidden="true"] input[name="website"]')).toHaveAttribute('tabindex', '-1');
  await expect(page.getByText('Prin abonare ești de acord')).toBeVisible();
  await expect(page.getByRole('link', { name: 'confidențialitate' }).last()).toHaveAttribute('href', '/despre/#alerte-email');
  await fits(page, 'form > :not(.sr-only), form > :not(.sr-only) *', 'subscribe form');
  await page.getByLabel('Adresa ta de email').fill(email);
  await page.getByRole('button', { name: 'Abonează-mă' }).click();
  await expect(page.getByText('Verifică-ți emailul. Linkul ajunge în cel mult 5 minute.')).toBeVisible();

  // /salvate/ marks the search in this browser, and only that search.
  await page.goto('/salvate/');
  await expect(page.locator('[data-search-key]').filter({ hasText: 'Cluj, medici' }).getByText('alertă cerută')).toBeVisible();
  await expect(page.locator('[data-search-key]').filter({ hasText: 'Ilfov' }).getByText('alertă cerută')).toBeHidden();

  const [confirmMail] = outboxFor(email);
  expect(confirmMail.kind).toBe('confirm');
  const link = `/alerte/confirma/${confirmMail.subscription_id}/${confirmMail.token}`;

  // A GET (a mail scanner) shows a button and changes nothing.
  await page.goto(link);
  await expect(page.getByText('Cluj, medici')).toBeVisible();
  await page.goto(`/alerte/abonari/${confirmMail.subscription_id}/${confirmMail.token}`);
  await expect(page.locator('[data-subscription]')).toContainText('în așteptarea confirmării');
  await page.goto(link);
  await page.getByRole('button', { name: 'Confirmă abonarea' }).click();
  await expect(page.getByText('Abonarea este confirmată.')).toBeVisible();

  // A second subscription of the same address, confirmed too.
  await page.goto('/alerte/abonare/?cheie=' + encodeURIComponent(ILFOV) + '&nume=Ilfov');
  await page.getByLabel('Adresa ta de email').fill(email);
  await page.getByRole('button', { name: 'Abonează-mă' }).click();
  const [second] = outboxFor(email);
  await page.goto(`/alerte/confirma/${second.subscription_id}/${second.token}`);
  await page.getByRole('button', { name: 'Confirmă abonarea' }).click();

  // The manage page lists both.
  await page.goto(`/alerte/abonari/${confirmMail.subscription_id}/${confirmMail.token}`);
  await expect(page.locator('[data-subscription]')).toHaveCount(2);
  await fits(page, '[data-alerts-list], [data-alerts-list] *', 'manage list');

  // Delete one: the other stays, and the page keeps working with a new one-hour link.
  await page.locator('[data-subscription]').filter({ hasText: 'Cluj, medici' }).getByRole('button', { name: /Șterge/ }).click();
  await expect(page.getByText('Abonarea este ștearsă.')).toBeVisible();
  await expect(page.locator('[data-subscription]')).toHaveCount(1);
  await expect(page.locator('[data-subscription]')).toContainText('Ilfov');
  await page.goto(`/alerte/abonari/${confirmMail.subscription_id}/${confirmMail.token}`);
  await expect(page.getByText('Linkul nu este valid sau a expirat.')).toBeVisible();

  // Delete all, through the link of the second subscription.
  await page.goto(`/alerte/abonari/${second.subscription_id}/${second.token}`);
  await page.getByRole('button', { name: 'Șterge toate' }).click();
  await expect(page.getByText('Toate abonările sunt șterse.')).toBeVisible();
  await expect(page.locator('[data-subscription]')).toHaveCount(0);
});

test('the delete link of an email asks first and deletes on the button', async ({ page }, testInfo) => {
  const email = `ion-${testInfo.project.name}-${Date.now()}@example.com`;
  await page.goto('/alerte/abonare/?cheie=' + encodeURIComponent(CLUJ) + '&nume=Cluj');
  await page.getByLabel('Adresa ta de email').fill(email);
  await page.getByRole('button', { name: 'Abonează-mă' }).click();
  const [m] = outboxFor(email);
  await page.goto(`/alerte/confirma/${m.subscription_id}/${m.token}`);
  await page.getByRole('button', { name: 'Confirmă abonarea' }).click();

  await page.goto(`/alerte/sterge/${m.subscription_id}/${m.token}`);
  await expect(page.getByRole('button', { name: 'Șterge abonarea' })).toBeVisible();
  await page.goto(`/alerte/abonari/${m.subscription_id}/${m.token}`);
  await expect(page.locator('[data-subscription]')).toHaveCount(1);       // the GET deleted nothing
  await page.goto(`/alerte/sterge/${m.subscription_id}/${m.token}`);
  await page.getByRole('button', { name: 'Șterge abonarea' }).click();
  await expect(page.getByText('Abonarea este ștearsă.')).toBeVisible();
});

test('„Abonamentele mele”: the same answer for any address, a one-hour link for a known one', async ({ page }, testInfo) => {
  const email = `maria-${testInfo.project.name}-${Date.now()}@example.com`;
  await page.goto('/alerte/abonare/?cheie=' + encodeURIComponent(CLUJ) + '&nume=Cluj');
  await page.getByLabel('Adresa ta de email').fill(email);
  await page.getByRole('button', { name: 'Abonează-mă' }).click();
  const [c] = outboxFor(email);
  await page.goto(`/alerte/confirma/${c.subscription_id}/${c.token}`);
  await page.getByRole('button', { name: 'Confirmă abonarea' }).click();

  const answer = 'Dacă ai abonări, îți trimitem un link în cel mult 5 minute.';
  await page.goto('/alerte/');
  await fits(page, 'form > :not(.sr-only), form > :not(.sr-only) *', 'login form');
  await page.getByLabel('Adresa ta de email').fill('nimeni-' + Date.now() + '@example.com');
  await page.getByRole('button', { name: 'Trimite linkul' }).click();
  await expect(page.getByText(answer)).toBeVisible();

  await page.goto('/alerte/');
  await page.getByLabel('Adresa ta de email').fill(email);
  await page.getByRole('button', { name: 'Trimite linkul' }).click();
  await expect(page.getByText(answer)).toBeVisible();
  const [mail] = outboxFor(email);
  expect(mail.kind).toBe('manage');

  await page.goto(`/alerte/intra/${mail.token}`);
  await expect(page.locator('[data-subscription]')).toHaveCount(1);
  await page.getByRole('button', { name: 'Șterge toate' }).click();
  await expect(page.getByText('Toate abonările sunt șterse.')).toBeVisible();
  await page.goto('/alerte/intra/' + 'a'.repeat(43));
  await expect(page.getByText('Linkul nu este valid sau a expirat.')).toBeVisible();
});

test('the empty search cannot be subscribed, and bin/ is not served', async ({ page, request }) => {
  const res = await page.goto('/alerte/abonare/?cheie=');
  expect(res.status()).toBe(400);
  await expect(page.getByText('Căutarea nu este validă.')).toBeVisible();
  const bin = await request.get('/bin/alerts.php');
  expect([403, 404]).toContain(bin.status());
});
