# BFF Auth — Sessão e shell Validation

**Date**: 2026-08-19  
**Spec**: `.specs/features/bff-auth/session-shell/spec.md`  
**Diff range**: `64f84e0..3bb93d5` (`a94f695..HEAD`; first parent `a94f695` is the password-slice merge; feature commits start at `64f84e0`)  
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

All T1–T21 Done-when checkboxes in `tasks.md` are `[x]`. No blocked or partial tasks.

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

---

## Spec-Anchored Acceptance Criteria

Re-derived from `spec.md` (evidence-or-zero). Paths below are under `frontend/` unless noted.

### P1: Logout da sessão atual via BFF — BFFUI-70, SH-01…05

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| POST logout + Origin/CSRF + kind `session`\|`verification` + upstream `204` | Allowlisted `POST /auth/logout` with server-side `Authorization: Bearer`; `destroySession`; `clearSessionCookie`; `200` `{ data: { redirect_to: "/login", message: "Você saiu da conta." } }` | `modules/auth/services/bff-logout.test.ts:117-139` — `status === 200`, `json === LOGOUT_SUCCESS_BODY`, `destroySessionSpy` with `sessionId`, `clearSessionCookieSpy` called, `fetchMock` URL `http://nginx/api/v1/auth/logout` + `Authorization: Bearer ${FIXTURE_BEARER}`, `body` undefined (extras not forwarded). Verification kind `:151-154` `status === 200` + same envelope | ✅ PASS |
| Logout success headers + cookie | `Cache-Control: private, no-store`; `__Host-fl_session` expired; CSRF cookies expired | `:120` Cache-Control; `:123` `/Max-Age=0/i` on session cookie; `:125-128` CSRF token + sid `Max-Age=0`. Unit `modules/auth/bff/csrf.test.ts:228-231` `clearCsrfCookies` Max-Age=0 | ✅ PASS |
| Success JSON sanitization | No `token`, `Bearer`, `token_kind`, `token_type`, `expires_at`, nor Bearer plaintext | `:322-327` `serialized.not.toContain(FIXTURE_BEARER \| 'Bearer' \| 'token' \| 'token_kind' \| 'token_type' \| 'expires_at')` | ✅ PASS |
| Redis `destroySession` fail + Bearer in memory | Still POST logout; still clear cookie; `200` same envelope; increment `bff_logout_redis_fail_total` | `:169-174` — `status === 200`, `json === LOGOUT_SUCCESS_BODY`, Max-Age=0, `fetchMock` called once, `getLogoutRedisFailCount() === redisBefore + 1`, upstream counter unchanged | ✅ PASS |
| Laravel timeout / 5xx | Still destroy + clear; `200` local success; increment `bff_logout_upstream_fail_total` | Timeout `:190-196`; 5xx `:211-215` — `status === 200`, envelope, destroy, Max-Age=0, `getLogoutUpstreamFailCount() === upstreamBefore + 1` | ✅ PASS |
| Cookie absent / Redis miss (no Bearer) | Require Origin; **not** CSRF; **not** Laravel; `clearSessionCookie` + `clearCsrfCookies`; `200` `redirect_to: "/login"` | Miss with Origin `:223-234` — `status === 200`, envelope, `fetchMock.not.toHaveBeenCalled()`, destroy not called, clear cookie + CSRF Max-Age=0. **CSRF-not-required is not independently asserted** (helper always sends `X-CSRF-Token`). Origin-required on miss shares the same top-level Origin check as `:246-251` (invalid Origin → `403` `{ message: 'Forbidden.' }`, no fetch, no clear) | ⚠️ Spec-precision gap (CSRF-optional on miss) |
| Laravel `401` | Local success destroy+clear+`200`; **without** incrementing upstream fail | `:283-287` — `status === 200`, envelope, destroy called, Max-Age=0, `getLogoutUpstreamFailCount() === upstreamBefore` | ✅ PASS |
| Origin or CSRF fail | `403` `{ "message": "Forbidden." }` without Laravel and without destroy | Origin `:246-251`; CSRF `:263-268` — `status === 403`, `json === { message: 'Forbidden.' }`, fetch/destroy/clear not called, session cookie not Max-Age=0 | ✅ PASS |
| kind `session`\|`verification` | SHALL NOT `403` only because of kind | `:151-154` verification → `200` + envelope + fetch once | ✅ PASS |

