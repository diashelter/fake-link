import { test, expect } from '@playwright/test';
import { clearMailbox } from './helpers/mailpit';
import { flushEphemeralRedis } from './helpers/redis';

/**
 * Harness connectivity smoke.
 * Proves the E2E infrastructure is reachable before any business specs run.
 */
test('harness connectivity', async ({ page, request }) => {
  // 1. Frontend health via app.localhost (proves --host-resolver-rules)
  const healthRes = await page.goto('/health');
  expect(healthRes?.status()).toBe(200);

  // 2. Backend reachable via app.localhost/api/v1 (proves Compose alias + nginx routing)
  const backendRes = await request.get('/api/v1/health', {
    headers: { Accept: 'application/json' },
    failOnStatusCode: false,
  });
  // Accept 200 (healthy) or 404 (endpoint not defined) — both prove network reach
  expect([200, 404]).toContain(backendRes.status());

  // 3. Mailpit reachable from helper (proves service alias)
  await expect(clearMailbox()).resolves.toBeUndefined();

  // 4. Redis ephemeral reachable from helper (proves service alias)
  await expect(flushEphemeralRedis()).resolves.toBeUndefined();
});
