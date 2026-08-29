/**
 * T18 — CSRF, Origin enforcement, and returnUrl sanitisation.
 *
 * ACs: E2E-09..11, BFFUI-81
 */
import { test, expect, type BrowserContext } from '@playwright/test';

import { loginViaUi } from './helpers/account';

const TEST_EMAIL = 'e2e-auth@fake-link.test';
const TEST_PASSWORD = 'E2E-P4ssw0rd!';
const SESSION_COOKIE = '__Host-fl_session';
const CSRF_TOKEN_COOKIE = '__Host-fl_csrf';
const CSRF_SID_COOKIE = '__Host-fl_csrf_sid';
const LOGOUT_ALL_PATH = '/api/bff/auth/logout-all';

/** Return all cookies for app.localhost from the given context. */
async function getAppCookies(ctx: BrowserContext) {
  const all = await ctx.cookies();
  return all.filter((c) => c.domain === 'app.localhost' || c.domain === '.app.localhost');
}

/** Build a Cookie header string from an array of name=value pairs. */
function cookieHeader(pairs: Array<{ name: string; value: string }>): string {
  return pairs.map((p) => `${p.name}=${p.value}`).join('; ');
}

// ---------------------------------------------------------------------------
// T18-1: POST without Origin header → ≥ 400, session intact
// ---------------------------------------------------------------------------
test('logout-all without Origin header is rejected and session stays intact', async ({
  page,
  request,
}) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const cookies = await getAppCookies(page.context());
  const session = cookies.find((c) => c.name === SESSION_COOKIE);
  const csrf = cookies.find((c) => c.name === CSRF_TOKEN_COOKIE);
  expect(session).toBeDefined();
  expect(csrf).toBeDefined();

  // Forged request — no Origin header
  const resp = await request.post(LOGOUT_ALL_PATH, {
    headers: {
      'Content-Type': 'application/json',
      Cookie: cookieHeader([session!, csrf!]),
      'X-CSRF-Token': csrf!.value,
      // Origin intentionally omitted
    },
    data: { current_password: TEST_PASSWORD },
  });
  expect(resp.status()).toBeGreaterThanOrEqual(400);

  // Session must still be valid: /settings does NOT redirect to /login
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });
  expect(page.url()).toContain('/settings');
});

// ---------------------------------------------------------------------------
// T18-2: POST with divergent Origin header → ≥ 400, session intact
// ---------------------------------------------------------------------------
test('logout-all with divergent Origin is rejected and session stays intact', async ({
  page,
  request,
}) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const cookies = await getAppCookies(page.context());
  const session = cookies.find((c) => c.name === SESSION_COOKIE);
  const csrf = cookies.find((c) => c.name === CSRF_TOKEN_COOKIE);
  expect(session).toBeDefined();
  expect(csrf).toBeDefined();

  // Forged request — wrong Origin
  const resp = await request.post(LOGOUT_ALL_PATH, {
    headers: {
      'Content-Type': 'application/json',
      Cookie: cookieHeader([session!, csrf!]),
      'X-CSRF-Token': csrf!.value,
      Origin: 'https://evil.example.com',
    },
    data: { current_password: TEST_PASSWORD },
  });
  expect(resp.status()).toBeGreaterThanOrEqual(400);

  // Session intact
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });
  expect(page.url()).toContain('/settings');
});

// ---------------------------------------------------------------------------
// T18-3: CSRF cookie absent / body token divergent → rejected, no effect
// ---------------------------------------------------------------------------
test('logout-all with CSRF cookie removed and divergent token is rejected', async ({
  page,
  request,
}) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const cookies = await getAppCookies(page.context());
  const session = cookies.find((c) => c.name === SESSION_COOKIE);
  expect(session).toBeDefined();

  // Send only the session cookie — no CSRF cookie, wrong X-CSRF-Token
  const resp = await request.post(LOGOUT_ALL_PATH, {
    headers: {
      'Content-Type': 'application/json',
      Cookie: cookieHeader([session!]),
      'X-CSRF-Token': 'definitely-wrong-csrf-token',
      Origin: 'https://app.localhost',
    },
    data: { current_password: TEST_PASSWORD },
  });
  expect(resp.status()).toBeGreaterThanOrEqual(400);

  // Session intact
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });
  expect(page.url()).toContain('/settings');
});

// ---------------------------------------------------------------------------
// T18-4: Official form flow → 204 (negative control — must succeed)
// ---------------------------------------------------------------------------
test('official logout-all via the UI form succeeds with status 204', async ({ page }) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });

  let logoutAllStatus = 0;
  page.on('response', (resp) => {
    if (resp.url().includes(LOGOUT_ALL_PATH)) {
      logoutAllStatus = resp.status();
    }
  });

  await page.locator('input[name="current_password"]').fill(TEST_PASSWORD);
  await page.locator('button[type="submit"]:has-text("Encerrar todas as sessões")').click();

  await page.waitForURL(/\/login/, { timeout: 10_000 });
  expect(logoutAllStatus).toBe(204);
});

// ---------------------------------------------------------------------------
// T18-5a..d: Malicious returnUrl variations → internal path after login
// ---------------------------------------------------------------------------
const MALICIOUS_URLS = [
  'https://evil.example.com',
  '//evil.example.com',
  '/%2f%2fevil.example.com',
  '/\\evil.example.com',
];

for (const maliciousUrl of MALICIOUS_URLS) {
  test(`returnUrl "${maliciousUrl}" redirects to internal path after login`, async ({ page }) => {
    await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
    await page.waitForURL('/', { timeout: 10_000 });

    // Logout first so we can log in again with a returnUrl
    await page.locator('button[type="submit"]:has-text("Sair")').click();
    await page.waitForURL(/\/login/, { timeout: 10_000 });

    // Log in with the malicious returnUrl
    await loginViaUi(page, {
      email: TEST_EMAIL,
      password: TEST_PASSWORD,
      returnUrl: maliciousUrl,
    });

    // Wait for any navigation to settle
    await page.waitForLoadState('networkidle');

    const finalUrl = page.url();
    // Must be an internal app.localhost path — not the evil domain
    expect(finalUrl).toContain('app.localhost');
    expect(finalUrl).not.toContain('evil.example.com');
    // Must NOT navigate away from the app
    expect(finalUrl.startsWith('https://app.localhost')).toBe(true);
  });
}

// ---------------------------------------------------------------------------
// T18-6: Safe returnUrl=/settings → post-login navigates to /settings
// ---------------------------------------------------------------------------
test('safe returnUrl=/settings redirects to /settings after login', async ({ page }) => {
  // Ensure logged out
  await page.goto('/login');
  await page.waitForURL(/\/login/, { timeout: 10_000 });

  await loginViaUi(page, {
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
    returnUrl: '/settings',
  });

  await page.waitForURL(/\/settings/, { timeout: 10_000 });
  expect(page.url()).toContain('/settings');
});
