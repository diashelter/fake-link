# Links — Fundação dos módulos · Tasks

## Execution Protocol (MANDATORY — do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Spec**: `.specs/features/links/foundation/spec.md`
**Context**: `.specs/features/links/foundation/context.md`
**Design**: `.specs/features/links/foundation/design.md`
**Status**: Draft

---

## Test Coverage Matrix

> Gerada do codebase, das guidelines do projeto e da spec — confirmar antes do Execute. Guidelines encontradas: `AGENTS.md`, `docs/testing.md` §2/§3.1/§4/§6.3, `backend/phpunit.xml`, `backend/composer.json` (`test`, `test:coverage`, `quality`), `Makefile` (`test-backend`, `test-architecture`, `test-backend-coverage`, `lint`), `LARAVEL_CODE_DESIGN.md`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| --- | --- | --- | --- | --- |
| Domain (Value Objects, Enums, Services puros) | unit | Todos os ramos; 1:1 com os ACs da spec; todo edge case listado tem teste (limites 1/48, 2048/2049, matriz completa de precedência) | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Domain Exceptions | unit | Códigos de erro estáveis + ausência de valor bruto na mensagem | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Infrastructure/Crypto (keyring, cipher) | unit | Todos os ramos: round-trip, nonce único, tag adulterada, `key_id` cruzado, envelope malformado, versão desconhecida, rotação, config inválida, ausência de plaintext em log | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Infrastructure/Identity (id generators) | unit | Geração UUID v7 válida e distinta entre chamadas | `backend/modules/Links/Tests/Unit/**` | `make test-backend` |
| Migrations + Eloquent Models/Factories | integration | Schema por `information_schema`/`pg_catalog` + **cada** constraint provada por violação (FK, RESTRICT, UNIQUE, CHECK, índice parcial) + rollback | `backend/modules/Links/Tests/Integration/**` | `make test-backend` (PostgreSQL `fake_link_testing`) |
| ServiceProviders / wiring / rotas | feature | Ports resolvidos do container + ausência de rotas novas | `backend/modules/{Links,Redirects}/Tests/Feature/**` | `make test-backend` |
| Regras de arquitetura | architecture | Seam `Redirects ↛ Links\{Infrastructure,Domain}` + regras existentes cobrindo os módulos novos | `backend/tests/Architecture/**` | `make test-architecture` |
| Scripts de gate (`backend/scripts/*.php`) | unit | Fixture acima e abaixo do limiar por módulo (o gate **falha** quando deve) | `backend/tests/Unit/**` | `make test-backend` |
| Config / `phpunit.xml` / `phpstan.neon` | none | — (build gate) | — | `make lint` |

**Nota de conformidade:** `docs/testing.md` §4 fixa **90% linhas / 85% métodos (proxy de branches)** para `Links` e `Redirects` — acima do padrão de Auth (80/80). A Coverage Expectation acima é o alvo por camada; o número é o piso agregado.

## Gate Check Commands

> Extraídas do `Makefile` e do `backend/composer.json` — confirmar antes do Execute. Todos os comandos rodam via Docker (AD-009); nunca no host.

| Gate Level | When to Use | Command |
| --- | --- | --- |
| Quick | Após tasks com testes unit apenas | `make test-backend` |
| Full | Após tasks com testes integration/feature | `make test-backend && make test-architecture` |
| Build | Após conclusão de fase ou tasks de config/gate | `make lint && make test-backend-coverage` |

---

## Execution Plan

Fases são ordenadas e rodam sequencialmente — cada fase conclui antes da próxima, e as tasks dentro de uma fase executam em ordem.

### Phase 1: Domínio de Links (4 tasks)

```
T1 → T2 → T3 → T4
```

### Phase 2: Criptografia de destinos (2 tasks)

```
T5 → T6
```

### Phase 3: Persistência (3 tasks)

```
T7 → T8 → T9
```

### Phase 4: Composição e wiring (3 tasks)

```
T10 → T11 → T12
```

### Phase 5: Gates estáticos e de cobertura (3 tasks)

```
T13 → T14 → T15
```

---

## Task Breakdown

### T1: Bootstrap do módulo Links — exceção de domínio e VOs de identidade

