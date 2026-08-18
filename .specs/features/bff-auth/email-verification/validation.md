# BFF Auth email-verification Validation

**Date**: 2026-08-18
**Spec**: `.specs/features/bff-auth/email-verification/spec.md`
**Diff range**: `88b16d6..65311c7` (HEAD `65311c7`)
**Verifier**: independent sub-agent (author ≠ verifier) — re-verify iteration 1 of max 3 after `65311c7`

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | `9f3d7c4` feat(bff-auth): add verify email zod schema |
| T2 | ✅ Done | `4185706` feat(bff-auth): add email verification auth messages |
| T3 | ✅ Done | `8f9f23c` feat(bff-auth): add verification session guard helper |
| T4 | ✅ Done | `15527bb` feat(bff-auth): add verification mutation context loader |
| T5 | ✅ Done | `dfb021d` feat(bff-auth): add performBffVerifyEmail service |
| T6 | ✅ Done | `89360ef` feat(bff-auth): add performBffResendVerification service |
| T7 | ✅ Done | `6824fd4` feat(bff-auth): add email verify and resend allowlist entries |
| T8 | ✅ Done | `efe2211` feat(bff-auth): add email verify bff route handler |
| T9 | ✅ Done | `c3af464` feat(bff-auth): add email resend bff route handler |
| T10 | ✅ Done | `1f38816` feat(bff-auth): add verify email form component |
| T11 | ✅ Done | `f8685ce` feat(bff-auth): add verify email page |
| T12 | ✅ Done | `b5820ec` feat(bff-auth): redirect verification session from home |
| T13 | ✅ Done | `5130ab0` chore(bff-auth): email verification slice quality gates |
| Fix | ✅ Done | `65311c7` fix(bff-auth): close email verification validation gaps |

All 13 tasks marked done in `tasks.md`. Fix commit `65311c7` addresses iteration-0 ranked gaps.

---

## Spec-Anchored Acceptance Criteria

### P1: Verificar e-mail via BFF

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Verify valid token + Origin/CSRF + `kind: verification` + upstream `204` | `destroySession` best-effort; `clearSessionCookie`; HTTP `200` body `{ data: { redirect_to: "/login", message: "E-mail confirmado. Faça login para continuar." } }`; Laravel with `Authorization: Bearer <cifrado>` and `{ token }` only | `frontend/modules/auth/services/bff-verify-email.test.ts:127-129` — `toEqual({ data: { redirect_to: '/login', message: SUCCESS_MESSAGE } })`; `:143` `after.context` null; `:155` `Max-Age=0`; `:201-209` `Authorization: Bearer ${FIXTURE_BEARER}` + `body: JSON.stringify({ token: EMAIL_TOKEN_SENTINEL })` | ✅ PASS |
| Verify success headers + cookie | `Cache-Control: private, no-store`; `__Host-fl_session` expired | `bff-verify-email.test.ts:167` — `Cache-Control` `toBe('private, no-store')`; `:155` `/Max-Age=0/i` | ✅ PASS |
| Verify success Redis | Previous session SHALL NOT remain resolvable via the cookie on the response | `bff-verify-email.test.ts:143` — `expect(after.context).toBeNull()` | ✅ PASS |
| Success JSON sanitization | SHALL NOT contain Bearer, `token_kind`, `token_type`, `expires_at`, or submitted email-token plaintext | `bff-verify-email.test.ts:180-185` — `not.toContain('Bearer' \| 'token_kind' \| 'token_type' \| 'expires_at' \| EMAIL_TOKEN_SENTINEL)` | ✅ PASS |
| Upstream status ≠ `204` | SHALL NOT call `destroySession` **nor** `clearSessionCookie` | `bff-verify-email.test.ts:228` `del` not called **and** `:229` `sessionCookieHeader(...).not.toMatch(/Max-Age=0/i)` on `INVALID_VERIFICATION_TOKEN`; `:263-264` same on `401`. Shared 4xx path. Sensor mutant C killed. | ✅ PASS |
| `session.kind !== 'verification'` | `403` `{ "message": "Forbidden." }` without Laravel | `bff-verify-email.test.ts:451-453` — status `403`, `json === { message: 'Forbidden.' }`, `fetchMock` not called; `bff-email-verification-shared.test.ts:155-157` | ✅ PASS |

