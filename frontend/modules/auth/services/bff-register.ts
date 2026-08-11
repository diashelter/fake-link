import 'server-only';

import { NextResponse } from 'next/server';

import { buildUpstreamUrl, type AllowlistEntry } from '../bff/allowlist';
import { issueCsrfForSession } from '../bff/csrf';
import { assertMutationGuard } from '../bff/mutation-guard';
import { jsonWithPrivateCache } from '../bff/private-response';
import {
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
const VERIFY_EMAIL_REDIRECT = '/verify-email' as const;

/** Local allowlist shape for register orchestration; AUTH_BFF_ALLOWLIST registers in T6. */
const REGISTER_ALLOWLIST_ENTRY: AllowlistEntry = {
  method: 'POST',
  bffPath: '/api/bff/auth/register',
  upstreamMethod: 'POST',
  upstreamPath: '/auth/register',
  requireSession: false,
  requireCsrf: true,
};

type RegisterUpstreamBody = {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
  accept_terms: true;
};

export type BffRegisterSuccess = {
  user: BffPublicUser;
  redirectTo: typeof VERIFY_EMAIL_REDIRECT;
  sessionId: string;
};

export type BffRegisterResult =
  | { ok: true; response: NextResponse; success: BffRegisterSuccess }
  | { ok: false; response: NextResponse };

export type BffRegisterDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function parseRegisterBody(text: string): RegisterUpstreamBody | null {
  try {
    const json: unknown = JSON.parse(text);
    if (typeof json !== 'object' || json === null) {
      return null;
    }
    const record = json as Record<string, unknown>;
    if (
      typeof record.name !== 'string' ||
      typeof record.email !== 'string' ||
      typeof record.password !== 'string' ||
      typeof record.password_confirmation !== 'string' ||
      record.accept_terms !== true
    ) {
      return null;
    }
    return {
      name: record.name,
      email: record.email,
      password: record.password,
      password_confirmation: record.password_confirmation,
      accept_terms: true,
    };
  } catch {
    return null;
  }
}

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
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

export async function performBffRegister(
  request: Request,
  deps: BffRegisterDependencies = {},
): Promise<BffRegisterResult> {
  const guard = await assertMutationGuard(request, REGISTER_ALLOWLIST_ENTRY, {
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
  const credentials = parseRegisterBody(bodyText);
  if (!credentials) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(REGISTER_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        name: credentials.name,
        email: credentials.email,
        password: credentials.password,
        password_confirmation: credentials.password_confirmation,
        accept_terms: true,
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

  if (upstream.status !== 201) {
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

  if (authData.token_kind !== 'verification') {
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
        kind: 'verification',
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

  const user = toPublicUser(authData.user);
  const response = jsonWithPrivateCache(
    {
      data: {
        user,
        redirect_to: VERIFY_EMAIL_REDIRECT,
      },
    },
    { status: 201 },
  );

  applySessionCookie(response, created.sessionId, undefined, deps);
  issueCsrfForSession(created.sessionId, response);

  return {
    ok: true,
    response,
    success: {
      user,
      redirectTo: VERIFY_EMAIL_REDIRECT,
      sessionId: created.sessionId,
    },
  };
}
