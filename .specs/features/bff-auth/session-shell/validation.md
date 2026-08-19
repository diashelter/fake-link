# BFF Auth — Sessão e shell Validation

**Date**: 2026-08-19  
**Spec**: `.specs/features/bff-auth/session-shell/spec.md`  
**Diff range**: `64f84e0..HEAD` (`64f84e0..3edf532`; includes fix `3edf532`)  
**Verifier**: independent sub-agent (author ≠ verifier). Fresh pass after `3edf532`. Previous report used only for contrast; coverage re-derived from spec.

---

## Task Completion

All T1–T21 Done-when checkboxes in `tasks.md` are `[x]`. No blocked or partial tasks. Fix commit `3edf532` closed the prior verifier gaps (GET/PATCH 504, logout miss without CSRF, logout-all/PATCH Origin+CSRF 403, ProfileForm/LogoutAllForm 403 copy).

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | `64f84e0` feat(bff-auth): add update profile zod schema |
| T2 | ✅ Done | `a6cb24b` feat(bff-auth): add logout-all zod schema |
| T3 | ✅ Done | `9b43c22` feat(bff-auth): add logout failure metrics hooks |
| T4 | ✅ Done | `6c8223b` feat(bff-auth): clear csrf cookies on logout |
| T5 | ✅ Done | `4cd7af0` feat(bff-auth): add account route guard helper |
| T6 | ✅ Done | `4ff5eea` feat(bff-auth): allowlist logout and me routes |
| T7 | ✅ Done | `146d4de` feat(bff-auth): add best-effort logout service |
| T8 | ✅ Done | `8948b6d` feat(bff-auth): add logout-all bff service |
| T9 | ✅ Done | `a395271` feat(bff-auth): add me get and patch service |
| T10 | ✅ Done | `626f673` feat(bff-auth): add logout route handler |
| T11 | ✅ Done | `6aa0b89` feat(bff-auth): add logout-all route handler |
| T12 | ✅ Done | `b469838` feat(bff-auth): add me route handler |
| T13 | ✅ Done | `9081bd9` feat(bff-auth): add logout button |
| T14 | ✅ Done | `8af6254` feat(bff-auth): add logout-all form |
| T15 | ✅ Done | `ca68c28` feat(bff-auth): add profile name form |
| T16 | ✅ Done | `e8c77d1` feat(bff-auth): add authenticated shell nav |
| T17 | ✅ Done | `e504ea3` feat(bff-auth): add settings profile page |
| T18 | ✅ Done | `6149b5d` feat(bff-auth): add authenticated home shell |
| T19 | ✅ Done | `8e3a104` feat(bff-auth): add logout to verify-email page |
| T20 | ✅ Done | `61d6351` feat(bff-auth): wrap password settings in account shell |
| T21 | ✅ Done | `3bb93d5` test(bff-auth): allow session-shell routes in foundation gates |
| Fix | ✅ Done | `3edf532` fix(bff-auth): close session-shell verification coverage gaps |

---

## Spec-Anchored Acceptance Criteria

Re-derived from `spec.md` (evidence-or-zero). Paths below are under `frontend/` unless noted.

Prior FAIL items re-checked on `3edf532` (not inherited):

1. GET/PATCH `/me` abort → `504` `{ message: 'Não foi possível conectar ao serviço. Tente novamente.' }` — `bff-me.test.ts:313-316` and `:332-335`.
2. Logout miss without CSRF header/cookie + valid Origin → `200` + no Laravel — `bff-logout.test.ts:244-253` (`includeCsrf: false`).
3. `performBffLogoutAll` / `performBffMePatch` invalid Origin and invalid CSRF → `403` `{ message: 'Forbidden.' }`, zero fetch, no destroy — `bff-logout-all.test.ts:191-211`; `bff-me.test.ts:347-363`.
4. ProfileForm and LogoutAllForm 403 → `'Você não tem permissão para concluir esta ação.'`, no CSRF/Origin in HTML, no navigation — `profile-form.test.tsx:190-196`; `logout-all-form.test.tsx:196-203`.

