# Auth — Verificação de e-mail — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/email-verification/design.md`  
**Spec**: `.specs/features/auth/email-verification/spec.md`  
**Status**: Execute in progress — Batch 1 (T1–T8)

> **Sub-agent note:** 18 tasks → ~3 batches (~6 + ~6 + ~6). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG `fake_link_testing`), §3.1 (Pest Arch), §4 (80/80 Auth), §6.1 (POST explícito, TTL, concorrência, Resend), `LARAVEL_CODE_DESIGN.md` §13/§25/§26, `.specs/features/auth/email-verification/spec.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Domain (`EmailActionPurpose`, `EmailActionToken`, `EmailActionTokenId`) | unit | TTL 3600; UUID v7; used/expired helpers; 1:1 EV-01 parcial | `backend/modules/Auth/Tests/Unit/**/*Test.php` | `make test-backend` |
| Domain (`User::markEmailVerified`) | unit | Status active + `emailVerifiedAt`; imutabilidade | `backend/modules/Auth/Tests/Unit/UserTest.php` | `make test-backend` |
| Migration `email_action_tokens` | integration | Schema CHECK, UNIQUE hash, FK; PG `fake_link_testing` | `backend/modules/Auth/Tests/Integration/EmailActionTokensSchemaContractTest.php` | `make test-backend` |
| Repository (`EloquentEmailActionTokenRepository`) | integration | save/find/invalidate/consume; lock concorrência; EV-03, EV-08 | `backend/modules/Auth/Tests/Integration/EloquentEmailActionTokenRepositoryTest.php` | `make test-backend` |
| Repository (`UserRepository::update`) | integration | Persist `active` + `email_verified_at` | `backend/modules/Auth/Tests/Integration/EloquentUserRepositoryTest.php` | `make test-backend` |
| Use case (`IssueEmailVerificationToken`) | integration | Issue, TTL, invalidate previous, hash only in DB | `backend/modules/Auth/Tests/Integration/IssueEmailVerificationTokenTest.php` | `make test-backend` |
| Use case (`VerifyUserEmail`) | integration | 1:1 AUTH-12,24, EV-07…10; revoke bearer; status transition | `backend/modules/Auth/Tests/Integration/VerifyUserEmailTest.php` | `make test-backend` |
| Use case (`ResendEmailVerification`) | integration | Reenvio + invalidate; already active | `backend/modules/Auth/Tests/Integration/ResendEmailVerificationTest.php` | `make test-backend` |
| Job + Mailable | unit | `Mail::fake()`; URL build; ciphertext payload; no plaintext in logs | `backend/modules/Auth/Tests/Unit/SendEmailVerificationJobTest.php` | `make test-backend` |
| Exceptions + error factory | unit | 403 codes/messages OpenAPI-aligned | `backend/modules/Auth/Tests/Unit/AuthErrorResponseFactoryTest.php` | `make test-backend` |
| Rate limit factory + middlewares | unit | 4º resend / 6º verify → 429; chaves HMAC estáveis | `backend/modules/Auth/Tests/Unit/HmacRateLimitKeyFactoryTest.php`, `ThrottleEmailVerification*Test.php` | `make test-backend` |
| HTTP success factory | unit | `202` Accepted / `204` NoContent headers | `backend/modules/Auth/Tests/Unit/AuthResponseFactoryTest.php` | `make test-backend` |
| Controllers + rotas | feature (E2E) | Todos ACs P1/P2; matrix verify/resend/throttle/edge | `backend/modules/Auth/Tests/Feature/EmailVerificationTest.php` | `make test-backend` |
| Regressão registro | feature | Token row + Mail após register | `backend/modules/Auth/Tests/Feature/RegistrationTest.php` | `make test-backend` |
| Config / contracts / provider wiring | none | — (gate via integration/feature) | — | build gate only |
| OpenAPI sync | none | — (manual/doc gate T18) | — | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após T1–T3, T7, T9 (unit-only) | `make test-backend` |
| Full | Após T4–T6, T8, T10–T12 (integration + unit) | `make test-backend` |
| Build | Após T13–T16 (HTTP + wiring) | `make lint && make test-backend` |
| Final | T17–T18 | `make lint && make test-backend` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schema e domínio

```
T1 → T2 → T3
```

### Phase 2: Persistência e User update

```
T4 → T5
```

### Phase 3: Emissão, exceções e config

```
T6 → T7 → T8
```

### Phase 4: Mail pipeline

```
T9 → T10
```

### Phase 5: Use cases verify e reenvio

```
T11 → T12
```

### Phase 6: Rate limiting

```
T13
```

### Phase 7: Camada HTTP

```
T14 → T15 → T16
```

### Phase 8: E2E, regressão e gates

```
T17 → T18
```

---

## Task Breakdown

### T1: Migration `email_action_tokens`

**What**: Criar migration PostgreSQL `email_action_tokens` (UUID v7, FK users, CHECK purpose, UNIQUE hash).  
**Where**: `backend/database/migrations/` (via `php artisan make:migration` no container)  
**Depends on**: None  
**Reuses**: Padrão `auth_tokens`; AD-012  
**Requirement**: EV-01, AUTH-21

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Migration com colunas conforme design §Migration
- [x] `EmailActionTokensSchemaContractTest` confirma tabela/constraints/index
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add email_action_tokens migration`

