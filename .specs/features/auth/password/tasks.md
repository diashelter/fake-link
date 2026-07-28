# Auth — Senha (alterar e recuperar) — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/password/design.md`  
**Spec**: `.specs/features/auth/password/spec.md`  
**Context**: `.specs/features/auth/password/context.md`  
**Status**: Draft — awaiting approval (Tasks)

> **Sub-agent note:** 18 tasks → ~3 batches (~6 + ~6 + ~6). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG `fake_link_testing`), §3.1 (Pest Arch), §4 (80/80 Auth), §6.1 (tokens recuperação, revogação, anti-enumeração), `LARAVEL_CODE_DESIGN.md` §13/§25/§26, `.specs/features/auth/password/spec.md`, `.specs/features/auth/email-verification/tasks.md` (floor de profundidade).

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Domain (`EmailActionPurpose::PasswordReset`) | unit | TTL 1800; fromString | `backend/modules/Auth/Tests/Unit/EmailActionPurposeTest.php` | `make test-backend` |
| Domain (`User::withPasswordHash`) | unit | Hash atualizado; status inalterado; imutabilidade | `backend/modules/Auth/Tests/Unit/UserTest.php` | `make test-backend` |
| Migration CHECK purpose | integration | CHECK aceita ambos purposes; PG `fake_link_testing` | `backend/modules/Auth/Tests/Integration/EmailActionTokensSchemaContractTest.php` | `make test-backend` |
| Use case (`IssuePasswordResetToken`) | integration | Issue, TTL 1800, invalidate previous, hash only | `backend/modules/Auth/Tests/Integration/IssuePasswordResetTokenTest.php` | `make test-backend` |
| Job + Mailable | unit | `Mail::fake()`; URL `/reset-password`; ciphertext; sem plaintext em logs | `backend/modules/Auth/Tests/Unit/SendPasswordResetJobTest.php` | `make test-backend` |
| Use case (`RequestPasswordReset`) | integration | 202 path: active→token+job; non-active/missing→noop; dummy verify sempre | `backend/modules/Auth/Tests/Integration/RequestPasswordResetTest.php` | `make test-backend` |
| Use case (`ResetPassword`) | integration | 1:1 AUTH-27/28/33, PW-05…08, PW-17; concorrência; purpose mismatch | `backend/modules/Auth/Tests/Integration/ResetPasswordTest.php` | `make test-backend` |
| Use case (`ChangePassword`) | integration | 1:1 AUTH-32/33, PW-09…11, PW-17; wrong current; revoke all | `backend/modules/Auth/Tests/Integration/ChangePasswordTest.php` | `make test-backend` |
| Exceptions + validation factory | unit | `PASSWORD_REUSED` + token message OpenAPI-aligned | `backend/modules/Auth/Tests/Unit/AuthValidationResponseFactoryTest.php` | `make test-backend` |
| Rate limit factory + middlewares | unit | 4º request / 6º reset / 121ª write → 429; chaves HMAC | `…/HmacRateLimitKeyFactoryTest.php`, `ThrottlePassword*Test.php`, `ThrottlePrivateAuthWriteTest.php` | `make test-backend` |
| Controllers + rotas reset | feature (E2E) | ACs P1 reset-request/reset; throttle; anti-enum; edges | `backend/modules/Auth/Tests/Feature/PasswordResetTest.php` | `make test-backend` |
| Controllers + rotas change | feature (E2E) | ACs P1 change; TOKEN_RESTRICTED; PW-17; revoke | `backend/modules/Auth/Tests/Feature/PasswordChangeTest.php` | `make test-backend` |
| Config / contracts / provider wiring | none | — (gate via integration/feature) | — | build gate only |
| OpenAPI sync | none | — (doc gate T18) | — | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após T2, T8, T12 (unit-only) | `make test-backend` |
| Full | Após T1, T4–T7, T9–T11 (integration + unit) | `make test-backend` |
| Build | Após T13–T15 (HTTP + wiring) | `make lint && make test-backend` |
| Final | T16–T18 | `make lint && make test-backend` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Schema, domínio e config

```
T1 → T2 → T3
```

### Phase 2: Emissão e mail pipeline

```
T4 → T5 → T6
```

### Phase 3: Use cases request / reset / change

```
T7 → T8 → T9 → T10
```

### Phase 4: Rate limiting

```
T11
```

### Phase 5: Camada HTTP