**What**: Criar `LinksDomainException`, `ShortLinkId` e `LinkDestinationVersionId`, e registrar a suíte Unit de `Links` em `phpunit.xml`.
**Where**: `backend/modules/Links/Exceptions/LinksDomainException.php`, `backend/modules/Links/Domain/ValueObjects/{ShortLinkId,LinkDestinationVersionId}.php`, `backend/phpunit.xml` (modificar)
**Depends on**: None
**Reuses**: `modules/Auth/Exceptions/AuthDomainException.php`, `modules/Auth/Domain/ValueObjects/UserId.php` (padrão copiado, **sem import** entre módulos)
**Requirement**: LNK-01, LFND-05, LFND-17, LFND-18

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `LinksDomainException` expõe códigos `INVALID_SLUG`, `INVALID_DESTINATION_URL`, `INVALID_SHORT_LINK_ID`, `INVALID_LINK_DESTINATION_VERSION_ID` via named constructors
- [ ] Nenhuma mensagem de exceção interpola o valor bruto recebido (assertivo em teste)
- [ ] `ShortLinkId::fromString` e `LinkDestinationVersionId::fromString` aceitam UUID v7 e rejeitam UUID v4, string vazia e formato inválido
- [ ] `phpunit.xml` registra `modules/Links/Tests/Unit` na suíte `Unit` e `modules/Links` em `<source><include>`
- [ ] A contagem de testes de `make test-backend` **aumenta** em relação ao baseline (prova de que a suíte foi descoberta)
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: baseline + ≥8 testes passam (sem deleções silenciosas)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add domain exception and uuid v7 identity value objects`

---

### T2: Value Object `Slug`

**What**: Criar o VO `Slug` com as invariantes estruturais desta fatia (ASCII minúsculo, 1–48, sem normalização).
**Where**: `backend/modules/Links/Domain/ValueObjects/Slug.php`
**Depends on**: T1
**Reuses**: padrão `EmailAddress` (`final readonly`, construtor privado, `fromString`/`value`/`equals`)
**Requirement**: LFND-09

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `fromString('abc-123')` retorna VO com `value() === 'abc-123'`
- [ ] Rejeita: maiúscula, caractere fora de `[a-z0-9-]`, string vazia, 49 caracteres
- [ ] Aceita: exatamente 1 e exatamente 48 caracteres
- [ ] Aceita hífen em borda e hífens consecutivos (regra de alias é da fatia 2) — coberto por teste explícito que **documenta** o comportamento
- [ ] `equals()` compara por valor
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: ≥8 testes passam

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add slug value object with structural invariants`

---

### T3: Value Object `DestinationUrl`

**What**: Criar o VO `DestinationUrl` com esquema `http`/`https`, host não vazio e limite de 2.048 caracteres, preservando o valor byte a byte.
**Where**: `backend/modules/Links/Domain/ValueObjects/DestinationUrl.php`
**Depends on**: T1
**Reuses**: padrão `EmailAddress`; `parse_url` para leitura da autoridade (nunca busca textual)
**Requirement**: LFND-10

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Aceita `http` e `https` com host não vazio
- [ ] Rejeita `ftp:`, `javascript:`, `data:`, string sem esquema e host vazio
- [ ] Aceita exatamente 2.048 caracteres e rejeita 2.049
- [ ] `value()` preserva query string e fragmento **sem alteração** (assertivo com URL contendo `?a=1&b=2#frag` e percent-encoding)
- [ ] Aceita IP literal e host privado nesta fatia — teste explícito documentando que o bloqueio é da fatia 3
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: ≥9 testes passam

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add destination url value object with scheme and length invariants`

---

### T4: Enum `LinkStatus` e derivação de estado efetivo

**What**: Criar o enum `LinkStatus` e o serviço puro `LinkStatusResolver` com a precedência `blocked` > `expired` > `inactive` > `active`.
**Where**: `backend/modules/Links/Domain/Enums/LinkStatus.php`, `backend/modules/Links/Domain/Services/LinkStatusResolver.php`
**Depends on**: T1
**Reuses**: padrão `UserStatus` (enum backed string)
**Requirement**: LFND-11, LFND-12

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `LinkStatus` expõe exatamente `active`, `inactive`, `expired`, `blocked`
- [ ] `resolve()` recebe `(?DateTimeImmutable $blockedAt, ?DateTimeImmutable $expiresAt, bool $isEnabled, DateTimeImmutable $now)` — relógio por parâmetro, sem estado global
- [ ] Bloqueado + expirado + desabilitado simultaneamente ⇒ `blocked`
- [ ] Não bloqueado + expirado + desabilitado ⇒ `expired`
- [ ] Não bloqueado + não expirado + desabilitado ⇒ `inactive`
- [ ] Não bloqueado + `expires_at` nulo ou futuro + habilitado ⇒ `active`
- [ ] `expires_at` exatamente igual a `now` ⇒ `expired` (limite exclusivo)
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: ≥10 testes passam (matriz de precedência completa)

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add link status enum and effective status resolver`

