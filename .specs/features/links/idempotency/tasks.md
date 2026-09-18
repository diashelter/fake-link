# Links — Idempotência · Tasks

## Execution Protocol (MANDATORY — do not skip)

Implemente estas tarefas com a skill `tlc-spec-driven`, seguindo o fluxo de Execute e as Critical Rules. Cada tarefa exige testes co-localizados, gate aprovado e commit atômico. Após a última tarefa, um Verifier independente executa a validação ancorada na spec e o sensor de discriminação.

---

**Spec:** [spec.md](./spec.md)  
**Design:** [design.md](./design.md)  
**Status:** Aprovado — pronto para Execute

## Test Coverage Matrix

> Gerada do codebase, guidelines e spec. Guidelines encontradas: `AGENTS.md`, `docs/testing.md` §§3–7, `LARAVEL_CODE_DESIGN.md`, `Makefile`, `backend/phpunit.xml` e AD-009/011/016/021.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Domain (`ValueObjects`, canonicalização) | unit | Todos os ramos; 1:1 com ACs e limites do header/canonicalização | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| UseCase e transação | integration | Feliz, replay, conflito, rollback, expiração e falha de decrypt em PostgreSQL real | `backend/modules/Links/Tests/Integration/**/*Test.php` | `make test-backend` |
| Repository / cifra | integration | Constraints, persistência cifrada, query por expiração e erros | `backend/modules/Links/Tests/Integration/**/*Test.php` | `make test-backend` |
| Request / Response factory | unit | Validação, bytes, headers semânticos e novo request ID | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| Controller / rota | Feature | Happy path, `401`, `403`, `409`, `422`, `429`, `503` e sem efeitos colaterais | `backend/modules/Links/Tests/Feature/**/*Test.php` | `make test-backend` |
| Contrato OpenAPI | Contract | `IdempotencyKey`, `201`, `409`, `422` e headers contra a OpenAPI | `backend/modules/Links/Tests/Contract/**/*Test.php` | `make test-backend` |
| Scheduler | integration | Remoção exata, lote, repetição e execução concorrente | `backend/modules/Links/Tests/Integration/**/*Test.php` | `make test-backend` |
| Config / migration / docs | none | Gate de build e lint; migration criada com Artisan no container | `backend/database/migrations/`, `backend/config/`, `docs/` | `make lint` |

Cobertura do módulo Links: **≥90% linhas e ≥85% métodos** (`docs/testing.md` §4); todos os testes com banco usam somente `fake_link_testing` (AD-011).

## Gate Check Commands

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | Após Domain, HTTP unitário ou config | `make test-backend` |
| Full | Após repository, UseCase, rota, concorrência ou scheduler | `make test-backend` |
| Contract | Após alteração/validação de contrato | `make test-backend && make lint-openapi` |
| Build | Ao fim de cada fase | `make lint` |
| Coverage | Após T9 | `make test-backend-coverage` |

Todos os comandos são executados via Docker; nenhuma dependência nova é instalada.

---

## Execution Plan

### Phase 1: Persistência e primitivas

```text
T1 → T2 → T3
```

### Phase 2: Criação e replay transacionais

```text
T4 → T5 → T6
```

### Phase 3: Operação e verificação de fronteira

```text
T7 → T8 → T9
```

---

## Task Breakdown

### T1: Criar persistência de `idempotency_keys`

**What:** Criar a migration usando `php artisan make:migration` dentro do container backend, Model/mapper/repository Eloquent e os índices/constraint definidos no design.  
**Where:** `backend/database/migrations/`, `backend/modules/Links/{Contracts,Infrastructure/Persistence}/`  
**Depends on:** None  
**Reuses:** migrations e repositories de Links; `DestinationKeyring` como referência estrutural  
**Requirement:** LNK-41, LNK-42, LNI-01

**Tools:** MCP: Context7 · Skill: `tlc-spec-driven`  
**Done when:**
- [x] A migration possui `user_id`, hashes de 64 caracteres, `bytea` para snapshot, `key_id`, timestamps, unique `(user_id, key_hash)` e índice `expires_at`.
- [x] O valor bruto da chave não tem coluna, índice ou log.
- [x] Repository reserva/localiza/conclui/remove conforme contrato, preservando a transação do chamador.
- [x] Testes provam constraint, escopo por usuário, índice de expiração e persistência exclusivamente em `fake_link_testing`.
- [x] Gate: `make test-backend`.

