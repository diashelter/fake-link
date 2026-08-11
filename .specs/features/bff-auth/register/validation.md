# BFF Auth — Cadastro Validation

**Date**: 2026-08-11  
**Spec**: `.specs/features/bff-auth/register/spec.md`  
**Diff range**: `e06325d^..HEAD` (`e06325d`…`ee9bb24`, branch `feature/bff-auth-register`)  
**Verifier**: independent sub-agent (author ≠ verifier) — re-verify after fix `ee9bb24`  
**Prior verdict**: FAIL ❌ (`.specs/features/bff-auth/register/validation.md` pre-fix) — 4 GAPs

---

## Verdict: PASS ✅

| Check | Result |
| ----- | ------ |
| Spec-anchored AC coverage | ✅ 48/48 P1+P2 ACs matched; 0 GAPs |
| Gate: `make lint-frontend && make test-frontend` | ✅ 289 passed, 0 failed |
| Discrimination sensor (P0 ≥5) | ✅ 9/9 mutants killed (scratch worktree only) |
| Spec-precision gaps | 0 |
| Prior gaps closed by `ee9bb24` | ✅ all 4 |

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 Password schema | ✅ Done | Commit `e06325d` |
| T2 Register schema | ✅ Done | Commit `62d25ec` |
| T3 Auth messages | ✅ Done | Commit `96a430e` |
| T4 Auth terms | ✅ Done | Commit `d645b7c` |
| T5 performBffRegister | ✅ Done | Commit `239afed` |
| T6 Allowlist | ✅ Done | Commit `b4d16bb` |
| T7 Route handler | ✅ Done | Commit `ff4a901` |
| T8 RegisterForm | ✅ Done | Commit `2a56d08` |
| T9 Register page | ✅ Done | Commits `52ce700`, `7244aa7` |
| T10 Terms page | ✅ Done | Commit `e07cb14` |
| T11 Quality gates | ✅ Done | Commit `37bb200` |
| Fix coverage gaps | ✅ Done | Commit `ee9bb24` — CSRF cookie, `terms_accepted_at`, Content-Type, bare `token` |

---

## Spec-Anchored Acceptance Criteria

### Prior gaps re-check (fix `ee9bb24`)

| Prior GAP | Spec-defined outcome | New evidence | Result |
| --------- | -------------------- | ------------ | ------ |
| issueCsrfForSession / CSRF re-issue | After `createSession`, CSRF cookie for new session | `bff-register.test.ts:141-144` — `deriveCsrfToken(sessionId)` + Set-Cookie starts with `${CSRF_TOKEN_COOKIE}=${expectedCsrf}`; impl `bff-register.ts:261` | ✅ PASS |
| `terms_accepted_at` filled | Exact filled timestamp in sanitized user | `bff-register.test.ts:131` — `expect(body.data.user.terms_accepted_at).toBe('2026-08-11T12:00:00.000Z')` | ✅ PASS |
| Form `Content-Type: application/json` | Exact header on submit | `register-form.test.tsx:225` — `headers.get('Content-Type')).toBe('application/json')` | ✅ PASS |
| Bare substring `token` absent | `JSON.stringify` success body lacks `token` | `bff-register.test.ts:167` — `expect(serialized).not.toContain('token')` | ✅ PASS |

### P1: Cadastro bem-sucedido via BFF

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN valid POST + Origin/CSRF + upstream 201 THEN allowlisted call, extract token server-side, `createSession({ kind: 'verification' })`, Set-Cookie `__Host-fl_session`, `issueCsrfForSession`, respond 201 `{ data: { user, redirect_to: '/verify-email' } }` | status 201; `redirect_to` `/verify-email`; session kind `verification`; session cookie; CSRF re-issue | `bff-register.test.ts:125-126` — status 201; `:132` — `redirect_to` `/verify-email`; `:152` — kind `verification`; `:136` — `__Host-fl_session`; `:141-144` — CSRF cookie; `route.test.ts:72-77` | ✅ PASS |
| WHEN upstream 201 THEN `data.user.status` = `pending_verification` and `email_verified_at` = `null` | exact status + null | `bff-register.test.ts:128-129` — `toBe('pending_verification')`; `toBeNull()` | ✅ PASS |
| WHEN success JSON inspected THEN no substrings `token`, `Bearer`, `token_kind`, `token_type`, `expires_at` nor Bearer value | all listed substrings absent | `bff-register.test.ts:166-171` — `not.toContain(FIXTURE_BEARER\|token\|token_kind\|token_type\|expires_at\|Bearer)` | ✅ PASS |
| WHEN success THEN `Cache-Control: private, no-store` | exact header | `bff-register.test.ts:133` — `toBe('private, no-store')` | ✅ PASS |
| WHEN success + prior valid session cookie THEN `destroySession` before `createSession` | prior session gone | `bff-register.test.ts:435` — `expect(priorResult.context).toBeNull()` | ✅ PASS |
| WHEN success THEN Redis record encrypted Bearer, not plaintext | store JSON lacks plaintext Bearer | Inherited: `bff-session.test.ts:72` createSession plaintext strip; register happy path uses `createSession` (`bff-register.ts:234-241`) | ✅ PASS (via session-core createSession contract) |

