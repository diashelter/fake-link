# Auth — Fechamento oficial do módulo backend — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/module-closure/design.md`  
**Spec**: `.specs/features/auth/module-closure/spec.md`  
**Status**: Implementing — Batch 2 (T9–T13) ✅ complete; next Batch 3 (T14–T19)

> **Sub-agent note:** 19 tasks → 3 batches phase-aligned (T1–T8 | T9–T13 | T14–T19). User accepted sub-agents 2026-08-11.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (Docker-only, PG `fake_link_testing`), §3.1 (Pest Arch), §4 (80/80 Auth), §6.1, §10 (OpenAPI lint + contract), `LARAVEL_CODE_DESIGN.md`, `.specs/features/auth/module-closure/spec.md` (ABMC-01…18, P2 gaps), `.specs/LESSONS.md` L-024, L-035.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| OpenAPI lint tooling | integration (compose smoke) | `make lint-openapi` exit 0 on valid yaml; exit ≠0 on `$ref` quebrado (manual/sensor) | `scripts/lint-openapi.sh`, `tests/compose/` (optional smoke) | `make lint-openapi` |
| OpenAPI document loader | unit | Load yaml; resolve `$ref` schema; fail claro se path missing | `backend/modules/Auth/Tests/Unit/OpenApiDocumentTest.php` | `make test-backend` |
| OpenAPI schema assert helper | unit | `additionalProperties: false`; required keys; error envelope | `backend/modules/Auth/Tests/Unit/OpenApiSchemaAssertTest.php` | `make test-backend` |
| Auth contract tests (11 endpoints) | feature (contract) | 1:1 ABMC-05 matrix: happy + error codes documentados; headers representativos | `backend/modules/Auth/Tests/Contract/*ContractTest.php` | `make test-backend` |
| P2 login token failure | feature | 500 INTERNAL_ERROR; zero tokens persisted | `backend/modules/Auth/Tests/Feature/LoginTest.php` | `make test-backend` |
| P2 email whitespace token | feature | 422 VALIDATION_FAILED; token unused | `backend/modules/Auth/Tests/Feature/EmailVerificationTest.php` | `make test-backend` |
| P2 password enqueue failure | integration | Token persisted + failure observable OR ops-verified note | `backend/modules/Auth/Tests/Integration/RequestPasswordResetTest.php` | `make test-backend` |
| P2 logout-all concurrent | feature | Final token count 0 | `backend/modules/Auth/Tests/Feature/LogoutAllTest.php` | `make test-backend` |
| Docker / Makefile / CI / docs | none | — (gate via lint/test commands) | — | build gate only |
| Fechamento documental | none | — (Verifier + manual review) | `.specs/`, `README.md` | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| OpenAPI lint | After T1–T5 (tooling) | `make lint-openapi` |
| Quick | After T7–T8 (unit helpers) | `make test-backend` |
| Full | After T9–T17 (contract + P2) | `make test-backend` |
| Build | After T5, T13, T17 | `make lint && make test-backend` |
| Final | T19 (pre-Verifier) | `make lint && make test-backend-coverage` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: OpenAPI tooling

```
T1 → T2 → T3 → T4 → T5
```

### Phase 2: Test infrastructure (Support OpenAPI)

```
T6 → T7 → T8
```

### Phase 3: Contract tests Auth

```
T9 → T10 → T11 → T12 → T13
```

### Phase 4: P2 — gaps menores

```
T14 → T15 → T16 → T17
```

### Phase 5: Fechamento documental e gates finais

```
T18 → T19
```

---

## Task Breakdown

### T1: Pin Spectral no monorepo raiz

**What**: Adicionar `@stoplight/spectral-cli` (versão pinada) em `package.json` raiz e atualizar `pnpm-lock.yaml`.  
**Where**: `/package.json`, `/pnpm-lock.yaml`  
**Depends on**: None  
**Reuses**: `packageManager: pnpm@11.15.1`; AD-005  
**Requirement**: ABMC-01

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `devDependencies` inclui `@stoplight/spectral-cli` com versão exata
- [x] Lockfile raiz atualizado
- [x] `pnpm install --frozen-lockfile` passa no serviço `openapi-tooling` (após T3) ou documentado como blocked until T3

