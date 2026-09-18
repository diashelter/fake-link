# Links — Política de destino · Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/links/destination-policy/design.md`
**Spec**: `.specs/features/links/destination-policy/spec.md`
**Status**: Draft
**Pré-requisito de execução**: a fatia [foundation](../foundation/tasks.md) precisa estar **executada** — `DestinationUrl`, `LinksDomainException`, `DestinationCipher`, `EncryptedDestination`, `config/links.php` e `LinksServiceProvider` já existentes no repositório. Hoje `backend/modules/` contém apenas `Auth/`.

---

## Test Coverage Matrix

> Gerada do codebase, das guidelines do projeto e da spec — confirmar antes do Execute. Guidelines encontradas: `AGENTS.md`, `docs/testing.md` §3.1/§4/§6.3, `backend/phpunit.xml`, `backend/composer.json` (`test`, `test:coverage`, `quality`), `Makefile` (`test-backend`, `test-architecture`, `test-backend-coverage`, `lint`), `LARAVEL_CODE_DESIGN.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Domain Enums (`DestinationRejectionReason`) | unit | Todos os 12 valores com string estável; enum é exaustivo em relação aos motivos da política | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Domain Exceptions (`LinksDomainException`) | unit | Código público estável, mensagem fixa idêntica por motivo, ausência da URL na mensagem e no contexto | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Domain Services (`PublicHostClassifier`, `DestinationUrlPolicy`) | unit | Todos os ramos; **1:1 com os ACs**; um caso por motivo (12) + todo edge case listado na spec (2048/2049, `:00080`, `#`/`?` vazios, `xn--`, `mylocalhost.com`, host 253/254) | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Domain Value Objects (`DestinationUrl`) | unit | Construção só via política; `value()` sempre normalizado; propriedade de idempotência sobre a matriz de aceitos | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| UseCases (`SealDestinationUrl`) | unit | Caminho feliz com cipher real (round-trip) + rejeição com spy provando **zero** chamadas a `encrypt` + revalidação em chamada repetida | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| ServiceProviders / config wiring | feature | Resolução do container + `self_hosts` derivado de `SHORT_HOST` e `APP_URL` | `backend/modules/Links/Tests/Feature/**` | `make test-backend` |
| Regras de arquitetura | architecture | Nenhum caminho cifra sem política; `Domain` não importa `Infrastructure` | `backend/tests/Architecture/**` | `make test-architecture` |
| Observabilidade (gate de não-vazamento) | unit | Sentinela em query e fragmento com **zero** ocorrências em log, mensagem, contexto e trace — em aceitação e em rejeição | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| `composer.json` / `config/links.php` / `phpunit.xml` | none | — (build gate) | — | `make lint` |

**Nota de conformidade:** `docs/testing.md` §4 fixa **90% linhas / 85% métodos** para `Links`. PCOV não mede branches, então o gate é proxy de métodos — por isso a matriz exige **um caso por motivo de rejeição**, independentemente do número agregado.

**Nota de ambiente:** nenhuma task desta fatia tem I/O de banco. A suíte roda sem PostgreSQL e **sem rede** — a ausência de resolução DNS é requisito (LDST-01/AC14), não conveniência.

## Gate Check Commands

> Extraídas do `Makefile` e do `backend/composer.json` — confirmar antes do Execute. Todos os comandos rodam via Docker (AD-009); nunca no host.

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | Após tasks com testes unit apenas | `make test-backend` |
| Full | Após tasks com testes feature ou architecture | `make test-backend && make test-architecture` |
| Build | Após conclusão de fase ou tasks de config/dependência | `make lint && make test-backend-coverage` |

---

## Execution Plan

Fases ordenadas e sequenciais; tasks dentro de uma fase executam em ordem.

### Phase 1: Tipos, motivos e classificação de host

Peças puras, sem dependência entre si além do enum.

```
T1 → T2 → T3 → T4 → T5
```

### Phase 2: Cadeia de política e normalização

O coração da fatia. Construída em três camadas para que cada gate prove um bloco de regras.

