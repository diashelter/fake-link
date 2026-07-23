# Auth — Fundação do módulo

**Status:** Fechada — confirmada 2026-07-23  
**Fatia:** 1 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-06, AUTH-07, AUTH-08  
**Requirement IDs (fatia):** FND-01 … FND-11

## Problem Statement

Antes de expor endpoints HTTP, o módulo `Auth` precisa existir como unidade hexagonal registrada no Laravel, com domínio compartilhado, política de senha e persistência inicial de `users`. Sem essa base, fatias seguintes (tokens, registro, login) não têm onde ancorar regras nem migrations.

O skeleton Laravel atual traz `App\Models\User`, migration `users` com `bigint` e tabelas legadas (`password_reset_tokens`, `sessions`, `remember_token`) incompatíveis com autenticação Bearer documentada. A fundação substitui esse legado pelo módulo `Modules\Auth` sem quebrar gates de qualidade já verdes.

**Desvio documentado:** `docs/data-model.md` §3 ainda descreve ULID para `users.id`. Esta fatia estabelece **UUID v7** como identificador canônico de `User`; a sincronização de `docs/data-model.md`, `docs/api.md` e demais referências ULID→UUID v7 para contas ocorre durante o Execute (tarefa de alinhamento documental).

## Goals

- [ ] Estrutura `backend/modules/Auth/` criada conforme `LARAVEL_CODE_DESIGN.md` e `docs/architecture.md` §4.
- [ ] Migration `users` com campos, constraints e enums alinhados ao modelo acordado (UUID v7, status, termos).
- [ ] Domínio compartilhado: entidade `User` mínima, `UserStatus`, normalização de e-mail.
- [ ] Política de senha validável e hash Argon2id encapsulados (sem vazar regra para Form Requests).
- [ ] `AuthServiceProvider` registra bindings e composição do módulo; rotas de negócio ausentes.
- [ ] Legado `App\Models\User` e artefatos Laravel default de sessão/reset removidos; `UserModel` no módulo Auth.
- [ ] Banco PostgreSQL dedicado **`fake_link_testing`** para testes; execuções de teste nunca usam banco de desenvolvimento ou produção.
- [ ] Testes Pest cobrem política de senha, normalização de e-mail, persistência de `User` e gates arquiteturais.
- [ ] `make test-backend` e `make lint` passam sem regressão; Larastan inclui `modules/Auth/`.

## Out of Scope

