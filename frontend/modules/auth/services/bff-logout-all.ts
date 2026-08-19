import 'server-only';

import { NextResponse } from 'next/server';

import { LOGOUT_ALL_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { clearCsrfCookies } from '../bff/csrf';
import { jsonWithPrivateCache } from '../bff/private-response';
import { logoutAllSchema } from '../schemas/logout-all-schema';
import { clearSessionCookie, destroySession, type BffSessionDependencies } from './bff-session';
import { loadSessionMutationContext } from './bff-password-shared';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';
const LOGOUT_ALL_SUCCESS_MESSAGE = 'Todas as sessões foram encerradas. Faça login para continuar.';
const LOGOUT_ALL_SUCCESS_REDIRECT = '/login' as const;

export type BffLogoutAllSuccess = {
  redirectTo: typeof LOGOUT_ALL_SUCCESS_REDIRECT;
  message: string;
};

export type BffLogoutAllResult =
  | { ok: true; response: NextResponse; success: BffLogoutAllSuccess }
  | { ok: false; response: NextResponse };

export type BffLogoutAllDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
}

function parseLogoutAllBody(text: string): { current_password: string } | null {
  try {
    const json: unknown = JSON.parse(text);
    const parsed = logoutAllSchema.strict().safeParse(json);
    if (!parsed.success) {
      return null;
    }
    return parsed.data;
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

export async function performBffLogoutAll(
  request: Request,
  deps: BffLogoutAllDependencies = {},
): Promise<BffLogoutAllResult> {
  const loaded = await loadSessionMutationContext(request, LOGOUT_ALL_ALLOWLIST_ENTRY, deps);
  if (!loaded.ok) {
    return { ok: false, response: loaded.response };
  }

  if (!hasJsonContentType(request)) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const parsed = parseLogoutAllBody(await request.text());
  if (!parsed) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(LOGOUT_ALL_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${loaded.ctx.bearerPlaintext}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        current_password: parsed.current_password,
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

  try {
    await destroySession(loaded.ctx.sessionId, deps);
  } catch {
    // best-effort destroy after upstream 204
  }

  const response = jsonWithPrivateCache({
    data: {
      redirect_to: LOGOUT_ALL_SUCCESS_REDIRECT,
      message: LOGOUT_ALL_SUCCESS_MESSAGE,
    },
  });
  clearSessionCookie(response, deps);
  clearCsrfCookies(response);

  return {
    ok: true,
    response,
    success: {
      redirectTo: LOGOUT_ALL_SUCCESS_REDIRECT,
      message: LOGOUT_ALL_SUCCESS_MESSAGE,
    },
  };
}
