# BFF Auth — CSRF e proxy — Validation Report

**Status:** PASS  
**Date:** 2026-08-11  
**Verifier:** independent pass (post-Execute)  
**Diff range:** `40b0eef` … `8c3ee52` (11 commits, T1–T11)

---

## Gate Results

| Gate | Command | Result |
| --- | --- | --- |
| Lint + typecheck | `make lint-frontend` | ✅ PASS |
| Unit tests | `make test-frontend` | ✅ 91 passed, 0 failed |
| Coverage | `make test-frontend-coverage` | ✅ `modules/auth/bff/**` 97.36% lines / 93.49% branches (≥80%) |

---

## Spec-Anchored AC Evidence (sample — all CP-01…CP-15 covered)

| AC | Spec outcome | Evidence | Result |
| --- | --- | --- | --- |
| CP-01 | `{ ok: true }` on exact Origin | `origin.test.ts:33` — `expect(...).toEqual({ ok: true })` | ✅ |
| CP-02 | `{ ok: false }` missing/null/wrong | `origin.test.ts:40,47,54` | ✅ |
| CP-03 | CSRF missing → fail | `csrf.test.ts:62-74` | ✅ |
| CP-04 | session HMAC token accepted | `csrf.test.ts:44-48` | ✅ |
| CP-05 | pre-auth sid token accepted | `csrf.test.ts:50-55` | ✅ |
| CP-06 | mismatch rejected | `csrf.test.ts:76-85`, `crypto.test.ts:28-34` | ✅ |
| CP-07 | rotate invalidates old token | `csrf.test.ts:87-108` | ✅ |
| CP-08 | prod allowlist length 0 | `allowlist.test.ts:26-28` | ✅ |
| CP-09 | fixed upstream URL only | `allowlist.test.ts:44-48`, `upstream.test.ts:33-36` | ✅ |
| CP-10/11 | malicious returnUrl → `/` | `return-url.test.ts:12-35` | ✅ |
| CP-12 | `private, no-store` on 403 | `private-response.test.ts:24-31` | ✅ |
| CP-13 | guard 403 without upstream | `mutation-guard.test.ts:52-83` | ✅ |
| CP-14 | Bearer server-side only; timeout 504 | `upstream.test.ts:37-88` | ✅ |
| CP-15 | probe route + matrix tests | `route.test.ts:39-95` | ✅ |

**Spec-precision gaps:** none blocking — CP-06 timing-safe path verified via behavior + dedicated crypto unit tests.

---

## Discrimination Sensor

| Mutation | Target | Tests run | Killed? |
| --- | --- | --- | --- |
| M1 | `origin.ts`: disable wrong-origin check (`!==` → always pass) | `make test-frontend` | ✅ Killed (3 failures: origin + probe) |

No surviving mutants.

---

## Verdict

**PASS** — All tasks T1–T11 implemented; gates green; AC evidence mapped; sensor killed mutation.

**Follow-ups (non-blocking):**

- Integração real com `SessionLoader` de `session-core` na fatia `login`.
- Cobertura de branches em `origin.ts` (lines 21–22) e `return-url.ts` decode catch paths — acima do threshold 80%.
