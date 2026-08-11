import { getBffAppOrigin } from './env';

export function isMutationMethod(method: string): boolean {
  return method === 'POST' || method === 'PATCH' || method === 'DELETE';
}

export function validateMutationOrigin(request: Request): { ok: true } | { ok: false } {
  const method = request.method.toUpperCase();

  if (!isMutationMethod(method)) {
    return { ok: true };
  }

  const origin = request.headers.get('Origin');

  if (origin === null || origin === 'null') {
    return { ok: false };
  }

  if (!origin) {
    return { ok: false };
  }

  if (origin !== getBffAppOrigin()) {
    return { ok: false };
  }

  return { ok: true };
}
