# BFF Auth — Senha Validation

**Date**: 2026-08-18
**Spec**: `.specs/features/bff-auth/password/spec.md`
**Diff range**: `main..HEAD` (`8dc7891` … `e150887`)
**Verifier**: independent sub-agent (author ≠ verifier) — re-run 2

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | `8dc7891` feat(bff-auth): add forgot password zod schema |
| T2 | ✅ Done | `fdd3afa` feat(bff-auth): add reset password zod schema |
| T3 | ✅ Done | `ef3fbfc` feat(bff-auth): add change password zod schema |
| T4 | ✅ Done | `cb923b9` feat(bff-auth): add server field error mapper |
| T5 | ✅ Done | `989a067` feat(bff-auth): allow recovery paths in verification guard |
| T6 | ✅ Done | `addc478` feat(bff-auth): add session mutation context loader |
| T7 | ✅ Done | `20ebd64` feat(bff-auth): add performBffPasswordResetRequest service |
| T8 | ✅ Done | `3a9b966` feat(bff-auth): add performBffPasswordReset service |
| T9 | ✅ Done | `b9aa11b` feat(bff-auth): add performBffPasswordChange service |
| T10 | ✅ Done | `e38d108` feat(bff-auth): add password allowlist entries |
| T11 | ✅ Done | `5036ae3` feat(bff-auth): add password reset-request bff route |
| T12 | ✅ Done | `a4ded66` feat(bff-auth): add password reset bff route |
| T13 | ✅ Done | `0a639f0` feat(bff-auth): add password change bff route |
| T14 | ✅ Done | `6635aa3` feat(bff-auth): add forgot password form component |
| T15 | ✅ Done | `f491d9e` feat(bff-auth): add reset password form component |
| T16 | ✅ Done | `b04418e` feat(bff-auth): add change password form component |
| T17 | ✅ Done | `65e95fd` feat(bff-auth): add forgot and reset password pages |
| T18 | ✅ Done | `af3bb63` feat(bff-auth): add settings password page |
| T19 | ✅ Done | `d195433` chore(bff-auth): password slice quality gates |

Fix commits after `d195433`: `0bf71d6`, `085a6dd`, `cdf31cd`, `c480ebf`, `e5d040e`, `e150887` (Prettier wrap). All T1–T19 Done-when checkboxes in `tasks.md` remain `[x]`. No blocked or partial tasks.

---

## Spec-Anchored Acceptance Criteria

Re-derived from `spec.md` (evidence-or-zero). Paths below are under `frontend/` unless noted.

### P1: Solicitar recuperação de senha (forgot) via BFF — BFFUI-60, PW-01…03

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Valid POST reset-request + Origin/CSRF pré-auth + upstream `202` | Allowlisted `POST /auth/password/reset-request` with `{ email }`; BFF `202` + envelope pass-through; no session create/alter | `modules/auth/services/bff-password-reset-request.test.ts:106-126` — `status === 202`, `existingBody === ACCEPTED_ENVELOPE`, `ineligibleBody === existingBody`, upstream URL + `JSON.stringify({ email })`; `:126` `expectNoSessionSideEffects()` | ✅ PASS |
| Upstream `202` for ineligible email | Same envelope as eligible (anti-enum) | `:110-111` — `toEqual(ACCEPTED_ENVELOPE)` and `ineligibleBody === existingBody` | ✅ PASS |
| Success headers + no session cookie | `Cache-Control: private, no-store`; no `__Host-fl_session` Set-Cookie | `:135-137` — `Cache-Control === 'private, no-store'`; `setCookies.some(__Host-fl_session) === false` | ✅ PASS |
| Upstream `422 VALIDATION_FAILED` | Repass status and `errors`; no destroy | `:170-173` — `status === 422`, `toMatchObject({ code: 'VALIDATION_FAILED' })`, `expectNoSessionSideEffects()`. Payload includes `errors.email` but matcher does not target `errors` | ⚠️ Spec-precision gap (status/code asserted; `errors` field not targeted) |
| Upstream `429 RATE_LIMIT_EXCEEDED` | Repass `429`, body, `Retry-After` when present | `:213-216` — `status === 429`, `Retry-After === '60'`, `code === 'RATE_LIMIT_EXCEEDED'` | ✅ PASS |
| Origin/CSRF fail | `403` `{ "message": "Forbidden." }` without Laravel | Origin `:299-303`; CSRF `app/api/bff/auth/password/reset-request/route.test.ts:67-69` — `status === 403`, `json === { message: 'Forbidden.' }`, `fetchMock` not called | ✅ PASS |

