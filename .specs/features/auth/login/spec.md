# Auth — Login

**Status:** Verified — 2026-07-27  
**Fatia:** 4 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-09 … AUTH-11  
**Requirement IDs (fatia):** LOG-01 … LOG-12  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md)

## Problem Statement

Usuários com conta existente precisam autenticar na API Laravel com e-mail e senha para obter um Bearer utilizável nos fluxos seguintes. O login deve emitir token conforme o `User.status` (`verification` para contas pendentes, `session` para contas ativas), bloquear estados inválidos sem vazar existência de conta em falhas de credencial, e aplicar rate limiting dual conforme `docs/api.md` §8.

## Goals

- [x] `POST /api/v1/auth/login` implementado e alinhado a `docs/openapi.yaml`.
- [x] Use case `LoginUser` com lookup por e-mail normalizado, verificação Argon2id e emissão via `IssueAuthToken`.
- [x] Resposta `401 INVALID_CREDENTIALS` uniforme para e-mail inexistente, senha incorreta e senha incorreta em conta bloqueada, com mitigação de timing oracle via verificação dummy.
- [x] Emissão de `verification` para `pending_verification` e `session` para `active`; bloqueio `403` para `suspended` e `deletion_pending` **somente com credencial correta**.
- [x] Rate limits 5/min por e-mail+IP e 30/min por IP com `429 RATE_LIMIT_EXCEEDED` e `Retry-After`.
- [x] Feature tests E2E cobrindo credencial, status, validação, emissão de token e throttle.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Registro, allowlist de convite | Fatia `registration` (concluída) |
| Verificação de e-mail, reenvio, `email_action_tokens` | Fatia `email-verification` |
| Recuperação e alteração de senha | Fatia `password` |
| Logout, logout-all, `GET/PATCH /me` | Fatia `session-and-profile` |
| Revogação de tokens existentes no login | MVP permite múltiplos tokens; revogação explícita em logout |
| Reenvio automático de e-mail de verificação no login | Endpoint dedicado na fatia `email-verification` |
| BFF, cookies, CSRF, UI | Camada Next.js |
| MFA, lockout persistente, tokens de integração | Fora do MVP (`docs/security.md` §4.2) |
| Comandos `Operations` (suspend, delete, revogação por status) | Fase 4 |

---

## Assumptions & Open Questions

Decisões confirmadas em revisão (2026-07-27).

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Fonte de identidade no login | `UserRepository::findByEmail(EmailAddress)` (novo método no port) | Lookup explícito hexagonal; evita acoplar ao Eloquent no UseCase | y |
| Validação de senha no login | Somente presença e `maxLength: 128`; **sem** revalidar composição (12–128, complexidade) | OpenAPI `LoginRequest` não exige composição; política aplica-se no registro/change | y |
| E-mail inexistente vs senha incorreta | Mesmo envelope `401 INVALID_CREDENTIALS` | Anti-enumeração — `docs/api.md` §3.2, `docs/testing.md` §6.1 | y |
| Senha incorreta em conta bloqueada | `401 INVALID_CREDENTIALS` (mesmo envelope que e-mail inexistente) | Não revelar `suspended`/`deletion_pending` sem credencial correta — decisão de revisão | y |
| Mitigação de timing oracle | Sempre executar `PasswordHasher::verify` — hash real se usuário existe, hash Argon2id dummy pré-computado se não | `docs/testing.md` §6.1 exige tempo observável uniforme; paridade exata de latência em CI não é gate | y |
| Token emitido por status | `pending_verification` → `verification`; `active` → `session` | AUTH-10; `docs/api.md` §3.1 | y |
| Estados bloqueados | `suspended` → `403 ACCOUNT_SUSPENDED`; `deletion_pending` → `403 ACCOUNT_PENDING_DELETION` (**após** senha correta) | AUTH-11; OpenAPI `AccountUnavailable` | y |
| Confiança em `User.status` vs `email_verified_at` | `User.status` é a fonte de verdade para kind do token | Transição para `active` ocorre em `email-verification`; inconsistência de dados é bug operacional | y |
| Tokens pré-existentes no login bem-sucedido | Login **não** revoga tokens anteriores (multi-sessão) | `docs/api.md` §3.1 — logout revoga só o atual — decisão de revisão | y |
| Reenvio de verificação no login | **Não** enfileirar e-mail; apenas emitir token `verification` | Reenvio fica em `email-verification` — decisão de revisão | y |
| HTTP de sucesso | `200` com corpo `AuthResponse` (mesmo schema do registro) | OpenAPI `login` → `AuthIssued` (200); registro permanece `201` | y |
| Rate limit — o que conta | Todas as tentativas `POST /login` na janela (`422`, `401`, `403`, `200`, etc.) | Consistente com registro — decisão de revisão | y |
| Rate limit — ordem de checagem | Ambos limites (e-mail+IP e IP) verificados; exceder **qualquer um** → `429` antes de autenticação | `docs/api.md` §8 lista dois limites de login | y |
| Chaves HMAC Redis | Prefixos distintos `login:email-ip:` e `login:ip:` via `HmacRateLimitKeyFactory` | `docs/security.md` §11 — finalidades separadas | y |
| Janela de decay | 60 segundos para ambos limites de login | “5 por minuto” / “30 por minuto” em `docs/api.md` §8 | y |
| Normalização de e-mail | `EmailAddress::fromString` antes de lookup e chave de rate limit | Mesmo padrão de registro | y |
| Falha na emissão do token após credencial válida | Responder `500 INTERNAL_ERROR` genérico; **não** emitir token parcial | Falha interna; credencial já validada — sem anti-enumeração neste caminho | y |
| Senha, token ou e-mail em logs | Proibidos | `docs/security.md` §13 | y |

