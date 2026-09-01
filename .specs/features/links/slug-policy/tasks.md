# Links — Política de slug · Tasks

## Execution Protocol (MANDATORY — do not skip)

Implemente estas tasks com a skill `tlc-spec-driven`: **ative-a pelo nome e siga o fluxo de Execute e as Critical Rules dela.** Não procure os arquivos da skill por caminho de filesystem. A skill é a fonte de verdade do fluxo completo (ciclo por task, delegação a sub-agents, revisão de adequação, Verifier, sensor de discriminação).

**Se a skill não puder ser ativada, PARE e avise o usuário — não prossiga sem ela.**

---

**Spec**: `.specs/features/links/slug-policy/spec.md`  
**Design**: `.specs/features/links/slug-policy/design.md`  
**Status**: Aprovado 2026-09-01 — Execute em andamento (sub-agents por batch)

> ✅ **Pré-requisito atendido**: a fatia [foundation](../foundation/spec.md) está implementada e verificada (STATE handoff — `f82f57c`…`922bbbb`). Já existem em `main`: módulos `backend/modules/{Links,Redirects}`, migrations `slug_reservations` / `short_links` / `link_destination_versions`, suítes de `Links` em `backend/phpunit.xml` (Unit/Feature/Integration) e no bloco `<source>`, e o gate por módulo `backend/scripts/check-module-coverage-gate.php` já com `Links => [lines 90, methods 85]`. A T1 confirma esse estado antes de escrever código.

### Reconciliação 2026-09-01 (o que mudou vs. o Draft original)

O Draft foi escrito assumindo `backend/modules/` só com `Auth/`. O estado real de `main` divergiu:

| Task original | Situação encontrada | Ajuste |
| --- | --- | --- |
| T1 — criar `config/links.php` | Arquivo **já existe** com a chave `destination` (fatia foundation) | T1 **acrescenta** a sub-árvore `slug`, não cria o arquivo |
| T4 — criar VO `Slug` | Skeleton **já shipado** (`fromString` + regex `[a-z0-9-]{1,48}`, sem normalização); **sem call site de produção** (só o próprio teste) | T4 **reescreve** o skeleton: troca `fromString` por `fromCustomAlias`/`fromGenerated`, substitui `SlugTest.php`, e remove `LinksDomainException::invalidSlug()` + `INVALID_SLUG` (órfãos após a troca) |
| T9 — criar `SlugReservationModel` + factory + `Pest.php` | Model, factory e testes de integração (`SlugReservationsSchemaContractTest`) **já existem e passam**; a foundation registra `RefreshDatabase` **por arquivo** (`uses(TestCase::class, RefreshDatabase::class)`), **não** em `Pest.php` | T9 **removida**. Sua verificação vira o primeiro bullet da nova T9 (repositório). Tasks renumeradas 14 → 13 |
| T14 — gravar `AD-019` | `AD-019` **já usado** (league/uri, 2026-08-30) | Agora **T13**, grava **`AD-020`** |

Numeração nova: **T1…T13**. Batches: A = T1–T8, B = T9–T13.

---

## Execution Log

### Batch A (Phase 1 + Phase 2, T1–T8) — ✅ COMPLETE 2026-09-01

| Task | Commit | Notes |
| --- | --- | --- |
| T1 | `f016f51` | `slug` subtree added to `config/links.php`; `destination` intact |
| T2 | `6fca361` | `SlugSource` / `SlugRejectionReason` enums |
| T3 | `242a71b` | `SlugPolicyException`, `SlugUnavailable` (`errorCode ALIAS_UNAVAILABLE`), `SlugGenerationExhausted` (`errorCode SLUG_GENERATION_FAILED`) |
| T4 | `f5887c9` | `Slug` skeleton replaced (`fromCustomAlias`/`fromGenerated`, no `fromString`); `LinksDomainException::invalidSlug` + `INVALID_SLUG` removed; 29 tests (all spec edge cases kept) |
| T5 | `2dd547b` | `ReservedSlugs` port + `Infrastructure/Slug/ConfigReservedSlugs` |
| T6 | `06e6300` | `SlugPolicy` (`final readonly`, ctor `(ReservedSlugs)`, `fromCustomAlias`/`fromGenerated`/`isReserved`) |
| T7 | `76c1071` | `RandomSlugSource` port + `Infrastructure/Slug/CsprngSlugSource` (`random_int` per position) |
| T8 | `c57a849` | `SlugGenerator` ctor `(RandomSlugSource, SlugPolicy, int length=8, int maxDenylistDiscards=5)`, `generate(): Slug` |

