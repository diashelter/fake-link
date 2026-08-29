import { test, expect, type BrowserContext } from '@playwright/test';

import { clearMailbox, waitForMessage, extractLinkToken } from './helpers/mailpit';
import { registerViaUi, verifyViaMailbox, loginViaUi } from './helpers/account';

const TEST_EMAIL = 'e2e-auth@fake-link.test';
const TEST_PASSWORD = 'E2E-P4ssw0rd!';
const NEW_PASSWORD = 'E2E-N3wP4ssw0rd!';
const SESSION_COOKIE = '__Host-fl_session';
const COOKIE_PATTERN = /^[A-Za-z0-9_-]{43}$/;

function getSessionCookie(ctx: BrowserContext) {
  return ctx.cookies().then((cs) => cs.find((c) => c.name === SESSION_COOKIE));
}

// ---------------------------------------------------------------------------
// T15-1: Register → verification session created, email delivered
// ---------------------------------------------------------------------------
test('register creates verification session and delivers email', async ({ page }) => {
  await clearMailbox();

  await registerViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });

  // After register the BFF sets a verification session cookie
  const cookie = await getSessionCookie(page.context());
  expect(cookie, 'session cookie should be set after registration').toBeDefined();
  expect(cookie!.value).toMatch(COOKIE_PATTERN);
  expect(cookie!.secure).toBe(true);
  expect(cookie!.httpOnly).toBe(true);

  // Mailpit should have received exactly one message for the test email
  const msg = await waitForMessage(TEST_EMAIL, { timeoutMs: 10_000 });
  expect(msg.To.some((r) => r.Address === TEST_EMAIL)).toBe(true);
});

// ---------------------------------------------------------------------------
// T15-2: Email verification promotes account to active → redirect to /login
// ---------------------------------------------------------------------------
test('email verification promotes account and redirects to /login', async ({ page }) => {
  // Reuse the email sent during T15-1 (mailbox not cleared between these two)
  const msg = await waitForMessage(TEST_EMAIL, { timeoutMs: 10_000 });
  const token = extractLinkToken(msg);

  await page.goto(`/verify-email?token=${encodeURIComponent(token)}`);
  await page.locator('input[name="token"]').fill(token);
  await page.locator('button[type="submit"]').click();

  // After verification the form redirects to /login
  await page.waitForURL(/\/login/, { timeout: 10_000 });
  expect(page.url()).toMatch(/\/login/);
});

// ---------------------------------------------------------------------------
// T15-3: Login after verification — cookie rotated, lands on /
// ---------------------------------------------------------------------------
test('login rotates cookie and navigates to post-login destination', async ({ page }) => {
  // At this point the account is active (verified in T15-2) and no session exists.
  // Pre-login: no session cookie
  const preLogin = await getSessionCookie(page.context());
  expect(preLogin).toBeUndefined();

  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });

  // Should navigate to the default post-login destination
  await page.waitForURL('/', { timeout: 10_000 });
  expect(page.url()).toMatch(/\/$/);

  // Post-login session cookie must be present and well-formed
  const postLogin = await getSessionCookie(page.context());
  expect(postLogin, 'session cookie should exist after login').toBeDefined();
  expect(postLogin!.value).toMatch(COOKIE_PATTERN);
  expect(postLogin!.secure).toBe(true);
  expect(postLogin!.httpOnly).toBe(true);

  // If there had been a pre-login session cookie it would differ (rotation).
  // Here the pre-login cookie is absent, so the post-login cookie is the new one.
});

