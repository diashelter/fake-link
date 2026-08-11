# BFF Auth — Login — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/login/design.md`  
**Spec**: `.specs/features/bff-auth/login/spec.md`  
**Status**: Approved — 2026-08-11 (Execute complete)

> **Sub-agent note:** 11 tasks → ~2 batches (~6 + ~5 tasks/worker). Execute MUST offer batch sub-agents before implementation if user accepts.

---

## Task Completion

| Task | Status | Commit |
| --- | --- | --- |
| T1 login schema | ✅ | 7dc2d2f |
| T2 types + messages | ✅ | b4e4183 |
| T3 performBffLogin + getSessionFromRequest | ✅ | cb21caf |
| T4 allowlist | ✅ | 76da130 |
| T5 route handler | ✅ | 00ecfaf |
| T6 CSRF RSC bootstrap | ✅ | fd66fd7 |
| T7 LoginForm | ✅ | 66a144f |
| T8 login page | ✅ | ac82fa2 |
| T9 getSessionFromRequest | ✅ no-op (delivered in T3) | — |
| T10 foundation gates | ✅ | 8286286 |
| T11 quality gates | ✅ | pending validation commit |

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL/MSW Route Handlers), §4 (domínios frontend ≥75%, Auth/BFF ≥80%), §6.2 (Bearer absent, CSRF, returnUrl), `.specs/features/bff-auth/login/spec.md`, amostras `frontend/app/api/bff/_probe/mutate/route.test.ts`, `frontend/modules/shared/lib/form-defaults.test.tsx`, `frontend/modules/auth/services/bff-session.test.ts`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Login Zod schema | unit | LOG-07 client bounds; email trim/lowercase; password max 128 | `frontend/modules/auth/schemas/*.test.ts` | `make test-frontend` |
| Auth API types + messages | unit | Parse guards; unknown token_kind → null; pt-BR map 1:1 codes spec | `frontend/modules/auth/lib/*.test.ts` | `make test-frontend` |
| BFF login service | unit | LOG-01–03, LOG-04–09, LOG-12; all edge cases spec; Bearer absent in JSON.stringify | `frontend/modules/auth/services/bff-login.test.ts` | `make test-frontend` |
| CSRF RSC bootstrap | unit | ensurePreAuthCsrfCookies idempotent; parity issuePreAuthCsrf | `frontend/modules/auth/bff/csrf.test.ts` | `make test-frontend` |
| Allowlist prod entry | unit | LOG-14; exactly one prod entry + probes gated | `frontend/modules/auth/bff/allowlist.test.ts` | `make test-frontend` |
| Login Route Handler | unit (Route Handler) | LOG-01–09, LOG-12; integration via performBffLogin mock/spy | `frontend/app/api/bff/auth/login/route.test.ts` | `make test-frontend` |
| LoginForm (client) | unit (RTL) | LOG-05, LOG-06, LOG-08, LOG-10; validation block; MSW submit | `frontend/modules/auth/components/login-form.test.tsx` | `make test-frontend` |
| Login page (RSC) | unit (RTL/node) | LOG-11; redirect session/verification; CSRF bootstrap spy | `frontend/app/login/page.test.tsx` | `make test-frontend` |
| Session helper | unit | getSessionFromRequest omits bearer | `frontend/modules/auth/services/bff-session.test.ts` | `make test-frontend` |
| Foundation gates | unit | Allows login routes; still forbids register/verify/password prod | `frontend/modules/shared/lib/foundation-gates.test.ts` | `make test-frontend` |
| Barrel / types-only | none | — typecheck gate | — | `make lint-frontend` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks com unit tests isolados (T1–T9) | `make test-frontend` |
| Full | Após Route Handler + UI (T5–T8) | `make test-frontend` |
| Build | Após T10–T11 ou fechamento de fatia | `make lint-frontend && make test-frontend` |
| Coverage | Task T11 | `make test-frontend-coverage` — ≥75% linhas/branches nos arquivos novos da fatia (`modules/auth/schemas`, `lib/auth-*`, `services/bff-login`, `components/login-form`, `app/login`, `app/api/bff/auth/login`) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schemas, types e mensagens