```
T6 → T7 → T8 → T9
```

### Phase 3: Selagem, seam e gate de não-vazamento

```
T10 → T11 → T12
```

---

## Task Breakdown

### T1: Promover `league/uri` a dependência direta

**What**: acrescentar `"league/uri": "^7.8"` ao `require` de `backend/composer.json` (pacote já presente em `vendor/` como transitivo de `laravel/framework`, versão 7.8.1) e atualizar o lock sem mover outros pacotes.
**Where**: `backend/composer.json`, `backend/composer.lock`
**Depends on**: None
**Reuses**: `backend/vendor/league/uri` (7.8.1, já instalado)
**Requirement**: LDST-07

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] `league/uri` aparece em `require` com constraint `^7.8`
- [x] `composer.lock` atualizado **sem** mudança de versão de nenhum outro pacote (diff mostra apenas a promoção)
- [x] Gate check passa: `make lint && make test-backend-coverage` — verificado por partes (Gotcha 4: `lint-frontend` falha pré-existente `TS2353`); `lint-openapi`, `lint-backend`, `test-architecture` (17/17), `test-backend` (652/652), `test-backend-coverage` (Links 92.49%/91.09%) todos verdes

**Tests**: none (camada de dependência — build gate)
**Gate**: build
**Commit**: `build(links): promote league/uri to a direct dependency`

---

### T2: Criar o enum `DestinationRejectionReason`

**What**: enum backed string com os 12 motivos de rejeição definidos no design.
**Where**: `backend/modules/Links/Domain/Enums/DestinationRejectionReason.php`
**Depends on**: None
**Reuses**: `backend/modules/Auth/Domain/Enums/PasswordViolationCode.php` (padrão de enum de motivo)
**Requirement**: LDST-23

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] 12 cases com os valores exatos do design (`TOO_LONG` … `INVALID_PORT`)
- [x] Teste assere o conjunto completo de valores (falha se um case for removido ou renomeado)
- [x] Teste assere que nenhum valor contém dado variável (cardinalidade fixa)
- [x] Gate check passa: `make test-backend`
- [x] Test count: 2 testes passam (verificado: 654/654, +2 sobre a baseline de 652; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add DestinationRejectionReason enum`

---

### T3: Carregar o motivo em `LinksDomainException::invalidDestinationUrl`

**What**: alterar o named constructor para receber `DestinationRejectionReason` (e **não** a URL) e expor `reason()`, mantendo `errorCode` público `INVALID_DESTINATION_URL` e mensagem fixa.
**Where**: `backend/modules/Links/Exceptions/LinksDomainException.php` (modifica)
**Depends on**: T2
**Reuses**: `backend/modules/Auth/Exceptions/AuthDomainException.php` (estrutura) — **sem** replicar o parâmetro `$raw` não usado
**Requirement**: LDST-23, LDST-24

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] `invalidDestinationUrl(DestinationRejectionReason $reason): self`; assinatura **não aceita** string
- [x] `errorCode()` devolve `INVALID_DESTINATION_URL` para todos os motivos
- [x] Mensagem é exatamente `The destination URL is not allowed.` para todos os motivos (teste percorre os 12)
- [x] `reason()` devolve o motivo informado
- [x] Testes da fatia 1 que chamavam a assinatura antiga atualizados, sem enfraquecer asserção (`DestinationUrlTest.php:23`, `LinksDomainExceptionTest.php:9,41`)
- [x] Gate check passa: `make test-backend`
- [x] Test count: 4 testes passam (verificado: 656/656, +2 sobre a baseline de 654 — 2 testes existentes atualizados + 2 novos; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): carry rejection reason in LinksDomainException`

---

### T4: Criar `PublicHostClassifier`

**What**: serviço de domínio puro que devolve o motivo de rejeição de um host já normalizado, ou `null`.
**Where**: `backend/modules/Links/Domain/Services/PublicHostClassifier.php`
**Depends on**: T2
**Reuses**: `backend/modules/Auth/Domain/Services/PasswordPolicy.php` (forma de serviço de domínio puro)
**Requirement**: LDST-08, LDST-10, LDST-11, LDST-12, LDST-13

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] `reject(string $host): ?DestinationRejectionReason` na ordem: IP literal → sintaxe → uso especial → host próprio
- [x] `selfHosts` recebido por construtor (`list<string>`), nunca lido de `config()` internamente
- [x] Testes cobrem: IPv4 (`127.0.0.1`, `10.0.0.5`, `8.8.8.8`), IPv6 (`[::1]`, `[fd00::1]`), formas inteiras (`2130706433`, `0x7f.1`), sintaxe (`intranet`, `a..b.com`, `-a.com`, `a-.com`, label 63/64, total 253/254, `exa_mple.com`), uso especial (os 9 sufixos, em caixa mista), host próprio (exato, subdomínio, caixa mista) e **aceitos** (`example.com`, `xn--caf-dma.com`, `mylocalhost.com`, `internal-tools.com`)
- [x] Teste prova ausência de I/O: nenhuma chamada de resolução; suíte passa sem rede
- [x] Gate check passa: `make test-backend`
- [x] Test count: ≥24 testes passam (verificado: 42 testes no arquivo; 698/698 na suíte completa, +42 sobre a baseline de 656; sem deleções silenciosas)

