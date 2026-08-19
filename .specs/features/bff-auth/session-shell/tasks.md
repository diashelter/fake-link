# BFF Auth — Sessão e shell — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/session-shell/design.md`  
**Spec**: `.specs/features/bff-auth/session-shell/spec.md`  
**Status**: Approved — ready for Execute

> **Sub-agent note:** 21 tasks → pack into batches (~7 tasks, whole phases). Suggested: Batch 1 T1–T6 (Phase 1); Batch 2 T7–T12 (Phases 2–3); Batch 3 T13–T16 (Phase 4); Batch 4 T17–T21 (Phases 5–6). Execute MUST offer batch sub-agents before implementation if the user accepts.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL/MSW Route Handlers), §4 (domínios frontend ≥75%, Auth/BFF ≥80%), §6.2 (Bearer absent, CSRF, Redis flush), amostras `frontend/modules/auth/services/bff-password-change.test.ts`, `frontend/app/api/bff/auth/login/route.test.ts`, `frontend/modules/auth/components/change-password-form.test.tsx`, `frontend/modules/auth/lib/verification-guard.test.ts`, `frontend/modules/auth/bff/allowlist.test.ts`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Profile Zod schema | unit | SH-27; name trim 1–120; empty/whitespace fail | `frontend/modules/auth/schemas/update-profile-schema.test.ts` | `make test-frontend` |
| Logout-all Zod schema | unit | SH-28; current_password required max 128 | `frontend/modules/auth/schemas/logout-all-schema.test.ts` | `make test-frontend` |
| Logout metrics | unit | SH-25; increment upstream/redis getters | `frontend/modules/auth/lib/session/metrics.test.ts` | `make test-frontend` |
| clearCsrfCookies | unit | SH-01 cookies CSRF expirados | `frontend/modules/auth/bff/csrf.test.ts` | `make test-frontend` |
| Account / verification guard | unit | SH-18–20; `/settings` prefix; `/settings` not in VERIFICATION_ALLOWED_PATHS | `frontend/modules/auth/lib/account-guard.test.ts` + `verification-guard.test.ts` | `make test-frontend` |
| Allowlist | unit | SH-24; 11 entradas; GET me requireCsrf false | `frontend/modules/auth/bff/allowlist.test.ts` | `make test-frontend` |
| BFF logout service | unit | SH-01–05, edges 429/401/miss/Origin; Bearer absent; counters | `frontend/modules/auth/services/bff-logout.test.ts` | `make test-frontend` |
| BFF logout-all service | unit | SH-06–09, SH-21; senha errada sem destroy; 429 Retry-After | `frontend/modules/auth/services/bff-logout-all.test.ts` | `make test-frontend` |
| BFF me service | unit | SH-10–13; GET verification; PATCH extras 400; GET sem CSRF | `frontend/modules/auth/services/bff-me.test.ts` | `make test-frontend` |
| Logout Route Handler | unit | SH-01 via service | `frontend/app/api/bff/auth/logout/route.test.ts` | `make test-frontend` |
| Logout-all Route Handler | unit | SH-06 via service | `frontend/app/api/bff/auth/logout-all/route.test.ts` | `make test-frontend` |
| Me Route Handler | unit | SH-10 GET+PATCH methods | `frontend/app/api/bff/auth/me/route.test.ts` | `make test-frontend` |
| LogoutButton | unit (RTL) | SH-16; Content-Type+CSRF; push /login | `frontend/modules/auth/components/logout-button.test.tsx` | `make test-frontend` |
| LogoutAllForm | unit (RTL) | SH-17, SH-23; 401 campo; 429 com/sem Retry-After | `frontend/modules/auth/components/logout-all-form.test.tsx` | `make test-frontend` |
| ProfileForm | unit (RTL) | SH-14–15, SH-22; email readonly; PATCH headers | `frontend/modules/auth/components/profile-form.test.tsx` | `make test-frontend` |
| AuthenticatedShell | unit (RTL) | SH-16, SH-19; nav links | `frontend/modules/auth/components/authenticated-shell.test.tsx` | `make test-frontend` |
| Settings page/layout | unit | SH-14, SH-18, ACCOUNT_* redirect | `frontend/app/settings/page.test.tsx` | `make test-frontend` |
| Home page | unit | SH-19 guest vs session chrome | `frontend/app/page.test.tsx` | `make test-frontend` |
| Verify-email Sair | unit (RTL) | SH-17 | `frontend/modules/auth/components/verify-email-form.test.tsx` | `make test-frontend` |
| Settings password + shell | unit | SH-19 nav visível; form change intacto | `frontend/app/settings/password/page.test.tsx` | `make test-frontend` |
| Foundation gates | unit | SH-26 rotas produto | `frontend/modules/shared/lib/foundation-gates.test.ts` | `make test-frontend` |
| Quality gates | none | Makefile | — | `make lint-frontend && make test-frontend-coverage` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | T1–T9 (schemas, metrics, services) | `make test-frontend` |
| Full | T10–T20 (handlers + UI + pages) | `make test-frontend` |
| Build | T21 / fim de fase | `make lint-frontend && make test-frontend` |
| Coverage | T21 | `make test-frontend-coverage` — ≥75% linhas/branches nos arquivos novos desta fatia |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Foundation

