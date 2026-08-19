'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import {
  forgotPasswordSchema,
  type ForgotPasswordFormValues,
} from '@/modules/auth/schemas/forgot-password-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';

export const FORGOT_PASSWORD_SUCCESS_MESSAGE =
  'Se o e-mail estiver cadastrado, você receberá instruções para redefinir sua senha.';

export function ForgotPasswordForm() {
  const [formError, setFormError] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<ForgotPasswordFormValues>({
    resolver: zodResolver(forgotPasswordSchema),
    defaultValues: { email: '' },
  });

  async function onValidSubmit(values: ForgotPasswordFormValues) {
    setFormError(null);
    setStatusMessage(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/password/reset-request', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({ email: values.email }),
    });

    let payload: { code?: string; message?: string } = {};
    try {
      payload = (await response.json()) as typeof payload;
    } catch {
      payload = {};
    }

    if (response.status === 202) {
      setStatusMessage(FORGOT_PASSWORD_SUCCESS_MESSAGE);
      return;
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
        {isSubmitting ? 'Enviando…' : 'Enviar instruções'}
      </Button>

      <div className="flex flex-col gap-2 text-sm">
        <Link href="/login" className="text-accent hover:underline">
          Voltar ao login
        </Link>
      </div>
    </form>
  );
}
