'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import { applyServerFieldErrors } from '@/modules/auth/lib/validation-errors';
import {
  changePasswordSchema,
  type ChangePasswordFormValues,
} from '@/modules/auth/schemas/change-password-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';
const CHANGE_SUCCESS_MESSAGE = 'Senha alterada. Faça login para continuar.';
const CURRENT_PASSWORD_INVALID_MESSAGE = 'Senha atual incorreta.';

const CHANGE_FIELD_KEYS = [
  'current_password',
  'password',
  'password_confirmation',
] as const satisfies ReadonlyArray<keyof ChangePasswordFormValues>;

export function ChangePasswordForm() {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ChangePasswordFormValues>({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: {
      current_password: '',
      password: '',
      password_confirmation: '',
    },
  });

  async function onValidSubmit(values: ChangePasswordFormValues) {
    setFormError(null);
    setStatusMessage(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/password/change', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({
        current_password: values.current_password,
        password: values.password,
        password_confirmation: values.password_confirmation,
      }),
    });

    let payload: {
      code?: string;
      message?: string;
      errors?: Parameters<typeof applyServerFieldErrors>[0];
      data?: { redirect_to?: string; message?: string };
    } = {};
    try {
      payload = (await response.json()) as typeof payload;
    } catch {
      payload = {};
    }

    if (response.ok) {
      setStatusMessage(payload.data?.message ?? CHANGE_SUCCESS_MESSAGE);
      router.push(payload.data?.redirect_to ?? '/login');
      return;
    }

    if (response.status === 401 && payload.code === 'INVALID_CREDENTIALS') {
      setError('current_password', {
        type: 'server',
        message: CURRENT_PASSWORD_INVALID_MESSAGE,
      });
      return;
    }

    if (response.status === 422) {
      const applied = applyServerFieldErrors(payload.errors, CHANGE_FIELD_KEYS, setError);
      if (applied) {
        return;
      }
    }

    const retryAfterHeader = response.headers.get('Retry-After');
    const retryAfterSeconds = retryAfterHeader ? Number(retryAfterHeader) : null;
    const retryMessage = formatRetryAfter(
      retryAfterSeconds !== null && Number.isFinite(retryAfterSeconds) ? retryAfterSeconds : null,
    );
    if (retryMessage) {
      setFormError(retryMessage);
      return;
    }

    if (payload.code) {
      setFormError(messageForAuthError(payload.code, response.status));
      return;
    }

    setFormError(payload.message ?? messageForAuthError(undefined, response.status));
  }

  return (
    <form
      className="flex w-full flex-col gap-4"
      onSubmit={handleSubmit(onValidSubmit, focusFirstError)}
      noValidate
    >
      <FormField name="current_password" label="Senha atual" error={errors.current_password?.message}>
        <Input
          id="current_password"
          type="password"
          autoComplete="current-password"
          invalid={Boolean(errors.current_password)}
          aria-describedby={errors.current_password ? 'current_password-error' : undefined}
          {...register('current_password')}
        />
      </FormField>

      <FormField name="password" label="Nova senha" error={errors.password?.message}>
        <Input
          id="password"
          type="password"
          autoComplete="new-password"
          invalid={Boolean(errors.password)}
          aria-describedby={errors.password ? 'password-error' : undefined}
          {...register('password')}
        />
      </FormField>

      <FormField
        name="password_confirmation"
        label="Confirmar nova senha"
        error={errors.password_confirmation?.message}
      >
        <Input
          id="password_confirmation"
          type="password"
          autoComplete="new-password"
          invalid={Boolean(errors.password_confirmation)}
          aria-describedby={
            errors.password_confirmation ? 'password_confirmation-error' : undefined
          }
          {...register('password_confirmation')}
        />
      </FormField>

      {formError ? (
        <p role="alert" className="text-sm text-red-700">
          {formError}
        </p>
      ) : null}

      {statusMessage ? (
        <p role="status" className="text-sm text-foreground">
          {statusMessage}
        </p>
      ) : null}

      <Button type="submit" disabled={shouldBlockSubmit(isSubmitting)}>
        {isSubmitting ? 'Alterando…' : 'Alterar senha'}
      </Button>
    </form>
  );
}
