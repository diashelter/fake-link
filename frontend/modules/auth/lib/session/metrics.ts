/** Internal counter hook for bff_session_decrypt_fail_total (SC-07 observability). */
let decryptFailCount = 0;

export function incrementDecryptFail(): void {
  decryptFailCount += 1;
}

/** Test-only read access to the decrypt-fail counter. */
export function getDecryptFailCount(): number {
  return decryptFailCount;
}
