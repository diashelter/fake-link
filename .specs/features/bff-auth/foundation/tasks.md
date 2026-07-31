# BFF Auth — Fundação frontend — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/foundation/design.md`  
**Spec**: `.specs/features/bff-auth/foundation/spec.md`  
**Status**: Approved — 2026-07-30 (pré-Execute)

> **Sub-agent note:** 16 tasks → ~3 batches (~5–6 tasks/worker). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL/MSW/RHF/Query defaults), §4 (domínios frontend ≥75%), §8 (TS/ESLint/Prettier), `.specs/features/bff-auth/foundation/spec.md`, amostras `frontend/lib/*.test.ts`, `frontend/app/health/route.test.ts`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Zod schemas (`emailSchema`) | unit | 1:1 FND-03; inválido/válido; max 254; trim | `frontend/modules/shared/schemas/*.test.ts` | `make test-frontend` |
| Form defaults (focus, submit guard, server error map) | unit + RTL | FND-04 ACs; double-submit; focus first error | `frontend/modules/shared/lib/*.test.tsx` | `make test-frontend` |
| Query client factory / visible interval | unit | FND-05/06; valores exatos; sem persister; visibility | `frontend/modules/shared/lib/query-client.test.ts` | `make test-frontend` |
| UI primitives (Button/Input/Label/FormField) | RTL | FND-08; label association; error visible | `frontend/modules/shared/components/ui/*.test.tsx` | `make test-frontend` |
| MSW wiring | unit | FND-15/16; intercept + reset | `frontend/modules/shared/test/msw/*.test.ts` | `make test-frontend` |
| App layout / landing (smoke) | RTL or unit | FND-02 lang/tema; regressão copy pt-BR | `frontend/app/*.test.tsx` (opcional smoke) | `make test-frontend` |
| ESLint/Prettier/Husky/Makefile config | none | — build/lint gate | — | `make lint-frontend` |
| Module scaffold empty dirs / barrels | none | — typecheck gate | — | `make lint-frontend` |
| Package.json dependency presence | none | FND-17/18 via assert em teste de smoke ou checklist gate | pode ser assert em `query-client`/`email` tests importando pacotes | `make test-frontend` |
| Health / session-cookie existentes | unit (regressão) | Sem regressão | `frontend/**/*.test.ts` existentes | `make test-frontend` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks de config sem testes de domínio (T1–T4, T5 parcial) | `make test-frontend` (suite existente deve continuar verde) |
| Full | Após tasks com unit/RTL novos | `make test-frontend` |
| Build | Após fase de lint/hooks ou fechamento | `make lint-frontend && make test-frontend` (e `make lint` quando `lint-frontend` estiver wired) |
| Coverage | Task final de cobertura | `make test-frontend-coverage` — ≥75% linhas/branches em `modules/**` |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Dependências e qualidade base

```
T1 → T2 → T3 → T4
```

### Phase 2: Scaffold modular + Tailwind + shell

```
T5 → T6
```

### Phase 3: Primitivos UI

```
T7 → T8 → T9
```

### Phase 4: Forms + Query

```
T10 → T11 → T12
```

### Phase 5: Test infra, MSW, coverage, docs

```
T13 → T14 → T15 → T16
```

---

## Task Breakdown

### T1: Instalar dependências frontend confirmadas

**What**: Adicionar ao `frontend/package.json` as deps runtime/dev confirmadas (RHF, Zod, resolvers, TanStack Query, Tailwind v4 + postcss, RTL, jest-dom, user-event, MSW, jsdom, ESLint flat stack, Prettier, eslint-config-prettier) e atualizar lockfile via container.
**Where**: `frontend/package.json`, `frontend/pnpm-lock.yaml`
**Depends on**: None
**Reuses**: `docker/node/Dockerfile` pnpm flow; AD-005 pins
**Requirement**: FND-17, FND-18, BFFUI-02/03 (pacotes)

**Tools**:

- MCP: NONE (install via Docker/`pnpm` no container)
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Pacotes listados na spec presentes em `dependencies` / `devDependencies`
- [x] Radix **ausente**
- [x] `pnpm install` no container frontend sucede (`pnpm-lock.yaml` atualizado)
- [x] Gate: `make test-frontend` ainda passa (suite existente)

