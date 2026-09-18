# Links — Criação de link · Tasks

## Execution Protocol (MANDATORY — do not skip)

Implemente estas tarefas com a skill `tlc-spec-driven`: **ative-a pelo nome e siga o fluxo de Execute e as Critical Rules.** Não procure arquivos da skill por caminho de filesystem. A skill é a fonte da verdade do fluxo completo (ciclo por tarefa, delegação a sub-agents, Verifier, sensor de discriminação).

**Se a skill não puder ser ativada, PARE e avise o usuário — não prossiga sem ela.**

---

**Spec**: [spec.md](./spec.md)  
**Context**: [context.md](./context.md)  
**Design**: [design.md](./design.md)  
**Status**: Draft — aguardando aprovação (e confirmação da abordagem A em design.md)

**Pré-requisito de execução:** as fatias [foundation](../foundation/spec.md) e [destination-policy](../destination-policy/spec.md) precisam estar entregues; [slug-policy](../slug-policy/spec.md) tem spec fechada mas ainda não implementada. Não iniciar Execute antes disso.

---

## Test Coverage Matrix

> Gerada do codebase, das guidelines do projeto e da spec — confirmar antes do Execute. Guidelines encontradas: `AGENTS.md`, `docs/testing.md` §3–§7, `backend/phpunit.xml`, `Makefile`, `.github/workflows/backend-quality.yml`, `backend/scripts/check-auth-coverage-gate.php`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Domain (`Domain/Services`, `Domain/ValueObjects`) | unit | Todos os ramos; 1:1 com as ACs da spec; todo edge case listado tem teste | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| UseCase (`UseCases/`) | integration | Caminho feliz + cada modo de falha + rollback de cada etapa da transação; PostgreSQL `fake_link_testing` (AD-011) | `backend/modules/Links/Tests/Integration/**/*Test.php` | `make test-backend` |
| Repository (`Infrastructure/Persistence`) | integration | Caminhos de escrita + violação de constraint + participação na transação corrente | `backend/modules/Links/Tests/Integration/**/*Test.php` | `make test-backend` |
| HTTP — Request / Resource / Response factories | unit | Todos os ramos de validação, serialização e headers | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| HTTP — Controller / rota / middleware | e2e (Feature) | Toda rota do escopo: happy path + todo edge case listado + caminhos de erro (`401`, `403`, `409`, `422`, `429`, `503`) | `backend/modules/Links/Tests/Feature/**/*Test.php` | `make test-backend` |
| Contrato OpenAPI | e2e (Contract) | Request + `201`/`409`/`422`/`429` validados contra `docs/openapi.yaml` (AD-016) | `backend/modules/Links/Tests/Contract/**/*Test.php` | `make test-backend` |
| Config / env / docs / OpenAPI YAML | none | — (build gate: `make lint` + `make lint-openapi`) | — | `make lint` |

Cobertura do módulo: **90% linhas / 85% branches** (`docs/testing.md` §4). Como PCOV não mede branches em PHP, a convenção do projeto usa **cobertura de métodos** no lugar — mesma substituição já aplicada ao Auth.

## Gate Check Commands

> Gerados do codebase — confirmar antes do Execute.

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | Após tarefas só com testes unitários | `make test-backend` |
| Full | Após tarefas com testes de integração ou e2e | `make test-backend` |
| Build | Após conclusão de fase, ou tarefas só de config/docs/OpenAPI | `make lint` (inclui `lint-openapi`, `lint-backend`, `lint-frontend`, Architecture e `test-backend`) |
| Coverage | Ao fim da última fase | `make test-backend-coverage` |

> Nota: os gates backend rodam **somente via Docker** (AD-009); `make test-backend` já executa Pest no container com `fake_link_testing`.

---

## Execution Plan

Fases ordenadas e sequenciais; tarefas dentro de uma fase executam em ordem.

### Phase 1: Contrato e configuração

```
T1 → T2 → T3
```

### Phase 2: Domínio da criação

```
T4 → T5 → T6
```

### Phase 3: Persistência transacional

```
T7 → T8 → T9
```

### Phase 4: Superfície HTTP

```
T10 → T11 → T12 → T13 → T14
```