### P1: Logout-all com senha via BFF — BFFUI-71, SH-06…09

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| POST logout-all + CSRF/Origin + `kind: session` + `{ current_password }` + upstream `204` | Send only `{ current_password }` to Laravel; destroy; clear cookie; `200` `{ data: { redirect_to: "/login", message: "Todas as sessões foram encerradas. Faça login para continuar." } }` | `modules/auth/services/bff-logout-all.test.ts:124-146` — `status === 200`, `json === LOGOUT_ALL_SUCCESS_BODY`, destroy+clear, Max-Age=0 CSRF, `body: JSON.stringify(VALID_BODY)`, Bearer header. Route `:113-115` same envelope | ✅ PASS |
| Upstream `401 INVALID_CREDENTIALS` | Pass-through status+body **without** destroy/clear | `:159-163` — `status === 401`, `json === payload`, destroy/clear not called, cookie not Max-Age=0 | ✅ PASS |
| `kind !== 'session'` | `403` `{ "message": "Forbidden." }` without Laravel and without evaluating password | `:175-179` — `status === 403`, `json === { message: 'Forbidden.' }`, `fetchMock.not.toHaveBeenCalled()`, destroy/clear not called | ✅ PASS |
| Omit `current_password`, >128, not JSON, or extras | `400` local pt-BR without Laravel and without destroy | Extras `:194-197` `status === 400`, `json === { message: 'Requisição inválida.' }`, zero fetch; malformed JSON `:209-212`; missing Content-Type `:224-227`. Schema empty `:16-17` `'Informe sua senha atual.'`; >128 `:28-30` max-128 message. **Omit-key `{}` and >128 are not asserted on the BFF service with zero fetch** | ⚠️ Spec-precision gap (omit/>128 only at Zod unit) |
| Origin/CSRF fail | `403` without Laravel | Shared loader `modules/auth/services/bff-password-shared.test.ts:106-109` Origin `status === 403`, `json === { message: 'Forbidden.' }`, store.get not called; CSRF `:142-144`. `performBffLogoutAll` starts with `loadSessionMutationContext`. **No `performBffLogoutAll`-level Origin/CSRF test** | ✅ PASS (helper) / ⚠️ integration |
| Upstream `429` | Pass-through status, body, `Retry-After` when present, **without** destroy | `:244-248` — `status === 429`, `Retry-After === '60'`, `code === 'RATE_LIMIT_EXCEEDED'`, destroy/clear not called | ✅ PASS |
| Upstream timeout/5xx | `504`/`5xx` generic **without** destroy/clear | Timeout `:262-267` `status === 504`, `message === 'Não foi possível conectar ao serviço. Tente novamente.'`; 500 `:280-288` `status === 500`, `message === 'Algo deu errado. Tente novamente.'`, stack trace absent | ✅ PASS |
| Upstream `204` + `destroySession` fail | Still `clearSessionCookie` + `200` | `:301-304` — `status === 200`, envelope, `clearSessionCookieSpy` called, Max-Age=0 | ✅ PASS |

