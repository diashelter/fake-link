# Auth — Registro por convite — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/registration/design.md`  
**Spec**: `.specs/features/auth/registration/spec.md`  
**Status**: Execute — Batch 1 (T1–T8) + Batch 2 (T9–T15) complete; awaiting Verifier

> **Sub-agent note:** 15 tasks → 2 batches (~8 + ~7). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG `fake_link_testing`), §3.1 (Pest Arch), §4 (80/80 Auth), §6.1 (convite, enumeração, termos), `LARAVEL_CODE_DESIGN.md` §13/§25, `.specs/features/auth/registration/spec.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Bootstrap HTTP (`ApiFormRequest`, `ApiResponse`) | unit | Envelope `422 VALIDATION_FAILED` OpenAPI; headers `Cache-Control`; sem vazamento de campos extras | `backend/tests/Unit/Http/**/*Test.php` | `make test-backend` |
| Validation rule (`PasswordPolicyRule`) | unit | 1:1 `PasswordPolicy`; cada `PasswordViolationCode`; REG-07 composição | `backend/modules/Auth/Tests/Unit/PasswordPolicyRuleTest.php` | `make test-backend` |
| Config / JSON allowlist file | none | — (validado via adapter tests) | — | build gate only |
| Allowlist adapter (`JsonFileInviteAllowlist`) | unit | Convite exato; case/trim; +alias rejeitado; unavailable → exception; sem log de e-mail | `backend/modules/Auth/Tests/Unit/JsonFileInviteAllowlistTest.php` | `make test-backend` |
| Queue adapter (`LaravelQueueEmailVerification` + job stub) | unit | `Queue::fake()`; job na fila `notifications`; 1 dispatch por registro | `backend/modules/Auth/Tests/Unit/LaravelQueueEmailVerificationTest.php` | `make test-backend` |
| Rate limit key (`HmacRateLimitKeyFactory`) | unit | Digest estável; IP bruto ausente da chave; purpose `registration:` | `backend/modules/Auth/Tests/Unit/HmacRateLimitKeyFactoryTest.php` | `make test-backend` |
| Rate limit middleware (`ThrottleRegistration`) | feature | REG-08; 6º POST → `429` + `Retry-After`; conta 422/403 | `backend/modules/Auth/Tests/Feature/RegistrationTest.php` (seção throttle) | `make test-backend` |
| Use case (`RegisterUser`) | integration | 1:1 AUTH-01…05, REG-01…06; transação; rollback token; anti-enum; terms; PG `fake_link_testing` | `backend/modules/Auth/Tests/Integration/RegisterUserTest.php` | `make test-backend` |
| HTTP factories (`AuthResponseFactory`, `AuthErrorResponseFactory` ext.) | unit | `201 AuthResponse` schema; `403 REGISTRATION_NOT_ALLOWED`; `503`; `429` | `backend/modules/Auth/Tests/Unit/AuthResponseFactoryTest.php`, estender `AuthErrorResponseFactoryTest` | `make test-backend` |
| Form request (`RegisterUserRequest`) | feature | REG-07 matrix; sem `Rule::unique`; `accept_terms`; campos extras → 422 | `backend/modules/Auth/Tests/Feature/RegistrationTest.php` | `make test-backend` |
| Controller + rota (`POST /api/v1/auth/register`) | feature (E2E) | Todos ACs P1/P2; happy path; enumeração byte-a-byte; headers `201` | `backend/modules/Auth/Tests/Feature/RegistrationTest.php` | `make test-backend` |
| Ports / DTOs / exceptions (interfaces) | none | — (cobertos indiretamente) | — | build gate only |
| `AuthServiceProvider` wiring | none | — (gate via feature/integration) | — | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após T1–T2, T5, T7 (unit-only) | `make test-backend` |
| Full | Após T6, T8–T10 (integration + unit) | `make test-backend` |
| Build | Após T11–T14 (HTTP + feature E2E) | `make lint && make test-backend` |
| Final | T15 | `make lint && make test-backend` (+ coverage Auth se gate existir) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Bootstrap HTTP global

```
T1 → T2
```

### Phase 2: Config operacional

```
T3
```

### Phase 3: Ports, allowlist e fila

```
T4 → T5 → T6
```

### Phase 4: Rate limiting

```
T7 → T8
```

### Phase 5: Use case de registro

```
T9 → T10
```

### Phase 6: Camada HTTP

```
T11 → T12 → T13
```

### Phase 7: E2E e gates finais

```
T14 → T15
```

---

## Task Breakdown

### T1: `ApiFormRequest` e `ApiResponse` (OpenAPI)

**What**: Criar bootstrap global de validação HTTP com envelope `422 VALIDATION_FAILED` alinhado a `docs/openapi.yaml`.  
**Where**: `backend/app/Http/Requests/ApiFormRequest.php`, `backend/app/Http/Responses/ApiResponse.php`  
**Depends on**: None  
**Reuses**: Padrão `LARAVEL_CODE_DESIGN.md` §13 (ajustado ao OpenAPI real)  
**Requirement**: REG-07, REG-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `ApiFormRequest::failedValidation` retorna `422` com `code=VALIDATION_FAILED`, `message`, `request_id`, `errors`
- [x] `ApiResponse::validationError()` inclui `Cache-Control: private, no-store`
- [x] Unit test em `backend/tests/Unit/Http/ApiResponseTest.php` assert estrutura OpenAPI
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(app): add OpenAPI-aligned ApiFormRequest and ApiResponse`