**Tests**: none  
**Gate**: build

**Commit**: `chore(tooling): pin spectral-cli for openapi lint`

---

### T2: Config Spectral (`.spectral.yaml`)

**What**: Criar ruleset OAS 3.1 estendendo `spectral:oas` com regras mínimas do projeto.  
**Where**: `.spectral.yaml` (repo raiz)  
**Depends on**: T1  
**Reuses**: `docs/openapi.yaml` structure  
**Requirement**: ABMC-01, ABMC-02

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Arquivo extends `spectral:oas`
- [x] Regras ativas: `operation-operationId-unique`, `operation-tag-defined` (e demais que passam no yaml atual sem warn excessivo)
- [x] Documentado no arquivo quais rules são error vs warn

**Tests**: none  
**Gate**: build

**Commit**: `chore(tooling): add spectral ruleset for openapi`

---

### T3: Docker — `openapi-tooling` + mount `docs/` no backend

**What**: Adicionar serviço `openapi-tooling` e volume read-only `./docs:/var/www/docs:ro` no anchor backend.  
**Where**: `docker-compose.yml`  
**Depends on**: None  
**Reuses**: AD-008; padrão `test-runner` / serviços auxiliares  
**Requirement**: ABMC-01, ABMC-05 (prereq contract tests)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Serviço `openapi-tooling` com image `node:${NODE_VERSION}-bookworm-slim`, mount `.:/repo`, `working_dir: /repo`
- [x] Anchor backend inclui `./docs:/var/www/docs:ro`
- [x] `docker compose config` válido

**Tests**: none  
**Gate**: build

**Commit**: `chore(docker): add openapi-tooling service and docs mount for backend`

---

### T4: Script `lint-openapi.sh` + Makefile

**What**: Gate `make lint-openapi`; integrar em `make lint` antes de `lint-backend`.  
**Where**: `scripts/lint-openapi.sh`, `Makefile`  
**Depends on**: T1, T2, T3  
**Reuses**: AD-003; padrão `tests/compose/*.sh`  
**Requirement**: ABMC-01, ABMC-02, ABMC-04

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `make lint-openapi` exit 0 no yaml atual
- [x] Script roda via `docker compose run --rm --no-deps openapi-tooling` (Docker-only)
- [x] `make lint` invoca `lint-openapi` antes de `lint-backend`
- [x] Introduzir `$ref` inválido temporariamente → exit ≠ 0 (validar manualmente ou sensor)

**Tests**: none (gate is the test)  
**Gate**: OpenAPI lint

**Commit**: `chore(make): add lint-openapi gate via spectral`

---

### T5: CI — step `make lint-openapi`

**What**: Adicionar step no workflow backend-quality com paridade local.  
**Where**: `.github/workflows/backend-quality.yml`  
**Depends on**: T4  
**Reuses**: Workflow existente  
**Requirement**: ABMC-03

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Step `make lint-openapi` antes dos gates PHP
- [x] Ordem documentada no comentário do workflow

**Tests**: none  
**Gate**: build

**Commit**: `ci: run openapi spectral lint in backend-quality workflow`

---

### T6: PHPUnit — suite Contract + env `OPENAPI_SPEC_PATH`

**What**: Registrar `modules/Auth/Tests/Contract` na suite Feature; env apontando para `/var/www/docs/openapi.yaml`.  
**Where**: `backend/phpunit.xml`  
**Depends on**: T3  
**Reuses**: Suite Feature existente (bearer-tokens pattern)  
**Requirement**: ABMC-05

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `<directory>modules/Auth/Tests/Contract</directory>` dentro de suite Feature
- [x] `<env name="OPENAPI_SPEC_PATH" value="/var/www/docs/openapi.yaml"/>`
- [x] Pest descobre diretório (smoke empty test ou T7)