### P1: UI de forgot password server-first — BFFUI-60, PW-04, PW-05

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET `/forgot-password` | Form with email, pt-BR labels/errors, link "Voltar ao login" → `/login`, shared primitives | Form `modules/auth/components/forgot-password-form.test.tsx:45` label `E-mail`; `:163` link `href="/login"`; page `app/forgot-password/page.test.tsx:62,68` title `Recuperar senha` + form testid. Implementation imports `Button`/`FormField`/`Input` from shared (`forgot-password-form.tsx:14-16`) but tests do not assert those primitives | ✅ PASS (observable) / ⚠️ primitives |
| Page load CSRF pré-auth | `ensurePreAuthCsrfCookies` before successful submit | `app/forgot-password/page.test.tsx:60` — `ensurePreAuthCsrfCookiesMock` called once | ✅ PASS |
| Valid email submit | `POST /api/bff/auth/password/reset-request` with `Content-Type: application/json`, `X-CSRF-Token`, body `{ email }` trim+lowercase | Form `:52-56` — `X-CSRF-Token === 'test-csrf-token'`; `Content-Type` contains `application/json`; `body === { email: email.toLowerCase() }` (`User@Example.COM` → `user@example.com`) | ✅ PASS |
| BFF `202` | Uniform pt-BR success independent of email; no account enumeration | `:49` `status.textContent === FORGOT_PASSWORD_SUCCESS_MESSAGE` (`'Se o e-mail estiver cadastrado, você receberá instruções para redefinir sua senha.'`) for `User@Example.COM` and `nobody@example.com` | ✅ PASS |
| Invalid email | Block submit; no BFF call | `:74-76` accessible description `/e-mail/i`; `fetchSpy` not called | ✅ PASS |
| HTML / fetch | Bearer SHALL NOT appear | `:54-58` `Authorization` null; `innerHTML` / request JSON `not.toContain('Bearer')` | ✅ PASS |

### P1: Concluir reset de senha via BFF — BFFUI-61, BFFUI-63, PW-06…08

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Valid POST reset + pré-auth + upstream `204` | Allowlisted call; `destroySession` if cookie; `clearSessionCookie`; `200` `{ data: { redirect_to: "/login", message: "Senha redefinida. Faça login para continuar." } }` | `modules/auth/services/bff-password-reset.test.ts:134-149` — `status === 200`, `toEqual({ data: { redirect_to: '/login', message: RESET_SUCCESS_MESSAGE } })`, `destroySessionSpy` with `sessionId`, `clearSessionCookieSpy` called, upstream body `VALID_BODY` | ✅ PASS |
| Success headers + cookie | `Cache-Control: private, no-store`; `__Host-fl_session` expired | `:139` Cache-Control; `:142` `/Max-Age=0/i` | ✅ PASS |
| Success JSON sanitization | No Bearer, passwords, or submitted token plaintext | `:203-206` `not.toContain(PASSWORD_SENTINEL \| TOKEN_SENTINEL \| FIXTURE_BEARER \| 'Bearer')` | ✅ PASS |
| Upstream status ≠ `204` | SHALL NOT `destroySession` nor `clearSessionCookie` | Token 422 `:170-173`; PASSWORD_REUSED `:193-194`; 429 `:273-274` — spies not called | ✅ PASS |
| Upstream `422` token field | Repass status+body; no destroy | `:168-171` — `status === 422`, `json === payload` (includes `errors.token`) | ✅ PASS |
| Upstream `422` `PASSWORD_REUSED` | Repass without consuming token / destroy | `:191-194` — `json === payload`, destroy/clear not called | ✅ PASS |