**Tests**: none (config/deps)
**Gate**: quick  
**Commit**: `chore(frontend): add foundation runtime and quality dependencies`

---

### T2: Configurar ESLint flat + Prettier

**What**: Criar `eslint.config.mjs` (eslint-config-next + prettier disable) e config Prettier; substituir script `lint` placeholder.
**Where**: `frontend/eslint.config.mjs`, `frontend/.prettierrc` (ou `prettier.config.mjs`), `frontend/.prettierignore`, `frontend/package.json` scripts
**Depends on**: T1
**Reuses**: padrão Next 16 flat (`eslint .`)
**Requirement**: BFFUI-04, FND-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `pnpm lint` executa ESLint (não é `echo`)
- [x] `pnpm format:check` / `pnpm typecheck` scripts existem
- [x] `eslint-config-prettier` evita conflito de formatação
- [x] Código existente passa ou é formatado o mínimo necessário nesta task

**Tests**: none
**Gate**: quick — após scripts: `$(COMPOSE) run --rm --no-deps frontend pnpm typecheck && pnpm lint && pnpm format:check` (ou equivalente documentado até T3)
**Commit**: `chore(frontend): configure ESLint flat and Prettier`

---

### T3: Makefile `lint-frontend` e integrar em `make lint`

**What**: Adicionar target `lint-frontend` e fazer `make lint` rodar backend + frontend fail-fast.
**Where**: `Makefile`
**Depends on**: T2
**Reuses**: padrão `test-frontend`
**Requirement**: FND-09, FND-10

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `make lint-frontend` roda typecheck + eslint + prettier check no container
- [x] `make lint` inclui `lint-frontend` após `lint-backend`
- [x] `.PHONY` atualizado
- [x] Gate: `make lint-frontend` exit 0

**Tests**: none
**Gate**: build (`make lint-frontend`)
**Commit**: `chore(make): add lint-frontend and include in lint`

---

### T4: Husky + lint-staged na raiz do monorepo

**What**: Criar `package.json` raiz com `prepare`/husky, `.husky/pre-commit`, lint-staged para globs `frontend/**`.
**Where**: `package.json` (raiz), `.husky/pre-commit`, config lint-staged
**Depends on**: T2
**Reuses**: configs ESLint/Prettier em `frontend/`
**Requirement**: FND-12, FND-13, FND-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `prepare` instala Husky
- [x] pre-commit invoca lint-staged
- [x] Globs limitados a frontend; non-frontend staged não quebra o hook
- [x] Documentação mínima no README da fatia ou `frontend/README` / raiz sobre `pnpm install` na raiz
- [x] Gate: config validável (script dry-run ou teste documentado); `make lint-frontend` permanece verde

**Tests**: none (hook config; comportamento assertado via checklist + eventual script)
**Gate**: build (`make lint-frontend`)
**Commit**: `chore: add husky and lint-staged for frontend`

---

### T5: Scaffold `modules/auth` e `modules/shared`

**What**: Criar árvore mínima dos módulos com arquivo âncora exportável (ex. `modules/shared/index.ts` ou `.gitkeep` + um `package` marker) e garantir path `@/modules/...` no tsc.
**Where**: `frontend/modules/auth/`, `frontend/modules/shared/`
**Depends on**: T3
**Reuses**: `tsconfig` paths `@/*`
**Requirement**: BFFUI-01, FND-01, FND-02

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] Pastas existem sob `frontend/modules/` (não `src/`)
- [x] Nenhum Route Handler Auth de produto criado
- [x] `make lint-frontend` passa

**Tests**: none
**Gate**: build
**Commit**: `feat(frontend): scaffold auth and shared modules`

---

### T6: Tailwind v4 + shell landing pt-BR

**What**: PostCSS Tailwind v4, `globals.css` com `@theme` claro, aplicar classes na landing; layout importa CSS; reflow 360px sem overflow horizontal do layout.
**Where**: `frontend/postcss.config.mjs`, `frontend/app/globals.css`, `frontend/app/layout.tsx`, `frontend/app/page.tsx`
**Depends on**: T5
**Reuses**: layout/page existentes
**Requirement**: BFFUI-05, FND-02, FND-07

**Tools**:

- MCP: NONE
- Skill: `frontend-design` (opcional, shell mínimo)