```
T12 → T13 → T14 → T15
```

### Phase 6: E2E, OpenAPI e gates

```
T16 → T17 → T18
```

---

## Task Breakdown

### T1: Migration — permitir purpose `password_reset`

**What**: Migration que altera CHECK de `email_action_tokens` para incluir `password_reset`.  
**Where**: `backend/database/migrations/` (via `php artisan make:migration` no container)  
**Depends on**: None  
**Reuses**: Migration EV; AD-011  
**Requirement**: PW-16, AUTH-27

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] CHECK aceita `email_verification` e `password_reset`
- [x] `EmailActionTokensSchemaContractTest` atualizado e passa
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): allow password_reset on email_action_tokens`

---

### T2: Domínio — `EmailActionPurpose::PasswordReset` + TTL 1800

**What**: Adicionar case `PasswordReset` e TTL 1800s; unit tests.  
**Where**: `backend/modules/Auth/Domain/Enums/EmailActionPurpose.php`, `Tests/Unit/EmailActionPurposeTest.php`  
**Depends on**: T1  
**Reuses**: Enum EV  
**Requirement**: PW-16, AUTH-27

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `PasswordReset->value === 'password_reset'`
- [x] `absoluteTtlSeconds() === 1800`
- [x] Unit tests passam
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add password_reset email action purpose`

---

### T3: Config — `password_reset` + rate limits

**What**: Estender `config/auth.php` com URL/path/TTL e três rate limits; defaults em `.env.testing` se necessário.  
**Where**: `backend/config/auth.php`  
**Depends on**: None  
**Reuses**: Blocos `email_verification` / `rate_limits`  
**Requirement**: PW-01, PW-04, PW-08, PW-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Keys `auth.password_reset.*` e `auth.rate_limits.password_reset_*` / `private_auth_write` presentes
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add password reset config and rate limits`

---

### T4: Use case — `IssuePasswordResetToken`

**What**: Emitir token `password_reset`, invalidar unused anteriores, retornar plaintext uma vez; integration tests.  
**Where**: `UseCases/IssuePasswordResetToken.php`, `Tests/Integration/IssuePasswordResetTokenTest.php`  
**Depends on**: T1, T2  
**Reuses**: `IssueEmailVerificationToken`  
**Requirement**: AUTH-27, PW-02, PW-16

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] TTL 1800; hash ≠ plaintext no DB
- [x] Segundo issue invalida o primeiro unused
- [x] Integration tests ≥4 casos
- [x] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): issue password reset email action tokens`

---

### T5: Port + adapter — `QueuePasswordReset`

**What**: Contract `QueuePasswordReset` + `LaravelQueuePasswordReset` (issue + dispatch job cifrado).  
**Where**: `Contracts/Services/QueuePasswordReset.php`, `Infrastructure/Notifications/LaravelQueuePasswordReset.php`  
**Depends on**: T4  
**Reuses**: `QueueEmailVerification` / `LaravelQueueEmailVerification`  
**Requirement**: AUTH-29, PW-02

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Adapter chama Issue + enfileira job com ciphertext
- [x] Registrado no `AuthServiceProvider`
- [x] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add queue password reset port`

---

### T6: Job + Mailable — envio de recuperação

**What**: `SendPasswordResetJob`, `PasswordResetMail`, view pt-BR, unit tests com `Mail::fake()`.  
**Where**: `Infrastructure/Jobs/`, `Mail/`, `resources/views/mail/`, `Tests/Unit/SendPasswordResetJobTest.php`  
**Depends on**: T3, T5  
**Reuses**: `SendEmailVerificationJob` / `EmailVerificationMail`  
**Requirement**: AUTH-29, PW-02, PW-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] URL `{base}/reset-password?token=…`; token só no corpo
- [x] Payload cifrado; sentinela ausente de exceptions/logs
- [x] Unit tests passam
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): send password reset mail via Resend job`

---

### T7: Use case — `RequestPasswordReset`