### P1: Logout da sessão atual via BFF — BFFUI-70, SH-01…05

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| POST logout + Origin/CSRF + kind `session`\|`verification` + upstream `204` | Allowlisted `POST /auth/logout` with server-side `Authorization: Bearer`; `destroySession`; `clearSessionCookie`; `200` `{ data: { redirect_to: "/login", message: "Você saiu da conta." } }` | `modules/auth/services/bff-logout.test.ts:125-146` — `status === 200`, `json === LOGOUT_SUCCESS_BODY`, `destroySessionSpy` with sessionId, `clearSessionCookieSpy`, fetch URL `http://nginx/api/v1/auth/logout` + `Authorization: Bearer ${FIXTURE_BEARER}`, `body` undefined. Verification kind `:158-161` `status === 200` + same envelope | ✅ PASS |
| Logout success headers + cookie | `Cache-Control: private, no-store`; `__Host-fl_session` expired; CSRF cookies expired | `:127` Cache-Control; `:130` `/Max-Age=0/i` on session cookie; `:132-135` CSRF token + sid `Max-Age=0`. Unit `modules/auth/bff/csrf.test.ts:228-231` `clearCsrfCookies` Max-Age=0 | ✅ PASS |
| Success JSON sanitization | No `token`, `Bearer`, `token_kind`, `token_type`, `expires_at`, nor Bearer plaintext | `:341-346` `serialized.not.toContain(FIXTURE_BEARER \| 'Bearer' \| 'token' \| 'token_kind' \| 'token_type' \| 'expires_at')` | ✅ PASS |
| Redis `destroySession` fail + Bearer in memory | Still POST logout; still clear cookie; `200` same envelope; increment `bff_logout_redis_fail_total` | `:176-181` — `status === 200`, envelope, Max-Age=0, `fetchMock` once, `getLogoutRedisFailCount() === redisBefore + 1`, upstream counter unchanged | ✅ PASS |
| Laravel timeout / 5xx | Still destroy + clear; `200` local success; increment `bff_logout_upstream_fail_total` | Timeout `:197-202`; 5xx `:218-222` — `status === 200`, envelope, destroy, Max-Age=0, `getLogoutUpstreamFailCount() === upstreamBefore + 1` | ✅ PASS |
| Cookie absent / Redis miss (no Bearer) | Require Origin; **not** CSRF; **not** Laravel; `clearSessionCookie` + `clearCsrfCookies`; `200` `redirect_to: "/login"` | Miss `:230-241` — `status === 200`, envelope, `fetchMock.not.toHaveBeenCalled()`, destroy not called, clear cookie + CSRF Max-Age=0. CSRF-optional `:249-253` — `makeRequest({ includeCsrf: false })`, `status === 200`, envelope, zero fetch, `clearSessionCookieSpy` called. Origin-required `:265-270` invalid Origin → `403` `{ message: 'Forbidden.' }`, no fetch, no clear | ✅ PASS |
| Laravel `401` | Local success destroy+clear+`200`; **without** incrementing upstream fail | `:302-306` — `status === 200`, envelope, destroy called, Max-Age=0, `getLogoutUpstreamFailCount() === upstreamBefore` | ✅ PASS |
| Origin or CSRF fail | `403` `{ "message": "Forbidden." }` without Laravel and without destroy | Origin `:265-270`; CSRF `:282-287` — `status === 403`, `json === { message: 'Forbidden.' }`, fetch/destroy/clear not called, session cookie not Max-Age=0 | ✅ PASS |
| kind `session`\|`verification` | SHALL NOT `403` only because of kind | `:158-161` verification → `200` + envelope + fetch once | ✅ PASS |

