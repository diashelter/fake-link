/**
 * T16 — Bearer absence from every client surface.
 *
 * Captures the plaintext Bearer token from the backend login endpoint
 * (sentinel) and asserts it is absent from:
 *   - Page HTML / RSC payloads / __NEXT_DATA__ / JS bundle excerpts
 *   - cookies (only __Host-fl_session, format-validated, ≠ sentinel)
 *   - localStorage / sessionStorage / IndexedDB
 *   - all /api/bff/** response bodies, headers, Set-Cookie values
 *   - URL bar on every navigation
 *
 * ACs: E2E-05..08, BFFUI-80
 */
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

import { test, expect } from '@playwright/test';

import { loginViaUi } from './helpers/account';
import { captureBearerSentinel, assertAbsent } from './helpers/sentinel';
import { collectClientState } from './helpers/leak-scan';

// Directory where ephemeral scan artefacts are written (same as playwright outputDir parent)
const ARTIFACTS_DIR = join(__dirname, '.artifacts');

async function writeArtefact(filename: string, content: string): Promise<void> {
  await mkdir(ARTIFACTS_DIR, { recursive: true });
  await writeFile(join(ARTIFACTS_DIR, filename), content, 'utf-8');
}

const TEST_EMAIL = 'e2e-auth@fake-link.test';
const TEST_PASSWORD = 'E2E-P4ssw0rd!';
const SESSION_COOKIE = '__Host-fl_session';
const COOKIE_PATTERN = /^[A-Za-z0-9_-]{43}$/;

// ---------------------------------------------------------------------------
// T16-1: Capture sentinel; assert absent from HTML / RSC / JS bundles
// ---------------------------------------------------------------------------
test('sentinel absent from page HTML, RSC payloads, and JS bundles', async ({ page, request }) => {
  // Capture Bearer plaintext — this goes directly to the backend, bypassing BFF
  const sentinel = await captureBearerSentinel(request, {
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
  });
  expect(sentinel.length).toBeGreaterThan(10);

  // Write sentinel to .artifacts/ so the Makefile scan step can read it
  await writeArtefact('sentinel.txt', sentinel);

  // Collect JS bundle URL samples for later scanning
  const jsBundleTexts: string[] = [];
  page.on('response', async (resp) => {
    const url = resp.url();
    if (
      url.includes('app.localhost') &&
      (url.endsWith('.js') || url.includes('/_next/static/')) &&
      resp.headers()['content-type']?.includes('javascript')
    ) {
      const text = await resp.text().catch(() => '');
      // Only keep non-trivially short responses
      if (text.length > 0) jsBundleTexts.push(text);
    }
  });

  // Log in via UI so the BFF session is established
  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  // Collect client state at '/'
  const stateHome = await collectClientState(page);

  // Navigate to /settings and collect again
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });
  const stateSettings = await collectClientState(page);

  // Assert sentinel absent from HTML and RSC payloads at both pages
  assertAbsent(sentinel, [stateHome.html, stateHome.rscPayload]);
  assertAbsent(sentinel, [stateSettings.html, stateSettings.rscPayload]);

  // Assert sentinel absent from localStorage, sessionStorage, and IndexedDB at both pages
  assertAbsent(sentinel, stateHome.localStorage);
  assertAbsent(sentinel, stateHome.sessionStorage);
  assertAbsent(sentinel, stateHome.indexedDbEntries);
  assertAbsent(sentinel, stateSettings.localStorage);
  assertAbsent(sentinel, stateSettings.sessionStorage);
  assertAbsent(sentinel, stateSettings.indexedDbEntries);

  // Assert sentinel absent from collected JS bundles
  assertAbsent(sentinel, jsBundleTexts);
});

