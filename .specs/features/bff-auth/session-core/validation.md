# BFF Auth — session-core Validation

**Date**: 2026-08-11  
**Spec**: `.specs/features/bff-auth/session-core/spec.md`  
**Diff range**: `fa24b26^..899d855` (17 commits: `fa24b26` … `899d855`)  
**Verifier**: independent sub-agent (author ≠ verifier) — re-validation iteration 1 of max 3  
**Branch**: `feature/bff-auth-session-core`  
**Prior FAIL**: concurrent `rotateSession` edge had no test evidence  
**Fix commit**: `899d855` — claim DEL before create; concurrent test added

---

## Task Completion

| Task | Status  | Notes |
| ---- | ------- | ----- |
| T1   | ✅ Done | `redis` pinned |
| T2   | ✅ Done | domain types |
| T3   | ✅ Done | config loader + tests |
| T4   | ✅ Done | session-id generate/parse |
| T5   | ✅ Done | AES-GCM envelope |
| T6   | ✅ Done | HMAC key + TTL helpers |
| T7   | ✅ Done | metrics + fake store |
| T8   | ✅ Done | Redis session store adapter |
| T9   | ✅ Done | createSession + cookie helpers |
| T10  | ✅ Done | getSession |
| T11  | ✅ Done | touch + expiry |
| T12  | ✅ Done | rotate/destroy/Redis failure + concurrent claim (`899d855`) |
| T13  | ✅ Done | Docker/.env.example |
| T14  | ✅ Done | probe route |
| T15  | ✅ Done | foundation gates + exports |
| T16  | ✅ Done | coverage gate |

All Done-when boxes in `tasks.md` are `[x]`.

---

