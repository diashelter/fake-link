# Auth — Sessão e perfil — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/session-and-profile/design.md`  
**Spec**: `.specs/features/auth/session-and-profile/spec.md`  
**Context**: `.specs/features/auth/session-and-profile/context.md`  
**Status**: Execute complete — awaiting Verifier

> **Sub-agent note:** 14 tasks → ~2 batches (~7 + ~7). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG `fake_link_testing`), §4 (80/80 Auth), §6.1 (logout, revogação, perfil), `LARAVEL_CODE_DESIGN.md`, `.specs/features/auth/session-and-profile/spec.md`, `.specs/features/auth/password/tasks.md` (floor de profundidade).

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Domain (`User::withName`) | unit | Imutabilidade; nome atualizado; demais campos intactos | `backend/modules/Auth/Tests/Unit/UserTest.php` | `make test-backend` |
| Persistence (`findProfileById`, mapper name/`updated_at`) | integration | Timestamps reais; update name bumpa `updated_at`; PG testing | `…/Integration/EloquentUserRepositoryTest.php` | `make test-backend` |
| Use case (`LogoutCurrentToken`) | integration | Revoga só o token do principal; outros intactos | `…/Integration/LogoutCurrentTokenTest.php` | `make test-backend` |
| Use case (`LogoutAllSessions`) | integration | Senha ok → revoke all; senha errada → exception sem revoke; user/status intactos | `…/Integration/LogoutAllSessionsTest.php` | `make test-backend` |
| Use case (`GetCurrentUser`) | integration | Profile + timestamps; user missing → exception | `…/Integration/GetCurrentUserTest.php` | `make test-backend` |
| Use case (`UpdateCurrentUser`) | integration | Trimmed rename + bump; no-op sem write/`updated_at`; email imutável | `…/Integration/UpdateCurrentUserTest.php` | `make test-backend` |
| Rate limit factory + `ThrottlePrivateAuthRead` | unit | 301ª → 429; chave HMAC por `AuthTokenId` | `…/HmacRateLimitKeyFactoryTest.php`, `ThrottlePrivateAuthReadTest.php` | `make test-backend` |
| `AuthResponseFactory::user` | unit | Envelope `UserResponse`; timestamps explícitos; headers | `…/Unit/AuthResponseFactoryTest.php` | `make test-backend` |
| Controllers + rotas logout | feature (E2E) | ACs AUTH-30/SP-01/02 + edges + write throttle | `…/Feature/LogoutTest.php` | `make test-backend` |
| Controllers + rotas logout-all | feature (E2E) | ACs AUTH-31/33/SP-03…05 + TOKEN_RESTRICTED + 401 senha | `…/Feature/LogoutAllTest.php` | `make test-backend` |
| Controllers + rotas `/me` | feature (E2E) | ACs AUTH-34…36/SP-06…11 + trim/no-op + read throttle | `…/Feature/CurrentUserTest.php` | `make test-backend` |
| Form Requests / config / provider wiring | none | — (exercidos pelos feature/integration gates) | — | build gate only |
| OpenAPI | none | Paths já publicados; sem schema novo obrigatório | — | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após T1, T3, T8, T9 (unit-only / config) | `make test-backend` |
| Full | Após T2, T4–T7 (integration) | `make test-backend` |
| Build | Após T10 (Form Requests) | `make lint && make test-backend` |
| Final | Após T11–T14 (HTTP E2E + coverage) | `make lint && make test-backend` (+ `make test-backend-coverage` no T14) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Domínio e persistência de perfil

```
T1 → T2 → T3
```

### Phase 2: Use cases

```
T4 → T5 → T6 → T7
```

### Phase 3: Throttle read e response factory

```
T8 → T9
```

### Phase 4: HTTP + Feature E2E + gates

```
T10 → T11 → T12 → T13 → T14
```

---

## Task Breakdown

### T1: Domínio — `User::withName`

