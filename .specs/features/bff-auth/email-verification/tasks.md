# BFF Auth — Verificação de e-mail — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/email-verification/design.md`  
**Spec**: `.specs/features/bff-auth/email-verification/spec.md`  
**Status**: Approved — 2026-08-18

> **Sub-agent note:** 13 tasks → ~2 batches (Batch 1: T1–T7 ~7 tasks; Batch 2: T8–T13 ~6 tasks). Execute MUST offer batch sub-agents before implementation if user accepts.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL/MSW Route Handlers), §4 (domínios frontend ≥75%, Auth/BFF ≥80%), §6.1 (verify POST-only, scanner GET), §6.2 (Bearer absent, CSRF session-mode), `.specs/features/bff-auth/email-verification/spec.md`, amostras `frontend/app/api/bff/auth/login/route.test.ts`, `frontend/modules/auth/services/bff-login.test.ts`, `frontend/modules/auth/components/login-form.test.tsx`, `frontend/app/login/page.test.tsx`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Verify email Zod schema | unit | EV-18; `token` minLength 1; whitespace rejected; sem trim | `frontend/modules/auth/schemas/verify-email-schema.test.ts` | `make test-frontend` |
| Auth messages (verify) | unit | EV-08, EV-09; INVALID_VERIFICATION_TOKEN, EMAIL_ALREADY_VERIFIED; regressão login/register | `frontend/modules/auth/lib/auth-messages.test.ts` | `make test-frontend` |
| Verification guard | unit | EV-16, EV-17; allowlist paths; redirect matrix por kind | `frontend/modules/auth/lib/verification-guard.test.ts` | `make test-frontend` |
| Shared mutation context loader | unit | EV-04, EV-11; kind !== verification → 403; guard failures sem upstream | `frontend/modules/auth/services/bff-email-verification-shared.test.ts` | `make test-frontend` |
| BFF verify service | unit | EV-01–04, EV-08–11, EV-18–19; upstream 204 → destroy + 200; erros sem destroy; Bearer absent | `frontend/modules/auth/services/bff-verify-email.test.ts` | `make test-frontend` |
| BFF resend service | unit | EV-05–07, EV-11; 202 pass-through; 429 Retry-After; zero destroySession | `frontend/modules/auth/services/bff-resend-verification.test.ts` | `make test-frontend` |
| Allowlist prod entries | unit | EV-20; verify + resend entries; requireSession true | `frontend/modules/auth/bff/allowlist.test.ts` | `make test-frontend` |
| Verify Route Handler | unit (Route Handler) | EV-01–03; integration via performBffVerifyEmail spy/mock | `frontend/app/api/bff/auth/email/verify/route.test.ts` | `make test-frontend` |
| Resend Route Handler | unit (Route Handler) | EV-05–07; integration via performBffResendVerification | `frontend/app/api/bff/auth/email/resend/route.test.ts` | `make test-frontend` |
| VerifyEmailForm (client) | unit (RTL) | EV-12–15, EV-08–10; scanner-safe mount; submit/resend MSW; replaceState | `frontend/modules/auth/components/verify-email-form.test.tsx` | `make test-frontend` |
| Verify-email page (RSC) | unit (RTL/node) | EV-12, EV-15; redirect matrix; initialToken prop; zero fetch on render | `frontend/app/verify-email/page.test.tsx` | `make test-frontend` |
| Home page guard | unit (RTL/node) | EV-16; verification session → /verify-email | `frontend/app/page.test.tsx` | `make test-frontend` |
| Foundation gates | unit | EV-21; permite verify routes/pages; password ainda gated | `frontend/modules/shared/lib/foundation-gates.test.ts` | `make test-frontend` |
| Quality gates | none | — Makefile gate | — | `make lint-frontend && make test-frontend-coverage` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks com unit tests isolados (T1–T7) | `make test-frontend` |
| Full | Após Route Handlers + UI (T8–T12) | `make test-frontend` |
| Build | Após T13 ou fechamento de fatia | `make lint-frontend && make test-frontend` |
| Coverage | Task T13 | `make test-frontend-coverage` — ≥75% linhas/branches nos arquivos novos (`schemas/verify-email-schema`, `lib/verification-guard`, `services/bff-email-verification-shared`, `bff-verify-email`, `bff-resend-verification`, `components/verify-email-form`, `app/verify-email`, `app/api/bff/auth/email/**`, `app/page.tsx` guard delta) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schemas, mensagens e guards

```
T1 → T2 → T3
```

### Phase 2: Serviços e allowlist

```
T4 → T5 → T6 → T7
```

### Phase 3: Route Handlers

```
T8 → T9
```

### Phase 4: UI e guard de landing

