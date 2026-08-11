const MIN_CSRF_KEY_BYTES = 32;

function readEnv(name: string): string | undefined {
  const value = process.env[name];
  return value === '' ? undefined : value;
}

export function getBffAppOrigin(): string {
  const origin = readEnv('BFF_APP_ORIGIN') ?? readEnv('NEXT_PUBLIC_APP_URL');

  if (!origin) {
    throw new Error('BFF_APP_ORIGIN or NEXT_PUBLIC_APP_URL must be set');
  }

  return origin;
}

function decodeCsrfKey(raw: string): Buffer {
  const trimmed = raw.trim();

  if (/^[0-9a-fA-F]+$/.test(trimmed) && trimmed.length % 2 === 0) {
    return Buffer.from(trimmed, 'hex');
  }

  return Buffer.from(trimmed, 'base64');
}

export function getCsrfHmacKey(): Buffer {
  const raw = readEnv('BFF_CSRF_HMAC_KEY');

  if (!raw) {
    throw new Error('BFF_CSRF_HMAC_KEY must be set');
  }

  const key = decodeCsrfKey(raw);

  if (key.length < MIN_CSRF_KEY_BYTES) {
    throw new Error(`BFF_CSRF_HMAC_KEY must be at least ${MIN_CSRF_KEY_BYTES} bytes`);
  }

  return key;
}

export function getLaravelInternalUrl(): string {
  const url = readEnv('LARAVEL_INTERNAL_URL');

  if (!url) {
    throw new Error('LARAVEL_INTERNAL_URL must be set');
  }

  return url.replace(/\/+$/, '');
}
