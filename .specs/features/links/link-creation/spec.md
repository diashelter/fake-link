# Links — Criação de link

**Status:** Fechada — confirmada 2026-08-30  
**Fatia:** 4 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-30 … LNK-34  
**Requirement IDs (fatia):** LNC-01 … LNC-22  
**Depende de:** [foundation](../foundation/spec.md), [slug-policy](../slug-policy/spec.md), [destination-policy](../destination-policy/spec.md)  
**Reutiliza:** middlewares `auth.bearer`, `token.kind:session` e o padrão `Throttle*` do módulo Auth; `HmacRateLimitKeyFactory`; `Tests/Support/OpenApi/*` (contract tests)

---

## Problem Statement

Esta é a primeira fatia vertical com endpoint do módulo `Links`: o proprietário autenticado cria um Short Link. Hoje existem (ou existirão, pelas fatias 1–3) a política de slug, a política de destino e o esquema persistente, mas nenhuma rota que os componha. A criação precisa reservar o slug, persistir o link e a primeira versão de destino na **mesma transação**, devolver `Location`, `ETag` forte e opaco e `LinkDetail`, e nunca deixar estado parcial — sem hard delete disponível depois para corrigir o que ficar errado.

## Goals

- [ ] `POST /api/v1/links` autenticado por token `session`, com payload fechado (`destination_url`, `custom_alias`, `title`, `expires_at`) e `additionalProperties: false`.
- [ ] Transação única: reserva do slug, `INSERT` do link e `INSERT` da primeira versão de destino confirmam ou revertem juntos.
- [ ] Resposta `201` com `Location`, `ETag` forte e opaco, `Cache-Control: private, no-store`, `X-Request-ID` e corpo `LinkDetail`.
- [ ] `409 ALIAS_UNAVAILABLE` para alias indisponível e `503 SLUG_GENERATION_FAILED` (com `Retry-After`) para exaustão de geração automática.
- [ ] Rate limit de criação de 60/min por conta, com `Retry-After` no `429`.
- [ ] Casos de fronteira de autenticação (`401 UNAUTHENTICATED`, `403 TOKEN_RESTRICTED`, `403 ACCOUNT_SUSPENDED`, `403 ACCOUNT_PENDING_DELETION`) cobertos no próprio caminho do endpoint.
- [ ] Contract test do endpoint contra `docs/openapi.yaml` (request, `201`, `409`, `422`, `429`).

## Out of Scope

Explicitamente excluído. Documentado para evitar scope creep.

| Item | Motivo |
| --- | --- |
| `Idempotency-Key`, replay e `409 IDEMPOTENCY_KEY_REUSED` | Fatia [idempotency](../idempotency/spec.md) — aqui o header é aceito e ignorado (ver Assumptions) |
| Listagem, detalhe e histórico | Fatias [link-queries](../link-queries/spec.md) e [destination-history](../destination-history/spec.md) |
| Edição, `If-Match`, `412`/`428` e no-op semântico | Fatia [link-update](../link-update/spec.md) |
| `DELETE` de link | Não existe endpoint (`docs/api.md` §4.1) |
| Regras internas de slug (normalização, allowlist, denylist, CSPRNG, reserva) | Fatia [slug-policy](../slug-policy/spec.md) — aqui só se compõe o resultado |
| Regras internas de destino (política de host, normalização, cifra AES-256-GCM, `key_id`) | Fatia [destination-policy](../destination-policy/spec.md) — aqui só se compõe o resultado |
| Migrations de `short_links`, `slug_reservations` e `link_destination_versions` | Fatia [foundation](../foundation/spec.md) |
| Invalidação de cache negativo do slug recém-criado | Fatia [redirect-cache](../redirect-cache/spec.md) (LNK-103) — não há redirect nem cache ainda |
| Publicação de evento de analytics | Fatia [click-publication](../click-publication/spec.md) |
| `400 MALFORMED_REQUEST`, `413 PAYLOAD_TOO_LARGE`, `405 METHOD_NOT_ALLOWED`, `500`, `504` | Camada global entregue no módulo Auth — esta fatia **assere** o comportamento, não o constrói |
| BFF, UI de criação e mensagens de erro em português | Pacote `bff-links/` futuro |

---

## Assumptions & Open Questions