### P1: UI de reset password server-first — BFFUI-61, PW-09…11

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET `/reset-password` | Fields email, token, password, password_confirmation; pt-BR; "Voltar ao login"; shared primitives | Form labels in `modules/auth/components/reset-password-form.test.tsx` (`fillValidForm` `:33-36`); link `:246` `href="/login"`; page title `Redefinir senha`. Implementation uses shared primitives (`reset-password-form.tsx:16-18`); tests do not assert them | ✅ PASS (observable) / ⚠️ primitives |
| `?token=` hydrates without auto-reset | Hydrate field; no reset on load | `:50` input value === `INITIAL_TOKEN`; `:52` `fetchSpy` not called | ✅ PASS |
| Mount with `?token=` | `history.replaceState` drops query `token` | `:64-66` — `replaceSpy` called; URL arg `not.toMatch(/[?&]token=/)` | ✅ PASS |
| Page load CSRF pré-auth | `ensurePreAuthCsrfCookies` | `app/reset-password/page.test.tsx:63` called once | ✅ PASS |
| Valid submit | `POST /api/bff/auth/password/reset` with CSRF and full `ResetPasswordRequest` body | `:94-102` — `X-CSRF-Token === 'test-csrf-token'`; `Content-Type` contains `application/json`; body `{ email: 'user@example.com', token, password, password_confirmation }` (lowercase email) | ✅ PASS |
| BFF `200` + `redirect_to` | Display success message and navigate `/login` | `:90-92` — `status.textContent === RESET_SUCCESS_MESSAGE`; `pushMock('/login')` | ✅ PASS |
| `422 PASSWORD_REUSED` | pt-BR error on `password` | `:156-158` accessible description `'A nova senha deve ser diferente da senha atual.'` | ✅ PASS |
| `422` token error | Uniform `'Link de redefinição inválido ou expirado.'` on token field | `:126-128` | ✅ PASS |
| GET with/without `?token=` | SHALL NOT POST reset during render | `app/reset-password/page.test.tsx:112` `fetchSpy` not called | ✅ PASS |
| RTL mount with `?token=` | Zero fetch until explicit interaction | Form `:52` | ✅ PASS |

### P1: Alterar senha autenticada via BFF — BFFUI-62, BFFUI-63, PW-12…14

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Valid POST change + session CSRF + `kind: session` + upstream `204` | Bearer server-side; `destroySession`; `clearSessionCookie`; `200` `{ data: { redirect_to: "/login", message: "Senha alterada. Faça login para continuar." } }` | `modules/auth/services/bff-password-change.test.ts:124-142` — status 200, exact body, destroy+clear, `Authorization: Bearer ${FIXTURE_BEARER}`, upstream `VALID_BODY` | ✅ PASS |
| Success cookie + no Bearer in response | `__Host-fl_session` Max-Age=0; Bearer absent | `:132` Max-Age; `:387-390` sentinels absent | ✅ PASS |
| Upstream `401 INVALID_CREDENTIALS` | Repass status+body; no destroy | `:156-159` — `status === 401`, `json === payload`, destroy/clear not called | ✅ PASS |
| Upstream `422 PASSWORD_REUSED` | Repass; no destroy | `:210-213` | ✅ PASS |
| `session.kind !== 'session'` | `403` `{ "message": "Forbidden." }` without Laravel | `:357-359`; shared `:162-164` | ✅ PASS |
| Upstream ≠ `204` | SHALL NOT destroy/clear | 401 + 422 + 429 + ACCOUNT_* cases | ✅ PASS |