```
T10 → T11 → T12
```

### Phase 5: Gates e fechamento

```
T13
```

---

## Task Breakdown

### T1: Schema Zod de verificação de e-mail

**What**: Criar `verifyEmailSchema` espelhando `VerifyEmailRequest` OpenAPI com testes.
**Where**: `frontend/modules/auth/schemas/verify-email-schema.ts`, `verify-email-schema.test.ts`
**Depends on**: None
**Reuses**: Padrão `login-schema.ts`
**Requirement**: EV-18, EV-22

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `token` required, `minLength: 1`; mensagem pt-BR
- [x] String só whitespace falha; **sem** trim automático
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add verify email zod schema`

---

### T2: Mensagens pt-BR de verificação

**What**: Estender `auth-messages.ts` com `INVALID_VERIFICATION_TOKEN`, `EMAIL_ALREADY_VERIFIED` e caso `401` sessão expirada.
**Where**: `frontend/modules/auth/lib/auth-messages.ts`, `auth-messages.test.ts`
**Depends on**: T1
**Reuses**: Mapa existente login/register
**Requirement**: EV-08, EV-09, BFFUI-32

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Mensagens conforme design.md (copy pt-BR)
- [x] Códigos login/register inalterados (regressão)
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add email verification auth messages`

---

### T3: Guard de sessão restrita (`verification-guard`)

**What**: Criar `VERIFICATION_ALLOWED_PATHS` e `resolveVerificationSessionGuard` com testes de matriz redirect.
**Where**: `frontend/modules/auth/lib/verification-guard.ts`, `verification-guard.test.ts`
**Depends on**: None
**Reuses**: Regras redirect de login/register pages
**Requirement**: EV-16, EV-17, BFFUI-52

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `verification` + `/` → redirect `/verify-email`
- [x] `verification` + `/verify-email` → allow
- [x] `null` + `/verify-email` → redirect `/login` (helper usado pela page)
- [x] `session` + `/verify-email` → redirect `/`
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add verification session guard helper`

---

### T4: Loader compartilhado de mutation autenticada

**What**: Implementar `loadVerificationMutationContext` (guard session-mode + kind check) com testes.
**Where**: `frontend/modules/auth/services/bff-email-verification-shared.ts`, `bff-email-verification-shared.test.ts`
**Depends on**: T3
**Reuses**: `assertMutationGuard`, `getSession`, `VERIFY_EMAIL_ALLOWLIST_ENTRY`
**Requirement**: EV-04, EV-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Guard Origin/CSRF/sessão ausente → 403 sem upstream mock
- [x] `kind: session` → 403 sem upstream
- [x] `kind: verification` → retorna ctx com bearerPlaintext
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add verification mutation context loader`

---

### T5: Serviço `performBffVerifyEmail`

**What**: Orquestração verify (context → upstream 204 → destroySession → clearSessionCookie → 200 sanitizado).
**Where**: `frontend/modules/auth/services/bff-verify-email.ts`, `bff-verify-email.test.ts`
**Depends on**: T2, T4
**Reuses**: Forward error helpers login/register; `destroySession`, `clearSessionCookie`
**Requirement**: BFFUI-50, BFFUI-17, EV-01–04, EV-08–11, EV-18–19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Happy path upstream `204` → `200` + `redirect_to: /login` + cookie cleared
- [x] Upstream `403 INVALID_VERIFICATION_TOKEN` → repasse; **zero** destroySession
- [x] Upstream `401/422/429/5xx` → matriz sem destroy
- [x] Body malformado → `400` sem fetch
- [x] `JSON.stringify` resposta sucesso não contém Bearer sentinel nem token submetido
- [x] Gate: `make test-frontend` passa; ≥16 casos no service test

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffVerifyEmail service`

---

### T6: Serviço `performBffResendVerification`

**What**: Orquestração resend (context → upstream POST sem body → pass-through 202/429).
**Where**: `frontend/modules/auth/services/bff-resend-verification.ts`, `bff-resend-verification.test.ts`
**Depends on**: T4
**Reuses**: `loadVerificationMutationContext`, forward 4xx pattern
**Requirement**: BFFUI-50, EV-05–07, EV-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Upstream `202` → repasse envelope Accepted
- [x] Upstream `429` + `Retry-After` repassados
- [x] `destroySession` **nunca** chamado
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffResendVerification service`

---

### T7: Entradas allowlist verify + resend

