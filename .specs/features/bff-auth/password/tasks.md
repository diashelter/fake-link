# BFF Auth — Senha — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/password/design.md`  
**Spec**: `.specs/features/bff-auth/password/spec.md`  
**Status**: Approved — 2026-08-18

> **Sub-agent note:** 18 tasks → ~3 batches (Batch 1: T1–T7 ~7 tasks; Batch 2: T8–T14 ~7 tasks; Batch 3: T15–T18 ~4 tasks). Execute MUST offer batch sub-agents before implementation if user accepts.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL/MSW Route Handlers), §4 (domínios frontend ≥75%, Auth/BFF ≥80%), §6.1 (reset POST-only, scanner GET), §6.2 (Bearer absent, CSRF), `.specs/features/bff-auth/password/spec.md`, amostras `frontend/app/api/bff/auth/login/route.test.ts`, `frontend/modules/auth/services/bff-verify-email.test.ts`, `frontend/modules/auth/components/login-form.test.tsx`, `frontend/modules/auth/schemas/password-schema.test.ts`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Forgot password Zod schema | unit | PW-18; `email` required/format; lowercase transform | `frontend/modules/auth/schemas/forgot-password-schema.test.ts` | `make test-frontend` |
| Reset password Zod schema | unit | PW-18; token minLength 1 sem trim; passwordSchema; confirmation match | `frontend/modules/auth/schemas/reset-password-schema.test.ts` | `make test-frontend` |
| Change password Zod schema | unit | PW-18; current_password bounds; passwordSchema; confirmation match | `frontend/modules/auth/schemas/change-password-schema.test.ts` | `make test-frontend` |
| Validation field errors helper | unit | PW-11, PW-17; PASSWORD_REUSED; token uniform message; FieldError object/string | `frontend/modules/auth/lib/validation-errors.test.ts` | `make test-frontend` |
| Verification guard (recovery paths) | unit | PW-23; `/forgot-password`, `/reset-password` allow; regressão paths existentes | `frontend/modules/auth/lib/verification-guard.test.ts` | `make test-frontend` |
| Session mutation loader (change) | unit | PW-13, PW-19; kind !== session → 403; guard failures sem upstream | `frontend/modules/auth/services/bff-password-shared.test.ts` | `make test-frontend` |
| BFF reset-request service | unit | PW-01–03, PW-19–21; 202 pass-through idêntico; zero session side effects | `frontend/modules/auth/services/bff-password-reset-request.test.ts` | `make test-frontend` |
| BFF reset service | unit | PW-06–08, PW-19–22; 204→200 destroy+clear; erros sem destroy; Bearer absent | `frontend/modules/auth/services/bff-password-reset.test.ts` | `make test-frontend` |
| BFF change service | unit | PW-12–14, PW-19–22; 204→200 destroy; 401/422 sem destroy; kind guard | `frontend/modules/auth/services/bff-password-change.test.ts` | `make test-frontend` |
| Allowlist prod entries | unit | PW-24; três entradas password; requireSession matrix | `frontend/modules/auth/bff/allowlist.test.ts` | `make test-frontend` |
| Reset-request Route Handler | unit (Route Handler) | PW-01–03, PW-19; integration via service spy/mock | `frontend/app/api/bff/auth/password/reset-request/route.test.ts` | `make test-frontend` |
| Reset Route Handler | unit (Route Handler) | PW-06–08, PW-19; integration via service | `frontend/app/api/bff/auth/password/reset/route.test.ts` | `make test-frontend` |
| Change Route Handler | unit (Route Handler) | PW-12–14, PW-19; integration via service | `frontend/app/api/bff/auth/password/change/route.test.ts` | `make test-frontend` |
| ForgotPasswordForm | unit (RTL) | PW-04–05, PW-20; anti-enum MSW; validação email | `frontend/modules/auth/components/forgot-password-form.test.tsx` | `make test-frontend` |
| ResetPasswordForm | unit (RTL) | PW-09–11, PW-15; scanner-safe mount; replaceState; PASSWORD_REUSED; token error | `frontend/modules/auth/components/reset-password-form.test.tsx` | `make test-frontend` |
| ChangePasswordForm | unit (RTL) | PW-16–17, PW-20; 401 current_password; PASSWORD_REUSED; policy client | `frontend/modules/auth/components/change-password-form.test.tsx` | `make test-frontend` |
| Forgot password page | unit (RTL/node) | PW-04; CSRF bootstrap; render público | `frontend/app/forgot-password/page.test.tsx` | `make test-frontend` |
| Reset password page | unit (RTL/node) | PW-09–10, PW-23; initialToken; zero fetch on render | `frontend/app/reset-password/page.test.tsx` | `make test-frontend` |
| Settings password page | unit (RTL/node) | PW-15; redirect matrix session/verification/anonymous | `frontend/app/settings/password/page.test.tsx` | `make test-frontend` |
| Foundation gates | unit | PW-24; permite rotas/pages password produto | `frontend/modules/shared/lib/foundation-gates.test.ts` | `make test-frontend` |
| Quality gates | none | — Makefile gate | — | `make lint-frontend && make test-frontend-coverage` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks com unit tests isolados (T1–T9) | `make test-frontend` |
| Full | Após Route Handlers + forms (T10–T15) | `make test-frontend` |
| Build | Após pages + T18 | `make lint-frontend && make test-frontend` |
| Coverage | Task T18 | `make test-frontend-coverage` — ≥75% linhas/branches nos arquivos novos (`schemas/forgot-password-schema`, `reset-password-schema`, `change-password-schema`, `lib/validation-errors`, `services/bff-password-*`, `components/*password-form*`, `app/forgot-password`, `app/reset-password`, `app/settings/password`, `app/api/bff/auth/password/**`, delta `verification-guard`) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schemas, validation helper e guard