### Phase 5: Concorrência, contrato e telemetria

```
T15 → T16 → T17
```

---

## Task Breakdown

### T1: Configuração do módulo Links

**What**: criar `backend/config/links.php` com `short_url.base_url`, `rate_limits.create`, `rate_limit_hmac_key` e `etag_hmac_key`; acrescentar `SHORT_URL_BASE` a `.env.example` e à validação de env.  
**Where**: `backend/config/links.php`, `backend/.env.example`, `docker/scripts/validate-env.sh`  
**Depends on**: None  
**Reuses**: `backend/config/auth.php` (`rate_limits.*`, `rate_limit_hmac_key`)  
**Requirement**: LNC-03, LNK-34

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] `links.short_url.base_url` lê `SHORT_URL_BASE` com fallback `https://{SHORT_HOST}`
- [x] `links.rate_limits.create` = `{max_attempts: 60, decay_seconds: 60}`
- [x] `SHORT_URL_BASE` presente em `.env.example` e na lista de `validate-env.sh`
- [x] Gate: `make lint`

**Tests**: none · **Gate**: build  
**Commit**: `feat(links): add module configuration for short url and creation limits`

---

### T2: Documentar `SLUG_GENERATION_FAILED` no contrato

**What**: acrescentar o código estável `SLUG_GENERATION_FAILED` a `docs/api.md` §7 e o exemplo correspondente na resposta `503` de `createLink` em `docs/openapi.yaml`.  
**Where**: `docs/api.md`, `docs/openapi.yaml`  
**Depends on**: None  
**Reuses**: exemplos existentes de `ServiceUnavailable` e `LinkConflict`  
**Requirement**: LNC-14

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] `SLUG_GENERATION_FAILED` listado em `docs/api.md` §7
- [x] Exemplo na resposta `503` de `createLink`, com `Retry-After` documentado
- [x] Gate: `make lint-openapi` passa sem novos warnings

**Tests**: none · **Gate**: build  
**Commit**: `docs(api): document SLUG_GENERATION_FAILED for link creation`

---

### T3: Códigos estáveis de erro por campo em `ApiFormRequest`

**What**: acrescentar `errorCodes(): array` opcional (mapa `campo.regra` → código) à base compartilhada, com default `'INVALID'` preservado.  
**Where**: `backend/app/Http/Requests/ApiFormRequest.php` (modificar), `backend/tests/Unit/Http/Requests/ApiFormRequestTest.php`  
**Depends on**: None  
**Reuses**: `App\Http\Responses\ApiResponse::validationError`  
**Requirement**: LNC-16

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] `errorCodes()` mapeia campo+regra para código estável; ausência do mapa mantém `'INVALID'`
- [x] Teste de regressão prova que os `FormRequest` do Auth continuam emitindo `'INVALID'`
- [x] Gate: `make test-backend`
- [x] Test count: ≥6 testes passam (sem deleções silenciosas)

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(api): allow form requests to declare stable per-field error codes`

> Se aprovado, registrar como `AD-019` em `.specs/STATE.md` (convenção válida para todos os módulos).

---

### T4: `EffectiveStatus` — derivação do estado efetivo

**What**: serviço de domínio que deriva `blocked` → `expired` → `inactive` → `active`.  
**Where**: `backend/modules/Links/Domain/Services/EffectiveStatus.php`, `Domain/Enums/LinkStatus.php`  
**Depends on**: None  
**Reuses**: padrão de enum e serviço de domínio do Auth  
**Requirement**: LNC-04

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] Precedência de `docs/data-model.md` §4 coberta nas quatro combinações e nos empates
- [x] `expires_at == now()` resolve como `expired` (limite exclusivo)
- [x] `Domain` sem `config()` nem Eloquent (Pest Arch)
- [x] Gate: `make test-backend`
- [x] Test count: ≥8 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): derive effective link status in the domain`

---

### T5: `LinkETag` — ETag forte e opaco

