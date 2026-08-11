import { NextResponse } from 'next/server';

import {
  assertMutationGuard,
  callAllowlistedUpstream,
  jsonWithPrivateCache,
  type AllowlistEntry,
} from '@/modules/auth/bff';

const PROBE_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/_probe/mutate',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/login',
  requireSession: false,
  requireCsrf: true,
};

export async function POST(request: Request): Promise<NextResponse> {
  if (process.env.NODE_ENV === 'production') {
    return new NextResponse(null, { status: 404 });
  }

  const guard = await assertMutationGuard(request, PROBE_ENTRY);

  if (!guard.ok) {
    return guard.response;
  }

  const upstream = await callAllowlistedUpstream(PROBE_ENTRY, {
    body: await request.text(),
  });

  return upstream.response;
}

export async function GET(): Promise<NextResponse> {
  if (process.env.NODE_ENV === 'production') {
    return new NextResponse(null, { status: 404 });
  }

  return jsonWithPrivateCache({ probe: 'ok' });
}
