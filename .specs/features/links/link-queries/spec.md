# Links — Consultas de link Specification

**Status:** Confirmada — 2026-09-18  
**Fatia:** 6 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-50 … LNK-56  
**Requirement IDs (fatia):** LNQ-01 … LNQ-12  
**Depende de:** [link-creation](../link-creation/spec.md)

---

## Problem Statement

Um `User` precisa localizar e consultar os próprios `Short Links` sem revelar recursos de outras contas ou expor `Destination` fora do detalhe autorizado. A lista deve ter paginação estável, filtros previsíveis e representação mínima; o detalhe deve fornecer o `ETag` necessário para a atualização concorrente da próxima fatia.

## Goals

- [ ] Expor `GET /api/v1/links` com cursor assinado, ordem fixa, busca e filtro por `Effective Status`.
- [ ] Expor `GET /api/v1/links/{link}` com `LinkDetail` autorizado e `ETag` forte e opaco.
- [ ] Preservar ownership por `404` uniforme, rate limit e confidencialidade de destinos.
- [ ] Manter OpenAPI, contract tests, testes de integração PostgreSQL e E2E aplicável sincronizados.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Histórico de `Destination Version` | Fatia [destination-history](../destination-history/spec.md) |
| Edição, `If-Match` e alteração de `ETag` | Fatia [link-update](../link-update/spec.md) |
| Analytics, métricas e totais de clique | Fase 3 |
| Busca por `Destination`, URL curta completa ou versão | `Destination` é cifrado e busca por URL não é suportada |
| BFF, dashboard e UI de filtros | Pacote futuro `bff-links/` |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- |
| Cursor | JSON versionado com `created_at` UTC e `id` UUID v7 da âncora, base64url e HMAC-SHA-256 por chave exclusiva de cursor | Permite paginação keyset sem aceitar adulteração | y |
| Escopo do cursor | Cursor inclui `search` e `status` normalizados; mudança desses filtros retorna `422 INVALID_CURSOR`. `per_page` pode mudar | Impede atravessar conjuntos de resultados distintos sem limitar tamanho da página | y |
| `search` | Espaços externos são removidos antes de validar 2–160 caracteres; vazio após normalização é inválido | Define limites inequívocos e evita consulta ambígua | y |
| Tempo | A consulta captura um único `now()` UTC e deriva todos os estados contra ele no PostgreSQL | Lista, filtro e detalhe ficam coerentes em uma resposta | y |
| Busca | OR entre substring de `title` e prefixo de `slug`, case-insensitive e accent-sensitive | Semântica já declarada em OpenAPI | y |
| Índices | Esta fatia instala `pg_trgm` e índice GIN do título, mais índices de ordenação/prefixo | São suporte necessário à consulta, não à criação predecessora | y |
| `ETag` | Reutiliza o mesmo componente da criação | Evita algoritmo ou segredo divergente antes da atualização | y |
| Decriptação | A lista não seleciona nem decifra destino; o detalhe o decifra só depois de ownership confirmado | Reduz custo e exposição | y |
| Falha de decriptação | Retorna `503 SERVICE_UNAVAILABLE`, sem corpo parcial | Não é seguro omitir/reconstruir seletivamente o destino | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | `per_page` inteiro 1–100, padrão 20; `search` normalizado 2–160; `status` limitado ao contrato; cursor malformado/adulterado/fora do escopo → `422 INVALID_CURSOR` |
| Failure / partial-failure states | Falha de PostgreSQL, keyring ou decriptação não devolve recurso parcial; mapeia para erros gerais documentados |
| Idempotency / retry | Consultas não alteram link, versão, reserva, `ETag` ou cursor persistido |
| Auth boundaries & rate limits | Bearer `session` ativo; ownership parte do principal autenticado; 300 leituras/min por token |
| Concurrency / ordering | Keyset por `(created_at DESC, id DESC)`; status derivado em único instante por request |
| Data lifecycle | Cursor é autocontido e não cria retenção; expiração muda somente o estado derivado |
| Observability | Métricas de baixa cardinalidade para list/detail/invalid_cursor/not_found/decrypt_failed, sem URL, slug, título, token, cursor ou destino |
| External-dependency failure | Sem fallback que revele destino ou contorne ownership |
| State-transition integrity | A fatia apenas lê `blocked` > `expired` > `inactive` > `active` |

---

## User Stories

### P1: Listar os links do proprietário ⭐ MVP

**User Story**: Como `User` autenticado, quero ver meus `Short Links` em páginas estáveis para encontrá-los e gerenciá-los.

**Why P1**: A listagem é a entrada de gestão do portfólio.

**Acceptance Criteria**:

1. WHEN um `User` ativo com token `session` envia `GET /api/v1/links` sem query THEN o sistema SHALL responder `200` com até 20 `LinkSummary` do proprietário, em ordem de `created_at` decrescente e `id` decrescente como desempate.
2. WHEN existem mais resultados THEN `meta` SHALL conter somente `next_cursor` não nulo e `per_page`; WHEN não existem THEN `next_cursor` SHALL ser `null`.
3. WHEN o cliente segue cursor válido com os mesmos filtros THEN SHALL receber itens estritamente posteriores à âncora, sem repetir item da página anterior.
4. WHEN `per_page` está ausente THEN SHALL usar 20; WHEN é inteiro 1–100 THEN SHALL devolver no máximo esse total e refletir o valor em `meta.per_page`; WHEN possui outro formato ou valor THEN SHALL responder `422 VALIDATION_FAILED`.
5. WHEN a lista é montada THEN cada item SHALL conter exatamente `id`, `slug`, `short_url`, `title`, `slug_source`, `is_enabled`, `status`, `expires_at`, `created_at` e `updated_at`; SHALL NOT conter `destination_url`, `ETag`, `version`, `blocked_at` ou `user_id`.
6. WHEN a lista é produzida THEN o sistema SHALL NOT decifrar nem selecionar `Destination`.

**Independent Test**: Feature, integration e contract tests percorrem páginas com dois proprietários, timestamps/IDs controlados, e verificam ordem, meta, ausência de duplicação, forma completa e ausência de acesso ao cipher.

**Requirement IDs**: LNK-50, LNK-51, LNQ-01, LNQ-02, LNQ-03

---

### P1: Pesquisar e filtrar por estado efetivo ⭐ MVP

**User Story**: Como `User` autenticado, quero filtrar meus links por texto e `Effective Status` para localizar rapidamente o item relevante.

**Why P1**: Um portfólio com múltiplos links não é navegável somente por criação.

**Acceptance Criteria**:

1. WHEN `search` normalizado tem 2–160 caracteres THEN a lista SHALL incluir link cujo título contém o texto ou cujo slug começa por ele, sem diferenciar caixa e diferenciando acentos.
2. WHEN `search` tem menos de 2, mais de 160 caracteres ou fica vazio após trim THEN SHALL responder `422 VALIDATION_FAILED`; WHEN ausente THEN SHALL não aplicar filtro textual.
3. WHEN `status` é ausente ou `all` THEN SHALL incluir todos os estados; WHEN é `active`, `inactive`, `expired` ou `blocked` THEN SHALL incluir apenas o estado derivado correspondente.
4. WHEN `status` possui outro valor THEN SHALL responder `422 VALIDATION_FAILED`.
5. WHEN status é derivado THEN SHALL aplicar `blocked`, `expired` para `expires_at <= now`, `inactive` para `is_enabled = false`, e `active` nos demais casos, no mesmo instante UTC da consulta.
6. WHEN `search` ou `status` muda THEN cursor anterior SHALL retornar `422 INVALID_CURSOR`; WHEN somente `per_page` muda THEN o cursor SHALL permanecer válido.

**Independent Test**: Testes tabelares e de integração cobrem OR título/slug, caixa, acentos, limites, estados, fronteira de expiração e escopo do cursor.

**Requirement IDs**: LNK-52, LNK-53, LNQ-04, LNQ-05, LNQ-06

---

### P1: Consultar o detalhe autorizado ⭐ MVP

**User Story**: Como `User` autenticado, quero abrir um `Short Link` meu com destino e `ETag` para visualizar o estado atual e iniciar edição segura.

**Why P1**: O detalhe habilita a gestão e estabelece a pré-condição da próxima fatia.

**Acceptance Criteria**:

1. WHEN o proprietário ativo com token `session` envia `GET /api/v1/links/{link}` para UUID v7 existente THEN SHALL responder `200` com `LinkDetail`, `Cache-Control: private, no-store`, `X-Request-ID` e `ETag` forte e opaco.
2. WHEN `LinkDetail` é retornado THEN `data` SHALL conter exatamente todos os campos de `LinkSummary` mais `destination_url`, e SHALL NOT conter `version`, `blocked_at` ou `user_id`.
3. WHEN detalhe de link recém-criado é consultado THEN seu `ETag` SHALL ser idêntico ao retornado na criação.
4. WHEN link não existe ou pertence a outro `User` THEN SHALL retornar a mesma resposta `404 RESOURCE_NOT_FOUND`, sem decifrar destino.
5. WHEN ciphertext, nonce, tag, `key_id` ou chave do destino não puderem ser autenticados/decriptados após ownership THEN SHALL responder `503 SERVICE_UNAVAILABLE`, sem `data`, destino, `ETag` ou detalhe da falha.

**Independent Test**: Feature e contract tests verificam headers e todos os campos; testes com dois proprietários comparam `404`; integração adultera o envelope e prova `503` sem resposta parcial.

