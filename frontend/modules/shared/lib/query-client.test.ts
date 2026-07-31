import { QueryClient } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { QUERY_DEFAULTS, createAppQueryClient, createVisibleRefetchInterval } from './query-client';

afterEach(() => {
  vi.unstubAllGlobals();
});

describe('createAppQueryClient', () => {
  it('applies documented query defaults', () => {
    const client = createAppQueryClient();
    const defaults = client.getDefaultOptions();

    expect(client).toBeInstanceOf(QueryClient);
    expect(defaults.queries?.staleTime).toBe(30_000);
    expect(defaults.queries?.gcTime).toBe(300_000);
    expect(defaults.mutations?.retry).toBe(0);
    expect(QUERY_DEFAULTS.staleTime).toBe(30_000);
    expect(QUERY_DEFAULTS.gcTime).toBe(300_000);
    expect(QUERY_DEFAULTS.queryRetry).toBe(1);
    expect(QUERY_DEFAULTS.mutationRetry).toBe(0);
  });

  it('retries transient GET failures once and skips 4xx', () => {
    const client = createAppQueryClient();
    const retry = client.getDefaultOptions().queries?.retry;
    expect(typeof retry).toBe('function');
    if (typeof retry !== 'function') {
      return;
    }

    expect(retry(0, new Error('network'))).toBe(true);
    expect(retry(1, new Error('network'))).toBe(false);

    const clientError = Object.assign(new Error('bad request'), { status: 400 });
    expect(retry(0, clientError)).toBe(false);
  });

  it('does not register a persister', () => {
    const client = createAppQueryClient();
    const serialized = JSON.stringify(client.getDefaultOptions());
    expect(serialized).not.toMatch(/persist/i);
    expect(
      (client as unknown as { persister?: unknown; persistOptions?: unknown }).persister,
    ).toBeUndefined();
    expect(
      (client as unknown as { persister?: unknown; persistOptions?: unknown }).persistOptions,
    ).toBeUndefined();
  });
});

describe('createVisibleRefetchInterval', () => {
  it('returns interval when document is visible', () => {
    vi.stubGlobal('document', { visibilityState: 'visible' });
    const interval = createVisibleRefetchInterval(60_000);
    expect(interval()).toBe(60_000);
  });

  it('returns false when document is hidden', () => {
    vi.stubGlobal('document', { visibilityState: 'hidden', hidden: true });
    const interval = createVisibleRefetchInterval();
    expect(interval()).toBe(false);
  });
});
