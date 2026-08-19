import 'server-only';

import { NextResponse } from 'next/server';

import { LOGOUT_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { clearCsrfCookies, validateCsrfDoubleSubmit } from '../bff/csrf';
import { validateMutationOrigin } from '../bff/origin';
import { forbiddenResponse, jsonWithPrivateCache } from '../bff/private-response';
import { incrementLogoutRedisFail, incrementLogoutUpstreamFail } from '../lib/session/metrics';
import {
  clearSessionCookie,
  destroySession,
  getSession,
  type BffSessionDependencies,
} from './bff-session';

const UPSTREAM_TIMEOUT_MS = 10_000;
const LOGOUT_SUCCESS_MESSAGE = 'Você saiu da conta.';
const LOGOUT_SUCCESS_REDIRECT = '/login' as const;

export type BffLogoutSuccess = {
  redirectTo: typeof LOGOUT_SUCCESS_REDIRECT;
  message: string;
};

export type BffLogoutResult =
  | { ok: true; response: NextResponse; success: BffLogoutSuccess }
  | { ok: false; response: NextResponse };

export type BffLogoutDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function localLogoutSuccess(deps: BffLogoutDependencies): BffLogoutResult {
  const response = jsonWithPrivateCache({
    data: {
      redirect_to: LOGOUT_SUCCESS_REDIRECT,
      message: LOGOUT_SUCCESS_MESSAGE,
    },
  });
  clearSessionCookie(response, deps);
  clearCsrfCookies(response);

  return {
    ok: true,
    response,
    success: {
      redirectTo: LOGOUT_SUCCESS_REDIRECT,
      message: LOGOUT_SUCCESS_MESSAGE,
    },
  };
}

export async function performBffLogout(
  request: Request,
  deps: BffLogoutDependencies = {},
): Promise<BffLogoutResult> {
  if (!validateMutationOrigin(request).ok) {
    return { ok: false, response: forbiddenResponse() };
  }

  const session = await getSession(request.headers.get('cookie'), deps);

  if (session.context) {
    const csrf = validateCsrfDoubleSubmit(request, {
      mode: 'session',
      sessionId: session.context.sessionId,
    });
    if (!csrf.ok) {
      return { ok: false, response: forbiddenResponse() };
    }

    const fetchImpl = deps.fetchImpl ?? fetch;
    const upstreamUrl = buildUpstreamUrl(LOGOUT_ALLOWLIST_ENTRY);

    try {
      const upstream = await fetchImpl(upstreamUrl, {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${session.context.bearer}`,
        },
        signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
      });
      if (upstream.status >= 500) {
        incrementLogoutUpstreamFail();
      }
    } catch {
      incrementLogoutUpstreamFail();
    }

    try {
      await destroySession(session.context.sessionId, deps);
    } catch {
      incrementLogoutRedisFail();
    }
  }

  return localLogoutSuccess(deps);
}