### P1: Logout-all com senha via BFF — BFFUI-71, SH-06…09

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| POST logout-all + CSRF/Origin + `kind: session` + `{ current_password }` + upstream `204` | Send only `{ current_password }` to Laravel; destroy; clear cookie; `200` `{ data: { redirect_to: "/login", message: "Todas as sessões foram encerradas. Faça login para continuar." } }` | `modules/auth/services/bff-logout-all.test.ts:125-146` — `status === 200`, `json === LOGOUT_ALL_SUCCESS_BODY`, destroy+clear, Max-Age=0 CSRF, `body: JSON.stringify(VALID_BODY)`, Bearer header | ✅ PASS |
| Upstream `401 INVALID_CREDENTIALS` | Pass-through status+body **without** destroy/clear | `:159-163` — `status === 401`, `json === payload`, destroy/clear not called, cookie not Max-Age=0 | ✅ PASS |
| `kind !== 'session'` | `403` `{ "message": "Forbidden." }` without Laravel and without evaluating password | `:175-179` — `status === 403`, `json === { message: 'Forbidden.' }`, `fetchMock.not.toHaveBeenCalled()`, destroy/clear not called | ✅ PASS |
| Omit `current_password`, >128, not JSON, or extras | `400` local pt-BR without Laravel and without destroy | Extras `:226-229` `status === 400`, `json === { message: 'Requisição inválida.' }`, zero fetch; malformed JSON `:241-244`; missing Content-Type `:256-259`. Schema empty `:16-17` `'Informe sua senha atual.'`; >128 `:28-30` max-128 message. Service parse uses `logoutAllSchema.strict().safeParse` (same `if (!parsed)` 400 branch as extras) | ✅ PASS |
| Origin/CSRF fail | `403` without Laravel | Origin `:191-195` `status === 403`, `{ message: 'Forbidden.' }`, zero fetch, no destroy; CSRF `:207-211` same | ✅ PASS |
| Upstream `429` | Pass-through status, body, `Retry-After` when present, **without** destroy | `:276-280` — `status === 429`, `Retry-After === '60'`, `code === 'RATE_LIMIT_EXCEEDED'`, destroy/clear not called | ✅ PASS |
| Upstream timeout/5xx | `504`/`5xx` generic **without** destroy/clear | Timeout `:294-299` `status === 504`, `message === 'Não foi possível conectar ao serviço. Tente novamente.'`; 500 `:313-320` `status === 500`, `message === 'Algo deu errado. Tente novamente.'`, stack trace absent | ✅ PASS |
| Upstream `204` + `destroySession` fail | Still `clearSessionCookie` + `200` | `:333-336` — `status === 200`, envelope, `clearSessionCookieSpy` called, Max-Age=0 | ✅ PASS |

### P1: GET e PATCH `/me` via BFF — BFFUI-72, SH-10…13

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET me + `session`\|`verification` | Laravel GET `/me` with Bearer; **no** CSRF/Origin required; pass-through `200` UserResponse; `Cache-Control: private, no-store` | `modules/auth/services/bff-me.test.ts:135-160` — `status === 200`, Cache-Control, `body === USER_ENVELOPE`, GET `http://nginx/api/v1/me` + Bearer. `makeGetRequest` omits CSRF/Origin by default | ✅ PASS |
| GET JSON User fields + no Bearer | `data` has OpenAPI User fields; no Bearer | `:139-150` `objectContaining` `id`, `name`, `email`, `status`, `email_verified_at`, `terms_version`, `terms_accepted_at`, `created_at`, `updated_at`; `:297-298` serialized `not.toContain(FIXTURE_BEARER \| 'Bearer')` | ✅ PASS |
| PATCH + CSRF/Origin + `kind: session` + `{ name }` | Send only trimmed `{ name }`; pass-through `200` UserResponse | `:197-211` — `status === 200`, `json === updated`, Cache-Control, `JSON.parse(body) === { name: 'Novo Nome' }` from `'  Novo Nome  '` | ✅ PASS |
| PATCH `kind !== 'session'` | `403` without Laravel | `:272-274` — `status === 403`, `json === { message: 'Forbidden.' }`, `fetchMock.not.toHaveBeenCalled()` | ✅ PASS |
| PATCH `email` or extra fields | `400` local without Laravel; persisted email unchanged | `:233-237` — both `status === 400`, `json === { message: 'Requisição inválida.' }`, `fetchMock.not.toHaveBeenCalled()` | ✅ PASS |
| `name` empty after trim or >120 | Client blocks; BFF `400` without Laravel | BFF `:256-260` empty + 121 chars → `400` + zero fetch. Client `modules/auth/components/profile-form.test.tsx:92-109` accessible descriptions + `fetchSpy.not.toHaveBeenCalled()` | ✅ PASS |
| GET/PATCH without session | `403` `{ "message": "Forbidden." }` without Laravel | GET `:182-184`; PATCH `:282-284` | ✅ PASS |
| PATCH Origin/CSRF fail | `403` without Laravel | Origin `:347-349`; CSRF `:361-363` — `status === 403`, `{ message: 'Forbidden.' }`, `fetchMock.not.toHaveBeenCalled()` | ✅ PASS |
| GET with `verification` | Succeed if upstream `200`; `pending_verification` visible | `:172-174` — `status === 200`, `json === VERIFICATION_USER_ENVELOPE` (`status: 'pending_verification'`) | ✅ PASS |