Toda ambiguidade está resolvida ou registrada aqui — nada fica silenciosamente indefinido.

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Origem do `short_url` | Nova chave `links.short_url.base_url` em `backend/config/links.php`, lendo env dedicada `SHORT_URL_BASE`, com fallback `https://{SHORT_HOST}`. O Resource monta `{base_url}/{slug}` e **nunca** usa o host do request | Host da aplicação ≠ host curto (`docs/api.md` §1); derivar do request produziria `short_url` errado em qualquer chamada server-to-server. `SHORT_HOST` já é validada por `docker/scripts/validate-env.sh` | y |
| `429` de criação conta tentativas que falham com `422` | Sim. O limitador incrementa **antes** da validação, como middleware de rota, exatamente como os `Throttle*` do Auth. `409` e `503` também consomem tentativa | Payload inválido em laço também é abuso (`docs/security.md` §11); mantém o precedente do módulo Auth e evita bypass barato do limite | y |
| Composição do `ETag` | `HMAC-SHA256` com chave de aplicação sobre a tupla canônica `id`, `slug`, `destination_url` normalizada, `title`, `is_enabled`, `expires_at`, `blocked_at`, `updated_at` e `status` efetivo. Serializado como ETag **forte**: `"<hex>"` (casa `^"[^"]+"$`) | `docs/api.md` §4.5 exige opacidade, inclusão do estado efetivo e não exposição de `version`. Incluir o `status` derivado faz bloqueio e expiração invalidarem pré-condição; a chave HMAC impede que o cliente reconstrua o valor | y |
| Exaustão da geração de slug automático | `503` com código estável `SLUG_GENERATION_FAILED` e `Retry-After`; esta fatia é a dona do mapeamento HTTP e do acréscimo à OpenAPI e a `docs/api.md` §7 | Decisão já fechada em [slug-policy](../slug-policy/spec.md); o domínio expõe `SlugGenerationExhausted` e o endpoint mapeia | y |
| `Idempotency-Key` nesta fatia | O header é **aceito e ignorado**: não valida formato, não persiste, não faz replay e não produz `409 IDEMPOTENCY_KEY_REUSED`. Duas requisições idênticas com a mesma chave criam dois links | Manter a fatia 5 como dona única da semântica; rejeitar o header aqui criaria contrato que a OpenAPI já declara como opcional e que mudaria de significado na fatia seguinte. Registrado como divergência temporária conhecida do contrato | y |
| `title` vazio | `title` sofre `trim` ASCII; string vazia após o trim é persistida como `null`. O limite de 160 é medido em **caracteres** (não bytes) após o trim | Evita dois estados indistinguíveis para "sem título"; contagem por caractere é o que o usuário percebe e o que `varchar(160)` acomoda em UTF-8 | y |
| `title` ausente vs. `null` | Ambos produzem `title = null`; não há diferença observável na criação | O campo é anulável e não há semântica de patch nesta fatia | y |
| `expires_at` no limite | Comparação estrita contra `now()` UTC no instante da validação: `expires_at <= now()` é rejeitado, `expires_at > now()` é aceito. Apenas ISO 8601 com sufixo `Z` (padrão `NullableDateTimeUtc`) é aceito; offsets numéricos (`+00:00`) são inválidos | `docs/api.md` §2 e §4.4 e o `pattern` da OpenAPI; aceitar offsets exigiria conversão silenciosa e criaria ambiguidade de contrato | y |
| `custom_alias` ausente vs. `null` | Ausente → slug automático. `null` explícito é **inválido** (`custom_alias` não é anulável no `CreateLinkRequest`) | A OpenAPI declara só `title` e `expires_at` como anuláveis; aceitar `null` seria contrato mais largo que o documentado | y |
| Campo desconhecido no payload | `422 VALIDATION_FAILED` com entrada em `errors[campo]` e código `UNKNOWN_FIELD`; nunca é ignorado silenciosamente | `additionalProperties: false` na OpenAPI e precedente do registro no Auth (campos extras são inválidos) | y |
| Códigos de erro por campo | `destination_url`: `REQUIRED` / `INVALID_DESTINATION_URL`; `custom_alias`: `INVALID_ALIAS`; `title`: `TITLE_TOO_LONG`; `expires_at`: `INVALID_DATETIME` / `EXPIRES_AT_NOT_IN_FUTURE`; qualquer outro campo: `UNKNOWN_FIELD` | `docs/api.md` §2 exige `errors[field]` como array de `{code,message}`; `INVALID_DESTINATION_URL` já é o exemplo canônico da doc. A granularidade por **motivo** de rejeição de destino é decisão aberta da fatia [destination-policy](../destination-policy/spec.md); até lá o endpoint emite o código genérico | y |
| Motivo detalhado da falha de alias | A resposta de validação **não** revela qual regra falhou além de `INVALID_ALIAS`, e o `409` não distingue reserva com link de reserva órfã | `docs/testing.md` §6.3: indisponível é indisponível; diferenciar enumeraria o namespace. Os códigos internos de `slug-policy` ficam em domínio/log, não no corpo público |  y |
| Precedência entre `422` e `409` | Toda a validação de payload roda antes de qualquer escrita: alias sintaticamente inválido é `422` mesmo que também estivesse reservado; `409` só ocorre para alias válido e indisponível | Evita que a resposta revele ocupação do namespace para entradas que sequer passariam na validação | y |
| Formato do `Location` | Caminho relativo `/api/v1/links/{id}` | `format: uri-reference` na OpenAPI permite; caminho relativo não expõe host interno nem diverge entre ambientes | y |
| Identidade do proprietário | `user_id` vem exclusivamente da identidade autenticada do token; o payload não aceita `user_id` (cai em `UNKNOWN_FIELD`) | Ownership nunca é entrada do cliente (`docs/api.md` §2, precedente do Auth) | y |
| Tipo de token aceito | Somente `session` (`x-allowed-token-kinds: [session]`); token `verification` → `403 TOKEN_RESTRICTED` | `docs/openapi.yaml` para `createLink`; criar link não é operação de conta pendente | y |
| Estado inicial do link | `is_enabled = true`, `blocked_at = null`, `version = 1`, `slug_source` conforme o caminho (`custom` / `automatic`), `status` efetivo derivado (sempre `active`, pois `expires_at` é futuro ou nulo) | `docs/data-model.md` §4; o estado efetivo não é persistido, é derivado | y |
| Primeira versão de destino | `valid_from = now()` do commit, `valid_to = null`, `destination_url` cifrada e `key_id` fornecidos pela política de destino | `docs/data-model.md` §4 — índice parcial único garante uma única versão vigente | y |
| Escopo do rate limit | Chave por **conta** (`user_id`) via `HmacRateLimitKeyFactory`, 60 tentativas / 60 s, aplicada por middleware de rota antes do `FormRequest` | `docs/api.md` §8 e `docs/security.md` §11 (dimensão "Conta"); HMAC impede valor bruto em chave Redis | y |
| Ordem entre autenticação e rate limit | `auth.bearer` → `token.kind:session` → `throttle.links.create`. Request sem Bearer válido nunca consome cota de uma conta | O limite é por conta; sem identidade não há conta a limitar. Mesma ordem dos middlewares privados do Auth | y |
| Conteúdo observável de log/métrica | Contador de criação e de falha com rótulos de baixa cardinalidade (resultado, motivo); **nunca** slug, alias, `destination_url`, query, fragmento ou título | `docs/testing.md` §6.7 e `docs/security.md` §13 | y |
| Falha de infraestrutura na transação | PostgreSQL indisponível ou erro inesperado → `503 SERVICE_UNAVAILABLE` (ou `500 INTERNAL_ERROR` para falha não classificada), sem retentativa cega e sem estado parcial | `docs/api.md` §2 e §7; a transação garante ausência de link sem reserva ou sem destino | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions (fatia link-creation)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Payload fechado de 4 campos; `destination_url` obrigatória (≤2.048, política da fatia 3); `custom_alias` 3–48 pós-normalização (fatia 2); `title` ≤160 caracteres após trim, anulável; `expires_at` ISO 8601 `Z` estritamente futuro, anulável; campo extra → `UNKNOWN_FIELD` |
| Failure / partial-failure states | Reserva, link e primeira versão em uma única transação; qualquer falha reverte tudo. Alias indisponível → `409`; exaustão de geração → `503 SLUG_GENERATION_FAILED`; falha de infraestrutura → `503`/`500` sem estado parcial |
| Idempotency / retry / duplicate handling | Fora de escopo nesta fatia: header `Idempotency-Key` aceito e ignorado; duas requisições idênticas criam dois links (com slugs distintos). Semântica completa em [idempotency](../idempotency/spec.md) |
| Auth boundaries & rate limits | `auth.bearer` + `token.kind:session`; `401 UNAUTHENTICATED`, `403 TOKEN_RESTRICTED`, `403 ACCOUNT_SUSPENDED`, `403 ACCOUNT_PENDING_DELETION`. Rate limit 60/min por conta, contando toda tentativa autenticada, com `Retry-After` |
| Concurrency / ordering | Duas criações concorrentes do mesmo alias (em qualquer caixa) → exatamente um `201` e um `409`, decidido pela PK de `slug_reservations`; sem check-then-insert e sem sobrescrita |
| Data lifecycle / expiry | `expires_at` é só metadado do link nesta fatia (nenhum efeito de redirect ainda); reserva de slug é permanente; primeira versão de destino nasce com `valid_to = null` e é imutável |
| Observability | Métrica/log de criação e de cada modo de falha com rótulos de baixa cardinalidade; `X-Request-ID` em toda resposta e `request_id` em todo erro; nenhum dado sensível de destino, alias ou título |
| External-dependency failure | Dependências são PostgreSQL (transação) e Redis (contador de rate limit). PostgreSQL fora → `503` sem estado parcial. Redis fora → decisão explícita: **fail-open** no limite, request segue e emite métrica (ver AC de rate limit) |
| State-transition integrity | Link nasce em um único estado válido (`is_enabled=true`, `blocked_at=null`, `version=1`); `slug` e `user_id` são imutáveis a partir da criação; não há caminho de criação que produza link sem reserva ou sem versão vigente |