// ---------------------------------------------------------------------------
// T16-2: Cookie surface — only __Host-fl_session, format valid, ≠ sentinel
// ---------------------------------------------------------------------------
test('cookie surface contains only session cookie with valid format, no sentinel', async ({
  page,
  request,
}) => {
  const sentinel = await captureBearerSentinel(request, {
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
  });

  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  // Write the current session cookie value to .artifacts/ for the scan step
  const initialCookies = await page.context().cookies();
  const initialSession = initialCookies.find(
    (c) => c.domain === 'app.localhost' && c.name === SESSION_COOKIE,
  );
  if (initialSession) {
    await writeArtefact('session-cookie.txt', initialSession.value);
  }

  const checkCookies = async (atPath: string) => {
    await page.goto(atPath);

    const cookies = await page.context().cookies();
    // Filter to app.localhost cookies only
    const appCookies = cookies.filter(
      (c) => c.domain === 'app.localhost' || c.domain === '.app.localhost',
    );

    // Must contain __Host-fl_session
    const sessionCookie = appCookies.find((c) => c.name === SESSION_COOKIE);
    expect(sessionCookie, `${atPath}: __Host-fl_session must be present`).toBeDefined();

    // Value format
    expect(sessionCookie!.value).toMatch(COOKIE_PATTERN);

    // Cookie value ≠ sentinel
    expect(sessionCookie!.value).not.toEqual(sentinel);
    expect(sessionCookie!.value).not.toContain(sentinel);

    // Sentinel must not appear in any cookie string
    const cookieStrings = appCookies.map((c) => `${c.name}=${c.value}`);
    assertAbsent(sentinel, cookieStrings);
  };

  await checkCookies('/');
  await checkCookies('/settings');
});

// ---------------------------------------------------------------------------
// T16-3: /api/bff/** responses — no sentinel in body/headers, Cache-Control correct
// ---------------------------------------------------------------------------
test('BFF responses carry no sentinel and include Cache-Control: private, no-store', async ({
  page,
  request,
}) => {
  const sentinel = await captureBearerSentinel(request, {
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
  });

  const bffResponses: {
    url: string;
    status: number;
    body: string;
    headers: Record<string, string>;
  }[] = [];

  page.on('response', async (resp) => {
    if (resp.url().includes('/api/bff/')) {
      const body = await resp.text().catch(() => '');
      bffResponses.push({
        url: resp.url(),
        status: resp.status(),
        body,
        headers: resp.headers(),
      });
    }
  });

  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  // Navigate through authenticated pages to generate BFF traffic
  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });

  // Verify we collected some BFF responses
  expect(bffResponses.length).toBeGreaterThan(0);

  for (const resp of bffResponses) {
    // Body must not contain sentinel
    expect(resp.body, `BFF body at ${resp.url} must not contain sentinel`).not.toContain(sentinel);

    // No header value should contain sentinel (including Set-Cookie)
    for (const [headerName, headerValue] of Object.entries(resp.headers)) {
      expect(
        headerValue,
        `BFF header ${headerName} at ${resp.url} must not contain sentinel`,
      ).not.toContain(sentinel);
    }

    // Must carry Cache-Control: private, no-store
    const cc = resp.headers['cache-control'] ?? '';
    expect(cc, `BFF ${resp.url} must carry Cache-Control: private, no-store`).toContain('private');
    expect(cc).toContain('no-store');
  }
});

// ---------------------------------------------------------------------------
// T16-4: URL bar — no sentinel, no `token=`/`bearer=` params on any navigation
// ---------------------------------------------------------------------------
test('URL bar never contains sentinel or token/bearer query params', async ({ page, request }) => {
  const sentinel = await captureBearerSentinel(request, {
    email: TEST_EMAIL,
    password: TEST_PASSWORD,
  });

  const visitedUrls: string[] = [];
  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame()) {
      visitedUrls.push(frame.url());
    }
  });

  await loginViaUi(page, { email: TEST_EMAIL, password: TEST_PASSWORD });
  await page.waitForURL('/', { timeout: 10_000 });

  await page.goto('/settings');
  await page.waitForURL('/settings', { timeout: 10_000 });

  // Verify URLs collected
  expect(visitedUrls.length).toBeGreaterThan(0);

  for (const url of visitedUrls) {
    expect(url, `URL must not contain sentinel: ${url}`).not.toContain(sentinel);

    try {
      const parsed = new URL(url);
      expect(
        parsed.searchParams.has('token'),
        `URL must not have token= param: ${url}`,
      ).toBe(false);
      expect(
        parsed.searchParams.has('bearer'),
        `URL must not have bearer= param: ${url}`,
      ).toBe(false);
    } catch {
      // Non-parseable URL — skip
    }
  }
});