---

### T5: Config e keyring de destinos

**What**: Criar `config/links.php` e `DestinationKeyring`, com validação estrita de chave e `active_key_id`, e a chave determinística de teste.
**Where**: `backend/config/links.php`, `backend/modules/Links/Infrastructure/Crypto/DestinationKeyring.php`, `backend/phpunit.xml` (env), `backend/.env.example` (placeholder)
**Depends on**: T1
**Reuses**: padrão de config de `backend/config/hashing.php`; env de teste como em `AUTH_RATE_LIMIT_HMAC_KEY`
**Requirement**: LNK-04, LFND-16

**Tools**:

- MCP: `context7` (API de config Laravel 13, se necessário)
- Skill: NONE

**Done when**:

- [ ] `config('links.destination')` expõe `keyring` (mapa `key_id` → chave de 32 bytes) e `active_key_id`, ambos lidos de `LINKS_DESTINATION_KEYRING` / `LINKS_DESTINATION_ACTIVE_KEY_ID`
- [ ] `DestinationKeyring::fromConfig()` falha quando: keyring vazio, `active_key_id` ausente do mapa, chave com ≠ 32 bytes após decodificação, JSON de env malformado
- [ ] `keyFor('desconhecida')` falha explicitamente
- [ ] Nenhuma chave real versionada: `.env.example` recebe placeholder e `phpunit.xml` recebe chave obviamente falsa e determinística
- [ ] Mensagens de erro do keyring **não** contêm material de chave (assertivo em teste)
- [ ] Config lida do arquivo publicado, não de `config()->set` no `beforeEach`
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: ≥7 testes passam

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add destination keyring config with strict key validation`

---

### T6: Porta `DestinationCipher` e adaptador AES-256-GCM

**What**: Criar a porta `DestinationCipher`, o VO `EncryptedDestination`, a exceção `DestinationDecryptionFailed` e o adaptador `Aes256GcmDestinationCipher`.
**Where**: `backend/modules/Links/Contracts/Services/DestinationCipher.php`, `backend/modules/Links/Domain/ValueObjects/EncryptedDestination.php`, `backend/modules/Links/Exceptions/DestinationDecryptionFailed.php`, `backend/modules/Links/Infrastructure/Crypto/Aes256GcmDestinationCipher.php`
**Depends on**: T3, T5
**Reuses**: `DestinationKeyring` (T5), `DestinationUrl` (T3); envelope conforme `design.md` (A1)
**Requirement**: LFND-13, LFND-14, LFND-15, LFND-16

**Tools**:

- MCP: `context7` (`openssl_encrypt`/`openssl_decrypt` com AAD e tag)
- Skill: NONE

**Done when**:

- [ ] Round-trip preserva o plaintext exato, incluindo query string e fragmento
- [ ] Duas cifras da mesma URL produzem envelopes **diferentes** (nonce por operação)
- [ ] Decifra com `key_id` diferente do usado na cifra ⇒ `DestinationDecryptionFailed`, sem plaintext
- [ ] Alteração de 1 byte no nonce, na tag ou no ciphertext ⇒ `DestinationDecryptionFailed`
- [ ] Envelope com versão desconhecida, base64 inválido ou tamanho insuficiente ⇒ `DestinationDecryptionFailed`
- [ ] Envelope cifrado com chave antiga presente no keyring decifra mesmo com `active_key_id` diferente (teste de rotação com duas chaves)
- [ ] `encrypt()` retorna `key_id` igual ao `active_key_id` da config
- [ ] O envelope produzido **não contém** o plaintext como substring
- [ ] Com `Log::fake()` (ou spy de canal), nenhuma falha registra URL, chave ou envelope
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: ≥12 testes passam

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add aes-256-gcm destination cipher with versioned keyring`

