'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import {
  verifyEmailSchema,
  type VerifyEmailFormValues,
} from '@/modules/auth/schemas/verify-email-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';
const VERIFY_SUCCESS_MESSAGE = 'E-mail confirmado. Faça login para continuar.';
const RESEND_SUCCESS_MESSAGE =
  'Se o e-mail estiver cadastrado e pendente, você receberá um novo link.';

export type VerifyEmailFormProps = {
  initialToken?: string;
};

export function VerifyEmailForm({ initialToken }: VerifyEmailFormProps) {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [statusMessage, setStatusMessage] = useState<string | null>(null);
  const [isResending, setIsResending] = useState(false);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useForm<VerifyEmailFormValues>({
    resolver: zodResolver(verifyEmailSchema),
    defaultValues: { token: initialToken ?? '' },
  });

  useEffect(() => {
    if (!initialToken) return;
    const url = new URL(window.location.href);
    if (!url.searchParams.has('token')) return;
    url.searchParams.delete('token');
    window.history.replaceState({}, '', url.pathname + url.search + url.hash);
  }, [initialToken]);

  async function onValidSubmit(values: VerifyEmailFormValues) {
    setFormError(null);
    setStatusMessage(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/email/verify', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({ token: values.token }),
    });

    let payload: {
      code?: string;
      message?: string;
      data?: { redirect_to?: string; message?: string };
    } = {};
    try {
      payload = (await response.json()) as typeof payload;
    } catch {
      payload = {};
    }

    if (response.ok) {
      setStatusMessage(payload.data?.message ?? VERIFY_SUCCESS_MESSAGE);
      router.push(payload.data?.redirect_to ?? '/login');
      return;
    }

    if (payload.code === 'EMAIL_ALREADY_VERIFIED') {
      setFormError(messageForAuthError(payload.code, response.status));
      router.push('/login');
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

  async function onResend() {
    setFormError(null);
    setStatusMessage(null);
    setIsResending(true);
    const csrf = readClientCookie(CSRF_COOKIE);

    try {
      const response = await fetch('/api/bff/auth/email/resend', {
        method: 'POST',
        credentials: 'include',
        headers: {
          ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
        },
      });

      let payload: { code?: string; message?: string } = {};
      try {
        payload = (await response.json()) as typeof payload;
      } catch {
        payload = {};
      }

      if (response.status === 202) {
        setStatusMessage(RESEND_SUCCESS_MESSAGE);
        return;
      }

      if (payload.code === 'EMAIL_ALREADY_VERIFIED') {
        setFormError(messageForAuthError(payload.code, response.status));
        router.push('/login');
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
    } finally {
      setIsResending(false);
    }
  }

  const blocked = shouldBlockSubmit(isSubmitting || isResending);

  return (
    <form
      className="flex w-full flex-col gap-4"
      onSubmit={handleSubmit(onValidSubmit, focusFirstError)}
      noValidate
    >
      <FormField name="token" label="Código de verificação" error={errors.token?.message}>
        <Input
          id="token"
          type="text"
          autoComplete="one-time-code"
          invalid={Boolean(errors.token)}
          aria-describedby={errors.token ? 'token-error' : undefined}
          {...register('token')}
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

      <Button type="submit" disabled={blocked}>
        Confirmar e-mail
      </Button>

      <Button type="button" variant="secondary" disabled={blocked} onClick={onResend}>
        Reenviar e-mail
      </Button>

      <div className="flex flex-col gap-2 text-sm">
        <Link href="/login" className="text-accent hover:underline">
          Ir para login
        </Link>
      </div>
    </form>
  );
}
