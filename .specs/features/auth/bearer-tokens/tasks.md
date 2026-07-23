# Auth — Tokens Bearer — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/bearer-tokens/design.md`  
**Spec**: `.specs/features/auth/bearer-tokens/spec.md`  
**Status**: Approved — aguardando Execute

> **Sub-agent note:** 18 tasks → ~3 batches (~6 tasks/worker). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG integration), §3.1 (Pest Arch, unit/feature/integration), §4 (80/80 Auth), §6.1 (Bearer, idle, ownership, token kind), `LARAVEL_CODE_DESIGN.md` §26, `.specs/features/auth/bearer-tokens/spec.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Domain VOs/enums (`AuthTokenId`, `TokenKind`, `AuthToken`) | unit | 1:1 spec BT-02; UUID v7; TTL/idle helpers; kinds `verification`/`session` | `backend/modules/Auth/Tests/Unit/**/*Test.php` | `make test-backend` |
| Domain service (`BearerTokenGenerator`) | unit | Entropia/formato base64url; length estável | `backend/modules/Auth/Tests/Unit/BearerTokenGeneratorTest.php` | `make test-backend` |
| Infrastructure hasher (`Sha256TokenHasher`) | unit | BT-03; hash 64 hex; verify round-trip; plaintext ≠ hash | `backend/modules/Auth/Tests/Unit/Sha256TokenHasherTest.php` | `make test-backend` |
| Use cases (`IssueAuthToken`, `ValidateAuthToken`, `Revoke*`) | integration | 1:1 spec ACs AUTH-13…19, BT-04…12; PG `fake_link_testing`; Carbon frozen para TTL/idle/throttle | `backend/modules/Auth/Tests/Integration/**/*Test.php` | `make test-backend` |
| Repository (`EloquentAuthTokenRepository`) | integration | save/find/delete/touchLastUsedAtIfStale; UNIQUE hash; FK user | `backend/modules/Auth/Tests/Integration/EloquentAuthTokenRepositoryTest.php` | `make test-backend` |
| Middleware + rotas teste (`AuthenticateBearer`, `RequireTokenKind`) | feature (E2E) | BT-06–10, BT-16; 401/403 JSON; probe routes; todos edge cases HTTP da spec | `backend/modules/Auth/Tests/Feature/**/*Test.php` | `make test-backend` |
| Ownership (`AuthorizesOwnedResource`) | unit | BT-14/AUTH-38; mismatch → `ResourceNotFoundException` | `backend/modules/Auth/Tests/Unit/AuthorizesOwnedResourceTest.php` | `make test-backend` |
| Plaintext discipline (exceções) | unit | BT-15; sentinel token ausente de messages | `backend/modules/Auth/Tests/Unit/AuthTokenExceptionTest.php` | `make test-backend` |
| Migration `auth_tokens` | integration | BT-01; schema via migrate + contract test | `backend/modules/Auth/Tests/Integration/AuthTokensSchemaContractTest.php` | `make test-backend` |
| Contracts / DTOs / factory wiring | none | — (cobertos indiretamente) | — | build gate only |
| `AuthServiceProvider` bindings | none | — (gate via integration/feature) | — | build gate only |
| Pest Arch Auth | architecture | Consumidores não importam Eloquent Auth | `backend/tests/Architecture/ModularMonolithTest.php` | `make lint` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks só de domain/contracts sem I/O novo (T2, T3 parcial) | `make test-backend` |
| Full | Após tasks com unit/integration (T3–T11) | `make test-backend` |
| Build | Após tasks HTTP + composição (T12–T17) | `make lint && make test-backend` |
| Coverage | Task final T18 | `make test-backend-coverage` — meta ≥80% linhas e ≥80% branches em código novo de tokens |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schema e domínio (BT-01, BT-02)

```
T1 → T2
```

### Phase 2: Hashing e persistência (BT-03, BT-17)

```
T3 → T4 → T5
```

### Phase 3: User lookup para validação

```
T6
```

### Phase 4: Use cases emissão e revogação (BT-04, BT-11, BT-12)

```
T7 → T8
```

### Phase 5: Validação, identidade e ownership (BT-05, BT-07, BT-08, BT-13, BT-14)

```
T9 → T10 → T11
```

### Phase 6: Camada HTTP (BT-06, BT-09, BT-10, BT-16)

```
T12 → T13 → T14 → T15
```

### Phase 7: Composição, segurança e gates finais (BT-15, BT-18)

```
T16 → T17 → T18
```

---

## Task Breakdown

### T1: Migration `auth_tokens`

**What**: Criar migration PostgreSQL `auth_tokens` com UUID v7, FK `users`, CHECK `token_kind`, UNIQUE `token_hash`.  
**Where**: `backend/database/migrations/` (via `php artisan make:migration` no container)  
**Depends on**: None  
**Reuses**: Padrão migration `users` da foundation; AD-012  
**Requirement**: BT-01, AUTH-13

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Migration criada com `id uuid PK`, `user_id uuid FK RESTRICT`, `token_hash char(64) UNIQUE`, `token_kind` CHECK, `expires_at`, `last_used_at`, `created_at`
- [ ] Índice em `user_id`
- [ ] Teste integração `AuthTokensSchemaContractTest` confirma tabela/constraints após migrate em `fake_link_testing`
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add auth_tokens migration`