**Tests**: none  
**Gate**: quick

**Commit**: `test(auth): register contract test suite and openapi spec path`

---

### T7: `OpenApiDocument` + unit tests

**What**: Loader YAML com cache, resolve `$ref` em `components/schemas` e `components/responses`.  
**Where**: `backend/modules/Auth/Tests/Support/OpenApi/OpenApiDocument.php`, `Tests/Unit/OpenApiDocumentTest.php`  
**Depends on**: T6  
**Reuses**: Symfony Yaml; path via `OPENAPI_SPEC_PATH`  
**Requirement**: ABMC-06

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `schema('User')` retorna array com keys esperadas
- [x] `responseSchema` resolve `$ref` de response component
- [x] Falha clara se arquivo missing
- [x] Unit tests ≥3 casos (load, ref, missing file message)
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `test(auth): add OpenApiDocument loader for contract tests`

---

### T8: `OpenApiSchemaAssert` + `AuthOpenApiCatalog` + unit tests

**What**: Helpers de assert estrutural + catálogo de códigos/mensagens Auth (ABMC-08).  
**Where**: `Tests/Support/OpenApi/OpenApiSchemaAssert.php`, `AuthOpenApiCatalog.php`, `Tests/Unit/OpenApiSchemaAssertTest.php`  
**Depends on**: T7  
**Reuses**: `AuthErrorResponseFactoryTest` strings; L-024  
**Requirement**: ABMC-06, ABMC-07, ABMC-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `assertExactKeys` falha em campo extra
- [x] `assertErrorEnvelope` valida status + code + message + request_id
- [x] `assertPrivateCacheAndRequestId` valida headers
- [x] `AuthOpenApiCatalog` lista ≥11 códigos de erro da spec
- [x] Unit tests cobrem happy + failure paths do helper
- [x] Gate check passes: `make test-backend`

**Tests**: unit  
**Gate**: quick

**Commit**: `test(auth): add openapi schema assert helpers and auth error catalog`

---

### T9: Contract — register + login

**What**: `RegisterContractTest.php` e `LoginContractTest.php` cobrindo ABMC-05 rows register/login.  
**Where**: `backend/modules/Auth/Tests/Contract/`  
**Depends on**: T8  
**Reuses**: Feature helpers (`loginPayload`, factories, `DatabaseSafetyGuard`)  
**Requirement**: ABMC-05, ABMC-06, ABMC-09, ABMC-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Register: 201 AuthResponse schema; 403 REGISTRATION_NOT_ALLOWED envelope
- [x] Login: 200 session; 401 INVALID_CREDENTIALS; headers em happy path
- [x] Asserts usam `OpenApiSchemaAssert` (não duplicar keys hardcoded sem schema)
- [x] Gate check passes: `make test-backend`

**Tests**: feature (contract)  
**Gate**: full

**Commit**: `test(auth): add register and login openapi contract tests`

---

### T10: Contract — email verification

**What**: `EmailVerificationContractTest.php` — verify + resend.  
**Where**: `backend/modules/Auth/Tests/Contract/EmailVerificationContractTest.php`  
**Depends on**: T9  
**Reuses**: `EmailVerificationTest` patterns  
**Requirement**: ABMC-05, ABMC-07, ABMC-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] verify: 204; 403 INVALID_VERIFICATION_TOKEN (message exato OpenAPI)
- [x] resend: 202; 403 TOKEN_RESTRICTED
- [x] Gate check passes: `make test-backend`

**Tests**: feature (contract)  
**Gate**: full

**Commit**: `test(auth): add email verification openapi contract tests`

---

### T11: Contract — password

**What**: `PasswordContractTest.php` — change, reset-request, reset.  
**Where**: `backend/modules/Auth/Tests/Contract/PasswordContractTest.php`  
**Depends on**: T10  
**Reuses**: `PasswordResetTest`, `PasswordChangeTest`  
**Requirement**: ABMC-05, ABMC-07, ABMC-08

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] reset-request: 202 Accepted envelope
- [x] reset: 204; 422 PASSWORD_REUSED com message OpenAPI
- [x] change: 204; 403 TOKEN_RESTRICTED on verification bearer
- [x] Gate check passes: `make test-backend`