```
T1 → T2 → T3 → T4 → T5 → T6
```

### Phase 2: Services

```
T7 → T8 → T9
```

### Phase 3: Route Handlers

```
T10 → T11 → T12
```

### Phase 4: UI components

```
T13 → T14 → T15 → T16
```

### Phase 5: Pages

```
T17 → T18 → T19 → T20
```

### Phase 6: Gates

```
T21
```

---

## Task Breakdown

### T1: Schema Zod update profile

**What**: Criar `updateProfileSchema` (name trim 1–120) com testes.
**Where**: `frontend/modules/auth/schemas/update-profile-schema.ts`, `update-profile-schema.test.ts`
**Depends on**: None
**Reuses**: Padrão Zod das fatias password
**Requirement**: SH-27, BFFUI-73

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Whitespace-only e >120 falham; trim aplica
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add update profile zod schema`

---

### T2: Schema Zod logout-all

**What**: Criar `logoutAllSchema` com `current_password` required max 128.
**Where**: `frontend/modules/auth/schemas/logout-all-schema.ts`, `logout-all-schema.test.ts`
**Depends on**: None
**Reuses**: Bounds de `change-password-schema` (`current_password`)
**Requirement**: SH-28, BFFUI-71

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Vazio e >128 falham; sem política de composição
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add logout-all zod schema`

---

### T3: Contadores de logout

**What**: Estender `metrics.ts` com `incrementLogoutUpstreamFail` / `incrementLogoutRedisFail` e getters de teste.
**Where**: `frontend/modules/auth/lib/session/metrics.ts`, `metrics.test.ts`
**Depends on**: None
**Reuses**: `incrementDecryptFail` pattern
**Requirement**: SH-25, BFFUI-70

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Incrementos isolados; getters refletem totais
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add logout failure metrics hooks`

---

### T4: `clearCsrfCookies`

**What**: Expirar cookies CSRF `__Host-fl_csrf` e `__Host-fl_csrf_sid` no response.
**Where**: `frontend/modules/auth/bff/csrf.ts`, `csrf.test.ts`
**Depends on**: None
**Reuses**: `issueCsrfForSession` / cookie options
**Requirement**: SH-01, BFFUI-70

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] `maxAge: 0` nos dois cookies; teste de Set-Cookie
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): clear csrf cookies on logout`

---

### T5: Account page guard