```
T1 → T2
```

### Phase 2: Serviço e allowlist

```
T3 → T4
```

### Phase 3: Route Handler

```
T5
```

### Phase 4: UI (CSRF bootstrap + form + page)

```
T6 → T7 → T8
```

### Phase 5: Gates e fechamento

```
T9 → T10 → T11
```

---

## Task Breakdown

### T1: Schema Zod de login

**What**: Criar `loginSchema` espelhando `LoginRequest` OpenAPI com testes.
**Where**: `frontend/modules/auth/schemas/login-schema.ts`, `login-schema.test.ts`
**Depends on**: None
**Reuses**: `modules/shared/schemas/email.ts`
**Requirement**: LOG-07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `email`: trim, lowercase, max 254, mensagens pt-BR
- [ ] `password`: required, max 128, sem composição
- [ ] Gate: `make test-frontend` passa (schema tests)

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add login zod schema`

---

### T2: Tipos upstream, parser e mensagens pt-BR

**What**: Tipos server-only, `parseUpstreamAuthResponse`, `auth-messages.ts` com mapa de códigos.
**Where**: `frontend/modules/auth/lib/auth-api-types.ts`, `auth-messages.ts`, `*.test.ts`
**Depends on**: T1
**Reuses**: OpenAPI shapes (manual types)
**Requirement**: LOG-04, LOG-05, LOG-06, LOG-08, LOG-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Parser rejeita body sem `data.token` ou `token_kind` inválido
- [ ] `messageForAuthError` cobre INVALID_CREDENTIALS, ACCOUNT_*, RATE_LIMIT, 504/500
- [ ] `formatRetryAfter` retorna string pt-BR ou null
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add auth api types and pt-BR messages`

---

### T3: Serviço `performBffLogin`

**What**: Orquestração completa login BFF (guard, upstream fetch, destroy prior, createSession, resposta sanitizada).
**Where**: `frontend/modules/auth/services/bff-login.ts`, `bff-login.test.ts`; adicionar `getSessionFromRequest` em `bff-session.ts` + testes
**Depends on**: T2
**Reuses**: `assertMutationGuard`, `buildUpstreamUrl`, `createSession`, `destroySession`, `sanitizeReturnUrl`, `issueCsrfForSession`
**Requirement**: BFFUI-30, BFFUI-15, BFFUI-17, BFFUI-23, LOG-01–03, LOG-04, LOG-06, LOG-07, LOG-08, LOG-09, LOG-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Happy path active → `redirect_to` sanitizado; verification → `/verify-email`
- [ ] Matriz 401/403/422/429 repasse; 500/503/504 genérico; guard 403 sem fetch
- [ ] `JSON.stringify` da resposta de sucesso não contém Bearer fixture
- [ ] destroySession chamado quando sessão prévia existe
- [ ] `getSessionFromRequest` retorna `{ sessionId, kind, userId }` sem bearer
- [ ] Gate: `make test-frontend` passa; ≥15 casos no service test

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add performBffLogin service`

---

### T4: Entrada allowlist de login

**What**: Exportar `LOGIN_ALLOWLIST_ENTRY` e registrá-la em `AUTH_BFF_ALLOWLIST`.
**Where**: `frontend/modules/auth/bff/allowlist.ts`, `allowlist.test.ts`
**Depends on**: T3
**Reuses**: Padrão probe entry
**Requirement**: LOG-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `lookupAllowlistEntry('POST', '/api/bff/auth/login')` resolve
- [ ] Teste confirma `requireSession: false`, `requireCsrf: true`
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): register login allowlist entry`

---

### T5: Route Handler `POST /api/bff/auth/login`

