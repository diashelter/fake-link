import { NextResponse } from 'next/server';

const PRIVATE_CACHE_CONTROL = 'private, no-store';

export function applyPrivateCacheHeaders(response: NextResponse): NextResponse {
  response.headers.set('Cache-Control', PRIVATE_CACHE_CONTROL);
  return response;
}

export function jsonWithPrivateCache(body: unknown, init?: ResponseInit): NextResponse {
  const response = NextResponse.json(body, init);
  response.headers.set('Cache-Control', PRIVATE_CACHE_CONTROL);
  return response;
}

export function forbiddenResponse(): NextResponse {
  return jsonWithPrivateCache({ message: 'Forbidden.' }, { status: 403 });
}