**What**: serviço de domínio que calcula `HMAC-SHA256` sobre a tupla canônica e devolve `"<hex>"`.  
**Where**: `backend/modules/Links/Domain/Services/LinkETag.php`, `Contracts/Services/ETagSigningKey.php`  
**Depends on**: T4  
**Reuses**: `HmacRateLimitKeyFactory` (formato de `hash_hmac`), padrão de contrato injetável de slug-policy  
**Requirement**: LNK-32, LNC-11, LNC-12

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] Formato forte casa `^"[^"]+"$` e não usa prefixo `W/`
- [x] Determinismo: mesmo estado → mesmo valor; teste de sensibilidade para **cada** campo da tupla
- [x] Estado efetivo entra no cálculo (bloqueio e expiração alteram o valor)
- [x] Teste prova que `version`, `user_id` e a URL de destino não são recuperáveis do valor
- [x] Gate: `make test-backend`
- [x] Test count: ≥12 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): compute opaque strong etag from effective link state`

---

### T6: DTOs de criação

**What**: `CreateLinkInput` (entrada) e `CreatedLinkDto` (saída, incluindo `version`/`blockedAt` para o ETag).  
**Where**: `backend/modules/Links/DTOs/Input/CreateLinkInput.php`, `DTOs/Output/CreatedLinkDto.php`  
**Depends on**: T4  
**Reuses**: `Modules\Auth\DTOs\*` (readonly classes)  
**Requirement**: LNC-02

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] DTOs `final readonly`, com tipos estritos e sem lógica
- [x] Teste prova que `CreatedLinkDto` carrega estado suficiente para ETag e para o Resource
- [x] Gate: `make test-backend`
- [x] Test count: ≥3 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add create link input and output dtos`

---

### T7: `ShortLinkRepository` — contrato e implementação Eloquent

**What**: contrato de criação de `short_links` participando da transação corrente, sem caminho de update de `slug`/`user_id`.  
**Where**: `backend/modules/Links/Contracts/Repositories/ShortLinkRepository.php`, `Infrastructure/Persistence/Eloquent/Repositories/EloquentShortLinkRepository.php`, `.../Models/ShortLinkModel.php`, `.../Mappers/ShortLinkMapper.php`  
**Depends on**: T6  
**Reuses**: `EloquentUserRepository` + `UserMapper` (padrão), `Uuid7UserIdGenerator` (AD-012)  
**Requirement**: LNC-04, LNC-08

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [x] `create()` insere com UUID v7 gerado na aplicação e participa da transação aberta pelo chamador
- [x] Não existe método de update de `slug` nem de `user_id` (verificado por teste + Pest Arch)
- [x] Violação de FK de `slug` mapeia para falha tipada
- [x] Gate: `make test-backend`
- [x] Test count: ≥6 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): add short link repository with immutable slug and owner`

---

### T8: `DestinationVersionRepository` — primeira versão vigente

**What**: contrato e implementação de `openFirstVersion()` gravando envelope cifrado + `key_id`, `valid_from` e `valid_to = null`.  
**Where**: `backend/modules/Links/Contracts/Repositories/DestinationVersionRepository.php`, `Infrastructure/Persistence/Eloquent/Repositories/EloquentDestinationVersionRepository.php`, `.../Models/`, `.../Mappers/`  
**Depends on**: T7  
**Reuses**: porta de cifra da fatia destination-policy; padrão de repositório do Auth  
**Requirement**: LNC-10

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Linha gravada com `valid_to = null`, `valid_from` = instante da criação e `key_id` preenchido
- [ ] Teste inspeciona a coluna e prova que a URL **não** está em texto claro
- [ ] Segunda versão vigente para o mesmo link viola o índice parcial único e falha
- [ ] Participa da transação corrente
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥6 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): persist first encrypted destination version`

---

### T9: UseCase `CreateLink` com transação única