**What**: Adicionar `User::withName(string $name): self` imutável; unit tests.  
**Where**: `backend/modules/Auth/Domain/Entities/User.php`, `Tests/Unit/UserTest.php`  
**Depends on**: None  
**Reuses**: `withPasswordHash` / `markEmailVerified`  
**Requirement**: AUTH-35, SP-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `withName` retorna nova instância com nome atualizado
- [x] Demais campos (email, status, hash, termos) permanecem iguais
- [x] Unit tests passam
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add User::withName for profile updates`

---

### T2: Persistência — `UserProfileDto` + `findProfileById` + mapper

**What**: DTO de perfil com timestamps; `UserRepository::findProfileById`; estender `toPersistenceUpdate` para `name` + `updated_at` opcional; integration tests.  
**Where**: `DTOs/Output/UserProfileDto.php`, `Contracts/Repositories/UserRepository.php`, `EloquentUserRepository.php`, `UserMapper.php`, `Tests/Integration/EloquentUserRepositoryTest.php`  
**Depends on**: T1  
**Reuses**: `UserMapper::toDomain`, padrão `update` existente  
**Requirement**: AUTH-34, AUTH-36, SP-06, SP-07, SP-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `findProfileById` retorna User + `created_at`/`updated_at` reais do model
- [x] `update(..., $updatedAt)` persiste `name` e bumpa `updated_at` quando informado
- [x] `update` sem `$updatedAt` (change/verify) **não** exige bump de `updated_at`
- [x] Integration tests cobrem profile + rename + timestamps
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): load user profile with persistence timestamps`

---

### T3: Config + HMAC — leituras privadas

**What**: `auth.rate_limits.private_auth_read` (300/60) e `HmacRateLimitKeyFactory::forPrivateAuthRead(AuthTokenId)`; unit test da chave.  
**Where**: `backend/config/auth.php`, `HmacRateLimitKeyFactory.php`, `Tests/Unit/HmacRateLimitKeyFactoryTest.php`  
**Depends on**: None  
**Reuses**: `forPrivateAuthWrite`  
**Requirement**: SP-06 (throttle), Q4

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Config keys presentes com defaults 300 / 60
- [x] Prefixo HMAC `private-auth:read:{tokenId}`
- [x] Unit test da factory passa
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add private auth read rate limit config`

---

### T4: Use case — `LogoutCurrentToken`

**What**: Revogar somente o Bearer do principal via `RevokeAuthToken::byId`; integration test.  
**Where**: `UseCases/LogoutCurrentToken.php`, `Tests/Integration/LogoutCurrentTokenTest.php`  
**Depends on**: None  
**Reuses**: `RevokeAuthToken`  
**Requirement**: AUTH-30, SP-01, SP-02

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Token do principal removido; outros tokens do mesmo user intactos
- [x] Integration ≥2 casos (isolamento dual-token)
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add LogoutCurrentToken use case`

---

### T5: Use case — `LogoutAllSessions`

**What**: Verificar `current_password` e `RevokeAllUserTokens`; DTO input; integration tests (ok / wrong password).  
**Where**: `UseCases/LogoutAllSessions.php`, `DTOs/Input/LogoutAllSessionsDto.php`, `Tests/Integration/LogoutAllSessionsTest.php`  
**Depends on**: None  
**Reuses**: `ChangePassword` verify + `InvalidCredentialsException`  
**Requirement**: AUTH-31, AUTH-33, SP-03, SP-04, SP-05, SP-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Senha correta → zero `auth_tokens`; user/status/hash inalterados
- [x] Senha incorreta → `InvalidCredentialsException`; tokens intactos
- [x] Sem plaintext de senha em `getMessage()` de exceções do fluxo
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add LogoutAllSessions use case`

---

### T6: Use case — `GetCurrentUser`

**What**: Retornar `UserProfileDto` por `principal.userId`; integration test.  
**Where**: `UseCases/GetCurrentUser.php`, `Tests/Integration/GetCurrentUserTest.php`  
**Depends on**: T2  
**Reuses**: `findProfileById`  
**Requirement**: AUTH-34, AUTH-36, SP-06, SP-07, SP-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Profile com timestamps reais
- [x] User ausente → exceção mapeável a `401 UNAUTHENTICATED`
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add GetCurrentUser use case`