---

## Entregáveis técnicos

### Rota e HTTP (`backend/modules/Links/Infrastructure/Http/`)

| Artefato | Regra |
| --- | --- |
| `routes/links.php` | `Route::post('/links', CreateLinkController::class)->middleware(['auth.bearer', 'token.kind:session', 'throttle.links.create'])` |
| `Middleware/ThrottleLinkCreation` | 60 tentativas / 60 s, chave HMAC por `user_id`; `429 RATE_LIMIT_EXCEEDED` com `Retry-After`; incrementa antes da validação |
| `Requests/CreateLinkRequest` | Payload fechado; validação de presença, tipos, `title` (trim + 160), `expires_at` (formato `Z` + futuro estrito), delegação de alias e destino aos VOs; campos extras → `UNKNOWN_FIELD` |
| `Controllers/CreateLinkController` | Fino: monta DTO de entrada, chama o UseCase, mapeia falhas tipadas para `409`/`503` e devolve o Resource com `Location` e `ETag` |
| `Resources/LinkDetailResource` | Campos exatos de `LinkDetail` (sem `version`, sem analytics); `short_url` de `links.short_url.base_url`; `status` efetivo derivado |

### Aplicação e domínio (`backend/modules/Links/`)

| Artefato | Regra |
| --- | --- |
| `UseCases/CreateLink` | Valida destino, resolve o `Slug` (alias ou gerado), abre **uma** transação e persiste reserva + link + primeira versão; devolve DTO de saída |
| `DTOs/Input/CreateLinkInput` | `userId`, `destinationUrl`, `customAlias?`, `title?`, `expiresAt?` |
| `Domain/Services/EffectiveStatus` | Deriva `blocked` → `expired` → `inactive` → `active` a partir de `blocked_at`, `expires_at`, `is_enabled` |
| `Domain/Services/LinkETag` | `HMAC-SHA256` sobre a tupla canônica; devolve ETag forte `"<hex>"` |
| `Contracts/Repositories/ShortLinkRepository` | `create(...)` participando da transação corrente; sem update de `slug` nem de `user_id` |
| `Contracts/Repositories/DestinationVersionRepository` | `openFirstVersion(...)` com `valid_to = null` |
| `Exceptions` | Mapeamento de `SlugUnavailable` → `409 ALIAS_UNAVAILABLE` e `SlugGenerationExhausted` → `503 SLUG_GENERATION_FAILED` |