---

### T7: Migration e Model de `slug_reservations`

**What**: Criar a migration de `slug_reservations`, o `SlugReservationModel` e sua factory, e registrar a suíte Integration de `Links`.
**Where**: `backend/database/migrations/*_create_slug_reservations_table.php`, `backend/modules/Links/Infrastructure/Persistence/Eloquent/{Models/SlugReservationModel.php,Factories/SlugReservationModelFactory.php}`, `backend/phpunit.xml` (modificar)
**Depends on**: T2
**Reuses**: migration `create_auth_tokens_table`, `UserModel`/`UserModelFactory`
**Requirement**: LNK-03, LFND-05, LFND-17

**Tools**:

- MCP: NONE
- Skill: NONE
- Nota: criar a migration com `php artisan make:migration` **dentro do container** (AGENTS.md)

**Done when**:

- [ ] Tabela criada com `slug varchar(48)` PK e `reserved_at timestamptz NOT NULL`, verificado por `information_schema.columns` e `pg_catalog` (não por leitura do arquivo)
- [ ] Insert de `slug` duplicado falha por violação de PK
- [ ] `down()` remove a tabela
- [ ] `phpunit.xml` registra `modules/Links/Tests/Integration` na suíte `Integration`
- [ ] Testes rodam contra `fake_link_testing` (assert de `DB_DATABASE` no setup — AD-011)
- [ ] Gate check passa: `make test-backend && make test-architecture`
- [ ] Test count: ≥5 testes passam

**Tests**: integration
**Gate**: full
**Commit**: `feat(links): add slug_reservations table with permanent reservation model`

---

### T8: Migration e Model de `short_links`

**What**: Criar a migration de `short_links` (FKs `RESTRICT`, `UNIQUE` de slug, `CHECK` de `slug_source`, índice por `user_id`), o `ShortLinkModel` e sua factory.
**Where**: `backend/database/migrations/*_create_short_links_table.php`, `backend/modules/Links/Infrastructure/Persistence/Eloquent/{Models/ShortLinkModel.php,Factories/ShortLinkModelFactory.php}`
**Depends on**: T7
**Reuses**: migration `create_auth_tokens_table` (`restrictOnDelete`, `CHECK` via `DB::statement`)
**Requirement**: LNK-03, LFND-05, LFND-06, LFND-07

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Colunas e tipos conferidos por `information_schema`: `id`/`user_id` `uuid`, `slug` `varchar(48)`, `slug_source` `text`, `title` `varchar(160)` nullable, `is_enabled` boolean, `blocked_at`/`expires_at` `timestamptz` nullable, `version` `bigint`, timestamps `timestamptz`
- [ ] **Nenhuma** coluna de estado efetivo (`status` / `effective_status`) existe — assertivo explícito
- [ ] Insert com `slug` sem reserva ⇒ falha de FK
- [ ] Delete de reserva referenciada ⇒ falha por `RESTRICT`
- [ ] Delete de `users` referenciado ⇒ falha por `RESTRICT`
- [ ] Dois `short_links` com o mesmo `slug` ⇒ falha de unicidade
- [ ] `slug_source` fora de `{automatic, custom}` ⇒ falha pelo `CHECK`
- [ ] Registro criado pela factory tem `id` UUID v7 gerado na aplicação e `version = 1`
- [ ] A factory de `Links` **não** importa a factory de `Auth`; o `users` do teste é criado no próprio teste
- [ ] Gate check passa: `make test-backend && make test-architecture`
- [ ] Test count: ≥10 testes passam

**Tests**: integration
**Gate**: full
**Commit**: `feat(links): add short_links table with restrict foreign keys and slug uniqueness`

---

### T9: Migration e Model de `link_destination_versions`