**Achado registrado durante T5 (não bloqueia T1–T5, relevante para T7):** a ordem "especial → próprio" faz com que os self_hosts reais (`go.localhost`, `app.localhost`) — ambos subdomínios do sufixo especial `localhost` — sejam classificados como `SpecialUseHost`, não `SelfHost`, contradizendo o AC7 do spec.md (`go.localhost` deve reprovar com motivo `SELF_HOST`). Quem implementar T7 precisará resolver isso (ex.: checar host próprio antes de uso especial).

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add PublicHostClassifier for destination hosts`

---

### T5: Configurar `self_hosts` e registrar o classificador

**What**: acrescentar `destination.self_hosts` (derivado de `SHORT_HOST` e do host de `APP_URL`) em `config/links.php` e bindar `PublicHostClassifier` como singleton no provider.
**Where**: `backend/config/links.php` (modifica), `backend/modules/Links/ServiceProviders/LinksServiceProvider.php` (modifica), `backend/phpunit.xml` (env determinístico)
**Depends on**: T4
**Reuses**: padrão de bind de ports do `LinksServiceProvider` (fatia 1)
**Requirement**: LDST-13

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] `config('links.destination.self_hosts')` devolve os hosts de `SHORT_HOST` e `APP_URL`, em minúsculas, sem porta e sem duplicatas
- [x] `PublicHostClassifier` resolvido do container vem com a lista da config (provado por resolução via `ReflectionProperty`, não por boot bem-sucedido)
- [x] `phpunit.xml` fixa `SHORT_HOST` e `APP_URL` determinísticos
- [x] Teste assere que a lista não é vazia no ambiente de teste
- [x] Gate check passa: `make test-backend && make test-architecture`
- [x] Test count: 3 testes passam (verificado: 701/701 em `test-backend`, +3 sobre a baseline de 698; 17/17 em `test-architecture`; sem deleções silenciosas)

**Tests**: feature
**Gate**: full
**Commit**: `feat(links): derive destination self_hosts from environment`

---

### T6: Criar `DestinationUrlPolicy` com as checagens anteriores ao parser

**What**: serviço com trim de borda, limite de 2.048 na entrada crua, rejeição de bytes não-ASCII e de controle, e validação de percent-encoding — tudo **antes** de qualquer parse.
**Where**: `backend/modules/Links/Domain/Services/DestinationUrlPolicy.php`
**Depends on**: T3, T4
**Reuses**: `PasswordPolicy` (forma `reject()`/`normalize()`)
**Requirement**: LDST-02, LDST-04, LDST-06, LDST-09

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] Passos 1–4 do design implementados na ordem exata
- [x] Testes: 2.048 aceito / 2.049 rejeitado; whitespace de borda aparado (incluindo o caso de 2.050 com espaços que passa a caber, documentado na spec); `\t`/`\r`/`\n`/`\x7F` internos ⇒ `CONTROL_CHARACTER`; byte ≥`\x80` em host, path, query ou fragmento ⇒ `NON_ASCII_INPUT`; `%zz`, `%A`, `%` isolado ⇒ `INVALID_PERCENT_ENCODING`
- [x] Teste prova que a checagem de percent-encoding roda **antes** do parser (entrada `%` não vira `%25`)
- [x] Gate check passa: `make test-backend`
- [x] Test count: 20 testes passam (verificado: 725/725, +20 sobre a baseline de 705; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add pre-parse checks to DestinationUrlPolicy`

