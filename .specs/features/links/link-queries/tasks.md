# Links — Consultas de link Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: activate it by name and follow its Execute flow and Critical Rules. If the skill cannot be activated, STOP and report it.

---

**Design**: `.specs/features/links/link-queries/design.md`  
**Status**: Approved — 2026-09-18

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md`, `LARAVEL_CODE_DESIGN.md`, `docs/testing.md`, `Makefile`, `.specs/STATE.md`; test style sampled from `backend/modules/Links/Tests/{Unit,Integration,Feature,Contract}/`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Cursor codec / query DTOs | Unit | Todas as branches, requisitos LNQ-03/06/11 e casos de borda | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Read repository / índices | Integration (PostgreSQL real) | Ordenação, scope, filtros, decriptação/falhas e plano que usa índices | `backend/modules/Links/Tests/Integration/**` | `make test-backend` |
| Form Requests / controllers / resources / rate limit | Feature | Happy, toda validação, auth, ownership, `429` e falhas | `backend/modules/Links/Tests/Feature/**` | `make test-backend` |
| OpenAPI | Contract | Cada resposta, headers, schema e código de erro definido | `backend/modules/Links/Tests/Contract/**` | `make test-backend && make lint-openapi` |
| Caminho de gestão entregue | E2E | Listar e consultar detalhe no ambiente Docker real, incluindo isolamento | `frontend/e2e/**` | comando E2E de Links criado nesta fatia |
| Cobertura do módulo | Coverage gate | Links ≥90% linhas e ≥85% métodos; sem exclusões de regra | relatório PCOV do módulo | `make test-backend-coverage` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | Unit, resource ou DTO | `make test-backend` |
| Full | Repositório, endpoint, contrato ou migration | `make lint-backend && make test-backend && make lint-openapi` |
| E2E | Caminho HTTP de gestão | target Docker E2E de Links criado na tarefa correspondente |
| Build | Fechamento da fase | `make lint && make test-backend-coverage` e target E2E de Links |

---

## Execution Plan

### Phase 1: Consulta e paginação

```text
T1 → T2 → T3 → T4
```

### Phase 2: Superfície HTTP

```text
T4 → T5 → T6 → T7
```

### Phase 3: Verificação de fluxo

```text
T5 + T6 + T7 → T8
```

---

## Task Breakdown

### T1: Criar migration dos índices de consulta

**What**: Criar via `php artisan make:migration` no container backend a migration de `pg_trgm`, GIN de título, ordenação por proprietário/criação/ID e índice de prefixo de slug definido pelo `EXPLAIN`.
**Where**: `backend/database/migrations/*_add_link_query_indexes.php` e `backend/modules/Links/Tests/Integration/`
**Depends on**: None
**Reuses**: schema de `short_links`; `DatabaseSafetyGuard`
**Requirement**: LNK-50, LNK-52, LNQ-01, LNQ-04

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Migration é criada pelo Artisan dentro do container e é reversível.
- [x] PostgreSQL de teste tem extensão e índices necessários.
- [x] Teste de integração prova a estrutura e `EXPLAIN` da busca/ordenação usa índice compatível.
- [x] Gate full passa sem remover testes existentes.

**Tests**: integration  
**Gate**: full  
**Commit**: `feat(links): add link query indexes`

---

### T2: Implementar cursor assinado e DTO de consulta

**What**: Implementar `CursorCodec`, sua chave HMAC exclusiva, âncora/escopo tipados e `ListLinksQuery` normalizado.
**Where**: `backend/modules/Links/{Contracts,DTOs,Infrastructure/Pagination,Tests/Unit}/`
**Depends on**: T1
**Reuses**: configuração/serviços HMAC do módulo e `ApiFormRequest#errorCodes()`
**Requirement**: LNK-50, LNK-51, LNK-56, LNQ-03, LNQ-06, LNQ-11

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Cursor contém somente versão, âncora e escopo exigidos; não contém `user_id`.
- [x] Assinatura, formato, tipos, versão e escopo inválidos falham com erro específico.
- [x] `search` e `status` normalizados são vinculados; `per_page` não é.
- [x] Unit tests cobrem todos os ramos e os limites da SPEC.
- [x] Gate quick passa.

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(links): add signed query cursor`

---

### T3: Criar porta e adaptador de leitura

**What**: Criar `LinkQueryRepository` e seu adaptador Eloquent com projeções mínimas de lista/detalhe, keyset, busca, filtro de estado e scope por proprietário.
**Where**: `backend/modules/Links/{Contracts/Repositories,DTOs/Output,Infrastructure/Persistence/Eloquent/Repositories,Tests/Integration}/`
**Depends on**: T1, T2
**Reuses**: modelos, mappers e `EffectiveStatus`
**Requirement**: LNK-50, LNK-52, LNK-53, LNK-55, LNQ-01, LNQ-02, LNQ-04, LNQ-05, LNQ-08

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Repositório de escrita não ganha métodos de leitura.
- [x] Lista aplica owner, filtros e keyset antes do limite; detalhe aplica owner no lookup.
- [x] Lista não seleciona `link_destination_versions.destination_url`.
- [x] Integration tests PostgreSQL cobrem ordenação, âncora ausente, título nulo, OR, acentos, estados e isolamento.
- [x] Gate full passa.

**Tests**: integration  
**Gate**: full  
**Commit**: `feat(links): add read query repository`

---

### T4: Implementar UseCases e representações de leitura

**What**: Implementar `ListLinks`, `GetLink`, DTOs de saída e `LinkSummaryResource`/adaptação segura do recurso de detalhe.
**Where**: `backend/modules/Links/{UseCases,DTOs/Output,Infrastructure/Http/Resources,Tests/Unit,Tests/Integration}/`
**Depends on**: T2, T3
**Reuses**: `EffectiveStatus`, `LinkETag`, `DestinationCipher` e formato UTC de `LinkDetailResource`
**Requirement**: LNK-51, LNK-53, LNK-54, LNQ-02, LNQ-05, LNQ-07, LNQ-09

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Lista emite cursor somente quando houver página seguinte.
- [x] Detalhe calcula o `ETag` com a mesma tupla da criação.
- [x] Decriptação ocorre apenas no detalhe já scoped ao owner; falha não produz DTO parcial.
- [x] Unit/integration tests cobrem estado temporal e corrupção de envelope.
- [x] Gate full passa.

**Tests**: unit + integration  
**Gate**: full  
**Commit**: `feat(links): add link read use cases`

---

### T5: Entregar o endpoint de listagem

**What**: Adicionar Request, Controller, rota, throttle por token e testes do endpoint `GET /api/v1/links`.
**Where**: `backend/modules/Links/Infrastructure/Http/` e `backend/modules/Links/Tests/{Feature,Contract}/`
**Depends on**: T4
**Reuses**: middleware Auth, `ApiFormRequest`, `LinkResponseFactory` e padrão de testes de criação
**Requirement**: LNK-50, LNK-51, LNK-52, LNK-53, LNK-56, LNQ-01–LNQ-06, LNQ-10–LNQ-12

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Endpoint aplica autenticação, token `session`, validações e 300/min por token antes do controller.
- [x] `422 INVALID_CURSOR` possui exatamente o path de erro e código contratados.
- [x] Feature/contract tests cobrem 200, 401, 403, 422, 429, schema, meta e redação da seam.
- [x] Gate full passa.

**Tests**: feature + contract + e2e  
**Gate**: full + e2e  
**Commit**: `feat(links): add link listing endpoint`

---

### T6: Entregar o endpoint de detalhe

**What**: Adicionar Controller, rota e testes do endpoint `GET /api/v1/links/{link}`.
**Where**: `backend/modules/Links/Infrastructure/Http/` e `backend/modules/Links/Tests/{Feature,Contract}/`
**Depends on**: T4
**Reuses**: middleware da listagem, `LinkETag` e resource de detalhe
**Requirement**: LNK-54, LNK-55, LNQ-07–LNQ-10

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Endpoint devolve todos e somente os campos/headers OpenAPI.
- [x] Link ausente e alheio produzem a mesma resposta 404.
- [x] Falha de decriptação mapeia para 503 sem `data`, `ETag` ou destino.
- [x] Feature/contract/E2E tests cobrem sucesso, auth, ownership, headers e envelope corrompido.
- [x] Gate full passa.

**Tests**: feature + contract + e2e  
**Gate**: full + e2e  
**Commit**: `feat(links): add link detail endpoint`

---

### T7: Compor bindings, contrato e documentação de superfície

**What**: Registrar bindings/configuração, validar OpenAPI final e atualizar a superfície runtime em documentação.
**Where**: `backend/modules/Links/ServiceProviders/LinksServiceProvider.php`, `backend/config/links.php`, `docs/api.md`, `docs/openapi.yaml`, testes de provider/contrato
**Depends on**: T5, T6
**Reuses**: padrão de bindings do `LinksServiceProvider` e catálogo OpenAPI
**Requirement**: LNK-50–LNK-56, LNQ-01–LNQ-12

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Todas as portas são resolvíveis sem service locator.
- [x] OpenAPI, docs de superfície e runtime concordam sobre ambos os GETs.
- [x] Provider e contract tests falham se binding/contrato divergir.
- [x] Gate build passa.

**Tests**: feature + contract  
**Gate**: build  
**Commit**: `docs(links): publish link query contract`

---

### T8: Executar e estabilizar o E2E de Links

**What**: Criar/estender o target Docker E2E de Links e provar o caminho autenticado de listar e abrir detalhe no ambiente real.
**Where**: `frontend/e2e/`, `docker-compose.e2e.yml`, `Makefile` e testes E2E
**Depends on**: T5, T6, T7
**Reuses**: profile E2E, fixtures e práticas de `test-e2e-auth`
**Requirement**: LNQ-01, LNQ-07, LNQ-08, LNQ-10

**Tools**:
- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:
- [x] Target não executa no host e usa composição efêmera.
- [x] E2E comprova lista paginada e detalhe do próprio link; acesso cruzado não vaza conteúdo.
- [x] Artefatos são sanitizados e estáveis.
- [x] Gate E2E e build passam.

**Tests**: e2e  
**Gate**: e2e + build  
**Commit**: `test(links): add link query e2e coverage`

---

## Phase Execution Map

```text
Phase 1 → Phase 2 → Phase 3

Phase 1: T1 → T2 → T3 → T4
Phase 2: T4 → T5 → T6 → T7
Phase 3: T5 + T6 + T7 → T8
```

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1 | Uma migration de suporte | ✅ Granular |
| T2 | Codec e tipos coesos de cursor | ✅ Granular |
| T3 | Um adaptador de leitura | ✅ Granular |
| T4 | Dois UseCases coesos e suas projeções | ⚠️ Coesivo |
| T5 | Um endpoint | ✅ Granular |
| T6 | Um endpoint | ✅ Granular |
| T7 | Composição/contrato de uma fatia | ⚠️ Coesivo |
| T8 | Um fluxo E2E | ✅ Granular |

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | início | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T1, T2 | T1 → T2 → T3 | ✅ Match |
| T4 | T2, T3 | T2 → T3 → T4 | ✅ Match |
| T5 | T4 | T4 → T5 | ✅ Match |
| T6 | T4 | T4 → T6 | ✅ Match |
| T7 | T5, T6 | T5 → T6 → T7 | ✅ Match |
| T8 | T5, T6, T7 | T5 + T6 + T7 → T8 | ✅ Match |

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Migration/query schema | Integration | integration | ✅ OK |
| T2 | Codec/DTO | Unit | unit | ✅ OK |
| T3 | Repository | Integration | integration | ✅ OK |
| T4 | UseCases/resources | Unit + integration | unit + integration | ✅ OK |
| T5 | List controller | Feature + contract + E2E | feature + contract + e2e | ✅ OK |
| T6 | Detail controller | Feature + contract + E2E | feature + contract + e2e | ✅ OK |
| T7 | Provider/OpenAPI | Feature + contract | feature + contract | ✅ OK |
| T8 | E2E flow | E2E | e2e | ✅ OK |
