# Links — Idempotência Specification

**Status:** Confirmada — 2026-09-18  
**Fatia:** 5 de 13 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** LNK-40 … LNK-44  
**Requirement IDs (fatia):** LNI-01 … LNI-12  
**Depende de:** [link-creation](../link-creation/spec.md)

---

## Problem Statement

Um cliente pode concluir `POST /api/v1/links` no servidor, mas perder a resposta por falha de rede ou timeout. Repetir esse comando não pode criar um segundo link nem devolver uma representação reconstruída e potencialmente diferente. Esta fatia torna a criação idempotente quando o cliente fornece uma chave válida, preservando privacidade e consistência sob concorrência.

## Goals

- [ ] Aceitar e validar `Idempotency-Key` opcional em `POST /api/v1/links`.
- [ ] Associar chave, comando canônico e resposta original ao usuário dentro da transação de criação.
- [ ] Reproduzir a resposta original por 24 horas para uma repetição semântica do mesmo comando.
- [ ] Rejeitar reuso da chave para comando diferente com `409 IDEMPOTENCY_KEY_REUSED`, sem efeitos colaterais.
- [ ] Nunca persistir, registrar ou expor chave bruta, URL de destino, título ou snapshot em texto claro.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Idempotência em `PATCH` | A atualização usa `If-Match`; pertence à fatia [link-update](../link-update/spec.md) |
| Reconstruir resposta pelo estado atual do link | O contrato exige o snapshot original |
| Alterar regras de criação, slug ou destino | Pertence às fatias predecessoras |
| Chaves de integração, API keys ou idempotência cross-account | Pós-MVP |
| Retenção além de 24 horas ou recuperação manual de snapshots | Sem caso de produto ou contrato |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Operação coberta | Somente `POST /api/v1/links` | É a única escrita de Links entregue e a única operação com header no contrato | y |
| Ausência do header | Mantém a criação não idempotente atual | O header é opcional; clientes existentes não mudam de comportamento | y |
| Header inválido | `422 VALIDATION_FAILED`, `errors.Idempotency-Key[0].code = "INVALID_IDEMPOTENCY_KEY"`, antes de qualquer escrita | O cliente pode corrigir a entrada sem bloquear uma chave | y |
| Comando canônico | Inclui `POST`, template `/api/v1/links` e JSON UTF-8 determinístico do payload validado/normalizado: chaves lexicográficas; alias minúsculo; título trimado, vazio como `null`; data UTC estrita; opcionais ausentes como `null` | Ordem JSON, caixa de alias e ausência versus `null` não distinguem o mesmo comando | y |
| HMACs | `key_hash` e `request_fingerprint` são HMAC-SHA-256 em hexadecimal, com chaves de finalidade distintas e escopo do usuário | Evita lookup offline e reuso entre contas/finalidades | y |
| Concorrência | A primeira transação que reservar `(user_id, key_hash)` é autora; concorrentes aguardam commit, fazem replay se confirmar ou podem executar após rollback | Não introduz estado público “em progresso” e não deixa resíduo após falha | y |
| Headers do snapshot | Preservar `Location`, `ETag` e `Cache-Control`; gerar novo `X-Request-ID` a cada request | Preserva semântica do recurso sem reutilizar identificador de correlação | y |
| Falhas antes da Action | `400`, `401`, `403`, `413`, `422` e `429` não reservam a chave | Não há comando de criação concluído a recuperar | y |
| Falha dentro da Action | `409 ALIAS_UNAVAILABLE`, `503 SLUG_GENERATION_FAILED` e toda falha que reverte a criação também revertem a reserva idempotente; só `201` confirmado gera snapshot | O cliente pode corrigir alias ou repetir falha transitória | y |
| Expiração e limpeza | `expires_at = created_at + 24h`; após expirar a chave é nova. Scheduler remove expirados em lotes, mas atraso da limpeza não autoriza replay | TTL é contrato; remoção física é best-effort | y |
| Cifra do snapshot | Envelope AES-256-GCM com nonce único e `key_id`, em keyring exclusivo de idempotência | O snapshot contém destino e outros dados privados | y |
| Falha de decrypt | `503 SERVICE_UNAVAILABLE`, sem resposta parcial nem nova criação; telemetria sanitizada | Reconstruir pode duplicar efeito e responder parcialmente viola o contrato | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Header opcional de 16–128 caracteres ASCII em `[A-Za-z0-9._:-]+`; inválido → `422` sem escrita |
| Failure / partial-failure states | Chave, reserva, link, versão e snapshot confirmam ou revertem juntos; decrypt falho → `503` |
| Idempotency / retry / duplicate handling | Mesmo usuário, chave e comando canônico por 24 h → replay; fingerprint divergente → `409`; sem header → fluxo atual |
| Auth boundaries & rate limits | Auth, tipo de token, estado da conta e rate limit precedem a reserva; replay não consome nova cota |
| Concurrency / ordering | Constraint `(user_id, key_hash)` e transação PostgreSQL elegem autora; concorrente aguarda e reexecuta só após rollback |
| Data lifecycle / expiry | Expiração lógica exata em 24 h; limpeza em lote idempotente |
| Observability | Métricas de baixa cardinalidade: `created`, `replayed`, `conflict`, `expired`, `decrypt_failed`, `cleanup_failed`; sem valores sensíveis |
| External-dependency failure | PostgreSQL indisponível falha sem estado parcial; snapshot ilegível retorna `503`, sem fallback reconstrutivo |
| State-transition integrity | Snapshot existe somente com criação `201` confirmada; fingerprint e snapshot nunca mudam após commit |