### Configuração e contrato

| Artefato | Regra |
| --- | --- |
| `backend/config/links.php` | `links.short_url.base_url` (env `SHORT_URL_BASE`, fallback `https://{SHORT_HOST}`); `links.rate_limits.create` = `{max_attempts: 60, decay_seconds: 60}` |
| `backend/.env.example` | Acrescenta `SHORT_URL_BASE=https://go.localhost` |
| `docs/openapi.yaml` | Acrescenta `503 SLUG_GENERATION_FAILED` como exemplo de `ServiceUnavailable` em `createLink`; demais respostas já declaradas |
| `docs/api.md` §7 | Acrescenta `SLUG_GENERATION_FAILED` à lista de códigos estáveis |
| `Tests/Contract/CreateLinkContractTest` | Valida request e respostas `201`/`409`/`422`/`429` contra `docs/openapi.yaml` via `Tests/Support/OpenApi` |

---

## User Stories

### P1: Criar link com slug automático ⭐ MVP

**User Story**: Como proprietário autenticado, quero enviar apenas a URL de destino e receber um link curto pronto para uso, para publicar o link imediatamente.

**Why P1**: É o caminho feliz do produto; sem ele o módulo não entrega valor nenhum.

**Acceptance Criteria**:

1. WHEN um token `session` válido envia `POST /api/v1/links` com apenas `destination_url` válida THEN o sistema SHALL responder `201` com header `Location: /api/v1/links/{id}`, header `ETag` no formato `"<hex>"`, `Cache-Control: private, no-store` e `X-Request-ID`.
2. WHEN a criação é bem-sucedida THEN o corpo SHALL ser `{"data": {...}}` contendo exatamente os campos de `LinkDetail` (`id`, `slug`, `short_url`, `destination_url`, `title`, `slug_source`, `is_enabled`, `status`, `expires_at`, `created_at`, `updated_at`), e SHALL NOT conter `version`, `blocked_at`, `user_id` ou qualquer campo de analytics.
3. WHEN nenhum `custom_alias` é enviado THEN o `slug` retornado SHALL ter 8 caracteres `[a-z0-9]` e `slug_source` SHALL ser `automatic`.
4. WHEN o link é criado THEN `id` SHALL ser um UUID v7, `is_enabled` SHALL ser `true`, `status` SHALL ser `active`, `title` e `expires_at` SHALL ser `null` quando não enviados, e `created_at`/`updated_at` SHALL ser ISO 8601 UTC com sufixo `Z`.
5. WHEN o Resource monta `short_url` THEN o valor SHALL ser `{links.short_url.base_url}/{slug}`, e SHALL NOT derivar do host que atendeu à requisição.
6. WHEN o link é criado THEN a linha em `short_links` SHALL ter `user_id` igual ao dono do token, `blocked_at` nulo e `version = 1`, e o `user_id` SHALL NOT ser aceito do payload.

**Independent Test**: Feature test autenticado que cria um link com o payload mínimo e asserta status, headers, corpo exato e as linhas persistidas em `fake_link_testing`.

**Requirement IDs**: LNK-30, LNK-32, LNC-01, LNC-02, LNC-03, LNC-04

---

### P1: Criar link com alias personalizado ⭐ MVP

**User Story**: Como proprietário autenticado, quero escolher meu próprio alias, em qualquer caixa, para ter um link curto memorável e previsível.

**Why P1**: É o diferencial imediato do produto e a superfície onde a normalização precisa estar provada ponta a ponta.

**Acceptance Criteria**:

1. WHEN `custom_alias` é `"Architecture"` THEN o `slug` retornado SHALL ser `architecture`, `slug_source` SHALL ser `custom`, e `short_url` SHALL usar o valor normalizado.
2. WHEN `custom_alias` é enviado THEN a normalização SHALL ocorrer antes de validação, reserva e comparação, e a linha persistida em `short_links.slug` e `slug_reservations.slug` SHALL ser o valor minúsculo.
3. WHEN `custom_alias` viola qualquer regra da política de slug (comprimento, caracteres, fronteiras, hífens consecutivos ou palavra reservada) THEN o sistema SHALL responder `422 VALIDATION_FAILED` com `errors.custom_alias[0].code = "INVALID_ALIAS"`, e SHALL NOT revelar qual regra específica falhou.
4. WHEN `custom_alias` é enviado como `null` THEN o sistema SHALL responder `422` (`INVALID_ALIAS`), e SHALL NOT tratá-lo como ausência de alias.
5. WHEN o alias é válido e disponível THEN a reserva SHALL ser criada na mesma transação do link, e `slug_source` SHALL ser persistido como `custom`.