---

### T2: `PasswordPolicyRule`

**What**: Rule de validação Laravel delegando a `PasswordPolicy` do módulo Auth.  
**Where**: `backend/modules/Auth/Infrastructure/Http/Rules/PasswordPolicyRule.php`  
**Depends on**: T1  
**Reuses**: `Domain/Services/PasswordPolicy.php`, `PasswordViolationCode`  
**Requirement**: REG-07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Rule rejeita senhas fora de 12–128 e composição ASCII (4 categorias)
- [x] Unit tests cobrem too short, missing symbol, senha válida aceita
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add PasswordPolicyRule for registration validation`

---

### T3: Config Auth — terms, allowlist, rate limits

**What**: Estender `config/auth.php`; adicionar `invite-allowlist.testing.json`; defaults em `.env.testing` / `phpunit.xml` (`AUTH_TERMS_CURRENT_VERSION`, `AUTH_INVITE_ALLOWLIST_PATH`, `AUTH_RATE_LIMIT_HMAC_KEY`).  
**Where**: `backend/config/auth.php`, `backend/config/invite-allowlist.testing.json`, `backend/.env.testing`, `backend/phpunit.xml`  
**Depends on**: None  
**Reuses**: Factories existentes (`terms_version=2026-01`)  
**Requirement**: REG-02, REG-05, REG-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] JSON contém pelo menos `invited@example.com` normalizado lowercase
- [x] Config expõe `auth.terms.current_version`, `auth.invite_allowlist.path`, `auth.rate_limits.registration`, `auth.rate_limit_hmac_key`
- [x] `.env.testing` define HMAC key fixa para testes determinísticos
- [x] Gate check passes: `make test-backend` (suite existente verde)

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add registration config and testing invite allowlist`

---

### T4: Ports e exceções de registro

**What**: `InviteAllowlist`, `QueueEmailVerification` ports; `RegistrationNotAllowedException`, `InviteAllowlistUnavailableException`.  
**Where**: `backend/modules/Auth/Contracts/Services/`, `backend/modules/Auth/Exceptions/`  
**Depends on**: None  
**Reuses**: Padrão `AuthDomainException`, `AuthTokenException`  
**Requirement**: AUTH-01, AUTH-02, REG-05, REG-06

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Interfaces compilam com typehints `EmailAddress`, `UserId`
- [x] Exceções expõem `errorCode()` estável (`REGISTRATION_NOT_ALLOWED`, `SERVICE_UNAVAILABLE` onde aplicável)
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add registration ports and domain exceptions`

---

### T5: `JsonFileInviteAllowlist`

**What**: Adapter que carrega JSON normalizado em Set in-memory; indisponível → `InviteAllowlistUnavailableException`.  
**Where**: `backend/modules/Auth/Infrastructure/Allowlist/JsonFileInviteAllowlist.php`  
**Depends on**: T3, T4  
**Reuses**: `EmailAddress` normalização  
**Requirement**: AUTH-01, REG-05

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `isInvited` compara forma normalizada exata (sem alias/+ trick)
- [x] Arquivo ausente/JSON inválido → `InviteAllowlistUnavailableException`
- [x] Unit tests: invited ok, not invited, trim/case, +alias rejeitado, unavailable
- [x] Nenhum teste/log contém e-mail consultado em mensagem de produção
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add JsonFileInviteAllowlist adapter`

---

### T6: Fila de verificação (stub)