---

### T2: Domínio — `TokenKind`, `AuthTokenId`, `AuthToken`

**What**: Enum `TokenKind`, VO `AuthTokenId` (UUID v7), entidade `AuthToken` com helpers TTL absoluto/idle.  
**Where**: `backend/modules/Auth/Domain/{Enums,ValueObjects,Entities}/`  
**Depends on**: T1  
**Reuses**: Padrão `UserId`, `UserStatus` da foundation  
**Requirement**: BT-02, AUTH-13, AUTH-15, AUTH-16

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] `TokenKind` expõe `verification` e `session` com constantes idle (1h/24h) e absolute (24h/7d)
- [ ] `AuthTokenId::fromString` rejeita não-v7
- [ ] `AuthToken` helpers `isExpiredAt`, `isIdleExpiredAt` cobrem limite exclusivo idle
- [ ] Unit tests cobrem kinds, UUID inválido, limites exatos de expiração
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add bearer token domain types`

---

### T3: `BearerTokenGenerator` e `TokenHasher`

**What**: Domain service `BearerTokenGenerator` + port `TokenHasher` + `Sha256TokenHasher`.  
**Where**: `Domain/Services/BearerTokenGenerator.php`, `Contracts/Services/TokenHasher.php`, `Infrastructure/Hashing/Sha256TokenHasher.php`  
**Depends on**: T2  
**Reuses**: Padrão `PasswordHasher` / `LaravelPasswordHasher`  
**Requirement**: BT-03, AUTH-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Plaintext = base64url de 32 bytes CSPRNG (~43 chars)
- [ ] Hash SHA-256 hex lowercase 64 chars; verify funciona
- [ ] Unit tests: formato, hash ≠ plaintext, verify false para mismatch
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add bearer token hashing and plaintext generator`

---

### T4: Contracts de persistência e geração de ID

**What**: `AuthTokenRepository` port, `AuthTokenIdGenerator` port, `Uuid7AuthTokenIdGenerator`.  
**Where**: `Contracts/Repositories/AuthTokenRepository.php`, `Contracts/Services/AuthTokenIdGenerator.php`, `Infrastructure/Identity/Uuid7AuthTokenIdGenerator.php`  
**Depends on**: T2, T3  
**Reuses**: `Uuid7UserIdGenerator` pattern  
**Requirement**: BT-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Interface repository com save/findByHash/deleteById/deleteByHash/deleteAllForUser/touchLastUsedAtIfStale
- [ ] Generator retorna `AuthTokenId` v7 válido
- [ ] Gate check passes: `make test-backend` (suite existente verde)

**Tests**: none  
**Gate**: build

