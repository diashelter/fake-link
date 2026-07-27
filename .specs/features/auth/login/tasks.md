# Auth — Login — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/login/design.md`  
**Spec**: `.specs/features/auth/login/spec.md`  
**Status**: Approved — ready for Execute

> **Sub-agent note:** 13 tasks → 2 batches (~7 + ~6). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG `fake_link_testing`), §3.1 (Pest Arch), §4 (80/80 Auth), §6.1 (credencial, status, timing), `LARAVEL_CODE_DESIGN.md` §13/§25, `.specs/features/auth/login/spec.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Config (`auth.php` login limits, dummy hash) | none | — (validado via unit/integration) | `backend/config/auth.php` | build gate only |
| Port extension (`UserRepository::findByEmail`) | none | — (coberto pelo adapter integration) | `backend/modules/Auth/Contracts/` | build gate only |
| Exception + error factory (`InvalidCredentialsException`, `invalidCredentials()`) | unit | Método retorna `401` + code/message OpenAPI | `backend/modules/Auth/Tests/Unit/AuthErrorResponseFactoryTest.php` | `make test-backend` |
| Rate limit key factory (login keys) | unit | Digest estável; IP/e-mail bruto ausentes; prefixos `login:email-ip:` / `login:ip:` | `backend/modules/Auth/Tests/Unit/HmacRateLimitKeyFactoryTest.php` | `make test-backend` |
| Rate limit middleware (`ThrottleLogin`) | unit | 6º hit email+IP → `429`; ambas chaves incrementadas; conta antes do controller | `backend/modules/Auth/Tests/Unit/ThrottleLoginTest.php` | `make test-backend` |
| Repository adapter (`findByEmail`) | integration | Found / not found / email normalizado; PG `fake_link_testing` | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php` | `make test-backend` |
| Use case (`LoginUser`) | integration | 1:1 LOG-01…06, LOG-12; dummy verify; multi-sessão; sem queue; PG isolado | `backend/modules/Auth/Tests/Integration/LoginUserTest.php` | `make test-backend` |
| HTTP factory (`AuthResponseFactory::authenticated`) | unit | `200` + schema AuthResponse; headers | `backend/modules/Auth/Tests/Unit/AuthResponseFactoryTest.php` | `make test-backend` |
| Form request (`LoginUserRequest`) | feature | LOG-07 matrix (campos extras, bounds, required) | `backend/modules/Auth/Tests/Feature/LoginTest.php` | `make test-backend` |
| Controller + rota (`POST /api/v1/auth/login`) | feature (E2E) | Todos ACs P1/P2; happy path por status; enumeração; throttle dual; headers `200` | `backend/modules/Auth/Tests/Feature/LoginTest.php` | `make test-backend` |
| Factory states (`UserModelFactory`) | none | — (suporte a Feature/Integration) | `backend/modules/Auth/Infrastructure/Persistence/Eloquent/Factories/` | build gate only |
| `AuthServiceProvider` wiring | none | — (gate via feature/integration) | — | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após T1–T5, T9 (unit-only) | `make test-backend` |
| Full | Após T6–T8, T10–T11 (integration + feature parcial) | `make test-backend` |
| Build | Após T12 (HTTP + feature E2E completo) | `make lint && make test-backend` |
| Final | T13 | `make lint && make test-backend` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Port, exceções e config

```
T1 → T2 → T3
```

### Phase 2: Rate limiting

```
T4 → T5
```

### Phase 3: Use case

```
T6 → T7
```

### Phase 4: Camada HTTP

```
T8 → T9 → T10
```

### Phase 5: E2E e gates finais

```
T11 → T12 → T13
```

---

## Task Breakdown

### T1: `UserRepository::findByEmail`

