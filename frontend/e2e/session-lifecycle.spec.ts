/**
 * T19 — Session lifecycle: Redis loss and TTL expiry.
 *
 * The e2e Docker stack sets short TTLs for `session` kind:
 *   BFF_SESSION_ABSOLUTE_TTL_SESSION = 20s
 *   BFF_SESSION_IDLE_TTL_SESSION     = 8s
 *
 * Tests use `backdateSessionRecord` to cross the TTL boundary without
 * waiting in real time. No `waitForTimeout` fixed delays are used.
 *
 * ACs: E2E-12..14, BFFUI-82
 */
import { test, expect } from '@playwright/test';

import { loginViaUi } from './helpers/account';
import { flushEphemeralRedis, backdateSessionRecord } from './helpers/redis';

const TEST_EMAIL = 'e2e-auth@fake-link.test';
const TEST_PASSWORD = 'E2E-P4ssw0rd!';
const SESSION_COOKIE = '__Host-fl_session';

// Slightly above the e2e stack TTL values so the expiry is definitely crossed.
// The stack sets idle=8s and absolute=20s, so we backdate by more than these.
const IDLE_TTL_S = 8;
const ABS_TTL_S = 20;

// ---------------------------------------------------------------------------
// T19-1: Redis flush mid-session → redirect to /login, cookie absent
// ---------------------------------------------------------------------------
test('Redis flush invalidates active session immediately', async ({ page }) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  // Verify we have a session cookie before flush
  const before = await page.context().cookies();
  expect(before.find((c) => c.name === SESSION_COOKIE)).toBeDefined();

  // Flush Redis — all sessions vanish
  await flushEphemeralRedis();

  // Navigate to a protected page; BFF finds no record → clears cookie + redirects
  await page.goto('/settings');
  await page.waitForURL(/\/login/, { timeout: 10_000 });
  expect(page.url()).toMatch(/\/login/);

  // Cookie must be absent (BFF cleared it via Set-Cookie maxAge=0)
  const after = await page.context().cookies();
  const sessionCookie = after.find((c) => c.name === SESSION_COOKIE);
  expect(sessionCookie).toBeUndefined();
});

// ---------------------------------------------------------------------------
// T19-2: Idle TTL expiry → redirect to /login, cookie cleaned
// ---------------------------------------------------------------------------
test('session expired by idle TTL redirects to /login', async ({ page }) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const cookies = await page.context().cookies();
  const sessionCookie = cookies.find((c) => c.name === SESSION_COOKIE);
  expect(sessionCookie).toBeDefined();

  // Backdate lastActivityAt far enough to cross the idle TTL
  await backdateSessionRecord(sessionCookie!.value, {
    lastActivityDeltaS: IDLE_TTL_S + 2,
  });

  // Next protected request: BFF detects idle expiry → clear cookie + redirect
  await page.goto('/settings');
  await page.waitForURL(/\/login/, { timeout: 10_000 });
  expect(page.url()).toMatch(/\/login/);

  const after = await page.context().cookies();
  expect(after.find((c) => c.name === SESSION_COOKIE)).toBeUndefined();
});

// ---------------------------------------------------------------------------
// T19-3: Absolute TTL expiry → redirect to /login even with recent activity
// ---------------------------------------------------------------------------
test('session expired by absolute TTL redirects to /login despite recent activity', async ({
  page,
}) => {
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  const cookies = await page.context().cookies();
  const sessionCookie = cookies.find((c) => c.name === SESSION_COOKIE);
  expect(sessionCookie).toBeDefined();

  // Backdate createdAt to cross absolute TTL; keep lastActivityAt recent so
  // the idle check alone would pass — only the absolute check should fire.
  await backdateSessionRecord(sessionCookie!.value, {
    createdAtDeltaS: ABS_TTL_S + 2,
    // lastActivityDeltaS intentionally NOT set → remains recent
  });

  // BFF detects absolute expiry → clear cookie + redirect
  await page.goto('/settings');
  await page.waitForURL(/\/login/, { timeout: 10_000 });
  expect(page.url()).toMatch(/\/login/);

  const after = await page.context().cookies();
  expect(after.find((c) => c.name === SESSION_COOKIE)).toBeUndefined();
});