### P1: Reenviar e-mail de verificação via BFF

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Resend + upstream `202` | Pass through `202` + Accepted envelope; session cookie remains valid | `bff-resend-verification.test.ts:105-126` — status `202`, `json === { message: 'Accepted.' }`, cookie not `Max-Age=0`, `after.context.sessionId === created.sessionId` | ✅ PASS |
| Resend success | SHALL NOT call `destroySession` | `bff-resend-verification.test.ts:108` — `expect(del).not.toHaveBeenCalled()` | ✅ PASS |
| Upstream `429 RATE_LIMIT_EXCEEDED` | Pass `429` body + `Retry-After` when present; no destroy | `bff-resend-verification.test.ts:142-145` — status `429`, `Retry-After === '120'`, `del` not called | ✅ PASS |
| `kind !== 'verification'` | `403` without Laravel | `bff-resend-verification.test.ts:156-166` — status `403`, `{ message: 'Forbidden.' }`, `fetchMock` not called | ✅ PASS |
| Resend headers | `Cache-Control: private, no-store` | `bff-resend-verification.test.ts:194` — `toBe('private, no-store')` | ✅ PASS |

### P1: Erros de verificação e estado da conta

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Upstream `403 INVALID_VERIFICATION_TOKEN` | Pass status+body; no destroy/clear cookie; UI `"Link de verificação inválido ou expirado."` | BFF `bff-verify-email.test.ts:226-229`; UI `verify-email-form.test.tsx:139-141` — `alert.textContent === 'Link de verificação inválido ou expirado.'`; `auth-messages.test.ts:57-59` | ✅ PASS |
| Upstream `403 EMAIL_ALREADY_VERIFIED` | Pass status+body; UI SHALL **display** `"Este e-mail já foi confirmado. Faça login para continuar."` **and** navigate `/login` | BFF `bff-verify-email.test.ts:244-246`; UI `verify-email-form.test.tsx:162-166` — `alert.textContent` exact string **and** `pushMock('/login')`; resend path `:282-286`; `auth-messages.test.ts:63-65` | ✅ PASS |
| Upstream `401 UNAUTHENTICATED` | Pass through; UI treats as expired session | BFF `bff-verify-email.test.ts:261-264`; UI resend `verify-email-form.test.tsx:325-327` — `'Sua sessão expirou. Faça login novamente.'`; `auth-messages.test.ts:69-70` | ✅ PASS |
| `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` | BFF pass-through; UI specific pt-BR | BFF suspended `bff-verify-email.test.ts:505-507` + resend `:180-181`; UI map `auth-messages.test.ts:11` `'Esta conta está suspensa.'` and `:15-17` `'Esta conta está em processo de exclusão.'`. Form displays via `messageForAuthError(payload.code)` (`verify-email-form.tsx:100-102`). Representative BFF 4xx forward; no dedicated `ACCOUNT_PENDING_DELETION` verify HTTP case (same branch). | ✅ PASS |
| Upstream `422 VALIDATION_FAILED` | Pass `errors`; no destroy | `bff-verify-email.test.ts:283-285` — status `422`, `json === payload` including `errors.token`, `del` not called | ✅ PASS |
| Upstream `500`/`503`/`504` | Generic pt-BR mirroring status (or gateway `504`); no destroy | `bff-verify-email.test.ts:322-326` / `:343-346` `'Algo deu errado. Tente novamente.'` at 500/503; `:362-366` 504 `'Não foi possível conectar ao serviço. Tente novamente.'`; `del` not called | ✅ PASS |

### P1: Guards BFF (Origin / CSRF / sessão)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Missing/invalid Origin, CSRF, or session cookie | `403` `{ "message": "Forbidden." }` without Laravel | Shared Origin `bff-email-verification-shared.test.ts:102-105`; missing cookie `:119-120`; CSRF `:137-138`; verify route `verify/route.test.ts:119-122`, `:131-133`, `:152-154`; resend `resend/route.test.ts:101-104` | ✅ PASS |
| `requireSession: true` | CSRF mode `session` bound to loaded `sessionId` | Allowlist `allowlist.test.ts:69-70` `requireSession/requireCsrf === true`; session-bound CSRF `mutation-guard.test.ts:104-121`; shared happy path `bff-email-verification-shared.test.ts:173-177` with `deriveCsrfToken(sessionId)`; page does not bootstrap pre-auth CSRF `verify-email/page.test.tsx:87` | ✅ PASS |
| Guard failure headers | `Cache-Control: private, no-store` | `bff-email-verification-shared.test.ts:104`; `verify/route.test.ts:121`; `resend/route.test.ts:103` | ✅ PASS |