---

### T2: Domínio — `EmailActionPurpose`, `EmailActionTokenId`, `EmailActionToken`

**What**: Enum purpose, VO id UUID v7, entidade com helpers expiry/used.  
**Where**: `backend/modules/Auth/Domain/`  
**Depends on**: T1  
**Reuses**: Padrão `TokenKind`, `AuthTokenId`, `AuthToken`  
**Requirement**: EV-01, AUTH-21

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `EmailActionPurpose::EmailVerification` → `email_verification`; TTL 3600
- [x] `EmailActionTokenId` rejeita não-v7
- [x] Unit tests: purpose, UUID inválido, expired/used boundaries
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add email action token domain types`

---

### T3: Contracts — `EmailActionTokenRepository`, `EmailActionTokenIdGenerator`

**What**: Port repository + generator UUID v7.  
**Where**: `backend/modules/Auth/Contracts/`  
**Depends on**: T2  
**Reuses**: `AuthTokenRepository` interface shape  
**Requirement**: EV-01

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Interfaces compilam com tipos de domínio
- [x] `Uuid7EmailActionTokenIdGenerator` implementa port
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add email action token repository contracts`

---

### T4: Persistência Eloquent — `EmailActionTokenModel`, mapper, repository

**What**: Model, mapper, `EloquentEmailActionTokenRepository`, factory, integration tests (save, find, invalidate, consume com lock).  
**Where**: `backend/modules/Auth/Infrastructure/Persistence/Eloquent/`  
**Depends on**: T1, T3  
**Reuses**: `EloquentAuthTokenRepository`, `DatabaseSafetyGuard`  
**Requirement**: EV-01, EV-03, AUTH-21

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `save` persiste hash ≠ plaintext
- [x] `invalidateUnusedForUser` marca `used_at` em unused
- [x] `consumeForUser` atômico; concorrência: 1 sucesso
- [x] Integration tests ≥8 casos
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add eloquent email action token repository`

---

### T5: `User::markEmailVerified` + `UserRepository::update`

**What**: Método domínio + port/adapter update + integration tests.  
**Where**: `Domain/Entities/User.php`, `Contracts/Repositories/UserRepository.php`, `EloquentUserRepository.php`, `UserMapper.php`  
**Depends on**: None (foundation) — **bloqueia T11**  
**Reuses**: Padrão imutável `User::create`  
**Requirement**: EV-08, AUTH-24

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `markEmailVerified` retorna `Active` + timestamp
- [x] `update()` persiste status e `email_verified_at`
- [x] Unit + integration tests passam
- [x] Gate check passes: `make test-backend`

**Tests**: unit + integration  
**Gate**: full

**Commit**: `feat(auth): add user email verified update path`

---

### T6: Config — `email_verification` + rate limits

**What**: Estender `config/auth.php` com URL, TTL, limites resend/verify.  
**Where**: `backend/config/auth.php`, defaults em `.env.testing` se necessário  
**Depends on**: None  
**Reuses**: Blocos `rate_limits.registration/login`  
**Requirement**: EV-04, EV-06

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Config expõe `frontend_base_url`, `path`, `token_ttl_seconds`
- [x] `rate_limits.email_verification_resend` (3/3600) e `_verify` (5/3600)
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add email verification config and rate limits`

---

### T7: Exceções + `AuthErrorResponseFactory` + `AuthResponseFactory`