**Commit**: `feat(auth): add auth token repository and id generator contracts`

---

### T5: Persistência Eloquent de tokens

**What**: `AuthTokenModel`, `AuthTokenMapper`, `EloquentAuthTokenRepository`, `AuthTokenModelFactory`.  
**Where**: `Infrastructure/Persistence/Eloquent/`  
**Depends on**: T1, T4  
**Reuses**: `UserModel`, `UserMapper`, `EloquentUserRepository` patterns  
**Requirement**: BT-17, AUTH-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] save persiste hash, nunca plaintext
- [ ] findByHash retorna entidade ou null
- [ ] deleteById/deleteByHash/deleteAllForUser idempotentes conforme spec
- [ ] touchLastUsedAtIfStale só escreve se NULL ou stale ≥15 min
- [ ] Integration tests em `fake_link_testing` com `DatabaseSafetyGuard`
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add eloquent auth token repository`

---

### T6: `UserRepository::findById`

**What**: Adicionar `findById(UserId): ?User` ao port e implementação Eloquent.  
**Where**: `Contracts/Repositories/UserRepository.php`, `EloquentUserRepository.php`, mapper  
**Depends on**: None (foundation) — **bloqueia T10**  
**Reuses**: `findByEmail` patterns existentes  
**Requirement**: BT-05, BT-07 (prereq design)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Port e implementação retornam `User` domain ou null
- [ ] Integration test: user existente retorna entidade; id inexistente → null
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add findById to user repository`

---

### T7: Use case `IssueAuthToken`

**What**: DTOs + use case emissão com TTL absoluto por kind; retorna plaintext uma vez.  
**Where**: `DTOs/Input/IssueAuthTokenDto.php`, `DTOs/Output/IssuedAuthTokenDto.php`, `UseCases/IssueAuthToken.php`  
**Depends on**: T3, T5  
**Reuses**: `BearerTokenGenerator`, `TokenHasher`, `AuthTokenRepository`  
**Requirement**: BT-04, AUTH-13, AUTH-14, AUTH-15

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] `verification` → expires_at +24h; `session` → +7d
- [ ] Banco contém somente hash; DTO expõe plaintext
- [ ] Kind inválido falha antes de persistir
- [ ] Integration tests: emissão por kind, hash único, sem plaintext no DB
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add issue auth token use case`

---

### T8: Use cases `RevokeAuthToken` e `RevokeAllUserTokens`

**What**: Revogação unitária (id/hash) e em massa por user; idempotente.  
**Where**: `UseCases/RevokeAuthToken.php`, `UseCases/RevokeAllUserTokens.php`  
**Depends on**: T5, T7  
**Reuses**: `AuthTokenRepository`  
**Requirement**: BT-11, BT-12, AUTH-33

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Revoke token existente remove linha; inexistente → no-op
- [ ] RevokeAll remove todas linhas do user; zero tokens → 0 deleted, success
- [ ] Integration tests cobrem ambos fluxos
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add auth token revocation use cases`

---

### T9: Contract `AuthenticatedPrincipal`

**What**: Interface exportável + `AuthenticatedPrincipalRecord` readonly.  
**Where**: `Contracts/Authentication/AuthenticatedPrincipal.php`, `Infrastructure/Authentication/AuthenticatedPrincipalRecord.php`  
**Depends on**: T2  
**Reuses**: `UserId`, `UserStatus`, `TokenKind`, `AuthTokenId`  
**Requirement**: BT-13, AUTH-37

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Contract expõe userId, userStatus, tokenKind, tokenId, expiresAt
- [ ] Record readonly implementa contract
- [ ] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: build

**Commit**: `feat(auth): add authenticated principal contract`

---

### T10: Use case `ValidateAuthToken`