**What**: Criar a migration de `link_destination_versions` (índice parcial único, `CHECK` de vigência, índice de histórico), o Model, a factory e a verificação de rollback ordenado.
**Where**: `backend/database/migrations/*_create_link_destination_versions_table.php`, `backend/modules/Links/Infrastructure/Persistence/Eloquent/{Models/LinkDestinationVersionModel.php,Factories/LinkDestinationVersionModelFactory.php}`
**Depends on**: T8
**Reuses**: padrão das migrations anteriores; `DB::statement` para índice parcial
**Requirement**: LNK-03, LFND-07, LFND-08

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Colunas e tipos conferidos por `information_schema`; `destination_url` é `text` e `key_id` é `varchar`
- [ ] Duas versões abertas (`valid_to IS NULL`) do mesmo `short_link_id` ⇒ falha pelo índice parcial único
- [ ] Duas versões abertas de links **diferentes** coexistem sem erro
- [ ] `valid_to <= valid_from` ⇒ falha pelo `CHECK`; `valid_to > valid_from` e `valid_to IS NULL` passam
- [ ] Delete de `short_links` com versões ⇒ falha por `RESTRICT`
- [ ] Índice `(short_link_id, valid_from DESC)` existe (`pg_indexes`)
- [ ] Um envelope cifrado por `Aes256GcmDestinationCipher` persiste e volta decifrável (round-trip via banco), e o valor gravado **não contém** a URL em claro
- [ ] `migrate:rollback` das três migrations desfaz na ordem inversa sem violar FK
- [ ] Gate check passa: `make test-backend && make test-architecture`
- [ ] Test count: ≥10 testes passam

**Tests**: integration
**Gate**: full
**Commit**: `feat(links): add link_destination_versions table with single open version constraint`

---

### T10: Ports e adaptadores UUID v7 de identidade

**What**: Criar `ShortLinkIdGenerator` e `LinkDestinationVersionIdGenerator` (portas) e seus adaptadores `Uuid7*`.
**Where**: `backend/modules/Links/Contracts/Services/{ShortLinkIdGenerator,LinkDestinationVersionIdGenerator}.php`, `backend/modules/Links/Infrastructure/Identity/{Uuid7ShortLinkIdGenerator,Uuid7LinkDestinationVersionIdGenerator}.php`
**Depends on**: T1
**Reuses**: `Uuid7UserIdGenerator` + porta `UserIdGenerator`
**Requirement**: LFND-02

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Cada adaptador retorna o VO correspondente com UUID **versão 7** válido
- [ ] Duas chamadas consecutivas retornam identificadores distintos e temporalmente ordenados
- [ ] Gate check passa: `make test-backend`
- [ ] Test count: ≥4 testes passam

**Tests**: unit
**Gate**: quick
**Commit**: `feat(links): add uuid v7 identity generators for links entities`

---

### T11: `LinksServiceProvider` e registro do módulo

**What**: Criar o `LinksServiceProvider` com bindings e arquivo de rotas vazio, registrá-lo em `bootstrap/providers.php` e a suíte Feature de `Links` em `phpunit.xml`.
**Where**: `backend/modules/Links/ServiceProviders/LinksServiceProvider.php`, `backend/modules/Links/Infrastructure/Http/routes/links.php`, `backend/bootstrap/providers.php` (modificar), `backend/phpunit.xml` (modificar)
**Depends on**: T6, T10
**Reuses**: `AuthServiceProvider` (`Route::prefix(...)->group(fn () => $this->loadRoutesFrom(...))`, `bind()` de ports)
**Requirement**: LNK-01, LFND-01, LFND-02, LFND-03, LFND-17

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `LinksServiceProvider` consta em `bootstrap/providers.php`
- [ ] `app(DestinationCipher::class)` resolve `Aes256GcmDestinationCipher` (teste **resolve a porta do container**, não apenas verifica que a app sobe)
- [ ] `app(ShortLinkIdGenerator::class)` e `app(LinkDestinationVersionIdGenerator::class)` resolvem os adaptadores UUID v7
- [ ] `links.php` existe e **não define nenhuma rota**; a route collection não contém rota sob `api/v1/links`
- [ ] `phpunit.xml` registra `modules/Links/Tests/Feature` na suíte `Feature`
- [ ] Gate check passa: `make test-backend && make test-architecture`
- [ ] Test count: ≥5 testes passam

**Tests**: feature
**Gate**: full
**Commit**: `feat(links): register links service provider with module bindings`

---

### T12: `RedirectsServiceProvider` e scaffold do módulo Redirects

