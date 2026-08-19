'use client';

import { useRouter } from 'next/navigation';
import { useState, type FormEvent } from 'react';

import { readClientCookie } from '@/modules/auth/lib/client-cookie';
import { Button } from '@/modules/shared/components/ui/button';
import { shouldBlockSubmit } from '@/modules/shared/lib/form-defaults';

const CSRF_COOKIE = '__Host-fl_csrf';
const FORBIDDEN_MESSAGE = 'Você não tem permissão para concluir esta ação.';

export function LogoutButton() {
  const router = useRouter();
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFormError(null);
    setIsSubmitting(true);
    const csrf = readClientCookie(CSRF_COOKIE);

    try {
      const response = await fetch('/api/bff/auth/logout', {
        method: 'POST',
        credentials: 'include',
        headers: {
          'Content-Type': 'application/json',
          ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
        },
        body: JSON.stringify({}),
      });

      if (response.ok) {
        router.push('/login');
        return;
      }

      if (response.status === 403) {
        setFormError(FORBIDDEN_MESSAGE);
        return;
      }

      setFormError('Algo deu errado. Tente novamente.');
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <form className="inline-flex flex-col gap-2" onSubmit={onSubmit} noValidate>
      {formError ? (
        <p role="alert" className="text-sm text-red-700">
          {formError}
        </p>
      ) : null}
      <Button type="submit" variant="destructive" disabled={shouldBlockSubmit(isSubmitting)}>
        Sair
      </Button>
    </form>
  );
}
