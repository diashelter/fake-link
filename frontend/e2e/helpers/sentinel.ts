import { expect, type APIRequestContext } from '@playwright/test';

/**
 * POST directly to the backend /api/v1/auth/login and return the Bearer plaintext token.
 * Throws if the response body does not contain a token.
 */
export async function captureBearerSentinel(
  request: APIRequestContext,
  creds: { email: string; password: string },
): Promise<string> {
  const res = await request.post('/api/v1/auth/login', {
    data: { email: creds.email, password: creds.password },
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
  });

  const body = await res.json();
  if (!body || typeof body.token !== 'string' || body.token === '') {
    throw new Error(
      `captureBearerSentinel: no token in response body (status ${res.status()})`,
    );
  }
  return body.token as string;
}

/**
 * Assert that `sentinel` does not appear in any of the provided haystacks.
 * Uses plain string containment — no regex.
 */
export function assertAbsent(sentinel: string, haystacks: string[]): void {
  for (const h of haystacks) {
    expect(h).not.toContain(sentinel);
  }
}
