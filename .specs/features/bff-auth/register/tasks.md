# BFF Auth — Cadastro — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/register/design.md`  
**Spec**: `.specs/features/bff-auth/register/spec.md`  
**Status**: Draft — 2026-08-11

> **Sub-agent note:** 11 tasks → ~2 batches (~6 + ~5 tasks/worker). Execute MUST offer batch sub-agents before implementation if user accepts.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL/MSW Route Handlers), §4 (domínios frontend ≥75%, Auth/BFF ≥80%), §6.1 (anti-enumeração cadastro), §6.2 (Bearer absent, CSRF), `.specs/features/bff-auth/register/spec.md`, amostras `frontend/app/api/bff/auth/login/route.test.ts`, `frontend/modules/auth/services/bff-login.test.ts`, `frontend/modules/auth/components/login-form.test.tsx`, `frontend/app/login/page.test.tsx`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Password Zod schema | unit | RGR-08 composição ASCII; 12–128 bounds; símbolos edge | `frontend/modules/auth/schemas/password-schema.test.ts` | `make test-frontend` |
| Register Zod schema | unit | RGR-06, RGR-07, RGR-10; name/email bounds; accept_terms literal; password match | `frontend/modules/auth/schemas/register-schema.test.ts` | `make test-frontend` |
| Auth messages (register) | unit | RGR-04, RGR-05; REGISTRATION_NOT_ALLOWED uniforme; demais códigos inalterados | `frontend/modules/auth/lib/auth-messages.test.ts` | `make test-frontend` |
| Auth terms helper | unit | Default `2026-01`; env override | `frontend/modules/auth/lib/auth-terms.test.ts` | `make test-frontend` |
| BFF register service | unit | RGR-01–03, RGR-04, RGR-11, RGR-12, RGR-15–16; upstream **201**; token_kind verification; Bearer absent in JSON.stringify | `frontend/modules/auth/services/bff-register.test.ts` | `make test-frontend` |
| Allowlist prod entry | unit | RGR-18; register entry + login coexist | `frontend/modules/auth/bff/allowlist.test.ts` | `make test-frontend` |
| Register Route Handler | unit (Route Handler) | RGR-01–03, RGR-12; integration via performBffRegister mock/spy | `frontend/app/api/bff/auth/register/route.test.ts` | `make test-frontend` |
| RegisterForm (client) | unit (RTL) | RGR-05–09, RGR-11, RGR-13; validation block; Terms checkbox; MSW submit | `frontend/modules/auth/components/register-form.test.tsx` | `make test-frontend` |
| Register page (RSC) | unit (RTL/node) | RGR-13, RGR-14; redirect session/verification; CSRF bootstrap spy | `frontend/app/register/page.test.tsx` | `make test-frontend` |
| Terms page (RSC) | unit (RTL/node) | RGR-17; versão exibida alinha auth-terms | `frontend/app/terms/page.test.tsx` | `make test-frontend` |
| Foundation gates | unit | Permite register routes; ainda proíbe verify/password prod | `frontend/modules/shared/lib/foundation-gates.test.ts` | `make test-frontend` |
| Quality gates | none | — Makefile gate | — | `make lint-frontend && make test-frontend-coverage` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks com unit tests isolados (T1–T6) | `make test-frontend` |
| Full | Após Route Handler + UI (T6–T9) | `make test-frontend` |
| Build | Após T10–T11 ou fechamento de fatia | `make lint-frontend && make test-frontend` |
| Coverage | Task T11 | `make test-frontend-coverage` — ≥75% linhas/branches nos arquivos novos da fatia (`modules/auth/schemas/password-schema`, `register-schema`, `lib/auth-terms`, `services/bff-register`, `components/register-form`, `app/register`, `app/terms`, `app/api/bff/auth/register`) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schemas, terms e mensagens

```
T1 → T2 → T3 → T4
```

### Phase 2: Serviço e allowlist