- **Tests**: 620 passed, 0 failed (full suite). Batch delta +66.
- **Quality**: Pint / PHPStan L6 + strict-rules / PHPMD all clean.
- **Env issue (not code)**: `make test-backend` blocked here by a host **port 6380** clash (Redis) with an unrelated running project. Worker ran the same suite via `docker compose -f docker-compose.yml run --rm … backend php artisan test` against `fake_link_testing`. **Batch B will hit the same clash** and must use the same workaround (or the other project's Redis is stopped). The **Verifier still needs `make test-backend-coverage`** to produce the coverage report — port 6380 must be free by then, or run the coverage command with the base-compose-only workaround.
- **SPEC_DEVIATION (SlugGenerator)**: `int $length = 8` kept in ctor but effectively pinned — `Slug::fromGenerated` enforces exactly 8 per spec. Alphabet is a private Base36 const in the generator (design ctor omits an alphabet param); `config('links.slug.alphabet')` exists but is **not read by code**. Rationale: the `Slug` VO is the single source of the 8-char rule.
- **T10 wiring reminder**: provider bindings should pass `max_collision_attempts`, `max_denylist_discards`, `length` from `config('links.slug.*')` (not hardcoded).
- **Global Pest helpers already defined** (avoid clashes in Batch B): `assertSlugRejected`, `policyWith`, `assertPolicyRejected`, `base36Alphabet`, `generatorWith`, `class ScriptedSlugSource`.

### Batch B (Phase 3 + Phase 4, T9–T13) — ✅ COMPLETE 2026-09-01

| Task | Commit | Notes |
| --- | --- | --- |
| T9 | `93d1139` | `SlugReservationRepository` port + `EloquentSlugReservationRepository` (`reserve` / `existsWithoutLink`, no removal path); 10 integration tests. Gate: full suite 630/630. |
| T10 | `22b9820` | `ReserveSlug` UseCase (`forAlias` single-shot; `automatic` bounded retry via per-attempt `DB::transaction` SAVEPOINT); provider binds the 3 ports + wires `SlugGenerator`/`ReserveSlug` from `config('links.slug.*')`. 11 + 3 tests. Gate: full suite 644/644. |
| T11 | `ed8dcf0` | `tests/Architecture/SlugPolicyBoundariesTest.php` — 4 rules (Domain framework-free, Domain⊄Infrastructure, no reservation removal/mutation path, `Slug` readonly/no-mutator) with a discrimination-sensor header. Gate: `make lint-backend` + `make test-architecture` 17/17 + coverage 648/648, **Links 92.30% lines / 91.00% methods**. |
| T12 | `68ac6eb` | `SlugReservationConcurrencyTest.php` — 2 real PG connections, overlapping transactions, one winner; loser gets `SlugUnavailable`; one row; failure carries no occupant data (orphan vs linked identical). Runs outside transactional `RefreshDatabase` with explicit cleanup + in-file note. 4 tests. |
| T13 | *(this commit)* | `docs/api.md` §7 registers `SLUG_GENERATION_FAILED` (`503`, `Retry-After`); `AD-020` in `.specs/STATE.md`; STATE Handoff + `links/README.md` updated; OpenAPI change explicitly deferred to `link-creation`. |

- **Environment caveat**: Batch B ran while the host was heavily loaded by an unrelated process (load avg up to ~41; it also holds Redis port 6380, so `make test-backend` was replaced by `docker compose -f docker-compose.yml run … backend php artisan test`). Consequences:
  - T9/T10/T11 gates completed green as noted above.
  - **T12 full-suite gate did not close**: its own 4 tests pass, `pint` passes, `phpstan analyse modules/Links` is clean, but the in-suite `QualityToolingTest` (which shells out to `phpstan` with a 180s self-timeout) timed out under load on every attempt — an environmental flake, not a code defect.
  - **T13 build gate (`make lint && make test-backend-coverage`) still needs one clean run** on an unloaded machine. T13 changes are docs/STATE only and cannot affect tests.
- **Follow-up for the orchestrator/Verifier**: re-run `make lint && make test-backend-coverage` once the host is quiet to close T12/T13; the Verifier's independent pass covers the same ground.

---

## Test Coverage Matrix

> Gerada a partir do codebase, das diretrizes do projeto e da spec — confirmar antes do Execute. Diretrizes encontradas: `AGENTS.md` (linhas 38, 42–44), `docs/testing.md` §3.1 e §4, `.specs/STATE.md` (AD-009, AD-011), `backend/phpunit.xml`, `backend/composer.json` (`quality`, `test:coverage`), `Makefile`, `LARAVEL_CODE_DESIGN.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Domain — VO, enums, exceções, services (`Slug`, `SlugPolicy`, `SlugGenerator`) | unit | Todas as branches; 1:1 com os ACs da spec; **todo** edge case listado tem teste | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| Contracts + adapters de serviço (`ConfigReservedSlugs`, `CsprngSlugSource`) | unit | Todas as branches + caminhos de erro; denylist e aleatoriedade injetadas, nunca globais | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| Persistência — Repository (`slug_reservations`) | integration | Caminhos de query principais + violação de constraint real em PostgreSQL + rollback | `backend/modules/Links/Tests/Integration/*Test.php` | `make test-backend` |
| UseCase com I/O (`ReserveSlug`) | integration | Happy path + colisão + exaustão + alias indisponível + concorrência | `backend/modules/Links/Tests/Integration/*Test.php` | `make test-backend` |
| Config PHP (`config/links.php`) | unit | Chaves, tipos e valores default asseridos (precedente `HashingConfigTest`) | `backend/modules/Links/Tests/Unit/**/*Test.php` | `make test-backend` |
| Regras arquiteturais | architecture | Ausência de caminho de remoção de reserva; `Domain` sem `config()`/Eloquent | `backend/tests/Architecture/*Test.php` | `make test-architecture` |
| Docs / STATE / índice | none | — (build gate) | — | `make lint` |

**Cobertura numérica da fatia**: 90% linhas / 85% branches — em PCOV, **métodos** substituem branches (`docs/testing.md` §4); já aplicado por `scripts/check-module-coverage-gate.php` (`Links => 90/85`).

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
T9 → T10
```

### Phase 4: Garantias transversais e fechamento

```
T11 → T12 → T13
```

**Packing previsto no Execute**: 13 tasks → 2 batches (Batch A = Phase 1 + Phase 2, 8 tasks; Batch B = Phase 3 + Phase 4, 5 tasks). Como isso passa de um batch, o Execute **deve oferecer** sub-agents antes de dispatch.

---

## Task Breakdown

### T1: Acrescentar a sub-árvore `slug` a `config/links.php`

**What**: adicionar ao arquivo de config já existente o alfabeto, comprimentos, tetos de tentativa e denylist do slug.  
**Where**: `backend/config/links.php` (modificar — hoje só tem a chave `destination`), `backend/modules/Links/Tests/Unit/Config/LinksSlugConfigTest.php`  
**Depends on**: None  
**Reuses**: `backend/config/auth.php` (forma), `modules/Auth/Tests/Unit/HashingConfigTest.php` (padrão de teste de config)  
**Requirement**: SLG-12, LNK-14

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Pré-requisito conferido: `backend/modules/Links/` existe; migrations `slug_reservations` / `short_links` presentes; `phpunit.xml` inclui as suítes de Links (Unit/Feature/Integration) e o `<source>`; `scripts/check-module-coverage-gate.php` tem `Links => 90/85` — se algo faltar, PARAR e escalar
- [ ] `config/links.php` ganha a chave `slug` com `length=8`, `alphabet` Base36 minúsculo (`abcdefghijklmnopqrstuvwxyz0123456789`), `min_alias_length=3`, `max_alias_length=48`, `max_collision_attempts=5`, `max_denylist_discards=5`
- [ ] `slug.reserved_words` contém exatamente as 10 palavras da spec (`admin`, `api`, `login`, `register`, `docs`, `health`, `status`, `support`, `terms`, `privacy`), todas minúsculas
- [ ] A chave `destination` pré-existente permanece intacta
- [ ] Teste assere cada chave nova, tipo e valor default
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 6 testes passam (sem deleções silenciosas)

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): add slug policy configuration`

---

### T2: Criar enums `SlugSource` e `SlugRejectionReason`

**What**: enums de domínio para origem do slug e códigos estáveis de rejeição.  
**Where**: `backend/modules/Links/Domain/Enums/{SlugSource,SlugRejectionReason}.php`, `modules/Links/Tests/Unit/Domain/Enums/SlugEnumsTest.php`  
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
**Where**: `backend/modules/Links/Exceptions/{SlugPolicyException,SlugUnavailable,SlugGenerationExhausted}.php`, `modules/Links/Tests/Unit/Exceptions/SlugExceptionsTest.php`  
**Depends on**: T2  
**Reuses**: `modules/Links/Exceptions/LinksDomainException.php` (named constructors, `errorCode()`), `modules/Auth/Exceptions/AuthDomainException.php`  
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

### T4: Reescrever o Value Object `Slug` (skeleton da foundation)

**What**: substituir o skeleton `fromString` por um VO imutável com normalização ASCII e validação estrutural completa do alias, mais o factory do slug gerado.  
**Where**: `backend/modules/Links/Domain/ValueObjects/Slug.php` (reescrever), `modules/Links/Tests/Unit/Domain/ValueObjects/SlugTest.php` (substituir os 13 testes de `fromString`), `modules/Links/Exceptions/LinksDomainException.php` + `modules/Links/Tests/Unit/Exceptions/LinksDomainExceptionTest.php` (remover `invalidSlug()` / `INVALID_SLUG` órfãos)  
**Depends on**: T2, T3  
**Reuses**: `modules/Auth/Domain/ValueObjects/EmailAddress.php` (forma `final readonly`, construtor privado, `value()`, `equals()`)  
**Requirement**: LNK-12, LNK-13, SLG-07, SLG-08, SLG-09, SLG-10, SLG-16

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Confirmado que `Slug` não tem call site de produção antes de reescrever (grep: só o próprio arquivo e `SlugTest.php`)
- [ ] `fromCustomAlias()` aplica trim ASCII e lowercase por `strtr` com mapa explícito `A-Z`→`a-z` (**não** `strtolower`/`mb_strtolower`)
- [ ] Normalização ocorre antes de qualquer validação
- [ ] Valida comprimento 3–48, allowlist `[a-z0-9-]`, fronteiras alfanuméricas e ausência de hífens consecutivos, cada falha lançando `SlugPolicyException` com seu `SlugRejectionReason`
- [ ] `fromGenerated()` aceita somente 8 caracteres `[a-z0-9]` e marca origem `automatic`; `source()` expõe `SlugSource`
- [ ] VO é `final readonly`, sem `config()`, sem framework, sem `intl`; não existe setter nem `withSlug()`; `fromString` deixa de existir
- [ ] `LinksDomainException::invalidSlug()` e a constante `INVALID_SLUG` são removidas, junto dos casos correspondentes em `LinksDomainExceptionTest.php`; `make lint` continua verde (sem referência órfã)
- [ ] Testes 1:1 com os ACs de LNK-12/LNK-13 e **todos** os edge cases da spec: `"  Architecture  "`, 2/3/48/49 caracteres, `---`, `a-b`, `a--b`, `аdmin` (cirílico U+0430), `ADMÍN`, `İstanbul` (U+0130), `%61dmin`, `"my link"`, string vazia, caractere de controle
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 28 testes passam

**Tests**: unit · **Gate**: quick  
**Commit**: `feat(links): replace Slug skeleton with normalized value object`

---

### T5: Criar port `ReservedSlugs` e adapter `ConfigReservedSlugs`

**What**: contrato que expõe a denylist ao domínio, com adaptador lendo `config('links.slug.reserved_words')`.  
**Where**: `backend/modules/Links/Contracts/Services/ReservedSlugs.php`, `modules/Links/Infrastructure/Slug/ConfigReservedSlugs.php`, `modules/Links/Tests/Unit/Infrastructure/Slug/ConfigReservedSlugsTest.php`  
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
**Where**: `backend/modules/Links/Domain/Services/SlugPolicy.php`, `modules/Links/Tests/Unit/Domain/Services/SlugPolicyTest.php`  
**Depends on**: T4, T5  
**Reuses**: `modules/Auth/Domain/Services/PasswordPolicy.php` (política de domínio com códigos estáveis)  
**Requirement**: LNK-14, SLG-11, SLG-13

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `fromCustomAlias()` e `fromGenerated()` são o único caminho de construção usado por gerador e UseCase
- [ ] Denylist é verificada **depois** da validação estrutural do VO e **sobre** o valor normalizado
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
**Where**: `backend/modules/Links/Contracts/Services/RandomSlugSource.php`, `modules/Links/Infrastructure/Slug/CsprngSlugSource.php`, `modules/Links/Tests/Unit/Infrastructure/Slug/CsprngSlugSourceTest.php`  
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
**Where**: `backend/modules/Links/Domain/Services/SlugGenerator.php`, `modules/Links/Tests/Unit/Domain/Services/SlugGeneratorTest.php`  
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

### T9: Implementar port e repositório de reserva

**What**: `SlugReservationRepository` (sem método de remoção) e o adaptador Eloquent que traduz violação de unicidade em `SlugUnavailable`.  
**Where**: `backend/modules/Links/Contracts/Repositories/SlugReservationRepository.php`, `modules/Links/Infrastructure/Persistence/Eloquent/Repositories/EloquentSlugReservationRepository.php`, `modules/Links/Tests/Integration/SlugReservationRepositoryTest.php`  
**Depends on**: T3, T4  
**Reuses**: `EloquentUserRepository.php:85` (captura de `UniqueConstraintViolationException`); `SlugReservationModel` + `SlugReservationModelFactory` (já entregues pela foundation); `SlugReservationsSchemaContractTest` (padrão de teste de integração com `uses(TestCase::class, RefreshDatabase::class)` por arquivo)  
**Requirement**: LNK-15, LNK-16, SLG-06, SLG-14, SLG-15

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] Pré-check: `SlugReservationModel` (tabela `slug_reservations`, PK `slug` string não-incrementing, sem `timestamps`, sem `SoftDeletes`) e sua factory existem e passam nos testes atuais — se divergir, PARAR e escalar; nenhuma migration nova nesta fatia
- [ ] Port expõe somente `reserve(Slug): void` e `existsWithoutLink(Slug): bool` — **nenhum** método de remoção ou update
- [ ] `reserve()` faz `INSERT` sem `SELECT` prévio de disponibilidade (SLG-06)
- [ ] Violação de PK vira `SlugUnavailable`; a reserva existente permanece com o mesmo `reserved_at`
- [ ] `reserve()` participa da transação do chamador: teste com `DB::transaction` + rollback prova que a linha não persiste (SLG-14)
- [ ] `existsWithoutLink()` retorna `true` para reserva sem `short_links` correspondente (`LEFT JOIN`) e `false` quando há link
- [ ] PostgreSQL indisponível/erro genérico (`QueryException` não-unicidade) propaga sem virar `SlugUnavailable`
- [ ] Gate passa: `make test-backend`
- [ ] Test count: 10 testes passam

**Tests**: integration · **Gate**: full  
**Commit**: `feat(links): add permanent slug reservation repository`

---

### T10: Implementar `ReserveSlug` e registrar bindings

**What**: UseCase que orquestra alias vs. automático com o teto de 5 colisões, e os bindings dos três ports no provider do módulo.  
**Where**: `backend/modules/Links/UseCases/ReserveSlug.php`, `modules/Links/ServiceProviders/LinksServiceProvider.php` (modificar), `modules/Links/Tests/Integration/ReserveSlugTest.php`, `modules/Links/Tests/Feature/LinksServiceProviderTest.php` (estender com as 3 resoluções)  
**Depends on**: T8, T9  
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

### T11: Adicionar gates arquiteturais da política de slug

**What**: regras Pest Arch que provam ausência de caminho de remoção de reserva e pureza do `Domain`.  
**Where**: `backend/tests/Architecture/SlugPolicyBoundariesTest.php` (novo)  
**Depends on**: T10  
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

### T12: Teste de concorrência entre aliases equivalentes

**What**: prova com duas conexões PostgreSQL de que aliases equivalentes por caixa têm um único vencedor.  
**Where**: `backend/modules/Links/Tests/Integration/SlugReservationConcurrencyTest.php`  
**Depends on**: T10  
**Reuses**: `modules/Auth/Tests/Integration/UsersPersistenceConstraintsTest.php` (padrão de constraint real), `Modules\Auth\Tests\Support\DatabaseSafetyGuard`  
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

### T13: Registrar o código de erro e fechar a fatia

**What**: documentar `SLUG_GENERATION_FAILED`, gravar AD-020 e atualizar índice e handoff.  
**Where**: `docs/api.md` §7 (modificar), `.specs/STATE.md` (Decisions + Handoff), `.specs/features/links/README.md`, `docs/roadmap.md` se aplicável  
**Depends on**: T11, T12  
**Reuses**: formato das entradas `AD-NNN` existentes  
**Requirement**: SLG-05

**Tools**: MCP: NONE · Skill: NONE

**Done when**:
- [ ] `docs/api.md` §7 registra `SLUG_GENERATION_FAILED` como código estável específico associado a `503` (na estrutura real da seção — tabela de status + nota de códigos; não confundir com a linha de "códigos gerais")
- [ ] `.specs/STATE.md` recebe `AD-020` com a decisão do código de erro; nenhuma decisão anterior é marcada superseded (`AD-019` = league/uri, já existente)
- [ ] Handoff do `STATE.md` e o índice `links/README.md` refletem a fatia entregue
- [ ] A OpenAPI **não** é alterada aqui (o endpoint que produz o erro é da fatia link-creation) — registrado explicitamente como escopo diferido
- [ ] Cobertura da fatia confere 90% linhas / 85% (métodos) em `modules/Links` via `make test-backend-coverage`
- [ ] Gate passa: `make lint && make test-backend-coverage`

**Tests**: none (build gate — camada docs/STATE na matriz) · **Gate**: build  
**Commit**: `docs(links): register SLUG_GENERATION_FAILED error code`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5
Phase 2:  T6 ──→ T7 ──→ T8
Phase 3:  T9 ──→ T10
Phase 4:  T11 ──→ T12 ──→ T13
```

Execução é estritamente sequencial — não há paralelismo intra-fase.

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: sub-árvore `slug` na config | 1 arquivo de config (modif.) + teste | ✅ Granular |
| T2: dois enums | 2 arquivos coesos, mesmo conceito | ⚠️ OK (coeso) |
| T3: três exceções | 3 arquivos coesos, mesma hierarquia | ⚠️ OK (coeso) |
| T4: reescrever VO `Slug` | 1 classe (reescrita) + limpeza de exceção órfã | ⚠️ OK (a limpeza é consequência direta da troca) |
| T5: port + adapter da denylist | 1 contrato + 1 implementação | ⚠️ OK (par port/adapter) |
| T6: `SlugPolicy` | 1 classe | ✅ Granular |
| T7: port + adapter CSPRNG | 1 contrato + 1 implementação | ⚠️ OK (par port/adapter) |
| T8: `SlugGenerator` | 1 classe | ✅ Granular |
| T9: port + repositório | 1 contrato + 1 implementação | ⚠️ OK (par port/adapter) |
| T10: UseCase + bindings | 1 classe + registro no provider | ⚠️ OK (o UseCase não é resolvível sem os bindings) |
| T11: gates arquiteturais | 1 arquivo de teste | ✅ Granular |
| T12: teste de concorrência | 1 arquivo de teste | ✅ Granular |
| T13: docs + STATE | somente documentação | ✅ Granular |

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
| T9 | T3, T4 | T8 → T9 (T3/T4 alcançados transitivamente) | ✅ Match |
| T10 | T8, T9 | T9 → T10 | ✅ Match |
| T11 | T10 | T10 → T11 | ✅ Match |
| T12 | T10 | T11 → T12 (T10 alcançado transitivamente) | ✅ Match |
| T13 | T11, T12 | T12 → T13 | ✅ Match |

Nenhuma task depende de fase posterior. As setas do mapa são a ordem de execução; as dependências de dados são um subconjunto delas.

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
| T9 | Persistência (Repository) | integration | integration | ✅ OK |
| T10 | UseCase com I/O + provider | integration | integration | ✅ OK |
| T11 | Regras arquiteturais | architecture | architecture | ✅ OK |
| T12 | UseCase com I/O (concorrência) | integration | integration | ✅ OK |
| T13 | Docs / STATE | none | none | ✅ OK |

Nenhuma ❌. T12 é cobertura **adicional** de concorrência, não o teste faltante de T10.

---

## Traceability (task → requirement)

| Requirement | Tasks |
| --- | --- |
| LNK-10, SLG-01, SLG-02, SLG-03 | T7, T8 |
| LNK-11, SLG-04, SLG-05, SLG-06 | T9, T10, T13 |
| LNK-12, SLG-09, SLG-10 | T4, T12 |
| LNK-13, SLG-07, SLG-08 | T2, T4 |
| LNK-14, SLG-11, SLG-12, SLG-13 | T1, T5, T6 |
| LNK-15, SLG-14, SLG-15, SLG-17 | T9, T11, T12 |
| LNK-16 | T9 |
| SLG-16 | T4, T11 |
| SLG-18 | T3, T12 |

**Coverage**: 25 requisitos, 25 mapeados para tasks, 0 sem mapeamento.