---

### T7: Acrescentar parse e regras de esquema, `userinfo`, host e porta

**What**: integrar `league/uri`, capturar `SyntaxError` como `MALFORMED_URL` (descartando a exceção original, que contém a URL), e aplicar esquema, `userinfo`, classificação de host e faixa de porta.
**Where**: `backend/modules/Links/Domain/Services/DestinationUrlPolicy.php` (modifica)
**Depends on**: T6
**Reuses**: `League\Uri\Uri` (T1), `PublicHostClassifier` (T4)
**Requirement**: LDST-01, LDST-05, LDST-07, LDST-08, LDST-10 … LDST-14

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] Passos 5–9 do design implementados na ordem exata
- [x] Testes: `ftp:`, `javascript:`, `data:`, `file:`, esquema ausente ⇒ `SCHEME_NOT_ALLOWED`; `u:p@`, `u@`, `@`, `:@` ⇒ `USERINFO_PRESENT`; `https://`, `http:///path`, `https://ho st.com/` ⇒ `MALFORMED_URL`; `:0` ⇒ `INVALID_PORT`; motivos de host delegados ao classificador — **SPEC_DEVIATION verificada empiricamente** (league/uri 7.8.1): `:65536` ⇒ `INVALID_PORT` como esperado, mas `:-1` (e qualquer porta não numérica, ex. `:abc`) ⇒ `MALFORMED_URL`, não `INVALID_PORT` — RFC 3986 define porta como `*DIGIT`, então `Uri::new()` já lança `SyntaxError` antes do passo 9 conseguir rodar; não há caminho até `INVALID_PORT` para esses casos sem parsear a autoridade por texto (proibido por LDST-07/AD-019). Contrato público (`422`) inalterado. Documentado em comentário `SPEC_DEVIATION` no código e nos testes
- [x] Teste assere que a mensagem do `SyntaxError` **não** é encadeada nem propagada (a URL não aparece em `getPrevious()` nem no trace)
- [x] Teste de ordem: entrada que viola duas regras devolve o motivo da regra anterior na cadeia
- [x] Gate check passa: `make test-backend`
- [x] Test count: 29 testes passam (verificado: 754/754, +29 sobre a baseline de 725; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): parse destination authority and apply host policy`

---

### T8: Acrescentar normalização e limite pós-normalização

**What**: reconstruir o valor canônico (minúsculas de esquema/host, remoção de ponto final e de porta padrão, path vazio ⇒ `/`, query/fragmento intactos) e revalidar o comprimento.
**Where**: `backend/modules/Links/Domain/Services/DestinationUrlPolicy.php` (modifica)
**Depends on**: T7
**Reuses**: normalização nativa de `league/uri` (esquema, host, porta padrão)
**Requirement**: LDST-03, LDST-15 … LDST-20

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] Passos 10–11 do design implementados
- [x] Testes de tabela entrada → valor normalizado esperado cobrindo **todos** os casos do design e das edge cases da spec: caixa mista, `:80`/`:443`/`:`/`:0443` removidas, `:8443` e `:00080` preservadas, path vazio ⇒ `/`, ponto final removido, `?`/`#` vazios preservados, `%2F` e `%C3%A1` intactos, `/a/./b/../c//d` não colapsado
- [x] Teste de propriedade: `normalize(normalize($x)) === normalize($x)` para toda entrada aceita da matriz
- [x] Teste: valor normalizado >2.048 ⇒ `TOO_LONG` (caso reachable construído com path vazio + query longa); e o caso de 2.048 que encolhe ao remover `:443` é aceito
- [x] Gate check passa: `make test-backend`
- [x] Test count: 32 testes passam (verificado: 786/786, +32 sobre a baseline de 754; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): normalize destination URLs without changing semantics`

---

### T9: Fazer `DestinationUrl` delegar à política

**What**: trocar as checagens inline da fatia 1 pela chamada à política, com a assinatura `fromString(string $raw, PublicHostClassifier $hosts)`.
**Where**: `backend/modules/Links/Domain/ValueObjects/DestinationUrl.php` (modifica)
**Depends on**: T8
**Reuses**: `EmailAddress` (forma de VO), `DestinationUrlPolicy` (T8)
**Requirement**: LDST-19, LDST-20, LDST-21

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] `fromString(string $raw, PublicHostClassifier $hosts): self`; construtor privado mantido
- [x] `value()` devolve **sempre** o valor normalizado; não existe acessor do valor cru (provado por teste de reflection sobre os métodos públicos)
- [x] Testes da fatia 1 (LFND-10) atualizados para a nova assinatura, **sem** enfraquecer asserção — e os casos que a fatia 1 documentava como "aceitos por ora" (IP literal, `localhost`) agora asserem rejeição
- [x] `equals()` compara valores normalizados
- [x] Gate check passa: `make test-backend`
- [x] Test count: 20 testes passam (verificado: 793/793, +7 sobre a baseline de 786 — arquivo reescrito de 13 para 20 testes; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): enforce full destination policy in DestinationUrl`