**Tests**: feature (contract)  
**Gate**: full

**Commit**: `test(auth): add password openapi contract tests`

---

### T12: Contract — session (logout, logout-all, me)

**What**: `SessionContractTest.php` — 4 endpoints de sessão/perfil.  
**Where**: `backend/modules/Auth/Tests/Contract/SessionContractTest.php`  
**Depends on**: T11  
**Reuses**: `CurrentUserTest`, `LogoutTest`, `LogoutAllTest`  
**Requirement**: ABMC-05, ABMC-06, ABMC-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] GET /me: 200 UserResponse exact keys (9 campos em `data`)
- [x] PATCH /me: 403 TOKEN_RESTRICTED com verification bearer
- [x] logout + logout-all: 204 happy; 401 missing bearer
- [x] Headers em GET /me happy path
- [x] Gate check passes: `make test-backend`

**Tests**: feature (contract)  
**Gate**: full

**Commit**: `test(auth): add session and profile openapi contract tests`

---

### T13: Contract regression sweep + fix OpenAPI drift

**What**: Rodar contract suite completa; corrigir **somente** drift encontrado entre implementação e `docs/openapi.yaml` (design-first).  
**Where**: `docs/openapi.yaml` e/ou factories (se drift legítimo)  
**Depends on**: T12  
**Reuses**: Matriz completa design §Contract Test Matrix  
**Requirement**: ABMC-05…10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] Todos os 5 arquivos Contract verdes
- [x] 11 endpoints Auth cobertos (checklist na descrição do PR/commit)
- [x] Gate check passes: `make lint && make test-backend`

**Tests**: feature (contract)  
**Gate**: build

**Commit**: `test(auth): complete auth openapi contract coverage`

---

### T14: P2 — login `IssueAuthToken` failure → 500

**What**: Feature test: mock/fake `IssueAuthToken` throws após credenciais válidas; assert 500 + zero tokens.  
**Where**: `backend/modules/Auth/Tests/Feature/LoginTest.php` (ou Contract se mais coeso)  
**Depends on**: T13  
**Reuses**: Login happy path setup  
**Requirement**: P2 spec (login validation edge)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] HTTP 500 + `code=INTERNAL_ERROR`
- [ ] `auth_tokens` count 0 após falha
- [ ] Gate check passes: `make test-backend`

**Tests**: feature  
**Gate**: full

**Commit**: `test(auth): cover login failure when token issuance throws`

---

### T15: P2 — email token whitespace + validação HTTP

**What**: Regra em `VerifyEmailRequest` rejeita token só-whitespace; Feature test 422 + token unused.  
**Where**: `VerifyEmailRequest.php`, `EmailVerificationTest.php`  
**Depends on**: T13  
**Reuses**: Padrão validation 422 existente  
**Requirement**: P2 spec (email-verification edge)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] POST verify com token `" "` → 422 VALIDATION_FAILED
- [ ] Email action token permanece unused
- [ ] Gate check passes: `make test-backend`

**Tests**: feature  
**Gate**: full

**Commit**: `fix(auth): reject whitespace-only email verification tokens`

---

### T16: P2 — password reset enqueue failure

**What**: Integration test para falha após persist de token reset; ou registrar ops-verified se sem seam.  
**Where**: `RequestPasswordResetTest.php`, possivelmente fake `QueuePasswordReset`  
**Depends on**: T13  
**Reuses**: `LaravelQueuePasswordReset` + `IssuePasswordResetToken`  
**Requirement**: P2 spec (password residual)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Teste asserta comportamento observável documentado **OU**
- [ ] `validation.md` draft note ops-verified com rationale (somente se seam inviável)
- [ ] Gate check passes: `make test-backend`

**Tests**: integration  
**Gate**: full

