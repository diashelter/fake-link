import { headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { ChangePasswordForm } from '@/modules/auth/components/change-password-form';
import { getSessionFromRequest } from '@/modules/auth/services/bff-session';

export default async function SettingsPasswordPage() {
  const hdrs = await headers();
  const request = new Request('https://app.localhost/settings/password', {
    headers: { cookie: hdrs.get('cookie') ?? '' },
  });

  const session = await getSessionFromRequest(request);

  if (!session) {
    redirect('/login');
  }

  if (session.kind === 'verification') {
    redirect('/verify-email');
  }

  return (
    <main className="mx-auto w-full max-w-md">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Alterar senha</h1>
      <p className="mt-2 text-sm text-muted">Confirme sua senha atual e defina uma nova.</p>
      <div className="mt-8">
        <ChangePasswordForm />
      </div>
    </main>
  );
}