| Item | Motivo |
| --- | --- |
| `auth_tokens`, `email_action_tokens` | Fatias `bearer-tokens` e `email-verification` / `password` |
| Endpoints HTTP de Auth | Fatias 3–7 |
| Allowlist de convite | Fatia `registration` |
| Jobs Resend | Fatias `email-verification` e `password` |
| Rate limiting | Cada fatia de endpoint |
| Transições de `User.status` e bloqueio de login | Fatias `registration`, `login`, `email-verification`, `Operations` |
| Representação pública HTTP do `User` (`UserResource`) | Fatia `session-and-profile` |
| Imutabilidade de e-mail no perfil | Fatia `session-and-profile` |
| Calibração de Argon2id em hardware de produção | Checklist pré-lançamento (`docs/security.md` §4.2); fundação entrega config documentada e testável em dev |
| Migração ULID→UUID v7 em módulos ainda não implementados (Links, etc.) | Escopo limitado a `users` nesta fatia; demais entidades seguem em specs próprias |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Local do `ServiceProvider` | `modules/Auth/ServiceProviders/AuthServiceProvider.php` (raiz do módulo, não dentro de `Infrastructure/`) | `docs/architecture.md` §4 e `LARAVEL_CODE_DESIGN.md` §7 | y |
| Substituição da migration skeleton | Alterar `0001_01_01_000000_create_users_table.php` in place (projeto pré-produção, sem dados a preservar) | Evita drift entre schema Laravel default e modelo acordado; uma única fonte desde o bootstrap | y |
| Tabelas Laravel legadas | Remover `password_reset_tokens` e `sessions` da migration inicial; reset usa `email_action_tokens` (fatia posterior); sessão usa Bearer + BFF | ADR 0002; sessões server-side Laravel e reset nativo não fazem parte do produto | y |
| `remember_token` | Não existe na tabela `users` | Autenticação é Bearer; campo não faz parte do modelo | y |
| PK de `users` | **UUID v7** — coluna PostgreSQL `uuid`, gerado na aplicação (time-ordered) | Decisão confirmada pelo mantenedor; substitui ULID para contas | y |
| Formato UUID v7 | RFC 9562; geração via API Laravel/PHP suportada (ex.: `Str::uuid7()`); persistido como tipo `uuid` nativo | Ordenação temporal útil para índices; interoperável com PostgreSQL | y |
| `App\Models\User` | Remover; Eloquent fica em `Modules\Auth\Infrastructure\Persistence\Eloquent\Models\UserModel` com mapper para entidade de domínio | Gates Pest Arch e decisão modular em `docs/decisions.md` | y |
| `Database\Factories\UserFactory` | Realocar para o módulo Auth (ex.: `Modules\Auth\Infrastructure\Persistence\Eloquent\Factories\UserModelFactory`) e apontar para `UserModel` | Testes de persistência e fatias seguintes precisam de factory determinística | y |
| E-mail como Value Object | `EmailAddress` em `Domain/ValueObjects/` com `fromString(string): self`, normalização (trim + lowercase) e validação sintática RFC-like | Padrão em `LARAVEL_CODE_DESIGN.md` §6.2; reutilizado por registro, login e reset | y |
| Senha em memória | Sem VO persistente de plaintext; `PasswordPolicy` valida `string` e `PasswordHasher` port hasheia imediatamente | Minimiza superfície de vazamento; alinha Object Calisthenics do guia | y |
| Definição de “símbolo ASCII” | Caractere imprimível ASCII (`U+0021`–`U+007E`) que não seja letra (`A–Z`, `a–z`) nem dígito (`0–9`) | Consistente com OpenAPI (`docs/openapi.yaml` `Password`) e quatro categorias em `docs/security.md` §4.2 | y |
| Códigos de falha da política de senha | Enum ou exceção de domínio com códigos estáveis (`too_short`, `too_long`, `missing_lowercase`, `missing_uppercase`, `missing_digit`, `missing_symbol`) | Form Requests das fatias HTTP mapeiam para mensagens sem reimplementar regra | y |
| Parâmetros Argon2id | Driver `argon2id` via adaptador Laravel; memória mínima **64 MiB** (`memory_cost` documentado); `time_cost`/`threads` em config versionada com alvo ~250 ms em dev | `docs/security.md` §4.2; calibração prod fica fora desta fatia | y |
| Config de hash | Estender/publicar `config/hashing.php` (ou equivalente Laravel 13) com driver default `argon2id` e parâmetros explícitos | Evita dependência de defaults implícitos entre ambientes | y |
| Status inicial na criação | Repository exige `status` explícito; sem default de banco além de NOT NULL + CHECK | Registro define `pending_verification` (fatia `registration`); evita estado implícito | y |
| `terms_version` / `terms_accepted_at` | Colunas NOT NULL na migration; valores fornecidos pelo UseCase de registro | Modelo acordado; foundation só garante schema | y |
| Rotas do módulo | Nenhuma rota de negócio; provider pode registrar arquivo de rotas vazio ou prefixo `/api/v1/auth` comentado para fatias seguintes | Evita superfície HTTP antes da fatia `registration` | y |
| Local dos testes | Preferencialmente `modules/Auth/Tests/{Unit,Integration,Feature}/`; Pest descobre via autoload ou `phpunit.xml` | `docs/testing.md` §3.1 e `LARAVEL_CODE_DESIGN.md` §26.2 | y |
| Banco dedicado para testes | PostgreSQL database **`fake_link_testing`**, distinto de `fake_link` (dev) e de qualquer banco de produção | Isolamento: testes nunca leem/escrevem dados de dev ou prod | y |
| Execução de testes backend | `make test-backend`, `make test-backend-coverage` e CI SHALL usar `DB_DATABASE=fake_link_testing` + PostgreSQL real; SQLite in-memory deixa de ser o default para o gate principal | Integração com constraints reais; decisão confirmada pelo mantenedor | y |
| Bootstrap do banco de teste | Script de init do Postgres (ex.: `docker/postgres/init/`) ou entrypoint documentado cria `fake_link_testing` e grants; `phpunit.xml` / `.env.testing` fixam `DB_DATABASE=fake_link_testing` | Garante existência do banco em compose local e CI | y |
| Guard de ambiente | Testes com I/O de banco SHALL falhar ou abortar se `DB_DATABASE` apontar para `fake_link` (dev) ou nome de produção documentado | Proteção contra wipe acidental de dados locais | y |
| Testes de repository | Integration com PostgreSQL **`fake_link_testing`**, não SQLite, para constraints e unicidade | Unicidade e CHECK exigem PostgreSQL real | y |
| Cobertura do módulo | Meta 80% linhas / 80% branches quando código existir (`docs/testing.md` §4) | Gate aplicável à introdução do módulo Auth | y |
| PHPStan | Incluir `modules/Auth/` em `phpstan.neon` paths | Paridade com `app/` já analisado | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions (fatia foundation)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Senha 12–128; e-mail ≤254; nome ≤120; símbolo ASCII definido acima |
| Failure / partial-failure states | Validação de senha/e-mail falha com códigos estáveis; violação de unicidade de e-mail propaga erro de persistência |
| Idempotency / retry / duplicate | N/A — sem endpoints HTTP nesta fatia |
| Auth boundaries & rate limits | N/A — rate limits por fatia de endpoint |
| Concurrency / ordering | Unicidade de e-mail garantida por constraint PostgreSQL após normalização |
| Data lifecycle / expiry | N/A — tokens e TTL entram em `bearer-tokens` |
| Observability | Senha plaintext SHALL NOT aparecer em logs, exceções serializadas ou dumps |
| External-dependency failure | N/A — sem integrações externas |
| State-transition integrity | `UserStatus` enum definido; transições válidas testadas nas fatias que alteram status |