**Independent Test**: Feature test com alias em caixa mista e tabela de aliases inválidos; asserta slug normalizado, `slug_source`, código de erro uniforme e linhas persistidas.

**Requirement IDs**: LNK-30, LNC-05, LNC-06, LNC-07

---

### P1: Transação única de criação ⭐ MVP

**User Story**: Como sistema, quero que reserva, link e primeira versão de destino confirmem ou revertam juntos, para que nunca exista link sem destino, destino sem link ou reserva de um link que não nasceu.

**Why P1**: Sem hard delete, qualquer estado parcial é permanente e não tem caminho de correção pelo produto.

**Acceptance Criteria**:

1. WHEN a criação é bem-sucedida THEN SHALL existir exatamente uma linha em `slug_reservations`, uma em `short_links` e uma em `link_destination_versions`, todas confirmadas na mesma transação.
2. WHEN a primeira versão é inserida THEN SHALL ter `valid_from` igual ao instante da criação, `valid_to = null`, `destination_url` cifrada (envelope AES-256-GCM) e `key_id` preenchido, e SHALL NOT persistir a URL em texto claro.
3. WHEN a inserção da versão de destino falha THEN o link e a reserva daquela transação SHALL NOT persistir.
4. WHEN a inserção do link falha THEN a reserva daquela transação SHALL NOT persistir.
5. WHEN a transação sofre rollback por qualquer motivo THEN o sistema SHALL NOT retornar `201` e SHALL NOT deixar nenhuma das três linhas.
6. WHEN o PostgreSQL está indisponível durante a criação THEN o sistema SHALL responder `503 SERVICE_UNAVAILABLE` com `request_id`, e SHALL NOT expor detalhes da falha nem tentar retentativa cega.

**Independent Test**: Testes de integração em `fake_link_testing` que forçam falha em cada etapa (versão de destino, link) e asseram ausência total de linhas, mais o caminho feliz assertando as três linhas.

**Requirement IDs**: LNK-31, LNC-08, LNC-09, LNC-10

---

### P1: `ETag` forte e opaco na criação ⭐ MVP

**User Story**: Como cliente da API, quero receber já na criação o `ETag` que a atualização vai exigir, para conseguir editar o link sem uma leitura adicional.

**Why P1**: A fatia 7 exige `If-Match`; sem `ETag` estável emitido na criação, o fluxo de edição nasce quebrado.

**Acceptance Criteria**:

1. WHEN um link é criado THEN o header `ETag` SHALL ser forte (sem prefixo `W/`) e casar `^"[^"]+"$`.
2. WHEN o `ETag` é calculado THEN SHALL derivar de `id`, `slug`, `destination_url` normalizada, `title`, `is_enabled`, `expires_at`, `blocked_at`, `updated_at` e do `status` efetivo, via `HMAC-SHA256` com chave de aplicação.
3. WHEN o `ETag` é inspecionado THEN SHALL NOT conter, codificar de forma reversível ou permitir derivar `version`, `user_id` ou a URL de destino.
4. WHEN dois links diferem em qualquer campo da tupla canônica THEN seus `ETag` SHALL ser diferentes.
5. WHEN o mesmo estado de link é serializado duas vezes THEN o `ETag` SHALL ser idêntico (função determinística do estado, não do instante da resposta).

**Independent Test**: Teste unitário do serviço de `ETag` (determinismo, sensibilidade a cada campo da tupla, ausência de dado sensível) mais assert de formato no feature test de criação.

**Requirement IDs**: LNK-32, LNC-11, LNC-12

---

### P1: Alias indisponível e exaustão de slug ⭐ MVP

**User Story**: Como usuário, quero uma resposta clara e uniforme quando o alias que pedi não está disponível, para tentar outro sem descobrir nada sobre quem o possui.

**Why P1**: É a única resposta de conflito do endpoint e a superfície onde vazaria a ocupação do namespace.

**Acceptance Criteria**:

1. WHEN o alias normalizado já está reservado THEN o sistema SHALL responder `409` com `code = "ALIAS_UNAVAILABLE"`, `message` e `request_id`, e SHALL NOT criar link nem alterar a reserva existente.
2. WHEN o `409` é produzido THEN a resposta SHALL ser idêntica para reserva com link e para reserva órfã, e SHALL NOT conter proprietário, título, destino, `reserved_at` ou qualquer dado do ocupante.
3. WHEN o alias é sintaticamente inválido **e** também estaria indisponível THEN o sistema SHALL responder `422 VALIDATION_FAILED`, e SHALL NOT responder `409` (validação precede escrita).
4. WHEN a geração de slug automático esgota o orçamento de tentativas THEN o sistema SHALL responder `503` com `code = "SLUG_GENERATION_FAILED"` e header `Retry-After`, e SHALL NOT revelar quantas tentativas ocorreram nem qualquer slug candidato.
5. WHEN duas requisições concorrentes criam o mesmo alias em caixas diferentes THEN exatamente uma SHALL receber `201` e a outra SHALL receber `409 ALIAS_UNAVAILABLE`, e SHALL NOT existir mais de uma linha para aquele slug.