**What**: Exportar `VERIFY_EMAIL_ALLOWLIST_ENTRY`, `RESEND_VERIFICATION_ALLOWLIST_ENTRY` e registrá-las em `AUTH_BFF_ALLOWLIST`.
**Where**: `frontend/modules/auth/bff/allowlist.ts`, `allowlist.test.ts`
**Depends on**: T5, T6
**Reuses**: Padrão `LOGIN_ALLOWLIST_ENTRY`
**Requirement**: EV-20

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Lookup resolve ambos paths BFF
- [x] `requireSession: true`, `requireCsrf: true` em ambos
- [x] Login/register entries inalteradas
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add email verify and resend allowlist entries`

---

### T8: Route Handler `POST /api/bff/auth/email/verify`

**What**: Handler fino delegando a `performBffVerifyEmail` com testes co-localizados.
**Where**: `frontend/app/api/bff/auth/email/verify/route.ts`, `route.test.ts`
**Depends on**: T5, T7
**Reuses**: Padrão `app/api/bff/auth/register/route.ts`
**Requirement**: BFFUI-50, EV-01–03, EV-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] POST happy path retorna `200` + clear cookie + body sem Bearer
- [x] CSRF/Origin failure → 403; upstream mock não invocado
- [x] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add email verify bff route handler`

---

### T9: Route Handler `POST /api/bff/auth/email/resend`