**What**: Helper `isAccountPath` + `resolveAccountPageGuard`; garantir `/settings` fora de `VERIFICATION_ALLOWED_PATHS`.
**Where**: `frontend/modules/auth/lib/account-guard.ts`, `account-guard.test.ts`; regressão `verification-guard.test.ts`
**Depends on**: None
**Reuses**: `resolveVerificationSessionGuard`
**Requirement**: SH-18, SH-19, SH-20, BFFUI-74

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Guest em `/settings` e `/settings/password` → `/login`
- [x] `verification` nesses paths → `/verify-email`
- [x] `/settings` **não** está em `VERIFICATION_ALLOWED_PATHS`
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add account route guard helper`

---

### T6: Allowlist logout / me

**What**: Quatro entradas na `AUTH_BFF_ALLOWLIST` (length 11) e testes.
**Where**: `frontend/modules/auth/bff/allowlist.ts`, `allowlist.test.ts`
**Depends on**: None
**Reuses**: Tipo `AllowlistEntry`
**Requirement**: SH-24, BFFUI-70–72

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Paths/upstream/requireSession/requireCsrf conforme spec
- [x] GET `/api/bff/auth/me` lookup funciona
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): allowlist logout and me routes`

---

### T7: Serviço `performBffLogout`

**What**: Implementar logout best-effort com guard especial (Origin; CSRF se sessão; miss → 200).
**Where**: `frontend/modules/auth/services/bff-logout.ts`, `bff-logout.test.ts`
**Depends on**: T3, T4, T6
**Reuses**: `validateMutationOrigin`, session facade, `jsonWithPrivateCache`
**Requirement**: SH-01, SH-02, SH-03, SH-04, SH-05, BFFUI-70, SH-21

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Happy path 204 → 200 + clear session/CSRF + destroy
- [x] Redis fail + Laravel ok → 200 + redis counter
- [x] Laravel timeout/5xx → 200 + upstream counter
- [x] Miss → 200 sem fetch; Origin inválido → 403 sem clear
- [x] CSRF inválido com sessão → 403 sem clear
- [x] Laravel 401/429 → 200 local **sem** upstream counter
- [x] Bearer/sentinela ausentes do JSON
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add best-effort logout service`

---

### T8: Serviço `performBffLogoutAll`

**What**: Logout-all com `loadSessionMutationContext`, Zod, destroy só em 204.
**Where**: `frontend/modules/auth/services/bff-logout-all.ts`, `bff-logout-all.test.ts`
**Depends on**: T2, T4, T6
**Reuses**: `loadSessionMutationContext`, T4
**Requirement**: SH-06, SH-07, SH-08, SH-09, SH-21, BFFUI-71

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] 204 → 200 envelope + clear cookies
- [x] 401 INVALID_CREDENTIALS → sem destroy
- [x] kind verification → 403 zero fetch
- [x] extras/malformed → 400 zero fetch
- [x] 429 pass-through + Retry-After; 5xx sem destroy
- [x] destroy fail após 204 ainda clear cookie
- [x] Senha sentinela ausente do JSON
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add logout-all bff service`

---

### T9: Serviço `performBffMe`

**What**: GET (session\|verification, sem CSRF) e PATCH (session, name strict).
**Where**: `frontend/modules/auth/services/bff-me.ts`, `bff-me.test.ts`
**Depends on**: T1, T6
**Reuses**: `loadSessionMutationContext` no PATCH; `getSession` no GET
**Requirement**: SH-10, SH-11, SH-12, SH-13, BFFUI-72, SH-21

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] GET session e verification 200 pass-through User; sem CSRF
- [x] GET miss → 403
- [x] PATCH name 200; extras/email → 400 zero fetch
- [x] PATCH verification → 403
- [x] Bearer ausente
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff-auth): add me get and patch service`

---

### T10: Route Handler logout

**What**: `POST app/api/bff/auth/logout/route.ts` delegando ao serviço.
**Where**: `frontend/app/api/bff/auth/logout/route.ts`, `route.test.ts`
**Depends on**: T7
**Reuses**: Padrão `login/route.ts`
**Requirement**: SH-01, BFFUI-70

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] POST chama `performBffLogout`; outros métodos 405 se o projeto já padroniza
- [x] Teste de integração fina com service mock/spy
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add logout route handler`