**Done when**:

- [x] Tailwind aplica na landing
- [x] `html lang="pt-BR"` mantido; tema claro only
- [x] Copy pt-BR preservada
- [x] Smoke test ou assert de render da home (RTL) opcional mas recomendado
- [x] `make lint-frontend && make test-frontend` passam

**Tests**: unit/RTL smoke da page se criado nesta task; senão regressão suite
**Gate**: full
**Commit**: `feat(frontend): add Tailwind v4 and light shell layout`

---

### T7: Primitivo `Button`

**What**: Implementar `Button` acessível com variantes mínimas + testes RTL.
**Where**: `frontend/modules/shared/components/ui/button.tsx`, `button.test.tsx`
**Depends on**: T6
**Reuses**: Tailwind tokens
**Requirement**: FND-08 (parcial)

**Tools**:

- MCP: NONE
- Skill: `react-composition-patterns` (opcional)

**Done when**:

- [x] `type`, `disabled`, variant básica
- [x] Testes RTL cobrem render e disabled
- [x] Gate: `make test-frontend` verde

**Tests**: unit (RTL)
**Gate**: full
**Commit**: `feat(shared): add Button UI primitive`

---

### T8: Primitivos `Input` e `Label`

**What**: Implementar `Input` e `Label` com associação `htmlFor`/`id` + testes.
**Where**: `frontend/modules/shared/components/ui/input.tsx`, `label.tsx`, `*.test.tsx`
**Depends on**: T7
**Reuses**: padrão Button
**Requirement**: FND-08 (parcial)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Associação label↔input testada
- [x] Tipos text/email/password suportados no Input
- [x] `make test-frontend` verde

**Tests**: unit (RTL)
**Gate**: full
**Commit**: `feat(shared): add Input and Label primitives`

---

### T9: Primitivo `FormField`

**What**: Compor label + controle + mensagem de erro; testes de erro visível.
**Where**: `frontend/modules/shared/components/ui/form-field.tsx`, `form-field.test.tsx`
**Depends on**: T8
**Reuses**: Label, Input
**Requirement**: FND-08

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Erro renderiza de forma acessível (role/alert ou `aria-describedby`)
- [x] Testes RTL cobrem happy + error
- [x] `make test-frontend` verde

**Tests**: unit (RTL)
**Gate**: full
**Commit**: `feat(shared): add FormField primitive`

---

### T10: Schema Zod `emailSchema` + testes

**What**: Schema âncora de e-mail (trim, email, max 254) com testes 1:1 FND-03.
**Where**: `frontend/modules/shared/schemas/email.ts`, `email.test.ts`
**Depends on**: T5
**Reuses**: bounds OpenAPI
**Requirement**: BFFUI-02, FND-03, FND-17

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Válido/inválido/max length cobertos
- [x] Import prova presença de `zod`
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full
**Commit**: `feat(shared): add email Zod schema anchor`

---

### T11: Defaults RHF + harness de formulário

**What**: Helpers de foco/submit guard + harness RTL com RHF+zodResolver+FormField cobrindo FND-04.
**Where**: `frontend/modules/shared/lib/form-defaults.ts`, `form-defaults.test.tsx`
**Depends on**: T9, T10
**Reuses**: FormField, emailSchema
**Requirement**: BFFUI-02, FND-04

**Tools**:

- MCP: NONE
- Skill: `react-best-practices` (opcional)

**Done when**:

- [x] Teste: submit inválido foca primeiro erro
- [x] Teste: segundo submit bloqueado enquanto submitting
- [x] Teste: erro server-side aparece no campo
- [x] Sem rota de produto `/login`
- [x] `make test-frontend` verde

**Tests**: unit (RTL)
**Gate**: full
**Commit**: `feat(shared): add RHF form defaults and test harness`

---

### T12: QueryClient factory + AppProviders

**What**: `createAppQueryClient`, helper de polling visível, provider no layout; testes dos defaults e ausência de persister (FND-05/06/18).
**Where**: `frontend/modules/shared/lib/query-client.ts`, `query-client.test.ts`, `frontend/modules/shared/components/app-providers.tsx`, `frontend/app/layout.tsx`
**Depends on**: T6
**Reuses**: QUERY_DEFAULTS do design
**Requirement**: BFFUI-03, FND-05, FND-06, FND-18

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] staleTime=30s, gcTime=5min, retry mutation=0, query retry=1 para GET transitório
- [x] Teste falha se persister for registrado
- [x] Polling helper retorna false quando `document.hidden`
- [x] Layout envolve children com `AppProviders`
- [x] `make lint-frontend && make test-frontend` verdes

