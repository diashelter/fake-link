import { QueryClient } from '@tanstack/react-query';

export const QUERY_DEFAULTS = {
  staleTime: 30_000,
  gcTime: 300_000,
  queryRetry: 1,
  mutationRetry: 0,
  visibleRefetchIntervalMs: 60_000,
} as const;

function isTransientGetFailure(failureCount: number, error: unknown): boolean {
  if (failureCount >= QUERY_DEFAULTS.queryRetry) {
    return false;
  }
  if (!(error instanceof Error)) {
    return failureCount < QUERY_DEFAULTS.queryRetry;
  }
  const status = (error as Error & { status?: number }).status;
  if (
    typeof status === 'number' &&
    status >= 400 &&
    status < 500 &&
    status !== 408 &&
    status !== 429
  ) {
    return false;
  }
  return true;
}

export function createAppQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: QUERY_DEFAULTS.staleTime,
        gcTime: QUERY_DEFAULTS.gcTime,
        retry: isTransientGetFailure,
        refetchOnWindowFocus: false,
      },
      mutations: {
        retry: QUERY_DEFAULTS.mutationRetry,
      },
    },
  });
}

export function createVisibleRefetchInterval(
  ms: number = QUERY_DEFAULTS.visibleRefetchIntervalMs,
): () => number | false {
  return () => {
    if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
      return false;
    }
    return ms;
  };
}