---

### T11: Route Handler logout-all

**What**: `POST .../logout-all/route.ts`.
**Where**: `frontend/app/api/bff/auth/logout-all/route.ts`, `route.test.ts`
**Depends on**: T8
**Reuses**: T10 pattern
**Requirement**: SH-06, BFFUI-71

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] POST → `performBffLogoutAll`
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add logout-all route handler`

---

### T12: Route Handler me

**What**: `GET` e `PATCH app/api/bff/auth/me/route.ts`.
**Where**: `frontend/app/api/bff/auth/me/route.ts`, `route.test.ts`
**Depends on**: T9
**Reuses**: T10 pattern
**Requirement**: SH-10, SH-11, BFFUI-72

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] GET e PATCH despacham o serviço; POST 405
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add me route handler`

---

### T13: `LogoutButton`

**What**: Client button/form POST logout com JSON + CSRF e `router.push('/login')`.
**Where**: `frontend/modules/auth/components/logout-button.tsx`, `logout-button.test.tsx`
**Depends on**: T10
**Reuses**: `readClientCookie`, change-password fetch pattern
**Requirement**: SH-16, L-046, BFFUI-70

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Assert `Content-Type` + `X-CSRF-Token` + body JSON
- [x] 200 → push `/login`; 403 copy genérica sem dizer CSRF
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add logout button`

---

### T14: `LogoutAllForm`

**What**: Formulário senha atual → POST logout-all.
**Where**: `frontend/modules/auth/components/logout-all-form.tsx`, `logout-all-form.test.tsx`
**Depends on**: T2, T11
**Reuses**: `applyServerFieldErrors`, `formatRetryAfter`, change-password 401 copy
**Requirement**: SH-17, SH-22, SH-23, BFFUI-71

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] 200 → `/login`; 401 campo senha; permanece autenticado no MSW 401
- [x] 429 com Retry-After ≠ copy sem header
- [x] Headers L-046
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add logout-all form`

---

### T15: `ProfileForm`

**What**: Nome editável, e-mail read-only, PATCH me.
**Where**: `frontend/modules/auth/components/profile-form.tsx`, `profile-form.test.tsx`
**Depends on**: T1, T12
**Reuses**: FormField, T14 error/429 pattern
**Requirement**: SH-14, SH-15, SH-22, SH-23, BFFUI-73

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [x] Email não é `<input>` editável (readonly ou texto)
- [x] PATCH headers L-046; 200 atualiza nome sem mudar email
- [x] Zod bloqueia submit inválido sem fetch
- [x] 429 com e sem Retry-After
- [x] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add profile name form`

---

### T16: `AuthenticatedShell`

**What**: Nav Início `/`, Conta `/settings`, slot children, `LogoutButton`.
**Where**: `frontend/modules/auth/components/authenticated-shell.tsx`, `authenticated-shell.test.tsx`
**Depends on**: T13
**Reuses**: primitivos shared, pt-BR
**Requirement**: SH-16, SH-19, BFFUI-74

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [ ] Links corretos; inclui Sair
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add authenticated shell nav`

---

### T17: Páginas `/settings` (layout + perfil)

**What**: Layout com account guard + shell; page hidrata User via serviço GET me; ACCOUNT_* força logout local + redirect login.
**Where**: `frontend/app/settings/layout.tsx`, `page.tsx`, `page.test.tsx`
**Depends on**: T5, T9, T14, T15, T16
**Reuses**: `getSessionFromRequest`, password page guard
**Requirement**: SH-14, SH-18, SH-20, BFFUI-73, BFFUI-74

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [ ] Guest → `/login`; verification → `/verify-email`
- [ ] Session renderiza perfil + logout-all + link `/settings/password`
- [ ] ACCOUNT_* → destroy/clear + `/login`
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add settings profile page`

---

### T18: Home autenticada vs landing

**What**: `app/page.tsx` — `session` usa shell + placeholder; guest mantém landing; verification helper existente.
**Where**: `frontend/app/page.tsx`, `page.test.tsx`
**Depends on**: T5, T16
**Reuses**: `resolveVerificationSessionGuard`
**Requirement**: SH-19, BFFUI-74

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [ ] Guest: landing (CTA Começar permanece)
- [ ] Session: shell, **sem** CTA anônimo como único chrome
- [ ] Verification: redirect `/verify-email`
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add authenticated home shell`