**Independent Test**: Feature test de alias já reservado (com link e órfão, comparando as respostas byte a byte), teste com fonte de slug determinística exaurida e teste de concorrência com duas conexões.

**Requirement IDs**: LNK-33, LNC-13, LNC-14, LNC-15

---

### P1: Validação do payload fechado ⭐ MVP

**User Story**: Como cliente da API, quero erros de validação previsíveis e por campo, para corrigir a requisição sem tentativa e erro.

**Why P1**: O payload é a superfície pública do endpoint e a OpenAPI o declara fechado; divergência aqui quebra o contract test e o BFF futuro.

**Acceptance Criteria**:

1. WHEN `destination_url` está ausente THEN o sistema SHALL responder `422 VALIDATION_FAILED` com `errors.destination_url[0].code = "REQUIRED"`.
2. WHEN `destination_url` viola a política de destino THEN o sistema SHALL responder `422` com `errors.destination_url[0].code = "INVALID_DESTINATION_URL"`, e a resposta SHALL NOT ecoar a URL enviada.
3. WHEN `title` tem mais de 160 caracteres após o trim THEN o sistema SHALL responder `422` com `errors.title[0].code = "TITLE_TOO_LONG"`; com exatamente 160 SHALL ser aceito.
4. WHEN `title` é `""` ou só espaços THEN o link SHALL ser criado com `title = null`.
5. WHEN `expires_at` não é nulo e é igual ou anterior a `now()` THEN o sistema SHALL responder `422` com `errors.expires_at[0].code = "EXPIRES_AT_NOT_IN_FUTURE"`; um instante estritamente futuro SHALL ser aceito.
6. WHEN `expires_at` não segue ISO 8601 UTC com sufixo `Z` (incluindo `+00:00`, data sem hora ou string livre) THEN o sistema SHALL responder `422` com `errors.expires_at[0].code = "INVALID_DATETIME"`.
7. WHEN o payload contém qualquer campo fora de `destination_url`, `custom_alias`, `title` e `expires_at` THEN o sistema SHALL responder `422` com `errors.{campo}[0].code = "UNKNOWN_FIELD"`, e SHALL NOT ignorar o campo silenciosamente.
8. WHEN qualquer `422` é produzido THEN SHALL NOT existir linha nova em `slug_reservations`, `short_links` ou `link_destination_versions`.

**Independent Test**: Feature test tabular cobrindo cada campo, cada código e os limites (160/161, `now()`/`now()+1s`), assertando ausência de escrita no banco.

**Requirement IDs**: LNK-30, LNC-16, LNC-17, LNC-18

---

### P1: Fronteiras de autenticação e rate limit ⭐ MVP

**User Story**: Como operador, quero que apenas contas ativas com token de sessão criem links, dentro de um limite por minuto, para que a rota não vire vetor de abuso.

**Why P1**: Lição recorrente do módulo Auth — toda rota autenticada precisa dos casos `401`, `TOKEN_RESTRICTED` e `ACCOUNT_*` provados no próprio caminho; e `docs/api.md` §8 exige o limite de 60/min desde a primeira entrega.

**Acceptance Criteria**:

1. WHEN a requisição não tem Bearer, ou o token é inválido, expirado ou revogado THEN o sistema SHALL responder `401` com `code = "UNAUTHENTICATED"`.
2. WHEN o Bearer é um token `verification` válido THEN o sistema SHALL responder `403` com `code = "TOKEN_RESTRICTED"`, e SHALL NOT criar link.
3. WHEN a conta está suspensa THEN o sistema SHALL responder `403 ACCOUNT_SUSPENDED`; WHEN está em exclusão THEN SHALL responder `403 ACCOUNT_PENDING_DELETION`.
4. WHEN a 61ª requisição autenticada da mesma conta ocorre dentro de 60 s THEN o sistema SHALL responder `429` com `code = "RATE_LIMIT_EXCEEDED"` e header `Retry-After` inteiro ≥ 1.
5. WHEN requisições da mesma conta falham com `422`, `409` ou `503` THEN cada uma SHALL consumir uma tentativa do limite (o contador incrementa antes da validação).
6. WHEN requisições sem Bearer válido chegam THEN SHALL NOT consumir cota de nenhuma conta (o limite é aplicado depois da autenticação).
7. WHEN duas contas distintas criam links no mesmo minuto THEN seus contadores SHALL ser independentes.
8. WHEN a chave de rate limit é montada THEN SHALL usar HMAC do identificador da conta, e SHALL NOT conter `user_id`, e-mail ou slug em texto claro em chave Redis, log ou métrica.
9. WHEN o Redis do rate limit está indisponível THEN o sistema SHALL processar a requisição (fail-open) e SHALL emitir métrica de falha do limitador, e SHALL NOT responder `500`.

**Independent Test**: Feature tests por caso de auth; teste de rate limit contando 60 sucessos + 1 bloqueio, um cenário só com `422`, um por conta distinta e um com Redis derrubado.

**Requirement IDs**: LNK-34, LNC-19, LNC-20, LNC-21

---

### P1: Conformidade com a OpenAPI ⭐ MVP

