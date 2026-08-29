import { type APIRequestContext, type Page } from '@playwright/test';
import { waitForMessage, extractLinkToken } from './mailpit';

/** Register a new account via the UI. Fills name, email, password, confirmation, accepts terms. */
export async function registerViaUi(
  page: Page,
  opts: { email: string; password: string },
): Promise<void> {
  await page.goto('/register');
  await page.locator('input[name="name"]').fill('Test User');
  await page.locator('input[name="email"]').fill(opts.email);
  await page.locator('input[name="password"]').fill(opts.password);
  await page.locator('input[name="password_confirmation"]').fill(opts.password);
  await page.locator('input[type="checkbox"]').check();
  await page.locator('button[type="submit"]').click();
}

/** Wait for the verification email, extract the token, and submit the verify-email form. */
export async function verifyViaMailbox(
  page: Page,
  opts: { email: string },
): Promise<void> {
  const msg = await waitForMessage(opts.email);
  const token = extractLinkToken(msg);
  await page.goto(`/verify-email?token=${encodeURIComponent(token)}`);
  await page.locator('input[name="token"]').fill(token);
  await page.locator('button[type="submit"]').click();
}

/** Log in via the UI. Optionally navigates to returnUrl first. */
export async function loginViaUi(
  page: Page,
  opts: { email: string; password: string; returnUrl?: string },
): Promise<void> {
  const loginUrl = opts.returnUrl
    ? `/login?returnUrl=${encodeURIComponent(opts.returnUrl)}`
    : '/login';
  await page.goto(loginUrl);
  await page.locator('input[name="email"]').fill(opts.email);
  await page.locator('input[name="password"]').fill(opts.password);
  await page.locator('button[type="submit"]').click();
}

/**
 * Create a BFF session via the probe endpoint.
 * Returns the raw session cookie value from the Set-Cookie header.
 */
export async function probeCreateSession(
  request: APIRequestContext,
  opts: { kind: 'session' | 'verification'; email: string },
): Promise<string> {
  const res = await request.post('/api/_test/session', {
    data: {
      bearer: `probe-sentinel-bearer-for-${opts.email}`,
      kind: opts.kind,
      userId: '00000000-0000-0000-0000-000000000001',
    },
    headers: { 'Content-Type': 'application/json' },
  });

  const setCookie = res.headers()['set-cookie'] ?? '';
  const match = setCookie.match(/(?:^|;\s*)([^=;]+=[^;]*)/);
  if (!match) {
    throw new Error(
      `probeCreateSession: no Set-Cookie header in response (status ${res.status()})`,
    );
  }
  // Return just the cookie value portion (after the first '=')
  const parts = match[1].split('=');
  return parts.slice(1).join('=');
}