**What**: `InvalidVerificationTokenException`, `EmailAlreadyVerifiedException`; factory methods 403; `accepted()` 202 + `noContent()` 204.  
**Where**: `Exceptions/`, `Infrastructure/Http/Responses/`  
**Depends on**: None  
**Reuses**: Padrão `InvalidCredentialsException`  
**Requirement**: EV-07, EV-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Códigos/mensagens exatos da spec
- [x] Unit tests estendem `AuthErrorResponseFactoryTest` + `AuthResponseFactoryTest`
- [x] Plaintext sentinela ausente de `getMessage()`
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add email verification error and success responses`

---

### T8: Use case `IssueEmailVerificationToken`

**What**: Emissão com invalidação prévia, TTL 3600, DTO plaintext once.  
**Where**: `UseCases/IssueEmailVerificationToken.php`, `DTOs/Output/IssuedEmailActionTokenDto.php`  
**Depends on**: T4, T6  
**Reuses**: `BearerTokenGenerator`, `TokenHasher`  
**Requirement**: AUTH-20, AUTH-21, EV-01, EV-02, EV-03

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Integration `IssueEmailVerificationTokenTest`: issue, re-issue invalidates, hash in DB, TTL
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add issue email verification token use case`

---

### T9: `EmailVerificationMail` + `SendEmailVerificationJob`

**What**: Mailable pt-BR; job decripta ciphertext, monta URL `{APP_URL}/verify-email?token=`, envia via Mail.  
**Where**: `Infrastructure/Mail/`, `Infrastructure/Jobs/`, view markdown  
**Depends on**: T6, T8  
**Reuses**: Laravel `Crypt`, `Mail` facade  
**Requirement**: AUTH-20, AUTH-25, EV-02

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Job constructor recebe `userId` + `encryptedToken` (sem plaintext serializado)
- [x] Unit test `Mail::fake()` assert destinatário; URL base correta; token ausente de logs
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add email verification mailable and job`

---

### T10: Refatorar `LaravelQueueEmailVerification`

**What**: Adapter chama `IssueEmailVerificationToken` + dispatch job cifrado; substitui stub.  
**Where**: `Infrastructure/Notifications/LaravelQueueEmailVerification.php`, `AuthServiceProvider.php` bindings  
**Depends on**: T8, T9  
**Reuses**: Port existente `QueueEmailVerification`  
**Requirement**: AUTH-20, EV-01

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `dispatch(UserId)` emite token + enfileira job
- [x] `RegisterUser` fluxo inalterado no contrato
- [x] Gate check passes: `make test-backend`

**Tests**: none (coberto T17/T18 regressão)  
**Gate**: full

**Commit**: `feat(auth): wire email verification queue to issue and send`

---

### T11: Use case `VerifyUserEmail`

**What**: Consumo token, activate user, revoke bearer apresentado; DTO input.  
**Where**: `UseCases/VerifyUserEmail.php`, `DTOs/Input/VerifyUserEmailDto.php`  
**Depends on**: T4, T5, T7  
**Reuses**: `RevokeAuthToken`, `AuthenticatedPrincipal`  
**Requirement**: AUTH-12, AUTH-22, AUTH-24, EV-07…EV-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Integration matrix: happy path, invalid, expired, used, already active, wrong user token
- [x] Bearer revogado; user `active`; sem emissão session
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add VerifyUserEmail use case`

---

### T12: Use case `ResendEmailVerification`

**What**: Reenvio para pending; already active → exception; issue + enqueue.  
**Where**: `UseCases/ResendEmailVerification.php`  
**Depends on**: T8, T10, T7  
**Reuses**: `IssueEmailVerificationToken`, queue adapter  
**Requirement**: AUTH-23, EV-04, EV-05

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Integration: success enqueues; invalidate previous; active → exception
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add ResendEmailVerification use case`

---

### T13: Rate limit — HMAC keys + middlewares resend/verify

**What**: `forEmailVerificationResend/Verify(UserId)` + dois middlewares; aliases `bootstrap/app.php`.  
**Where**: `HmacRateLimitKeyFactory.php`, `Infrastructure/Http/Middleware/`, `bootstrap/app.php`  
**Depends on**: T6  
**Reuses**: `ThrottleRegistration`, `ThrottleLogin`  
**Requirement**: EV-06, EV-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] 4º resend / 6º verify → 429 + Retry-After
- [x] Middleware lê `AuthenticatedPrincipal` do container
- [x] Unit tests throttle + factory
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add email verification rate limit middlewares`

---

### T14: `VerifyEmailRequest` + `VerifyEmailController` + rota