**What**: Handler fino delegando a `performBffLogin` com testes co-localizados.
**Where**: `frontend/app/api/bff/auth/login/route.ts`, `route.test.ts`
**Depends on**: T3, T4
**Reuses**: Padrão `_probe/mutate/route.test.ts`
**Requirement**: BFFUI-30, LOG-01–09, LOG-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] POST happy path retorna 200 + Set-Cookie session + body sem token
- [ ] CSRF/Origin failure → 403; upstream mock não invocado
- [ ] Spy/mock de `performBffLogin` ou fetch conforme design
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff-auth): add login bff route handler`

---

### T6: CSRF bootstrap para RSC

**What**: Refatorar `issuePreAuthCsrf` + adicionar `ensurePreAuthCsrfCookies` para Server Components.
**Where**: `frontend/modules/auth/bff/csrf.ts`, `csrf.test.ts`
**Depends on**: T5
**Reuses**: Lógica existente `issuePreAuthCsrf`
**Requirement**: LOG-09, LOG-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `issuePreAuthCsrf` continua passando testes existentes (regressão)
- [ ] `ensurePreAuthCsrfCookies` emite sid+token quando ausentes; idempotente quando válidos
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add pre-auth csrf bootstrap for rsc`

---

### T7: Componente `LoginForm`

**What**: Formulário client RHF+Zod; submit BFF; erros pt-BR; links auxiliares.
**Where**: `frontend/modules/auth/components/login-form.tsx`, `login-form.test.tsx`
**Depends on**: T1, T2, T6
**Reuses**: shared UI, form-defaults, auth-messages
**Requirement**: BFFUI-31, BFFUI-32, LOG-05, LOG-06, LOG-08, LOG-10, LOG-13

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Validação client bloqueia submit inválido sem fetch
- [ ] MSW: 200 → router.push(redirect_to); 401/403/429 mensagens corretas
- [ ] Links `/register` e `/forgot-password` presentes
- [ ] Submit envia CSRF header + credentials include
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL)  
**Gate**: full  
**Commit**: `feat(bff-auth): add login form component`

---

### T8: Página `/login` (RSC)

**What**: Server page com redirect autenticado, bootstrap CSRF, shell pt-BR, `LoginForm`.
**Where**: `frontend/app/login/page.tsx`, `page.test.tsx`
**Depends on**: T6, T7
**Reuses**: `getSessionFromRequest`, `sanitizeReturnUrl`, `ensurePreAuthCsrfCookies`
**Requirement**: BFFUI-31, LOG-10, LOG-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Sessão `session` → redirect `/` ou returnUrl seguro
- [ ] Sessão `verification` → redirect `/verify-email`
- [ ] Anônimo → renderiza formulário; CSRF bootstrap invocado
- [ ] HTML/props não contêm Bearer
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (RTL/node)  
**Gate**: full  
**Commit**: `feat(bff-auth): add login page`

---

### T9: Helper `getSessionFromRequest` (se não completado em T3)