**Commit**: `test(auth): cover password reset enqueue failure path`

---

### T17: P2 — logout-all concorrente

**What**: Feature test simulando duas requisições logout-all; assert zero tokens finais.  
**Where**: `LogoutAllTest.php`  
**Depends on**: T13  
**Reuses**: Logout-all happy path helpers  
**Requirement**: P2 spec (session-and-profile edge)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Duas chamadas sequenciais/rápidas com mesmo bearer+password → token count 0
- [ ] Segunda chamada não deixa tokens residuais
- [ ] Gate check passes: `make test-backend`

**Tests**: feature  
**Gate**: full

**Commit**: `test(auth): cover concurrent logout-all token revocation`

---

### T18: Fechamento documental

**What**: Sincronizar índice Auth, goals specs 4–7, STATE.md, README raiz; registrar AD-016.  
**Where**: `.specs/features/auth/README.md`, specs filhas, `.specs/STATE.md`, `README.md`  
**Depends on**: T17  
**Reuses**: ABMC-11…15; design AD-016  
**Requirement**: ABMC-11, ABMC-12, ABMC-13, ABMC-14

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Fatia 8 index → Implementing/Done conforme progresso
- [ ] Goals `[x]` em login, email-verification, password, session-and-profile
- [ ] STATE Handoff: Auth Backend concluído; next `bff-auth/session-core`
- [ ] AD-016 appended em STATE Decisions
- [ ] README raiz §Estado atual atualizado

**Tests**: none  
**Gate**: build

**Commit**: `docs(auth): close module-closure documentation and AD-016`

---

### T19: Gates finais + slot Verifier

**What**: Executar gate final; marcar tasks complete; disparar Verifier (author ≠ verifier).  
**Where**: `.specs/features/auth/module-closure/tasks.md`, `validation.md` (Verifier escreve)  
**Depends on**: T18  
**Reuses**: `validate.md`; sensor mutations design §Gate & Verification  
**Requirement**: ABMC-15, ABMC-16, ABMC-17, ABMC-18

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Validate / Verifier sub-agent)

**Done when**:

- [ ] `make lint && make test-backend-coverage` exit 0
- [ ] Cobertura Auth ≥80% linhas e métodos (`check-auth-coverage-gate.php`)
- [ ] Verifier PASS em `validation.md` com sensor ≥3/3 killed
- [ ] Fatia 8 index → Verified

**Tests**: none (Verifier re-runs gates)  
**Gate**: Final