### P1: UI de verificação server-first

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET `/verify-email` without BFF session | `redirect('/login')` | `app/verify-email/page.test.tsx:58-59` — `rejects.toThrow('REDIRECT:/login')` | ✅ PASS |
| Session kind `session` visits `/verify-email` | `redirect('/')` | `app/verify-email/page.test.tsx:70-71` — `'REDIRECT:/'` | ✅ PASS |
| Session `verification` | Form with token field, primary `"Confirmar e-mail"`, secondary `"Reenviar e-mail"` | `verify-email-form.test.tsx:43` label `Código de verificação`; `:77` button `Confirmar e-mail`; `:96` `Reenviar e-mail`; page hydrates token `page.test.tsx:88` `"initialToken":"from-query"` | ✅ PASS |
| `?token=` hydrates field without auto verify/resend | Hydrate only; no fetch on load | `verify-email-form.test.tsx:43-45` value === INITIAL_TOKEN; `fetchSpy` not called | ✅ PASS |
| Mount with `?token=` | `history.replaceState` drops query `token` | `verify-email-form.test.tsx:56-58` — `replaceSpy` called; URL arg `not.toMatch(/[?&]token=/)` | ✅ PASS |
| Submit valid token | `POST /api/bff/auth/email/verify` with `Content-Type: application/json`, `X-CSRF-Token`, body `{ token }` | `verify-email-form.test.tsx:262-264` | ✅ PASS |
| BFF `200` with `redirect_to` | UI SHALL **display** `"E-mail confirmado. Faça login para continuar."` and navigate `/login` | `verify-email-form.test.tsx:80-84` — `status.textContent` exact string **and** `pushMock('/login')`. Sensor mutant D killed. | ✅ PASS |
| Resend | `POST /api/bff/auth/email/resend` with CSRF; `202` shows `"Se o e-mail estiver cadastrado e pendente, você receberá um novo link."` | Confirmation `verify-email-form.test.tsx:99` exact string; CSRF `verify-email-form.test.tsx:120` `X-CSRF-Token === 'test-csrf-token'` | ✅ PASS |
| HTML / serialized props / fetch | Bearer and upstream auth token SHALL NOT appear | `verify-email/page.test.tsx:89-91` serialized page `not.toContain('Bearer' \| FIXTURE_BEARER \| 'bearer')`; BFF success JSON `bff-verify-email.test.ts:180-185` | ✅ PASS |
| Login escape link | Link `"Ir para login"` → `/login` | `verify-email-form.test.tsx:242` — `getByRole('link', { name: 'Ir para login' })` `href="/login"` | ✅ PASS |

### P1: Proteção contra scanner / prefetch

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET `/verify-email` (with or without `?token=`) | SHALL NOT invoke verify/resend during render | `app/verify-email/page.test.tsx:118` — `expect(fetchSpy).not.toHaveBeenCalled()` | ✅ PASS |
| RTL mount with `?token=` | Zero fetch to verify/resend until user gesture | `verify-email-form.test.tsx:45` | ✅ PASS |
| Prefetch / mount without gesture | No verification side-effect | Same evidence (`:37-47`) | ✅ PASS |

### P1: UX de sessão restrita

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| `verification` session on `/` | `redirect('/verify-email')` | Helper `verification-guard.test.ts:25-27`; page `app/page.test.tsx:43` `'REDIRECT:/verify-email'` | ✅ PASS |
| `verification` outside `{ /verify-email, /login, /terms }` | `redirect('/verify-email')` | `verification-guard.test.ts:49-51` pathname `/dashboard` → `{ action: 'redirect', to: '/verify-email' }` | ✅ PASS |
| `resolveVerificationSessionGuard` exported | Document contract (paths + behavior) for `session-shell` | Export `verification-guard.test.ts:7` `VERIFICATION_ALLOWED_PATHS === ['/verify-email', '/login', '/terms']`; JSDoc `verification-guard.ts:6-13` | ✅ PASS |
| After verify, new login emits `session` kind | Chained verify then login asserting `kind: 'session'` | `bff-verify-email.test.ts:510-549` — `performBffVerifyEmail` then `performBffLogin`; `after.context?.kind === 'session'` | ✅ PASS |

