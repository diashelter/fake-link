import 'server-only';

import { NextResponse } from 'next/server';

import { PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { assertMutationGuard } from '../bff/mutation-guard';
import { jsonWithPrivateCache } from '../bff/private-response';
import type { BffSessionDependencies } from './bff-session';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';

export type BffPasswordResetRequestResult =
  { ok: true; response: NextResponse } | { ok: false; response: NextResponse };

export type BffPasswordResetRequestDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
}

function parseResetRequestBody(text: string): { email: string } | null {
  try {
    const json: unknown = JSON.parse(text);
    if (typeof json !== 'object' || json === null) {
      return null;
    }
    const record = json as Record<string, unknown>;
    if (typeof record.email !== 'string') {
      return null;
    }
    return { email: record.email };
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

export async function performBffPasswordResetRequest(
  request: Request,
  deps: BffPasswordResetRequestDependencies = {},
): Promise<BffPasswordResetRequestResult> {
  const guard = await assertMutationGuard(request, PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY);

  if (!guard.ok) {
    return { ok: false, response: guard.response };
  }

  if (!hasJsonContentType(request)) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const credentials = parseResetRequestBody(await request.text());
  if (!credentials) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(PASSWORD_RESET_REQUEST_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: credentials.email }),
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GATEWAY_ERROR_MESSAGE }, { status: 504 }),
    };
  }

  const upstreamBodyText = await upstream.text();

  if (upstream.status !== 202) {
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

  let parsedBody: unknown = {};
  try {
    parsedBody = upstreamBodyText ? JSON.parse(upstreamBodyText) : {};
  } catch {
    parsedBody = {};
  }

  return {
    ok: true,
    response: jsonWithPrivateCache(parsedBody, { status: 202 }),
  };
}
