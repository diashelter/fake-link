# Auth — Fundação do módulo — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/auth/foundation/design.md`  
**Spec**: `.specs/features/auth/foundation/spec.md`  
**Status**: Draft — aguardando aprovação antes de Execute

> **Sub-agent note:** 18 tasks → ~3 batches (~6–7 tasks/worker). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (container-only, PG integration), §3.1 (Pest Arch, camadas unit/feature/integration/architecture), §4 (80/80 Auth), §6.1 (senha, status), `LARAVEL_CODE_DESIGN.md` §26, `.specs/features/auth/foundation/spec.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Domain VOs (`EmailAddress`, `UserId`) | unit | 1:1 spec ACs FND-07; edge cases trim/case/invalid email; UUID v7 reject non-v7 | `backend/modules/Auth/Tests/Unit/**/*Test.php` | `make test-backend` |
| Domain service (`PasswordPolicy`) | unit | 1:1 AUTH-06/07 + FND-08; limites 12/128; cada categoria faltante; símbolo ASCII; Unicode edge case documentado | `backend/modules/Auth/Tests/Unit/PasswordPolicyTest.php` | `make test-backend` |
| Infrastructure hasher (`LaravelPasswordHasher`) | unit | AUTH-08 round-trip verify; hash ≠ plaintext; exceção/message sem plaintext (FND-09) | `backend/modules/Auth/Tests/Unit/LaravelPasswordHasherTest.php` | `make test-backend` |
| Infrastructure repository (`EloquentUserRepository`) | integration | FND-04/05/06 ACs; PG `fake_link_testing`; normalização e-mail; UNIQUE; UUID v7; termos/status explícitos | `backend/modules/Auth/Tests/Integration/**/*Test.php` | `make test-backend` |
| Test DB guard (`DatabaseSafety`) | integration | FND-11; falha se `DB_DATABASE=fake_link` em suite Integration | `backend/modules/Auth/Tests/Integration/DatabaseSafetyTest.php` | `make test-backend` |
| Migration `users` | integration | Coberta via repository integration + migrate em testing DB | (via T6 + T13) | `make test-backend` |
| Contracts / enums / exceptions / entity scaffold | none | — (cobertos indiretamente por unit/integration acima) | — | build gate only |
| `AuthServiceProvider` / PHPStan paths / docs | none | — (gate `make lint` + testes existentes) | — | build gate only |
| Pest Arch Auth (estrutura modular) | architecture | FND-02; regras existentes em `ModularMonolithTest.php` | `backend/tests/Architecture/ModularMonolithTest.php` | `make lint` |
| Feature existentes (health, quality) | feature | Regressão zero após cada fase | `backend/tests/Feature/*.php` | `make test-backend` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks só de config/docs/contracts sem testes novos (T1–T4, T7) | `make test-backend` (deve passar com suite existente) |
| Full | Após tasks com unit/integration Auth (T8–T13) | `make test-backend` |
| Build | Após tasks de composição, PHPStan, docs (T14–T18) | `make lint && make test-backend` |
| Coverage | Task final T18 ou verificação pós-T17 | `make test-backend-coverage` — meta ≥80% linhas e ≥80% branches em `modules/Auth/` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Banco de testes PostgreSQL (FND-10, FND-11)

```
T1 → T2 → T3 → T4
```

### Phase 2: Migration `users` (FND-04, FND-05)

```
T5
```

### Phase 3: Domínio Auth (FND-07, AUTH-06, AUTH-07, FND-08)

```
T6 → T7 → T8 → T9
```

### Phase 4: Hashing Argon2id (AUTH-08, FND-09)

```
T10 → T11
```

### Phase 5: Persistência Eloquent (FND-06, FND-04 parcial)

```
T12 → T13 → T14
```

### Phase 6: Composição, limpeza skeleton e documentação (FND-01–03, AD-010/011)

```
T15 → T16 → T17 → T18
```

---

## Task Breakdown

### T1: Init Postgres — database `fake_link_testing`

**What**: Criar script SQL de init e montar volume no serviço `postgres` do Compose.  
**Where**: `docker/postgres/init/01-create-testing-database.sql`, `docker-compose.yml` (volume mount)  
**Depends on**: None  
**Reuses**: `docker/postgres/init/.gitkeep`; pattern oficial Postgres `/docker-entrypoint-initdb.d`  
**Requirement**: FND-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [ ] Script cria `fake_link_testing` com owner/grants para `fake_link` se não existir
- [ ] Compose monta `./docker/postgres/init:/docker-entrypoint-initdb.d:ro`
- [ ] README ou `docs/testing.md` documenta recreate de volume se DB testing ausente em dev existente
- [ ] Gate check passes: `docker compose ... up -d postgres --wait` (em volume novo ou após script manual de ensure)

**Tests**: none  
**Gate**: build

**Commit**: `chore(docker): add fake_link_testing postgres init script`

---

### T2: Ambiente de teste Laravel — `.env.testing` e `phpunit.xml`

**What**: Fixar defaults seguros de PG testing (`fake_link_testing`); remover SQLite como default em `phpunit.xml`.  
**Where**: `backend/.env.testing`, `backend/phpunit.xml`  
**Depends on**: T1  
**Reuses**: `backend/.env.example` vars `DB_*`  
**Requirement**: FND-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `.env.testing` define `APP_ENV=testing`, `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE=fake_link_testing`
- [ ] `phpunit.xml` `<env>` usa PG + `fake_link_testing` (não SQLite)
- [ ] Gate check passes: `make test-backend` (suite existente ainda verde)

**Tests**: none  
**Gate**: quick

**Commit**: `test(backend): configure phpunit for fake_link_testing database`

---

### T3: Makefile — `test-backend` e coverage com PostgreSQL

**What**: Atualizar targets de teste para usar Postgres healthy + `fake_link_testing`; remover override SQLite e `--no-deps` onde PG é necessário.  
**Where**: `Makefile` (`test-backend`, `test-backend-coverage`, `test-architecture` se aplicável)  
**Depends on**: T2  
**Reuses**: AD-003; pattern `COMPOSE exec backend`  
**Requirement**: FND-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `make test-backend` passa `-e DB_DATABASE=fake_link_testing` e depende de serviço `postgres` healthy
- [ ] `make test-backend-coverage` usa mesma conexão
- [ ] Gate check passes: `make test-backend`

**Tests**: none (regressão via gate)  
**Gate**: quick

**Commit**: `chore(make): run backend tests against fake_link_testing postgres`

---

### T4: Documentação — banco dedicado em `docs/testing.md`

**What**: Atualizar §2 com obrigatoriedade de `fake_link_testing`, proibição de dev/prod em testes, e nota sobre init Postgres.  
**Where**: `docs/testing.md`  
**Depends on**: T3  
**Reuses**: Spec AC FND-11 item 5  
**Requirement**: FND-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] §2 descreve `fake_link_testing`, isolamento e comando `make test-backend`
- [ ] SQLite in-memory marcado como não substituto para integration Auth/constraints
- [ ] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `docs(testing): require dedicated fake_link_testing database`

---

### T5: Migration `users` — UUID v7 e remoção de legado Laravel

**What**: Reescrever migration inicial: tabela `users` canônica; remover `password_reset_tokens`, `sessions`, `remember_token`.  
**Where**: `backend/database/migrations/0001_01_01_000000_create_users_table.php`  
**Depends on**: T3 (migrate/test em PG testing)  
**Reuses**: Design §Migration DDL  
**Requirement**: FND-04, FND-05

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Migration cria `users` com PK `uuid`, CHECK `status`, colunas de termos NOT NULL, UNIQUE `email`
- [ ] Migration **não** cria `password_reset_tokens`, `sessions`; sem `remember_token`
- [ ] `php artisan migrate --force` succeeds no container apontando para `fake_link_testing`
- [ ] Gate check passes: `make test-backend`

**Tests**: none (integration coberta em T13)  
**Gate**: quick

**Commit**: `feat(auth): replace users migration with uuid v7 schema`

---

### T6: Contracts — ports de Auth

**What**: Criar interfaces `UserRepository`, `PasswordHasher`, `UserIdGenerator`.  
**Where**: `backend/modules/Auth/Contracts/Repositories/UserRepository.php`, `Contracts/Services/{PasswordHasher,UserIdGenerator}.php`  
**Depends on**: T5  
**Reuses**: `LARAVEL_CODE_DESIGN.md` §15.2–15.3  
**Requirement**: (prepara FND-01, FND-06)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Três interfaces com tipos de domínio (stubs mínimos de Domain ou forward references via imports após T7)
- [ ] `composer dump-autoload` no container sem erro
- [ ] Gate check passes: `make test-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `feat(auth): add repository and service contracts`

---

### T7: Domain — enums, exceções e Value Objects base

**What**: Implementar `UserStatus`, `PasswordViolationCode`, `AuthDomainException`, `PasswordPolicyException`, `EmailAddress`, `UserId`.  
**Where**: `backend/modules/Auth/Domain/...`, `backend/modules/Auth/Exceptions/...`  
**Depends on**: T6  
**Reuses**: Design §Domain; guia §16.3, §19  
**Requirement**: FND-07, FND-08

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `EmailAddress` normaliza trim+lowercase; rejeita inválido
- [ ] `UserId` valida UUID v7; rejeita v4/outros
- [ ] `UserStatus` expõe quatro estados snake_case
- [ ] Exceções com códigos estáveis; mensagens sem senha plaintext
- [ ] Gate check passes: `make test-backend`

**Tests**: unit (co-located nesta task)  
**Gate**: full

**Done when (tests)**:

- [ ] `EmailAddressTest`: AC spec items 1–2 + edge trim/case
- [ ] `UserIdTest`: v7 aceito; v4/invalid rejeitado
- [ ] `UserStatusTest`: quatro valores; from inválido falha
- [ ] Test count: ≥8 tests pass (no silent deletions)

**Commit**: `feat(auth): add domain value objects and status enum`

---

### T8: Domain — `PasswordPolicy`

**What**: Serviço de domínio validando comprimento 12–128 e complexidade ASCII com códigos estáveis.  
**Where**: `backend/modules/Auth/Domain/Services/PasswordPolicy.php`  
**Depends on**: T7  
**Reuses**: Spec símbolo ASCII; AUTH-06, AUTH-07  
**Requirement**: AUTH-06, AUTH-07, FND-08, FND-09

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `validate()` lança `PasswordPolicyException` com `PasswordViolationCode` correto
- [ ] Limites 12 e 128 inclusive quando complexidade OK
- [ ] Mensagens/exceções não contêm senha plaintext
- [ ] Gate check passes: `make test-backend`
- [ ] Test count: ≥12 tests (cada violação + happy paths + Unicode edge)

**Tests**: unit  
**Gate**: full

**Commit**: `feat(auth): add password policy domain service`

---

### T9: Domain — entidade `User`

**What**: Entidade mínima com factory `create()` exigindo todos os campos; getters; e-mail imutável.  
**Where**: `backend/modules/Auth/Domain/Entities/User.php`  
**Depends on**: T7  
**Reuses**: Design §User entity  
**Requirement**: (suporte FND-06)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Entity agrega `UserId`, `EmailAddress`, `UserStatus`, hash, termos, `emailVerifiedAt`
- [ ] Sem setter de e-mail
- [ ] Gate check passes: `make test-backend`

**Tests**: unit (factory + getters mínimos)  
**Gate**: full

**Commit**: `feat(auth): add user domain entity`

---

### T10: Config — `hashing.php` Argon2id

**What**: Publicar config de hashing; default `argon2id` com memory ≥64 MiB; atualizar `.env.example`.  
**Where**: `backend/config/hashing.php`, `backend/.env.example`  
**Depends on**: T4  
**Reuses**: Laravel 13 `config:publish hashing`; design §LaravelPasswordHasher config  
**Requirement**: AUTH-08

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `HASH_DRIVER=argon2id` documentado em `.env.example`
- [ ] `ARGON_MEMORY=65536` (ou equivalente 64 MiB) versionado
- [ ] Gate check passes: `make lint`

**Tests**: none  
**Gate**: build

**Commit**: `feat(auth): publish argon2id hashing configuration`

---

### T11: Infrastructure — `LaravelPasswordHasher`

**What**: Adaptador implementando `PasswordHasher` via Laravel `Hash` facade.  
**Where**: `backend/modules/Auth/Infrastructure/Hashing/LaravelPasswordHasher.php`  
**Depends on**: T6, T10  
**Reuses**: Design §LaravelPasswordHasher  
**Requirement**: AUTH-08, FND-09

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `hash()` produz verificável por `verify()` com driver argon2id
- [ ] Hash ≠ plaintext
- [ ] Gate check passes: `make test-backend`
- [ ] Test count: ≥4 tests (round-trip, algorithm prefix `$argon2id$`, no plaintext in exception paths)

**Tests**: unit  
**Gate**: full

**Commit**: `feat(auth): add laravel argon2id password hasher adapter`

---

### T12: Infrastructure — `UserModel`, `UserMapper`, `Uuid7UserIdGenerator`

**What**: Model Eloquent, mapper Domain↔persistence, gerador UUID v7.  
**Where**: `backend/modules/Auth/Infrastructure/Persistence/Eloquent/...`, `Infrastructure/Identity/Uuid7UserIdGenerator.php`  
**Depends on**: T9, T11  
**Reuses**: Design §UserModel; `Str::uuid7()`  
**Requirement**: FND-04 (geração id), FND-06 (mapper)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `UserModel`: `$keyType='string'`, `$incrementing=false`, hidden password
- [ ] `UserMapper` converte bidirecional sem regra de negócio
- [ ] `Uuid7UserIdGenerator` implementa port e retorna `UserId` v7
- [ ] Gate check passes: `make test-backend`

**Tests**: unit (mapper + generator com fake Str ou integration light)  
**Gate**: full

**Commit**: `feat(auth): add user eloquent model mapper and uuid7 generator`

---

### T13: Infrastructure — `EloquentUserRepository`, factory e testes de integração

**What**: Repository + `UserModelFactory`; testes PG em `fake_link_testing`; trait `DatabaseSafety`.  
**Where**: `backend/modules/Auth/Infrastructure/Persistence/Eloquent/Repositories/EloquentUserRepository.php`, `Factories/UserModelFactory.php`, `backend/modules/Auth/Tests/Integration/`, `Tests/Support/DatabaseSafety.php`  
**Depends on**: T5, T12  
**Reuses**: Design §EloquentUserRepository; `RefreshDatabase`  
**Requirement**: FND-04, FND-05, FND-06, FND-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `save()` persiste e-mail normalizado; duplicata falha
- [ ] `nextIdentity()` retorna UUID v7
- [ ] Integration tests usam `DatabaseSafety` — abortam se DB ≠ `fake_link_testing`
- [ ] `phpunit.xml` inclui suite/directory `modules/Auth/Tests`
- [ ] Gate check passes: `make test-backend`
- [ ] Test count: ≥6 integration tests (save, normalize, duplicate, uuid, terms required, safety guard)

**Tests**: integration  
**Gate**: full

**Commit**: `feat(auth): add eloquent user repository with integration tests`

---

### T14: Remover skeleton Laravel User

**What**: Deletar `App\Models\User`, factory global; limpar `DatabaseSeeder`.  
**Where**: `backend/app/Models/User.php`, `backend/database/factories/UserFactory.php`, `backend/database/seeders/DatabaseSeeder.php`  
**Depends on**: T13  
**Reuses**: Design "Removidos"  
**Requirement**: FND-01 (limpeza modular)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Arquivos skeleton removidos; seeder sem referência a `App\Models\User`
- [ ] Nenhum import `App\Models\User` restante no backend
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: none (regressão gate)  
**Gate**: build

**Commit**: `refactor(auth): remove default laravel user skeleton`

---

### T15: `AuthServiceProvider` e registro bootstrap

**What**: Provider com bindings; registrar em `bootstrap/providers.php`.  
**Where**: `backend/modules/Auth/ServiceProviders/AuthServiceProvider.php`, `backend/bootstrap/providers.php`  
**Depends on**: T13, T14  
**Reuses**: Design §AuthServiceProvider; guia §7  
**Requirement**: FND-01, FND-02

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Bindings: `UserRepository`, `PasswordHasher`, `UserIdGenerator`
- [ ] App boota; container resolve ports
- [ ] Sem rotas HTTP de negócio
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: none (Pest Arch valida estrutura)  
**Gate**: build

**Commit**: `feat(auth): register auth module service provider`

---

### T16: PHPStan e cobertura — paths `modules/Auth`

**What**: Incluir módulo em `phpstan.neon` e `<source>` do `phpunit.xml` para PCOV.  
**Where**: `backend/phpstan.neon`, `backend/phpunit.xml`  
**Depends on**: T15  
**Reuses**: AD-009  
**Requirement**: FND-03

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `phpstan.neon` paths incluem `modules/Auth`
- [ ] Coverage source inclui `modules/Auth`
- [ ] Gate check passes: `make lint`

**Tests**: none  
**Gate**: build

**Commit**: `chore(auth): include auth module in phpstan and coverage paths`

---

### T17: Decisões de projeto — `STATE.md` AD-010/AD-011

**What**: Registrar UUID v7 para User e banco testing obrigatório.  
**Where**: `.specs/STATE.md`  
**Depends on**: T16  
**Reuses**: Design §Tech Decisions  
**Requirement**: (rastreabilidade spec desvio ULID)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (memory)

**Done when**:

- [ ] AD-010 e AD-011 append no Decisions log
- [ ] Gate check passes: `make lint && make test-backend`

**Tests**: none  
**Gate**: build

**Commit**: `docs(state): record uuid v7 and testing database decisions`

---

### T18: Sync documentação — `docs/data-model.md` e índice Auth

**What**: Atualizar `users.id` para UUID v7; status fatia foundation no README Auth.  
**Where**: `docs/data-model.md` §3, `.specs/features/auth/README.md`, `.specs/features/auth/foundation/spec.md` traceability status  
**Depends on**: T17  
**Reuses**: Spec success criteria documental  
**Requirement**: FND-04 (doc), spec Success Criteria

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `docs/data-model.md` §3 descreve UUID v7 para `users.id`
- [ ] Índice Auth marca foundation como em progresso/concluída conforme gate
- [ ] Gate check passes: `make lint && make test-backend && make test-backend-coverage`
- [ ] Cobertura `modules/Auth/` ≥80% linhas e ≥80% branches

**Tests**: none (coverage gate)  
**Gate**: coverage

**Commit**: `docs: align data model with uuid v7 user identifiers`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4
Phase 2:  T5
Phase 3:  T6 ──→ T7 ──→ T8 ──→ T9
Phase 4:  T10 ──→ T11
Phase 5:  T12 ──→ T13 ──→ T14
Phase 6:  T15 ──→ T16 ──→ T17 ──→ T18
```

Execução estritamente sequencial — um commit atômico por task.

**Batch packing (Execute):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| Worker 1 | 1–3 | T1–T9 |
| Worker 2 | 4–5 | T10–T14 |
| Worker 3 | 6 | T15–T18 + Verifier |

---

## Requirement Traceability (Tasks → Spec)

| Requirement ID | Task(s) |
| --- | --- |
| FND-10 | T1 |
| FND-11 | T2, T3, T4, T13 |
| FND-04 | T5, T12, T13, T18 |
| FND-05 | T5, T13 |
| FND-06 | T12, T13 |
| FND-07 | T7 |
| FND-08 | T7, T8 |
| FND-09 | T8, T11 |
| AUTH-06 | T8 |
| AUTH-07 | T8 |
| AUTH-08 | T10, T11 |
| FND-01 | T15, T14 |
| FND-02 | T15 |
| FND-03 | T16 |

**Coverage:** 14/14 requirements mapped ✅

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: Postgres init script | 1 infra deliverable | ✅ Granular |
| T2: `.env.testing` + phpunit | 2 arquivos config coesos | ✅ Granular |
| T3: Makefile test targets | 1 Makefile concern | ✅ Granular |
| T4: docs/testing.md §2 | 1 doc section | ✅ Granular |
| T5: users migration | 1 migration file | ✅ Granular |
| T6: Contracts (3 interfaces) | 1 camada coesa | ✅ Granular |
| T7: VOs + enums + exceptions | 1 camada domínio base + tests | ✅ Granular |
| T8: PasswordPolicy + tests | 1 domain service | ✅ Granular |
| T9: User entity + tests | 1 entity | ✅ Granular |
| T10: hashing config | 1 config publish | ✅ Granular |
| T11: LaravelPasswordHasher + tests | 1 adapter | ✅ Granular |
| T12: Model + Mapper + Generator | 1 infra persistência slice | ✅ Granular |
| T13: Repository + integration | 1 repository + tests | ✅ Granular |
| T14: Remove skeleton | cleanup atômico | ✅ Granular |
| T15: ServiceProvider | 1 provider + bootstrap | ✅ Granular |
| T16: PHPStan/coverage paths | 2 configs coesos | ✅ Granular |
| T17: STATE.md ADs | 1 doc decision | ✅ Granular |
| T18: data-model sync + coverage gate | docs + verificação final | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start Phase 1) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | T3 | T3 → T4 | ✅ Match |
| T5 | T3 | Phase 2 after Phase 1 | ✅ Match |
| T6 | T5 | T5 → T6 | ✅ Match |
| T7 | T6 | T6 → T7 | ✅ Match |
| T8 | T7 | T7 → T8 | ✅ Match |
| T9 | T7 | T7 → T9 (parallel after T7) | ✅ Match |
| T10 | T4 | Phase 4 after Phase 1 (T4 done) | ✅ Match |
| T11 | T6, T10 | after T6, T10 | ✅ Match |
| T12 | T9, T11 | after T9, T11 | ✅ Match |
| T13 | T5, T12 | after T5, T12 | ✅ Match |
| T14 | T13 | T13 → T14 | ✅ Match |
| T15 | T13, T14 | after T13, T14 | ✅ Match |
| T16 | T15 | T15 → T16 | ✅ Match |
| T17 | T16 | T16 → T17 | ✅ Match |
| T18 | T17 | T17 → T18 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Docker init | none | none | ✅ OK |
| T2 | phpunit/env config | none | none | ✅ OK |
| T3 | Makefile | none | none | ✅ OK |
| T4 | docs | none | none | ✅ OK |
| T5 | migration | integration (via T13) | none | ✅ OK (defer to T13 per matrix) |
| T6 | contracts | none | none | ✅ OK |
| T7 | Domain VOs/enums | unit | unit | ✅ OK |
| T8 | PasswordPolicy | unit | unit | ✅ OK |
| T9 | User entity | none (covered by T13) | unit | ✅ OK |
| T10 | hashing config | none | none | ✅ OK |
| T11 | LaravelPasswordHasher | unit | unit | ✅ OK |
| T12 | Model/Mapper/Generator | unit | unit | ✅ OK |
| T13 | EloquentUserRepository | integration | integration | ✅ OK |
| T14 | cleanup | none | none | ✅ OK |
| T15 | ServiceProvider | none | none | ✅ OK |
| T16 | phpstan/phpunit | none | none | ✅ OK |
| T17 | STATE.md | none | none | ✅ OK |
| T18 | docs + coverage | none | none | ✅ OK |

---

## MCPs e Skills (perguntar antes do Execute)

Antes de iniciar Execute, confirmar com o mantenedor:

> **MCPs disponíveis:** Context7 (docs Laravel), cursor-app-control  
> **Skills:** `tlc-spec-driven` (obrigatório), `codebase-design` (opcional — revisão de seams)

Default por task: **MCP NONE** salvo consulta Laravel (`Str::uuid7`, `config:publish hashing`) via Context7 quando necessário.