**What**: Criar o `RedirectsServiceProvider` com arquivo de rotas vazio, registrá-lo em `bootstrap/providers.php`, e registrar a suíte Feature e o `<source>` de `Redirects`.
**Where**: `backend/modules/Redirects/ServiceProviders/RedirectsServiceProvider.php`, `backend/modules/Redirects/Infrastructure/Http/routes/redirects.php`, `backend/bootstrap/providers.php` (modificar), `backend/phpunit.xml` (modificar)
**Depends on**: T11
**Reuses**: `LinksServiceProvider` (T11)
**Requirement**: LNK-02, LFND-01, LFND-03, LFND-17, LFND-18

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `RedirectsServiceProvider` consta em `bootstrap/providers.php` e a aplicação boota
- [ ] `redirects.php` existe e **não define nenhuma rota**
- [ ] Os placeholders de `backend/routes/web.php` (`/`, `/robots.txt`, `GET /{slug}` → 404) continuam registrados e respondendo como antes — assertivo em teste HTTP
- [ ] Nenhuma rota nova do host curto foi adicionada (comparação da route collection contra a lista esperada)
- [ ] `phpunit.xml` registra `modules/Redirects/Tests/Feature` na suíte `Feature` e `modules/Redirects` em `<source><include>`
- [ ] Gate check passa: `make test-backend && make test-architecture`
- [ ] Test count: ≥5 testes passam

**Tests**: feature
**Gate**: full
**Commit**: `feat(redirects): register redirects service provider scaffold`

---

### T13: PHPStan cobre os módulos novos

**What**: Acrescentar `modules/Links` e `modules/Redirects` aos `paths` de `phpstan.neon`, com os `ignoreErrors` de Pest equivalentes aos de Auth.
**Where**: `backend/phpstan.neon` (modificar)
**Depends on**: T12
**Reuses**: entradas existentes de `modules/Auth`
**Requirement**: LFND-04

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] `paths` inclui `modules/Links` e `modules/Redirects`
- [ ] `composer run analyse` termina com exit code 0 no nível 6 com `phpstan-strict-rules`
- [ ] Nenhum `ignoreErrors` novo além dos padrões de Pest já usados para `modules/Auth/Tests/*`
- [ ] Gate check passa: `make lint && make test-backend-coverage`

**Tests**: none (build gate — camada de config na matriz)
**Gate**: build
**Commit**: `chore(quality): analyse links and redirects modules with phpstan`

---

### T14: Regra de arquitetura do seam `Redirects ↛ Links`

**What**: Acrescentar ao `ModularMonolithTest` a regra que proíbe `Modules\Redirects` de usar `Modules\Links\Infrastructure` e `Modules\Links\Domain`.
**Where**: `backend/tests/Architecture/ModularMonolithTest.php` (modificar)
**Depends on**: T12
**Reuses**: array `$domainModules` e o padrão `arch(...)->expect(...)->not->toUse(...)` já presentes
**Requirement**: LFND-04

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] Existe regra `arch('Redirects does not reach into Links internals')` expectando `Modules\Redirects` e proibindo `Modules\Links\Infrastructure` e `Modules\Links\Domain`
- [ ] O comentário de discriminação do arquivo é atualizado descrevendo o mutante que a nova regra mata (um `use Modules\Links\Domain\...` dentro de `Modules\Redirects`)
- [ ] As regras existentes de Controller/Eloquent continuam cobrindo `Links` e `Redirects` (já enumerados em `$domainModules`)
- [ ] Gate check passa: `make test-backend && make test-architecture`
- [ ] Test count: suíte Architecture com ≥1 teste novo passando

**Tests**: architecture
**Gate**: full
**Commit**: `test(architecture): forbid redirects from reaching into links internals`

---

### T15: Gate de cobertura por módulo

**What**: Generalizar o script de cobertura em `check-module-coverage-gate.php` com limiares por módulo, apontar `composer test:coverage` para ele e atualizar `docs/testing.md`.
**Where**: `backend/scripts/check-module-coverage-gate.php` (novo, substitui `check-auth-coverage-gate.php`), `backend/composer.json` (modificar), `docs/testing.md` §4 (modificar), `backend/tests/Unit/ModuleCoverageGateTest.php` (novo)
**Depends on**: T12
**Reuses**: parser PCOV de `check-auth-coverage-gate.php`
**Requirement**: LNK-05, LFND-19, LFND-20

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [ ] O script aplica `Auth` 80/80, `Links` 90/85 e `Redirects` 90/85, e reporta cada módulo fora do limiar
- [ ] Teste com relatório-fixture **abaixo** do limiar prova que o script sai com código 1 (o gate falha quando deve)
- [ ] Teste com relatório-fixture **acima** do limiar prova exit code 0
- [ ] Relatório ausente para um módulo esperado ⇒ falha explícita (não passa por omissão)
- [ ] `composer test:coverage` chama o script generalizado; `check-auth-coverage-gate.php` é removido
- [ ] `docs/testing.md` §4 descreve o gate por módulo e o script novo
- [ ] `make test-backend-coverage` passa com os módulos entregues (≥90% linhas / ≥85% métodos em `Links` e `Redirects`)
- [ ] Gate check passa: `make lint && make test-backend-coverage`
- [ ] Test count: ≥4 testes do script passam