**What**: Anti-enumeração; só `active` enfileira; dummy verify sempre; integration tests.  
**Where**: `UseCases/RequestPasswordReset.php`, `DTOs/Input/RequestPasswordResetDto.php`, `Tests/Integration/RequestPasswordResetTest.php`  
**Depends on**: T5, T6  
**Reuses**: `LoginUser` dummy timing  
**Requirement**: AUTH-26, AUTH-29, PW-01, PW-03, PW-04

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Active → 1 token + 1 job; missing/pending/suspended/deletion_pending → 0 token/job
- [ ] `PasswordHasher::verify` chamado em todos os caminhos
- [ ] Integration tests ≥5 casos
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): request password reset without enumeration`

---

### T8: Domínio — `User::withPasswordHash`

**What**: Método imutável de troca de hash; unit tests.  
**Where**: `Domain/Entities/User.php`, `Tests/Unit/UserTest.php`  
**Depends on**: None  
**Reuses**: `markEmailVerified` pattern  
**Requirement**: AUTH-28, AUTH-32

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Novo hash refletido; status/`emailVerifiedAt`/terms inalterados
- [ ] Unit tests passam
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add User::withPasswordHash`

---

### T9: Use case — `ResetPassword`

**What**: Consumir token, rejeitar reused, atualizar hash, `RevokeAllUserTokens`; integration tests (TTL, concorrência, purpose, PW-17).  
**Where**: `UseCases/ResetPassword.php`, `Exceptions/InvalidPasswordResetTokenException.php`, `Exceptions/PasswordReusedException.php`, `Tests/Integration/ResetPasswordTest.php`  
**Depends on**: T4, T8  
**Reuses**: `VerifyUserEmail` consume txn; `RevokeAllUserTokens`  
**Requirement**: AUTH-27, AUTH-28, AUTH-33, PW-05…PW-08, PW-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Happy path: used_at set; hash novo; zero auth_tokens; status intacto
- [ ] Token inválido / email mismatch / purpose errado → InvalidPasswordResetTokenException
- [ ] Senha = atual → PasswordReusedException; token unused
- [ ] Concorrência: 1 sucesso
- [ ] Integration tests ≥8 casos
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): reset password with one-time token`

---

### T10: Use case — `ChangePassword`

**What**: Verificar current; rejeitar reused; update hash; revoke all; integration tests.  
**Where**: `UseCases/ChangePassword.php`, `DTOs/Input/ChangePasswordDto.php`, `Tests/Integration/ChangePasswordTest.php`  
**Depends on**: T8, T9 (exceção PasswordReused compartilhada)  
**Reuses**: `InvalidCredentialsException`; `RevokeAllUserTokens`  
**Requirement**: AUTH-32, AUTH-33, PW-09…PW-11, PW-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Happy path: hash novo; todos bearers revogados
- [ ] Current errada → InvalidCredentialsException; tokens intactos
- [ ] Senha = atual → PasswordReusedException; tokens intactos
- [ ] Integration tests ≥5 casos
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): change password and revoke all tokens`

---

### T11: Rate limit — HMAC keys + três middlewares

**What**: Métodos HMAC + `ThrottlePasswordResetRequest`, `ThrottlePasswordReset`, `ThrottlePrivateAuthWrite`; aliases bootstrap; unit tests.  
**Where**: `HmacRateLimitKeyFactory.php`, `Infrastructure/Http/Middleware/`, `bootstrap/app.php`, unit tests  
**Depends on**: T3  
**Reuses**: Throttle login/EV  
**Requirement**: PW-04, PW-08, PW-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] 4º reset-request / 6º reset / 121ª write → 429 + Retry-After
- [ ] Conta todas as tentativas antes do use case
- [ ] Unit tests passam
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): throttle password reset and private writes`

---

### T12: `AuthValidationResponseFactory` + unit tests

**What**: Factory `invalidPasswordResetToken()` e `passwordReused()` via `ApiResponse::validationError`.  
**Where**: `Infrastructure/Http/Responses/AuthValidationResponseFactory.php`, `Tests/Unit/AuthValidationResponseFactoryTest.php`  
**Depends on**: None  
**Reuses**: `ApiResponse::validationError`  
**Requirement**: PW-07, PW-13, PW-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Token: 422 + `errors.token[0].message` exata da spec
- [ ] Reused: 422 + `errors.password[0].code === PASSWORD_REUSED` + message exata
- [ ] Headers `Cache-Control` / request id se padrão do projeto exigir
- [ ] Unit tests passam
- [ ] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `feat(auth): add password validation error response factory`

---

### T13: Form Requests — reset-request, reset, change

**What**: Três Form Requests OpenAPI-aligned + `PasswordPolicyRule` / confirmed.  
**Where**: `Infrastructure/Http/Requests/`  
**Depends on**: None  
**Reuses**: `RegisterUserRequest` / `PasswordPolicyRule`  
**Requirement**: PW-01, PW-05, PW-09

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `additionalProperties` rejeitados; schemas batem OpenAPI
- [ ] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add password form requests`