**What**: orquestrar validação/cifra do destino, alocação de slug e persistência das três linhas em uma transação.  
**Where**: `backend/modules/Links/UseCases/CreateLink.php`, `backend/modules/Links/Tests/Integration/CreateLinkTest.php`  
**Depends on**: T5, T6, T7, T8  
**Reuses**: `Modules\Auth\UseCases\RegisterUser` (estrutura), `SlugGenerator` + `SlugReservationRepository` (slug-policy), `DestinationUrl` + cifra (destination-policy)  
**Requirement**: LNK-31, LNC-08, LNC-09, LNC-10, LNC-13, LNC-14

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Caminho feliz cria exatamente uma linha em cada uma das três tabelas
- [ ] Falha no insert da versão de destino reverte link e reserva; falha no insert do link reverte a reserva
- [ ] Validação e cifra do destino ocorrem antes de abrir a transação
- [ ] `SlugUnavailable` e `SlugGenerationExhausted` propagam tipadas, sem estado parcial
- [ ] Estado inicial: `is_enabled=true`, `blocked_at=null`, `version=1`, `slug_source` correto
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥10 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): create link, reservation and first destination in one transaction`

---

### T10: `CreateLinkRequest` — payload fechado

**What**: `FormRequest` com os quatro campos, códigos estáveis por campo e rejeição de campo desconhecido.  
**Where**: `backend/modules/Links/Infrastructure/Http/Requests/CreateLinkRequest.php`, `Tests/Unit/Http/CreateLinkRequestTest.php`  
**Depends on**: T3, T6  
**Reuses**: `ApiFormRequest` + `errorCodes()` (T3), padrão `ALLOWED_FIELDS`/`$submittedKeys` de `UpdateCurrentUserRequest`  
**Requirement**: LNK-30, LNC-16, LNC-17, LNC-18

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] `destination_url` ausente → `REQUIRED`; inválida → `INVALID_DESTINATION_URL` sem ecoar a URL
- [ ] `custom_alias` inválido ou `null` → `INVALID_ALIAS`, sem revelar a regra violada
- [ ] `title` com trim, `""` → `null`, 160 aceito, 161 → `TITLE_TOO_LONG` (contagem por caractere)
- [ ] `expires_at` `<= now()` → `EXPIRES_AT_NOT_IN_FUTURE`; formato fora de ISO `Z` → `INVALID_DATETIME`
- [ ] Campo extra → `UNKNOWN_FIELD` por campo
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥20 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): validate the closed create link payload`

---

### T11: `LinkDetailResource` e `LinkResponseFactory`

**What**: serialização exata de `LinkDetail` e resposta `201` com `Location`, `ETag`, `Cache-Control` e `X-Request-ID`.  
**Where**: `backend/modules/Links/Infrastructure/Http/Resources/LinkDetailResource.php`, `.../Http/Responses/LinkResponseFactory.php`  
**Depends on**: T5, T6  
**Reuses**: `AuthUserResource::formatUtc()` (formato), `AuthResponseFactory` (headers)  
**Requirement**: LNC-01, LNC-02, LNC-03

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Corpo contém exatamente os campos de `LinkDetail`; sem `version`, `blocked_at`, `user_id` ou analytics
- [ ] `short_url` = `{links.short_url.base_url}/{slug}`; teste prova independência do host do request
- [ ] Datas em ISO 8601 UTC com sufixo `Z`
- [ ] `Location: /api/v1/links/{id}`, `ETag` forte, `Cache-Control: private, no-store`, `X-Request-ID` presentes
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥10 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): serialize link detail and creation response headers`

---

### T12: `LinkErrorResponseFactory`

**What**: respostas estáveis `409 ALIAS_UNAVAILABLE`, `503 SLUG_GENERATION_FAILED` (+`Retry-After`), `429 RATE_LIMIT_EXCEEDED` (+`Retry-After`) e `503 SERVICE_UNAVAILABLE`.  
**Where**: `backend/modules/Links/Infrastructure/Http/Responses/LinkErrorResponseFactory.php`  
**Depends on**: None  
**Reuses**: `AuthErrorResponseFactory` (formato de corpo, `request_id`, headers)  
**Requirement**: LNK-33, LNC-13, LNC-14

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Cada resposta tem `code`, `message`, `request_id`, `Cache-Control: private, no-store` e `X-Request-ID`
- [ ] `Retry-After` inteiro ≥ 1 nas respostas que o exigem
- [ ] Teste prova que nenhuma resposta contém alias, destino, título ou dados do ocupante
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥8 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add stable error responses for link creation`

---

### T13: Rota, Controller e registro do módulo

