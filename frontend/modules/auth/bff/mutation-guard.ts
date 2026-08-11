import { readPreAuthCsrfSid, validateCsrfDoubleSubmit } from './csrf';
import { validateMutationOrigin } from './origin';
import { forbiddenResponse } from './private-response';
import type { AllowlistEntry, BffSessionRecord, GuardResult, SessionLoader } from './types';

type MutationGuardDeps = {
  loadSession?: SessionLoader;
};

export async function assertMutationGuard(
  request: Request,
  entry: AllowlistEntry,
  deps: MutationGuardDeps = {},
): Promise<GuardResult> {
  const originResult = validateMutationOrigin(request);
  if (!originResult.ok) {
    return { ok: false, response: forbiddenResponse() };
  }

  let session: BffSessionRecord | null = null;

  if (entry.requireSession) {
    if (!deps.loadSession) {
      return { ok: false, response: forbiddenResponse() };
    }

    session = await deps.loadSession(request);

    if (!session) {
      return { ok: false, response: forbiddenResponse() };
    }
  }

  if (entry.requireCsrf) {
    const csrfContext =
      entry.requireSession && session
        ? { mode: 'session' as const, sessionId: session.sessionId }
        : { mode: 'pre-auth' as const, csrfSid: readPreAuthCsrfSid(request) ?? '' };

    if (csrfContext.mode === 'pre-auth' && !csrfContext.csrfSid) {
      return { ok: false, response: forbiddenResponse() };
    }

    const csrfResult = validateCsrfDoubleSubmit(request, csrfContext);
    if (!csrfResult.ok) {
      return { ok: false, response: forbiddenResponse() };
    }
  }

  return { ok: true, session };
}