**Tests**: unit
**Gate**: build
**Commit**: `feat(shared): add TanStack Query defaults and providers`

---

### T13: Vitest jsdom, setup RTL e coverage config

**What**: Estender `vitest.config.ts` (jsdom para tsx, setupFiles, coverage thresholds 75% em `modules/**`), `vitest.setup.ts` com jest-dom.
**Where**: `frontend/vitest.config.ts`, `frontend/vitest.setup.ts`
**Depends on**: T1, T11, T12
**Reuses**: alias `@/` existente
**Requirement**: BFFUI-04, FND-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `*.test.tsx` rodam em jsdom
- [x] `*.test.ts` existentes continuam passando
- [x] Coverage reporter configurado; thresholds documentados
- [x] `make test-frontend` verde

**Tests**: none (infra — validada pelos testes existentes/novos)
**Gate**: full
**Commit**: `chore(frontend): configure Vitest jsdom RTL and coverage`

---

### T14: Wiring MSW smoke + reset

**What**: Server MSW de teste, handler exemplo, teste FND-15/16 com reset afterEach.
**Where**: `frontend/modules/shared/test/msw/server.ts`, `handlers.ts`, `msw-smoke.test.ts`
**Depends on**: T13
**Reuses**: MSW docs pattern
**Requirement**: FND-15, FND-16

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Teste intercepta request sem rede externa
- [x] afterEach reset/restore
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full
**Commit**: `test(shared): add MSW smoke wiring`

---

### T15: Gate Makefile de cobertura frontend ≥75%

**What**: Target `test-frontend-coverage` (ou equivalente) que falha se cobertura de `modules/**` &lt; 75% linhas/branches.
**Where**: `Makefile`, possivelmente script `frontend/scripts/check-coverage.mjs`
**Depends on**: T13, T14
**Reuses**: padrão mental do gate Auth backend
**Requirement**: FND-11

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Comando documentado no Makefile
- [x] Falha proposital se threshold baixado em teste local (ou assert do script)
- [x] Gate: `make test-frontend-coverage` exit 0 no estado atual

**Tests**: none (gate)
**Gate**: coverage
**Commit**: `chore(make): add frontend coverage gate`

---

### T16: Docs de hooks + fechar regressões

**What**: Documentar install raiz Husky; garantir health/cookie tests verdes; checklist Out of Scope (sem Auth handlers, sem Radix, sem Bearer).
**Where**: `README.md` (raiz ou `frontend/README.md`), `.specs/features/bff-auth/foundation/` status se necessário
**Depends on**: T4, T15
**Reuses**: índice BFF Auth
**Requirement**: FND-02, FND-12 (docs), success criteria

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Docs descrevem `pnpm install` na raiz para hooks
- [x] `make lint && make test-frontend && make test-frontend-coverage` verdes
- [x] Assert manual/checklist: zero Route Handlers Auth de produto; Radix ausente