**What**: `SendEmailVerificationJob` (handler no-op) + `LaravelQueueEmailVerification` adapter.  
**Where**: `backend/modules/Auth/Infrastructure/Jobs/`, `Infrastructure/Notifications/`  
**Depends on**: T4  
**Reuses**: Laravel queue; fila `notifications`  
**Requirement**: REG-01 (AC dispatch), AUTH-03

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `dispatch(UserId)` enfileira job na fila `notifications`
- [x] Job `handle()` no-op (sem Resend, sem `email_action_tokens`)
- [x] Unit test com `Queue::fake()` assert 1 job dispatched
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add email verification queue stub for registration`

---

### T7: `HmacRateLimitKeyFactory`

**What**: Factory de chave HMAC-SHA256 para rate limit de registro por IP.  
**Where**: `backend/modules/Auth/Infrastructure/RateLimit/HmacRateLimitKeyFactory.php`  
**Depends on**: T3  
**Reuses**: `docs/security.md` §11  
**Requirement**: REG-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `forRegistrationIp(string $ip)` retorna digest hex estável para mesmo input+key
- [x] IP bruto não aparece literalmente na chave retornada
- [x] Unit tests cobrem estabilidade e propósito `registration:`
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add HMAC rate limit key factory for registration`

---

### T8: `ThrottleRegistration` middleware

**What**: Middleware 5/h por IP contando **todas** tentativas POST; estender `AuthErrorResponseFactory::rateLimitExceeded`.  
**Where**: `backend/modules/Auth/Infrastructure/Http/Middleware/ThrottleRegistration.php`, `AuthErrorResponseFactory.php`  
**Depends on**: T7  
**Reuses**: Laravel `RateLimiter`; alias `throttle.registration` em `bootstrap/app.php`  
**Requirement**: REG-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] 6ª requisição na janela → `429 RATE_LIMIT_EXCEEDED` + header `Retry-After`
- [x] `RateLimiter::hit` executa antes do controller (conta 422/403/201)
- [x] Unit/Feature coverage do middleware incluída em T14 (merged forward) OU teste dedicado mínimo aqui se rota ainda não existir — usar rota temporária testing-only **proibido**; preferir teste de middleware isolado com `Request::create` se necessário
- [x] Gate check passes: `make test-backend`

**Tests**: unit (middleware isolado) + feature throttle validado em T14  
**Gate**: full

**Commit**: `feat(auth): add registration rate limit middleware`

---

### T9: DTOs de registro

**What**: `RegisterUserDto`, `RegisteredUserDto`.  
**Where**: `backend/modules/Auth/DTOs/Input/RegisterUserDto.php`, `DTOs/Output/RegisteredUserDto.php`  
**Depends on**: T4  
**Reuses**: `IssuedAuthTokenDto`, `User` entity  
**Requirement**: REG-01

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] DTOs readonly com campos do design
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add RegisterUser DTOs`

---

### T10: Use case `RegisterUser`

**What**: Orquestração transacional: allowlist → exists → password → save user → issue token → enqueue pós-commit.  
**Where**: `backend/modules/Auth/UseCases/RegisterUser.php`  
**Depends on**: T4, T5, T6, T9  
**Reuses**: `IssueAuthToken`, `UserRepository`, `PasswordHasher`, `PasswordPolicy`, `UserStatus::PendingVerification`  
**Requirement**: AUTH-01…05, REG-01…04, REG-06

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Sucesso: user `pending_verification`, `terms_version=2026-01`, token `verification`, hash no DB
- [x] Convite inválido / duplicado / race UNIQUE → `RegistrationNotAllowedException` (mesma classe)
- [x] Allowlist unavailable → `InviteAllowlistUnavailableException`
- [x] Falha em `IssueAuthToken` dentro da transação → rollback + `RegistrationNotAllowedException`
- [x] Falha enqueue pós-commit não lança (best-effort)
- [x] Integration tests em `RegisterUserTest.php` com PG `fake_link_testing`
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add RegisterUser use case`

---

### T11: Factories de resposta HTTP Auth

**What**: `AuthResponseFactory` (`201 AuthIssued`) + `AuthUserResource`; estender `AuthErrorResponseFactory` (`registrationNotAllowed`, `serviceUnavailable`).  
**Where**: `backend/modules/Auth/Infrastructure/Http/Responses/`, `Resources/AuthUserResource.php`  
**Depends on**: T9  
**Reuses**: `AuthErrorResponseFactory` existente; schema OpenAPI `User`, `AuthData`  
**Requirement**: REG-01, REG-06, REG-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `issued(RegisteredUserDto)` retorna `201` sem wrapper `success`
- [x] User JSON inclui todos campos OpenAPI; timestamps UTC `Z`
- [x] `registrationNotAllowed()` body idêntico ao OpenAPI example
- [x] Unit tests: `AuthResponseFactoryTest` + asserts em `AuthErrorResponseFactoryTest`
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: full