### P1: UI de perfil (somente nome) — BFFUI-73, SH-14…15

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET `/settings` + `kind: session` | Editable name; email visible **not** editable; link `/settings/password` (“Alterar senha”); logout-all section; pt-BR; shared primitives | Page `app/settings/page.test.tsx:151-157` profile `data-name`/`data-email`, logout-all testid, link `href="/settings/password"`. Form `:45-47` name value + only enabled textbox; email `readOnly` | ✅ PASS |
| Page load hydrates `name` and `email` from GET me | Form values from User | `:152-153` `data-name === FIXTURE_USER.name`, `data-email === FIXTURE_USER.email` | ✅ PASS |
| Valid name submit | `PATCH /api/bff/auth/me` with `Content-Type: application/json`, `X-CSRF-Token`, body `{ name }` trim | `profile-form.test.tsx:75-79` — CSRF token, Content-Type contains `application/json`, `json === { name: UPDATED_NAME }` after typing padded spaces | ✅ PASS |
| PATCH `200` | UI shows new name; displayed email unchanged | `:72-74` name value `UPDATED_NAME`; email still `INITIAL_EMAIL` | ✅ PASS |
| Zod fail | Field errors pt-BR; no BFF | `:92-109` `'Informe seu nome.'` / max-120 copy; fetch not called | ✅ PASS |
| HTML/fetch | Bearer SHALL NOT appear | `:80` `innerHTML.not.toContain('Bearer')`; page `:145-147` serialized page `not.toContain('Bearer' \| FIXTURE_BEARER)` | ✅ PASS |

### P1: UI de logout e logout-all — BFFUI-70/71, SH-16…17

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Shell visible | “Sair” POSTs `/api/bff/auth/logout` with JSON + `X-CSRF-Token` | `logout-button.test.tsx:54-62` — `pushMock('/login')`, CSRF header, Content-Type json, `json === {}`. Shell `:28` button “Sair” | ✅ PASS |
| Logout BFF `200` | `router.push('/login')` with **no** `?message=` query | `:54-57` `toHaveBeenCalledWith('/login')`, times 1, first arg `not.toContain('?message=')` | ✅ PASS |
| `/settings` + session | Logout-all form with `current_password`; POST Content-Type + CSRF | `logout-all-form.test.tsx:65-71` CSRF, Content-Type, `json === { current_password }`; page `:154` logout-all testid | ✅ PASS |
| Logout-all `200` | `router.push('/login')` immediately, no flash query | `:60-63` `push('/login')`, `not.toContain('?message=')` | ✅ PASS |
| `401 INVALID_CREDENTIALS` | pt-BR field error on password; remain on `/settings` | `:90-94` accessible description `'Senha atual incorreta.'`; `pushMock.not.toHaveBeenCalled()` | ✅ PASS |
| `verification` → `/settings` redirects `/verify-email`; shell has Sair; `/verify-email` includes `LogoutButton` | Redirect + Sair on verify-email | Settings `:110` `'REDIRECT:/verify-email'`; `verify-email-form.test.tsx:53` button “Sair”; home session shell `:85` Sair | ✅ PASS |

