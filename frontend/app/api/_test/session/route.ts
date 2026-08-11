import { NextResponse } from 'next/server';

import type { SessionKind } from '@/modules/auth/lib/session/types';
import {
  applySessionCookie,
  clearSessionCookie,
  createSession,
  getSession,
  SessionValidationError,
  type BffSessionDependencies,
} from '@/modules/auth/services/bff-session';

export const dynamic = 'force-dynamic';

/** Injected only in unit tests (FakeSessionStore). Production/dev use default deps. */
let probeDeps: BffSessionDependencies = {};

/** Test-only DI hook — do not call from product handlers. */
export function setProbeSessionDeps(deps: BffSessionDependencies): void {
  probeDeps = deps;
}

export function resetProbeSessionDeps(): void {
  probeDeps = {};
}

/**
 * Probe is available when NODE_ENV=test or BFF_SESSION_PROBE_ENABLED=true (SC-16).
 * Production with the flag unset/false returns 404.
 */
function isProbeEnabled(): boolean {
  return process.env.NODE_ENV === 'test' || process.env.BFF_SESSION_PROBE_ENABLED === 'true';
}

function probeDisabledResponse(): NextResponse {
  return NextResponse.json({ message: 'Not Found' }, { status: 404 });
}

export async function GET(request: Request): Promise<NextResponse> {
  if (!isProbeEnabled()) {
    return probeDisabledResponse();
  }

  const result = await getSession(request.headers.get('cookie'), probeDeps);
  if (!result.context) {
    const response = NextResponse.json({ authenticated: false });
    if (result.clearCookie) {
      clearSessionCookie(response, probeDeps);
    }
    return response;
  }

  return NextResponse.json({
    authenticated: true,
    kind: result.context.kind,
  });
}

type ProbeCreateBody = {
  bearer?: unknown;
  kind?: unknown;
  userId?: unknown;
};

function isSessionKind(value: unknown): value is SessionKind {
  return value === 'session' || value === 'verification';
}

export async function POST(request: Request): Promise<NextResponse> {
  if (!isProbeEnabled()) {
    return probeDisabledResponse();
  }

  let body: ProbeCreateBody;
  try {
    body = (await request.json()) as ProbeCreateBody;
  } catch {
    return NextResponse.json({ message: 'Invalid JSON body' }, { status: 400 });
  }

  if (
    typeof body.bearer !== 'string' ||
    !isSessionKind(body.kind) ||
    typeof body.userId !== 'string'
  ) {
    return NextResponse.json({ message: 'Invalid probe create payload' }, { status: 400 });
  }

  try {
    const created = await createSession(
      { bearer: body.bearer, kind: body.kind, userId: body.userId },
      probeDeps,
    );
    const response = new NextResponse(null, { status: 204 });
    return applySessionCookie(response, created.sessionId, undefined, probeDeps);
  } catch (error) {
    if (error instanceof SessionValidationError) {
      return NextResponse.json({ message: error.message }, { status: 400 });
    }
    throw error;
  }
}