### P1: Anti-enumeração no cadastro

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN upstream 403 `REGISTRATION_NOT_ALLOWED` THEN BFF 403 + equivalent body, no session cookie | status 403; body `{ code, message }`; no `__Host-fl_session` | `bff-register.test.ts:281-288` | ✅ PASS |
| WHEN 403 `REGISTRATION_NOT_ALLOWED` THEN no user/token fields beyond API contract | body equals `{ code, message }` only | `bff-register.test.ts:283-286` — `toEqual({ code: 'REGISTRATION_NOT_ALLOWED', message: 'Registration not allowed.' })` | ✅ PASS |
| WHEN invite invalid or duplicate THEN no new Redis session entry | no session created | Proxy: no Set-Cookie (`:287-288`); createSession not on 4xx path; identical bodies invite vs duplicate `:477-479` | ✅ PASS |
| WHEN UI receives 403 `REGISTRATION_NOT_ALLOWED` THEN same pt-BR message for invite vs duplicate | identical string | `register-form.test.tsx:124` — `toBe(REGISTRATION_NOT_ALLOWED_MESSAGE)`; `auth-messages.test.ts:33-40` | ✅ PASS |

### P1: Aceite de Terms

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN Terms unchecked THEN block submit, no BFF call | field error; fetch not called | `register-form.test.tsx:72-75` — message + `fetchSpy).not.toHaveBeenCalled()` | ✅ PASS |
| WHEN form renders THEN pt-BR label with version + `/terms` new tab | label + href + target | `register-form.test.tsx:262-270` | ✅ PASS |
| WHEN valid submit THEN body includes `accept_terms: true` literal | boolean `true` in JSON | `register-form.test.tsx:250-255` — `accept_terms: true` | ✅ PASS |
| WHEN upstream 422 on `accept_terms` THEN pass field errors, no session | 422 + errors, no cookie | `bff-register.test.ts:291-312` (422 pass-through); local `accept_terms: false` → 400 `:258-268` | ✅ PASS |
| WHEN success THEN `terms_version` persisted + `terms_accepted_at` filled | both fields present/correct | `bff-register.test.ts:130-131` — `terms_version` `toBe('2026-01')`; `terms_accepted_at` `toBe('2026-08-11T12:00:00.000Z')` | ✅ PASS |

### P1: Política de senha

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN password length &lt;12 or &gt;128 THEN client blocks | pt-BR field errors | `password-schema.test.ts:29-44` | ✅ PASS |
| WHEN missing ASCII lower/upper/digit/symbol THEN client blocks | pt-BR composition errors | `password-schema.test.ts:47-89` | ✅ PASS |
| WHEN password ≠ confirmation THEN client blocks | field error | `register-schema.test.ts:81-92` — `As senhas não coincidem.` | ✅ PASS |
| WHEN upstream 422 password THEN BFF forwards `errors.password`, no session | 422 + errors; no cookie | `bff-register.test.ts:307-312` | ✅ PASS |
| WHEN client-valid but server rejects THEN UI shows server field errors | accessible description from server | `register-form.test.tsx:154-160` | ✅ PASS |

### P1: Validação de entrada

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN name empty or &gt;120 THEN client blocks | pt-BR errors | `register-schema.test.ts:32-45` | ✅ PASS |
| WHEN email invalid or &gt;254 THEN client blocks | pt-BR errors | `register-schema.test.ts:48-53`; `modules/shared/schemas/email.test.ts:18-26` (via `emailSchema`) | ✅ PASS |
| WHEN email normalized THEN trim + lowercase before submit | normalized output | `register-schema.test.ts:18-29` | ✅ PASS |
| WHEN BFF JSON THEN only RegisterRequest fields upstream | exact 5 fields | `bff-register.test.ts:189-198` | ✅ PASS |
| WHEN upstream 422 THEN pass status+errors, no session | 422; no cookie | `bff-register.test.ts:305-312` | ✅ PASS |
| WHEN malformed JSON / bad Content-Type THEN 400, no Laravel | 400; fetch not called | `bff-register.test.ts:230-255` | ✅ PASS |