---

### T19: Sair em `/verify-email`

**What**: Incluir `LogoutButton` na UI de verificação.
**Where**: `frontend/modules/auth/components/verify-email-form.tsx` (ou page) + testes existentes
**Depends on**: T13
**Reuses**: T13
**Requirement**: SH-17, BFFUI-70

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [ ] RTL encontra Sair; GET da página ainda não chama verify
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): add logout to verify-email page`

---

### T20: Shell em `/settings/password`

**What**: Remover chrome duplicado da password page para herdar `settings/layout.tsx`; atualizar testes.
**Where**: `frontend/app/settings/password/page.tsx`, `page.test.tsx`
**Depends on**: T17
**Reuses**: `ChangePasswordForm`
**Requirement**: SH-19, BFFUI-74

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [ ] Página não duplica h1/nav do shell de forma quebrada; form change permanece
- [ ] Testes: session vê nav Conta/Sair; guest/verification redirects (layout)
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: full  
**Commit**: `feat(bff-auth): wrap password settings in account shell`

---

### T21: Foundation gates e cobertura

**What**: Atualizar `foundation-gates.test.ts` para rotas/handlers novos; `make lint-frontend` + coverage ≥75% nos arquivos da fatia.
**Where**: `frontend/modules/shared/lib/foundation-gates.test.ts`
**Depends on**: T12, T17, T18, T19, T20
**Reuses**: gates password/login
**Requirement**: SH-26

**Tools**: MCP: NONE · Skill: `tlc-spec-driven`

**Done when**:

- [ ] Gates conhecem `/settings`, logout, me
- [ ] `make lint-frontend` passa
- [ ] `make test-frontend-coverage` ≥75% no escopo novo
- [ ] Test count não cai por deleção silenciosa

**Tests**: none (quality gate)  
**Gate**: build  
**Commit**: `test(bff-auth): allow session-shell routes in foundation gates`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5 ──→ T6
Phase 2:  T7 ──→ T8 ──→ T9
Phase 3:  T10 ─→ T11 ─→ T12
Phase 4:  T13 ─→ T14 ─→ T15 ─→ T16
Phase 5:  T17 ─→ T18 ─→ T19 ─→ T20
Phase 6:  T21
```

Execution is strictly sequential. Packing: Batch 1 = Phase 1 (6); Batch 2 = Phases 2–3 (6); Batch 3 = Phase 4 (4); Batch 4 = Phases 5–6 (5).

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1 Schema profile | 1 schema | ✅ Granular |
| T2 Schema logout-all | 1 schema | ✅ Granular |
| T3 Metrics | 1 módulo | ✅ Granular |
| T4 clearCsrf | 1 função | ✅ Granular |
| T5 Account guard | 1 helper | ✅ Granular |
| T6 Allowlist | 1 tabela | ✅ Granular |
| T7 Logout service | 1 serviço | ✅ Granular |
| T8 Logout-all service | 1 serviço | ✅ Granular |
| T9 Me service | 1 serviço GET+PATCH | ⚠️ Coeso (um recurso `/me`) |
| T10–T12 Handlers | 1 endpoint cada | ✅ Granular |
| T13–T16 UI | 1 componente cada | ✅ Granular |
| T17 Settings layout+page | 2 arquivos mesma rota | ⚠️ Coeso |
| T18 Home | 1 page | ✅ Granular |
| T19 Verify Sair | 1 integração | ✅ Granular |
| T20 Password chrome | 1 page | ✅ Granular |
| T21 Gates | 1 test file + coverage | ✅ Granular |