**Tests**: none
**Gate**: build (`make lint && make test-frontend-coverage`)
**Commit**: `docs(frontend): document husky setup and foundation gates`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4
Phase 2:  T5 ──→ T6
Phase 3:  T7 ──→ T8 ──→ T9
Phase 4:  T10 ──→ T11 ──→ T12
Phase 5:  T13 ──→ T14 ──→ T15 ──→ T16
```

**Batch packing (Execute):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | Phase 1 + Phase 2 | T1–T6 (6) |
| 2 | Phase 3 + Phase 4 | T7–T12 (6) |
| 3 | Phase 5 | T13–T16 (4) |

Execution is strictly sequential within and across batches.

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: deps | 1 manifesto + lockfile | ✅ Granular |
| T2: ESLint+Prettier | configs coesas | ✅ Granular |
| T3: Makefile lint | 1 arquivo Makefile | ✅ Granular |
| T4: Husky | raiz hooks | ✅ Granular |
| T5: scaffold modules | árvore modules | ✅ Granular |
| T6: Tailwind+shell | CSS+layout+page | ⚠️ 2–3 arquivos coesos | ✅ OK |
| T7: Button | 1 component | ✅ Granular |
| T8: Input+Label | 2 componentes relacionados | ⚠️ OK cohesive |
| T9: FormField | 1 component | ✅ Granular |
| T10: emailSchema | 1 schema | ✅ Granular |
| T11: form defaults | 1 lib + harness | ✅ Granular |
| T12: Query+providers | factory+provider+layout wire | ⚠️ OK cohesive |
| T13: Vitest config | config only | ✅ Granular |
| T14: MSW | wiring smoke | ✅ Granular |
| T15: coverage gate | Makefile/script | ✅ Granular |
| T16: docs | docs + final gate | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (body) | Diagram Shows | Status |
| ---- | ----------------- | ------------- | ------ |
| T1 | None | start | ✅ |
| T2 | T1 | T1→T2 | ✅ |
| T3 | T2 | T2→T3 | ✅ |
| T4 | T2 | T2→T4 (parallel after T2; phase order T3 then T4) | ✅ Match phase order |
| T5 | T3 | T3→T5 via Phase2 after Phase1 | ✅ |
| T6 | T5 | T5→T6 | ✅ |
| T7 | T6 | T6→T7 | ✅ |
| T8 | T7 | T7→T8 | ✅ |
| T9 | T8 | T8→T9 | ✅ |
| T10 | T5 | T5→T10 (Phase4 after T5; also after Phase3 for T11) | ✅ |
| T11 | T9, T10 | T9→T11, T10→T11 | ✅ |
| T12 | T6 | T6→T12 | ✅ |
| T13 | T1, T11, T12 | after Phase4 | ✅ |
| T14 | T13 | T13→T14 | ✅ |
| T15 | T13, T14 | T14→T15 | ✅ |
| T16 | T4, T15 | after hooks+coverage | ✅ |

Note: T4 depends only on T2 (can conceptually parallel T3) but **phase order** runs T3 before T4 — diagram uses sequential Phase 1 order. Bodies allow T4∥T3 after T2; execution plan keeps T3→T4 for simpler single-agent flow. ✅ Acceptable.

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| ---- | --------------------------- | --------------- | --------- | ------ |
| T1 | package.json | none | none | ✅ |
| T2 | ESLint/Prettier config | none | none | ✅ |
| T3 | Makefile | none | none | ✅ |
| T4 | Husky config | none | none | ✅ |
| T5 | module scaffold | none | none | ✅ |
| T6 | layout/landing + CSS | smoke optional | unit/RTL smoke or regressão | ✅ |
| T7 | Button | RTL | unit (RTL) | ✅ |
| T8 | Input/Label | RTL | unit (RTL) | ✅ |
| T9 | FormField | RTL | unit (RTL) | ✅ |
| T10 | emailSchema | unit | unit | ✅ |
| T11 | form defaults | unit+RTL | unit (RTL) | ✅ |
| T12 | query client | unit | unit | ✅ |
| T13 | vitest config | none | none | ✅ |
| T14 | MSW | unit | unit | ✅ |
| T15 | coverage gate | none | none | ✅ |
| T16 | docs | none | none | ✅ |

---

## Requirement Traceability (tasks)

| Requirement ID | Tasks |
| --- | --- |
| BFFUI-01 | T5 |
| BFFUI-02 | T1, T10, T11 |
| BFFUI-03 | T1, T12 |
| BFFUI-04 | T2, T3, T13 |
| BFFUI-05 | T6 |
| FND-01 | T5 |
| FND-02 | T5, T6, T16 |
| FND-03 | T10 |
| FND-04 | T11 |
| FND-05 | T12 |
| FND-06 | T12 |
| FND-07 | T6 |
| FND-08 | T7, T8, T9 |
| FND-09 | T2, T3 |
| FND-10 | T3 |
| FND-11 | T13, T15 |
| FND-12 | T4, T16 |
| FND-13 | T4 |
| FND-14 | T4 |
| FND-15 | T14 |
| FND-16 | T14 |
| FND-17 | T1, T10 |
| FND-18 | T1, T12 |

**Coverage:** 22 requirements mapped, 0 unmapped.