**Tests:** integration · **Gate:** full  
**Commit:** `feat(links): persist encrypted idempotency records`

---

### T2: Implementar chave, fingerprint e cifra de snapshot

**What:** Implementar Value Object do header, canonicalização determinística, HMACs de finalidade distinta e cipher/keyring AES-256-GCM exclusivo de idempotência.  
**Where:** `backend/modules/Links/{Domain,Contracts,Infrastructure/Crypto}/`  
**Depends on:** T1  
**Reuses:** `DestinationKeyring`, `Aes256GcmDestinationCipher`, `ETagSigningKey`  
**Requirement:** LNK-40, LNK-41, LNK-42, LNI-06, LNI-09

**Tools:** MCP: Context7 · Skill: `tlc-spec-driven`  
**Done when:**
- [x] `IdempotencyKey` aceita somente 16–128 caracteres `[A-Za-z0-9._:-]+`.
- [x] JSON em ordem diferente, alias em caixa diferente, título com espaços e ausente/`null` canônico geram fingerprint igual.
- [x] Mudança normalizada de qualquer campo do payload gera fingerprint diferente.
- [x] Snapshot criptografado não contém corpo/headers/destino em texto claro e envelope adulterado falha fechado.
- [x] Testes unitários e de criptografia passam com `make test-backend`.

**Tests:** unit + integration · **Gate:** full  
**Commit:** `feat(links): canonicalize and protect idempotency commands`

---

### T3: Extrair a fronteira transacional da criação

**What:** Introduzir `TransactionManager` como Contract e refatorar `CreateLink` para participar de uma transação externa, sem mudar suas regras nem a resposta sem header.  
**Where:** `backend/modules/Links/{Contracts,Infrastructure,UseCases}/CreateLink.php`  
**Depends on:** T1  
**Reuses:** fluxo de `CreateLink` e `DB::transaction` do Laravel  
**Requirement:** LNI-01, LNI-03, LNI-08

**Tools:** MCP: Context7 · Skill: `tlc-spec-driven`  
**Done when:**
- [x] UseCase não importa facade Laravel nem inicia transação.
- [x] Adaptador `TransactionManager` faz rollback em exceção e o provider registra o binding.
- [x] A criação atual ainda confirma reserva, link e versão numa única transação.
- [x] Testes de rollback existentes permanecem verdes e novos testes provam que a transação externa inclui todas as três linhas.
- [x] Gate: `make test-backend`.

**Tests:** integration · **Gate:** full  
**Commit:** `refactor(links): expose link creation transaction boundary`

---

### T4: Implementar `CreateIdempotentLink`

**What:** Criar o UseCase que identifica replay/conflito ou reserva a chave, chama `CreateLink` e persiste snapshot apenas após o resultado `201`, tudo na única transação.  
**Where:** `backend/modules/Links/UseCases/CreateIdempotentLink.php` e DTOs relacionados  
**Depends on:** T1, T2, T3  
**Reuses:** `CreateLink`, `LinkETag`, repository e `TransactionManager`  
**Requirement:** LNK-43, LNK-44, LNI-01, LNI-04, LNI-07, LNI-08

**Tools:** MCP: Context7 · Skill: `tlc-spec-driven`  
**Done when:**
- [x] Mesmo comando ativo retorna resultado de replay sem chamar criação.
- [x] Fingerprint divergente retorna falha tipada mapeável para `409`.
- [x] Falha durante criação ou snapshot reverte chave, reserva, link e versão.
- [x] Registro expirado não faz replay/conflito e pode ser substituído atomically.
- [x] Testes com PostgreSQL real cobrem happy path, rollback, conflito, expiração e isolamento por usuário.
- [x] Gate: `make test-backend`.

**Tests:** integration · **Gate:** full  
**Commit:** `feat(links): create links through idempotent transaction`

---

### T5: Centralizar snapshot e resposta `201`

**What:** Fazer a factory produzir e consumir o snapshot semântico do `201`, preservando bytes e os headers de recurso, e deixando `X-Request-ID` por request.  
**Where:** `backend/modules/Links/Infrastructure/Http/Responses/`  
**Depends on:** T2, T4  
**Reuses:** `LinkResponseFactory`, `LinkDetailResource`  
**Requirement:** LNK-43, LNI-04, LNI-05

