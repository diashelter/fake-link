# Links — Fundação dos módulos

**Status:** Fechada — confirmada 2026-08-30
**Fatia:** 1 de 13 — ver [índice](../README.md)
**Requirement IDs (catálogo):** LNK-01 … LNK-05
**Requirement IDs (fatia):** LFND-01 … LFND-20
**Depende de:** Fase 0 (Docker, quality gates) e módulo [Auth](../../auth/README.md) verificado

---

## Problem Statement

Não existe nenhum código dos módulos `Links` e `Redirects` no backend — `backend/modules/` contém apenas `Auth/`. Antes de qualquer endpoint, é preciso um scaffold hexagonal conforme `docs/architecture.md` §4.0, o esquema persistente base e o keyring de criptografia de destinos, para que as fatias 2–13 só acrescentem comportamento sem renegociar estrutura.

Sem essa fundação, cada fatia seguinte inventaria seu próprio local para migrations, ports e testes, e o gate de arquitetura Pest (que já enumera `Modules\Links` e `Modules\Redirects` de forma vácua em `backend/tests/Architecture/ModularMonolithTest.php`) continuaria passando sem exercer nenhuma restrição real.

## Goals

- [ ] `backend/modules/Links/` e `backend/modules/Redirects/` criados e registrados, com autoload PSR-4 já existente (`Modules\` → `modules/`).
- [ ] Um `ServiceProvider` por módulo, registrado em `bootstrap/providers.php`, com arquivo de rotas próprio e sem rotas de negócio.
- [ ] Três migrations base (`slug_reservations`, `short_links`, `link_destination_versions`) aplicáveis em PostgreSQL, com UUID v7, FKs `RESTRICT`, `CHECK`, unicidade e índices de `docs/data-model.md` §4.
- [ ] Value Objects `Slug` e `DestinationUrl` (invariantes estruturais mínimas) e `LinkStatus` com derivação completa de precedência.
- [ ] Porta `DestinationCipher` + adaptador AES-256-GCM com keyring versionado externo à persistência (`key_id` para rotação).
- [ ] Suítes dos dois módulos descobertas por `make test-backend`; gate de cobertura 90% linhas / 85% métodos aplicável aos módulos novos.
- [ ] `make lint` e `make test-backend` passam sem regressão; PHPStan analisa os dois módulos.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Endpoints, Controllers, Form Requests e Resources | Fatias 4, 6, 7, 8 e 10 |
| Regras completas de slug (Base36, denylist, retentativa, reserva) | Fatia [slug-policy](../slug-policy/spec.md) |
| Regras completas de destino (SSRF, normalização, portas, userinfo) | Fatia [destination-policy](../destination-policy/spec.md) |
| UseCases de criação, atualização e consulta de link | Fatias 4, 6 e 7 |
| Tabela `idempotency_keys` e keyring de snapshots | Fatia [idempotency](../idempotency/spec.md) |
| Cache Redis, keyring de cache, TTL e invalidação | Fatia [redirect-cache](../redirect-cache/spec.md) |
| Porta de resolução efetiva de slug | Fatia [resolution-contract](../resolution-contract/spec.md) |
| Superfície HTTP do host curto (`GET`/`HEAD /{slug}`) | Fatia [redirect-http](../redirect-http/spec.md); placeholders atuais em `backend/routes/web.php` permanecem intactos |
| Tabelas de analytics, `click_events`, agregados e particionamento | Fase 3 |
| `audit_events` e comandos `Operations` | Fase 4 |
| Rotação efetiva de chaves em produção (procedimento operacional) | Runbook de segurança; a fundação entrega o mecanismo (`key_id`) e testes de round-trip multi-chave |
| Entrada de PHPMD para os módulos | `composer md` hoje analisa somente `app` (inclusive para Auth); mudar o escopo do PHPMD é decisão transversal fora desta fatia |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Providers de rotas | **Um por módulo**: `Modules\Links\ServiceProviders\LinksServiceProvider` e `Modules\Redirects\ServiceProviders\RedirectsServiceProvider`, ambos em `bootstrap/providers.php` | Precedente `AuthServiceProvider`; mantém a fronteira `docs/architecture.md` §4.2/§4.3 e evita acoplar Redirects a Links no bootstrap | y |
| Local do keyring de destinos | **Porta e adaptador em `Links`**: `Modules\Links\Contracts\Services\DestinationCipher` + `Modules\Links\Infrastructure\Crypto\Aes256GcmDestinationCipher` | Vocabulário é de Link (destino); `Shared` só recebe contratos sem owner de domínio (`docs/architecture.md` §4.6). Nenhum módulo `Shared` é criado nesta fatia | y |
| Granularidade das migrations | **Três arquivos**, timestamps crescentes na ordem `slug_reservations` → `short_links` → `link_destination_versions` | Ordem de FK garantida por ordenação; rollback granular e diff legível | y |
| Value Objects nesta fatia | `Slug` e `DestinationUrl` como **VOs de tipo** (invariantes estruturais mínimas); `LinkStatus` **completo**, incluindo derivação de precedência | Migrations e repositórios precisam de tipos já na fundação; políticas completas ficam nas fatias 2 e 3 sem esvaziá-las | y |
| Invariantes mínimas de `Slug` nesta fatia | ASCII minúsculo (`a-z`, `0-9`, `-`), comprimento 1–48, não vazio; valor de entrada é rejeitado se contiver maiúsculas (sem normalização silenciosa) | Alinha ao tipo `varchar(48)` e à PK de `slug_reservations`; normalização de alias e denylist são da fatia 2 | y |
| Invariantes mínimas de `DestinationUrl` nesta fatia | Esquema `http` ou `https`, comprimento ≤2.048 caracteres, host não vazio; sem checagem de rede privada, userinfo ou canonicalização | Limite e esquema já são contratos de schema/segurança (`docs/data-model.md` §4, `docs/security.md` §8.1); política completa é da fatia 3 | y |
| `LinkStatus` | Enum backed string com `active`, `inactive`, `expired`, `blocked` + função pura de derivação a partir de (`blocked_at`, `expires_at`, `is_enabled`, `now`) | `docs/data-model.md` §4 exige derivação, nunca coluna; a fatia 9 consome esse cálculo sem reimplementá-lo | y |
| Estado efetivo nunca persistido | Nenhuma coluna `status`/`effective_status` em `short_links` | `docs/data-model.md` §4 | y |
| Semântica de `expires_at` | Limite **exclusivo**: `expires_at <= now()` ⇒ `expired`; `expires_at > now()` ⇒ não expirado | `docs/data-model.md` §4 | y |
| Geração de IDs | UUID v7 gerado na aplicação para `short_links.id` e `link_destination_versions.id`, via ports `ShortLinkIdGenerator` e `LinkDestinationVersionIdGenerator` (precedente `Uuid7UserIdGenerator`) | AD-010, AD-012; ports permitem determinismo em teste | y |
| `short_links.version` | `bigint NOT NULL`, valor inicial `1`, incrementado pela aplicação; nunca gravável pelo cliente | `docs/data-model.md` §4; concorrência otimista é das fatias 7 e 9 | y |
| FK de slug | `short_links.slug` referencia `slug_reservations.slug` com `ON DELETE RESTRICT ON UPDATE RESTRICT`, e é `UNIQUE` em `short_links` | `docs/data-model.md` §4 e §9 | y |
| `short_links.user_id` | FK para `users.id` com `RESTRICT`; imutabilidade é regra de domínio documentada, sem trigger nesta fatia | `docs/data-model.md` §4/§9 | y |
| Envelope de cifra | Valor persistido em `link_destination_versions.destination_url` é **texto único autocontido** com versão do envelope, nonce (12 bytes) e tag GCM (16 bytes); `key_id` fica em coluna própria e **não** entra no material cifrado | `docs/data-model.md` §4/§10; `key_id` em coluna permite rotação e consulta sem decifrar | y |
| AAD do envelope | O envelope usa AAD contendo `key_id` e a versão do envelope, para impedir troca cruzada de envelopes | `docs/security.md` §14 (autenticação sempre validada antes do uso) | y |
| Keyring de destinos | Config `backend/config/links.php`: mapa `key_id → chave de 32 bytes` + `active_key_id`; carregado de env (`LINKS_DESTINATION_KEYRING`, `LINKS_DESTINATION_ACTIVE_KEY_ID`) | `docs/security.md` §8.1/§14: chave fora do PostgreSQL, imagem e repositório; keyring versionado e separado do de cache | y |
| Cifra sempre com a chave ativa; decifra por `key_id` | `encrypt()` usa `active_key_id`; `decrypt()` seleciona a chave pelo `key_id` do registro | Rotação sem recriptografar tudo | y |
| Falha de decifra | Exceção de domínio `DestinationDecryptionFailed`, sem devolver plaintext parcial; mapeamento HTTP (`503`) fica na fatia 10/11 | `docs/security.md` §9 e `docs/testing.md` §6.4 | y |
| Chave ausente/ inválida na config | Boot do adaptador falha explicitamente (chave ≠ 32 bytes ou `active_key_id` ausente do keyring) em vez de degradar para cifra fraca | `docs/security.md` §14 | y |
| Destino em logs | URL de destino em claro, chaves e envelope **SHALL NOT** aparecer em log, exceção serializada, trace ou métrica | `docs/security.md` §13; `docs/testing.md` §6.3 | y |
| Rotas nesta fatia | Cada provider carrega um arquivo de rotas próprio **sem nenhuma rota definida**; `LinksServiceProvider` reserva o prefixo `api/v1/links`, `RedirectsServiceProvider` reserva a superfície do host curto | Evita superfície HTTP antes das fatias 4 e 10; garante que o wiring existe e é testável | y |
| Placeholders do host curto | `backend/routes/web.php` (`/`, `/robots.txt`, `/{slug}` → 404) permanece inalterado nesta fatia | Substituição é entrega da fatia 10; alterar aqui quebraria smoke tests existentes | y |
| Roteamento por `server_name` | Continua sendo responsabilidade do Nginx (AD-006); nenhum `domain()` de rota é introduzido aqui | Decisão de infraestrutura já vigente | y |
| Pastas vazias | Somente pastas com arquivo real são criadas (`Domain/`, `Contracts/`, `Infrastructure/`, `ServiceProviders/`, `Tests/`); `UseCases/` e `DTOs/` nascem nas fatias que os usam | `LARAVEL_CODE_DESIGN.md` §6.2; precedente Auth | y |
| Eloquent Models desta fatia | `ShortLinkModel`, `SlugReservationModel`, `LinkDestinationVersionModel` em `Modules\Links\Infrastructure\Persistence\Eloquent\Models` + factories determinísticas | Necessários para testes de constraint; `Redirects` não define Models próprios | y |
| Repositórios | Nenhum repositório de escrita nesta fatia além do necessário para provar constraints; UseCases de criação são da fatia 4 | Evita antecipar regras das fatias 2–4 | y |
| Testes de schema | Asserções via `information_schema` / `pg_catalog`, não por leitura do arquivo de migration | `docs/testing.md` §3.1; evidência real do banco aplicado | y |
| Banco de testes | Exclusivamente `fake_link_testing` | AD-011 | y |
| Suítes no `phpunit.xml` | Adicionar `modules/Links/Tests/{Unit,Feature,Integration}` e `modules/Redirects/Tests/{Unit,Feature,Integration}` às suítes existentes e `modules/Links`, `modules/Redirects` ao `<source><include>` | `docs/testing.md` §3.1; suítes não registradas não são descobertas por `make test-backend` | y |
| Gate de cobertura | Generalizar `backend/scripts/check-auth-coverage-gate.php` em `check-module-coverage-gate.php` com limiares por módulo (Auth 80/80, Links 90/85, Redirects 90/85), preservando o comportamento atual do gate Auth | `docs/testing.md` §4 fixa 90%/85% para Links e Redirects | y |
| Proxy de branches | Cobertura de **métodos** substitui branches (PCOV) | `docs/testing.md` §4 | y |
| PHPStan | Incluir `modules/Links` e `modules/Redirects` em `backend/phpstan.neon` `paths` | Paridade com `modules/Auth` | y |
| Gate de arquitetura | As regras já enumeram `Links` e `Redirects` em `ModularMonolithTest.php`; esta fatia adiciona a regra de que `Modules\Redirects` **não** usa `Modules\Links\Infrastructure` nem `Modules\Links\Domain` (só `Contracts`/`DTOs`) | `docs/architecture.md` §4.3/§5; hoje a regra passa vácua | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions (fatia foundation)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | `Slug` 1–48 ASCII minúsculo; `DestinationUrl` ≤2.048 e esquema `http`/`https`; `title` ≤160; `slug_source` restrito por `CHECK` |
| Failure / partial-failure states | Decifra que falha na tag ou com `key_id` desconhecido lança exceção de domínio sem plaintext; inserts que violam FK/CHECK/unicidade falham no PostgreSQL |
| Idempotency / retry / duplicate | N/A — sem endpoints nesta fatia; `idempotency_keys` é da fatia 5. Duplicidade de slug já é barrada por PK/UNIQUE |
| Auth boundaries & rate limits | N/A — sem superfície HTTP nesta fatia; ownership e rate limit ficam nas fatias de endpoint |
| Concurrency / ordering | Índice parcial único `(short_link_id) WHERE valid_to IS NULL` garante uma única versão atual sob concorrência; PK de `slug_reservations` é a autoridade contra colisão |
| Data lifecycle / expiry | Reserva de slug é permanente (nunca removida); `expires_at` é limite exclusivo e derivado em `LinkStatus`, nunca persistido como estado |
| Observability | Destino em claro, material de chave e envelope ausentes de log, exceção, trace e métrica |
| External-dependency failure | N/A — sem Redis, fila ou HTTP externo nesta fatia; chave ausente/ inválida falha explicitamente no boot do adaptador |
| State-transition integrity | `LinkStatus` deriva de `blocked_at` > `expires_at` > `is_enabled` > `active`; nenhuma transição é persistida |

---

## Entregáveis técnicos

### Estrutura de pastas

```txt
backend/modules/Links/
  Domain/
    Enums/            # LinkStatus
    ValueObjects/     # Slug, DestinationUrl, ShortLinkId, LinkDestinationVersionId
    Services/         # LinkStatusResolver (derivação de precedência)
  Contracts/
    Services/         # DestinationCipher, ShortLinkIdGenerator, LinkDestinationVersionIdGenerator
  Exceptions/         # InvalidSlug, InvalidDestinationUrl, DestinationDecryptionFailed
  Infrastructure/
    Crypto/           # Aes256GcmDestinationCipher, DestinationKeyring
    Identity/         # Uuid7ShortLinkIdGenerator, Uuid7LinkDestinationVersionIdGenerator
    Http/routes/      # links.php (vazio nesta fatia)
    Persistence/Eloquent/
      Models/         # ShortLinkModel, SlugReservationModel, LinkDestinationVersionModel
      Factories/
  ServiceProviders/
    LinksServiceProvider.php
  Tests/{Unit,Integration}/

backend/modules/Redirects/
  Infrastructure/
    Http/routes/      # redirects.php (vazio nesta fatia)
  ServiceProviders/
    RedirectsServiceProvider.php
  Tests/Feature/
```

Pastas sem arquivo real (`UseCases/`, `DTOs/`) **não** são criadas nesta fatia.

### Migrations

**1. `slug_reservations`**

| Campo | Regra |
| --- | --- |
| `slug` | `varchar(48)` PK, ASCII minúsculo |
| `reserved_at` | `timestamptz NOT NULL` |

Sem proprietário, sem referência ao link; reserva nunca é removida.

**2. `short_links`**

| Campo | Regra |
| --- | --- |
| `id` | `uuid` PK (UUID v7 gerado na aplicação) |
| `user_id` | `uuid NOT NULL`, FK → `users.id` `RESTRICT` |
| `slug` | `varchar(48) NOT NULL`, `UNIQUE`, FK → `slug_reservations.slug` `RESTRICT` |
| `slug_source` | `text NOT NULL`, `CHECK (slug_source IN ('automatic','custom'))` |
| `title` | `varchar(160)` NULL |
| `is_enabled` | `boolean NOT NULL` |
| `blocked_at` | `timestamptz` NULL |
| `expires_at` | `timestamptz` NULL |
| `version` | `bigint NOT NULL` |
| `created_at`, `updated_at` | `timestamptz NOT NULL` |

Índice por `user_id` para as consultas privadas da fatia 6.

**3. `link_destination_versions`**

| Campo | Regra |
| --- | --- |
| `id` | `uuid` PK (UUID v7) |
| `short_link_id` | `uuid NOT NULL`, FK → `short_links.id` `RESTRICT` |
| `destination_url` | `text NOT NULL` — envelope AES-256-GCM |
| `key_id` | `varchar NOT NULL` |
| `valid_from` | `timestamptz NOT NULL` |
| `valid_to` | `timestamptz` NULL |

- Índice parcial `UNIQUE (short_link_id) WHERE valid_to IS NULL`.
- `CHECK (valid_to IS NULL OR valid_to > valid_from)`.
- Índice `(short_link_id, valid_from DESC)`.

### Keyring de destinos

| Artefato | Regra |
| --- | --- |
| `backend/config/links.php` | `destination.keyring` (`key_id` → chave 32 bytes) e `destination.active_key_id`, ambos de env |
| `DestinationCipher` (porta) | `encrypt(string $plaintext): EncryptedDestination` (envelope + `key_id`); `decrypt(string $envelope, string $keyId): string` |
| `Aes256GcmDestinationCipher` | AES-256-GCM, nonce aleatório de 12 bytes por operação, tag de 16 bytes verificada, AAD com versão do envelope + `key_id` |
| Falhas | `key_id` desconhecido, tag inválida, envelope malformado ou chave ≠ 32 bytes ⇒ exceção; nunca plaintext parcial |
| Isolamento | Keyring de destinos ≠ keyring de cache (fatia 11) ≠ keyring de idempotência (fatia 5) |

---

## User Stories

### P1: Scaffold e registro dos módulos ⭐ MVP

**User Story**: Como mantenedor, quero os módulos `Links` e `Redirects` registrados no Laravel para que as fatias 2–13 acrescentem comportamento sem renegociar estrutura.

**Why P1**: Nenhuma fatia seguinte compila ou passa nos gates modulares sem namespace, provider e wiring.

**Acceptance Criteria**:

1. WHEN a aplicação boota THEN os namespaces `Modules\Links` e `Modules\Redirects` SHALL autoloadar e `LinksServiceProvider` e `RedirectsServiceProvider` SHALL constar em `backend/bootstrap/providers.php`.
2. WHEN um teste resolve `Modules\Links\Contracts\Services\DestinationCipher` do container THEN SHALL receber a implementação `Aes256GcmDestinationCipher` (registro provado por resolução, não apenas por boot bem-sucedido).
3. WHEN um teste resolve `ShortLinkIdGenerator` e `LinkDestinationVersionIdGenerator` do container THEN SHALL receber as implementações UUID v7 registradas pelo `LinksServiceProvider`.
4. WHEN a lista de rotas da aplicação é inspecionada após o boot THEN SHALL NOT existir nenhuma rota sob `api/v1/links` nem nova rota do host curto além dos placeholders já presentes em `backend/routes/web.php`.
5. WHEN `./vendor/bin/phpstan analyse` roda no container THEN `modules/Links` e `modules/Redirects` SHALL estar nos `paths` analisados e o exit code SHALL ser 0.
6. WHEN a suíte Architecture roda THEN SHALL existir regra falhando se `Modules\Redirects` usar `Modules\Links\Infrastructure` ou `Modules\Links\Domain`, e as regras existentes de Controller/Eloquent SHALL cobrir os dois módulos.
7. WHEN `make lint` e `make test-backend` rodam com os módulos introduzidos THEN SHALL passar sem regressão nas suítes existentes.

**Independent Test**: Feature test que resolve as três portas do container, inspeciona a route collection e roda a suíte Architecture; `make lint && make test-backend` verdes.

**Requirement IDs**: LNK-01, LNK-02, LFND-01, LFND-02, LFND-03, LFND-04

---

### P1: Esquema base persistente ⭐ MVP

**User Story**: Como desenvolvedor, quero as três tabelas base aplicadas em PostgreSQL para persistir links, reservas e versões de destino nas fatias seguintes.

**Why P1**: Fatias 2–8 dependem de schema canônico com constraints reais; SQLite não valida `CHECK`, FK `RESTRICT` nem índice parcial.

**Acceptance Criteria**:

1. WHEN `php artisan migrate` roda contra `fake_link_testing` THEN `slug_reservations`, `short_links` e `link_destination_versions` SHALL existir, verificadas por `information_schema`/`pg_catalog`, com os tipos da tabela de entregáveis (`id`/`user_id`/`short_link_id` do tipo `uuid`, `slug` `varchar(48)`, `version` `bigint`, timestamps `timestamptz`).
2. WHEN um `short_links` é inserido com `slug` sem reserva correspondente THEN a operação SHALL falhar por violação de FK.
3. WHEN uma linha de `slug_reservations` referenciada por um `short_links` é excluída THEN a operação SHALL falhar por `RESTRICT` (reserva permanente).
4. WHEN dois `short_links` são inseridos com o mesmo `slug` THEN o segundo insert SHALL falhar por violação de unicidade.
5. WHEN um `short_links` é inserido com `slug_source` fora de `{automatic, custom}` THEN a operação SHALL falhar pelo `CHECK`.
6. WHEN dois `link_destination_versions` do mesmo `short_link_id` são inseridos com `valid_to IS NULL` THEN o segundo insert SHALL falhar pelo índice parcial único.
7. WHEN um `link_destination_versions` é inserido com `valid_to <= valid_from` THEN a operação SHALL falhar pelo `CHECK`.
8. WHEN um `users` referenciado por um `short_links` é excluído THEN a operação SHALL falhar por `RESTRICT`.
9. WHEN o schema de `short_links` é inspecionado THEN SHALL NOT existir coluna de estado efetivo (`status`, `effective_status` ou equivalente).
10. WHEN um `short_links` é criado pela aplicação THEN `id` SHALL ser UUID v7 válido gerado antes da persistência e `version` SHALL ser `1`.
11. WHEN as migrations são revertidas (`migrate:rollback`) THEN SHALL desfazer na ordem inversa sem violar FK.

**Independent Test**: Integration tests em `fake_link_testing` que aplicam as migrations, consultam `information_schema` e provocam cada violação (FK, RESTRICT, UNIQUE, CHECK, índice parcial).

**Requirement IDs**: LNK-03, LFND-05, LFND-06, LFND-07, LFND-08

---

### P1: Value Objects e estado efetivo derivado ⭐ MVP

**User Story**: Como sistema, quero tipos de domínio para slug, destino e estado do link para que as fatias seguintes não tratem esses valores como string crua nem dupliquem a precedência de estado.

**Why P1**: `resolution-contract`, `redirect-http` e `link-queries` derivam o mesmo estado efetivo; duplicar a regra garante divergência.

**Acceptance Criteria**:

1. WHEN `Slug::fromString('abc-123')` é construído THEN SHALL expor o valor `abc-123`.
2. WHEN `Slug::fromString` recebe valor com maiúscula, caractere fora de `[a-z0-9-]`, string vazia ou com mais de 48 caracteres THEN SHALL lançar exceção de domínio (sem normalização silenciosa).
3. WHEN `Slug::fromString` recebe valor com exatamente 1 e exatamente 48 caracteres válidos THEN SHALL construir com sucesso.
4. WHEN `DestinationUrl::fromString` recebe URL `http`/`https` com host não vazio e ≤2.048 caracteres THEN SHALL construir e preservar o valor recebido sem alterar query string ou fragmento.
5. WHEN `DestinationUrl::fromString` recebe esquema diferente de `http`/`https`, host vazio ou comprimento >2.048 THEN SHALL lançar exceção de domínio.
6. WHEN `DestinationUrl::fromString` recebe URL com exatamente 2.048 caracteres THEN SHALL construir com sucesso.
7. WHEN o estado efetivo é derivado com `blocked_at` não nulo THEN SHALL ser `blocked`, mesmo com `expires_at` no futuro e `is_enabled = true`.
8. WHEN `blocked_at` é nulo e `expires_at <= now` THEN SHALL ser `expired`, mesmo com `is_enabled = false`.
9. WHEN `blocked_at` é nulo, não expirado e `is_enabled = false` THEN SHALL ser `inactive`.
10. WHEN `blocked_at` é nulo, `expires_at` nulo ou futuro e `is_enabled = true` THEN SHALL ser `active`.
11. WHEN `expires_at` é exatamente igual a `now` THEN SHALL ser `expired` (limite exclusivo).
12. WHEN `LinkStatus` é enumerado THEN SHALL expor exatamente `active`, `inactive`, `expired`, `blocked`.

**Independent Test**: Testes unitários sem banco, com relógio injetado, cobrindo a matriz de precedência (incluindo blocked+expired+disabled simultâneos) e os limites de `Slug`/`DestinationUrl`.

**Requirement IDs**: LFND-09, LFND-10, LFND-11, LFND-12

---

### P1: Keyring e cifra de destinos ⭐ MVP

**User Story**: Como sistema, quero cifrar e decifrar destinos com chave externa à persistência e `key_id` versionado para que URLs nunca fiquem em claro no PostgreSQL e a rotação seja possível sem migração de dados.

**Why P1**: `docs/security.md` §8.1 exige cifra na aplicação antes do PostgreSQL desde a primeira persistência de destino (fatia 4).

**Acceptance Criteria**:

1. WHEN uma URL é cifrada e imediatamente decifrada com o mesmo `key_id` THEN o plaintext recuperado SHALL ser exatamente igual ao original, incluindo query string e fragmento.
2. WHEN a mesma URL é cifrada duas vezes THEN os dois envelopes SHALL ser diferentes (nonce único por operação).
3. WHEN um envelope é decifrado com `key_id` diferente do usado na cifra THEN SHALL lançar `DestinationDecryptionFailed` e SHALL NOT retornar plaintext.
4. WHEN um byte do ciphertext, do nonce ou da tag é alterado THEN a decifra SHALL falhar com `DestinationDecryptionFailed`.
5. WHEN o envelope tem versão desconhecida ou formato inválido THEN a decifra SHALL falhar com `DestinationDecryptionFailed`.
6. WHEN um envelope produzido com uma chave antiga presente no keyring é decifrado THEN SHALL retornar o plaintext original, mesmo que `active_key_id` aponte para outra chave.
7. WHEN uma URL é cifrada THEN o `key_id` retornado SHALL ser o `active_key_id` da config e SHALL ser persistido em coluna própria, fora do material cifrado.
8. WHEN a config é inspecionada THEN a chave SHALL vir de env (`LINKS_DESTINATION_KEYRING` / `LINKS_DESTINATION_ACTIVE_KEY_ID`), SHALL NOT estar versionada no repositório e SHALL NOT residir no PostgreSQL.
9. WHEN `active_key_id` não existe no keyring, ou uma chave do keyring não tem 32 bytes THEN a construção do adaptador SHALL falhar explicitamente (sem fallback para cifra mais fraca).
10. WHEN cifra ou decifra falha THEN a URL em claro, o material de chave e o envelope SHALL NOT aparecer em log, mensagem de exceção, trace ou métrica.
11. WHEN o envelope persistido é inspecionado THEN SHALL NOT conter a URL em claro em nenhuma forma legível (o valor SHALL diferir do plaintext e não o conter como substring).

**Independent Test**: Testes unitários de round-trip, mutação de bytes, `key_id` cruzado e rotação (duas chaves no keyring) + teste com `Log::fake`/spy provando ausência de plaintext em logs.

**Requirement IDs**: LNK-04, LFND-13, LFND-14, LFND-15, LFND-16

---

### P1: Descoberta de suítes e gate de cobertura ⭐ MVP

**User Story**: Como mantenedor, quero as suítes dos dois módulos descobertas e cobertas pelos gates para que testes novos não fiquem invisíveis ao CI.

**Why P1**: Suíte não registrada em `phpunit.xml` não roda em `make test-backend` — falha já observada no módulo Auth.

**Acceptance Criteria**:

1. WHEN `make test-backend` roda THEN os diretórios `modules/Links/Tests/{Unit,Feature,Integration}` e `modules/Redirects/Tests/{Unit,Feature,Integration}` existentes SHALL estar registrados nas suítes de `backend/phpunit.xml` e seus testes SHALL aparecer na contagem executada.
2. WHEN `make test-backend-coverage` roda THEN `modules/Links` e `modules/Redirects` SHALL constar em `<source><include>` e SHALL gerar relatório em `storage/coverage/modules/{Links,Redirects}/index.html`.
3. WHEN o gate de cobertura roda THEN SHALL falhar se linhas de `Links` ou `Redirects` ficarem abaixo de **90%** ou se métodos (proxy de branches) ficarem abaixo de **85%**.
4. WHEN o gate de cobertura roda para `Auth` THEN SHALL preservar o limiar atual de 80% linhas / 80% métodos, sem regressão de comportamento.
5. WHEN um teste com I/O de banco executa THEN `DB_DATABASE` SHALL ser `fake_link_testing` (AD-011).
6. WHEN `docs/testing.md` é inspecionado THEN SHALL refletir o gate por módulo de `Links` e `Redirects` (90%/85%) e o script generalizado.

**Independent Test**: Rodar `make test-backend` e conferir que testes dos módulos novos aparecem na contagem; rodar `make test-backend-coverage` e provar que o gate falha ao ser executado contra um relatório abaixo do limiar.

**Requirement IDs**: LNK-05, LFND-17, LFND-18, LFND-19, LFND-20

---

## Edge Cases

- `Slug` com exatamente 1 e 48 caracteres → aceito; 0 e 49 → rejeitado.
- `Slug` com hífens consecutivos ou nas bordas → **aceito** nesta fatia (regra de alias é da fatia 2); documentado para não gerar falsa sensação de cobertura.
- `Slug` com maiúscula → rejeitado, não normalizado (normalização é responsabilidade da fatia 2, antes de construir o VO).
- `DestinationUrl` com exatamente 2.048 e 2.049 caracteres → aceito e rejeitado, respectivamente.
- `DestinationUrl` com IP literal, host privado ou userinfo → **aceito** nesta fatia; bloqueio é da fatia 3 (documentado explicitamente).
- Link simultaneamente bloqueado, expirado e desabilitado → `blocked` (precedência mais alta vence).
- `expires_at` exatamente igual a `now` → `expired` (limite exclusivo).
- `expires_at` nulo → nunca expira.
- Envelope cifrado com chave removida do keyring → `DestinationDecryptionFailed`, sem plaintext.
- Nonce reutilizado → impossível por construção (nonce aleatório por operação); provado por dois envelopes distintos da mesma URL.
- Insert de segunda versão aberta do mesmo link → rejeitado pelo índice parcial único, não por checagem em PHP.
- `migrate:rollback` com dados presentes → falha por `RESTRICT` se as folhas não forem removidas antes (comportamento esperado, `docs/data-model.md` §9).

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| LNK-01 | P1: Scaffold | Scaffold hexagonal do módulo `Links` | Design | Pending |
| LNK-02 | P1: Scaffold | Scaffold hexagonal do módulo `Redirects` | Design | Pending |
| LFND-01 | P1: Scaffold | Providers registrados em `bootstrap/providers.php` | Design | Pending |
| LFND-02 | P1: Scaffold | Ports resolvíveis do container | Design | Pending |
| LFND-03 | P1: Scaffold | Nenhuma rota de negócio registrada | Design | Pending |
| LFND-04 | P1: Scaffold | PHPStan + Pest Arch cobrem os módulos e seam Redirects↛Links | Design | Pending |
| LNK-03 | P1: Schema | Três migrations base aplicadas | Design | Pending |
| LFND-05 | P1: Schema | Tipos e colunas por `information_schema` | Design | Pending |
| LFND-06 | P1: Schema | FKs `RESTRICT` e unicidade de slug | Design | Pending |
| LFND-07 | P1: Schema | `CHECK` de `slug_source` e de vigência | Design | Pending |
| LFND-08 | P1: Schema | Índice parcial único de versão atual | Design | Pending |
| LFND-09 | P1: Domínio | `Slug` com invariantes estruturais | Design | Pending |
| LFND-10 | P1: Domínio | `DestinationUrl` com esquema e limite | Design | Pending |
| LFND-11 | P1: Domínio | Enum `LinkStatus` | Design | Pending |
| LFND-12 | P1: Domínio | Derivação de precedência do estado efetivo | Design | Pending |
| LNK-04 | P1: Keyring | Keyring de destinos com chave externa à persistência | Design | Pending |
| LFND-13 | P1: Keyring | Round-trip AES-256-GCM com nonce único | Design | Pending |
| LFND-14 | P1: Keyring | Falha autenticada (tag, `key_id`, formato) | Design | Pending |
| LFND-15 | P1: Keyring | Rotação por `key_id` com chave antiga | Design | Pending |
| LFND-16 | P1: Keyring | Ausência de plaintext e chave em logs/telemetria | Design | Pending |
| LNK-05 | P1: Gates | Suítes registradas e cobertura dos módulos | Design | Pending |
| LFND-17 | P1: Gates | `phpunit.xml` descobre as suítes novas | Design | Pending |
| LFND-18 | P1: Gates | `<source><include>` cobre os módulos | Design | Pending |
| LFND-19 | P1: Gates | Gate 90%/85% para Links e Redirects, 80/80 preservado para Auth | Design | Pending |
| LFND-20 | P1: Gates | `docs/testing.md` atualizado | Design | Pending |

**Coverage:** 25 total, 0 mapeados para tasks (Tasks pendente)

---

## Success Criteria

- [ ] `make lint` e `make test-backend` passam com os dois módulos introduzidos.
- [ ] `php artisan migrate` e `migrate:rollback` aplicam e revertem as três tabelas em `fake_link_testing`.
- [ ] Cada constraint listada (FK `RESTRICT`, `UNIQUE`, `CHECK`, índice parcial) é provada por um teste que a viola e falha no PostgreSQL.
- [ ] Nenhum destino em claro aparece em banco, log, exceção ou trace nos testes da fatia.
- [ ] `make test-backend-coverage` reporta ≥90% linhas e ≥85% métodos para `Links` e `Redirects`, com o gate Auth inalterado.
- [ ] A suíte Architecture falha se `Modules\Redirects` importar `Modules\Links\Infrastructure` (discrimination sensor preparado).
- [ ] As fatias `slug-policy` e `destination-policy` podem iniciar sem alterar schema, providers ou mover pastas.

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Lint + análise estática | `make lint` (PHPStan com `modules/Links`, `modules/Redirects`) |
| Testes backend | `make test-backend` — PostgreSQL `fake_link_testing` only |
| Cobertura | `make test-backend-coverage` — 90% linhas / 85% métodos nos módulos novos |
| Migrations | `php artisan migrate` + `migrate:rollback` no container (env testing) |
| Schema | Integration tests via `information_schema` / `pg_catalog` |
| Arquitetura | `backend/tests/Architecture/ModularMonolithTest.php` (incl. seam Redirects ↛ Links) |
| Criptografia | Testes unitários de round-trip, mutação e rotação de `key_id` |

---

## Referências

| Documento | Uso |
| --- | --- |
| [Índice Links](../README.md) | Ordem das fatias e catálogo `LNK-XX` |
| `docs/architecture.md` §4.0, §4.2, §4.3, §4.6, §5 | Camadas, papel dos módulos e limites de dependência |
| `docs/data-model.md` §4, §9, §10 | Esquema das três tabelas base, constraints e criptografia |
| `docs/security.md` §8.1, §9, §14 | URL policy, keyrings e segredos |
| `docs/testing.md` §2, §3.1, §4, §6.3 | Ambiente, camadas, gates de cobertura e casos obrigatórios de Links |
| `LARAVEL_CODE_DESIGN.md` | Padrões hexagonais e Object Calisthenics |
| `.specs/features/auth/foundation/spec.md` | Precedente de fatia de fundação |
| `.specs/STATE.md` | AD-006, AD-010, AD-011, AD-012 |