```
T5 → T6
```

### Phase 3: Route Handler

```
T7
```

### Phase 4: UI (form + pages)

```
T8 → T9 → T10
```

### Phase 5: Gates e fechamento

```
T11
```

---

## Task Breakdown

### T1: Schema Zod de senha (compartilhado)

**What**: Criar `passwordSchema` espelhando OpenAPI `Password` com testes de composição ASCII.
**Where**: `frontend/modules/auth/schemas/password-schema.ts`, `password-schema.test.ts`
**Depends on**: None
**Reuses**: OpenAPI `Password` description
**Requirement**: RGR-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] 12–128 chars; minúscula, maiúscula, dígito, símbolo ASCII; mensagens pt-BR
- [x] Gate: `make test-frontend` passa (password-schema tests)

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add shared password zod schema`

---

### T2: Schema Zod de cadastro

**What**: Criar `registerSchema` espelhando `RegisterRequest` OpenAPI com testes.
**Where**: `frontend/modules/auth/schemas/register-schema.ts`, `register-schema.test.ts`
**Depends on**: T1
**Reuses**: `emailSchema`, `passwordSchema`
**Requirement**: RGR-06, RGR-07, RGR-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `name`: trim, 1–120; `email`: trim + lowercase; `accept_terms`: literal `true`
- [x] `password_confirmation` mismatch → erro de campo
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add register zod schema`

---

### T3: Mensagens pt-BR de cadastro

**What**: Estender `auth-messages.ts` com `REGISTRATION_NOT_ALLOWED` (mensagem uniforme anti-enum).
**Where**: `frontend/modules/auth/lib/auth-messages.ts`, `auth-messages.test.ts`
**Depends on**: T2
**Reuses**: Mapa existente login
**Requirement**: RGR-04, RGR-05, BFFUI-32

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `REGISTRATION_NOT_ALLOWED` retorna mesma string para convite inválido e duplicata
- [x] Códigos login existentes inalterados (regressão)
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add registration not allowed message`

---

### T4: Helper versão dos Terms

**What**: Criar `getAuthTermsCurrentVersion()` lendo env pública com default `2026-01`.
**Where**: `frontend/modules/auth/lib/auth-terms.ts`, `auth-terms.test.ts`
**Depends on**: None
**Reuses**: Alinhamento `AUTH_TERMS_CURRENT_VERSION` backend
**Requirement**: RGR-07, BFFUI-41

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Default `2026-01` quando env ausente
- [x] Override via `NEXT_PUBLIC_AUTH_TERMS_CURRENT_VERSION` funciona em teste
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add auth terms version helper`

---

### T5: Serviço `performBffRegister`

**What**: Orquestração completa cadastro BFF (guard, upstream fetch 201, destroy prior, createSession verification, resposta sanitizada).
**Where**: `frontend/modules/auth/services/bff-register.ts`, `bff-register.test.ts`
**Depends on**: T3
**Reuses**: `assertMutationGuard`, `buildUpstreamUrl`, `createSession`, `destroySession`, `parseUpstreamAuthResponse`, `issueCsrfForSession`
**Requirement**: BFFUI-40, BFFUI-15, BFFUI-17, BFFUI-32, RGR-01–04, RGR-11, RGR-12, RGR-15, RGR-16

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Happy path → `201` + `redirect_to: '/verify-email'` + sessão `verification`
- [x] Matriz 403/422/429 repasse; 500/503/504 genérico; guard 403 sem fetch
- [x] Upstream 201 com `token_kind !== verification` → 500 sem cookie
- [x] `JSON.stringify` resposta sucesso não contém Bearer fixture
- [x] destroySession chamado quando sessão prévia existe
- [x] Gate: `make test-frontend` passa; ≥18 casos no service test

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffRegister service`

---

### T6: Entrada allowlist de cadastro

**What**: Exportar `REGISTER_ALLOWLIST_ENTRY` e registrá-la em `AUTH_BFF_ALLOWLIST`.
**Where**: `frontend/modules/auth/bff/allowlist.ts`, `allowlist.test.ts`
**Depends on**: T5
**Reuses**: Padrão `LOGIN_ALLOWLIST_ENTRY`
**Requirement**: RGR-18

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `lookupAllowlistEntry('POST', '/api/bff/auth/register')` resolve
- [x] Teste confirma `requireSession: false`, `requireCsrf: true`
- [x] Login entry continua presente
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): register register allowlist entry`

