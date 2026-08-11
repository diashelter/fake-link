import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { callAllowlistedUpstream } from './upstream';
import type { AllowlistEntry, BffSessionRecord } from './types';

const ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/_probe/mutate',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/login',
  requireSession: false,
  requireCsrf: true,
};

afterEach(() => {
  vi.unstubAllEnvs();
  vi.unstubAllGlobals();
});

beforeEach(() => {
  vi.stubEnv('LARAVEL_INTERNAL_URL', 'http://nginx/api/v1');
});

describe('callAllowlistedUpstream', () => {
  it('calls fetch with fixed allowlisted URL only', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json({ ok: true }, { status: 200, headers: { 'Content-Type': 'application/json' } }),
    );
    vi.stubGlobal('fetch', fetchMock);

    await callAllowlistedUpstream(ENTRY, { body: JSON.stringify({ email: 'a@b.com' }) });

    expect(fetchMock).toHaveBeenCalledWith(
      'http://nginx/api/v1/auth/login',
      expect.objectContaining({ method: 'POST' }),
    );
  });

  it('sends Authorization Bearer when session is present', async () => {
    let authorization: string | null = null;

    const fetchMock = vi.fn(async (_url: string, init?: RequestInit) => {
      authorization = new Headers(init?.headers).get('Authorization');
      return Response.json(
        { ok: true },
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      );
    });
    vi.stubGlobal('fetch', fetchMock);

    const session: BffSessionRecord = {
      sessionId: 'session-1',
      bearerPlaintext: 'plain-bearer-token',
    };

    await callAllowlistedUpstream(ENTRY, { session });

    expect(authorization).toBe('Bearer plain-bearer-token');
  });

  it('does not expose Bearer token in browser-facing response body', async () => {
    const fetchMock = vi.fn(async () =>
      Response.json(
        { message: 'Invalid credentials.' },
        {
          status: 401,
          headers: { 'Content-Type': 'application/json' },
        },
      ),
    );
    vi.stubGlobal('fetch', fetchMock);

    const session: BffSessionRecord = {
      sessionId: 'session-1',
      bearerPlaintext: 'plain-bearer-token',
    };

    const result = await callAllowlistedUpstream(ENTRY, { session });
    const body = await result.response.text();

    expect(result.status).toBe(401);
    expect(body).not.toContain('plain-bearer-token');
    expect(body).toContain('Invalid credentials.');
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
  });

  it('returns generic 504 with private cache headers on timeout', async () => {
    const fetchMock = vi.fn(async () => {
      throw new DOMException('Timeout', 'TimeoutError');
    });
    vi.stubGlobal('fetch', fetchMock);

    const result = await callAllowlistedUpstream(ENTRY);
    const body = await result.response.json();

    expect(result.status).toBe(504);
    expect(body).toEqual({ message: 'Bad gateway.' });
    expect(result.response.headers.get('Cache-Control')).toBe('private, no-store');
  });
});