**Tools:** MCP: NONE · Skill: `tlc-spec-driven`  
**Done when:**
- [x] Criação e replay usam a mesma serialização de corpo UTF-8.
- [x] `Location`, `ETag` e `Cache-Control` são reproduzidos literalmente.
- [x] `X-Request-ID` não integra o snapshot e é aplicado na resposta corrente.
- [x] Testes unitários discriminam mutações no status, bytes e cada header semântico.
- [x] Gate: `make test-backend`.

**Tests:** unit · **Gate:** quick  
**Commit:** `refactor(links): replay idempotent creation responses exactly`

---

### T6: Integrar validação e rota HTTP

**What:** Validar o header no `CreateLinkRequest`, delegar o Controller ao novo UseCase e mapear conflito/decrypt failure às respostas estáveis existentes.  
**Where:** `backend/modules/Links/Infrastructure/Http/{Requests,Controllers,Responses}/`  
**Depends on:** T4, T5  
**Reuses:** `ApiFormRequest::errorCodes()`, `LinkErrorResponseFactory`, middlewares atuais da rota  
**Requirement:** LNK-40, LNK-43, LNK-44, LNI-02, LNI-03, LNI-07

**Tools:** MCP: NONE · Skill: `tlc-spec-driven`  
**Done when:**
- [ ] Header inválido retorna `422 INVALID_IDEMPOTENCY_KEY` sem escrita.
- [ ] Header ausente mantém a criação atual; chave válida faz criação/replay/conflito corretos.
- [ ] `409 IDEMPOTENCY_KEY_REUSED` e `503 SERVICE_UNAVAILABLE` não expõem entrada ou snapshot.
- [ ] Feature tests cobrem `401`, `403`, `422`, `429`, `201`, replay, `409` e `503`.
- [ ] Gate: `make test-backend`.

**Tests:** Feature · **Gate:** full  
**Commit:** `feat(links): expose idempotent link creation over HTTP`

---

### T7: Agendar limpeza de chaves expiradas

**What:** Criar command de limpeza em lotes, registrá-lo e agendá-lo com proteção contra sobreposição.  
**Where:** `backend/modules/Links/Infrastructure/Console/Commands/`, provider e `backend/routes/console.php`  
**Depends on:** T1  
**Reuses:** `IdempotencyKeyRepository`, `Schedule::command()->withoutOverlapping()`  
**Requirement:** LNI-10, LNI-11

**Tools:** MCP: Context7 · Skill: `tlc-spec-driven`  
**Done when:**
- [ ] Command remove somente registros com `expires_at <= now()` em lote configurado.
- [ ] Agenda executa a cada minuto e não sobrepõe instâncias.
- [ ] Duas execuções deixam o mesmo estado final e falha não apaga chaves válidas.
- [ ] Testes controlam relógio e provam limites antes/no instante/depois da expiração.
- [ ] Gate: `make test-backend`.

**Tests:** integration · **Gate:** full  
**Commit:** `feat(links): prune expired idempotency records`

---

### T8: Cobrir concorrência e privacidade

**What:** Criar a suíte de duas conexões para mesma chave e sentinelas de persistência/telemetria sem dados sensíveis.  
**Where:** `backend/modules/Links/Tests/{Integration,Feature}/` e telemetry do módulo se necessário  
**Depends on:** T6, T7  
**Reuses:** `CreateLinkConcurrencyTest`, testes sentinela de Auth  
**Requirement:** LNI-06, LNI-08, LNI-12

**Tools:** MCP: NONE · Skill: `tlc-spec-driven`  
**Done when:**
- [ ] Mesma chave/comando concorrentes criam um link; a segunda resposta é replay.
- [ ] Rollback da autora não deixa resíduo e permite nova execução.
- [ ] Snapshot adulterado retorna `503` sem segundo link, corpo parcial, `Location` ou `ETag`.
- [ ] Sentinelas não encontram chave, URL, título, corpo ou fingerprint em PostgreSQL em claro, logs, métricas ou traces.
- [ ] Gate: `make test-backend`.

**Tests:** integration + Feature · **Gate:** full  
**Commit:** `test(links): cover idempotency concurrency and privacy boundaries`

---