### P1: Guards de rota autenticada / restrita — BFFUI-74, SH-18…20

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Guest `/settings` | `redirect('/login')` | `account-guard.test.ts:18-21` `{ action: 'redirect', to: '/login' }`; `app/settings/page.test.tsx:92` `'REDIRECT:/login'` | ✅ PASS |
| `verification` on `/`, `/settings`, `/settings/password` | `redirect('/verify-email')` | Guard `:32-34` / `:56-59`; settings `:110`; home `app/page.test.tsx:50` `'REDIRECT:/verify-email'`; password page `:74` | ✅ PASS |
| `session` on `/verify-email` | `redirect('/')` | Guard `:63-66`; `app/verify-email/page.test.tsx:70-72` `'REDIRECT:/'` | ✅ PASS |
| `session` on `/` | Authenticated shell (nav + placeholder), not guest “Começar” as only chrome | `app/page.test.tsx:83-87` Início `/`, Conta `/settings`, Sair, `/em breve/i`, `queryByRole('Começar')` null | ✅ PASS |
| Guest `/` | Public landing, no forced login | `:59-67` no redirect; “Começar” link; Conta/Sair absent | ✅ PASS |
| Redis flush → `getSession` null on `/settings` | Treat as guest → `/login` without Bearer | Null session `:92-93` `'REDIRECT:/login'`; `performBffMeGetMock.not.toHaveBeenCalled()`. Flush simulated as null session per Independent Test | ✅ PASS |
| `VERIFICATION_ALLOWED_PATHS` | SHALL **not** include `/settings` | `verification-guard.test.ts:16-17` `not.toContain('/settings')`; full list `:7-13` | ✅ PASS |

### P1: Privacidade de credenciais — SH-21

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Logout-all `current_password` | Plaintext absent from logs, exceptions-to-browser, metrics, HTML | Independent Test requires `JSON.stringify(response)`: service `:349` `serialized.not.toContain(CURRENT_PASSWORD_SENTINEL)`; form `:64` `innerHTML.not.toContain(CURRENT_PASSWORD)` | ✅ PASS |
| Any handler JSON / Set-Cookie / HTML | Bearer SHALL NOT appear (except opaque session id) | Logout `:341-342`; logout-all `:350-351`; GET me `:297-298`; settings page `:145-147` | ✅ PASS |
| Tests use sentinels scanning body/HTML/storage | Assert sentinels absent | Same citations; Independent Test is JSON/HTML body scan | ✅ PASS |

### P1: Erros, rate limit e validação — SH-22, SH-23

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| PATCH/logout-all `422 VALIDATION_FAILED` | Map `errors` to pt-BR fields | Profile `:133-136` name description from 422 payload; logout-all `:119-122` `'Informe sua senha atual.'` | ✅ PASS |
| `429` **with** `Retry-After` | Copy includes wait info | Profile `:154-156` `'Aguarde cerca de 2 minutos...'`; logout-all `:142-144` same string | ✅ PASS |
| `429` **without** `Retry-After` | Distinct generic limit copy | Profile `:172-174` `'Muitas tentativas. Aguarde antes de tentar novamente.'`; logout-all `:161-163` same; strings differ from Retry-After copy | ✅ PASS |
| GET/PATCH/logout-all timeout | Handler/UI generic pt-BR `504` | GET `:313-316` `status === 504` + gateway message, no Bearer/email leak; PATCH `:332-335` same status+message; logout-all `:294-296` same. Mapper `auth-messages.test.ts:45-48` `messageForAuthError(undefined, 504)` → same string. Forms display `payload.message` from BFF 504 | ✅ PASS |
| Origin/CSRF fail UI | Generic permission/forbidden copy **without** saying “CSRF” or “Origin” | LogoutButton `:77-81` `'Você não tem permissão para concluir esta ação.'`; HTML `not.toMatch(/CSRF\|Origin/)`; no push. ProfileForm `:190-196` same copy, no `Forbidden.`, no CSRF/Origin in HTML. LogoutAllForm `:196-203` same copy + `pushMock.not.toHaveBeenCalled()` | ✅ PASS |