**Open questions:** none — all resolved or logged above.

**Dimensões implícitas (Large):**

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | `LoginRequest` OpenAPI; `additionalProperties: false`; e-mail max 254; senha max 128 |
| Failure / partial-failure states | Credencial inválida → `401`; status bloqueado + senha correta → `403`; validação → `422`; rate limit → `429`; falha interna pós-auth → `500` |
| Idempotency / retry / duplicate handling | Sem idempotency key; re-login válido emite **novo** token adicional |
| Auth boundaries & rate limits | Endpoint público; dual throttle HMAC Redis |
| Concurrency / ordering | Dois logins concorrentes válidos → dois tokens distintos persistidos |
| Data lifecycle / expiry | Token emitido com TTL/idle do kind (bearer-tokens); usuário inalterado |
| Observability | Sem e-mail, senha ou token em log/trace/métrica |
| External-dependency failure | Falha DB/emissão → `500` sem detalhe sensível |
| State-transition integrity | Login **não** altera `User.status`; só lê status para decidir token ou bloqueio |

---

## User Stories

### P1: Login bem-sucedido por status da conta ⭐ MVP

**User Story**: Como usuário registrado, quero autenticar com e-mail e senha para receber um Bearer adequado ao estado da minha conta.

**Why P1**: Desbloqueia dashboard (`session`) e fluxos restritos de verificação (`verification`).

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/login` recebe `email` e `password` válidos para usuário `active` THEN o sistema SHALL responder `200` com corpo `AuthResponse` (`docs/openapi.yaml`).
2. WHEN login bem-sucedido de usuário `active` THEN `data.token_kind` SHALL ser `session`, `data.token_type` SHALL ser `Bearer`, e `data.expires_at` SHALL ser now + 604800 segundos (TTL absoluto de session).
3. WHEN login bem-sucedido de usuário `pending_verification` THEN `data.token_kind` SHALL ser `verification` e `data.expires_at` SHALL ser now + 86400 segundos.
4. WHEN login bem-sucedido THEN o plaintext do token SHALL aparecer somente no corpo da resposta e SHALL ser persistido somente como hash em `auth_tokens`.
5. WHEN login bem-sucedido THEN `data.user` SHALL refletir o usuário autenticado (`AuthUserResource`) incluindo `status` atual.
6. WHEN login bem-sucedido THEN nenhum token Bearer preexistente da mesma conta SHALL ser revogado.
7. WHEN login bem-sucedido de usuário `pending_verification` THEN o port `QueueEmailVerification` SHALL NOT ser invocado.

**Independent Test**: Factory `active` → POST login → `200` + `session`; factory `pending_verification` → `200` + `verification`; contagem de `auth_tokens` aumenta sem deletar rows anteriores; `Queue::assertNothingPushed` no login pendente.

**Requirement IDs**: AUTH-09, AUTH-10, LOG-01, LOG-02, LOG-03

---

### P1: Credenciais inválidas ⭐ MVP

**User Story**: Como plataforma, quero respostas uniformes em falhas de credencial para impedir enumeração de contas.

**Why P1**: `docs/security.md` §4.1, `docs/testing.md` §6.1.

**Acceptance Criteria**:

1. WHEN o e-mail normalizado não corresponde a nenhum usuário THEN o sistema SHALL responder `401` com `code=INVALID_CREDENTIALS` e `message=The provided credentials are invalid.`
2. WHEN o usuário existe mas a senha não confere THEN o sistema SHALL responder `401 INVALID_CREDENTIALS` com o **mesmo** `code` e `message` que e-mail inexistente.
3. WHEN o usuário existe com `status` `suspended` ou `deletion_pending` mas a senha não confere THEN o sistema SHALL responder `401 INVALID_CREDENTIALS` (não `403`).
4. WHEN a resposta é `401 INVALID_CREDENTIALS` THEN SHALL NOT existir corpo de usuário, token ou indício de qual motivo específico causou a falha.
5. WHEN o e-mail não existe THEN o UseCase SHALL ainda invocar `PasswordHasher::verify` contra um hash Argon2id dummy antes de retornar `401`.
6. WHEN credencial inválida THEN nenhum token SHALL ser emitido ou persistido.

**Independent Test**: E-mail desconhecido vs senha errada vs senha errada em conta `suspended` → envelopes idênticos (`code`, `message`, status); spy confirma `verify` chamado nos casos de e-mail inexistente; zero novos `auth_tokens`.

**Requirement IDs**: AUTH-09, LOG-04, LOG-05, LOG-12

---

### P1: Bloqueio por status da conta ⭐ MVP

**User Story**: Como plataforma, quero impedir login de contas suspensas ou em exclusão quando a credencial é reconhecida como correta.

**Why P1**: AUTH-11; integridade de lifecycle (`docs/testing.md` §6.1).

**Acceptance Criteria**:

1. WHEN credenciais são válidas e `User.status` é `suspended` THEN o sistema SHALL responder `403` com `code=ACCOUNT_SUSPENDED` e `message=The account is suspended.`
2. WHEN credenciais são válidas e `User.status` é `deletion_pending` THEN o sistema SHALL responder `403` com `code=ACCOUNT_PENDING_DELETION` e `message=The account is pending deletion.`
3. WHEN login é bloqueado por status THEN nenhum token SHALL ser emitido ou persistido.
4. WHEN login é bloqueado por status THEN a resposta SHALL NOT usar `401 INVALID_CREDENTIALS`.

**Independent Test**: Factories `suspended` e `deletion_pending` com senha conhecida → `403` específico, zero tokens novos; mesma conta com senha errada → `401`.

**Requirement IDs**: AUTH-11, LOG-06

---

### P1: Validação de entrada HTTP ⭐ MVP

**User Story**: Como API, quero rejeitar payloads inválidos com erros de validação explícitos sem side effects.

**Why P1**: Contrato OpenAPI e segurança de entrada.

**Acceptance Criteria**:

1. WHEN o JSON contém campos além de `email` e `password` THEN o sistema SHALL responder `422 VALIDATION_FAILED` sem emitir token.
2. WHEN `email` ou `password` estão ausentes THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
3. WHEN `email` é sintaticamente inválido ou excede 254 caracteres THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
4. WHEN `password` excede 128 caracteres THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
5. WHEN validação falha THEN nenhum registro em `auth_tokens` SHALL ser criado.
6. WHEN a requisição não tem `Content-Type: application/json` ou o JSON é malformado THEN o sistema SHALL responder `400 MALFORMED_REQUEST` sem side effects.

**Independent Test**: Matrix de payloads inválidos → `422`/`400` + zero tokens.

**Requirement IDs**: LOG-07

---

### P1: Rate limiting ⭐ MVP

**User Story**: Como plataforma, quero limitar tentativas de login para conter abuso de credenciais.

**Why P1**: `docs/api.md` §8; controles compensatórios (`docs/security.md` §4.2).

**Acceptance Criteria**:

1. WHEN um par e-mail normalizado+IP excede 5 tentativas `POST /api/v1/auth/login` em 60 segundos THEN o sistema SHALL responder `429 RATE_LIMIT_EXCEEDED` com header `Retry-After`.
2. WHEN um IP excede 30 tentativas `POST /api/v1/auth/login` em 60 segundos THEN o sistema SHALL responder `429 RATE_LIMIT_EXCEEDED` com header `Retry-After`.
3. WHEN ambos limites são aplicados THEN exceder **qualquer** deles SHALL produzir `429` antes de executar autenticação.
4. WHEN o rate limit é aplicado THEN as chaves SHALL ser derivadas por HMAC Redis com prefixos de finalidade distintos (`login:email-ip:` e `login:ip:`), sem IP ou e-mail bruto na chave.
5. WHEN rate limit dispara THEN nenhum token adicional SHALL ser emitido naquela tentativa.
6. WHEN tentativas incluem respostas `422`, `401`, `403` ou `200` THEN todas SHALL contabilizar para ambos limites.

**Independent Test**: Sexta tentativa mesmo e-mail+IP em 60s → `429`; 31ª do mesmo IP (e-mails distintos) → `429`; payload inválido (`422`) também incrementa contador; chaves HMAC unit-tested.

**Requirement IDs**: LOG-08, LOG-09

---

### P2: Contrato HTTP e descoberta de testes

**User Story**: Como mantenedor, quero o endpoint registrado, documentado e coberto por testes descobertos pelo suite padrão.

**Why P2**: Gates de CI e OpenAPI design-first.

**Acceptance Criteria**:

1. WHEN a rota é inspecionada THEN `POST /api/v1/auth/login` SHALL estar registrada no módulo Auth com controller fino, Form Request e factories alinhados a `docs/openapi.yaml`.
2. WHEN `make test-backend` roda THEN testes Feature em `modules/Auth/Tests/Feature/LoginTest.php` (ou equivalente) SHALL ser descobertos.
3. WHEN a resposta é `200` THEN headers SHALL incluir `Cache-Control: private, no-store` e `X-Request-ID`.

**Independent Test**: `make test-backend` + inspeção estática de rota/OpenAPI.

**Requirement IDs**: LOG-10, LOG-11

---

## Edge Cases

- E-mail com variação de caixa/espaços → normalizado antes de lookup; credencial avaliada contra usuário canônico
- Usuário `active` com senha correta após múltiplos logins → múltiplos tokens `session` coexistem
- Usuário `pending_verification` relogando → novo token `verification`; tokens anteriores permanecem até expirar/revogar
- Conta `suspended`/`deletion_pending` + senha incorreta → `401 INVALID_CREDENTIALS` (não revela status)
- Conta `suspended`/`deletion_pending` + senha correta → `403` específico (credencial reconhecida)
- Payload com senha vazia `""` → `422` (campo required)
- Requisição `413` por body > 64 KiB → tratado pela infra global (`docs/security.md` §12); fora do escopo desta fatia além de não crashar
- Falha interna em `IssueAuthToken` após senha válida → `500` sem token parcial
- Token plaintext nunca aparece em exceções, logs ou corpo de erro
- Login não altera `terms_accepted_at`, `email_verified_at` nem `User.status`
- Login `pending_verification` não dispara job de verificação por e-mail

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| AUTH-09 | P1: Credenciais inválidas / Login bem-sucedido | Tasks | ✅ Verified |
| AUTH-10 | P1: Login bem-sucedido | Tasks | ✅ Verified |
| AUTH-11 | P1: Bloqueio por status | Tasks | ✅ Verified |
| LOG-01 | P1: Login bem-sucedido | Tasks | ✅ Verified |
| LOG-02 | P1: Login bem-sucedido | Tasks | ✅ Verified |
| LOG-03 | P1: Login bem-sucedido | Tasks | ✅ Verified |
| LOG-04 | P1: Credenciais inválidas | Tasks | ✅ Verified |
| LOG-05 | P1: Credenciais inválidas | Tasks | ✅ Verified |
| LOG-06 | P1: Bloqueio por status | Tasks | ✅ Verified |
| LOG-07 | P1: Validação HTTP | Tasks | ✅ Verified |
| LOG-08 | P1: Rate limiting | Tasks | ✅ Verified |
| LOG-09 | P1: Rate limiting | Tasks | ✅ Verified |
| LOG-10 | P2: Contrato HTTP | Tasks | ✅ Verified |
| LOG-11 | P2: Test discovery | Tasks | ✅ Verified |
| LOG-12 | P1: Credenciais inválidas (conta bloqueada + senha errada) | Tasks | ✅ Verified |

**Coverage:** 15 total, 15 mapped ✅

---

## Success Criteria

- [x] Usuário `active` autentica via API e recebe token `session` utilizável nos endpoints `session`.
- [x] Usuário `pending_verification` autentica e recebe token `verification` sem transição para `active` e sem reenvio automático de e-mail.
- [x] E-mail inexistente, senha incorreta e senha incorreta em conta bloqueada produzem respostas `401` indistinguíveis no contrato público.
- [x] Contas `suspended` e `deletion_pending` com credencial correta não emitem token.
- [x] Dual rate limit respeitado com `429` + `Retry-After` (todas as tentativas POST contam).
- [x] `make test-backend` passa com cobertura Feature E2E do login.
- [x] OpenAPI permanece sincronizada (sem drift de schema/respostas para `login`).

---

## Referências

| Documento | Uso |
| --- | --- |
| `docs/product.md` §3 | Fluxo de login no produto |
| `docs/api.md` §3.1–3.2, §8 | Contrato HTTP, kinds de token e rate limits |
| `docs/openapi.yaml` | `login`, `LoginRequest`, `AuthIssued`, `InvalidCredentials`, `AccountUnavailable` |
| `docs/security.md` §4.1–4.2, §11, §13 | Anti-enumeração, senha, rate limit HMAC, telemetria |
| `docs/testing.md` §6.1 | Casos obrigatórios credencial/status/timing |
| `docs/data-model.md` §3 | Schema `users`, `auth_tokens` |
| `.specs/features/auth/bearer-tokens/spec.md` | Emissão, TTL e idle por kind |
| `.specs/features/auth/registration/spec.md` | Padrões HTTP, rate limit e response factories |