---

### T10: Criar o UseCase `SealDestinationUrl`

**What**: caminho único de string crua → `EncryptedDestination`, revalidando a política a cada chamada.
**Where**: `backend/modules/Links/UseCases/SealDestinationUrl.php`
**Depends on**: T9
**Reuses**: `DestinationCipher` + `EncryptedDestination` (fatia 1), estilo invocável de `Modules\Auth\UseCases`
**Requirement**: LDST-21, LDST-22

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] `__invoke(string $raw): EncryptedDestination` valida antes de cifrar
- [x] Teste de round-trip com cipher real: decifrar devolve o valor normalizado, incluindo query e fragmento
- [x] Teste com spy da porta: entrada rejeitada ⇒ **zero** chamadas a `encrypt`
- [x] Teste: duas chamadas seguidas executam a política **duas** vezes (revalidação por versão)
- [x] Teste: `key_id` devolvido é o `active_key_id` da config
- [x] Gate check passa: `make test-backend`
- [x] Test count: 7 testes passam (verificado: 800/800, +7 sobre a baseline de 793; sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add SealDestinationUrl use case`

---

### T11: Regra de arquitetura do portão de cifra

**What**: regra Pest Arch provando que a política é o único caminho até a cifra e que o domínio permanece limpo.
**Where**: `backend/tests/Architecture/ModularMonolithTest.php` (modifica)
**Depends on**: T10
**Reuses**: regras existentes de `ModularMonolithTest` (já enumeram `Links`)
**Requirement**: LDST-21, LDST-22

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] Regra: nenhuma classe fora de `Modules\Links\UseCases` e `Modules\Links\Infrastructure\Crypto` depende de `DestinationCipher` — ampliada para também permitir `Modules\Links\ServiceProviders`, que referencia a interface apenas para registrar o bind no container (nunca chama `encrypt()`/`decrypt()`); sem essa exceção a regra reprova o próprio `LinksServiceProvider` legítimo
- [x] Regra: `Modules\Links\Domain` não importa `Modules\Links\Infrastructure`
- [x] Mutação manual (import proibido em classe de teste temporária) prova que a regra **falha** quando deve — duas mutações separadas (uma por regra), ambas descartadas depois
- [x] Gate check passa: `make test-backend && make test-architecture`
- [x] Test count: 2 regras novas passam (verificado: 19/19 em `test-architecture`, 802/802 em `test-backend`, +2 sobre a baseline de 800; sem deleções silenciosas)

**Tests**: architecture
**Gate**: full
**Commit**: `test(links): assert destination cipher is reachable only through the policy`

---

### T12: Gate de não-vazamento do destino

**What**: suporte de sentinela e suíte transversal provando que URL, query e fragmento não aparecem em log, mensagem, contexto ou trace — em aceitação e em rejeição.
**Where**: `backend/modules/Links/Tests/Support/DestinationSentinel.php`, `backend/modules/Links/Tests/Unit/DestinationLeakTest.php`
**Depends on**: T10
**Reuses**: técnica de sentinela do gate Bearer da Fase 1 (`frontend/e2e/**`), `Log::fake`
**Requirement**: LDST-24

**Tools**:
- MCP: NONE
- Skill: NONE

**Done when**:
- [x] Helper gera token único e monta URLs com o token em host, path, query e fragmento
- [x] Varredura assere **zero** ocorrências do token em: registros de `Log::listen` (Laravel 13 não tem `Log::fake()`; `MessageLogged` + `Log::listen` é o spy nativo já usado em `Aes256GcmDestinationCipherTest`), `getMessage()`, `getTraceAsString()`, `getPrevious()` e no `EncryptedDestination` serializado em texto — **ampliado** para também cobrir `getTrace()` (array estruturado, não só sua forma de string): um vazamento real foi encontrado e corrigido durante esta task (ver nota abaixo)
- [x] Cobre os dois caminhos: destino aceito e cifrado, e destino rejeitado por cada um dos 12 motivos
- [x] Teste assere que dois motivos diferentes produzem exceção pública indistinguível (código e mensagem idênticos)
- [x] Gate check passa: verificado por partes (`make lint` composto falha em `lint-frontend`, desvio pré-existente e não relacionado — ver Handoff em `.specs/STATE.md`): `lint-openapi` (0 erros), `lint-backend` (Pint 333 arquivos, PHPStan 0 erros, PHPMD limpo), `test-architecture` (19/19), `test-backend` (816/816), `test-backend-coverage` (816/816, Links 91.25% linhas / 91.20% métodos — gate 90/85)
- [x] Test count: 14 testes passam (verificado: 816/816, +14 sobre a baseline de 802; sem deleções silenciosas)

**Achado e corrigido durante esta task**: `PHP\Exception::getTrace()`/`getTraceAsString()` capturam o valor **ao vivo** de todo argumento em toda frame ativa da call stack no momento em que a exceção é construída — não só da função imediata. Verificado empiricamente: sem correção, a URL crua de destino vazava por completo em `getTrace()` (e parcialmente, truncada em 15 caracteres por `zend.exception_string_param_max_len`, em `getTraceAsString()`) para **todos** os 12 motivos de rejeição, não só o caso `MALFORMED_URL` que a T7 já protegia. `DestinationUrlPolicy` agora canaliza todo `throw` por um novo método `fail()` que chama `ini_set('zend.exception_ignore_args', '1')` antes de construir a exceção — efeito por requisição (PHP-FPM reseta a cada requisição), fecha o vazamento para toda frame já na pilha de uma só vez, sem depender de cada chamador (presente ou futuro) redigir sua própria cópia. Verificado como determinante por mutação manual: revertendo a correção, os 12 testes de rejeição falham na asserção de `getTrace()`; restaurando, voltam a passar.

**Tests**: unit
**Gate**: build
**Commit**: `test(links): assert destination URLs never leak to logs or traces`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5
Phase 2:  T6 ──→ T7 ──→ T8 ──→ T9
Phase 3:  T10 ──→ T11 ──→ T12
```

Dependências reais (não apenas ordem de execução):

```
T1 ─────────────────────────┐
T2 ──→ T3 ──┐               │
T2 ──→ T4 ──┼──→ T6 ──→ T7 ─┴──→ T8 ──→ T9 ──→ T10 ──→ T11
       T4 ──→ T5                                 └────→ T12
```

**Packing previsto no Execute:** 12 tasks ⇒ mais de um batch de ~7. Fases inteiras: batch 1 = Phase 1 (5 tasks); batch 2 = Phase 2 + Phase 3 (7 tasks). A oferta de sub-agents é apresentada antes do primeiro dispatch — nunca automática.

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: promover dependência | 1 arquivo de manifesto | ✅ Granular |
| T2: enum de motivos | 1 enum | ✅ Granular |
| T3: motivo na exceção | 1 classe (modifica) | ✅ Granular |
| T4: classificador de host | 1 serviço | ✅ Granular |
| T5: config + bind | 2 arquivos coesos (config + provider) | ⚠️ OK — uma única unidade de wiring |
| T6: checagens pré-parser | 1 serviço (cria) | ✅ Granular |
| T7: parse e regras de autoridade | 1 serviço (modifica) | ✅ Granular |
| T8: normalização | 1 serviço (modifica) | ✅ Granular |
| T9: VO delega à política | 1 VO (modifica) | ✅ Granular |
| T10: UseCase de selagem | 1 UseCase | ✅ Granular |
| T11: regra de arquitetura | 1 arquivo de teste | ✅ Granular |
| T12: gate de não-vazamento | 1 helper + 1 suíte | ⚠️ OK — helper existe só para a suíte |

> T6, T7 e T8 tocam o **mesmo arquivo** em sequência. É deliberado: cada uma fecha um bloco de regras com seu próprio gate, e a alternativa (um único task "implementar a política") seria exatamente o task vago que este processo evita.

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (raiz) | ✅ Match |
| T2 | None | (raiz) | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | T2 | T2 → T4 | ✅ Match |
| T5 | T4 | T4 → T5 | ✅ Match |
| T6 | T3, T4 | T3 → T6, T4 → T6 | ✅ Match |
| T7 | T6 | T6 → T7 (e T1 disponível) | ✅ Match |
| T8 | T7 | T7 → T8 | ✅ Match |
| T9 | T8 | T8 → T9 | ✅ Match |
| T10 | T9 | T9 → T10 | ✅ Match |
| T11 | T10 | T10 → T11 | ✅ Match |
| T12 | T10 | T10 → T12 | ✅ Match |

Nenhuma task depende de task em fase posterior.

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | `composer.json` (dependência) | none | none | ✅ OK |
| T2 | Domain Enum | unit | unit | ✅ OK |
| T3 | Domain Exception | unit | unit | ✅ OK |
| T4 | Domain Service | unit | unit | ✅ OK |
| T5 | Config + ServiceProvider | feature | feature | ✅ OK |
| T6 | Domain Service | unit | unit | ✅ OK |
| T7 | Domain Service | unit | unit | ✅ OK |
| T8 | Domain Service | unit | unit | ✅ OK |
| T9 | Domain Value Object | unit | unit | ✅ OK |
| T10 | UseCase | unit | unit | ✅ OK |
| T11 | Regras de arquitetura | architecture | architecture | ✅ OK |
| T12 | Observabilidade (gate de não-vazamento) | unit | unit | ✅ OK |

**Nota sobre T12:** não é deferral de teste. Cada task de T3 a T10 já assere localmente a ausência da URL na sua própria mensagem de erro; T12 é o gate **transversal** de LDST-24, que só pode existir depois que todos os caminhos (aceito e os 12 motivos) estão implementados. Nenhuma task produz código sem teste próprio.

---

## Tools e Skills

Nenhuma task desta fatia precisa de MCP ou Skill além do próprio `tlc-spec-driven`:

- **Context7 / web**: não necessário — o comportamento de `league/uri` foi verificado por execução direta no container, e está registrado na tabela de probe do `design.md`.
- **Docker**: obrigatório para todos os gates (AD-009).
- **Confirmar com o mantenedor** antes do Execute, conforme o passo 6 do processo de Tasks.