**What**: Adicionar `findByEmail(EmailAddress): ?User` ao port e implementação Eloquent.  
**Where**: `Contracts/Repositories/UserRepository.php`, `Infrastructure/Persistence/Eloquent/Repositories/EloquentUserRepository.php`  
**Depends on**: None  
**Reuses**: `UserMapper`, padrão `findById` / `existsByEmail`  
**Requirement**: LOG-01 (lookup)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Port e adapter compilam; lookup por e-mail normalizado retorna entidade ou `null`
- [x] Integration tests em `EloquentUserRepositoryTest`: found, not found, case-insensitive match via normalized email
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add UserRepository findByEmail lookup`

---

### T2: `InvalidCredentialsException` e `invalidCredentials()` factory

**What**: Exceção de domínio `INVALID_CREDENTIALS` + método `AuthErrorResponseFactory::invalidCredentials()` → `401`.  
**Where**: `Exceptions/InvalidCredentialsException.php`, `Infrastructure/Http/Responses/AuthErrorResponseFactory.php`  
**Depends on**: None  
**Reuses**: Padrão `RegistrationNotAllowedException`, `errorResponse()` privado  
**Requirement**: LOG-04, LOG-05

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `errorCode()` retorna `INVALID_CREDENTIALS`
- [x] Factory retorna `401` + message OpenAPI exata
- [x] Unit tests estendem `AuthErrorResponseFactoryTest`
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add InvalidCredentialsException and error response`

---

### T3: Config login rate limits e dummy password hash

**What**: Estender `config/auth.php` com `rate_limits.login` (email_ip 5/60s, ip 30/60s) e `dummy_password_hash` Argon2id estático.  
**Where**: `backend/config/auth.php`, opcional default em `backend/phpunit.xml` / `.env.testing`  
**Depends on**: None  
**Reuses**: Bloco `rate_limits.registration` existente  
**Requirement**: LOG-08, LOG-05 (dummy verify)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Config expõe `auth.rate_limits.login.email_ip`, `.ip`, `auth.dummy_password_hash`
- [x] Hash dummy é Argon2id válido verificável por `PasswordHasher::verify` (qualquer senha → false, sem exception)
- [x] Gate check passes: `make test-backend` (suite existente verde)

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add login rate limit config and dummy password hash`

---

### T4: `HmacRateLimitKeyFactory` — chaves login

**What**: Métodos `forLoginEmailIp($ip, $emailPart)` e `forLoginIp($ip)` com prefixos HMAC distintos.  
**Where**: `Infrastructure/RateLimit/HmacRateLimitKeyFactory.php`  
**Depends on**: T3  
**Reuses**: Padrão `forRegistrationIp`  
**Requirement**: LOG-08, LOG-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Digest estável para mesmas entradas; IP/e-mail bruto ausentes do digest exposto
- [x] Unit tests em `HmacRateLimitKeyFactoryTest` cobrem login keys + sentinel `_invalid_`
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add HMAC rate limit keys for login`

---

### T5: Middleware `ThrottleLogin`

**What**: Middleware dual-limit; alias `throttle.login` em `bootstrap/app.php`; hit ambas chaves antes do controller.  
**Where**: `Infrastructure/Http/Middleware/ThrottleLogin.php`, `backend/bootstrap/app.php`  
**Depends on**: T4  
**Reuses**: Padrão `ThrottleRegistration`, `AuthErrorResponseFactory::rateLimitExceeded`  
**Requirement**: LOG-08, LOG-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Exceder email+IP (6º) ou IP (31º) → `429` + `Retry-After`
- [x] Contabiliza tentativa antes de autenticação (middleware pre-controller)
- [x] Unit tests `ThrottleLoginTest.php` com `RateLimiter` fake/clear
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add ThrottleLogin dual rate limit middleware`

---

### T6: DTOs `LoginUserDto` e `LoggedInUserDto`

**What**: DTOs de entrada/saída do use case login.  
**Where**: `DTOs/Input/LoginUserDto.php`, `DTOs/Output/LoggedInUserDto.php`  
**Depends on**: None  
**Reuses**: Shape de `RegisteredUserDto` / `RegisterUserDto`  
**Requirement**: LOG-01

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] DTOs readonly com typehints explícitos
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add LoginUser DTOs`

---

### T7: Use case `LoginUser`

