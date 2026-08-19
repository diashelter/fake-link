import 'server-only';

import { NextResponse } from 'next/server';

import {
  ME_GET_ALLOWLIST_ENTRY,
  ME_PATCH_ALLOWLIST_ENTRY,
  buildUpstreamUrl,
} from '../bff/allowlist';
import { forbiddenResponse, jsonWithPrivateCache } from '../bff/private-response';
import { updateProfileSchema } from '../schemas/update-profile-schema';
import { getSession, type BffSessionDependencies } from './bff-session';
import { loadSessionMutationContext } from './bff-password-shared';

const UPSTREAM_TIMEOUT_MS = 10_000;
const BAD_REQUEST_MESSAGE = 'Requisição inválida.';
const GENERIC_ERROR_MESSAGE = 'Algo deu errado. Tente novamente.';
const GATEWAY_ERROR_MESSAGE = 'Não foi possível conectar ao serviço. Tente novamente.';

export type BffMeResult =
  { ok: true; response: NextResponse } | { ok: false; response: NextResponse };

export type BffMeDependencies = BffSessionDependencies & {
  fetchImpl?: typeof fetch;
};

function hasJsonContentType(request: Request): boolean {
  const contentType = request.headers.get('content-type') ?? '';
  return contentType.includes('application/json');
}

function parsePatchBody(text: string): { name: string } | null {
  try {
    const json: unknown = JSON.parse(text);
    const parsed = updateProfileSchema.strict().safeParse(json);
    if (!parsed.success) {
      return null;
    }
    return parsed.data;
  } catch {
    return null;
  }
}

async function passThroughUpstream(upstream: Response): Promise<NextResponse> {
  if (upstream.status === 500 || upstream.status === 503) {
    return jsonWithPrivateCache({ message: GENERIC_ERROR_MESSAGE }, { status: upstream.status });
  }

  const bodyText = await upstream.text();
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

export async function performBffMeGet(
  request: Request,
  deps: BffMeDependencies = {},
): Promise<BffMeResult> {
  const session = await getSession(request.headers.get('cookie'), deps);
  if (!session.context) {
    return { ok: false, response: forbiddenResponse() };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(ME_GET_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'GET',
      headers: {
        Authorization: `Bearer ${session.context.bearer}`,
      },
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GATEWAY_ERROR_MESSAGE }, { status: 504 }),
    };
  }

  const response = await passThroughUpstream(upstream);
  return { ok: upstream.status === 200, response };
}

export async function performBffMePatch(
  request: Request,
  deps: BffMeDependencies = {},
): Promise<BffMeResult> {
  const loaded = await loadSessionMutationContext(request, ME_PATCH_ALLOWLIST_ENTRY, deps);
  if (!loaded.ok) {
    return { ok: false, response: loaded.response };
  }

  if (!hasJsonContentType(request)) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const parsed = parsePatchBody(await request.text());
  if (!parsed) {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: BAD_REQUEST_MESSAGE }, { status: 400 }),
    };
  }

  const fetchImpl = deps.fetchImpl ?? fetch;
  const upstreamUrl = buildUpstreamUrl(ME_PATCH_ALLOWLIST_ENTRY);

  let upstream: Response;
  try {
    upstream = await fetchImpl(upstreamUrl, {
      method: 'PATCH',
      headers: {
        Authorization: `Bearer ${loaded.ctx.bearerPlaintext}`,
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({ name: parsed.name }),
      signal: AbortSignal.timeout(UPSTREAM_TIMEOUT_MS),
    });
  } catch {
    return {
      ok: false,
      response: jsonWithPrivateCache({ message: GATEWAY_ERROR_MESSAGE }, { status: 504 }),
    };
  }

  const response = await passThroughUpstream(upstream);
  return { ok: upstream.status === 200, response };
}