## Spec-Anchored Acceptance Criteria

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| SC-01: createSession → opaque ID, AES-GCM, HMAC Redis key, absolute TTL | 43-char base64url ID; `schemaVersion: 1`; EX = absolute TTL; ciphertext present | `bff-session.test.ts:54` — `expect(result.sessionId).toMatch(/^[A-Za-z0-9_-]{43}$/)`; `:64` — `expect(record!.schemaVersion).toBe(1)`; `:68` — `expect(store.getExSeconds(key)).toBe(ABSOLUTE_TTL_SECONDS.session)`; `crypto.test.ts:26` — plaintext decrypt equals bearer | ✅ PASS |
| SC-02: Redis record MUST NOT contain Bearer plaintext / Authorization | `JSON.stringify(record)` excludes bearer & Authorization | `bff-session.test.ts:81-82` — `not.toContain(TEST_BEARER)` / `not.toContain('Authorization')` | ✅ PASS |
| SC-03: Set-Cookie `__Host-fl_session` + HttpOnly/Secure/SameSite=Lax/Path=/; no Domain | Flags present; no Domain; Path=/ | `bff-session.test.ts:130-134` — cookie name + flag regexes + `not.toMatch(/Domain=/i)`; Path=/ via `session-cookie.test.ts` helper defaults | ✅ PASS |
| SC-04: absolute expiresAt ≤ now+604800 (session) / ≤ now+86400 (verification) | expiresAt exact absolute from constants | `bff-session.test.ts:55-57` session TTL; `:91-93` verification TTL; `ttl.test.ts` constants `604_800` / `86_400` | ✅ PASS |
| SC-05: getSession → SessionContext with decrypted bearer in memory | `context.bearer === TEST_BEARER` | `bff-session.test.ts:175-178` — `expect(result.context!.bearer).toBe(TEST_BEARER)` | ✅ PASS |
| SC-06: absent/malformed cookie or Redis miss → null; no raw-ID Redis key | `{ context: null, clearCookie: true }`; no store get on malformed | `bff-session.test.ts:208-209` — null+clearCookie; `getSpy` not called; `session-id.test.ts:30` invalid charset → null | ✅ PASS |
| SC-07: decrypt fail → destroy + clearCookie + null | clearCookie; Redis key deleted; metric +1 | `bff-session.test.ts:238-240` — null+clearCookie; decrypt-fail count; store.get null; `crypto.test.ts` invalid tag / unknown kid throw | ✅ PASS |
| SC-05/SC-07 leak guard: safe serialization must not include bearer | Probe/product-safe shape omits bearer | `bff-session.test.ts:192` — `JSON.stringify(safeShape).not.toContain(TEST_BEARER)` | ✅ PASS |
| SC-08: absolute expired → null + destroy + clearCookie | null+clearCookie; Redis key gone | `bff-session.test.ts:304-305` | ✅ PASS |
| SC-09: idle expired → null + destroy | session idle destroyed | `bff-session.test.ts:279-280`; `ttl.test.ts` idle helpers | ✅ PASS |
| SC-10: touch &lt;900s no write; ≥900s updates lastActivityAt + EX | set not called &lt;900s; lastActivityAt updated ≥900s | `bff-session.test.ts:318-320`; `:333-334`; `ttl.test.ts` shouldTouch | ✅ PASS |
| SC-11: rotate invalidates old ID, emits new; destroy removes key + Max-Age=0 | old getSession null; new has bearer; destroy clearCookie + Max-Age=0 | `bff-session.test.ts:360` new ≠ old; `:367` old null; `:374-375` new bearer; `:421-422` destroy; `:431` Max-Age=0 | ✅ PASS |
| SC-12: Redis miss → null + clearCookie, no Bearer fallback | null+clearCookie on miss | `bff-session.test.ts:367` miss after rotate | ✅ PASS |
| SC-13: Redis connection fail on getSession → null + clearCookie | same as miss | `bff-session.test.ts:447` — `expect(result).toEqual({ context: null, clearCookie: true })` after `store.get` reject | ✅ PASS |
| SC-14: missing/malformed AES/HMAC keys → explicit startup error | throws naming env var | `config.test.ts:79` AES; HMAC missing / wrong length cases | ✅ PASS |
| SC-15: unknown AES kid → decrypt fail + session destroyed | SessionDecryptError; destroy path shared with SC-07 | `crypto.test.ts` unknown kid; destroy via SC-07 path | ✅ PASS |
| SC-16: prod + probe disabled → 404 (GET and POST) | status 404 | `route.test.ts:72-73` | ✅ PASS |
| SC-17: probe POST→GET returns `{ authenticated, kind }` without secrets | exact body; no bearer/sessionId/ciphertext | `route.test.ts:109-124` | ✅ PASS |
| SC-18: document AES key rotation invalidates sessions | comment in `.env.example` | `.env.example:27` — rotating AES key invalidates sessions | ✅ PASS |

**Status**: ✅ All SC-01…SC-18 ACs covered with spec-anchored assertions — no edge-case gaps

---

## Fix 1 evidence (re-verify focus)

Spec edge: *WHEN duas requisições concorrentes chamam `rotateSession` no mesmo ID THEN ao menos uma SHALL falhar ou só um novo ID SHALL permanecer válido (ID antigo inválido).*

| Item | Evidence |
| ---- | -------- |
| Test | `frontend/modules/auth/services/bff-session.test.ts:378` — `concurrent rotateSession on same id leaves at most one valid successor` |
| Concurrent call | `:385-388` — `Promise.allSettled([rotateSession(...), rotateSession(...)])` |
| ≤1 successor / one failure | `:396-401` — `expect(fulfilled).toHaveLength(1)`; `expect(rejected).toHaveLength(1)`; rejected reason `SessionValidationError` |
| Old ID invalid | `:403-404` — `expect(oldResult).toEqual({ context: null, clearCookie: true })` |
| Successor valid | `:406-409` — `newResult.context` non-null; bearer equals `TEST_BEARER` |
| Implementation | `bff-session.ts:279-282` — claim via `deleteSessionRecord`; `if (!claimed) throw SessionValidationError` before `createSession` |

**Result**: ✅ Covered — assertions match “old invalid + ≤1 successor valid OR one failure”

