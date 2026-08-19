'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import { applyServerFieldErrors } from '@/modules/auth/lib/validation-errors';
import {
  logoutAllSchema,
  type LogoutAllFormValues,
} from '@/modules/auth/schemas/logout-all-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';
const CURRENT_PASSWORD_INVALID_MESSAGE = 'Senha atual incorreta.';
const FORBIDDEN_MESSAGE = 'Você não tem permissão para concluir esta ação.';
const LOGOUT_ALL_FIELD_KEYS = ['current_password'] as const satisfies ReadonlyArray<
  keyof LogoutAllFormValues
>;

export function LogoutAllForm() {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors, isSubmitting },
  } = useForm<LogoutAllFormValues>({
    resolver: zodResolver(logoutAllSchema),
    defaultValues: {
      current_password: '',
    },
  });

  async function onValidSubmit(values: LogoutAllFormValues) {
    setFormError(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/logout-all', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({
        current_password: values.current_password,
      }),
    });

    reset({ current_password: '' });

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
      router.push('/login');
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
      const applied = applyServerFieldErrors(payload.errors, LOGOUT_ALL_FIELD_KEYS, setError);
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

    if (response.status === 403) {
      setFormError(FORBIDDEN_MESSAGE);
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
      <FormField
        name="current_password"
        label="Senha atual"
        error={errors.current_password?.message}
      >
        <Input
          id="current_password"
          type="password"
          autoComplete="current-password"
          invalid={Boolean(errors.current_password)}
          aria-describedby={errors.current_password ? 'current_password-error' : undefined}
          {...register('current_password')}
        />
      </FormField>

      {formError ? (
        <p role="alert" className="text-sm text-red-700">
          {formError}
        </p>
      ) : null}

      <Button type="submit" variant="destructive" disabled={shouldBlockSubmit(isSubmitting)}>
        {isSubmitting ? 'Encerrando…' : 'Encerrar todas as sessões'}
      </Button>
    </form>
  );
}