**What**: `CreateLinkController`, `routes/links.php`, `LinksServiceProvider` (bindings + rota) e registro das suítes de `Links` em `phpunit.xml`; testes e2e do endpoint.  
**Where**: `backend/modules/Links/Infrastructure/Http/Controllers/CreateLinkController.php`, `.../Http/routes/links.php`, `backend/modules/Links/ServiceProviders/LinksServiceProvider.php`, `backend/phpunit.xml`, `Tests/Feature/CreateLinkTest.php`  
**Depends on**: T9, T10, T11, T12  
**Reuses**: `UpdateCurrentUserController` (estrutura), `AuthServiceProvider` (padrão de provider e `Route::prefix('api/v1')->middleware('api')`)  
**Requirement**: LNK-30, LNK-32, LNK-33, LNC-01…LNC-04, LNC-05, LNC-06, LNC-07, LNC-19

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] `POST /api/v1/links` com `auth.bearer` + `token.kind:session` responde `201` com headers e corpo corretos
- [ ] Alias em caixa mista normaliza; `slug_source` correto nos dois caminhos
- [ ] Alias ocupado → `409` idêntico para reserva com link e órfã; alias inválido **e** ocupado → `422`
- [ ] Exaustão de geração → `503 SLUG_GENERATION_FAILED` com `Retry-After`
- [ ] `401 UNAUTHENTICATED`, `403 TOKEN_RESTRICTED`, `403 ACCOUNT_SUSPENDED`, `403 ACCOUNT_PENDING_DELETION` cobertos
- [ ] Todo `422` não deixa linha em nenhuma das três tabelas
- [ ] Suítes de `modules/Links` registradas em `phpunit.xml` (Unit/Feature/Integration/Contract) e `<source>` inclui `modules/Links`
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥25 testes passam

**Tests**: e2e · **Gate**: full  
**Commit**: `feat(links): expose POST /api/v1/links`

---

### T14: Rate limit de criação

**What**: `ThrottleLinkCreation` + `LinkRateLimitKeyFactory` + alias em `bootstrap/app.php` + aplicação na rota.  
**Where**: `backend/modules/Links/Infrastructure/Http/Middleware/ThrottleLinkCreation.php`, `.../RateLimit/LinkRateLimitKeyFactory.php`, `backend/bootstrap/app.php`, `.../Http/routes/links.php` (modificar), `Tests/Feature/CreateLinkRateLimitTest.php`  
**Depends on**: T13  
**Reuses**: `ThrottlePrivateAuthWrite`, `HmacRateLimitKeyFactory`  
**Requirement**: LNK-34, LNC-20, LNC-21

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] 60 requisições passam e a 61ª retorna `429 RATE_LIMIT_EXCEEDED` com `Retry-After` ≥ 1
- [ ] Requisições que terminam em `422`/`409`/`503` consomem tentativa
- [ ] Requisição sem Bearer válido não consome cota de conta alguma
- [ ] Contas distintas têm contadores independentes
- [ ] Chave é HMAC; teste prova ausência de `user_id` em texto claro na chave
- [ ] Driver de rate limit indisponível → requisição segue (fail-open) e métrica é emitida
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥8 testes passam

**Tests**: e2e · **Gate**: full  
**Commit**: `feat(links): throttle link creation at 60 per minute per account`

---

### T15: Concorrência de alias equivalente

**What**: suíte de concorrência com duas conexões criando o mesmo alias em caixas diferentes.  
**Where**: `backend/modules/Links/Tests/Integration/CreateLinkConcurrencyTest.php`  
**Depends on**: T14  
**Reuses**: padrão de teste concorrente previsto em `docs/testing.md` §7 e na fatia slug-policy  
**Requirement**: LNC-15

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Exatamente um `201` e um `409` entre as duas transações concorrentes
- [ ] Uma única linha em `slug_reservations` e um único `short_link` para o slug
- [ ] A perdedora não altera nem remove a reserva vencedora
- [ ] A falha não revela o proprietário do slug vencedor
- [ ] Gate: `make test-backend`
- [ ] Test count: ≥4 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `test(links): cover concurrent creation of equivalent aliases`

---

### T16: Contract test do endpoint