---

### T7: Route Handler `POST /api/bff/auth/register`

**What**: Handler fino delegando a `performBffRegister` com testes co-localizados.
**Where**: `frontend/app/api/bff/auth/register/route.ts`, `route.test.ts`
**Depends on**: T5, T6
**Reuses**: Padrão `app/api/bff/auth/login/route.ts`
**Requirement**: BFFUI-40, RGR-01–03, RGR-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] POST happy path retorna **201** + Set-Cookie session + body sem token
- [ ] CSRF/Origin failure → 403; upstream mock não invocado
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add register bff route handler`

---

### T8: Componente `RegisterForm`

**What**: Formulário client RHF+Zod; submit BFF; Terms checkbox + link `/terms`; erros pt-BR.
**Where**: `frontend/modules/auth/components/register-form.tsx`, `register-form.test.tsx`
**Depends on**: T2, T3, T4
**Reuses**: shared UI, form-defaults, auth-messages, padrão `login-form.tsx`
**Requirement**: BFFUI-41, BFFUI-32, RGR-05–09, RGR-11, RGR-13

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Validação client bloqueia submit inválido (incl. Terms desmarcado) sem fetch
- [ ] MSW: 201 → router.push(`/verify-email`); 403 REGISTRATION_NOT_ALLOWED mensagem idêntica nos dois fixtures
- [ ] 422 server-side errors mapeados para campos
- [ ] 429 exibe throttle + Retry-After quando presente
- [ ] Link "Já tenho conta" → `/login`
- [ ] Submit envia CSRF header + credentials include
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL)  
**Gate**: full  
**Commit**: `feat(bff-auth): add register form component`

---

### T9: Página `/register` (RSC)

**What**: Server page com redirect autenticado, bootstrap CSRF, shell pt-BR, `RegisterForm`.
**Where**: `frontend/app/register/page.tsx`, `page.test.tsx`
**Depends on**: T4, T8
**Reuses**: `getSessionFromRequest`, `ensurePreAuthCsrfCookies`
**Requirement**: BFFUI-41, RGR-13, RGR-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Sessão `session` → redirect `/`
- [ ] Sessão `verification` → redirect `/verify-email`
- [ ] Anônimo → renderiza formulário; CSRF bootstrap invocado; `termsVersion` passada ao form
- [ ] HTML/props não contêm Bearer
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: full  
**Commit**: `feat(bff-auth): add register page`

---

### T10: Página `/terms` (RSC)

**What**: Página estática mínima pt-BR exibindo versão atual dos Terms.
**Where**: `frontend/app/terms/page.tsx`, `page.test.tsx`
**Depends on**: T4
**Reuses**: `getAuthTermsCurrentVersion`
**Requirement**: RGR-17, BFFUI-41

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Renderiza versão de `getAuthTermsCurrentVersion()`
- [ ] Texto pt-BR placeholder (conteúdo legal final fora do escopo)
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: quick  
**Commit**: `feat(bff-auth): add terms page`

---

### T11: Foundation gates + quality gates

**What**: Permitir rotas register produto; executar lint e cobertura final.
**Where**: `frontend/modules/shared/lib/foundation-gates.test.ts`; Makefile gates
**Depends on**: T7, T9, T10
**Reuses**: Gate existente FND; `make lint-frontend`, `make test-frontend-coverage`
**Requirement**: RGR-18, Success criteria spec (cobertura ≥75%)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Gate lista `api/bff/auth/register/route.ts`, `register/page.tsx`, `terms/page.tsx` como permitidos
- [ ] Segmentos `verify`, `password` ainda proibidos em prod routes
- [ ] `make lint-frontend` verde
- [ ] `make test-frontend-coverage` verde com ≥75% nos arquivos novos da fatia
- [ ] Nenhum teste silenciosamente removido vs contagem baseline login (222+)

**Tests**: unit (foundation) + none (coverage gate)  
**Gate**: build  
**Commit**: `chore(bff-auth): register slice quality gates`

---

## Phase Execution Map

```
Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4
Phase 2:  T5 ──→ T6
Phase 3:  T7
Phase 4:  T8 ──→ T9 ──→ T10
Phase 5:  T11
```

Execution is strictly sequential — one task at a time, in order.

**Batch packing (Execute):** Batch 1 = Phases 1–3 (T1–T7, ~7 tasks); Batch 2 = Phases 4–5 (T8–T11, ~4 tasks).

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: password schema | 1 schema file + test | ✅ Granular |
| T2: register schema | 1 schema file + test | ✅ Granular |
| T3: auth messages extension | 1 lib modify + test | ✅ Granular |
| T4: auth terms helper | 1 lib file + test | ✅ Granular |
| T5: performBffRegister service | 1 service + tests | ✅ Granular |
| T6: allowlist entry | 1 const + test update | ✅ Granular |
| T7: route handler | 1 route + test | ✅ Granular |
| T8: RegisterForm | 1 component + test | ✅ Granular |
| T9: register page | 1 page + test | ✅ Granular |
| T10: terms page | 1 page + test | ✅ Granular |
| T11: gates + coverage | 1 test file update + Makefile | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | None | (parallel start) | ✅ Match |
| T5 | T3 | T3 → T5 | ✅ Match |
| T6 | T5 | T5 → T6 | ✅ Match |
| T7 | T5, T6 | T6 → T7 | ✅ Match |
| T8 | T2, T3, T4 | T4 → T8 (via deps) | ✅ Match |
| T9 | T4, T8 | T8 → T9 | ✅ Match |
| T10 | T4 | T4 → T10 | ✅ Match |
| T11 | T7, T9, T10 | T9/T10 → T11 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Password Zod schema | unit | unit | ✅ OK |
| T2 | Register Zod schema | unit | unit | ✅ OK |
| T3 | Auth messages | unit | unit | ✅ OK |
| T4 | Auth terms helper | unit | unit | ✅ OK |
| T5 | BFF register service | unit | unit | ✅ OK |
| T6 | Allowlist entry | unit | unit | ✅ OK |
| T7 | Register Route Handler | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T8 | RegisterForm | unit (RTL) | unit (RTL) | ✅ OK |
| T9 | Register page | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T10 | Terms page | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T11 | Foundation gates + coverage | unit + none | unit + none | ✅ OK |

---

## Requirement Traceability (tasks → spec)

| Task | Requirement IDs |
| --- | --- |
| T1 | RGR-08 |
| T2 | RGR-06, RGR-07, RGR-10 |
| T3 | RGR-04, RGR-05, BFFUI-32 |
| T4 | RGR-07, BFFUI-41 |
| T5 | BFFUI-40, BFFUI-15, BFFUI-17, BFFUI-32, RGR-01–04, RGR-11, RGR-12, RGR-15, RGR-16 |
| T6 | RGR-18 |
| T7 | BFFUI-40, RGR-01–03, RGR-12 |
| T8 | BFFUI-41, BFFUI-32, RGR-05–09, RGR-11, RGR-13 |
| T9 | BFFUI-41, RGR-13, RGR-14 |
| T10 | RGR-17, BFFUI-41 |
| T11 | RGR-18, Success criteria (coverage) |