### P1: UI de change password server-first — BFFUI-62, PW-15…17

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Anonymous GET `/settings/password` | `redirect('/login')` | `app/settings/password/page.test.tsx:57` — `rejects.toThrow('REDIRECT:/login')` | ✅ PASS |
| `verification` session | `redirect('/verify-email')` | `:67` `'REDIRECT:/verify-email'` | ✅ PASS |
| `session` kind | Form `current_password`, `password`, `password_confirmation`, pt-BR, shared primitives | Page `:82,88` title `Alterar senha` + form testid; form labels in `change-password-form.test.tsx` `fillValidForm`. Implementation uses shared primitives (`change-password-form.tsx:15-17`); tests do not assert them | ✅ PASS (observable) / ⚠️ primitives |
| Valid submit | `POST /api/bff/auth/password/change` with session-mode `X-CSRF-Token` and `ChangePasswordRequest` body | `:69-76` — CSRF, `Content-Type` contains `application/json`, body `{ current_password, password, password_confirmation }` | ✅ PASS |
| BFF `200` + `redirect_to` | Success message + navigate `/login` | `:64-66` exact `CHANGE_SUCCESS_MESSAGE`; `pushMock('/login')` | ✅ PASS |
| `401 INVALID_CREDENTIALS` | pt-BR error on `current_password` without leaking extra motive | `:95-99` accessible description `'Senha atual incorreta.'`; login anti-enum `'E-mail ou senha incorretos.'` absent. Spec did **not** freeze the string (generic pt-BR on `current_password`) | ⚠️ Spec-precision gap |
| `422 PASSWORD_REUSED` | pt-BR on `password` | `:126-128` `'A nova senha deve ser diferente da senha atual.'` | ✅ PASS |
| Client validation fail | Block submit; no BFF | `:145,157,170` — fetch not called for empty current, weak password, confirmation mismatch | ✅ PASS |
| HTML / fetch | Passwords and Bearer SHALL NOT appear | `:67` `innerHTML` `not.toContain('Bearer')`. Password sentinels not scanned in HTML (typed values live in inputs) | ✅ PASS (Bearer) / ⚠️ HTML password scan incomplete |

### P1: Validação de entrada e política de senha — PW-18

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Reset/change UI violates `passwordSchema` or confirmation | Block with pt-BR field errors; no BFF | Change form `:153-155` `/pelo menos 12 caracteres/`; `:166-168` `'As senhas não coincidem.'`; reset schema `:67,81` | ✅ PASS |
| Forgot invalid email | Block; no BFF | `forgot-password-form.test.tsx:74-76` | ✅ PASS |
| Reset empty token | Block with field error; no BFF | Schema `reset-password-schema.test.ts:32-38` `'Informe o código de recuperação.'` | ✅ PASS |
| Malformed JSON or invalid Content-Type | `400` generic pt-BR; no Laravel | Reset-request `:271-274` + `:285-288`; reset `:344-347` + `:358-361`; change `:325-329` + `:341-345` — `status === 400`, `{ message: 'Requisição inválida.' }`, `fetchMock` not called | ✅ PASS |
| Extra body keys | Forward only upstream schema fields | Reset-request `:151-155`; reset `:250-253`; change `:309-313` `JSON.parse(body) ===` schema fields only | ✅ PASS |
| Whitespace-only token | Invalid; no silent trim | Schema `:42-49` `'   '` fails; `:54` `' abc '` preserved; BFF reset `:234-238` upstream body keeps `' abc '` | ✅ PASS |

### P1: Guards BFF — PW-19

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Public POST without Origin/CSRF | `403` Forbidden without Laravel | Reset-request `:299-303`; reset `:372-375`; routes CSRF/Origin 403 | ✅ PASS |
| Change without session / invalid CSRF / kind ≠ session | `403` without Laravel | Shared `:106-109`, `:124-125`, `:143-144`, `:162-164`; change `:357-359`, `:373-374` | ✅ PASS |
| Guard failure headers | `Cache-Control: private, no-store` | Reset-request `:301`; shared Origin `:108` and kind `:164` | ✅ PASS |
| CSRF mode | Public pré-auth (`requireSession: false`); change session (`requireSession: true`) | `modules/auth/bff/allowlist.test.ts:97-98`, `:108-109`, `:119-120`; tests use `derivePreAuthCsrfToken` vs `deriveCsrfToken(sessionId)` | ✅ PASS |

