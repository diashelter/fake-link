import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('server-only', () => ({}));

import type { BffSessionConfig } from '@/modules/auth/lib/session/config';
import { FakeSessionStore } from '@/modules/auth/lib/session/test/fake-session-store';

import { GET, POST, resetProbeSessionDeps, setProbeSessionDeps } from './route';

const TEST_BEARER = 'probe-bearer-token-PLAINTEXT-secret-abc';
const TEST_USER_ID = '019082da-0000-7000-8000-000000000099';
const COOKIE_NAME = '__Host-fl_session';

function testConfig(): BffSessionConfig {
  return {
    aesKey: Buffer.alloc(32, 7),
    hmacKey: Buffer.alloc(32, 9),
    aesKeyId: '1',
    cookieName: COOKIE_NAME,
    redisUrl: 'redis://redis-ephemeral:6379',
    probeEnabled: true,
  };
}

function enableProbe(): void {
  vi.stubEnv('NODE_ENV', 'test');
  vi.stubEnv('BFF_SESSION_PROBE_ENABLED', 'true');
}

function disableProbeProduction(): void {
  vi.stubEnv('NODE_ENV', 'production');
  vi.stubEnv('BFF_SESSION_PROBE_ENABLED', 'false');
}

function collectHeaderBlob(response: Response): string {
  const parts: string[] = [];
  response.headers.forEach((value, key) => {
    parts.push(`${key}: ${value}`);
  });
  return parts.join('\n');
}

describe('GET/POST /api/_test/session (SC-16, SC-17)', () => {
  let store: FakeSessionStore;

  beforeEach(() => {
    store = new FakeSessionStore();
    setProbeSessionDeps({ config: testConfig(), store });
  });

  afterEach(() => {
    resetProbeSessionDeps();
    vi.unstubAllEnvs();
  });

  it('returns 404 when NODE_ENV=production and probe is disabled', async () => {
    disableProbeProduction();

    const getResponse = await GET(new Request('http://localhost/api/_test/session'));
    const postResponse = await POST(
      new Request('http://localhost/api/_test/session', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          bearer: TEST_BEARER,
          kind: 'session',
          userId: TEST_USER_ID,
        }),
      }),
    );

    expect(getResponse.status).toBe(404);
    expect(postResponse.status).toBe(404);
  });

  it('POST creates session and GET with cookie returns authenticated kind without secrets', async () => {
    enableProbe();

    const postResponse = await POST(
      new Request('http://localhost/api/_test/session', {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify({
          bearer: TEST_BEARER,
          kind: 'session',
          userId: TEST_USER_ID,
        }),
      }),
    );

    expect(postResponse.status).toBe(204);
    const setCookie = postResponse.headers.get('set-cookie') ?? '';
    expect(setCookie).toContain(`${COOKIE_NAME}=`);
    expect(setCookie.toLowerCase()).toContain('httponly');
    expect(setCookie.toLowerCase()).toContain('secure');

    const cookieMatch = setCookie.match(new RegExp(`${COOKIE_NAME}=([^;]+)`));
    expect(cookieMatch).not.toBeNull();
    const sessionCookieValue = cookieMatch![1];

    const getResponse = await GET(
      new Request('http://localhost/api/_test/session', {
        headers: { cookie: `${COOKIE_NAME}=${sessionCookieValue}` },
      }),
    );
    const body = (await getResponse.json()) as Record<string, unknown>;

    expect(getResponse.status).toBe(200);
    expect(body).toEqual({ authenticated: true, kind: 'session' });
    expect(body).not.toHaveProperty('bearer');
    expect(body).not.toHaveProperty('sessionId');
    expect(body).not.toHaveProperty('ciphertext');

    const bodyText = JSON.stringify(body);
    expect(bodyText).not.toContain(TEST_BEARER);
    expect(bodyText).not.toContain(sessionCookieValue);

    const getHeaders = collectHeaderBlob(getResponse);
    expect(getHeaders).not.toContain(TEST_BEARER);
    expect(getHeaders.toLowerCase()).not.toMatch(/authorization:\s*bearer/i);

    const postHeaders = collectHeaderBlob(postResponse);
    expect(postHeaders).not.toContain(TEST_BEARER);
    expect(postHeaders.toLowerCase()).not.toMatch(/authorization:\s*bearer/i);
  });

  it('GET without cookie returns authenticated false when probe enabled', async () => {
    enableProbe();

    const response = await GET(new Request('http://localhost/api/_test/session'));
    const body = await response.json();

    expect(response.status).toBe(200);
    expect(body).toEqual({ authenticated: false });
  });
});