---

## Discrimination Sensor

Scratch protocol: backup → mutate in place → scoped `vitest run` → restore from `/tmp/session-core-mutant-backups-reverify1` → `cmp` verify. Final mutated paths clean vs backup.

| Mutation | File:line (approx) | Description | Killed? |
| -------- | ------------------ | ----------- | ------- |
| 1 | `bff-session.ts:280` | Skip claim check — `if (false && !claimed)` so create proceeds when DEL returns false | ✅ Killed (`concurrent rotateSession…` — fulfilled length 2) |
| 2 | `fake-session-store.ts:24-26` | `del` always returns `true` without deleting | ✅ Killed (SC-11 after-rotate old still resolves; concurrent fulfilled length 2) |
| 3 | `route.ts:53-55` | Probe GET includes `bearer: result.context.bearer` | ✅ Killed (`route.test.ts:109` body equality / `not.toHaveProperty('bearer')`) |

**Sensor depth**: lightweight re-verify (≥3 mutations focused on Fix 1 + one prior P0 leak)  
**Result**: 3/3 killed — PASS ✅

---

## Interactive UAT Results

Not performed — infrastructure / library feature (no product UI). Automated Vitest + probe route sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ — Fix 1 is claim-DEL-before-create + concurrent test |
| No scope creep | ✅ |
| Matches patterns | ✅ — FakeSessionStore DI; Redis `del` → boolean claim |
| Spec-anchored outcome check | ✅ for SC-01…SC-18 + concurrent edge |
| Per-layer Coverage Expectation met | ✅ — prior coverage gate T16; rotate path re-spot-checked |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed | ✅ — `docs/testing.md`; `docs/security.md` §5.1–5.2 |

---

## Edge Cases

- [x] Cookie charset outside base64url → null without Redis GET — `session-id.test.ts:30-32`; `bff-session.test.ts:208-209`
- [x] Cookie decode ≠32 bytes → null — `session-id.test.ts:41-57`
- [x] `schemaVersion` ≠ 1 → treat as miss — `session-store.test.ts` parse null
- [x] Unknown `kind` → treat as miss — `session-store.test.ts` parse null
- [x] **Concurrent `rotateSession` on same ID** — `bff-session.test.ts:378` (+ `:396-409`) — old invalid + exactly one fulfilled successor / one `SessionValidationError`
- [x] Empty bearer rejected before SET — `bff-session.test.ts:104` / `:113`
- [x] Redis eviction before TTL → miss behavior — SC-12 path (`bff-session.test.ts:367`)
- [x] Probe POST in production without flag → 404 not 500 — `route.test.ts:72-73`

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: 91 passed, 0 failed, 0 skipped
- **Lint**: typecheck + eslint + prettier + lint-staged contract OK
- **Test count before feature**: ~40–45 frontend tests (foundation baseline)
- **Test count after feature**: 91 (+2 vs prior validation’s 89 — concurrent rotate + related)
- **Delta**: net increase; no skipped/deleted integrity flags
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans (if issues found)

None — re-verify PASS.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| SC-01 … SC-18 | ✅ Verified | ✅ Verified (re-confirmed) |
| Concurrent rotate edge | ❌ Needs Fix (prior validation) | ✅ Verified (`bff-session.test.ts:378`) |

`spec.md` requirement table already ✅ Verified for SC-01…SC-18; no status edits required.

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 18/18 ACs matched spec outcome | 0 spec-precision gaps | 0 edge-case gaps (concurrent rotate closed by `899d855`)  
**Sensor**: 3/3 mutations killed  
**Gate**: 91 passed, 0 failed

**What works**: Full session-core facade (create/get/touch/rotate/destroy), claim-before-create concurrent rotate, crypto/HMAC keys, TTL/idle/throttle, Redis failure handling, gated probe without Bearer leak, config fail-fast, AES rotation docs.

**Issues found**: none

**Next steps**: Feature ready for merge/handoff; no further fix→re-verify iterations needed.