### P1: Rate limiting e falhas upstream — BFFUI-32, PW-20, PW-21

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Upstream `429` on any handler in this slice | Repass `429` + body + `Retry-After` when present; no destroy | Reset-request `:213-216`; **reset `:270-274`** (`status === 429`, `Retry-After === '60'`, `code === 'RATE_LIMIT_EXCEEDED'`, destroy/clear not called); **change `:230-234`** same | ✅ PASS |
| Upstream timeout (10s) | `504` generic pt-BR | Reset-request `:226-229`; **reset `:288-293`**; **change `:248-253`** — `status === 504`, `'Não foi possível conectar ao serviço. Tente novamente.'`, no destroy | ✅ PASS |
| Upstream `500` or `503` | Generic pt-BR mirroring status | Reset-request `:240-244` / `:254-258`; **reset `:307-314` / `:328-333`**; **change `:267-274` / `:288-293`** — status 500/503 + `'Algo deu errado. Tente novamente.'`; 500 JSON `not.toContain('Internal stack trace')` | ✅ PASS |
| Upstream `403 ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` | BFF pass-through; UI specific pt-BR for `code` | BFF reset-request `:183-184` / `:198-199`; **change `:173-174` / `:189-190`** `toMatchObject({ code })`. UI all three forms: `'Esta conta está suspensa.'` / `'Esta conta está em processo de exclusão.'` (forgot `:93`, reset `:176`, change `:187`) | ✅ PASS |
| UI `429` | pt-BR limit + temporal guidance if `Retry-After` present | All three forms `'Aguarde cerca de 2 minutos…'` with `Retry-After: 90` (forgot `:135-137`, reset `:218-220`, change `:231-233`). Without header: `'Muitas tentativas. Aguarde antes de tentar novamente.'` (forgot `:154-156`, reset `:237-239`, change `:250-252`) | ✅ PASS |

### P1: Privacidade de senhas e tokens — PW-22

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Handlers/services | Plaintext password / `current_password` / reset token SHALL NOT appear in `console.log`, serialized errors, or client-bundle fixtures | JSON scans reset `:203-206`, change `:387-390`, reset-request `:314-317`. **No `console.log` spy.** Implementation has no `console.log` in password services/forms; absence of logs is not a precise asserted outcome | ⚠️ Spec-precision gap (same class as EV-19 / L-026) |
| Sentinel scan | Asserts scan response JSON and rendered HTML | JSON as above; HTML Bearer `forgot-password-form.test.tsx:57`; pages stringify `not.toContain(FIXTURE_BEARER)` | ✅ PASS |
| Success body | SHALL NOT include submitted passwords/tokens | Reset/change `toEqual({ data: { redirect_to, message } })` plus sentinel `not.toContain` | ✅ PASS |

### P1: UX de sessão restrita — PW-23

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| `verification` on `/forgot-password` or `/reset-password` | SHALL NOT redirect to `/verify-email` | Guard `:60,69` `{ action: 'allow' }`; pages `app/forgot-password/page.test.tsx:80`; `app/reset-password/page.test.tsx:127` `redirectMock` not called | ✅ PASS |
| `VERIFICATION_ALLOWED_PATHS` | Includes `/forgot-password` and `/reset-password` | `modules/auth/lib/verification-guard.test.ts:7-13` exact array including both | ✅ PASS |
| Reset success with `verification` session | Destroy that BFF session | `bff-password-reset.test.ts:209-225` — creates `verification` session; Redis-down still `200` + `clearSessionCookie` / Max-Age=0 (destroy attempted) | ✅ PASS |

### P2: Allowlist, schemas, descoberta — PW-24

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| `AUTH_BFF_ALLOWLIST` | Contains reset-request, reset, change plus existing | `modules/auth/bff/allowlist.test.ts:34-45` length 7 `arrayContaining` three password entries; lookup `:96-120` paths + `requireSession` matrix | ✅ PASS |
| `make test-frontend` discovers slice tests | Tests under `app/api/bff/auth/password/`, pages, schemas run | Gate 2026-08-18 (re-run 2) executed those files (509 passed). `modules/shared/lib/foundation-gates.test.ts:39-42,75-78` lists 3 routes + 3 pages | ✅ PASS |
| Schemas mirror OpenAPI | `PasswordResetRequest` / `ResetPasswordRequest` / `ChangePasswordRequest` | Forgot `:7-8`; reset `:14`; change `:13` | ✅ PASS |

**Catalog IDs:** PW-01…PW-24, BFFUI-60…63, BFFUI-32 mapped through the story ACs above.

**Status**: ⚠️ Spec-precision gaps flagged (no remaining AC ❌). Overall feature **ready** — gate green, sensor 6/6 killed.