---

### T7: Use case — `UpdateCurrentUser`

**What**: Atualizar `name` com no-op sem write; DTO; integration tests (rename, no-op, imutabilidade e-mail).  
**Where**: `UseCases/UpdateCurrentUser.php`, `DTOs/Input/UpdateCurrentUserDto.php`, `Tests/Integration/UpdateCurrentUserTest.php`  
**Depends on**: T1, T2  
**Reuses**: `User::withName`, `findProfileById`, `update`  
**Requirement**: AUTH-35, SP-09, SP-10, SP-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Nome diferente → persist + `updated_at` avança
- [x] Nome idêntico → retorna profile sem alterar `updated_at`
- [x] E-mail permanece o mesmo
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add UpdateCurrentUser use case`

---

### T8: Middleware — `ThrottlePrivateAuthRead`

**What**: Middleware 300/min por token; alias `throttle.private_auth.read`; unit tests.  
**Where**: `ThrottlePrivateAuthRead.php`, `bootstrap/app.php`, `Tests/Unit/ThrottlePrivateAuthReadTest.php`  
**Depends on**: T3  
**Reuses**: `ThrottlePrivateAuthWrite`  
**Requirement**: SP-06 (429), Q4

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Alias registrado em `bootstrap/app.php`
- [x] 301ª tentativa → `429 RATE_LIMIT_EXCEEDED` + `Retry-After`
- [x] Hit incrementa antes do `$next` (qualquer status)
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add private auth read throttle middleware`

---

### T9: Response — `AuthResponseFactory::user`

