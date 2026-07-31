import { afterAll, afterEach, beforeAll, describe, expect, it } from 'vitest';
import { server } from './server';

describe('MSW smoke wiring', () => {
  beforeAll(() => {
    server.listen({ onUnhandledRequest: 'error' });
  });

  afterEach(() => {
    server.resetHandlers();
  });

  afterAll(() => {
    server.close();
  });

  it('intercepts requests without hitting the network', async () => {
    const response = await fetch('https://example.test/api/health');
    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({ status: 'ok' });
  });
});