### P1: Rate limiting upstream

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN upstream 429 THEN BFF 429 + body, no session | 429; no cookie | `bff-register.test.ts:325-329` | ✅ PASS |
| WHEN `Retry-After` present THEN forward header | `Retry-After: 60` | `bff-register.test.ts:327` | ✅ PASS |
| WHEN UI 429 THEN pt-BR limit + temporal guidance if header | exact messages | `register-form.test.tsx:180-182`, `:199-201` | ✅ PASS |
| WHEN rate limit THEN no BFF session | no cookie | `bff-register.test.ts:328-329` | ✅ PASS |

### P1: Guards BFF (Origin / CSRF)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN invalid Origin/CSRF THEN 403 `{ message: "Forbidden." }`, no Laravel, no session | 403 + body; fetch not called | `bff-register.test.ts:203-227`; `route.test.ts:80-109` | ✅ PASS |
| WHEN guards fail THEN `Cache-Control: private, no-store` | exact header | `mutation-guard.test.ts:62` (shared `assertMutationGuard` used by register) | ✅ PASS |
| WHEN guards pass with `requireSession: false` THEN pre-auth CSRF | allowlist flags | `allowlist.test.ts:50-52` — `requireSession: false`, `requireCsrf: true` | ✅ PASS |

### P1: UI de cadastro server-first

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN GET `/register` anonymous THEN form fields (name, email, password, confirm, Terms) | fields render | `register-form.test.tsx` labels; `page.test.tsx:86-88` form + termsVersion | ✅ PASS |
| WHEN page loads THEN pre-auth CSRF cookies ensured | bootstrap called | `page.test.tsx:86` — `ensurePreAuthCsrfCookiesMock).toHaveBeenCalledOnce()` | ✅ PASS |
| WHEN valid submit THEN POST BFF with `Content-Type: application/json`, CSRF header, RegisterRequest body | all three | `register-form.test.tsx:225-226` Content-Type + CSRF; body `:250-255` | ✅ PASS |
| WHEN BFF 201 with `redirect_to` THEN navigate `/verify-email` | `router.push('/verify-email')` | `register-form.test.tsx:95` | ✅ PASS |
| WHEN session kind `session` visits `/register` THEN redirect `/` | redirect `/` | `page.test.tsx:67` — `REDIRECT:/` | ✅ PASS |
| WHEN session kind `verification` visits `/register` THEN redirect `/verify-email` | redirect `/verify-email` | `page.test.tsx:77` | ✅ PASS |
| WHEN HTML/props/fetch inspected THEN no Bearer | no Bearer substrings | `page.test.tsx:89-90`; success JSON strip tests | ✅ PASS |
| WHEN `/register` renders THEN "Já tenho conta" → `/login` | link href | `register-form.test.tsx:272` | ✅ PASS |

### P1: Falhas upstream e gateway

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN upstream timeout THEN 504 generic pt-BR, no cookie | 504 + message | `bff-register.test.ts:362-373` | ✅ PASS |
| WHEN upstream 500/503 THEN generic pt-BR mirroring status, no stack leak | 500/503 + generic message | `bff-register.test.ts:332-359` | ✅ PASS |
| WHEN createSession fails after 201 THEN no Bearer to browser, no valid session cookie | 500; no cookie | `bff-register.test.ts:454-457` | ✅ PASS |
| WHEN 201 without `data.token` THEN 500 generic, no cookie | 500; no cookie | `bff-register.test.ts:391-401` | ✅ PASS |

### P2: Página Terms, allowlist e descoberta

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| WHEN GET `/terms` THEN static pt-BR + current version | version + copy | `terms/page.test.tsx:20-32` | ✅ PASS |
| WHEN allowlist inspected THEN register + login entries | both present | `allowlist.test.ts:28-32`, `:47-54` | ✅ PASS |
| WHEN `make test-frontend` THEN register tests discovered | suite includes register files | Gate run: 289 tests including register suites | ✅ PASS |

**Status**: ✅ All ACs covered (48/48)

---

## Edge Cases

- [x] Allowlisted subaddress → 403 anti-enum UI message (handler + form fixtures)
- [x] Allowlist unavailable → 503 generic, no cookie (`bff-register.test.ts:332-345`)
- [x] Unicode-only password composition → client reject (`password-schema.test.ts:98-101`)
- [x] `Retry-After` absent on 429 → generic UI message (`register-form.test.tsx:186-202`)
- [x] Terms must be literal `true` (`register-schema.test.ts:56-78`; BFF `:258-268`)
- [x] Upstream 201 `token_kind !== verification` → 500 no cookie (`bff-register.test.ts:376-388`)
- [x] Name whitespace-only → validation (`register-schema.test.ts:32-37`)
- [x] Double-submit pending disable — shared `shouldBlockSubmit` (`form-defaults.test.tsx`; form wires it)
- [ ] Concurrent same-email POSTs — API-level; not asserted in BFF unit (acceptable out-of-layer)
- [ ] Bearer never in `console.log` / MSW browser bundle — no direct evidence (observability; soft)

