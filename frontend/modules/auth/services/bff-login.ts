import 'server-only';

import { NextResponse } from 'next/server';

import { LOGIN_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { issueCsrfForSession } from '../bff/csrf';
import { assertMutationGuard } from '../bff/mutation-guard';
import { jsonWithPrivateCache } from '../bff/private-response';
import { sanitizeReturnUrl } from '../bff/return-url';
import {
  mapTokenKindToSessionKind,
  parseUpstreamAuthResponse,
  toPublicUser,
  type BffPublicUser,
} from '../lib/auth-api-types';
import {
  applySessionCookie,
  createSession,
  destroySession,
  getSession,
  type BffSessionDependencies,
} from './bff-session';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';

export type BffLoginSuccess = {
  user: BffPublicUser;
  redirectTo: string;
  sessionId: string;
};

export type BffLoginResult =
  | { ok: true; response: NextResponse; success: BffLoginSuccess }
  | { ok: false; response: NextResponse };

export type BffLoginDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function parseLoginBody(text: string): { email: string; password: string } | null {
  try {
    const json: unknown = JSON.parse(text);
    if (typeof json !== 'object' || json === null) {
      return null;
    }
    const record = json as Record<string, unknown>;
    if (typeof record.email !== 'string' || typeof record.password !== 'string') {
      return null;
    }
    return { email: record.email, password: record.password };
  } catch {
    return null;
  }
}

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
}

function readReturnUrl(request: Request): string {
  const url = new URL(request.url);
  return sanitizeReturnUrl(url.searchParams.get('returnUrl'), '/');
}

async function forwardUpstreamError(upstream: Response, bodyText: string): Promise<NextResponse> {
  if (upstream.status === 500 || upstream.status === 503) {
    return jsonWithPrivateCache({ message: GENERIC_ERROR_MESSAGE }, { status: upstream.status });
  }

  const headers: Record<string, string> = {
    'Content-Type': upstream.headers.get('Content-Type') ?? 'application/json',
  };
  const retryAfter = upstream.headers.get('Retry-After');
  if (retryAfter) {
    headers['Retry-After'] = retryAfter;
  }

  return jsonWithPrivateCache(bodyText ? JSON.parse(bodyText) : {}, {
    status: upstream.status,
    headers,
  });
}

function buildSuccessRedirect(tokenKind: 'session' | 'verification', returnUrl: string): string {
  return tokenKind === 'verification' ? '/verify-email' : returnUrl;
}

export async function performBffLogin(
  request: Request,
  deps: BffLoginDependencies = {},
): Promise<BffLoginResult> {
  const guard = await assertMutationGuard(request, LOGIN_ALLOWLIST_ENTRY, {
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

  if (!hasJsonContentType(request)) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const bodyText = await request.text();
  const credentials = parseLoginBody(bodyText);
  if (!credentials) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const returnUrl = readReturnUrl(request);
  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(LOGIN_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email: credentials.email,
        password: credentials.password,
      }),
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GATEWAY_ERROR_MESSAGE }, { status: 504 }),
    };
  }

  const upstreamBodyText = await upstream.text();

  if (upstream.status !== 200) {
    if (upstream.status >= 400 && upstream.status < 500) {
      let parsedBody: unknown = {};
      try {
        parsedBody = upstreamBodyText ? JSON.parse(upstreamBodyText) : {};
      } catch {
        parsedBody = { message: BAD_REQUEST_MESSAGE };
      }
      const headers: Record<string, string> = {
        'Content-Type': upstream.headers.get('Content-Type') ?? 'application/json',
      };
      const retryAfter = upstream.headers.get('Retry-After');
      if (retryAfter) {
        headers['Retry-After'] = retryAfter;
      }
      return {
        ok: false,
        response: jsonWithPrivateCache(parsedBody, { status: upstream.status, headers }),
      };
    }

    return {
      ok: false,
      response: await forwardUpstreamError(upstream, upstreamBodyText),
    };
  }

  let parsedJson: unknown;
  try {
    parsedJson = upstreamBodyText ? JSON.parse(upstreamBodyText) : null;
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GENERIC_ERROR_MESSAGE }, { status: 500 }),
    };
  }

  const authData = parseUpstreamAuthResponse(parsedJson);
  if (!authData) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GENERIC_ERROR_MESSAGE }, { status: 500 }),
    };
  }

  const priorSession = await getSession(request.headers.get('cookie'), deps);
  if (priorSession.context) {
    try {
      await destroySession(priorSession.context.sessionId, deps);
    } catch {
      // best-effort destroy before create
    }
  }

  let created;
  try {
    created = await createSession(
      {
        bearer: authData.token,
        kind: mapTokenKindToSessionKind(authData.token_kind),
        userId: authData.user.id,
      },
      deps,
    );
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GENERIC_ERROR_MESSAGE }, { status: 500 }),
    };
  }

  const redirectTo = buildSuccessRedirect(authData.token_kind, returnUrl);
  const user = toPublicUser(authData.user);
  const response = jsonWithPrivateCache({
    data: {
      user,
      redirect_to: redirectTo,
    },
  });

  applySessionCookie(response, created.sessionId, undefined, deps);
  issueCsrfForSession(created.sessionId, response);

  return {
    ok: true,
    response,
    success: {
      user,
      redirectTo,
      sessionId: created.sessionId,
    },
  };
}