---

## Entregáveis técnicos

### Estrutura de pastas

```txt
backend/modules/Auth/
  Domain/
    Entities/
    Enums/
    ValueObjects/
    Services/          # ex.: PasswordPolicy
  UseCases/            # vazio até fatias HTTP
  Contracts/
    Repositories/
    Services/          # ex.: PasswordHasher
  DTOs/
  Exceptions/
  Infrastructure/
    Hashing/
    Persistence/
      Eloquent/
        Models/
        Mappers/
        Repositories/
        Factories/
  ServiceProviders/
    AuthServiceProvider.php
  Tests/
    Unit/
    Integration/
    Feature/
```

Subpastas HTTP (`Infrastructure/Http/...`) entram nas fatias que expõem rotas. Pastas vazias antecipadas **não** devem ser criadas (`LARAVEL_CODE_DESIGN.md` §6.2).

### Migration `users`

| Campo | Regra |
| --- | --- |
| `id` | **UUID v7** PK — tipo PostgreSQL `uuid`, gerado na aplicação |
| `name` | varchar(120), NOT NULL |
| `email` | varchar(254), NOT NULL, UNIQUE, normalizado minúsculas na aplicação |
| `password` | varchar, NOT NULL — hash Argon2id |
| `status` | text NOT NULL, `CHECK (status IN ('pending_verification','active','suspended','deletion_pending'))` |
| `email_verified_at` | timestamptz nullable |
| `terms_version` | varchar NOT NULL |
| `terms_accepted_at` | timestamptz NOT NULL |
| `created_at`, `updated_at` | timestamptz UTC |

E-mail imutável após criação (regra de domínio documentada; enforcement completo na fatia `session-and-profile`).

### Domínio

- **`UserId`** — Value Object ou tipo dedicado encapsulando UUID v7 (string canônica RFC 9562).
- **`UserStatus`** — enum backed string com os quatro estados públicos.
- **`EmailAddress`** — Value Object: trim, lowercase, validação sintática; igualdade por valor normalizado.
- **`User`** — entidade mínima (id UUID v7, name, email, status, timestamps de termos/verificação); sem lógica HTTP.
- **`PasswordPolicy`** — comprimento 12–128; minúscula, maiúscula, dígito e símbolo ASCII; falhas com códigos estáveis.
- **`PasswordHasher` port** + adaptador Argon2id (parâmetros via config; memória ≥64 MiB).

