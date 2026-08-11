const DEFAULT_FALLBACK = '/';
const MAX_RETURN_URL_LENGTH = 2048;

function isSafePath(path: string): boolean {
  if (!path.startsWith('/')) {
    return false;
  }

  if (path.startsWith('//')) {
    return false;
  }

  if (path.includes('://') || path.includes('\\') || path.includes('@') || path.includes('\0')) {
    return false;
  }

  return true;
}

function validateAfterDecode(path: string): boolean {
  let current = path;

  for (let pass = 0; pass < 2; pass += 1) {
    if (!isSafePath(current)) {
      return false;
    }

    try {
      const decoded = decodeURIComponent(current);
      if (decoded === current) {
        return isSafePath(decoded);
      }
      current = decoded;
    } catch {
      return false;
    }
  }

  return isSafePath(current);
}

export function sanitizeReturnUrl(
  input: string | null | undefined,
  fallback: string = DEFAULT_FALLBACK,
): string {
  if (input === null || input === undefined) {
    return fallback;
  }

  const trimmed = input.trim();

  if (trimmed.length === 0 || trimmed.length > MAX_RETURN_URL_LENGTH) {
    return fallback;
  }

  if (!validateAfterDecode(trimmed)) {
    return fallback;
  }

  return trimmed;
}
