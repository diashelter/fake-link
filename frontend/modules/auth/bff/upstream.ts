import { NextResponse } from 'next/server';

import { buildUpstreamUrl } from './allowlist';
import { applyPrivateCacheHeaders } from './private-response';
import type { AllowlistEntry, BffSessionRecord, UpstreamResult } from './types';

const UPSTREAM_TIMEOUT_MS = 10_000;

type UpstreamContext = {
  session?: BffSessionRecord | null;
  body?: BodyInit | null;
};

export async function callAllowlistedUpstream(
  entry: AllowlistEntry,
  ctx: UpstreamContext = {},
  init: RequestInit = {},
): Promise<UpstreamResult> {
  const url = buildUpstreamUrl(entry);
  const headers = new Headers(init.headers);

  if (ctx.session?.bearerPlaintext) {
    headers.set('Authorization', `Bearer ${ctx.session.bearerPlaintext}`);
  }

  let response: Response;

  try {
    response = await fetch(url, {
      ...init,
      method: entry.upstreamMethod,
      headers,
      body: ctx.body ?? init.body ?? null,
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      response: applyPrivateCacheHeaders(
        NextResponse.json({ message: 'Bad gateway.' }, { status: 504 }),
      ),
      status: 504,
    };
  }

  const bodyText = await response.text();
  const nextResponse = applyPrivateCacheHeaders(
    new NextResponse(bodyText, {
      status: response.status,
      headers: {
        'Content-Type': response.headers.get('Content-Type') ?? 'application/json',
      },
    }),
  );

  return {
    response: nextResponse,
    status: response.status,
  };
}