```
T1 → T2 → T3 → T4 → T5
```

### Phase 2: Serviços BFF e allowlist

```
T6 → T7 → T8 → T9
```

### Phase 3: Route Handlers

```
T10 → T11 → T12
```

### Phase 4: Formulários client

```
T13 → T14 → T15
```

### Phase 5: Páginas RSC

```
T16 → T17 → T18
```

### Phase 6: Gates e fechamento

```
T19
```

---

## Task Breakdown

### T1: Schema Zod forgot password

**What**: Criar `forgotPasswordSchema` espelhando `PasswordResetRequest` com testes.
**Where**: `frontend/modules/auth/schemas/forgot-password-schema.ts`, `forgot-password-schema.test.ts`
**Depends on**: None
**Reuses**: `emailSchema` de `modules/shared/schemas/email`
**Requirement**: PW-18, PW-24

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `email` required, format, max 254; trim + lowercase no transform
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add forgot password zod schema`

---

### T2: Schema Zod reset password

**What**: Criar `resetPasswordSchema` com `passwordSchema`, token sem trim e testes.
**Where**: `frontend/modules/auth/schemas/reset-password-schema.ts`, `reset-password-schema.test.ts`
**Depends on**: None
**Reuses**: `password-schema.ts`, `emailSchema`
**Requirement**: PW-18, PW-24

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `token` minLength 1; whitespace-only falha; **sem** trim
- [x] `password_confirmation` mismatch → erro de campo
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add reset password zod schema`

---

### T3: Schema Zod change password

**What**: Criar `changePasswordSchema` com `current_password` bounds e testes.
**Where**: `frontend/modules/auth/schemas/change-password-schema.ts`, `change-password-schema.test.ts`
**Depends on**: None
**Reuses**: `password-schema.ts`
**Requirement**: PW-18, PW-24

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `current_password` required, max 128; sem composição
- [x] Política de senha e confirmação cobertas
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add change password zod schema`

---

### T4: Helper de erros de campo (`validation-errors`)

**What**: Criar `messageForFieldError`, `messageForTokenFieldError`, `applyServerFieldErrors` com testes.
**Where**: `frontend/modules/auth/lib/validation-errors.ts`, `validation-errors.test.ts`
**Depends on**: T2, T3
**Reuses**: Padrão 422 de `register-form.tsx` (adaptado para `FieldError`)
**Requirement**: PW-11, PW-17, PW-18

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `PASSWORD_REUSED` → pt-BR no campo `password`
- [x] Qualquer erro em `token` → mensagem uniforme pt-BR
- [x] Suporta `FieldError` objeto e string legada
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add server field error mapper`

