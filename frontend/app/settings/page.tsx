import { cookies, headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { buildSessionCookieOptions } from '@/lib/session-cookie';
import { CSRF_SID_COOKIE, CSRF_TOKEN_COOKIE } from '@/modules/auth/bff/csrf';
import { LogoutAllForm } from '@/modules/auth/components/logout-all-form';
import { ProfileForm } from '@/modules/auth/components/profile-form';
import { resolveAccountPageGuard } from '@/modules/auth/lib/account-guard';
import { performBffMeGet } from '@/modules/auth/services/bff-me';
import { destroySession, getSessionFromRequest } from '@/modules/auth/services/bff-session';

const SESSION_COOKIE_NAME = '__Host-fl_session';
const ACCOUNT_BLOCKED_CODES = new Set(['ACCOUNT_SUSPENDED', 'ACCOUNT_PENDING_DELETION']);

type MeBody = {
  code?: string;
  data?: { name?: string; email?: string };
};

async function expireAccountCookies(): Promise<void> {
  const store = await cookies();
  store.set(SESSION_COOKIE_NAME, '', buildSessionCookieOptions({ maxAge: 0 }));
  store.set(CSRF_TOKEN_COOKIE, '', {
    httpOnly: false,
    secure: true,
    sameSite: 'lax',
    path: '/',
    maxAge: 0,
  });
  store.set(CSRF_SID_COOKIE, '', buildSessionCookieOptions({ maxAge: 0 }));
}

async function readMeBody(response: { json: () => Promise<unknown> }): Promise<MeBody> {
  try {
    return (await response.json()) as MeBody;
  } catch {
    return {};
  }
}

export default async function SettingsPage() {
  const hdrs = await headers();
  const request = new Request('https://app.localhost/settings', {
    headers: { cookie: hdrs.get('cookie') ?? '' },
  });

  const session = await getSessionFromRequest(request);
  const decision = resolveAccountPageGuard({
    pathname: '/settings',
    sessionKind: session?.kind ?? null,
  });

  if (decision.action === 'redirect') {
    redirect(decision.to);
  }

  const me = await performBffMeGet(request);
  const body = await readMeBody(me.response);

  if (me.response.status === 403 && body.code && ACCOUNT_BLOCKED_CODES.has(body.code)) {
    if (session) {
      try {
        await destroySession(session.sessionId);
      } catch {
        // Redis destroy is best-effort; cookies must still expire.
      }
    }
    await expireAccountCookies();
    redirect('/login');
  }

  const name = typeof body.data?.name === 'string' ? body.data.name : '';
  const email = typeof body.data?.email === 'string' ? body.data.email : '';

  return (
    <main className="mx-auto w-full max-w-md">
      <h1 className="font-display text-3xl tracking-tight text-foreground">Conta</h1>
      <p className="mt-2 text-sm text-muted">Atualize seu nome. O e-mail não pode ser alterado.</p>
      <div className="mt-8">
        <ProfileForm name={name} email={email} />
      </div>
      <p className="mt-6">
        <a href="/settings/password" className="text-sm font-medium text-accent hover:underline">
          Alterar senha
        </a>
      </p>
      <section className="mt-10">
        <h2 className="text-lg font-medium text-foreground">Encerrar todas as sessões</h2>
        <p className="mt-1 text-sm text-muted">
          Confirme sua senha atual para sair de todos os dispositivos.
        </p>
        <div className="mt-4">
          <LogoutAllForm />
        </div>
      </section>
    </main>
  );
}
