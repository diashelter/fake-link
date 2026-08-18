import 'server-only';

import { NextResponse } from 'next/server';

import { RESEND_VERIFICATION_ALLOWLIST_ENTRY, buildUpstreamUrl } from '../bff/allowlist';
import { jsonWithPrivateCache } from '../bff/private-response';
import type { BffSessionDependencies } from './bff-session';
import { loadVerificationMutationContext } from './bff-email-verification-shared';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';

export type BffResendVerificationResult =
  | { ok: true; response: NextResponse }
  | { ok: false; response: NextResponse };

export type BffResendVerificationDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

async function forwardUpstreamResponse(upstream: Response, bodyText: string): Promise<NextResponse> {
  if (upstream.status === 500 || upstream.status === 503) {
    return jsonWithPrivateCache({ message: GENERIC_ERROR_MESSAGE }, { status: upstream.status });
  }

  let parsedBody: unknown = {};
  try {
    parsedBody = bodyText ? JSON.parse(bodyText) : {};
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

  return jsonWithPrivateCache(parsedBody, { status: upstream.status, headers });
}

export async function performBffResendVerification(
  request: Request,
  deps: BffResendVerificationDependencies = {},
): Promise<BffResendVerificationResult> {
  const loaded = await loadVerificationMutationContext(
    request,
    RESEND_VERIFICATION_ALLOWLIST_ENTRY,
    deps,
  );
  if (!loaded.ok) {
    return { ok: false, response: loaded.response };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(RESEND_VERIFICATION_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${loaded.ctx.bearerPlaintext}`,
      },
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GATEWAY_ERROR_MESSAGE }, { status: 504 }),
    };
  }

  const response = await forwardUpstreamResponse(upstream, await upstream.text());
  return { ok: upstream.status === 202, response };
}