### Infraestrutura

- **`AuthServiceProvider`** — registrado em `bootstrap/providers.php`; bindings de `UserRepository`, `PasswordHasher` e demais ports de saída.
- **`UserRepository` port** + `EloquentUserRepository` com mapper Domain ↔ `UserModel`.
- Autoload PSR-4 `Modules\Auth\` → `modules/Auth/` (já presente em `composer.json`).

### Banco de testes (infra transversal desta fatia)

| Artefato | Regra |
| --- | --- |
| Database | `fake_link_testing` — criado no bootstrap do Postgres, separado de `fake_link` |
| `backend/.env.testing` | `DB_CONNECTION=pgsql`, `DB_HOST=postgres`, `DB_DATABASE=fake_link_testing` |
| `backend/phpunit.xml` | `APP_ENV=testing`; env vars de banco apontam para `fake_link_testing` |
| `Makefile` (`test-backend`, `test-backend-coverage`) | Remove override SQLite; usa PostgreSQL + `fake_link_testing` via compose |
| `docs/testing.md` | Atualizar §2: banco dedicado obrigatório; proibir uso de `fake_link` em testes |
| Init Postgres | Script em `docker/postgres/init/` (ou equivalente) com `CREATE DATABASE fake_link_testing` + grants |

---

## User Stories

### P1: Scaffold e registro do módulo ⭐ MVP

**User Story**: Como mantenedor, quero o módulo Auth registrado no Laravel para implementar fatias seguintes sem retrabalho estrutural.

**Why P1**: Nenhuma outra fatia compila ou passa nos gates modulares sem o namespace e provider.

**Acceptance Criteria**:

1. WHEN a aplicação boota THEN o namespace `Modules\Auth` SHALL autoloadar e `AuthServiceProvider` SHALL estar registrado em `bootstrap/providers.php`.
2. WHEN Pest Arch roda THEN regras modulares SHALL aplicar a `Modules\Auth\*` conforme `backend/tests/Architecture/ModularMonolithTest.php`.
3. WHEN `./vendor/bin/phpstan analyse` roda no container THEN SHALL incluir `modules/Auth/` nos paths analisados com exit code 0.
4. WHEN não há rotas de negócio Auth THEN o health check / suite de testes existente SHALL continuar passando.

**Independent Test**: `make lint && make test-backend` com módulo scaffold presente e zero controllers Auth.

**Requirement IDs**: FND-01, FND-02, FND-03

---

### P1: Banco PostgreSQL dedicado para testes ⭐ MVP

**User Story**: Como desenvolvedor, quero que todos os testes backend usem um banco isolado para nunca afetar dados de desenvolvimento ou produção.

**Why P1**: Testes de integração com migrations e constraints exigem PostgreSQL real; isolamento evita perda de dados locais.

**Acceptance Criteria**:

1. WHEN o container Postgres sobe THEN o database `fake_link_testing` SHALL existir e SHALL ser distinto de `fake_link`.
2. WHEN `make test-backend` ou `make test-backend-coverage` roda THEN SHALL conectar a `fake_link_testing` via PostgreSQL (NOT SQLite, NOT `fake_link`).
3. WHEN `APP_ENV=testing` THEN `DB_DATABASE` SHALL ser `fake_link_testing` (via `.env.testing` e/ou `phpunit.xml`).
4. WHEN um teste de integração executa migrations THEN SHALL aplicá-las somente em `fake_link_testing`.
5. WHEN `docs/testing.md` §2 é inspecionado THEN SHALL documentar o banco dedicado e a proibição de usar dev/prod em testes.

**Independent Test**: Rodar `make test-backend` com Postgres up; verificar via query ou env que conexão usa `fake_link_testing`; dados em `fake_link` permanecem intactos.

**Requirement IDs**: FND-10, FND-11

---

### P1: Migration e persistência de User ⭐ MVP

**User Story**: Como desenvolvedor, quero a tabela `users` e repositório alinhados ao modelo de dados para persistir contas nas fatias de registro e login.

**Why P1**: Registro e login dependem de schema canônico e persistência testável.

**Acceptance Criteria**:

1. WHEN `php artisan migrate` roda no container THEN a tabela `users` SHALL existir com PK **UUID v7** (`uuid`), `CHECK` de `status`, colunas de termos NOT NULL e UNIQUE em `email`.
2. WHEN a migration skeleton é inspecionada THEN SHALL NOT existir `remember_token`, tabela `password_reset_tokens` nem tabela `sessions`.
3. WHEN um `User` é salvo via repository com e-mail `  Foo@Bar.com  ` THEN o valor persistido SHALL ser `foo@bar.com`.
4. WHEN dois registros com o mesmo e-mail normalizado são inseridos THEN a segunda operação SHALL falhar por violação de unicidade.
5. WHEN um registro é persistido THEN campos `terms_version`, `terms_accepted_at` e `status` SHALL ser armazenados conforme fornecidos pelo chamador (sem default silencioso de negócio).
6. WHEN um `User` é criado THEN `id` SHALL ser UUID v7 válido gerado na aplicação antes da persistência.

**Independent Test**: Teste de integração em `fake_link_testing` cria usuário via repository, assert UUID v7, normalização e constraint de e-mail duplicado.

**Requirement IDs**: FND-04, FND-05, FND-06

---

### P1: Domínio compartilhado (e-mail e status) ⭐ MVP

**User Story**: Como sistema, quero tipos de domínio reutilizáveis para e-mail e status de conta para que fatias HTTP não dupliquem normalização nem estados inválidos.

**Why P1**: Registro, login e perfil compartilham as mesmas regras de e-mail e enum de status.

**Acceptance Criteria**:

1. WHEN `EmailAddress::fromString('  A@B.C  ')` é construído THEN SHALL expor valor normalizado `a@b.c`.
2. WHEN `EmailAddress::fromString('not-an-email')` é construído THEN SHALL falhar com exceção de domínio (sem persistir).
3. WHEN `UserStatus` é referenciado THEN SHALL expor exatamente `pending_verification`, `active`, `suspended`, `deletion_pending`.
4. WHEN valor de status fora do enum é atribuído na aplicação THEN SHALL falhar antes de atingir o banco.

**Independent Test**: Testes unitários de `EmailAddress` e `UserStatus` sem banco.

**Requirement IDs**: FND-07

---

### P1: Política de senha e hash ⭐ MVP

**User Story**: Como sistema, quero validar e hashear senhas de forma uniforme para registro, login e fluxos de senha futuros.

**Why P1**: AUTH-06, AUTH-07 e AUTH-08 são requisitos de produto desde a Fase 1.

**Acceptance Criteria**:

1. WHEN a senha tem menos de 12 ou mais de 128 caracteres THEN `PasswordPolicy` SHALL falhar com código estável (`too_short` / `too_long`).
2. WHEN falta minúscula, maiúscula, dígito ou símbolo ASCII THEN a validação SHALL falhar com código estável por categoria faltante.
3. WHEN a senha tem exatamente 12 ou 128 caracteres e cumpre complexidade THEN a validação SHALL passar.
4. WHEN a senha é válida e passada ao `PasswordHasher` THEN o hash persistido SHALL ser verificável com Argon2id e SHALL NOT ser igual ao plaintext.
5. WHEN logs ou mensagens de exceção são emitidos durante validação/hash THEN a senha em claro SHALL NOT aparecer.
6. WHEN a config de hashing é inspecionada THEN o driver SHALL ser `argon2id` com memória mínima equivalente a 64 MiB documentada.

**Independent Test**: Testes unitários de `PasswordPolicy` (limites e complexidade) + teste de `PasswordHasher` round-trip verify.

**Requirement IDs**: AUTH-06, AUTH-07, AUTH-08, FND-08, FND-09

---

## Edge Cases

- E-mail com espaços externos → trim antes de lowercase e validação.
- E-mail differing only by case → colapsa para o mesmo valor normalizado; unicidade impede duplicata.
- Senha no limite exato (12 e 128 caracteres) → aceita se cumprir complexidade.
- Senha com Unicode fora de ASCII → categorias minúscula/maiúscula/dígito/símbolo consideram **somente** ASCII; senha `Café123!Aa` pode falhar em símbolo/dígito conforme definição acima (comportamento documentado nos testes).
- `terms_accepted_at` / `terms_version` ausentes na chamada de persistência → falha de validação/domínio ou SQL NOT NULL (não valores default inventados).
- Tentativa de persistir `status` inválido → rejeitado na aplicação ou pelo `CHECK` do PostgreSQL.
- UUID v7 inválido ou v4 passado ao repository → rejeitado na aplicação antes de persistir.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| FND-01 | P1: Scaffold | Autoload + provider registrado | Execute | Done |
| FND-02 | P1: Scaffold | Pest Arch modular para Auth | Execute | Done |
| FND-03 | P1: Scaffold | PHPStan inclui `modules/Auth/` | Execute | Done |
| FND-10 | P1: Banco teste | Database `fake_link_testing` existe | Execute | Done |
| FND-11 | P1: Banco teste | `make test-backend` usa PG testing, nunca dev/prod | Execute | Done |
| FND-04 | P1: Migration | Schema `users` com UUID v7 | Execute | Done |
| FND-05 | P1: Migration | Remoção legado sessions/reset/remember | Execute | Done |
| FND-06 | P1: Migration | Unicidade e normalização de e-mail | Execute | Done |
| FND-07 | P1: Domínio | `EmailAddress` + `UserStatus` | Execute | Done |
| AUTH-06 | P1: Senha | Comprimento 12–128 | Execute | Done |
| AUTH-07 | P1: Senha | Complexidade (4 categorias ASCII) | Execute | Done |
| AUTH-08 | P1: Senha | Hash Argon2id verificável | Execute | Done |
| FND-08 | P1: Senha | Códigos estáveis de falha da política | Execute | Done |
| FND-09 | P1: Senha | Senha ausente de logs/exceções | Execute | Done |

**Coverage:** 14 total, 14 Done

---

## Success Criteria

- [ ] `make lint` e `make test-backend` passam com o módulo Auth introduzido.
- [ ] Testes backend conectam exclusivamente a `fake_link_testing`; banco `fake_link` de dev permanece intacto após suite completa.
- [ ] Migration aplicável em compose com PostgreSQL (dev e testing).
- [ ] Pest Arch falha se controller Auth importar Eloquent model diretamente (discrimination sensor preparado).
- [ ] Cobertura do código em `modules/Auth/` atinge ≥80% linhas e ≥80% branches nos arquivos de domínio e infraestrutura entregues.
- [ ] Fatia `bearer-tokens` pode iniciar sem alterar schema `users` nem mover pastas do módulo.
- [ ] `docs/data-model.md` §3 sincronizado com UUID v7 para `users.id` (tarefa de alinhamento documental).

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Lint + análise estática | `make lint` |
| Testes backend | `make test-backend` — **PostgreSQL `fake_link_testing` only** |
| Cobertura (quando aplicável) | `make test-backend-coverage` — meta 80/80 em `modules/Auth/` |
| Migration | `php artisan migrate` no container (env testing → `fake_link_testing`) |
| Arquitetura | `backend/tests/Architecture/ModularMonolithTest.php` |
| Isolamento de banco | Assert env `DB_DATABASE=fake_link_testing` durante Pest; doc em `docs/testing.md` §2 |

---

## Referências

- [Índice Auth](../README.md)
- `CONTEXT.md` — `User`, `User Status`
- `docs/data-model.md` §3 (`users`) — **atualizar para UUID v7 no Execute**
- `docs/security.md` §4.2 (senha)
- `docs/decisions.md` — identidade e backend modular
- `docs/architecture.md` §4.1, §4 (estrutura de módulos)
- `docs/testing.md` §2, §3.1, §4, §6.1 — **atualizar §2 com banco dedicado**
- `LARAVEL_CODE_DESIGN.md`