---

### T14: Controllers + rotas password

**What**: Três controllers finos + rotas em `auth.php` com middleware stacks do design.  
**Where**: `Infrastructure/Http/Controllers/`, `routes/auth.php`  
**Depends on**: T7, T9, T10, T11, T12, T13  
**Reuses**: Controllers EV/Login  
**Requirement**: AUTH-26…29, AUTH-32, PW-01…PW-11, PW-13, PW-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Rotas registradas sob `/api/v1/auth/password/*`
- [ ] Exceções mapeadas para factories corretas
- [ ] Sucesso `202` / `204`
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: none (E2E em T16–T17)  
**Gate**: build  

> **Co-location note:** Controllers isolados não têm feature tests até T16/T17 — essas tasks absorvem o gate E2E (merge forward, conforme tasks.md). T14 só wiring compilável + lint.

**Commit**: `feat(auth): wire password HTTP endpoints`

---

### T15: Provider wiring — bind use cases / queue / factory

**What**: Registrar bindings/singletons no `AuthServiceProvider`; aliases middleware se faltarem.  
**Where**: `ServiceProviders/AuthServiceProvider.php`, `bootstrap/app.php`  
**Depends on**: T5, T7, T9, T10, T11, T12, T14  
**Reuses**: Bindings EV  
**Requirement**: PW-14

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Container resolve todos os use cases/ports novos
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: none  
**Gate**: build

**Commit**: `feat(auth): register password services in AuthServiceProvider`

---

### T16: Feature E2E — `PasswordResetTest`

**What**: Feature tests dos endpoints reset-request e reset cobrindo ACs P1 + edges + throttle.  
**Where**: `Tests/Feature/PasswordResetTest.php`  
**Depends on**: T14, T15  
**Reuses**: `EmailVerificationTest` / `LoginTest` style  
**Requirement**: AUTH-26…29, PW-01…PW-08, PW-12…PW-15, PW-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Happy path request→mail→reset→login nova senha
- [ ] Anti-enum 202; token inválido 422; PW-17; 429; GET sem efeito
- [ ] Headers `Cache-Control` / request id
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: final

**Commit**: `test(auth): add password reset feature coverage`

---

### T17: Feature E2E — `PasswordChangeTest`

**What**: Feature tests do endpoint change cobrindo ACs P1 + TOKEN_RESTRICTED + PW-17 + revoke.  
**Where**: `Tests/Feature/PasswordChangeTest.php`  
**Depends on**: T14, T15, T16  
**Reuses**: `LoginTest` bearer helpers  
**Requirement**: AUTH-32, AUTH-33, PW-09…PW-15, PW-17

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Happy path change → bearer antigo 401; login nova senha 200
- [ ] Current errada 401; verification bearer 403; PW-17 422
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: feature (E2E)  
**Gate**: final

**Commit**: `test(auth): add password change feature coverage`

---

### T18: OpenAPI `PASSWORD_REUSED` + gate final

**What**: Documentar exemplo `PASSWORD_REUSED` em `docs/openapi.yaml`; gate final completo.  
**Where**: `docs/openapi.yaml`  
**Depends on**: T16, T17  
**Reuses**: ValidationError examples  
**Requirement**: PW-13, PW-14, PW-15

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Exemplo `PASSWORD_REUSED` presente no OpenAPI
- [ ] `make lint && make test-backend` verde sem regressão
- [ ] Spec success criteria checáveis cobertos

**Tests**: none  
**Gate**: final

**Commit**: `docs(auth): document PASSWORD_REUSED validation error`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6