**What**: Orquestração credencial → status → token; dummy verify; sem queue; sem revogação.  
**Where**: `UseCases/LoginUser.php`  
**Depends on**: T1, T2, T3, T6  
**Reuses**: `IssueAuthToken`, `PasswordHasher`, `AuthTokenException`, `UserStatus`, `TokenKind`  
**Requirement**: AUTH-09, AUTH-10, AUTH-11, LOG-01…06, LOG-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Integration `LoginUserTest.php` cobre: active→session, pending→verification, invalid email/password, wrong password on suspended→401, suspended+correct→403, deletion_pending+correct→403, dummy verify when user missing (mock spy), multi-token (no revoke), no QueueEmailVerification
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add LoginUser use case`

---

### T8: `LoginUserRequest`

**What**: Form Request OpenAPI strict (`email`, `password` only; bounds; no PasswordPolicyRule).  
**Where**: `Infrastructure/Http/Requests/LoginUserRequest.php`  
**Depends on**: T6  
**Reuses**: `ApiFormRequest`, padrão `RegisterUserRequest::prepareForValidation`  
**Requirement**: LOG-07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `toDto()` retorna `LoginUserDto`
- [ ] Validação coberta em Feature tests (T12) — task verificada quando T12 passa
- [x] Gate check passes: `make test-backend`

**Tests**: feature (via T12)  
**Gate**: full

**Commit**: `feat(auth): add LoginUserRequest form validation`

---

### T9: `AuthResponseFactory::authenticated` (HTTP 200)

**What**: Método de resposta login `200` com envelope `AuthResponse` idêntico ao registro.  
**Where**: `Infrastructure/Http/Responses/AuthResponseFactory.php`  
**Depends on**: T6  
**Reuses**: `AuthUserResource`, lógica de `issued()`  
**Requirement**: LOG-01, LOG-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `authenticated(LoggedInUserDto)` retorna HTTP 200 (não 201)
- [x] Unit test estende `AuthResponseFactoryTest` assert status + schema + headers
- [x] `issued()` permanece `201` inalterado
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add AuthResponseFactory authenticated response for login`

---

### T10: Controller, rota e provider wiring

**What**: `LoginUserController`, rota `POST /login` com `throttle.login`, binding `LoginUser` no provider.  
**Where**: `Infrastructure/Http/Controllers/LoginUserController.php`, `routes/auth.php`, `AuthServiceProvider.php`  
**Depends on**: T2, T5, T7, T8, T9  
**Reuses**: Padrão `RegisterUserController` exception mapping  
**Requirement**: LOG-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Rota registrada; controller fino mapeia exceções → 401/403/500
- [ ] Gate check passes: `make test-backend`

**Tests**: feature (via T12)  
**Gate**: full

**Commit**: `feat(auth): wire login endpoint and service provider`

---

### T11: `UserModelFactory` states para login

**What**: States `active()`, `suspended()`, `deletionPending()`, `withPassword(string $plain)`.  
**Where**: `Infrastructure/Persistence/Eloquent/Factories/UserModelFactory.php`  
**Depends on**: None  
**Reuses**: `UserStatus` enum, `Hash`/`PasswordHasher`  
**Requirement**: LOG-01, LOG-06, LOG-12 (test support)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Factory states produzem usuários persistíveis com senha conhecida nos Feature tests
- [ ] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add UserModelFactory states for login tests`

---

### T12: Feature tests E2E `LoginTest.php`

**What**: Suite Feature cobrindo todos ACs P1/P2 da spec (credencial, status, validação, throttle dual, headers).  
**Where**: `modules/Auth/Tests/Feature/LoginTest.php`  
**Depends on**: T8, T10, T11  
**Reuses**: Padrão `RegistrationTest.php`, `DatabaseSafetyGuard`, `HmacRateLimitKeyFactory`  
**Requirement**: LOG-01…12, LOG-10, LOG-11, AUTH-09…11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Matrix: active session, pending verification, invalid credentials uniformes (3 cenários), blocked statuses, validation 422/400, dual rate limit 429, headers 200, multi-session token count, no queue on pending login
- [ ] `make test-backend` descobre e executa `LoginTest.php`
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: feature  
**Gate**: build

**Commit**: `test(auth): add login feature tests`

---

### T13: Quality gates finais

**What**: Confirmar lint + test-backend verdes; atualizar status tasks/spec se necessário.  
**Where**: —  
**Depends on**: T12  
**Reuses**: `make lint`, `make test-backend`  
**Requirement**: LOG-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] `make lint && make test-backend` passa sem regressões Auth
- [ ] Verifier sub-agent executado pós-T13 (automático pelo skill Execute)

**Tests**: none (gate only)  
**Gate**: final

**Commit**: `chore(auth): complete login slice quality gates`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 ──→ T2 ──→ T3
Phase 2:  T4 ──→ T5
Phase 3:  T6 ──→ T7
Phase 4:  T8 ──→ T9 ──→ T10
Phase 5:  T11 ──→ T12 ──→ T13
```