### T9: Validar contrato, arquitetura e cobertura

**What:** Completar contract tests da OpenAPI, regras Pest Arch e cobertura final da fatia.  
**Where:** `backend/modules/Links/Tests/{Contract,Architecture}/` e `docs/openapi.yaml` somente se houver divergência comprovada  
**Depends on:** T6, T8  
**Reuses:** `CreateLinkContractTest`, `Tests/Support/OpenApi`  
**Requirement:** LNK-40, LNK-43, LNK-44, LNI-01…LNI-12

**Tools:** MCP: NONE · Skill: `tlc-spec-driven`  
**Done when:**
- [ ] Contract tests validam parâmetro, `201` original/replay, `409`, `422` e headers declarados.
- [ ] Architecture tests provam Domain livre de Laravel e Controller sem persistência/transação.
- [ ] `make lint-openapi`, `make lint`, `make test-backend` e `make test-backend-coverage` passam, com Links em ≥90% linhas e ≥85% métodos.
- [ ] Nenhum teste é removido ou enfraquecido para aprovar os gates.

**Tests:** Contract + architecture · **Gate:** contract + build + coverage  
**Commit:** `test(links): verify idempotency contract and coverage`

---

## Phase Execution Map

```text
Phase 1 → Phase 2 → Phase 3

Phase 1: T1 → T2 → T3
Phase 2: T4 → T5 → T6
Phase 3: T7 → T8 → T9
```

**Packing previsto no Execute:** 9 tarefas; cada fase contém 3 tarefas. Como excede um batch, o Execute deve oferecer sub-agents antes de iniciar. O Verifier independente roda automaticamente depois de T9.

---

## Task Granularity Check

| Task | Escopo | Status |
| --- | --- | --- |
| T1 | Uma capacidade persistente | ✅ Coeso |
| T2 | Uma capacidade criptográfica/canônica | ✅ Coeso |
| T3 | Uma fronteira transacional | ✅ Coeso |
| T4 | Um UseCase | ✅ Granular |
| T5 | Uma fábrica de resposta/snapshot | ✅ Coeso |
| T6 | Uma integração HTTP | ✅ Coeso |
| T7 | Um command operacional | ✅ Granular |
| T8 | Uma suíte de resiliência e privacidade | ✅ Coeso |
| T9 | Uma camada de verificação de contrato | ✅ Coeso |

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | início | ✅ |
| T2 | T1 | T1 → T2 | ✅ |
| T3 | T1 | T2 → T3; T1 já antecede | ✅ |
| T4 | T1, T2, T3 | Phase 1 → Phase 2 | ✅ |
| T5 | T2, T4 | T4 → T5 | ✅ |
| T6 | T4, T5 | T5 → T6 | ✅ |
| T7 | T1 | Phase 1 → Phase 3 | ✅ |
| T8 | T6, T7 | T7 → T8 | ✅ |
| T9 | T6, T8 | T8 → T9 | ✅ |

## Test Co-location Validation

| Task | Code Layer | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | Repository/schema | integration | integration | ✅ |
| T2 | Domain/cipher | unit + integration | unit + integration | ✅ |
| T3 | UseCase transaction | integration | integration | ✅ |
| T4 | UseCase | integration | integration | ✅ |
| T5 | Response factory | unit | unit | ✅ |
| T6 | HTTP | Feature | Feature | ✅ |
| T7 | Scheduler | integration | integration | ✅ |
| T8 | Concurrency/privacy | integration + Feature | integration + Feature | ✅ |
| T9 | Contract/architecture | Contract + architecture | Contract + architecture | ✅ |

## Requirement Coverage

| Requirement | Tasks |
| --- | --- |
| LNK-40 | T2, T6, T9 |
| LNK-41 | T1, T2, T4, T8 |
| LNK-42 | T1, T2, T4, T8 |
| LNK-43 | T4, T5, T6, T7, T9 |
| LNK-44 | T4, T6, T9 |
| LNI-01…LNI-03 | T1, T3, T4, T6 |
| LNI-04…LNI-06 | T2, T4, T5, T8 |
| LNI-07…LNI-09 | T2, T4, T6, T8 |
| LNI-10…LNI-12 | T7, T8, T9 |

**Coverage:** 17 requisitos, 17 mapeados, 0 sem mapeamento ✅