**What**: `CreateLinkContractTest` validando request e respostas `201`/`409`/`422`/`429` contra `docs/openapi.yaml`.  
**Where**: `backend/modules/Links/Tests/Contract/CreateLinkContractTest.php`, `backend/modules/Links/Tests/Support/OpenApi/LinksOpenApiCatalog.php`  
**Depends on**: T14  
**Reuses**: `modules/Auth/Tests/Support/OpenApi/{OpenApiDocument,OpenApiSchemaAssert,AuthOpenApiCatalog}` (AD-016)  
**Requirement**: LNC-22

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Request valida contra `CreateLinkRequest`; `201` contra `LinkResponse` com os headers de `LinkCreated`
- [ ] `409`, `422` e `429` validam contra `LinkConflict`, `ValidationError` e `TooManyRequests`
- [ ] Corpo do `201` não contém propriedade fora de `LinkDetail` (`additionalProperties: false`)
- [ ] Gate: `make test-backend` e `make lint-openapi`
- [ ] Test count: ≥6 testes passam

**Tests**: e2e (contract) · **Gate**: full  
**Commit**: `test(links): assert create link endpoint against the openapi contract`

---

### T17: Telemetria e sentinela de redaction

**What**: métricas/logs de criação e de cada modo de falha com rótulos de baixa cardinalidade + teste sentinela de redaction.  
**Where**: `backend/modules/Links/Infrastructure/Telemetry/LinkCreationMetrics.php`, `Tests/Feature/CreateLinkTelemetryTest.php`  
**Depends on**: T16  
**Reuses**: padrão de telemetria e testes sentinela do Auth (`docs/testing.md` §6.7)  
**Requirement**: LNC-13, LNC-21

**Tools**: MCP: NONE · Skill: NONE

**Done when**:

- [ ] Contadores de sucesso e de cada falha (`alias_unavailable`, `slug_exhausted`, `validation_failed`, `rate_limited`, `infrastructure`)
- [ ] Nenhum rótulo contém slug, alias, `destination_url`, query, fragmento ou título
- [ ] Sentinela injeta valores marcadores e varre log, métrica e trace provando ausência
- [ ] Gate: `make lint` e `make test-backend-coverage` (≥90% linhas / ≥85% métodos em `modules/Links`)
- [ ] Test count: ≥8 testes passam