**User Story**: Como mantenedor, quero que o endpoint entregue seja validado contra `docs/openapi.yaml`, para que o contrato design-first não divirja da implementação já na primeira rota do módulo.

**Why P1**: `AD-016` já estabeleceu contract tests como gate; esta é a primeira rota de `Links` e define o padrão para as fatias 5–8.

**Acceptance Criteria**:

1. WHEN o contract test roda THEN o payload aceito SHALL validar contra `CreateLinkRequest` e a resposta `201` contra `LinkResponse` com os headers `Location`, `ETag`, `X-Request-ID` e `Cache-Control` declarados em `LinkCreated`.
2. WHEN o contract test roda THEN as respostas `409`, `422` e `429` SHALL validar contra `LinkConflict`, `ValidationError` e `TooManyRequests`.
3. WHEN esta fatia é entregue THEN `docs/openapi.yaml` e `docs/api.md` §7 SHALL documentar `SLUG_GENERATION_FAILED`, e `make lint-openapi` SHALL passar.
4. WHEN o corpo de `201` é validado THEN SHALL NOT conter propriedade fora do schema `LinkDetail` (`additionalProperties: false`).

**Independent Test**: `CreateLinkContractTest` em `backend/modules/Links/Tests/Contract/` usando `Tests/Support/OpenApi`, mais `make lint-openapi`.

**Requirement IDs**: LNK-30, LNK-32, LNC-22

---

## Edge Cases

- `custom_alias` `"ARCHITECTURE"` e `"architecture"` criados em sequência → o segundo recebe `409`, não um segundo recurso.
- `custom_alias` com 2 ou 49 caracteres → `422 INVALID_ALIAS` (limites da política de slug), nunca `409`.
- `custom_alias` igual a uma palavra da denylist, em qualquer caixa → `422 INVALID_ALIAS`.
- `custom_alias` com Unicode ou homoglifo (`аrchitecture` com `а` cirílico) → `422 INVALID_ALIAS`, sem transliteração.
- `title` com exatamente 160 caracteres → aceito; 161 → `TITLE_TOO_LONG`; 160 caracteres multibyte (acentos, emoji) → aceito (contagem por caractere).
- `title` com espaços nas extremidades → persistido sem eles; `"   "` → `null`.
- `expires_at` exatamente igual a `now()` → `EXPIRES_AT_NOT_IN_FUTURE`; `now() + 1s` → aceito.
- `expires_at` com `+00:00` em vez de `Z` → `INVALID_DATETIME`.
- `expires_at: null` explícito → aceito, link sem expiração.
- `custom_alias: null` explícito → `422 INVALID_ALIAS` (não equivale a ausência).
- Payload com `user_id`, `slug`, `is_enabled` ou `version` → `UNKNOWN_FIELD` para cada campo extra.
- Payload vazio `{}` → `422` com `destination_url` `REQUIRED`.
- JSON malformado → `400 MALFORMED_REQUEST` (camada global) sem tocar no banco.
- Corpo acima de 64 KiB → `413 PAYLOAD_TOO_LARGE` (camada global), sem consumir transação.
- `DELETE /api/v1/links/{id}` → `405 METHOD_NOT_ALLOWED`; não existe caminho de remoção.
- `Idempotency-Key` presente → aceito e ignorado nesta fatia; duas requisições idênticas criam dois links com slugs distintos (divergência conhecida, fechada na fatia 5).
- 5 colisões consecutivas de slug automático → `503 SLUG_GENERATION_FAILED` com `Retry-After`, sem link e sem reserva parcial.
- Falha de cifra do destino (keyring indisponível) → `503`, sem link, sem reserva e sem versão.
- Requisição concorrente da mesma conta no limite exato do rate limit → no máximo 60 tentativas processadas na janela.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| LNK-30 | P1: Slug automático / Alias / Validação / OpenAPI | `POST /api/v1/links` com payload fechado | Design | Pending |
| LNC-01 | P1: Slug automático | `201` com `Location`, `Cache-Control` e `X-Request-ID` | Design | Pending |
| LNC-02 | P1: Slug automático | Corpo `LinkDetail` exato, sem `version` nem campos internos | Design | Pending |
| LNC-03 | P1: Slug automático | `short_url` de `links.short_url.base_url`, nunca do host do request | Design | Pending |
| LNC-04 | P1: Slug automático | Estado inicial (`is_enabled`, `blocked_at`, `version`, `status`, ownership) | Design | Pending |
| LNC-05 | P1: Alias | Alias normalizado antes de validação, reserva e persistência | Design | Pending |
| LNC-06 | P1: Alias | Alias inválido → `422 INVALID_ALIAS` uniforme | Design | Pending |
| LNC-07 | P1: Alias | `slug_source` persistido conforme o caminho de criação | Design | Pending |
| LNK-31 | P1: Transação | Reserva, link e primeira versão na mesma transação | Design | Pending |
| LNC-08 | P1: Transação | Três linhas exatas no caminho feliz | Design | Pending |
| LNC-09 | P1: Transação | Rollback total em falha de qualquer etapa | Design | Pending |
| LNC-10 | P1: Transação | Primeira versão cifrada com `key_id` e `valid_to = null` | Design | Pending |
| LNK-32 | P1: `ETag` / OpenAPI | `201` com `Location`, `ETag` forte e `LinkDetail` | Design | Pending |
| LNC-11 | P1: `ETag` | Tupla canônica com estado efetivo, via HMAC | Design | Pending |
| LNC-12 | P1: `ETag` | Opacidade, determinismo e sensibilidade a cada campo | Design | Pending |
| LNK-33 | P1: Conflito | `409 ALIAS_UNAVAILABLE` | Design | Pending |
| LNC-13 | P1: Conflito | Resposta uniforme sem dados do ocupante | Design | Pending |
| LNC-14 | P1: Conflito | `503 SLUG_GENERATION_FAILED` com `Retry-After` | Design | Pending |
| LNC-15 | P1: Conflito | Concorrência: um `201` e um `409` | Design | Pending |
| LNC-16 | P1: Validação | Códigos estáveis por campo e motivo | Design | Pending |
| LNC-17 | P1: Validação | Limites de `title` e `expires_at` | Design | Pending |
| LNC-18 | P1: Validação | Campo desconhecido → `UNKNOWN_FIELD`; `422` não escreve | Design | Pending |
| LNK-34 | P1: Auth e limite | Rate limit de criação 60/min por conta | Design | Pending |
| LNC-19 | P1: Auth e limite | `401`, `TOKEN_RESTRICTED`, `ACCOUNT_SUSPENDED`, `ACCOUNT_PENDING_DELETION` | Design | Pending |
| LNC-20 | P1: Auth e limite | Contagem de toda tentativa autenticada e isolamento por conta | Design | Pending |
| LNC-21 | P1: Auth e limite | Chave HMAC e fail-open com métrica quando o Redis cai | Design | Pending |
| LNC-22 | P1: OpenAPI | Contract test do request e das respostas `201`/`409`/`422`/`429` | Design | Pending |

