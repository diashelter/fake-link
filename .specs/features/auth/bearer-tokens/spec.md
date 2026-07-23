# Auth — Tokens Bearer

**Status:** Fechada — confirmada 2026-07-23  
**Fatia:** 2 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-13 … AUTH-19, AUTH-33 (parcial), AUTH-37, AUTH-38  
**Requirement IDs (fatia):** BT-01 … BT-18  
**Depende de:** [foundation](../foundation/spec.md)

## Problem Statement

Endpoints autenticados das fatias 3–7 e dos módulos consumidores (Links, Analytics) precisam de uma infraestrutura única de tokens Bearer: persistência por hash, emissão, validação, revogação, expiração absoluta e por inatividade, restrição por `token_kind` e identidade autenticada exportável.

A fundação entregou `users` e domínio compartilhado, mas ainda não existe `auth_tokens`, middleware de autenticação nem contrato de identidade para outros módulos. Sem esta fatia, registro, login, perfil e recursos privados não têm como autenticar requisições de forma consistente com `docs/api.md` §3.1 e `docs/security.md` §6.

## Goals

- [ ] Migration `auth_tokens` com constraints, FK para `users.id` (UUID v7) e índices de lookup por hash.
- [ ] Domínio e persistência de tokens: tipos `verification` e `session`, hash SHA-256, TTL absoluto e idle por tipo.
- [ ] Use cases: emitir, validar, revogar um token, revogar todos por usuário.
- [ ] Middleware `Authorization: Bearer` com respostas `401 UNAUTHENTICATED` e checagem de status de conta.
- [ ] Restrição de rota por `token_kind` permitido com `403 TOKEN_RESTRICTED`.
- [ ] Throttle de `last_used_at`: no máximo uma escrita a cada 15 minutos por token válido.
- [ ] Contrato exportável `AuthenticatedPrincipal` para outros módulos e base de ownership com `404` uniforme.
- [ ] Testes Pest cobrindo emissão, validação, expiração, idle, revogação, throttle, middleware e policies; gates `make lint` e `make test-backend` verdes.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Endpoints HTTP públicos de Auth (`register`, `login`, `logout`, …) | Fatias 3–7 |
| `email_action_tokens` | Fatias `email-verification` e `password` |
| Rate limiting por endpoint | Cada fatia de endpoint (`docs/api.md` §8) |
| BFF, cookies, Redis de sessão, cifra AES-GCM | Frontend / ADR 0002 |
| Listagem de tokens, `device_name`, abilities, tokens de integração | MVP explícito em `docs/api.md` §3.1 |
| Revogação automática por suspensão operacional | AUTH-40 / Fase 4 (`Operations`) |
| Bloqueio de emissão por status na validação de credenciais | Fatia `login` (AUTH-09 … AUTH-11) |
| `UserResource` e representação HTTP do `User` | Fatia `session-and-profile` |
| Rotas de negócio reais além de rota(s) de teste interna do middleware | Fatias consumidoras |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| PK de `auth_tokens.id` | **UUID v7** gerado na aplicação | AD-012; paridade com demais entidades | y |
| FK `auth_tokens.user_id` | **UUID v7** → `users.id` | AD-010/AD-012 | y |
| Formato do plaintext do token | 32 bytes CSPRNG (`random_bytes(32)`), codificados em **base64url sem padding** (~43 caracteres) | Entropia de 256 bits; valor compacto no header Bearer | y |
| Hash persistido | **SHA-256** do plaintext, hex lowercase **64** chars em `char(64)` | `docs/data-model.md`; padrão Laravel para tokens opacos (ex.: Sanctum) | y |
| Lookup de token | Somente por `token_hash` (único); plaintext nunca é recuperável | AUTH-14; minimiza vazamento em logs de ID | y |
| `expires_at` na emissão | `verification`: `now + 24h`; `session`: `now + 7d` | `docs/api.md` §3.1; `docs/security.md` §5.2 | y |
| Idle expiry | Referência: `last_used_at ?? created_at`; limites: `verification` 1h, `session` 24h | OpenAPI `AuthData`; idle não estende `expires_at` absoluto | y |
| Ordem de validação | (1) parse Bearer → (2) hash lookup → (3) revogado/ausente → (4) `expires_at` absoluto → (5) idle → (6) status da conta → (7) `token_kind` da rota | Falhas genéricas antes de revelar estado da conta quando possível | y |
| Token ausente/inválido/expirado/revogado | `401` + código `UNAUTHENTICATED` | `docs/openapi.yaml` `Unauthenticated` | y |
| `token_kind` não permitido na rota | `403` + código `TOKEN_RESTRICTED` | `docs/openapi.yaml` `Forbidden` / `docs/api.md` §7 | y |
| Token válido + conta `suspended` / `deletion_pending` | `403` + `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` | Login bloqueia emissão; requisições autenticadas refletem status | y |
| Revogação | **DELETE** físico da linha (sem `revoked_at`) | Modelo enxuto em `docs/data-model.md`; logout = remoção | y |
| AUTH-33 (parcial) nesta fatia | Entregar **UseCase** `RevokeAllUserTokens`; endpoints HTTP ficam em `password` e `session-and-profile` | README catálogo AUTH-33 | y |
| Atualização de `last_used_at` | Somente após token considerado válido; UPDATE condicional `last_used_at IS NULL OR last_used_at < now() - interval '15 minutes'` | AUTH-17; evita write por request e não “ressuscita” token inválido | y |
| Middleware alias | `AuthenticateBearer` registrado como alias `auth.bearer` | Padrão Laravel 11+ bootstrap; composição com restrição de kind | y |
| Restrição de `token_kind` | Middleware dedicado `RequireTokenKind` (ou equivalente) + parâmetro de rota `session`, `verification` ou lista | AUTH-19; rotas declaram kind exigido explicitamente | y |
| Identidade exportável | Contract `AuthenticatedPrincipal` em `Modules\Auth\Contracts\` com `userId`, `userStatus`, `tokenKind`, `tokenId`, `expiresAt` | AUTH-37; outros módulos não importam Eloquent | y |
| Ownership uniforme | Trait/base `AuthorizesOwnedResource` + exceção mapeada para `404 RESOURCE_NOT_FOUND` quando owner difere | AUTH-38; existência confidencial | y |
| Emissão vs validação de status | Emissão (fatias 3–4) decide kind; **validação** rejeita token se status da conta impede o kind (ex.: `session` com `pending_verification` → `403 TOKEN_RESTRICTED`) | Consistência com `docs/testing.md` §6.1 | y |
| Rota de teste HTTP | Uma rota interna em `routes/api.php` ou arquivo de rotas do módulo, prefixo `/api/v1/_test/auth`, **somente `APP_ENV=testing`**, para feature tests do middleware | Endpoints reais chegam nas fatias 3–7; gate exige prova HTTP | y |
| Local dos testes | `modules/Auth/Tests/{Unit,Integration,Feature}/` | Paridade com fundação e `docs/testing.md` §3.1 | y |
| Banco de testes | PostgreSQL **`fake_link_testing`** exclusivamente | AD-011 | y |
| Migration | Criada via `php artisan make:migration` no container; arquivo em `backend/database/migrations/` | Regra do projeto | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions (fatia bearer-tokens)

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Bearer header parseado estritamente (`Bearer <token>`); token plaintext length fixa da codificação base64url de 32 bytes; kinds limitados ao enum |
| Failure / partial-failure states | Token inválido → 401 uniforme; falha no UPDATE de `last_used_at` não invalida request já autenticado (best-effort write) |
| Idempotency / retry / duplicate | Revogar token já revogado é no-op idempotente; revalidar mesmo token dentro de 15 min não gera write extra |
| Auth boundaries & rate limits | Rate limits por endpoint fora desta fatia; middleware não implementa throttling de leitura |
| Concurrency / ordering | Unicidade de `token_hash`; UPDATE condicional de `last_used_at`; emissões concorrentes geram hashes distintos |
| Data lifecycle / expiry | TTL absoluto + idle; linhas removidas na revogação; cascata na exclusão de conta tratada em fatia futura |
| Observability | Plaintext do token SHALL NOT aparecer em logs, exceções serializadas ou telemetria |
| External-dependency failure | N/A — somente PostgreSQL |
| State-transition integrity | Validação consulta `users.status` atual; não emite token nesta fatia |

---

## Entregáveis técnicos

### Migration `auth_tokens`

| Campo | Regra |
| --- | --- |
| `id` | UUID v7 PK, gerado na aplicação |
| `user_id` | UUID v7 NOT NULL, FK `users.id` ON DELETE RESTRICT |
| `token_hash` | `char(64)` NOT NULL UNIQUE — SHA-256 hex lowercase do plaintext |
| `token_kind` | text NOT NULL, `CHECK (token_kind IN ('verification','session'))` |
| `expires_at` | timestamptz NOT NULL — teto absoluto |
| `last_used_at` | timestamptz nullable |
| `created_at` | timestamptz NOT NULL UTC |

Índices: UNIQUE em `token_hash`; índice em `user_id` para revogação em massa.

### Domínio

- **`TokenKind`** — enum backed string: `verification`, `session`.
- **`AuthTokenId`** — VO UUID v7 (mesmo padrão de validação que `UserId`).
- **`AuthToken`** — entidade (id, userId, tokenKind, expiresAt, lastUsedAt, createdAt); sem plaintext.
- **`TokenHasher` port** — `hash(plainText): string`, `verify(plainText, hash): bool` (implementação SHA-256).
- **`PlainAuthToken` / `IssuedAuthToken` DTO** — transporte na emissão: plaintext **uma única vez**, kind, expiresAt, userId.

### Use cases

| Use case | Responsabilidade |
| --- | --- |
| `IssueAuthToken` | Gera plaintext, persiste hash + metadados, retorna `IssuedAuthToken` |
| `ValidateAuthToken` | Resolve Bearer → principal ou falha de domínio mapeável a 401/403 |
| `RevokeAuthToken` | Remove token pelo hash ou id |
| `RevokeAllUserTokens` | Remove todas as linhas de `user_id` (AUTH-33 parcial) |

### Infraestrutura HTTP

- **`AuthenticateBearer`** — extrai header, chama `ValidateAuthToken`, injeta `AuthenticatedPrincipal` no container/request attributes.
- **`RequireTokenKind`** — após autenticação, compara `AuthenticatedPrincipal::tokenKind` com kinds permitidos na rota.
- **`AuthorizesOwnedResource`** (ou Policy base) — compara `AuthenticatedPrincipal::userId` com owner do recurso; mismatch → exceção → `404 RESOURCE_NOT_FOUND`.

### Contrato exportável (AUTH-37)

```php
// Modules\Auth\Contracts\Authentication\AuthenticatedPrincipal
// userId: UserId
// userStatus: UserStatus
// tokenKind: TokenKind
// tokenId: AuthTokenId
// expiresAt: DateTimeImmutable
```

Outros módulos dependem do **contract**, não de models Eloquent.

---

## User Stories

### P1: Persistência e emissão de token ⭐ MVP

**User Story**: Como fatia de login/registro, quero emitir tokens Bearer persistidos por hash para entregar credencial ao cliente uma única vez.

**Why P1**: Sem emissão persistida, login e registro não têm o que retornar.

**Acceptance Criteria**:

1. WHEN `IssueAuthToken` é chamado com `userId` válido e kind `verification` THEN SHALL persistir linha com `expires_at = created_at + 24 hours`, hash SHA-256 único e retornar plaintext **somente** no DTO de saída.
2. WHEN `IssueAuthToken` é chamado com kind `session` THEN SHALL definir `expires_at = created_at + 7 days`.
3. WHEN o mesmo plaintext é emitido duas vezes (dois tokens distintos) THEN cada linha SHALL ter `token_hash` distinto.
4. WHEN o repositório é consultado após emissão THEN SHALL NOT existir coluna ou campo recuperável com o plaintext.
5. WHEN `token_kind` inválido é passado THEN SHALL falhar antes de persistir.

**Independent Test**: Teste de integração em `fake_link_testing` emite token, assert hash no banco ≠ plaintext e `expires_at` correto por kind.

**Requirement IDs**: AUTH-13, AUTH-14, AUTH-15, BT-01, BT-02, BT-03, BT-04

---

### P1: Validação Bearer e expiração ⭐ MVP

**User Story**: Como middleware HTTP, quero validar `Authorization: Bearer` com TTL absoluto e idle para proteger rotas privadas.

**Why P1**: AUTH-18 é pré-requisito de qualquer endpoint autenticado.

**Acceptance Criteria**:

1. WHEN header ausente ou não `Bearer <token>` THEN middleware SHALL responder `401` com corpo `code: UNAUTHENTICATED`.
2. WHEN hash não encontrado THEN SHALL responder `401 UNAUTHENTICATED` (mesma forma que token inválido).
3. WHEN `now >= expires_at` THEN SHALL responder `401 UNAUTHENTICATED` **sem** atualizar `last_used_at`.
4. WHEN `now - coalesce(last_used_at, created_at) > idle_ttl` do kind THEN SHALL responder `401 UNAUTHENTICATED` **sem** estender `expires_at` (idle: `verification` 1h, `session` 24h).
5. WHEN token válido THEN SHALL disponibilizar `AuthenticatedPrincipal` com `userId`, `userStatus`, `tokenKind`, `tokenId`, `expiresAt`.
6. WHEN token válido e `user.status` é `suspended` THEN SHALL responder `403 ACCOUNT_SUSPENDED`.
7. WHEN token válido e `user.status` é `deletion_pending` THEN SHALL responder `403 ACCOUNT_PENDING_DELETION`.

**Independent Test**: Feature test HTTP contra rota `/_test/auth` com tokens fabricados e relógio controlado (`Carbon::setTestNow`).

**Requirement IDs**: AUTH-15, AUTH-16, AUTH-18, BT-05, BT-06, BT-07

---

### P1: Throttle de `last_used_at` ⭐ MVP

**User Story**: Como operador, quero limitar writes de `last_used_at` para evitar uma escrita por requisição autenticada.

**Why P1**: AUTH-17; requisito explícito de performance e `docs/security.md` §6.

**Acceptance Criteria**:

1. WHEN token válido é usado e `last_used_at` IS NULL THEN SHALL persistir `last_used_at = now()`.
2. WHEN token válido é usado e `last_used_at` anterior ≥ 15 minutos THEN SHALL atualizar `last_used_at`.
3. WHEN token válido é usado e `last_used_at` anterior < 15 minutos THEN SHALL NOT executar UPDATE (request continua autenticado).
4. WHEN token expirado ou revogado é apresentado THEN SHALL NOT atualizar `last_used_at`.

**Independent Test**: Integração com duas requisições sequenciais dentro de 15 min → uma write; após avançar relógio 15 min → segunda write.

**Requirement IDs**: AUTH-17, BT-08

---

### P1: Restrição por `token_kind` ⭐ MVP

**User Story**: Como API, quero declarar quais kinds podem acessar cada rota para separar Restricted Session de sessão completa.

**Why P1**: AUTH-19; `PATCH /me` exige `session`, verificação de e-mail exige `verification`.

**Acceptance Criteria**:

1. WHEN rota exige kind `session` e principal autenticado é `verification` THEN SHALL responder `403` com `code: TOKEN_RESTRICTED`.
2. WHEN rota exige kind `verification` e principal é `session` THEN SHALL responder `403 TOKEN_RESTRICTED`.
3. WHEN rota aceita ambos (`session` ou `verification`) e token válido THEN SHALL permitir.
4. WHEN rota exige `session` e usuário ainda `pending_verification` com token `session` (estado inconsistente) THEN SHALL responder `403 TOKEN_RESTRICTED`.

**Independent Test**: Feature tests na rota de teste com middleware `RequireTokenKind:session` e tokens de cada kind.

**Requirement IDs**: AUTH-19, BT-09, BT-10

---

### P1: Revogação ⭐ MVP

**User Story**: Como fluxos de logout e segurança, quero revogar um token ou todos os tokens de uma conta.

**Why P1**: Logout e AUTH-33 dependem da infraestrutura de revogação.

**Acceptance Criteria**:

1. WHEN `RevokeAuthToken` recebe hash ou id de token existente THEN SHALL remover a linha.
2. WHEN `RevokeAuthToken` recebe token inexistente THEN SHALL completar sem erro (idempotente).
3. WHEN `RevokeAllUserTokens` é chamado para `userId` THEN SHALL remover **todas** as linhas daquele usuário.
4. WHEN token revogado é apresentado no Bearer THEN middleware SHALL responder `401 UNAUTHENTICATED`.

**Independent Test**: Integração emite → revoga → assert count 0 → HTTP 401.

**Requirement IDs**: AUTH-33 (parcial), BT-11, BT-12

---

### P1: Identidade e ownership para outros módulos ⭐ MVP

**User Story**: Como módulo Links, quero consumir identidade autenticada e aplicar ownership sem acoplar ao Eloquent de Auth.

**Why P1**: AUTH-37 e AUTH-38 são contratos arquiteturais em `docs/architecture.md` §4.1.

**Acceptance Criteria**:

1. WHEN middleware autentica THEN `AuthenticatedPrincipal` SHALL estar registrado no container Laravel resolvível por contract.
2. WHEN policy/trait de ownership detecta `resource.userId !== principal.userId` THEN SHALL lançar exceção mapeada para HTTP `404` com `code: RESOURCE_NOT_FOUND`.
3. WHEN resource pertence ao principal THEN SHALL permitir (retorno delegado ao caller).
4. WHEN módulo consumidor importa identidade THEN SHALL depender apenas de `Modules\Auth\Contracts\`, não de `Infrastructure/Persistence`.

**Independent Test**: Teste unitário/feature da trait/base com resource stub; Pest Arch opcional para dependência unidirecional.

**Requirement IDs**: AUTH-37, AUTH-38, BT-13, BT-14

---

### P2: Observabilidade e segurança do plaintext

**User Story**: Como mantenedor, quero garantir que tokens completos nunca vazem em logs ou exceções.

**Why P2**: Requisito transversal de `docs/security.md` §6 e §13.

**Acceptance Criteria**:

1. WHEN emissão, validação ou revogação falham THEN mensagens de exceção e logs SHALL NOT conter o plaintext do Bearer.
2. WHEN exception handler serializa erro de autenticação THEN corpo público SHALL ser apenas códigos documentados (`UNAUTHENTICATED`, etc.).

**Independent Test**: Teste unitário inspeciona mensagem de exceções simuladas com token marcador.

**Requirement IDs**: BT-15

---

## Edge Cases

- Header `Authorization: bearer` (caixa incorreta) → tratar como ausente (`401`).
- Token com espaços extras após trim → rejeitar se não bater hash.
- Múltiplos headers Authorization → comportamento indefinido do framework; testar apenas caso canônico single header.
- Relógio exatamente em `expires_at` → token expirado (`now >= expires_at`).
- Idle exatamente no limite (`now - reference == idle_ttl`) → token **válido** (limite exclusivo: expira quando **maior** que idle_ttl).
- Usuário deletado com tokens existentes → FK RESTRICT impede delete de user com tokens; exclusão de conta é fluxo assíncrono futuro.
- Colisão de hash (extremamente improvável) → emissão falha por UNIQUE e deve gerar novo plaintext.
- `RevokeAllUserTokens` com zero tokens → no-op success.

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| BT-01 | P1: Persistência | Migration `auth_tokens` + constraints | Execute | Pending |
| BT-02 | P1: Persistência | Enum `TokenKind` + entidade `AuthToken` | Execute | Pending |
| BT-03 | P1: Persistência | `TokenHasher` SHA-256 + port | Execute | Pending |
| BT-04 | P1: Persistência | `IssueAuthToken` + repository | Execute | Pending |
| BT-05 | P1: Validação | `ValidateAuthToken` | Execute | Pending |
| BT-06 | P1: Validação | Middleware `AuthenticateBearer` | Execute | Pending |
| BT-07 | P1: Validação | Checagem de status de conta | Execute | Pending |
| BT-08 | P1: Throttle | UPDATE condicional `last_used_at` | Execute | Pending |
| BT-09 | P1: Kind | Middleware `RequireTokenKind` | Execute | Pending |
| BT-10 | P1: Kind | Resposta `403 TOKEN_RESTRICTED` | Execute | Pending |
| BT-11 | P1: Revogação | `RevokeAuthToken` | Execute | Pending |
| BT-12 | P1: Revogação | `RevokeAllUserTokens` | Execute | Pending |
| BT-13 | P1: Identidade | Contract `AuthenticatedPrincipal` | Execute | Pending |
| BT-14 | P1: Ownership | Base/trait `404` uniforme | Execute | Pending |
| BT-15 | P2: Segurança | Plaintext ausente de logs/exceções | Execute | Pending |
| BT-16 | P1: Validação | Rota HTTP de teste (`APP_ENV=testing`) | Execute | Pending |
| BT-17 | P1: Persistência | `AuthTokenRepository` + factory de teste | Execute | Pending |
| BT-18 | P1: Docs | Verificar alinhamento `docs/data-model.md` §3 `auth_tokens` | Execute | Pending |
| AUTH-13 | P1: Persistência | Kinds `verification` e `session` | Execute | Pending |
| AUTH-14 | P1: Persistência | Armazenamento por hash | Execute | Pending |
| AUTH-15 | P1: Validação | TTL absoluto por tipo | Execute | Pending |
| AUTH-16 | P1: Validação | Expiração por inatividade | Execute | Pending |
| AUTH-17 | P1: Throttle | Throttle 15 min `last_used_at` | Execute | Pending |
| AUTH-18 | P1: Validação | Header `Authorization: Bearer` | Execute | Pending |
| AUTH-19 | P1: Kind | Restrição de endpoint por kind | Execute | Pending |
| AUTH-33 | P1: Revogação | Revogação em massa (UseCase only) | Execute | Pending |
| AUTH-37 | P1: Identidade | Identidade autenticada exportável | Execute | Pending |
| AUTH-38 | P1: Ownership | Policies ownership → `404` | Execute | Pending |

**Coverage:** 27 total, 27 mapped, 0 unmapped

---

## Success Criteria

- [ ] `make lint` e `make test-backend` passam com infraestrutura Bearer introduzida.
- [ ] Testes usam exclusivamente `fake_link_testing`.
- [ ] Emissão retorna plaintext uma vez; banco contém apenas hash de 64 chars hex.
- [ ] TTL absoluto e idle por kind comportam-se conforme `docs/api.md` §3.1.
- [ ] `last_used_at` respeita throttle de 15 minutos em testes com relógio controlado.
- [ ] Middleware retorna `401 UNAUTHENTICATED`, `403 TOKEN_RESTRICTED` e códigos de conta conforme OpenAPI.
- [ ] `AuthenticatedPrincipal` resolvível por outros módulos via contract.
- [ ] Fatias `registration` e `login` podem iniciar consumindo `IssueAuthToken` sem alterar schema `users`.
- [ ] `docs/data-model.md` §3 `auth_tokens` alinhado com UUID v7 (AD-012).

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Lint + análise estática | `make lint` |
| Testes backend | `make test-backend` — PostgreSQL `fake_link_testing` only |
| Cobertura (quando aplicável) | `make test-backend-coverage` — meta 80/80 em código novo de Auth tokens |
| Migration | `php artisan migrate` no container (env testing) |
| Arquitetura | `ModularMonolithTest` — consumidores não importam Eloquent Auth |
| Contrato HTTP | Respostas de erro alinhadas a `docs/openapi.yaml` (`UNAUTHENTICATED`, `TOKEN_RESTRICTED`) |

---

## Referências

- [Índice Auth](../README.md)
- [Fundação](../foundation/spec.md)
- `docs/api.md` §3.1, §7
- `docs/security.md` §6, §7
- `docs/data-model.md` §3 (`auth_tokens`)
- `docs/testing.md` §6.1 (Bearer, idle, ownership)
- `docs/architecture.md` §4.1, §6
- `docs/openapi.yaml` — `AuthData`, `Unauthenticated`, `Forbidden`
- `LARAVEL_CODE_DESIGN.md` — UseCases, Contracts, middleware na borda