---

## User Stories

### P1: Criar link com proteção idempotente ⭐ MVP

**User Story**: Como cliente autenticado, quero repetir com segurança a criação de um link após perder a resposta, para não gerar links duplicados.

**Why P1**: Criação é uma mutation exposta a falhas de transporte.

**Acceptance Criteria**:

1. WHEN um usuário com token `session` envia uma criação válida com `Idempotency-Key` válida THEN o sistema SHALL criar exatamente um link e um registro `idempotency_keys` na mesma transação.
2. WHEN a transação confirma THEN o registro SHALL conter `user_id`, `key_hash`, `request_fingerprint`, `response_snapshot`, `key_id`, `created_at` e `expires_at = created_at + 24 hours`; SHALL NOT conter chave bruta.
3. WHEN o header está ausente THEN o sistema SHALL manter a criação não idempotente e SHALL NOT criar registro `idempotency_keys`.
4. WHEN o header possui menos de 16, mais de 128 caracteres ou caractere fora de `[A-Za-z0-9._:-]+` THEN o sistema SHALL responder `422 VALIDATION_FAILED` com `INVALID_IDEMPOTENCY_KEY` e SHALL NOT persistir link, reserva, versão ou chave.
5. WHEN autenticação, tipo de token, estado da conta, rate limit ou validação do payload falham THEN o sistema SHALL preservar a resposta aplicável e SHALL NOT reservar a chave.

**Independent Test**: Feature e integration tests validam criação com/sem header, linha persistida em `fake_link_testing` e ausência completa de escrita nos erros anteriores à Action.

**Requirement IDs**: LNK-40, LNK-41, LNI-01, LNI-02, LNI-03

---

### P1: Reproduzir a resposta original ⭐ MVP

**User Story**: Como cliente autenticado, quero receber a resposta original ao repetir o mesmo comando, para recuperar o resultado perdido.

**Why P1**: Replay é recuperação de transporte, não nova leitura sujeita a mudanças futuras.

**Acceptance Criteria**:

1. WHEN o mesmo usuário repete dentro de 24 horas a mesma chave com o mesmo comando canônico após o primeiro `201` THEN o sistema SHALL responder `201` e SHALL NOT criar outro link, reserva ou versão.
2. WHEN uma resposta é reproduzida THEN status, bytes UTF-8 do corpo e valores de `Location`, `ETag` e `Cache-Control` SHALL ser idênticos aos da resposta original.
3. WHEN uma resposta é reproduzida THEN o sistema SHALL gerar novo `X-Request-ID` para a nova request.
4. WHEN o link tiver sido alterado depois por outro fluxo THEN o replay SHALL continuar a devolver o snapshot `201` original, sem reconstruir corpo ou `ETag`.
5. WHEN o snapshot é persistido THEN SHALL ser AES-256-GCM com `key_id` do keyring exclusivo, e destino, título, corpo e headers do snapshot SHALL NOT aparecer em texto claro na persistência, logs, métricas ou traces.

**Independent Test**: Integration test captura resposta inicial, altera fixture do recurso e comprova replay byte a byte dos artefatos persistidos, com novo request ID e apenas um link criado.

**Requirement IDs**: LNK-42, LNK-43, LNI-04, LNI-05, LNI-06

---

### P1: Distinguir reuso indevido e concorrência ⭐ MVP

**User Story**: Como cliente autenticado, quero saber quando reutilizo uma chave para outro comando, para corrigir a retentativa sem produzir efeito inesperado.

**Why P1**: Uma chave não pode representar dois comandos e retries podem ocorrer em paralelo.

**Acceptance Criteria**:

1. WHEN o mesmo usuário envia chave não expirada com fingerprint diferente THEN o sistema SHALL responder `409` com `code = "IDEMPOTENCY_KEY_REUSED"` e SHALL NOT criar, alterar ou excluir link, reserva, versão ou snapshot.
2. WHEN dois usuários usam a mesma chave bruta THEN cada um SHALL ter escopo independente e poderá criar seu próprio link.
3. WHEN dois requests concorrentes do mesmo usuário usam a mesma chave e comando canônico THEN exatamente um SHALL executar a criação; o outro SHALL aguardar e receber replay `201` se a autora confirmar.
4. WHEN a autora concorrente sofre rollback THEN SHALL NOT permanecer registro de idempotência, link, reserva ou versão daquela tentativa; o concorrente poderá executar o comando.
5. WHEN a comparação ocorre THEN diferenças apenas de ordem JSON, caixa de alias, espaços externos no título ou opcional ausente versus `null` SHALL produzir o mesmo fingerprint; diferença normalizada de destino, alias, título ou expiração SHALL produzir fingerprint diferente.

**Independent Test**: Testes unitários tabelares de canonicalização/HMAC e integração com duas conexões PostgreSQL provam autora única, replay concorrente, isolamento entre usuários, rollback limpo e `409`.

**Requirement IDs**: LNK-41, LNK-44, LNI-07, LNI-08, LNI-09

---

### P2: Expirar e limpar registros idempotentes

**User Story**: Como operador, quero que snapshots expirem e sejam removidos, para limitar retenção de dados privados.

**Why P2**: A janela de replay é contrato de privacidade e custo; a correctness síncrona independe da limpeza física.

**Acceptance Criteria**:

1. WHEN `now() < expires_at` THEN o registro SHALL continuar elegível para replay ou conflito.
2. WHEN `now() >= expires_at` THEN a chave SHALL ser tratada como nova e SHALL NOT reproduzir nem conflitar com o registro expirado, mesmo antes da limpeza.
3. WHEN o scheduler executa THEN SHALL remover somente registros com `expires_at <= now()` em lotes e SHALL ser seguro executar repetidamente ou concorrentemente.
4. WHEN a limpeza falha THEN SHALL emitir sinal sanitizado e SHALL NOT apagar registro não expirado nem afetar chave ainda válida.
5. WHEN a decriptação de snapshot não expirado falha THEN o sistema SHALL responder `503 SERVICE_UNAVAILABLE`, SHALL NOT criar outro link e SHALL registrar somente telemetria sanitizada.

**Independent Test**: Relógio controlado prova fronteiras antes/no instante/depois de 24 h; limpeza repetida e snapshot adulterado provam idempotência e falha segura.

**Requirement IDs**: LNK-43, LNI-10, LNI-11, LNI-12

