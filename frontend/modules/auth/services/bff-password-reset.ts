import 'server-only';

import { NextResponse } from 'next/server';

import { PASSWORD_RESET_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { assertMutationGuard } from '../bff/mutation-guard';
import { jsonWithPrivateCache } from '../bff/private-response';
import {
  clearSessionCookie,
  destroySession,
  getSession,
  type BffSessionDependencies,
} from './bff-session';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';
const RESET_SUCCESS_MESSAGE = 'Senha redefinida. Faça login para continuar.';
const RESET_SUCCESS_REDIRECT = '/login' as const;

type ResetPasswordRequestBody = {
  email: string;
  token: string;
  password: string;
  password_confirmation: string;
};

export type BffPasswordResetSuccess = {
  redirectTo: typeof RESET_SUCCESS_REDIRECT;
  message: string;
};

export type BffPasswordResetResult =
  | { ok: true; response: NextResponse; success: BffPasswordResetSuccess }
  | { ok: false; response: NextResponse };

export type BffPasswordResetDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
}

function parseResetBody(text: string): ResetPasswordRequestBody | null {
  try {
    const json: unknown = JSON.parse(text);
    if (typeof json !== 'object' || json === null) {
      return null;
    }
    const record = json as Record<string, unknown>;
    if (
      typeof record.email !== 'string' ||
      typeof record.token !== 'string' ||
      typeof record.password !== 'string' ||
      typeof record.password_confirmation !== 'string'
    ) {
      return null;
    }
    return {
      email: record.email,
      token: record.token,
      password: record.password,
      password_confirmation: record.password_confirmation,
    };
  } catch {
    return null;
  }
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

export async function performBffPasswordReset(
  request: Request,
  deps: BffPasswordResetDependencies = {},
): Promise<BffPasswordResetResult> {
  const guard = await assertMutationGuard(request, PASSWORD_RESET_ALLOWLIST_ENTRY);

  if (!guard.ok) {
    return { ok: false, response: guard.response };
  }

  if (!hasJsonContentType(request)) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const parsed = parseResetBody(await request.text());
  if (!parsed) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(PASSWORD_RESET_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email: parsed.email,
        token: parsed.token,
        password: parsed.password,
        password_confirmation: parsed.password_confirmation,
      }),
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GATEWAY_ERROR_MESSAGE }, { status: 504 }),
    };
  }

  if (upstream.status !== 204) {
    const upstreamBodyText = await upstream.text();

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

  const session = await getSession(request.headers.get('cookie'), deps);
  if (session.context) {
    try {
      await destroySession(session.context.sessionId, deps);
    } catch {
      // best-effort destroy after upstream 204
    }
  }

  const response = jsonWithPrivateCache({
    data: {
      redirect_to: RESET_SUCCESS_REDIRECT,
      message: RESET_SUCCESS_MESSAGE,
    },
  });
  clearSessionCookie(response, deps);

  return {
    ok: true,
    response,
    success: {
      redirectTo: RESET_SUCCESS_REDIRECT,
      message: RESET_SUCCESS_MESSAGE,
    },
  };
}
