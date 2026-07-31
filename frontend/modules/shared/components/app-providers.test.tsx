import { QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { AppProviders } from './app-providers';

afterEach(() => {
  cleanup();
});

describe('AppProviders', () => {
  it('renders children inside the query provider tree', () => {
    render(
      <AppProviders>
        <span>conteúdo</span>
      </AppProviders>,
    );

    expect(screen.getByText('conteúdo')).toBeTruthy();
    expect(QueryClientProvider).toBeTypeOf('function');
  });
});