---

## Discrimination Sensor

**Scratch**: `git worktree` detached at `/tmp/fake-link-register-sensor-reverify` (removed after run). Real tree not mutated.

| # | Mutation | File | Description | Killed? |
| - | -------- | ---- | ----------- | ------- |
| 1 | Success status | `bff-register.ts` success `{ status: 201 }` | `201` → `200` | ✅ Killed (`bff-register.test.ts` expected 201) |
| 2 | redirect_to | `VERIFY_EMAIL_REDIRECT` | `/verify-email` → `/` | ✅ Killed (expected `/verify-email`) |
| 3 | CSRF re-issue | `issueCsrfForSession(...)` removed | Drop CSRF Set-Cookie | ✅ Killed (CSRF cookie assertion `:141-144`) |
| 4 | Session kind | `createSession` kind | `verification` → `session` | ✅ Killed (kind assertion `:152`) |
| 5 | destroySession | prior-session block | Skip destroy | ✅ Killed (`:435` prior not null) |
| 6 | Token leak | success body | Add `token: authData.token` | ✅ Killed (RGR-03 strip test) |
| 7 | Content-Type | `register-form.tsx` | `application/json` → `text/plain` | ✅ Killed (`:225`) |
| 8 | terms_accepted_at | omit from public user | Delete field | ✅ Killed (`:131`) |
| 9 | token_kind gate | `!== 'verification'` → `!== 'session'` | Allow wrong kind / reject good | ✅ Killed (token_kind edge + happy path) |

**Sensor depth**: P0-full (9 ≥ 5)  
**Result**: 9/9 killed — PASS ✅

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ (`ee9bb24` +8 lines tests only) |
| No scope creep | ✅ |
| Matches login/csrf-proxy patterns | ✅ |
| Spec-anchored outcome check | ✅ (prior 4 gaps closed) |
| Per-layer coverage expectation | ✅ schemas/service/route/UI present |
| Every test maps to requirement | ✅ |
| Documented guidelines: `docs/testing.md` §3.2, §6.1, §6.2 | ✅ |

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: lint ✅ (1 eslint warning unused `_omit` in `register-schema.test.ts:69`, non-blocking); tests **289 passed, 0 failed, 0 skipped**
- **Test count before feature**: ~222 (login-validated baseline / tasks T11 note)
- **Test count after feature**: 289
- **Delta**: +~67
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

None — all prior fix tasks closed by `ee9bb24`; sensor found no surviving mutants.

---

## Requirement Traceability Update

| Requirement | Previous Status (prior FAIL) | New Status |
| ----------- | ---------------------------- | ---------- |
| BFFUI-40 | ❌ Needs Fix (issueCsrf) | ✅ Verified |
| BFFUI-41 | ❌ Needs Fix (`terms_accepted_at`) | ✅ Verified |
| BFFUI-32 | ✅ Verified | ✅ Verified |
| BFFUI-15 | ✅ Verified | ✅ Verified |
| BFFUI-17 | ❌ Needs Fix (bare `token`) | ✅ Verified |
| RGR-01 | ❌ Needs Fix | ✅ Verified |
| RGR-02 | ✅ Verified | ✅ Verified |
| RGR-03 | ❌ Needs Fix | ✅ Verified |
| RGR-04 … RGR-05 | ✅ Verified | ✅ Verified |
| RGR-06 | ✅ Verified | ✅ Verified |
| RGR-07 | ❌ Needs Fix (`terms_accepted_at`) | ✅ Verified |
| RGR-08 … RGR-12 | ✅ Verified | ✅ Verified |
| RGR-13 | ❌ Needs Fix (Content-Type) | ✅ Verified |
| RGR-14 … RGR-18 | ✅ Verified | ✅ Verified |

*(Verifier did not edit `spec.md` — read-only constraint; orchestrator may flip Pending → Verified.)*

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 48/48 ACs matched spec outcome | 0 spec-precision gaps  
**Sensor**: 9/9 mutations killed  
**Gate**: 289 passed

**What works**: Full BFF register orchestration (201, verification session, CSRF re-issue, destroy-prior, Bearer strip including bare `token`, anti-enum, password/schema, guards, rate-limit, upstream failures, UI redirects/Content-Type, Terms page/allowlist). Prior four evidence gaps closed by `ee9bb24` and confirmed by targeted mutants.

**Issues found**: none

**Next steps**: Mark feature Verified in `spec.md` / STATE; proceed to next fatia.