**Commit**: `chore(auth): pass module-closure final gates`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5
Phase 2:  T6 ──→ T7 ──→ T8
Phase 3:  T9 ──→ T10 ──→ T11 ──→ T12 ──→ T13
Phase 4:  T14 ──→ T15 ──→ T16 ──→ T17
Phase 5:  T18 ──→ T19
```

**Batch packing (Execute):**

| Batch | Tasks | Fases |
| --- | --- | --- |
| 1 | T1–T7 | Phase 1 + Phase 2 (início) |
| 2 | T8–T14 | Phase 2 (fim) + Phase 3 + P2 start |
| 3 | T15–T19 | Phase 4 (fim) + Phase 5 |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: Pin Spectral | 1 dep + lockfile | ✅ Granular |
| T2: `.spectral.yaml` | 1 config file | ✅ Granular |
| T3: Docker compose | 1 compose change (2 concerns cohesive) | ✅ Granular |
| T4: Makefile lint-openapi | 1 script + Makefile | ✅ Granular |
| T5: CI step | 1 workflow file | ✅ Granular |
| T6: phpunit.xml | 1 config file | ✅ Granular |
| T7: OpenApiDocument | 1 class + unit tests | ✅ Granular |
| T8: Schema assert helpers | 2 support classes + unit tests | ✅ Granular |
| T9: Register + Login contract | 2 test files (endpoint pair) | ✅ Granular |
| T10–T12: Contract files | 1 test file each | ✅ Granular |
| T13: Regression sweep | cross-cutting fix | ✅ Granular |
| T14–T17: P2 gaps | 1 gap each | ✅ Granular |
| T18: Docs closure | multi-file docs | ✅ Granular (single deliverable) |
| T19: Final gates | verification | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | None | parallel start Phase 1 | ✅ Match |
| T4 | T1, T2, T3 | T3 → T4 (via T1,T2 chain) | ✅ Match |
| T5 | T4 | T4 → T5 | ✅ Match |
| T6 | T3 | Phase 2 after Phase 1 | ✅ Match |
| T7 | T6 | T6 → T7 | ✅ Match |
| T8 | T7 | T7 → T8 | ✅ Match |
| T9 | T8 | T8 → T9 | ✅ Match |
| T10 | T9 | T9 → T10 | ✅ Match |
| T11 | T10 | T10 → T11 | ✅ Match |
| T12 | T11 | T11 → T12 | ✅ Match |
| T13 | T12 | T12 → T13 | ✅ Match |
| T14 | T13 | T13 → T14 | ✅ Match |
| T15 | T13 | T13 → T15 | ✅ Match |
| T16 | T13 | T13 → T16 | ✅ Match |
| T17 | T13 | T13 → T17 | ✅ Match |
| T18 | T17 | T17 → T18 | ✅ Match |
| T19 | T18 | T18 → T19 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | npm deps | none | none | ✅ OK |
| T2 | spectral config | none | none | ✅ OK |
| T3 | docker compose | none | none | ✅ OK |
| T4 | lint script/Makefile | none (gate=self) | none | ✅ OK |
| T5 | CI workflow | none | none | ✅ OK |
| T6 | phpunit config | none | none | ✅ OK |
| T7 | OpenApiDocument | unit | unit | ✅ OK |
| T8 | Schema assert helpers | unit | unit | ✅ OK |
| T9 | Contract register/login | feature (contract) | feature (contract) | ✅ OK |
| T10 | Contract email | feature (contract) | feature (contract) | ✅ OK |
| T11 | Contract password | feature (contract) | feature (contract) | ✅ OK |
| T12 | Contract session | feature (contract) | feature (contract) | ✅ OK |
| T13 | OpenAPI drift fix | feature (contract) | feature (contract) | ✅ OK |
| T14 | Login P2 | feature | feature | ✅ OK |
| T15 | VerifyEmailRequest + test | feature | feature | ✅ OK |
| T16 | Password enqueue P2 | integration | integration | ✅ OK |
| T17 | Logout-all P2 | feature | feature | ✅ OK |
| T18 | docs | none | none | ✅ OK |
| T19 | Verifier | none | none | ✅ OK |

---

## Requirement Traceability (Tasks → ABMC)

| Requirement | Task(s) |
| --- | --- |
| ABMC-01 | T1, T2, T4 |
| ABMC-02 | T2, T4 |
| ABMC-03 | T5 |
| ABMC-04 | T4 |
| ABMC-05 | T6, T9–T13 |
| ABMC-06 | T7, T9, T12 |
| ABMC-07 | T8, T10, T11 |
| ABMC-08 | T8, T10, T11 |
| ABMC-09 | T9, T12 |
| ABMC-10 | T9 |
| ABMC-11 | T18 |
| ABMC-12 | T18 |
| ABMC-13 | T18 |
| ABMC-14 | T18 |
| ABMC-15 | T19 (Verifier) |
| ABMC-16 | T19 |
| ABMC-17 | T19 |
| ABMC-18 | T19 |
| P2 gaps | T14–T17 |

**Coverage:** 18 ABMC + P2 → 19 tasks mapped ✅

---

## Tools (Execute)

| Task range | MCP | Skill |
| --- | --- | --- |
| T1–T19 | NONE (Docker/Makefile only) | `tlc-spec-driven` (Execute + Verifier) |
| Spectral docs (if needed) | `user-context7` | `context7-mcp` |

Perguntar ao usuário antes de instalar deps além de Spectral (já aprovado na SPEC).
