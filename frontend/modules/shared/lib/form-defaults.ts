import type { FieldErrors, FieldValues } from 'react-hook-form';

export function shouldBlockSubmit(isSubmitting: boolean): boolean {
  return isSubmitting;
}

export function focusFirstError<TFieldValues extends FieldValues>(
  errors: FieldErrors<TFieldValues>,
): void {
  const firstName = Object.keys(errors)[0];
  if (!firstName || typeof document === 'undefined') {
    return;
  }

  const field = document.getElementById(firstName);
  if (field instanceof HTMLElement) {
    field.focus();
  }
}