**Batch packing (Execute):**

| Batch | Tasks | Fases |
| --- | --- | --- |
| 1 | T1–T7 | 1–3 |
| 2 | T8–T13 | 4–5 |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: findByEmail port+adapter | 1 port method + impl | ✅ Granular |
| T2: InvalidCredentials + factory | 1 exception + 1 factory method | ✅ Granular |
| T3: Config login limits | 1 config file extend | ✅ Granular |
| T4: HMAC login keys | 2 methods factory | ✅ Granular |
| T5: ThrottleLogin middleware | 1 middleware + alias | ✅ Granular |
| T6: Login DTOs | 2 small DTO files | ✅ Granular |
| T7: LoginUser use case | 1 use case + integration tests | ✅ Granular |
| T8: LoginUserRequest | 1 Form Request | ✅ Granular |
| T9: authenticated() factory | 1 factory method | ✅ Granular |
| T10: Controller + route + provider | 1 endpoint wiring | ✅ Granular |
| T11: Factory states | 1 factory extend | ✅ Granular |
| T12: LoginTest Feature | 1 test file (E2E slice) | ✅ Granular |
| T13: Quality gates | gate only | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | Phase 1 start | ✅ Match |
| T2 | None | Phase 1 parallel to T1 | ✅ Match |
| T3 | None | Phase 1 | ✅ Match |
| T4 | T3 | T3 → T4 | ✅ Match |
| T5 | T4 | T4 → T5 | ✅ Match |
| T6 | None | Phase 3 start | ✅ Match |
| T7 | T1, T2, T3, T6 | T1,T2,T3 → T7; T6 → T7 | ✅ Match |
| T8 | T6 | T6 → T8 | ✅ Match |
| T9 | T6 | T6 → T9 | ✅ Match |
| T10 | T2, T5, T7, T8, T9 | Phase 4 chain | ✅ Match |
| T11 | None | Phase 5 start | ✅ Match |
| T12 | T8, T10, T11 | T8,T10,T11 → T12 | ✅ Match |
| T13 | T12 | T12 → T13 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1: findByEmail | Repository adapter | integration | integration | ✅ OK |
| T2: InvalidCredentials | Error factory | unit | unit | ✅ OK |
| T3: Config | Config | none | none | ✅ OK |
| T4: HMAC keys | Rate limit factory | unit | unit | ✅ OK |
| T5: ThrottleLogin | Middleware | unit | unit | ✅ OK |
| T6: DTOs | DTOs | none | none | ✅ OK |
| T7: LoginUser | Use case | integration | integration | ✅ OK |
| T8: LoginUserRequest | Form request | feature | feature (via T12) | ✅ OK — T12 runs full feature matrix including LOG-07 |
| T9: authenticated() | HTTP factory | unit | unit | ✅ OK |
| T10: Controller+route | Controller/E2E | feature | feature (via T12) | ✅ OK — wired + verified in T12 |
| T11: Factory states | Factory | none | none | ✅ OK |
| T12: LoginTest | Feature E2E | feature | feature | ✅ OK |
| T13: Gates | — | none | none | ✅ OK |

---

## Referências

- `.specs/features/auth/login/spec.md`
- `.specs/features/auth/login/design.md`
- `.specs/features/auth/registration/tasks.md` (padrão Execute)