### P1: GET e PATCH `/me` via BFF — BFFUI-72, SH-10…13

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET me + `session`\|`verification` | Laravel GET `/me` with Bearer; **no** CSRF/Origin required; pass-through `200` UserResponse; `Cache-Control: private, no-store` | `modules/auth/services/bff-me.test.ts:135-160` — `status === 200`, Cache-Control, `body === USER_ENVELOPE`, GET `http://nginx/api/v1/me` + Bearer. Request helper omits CSRF/Origin | ✅ PASS |
| GET JSON User fields + no Bearer | `data` has OpenAPI User fields; no Bearer | `:139-150` `objectContaining` `id`, `name`, `email`, `status`, `email_verified_at`, `terms_version`, `terms_accepted_at`, `created_at`, `updated_at`; `:297-298` serialized `not.toContain(FIXTURE_BEARER \| 'Bearer')` | ✅ PASS |
| PATCH + CSRF/Origin + `kind: session` + `{ name }` | Send only trimmed `{ name }`; pass-through `200` UserResponse | `:197-211` — `status === 200`, `json === updated`, Cache-Control, `JSON.parse(body) === { name: 'Novo Nome' }` from `'  Novo Nome  '` | ✅ PASS |
| PATCH `kind !== 'session'` | `403` without Laravel | `:272-274` — `status === 403`, `json === { message: 'Forbidden.' }`, `fetchMock.not.toHaveBeenCalled()` | ✅ PASS |
| PATCH `email` or extra fields | `400` local without Laravel; persisted email unchanged | `:233-237` — both `status === 400`, `json === { message: 'Requisição inválida.' }`, `fetchMock.not.toHaveBeenCalled()` | ✅ PASS |
| `name` empty after trim or >120 | Client blocks; BFF `400` without Laravel | BFF `:256-260` empty + 121 chars → `400` + zero fetch. Client `modules/auth/components/profile-form.test.tsx:92-109` accessible descriptions + `fetchSpy.not.toHaveBeenCalled()` | ✅ PASS |
| GET/PATCH without session | `403` `{ "message": "Forbidden." }` without Laravel | GET `:182-184`; PATCH `:282-284` | ✅ PASS |
| PATCH Origin/CSRF fail | `403` without Laravel | Shared `bff-password-shared.test.ts:106-109` / `:142-144`; PATCH uses `loadSessionMutationContext`. **No `performBffMePatch`-level Origin/CSRF test** | ✅ PASS (helper) / ⚠️ integration |
| GET with `verification` | Succeed if upstream `200`; `pending_verification` visible | `:172-174` — `status === 200`, `json === VERIFICATION_USER_ENVELOPE` (`status: 'pending_verification'`) | ✅ PASS |

### P1: UI de perfil (somente nome) — BFFUI-73, SH-14…15

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| GET `/settings` + `kind: session` | Editable name; email visible **not** editable; link `/settings/password` (“Alterar senha”); logout-all section; pt-BR; shared primitives | Page `app/settings/page.test.tsx:151-157` profile `data-name`/`data-email`, logout-all testid, link `href="/settings/password"`. Form `:45-47` name value + only enabled textbox; email `readOnly`. Implementation uses `FormField`/`Input`/`Button` from shared (`profile-form.tsx:14-17`); tests do not assert those primitives | ✅ PASS (observable) / ⚠️ primitives |
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
| Redis flush → `getSession` null on `/settings` | Treat as guest → `/login` without Bearer | Null session `:92-93` `'REDIRECT:/login'`; `performBffMeGetMock.not.toHaveBeenCalled()`. Flush is simulated as null session per Independent Test | ✅ PASS |
| `VERIFICATION_ALLOWED_PATHS` | SHALL **not** include `/settings` | `verification-guard.test.ts:16-17` `not.toContain('/settings')`; full list `:7-13` | ✅ PASS |

### P1: Privacidade de credenciais — SH-21

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Logout-all `current_password` | Plaintext absent from logs, exceptions-to-browser, metrics, HTML | Service `:317` `serialized.not.toContain(CURRENT_PASSWORD_SENTINEL)`; form `:64` `innerHTML.not.toContain(CURRENT_PASSWORD)`. Logs/metrics streams not scanned | ✅ PASS (JSON/HTML) / ⚠️ logs |
| Any handler JSON / Set-Cookie / HTML | Bearer SHALL NOT appear (except opaque session id) | Logout `:322-323`; logout-all `:318-319`; GET me `:297-298`; me route `:149`; settings page `:145-147` | ✅ PASS |
| Tests use sentinels scanning body/HTML/storage | Assert sentinels absent | Same citations; storage simulated not scanned | ✅ PASS (body/HTML) / ⚠️ storage |

