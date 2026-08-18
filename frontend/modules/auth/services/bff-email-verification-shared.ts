import 'server-only';

import type { NextResponse } from 'next/server';

import { assertMutationGuard } from '../bff/mutation-guard';
import { forbiddenResponse } from '../bff/private-response';
import type { AllowlistEntry } from '../bff/types';
import { getSession, type BffSessionDependencies } from './bff-session';

type AuthenticatedMutationContext = {
  sessionId: string;
  bearerPlaintext: string;
  kind: 'verification';
};

export async function loadVerificationMutationContext(
  request: Request,
  entry: AllowlistEntry,
  deps?: BffSessionDependencies,
): Promise<
  { ok: true; ctx: AuthenticatedMutationContext } | { ok: false; response: NextResponse }
> {
  const guard = await assertMutationGuard(request, entry, {
    loadSession: async (req) => {
      const result = await getSession(req.headers.get('cookie'), deps);
      if (!result.context) {
        return null;
      }

      return {
        sessionId: result.context.sessionId,
        bearerPlaintext: result.context.bearer,
      };
    },
  });

  if (!guard.ok) {
    return { ok: false, response: guard.response };
  }

  const session = await getSession(request.headers.get('cookie'), deps);
  if (!session.context || session.context.kind !== 'verification') {
    return { ok: false, response: forbiddenResponse() };
  }

  return {
    ok: true,
    ctx: {
      sessionId: session.context.sessionId,
      bearerPlaintext: session.context.bearer,
      kind: 'verification',
    },
  };
}
