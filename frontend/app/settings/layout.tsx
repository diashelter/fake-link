import type { ReactNode } from 'react';
import { headers } from 'next/headers';
import { redirect } from 'next/navigation';

import { AuthenticatedShell } from '@/modules/auth/components/authenticated-shell';
import { resolveAccountPageGuard } from '@/modules/auth/lib/account-guard';
import { getSessionFromRequest } from '@/modules/auth/services/bff-session';

type SettingsLayoutProps = {
  children: ReactNode;
};

export default async function SettingsLayout({ children }: SettingsLayoutProps) {
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

  return <AuthenticatedShell>{children}</AuthenticatedShell>;
}