**What**: Garantir helper exportado e testado; completar gaps de T3 se necessário.
**Where**: `frontend/modules/auth/services/bff-session.ts`, `bff-session.test.ts`
**Depends on**: T3
**Reuses**: `getSession`
**Requirement**: LOG-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Tipo retornado **não** inclui `bearer`
- [ ] Testes cobrem cookie ausente, válido, expirado
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add getSessionFromRequest helper`

> **Nota:** Se T3 já entregou helper + testes completos, T9 SHALL ser no-op documentado (atualizar tasks checkbox + commit vazio evitado — marcar done com referência T3).

---

### T10: Atualizar foundation gates

**What**: Permitir rotas login produto; manter proibição register/verify/password.
**Where**: `frontend/modules/shared/lib/foundation-gates.test.ts`
**Depends on**: T5, T8
**Reuses**: Gate existente FND
**Requirement**: LOG-14, FND regression

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Gate lista `api/bff/auth/login/route.ts` e `login/page.tsx` como permitidos
- [ ] Segmentos `register`, `verify`, `password` ainda proibidos em prod routes
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `test(bff-auth): allow login routes in foundation gates`

---

### T11: Lint, cobertura e handoff

**What**: Verificar gates finais; atualizar `.specs/STATE.md` handoff pós-Execute (feito pelo orchestrator após T11).
**Where**: Makefile gates; opcional `README.md` pendente status
**Depends on**: T10
**Reuses**: `make lint-frontend`, `make test-frontend-coverage`
**Requirement**: Success criteria spec (cobertura ≥75%)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `make lint-frontend` verde
- [ ] `make test-frontend-coverage` verde com ≥75% nos arquivos novos da fatia
- [ ] Nenhum teste silenciosamente removido vs contagem baseline

**Tests**: none (gate only)  
**Gate**: build  
**Commit**: `chore(bff-auth): login slice quality gates`

---

## Phase Execution Map

```
Phase 1:  T1 ──→ T2
Phase 2:  T3 ──→ T4
Phase 3:  T5
Phase 4:  T6 ──→ T7 ──→ T8
Phase 5:  T9 ──→ T10 ──→ T11
```

Execution is strictly sequential — one task at a time, in order.

**Batch packing (Execute):** Batch 1 = Phases 1–3 (T1–T5, ~5 tasks); Batch 2 = Phases 4–5 (T6–T11, ~6 tasks).

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: login schema | 1 schema file + test | ✅ Granular |
| T2: types + messages | 2 lib files + tests | ✅ Granular |
| T3: performBffLogin service | 1 service + helper + tests | ✅ Granular |
| T4: allowlist entry | 1 const + test update | ✅ Granular |
| T5: route handler | 1 route + test | ✅ Granular |
| T6: CSRF RSC bootstrap | refactor csrf + tests | ✅ Granular |
| T7: LoginForm | 1 component + test | ✅ Granular |
| T8: login page | 1 page + test | ✅ Granular |
| T9: session helper | 1 function + tests (or no-op) | ✅ Granular |
| T10: foundation gates | 1 test file update | ✅ Granular |
| T11: quality gates | Makefile commands only | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | T3 | T3 → T4 | ✅ Match |
| T5 | T3, T4 | T4 → T5 | ✅ Match |
| T6 | T5 | Phase 4 start | ✅ Match |
| T7 | T1, T2, T6 | T6 → T7 | ✅ Match |
| T8 | T6, T7 | T7 → T8 | ✅ Match |
| T9 | T3 | T3 → T9 (phase 5) | ✅ Match |
| T10 | T5, T8 | T8 → T10 | ✅ Match |
| T11 | T10 | T10 → T11 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Login Zod schema | unit | unit | ✅ OK |
| T2 | Auth types + messages | unit | unit | ✅ OK |
| T3 | BFF login service + session helper | unit | unit | ✅ OK |
| T4 | Allowlist entry | unit | unit | ✅ OK |
| T5 | Login Route Handler | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T6 | CSRF RSC bootstrap | unit | unit | ✅ OK |
| T7 | LoginForm | unit (RTL) | unit (RTL) | ✅ OK |
| T8 | Login page | unit (RTL/node) | unit (RTL/node) | ✅ OK |
| T9 | Session helper | unit | unit | ✅ OK |
| T10 | Foundation gates | unit | unit | ✅ OK |
| T11 | Quality gates | none | none | ✅ OK |

---

## Requirement Traceability (tasks → spec)

| Task | Requirement IDs |
| --- | --- |
| T1 | LOG-07 |
| T2 | LOG-04, LOG-05, LOG-06, LOG-08, LOG-12 |
| T3 | BFFUI-30, BFFUI-15, BFFUI-17, BFFUI-23, LOG-01–03, LOG-04, LOG-06–09, LOG-12 |
| T4 | LOG-14 |
| T5 | BFFUI-30, LOG-01–09, LOG-12 |
| T6 | LOG-09, LOG-10 |
| T7 | BFFUI-31, BFFUI-32, LOG-05, LOG-06, LOG-08, LOG-10, LOG-13 |
| T8 | BFFUI-31, LOG-10, LOG-11 |
| T9 | LOG-11 |
| T10 | LOG-14 |
| T11 | Success criteria (coverage) |
