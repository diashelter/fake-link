'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import { applyServerFieldErrors } from '@/modules/auth/lib/validation-errors';
import {
  resetPasswordSchema,
  type ResetPasswordFormValues,
} from '@/modules/auth/schemas/reset-password-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';
const RESET_SUCCESS_MESSAGE = 'Senha redefinida. Faça login para continuar.';

const RESET_FIELD_KEYS = [
  'email',
  'token',
  'password',
  'password_confirmation',
] as const satisfies ReadonlyArray<keyof ResetPasswordFormValues>;

export type ResetPasswordFormProps = {
  initialToken?: string;
};

export function ResetPasswordForm({ initialToken }: ResetPasswordFormProps) {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<ResetPasswordFormValues>({
    resolver: zodResolver(resetPasswordSchema),
    defaultValues: {
      email: '',
      token: initialToken ?? '',
      password: '',
      password_confirmation: '',
    },
  });

  useEffect(() => {
    if (!initialToken) return;
    const url = new URL(window.location.href);
    if (!url.searchParams.has('token')) return;
    url.searchParams.delete('token');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }, [initialToken]);

  async function onValidSubmit(values: ResetPasswordFormValues) {
    setFormError(null);
    setStatusMessage(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/password/reset', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({
        email: values.email,
        token: values.token,
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
      setStatusMessage(payload.data?.message ?? RESET_SUCCESS_MESSAGE);
      router.push(payload.data?.redirect_to ?? '/login');
      return;
    }

    if (response.status === 422) {
      const applied = applyServerFieldErrors(payload.errors, RESET_FIELD_KEYS, setError);
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
      <FormField name="email" label="E-mail" error={errors.email?.message}>
        <Input
          id="email"
          type="email"
          autoComplete="email"
          invalid={Boolean(errors.email)}
          aria-describedby={errors.email ? 'email-error' : undefined}
          {...register('email')}
        />
      </FormField>

      <FormField name="token" label="Código de recuperação" error={errors.token?.message}>
        <Input
          id="token"
          type="text"
          autoComplete="one-time-code"
          invalid={Boolean(errors.token)}
          aria-describedby={errors.token ? 'token-error' : undefined}
          {...register('token')}
        />
      </FormField>

      <FormField name="password" label="Senha" error={errors.password?.message}>
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
        label="Confirmar senha"
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
        {isSubmitting ? 'Redefinindo…' : 'Redefinir senha'}
      </Button>

      <div className="flex flex-col gap-2 text-sm">
        <Link href="/login" className="text-accent hover:underline">
          Voltar ao login
        </Link>
      </div>
    </form>
  );
}