**What**: Form request OpenAPI; controller fino; rota POST `email/verify` com middleware stack.  
**Where**: `Infrastructure/Http/`  
**Depends on**: T11, T13, T7  
**Reuses**: `ApiFormRequest`, `LoginUserController` mapping  
**Requirement**: AUTH-22, EV-07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Stack: `auth.bearer` → `token.kind:verification` → `throttle.email_verification.verify`
- [x] Gate check passes: `make test-backend`

**Tests**: feature (via T17)  
**Gate**: build

**Commit**: `feat(auth): add verify email endpoint`

---

### T15: `ResendEmailVerificationController` + rota

**What**: Controller + rota POST `email/verification-notification`.  
**Where**: `Infrastructure/Http/Controllers/`, `routes/auth.php`  
**Depends on**: T12, T13, T7  
**Reuses**: Padrão T14  
**Requirement**: AUTH-23, EV-04

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Stack: auth → kind → throttle resend
- [x] Gate check passes: `make test-backend`

**Tests**: feature (via T17)  
**Gate**: build

**Commit**: `feat(auth): add resend email verification endpoint`

---

### T16: `AuthServiceProvider` — bindings e rotas

**What**: Registrar use cases, repository, middleware resolvíveis, rotas completas.  
**Where**: `ServiceProviders/AuthServiceProvider.php`  
**Depends on**: T10, T14, T15  
**Reuses**: Bindings existentes  
**Requirement**: EV-04, EV-07

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Container resolve todos ports/use cases novos
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: none  
**Gate**: build

**Commit**: `feat(auth): wire email verification services in provider`

---

### T17: Feature tests E2E — `EmailVerificationTest`

**What**: Suite cobrindo verify/resend happy path, invalid token, already active, throttle, validation 422, bearer 401/403, login pós-verify emite session.  
**Where**: `backend/modules/Auth/Tests/Feature/EmailVerificationTest.php`  
**Depends on**: T16  
**Reuses**: `RegistrationTest`/`LoginTest` helpers, `Mail::fake()`, `IssueAuthToken`  
**Requirement**: EV-04…EV-12, AUTH-12…25

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Matrix P1/P2 da spec coberta
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: build

**Commit**: `test(auth): add email verification feature tests`

---

### T18: Regressão registro + OpenAPI + gates finais

**What**: Atualizar `RegistrationTest`/`RegisterUserTest` para assert `email_action_tokens` row + Mail; sync `docs/openapi.yaml` códigos 403; handoff STATE.  
**Where**: `RegistrationTest.php`, `RegisterUserTest.php`, `docs/openapi.yaml`, `.specs/STATE.md`  
**Depends on**: T17  
**Reuses**: Gates Makefile  
**Requirement**: EV-13, EV-14, AUTH-20

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute → Verifier automático)

**Done when**:

- [ ] Registro dispara token e-mail + Mail::fake assert
- [ ] OpenAPI examples `INVALID_VERIFICATION_TOKEN`, `EMAIL_ALREADY_VERIFIED`
- [ ] `make lint && make test-backend` verde
- [ ] Verifier sub-agent pós-T18

**Tests**: feature (regressão)  
**Gate**: final

**Commit**: `chore(auth): complete email verification slice gates and openapi`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6 → Phase 7 → Phase 8

