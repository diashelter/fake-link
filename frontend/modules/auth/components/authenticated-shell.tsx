'use client';

import Link from 'next/link';
import type { ReactNode } from 'react';

import { LogoutButton } from '@/modules/auth/components/logout-button';

export type AuthenticatedShellProps = {
  children: ReactNode;
};

export function AuthenticatedShell({ children }: AuthenticatedShellProps) {
  return (
    <div className="flex min-h-full flex-col">
      <nav className="flex items-center gap-4 border-b border-foreground/10 px-4 py-3">
        <Link href="/" className="text-sm font-medium text-accent hover:underline">
          Início
        </Link>
        <Link href="/settings" className="text-sm font-medium text-accent hover:underline">
          Conta
        </Link>
        <div className="ml-auto">
          <LogoutButton />
        </div>
      </nav>
      <div className="flex-1 px-4 py-6">{children}</div>
    </div>
  );
}
