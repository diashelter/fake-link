/** Internal counter hook for bff_session_decrypt_fail_total (SC-07 observability). */
let decryptFailCount = 0;

/** Internal counter hook for bff_logout_upstream_fail_total (SH-25 observability). */
let logoutUpstreamFailCount = 0;

/** Internal counter hook for bff_logout_redis_fail_total (SH-25 observability). */
let logoutRedisFailCount = 0;

export function incrementDecryptFail(): void {
  decryptFailCount += 1;
}

/** Test-only read access to the decrypt-fail counter. */
export function getDecryptFailCount(): number {
  return decryptFailCount;
}

export function incrementLogoutUpstreamFail(): void {
  logoutUpstreamFailCount += 1;
}

/** Test-only read access to the logout upstream-fail counter. */
export function getLogoutUpstreamFailCount(): number {
  return logoutUpstreamFailCount;
}

export function incrementLogoutRedisFail(): void {
  logoutRedisFailCount += 1;
}

/** Test-only read access to the logout Redis-fail counter. */
export function getLogoutRedisFailCount(): number {
  return logoutRedisFailCount;
}