---

### T5: Verification guard — paths de recovery

**What**: Adicionar `/forgot-password` e `/reset-password` a `VERIFICATION_ALLOWED_PATHS` com testes.
**Where**: `frontend/modules/auth/lib/verification-guard.ts`, `verification-guard.test.ts`
**Depends on**: None
**Reuses**: Helper existente EV slice
**Requirement**: PW-23, BFFUI-52

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `verification` + `/forgot-password` → allow
- [x] `verification` + `/reset-password` → allow
- [x] Regressão: paths anteriores inalterados
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): allow recovery paths in verification guard`

---

### T6: Loader `loadSessionMutationContext`

**What**: Implementar loader de mutation autenticada (`kind: session`) com testes.
**Where**: `frontend/modules/auth/services/bff-password-shared.ts`, `bff-password-shared.test.ts`
**Depends on**: T5
**Reuses**: `loadVerificationMutationContext` pattern; `assertMutationGuard`, `getSession`
**Requirement**: PW-13, PW-19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `kind: verification` → 403 sem upstream
- [x] `kind: session` → retorna ctx com bearer
- [x] Guard CSRF/Origin/sessão ausente → 403
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add session mutation context loader`

---

### T7: Serviço `performBffPasswordResetRequest`

**What**: Orquestração forgot (pré-auth guard → upstream 202 pass-through).
**Where**: `frontend/modules/auth/services/bff-password-reset-request.ts`, `bff-password-reset-request.test.ts`
**Depends on**: T1
**Reuses**: Forward error helpers login/register; `assertMutationGuard`
**Requirement**: BFFUI-60, PW-01–03, PW-19–21

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Upstream `202` → repasse envelope idêntico (dois cenários e-mail)
- [x] `422`/`429`/`504` matriz; **zero** `destroySession`/`getSession` em todos os casos
- [x] Body malformado → `400` sem fetch
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffPasswordResetRequest service`

---

### T8: Serviço `performBffPasswordReset`

**What**: Orquestração reset (pré-auth → upstream 204 → destroy opcional → 200 sanitizado).
**Where**: `frontend/modules/auth/services/bff-password-reset.ts`, `bff-password-reset.test.ts`
**Depends on**: T2, T4
**Reuses**: Padrão `bff-verify-email.ts` destroy; forward 4xx
**Requirement**: BFFUI-61, BFFUI-63, PW-06–08, PW-19–22

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Happy path `204` + cookie mock → `200` + destroy + clear cookie
- [x] Upstream `422` token / `PASSWORD_REUSED` → repasse; **zero** destroy
- [x] `JSON.stringify` sucesso não contém senha/token/Bearer sentinel
- [x] Redis destroy fail pós-`204` → ainda clear cookie + `200`
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffPasswordReset service`

---

### T9: Serviço `performBffPasswordChange`

**What**: Orquestração change (session loader → upstream 204 → destroy → 200).
**Where**: `frontend/modules/auth/services/bff-password-change.ts`, `bff-password-change.test.ts`
**Depends on**: T3, T4, T6
**Reuses**: `loadSessionMutationContext`; padrão destroy verify
**Requirement**: BFFUI-62, BFFUI-63, PW-12–14, PW-19–22

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Happy path `204` → destroy + clear + `redirect_to: /login`
- [x] `401 INVALID_CREDENTIALS` → repasse; sessão intacta
- [x] `422 PASSWORD_REUSED` → repasse; sessão intacta
- [x] `kind !== session` → 403 sem upstream
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffPasswordChange service`

---

### T10: Entradas allowlist password (3 rotas)

**What**: Exportar e registrar `PASSWORD_RESET_REQUEST`, `PASSWORD_RESET`, `PASSWORD_CHANGE` em `AUTH_BFF_ALLOWLIST`.
**Where**: `frontend/modules/auth/bff/allowlist.ts`, `allowlist.test.ts`
**Depends on**: T7, T8, T9
**Reuses**: Padrão entradas login/register/verify
**Requirement**: PW-24

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Lookup resolve os três paths BFF
- [x] `requireSession` false/true conforme spec
- [x] Entradas anteriores inalteradas
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add password allowlist entries`

