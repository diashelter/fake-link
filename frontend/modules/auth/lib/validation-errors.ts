export type FieldErrorItem = { code?: string; message?: string } | string;

export function messageForFieldError(code: string | undefined): string | null {
  switch (code) {
    case 'PASSWORD_REUSED':
      return 'A nova senha deve ser diferente da senha atual.';
    default:
      return null;
  }
}

/** Token field: uniform message for any server token validation failure. */
export function messageForTokenFieldError(field: 'token'): string {
  return field === 'token'
    ? 'Link de redefinição inválido ou expirado.'
    : 'Verifique o campo informado.';
}

export function applyServerFieldErrors<TField extends string>(
  errors:
    | Record<string, FieldErrorItem[] | FieldErrorItem | string[] | string>
    | undefined,
  allowedFields: readonly TField[],
  setError: (field: TField, error: { type: string; message: string }) => void,
): boolean {
  if (!errors) {
    return false;
  }

  const allowed = new Set<string>(allowedFields);
  let applied = false;

  for (const [field, raw] of Object.entries(errors)) {
    if (!allowed.has(field)) {
      continue;
    }

    const items = Array.isArray(raw) ? raw : [raw];
    const first = items[0];
    if (first === undefined) {
      continue;
    }

    const message = resolveFieldMessage(field, first);
    if (message) {
      setError(field as TField, { type: 'server', message });
      applied = true;
    }
  }

  return applied;
}

function resolveFieldMessage(field: string, item: FieldErrorItem): string | undefined {
  if (field === 'token') {
    return messageForTokenFieldError('token');
  }

  if (typeof item === 'string') {
    return item.length > 0 ? item : undefined;
  }

  return messageForFieldError(item.code) ?? (item.message?.length ? item.message : undefined);
}