**What**: Handler fino delegando a `performBffResendVerification` com testes co-localizados.
**Where**: `frontend/app/api/bff/auth/email/resend/route.ts`, `route.test.ts`
**Depends on**: T6, T7
**Reuses**: Padrão verify route
**Requirement**: BFFUI-50, EV-05–07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] POST repassa `202` upstream
- [x] Guard failure → 403 sem upstream
- [x] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add email resend bff route handler`

---

### T10: Componente `VerifyEmailForm`

**What**: Formulário client RHF+Zod; submit verify; resend; strip `?token=`; erros pt-BR.
**Where**: `frontend/modules/auth/components/verify-email-form.tsx`, `verify-email-form.test.tsx`
**Depends on**: T1, T2
**Reuses**: shared UI, form-defaults, auth-messages, padrão `login-form.tsx`
**Requirement**: BFFUI-51, BFFUI-32, EV-12–15, EV-08–10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Mount com `initialToken` **não** chama fetch verify/resend (scanner-safe)
- [x] `replaceState` remove `?token=` após mount (spy)
- [x] Submit válido MSW `200` → `router.push('/login')`
- [x] Resend MSW `202` → mensagem confirmação pt-BR
- [x] `403 INVALID_VERIFICATION_TOKEN` → mensagem uniforme
- [x] `403 EMAIL_ALREADY_VERIFIED` → redirect login
- [x] `429` + Retry-After → throttle message
- [x] Token vazio bloqueia submit client-side
- [x] Link "Ir para login" presente
- [x] Gate: `make test-frontend` passa

**Tests**: unit (RTL)  
**Gate**: full  
**Commit**: `feat(bff-auth): add verify email form component`

---

### T11: Página `/verify-email` (RSC)

**What**: Server page com redirect matrix, `initialToken` de query, shell pt-BR.
**Where**: `frontend/app/verify-email/page.tsx`, `page.test.tsx`
**Depends on**: T3, T10
**Reuses**: `getSessionFromRequest`, `redirect`
**Requirement**: BFFUI-51, EV-12, EV-15

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Sem sessão → redirect `/login`
- [x] Sessão `session` → redirect `/`
- [x] Sessão `verification` → renderiza `VerifyEmailForm` com token da query
- [x] Render não dispara fetch verify/resend (spy global fetch count 0)
- [x] HTML/props não contêm Bearer
- [x] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: full  
**Commit**: `feat(bff-auth): add verify email page`

---

### T12: Guard na landing `/`

**What**: Redirect sessão `verification` de `/` para `/verify-email`.
**Where**: `frontend/app/page.tsx`, `page.test.tsx` (criar se ausente)
**Depends on**: T3
**Reuses**: `getSessionFromRequest`
**Requirement**: EV-16, BFFUI-52

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Sessão `verification` mock → redirect `/verify-email`
- [x] Sem sessão ou `session` kind → render landing inalterada
- [x] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: full  
**Commit**: `feat(bff-auth): redirect verification session from home`

---

### T13: Foundation gates + quality gates

**What**: Permitir rotas/páginas verify produto; executar lint e cobertura final.
**Where**: `frontend/modules/shared/lib/foundation-gates.test.ts`; Makefile gates
**Depends on**: T8, T9, T11, T12
**Reuses**: Gate existente FND; `make lint-frontend`, `make test-frontend-coverage`
**Requirement**: EV-21, EV-22, Success criteria spec (cobertura ≥75%)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Gate lista `api/bff/auth/email/verify/route.ts`, `api/bff/auth/email/resend/route.ts`, `verify-email/page.tsx`
- [ ] Segmento `password` ainda proibido em prod routes/pages
- [ ] `make lint-frontend` verde
- [ ] `make test-frontend-coverage` verde com ≥75% nos arquivos novos da fatia
- [ ] Nenhum teste silenciosamente removido vs baseline register (289+)

**Tests**: unit (foundation) + none (coverage gate)  
**Gate**: build  
**Commit**: `chore(bff-auth): email verification slice quality gates`

---

## Phase Execution Map

```
Phase 1:  T1 ──→ T2 ──→ T3
Phase 2:  T4 ──→ T5 ──→ T6 ──→ T7
Phase 3:  T8 ──→ T9
Phase 4:  T10 ──→ T11 ──→ T12
Phase 5:  T13
```

Execution is strictly sequential — one task at a time, in order.

**Batch packing (Execute):** Batch 1 = Phases 1–3 (T1–T9, 9 tasks); Batch 2 = Phases 4–5 (T10–T13, 4 tasks).

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: verify-email schema | 1 schema + test | ✅ Granular |
| T2: auth messages extension | 1 lib modify + test | ✅ Granular |
| T3: verification-guard | 1 lib file + test | ✅ Granular |
| T4: shared mutation loader | 1 service helper + test | ✅ Granular |
| T5: performBffVerifyEmail | 1 service + tests | ✅ Granular |
| T6: performBffResendVerification | 1 service + tests | ✅ Granular |
| T7: allowlist entries | 1 const block + test update | ✅ Granular |
| T8: verify route handler | 1 route + test | ✅ Granular |
| T9: resend route handler | 1 route + test | ✅ Granular |
| T10: VerifyEmailForm | 1 component + test | ✅ Granular |
| T11: verify-email page | 1 page + test | ✅ Granular |
| T12: home guard | 1 page modify + test | ✅ Granular |
| T13: gates + coverage | 1 test file update + Makefile | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | None | (parallel start) | ✅ Match |
| T4 | T3 | T3 → T4 | ✅ Match |
| T5 | T2, T4 | T4 → T5 | ✅ Match |
| T6 | T4 | T4 → T6 | ✅ Match |
| T7 | T5, T6 | T6 → T7 | ✅ Match |
| T8 | T5, T7 | T7 → T8 | ✅ Match |
| T9 | T6, T7 | T7 → T9 | ✅ Match |
| T10 | T1, T2 | T2 → T10 | ✅ Match |
| T11 | T3, T10 | T10 → T11 | ✅ Match |
| T12 | T3 | T3 → T12 | ✅ Match |
| T13 | T8, T9, T11, T12 | T11/T12 → T13 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Verify email Zod schema | unit | unit | ✅ OK |
| T2 | Auth messages | unit | unit | ✅ OK |
| T3 | Verification guard | unit | unit | ✅ OK |
| T4 | Shared mutation loader | unit | unit | ✅ OK |
| T5 | BFF verify service | unit | unit | ✅ OK |
| T6 | BFF resend service | unit | unit | ✅ OK |
| T7 | Allowlist entries | unit | unit | ✅ OK |
| T8 | Verify Route Handler | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T9 | Resend Route Handler | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T10 | VerifyEmailForm | unit (RTL) | unit (RTL) | ✅ OK |
| T11 | Verify-email page | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T12 | Home page guard | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T13 | Foundation gates + coverage | unit + none | unit + none | ✅ OK |

---

## Requirement Traceability (tasks → spec)

| Task | Requirement IDs |
| --- | --- |
| T1 | EV-18, EV-22 |
| T2 | EV-08, EV-09, BFFUI-32 |
| T3 | EV-16, EV-17, BFFUI-52 |
| T4 | EV-04, EV-11 |
| T5 | BFFUI-50, BFFUI-17, EV-01–04, EV-08–11, EV-18–19 |
| T6 | BFFUI-50, EV-05–07, EV-11 |
| T7 | EV-20 |
| T8 | BFFUI-50, EV-01–03, EV-11 |
| T9 | BFFUI-50, EV-05–07 |
| T10 | BFFUI-51, BFFUI-32, EV-08–15 |
| T11 | BFFUI-51, EV-12, EV-15 |
| T12 | EV-16, BFFUI-52 |
| T13 | EV-21, EV-22, Success criteria (coverage) |

---

## MCPs e Skills (Execute)

| Task | MCPs sugeridos | Skills |
| --- | --- | --- |
| T1–T13 | NONE (código local + Docker gates) | `tlc-spec-driven` |
| Consulta Next.js App Router / RHF | `user-context7` (se dúvida de API) | `react-best-practices` |

Confirme antes do Execute se deseja sub-agents em 2 batches (T1–T9, T10–T13).
