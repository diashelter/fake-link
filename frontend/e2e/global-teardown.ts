/**
 * T17 — global teardown.
 *
 * Closes any persistent Redis / HTTP clients that helpers may have left open.
 * Currently all helpers (mailpit, redis) connect and disconnect per-call, so
 * there are no lingering connections. This hook is present to satisfy the
 * teardown contract and to serve as the extension point if persistent clients
 * are added in the future.
 */
async function globalTeardown(): Promise<void> {
  // All Redis connections in frontend/e2e/helpers/redis.ts create, use, and
  // disconnect a client within each exported function — nothing to clean up.
  //
  // Mailpit helpers use the built-in fetch API (ephemeral).
  //
  // If persistent connections are added later, close them here.
}

export default globalTeardown;