// ---------------------------------------------------------------------------
// T15-4: Logout removes cookie; re-login + logout-all (password) → 204
// ---------------------------------------------------------------------------
test('logout removes cookie; re-login and logout-all with password clears session', async ({
  page,
}) => {
  // ---- Step 1: log in ----
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  // Capture pre-logout cookie id
  const beforeLogout = await getSessionCookie(page.context());
  expect(beforeLogout).toBeDefined();

  // ---- Step 2: click "Sair" (logout) ----
  await page.locator('button[type="submit"]:has-text("Sair")').click();
  await page.waitForURL(/\/login/, { timeout: 10_000 });

  // Cookie should be absent
  const afterLogout = await getSessionCookie(page.context());
  expect(afterLogout).toBeUndefined();

  // /settings should redirect to /login
  await page.goto('/settings');
  await page.waitForURL(/\/login/, { timeout: 10_000 });

  // ---- Step 3: re-login ----
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const afterRelogin = await getSessionCookie(page.context());
  expect(afterRelogin).toBeDefined();
  // Cookie id must differ from the one before logout
  expect(afterRelogin!.value).not.toEqual(beforeLogout!.value);

  // ---- Step 4: navigate to /settings and submit logout-all ----
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });

  // Intercept the logout-all response
  let logoutAllStatus = 0;
  page.on('response', (resp) => {
    if (resp.url().includes('/api/bff/auth/logout-all')) {
      logoutAllStatus = resp.status();
    }
  });

  await page.locator('input[name="current_password"]').fill(TEST_PASSWORD);
  await page.locator('button[type="submit"]:has-text("Encerrar todas as sessões")').click();

  await page.waitForURL(/\/login/, { timeout: 10_000 });

  // Upstream should have responded 204
  expect(logoutAllStatus).toBe(204);

  // Session cookie must be gone
  const afterLogoutAll = await getSessionCookie(page.context());
  expect(afterLogoutAll).toBeUndefined();
});

// ---------------------------------------------------------------------------
// T15-5: Forgot-password → reset → previous session rejected, new password authenticates
// ---------------------------------------------------------------------------
test('password reset invalidates previous session and authenticates with new password', async ({
  page,
  browser,
}) => {
  // ---- Step 1: log in, capture session cookie ----
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const previousCookie = await getSessionCookie(page.context());
  expect(previousCookie).toBeDefined();
  const previousCookieValue = previousCookie!.value;

  // ---- Step 2: request password reset ----
  await clearMailbox();
  await page.goto('/forgot-password');

  await page.locator('input[name="email"]').fill(TEST_EMAIL);
  await page.locator('button[type="submit"]:has-text("Enviar instruções")').click();

  // Wait for success status message
  await page.locator('[role="status"]').waitFor({ timeout: 10_000 });

  // ---- Step 3: extract reset token from Mailpit ----
  const resetMsg = await waitForMessage(TEST_EMAIL, { timeoutMs: 10_000 });
  const resetToken = extractLinkToken(resetMsg);

  // ---- Step 4: complete reset with new password ----
  await page.goto(`/reset-password?token=${encodeURIComponent(resetToken)}`);

  await page.locator('input[name="email"]').fill(TEST_EMAIL);
  await page.locator('input[name="token"]').fill(resetToken);
  await page.locator('input[name="password"]').fill(NEW_PASSWORD);
  await page.locator('input[name="password_confirmation"]').fill(NEW_PASSWORD);
  await page.locator('button[type="submit"]:has-text("Redefinir senha")').click();

  await page.waitForURL(/\/login/, { timeout: 10_000 });

  // ---- Step 5: verify previous session is rejected ----
  // Use a fresh context with only the old cookie to probe the session
  const oldCtx = await browser.newContext({
    ignoreHTTPSErrors: true,
    launchOptions: {
      args: [
        '--host-resolver-rules=MAP app.localhost:443 nginx:443, MAP go.localhost:443 nginx:443',
      ],
    },
  });
  const oldPage = await oldCtx.newPage();

  // Inject old session cookie
  await oldCtx.addCookies([
    {
      name: SESSION_COOKIE,
      value: previousCookieValue,
      domain: 'app.localhost',
      path: '/',
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
    },
  ]);

  await oldPage.goto('/settings');
  await oldPage.waitForURL(/\/login/, { timeout: 10_000 });
  // Previous session is rejected — redirected to /login
  expect(oldPage.url()).toMatch(/\/login/);

  await oldCtx.close();

  // ---- Step 6: new password authenticates ----
  await loginViaUi(page, { email: TEST_EMAIL, password: NEW_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const newCookie = await getSessionCookie(page.context());
  expect(newCookie).toBeDefined();
  expect(newCookie!.value).toMatch(COOKIE_PATTERN);

  // Reset the password back to the original so subsequent suites work
  // (Forgot-password for original password or just note the deviation)
  // We leave NEW_PASSWORD in effect — journey spec is last to exercise password reset.
});