**Commit**: `feat(auth): add Auth response factories for registration`

---

### T12: `RegisterUserRequest`

**What**: Form Request com validação estrita OpenAPI; **sem** `Rule::unique`; `PasswordPolicyRule`; `toDto()`.  
**Where**: `backend/modules/Auth/Infrastructure/Http/Requests/RegisterUserRequest.php`  
**Depends on**: T1, T2, T9  
**Reuses**: `ApiFormRequest`, `EmailAddress` normalização em `prepareForValidation`  
**Requirement**: REG-07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Campos extras rejeitados (`422`); `accept_terms` must be true
- [x] `password_confirmation` enforced; email max 254
- [x] `toDto()` retorna `RegisterUserDto`
- [x] Validação coberta via Feature tests em T14 (merged forward)
- [x] Gate check passes: `make test-backend`

**Tests**: feature (via T14)  
**Gate**: build

**Commit**: `feat(auth): add RegisterUserRequest form validation`

---

### T13: Controller, rotas e provider wiring

**What**: `RegisterUserController`; rotas `POST /api/v1/auth/register`; bindings no `AuthServiceProvider`; middleware `throttle.registration`.  
**Where**: `Infrastructure/Http/Controllers/`, `Infrastructure/Http/routes/auth.php`, `ServiceProviders/AuthServiceProvider.php`, `bootstrap/app.php`  
**Depends on**: T8, T10, T11, T12  
**Reuses**: `loadRoutesFrom`; padrão rotas testing bearer-tokens  
**Requirement**: REG-09, REG-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Rota registrada com middleware `throttle.registration`
- [x] Controller mapeia exceções → factories corretas (`403`, `503`)
- [x] Provider registra `InviteAllowlist`, `QueueEmailVerification`, `RegisterUser`, singletons
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: feature (via T14)  
**Gate**: build

**Commit**: `feat(auth): wire registration endpoint and service provider`

---

### T14: Feature tests E2E — `RegistrationTest`

**What**: Suite Feature cobrindo todos ACs P1/P2 e edge cases da spec.  
**Where**: `backend/modules/Auth/Tests/Feature/RegistrationTest.php`  
**Depends on**: T13  
**Reuses**: `DatabaseSafetyGuard`; JSON allowlist de teste; padrão `BearerMiddlewareTest`  
**Requirement**: AUTH-01…05, REG-01…REG-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Happy path allowlisted → `201` + token kind + user status + terms + job queued (`Queue::fake()`)
- [x] Não convidado vs duplicado → `403` bodies byte-equivalentes (code, message, status)
- [x] Matrix `422`: senha fraca, terms false, campos extras, email inválido
- [x] Allowlist unavailable → `503`
- [x] Rate limit: 6 POSTs mesmo IP → 5 processados + 1 `429` + `Retry-After`
- [x] Headers `201`: `Cache-Control: private, no-store`
- [x] Plaintext token ausente de corpo de erro
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: build

**Commit**: `test(auth): add registration feature tests`

---

### T15: Gates finais e rastreabilidade

**What**: Verificar suite completa, lint, atualizar traceability na spec; preparar handoff para Verifier.  
**Where**: `.specs/features/auth/registration/spec.md` (status IDs), `.specs/STATE.md`  
**Depends on**: T1–T14  
**Reuses**: `make lint`, `make test-backend`  
**Requirement**: REG-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute → Verifier automático após esta task)

**Done when**:

- [x] `make lint && make test-backend` passa sem regressões Auth
- [x] Requirement traceability atualizada (phase → Implementing/Done conforme estado)
- [x] Nenhum teste Auth bearer regressou
- [x] Handoff STATE.md atualizado para Execute complete → Verifier

**Tests**: none  
**Gate**: build

**Commit**: `chore(auth): complete registration slice quality gates`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6 → Phase 7