Phase 1:  T1 ──→ T2 ──→ T3
Phase 2:  T4 ──→ T5 ──→ T6
Phase 3:  T7 ──→ T8 ──→ T9 ──→ T10
Phase 4:  T11
Phase 5:  T12 ──→ T13 ──→ T14 ──→ T15
Phase 6:  T16 ──→ T17 ──→ T18
```

**Suggested batches (~7 tasks):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | 1–2 | T1–T6 |
| 2 | 3–4 | T7–T11 |
| 3 | 5–6 | T12–T18 |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1 | 1 migration + schema test update | ✅ Granular |
| T2 | 1 enum case + unit | ✅ Granular |
| T3 | 1 config file | ✅ Granular |
| T4 | 1 use case + integration | ✅ Granular |
| T5 | 1 port + adapter | ✅ Granular |
| T6 | Job + Mailable + unit (cohesive mail pipeline) | ✅ OK cohesive |
| T7 | 1 use case + integration | ✅ Granular |
| T8 | 1 domain method + unit | ✅ Granular |
| T9 | 1 use case + exceptions + integration | ✅ OK cohesive |
| T10 | 1 use case + integration | ✅ Granular |
| T11 | Factory methods + 3 middlewares (cohesive throttle) | ✅ OK cohesive |
| T12 | 1 factory + unit | ✅ Granular |
| T13 | 3 Form Requests same concern | ✅ OK cohesive |
| T14 | 3 controllers + routes | ✅ OK cohesive |
| T15 | Provider wiring | ✅ Granular |
| T16 | 1 feature file | ✅ Granular |
| T17 | 1 feature file | ✅ Granular |
| T18 | OpenAPI + final gate | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ |
| T2 | T1 | T1→T2 | ✅ |
| T3 | None | parallel start Phase 1 | ✅ (no arrow required) |
| T4 | T1, T2 | Phase2 after Phase1; T4 after T2 | ✅ |
| T5 | T4 | T4→T5 | ✅ |
| T6 | T3, T5 | T5→T6; T3 available | ✅ |
| T7 | T5, T6 | Phase3 after Phase2 | ✅ |
| T8 | None | within Phase3 before T9 | ✅ |
| T9 | T4, T8 | T8→T9; T4 prior | ✅ |
| T10 | T8, T9 | T9→T10 | ✅ |
| T11 | T3 | Phase4; config ready | ✅ |
| T12 | None | Phase5 start | ✅ |
| T13 | None | Phase5 | ✅ |
| T14 | T7, T9, T10, T11, T12, T13 | after prior phases | ✅ |
| T15 | T5, T7, T9, T10, T11, T12, T14 | T14→T15 | ✅ |
| T16 | T14, T15 | T15→T16 | ✅ |
| T17 | T14, T15, T16 | T16→T17 | ✅ |
| T18 | T16, T17 | T17→T18 | ✅ |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| ---- | --------------------------- | --------------- | --------- | ------ |
| T1 | Migration schema | integration | integration | ✅ |
| T2 | Domain purpose | unit | unit | ✅ |
| T3 | Config | none | none | ✅ |
| T4 | Use case Issue | integration | integration | ✅ |
| T5 | Port/adapter | none | none | ✅ |
| T6 | Job + Mailable | unit | unit | ✅ |
| T7 | Use case Request | integration | integration | ✅ |
| T8 | Domain User | unit | unit | ✅ |
| T9 | Use case Reset | integration | integration | ✅ |
| T10 | Use case Change | integration | integration | ✅ |
| T11 | Rate limit + middleware | unit | unit | ✅ |
| T12 | Validation factory | unit | unit | ✅ |
| T13 | Form Requests | none | none | ✅ |
| T14 | Controllers/routes | feature | none (E2E merge-forward T16/T17) | ✅ OK — documented |
| T15 | Provider wiring | none | none | ✅ |
| T16 | Controllers E2E reset | feature | feature | ✅ |
| T17 | Controllers E2E change | feature | feature | ✅ |
| T18 | OpenAPI | none | none | ✅ |

---

## Requirement Traceability (tasks)

| Requirement ID | Tasks |
| --- | --- |
| AUTH-26 | T7, T16 |
| AUTH-27 | T1, T2, T4, T9, T16 |
| AUTH-28 | T8, T9, T16 |
| AUTH-29 | T5, T6, T7, T16 |
| AUTH-32 | T8, T10, T17 |
| AUTH-33 | T9, T10, T16, T17 |
| PW-01…PW-04 | T3, T7, T11, T13, T16 |
| PW-05…PW-08 | T9, T11, T12, T16 |
| PW-09…PW-11 | T10, T11, T13, T17 |
| PW-12 | T6, T16 |
| PW-13 | T12, T16, T18 |
| PW-14, PW-15 | T15, T16, T17, T18 |
| PW-16 | T1, T2, T4 |
| PW-17 | T9, T10, T12, T16, T17 |

**Coverage:** 23 requirement IDs mapped ✅