---

### T11: Route Handler `POST .../password/reset-request`

**What**: Handler fino + testes co-localizados.
**Where**: `frontend/app/api/bff/auth/password/reset-request/route.ts`, `route.test.ts`
**Depends on**: T7, T10
**Reuses**: Padrão `register/route.ts`
**Requirement**: BFFUI-60, PW-01–03, PW-19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] POST `202` pass-through; guards `403` sem upstream mock
- [x] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add password reset-request bff route`

---

### T12: Route Handler `POST .../password/reset`

**What**: Handler fino + testes co-localizados.
**Where**: `frontend/app/api/bff/auth/password/reset/route.ts`, `route.test.ts`
**Depends on**: T8, T10
**Reuses**: Padrão reset-request route
**Requirement**: BFFUI-61, BFFUI-63, PW-06–08, PW-19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] POST happy path `200` + clear cookie
- [x] Guards bloqueiam sem upstream
- [x] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add password reset bff route`

---

### T13: Route Handler `POST .../password/change`

**What**: Handler fino + testes co-localizados.
**Where**: `frontend/app/api/bff/auth/password/change/route.ts`, `route.test.ts`
**Depends on**: T9, T10
**Reuses**: Padrão reset route
**Requirement**: BFFUI-62, BFFUI-63, PW-12–14, PW-19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] POST happy path `200` + clear cookie
- [x] Kind guard via service; guards `403`
- [x] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add password change bff route`

---

### T14: Componente `ForgotPasswordForm`

**What**: Form RHF+Zod; submit reset-request; estado sucesso anti-enum; link voltar login.
**Where**: `frontend/modules/auth/components/forgot-password-form.tsx`, `forgot-password-form.test.tsx`
**Depends on**: T1, T4
**Reuses**: shared UI, form-defaults, padrão `login-form.tsx`
**Requirement**: BFFUI-60, PW-04–05, PW-20

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Dois e-mails MSW `202` → mesma copy de sucesso
- [x] Email inválido bloqueia submit client-side
- [x] `429` exibe throttle com Retry-After
- [x] Link `/login` presente
- [x] Gate: `make test-frontend` passa

**Tests**: unit (RTL)  
**Gate**: full  
**Commit**: `feat(bff-auth): add forgot password form component`

---

### T15: Componente `ResetPasswordForm`

**What**: Form com `initialToken`, strip query, submit reset, erros token/PASSWORD_REUSED.
**Where**: `frontend/modules/auth/components/reset-password-form.tsx`, `reset-password-form.test.tsx`
**Depends on**: T2, T4
**Reuses**: Padrão `verify-email-form.tsx` (replaceState)
**Requirement**: BFFUI-61, PW-09–11, PW-20, PW-22

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Mount com `initialToken` → zero fetch até submit
- [ ] `replaceState` remove `?token=` (spy)
- [ ] MSW `200` → `router.push('/login')`
- [ ] `422` token / `PASSWORD_REUSED` → erros de campo pt-BR
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL)  
**Gate**: full  
**Commit**: `feat(bff-auth): add reset password form component`

---

### T16: Componente `ChangePasswordForm`

**What**: Form change com CSRF session-mode; 401 em current_password; PASSWORD_REUSED.
**Where**: `frontend/modules/auth/components/change-password-form.tsx`, `change-password-form.test.tsx`
**Depends on**: T3, T4
**Reuses**: Padrão register-form 422 handling via T4 helper
**Requirement**: BFFUI-62, PW-16–17, PW-20, PW-22

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] MSW `200` → redirect login
- [ ] `401 INVALID_CREDENTIALS` → erro em `current_password`
- [ ] `422 PASSWORD_REUSED` → erro em `password`
- [ ] Validação client bloqueia submit inválido
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL)  
**Gate**: full  
**Commit**: `feat(bff-auth): add change password form component`

---

### T17: Páginas RSC forgot + reset

**What**: `app/forgot-password/page.tsx` e `app/reset-password/page.tsx` com testes.
**Where**: `frontend/app/forgot-password/`, `frontend/app/reset-password/`
**Depends on**: T14, T15
**Reuses**: `ensurePreAuthCsrfCookies`; paridade login/register pages
**Requirement**: PW-04, PW-09–10, PW-23

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Forgot: bootstrap CSRF; renderiza form
- [ ] Reset: `initialToken` de query; render sem fetch; verification session não redireciona
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: full  
**Commit**: `feat(bff-auth): add forgot and reset password pages`

---

### T18: Página RSC `/settings/password`

**What**: Server page com guards redirect + render `ChangePasswordForm`.
**Where**: `frontend/app/settings/password/page.tsx`, `page.test.tsx`
**Depends on**: T16
**Reuses**: `getSessionFromRequest`, `redirect` (paridade verify-email)
**Requirement**: PW-15, BFFUI-62

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Sem sessão → `/login`
- [ ] `verification` → `/verify-email`
- [ ] `session` → render form
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: full  
**Commit**: `feat(bff-auth): add settings password page`

---

### T19: Foundation gates + quality gates

**What**: Permitir rotas/pages password produto; lint e cobertura final.
**Where**: `frontend/modules/shared/lib/foundation-gates.test.ts`; Makefile gates
**Depends on**: T11, T12, T13, T17, T18
**Reuses**: Gate EV slice; `make lint-frontend`, `make test-frontend-coverage`
**Requirement**: PW-24, Success criteria spec (cobertura ≥75%)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Gate lista 3 route handlers + 3 pages password
- [ ] `make lint-frontend` verde
- [ ] `make test-frontend-coverage` verde com ≥75% nos arquivos novos da fatia
- [ ] Nenhum teste silenciosamente removido vs baseline email-verification

**Tests**: unit (foundation) + none (coverage gate)  
**Gate**: build  
**Commit**: `chore(bff-auth): password slice quality gates`

---

## Phase Execution Map

```
Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5
Phase 2:  T6 ──→ T7 ──→ T8 ──→ T9 ──→ T10
Phase 3:  T11 ──→ T12 ──→ T13
Phase 4:  T14 ──→ T15 ──→ T16
Phase 5:  T17 ──→ T18
Phase 6:  T19
```

Execution is strictly sequential — one task at a time, in order.

**Batch packing (Execute):** Batch 1 = Phases 1–2 (T1–T10, 10 tasks); Batch 2 = Phases 3–4 (T11–T16, 6 tasks); Batch 3 = Phases 5–6 (T17–T19, 3 tasks).

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: forgot schema | 1 schema + test | ✅ Granular |
| T2: reset schema | 1 schema + test | ✅ Granular |
| T3: change schema | 1 schema + test | ✅ Granular |
| T4: validation-errors helper | 1 lib + test | ✅ Granular |
| T5: verification guard paths | 1 lib modify + test | ✅ Granular |
| T6: session mutation loader | 1 service helper + test | ✅ Granular |
| T7: reset-request service | 1 service + tests | ✅ Granular |
| T8: reset service | 1 service + tests | ✅ Granular |
| T9: change service | 1 service + tests | ✅ Granular |
| T10: allowlist entries | 1 const block + test | ✅ Granular |
| T11–T13: route handlers | 1 route each + test | ✅ Granular |
| T14–T16: form components | 1 component each + test | ✅ Granular |
| T17: forgot + reset pages | 2 pages (coesão recovery) | ✅ OK |
| T18: settings password page | 1 page + test | ✅ Granular |
| T19: gates + coverage | 1 test file + Makefile | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | None | (parallel) | ✅ Match |
| T3 | None | (parallel) | ✅ Match |
| T4 | T2, T3 | T2/T3 → T4 | ✅ Match |
| T5 | None | (parallel) | ✅ Match |
| T6 | T5 | T5 → T6 | ✅ Match |
| T7 | T1 | T1 → T7 | ✅ Match |
| T8 | T2, T4 | T4 → T8 | ✅ Match |
| T9 | T3, T4, T6 | T6 → T9 | ✅ Match |
| T10 | T7, T8, T9 | T9 → T10 | ✅ Match |
| T11 | T7, T10 | T10 → T11 | ✅ Match |
| T12 | T8, T10 | T10 → T12 | ✅ Match |
| T13 | T9, T10 | T10 → T13 | ✅ Match |
| T14 | T1, T4 | T4 → T14 | ✅ Match |
| T15 | T2, T4 | T4 → T15 | ✅ Match |
| T16 | T3, T4 | T4 → T16 | ✅ Match |
| T17 | T14, T15 | T15 → T17 | ✅ Match |
| T18 | T16 | T16 → T18 | ✅ Match |
| T19 | T11–T13, T17, T18 | T18 → T19 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Forgot schema | unit | unit | ✅ OK |
| T2 | Reset schema | unit | unit | ✅ OK |
| T3 | Change schema | unit | unit | ✅ OK |
| T4 | validation-errors | unit | unit | ✅ OK |
| T5 | verification-guard | unit | unit | ✅ OK |
| T6 | bff-password-shared | unit | unit | ✅ OK |
| T7 | reset-request service | unit | unit | ✅ OK |
| T8 | reset service | unit | unit | ✅ OK |
| T9 | change service | unit | unit | ✅ OK |
| T10 | allowlist | unit | unit | ✅ OK |
| T11 | reset-request route | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T12 | reset route | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T13 | change route | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T14 | ForgotPasswordForm | unit (RTL) | unit (RTL) | ✅ OK |
| T15 | ResetPasswordForm | unit (RTL) | unit (RTL) | ✅ OK |
| T16 | ChangePasswordForm | unit (RTL) | unit (RTL) | ✅ OK |
| T17 | forgot + reset pages | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T18 | settings password page | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T19 | foundation + coverage | unit + none | unit + none | ✅ OK |

---

## Requirement Traceability (tasks → spec)

| Task | Requirement IDs |
| --- | --- |
| T1 | PW-18, PW-24 |
| T2 | PW-18, PW-24 |
| T3 | PW-18, PW-24 |
| T4 | PW-11, PW-17, PW-18 |
| T5 | PW-23, BFFUI-52 |
| T6 | PW-13, PW-19 |
| T7 | BFFUI-60, PW-01–03, PW-19–21 |
| T8 | BFFUI-61, BFFUI-63, PW-06–08, PW-19–22 |
| T9 | BFFUI-62, BFFUI-63, PW-12–14, PW-19–22 |
| T10 | PW-24 |
| T11 | BFFUI-60, PW-01–03, PW-19 |
| T12 | BFFUI-61, BFFUI-63, PW-06–08, PW-19 |
| T13 | BFFUI-62, BFFUI-63, PW-12–14, PW-19 |
| T14 | BFFUI-60, PW-04–05, PW-20 |
| T15 | BFFUI-61, PW-09–11, PW-20, PW-22 |
| T16 | BFFUI-62, PW-16–17, PW-20, PW-22 |
| T17 | PW-04, PW-09–10, PW-23 |
| T18 | PW-15, BFFUI-62 |
| T19 | PW-24, Success criteria (coverage) |

---

## MCPs e Skills (Execute)

| Task | MCPs sugeridos | Skills |
| --- | --- | --- |
| T1–T19 | NONE (código local + Docker gates) | `tlc-spec-driven` |
| Consulta Next.js / RHF | `user-context7` (se dúvida de API) | `react-best-practices` |

Confirme antes do Execute se deseja sub-agents em 3 batches (T1–T10, T11–T16, T17–T19).
