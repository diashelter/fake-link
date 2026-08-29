/**
 * T20 — Accessibility: axe-core on critical auth flows + 360px reflow.
 *
 * Failures on `serious` / `critical` violations fail the gate.
 * `moderate` / `minor` violations are logged but do not fail.
 *
 * ACs: E2E-15..16, BFFUI-83
 */
import { test, expect, type Page } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

import { loginViaUi } from './helpers/account';
import { waitForMessage, extractLinkToken } from './helpers/mailpit';

const TEST_EMAIL = 'e2e-auth@fake-link.test';
const TEST_PASSWORD = 'E2E-P4ssw0rd!';

// ---------------------------------------------------------------------------
// Helper: run axe, fail on serious/critical, log minor/moderate
// ---------------------------------------------------------------------------
async function expectNoSeriousA11y(page: Page, label: string): Promise<void> {
  const results = await new AxeBuilder({ page }).analyze();

  const blocking = results.violations.filter(
    (v) => v.impact === 'serious' || v.impact === 'critical',
  );
  const advisory = results.violations.filter(
    (v) => v.impact === 'moderate' || v.impact === 'minor',
  );

  if (advisory.length > 0) {
    // Log non-blocking violations for triage without failing the gate
    console.log(
      `[a11y] ${label} — ${advisory.length} advisory violation(s) (moderate/minor):`,
      advisory.map((v) => `${v.id}: ${v.description}`).join(', '),
    );
  }

  if (blocking.length > 0) {
    // SPEC_DEVIATION note: if a real serious/critical violation is found,
    // we do NOT suppress the rule — we fail the test.
    // Create a separate "fix task" for the accessibility issue.
    console.error(
      `[a11y] ${label} — BLOCKING violations (serious/critical):`,
      JSON.stringify(blocking, null, 2),
    );
  }

  expect(blocking, `${label}: serious/critical a11y violations`).toHaveLength(0);
}

// ---------------------------------------------------------------------------
// Helper: reflow check at 360px width
// ---------------------------------------------------------------------------
async function expectNoHorizontalScroll(page: Page, label: string): Promise<void> {
  await page.setViewportSize({ width: 360, height: 800 });
  const hasScroll = await page.evaluate(
    () => document.body.scrollWidth > document.documentElement.clientWidth,
  );
  expect(hasScroll, `${label}: horizontal body scroll at 360px`).toBe(false);
  // Restore default viewport
  await page.setViewportSize({ width: 1280, height: 720 });
}

// ---------------------------------------------------------------------------
// Unauthenticated pages
// ---------------------------------------------------------------------------
test('axe: /login has no serious/critical violations', async ({ page }) => {
  await page.goto('/login');
  await page.waitForLoadState('networkidle');
  await expectNoSeriousA11y(page, '/login');
});

test('axe: /register has no serious/critical violations', async ({ page }) => {
  await page.goto('/register');
  await page.waitForLoadState('networkidle');
  await expectNoSeriousA11y(page, '/register');
});

test('axe: /verify-email has no serious/critical violations', async ({ page }) => {
  // Navigate with a stub token; the page renders the form even with an invalid token
  await page.goto('/verify-email?token=stub-token-for-a11y');
  await page.waitForLoadState('networkidle');
  await expectNoSeriousA11y(page, '/verify-email');
});

test('axe: /forgot-password has no serious/critical violations', async ({ page }) => {
  await page.goto('/forgot-password');
  await page.waitForLoadState('networkidle');
  await expectNoSeriousA11y(page, '/forgot-password');
});

test('axe: /reset-password has no serious/critical violations', async ({ page }) => {
  // Use a stub token; the page renders the form regardless
  await page.goto('/reset-password?token=stub-token-for-a11y');
  await page.waitForLoadState('networkidle');
  await expectNoSeriousA11y(page, '/reset-password');
});

// ---------------------------------------------------------------------------
// Authenticated page
// ---------------------------------------------------------------------------
test('axe: /settings (authenticated) has no serious/critical violations', async ({ page }) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });
  await page.waitForLoadState('networkidle');

  await expectNoSeriousA11y(page, '/settings');
});

// ---------------------------------------------------------------------------
// 360px reflow: no horizontal scroll on critical pages
// ---------------------------------------------------------------------------
test('360px reflow: no horizontal body scroll on auth pages', async ({ page }) => {
  const pages: Array<{ path: string; setup?: () => Promise<void> }> = [
    { path: '/login' },
    { path: '/register' },
    { path: '/verify-email?token=stub' },
    { path: '/forgot-password' },
    { path: '/reset-password?token=stub' },
  ];

  for (const { path, setup } of pages) {
    if (setup) await setup();
    await page.goto(path);
    await page.waitForLoadState('networkidle');
    await expectNoHorizontalScroll(page, path);
  }

  // /settings requires auth
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });
  await page.waitForLoadState('networkidle');
  await expectNoHorizontalScroll(page, '/settings');
});

// ---------------------------------------------------------------------------
// Form error visible + associated to field at 360px
// ---------------------------------------------------------------------------
test('login form error is visible and associated to field at 360px', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 800 });
  await page.goto('/login');
  await page.waitForLoadState('networkidle');

  // Submit with invalid credentials to trigger a field error
  await page.locator('input[name="email"]').fill('bad@example.com');
  await page.locator('input[name="password"]').fill('wrong');
  await page.locator('button[type="submit"]').click();

  // Wait for an error message to appear (server-side validation or client-side)
  const errorLocator = page.locator('[role="alert"], [id$="-error"]').first();
  await errorLocator.waitFor({ timeout: 10_000 });

  // Error must be visible
  expect(await errorLocator.isVisible()).toBe(true);

  // Error must be programmatically associated to the field (AC E2E-16)
  // Check via aria-describedby on the invalid input, or aria-errormessage
  const associatedInput = page
    .locator('input[aria-describedby], input[aria-errormessage], input[aria-invalid="true"]')
    .first();
  const describedBy =
    (await associatedInput.getAttribute('aria-describedby')) ??
    (await associatedInput.getAttribute('aria-errormessage'));
  expect(describedBy, 'input must have aria-describedby or aria-errormessage referencing the error').toBeTruthy();

  // Restore viewport
  await page.setViewportSize({ width: 1280, height: 720 });
});
