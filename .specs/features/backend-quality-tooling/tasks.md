# Backend Quality Tooling Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and do not proceed without it.**

---

**Design**: `.specs/features/backend-quality-tooling/design.md`  
**Status**: Approved — pronto para Execute (2026-07-22)

> **Sub-agent note:** 15 tasks → ~2 batches (~7 tasks/worker). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `docs/testing.md` §2 (container-only), §3.1 (Pest Arch), §4 (gates estáticos), §10 (CI PR backend slice), `LARAVEL_CODE_DESIGN.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Compose smoke — contrato `make lint` / CI parity | integration | Script valida exit 0 de `make lint-backend` e presença de workflow YAML; falha se placeholder lint permanecer | `tests/compose/backend-quality-gates.sh` | `bash tests/compose/backend-quality-gates.sh` |
| Pest Architecture — modular monolith | architecture (Pest Arch) | Regras §3.1 mapeadas; sentinela de discriminação (Controller + Eloquent) mata mutante | `backend/tests/Architecture/**/*.php` | `make test-backend` ou `vendor/bin/pest tests/Architecture` |
| Meta smoke tooling (P3) | feature (Pest) | AC QTOOL-26: pint/phpstan/phpmd exit 0 via Process | `backend/tests/Feature/QualityToolingTest.php` | `make test-backend` |
| Composer configs / phpstan.neon / phpmd.xml / phpunit.xml / Dockerfile PCOV / Makefile / workflow YAML | none | — (build gate + compose smoke + make lint cobre comportamento) | — | build gate only |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após T7 (Makefile static gates) | `make lint-backend` |
| Full | Após T9–T13 (lint completo + cobertura + arch) | `make lint && make test-backend-coverage && bash tests/compose/backend-quality-gates.sh` |
| Build | Após T1–T4 (packages, PCOV, phpunit coverage config) | `docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml build backend && docker compose ... run --rm --no-deps backend composer install --no-interaction` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Pacotes e infraestrutura Docker

```
T1 → T2 → T3 → T4
```

### Phase 2: Configuração estática

```
T5 → T6
```

### Phase 3: Makefile — gates locais

```
T7 → T8 → T9
```

### Phase 4: CI GitHub Actions

```
T10 → T11
```

### Phase 5: Pest Architecture

```
T12 → T13
```

### Phase 6: Documentação e meta-teste

```
T14 → T15
```

---

## Task Breakdown

### T1: Instalar pacotes `require-dev` de qualidade

**What**: Adicionar `larastan/larastan`, `phpstan/phpstan-strict-rules`, `phpmd/phpmd`, `pestphp/pest-plugin-arch` via `composer require --dev` no container; atualizar `composer.lock`.  
**Where**: `backend/composer.json`, `backend/composer.lock`  
**Depends on**: None  
**Reuses**: Pacotes Pest/Pint existentes  
**Requirement**: QTOOL-01, QTOOL-02, QTOOL-03

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Execute)

**Done when**:

- [x] `composer show larastan/larastan phpmd/phpmd pestphp/pest-plugin-arch phpstan/phpstan-strict-rules` lista pacotes no container
- [x] Ausência de PHP-CS-Fixer, PHPCS, PHP Insights em `composer.json`
- [x] Gate check passes: `docker compose ... run --rm --no-deps backend composer install --no-interaction`

**Tests**: none  
**Gate**: build

**Commit**: `chore(backend): add quality tooling dev dependencies`

---

### T2: Composer scripts de qualidade

**What**: Adicionar scripts `lint`, `analyse`, `md`, `quality`, `test:coverage` em `composer.json`.  
**Where**: `backend/composer.json`  
**Depends on**: T1  
**Reuses**: Design §Composer scripts  
**Requirement**: QTOOL-04

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `composer run lint` executa `pint --test`
- [x] `composer run analyse` executa `phpstan analyse`
- [x] `composer run md` executa `phpmd`
- [x] `composer run quality` encadeia lint → analyse → md
- [x] Gate check passes: `docker compose ... run --rm --no-deps backend composer run lint` (pode falhar até T5 — OK se script existe)

**Tests**: none  
**Gate**: build

**Commit**: `chore(backend): add composer quality scripts`

---

### T3: PCOV no stage `dev` do Dockerfile PHP

**What**: Instalar e habilitar extensão `pcov` no stage `dev`; confirmar ausência em `prod`/`runtime`.  
**Where**: `docker/php/Dockerfile`  
**Depends on**: None  
**Reuses**: Pattern `pecl install redis`  
**Requirement**: QTOOL-10, QTOOL-11, QTOOL-14

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Rebuild imagem backend dev
- [x] `docker compose ... run --rm --no-deps backend php -m | grep pcov` retorna match
- [x] Stage prod/runtime não inclui PCOV (inspeção Dockerfile + build prod target se aplicável)

**Tests**: none  
**Gate**: build

**Commit**: `chore(docker): enable pcov in php dev image`

---

### T4: Configurar cobertura em `phpunit.xml`

**What**: Adicionar seção `<coverage>` com reporters `text` e `html` compatíveis PHPUnit 12; manter `<source>` em `app/`.  
**Where**: `backend/phpunit.xml`  
**Depends on**: T3  
**Reuses**: `<source>` existente  
**Requirement**: QTOOL-12, QTOOL-13

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `php artisan test --coverage` no container dev produz summary text sem "No code coverage driver available"
- [x] Relatório HTML gerado em path documentado (ex.: `storage/coverage` ou default PHPUnit)
- [x] Gate check passes após T2 script `test:coverage` wired (T2) + rebuild T3

**Tests**: none  
**Gate**: build

**Commit**: `chore(backend): configure phpunit coverage reporters`

---

### T5: `phpstan.neon` Larastan nível 6 + baseline verde

**What**: Criar `backend/phpstan.neon` com level 6, Larastan + strict rules; corrigir código skeleton até `./vendor/bin/phpstan analyse` exit 0.  
**Where**: `backend/phpstan.neon`, possíveis fixes em `app/`  
**Depends on**: T1  
**Reuses**: Design §Larastan config  
**Requirement**: QTOOL-05, QTOOL-06, QTOOL-09

**Tools**:

- MCP: Context7 (Larastan/Laravel 13 patterns, se necessário)
- Skill: NONE

**Done when**:

- [x] `phpstan.neon` com `level: 6`, paths `app/` + `tests/`, excludes corretos
- [x] `composer run analyse` exit 0 no skeleton
- [x] Nenhum `@phpstan-ignore` amplo sem justificativa inline
- [x] Gate check passes: `docker compose ... run --rm --no-deps backend composer run analyse`

**Tests**: none  
**Gate**: quick (via `composer run analyse`)

**Commit**: `chore(backend): add larastan level 6 configuration`

---

### T6: `phpmd.xml` e baseline verde

**What**: Criar `backend/phpmd.xml` com thresholds do design; `./vendor/bin/phpmd app text phpmd.xml` exit 0.  
**Where**: `backend/phpmd.xml`  
**Depends on**: T1  
**Reuses**: Design §PHPMD config  
**Requirement**: QTOOL-07, QTOOL-08

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Regras documentadas no XML (complexity ≤12, size, coupling)
- [x] `composer run md` exit 0
- [x] Gate check passes: `docker compose ... run --rm --no-deps backend composer run md`

**Tests**: none  
**Gate**: quick

**Commit**: `chore(backend): add phpmd ruleset`

---

### T7: Makefile — targets estáticos backend

**What**: Criar `lint-backend`, `analyse-backend`, `md-backend`, `format-backend` via `docker compose run` + env test stub.  
**Where**: `Makefile`  
**Depends on**: T2, T5, T6  
**Reuses**: Pattern `test-backend`, `COMPOSE`, `COMPOSE_TEST` vars  
**Requirement**: QTOOL-15, QTOOL-16, QTOOL-17

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `make lint-backend` exit 0 (Pint + PHPStan + PHPMD)
- [x] `make help` lista novos targets
- [x] Nenhum binário invocado no host
- [x] Gate check passes: `make lint-backend`

**Tests**: none  
**Gate**: quick

**Commit**: `chore(make): add backend static analysis targets`

---

### T8: Makefile — `test-backend-coverage`

**What**: Target `test-backend-coverage` executando `composer run test:coverage` no container com env in-memory igual `test-backend`.  
**Where**: `Makefile`  
**Depends on**: T4, T2  
**Reuses**: `test-backend` env vars  
**Requirement**: QTOOL-13 (operacional), QTOOL-18 (parcial)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `make test-backend-coverage` exit 0 com output de cobertura text
- [x] Gate check passes: `make test-backend-coverage`

**Tests**: none  
**Gate**: full (parcial)

**Commit**: `chore(make): add backend test coverage target`

---

### T9: Substituir placeholder `make lint`

**What**: Implementar `make lint` como pipeline backend: `lint-backend` + `test-backend` (Pest unit/feature); falha fail-fast.  
**Where**: `Makefile`  
**Depends on**: T7, T8  
**Reuses**: `test-backend` existente  
**Requirement**: QTOOL-15, QTOOL-18

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `make lint` exit 0 no skeleton
- [x] Placeholder `@echo "lint targets..."` removido
- [x] Ordem: static gates → Pest
- [x] Gate check passes: `make lint`

**Tests**: none  
**Gate**: full

**Commit**: `chore(make): wire backend quality lint pipeline`

---

### T10: Workflow GitHub Actions `backend-quality.yml`

**What**: Criar workflow PR/push main: build backend image, composer install, `make lint-backend`, `make test-backend-coverage` via Docker.  
**Where**: `.github/workflows/backend-quality.yml`  
**Depends on**: T9  
**Reuses**: Design §GitHub Actions  
**Requirement**: QTOOL-27, QTOOL-28, QTOOL-29, QTOOL-30, QTOOL-31, QTOOL-32, QTOOL-33

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Workflow YAML válido (syntax check ou `actionlint` se disponível)
- [x] Steps separados ou nomeados por ferramenta (Pint/PHPStan/PHPMD/Pest)
- [x] Sem setup-php / composer no host
- [x] Triggers: `pull_request`, `push` → `main`
- [x] Gate check passes: revisão manual + `make lint` local green (CI verificado no PR real ou documentado)

**Tests**: none  
**Gate**: full

**Commit**: `ci: add backend quality workflow`

---

### T11: Compose smoke — contrato gates backend

**What**: Script `tests/compose/backend-quality-gates.sh` validando `make lint-backend` exit 0 e ausência de placeholder lint.  
**Where**: `tests/compose/backend-quality-gates.sh`; integrar em `make test` se apropriado  
**Depends on**: T9, T10  
**Reuses**: Padrão `tests/compose/*.sh`  
**Requirement**: QTOOL-33 (paridade), edge cases vendor ausente documentado

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Script exit 0 quando gates passam
- [x] Script falha se `make lint` ainda for placeholder
- [x] Gate check passes: `bash tests/compose/backend-quality-gates.sh`

**Tests**: integration (script é o teste)  
**Gate**: full

**Commit**: `test(compose): add backend quality gates smoke`

---

### T12: Pest Architecture — regras modulares baseline

**What**: Criar `backend/tests/Architecture/ModularMonolithTest.php` com regras Pest Arch §3.1; adicionar testsuite `Architecture` em `phpunit.xml`.  
**Where**: `backend/tests/Architecture/`, `backend/phpunit.xml`, `backend/tests/Pest.php` se necessário  
**Depends on**: T1 (pest-plugin-arch)  
**Reuses**: `docs/testing.md` §3.1, design §Pest Architecture  
**Requirement**: QTOOL-19, QTOOL-20, QTOOL-21, QTOOL-22

**Tools**:

- MCP: Context7 (pest-plugin-arch API, se necessário)
- Skill: NONE

**Done when**:

- [x] `vendor/bin/pest tests/Architecture` exit 0 no baseline
- [x] Regras cobrem: Controller sem Eloquent direto; cross-module Models proibido; Shared não depende de domínio (namespace-aware)
- [x] Discriminação documentada: mutante Controller+Eloquent falharia (verifier/sensor na Execute)
- [x] Gate check passes: `docker compose ... run --rm --no-deps backend vendor/bin/pest tests/Architecture`

**Tests**: architecture  
**Gate**: full

**Commit**: `test(backend): add modular monolith architecture rules`

---

### T13: Integrar Pest Arch em `make lint` e CI

**What**: Incluir suite Architecture em `make lint` e workflow `backend-quality.yml`.  
**Where**: `Makefile`, `.github/workflows/backend-quality.yml`  
**Depends on**: T9, T10, T12  
**Reuses**: T12 tests  
**Requirement**: QTOOL-22, QTOOL-34

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `make lint` executa Architecture suite
- [ ] CI workflow inclui step Pest Arch ou herda via `make lint` atualizado
- [ ] Gate check passes: `make lint && bash tests/compose/backend-quality-gates.sh`

**Tests**: none (coberto por T12)  
**Gate**: full

**Commit**: `chore(ci): include architecture tests in backend lint pipeline`

---

### T14: Documentação e AD-009

**What**: Registrar AD-009 em `.specs/STATE.md`; atualizar `README.md` e `docs/testing.md` §4/§10 com Pint, Larastan 6, PHPMD, CI backend Docker.  
**Where**: `.specs/STATE.md`, `README.md`, `docs/testing.md`  
**Depends on**: T13  
**Reuses**: Decisões da spec  
**Requirement**: QTOOL-23, QTOOL-24, QTOOL-25

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] AD-009 presente no Decisions log
- [ ] README referencia `make lint`, `make test-backend-coverage`, workflow CI
- [ ] `docs/testing.md` consistente (sem PHPCS; Larastan nível 6 explícito)
- [ ] Handoff `.specs/STATE.md` atualizado

**Tests**: none  
**Gate**: full

**Commit**: `docs: record backend quality tooling AD-009`

---

### T15: Meta-teste QualityToolingTest (P3)

**What**: Criar `backend/tests/Feature/QualityToolingTest.php` smoke de `pint --test`, `phpstan analyse`, `phpmd` via Process.  
**Where**: `backend/tests/Feature/QualityToolingTest.php`  
**Depends on**: T5, T6  
**Reuses**: AC QTOOL-26  
**Requirement**: QTOOL-26

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Teste passa em `make test-backend`
- [ ] Assert exit code 0 para os três comandos
- [ ] Gate check passes: `make test-backend`

**Tests**: feature  
**Gate**: full

**Commit**: `test(backend): add quality tooling smoke test`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5 → Phase 6

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4
Phase 2:  T5 ──→ T6
Phase 3:  T7 ──→ T8 ──→ T9
Phase 4:  T10 ──→ T11
Phase 5:  T12 ──→ T13
Phase 6:  T14 ──→ T15
```

**Batch packing (Execute):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| Worker 1 | 1 + 2 + 3 (partial) | T1–T9 |
| Worker 2 | 4 + 5 + 6 | T10–T15 |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: Composer packages | 1 manifest + lock | ✅ Granular |
| T2: Composer scripts | 1 file scripts section | ✅ Granular |
| T3: PCOV Dockerfile | 1 Dockerfile stage | ✅ Granular |
| T4: phpunit coverage | 1 file | ✅ Granular |
| T5: phpstan.neon + fixes | 1 config + minimal app fixes | ✅ Granular |
| T6: phpmd.xml | 1 config file | ✅ Granular |
| T7: Makefile static targets | 1 Makefile section | ✅ Granular |
| T8: coverage target | 1 Makefile target | ✅ Granular |
| T9: make lint wire | 1 Makefile target | ✅ Granular |
| T10: GH workflow | 1 workflow file | ✅ Granular |
| T11: compose smoke | 1 script | ✅ Granular |
| T12: Architecture tests | 1 test file + phpunit suite | ✅ Granular |
| T13: lint/CI arch wire | 2 files modify | ✅ Granular |
| T14: docs + AD | 3 doc files | ✅ Granular |
| T15: meta-test | 1 test file | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | None | (parallel start) | ✅ Match |
| T4 | T3 | T3 → T4 | ✅ Match |
| T5 | T1 | T1 → T5 | ✅ Match |
| T6 | T1 | T1 → T6 | ✅ Match |
| T7 | T2, T5, T6 | T2,T5,T6 → T7 | ✅ Match |
| T8 | T4, T2 | T4 → T8 | ✅ Match |
| T9 | T7, T8 | T7 → T9, T8 → T9 | ✅ Match |
| T10 | T9 | T9 → T10 | ✅ Match |
| T11 | T9, T10 | T10 → T11 | ✅ Match |
| T12 | T1 | T1 → T12 | ✅ Match |
| T13 | T9, T10, T12 | T12 → T13, T9 → T13, T10 → T13 | ✅ Match |
| T14 | T13 | T13 → T14 | ✅ Match |
| T15 | T5, T6 | T5 → T15, T6 → T15 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Composer manifest | none | none | ✅ OK |
| T2 | Composer scripts | none | none | ✅ OK |
| T3 | Dockerfile | none | none | ✅ OK |
| T4 | phpunit.xml | none | none | ✅ OK |
| T5 | phpstan.neon + app fixes | none | none | ✅ OK |
| T6 | phpmd.xml | none | none | ✅ OK |
| T7 | Makefile | none | none | ✅ OK |
| T8 | Makefile | none | none | ✅ OK |
| T9 | Makefile | none | none | ✅ OK |
| T10 | workflow YAML | none | none | ✅ OK |
| T11 | compose smoke script | integration | integration | ✅ OK |
| T12 | Architecture tests | architecture | architecture | ✅ OK |
| T13 | Makefile + workflow | none | none | ✅ OK |
| T14 | docs | none | none | ✅ OK |
| T15 | Feature meta-test | feature | feature | ✅ OK |

---

## Requirement Traceability (Tasks)

| Requirement ID | Task(s) |
| --- | --- |
| QTOOL-01–03 | T1 |
| QTOOL-04 | T2 |
| QTOOL-05–06, QTOOL-09 | T5 |
| QTOOL-07–08 | T6 |
| QTOOL-10–11, QTOOL-14 | T3 |
| QTOOL-12–13 | T4, T8 |
| QTOOL-15–18 | T7, T8, T9 |
| QTOOL-19–22, QTOOL-34 | T12, T13 |
| QTOOL-23–25 | T14 |
| QTOOL-26 | T15 |
| QTOOL-27–33 | T10, T11 |

**Coverage:** 34 total, 34 mapped to tasks, 0 unmapped ✅

---

## Tools per Task (MCP / Skills)

| Task | MCP | Skill |
| --- | --- | --- |
| T1–T4, T6–T11, T13–T14 | NONE | `tlc-spec-driven` (Execute) |
| T5 | Context7 (Larastan) | `tlc-spec-driven` |
| T12 | Context7 (pest-plugin-arch) | `tlc-spec-driven` |
| T15 | NONE | `tlc-spec-driven` |

Confirm tool choices before Execute or override per task.