---

## Discrimination Sensor

Scratch: `git worktree add /tmp/fake-link-pw-sensor-r2 HEAD` (`e150887`). Mutations applied only there. Focused Vitest via `docker compose -p fake_link … pnpm exec vitest run <file>` from the worktree (compose bind-mounts worktree `./frontend:/app`; named `frontend_node_modules` reused via `-p fake_link`). After each mutant: `git checkout -- <file>`. Worktree removed with `git worktree remove --force`. Feature branch `frontend/` remained clean vs `HEAD`.

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `bff-password-reset.ts:153-156` | Drop `Retry-After` copy on reset 4xx (must stay killed) | ✅ Killed — `Retry-After` expected `'60'` got `null` (`bff-password-reset.test.ts:271`) |
| 2 | `bff-password-reset-request.ts:141-144` | On upstream 202, return `{ message: 'Accepted.' }` instead of pass-through envelope | ✅ Killed — `toEqual(ACCEPTED_ENVELOPE)` (`bff-password-reset-request.test.ts:110`) |
| 3 | `bff-password-reset.ts:169-176` | Skip `destroySession` on upstream 204 (`if (false && session.context)`) | ✅ Killed — `destroySessionSpy.toHaveBeenCalledWith(sessionId)` (`:140`) |
| 4 | `bff-password-shared.ts:40` | Accept any session kind (drop `kind !== 'session'` check) | ✅ Killed — `result.ok === false` (`bff-password-shared.test.ts:158`); change `:357` expected 403 got 200 |
| 5 | `reset-password-schema.ts` token | `.transform((token) => token.trim())` | ✅ Killed — token `' abc '` expected unchanged (`reset-password-schema.test.ts:54`) |
| 6 | `bff-password-change.ts` 4xx | Map upstream 403 to generic `{ message: 'Forbidden.' }` (drop ACCOUNT_* code) | ✅ Killed — `toMatchObject({ code: 'ACCOUNT_SUSPENDED' })` (`:174`) and PENDING_DELETION (`:190`) |

**Sensor depth**: P0-full (≥5 behavior-level mutations; includes previously surviving Retry-After mutant)
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results (if performed)

Not performed (orchestrator will offer to the user).

| # | Test | Result | Details |
| --- | --- | --- | --- |
| — | — | ⏭️ Skip | Interactive UAT not performed |

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ (login/verify BFF services, RHF+Zod forms, `jsonWithPrivateCache`) |
| Spec-anchored outcome check (asserted values match spec) | ⚠️ — remaining precision gaps only (not story blockers) |
| Per-layer Coverage Expectation met (domain 1:1 ACs; routes happy+edge+error) | ✅ |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed: `docs/testing.md` §3.2, §4, §6.1–6.2; `AGENTS.md` | ✅ (Vitest/RTL/MSW in Docker) |

Would a senior engineer approve the implementation? Yes — production code and tests discriminate the prior FAIL gaps; Prettier wrap in `e150887` unblocks the gate.

---

## Edge Cases

- [x] Reset without BFF cookie: public submit still `200` + expired session cookie — `app/api/bff/auth/password/reset/route.test.ts:51-65`
- [x] Invalid `?token=` / 422 token without destroy — `bff-password-reset.test.ts:155-173`
- [x] Uniform token message (covers invalidated previous token UX) — `reset-password-form.test.tsx:126-128`
- [ ] Concurrent two-tab reset (one `200`, other `422` or expired cookie) — **not tested** (same class as EV concurrent edge; not ranked as story AC FAIL)
- [x] Redis `destroySession` fail after `204` still clear cookie + `200` — `bff-password-reset.test.ts:209-225`
- [x] `429` without `Retry-After` → generic limit copy — forgot `:154-156`, reset `:237-239`, change `:250-252`
- [x] Change `PASSWORD_REUSED` without logout — `bff-password-change.test.ts:195-213`
- [x] Change with valid bearer + `ACCOUNT_SUSPENDED` — `bff-password-change.test.ts:163-176` + change form `:187`
- [x] URL-encoded query token decoded once — `app/reset-password/page.test.tsx:71-78` `"initialToken":"hello/world"`
- [x] Trailing whitespace token: no BFF trim — schema `:54` + BFF `:234-238`
- [x] Bearer absent from serialized success JSON / page HTML — sentinel scans cited above

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: **lint ✅** — typecheck + ESLint + `prettier --check` exit 0 (1 pre-existing ESLint warning in `register-schema.test.ts:69` `@typescript-eslint/no-unused-vars`, non-blocking). **tests ✅** — 509 passed, 0 failed, 0 skipped
- **Test count before feature**: 380 (email-verification validation after `65311c7`)
- **Test count after prior FAIL**: 484 at `d195433`; 509 at re-run 1 (`e5d040e`)
- **Test count after feature (this re-run)**: 509
- **Delta**: +129 vs 380 EV baseline; **0** vs re-run 1 (Prettier-only commit). Increased vs feature start; no silent deletion
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans (if issues found)