### P1: Erros, rate limit e validação — SH-22, SH-23

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| PATCH/logout-all `422 VALIDATION_FAILED` | Map `errors` to pt-BR fields | Profile `:133-136` name description from 422 payload; logout-all `:119-122` `'Informe sua senha atual.'` | ✅ PASS |
| `429` **with** `Retry-After` | Copy includes wait info | Profile `:154-156` `'Aguarde cerca de 2 minutos...'`; logout-all `:142-144` same string | ✅ PASS |
| `429` **without** `Retry-After` | Distinct generic limit copy | Profile `:172-174` `'Muitas tentativas. Aguarde antes de tentar novamente.'`; logout-all `:161-163` same; strings differ from Retry-After copy | ✅ PASS |
| GET/PATCH/logout-all timeout | Handler/UI generic pt-BR `504` | Logout-all handler `:262-264` `status === 504` + gateway message. UI mapper `auth-messages.test.ts:45-48` `messageForAuthError(undefined, 504)` → same string. **`performBffMeGet` / `performBffMePatch` timeout → 504 has no test.** **ProfileForm / LogoutAllForm have no 504 RTL.** | ❌ GAP (GET/PATCH handler) / ⚠️ UI |
| Origin/CSRF fail UI | Generic permission/forbidden copy **without** saying “CSRF” or “Origin” | LogoutButton `:77-81` `'Você não tem permissão para concluir esta ação.'`; HTML `not.toMatch(/CSRF\|Origin/)`; no push. **ProfileForm and LogoutAllForm fall through to `payload.message` (`Forbidden.`) and have no 403 RTL** | ✅ PASS (logout) / ⚠️ PATCH & logout-all UI |

### P2: Allowlist, métricas e descoberta — SH-24…28

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| `AUTH_BFF_ALLOWLIST` four new entries | logout, logout-all, GET me (`requireCsrf: false`), PATCH me; length 11 | `allowlist.test.ts:38-52` `toHaveLength(11)` + arrayContaining four entries; `:141-142` logout CSRF true; `:167-168` GET me `requireCsrf === false`; `:180-181` PATCH CSRF true | ✅ PASS |
| Redis/upstream logout fail getters | Increment reflected without OTel | `metrics.test.ts:41,50` getters `=== before + 2`; isolation `:59-65`. Service wiring SH-02/SH-03 citations above | ✅ PASS |
| `make test-frontend` discovers slice tests | Tests under logout/logout-all/me/settings/schemas run | Gate 2026-08-19 executed those files (604 passed). `foundation-gates.test.ts:32-34,50-56,83-84,96-98` lists routes + `/settings` pages | ✅ PASS |
| Schemas | `name` 1–120; `current_password` max 128 | `update-profile-schema.test.ts:7,34,40`; `logout-all-schema.test.ts:6-8,22,28-30` | ✅ PASS |

**Status**: ❌ Gaps present / ⚠️ Spec-precision gaps flagged

---

## Discrimination Sensor

Scratch state: `git worktree add /tmp/session-shell-sensor HEAD` (detached `3bb93d5`). Real tree never mutated. Discarded with `git worktree remove --force`. Targeted Vitest via `docker compose … pnpm exec vitest run <files>`.

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `modules/auth/services/bff-logout.ts:62` | Redis miss / no session returns `403` instead of local `200` | ✅ Killed — `bff-logout.test.ts:223` `expected 403 to be 200` |
| 2 | `modules/auth/services/bff-logout.ts:84-86` | Removed `incrementLogoutUpstreamFail()` on Laravel 5xx | ✅ Killed — `bff-logout.test.ts:215` `expected 1 to be 2` |
| 3 | `modules/auth/services/bff-logout-all.ts:116` | `destroySession` before checking upstream status (401 still destroys) | ✅ Killed — `bff-logout-all.test.ts:161` `destroySession` called |
| 4 | `modules/auth/services/bff-me.ts:35` | Dropped `.strict()` so PATCH extras/email are stripped and forwarded | ✅ Killed — `bff-me.test.ts:233` `expected 200 to be 400` |
| 5 | `modules/auth/services/bff-me.ts:73` | GET me requires CSRF double-submit | ✅ Killed — `bff-me.test.ts:135` `expected 403 to be 200` |
| 6 | `app/settings/page.tsx:60` | `ACCOUNT_*` 403 no longer redirects (`if (false && …)`) | ✅ Killed — `settings/page.test.tsx:166` promise resolved instead of `'REDIRECT:/login'` |
| 7 | `modules/auth/lib/account-guard.ts:14-16` | Guest on `/settings` returns `allow` | ✅ Killed — `account-guard.test.ts:18` `{ action: 'allow' }` vs `{ redirect, to: '/login' }` |

