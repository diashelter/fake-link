'use client';

import { zodResolver } from '@hookform/resolvers/zod';
import { useState } from 'react';
import { useForm } from 'react-hook-form';

import { formatRetryAfter, messageForAuthError } from '@/modules/auth/lib/auth-messages';
import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import { applyServerFieldErrors } from '@/modules/auth/lib/validation-errors';
import {
  updateProfileSchema,
  type UpdateProfileFormValues,
} from '@/modules/auth/schemas/update-profile-schema';
import { Button } from '@/modules/shared/components/ui/button';
import { FormField } from '@/modules/shared/components/ui/form-field';
import { Input } from '@/modules/shared/components/ui/input';
import { focusFirstError, shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';
const FORBIDDEN_MESSAGE = 'Você não tem permissão para concluir esta ação.';
const PROFILE_FIELD_KEYS = ['name'] as const satisfies ReadonlyArray<keyof UpdateProfileFormValues>;

export type ProfileFormProps = {
  name: string;
  email: string;
};

export function ProfileForm({ name, email }: ProfileFormProps) {
  const [formError, setFormError] = useState<string | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    setValue,
    formState: { errors, isSubmitting },
  } = useForm<UpdateProfileFormValues>({
    resolver: zodResolver(updateProfileSchema),
    defaultValues: {
      name,
    },
  });

  async function onValidSubmit(values: UpdateProfileFormValues) {
    setFormError(null);
    const csrf = readClientCookie(CSRF_COOKIE);

    const response = await fetch('/api/bff/auth/me', {
      method: 'PATCH',
      credentials: 'include',
      headers: {
        'Content-Type': 'application/json',
        ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
      },
      body: JSON.stringify({
        name: values.name,
      }),
    });

    let payload: {
      code?: string;
      message?: string;
      errors?: Parameters<typeof applyServerFieldErrors>[0];
      data?: { name?: string; email?: string };
    } = {};
    try {
      payload = (await response.json()) as typeof payload;
    } catch {
      payload = {};
    }

    if (response.ok) {
      setValue('name', payload.data?.name ?? values.name);
      return;
    }

    if (response.status === 422) {
      const applied = applyServerFieldErrors(payload.errors, PROFILE_FIELD_KEYS, setError);
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

      <FormField name="email" label="E-mail">
        <Input id="email" type="email" defaultValue={email} readOnly autoComplete="email" />
      </FormField>

      {formError ? (
        <p role="alert" className="text-sm text-red-700">
          {formError}
        </p>
      ) : null}

      <Button type="submit" disabled={shouldBlockSubmit(isSubmitting)}>
        {isSubmitting ? 'Salvando…' : 'Salvar nome'}
      </Button>
    </form>
  );
}