**Granularity check**: nenhuma tarefa ❌.

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | início Phase 1 | ✅ Match |
| T2 | None | após T1 (ordem, sem dep lógica) | ✅ Match |
| T3 | None | após T2 | ✅ Match |
| T4 | None | após T3 | ✅ Match |
| T5 | None | após T4 | ✅ Match |
| T6 | None | após T5 | ✅ Match |
| T7 | T3, T4, T6 | Phase 2 após Phase 1 | ✅ Match |
| T8 | T2, T4, T6 | T7→T8 (T2/T4/T6 já na Phase 1) | ✅ Match |
| T9 | T1, T6 | T8→T9 | ✅ Match |
| T10 | T7 | Phase 3 após T7 | ✅ Match |
| T11 | T8 | T10→T11 | ✅ Match |
| T12 | T9 | T11→T12 | ✅ Match |
| T13 | T10 | Phase 4 após T10 | ✅ Match |
| T14 | T2, T11 | T13→T14 | ✅ Match |
| T15 | T1, T12 | T14→T15 | ✅ Match |
| T16 | T13 | T15→T16 | ✅ Match |
| T17 | T5, T9, T14, T15, T16 | Phase 5 após Phase 4 | ✅ Match |
| T18 | T5, T16 | T17→T18 | ✅ Match |
| T19 | T13 | T18→T19 | ✅ Match |
| T20 | T17 | T19→T20 | ✅ Match |
| T21 | T12, T17, T18, T19, T20 | Phase 6 | ✅ Match |

T2–T6 são independentes entre si; o diagrama lineariza a ordem de execução (sem paralelismo), o que é permitido pelo protocolo.

---

## Test Co-location Validation

| Task | Code Layer | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Profile schema | unit | unit | ✅ OK |
| T2 | Logout-all schema | unit | unit | ✅ OK |
| T3 | Metrics | unit | unit | ✅ OK |
| T4 | csrf helper | unit | unit | ✅ OK |
| T5 | Account guard | unit | unit | ✅ OK |
| T6 | Allowlist | unit | unit | ✅ OK |
| T7 | Logout service | unit | unit | ✅ OK |
| T8 | Logout-all service | unit | unit | ✅ OK |
| T9 | Me service | unit | unit | ✅ OK |
| T10 | Logout RH | unit | unit | ✅ OK |
| T11 | Logout-all RH | unit | unit | ✅ OK |
| T12 | Me RH | unit | unit | ✅ OK |
| T13 | LogoutButton | unit (RTL) | unit | ✅ OK |
| T14 | LogoutAllForm | unit (RTL) | unit | ✅ OK |
| T15 | ProfileForm | unit (RTL) | unit | ✅ OK |
| T16 | Shell | unit (RTL) | unit | ✅ OK |
| T17 | Settings pages | unit | unit | ✅ OK |
| T18 | Home | unit | unit | ✅ OK |
| T19 | Verify-email | unit (RTL) | unit | ✅ OK |
| T20 | Password page | unit | unit | ✅ OK |
| T21 | Quality gates | none | none | ✅ OK |

---

## Requirement mapping (tasks)

| IDs | Tasks |
| --- | --- |
| BFFUI-70, SH-01–05 | T3, T4, T6, T7, T10, T13, T19 |
| BFFUI-71, SH-06–09 | T2, T8, T11, T14 |
| BFFUI-72, SH-10–13 | T1, T9, T12 |
| BFFUI-73, SH-14–15 | T15, T17 |
| BFFUI-74, SH-18–20 | T5, T16, T17, T18, T20 |
| SH-16–17 | T13, T14, T16, T19 |
| SH-21 | T7, T8, T9 |
| SH-22–23 | T14, T15 |
| SH-24 | T6 |
| SH-25 | T3, T7 |
| SH-26 | T21 |
| SH-27 | T1 |
| SH-28 | T2 |