Phase 1:  T1 ──→ T2 ──→ T3
Phase 2:  T4 ──→ T5
Phase 3:  T6 ──→ T7 ──→ T8
Phase 4:  T9 ──→ T10
Phase 5:  T11 ──→ T12
Phase 6:  T13
Phase 7:  T14 ──→ T15 ──→ T16
Phase 8:  T17 ──→ T18
```

Execution is strictly sequential — one atomic commit per task.

**Batch packing (Execute):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | 1–3 | T1–T8 (8 tasks) |
| 2 | 4–6 | T9–T13 (5 tasks) |
| 3 | 7–8 | T14–T18 (5 tasks) |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: Migration | 1 migration + schema test | ✅ Granular |
| T2: Domain types | 1 camada + unit | ✅ Granular |
| T3: Contracts | interfaces + generator | ✅ Granular |
| T4: Eloquent repository | persistência + integration | ✅ Granular |
| T5: User update | domain + port method | ✅ Granular |
| T6: Config | 1 config extend | ✅ Granular |
| T7: Exceptions + factories | 2 exceptions + factory methods | ✅ Granular |
| T8: Issue use case | 1 use case + integration | ✅ Granular |
| T9: Mail + job | mailable + job + unit | ✅ Granular |
| T10: Queue adapter | 1 adapter refactor | ✅ Granular |
| T11: Verify use case | 1 use case + integration | ✅ Granular |
| T12: Resend use case | 1 use case + integration | ✅ Granular |
| T13: Rate limit | factory ext + 2 middlewares | ✅ Granular |
| T14: Verify HTTP | 1 endpoint | ✅ Granular |
| T15: Resend HTTP | 1 endpoint | ✅ Granular |
| T16: Provider wiring | 1 provider update | ✅ Granular |
| T17: Feature E2E | 1 test file | ✅ Granular |
| T18: Gates + docs | regressão + openapi | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | Phase 1 start | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | T1, T3 | Phase 2 T4 | ✅ Match |
| T5 | None | Phase 2 T5 parallel | ✅ Match |
| T6 | None | Phase 3 start | ✅ Match |
| T7 | None | Phase 3 T7 | ✅ Match |
| T8 | T4, T6 | T4,T6 → T8 | ✅ Match |
| T9 | T6, T8 | T8 → T9 | ✅ Match |
| T10 | T8, T9 | T9 → T10 | ✅ Match |
| T11 | T4, T5, T7 | Phase 5 T11 | ✅ Match |
| T12 | T8, T10, T7 | T8,T10 → T12 | ✅ Match |
| T13 | T6 | Phase 6 | ✅ Match |
| T14 | T11, T13, T7 | T11,T13 → T14 | ✅ Match |
| T15 | T12, T13, T7 | T12,T13 → T15 | ✅ Match |
| T16 | T10, T14, T15 | T14,T15 → T16 | ✅ Match |
| T17 | T16 | T16 → T17 | ✅ Match |
| T18 | T17 | T17 → T18 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Migration | integration | integration | ✅ OK |
| T2 | Domain | unit | unit | ✅ OK |
| T3 | Contracts | none | none | ✅ OK |
| T4 | Repository | integration | integration | ✅ OK |
| T5 | User update | unit + integration | unit + integration | ✅ OK |
| T6 | Config | none | none | ✅ OK |
| T7 | Error/success factories | unit | unit | ✅ OK |
| T8 | Issue use case | integration | integration | ✅ OK |
| T9 | Job + Mailable | unit | unit | ✅ OK |
| T10 | Queue adapter | none | none (T17/T18) | ✅ OK |
| T11 | Verify use case | integration | integration | ✅ OK |
| T12 | Resend use case | integration | integration | ✅ OK |
| T13 | Middleware | unit | unit | ✅ OK |
| T14 | Verify HTTP | feature | feature via T17 | ✅ OK |
| T15 | Resend HTTP | feature | feature via T17 | ✅ OK |
| T16 | Provider | none | none | ✅ OK |
| T17 | Feature E2E | feature | feature | ✅ OK |
| T18 | Regressão + docs | feature | feature | ✅ OK |

---

## Requirement → Task Mapping

| Requirement ID | Task(s) |
| --- | --- |
| AUTH-12 | T11, T17 |
| AUTH-20 | T8, T9, T10, T18 |
| AUTH-21 | T1, T2, T4, T8, T17 |
| AUTH-22 | T11, T14, T17 |
| AUTH-23 | T12, T15, T17 |
| AUTH-24 | T5, T11, T17 |
| AUTH-25 | T7, T9, T17 |
| EV-01 | T1, T2, T8, T10 |
| EV-02 | T8, T9 |
| EV-03 | T4, T8 |
| EV-04 | T6, T12, T15, T17 |
| EV-05 | T12, T17 |
| EV-06 | T6, T13, T17 |
| EV-07 | T7, T11, T14, T17 |
| EV-08 | T5, T11, T17 |
| EV-09 | T11, T17 |
| EV-10 | T11, T13, T17 |
| EV-11 | T7, T9, T17 |
| EV-12 | T7, T17 |
| EV-13 | T17, T18 |
| EV-14 | T18 |

**Coverage:** 21/21 requirements mapped ✅

---

## MCPs e Skills (Execute)

| Task | MCP sugerido | Skill |
| --- | --- | --- |
| T1 | Context7 (Laravel migration) se dúvida | `tlc-spec-driven` (Execute) |
| T9 | Context7 (Laravel Mail/Mailable) se dúvida | `tlc-spec-driven` |
| T1–T18 | — | Verifier automático após T18 |

Perguntar ao mantenedor antes do Execute se deseja ajustar ferramentas por task.
