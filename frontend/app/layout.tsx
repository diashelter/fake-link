import type { Metadata } from 'next';
import type { ReactNode } from 'react';
import { AppProviders } from '@/modules/shared/components/app-providers';
import './globals.css';

export const metadata: Metadata = {
  title: 'Fake Link',
  description: 'Encurtador de URLs',
};

export default function RootLayout({
  children,
}: Readonly<{
  children: ReactNode;
}>) {
  return (
    <html lang="pt-BR">
      <body>
        <AppProviders>{children}</AppProviders>
      </body>
    </html>
  );
}