**Coverage:** 27 total, 0 mapped to tasks ⚠️ (Design pendente)

---

## Success Criteria

- [ ] `make lint`, `make lint-openapi` e `make test-backend` passam com o endpoint introduzido.
- [ ] Um usuário autenticado cria um link com e sem alias e recebe `201`, `Location`, `ETag` e `LinkDetail` válidos contra a OpenAPI.
- [ ] Nenhum teste consegue produzir link sem reserva, link sem versão de destino ou reserva sem link a partir deste endpoint.
- [ ] Nenhum teste consegue criar dois recursos para o mesmo alias, sequencial ou concorrentemente.
- [ ] Nenhuma resposta de erro contém a URL de destino enviada, o proprietário do alias ocupado ou o motivo específico de rejeição do alias.
- [ ] `destination_url` não aparece em texto claro em nenhuma linha de `link_destination_versions`, log, métrica ou trace.
- [ ] O 61º request autenticado da mesma conta em 60 s recebe `429` com `Retry-After`.
- [ ] Substituir o `ETag` por um valor constante faz os testes de sensibilidade falharem de forma determinística (sensor de discriminação preparado).
- [ ] Cobertura de `modules/Links/` referente a esta fatia ≥90% linhas / ≥85% branches (`docs/testing.md` §4).
- [ ] A fatia [idempotency](../idempotency/spec.md) pode iniciar envolvendo este UseCase sem alterar sua assinatura nem o schema das três tabelas.

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Lint + análise estática | `make lint` |
| Lint de contrato | `make lint-openapi` (Spectral, AD-016) |
| Testes backend | `make test-backend` — PostgreSQL `fake_link_testing` only (AD-011) |
| Cobertura | `make test-backend-coverage` — 90% linhas / 85% branches |
| Contract test | `backend/modules/Links/Tests/Contract/CreateLinkContractTest.php` |
| Concorrência | Suíte de criação concorrente do mesmo alias com duas conexões (`docs/testing.md` §7) |
| Arquitetura | Pest Arch: Controller sem regra de negócio; `Domain` sem `config()`/Eloquent; ausência de rota `DELETE` |
| Telemetria | Teste sentinela: alias, destino, query e fragmento ausentes de log, métrica e trace |

---

## Referências

| Documento | Uso |
| --- | --- |
| [Índice Links](../README.md) | Catálogo `LNK-XX` e ordem das fatias |
| [slug-policy](../slug-policy/spec.md) | `Slug`, gerador, reserva e falhas tipadas consumidas aqui |
| [destination-policy](../destination-policy/spec.md) | `DestinationUrl`, normalização e cifra consumidas aqui |
| [foundation](../foundation/spec.md) | Esquema de `short_links`, `slug_reservations` e `link_destination_versions` |
| `docs/api.md` §2, §4.1, §4.2, §4.4, §4.5, §7, §8 | Envelope, contrato do endpoint, `ETag`, códigos estáveis e limites |
| `docs/data-model.md` §4 | Estado inicial, precedência do estado efetivo e transação |
| `docs/architecture.md` §6.1 | Fluxo de criação |
| `docs/security.md` §11, §13 | Rate limiting por conta e redaction |
| `docs/testing.md` §6.3, §6.7, §7 | Casos obrigatórios, telemetria e concorrência |
| `docs/openapi.yaml` | `CreateLinkRequest`, `LinkCreated`, `LinkDetail`, `LinkConflict` |
| `.specs/features/auth/session-and-profile/spec.md` | Precedente de rota privada com auth boundaries e rate limit |