### P1: Validação de entrada

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Empty UI token | Block submit with pt-BR field error; no BFF call | `verify-email-form.test.tsx:218-220` `'Informe o código de verificação.'`; `fetchSpy` not called | ✅ PASS |
| BFF missing/empty `token` | Local `400` generic pt-BR without Laravel | `bff-verify-email.test.ts:429-438` status `400`, `{ message: 'Requisição inválida.' }`, `fetchMock` not called | ✅ PASS |
| Malformed JSON **or** invalid `Content-Type` | `400` generic without Laravel | Malformed JSON `bff-verify-email.test.ts:379-381`; invalid Content-Type `bff-verify-email.test.ts:398-400` status `400`, `{ message: 'Requisição inválida.' }`, `fetchMock` not called. Sensor mutant B killed. | ✅ PASS |
| Whitespace-only token | Client treats as invalid; **no silent trim** on BFF | Schema `verify-email-schema.test.ts:11-13` preserves `'  opaque-token  '`; `:33-36` `'   '` fails; form `verify-email-form.test.tsx:232-235`. BFF `bff-verify-email.test.ts:413-417` — `body: JSON.stringify({ token: '  opaque-token  ' })`. Sensor mutant A killed. | ✅ PASS |

### P1: Privacidade do token de e-mail

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Handlers/services | Email-token plaintext SHALL NOT appear in `console.log`, serialized errors, or client-bundle fixtures | Success/error JSON scan `bff-verify-email.test.ts:180-185` + resend `:204`. **No `console.log` spy.** Absence of logs is not a precise asserted outcome. | ⚠️ Spec-precision gap (same as iter 0; L-026 already recorded) |
| Sentinel token | Asserts scan success/error JSON and rendered HTML | JSON: `bff-verify-email.test.ts:185`; HTML/props: `verify-email/page.test.tsx:89-91` | ✅ PASS |
| Success body | Only `redirect_to` and `message` (no submitted token / extra user data) | `bff-verify-email.test.ts:127-129` exact `toEqual({ data: { redirect_to: '/login', message: SUCCESS_MESSAGE } })` | ✅ PASS |

### P2: Allowlist, descoberta, schema

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| `AUTH_BFF_ALLOWLIST` | Contains verify + resend; both `requireSession: true`, `requireCsrf: true` | `allowlist.test.ts:31-38` length 4 including both entries; `:69-71` verify; `:80-82` resend `requireSession/requireCsrf true`, upstream paths `/auth/email/verify` and `/auth/email/verification-notification` | ✅ PASS |
| `make test-frontend` | Discovers tests under `app/api/bff/auth/email/`, `app/verify-email/`, schema | Gate 2026-08-18: verify service 23, resend 10, form 16, pages, routes, schema 5 executed. `foundation-gates.test.ts:29-54` lists verify/resend routes + `verify-email/page.tsx` | ✅ PASS |
| `verify-email-schema` | OpenAPI `token` required, `minLength: 1`, no trim | `verify-email-schema.test.ts:6-7`, `:11-13`, `:17-20`, `:33-36` | ✅ PASS |

**Catalog IDs:** EV-01…EV-22, BFFUI-50…52, BFFUI-32 mapped through the story ACs above. Previous Needs Fix IDs (EV-02, EV-09, EV-13, EV-18, BFFUI-51) now have `file:line` evidence matching spec outcomes.

**Status**: ⚠️ Spec-precision gaps flagged (1 — EV-19 `console.log` absence). No AC gaps.

---

## Discrimination Sensor

