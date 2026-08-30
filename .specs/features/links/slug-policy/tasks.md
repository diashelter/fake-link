# Links — Política de slug · Tasks

## Execution Protocol (MANDATORY — do not skip)

Implemente estas tasks com a skill `tlc-spec-driven`: **ative-a pelo nome e siga o fluxo de Execute e as Critical Rules dela.** Não procure os arquivos da skill por caminho de filesystem. A skill é a fonte de verdade do fluxo completo (ciclo por task, delegação a sub-agents, revisão de adequação, Verifier, sensor de discriminação).

**Se a skill não puder ser ativada, PARE e avise o usuário — não prossiga sem ela.**

---

**Spec**: `.specs/features/links/slug-policy/spec.md`  
**Design**: `.specs/features/links/slug-policy/design.md`  
**Status**: Draft — aguardando aprovação

> ⛔ **Bloqueio de pré-requisito**: nenhuma task abaixo pode começar antes de a fatia [foundation](../foundation/spec.md) estar implementada e verificada. Hoje `backend/modules/` contém apenas `Auth/`. A T1 começa conferindo esse pré-requisito.

---

## Test Coverage Matrix

> Gerada a partir do codebase, das diretrizes do projeto e da spec — confirmar antes do Execute. Diretrizes encontradas: `AGENTS.md` (linhas 38, 42–44), `docs/testing.md` §3.1 e §4, `.specs/STATE.md` (AD-009, AD-011), `backend/phpunit.xml`, `backend/composer.json` (`quality`, `test:coverage`), `Makefile`, `LARAVEL_CODE_DESIGN.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Domain — VO, enums, exceções, services (`Slug`, `SlugPolicy`, `SlugGenerator`) | unit | Todas as branches; 1:1 com os ACs da spec; **todo** edge case listado tem teste | `backend/modules/Links/Tests/Unit/*Test.php` | `make test-backend` |
| Contracts + adapters de serviço (`ConfigReservedSlugs`, `CsprngSlugSource`) | unit | Todas as branches + caminhos de erro; denylist e aleatoriedade injetadas, nunca globais | `backend/modules/Links/Tests/Unit/*Test.php` | `make test-backend` |
| Persistência — Model, Repository (`slug_reservations`) | integration | Caminhos de query principais + violação de constraint real em PostgreSQL + rollback | `backend/modules/Links/Tests/Integration/*Test.php` | `make test-backend` |
| UseCase com I/O (`ReserveSlug`) | integration | Happy path + colisão + exaustão + alias indisponível + concorrência | `backend/modules/Links/Tests/Integration/*Test.php` | `make test-backend` |
| Config PHP (`config/links.php`) | unit | Chaves, tipos e valores default asseridos (precedente `HashingConfigTest`) | `backend/modules/Links/Tests/Unit/*Test.php` | `make test-backend` |
| Regras arquiteturais | architecture | Ausência de caminho de remoção de reserva; `Domain` sem `config()`/Eloquent | `backend/tests/Architecture/*Test.php` | `make test-architecture` |
| Docs / STATE / índice | none | — (build gate) | — | `make lint` |

**Cobertura numérica da fatia**: 90% linhas / 85% branches — em PCOV, **métodos** substituem branches (`docs/testing.md` §4).

## Gate Check Commands

> Derivadas do `Makefile` e de `docs/testing.md` §4. AD-009: gates backend rodam **somente via Docker**, através de alvos `make`. O repositório não expõe alvo por suíte, então Quick e Full são o mesmo comando — é a granularidade real disponível, não um atalho.

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | Após tasks só com testes unitários | `make test-backend` |
| Full | Após tasks com testes de integração | `make test-backend` |
| Build | Ao fechar fase, e em tasks de config/docs/arquitetura | `make lint && make test-backend-coverage` |

---

## Execution Plan

Fases são ordenadas e executam sequencialmente; dentro de cada fase, as tasks executam em ordem.

### Phase 1: Domínio puro (sem I/O)

```
T1 → T2 → T3 → T4 → T5
```

### Phase 2: Política e geração

```
T6 → T7 → T8
```

### Phase 3: Persistência e reserva

```
T9 → T10 → T11
```

### Phase 4: Garantias transversais e fechamento

```
T12 → T13 → T14
```

**Packing previsto no Execute**: 14 tasks → 2 batches (Batch A = Phase 1 + Phase 2, 8 tasks; Batch B = Phase 3 + Phase 4, 6 tasks). Como isso passa de um batch, o Execute **deve oferecer** sub-agents antes de dispatch.

---

## Task Breakdown

### T1: Criar `config/links.php` com os parâmetros de slug

**What**: arquivo de configuração versionado com alfabeto, comprimentos, tetos de tentativa e denylist.  
**Where**: `backend/config/links.php` (novo), `backend/modules/Links/Tests/Unit/LinksSlugConfigTest.php`  
**Depends on**: None (após a foundation)  
**Reuses**: `backend/config/auth.php` (forma), `modules/Auth/Tests/Unit/HashingConfigTest.php` (padrão de teste de config)  
**Requirement**: SLG-12, LNK-14

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Pré-requisito conferido: `backend/modules/Links/` existe, `slug_reservations` migra e `phpunit.xml` inclui as suítes de Links — se faltar, PARAR e escalar
- [ ] `config/links.php` define `slug.length=8`, `slug.alphabet` Base36 minúsculo, `slug.min_alias_length=3`, `slug.max_alias_length=48`, `slug.max_collision_attempts=5`, `slug.max_denylist_discards=5`
- [ ] `slug.reserved_words` contém exatamente as 10 palavras da spec, todas minúsculas
- [ ] Teste assere cada chave, tipo e valor default
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 6 testes passam (sem deleções silenciosas)

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add slug policy configuration`

---

### T2: Criar enums `SlugSource` e `SlugRejectionReason`

**What**: enums de domínio para origem do slug e códigos estáveis de rejeição.  
**Where**: `backend/modules/Links/Domain/Enums/{SlugSource,SlugRejectionReason}.php`, `modules/Links/Tests/Unit/SlugEnumsTest.php`  
**Depends on**: None  
**Reuses**: `modules/Auth/Domain/Enums/UserStatus.php`, `PasswordPolicyRule`  
**Requirement**: SLG-07, SLG-08, SLG-11

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `SlugSource` expõe exatamente `automatic` e `custom` (backed string, alinhado ao `CHECK` de `short_links.slug_source`)
- [ ] `SlugRejectionReason` expõe exatamente `too_short`, `too_long`, `invalid_characters`, `invalid_boundary`, `consecutive_hyphens`, `reserved_word`
- [ ] Teste assere os casos e seus valores backed (falha se um caso for renomeado ou acrescentado)
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 4 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add slug source and rejection reason enums`

---

### T3: Criar exceções tipadas da política de slug

**What**: `SlugPolicyException` (com `SlugRejectionReason`), `SlugUnavailable` e `SlugGenerationExhausted`.  
**Where**: `backend/modules/Links/Exceptions/*.php`, `modules/Links/Tests/Unit/SlugExceptionsTest.php`  
**Depends on**: T2  
**Reuses**: `modules/Auth/Exceptions/AuthDomainException.php` (named constructors)  
**Requirement**: SLG-05, SLG-18

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `SlugPolicyException` carrega o `SlugRejectionReason` acessível programaticamente
- [ ] `SlugUnavailable` tem mensagem uniforme e **não** aceita nem expõe proprietário, `reserved_at`, título ou destino
- [ ] `SlugGenerationExhausted` não expõe contagem de tentativas nem candidatos na mensagem
- [ ] Teste assere que a mensagem de `SlugUnavailable` é idêntica independentemente do motivo de ocupação
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 6 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add typed slug policy exceptions`

---

### T4: Implementar o Value Object `Slug`

**What**: VO imutável com normalização ASCII e validação estrutural completa do alias, mais o factory do slug gerado.  
**Where**: `backend/modules/Links/Domain/ValueObjects/Slug.php`, `modules/Links/Tests/Unit/SlugTest.php`  
**Depends on**: T2, T3  
**Reuses**: `modules/Auth/Domain/ValueObjects/EmailAddress.php` (forma `final readonly`, construtor privado, `value()`, `equals()`)  
**Requirement**: LNK-12, LNK-13, SLG-07, SLG-08, SLG-09, SLG-10, SLG-16

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `fromCustomAlias()` aplica trim ASCII e lowercase por `strtr` com mapa explícito `A-Z`→`a-z` (**não** `strtolower`/`mb_strtolower`)
- [ ] Normalização ocorre antes de qualquer validação
- [ ] Valida comprimento 3–48, allowlist `[a-z0-9-]`, fronteiras alfanuméricas e ausência de hífens consecutivos, cada falha com seu `SlugRejectionReason`
- [ ] `fromGenerated()` aceita somente 8 caracteres `[a-z0-9]` e marca origem `automatic`
- [ ] VO é `final readonly`, sem `config()`, sem framework, sem `intl`; não existe setter nem `withSlug()`
- [ ] Testes 1:1 com os ACs de LNK-12/LNK-13 e **todos** os edge cases da spec: `"  Architecture  "`, 2/3/48/49 caracteres, `---`, `a-b`, `a--b`, `аdmin` (cirílico U+0430), `ADMÍN`, `İstanbul` (U+0130), `%61dmin`, `"my link"`, string vazia, caractere de controle
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 28 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add Slug value object with ASCII normalization`

---

### T5: Criar port `ReservedSlugs` e adapter `ConfigReservedSlugs`

**What**: contrato que expõe a denylist ao domínio, com adaptador lendo `config('links.slug.reserved_words')`.  
**Where**: `backend/modules/Links/Contracts/Services/ReservedSlugs.php`, `modules/Links/Infrastructure/Slug/ConfigReservedSlugs.php`, `modules/Links/Tests/Unit/ConfigReservedSlugsTest.php`  
**Depends on**: T1  
**Reuses**: `modules/Auth/Contracts/Services/InviteAllowlist.php` + `Infrastructure/Allowlist/JsonFileInviteAllowlist.php`  
**Requirement**: LNK-14, SLG-12

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `ReservedSlugs::contains(string $normalizedSlug): bool` definido no contrato
- [ ] Adapter indexa a lista em `array<string,true>` no construtor e aceita lista injetada, sem depender de `config()` quando recebida por parâmetro
- [ ] Comparação é de igualdade exata: `admin` → `true`; `admin-panel`, `myadmin`, `apis` → `false`
- [ ] Lista vazia é configuração válida (não lança)
- [ ] Teste com palavra fictícia injetada em runtime prova que a fonte é a configuração, não uma constante
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 8 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add reserved slugs port backed by config`

---

### T6: Implementar `SlugPolicy` como fábrica única de `Slug`

**What**: serviço de domínio que combina validação estrutural do VO com a denylist, nos dois fluxos.  
**Where**: `backend/modules/Links/Domain/Services/SlugPolicy.php`, `modules/Links/Tests/Unit/SlugPolicyTest.php`  
**Depends on**: T4, T5  
**Reuses**: `modules/Auth/Domain/Services/PasswordPolicy.php` (política de domínio com códigos estáveis)  
**Requirement**: LNK-14, SLG-11, SLG-13

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `fromCustomAlias()` e `fromGenerated()` são o único caminho de construção usado por gerador e UseCase
- [ ] Denylist é verificada **depois** da validação estrutural e **sobre** o valor normalizado
- [ ] `ADMIN` e `Admin` falham com `reserved_word`; `ADMÍN` falha com `invalid_characters` (a ordem importa e está testada)
- [ ] A mesma denylist se aplica a alias e a candidato gerado (SLG-13), com uma única implementação
- [ ] Nenhuma chamada a `config()` dentro de `Domain`
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 12 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add SlugPolicy as sole slug factory`

---

### T7: Criar port `RandomSlugSource` e adapter `CsprngSlugSource`

**What**: fonte de aleatoriedade criptográfica isolável, sem viés de módulo.  
**Where**: `backend/modules/Links/Contracts/Services/RandomSlugSource.php`, `modules/Links/Infrastructure/Slug/CsprngSlugSource.php`, `modules/Links/Tests/Unit/CsprngSlugSourceTest.php`  
**Depends on**: T1  
**Reuses**: `modules/Auth/Domain/Services/BearerTokenGenerator.php` (padrão de geração segura)  
**Requirement**: LNK-10, SLG-02

**Tools**: MCP: NONE · Skill: `context7` para confirmar a API de `random_int` se houver dúvida

**Done when**:
- [ ] `candidate(int $length, string $alphabet): string` sorteia cada posição com `random_int(0, strlen($alphabet) - 1)`
- [ ] Não usa `rand`, `mt_rand`, `uniqid`, `shuffle`, timestamp nem `%` sobre bytes brutos (verificável por grep no arquivo)
- [ ] 10.000 gerações: nenhuma repetição e os 36 símbolos do alfabeto todos alcançados
- [ ] Comprimento retornado é exatamente o solicitado
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 6 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add CSPRNG slug source`

---

### T8: Implementar `SlugGenerator` com descarte por denylist

**What**: gerador Base36 de 8 caracteres que descarta candidatos reservados sob teto próprio.  
**Where**: `backend/modules/Links/Domain/Services/SlugGenerator.php`, `modules/Links/Tests/Unit/SlugGeneratorTest.php`  
**Depends on**: T6, T7  
**Reuses**: `SlugPolicy` (T6), `RandomSlugSource` (T7)  
**Requirement**: LNK-10, SLG-01, SLG-03

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `generate()` retorna `Slug` com origem `automatic` e exatamente 8 caracteres `[a-z0-9]`
- [ ] Candidato na denylist é descartado e regerado, **sem** consumir orçamento de colisão (D2 do contexto)
- [ ] 5 descartes consecutivos por denylist ⇒ `SlugGenerationExhausted`
- [ ] Com fonte injetada determinística, o teste prova a sequência exata de descartes e a parada no teto
- [ ] Gerador não conhece repositório nem banco
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 9 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add Base36 slug generator with denylist discard`

---

### T9: Criar `SlugReservationModel`, factory e registro de `RefreshDatabase`

**What**: Eloquent Model da tabela `slug_reservations` (schema da foundation), factory de teste e registro do trait para a suíte de Integration de Links.  
**Where**: `backend/modules/Links/Infrastructure/Persistence/Eloquent/Models/SlugReservationModel.php`, `.../Factories/SlugReservationModelFactory.php`, `backend/tests/Pest.php` (modificar), `modules/Links/Tests/Integration/SlugReservationModelTest.php`  
**Depends on**: T4  
**Reuses**: `modules/Auth/.../Models/UserModel.php`, `UserModelFactory`, `backend/tests/Pest.php:19-27`  
**Requirement**: LNK-15, SLG-14

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Model aponta para `slug_reservations`, PK `slug` (string, não incrementing), sem `updated_at`
- [ ] `backend/tests/Pest.php` registra `RefreshDatabase` para `modules/Links/Tests/Integration` (se a foundation ainda não o fez)
- [ ] Teste de integração cria e lê uma reserva em `fake_link_testing` (AD-011)
- [ ] Model não expõe `delete()` habilitado por soft delete nem `SoftDeletes`
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 4 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): add slug reservation eloquent model`

---

### T10: Implementar port e repositório de reserva

**What**: `SlugReservationRepository` (sem método de remoção) e o adaptador Eloquent que traduz violação de unicidade em `SlugUnavailable`.  
**Where**: `backend/modules/Links/Contracts/Repositories/SlugReservationRepository.php`, `.../Eloquent/Repositories/EloquentSlugReservationRepository.php`, `modules/Links/Tests/Integration/SlugReservationRepositoryTest.php`  
**Depends on**: T3, T9  
**Reuses**: `EloquentUserRepository.php:85` (captura de `UniqueConstraintViolationException`)  
**Requirement**: LNK-15, LNK-16, SLG-06, SLG-14, SLG-15

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Port expõe somente `reserve(Slug): void` e `existsWithoutLink(Slug): bool` — **nenhum** método de remoção ou update
- [ ] `reserve()` faz `INSERT` sem `SELECT` prévio de disponibilidade (SLG-06)
- [ ] Violação de PK vira `SlugUnavailable`; a reserva existente permanece com o mesmo `reserved_at`
- [ ] `reserve()` participa da transação do chamador: teste com `DB::transaction` + rollback prova que a linha não persiste (SLG-14)
- [ ] `existsWithoutLink()` retorna `true` para reserva sem `short_link` e `false` quando há link
- [ ] PostgreSQL indisponível/erro genérico propaga sem virar `SlugUnavailable`
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 10 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): add permanent slug reservation repository`

---

### T11: Implementar `ReserveSlug` e registrar bindings

**What**: UseCase que orquestra alias vs. automático com o teto de 5 colisões, e os bindings dos três ports no provider do módulo.  
**Where**: `backend/modules/Links/UseCases/ReserveSlug.php`, `modules/Links/ServiceProviders/LinksServiceProvider.php` (modificar), `modules/Links/Tests/Integration/ReserveSlugTest.php`  
**Depends on**: T8, T10  
**Reuses**: `modules/Auth/ServiceProviders/AuthServiceProvider.php` (padrão de bindings)  
**Requirement**: LNK-11, SLG-04, SLG-05

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `forAlias()` reserva sem nenhuma retentativa e propaga `SlugUnavailable`
- [ ] `automatic()` tenta no máximo 5 `INSERT`s após colisão; a 6ª nunca ocorre
- [ ] Sucesso na 5ª tentativa retorna normalmente; sucesso na 1ª não gera candidatos extras (SLG-04)
- [ ] 5 colisões ⇒ `SlugGenerationExhausted` e nenhuma reserva parcial no banco
- [ ] Provider resolve `ReservedSlugs`, `RandomSlugSource` e `SlugReservationRepository` pelo container; teste assere as três resoluções
- [ ] Tetos vêm de `config('links.slug.*')`, não hardcoded no UseCase
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 11 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): add ReserveSlug use case with bounded retry`

---

### T12: Adicionar gates arquiteturais da política de slug

**What**: regras Pest Arch que provam ausência de caminho de remoção de reserva e pureza do `Domain`.  
**Where**: `backend/tests/Architecture/SlugPolicyBoundariesTest.php` (novo)  
**Depends on**: T11  
**Reuses**: `backend/tests/Architecture/ModularMonolithTest.php` (que já itera `Links` em `$domainModules`)  
**Requirement**: SLG-15, SLG-16

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Regra falha se `Modules\Links\Domain` usar `config()`, `Illuminate\Support\Facades\*` ou Eloquent
- [ ] Regra falha se qualquer arquivo de `Modules\Links` chamar `delete`, `forceDelete`, `truncate` ou `update` sobre `slug_reservations` / `SlugReservationModel`
- [ ] Regra falha se aparecer método público que altere o valor de um `Slug` já construído
- [ ] Sensor de discriminação registrado no cabeçalho do arquivo: qual mutação cada regra mata
- [ ] Gate passa: `make test-architecture` e `make lint`
- [ ] Test count: 4 regras arch passam

**Tests**: architecture · **Gate**: build  
**Commit**: `test(links): add architecture gates for slug reservation immutability`

---

### T13: Teste de concorrência entre aliases equivalentes

**What**: prova com duas conexões PostgreSQL de que aliases equivalentes por caixa têm um único vencedor.  
**Where**: `backend/modules/Links/Tests/Integration/SlugReservationConcurrencyTest.php`  
**Depends on**: T11  
**Reuses**: `modules/Auth/Tests/Integration/UsersPersistenceConstraintsTest.php` (padrão de constraint real)  
**Requirement**: LNK-12, LNK-15, SLG-17, SLG-18

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Duas conexões distintas reservam `Foo` e `foo` com transações sobrepostas
- [ ] Exatamente uma confirma; a outra recebe `SlugUnavailable`
- [ ] `slug_reservations` termina com **uma** linha e `short_links` sem duplicata de slug
- [ ] A falha não contém proprietário, `reserved_at` nem qualquer dado do vencedor (SLG-18)
- [ ] O teste roda fora de `RefreshDatabase` transacional (limpeza explícita), com nota explicando por quê — transação aninhada mascararia o cenário
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 4 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `test(links): add concurrent slug reservation coverage`

---

### T14: Registrar o código de erro e fechar a fatia

**What**: documentar `SLUG_GENERATION_FAILED`, gravar AD-019 e atualizar índice e handoff.  
**Where**: `docs/api.md` §7 (modificar), `.specs/STATE.md` (Decisions + Handoff), `.specs/features/links/README.md`, `docs/roadmap.md` se aplicável  
**Depends on**: T12, T13  
**Reuses**: formato das entradas `AD-NNN` existentes  
**Requirement**: SLG-05

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `docs/api.md` §7 lista `SLUG_GENERATION_FAILED` entre os códigos estáveis, associado a `503`
- [ ] `.specs/STATE.md` recebe `AD-019` com a decisão do código de erro; nenhuma decisão anterior é marcada superseded
- [ ] Handoff do `STATE.md` e o índice `links/README.md` refletem a fatia entregue
- [ ] A OpenAPI **não** é alterada aqui (o endpoint que produz o erro é da fatia link-creation) — registrado explicitamente como escopo diferido
- [ ] Cobertura da fatia confere 90% linhas / 85% (métodos) em `modules/Links`
- [ ] Gate passa: `make lint && make test-backend-coverage`

**Tests**: none (build gate — camada docs/STATE na matriz) · **Gate**: build  
**Commit**: `docs(links): register SLUG_GENERATION_FAILED error code`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5
Phase 2:  T6 ──→ T7 ──→ T8
Phase 3:  T9 ──→ T10 ──→ T11
Phase 4:  T12 ──→ T13 ──→ T14
```

Execução é estritamente sequencial — não há paralelismo intra-fase.

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: config de slug | 1 arquivo de config + teste | ✅ Granular |
| T2: dois enums | 2 arquivos coesos, mesmo conceito | ⚠️ OK (coeso) |
| T3: três exceções | 3 arquivos coesos, mesma hierarquia | ⚠️ OK (coeso) |
| T4: VO `Slug` | 1 classe | ✅ Granular |
| T5: port + adapter da denylist | 1 contrato + 1 implementação | ⚠️ OK (par port/adapter) |
| T6: `SlugPolicy` | 1 classe | ✅ Granular |
| T7: port + adapter CSPRNG | 1 contrato + 1 implementação | ⚠️ OK (par port/adapter) |
| T8: `SlugGenerator` | 1 classe | ✅ Granular |
| T9: Model + factory + `Pest.php` | 1 model + suporte de teste | ⚠️ OK (coeso) |
| T10: port + repositório | 1 contrato + 1 implementação | ⚠️ OK (par port/adapter) |
| T11: UseCase + bindings | 1 classe + registro no provider | ⚠️ OK (o UseCase não é resolvível sem os bindings) |
| T12: gates arquiteturais | 1 arquivo de teste | ✅ Granular |
| T13: teste de concorrência | 1 arquivo de teste | ✅ Granular |
| T14: docs + STATE | somente documentação | ✅ Granular |

Nenhum ❌ — nenhuma task cria múltiplos componentes não coesos.

---

## Diagram-Definition Cross-Check

| Task | Depends On (corpo) | Diagrama mostra | Status |
| --- | --- | --- | --- |
| T1 | None | início da Phase 1 | ✅ Match |
| T2 | None | T1 → T2 (ordem sequencial, sem dependência de dados) | ✅ Match |
| T3 | T2 | T2 → T3 | ✅ Match |
| T4 | T2, T3 | T3 → T4 (T2 alcançado transitivamente por T3) | ✅ Match |
| T5 | T1 | T4 → T5 (T1 alcançado transitivamente) | ✅ Match |
| T6 | T4, T5 | T5 → T6 | ✅ Match |
| T7 | T1 | T6 → T7 (T1 alcançado transitivamente) | ✅ Match |
| T8 | T6, T7 | T7 → T8 | ✅ Match |
| T9 | T4 | T8 → T9 (T4 alcançado transitivamente) | ✅ Match |
| T10 | T3, T9 | T9 → T10 | ✅ Match |
| T11 | T8, T10 | T10 → T11 | ✅ Match |
| T12 | T11 | T11 → T12 | ✅ Match |
| T13 | T11 | T12 → T13 (T11 alcançado transitivamente) | ✅ Match |
| T14 | T12, T13 | T13 → T14 | ✅ Match |

Nenhuma task depende de fase posterior. As setas do mapa são a ordem de execução; as dependências de dados são um subconjunto delas — nenhuma dependência do corpo fica sem caminho no diagrama.

---

## Test Co-location Validation

| Task | Code Layer criada/modificada | Matriz exige | Task diz | Status |
| --- | --- | --- | --- | --- |
| T1 | Config PHP | unit | unit | ✅ OK |
| T2 | Domain (enums) | unit | unit | ✅ OK |
| T3 | Domain (exceções) | unit | unit | ✅ OK |
| T4 | Domain (VO) | unit | unit | ✅ OK |
| T5 | Contract + adapter de serviço | unit | unit | ✅ OK |
| T6 | Domain (service) | unit | unit | ✅ OK |
| T7 | Contract + adapter de serviço | unit | unit | ✅ OK |
| T8 | Domain (service) | unit | unit | ✅ OK |
| T9 | Persistência (Model) | integration | integration | ✅ OK |
| T10 | Persistência (Repository) | integration | integration | ✅ OK |
| T11 | UseCase com I/O + provider | integration | integration | ✅ OK |
| T12 | Regras arquiteturais | architecture | architecture | ✅ OK |
| T13 | UseCase com I/O (concorrência) | integration | integration | ✅ OK |
| T14 | Docs / STATE | none | none | ✅ OK |

Nenhuma ❌. Nenhuma task produz código não verificado, e nenhum teste foi adiado para task posterior — T13 é cobertura **adicional** de concorrência, não o teste faltante de T11.

---

## Traceability (task → requirement)

| Requirement | Tasks |
| --- | --- |
| LNK-10, SLG-01, SLG-02, SLG-03 | T7, T8 |
| LNK-11, SLG-04, SLG-05, SLG-06 | T10, T11, T14 |
| LNK-12, SLG-09, SLG-10 | T4, T13 |
| LNK-13, SLG-07, SLG-08 | T2, T4 |
| LNK-14, SLG-11, SLG-12, SLG-13 | T1, T5, T6 |
| LNK-15, SLG-14, SLG-15, SLG-17 | T9, T10, T12, T13 |
| LNK-16 | T10 |
| SLG-16 | T4, T12 |
| SLG-18 | T3, T13 |

**Coverage**: 25 requisitos, 25 mapeados para tasks, 0 sem mapeamento.
