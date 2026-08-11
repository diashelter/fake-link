'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import { registerSchema, type RegisterFormValues } from '@/modules/auth/schemas/register-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { Label } from '@/modules/shared/components/ui/label';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';

const REGISTER_FIELD_KEYS = [
  'name',
  'email',
  'password',
  'password_confirmation',
  'accept_terms',
] as const satisfies ReadonlyArray<keyof RegisterFormValues>;

type RegisterFieldKey = (typeof REGISTER_FIELD_KEYS)[number];

function isRegisterFieldKey(key: string): key is RegisterFieldKey {
  return (REGISTER_FIELD_KEYS as ReadonlyArray<string>).includes(key);
}

export type RegisterFormProps = {
  termsVersion: string;
};

export function RegisterForm({ termsVersion }: RegisterFormProps) {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useForm<RegisterFormValues>({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: '',
      email: '',
      password: '',
      password_confirmation: '',
      accept_terms: false as unknown as true,
    },
  });

  async function onValidSubmit(values: RegisterFormValues) {
    setFormError(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/register', {
      method: 'POST',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({
        name: values.name,
        email: values.email,
        password: values.password,
        password_confirmation: values.password_confirmation,
        accept_terms: true,
      }),
    });

    let payload: {
      code?: string;
      message?: string;
      errors?: Record<string, string[] | string>;
      data?: { redirect_to?: string };
    } = {};
    try {
      payload = (await response.json()) as typeof payload;
    } catch {
      payload = {};
    }

    if (response.status === 201 && payload.data?.redirect_to) {
      router.push(payload.data.redirect_to);
      return;
    }

    if (response.status === 422 && payload.errors) {
      for (const [field, messages] of Object.entries(payload.errors)) {
        if (!isRegisterFieldKey(field)) {
          continue;
        }
        const message = Array.isArray(messages) ? messages[0] : messages;
        if (typeof message === 'string' && message.length > 0) {
          setError(field, { type: 'server', message });
        }
      }
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
      <FormField name="name" label="Nome" error={errors.name?.message}>
        <Input
          id="name"
          type="text"
          autoComplete="name"
          invalid={Boolean(errors.name)}
          aria-describedby={errors.name ? 'name-error' : undefined}
          {...register('name')}
        />
      </FormField>

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

      <div className="flex flex-col gap-1.5">
        <div className="flex items-start gap-2">
          <input
            id="accept_terms"
            type="checkbox"
            className="mt-1 size-4 shrink-0 accent-accent"
            aria-invalid={Boolean(errors.accept_terms) || undefined}
            aria-describedby={errors.accept_terms ? 'accept_terms-error' : undefined}
            {...register('accept_terms')}
          />
          <Label htmlFor="accept_terms" className="font-normal leading-snug">
            Li e aceito os{' '}
            <Link
              href="/terms"
              target="_blank"
              rel="noopener noreferrer"
              className="text-accent hover:underline"
            >
              Termos de uso
            </Link>{' '}
            (versão {termsVersion})
          </Label>
        </div>
        {errors.accept_terms?.message ? (
          <p id="accept_terms-error" role="alert" className="text-sm text-red-700">
            {errors.accept_terms.message}
          </p>
        ) : null}
      </div>

      {formError ? (
        <p role="alert" className="text-sm text-red-700">
          {formError}
        </p>
      ) : null}

      <Button type="submit" disabled={shouldBlockSubmit(isSubmitting)}>
        {isSubmitting ? 'Criando conta…' : 'Criar conta'}
      </Button>

      <div className="flex flex-col gap-2 text-sm">
        <Link href="/login" className="text-accent hover:underline">
          Já tenho conta
        </Link>
      </div>
    </form>
  );
}