**Tests**: e2e · **Gate**: build + coverage  
**Commit**: `feat(links): record redacted creation telemetry`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 ──→ T2 ──→ T3
Phase 2:  T4 ──→ T5 ──→ T6
Phase 3:  T7 ──→ T8 ──→ T9
Phase 4:  T10 ─→ T11 ─→ T12 ─→ T13 ─→ T14
Phase 5:  T15 ─→ T16 ─→ T17
```

**Packing previsto no Execute:** 17 tarefas. Batches de ~7 tarefas em fronteira de fase: **batch 1 = fases 1+2 (6)**, **batch 2 = fases 3+4 (8)**, **batch 3 = fase 5 (3)** → 3 workers. Como o resultado é mais de um batch, o Execute **deve oferecer** sub-agents antes de despachar (offer-then-confirm). O Verifier roda automaticamente após T17.

---

## Task Granularity Check

| Task | Escopo | Status |
| --- | --- | --- |
| T1 | 1 config + 2 arquivos de env | ✅ Coeso |
| T2 | 2 docs, 1 código estável | ✅ Coeso |
| T3 | 1 método em 1 classe base | ✅ Granular |
| T4 | 1 serviço + 1 enum | ✅ Coeso |
| T5 | 1 serviço + 1 contrato | ✅ Coeso |
| T6 | 2 DTOs do mesmo caso de uso | ✅ Coeso |
| T7 | 1 repositório (contrato + impl + model + mapper) | ⚠️ 4 arquivos, uma responsabilidade — OK |
| T8 | 1 repositório | ⚠️ idem — OK |
| T9 | 1 UseCase | ✅ Granular |
| T10 | 1 FormRequest | ✅ Granular |
| T11 | 1 Resource + 1 factory | ✅ Coeso |
| T12 | 1 factory | ✅ Granular |
| T13 | 1 Controller + rota + provider + registro de suíte | ⚠️ Cohesivo por necessidade: o Controller não é testável sem rota e provider (merge backward) |
| T14 | 1 middleware + 1 key factory | ✅ Coeso |
| T15 | 1 suíte de concorrência | ✅ Granular |
| T16 | 1 contract test + 1 catálogo | ✅ Coeso |
| T17 | 1 componente de telemetria | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (corpo) | Diagrama mostra | Status |
| --- | --- | --- | --- |
| T1 | None | início da fase 1 | ✅ |
| T2 | None | T1 → T2 (ordem, não dependência) | ✅ ordem sequencial |
| T3 | None | T2 → T3 (ordem) | ✅ ordem sequencial |
| T4 | None | início da fase 2 | ✅ |
| T5 | T4 | T4 → T5 | ✅ |
| T6 | T4 | T5 → T6 (mesma fase, ordem) | ✅ dep. anterior satisfeita |
| T7 | T6 | fase 2 → fase 3 | ✅ |
| T8 | T7 | T7 → T8 | ✅ |
| T9 | T5, T6, T7, T8 | T8 → T9 | ✅ |
| T10 | T3, T6 | fases 1 e 2 → fase 4 | ✅ |
| T11 | T5, T6 | fase 2 → fase 4 | ✅ |
| T12 | None | ordem em T11 → T12 | ✅ |
| T13 | T9, T10, T11, T12 | T12 → T13; fase 3 → fase 4 | ✅ |
| T14 | T13 | T13 → T14 | ✅ |
| T15 | T14 | fase 4 → fase 5 | ✅ |
| T16 | T14 | T15 → T16 (ordem) | ✅ |
| T17 | T16 | T16 → T17 | ✅ |

Nenhuma tarefa depende de fase posterior. As setas do diagrama representam ordem de execução; toda dependência declarada tem seta correspondente ou antecedente na mesma fase.

---

## Test Co-location Validation

| Task | Camada criada/modificada | Matriz exige | Tarefa diz | Status |
| --- | --- | --- | --- | --- |
| T1 | Config / env | none | none | ✅ |
| T2 | Docs / OpenAPI YAML | none | none | ✅ |
| T3 | HTTP — Request (base) | unit | unit | ✅ |
| T4 | Domain | unit | unit | ✅ |
| T5 | Domain | unit | unit | ✅ |
| T6 | DTO (Domain-adjacente) | unit | unit | ✅ |
| T7 | Repository | integration | integration | ✅ |
| T8 | Repository | integration | integration | ✅ |
| T9 | UseCase | integration | integration | ✅ |
| T10 | HTTP — Request | unit | unit | ✅ |
| T11 | HTTP — Resource/Response | unit | unit | ✅ |
| T12 | HTTP — Response | unit | unit | ✅ |
| T13 | HTTP — Controller/rota | e2e | e2e | ✅ |
| T14 | HTTP — middleware | e2e | e2e | ✅ |
| T15 | UseCase/Repository (concorrência) | integration | integration | ✅ |
| T16 | Contrato OpenAPI | e2e (contract) | e2e (contract) | ✅ |
| T17 | HTTP + telemetria | e2e | e2e | ✅ |

Nenhuma violação. Nenhuma tarefa produz código não verificado: T13 absorve rota e provider (merge backward) para que o Controller seja testável na própria tarefa que o cria.

---

## Requirement Coverage

| Requirement | Tarefas |
| --- | --- |
| LNK-30 | T10, T13 |
| LNK-31 | T9 |
| LNK-32 | T5, T11, T13 |
| LNK-33 | T12, T13 |
| LNK-34 | T1, T14 |
| LNC-01…LNC-04 | T1, T4, T6, T7, T11, T13 |
| LNC-05…LNC-07 | T13 |
| LNC-08…LNC-10 | T7, T8, T9 |
| LNC-11, LNC-12 | T5 |
| LNC-13, LNC-14 | T2, T12, T13, T17 |
| LNC-15 | T15 |
| LNC-16…LNC-18 | T3, T10 |
| LNC-19…LNC-21 | T13, T14, T17 |
| LNC-22 | T16 |

**Coverage:** 27 requirement IDs, 27 mapeados a tarefas, 0 sem mapeamento ✅