**Tests**: unit
**Gate**: build
**Commit**: `chore(quality): generalize coverage gate with per-module thresholds`

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3 → Phase 4 → Phase 5

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4
Phase 2:  T5 ──→ T6
Phase 3:  T7 ──→ T8 ──→ T9
Phase 4:  T10 ──→ T11 ──→ T12
Phase 5:  T13 ──→ T14 ──→ T15
```

Dependências reais (grafo, não ordem de execução):

```
T1 ──→ T2 ──→ T7 ──→ T8 ──→ T9
 │
 ├──→ T3 ──┐
 │         ├──→ T6 ──┐
 ├──→ T5 ──┘         ├──→ T11 ──→ T12 ──→ T13
 │                   │                 ├──→ T14
 ├──→ T4             │                 └──→ T15
 └──→ T10 ───────────┘
```

Execução é estritamente sequencial — não há paralelismo intra-fase.

**Packing previsto no Execute** (~7 tasks por worker, fases inteiras):

| Batch | Fases | Tasks | Total |
| --- | --- | --- | --- |
| 1 | Phase 1 + Phase 2 | T1–T6 | 6 |
| 2 | Phase 3 + Phase 4 | T7–T12 | 6 |
| 3 | Phase 5 | T13–T15 | 3 |

15 tasks ⇒ mais de um batch ⇒ o Execute **deve oferecer** sub-agents (offer-then-confirm) antes de despachar. Verifier roda automaticamente após T15.

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: exceção + 2 VOs de ID + registro de suíte | 3 arquivos coesos (mesmo padrão) + 1 config | ⚠️ OK — coeso e autoverificável (o registro da suíte é o que prova que os testes rodam) |
| T2: `Slug` | 1 VO | ✅ Granular |
| T3: `DestinationUrl` | 1 VO | ✅ Granular |
| T4: enum + resolver | 1 enum + 1 função pura | ⚠️ OK — o resolver é a razão de o enum existir |
| T5: config + keyring | 1 config + 1 classe | ⚠️ OK — o keyring é intestável sem a config |
| T6: porta + VO + exceção + adaptador | 1 conceito (envelope) em 4 arquivos | ⚠️ OK — separar deixaria a porta sem implementação testável |
| T7: migration + model + factory `slug_reservations` | 1 tabela | ✅ Granular |
| T8: migration + model + factory `short_links` | 1 tabela | ✅ Granular |
| T9: migration + model + factory `link_destination_versions` | 1 tabela | ✅ Granular |
| T10: 2 portas + 2 adaptadores de ID | 1 padrão repetido | ⚠️ OK — mesma implementação, testes gêmeos |
| T11: `LinksServiceProvider` | 1 provider | ✅ Granular |
| T12: `RedirectsServiceProvider` | 1 provider | ✅ Granular |
| T13: `phpstan.neon` | 1 arquivo de config | ✅ Granular |
| T14: regra de arquitetura | 1 regra | ✅ Granular |
| T15: script de gate | 1 script + wiring | ✅ Granular |

Nenhuma task ❌ — nada precisa ser dividido.

---

## Diagram-Definition Cross-Check

| Task | Depends On (corpo) | Diagrama mostra | Status |
| --- | --- | --- | --- |
| T1 | None | (raiz) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T1 | T1 → T3 | ✅ Match |
| T4 | T1 | T1 → T4 | ✅ Match |
| T5 | T1 | T1 → T5 | ✅ Match |
| T6 | T3, T5 | T3 → T6, T5 → T6 | ✅ Match |
| T7 | T2 | T2 → T7 | ✅ Match |
| T8 | T7 | T7 → T8 | ✅ Match |
| T9 | T8 | T8 → T9 | ✅ Match |
| T10 | T1 | T1 → T10 | ✅ Match |
| T11 | T6, T10 | T6 → T11, T10 → T11 | ✅ Match |
| T12 | T11 | T11 → T12 | ✅ Match |
| T13 | T12 | T12 → T13 | ✅ Match |
| T14 | T12 | T12 → T14 | ✅ Match |
| T15 | T12 | T12 → T15 | ✅ Match |

Nenhuma task depende de task de fase posterior. `T4` e `T9` não têm dependentes nesta fatia (`LinkStatusResolver` é consumido pelas fatias 6/9/10; `link_destination_versions` pelas fatias 3/4/8) — intencional.

---

## Test Co-location Validation

| Task | Camada criada/modificada | Matriz exige | Task diz | Status |
| --- | --- | --- | --- | --- |
| T1 | Domain Exception + VOs; config `phpunit.xml` | unit (maior) | unit | ✅ OK |
| T2 | Domain VO | unit | unit | ✅ OK |
| T3 | Domain VO | unit | unit | ✅ OK |
| T4 | Domain Enum + Service puro | unit | unit | ✅ OK |
| T5 | Config + Infrastructure/Crypto | unit (maior) | unit | ✅ OK |
| T6 | Contracts + Domain VO + Exception + Infrastructure/Crypto | unit | unit | ✅ OK |
| T7 | Migration + Model + Factory; config | integration (maior) | integration | ✅ OK |
| T8 | Migration + Model + Factory | integration | integration | ✅ OK |
| T9 | Migration + Model + Factory | integration | integration | ✅ OK |
| T10 | Contracts + Infrastructure/Identity | unit | unit | ✅ OK |
| T11 | ServiceProvider + rotas; config | feature (maior) | feature | ✅ OK |
| T12 | ServiceProvider + rotas; config | feature (maior) | feature | ✅ OK |
| T13 | Config (`phpstan.neon`) | none (build gate) | none | ✅ OK |
| T14 | Regra de arquitetura | architecture | architecture | ✅ OK |
| T15 | Script de gate + config + docs | unit (maior) | unit | ✅ OK |

Nenhuma ❌ VIOLATION. Nenhuma task adia testes para outra — `T13` é a única com `Tests: none`, e a matriz classifica `phpstan.neon` como camada de config coberta por build gate (`composer run analyse` dentro de `make lint` é a verificação).

---

## Rastreabilidade requisito → task

| Requirement ID | Tasks |
| --- | --- |
| LNK-01 | T1, T11 |
| LNK-02 | T12 |
| LNK-03 | T7, T8, T9 |
| LNK-04 | T5, T6 |
| LNK-05 | T15 |
| LFND-01 | T11, T12 |
| LFND-02 | T10, T11 |
| LFND-03 | T11, T12 |
| LFND-04 | T13, T14 |
| LFND-05 | T1, T7, T8 |
| LFND-06 | T8 |
| LFND-07 | T8, T9 |
| LFND-08 | T9 |
| LFND-09 | T2 |
| LFND-10 | T3 |
| LFND-11 | T4 |
| LFND-12 | T4 |
| LFND-13 | T6 |
| LFND-14 | T6 |
| LFND-15 | T6 |
| LFND-16 | T5, T6 |
| LFND-17 | T1, T7, T11, T12 |
| LFND-18 | T1, T12 |
| LFND-19 | T15 |
| LFND-20 | T15 |

**Coverage:** 25 requisitos, 25 mapeados para tasks, 0 não mapeados ✅

---

## MCPs e Skills

Proposta por task já preenchida no campo **Tools**. Resumo:

- **Context7 MCP** — apenas em T5 e T6 (config Laravel 13 e API `openssl_*` com AAD/tag). AGENTS.md manda usar Context7 para bibliotecas de terceiros.
- **Skills** — nenhuma. A fatia é backend puro, sem UI, sem OpenAPI (contratos HTTP só nas fatias 4+).
- **Restrições operacionais** (AGENTS.md): migrations criadas com `php artisan make:migration` **dentro do container**; todos os comandos via Docker; commits sem `Co-authored-by`; nunca push direto na `main`.

Confirme ou ajuste antes do Execute.