**What**: Validação completa: hash lookup, absolute/idle expiry, user status, touch last_used_at.  
**Where**: `UseCases/ValidateAuthToken.php`, `Exceptions/AuthTokenException.php`  
**Depends on**: T5, T6, T9  
**Reuses**: `TokenHasher`, `UserRepository`, `AuthenticatedPrincipalRecord`  
**Requirement**: BT-05, BT-07, BT-08, AUTH-15, AUTH-16, AUTH-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Ordem de validação conforme spec; expirado/revogado não atualiza `last_used_at`
- [ ] Idle usa `last_used_at ?? created_at`; limites por kind
- [ ] Status `suspended` / `deletion_pending` → exceções mapeáveis a 403
- [ ] Throttle 15 min: segunda validação dentro da janela não escreve
- [ ] Integration tests com `Carbon::setTestNow()` cobrem todos ACs
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add validate auth token use case`

---

### T11: Ownership — `AuthorizesOwnedResource` e `ResourceNotFoundException`

**What**: Trait/base ownership + exceção para 404 uniforme.  
**Where**: `Infrastructure/Authorization/AuthorizesOwnedResource.php`, `Exceptions/ResourceNotFoundException.php`  
**Depends on**: T9  
**Reuses**: `AuthenticatedPrincipal`, `UserId`  
**Requirement**: BT-14, AUTH-38

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] `ensureOwnedBy` lança `ResourceNotFoundException` quando owner difere
- [ ] Match owner não lança
- [ ] Unit test cobre match e mismatch
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add owned resource authorization helper`

---

### T12: `AuthErrorResponseFactory`