Scratch: `git worktree add /tmp/fake-link-ev-sensor-r1 HEAD` (`65311c7`). Mutations applied only there; tests ran with `-v /tmp/fake-link-ev-sensor-r1/frontend:/app`. Real tree `frontend/` remained clean vs HEAD. Worktree restored after each mutant (`git checkout -- frontend`).

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| A | `bff-verify-email.ts:45` `parseVerifyBody` | Trimmed token before upstream (`record.token.trim()`) — previously surviving | ✅ Killed — `bff-verify-email.test.ts` “forwards surrounding whitespace…” expected `JSON.stringify({ token: '  opaque-token  ' })` |
| B | `bff-verify-email.ts:79-83` | Removed invalid `Content-Type` → 400 guard — previously surviving | ✅ Killed — “returns 400 for invalid Content-Type…” `fetchMock` was called (`expected true to be false`) |
| C | `bff-verify-email.ts` 4xx return | `clearSessionCookie` on 4xx **without** `destroySession` — previously surviving | ✅ Killed — INVALID_VERIFICATION_TOKEN `:229` and 401 `:264` `not.toMatch(/Max-Age=0/i)` |
| D | `verify-email-form.tsx:78-80` | Skipped `setStatusMessage` on success (navigate only) | ✅ Killed — “shows success message and navigates…” `getByRole('status')` |
| E | `verify-email-form.tsx:84-87` | Skipped `setFormError` on `EMAIL_ALREADY_VERIFIED` (navigate only) | ✅ Killed — “shows pt-BR copy and navigates…” `getByRole('alert')` |
| F | `app/verify-email/page.tsx:48` | Passed raw `params.token` (skipped `decodeEmailToken`) | ✅ Killed — “decodes a URL-encoded query token…” expected `"initialToken":"hello/world"` |

**Sensor depth**: P0-full (6 behavior-level mutations on auth critical path; includes the three previously surviving mutants plus success-copy and already-verified-alert)
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results (if performed)

Skipped (orchestrator: skip interactive UAT).

| # | Test | Result | Details |
| - | ---- | ------ | ------- |
| — | Interactive UAT | ⏭️ Skip | Non-interactive verifier pass |

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ Thin route handlers; two services + shared loader; success/already-verified copy rendered in the existing form (no extra success-page file) |
| Surgical changes | ✅ Feature `88b16d6..5130ab0`; fix `65311c7` touches 5 files (page, form, verify service tests) |
| No scope creep | ✅ No password routes; no Playwright; no auto-login |
| Matches patterns | ✅ Mirrors login/register BFF (guards, `jsonWithPrivateCache`, FakeSessionStore, MSW forms) |
| Spec-anchored outcome check (asserted values match spec) | ✅ Exact pt-BR strings, status codes, cookie Max-Age, upstream body including whitespace |
| Per-layer Coverage Expectation met (domain 1:1 ACs; routes happy+edge+error) | ✅ Service tests discriminate happy + error + Content-Type + no-trim; route tests cover Origin/CSRF/no-session; RTL covers success/already-verified/429-with-and-without-Retry-After |
| Every test maps to a spec requirement — no unclaimed tests | ✅ Login-after-verify maps to BFFUI-52 AC4; URL-decode and 429-without-Retry-After map to listed edges |
| Documented guidelines followed: `docs/testing.md` §3.2, §4, §6.1, §6.2 | ✅ Vitest/RTL/MSW; scanner GET; Bearer absent; CSRF session-mode |

---

## Edge Cases

