import { NextResponse } from 'next/server';
import { describe, expect, it } from 'vitest';

import {
  applyPrivateCacheHeaders,
  forbiddenResponse,
  jsonWithPrivateCache,
} from './private-response';

describe('private response helpers', () => {
  it('applies private no-store cache headers', () => {
    const response = applyPrivateCacheHeaders(NextResponse.json({ ok: true }));

    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
  });

  it('emits JSON with private no-store cache headers', () => {
    const response = jsonWithPrivateCache({ data: 'x' });

    expect(response.headers.get('Content-Type')).toContain('application/json');
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
  });

  it('returns forbidden response with private no-store cache headers', async () => {
    const response = forbiddenResponse();
    const body = await response.json();

    expect(response.status).toBe(403);
    expect(body).toEqual({ message: 'Forbidden.' });
    expect(response.headers.get('Cache-Control')).toBe('private, no-store');
  });
});