**What**: Factory JSON para `UNAUTHENTICATED`, `TOKEN_RESTRICTED`, `ACCOUNT_*`, `RESOURCE_NOT_FOUND`.  
**Where**: `Infrastructure/Http/Responses/AuthErrorResponseFactory.php`  
**Depends on**: T10, T11  
**Reuses**: Formato `docs/openapi.yaml` ErrorResponse  
**Requirement**: BT-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Respostas incluem `code`, `message`, `request_id` stub ou real
- [ ] Headers `Cache-Control: private, no-store`
- [ ] Unit test assert structure/codes por cenário
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add auth error response factory`

---

### T13: Middleware `AuthenticateBearer`

**What**: Parse Bearer, chama `ValidateAuthToken`, bind `AuthenticatedPrincipal`, 401 em falha.  
**Where**: `Infrastructure/Http/Middleware/AuthenticateBearer.php`, `bootstrap/app.php` alias `auth.bearer`  
**Depends on**: T10, T12  
**Reuses**: `AuthErrorResponseFactory`  
**Requirement**: BT-06, AUTH-18

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Header `Bearer ` case-sensitive; ausente/malformado → 401 `UNAUTHENTICATED`
- [ ] Sucesso bind `AuthenticatedPrincipal` no container
- [ ] Alias registrado em `bootstrap/app.php`
- [ ] Gate check passes: `make test-backend`

**Tests**: none (coberto em T15 feature)  
**Gate**: build

**Commit**: `feat(auth): add authenticate bearer middleware`

---

### T14: Middleware `RequireTokenKind`

**What**: Restringe rota por kind(s); 403 `TOKEN_RESTRICTED`.  
**Where**: `Infrastructure/Http/Middleware/RequireTokenKind.php`, `bootstrap/app.php` alias `token.kind`  
**Depends on**: T9, T12, T13  
**Reuses**: `AuthenticatedPrincipal`, `AuthErrorResponseFactory`  
**Requirement**: BT-09, AUTH-19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Parâmetro rota aceita kind único ou lista separada por vírgula
- [ ] Kind mismatch → 403 `TOKEN_RESTRICTED`
- [ ] `session` + `pending_verification` → 403
- [ ] Alias `token.kind` registrado
- [ ] Gate check passes: `make test-backend`

**Tests**: none (coberto em T15 feature)  
**Gate**: build

**Commit**: `feat(auth): add require token kind middleware`

---

### T15: Rotas de teste e feature tests HTTP

**What**: `TestingAuthProbeController`, rotas `/api/v1/_test/auth/*` (testing only), feature tests E2E middleware.  
**Where**: `Infrastructure/Http/Controllers/TestingAuthProbeController.php`, `routes/api.php` ou routes Auth  
**Depends on**: T7, T13, T14  
**Reuses**: `IssueAuthToken` para fabricar tokens nos tests  
**Requirement**: BT-16, AUTH-18, AUTH-19

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Rotas registradas **somente** quando `app()->environment('testing')`
- [ ] `GET /api/v1/_test/auth/probe` com Bearer válido → 200 + user_id/token_kind
- [ ] Feature tests: 401 ausente/inválido/expirado/idle; 403 kind/status; throttle last_used_at via HTTP
- [ ] Gate check passes: `make test-backend`

**Tests**: feature (E2E)  
**Gate**: full

**Commit**: `test(auth): add bearer middleware feature tests and probe routes`

---

### T16: `AuthServiceProvider` — bindings completos

**What**: Registrar todos ports/adapters/use cases/middleware no provider.  
**Where**: `ServiceProviders/AuthServiceProvider.php`  
**Depends on**: T3–T14  
**Reuses**: Bindings existentes UserRepository/PasswordHasher  
**Requirement**: BT-13 (wiring exportável)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Bindings: TokenHasher, AuthTokenRepository, AuthTokenIdGenerator, use cases
- [ ] Middleware resolvíveis via container
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: none  
**Gate**: build

**Commit**: `feat(auth): wire bearer token services in auth provider`

---

### T17: Disciplina plaintext (BT-15)

**What**: Garantir exceções/mensagens não contêm plaintext; teste sentinela.  
**Where**: `Exceptions/AuthTokenException.php` (refinar), tests unit  
**Depends on**: T10, T12  
**Reuses**: Padrão FND-09 (senha ausente de logs)  
**Requirement**: BT-15

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Factories de exceção auth token não interpolam plaintext
- [ ] Unit test injeta token marcador e assert ausência em `getMessage()`
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `test(auth): ensure bearer plaintext never leaks in exceptions`

---

### T18: Verificação documental e gates finais

**What**: Confirmar `docs/data-model.md` §3 alinhado; rodar lint + coverage.  
**Where**: `.specs/features/auth/bearer-tokens/`, `docs/data-model.md` (somente se drift)  
**Depends on**: T1–T17  
**Reuses**: Gates Makefile  
**Requirement**: BT-18

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute → Verifier)

**Done when**:

- [ ] `docs/data-model.md` §3 `auth_tokens` confere com migration (UUID v7, campos, constraints)
- [ ] `make lint` exit 0
- [ ] `make test-backend` exit 0
- [ ] `make test-backend-coverage` ≥80/80 em código novo de tokens Auth
- [ ] Verifier independente executado pós-commit

**Tests**: none (gates)  
**Gate**: coverage

**Commit**: `docs(auth): confirm auth_tokens data model alignment`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6 → Phase 7

Phase 1:  T1 ──→ T2
Phase 2:  T3 ──→ T4 ──→ T5
Phase 3:  T6
Phase 4:  T7 ──→ T8
Phase 5:  T9 ──→ T10 ──→ T11
Phase 6:  T12 ──→ T13 ──→ T14 ──→ T15
Phase 7:  T16 ──→ T17 ──→ T18
```

Execution is strictly sequential — one task at a time, one atomic commit per task.

**Batch packing (Execute):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | 1–3 | T1–T6 (6 tasks) |
| 2 | 4–5 | T7–T11 (5 tasks) |
| 3 | 6–7 | T12–T18 (7 tasks) |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: Migration auth_tokens | 1 migration + schema test | ✅ Granular |
| T2: Domain types | 1 camada domain + unit tests | ✅ Granular |
| T3: Generator + hasher | 1 port + 1 adapter + unit tests | ✅ Granular |
| T4: Repository contracts | interfaces + generator | ✅ Granular |
| T5: Eloquent repository | persistência + integration | ✅ Granular |
| T6: UserRepository findById | 1 método + test | ✅ Granular |
| T7: IssueAuthToken | 1 use case + integration | ✅ Granular |
| T8: Revoke use cases | 2 use cases relacionados | ✅ Granular |
| T9: AuthenticatedPrincipal | 1 contract + record | ✅ Granular |
| T10: ValidateAuthToken | 1 use case + integration | ✅ Granular |
| T11: Ownership helper | 1 trait + exception + unit | ✅ Granular |
| T12: Error response factory | 1 factory + unit | ✅ Granular |
| T13: AuthenticateBearer | 1 middleware | ✅ Granular |
| T14: RequireTokenKind | 1 middleware | ✅ Granular |
| T15: Feature tests HTTP | rotas teste + E2E | ✅ Granular |
| T16: Provider wiring | 1 provider update | ✅ Granular |
| T17: Plaintext discipline | tests sentinela | ✅ Granular |
| T18: Final gates | verificação + coverage | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | T1 (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | T2, T3 | T3 → T4 | ✅ Match |
| T5 | T1, T4 | T4 → T5 | ✅ Match |
| T6 | None | T6 (parallel prereq) | ✅ Match |
| T7 | T3, T5 | T5 → T7 | ✅ Match |
| T8 | T5, T7 | T7 → T8 | ✅ Match |
| T9 | T2 | T2 → T9 (phase 5) | ✅ Match |
| T10 | T5, T6, T9 | T6,T5,T9 → T10 | ✅ Match |
| T11 | T9 | T9 → T11 | ✅ Match |
| T12 | T10, T11 | T10,T11 → T12 | ✅ Match |
| T13 | T10, T12 | T12 → T13 | ✅ Match |
| T14 | T9, T12, T13 | T13 → T14 | ✅ Match |
| T15 | T7, T13, T14 | T14 → T15 | ✅ Match |
| T16 | T3–T14 | T15 → T16 | ✅ Match |
| T17 | T10, T12 | T16 → T17 | ✅ Match |
| T18 | T1–T17 | T17 → T18 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Migration | integration | integration | ✅ OK |
| T2 | Domain VOs | unit | unit | ✅ OK |
| T3 | Hasher + generator | unit | unit | ✅ OK |
| T4 | Contracts | none | none | ✅ OK |
| T5 | Repository | integration | integration | ✅ OK |
| T6 | UserRepository | integration | integration | ✅ OK |
| T7 | Use case Issue | integration | integration | ✅ OK |
| T8 | Use case Revoke | integration | integration | ✅ OK |
| T9 | Contract | none | none | ✅ OK |
| T10 | Use case Validate | integration | integration | ✅ OK |
| T11 | Ownership | unit | unit | ✅ OK |
| T12 | Response factory | unit | unit | ✅ OK |
| T13 | Middleware | feature (via T15) | none | ✅ OK (merged forward T15) |
| T14 | Middleware | feature (via T15) | none | ✅ OK (merged forward T15) |
| T15 | Middleware/routes | feature (E2E) | feature (E2E) | ✅ OK |
| T16 | Provider | none | none | ✅ OK |
| T17 | Exception discipline | unit | unit | ✅ OK |
| T18 | Docs/gates | none | none | ✅ OK |

---

## Requirement → Task Mapping

| Requirement ID | Task(s) |
| --- | --- |
| BT-01 | T1 |
| BT-02 | T2 |
| BT-03 | T3 |
| BT-04 | T7 |
| BT-05 | T10 |
| BT-06 | T13, T15 |
| BT-07 | T6, T10 |
| BT-08 | T5, T10 |
| BT-09 | T14, T15 |
| BT-10 | T12, T15 |
| BT-11 | T8 |
| BT-12 | T8 |
| BT-13 | T9, T16 |
| BT-14 | T11 |
| BT-15 | T17 |
| BT-16 | T15 |
| BT-17 | T4, T5 |
| BT-18 | T18 |
| AUTH-13 … AUTH-19 | T1–T15 (via BT mapping) |
| AUTH-33 | T8 |
| AUTH-37 | T9 |
| AUTH-38 | T11 |
