// ADR 0005 — the gov2 design system on every page: keyboard focus shows the DS
// focus colours, and no page type overflows sideways (320, 375 and 1280 from
// playwright.config.js). Fixture clock 2026-10-03.
const { test, expect } = require('@playwright/test');

const FOCUS = 'rgb(255, 221, 0)';     // --g2-color-focus
const ON_FOCUS = 'rgb(11, 12, 12)';   // --g2-color-on-focus

// One URL for each page type of the DS-alignment brief.
const PAGES = {
  'list': '/posturi/',
  'list, filtered': '/?judet%5B%5D=cluj&q=asistent',
  'detail': '/job/1001-asistent-medical-generalist/',
  'archived': '/job/9001-asistent-medical-principal/',
  'employers': '/angajatori/',
  'employer': '/angajator/spitalul-clinic-judetean-cluj/',
  'statistics': '/statistici/',
  'saved': '/salvate/',
  'alerts': '/alerte/',
  'alerts, subscribe': '/alerte/abonare/?cheie=judet%255B%255D%3Dilfov&nume=Ilfov',
  'alerts, bad confirm link': '/alerte/confirma/1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'alerts, bad manage link': '/alerte/abonari/1/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
  'eligibility tool': '/pot-aplica/',
  'employer builder': '/pentru-angajatori/anunt/',
  'about': '/despre/',
  'landing': '/',
  '404': '/nu-exista/',
  '400': '/?q%5B%5D=x',
};

for (const [name, url] of Object.entries(PAGES)) {
  test(`no horizontal overflow, DS stylesheet applied: ${name}`, async ({ page }) => {
    await page.goto(url);
    const o = await page.evaluate(() => document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth);
    expect(o, 'horizontal overflow').toBeLessThanOrEqual(1);
    // gov2.css is in effect: the DS tokens are on :root, and Inter is the body family.
    expect(await page.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--g2-color-focus').trim())).toBe('255 221 0');
    expect(await page.evaluate(() => getComputedStyle(document.body).fontFamily)).toContain('Inter');
  });
}

async function expectFocus(page, locator, what) {
  await locator.scrollIntoViewIfNeeded();
  await page.keyboard.press('Tab');                       // a keyboard event makes :focus-visible match
  await locator.focus();
  await page.waitForTimeout(300);                         // a `transition-colors` utility needs its time
  const s = await locator.evaluate((el) => {
    const c = getComputedStyle(el);
    return { bg: c.backgroundColor, fg: c.color, ol: c.outlineColor, ow: c.outlineWidth, shadow: c.boxShadow, focused: el.matches(':focus-visible') };
  });
  expect(s.focused, `${what}: :focus-visible matches`).toBe(true);
  const isField = await locator.evaluate((el) => /^(input|select|textarea)$/i.test(el.tagName));
  if (isField) {
    // A field: a yellow outline of 3px and an ink inset ring (the DS field focus).
    expect(s.ol, `${what}: outline colour`).toBe(FOCUS);
    expect(parseFloat(s.ow), `${what}: outline width`).toBeGreaterThanOrEqual(3);
    expect(s.shadow, `${what}: inset ring`).toContain(ON_FOCUS);
  } else {
    expect(s.bg, `${what}: background`).toBe(FOCUS);
    expect(s.fg, `${what}: text colour`).toBe(ON_FOCUS);
    expect(s.shadow, `${what}: bar`).toContain(ON_FOCUS);
  }
}

test('keyboard focus: a navigation link, a sector link, the search button, the search input', async ({ page }) => {
  await page.goto('/');
  await expectFocus(page, page.locator('nav[aria-label="Navigare principală"] a').first(), 'navigation link');
  await expectFocus(page, page.locator('[data-home-tile] a').first(), 'sector link');
  await expectFocus(page, page.getByRole('button', { name: 'Caută', exact: true }), 'search button');
  await expectFocus(page, page.locator('#home-q'), 'search input');
});

test('keyboard focus: list filter controls (search field, status select, a filter summary)', async ({ page, viewport }) => {
  await page.goto('/posturi/');
  await expectFocus(page, page.locator('#q'), 'list search input');
  await expectFocus(page, page.locator('#status'), 'status select');
  if (viewport.width >= 1024) {
    await expectFocus(page, page.locator('#facet-list summary').first(), 'filter group summary');
  }
  // A control that a utility colours (the layout switch, aria-pressed) still shows the focus colours.
  if (viewport.width >= 1024) {
    await expectFocus(page, page.locator('#layout-switch button').first(), 'view switch button');
  }
});