### P2: Allowlist, métricas e descoberta — SH-24…28

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| `AUTH_BFF_ALLOWLIST` four new entries | logout, logout-all, GET me (`requireCsrf: false`), PATCH me; length 11 | `allowlist.test.ts:38-52` `toHaveLength(11)` + arrayContaining four entries; `:141-142` logout CSRF true; `:167-168` GET me `requireCsrf === false`; `:180-181` PATCH CSRF true | ✅ PASS |
| Redis/upstream logout fail getters | Increment reflected without OTel | `metrics.test.ts:41,50` getters `=== before + 2`; isolation `:59-65`. Service wiring SH-02/SH-03 citations above | ✅ PASS |
| `make test-frontend` discovers slice tests | Tests under logout/logout-all/me/settings/schemas run | Gate 2026-08-19 executed those files (613 passed). `foundation-gates.test.ts:32-34,83-84,96-98` lists routes + `/settings` pages | ✅ PASS |
| Schemas | `name` 1–120; `current_password` max 128 | `update-profile-schema.test.ts:7,34,40`; `logout-all-schema.test.ts:6-8,22,28-30` | ✅ PASS |

**Status**: ✅ All ACs covered

---

## Discrimination Sensor

Scratch state: `git worktree add /tmp/session-shell-sensor-reverify HEAD` (detached `3edf532`). Real tree never mutated. Discarded with `git worktree remove --force`. Targeted Vitest via `docker compose … pnpm exec vitest run <files>` from the worktree (`COMPOSE_PROJECT_NAME=fake_link`). Includes mutations on the newly added 504 / CSRF-miss / 403 paths.

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `modules/auth/services/bff-me.ts:91-93` | GET abort catch returns `200` instead of `504` | ✅ Killed — `bff-me.test.ts:313` `expected 200 to be 504` |
| 2 | `modules/auth/services/bff-logout.ts:62` | Redis miss requires CSRF (`validateCsrfDoubleSubmit` before local `200`) | ✅ Killed — `bff-logout.test.ts:230` and `:249` (`includeCsrf: false`) `expected 403 to be 200` |
| 3 | `modules/auth/bff/origin.ts:24-26` | Invalid Origin treated as `ok: true` | ✅ Killed — `bff-logout-all.test.ts:191` and `bff-me.test.ts:347` `expected 200 to be 403` |
| 4 | `modules/auth/components/profile-form.tsx:99-101` | Removed 403 special-case; UI shows English `Forbidden.` | ✅ Killed — `profile-form.test.tsx:190` expected permission copy, received `Forbidden.` |
| 5 | `modules/auth/services/bff-logout-all.ts:116` | `destroySession` before checking upstream status | ✅ Killed — `bff-logout-all.test.ts:161` `destroySession` called on 401 |
| 6 | `modules/auth/services/bff-me.ts:139-142` | PATCH abort catch returns `502` instead of `504` | ✅ Killed — `bff-me.test.ts:332` `expected 502 to be 504` |

**Sensor depth**: P0-full (6 behavior-level mutations, auth path; ≥5 required)  
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results (if performed)

Not performed — Verifier pass is automated (spec-anchored + sensor + gate). UI is user-facing but interactive UAT was not requested in this verification dispatch.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ Fix `3edf532` adds the missing assertions plus the 403 pt-BR mapping on ProfileForm/LogoutAllForm |
| No scope creep | ✅ |
| Matches patterns | ✅ Reuses `loadSessionMutationContext`, Zod `.strict()`, `jsonWithPrivateCache`, password-slice form/error patterns |
| Spec-anchored outcome check (asserted values match spec) | ✅ Prior gaps now assert exact status, envelope, and copy |
| Per-layer Coverage Expectation met (domain 1:1 ACs; routes happy+edge+error) | ✅ Route handlers remain thin delegates; services cover happy+edge+error including GET/PATCH timeout |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed: `docs/testing.md` §3.2 / §4 / §6.2; `AGENTS.md` | ✅ |
| Would senior engineer approve? | ✅ |

No `// SPEC_DEVIATION` markers in the slice.

---

## Edge Cases