**Requirement IDs**: LNK-54, LNK-55, LNQ-07, LNQ-08, LNQ-09

---

### P1: Proteger a superfície de leitura ⭐ MVP

**User Story**: Como operador, quero que consultas privadas mantenham autenticação, limite e privacidade para que o portfólio e destinos não vazem.

**Why P1**: Listagem e detalhe carregam dados privados.

**Acceptance Criteria**:

1. WHEN endpoint recebe Bearer ausente, inválido ou expirado THEN SHALL responder `401 UNAUTHENTICATED`; WHEN recebe token `verification`, conta suspensa ou em exclusão THEN SHALL preservar o `403` aplicável, sem consultar links.
2. WHEN token `session` excede 300 leituras/min nesta fatia THEN SHALL responder `429 RATE_LIMIT_EXCEEDED` com `Retry-After`; tokens distintos SHALL ter contadores independentes.
3. WHEN cursor é vazio, malformado, adulterado, incompatível com seu escopo ou âncora THEN SHALL responder `422 VALIDATION_FAILED` com `errors.cursor[0].code = "INVALID_CURSOR"` e SHALL NOT consultar dados.
4. WHEN consultas ou falhas são instrumentadas THEN logs, métricas e traces SHALL NOT conter token, cursor, slug, título ou destino; comportamento da aplicação SHALL ter seam testável, enquanto a coleta externa SHALL ser verificada operacionalmente.

**Independent Test**: Feature tests cobrem tokens e rate limit; unit/feature tests exercitam cada cursor inválido e usam sentinelas na seam de telemetria. Coleta externa é verificação operacional explícita.

**Requirement IDs**: LNK-50, LNK-55, LNK-56, LNQ-10, LNQ-11, LNQ-12

---

## Edge Cases

- WHEN dois links possuem o mesmo `created_at` THEN UUID v7 decrescente SHALL decidir a posição.
- WHEN link expira entre páginas THEN cada request SHALL usar seu próprio `now()`; cursor preserva ordem, não congela estado.
- WHEN `title` é nulo THEN não casa busca, mas slug ainda pode casar.
- WHEN título é `ação` e busca `acao` THEN SHALL NOT casar; WHEN busca `AÇÃO` THEN SHALL casar.
- WHEN cursor aponta para âncora removida por operação futura THEN SHALL continuar válido pelos valores assinados.
- WHEN não há resultados THEN SHALL responder `200` com `data: []`, `next_cursor: null` e `per_page` efetivo.
- WHEN PostgreSQL está indisponível THEN SHALL retornar erro aplicável, nunca lista vazia ou `404`.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| LNK-50 | P1: Listar | Cursor assinado e ordem fixa | Design | ✅ Verified |
| LNK-51 | P1: Listar | Página e meta mínima | Design | ✅ Verified |
| LNK-52 | P1: Pesquisar | Busca por título e slug | Design | ✅ Verified |
| LNK-53 | P1: Pesquisar | Filtro e estado efetivo | Design | ✅ Verified |
| LNK-54 | P1: Detalhe | `LinkDetail` e `ETag` | Design | ✅ Verified |
| LNK-55 | P1: Detalhe/Proteção | Ownership por `404` | Design | ✅ Verified |
| LNK-56 | P1: Proteção | Cursor inválido | Design | ✅ Verified |
| LNQ-01–LNQ-12 | Todas | Critérios locais acima | Design | ✅ Verified |

**Coverage:** 19 total, 0 mapped to tasks ⚠️

---

## Success Criteria

- [ ] O proprietário percorre todos os links sem duplicação e sem receber recursos de outro `User`.
- [ ] Busca, filtros e expiração seguem os limites e a semântica especificada.
- [ ] `LinkSummary` nunca expõe/decifra destino; `LinkDetail` só o devolve ao proprietário com `ETag` consistente.
- [ ] Cursor inválido e acesso cruzado não revelam dados nem executam consulta de recurso.
- [ ] `make lint`, `make lint-openapi`, `make test-backend`, `make test-backend-coverage` e E2E aplicável de gestão de links passam.

---

## Referências

| Documento | Uso |
| --- | --- |
| [Índice Links](../README.md) | Catálogo, dependências e rate limit |
| [link-creation](../link-creation/spec.md) | Representação, `ETag` e predecessora |
| `docs/api.md` §1.1, §2, §4.2–§4.3, §7–§8 | Contrato, campos, paginação e erros |
| `docs/openapi.yaml` | Operações, parâmetros e schemas |
| `docs/data-model.md` §4, §9–§10 | Estado, índices, busca e cifra |
| `docs/security.md` §6–§8 | Bearer, ownership e destino |
| `docs/testing.md` §3–§7 | Testes, contrato, E2E, privacidade e cobertura |