Phase 1:  T1 ──→ T2
Phase 2:  T3
Phase 3:  T4 ──→ T5 ──→ T6
Phase 4:  T7 ──→ T8
Phase 5:  T9 ──→ T10
Phase 6:  T11 ──→ T12 ──→ T13
Phase 7:  T14 ──→ T15
```

Execution is strictly sequential — one task at a time, one atomic commit per task.

**Batch packing (Execute):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | 1–4 | T1–T8 (8 tasks) |
| 2 | 5–7 | T9–T15 (7 tasks) |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: ApiFormRequest + ApiResponse | 2 classes app + unit test | ✅ Granular |
| T2: PasswordPolicyRule | 1 rule + unit | ✅ Granular |
| T3: Config + JSON | config operacional | ✅ Granular |
| T4: Ports + exceptions | interfaces + 2 exceptions | ✅ Granular |
| T5: JsonFileInviteAllowlist | 1 adapter + unit | ✅ Granular |
| T6: Queue stub | job + adapter + unit | ✅ Granular |
| T7: HmacRateLimitKeyFactory | 1 factory + unit | ✅ Granular |
| T8: ThrottleRegistration | 1 middleware + factory ext. | ✅ Granular |
| T9: DTOs | 2 readonly DTOs | ✅ Granular |
| T10: RegisterUser | 1 use case + integration | ✅ Granular |
| T11: Response factories | factories + resource + unit | ✅ Granular |
| T12: RegisterUserRequest | 1 form request | ✅ Granular |
| T13: Controller + wiring | endpoint + provider | ✅ Granular |
| T14: RegistrationTest | feature E2E suite | ✅ Granular |
| T15: Final gates | verificação transversal | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | T1 (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | None | T3 (parallel) | ✅ Match |
| T4 | None | T4 (start phase 3) | ✅ Match |
| T5 | T3, T4 | T3,T4 → T5 | ✅ Match |
| T6 | T4 | T4 → T6 | ✅ Match |
| T7 | T3 | T3 → T7 | ✅ Match |
| T8 | T7 | T7 → T8 | ✅ Match |
| T9 | T4 | T4 → T9 | ✅ Match |
| T10 | T4, T5, T6, T9 | T5,T6,T9 → T10 | ✅ Match |
| T11 | T9 | T9 → T11 | ✅ Match |
| T12 | T1, T2, T9 | T1,T2,T9 → T12 | ✅ Match |
| T13 | T8, T10, T11, T12 | T8,T10,T11,T12 → T13 | ✅ Match |
| T14 | T13 | T13 → T14 | ✅ Match |
| T15 | T1–T14 | T14 → T15 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Bootstrap HTTP | unit | unit | ✅ OK |
| T2 | PasswordPolicyRule | unit | unit | ✅ OK |
| T3 | Config | none | none | ✅ OK |
| T4 | Ports/exceptions | none | none | ✅ OK |
| T5 | Allowlist adapter | unit | unit | ✅ OK |
| T6 | Queue adapter | unit | unit | ✅ OK |
| T7 | Rate limit key | unit | unit | ✅ OK |
| T8 | Middleware | feature (T14) + unit | unit + feature via T14 | ✅ OK |
| T9 | DTOs | none | none | ✅ OK |
| T10 | RegisterUser | integration | integration | ✅ OK |
| T11 | HTTP factories | unit | unit | ✅ OK |
| T12 | FormRequest | feature | feature (via T14) | ✅ OK |
| T13 | Controller/routes | feature | feature (via T14) | ✅ OK |
| T14 | E2E register | feature (E2E) | feature (E2E) | ✅ OK |
| T15 | Gates/docs | none | none | ✅ OK |

---

## Requirement → Task Mapping

| Requirement ID | Task(s) |
| --- | --- |
| AUTH-01 | T5, T10, T14 |
| AUTH-02 | T4, T10, T11, T14 |
| AUTH-03 | T10, T12, T14 |
| AUTH-04 | T10, T14 |
| AUTH-05 | T10, T14 |
| REG-01 | T6, T9, T10, T11, T14 |
| REG-02 | T3, T10, T14 |
| REG-03 | T10, T14 |
| REG-04 | T10, T11, T14 |
| REG-05 | T3, T5, T14 |
| REG-06 | T4, T10, T11, T14 |
| REG-07 | T1, T2, T12, T14 |
| REG-08 | T3, T7, T8, T14 |
| REG-09 | T1, T11, T12, T13, T14 |
| REG-10 | T13, T14, T15 |

---

## MCPs e Skills (Execute)

| Task | MCP sugerido | Skill |
| --- | --- | --- |
| T1–T15 | Context7 (Laravel RateLimiter / FormRequest se dúvida) | `tlc-spec-driven` (Execute) |
| T14 | — | Verifier automático após T15 |

Perguntar ao usuário antes do Execute se deseja ajustar ferramentas por task.