**Sensor depth**: P0-full (7 behavior-level mutations, auth path)  
**Result**: 7/7 killed — PASS ✅

---

## Interactive UAT Results (if performed)

Not performed — Verifier pass is automated (spec-anchored + sensor + gate). UI is user-facing but interactive UAT was not requested in this verification dispatch.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ Reuses `loadSessionMutationContext`, Zod `.strict()`, `jsonWithPrivateCache`, password-slice form/error patterns |
| Spec-anchored outcome check (asserted values match spec) | ⚠️ GET/PATCH 504 untested; some conjunction clauses only via shared helper |
| Per-layer Coverage Expectation met (domain 1:1 ACs; routes happy+edge+error) | ⚠️ Route handlers are thin delegates; GET/PATCH timeout path uncovered |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed: `docs/testing.md` §3.2 / §4 / §6.2; `AGENTS.md` | ✅ |
| Would senior engineer approve? | ⚠️ Approve services/UI; request GET/PATCH 504 tests + logout-miss CSRF-optional + PATCH/logout-all 403 copy before calling the slice done |

No `// SPEC_DEVIATION` markers in the slice.

---

## Edge Cases

- [x] Second Sair after cookie already cleared → logout BFF `200` local without Laravel (`bff-logout.test.ts:218-234`)
- [x] Two-tab logout; second Laravel `401` treated as local success (`:271-287`)
- [ ] Logout-all and change-password in flight — not specifically tested (each BFF clears cookie only on its own `204`; covered indirectly by destroy-only-on-204)
- [ ] PATCH identical name → `200` UI consistent — no dedicated no-op test (happy-path PATCH `200` exists)
- [x] GET me `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` on RSC `/settings` → destroy + expire cookies + `redirect('/login')` (`settings/page.test.tsx:160-196`)
- [x] `verification` GET me `200` with `pending_verification` (`bff-me.test.ts:163-174`); `/settings` still redirects (`settings/page.test.tsx:113-121`)
- [x] `name` only spaces → client blocks + BFF `400` (`profile-form.test.tsx:96-100`; `bff-me.test.ts:244-260`)
- [x] Logout Laravel `429` → still clear cookie + `200`, no upstream-fail increment (`bff-logout.test.ts:305-309`)
- [x] Logout-all `429` → cookie not cleared (`bff-logout-all.test.ts:247-248`)

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: lint ✅ (typecheck + ESLint + `prettier --check` exit 0; 1 pre-existing ESLint warning in `register-schema.test.ts:69` `@typescript-eslint/no-unused-vars`, non-blocking). tests ✅ — **604 passed, 0 failed, 0 skipped** (87 files)
- **Test count before feature**: 509 (password validation after `e150887`)
- **Test count after feature**: 604
- **Delta**: +95 tests
- **Skipped tests**: none
- **Failures**: none
- **Flake retry**: not needed (first run passed; no RTL 15s timeouts)

---

## Fix Plans (if issues found)

### Fix 1: GET/PATCH me timeout must assert `504` generic pt-BR

- **Root cause**: `performBffMeGet` / `performBffMePatch` catch abort/timeout and return `504` + gateway message, but `bff-me.test.ts` never throws from `fetchMock`. SH-22 AC 4 is therefore uncovered for GET/PATCH (logout-all is covered).
- **Fix task**: Add Vitest cases: GET abort → `504` `{ message: 'Não foi possível conectar ao serviço. Tente novamente.' }`, zero User leak; PATCH abort → same `504`, no partial name update. Optionally RTL ProfileForm/LogoutAllForm `504` shows that copy.
- **Verify**: `pnpm exec vitest run modules/auth/services/bff-me.test.ts`
- **Done when**: SH-22 AC 4 has `file:line` citations for GET and PATCH matching the spec message and status.
- **Priority**: Major