- [x] Second Sair after cookie already cleared → logout BFF `200` local without Laravel (`bff-logout.test.ts:225-241` and CSRF-optional `:244-253`)
- [x] Two-tab logout; second Laravel `401` treated as local success (`:290-306`)
- [ ] Logout-all and change-password in flight — not specifically tested (each BFF clears cookie only on its own `204`; covered indirectly by destroy-only-on-204)
- [ ] PATCH identical name → `200` UI consistent — no dedicated no-op test (happy-path PATCH `200` exists)
- [x] GET me `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` on RSC `/settings` → destroy + expire cookies + `redirect('/login')` (`settings/page.test.tsx:160-196`)
- [x] `verification` GET me `200` with `pending_verification` (`bff-me.test.ts:163-174`); `/settings` still redirects (`settings/page.test.tsx:113-121`)
- [x] `name` only spaces → client blocks + BFF `400` (`profile-form.test.tsx:96-100`; `bff-me.test.ts:244-260`)
- [x] Logout Laravel `429` → still clear cookie + `200`, no upstream-fail increment (`bff-logout.test.ts:324-328`)
- [x] Logout-all `429` → cookie not cleared (`bff-logout-all.test.ts:279-280`)

Unchecked concurrency/no-op edges are listed in the spec as product notes, not numbered ACs; they do not block verification.

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: lint ✅ (typecheck + ESLint + `prettier --check` exit 0; 1 pre-existing ESLint warning in `register-schema.test.ts:69` `@typescript-eslint/no-unused-vars`, non-blocking). tests ✅ — **613 passed, 0 failed, 0 skipped** (87 files)
- **Test count before feature**: 509 (password validation after `e150887`)
- **Test count after feature**: 613
- **Delta**: +104 tests (+9 vs prior 604 after `3edf532`)
- **Skipped tests**: none
- **Failures**: none
- **Flake retry**: not needed (first run passed; no RTL 15s timeouts)

---

## Fix Plans (if issues found)

None. Prior Fix 1–4 from the `64f84e0..3bb93d5` report are closed by `3edf532` with `file:line` evidence above.

---

## Requirement Traceability Update

Statuses below are Verifier recommendations (spec.md not edited by this pass).

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| BFFUI-70 | ⚠️ Needs Fix (logout miss CSRF-optional) | ✅ Verified |
| BFFUI-71 | ⚠️ Needs Fix (service Origin/CSRF via helper only) | ✅ Verified |
| BFFUI-72 | ❌ Needs Fix (GET/PATCH timeout 504) | ✅ Verified |
| BFFUI-73 | ✅ Verified | ✅ Verified |
| BFFUI-74 | ✅ Verified | ✅ Verified |
| SH-01…05 | ⚠️ SH-04 CSRF-optional | ✅ Verified |
| SH-06…09 | ⚠️ SH-08 Origin/CSRF integration | ✅ Verified |
| SH-10…13 | ❌ GET/PATCH 504 | ✅ Verified |
| SH-14…15 | ✅ Verified | ✅ Verified |
| SH-16…17 | ✅ Verified | ✅ Verified |
| SH-18…20 | ✅ Verified | ✅ Verified |
| SH-21 | ✅ Verified (JSON/HTML sentinels) | ✅ Verified |
| SH-22 | ❌ Needs Fix (GET/PATCH 504) | ✅ Verified |
| SH-23 | ⚠️ Profile/logout-all 403 copy | ✅ Verified |
| SH-24…28 | ✅ Verified | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 57/57 numbered ACs matched spec outcome | 0 spec-precision gaps  
**Sensor**: 6/6 mutations killed  
**Gate**: 613 passed, 0 failed

**What works**: Best-effort logout (204/401/429/5xx/timeout/miss without CSRF/Origin/CSRF fail/kinds) with envelope values and counters; logout-all destroy-only-on-204 including wrong password, Origin/CSRF 403, and 429 Retry-After; GET/PATCH User envelope, trim, extras `400`, verification GET, GET/PATCH abort `504`; `/settings` guest/verification/ACCOUNT_* redirects; home guest vs session chrome; 429 with/without Retry-After; 403 generic pt-BR on LogoutButton, ProfileForm, and LogoutAllForm; Bearer/password sentinels on JSON/HTML; allowlist of 11; discovery via foundation gates.

**Issues found**: none

**Next steps**: Mark BFFUI-70…74 and SH-01…28 verified in spec traceability when the maintainer next updates `spec.md`.