---

## Edge Cases

- Chave válida de 16 ou 128 caracteres é aceita; 15 ou 129 é rejeitada.
- Espaço, quebra de linha, Unicode ou `%` no header resulta em `INVALID_IDEMPOTENCY_KEY`.
- Alias `"Architecture"` e `"architecture"` com restante normalizado igual fazem replay.
- Ordem diferente de propriedades JSON não cria segundo link.
- Request sem Bearer, token `verification`, conta suspensa ou `429` não bloqueia a chave para tentativa futura válida.
- Reuso após expiração cria registro novo e pode criar novo link; snapshot anterior não é acessível.
- Falha de criptografia, persistência ou commit nunca deixa snapshot utilizável sem criação confirmada.
- Envelope, `key_id`, nonce, tag ou ciphertext adulterado nunca produz corpo parcial, `Location` ou `ETag`.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| LNK-40 | P1: Proteção | Header opcional e formato restrito | Design | Pending |
| LNK-41 | P1: Proteção / Reuso | HMAC de chave e comando por usuário | Design | Pending |
| LNK-42 | P1: Replay | Snapshot cifrado e keyring dedicado | Design | Pending |
| LNK-43 | P1/P2: Replay / Expiração | Replay exato por 24 horas | Design | Pending |
| LNK-44 | P1: Reuso | `409 IDEMPOTENCY_KEY_REUSED` | Design | Pending |
| LNI-01 | P1: Proteção | Criação e registro transacionais | Design | Pending |
| LNI-02 | P1: Proteção | Ausência preserva comportamento atual | Design | Pending |
| LNI-03 | P1: Proteção | Erros prévios não reservam chave | Design | Pending |
| LNI-04 | P1: Replay | Status, corpo e headers originais | Design | Pending |
| LNI-05 | P1: Replay | Request ID novo no replay | Design | Pending |
| LNI-06 | P1: Replay | Cifra e redação | Design | Pending |
| LNI-07 | P1: Reuso | `409` sem efeito colateral | Design | Pending |
| LNI-08 | P1: Reuso | Isolamento e concorrência | Design | Pending |
| LNI-09 | P1: Reuso | Canonicalização determinística | Design | Pending |
| LNI-10 | P2: Expiração | Janela exata | Design | Pending |
| LNI-11 | P2: Expiração | Limpeza segura | Design | Pending |
| LNI-12 | P2: Expiração | Decrypt falha seguro | Design | Pending |

**Coverage:** 17 total, 0 mapped to tasks ⚠️

---

## Success Criteria

- [ ] Mesma chave e comando canônico criam no máximo um link por usuário em 24 horas.
- [ ] Replay devolve `201`, corpo e headers semânticos originais, com novo `X-Request-ID`.
- [ ] Comando normalizado diferente retorna `409 IDEMPOTENCY_KEY_REUSED` sem efeito colateral.
- [ ] Nenhum teste encontra chave bruta, URL, título ou snapshot em texto claro em PostgreSQL, logs, métricas ou traces.
- [ ] Concorrência PostgreSQL prova autora única, replay do concorrente e rollback sem resíduo.
- [ ] `make lint`, `make lint-openapi`, `make test-backend` e `make test-backend-coverage` passam para a fatia.

---

## Referências

| Documento | Uso |
| --- | --- |
| [Índice Links](../README.md) | Catálogo, dependências e ordem |
| [link-creation](../link-creation/spec.md) | Endpoint e transação predecessora |
| `docs/api.md` §2, §4.4, §7, §8 | Contrato HTTP, header, `409` e rate limit |
| `docs/openapi.yaml` | `IdempotencyKey`, `createLink`, `LinkCreated`, `LinkConflict` |
| `docs/data-model.md` §7, §9–§11 | Persistência, transação, cifra e retenção |
| `docs/security.md` §7, §13–§14 | Isolamento, redação, keyrings e criptografia |
| `docs/testing.md` §4–§7 | Contract tests, cobertura, privacidade e concorrência |