None. Remaining items are ⚠️ spec-precision gaps (carry-forward; not story blockers). Concurrent two-tab reset remains an untested edge, not an AC FAIL.

---

## Requirement Traceability Update

`spec.md` already lists all catalog IDs as ✅ Verified (uncommitted Specify edits left intact). No status reverted.

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| BFFUI-60 | ✅ Verified | ✅ Verified |
| BFFUI-61 | ✅ Verified | ✅ Verified |
| BFFUI-62 | ✅ Verified | ✅ Verified |
| BFFUI-63 | ✅ Verified | ✅ Verified |
| BFFUI-32 | ✅ Verified | ✅ Verified |
| PW-01 | ✅ Verified | ✅ Verified (⚠️ `errors` matcher) |
| PW-02 | ✅ Verified | ✅ Verified |
| PW-03 | ✅ Verified | ✅ Verified |
| PW-04 | ✅ Verified | ✅ Verified (⚠️ primitives) |
| PW-05 | ✅ Verified | ✅ Verified |
| PW-06 | ✅ Verified | ✅ Verified |
| PW-07 | ✅ Verified | ✅ Verified |
| PW-08 | ✅ Verified | ✅ Verified |
| PW-09 | ✅ Verified | ✅ Verified (⚠️ primitives) |
| PW-10 | ✅ Verified | ✅ Verified |
| PW-11 | ✅ Verified | ✅ Verified |
| PW-12 | ✅ Verified | ✅ Verified |
| PW-13 | ✅ Verified | ✅ Verified |
| PW-14 | ✅ Verified | ✅ Verified |
| PW-15 | ✅ Verified | ✅ Verified |
| PW-16 | ✅ Verified | ✅ Verified (⚠️ primitives) |
| PW-17 | ✅ Verified | ✅ Verified (⚠️ 401 copy not frozen; HTML password scan) |
| PW-18 | ✅ Verified | ✅ Verified |
| PW-19 | ✅ Verified | ✅ Verified |
| PW-20 | ✅ Verified | ✅ Verified |
| PW-21 | ✅ Verified | ✅ Verified |
| PW-22 | ✅ Verified | ✅ Verified (⚠️ no `console.log` spy) |
| PW-23 | ✅ Verified | ✅ Verified |
| PW-24 | ✅ Verified | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 60/67 numbered ACs matched spec outcome | 7 spec-precision gaps (5 types, carry-forward, not story blockers)
**Sensor**: 6/6 mutations killed (including previously surviving reset Retry-After mutant)
**Gate**: lint ✅; tests 509 passed, 0 failed

**What works**: Prior FAIL gaps remain covered with file:line — reset/change 429+Retry-After, 504/500/503, ACCOUNT_* BFF+UI, form CSRF/Content-Type/schema body, change 400+extra-field strip, 429 without Retry-After on all three forms. Prettier wrap in `e150887` unblocks `format:check`. Retry-After mutant stays killed.

**Issues found**: None blocking. Carry-forward ⚠️: loose 422 `errors` matcher; shared primitives not asserted; 401 copy not frozen in spec; HTML password sentinel scan incomplete; no `console.log` spy.

**Next steps**: Interactive UAT (user-facing). Optional spec freeze of 401 copy / primitive names if a later slice wants to close the ⚠️s.