**What**: Método `user(UserProfileDto)` → `200` `UserResponse` com timestamps explícitos e headers.  
**Where**: `AuthResponseFactory.php`, `Tests/Unit/AuthResponseFactoryTest.php`  
**Depends on**: T2  
**Reuses**: `AuthUserResource::toArray(..., createdAt, updatedAt)`  
**Requirement**: AUTH-36, SP-06, SP-13

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Body `{ data: User }` com campos OpenAPI e timestamps passados
- [x] Headers `Cache-Control: private, no-store` e `X-Request-ID`
- [x] Unit tests passam
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add AuthResponseFactory::user envelope`

---

### T10: Form Requests — logout, logout-all, update me

**What**: Três Form Requests OpenAPI-aligned (extras → 422; trim de `name`; `current_password` max 128).  
**Where**: `Infrastructure/Http/Requests/LogoutRequest.php`, `LogoutAllRequest.php`, `UpdateCurrentUserRequest.php`  
**Depends on**: None  
**Reuses**: `ChangePasswordRequest` pattern (`ALLOWED_FIELDS` + `withValidator`)  
**Requirement**: Q1, Q2, SP-05, SP-09, SP-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `LogoutRequest`: `{}`/ausente ok; extras → 422
- [x] `LogoutAllRequest`: só `current_password`
- [x] `UpdateCurrentUserRequest`: trim + min 1 / max 120 pós-trim; extras → 422
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: none  
**Gate**: build

> Exercício E2E das regras ocorre em T11–T13 (feature). Matrix marca Form Requests como `none`.

**Commit**: `feat(auth): add session and profile form requests`

---

### T11: HTTP + Feature — logout

**What**: `LogoutController` + rota `POST /auth/logout` (bearer → write throttle) + provider bind + `LogoutTest` Feature cobrindo ACs.  
**Where**: `LogoutController.php`, `routes/auth.php`, `AuthServiceProvider.php`, `Tests/Feature/LogoutTest.php`  
**Depends on**: T4, T8 (write já existe), T9 (`noContent`), T10  
**Reuses**: `ChangePasswordController` thin pattern; probe `_test/auth/*`  
**Requirement**: AUTH-30, SP-01, SP-02, SP-13, SP-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Dual-token: logout A → A `401`, B ok
- [x] Verification token pode fazer logout `204`
- [x] Segundo logout mesmo bearer → `401`
- [x] Extras no body → `422`; headers `Cache-Control`
- [x] Write throttle 429 coberto (ou smoke alinhado a change)
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: final

**Commit**: `feat(auth): wire logout endpoint with feature coverage`

---

### T12: HTTP + Feature — logout-all

**What**: `LogoutAllController` + rota (bearer → `token.kind:session` → write throttle) + `LogoutAllTest` Feature.  
**Where**: `LogoutAllController.php`, `routes/auth.php`, `AuthServiceProvider.php`, `Tests/Feature/LogoutAllTest.php`  
**Depends on**: T5, T10, T11  
**Reuses**: `ChangePasswordController` / `invalidCredentials`  
**Requirement**: AUTH-31, AUTH-33, SP-03, SP-04, SP-05, SP-12, SP-13

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Senha ok → `204`; todos bearers `401` no probe
- [x] Senha errada → `401 INVALID_CREDENTIALS`; tokens intactos
- [x] Verification bearer → `403 TOKEN_RESTRICTED`
- [x] Validação body / extras → `422`
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: final

**Commit**: `feat(auth): wire logout-all endpoint with feature coverage`

---

### T13: HTTP + Feature — GET/PATCH `/me`

**What**: Controllers + `routes/me.php` (grupo `api/v1`) + read throttle no GET + `CurrentUserTest` Feature (trim, no-op, kinds, throttle).  
**Where**: `GetCurrentUserController.php`, `UpdateCurrentUserController.php`, `routes/me.php`, `AuthServiceProvider.php`, `Tests/Feature/CurrentUserTest.php`  
**Depends on**: T6, T7, T8, T9, T10, T12  
**Reuses**: Envelope `user()`; probe/login helpers  
**Requirement**: AUTH-34…36, SP-06…SP-11, SP-13, SP-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] GET session e verification → `200` + campos OpenAPI + timestamps reais
- [x] Pending → `status=pending_verification`, `email_verified_at=null`
- [x] PATCH session renomeia; `"  Ana  "` → `"Ana"`; no-op sem bump `updated_at`
- [x] PATCH verification → `403`; extras/`email` → `422` sem mudar e-mail
- [x] Read throttle 429 no GET
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: final

**Commit**: `feat(auth): wire me endpoints with feature coverage`

---

### T14: Gates finais — lint, testes e cobertura Auth

**What**: Gate final da fatia; cobertura Auth ≥ 80% linhas/métodos; atualizar índice/spec status se necessário.  
**Where**: (sem código de produto obrigatório); rodar gates Docker  
**Depends on**: T11, T12, T13  
**Reuses**: `backend/scripts/check-auth-coverage-gate.php`  
**Requirement**: SP-14, SP-15, SP-16

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `make lint && make test-backend` verde sem regressão
- [x] `make test-backend-coverage` + gate Auth ≥ 80%
- [x] Success criteria da spec checáveis cobertos pelos Feature tests
- [x] OpenAPI paths existentes permanecem alinhados (smoke visual / sem divergência de method/status)

**Tests**: none  
**Gate**: final

**Commit**: `test(auth): close session-and-profile quality gates`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4

Phase 1:  T1 ──→ T2 ──→ T3
Phase 2:  T4 ──→ T5 ──→ T6 ──→ T7
Phase 3:  T8 ──→ T9
Phase 4:  T10 ──→ T11 ──→ T12 ──→ T13 ──→ T14
```

**Suggested batches (~7 tasks):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | 1–2 | T1–T7 |
| 2 | 3–4 | T8–T14 |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1 | 1 domain method + unit | ✅ Granular |
| T2 | DTO + repo method + mapper (cohesive persistence) | ✅ OK cohesive |
| T3 | Config + 1 factory method + unit | ✅ Granular |
| T4 | 1 use case + integration | ✅ Granular |
| T5 | 1 use case + DTO + integration | ✅ Granular |
| T6 | 1 use case + integration | ✅ Granular |
| T7 | 1 use case + DTO + integration | ✅ Granular |
| T8 | 1 middleware + alias + unit | ✅ Granular |
| T9 | 1 factory method + unit | ✅ Granular |
| T10 | 3 Form Requests same concern | ✅ OK cohesive |
| T11 | 1 endpoint + Feature E2E (co-located) | ✅ OK cohesive |
| T12 | 1 endpoint + Feature E2E | ✅ OK cohesive |
| T13 | 2 related `/me` endpoints + Feature E2E | ✅ OK cohesive |
| T14 | Final quality gate | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | Phase1 start | ✅ |
| T2 | T1 | T1→T2 | ✅ |
| T3 | None | Phase1 (após T2 na ordem; sem dep) | ✅ |
| T4 | None | Phase2 start | ✅ |
| T5 | None | Phase2 | ✅ |
| T6 | T2 | após Phase1; T5→T6 na ordem | ✅ |
| T7 | T1, T2 | T6→T7; deps Phase1 | ✅ |
| T8 | T3 | Phase3; T3 prior | ✅ |
| T9 | T2 | T8→T9; T2 prior | ✅ |
| T10 | None | Phase4 start | ✅ |
| T11 | T4, T8, T9, T10 | T10→T11; deps prior | ✅ |
| T12 | T5, T10, T11 | T11→T12 | ✅ |
| T13 | T6, T7, T8, T9, T10, T12 | T12→T13 | ✅ |
| T14 | T11, T12, T13 | T13→T14 | ✅ |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Domain User | unit | unit | ✅ OK |
| T2 | Persistence profile | integration | integration | ✅ OK |
| T3 | Config + rate factory | unit | unit | ✅ OK |
| T4 | Use case LogoutCurrentToken | integration | integration | ✅ OK |
| T5 | Use case LogoutAllSessions | integration | integration | ✅ OK |
| T6 | Use case GetCurrentUser | integration | integration | ✅ OK |
| T7 | Use case UpdateCurrentUser | integration | integration | ✅ OK |
| T8 | Middleware read throttle | unit | unit | ✅ OK |
| T9 | AuthResponseFactory::user | unit | unit | ✅ OK |
| T10 | Form Requests | none | none | ✅ OK |
| T11 | Controller + rota logout | feature | feature | ✅ OK |
| T12 | Controller + rota logout-all | feature | feature | ✅ OK |
| T13 | Controllers + rotas `/me` | feature | feature | ✅ OK |
| T14 | Gates only | none | none | ✅ OK |

---

## Requirement Traceability (task mapping)

| Requirement ID | Tasks |
| --- | --- |
| AUTH-30 | T4, T11 |
| AUTH-31 | T5, T12 |
| AUTH-33 | T5, T12 |
| AUTH-34 | T2, T6, T13 |
| AUTH-35 | T1, T7, T13 |
| AUTH-36 | T2, T6, T9, T13 |
| SP-01 … SP-02 | T4, T11 |
| SP-03 … SP-05 | T5, T12 |
| SP-06 … SP-08 | T3, T6, T8, T9, T13 |
| SP-09 … SP-11 | T1, T7, T10, T13 |
| SP-12 | T5, T12 |
| SP-13 | T9, T11–T13 |
| SP-14 … SP-16 | T11–T14 |

**Coverage:** 22 spec IDs mapped; 0 unmapped.