- [x] Device without BFF cookie: `/verify-email` → `/login` (`page.test.tsx:58-59`)
- [x] Invalid `?token=` with valid session: hydrate (`form.test.tsx:43`) + submit `403` without destroy/clear (`bff-verify-email.test.ts:214-229`)
- [x] Resend invalidates prior token → `403 INVALID_VERIFICATION_TOKEN` (same forward path)
- [ ] Concurrent verify two tabs (one `200`, other `403` / session already gone) — **not tested** (spec edge; not a story AC; no `file:line`)
- [x] Upstream `204` but `destroySession` throws: still `200` + `Max-Age=0` (`bff-verify-email.test.ts:485-490`)
- [x] `429` **without** `Retry-After`: UI generic limit copy (`verify-email-form.test.tsx:204-206` `'Muitas tentativas. Aguarde antes de tentar novamente.'`)
- [x] Suspended resend → `403 ACCOUNT_SUSPENDED` (`bff-resend-verification.test.ts:169-182`)
- [x] URL-encoded `?token=` decoded once before hydrate (`page.test.tsx:105` `"initialToken":"hello/world"`)
- [x] Trailing whitespace / no BFF trim: client (`verify-email-schema.test.ts:10-13`) and BFF (`bff-verify-email.test.ts:413-417`)
- [x] Bearer absent from serialized success/error JSON (`bff-verify-email.test.ts:180-182`; resend `:204`)

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: lint ✅ (1 pre-existing ESLint warning in `register-schema.test.ts:69`, non-blocking); tests **380 passed**, **0 failed**, **0 skipped**
- **Test count before feature**: 289 (register slice validation)
- **Test count after feature (iter 0)**: 374
- **Test count after fix `65311c7`**: 380
- **Delta**: +91 vs 289 baseline; +6 vs iter-0 374 (increased; no silent deletion)
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans (if issues found)

None. Previously surviving mutants A–C and AC gaps (success copy, already-verified copy, login-after-verify, 429-without-Retry-After, URL-decode) are now covered with `file:line` evidence and killed by the sensor.

Uncovered concurrent two-tab edge is documented above; it is not a story AC and is not ranked as a FAIL gap for this iteration.

---

## Requirement Traceability Update

Update spec.md requirement statuses:

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| BFFUI-50 | ✅ Verified | ✅ Verified |
| BFFUI-51 | ❌ Needs Fix | ✅ Verified |
| BFFUI-52 | ✅ Verified | ✅ Verified (login-after-verify now evidenced) |
| BFFUI-32 | ✅ Verified | ✅ Verified |
| EV-01 | ✅ Verified | ✅ Verified |
| EV-02 | ❌ Needs Fix | ✅ Verified |
| EV-03 | ✅ Verified | ✅ Verified |
| EV-04 | ✅ Verified | ✅ Verified |
| EV-05 | ✅ Verified | ✅ Verified |
| EV-06 | ✅ Verified | ✅ Verified |
| EV-07 | ✅ Verified | ✅ Verified |
| EV-08 | ✅ Verified | ✅ Verified |
| EV-09 | ❌ Needs Fix | ✅ Verified |
| EV-10 | ✅ Verified | ✅ Verified |
| EV-11 | ✅ Verified | ✅ Verified |
| EV-12 | ✅ Verified | ✅ Verified |
| EV-13 | ❌ Needs Fix | ✅ Verified |
| EV-14 | ✅ Verified | ✅ Verified |
| EV-15 | ✅ Verified | ✅ Verified |
| EV-16 | ✅ Verified | ✅ Verified |
| EV-17 | ✅ Verified | ✅ Verified |
| EV-18 | ❌ Needs Fix | ✅ Verified |
| EV-19 | ✅ Verified | ✅ Verified (console.log still ⚠️ spec-precision; L-026) |
| EV-20 | ✅ Verified | ✅ Verified |
| EV-21 | ✅ Verified | ✅ Verified |
| EV-22 | ✅ Verified | ✅ Verified |

---

## Lessons

No new lessons. The only remaining signal is the EV-19 `console.log` spec-precision gap, already grounded as confirmed **L-026** for this feature. Distill skipped per “do not re-add the same lessons unless a NEW distinct failure occurred.”

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 46/47 ACs matched spec outcome | 1 spec-precision gap (EV-19 `console.log`)
**Sensor**: 6/6 mutations killed
**Gate**: 380 passed, 0 failed

**What works**: Verify 204→200 sanitised body with exact `redirect_to`/`message`; destroy+cookie on success only; error paths keep cookie (`Max-Age=0` absent); BFF forwards opaque token including surrounding whitespace; invalid Content-Type 400 without Laravel; success and already-verified UI copy displayed then navigate `/login`; login-after-verify issues `session` kind; 429 with and without Retry-After; URL-encoded query token decoded once; scanner-safe GET; kind/Origin/CSRF 403.

**Issues found**: none ranked. Residual: no `console.log` spy (spec-precision); concurrent two-tab verify untested (edge, not story AC).

**Next steps**: Mark feature verified. No fix→re-verify iteration needed.
