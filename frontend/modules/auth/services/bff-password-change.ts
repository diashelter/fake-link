import 'server-only';

import { NextResponse } from 'next/server';

import { PASSWORD_CHANGE_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { jsonWithPrivateCache } from '../bff/private-response';
import { clearSessionCookie, destroySession, type BffSessionDependencies } from './bff-session';
import { loadSessionMutationContext } from './bff-password-shared';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';
const CHANGE_SUCCESS_MESSAGE = 'Senha alterada. Faça login para continuar.';
const CHANGE_SUCCESS_REDIRECT = '/login' as const;

type ChangePasswordRequestBody = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export type BffPasswordChangeSuccess = {
  redirectTo: typeof CHANGE_SUCCESS_REDIRECT;
  message: string;
};

export type BffPasswordChangeResult =
  | { ok: true; response: NextResponse; success: BffPasswordChangeSuccess }
  | { ok: false; response: NextResponse };

export type BffPasswordChangeDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
}

function parseChangeBody(text: string): ChangePasswordRequestBody | null {
  try {
    const json: unknown = JSON.parse(text);
    if (typeof json !== 'object' || json === null) {
      return null;
    }
    const record = json as Record<string, unknown>;
    if (
      typeof record.current_password !== 'string' ||
      typeof record.password !== 'string' ||
      typeof record.password_confirmation !== 'string'
    ) {
      return null;
    }
    return {
      current_password: record.current_password,
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

export async function performBffPasswordChange(
  request: Request,
  deps: BffPasswordChangeDependencies = {},
): Promise<BffPasswordChangeResult> {
  const loaded = await loadSessionMutationContext(request, PASSWORD_CHANGE_ALLOWLIST_ENTRY, deps);
  if (!loaded.ok) {
    return { ok: false, response: loaded.response };
  }

  if (!hasJsonContentType(request)) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const parsed = parseChangeBody(await request.text());
  if (!parsed) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(PASSWORD_CHANGE_ALLOWLIST_ENTRY);

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

  try {
    await destroySession(loaded.ctx.sessionId, deps);
  } catch {
    // best-effort destroy after upstream 204
  }

  const response = jsonWithPrivateCache({
    data: {
      redirect_to: CHANGE_SUCCESS_REDIRECT,
      message: CHANGE_SUCCESS_MESSAGE,
    },
  });
  clearSessionCookie(response, deps);

  return {
    ok: true,
    response,
    success: {
      redirectTo: CHANGE_SUCCESS_REDIRECT,
      message: CHANGE_SUCCESS_MESSAGE,
    },
  };
}