### Fix 2: Logout miss must prove CSRF is not required

- **Root cause**: SH-04 miss case always sends `X-CSRF-Token`. A mutant that required CSRF on miss would survive.
- **Fix task**: Request without CSRF cookie/header + valid Origin + no session → `200` + envelope + no Laravel.
- **Priority**: Major (auth idempotency)

### Fix 3: PATCH and logout-all Origin/CSRF 403 on the service under test

- **Root cause**: Origin/CSRF for those mutations live only in `loadSessionMutationContext` tests (password-change entry). Skipping the loader in `performBffLogoutAll` / `performBffMePatch` while still checking kind would not be killed by this slice’s tests.
- **Fix task**: Invalid Origin and invalid CSRF on `performBffLogoutAll` and `performBffMePatch` → `403` `{ message: 'Forbidden.' }`, `fetchMock` not called, no destroy (logout-all).
- **Priority**: Major

### Fix 4: Generic pt-BR 403 copy on ProfileForm and LogoutAllForm

- **Root cause**: Only `LogoutButton` maps 403 to `'Você não tem permissão para concluir esta ação.'`. The other forms display upstream `{ message: 'Forbidden.' }` and have no RTL.
- **Fix task**: Reuse the logout forbidden copy (or `messageForAuthError` for 403 without code); RTL asserts pt-BR, no “CSRF”/“Origin”, user stays on page.
- **Priority**: Minor

---

## Requirement Traceability Update

Statuses below are Verifier recommendations (spec.md not edited by this pass).

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| BFFUI-70 | Pending | ⚠️ Needs Fix (logout miss CSRF-optional untested; UI Sair otherwise verified) |
| BFFUI-71 | Pending | ⚠️ Needs Fix (service Origin/CSRF via helper only) |
| BFFUI-72 | Pending | ❌ Needs Fix (GET/PATCH timeout 504 untested) |
| BFFUI-73 | Pending | ✅ Verified |
| BFFUI-74 | Pending | ✅ Verified |
| SH-01…05 | Pending | ⚠️ SH-04 CSRF-optional |
| SH-06…09 | Pending | ⚠️ SH-08 Origin/CSRF integration |
| SH-10…13 | Pending | ❌ SH-22 overlap: GET/PATCH 504 |
| SH-14…15 | Pending | ✅ Verified |
| SH-16…17 | Pending | ✅ Verified |
| SH-18…20 | Pending | ✅ Verified |
| SH-21 | Pending | ✅ Verified (JSON/HTML sentinels) |
| SH-22 | Pending | ❌ Needs Fix (GET/PATCH 504) |
| SH-23 | Pending | ✅ Verified |
| SH-24…28 | T1/T2/T5/T6/T21 Done | ✅ Verified |

---

## Summary

**Overall**: ❌ Not Ready

**Spec-anchored check**: 56/57 numbered ACs matched spec outcome | 1 AC gap (GET/PATCH 504) | 6 spec-precision gaps flagged  
**Sensor**: 7/7 mutations killed  
**Gate**: 604 passed, 0 failed

**What works**: Best-effort logout (204/401/429/5xx/timeout/miss/Origin/CSRF/kinds) with envelope values and counters; logout-all destroy-only-on-204 including wrong password and 429 Retry-After; GET/PATCH User envelope, trim, extras `400`, verification GET; `/settings` guest/verification/ACCOUNT_* redirects; home guest vs session chrome; 429 with/without Retry-After; Bearer/password sentinels on JSON/HTML; allowlist of 11; discovery via foundation gates.

**Issues found**: GET/PATCH me timeout is implemented but not asserted (SH-22). Logout miss does not prove CSRF is optional. PATCH/logout-all Origin/CSRF are not asserted on those services. Profile/logout-all 403 UI copy is English `Forbidden.` and untested.

**Next steps**: Implement Fix 1–3 (Major) then re-verify. Do not mark BFFUI-72 / SH-22 verified until GET/PATCH `504` has evidence.
