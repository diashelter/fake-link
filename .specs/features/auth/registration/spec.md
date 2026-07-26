# Auth — Registro por convite

**Status:** Confirmada — 2026-07-26  
**Fatia:** 3 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-01 … AUTH-05  
**Requirement IDs (fatia):** REG-01 … REG-10  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md)

## Problem Statement

Convidados elegíveis precisam criar conta na API Laravel antes de verificar e-mail e acessar o dashboard. O produto é invite-only: só e-mails presentes na allowlist do servidor podem registrar, falhas de convite e duplicidade não podem permitir enumeração, e o cadastro deve registrar aceite de termos, criar o usuário em `pending_verification` e emitir um Bearer `verification` para os fluxos restritos seguintes.

## Goals

- [x] `POST /api/v1/auth/register` implementado e alinhado a `docs/openapi.yaml`.
- [x] Allowlist de convite consultada via port hexagonal (`InviteAllowlist`), sem segredo em código ou log.
- [x] Resposta anti-enumeração uniforme (`403 REGISTRATION_NOT_ALLOWED`) para convite inválido, e-mail já cadastrado e estados incompatíveis.
- [x] Usuário criado com `status=pending_verification`, termos persistidos e senha hasheada (Argon2id via foundation).
- [x] Token `verification` emitido na resposta `201` via `IssueAuthToken` (bearer-tokens).
- [x] Solicitação de e-mail de verificação enfileirada via port (integração Resend fica na fatia `email-verification`).
- [x] Rate limit 5/h por IP com `429 RATE_LIMIT_EXCEEDED` e `Retry-After`.
- [x] Feature tests E2E cobrindo convite, enumeração, validação, termos, emissão de token e throttle.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Integração Resend / conteúdo do e-mail | AUTH-20 — fatia `email-verification` |
| Tabela `email_action_tokens` e consumo do token de e-mail | AUTH-21 … AUTH-25 — fatia `email-verification` |
| `POST /api/v1/auth/login` | Fatia `login` |
| BFF, cookies, CSRF, UI | Camada Next.js |
| MFA, cadastro público, tokens de integração | Fora do MVP |
| Comandos `Operations` (suspend, delete) | Fase 4 |
| Alteração de e-mail ou perfil | Fatia `session-and-profile` |

---

## Assumptions & Open Questions

Decisões confirmadas em revisão (2026-07-26).

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Fonte da allowlist em dev/test | Arquivo JSON versionado (ex.: `backend/config/invite-allowlist.testing.json`), referenciado por `config/auth.php` | Testes determinísticos sem SOPS local | y |
| Fonte da allowlist em produção | SOPS (mesmo padrão de segredos do projeto) | `docs/security.md` §4.1 | y |
| Comparação de convite | E-mail normalizado (`EmailAddress`) comparado exatamente à entrada da allowlist (case/trim já normalizados) | `docs/testing.md` §6.1 — sem aliases ou variações | y |
| `terms_version` na criação | `2026-01` via `config('auth.terms.current_version')`; não enviado pelo cliente | Alinhado a factories/testes existentes; OpenAPI não inclui o campo | y |
| `terms_accepted_at` | Instantâneo UTC no momento da persistência bem-sucedida do registro | Aceite explícito via `accept_terms=true` | y |
| E-mail já cadastrado (`active`, `pending_verification`, `suspended`, `deletion_pending`) | Mesma resposta `403 REGISTRATION_NOT_ALLOWED` | Anti-enumeração — `docs/testing.md` §6.1 | y |
| Disparo de verificação por e-mail | Port `QueueEmailVerification`; adapter enfileira job na fila `notifications` com handler no-op/testável até `email-verification` | Registro dispara o fluxo; Resend é AUTH-20 | y |
| Ordem transacional | Persistir usuário → emitir token dentro de transação DB; falha na emissão do token faz rollback e responde `403 REGISTRATION_NOT_ALLOWED` | Anti-enumeração; nenhum estado parcial exposto | y |
| Falha ao enfileirar e-mail após commit | HTTP `201` mantido; falha interna para retry da fila | Registro + token já entregues ao cliente | y |
| Concorrência de dois registros simultâneos no mesmo e-mail | Segunda requisição recebe `403 REGISTRATION_NOT_ALLOWED` (constraint UNIQUE + mapeamento uniforme) | Consistente com anti-enumeração | y |
| Rate limit — o que conta | Todas as tentativas `POST /register` na janela (422, 403, 201 e demais respostas) | Controle anti-abuso — decisão de revisão | y |
| Allowlist indisponível | `503 SERVICE_UNAVAILABLE`; sem vazar formato ou conteúdo da lista | OpenAPI `register` + decisão de revisão | y |

**Open questions:** none — all resolved or logged above.

**Dimensões implícitas (Large):**

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | `RegisterRequest` OpenAPI; `PasswordPolicy` foundation; `additionalProperties: false` |
| Failure / partial-failure states | Allowlist/duplicidade/token failure → `403` genérico (rollback se necessário); validação → `422`; rate limit → `429`; allowlist indisponível → `503`; demais falhas internas → `500` sem detalhe sensível |
| Idempotency / retry / duplicate handling | Re-post com mesmo e-mail → `403` uniforme; sem idempotency key |
| Auth boundaries & rate limits | Endpoint público; 5/h por IP contando **todas** as tentativas POST (HMAC Redis conforme `docs/api.md` §8) |
| Concurrency / ordering | UNIQUE em `email`; race → uma vitória, demais `403` uniforme |
| Data lifecycle / expiry | Usuário nasce `pending_verification`; token `verification` TTL 24h/idle 1h (bearer-tokens) |
| Observability | Sem e-mail, senha ou token em log/trace/métrica (`docs/security.md` §13) |
| External-dependency failure | Falha ao enfileirar e-mail: registro concluído + token emitido; job retry pela fila (não falha o HTTP 201) |
| State-transition integrity | Registro só cria `pending_verification`; transição para `active` só em `email-verification` |

---

## User Stories

### P1: Registro bem-sucedido com convite válido ⭐ MVP

**User Story**: Como convidado elegível, quero criar minha conta com nome, e-mail, senha e aceite de termos para iniciar a verificação de e-mail.

**Why P1**: Jornada principal do produto (`docs/product.md` §2.1); desbloqueia verificação e dashboard.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/register` recebe `name`, `email`, `password`, `password_confirmation` e `accept_terms=true` com e-mail normalizado presente exatamente na allowlist e senha conforme política THEN o sistema SHALL responder `201` com corpo `AuthResponse` (`docs/openapi.yaml`).
2. WHEN o registro é bem-sucedido THEN o usuário persistido SHALL ter `status=pending_verification`, `email_verified_at=null`, `terms_version=2026-01` (via config do servidor) e `terms_accepted_at` preenchido em UTC.
3. WHEN o registro é bem-sucedido THEN a senha SHALL ser persistida somente como hash Argon2id (nunca plaintext).
4. WHEN o registro é bem-sucedido THEN `data.token_kind` SHALL ser `verification`, `data.token_type` SHALL ser `Bearer`, e `data.expires_at` SHALL ser now + 86400 segundos (TTL absoluto de verification).
5. WHEN o registro é bem-sucedido THEN o plaintext do token SHALL aparecer somente no corpo da resposta `201` e SHALL ser persistido somente como hash em `auth_tokens`.
6. WHEN o registro é bem-sucedido THEN o port `QueueEmailVerification` SHALL ser invocado exatamente uma vez para o usuário criado.

**Independent Test**: POST com e-mail allowlisted → `201`, assert user row, token kind, hash no banco, job enfileirado (fake adapter).

**Requirement IDs**: AUTH-03, AUTH-04, AUTH-05, REG-01, REG-02, REG-03, REG-04

---

### P1: Allowlist de convite ⭐ MVP

**User Story**: Como operador, quero que apenas e-mails convidados possam registrar para manter o produto invite-only.

**Why P1**: Decisão de produto e segurança (`docs/decisions.md`, ADR 0001).

**Acceptance Criteria**:

1. WHEN o e-mail normalizado não está na allowlist THEN o sistema SHALL responder `403` com `code=REGISTRATION_NOT_ALLOWED` e mensagem genérica (`Registration is not available for these details.`).
2. WHEN o e-mail tem variação de caixa, espaços externos ou forma não normalizada que resulte em endereço fora da allowlist THEN o sistema SHALL responder `403 REGISTRATION_NOT_ALLOWED` (não cria conta).
3. WHEN a allowlist é consultada THEN o adaptador SHALL NOT registrar e-mails consultados em log, trace ou métrica.
4. WHEN a allowlist não pode ser carregada THEN o sistema SHALL responder `503 SERVICE_UNAVAILABLE` sem vazar formato ou conteúdo da lista.

**Independent Test**: E-mail fora da lista → `403`; e-mail na lista com trim/case → sucesso; spy no logger sem vazamento.

**Requirement IDs**: AUTH-01, REG-05

---

### P1: Anti-enumeração no registro ⭐ MVP

**User Story**: Como plataforma, quero respostas uniformes em falhas de registro para impedir descoberta de convites ou contas existentes.

**Why P1**: `docs/security.md` §4.1 e `docs/testing.md` §6.1.

**Acceptance Criteria**:

1. WHEN o e-mail já possui conta (qualquer `status` público) THEN o sistema SHALL responder `403 REGISTRATION_NOT_ALLOWED` com o mesmo corpo e status que convite inválido.
2. WHEN convite inválido e e-mail duplicado são comparados THEN `code`, `message` e status HTTP SHALL ser idênticos (diferença observável de timing não faz parte desta fatia).
3. WHEN a resposta é `403 REGISTRATION_NOT_ALLOWED` THEN SHALL NOT existir corpo de usuário, token ou indício de qual motivo específico causou a recusa.

**Independent Test**: Três cenários (não convidado, duplicado, convidado ok) — dois primeiros byte-a-byte equivalentes no envelope de erro.

**Requirement IDs**: AUTH-02, REG-06

---

### P1: Validação de entrada HTTP ⭐ MVP

**User Story**: Como API, quero rejeitar payloads inválidos com erros de validação explícitos sem criar conta parcial.

**Why P1**: Contrato OpenAPI e segurança de entrada.

**Acceptance Criteria**:

1. WHEN o JSON contém campos além de `name`, `email`, `password`, `password_confirmation`, `accept_terms` THEN o sistema SHALL responder `422 VALIDATION_FAILED` sem persistir usuário.
2. WHEN `accept_terms` é ausente, `false` ou não booleano THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
3. WHEN `password` ou `password_confirmation` violam comprimento (12–128) ou composição (minúscula, maiúscula, dígito, símbolo ASCII) THEN o sistema SHALL responder `422 VALIDATION_FAILED` com erros de campo conforme padrão Laravel/API.
4. WHEN `password` ≠ `password_confirmation` THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
5. WHEN `name` está vazio ou excede 120 caracteres THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
6. WHEN `email` é sintaticamente inválido ou excede 254 caracteres THEN o sistema SHALL responder `422 VALIDATION_FAILED`.
7. WHEN validação falha THEN nenhum registro em `users` ou `auth_tokens` SHALL ser criado.

**Independent Test**: Matrix de payloads inválidos → `422` + zero rows.

**Requirement IDs**: REG-07

---

### P1: Rate limiting ⭐ MVP

**User Story**: Como plataforma, quero limitar tentativas de registro por IP para conter abuso.

**Why P1**: `docs/api.md` §8; controle compensatório sem MFA (`docs/security.md` §4.2).

**Acceptance Criteria**:

1. WHEN um IP excede 5 tentativas `POST /api/v1/auth/register` em uma janela de 1 hora (incluindo respostas `422`, `403` e `201`) THEN o sistema SHALL responder `429 RATE_LIMIT_EXCEEDED` com header `Retry-After`.
2. WHEN o rate limit é aplicado THEN a chave SHALL ser derivada por IP conforme padrão HMAC Redis do projeto (mesma família dos demais limites Auth).
3. WHEN a sexta tentativa dentro da janela ocorre THEN nenhum usuário adicional SHALL ser criado.

**Independent Test**: Seis POSTs sequenciais do mesmo IP (mesmo com payloads inválidos) → 5 contabilizadas + 1 `429` com `Retry-After`.

**Requirement IDs**: REG-08

---

### P2: Contrato HTTP e descoberta de testes

**User Story**: Como mantenedor, quero o endpoint registrado, documentado e coberto por testes descobertos pelo suite padrão.

**Why P2**: Gates de CI e OpenAPI design-first.

**Acceptance Criteria**:

1. WHEN a rota é inspecionada THEN `POST /api/v1/auth/register` SHALL estar registrada no módulo Auth com controller fino, Form Request e Resource alinhados a `docs/openapi.yaml`.
2. WHEN `make test-backend` roda THEN testes Feature em `modules/Auth/Tests/Feature/RegistrationTest.php` (ou equivalente) SHALL ser descobertos.
3. WHEN a resposta é `201` THEN headers SHALL incluir `Cache-Control: private, no-store` e `X-Request-ID`.

**Independent Test**: `make test-backend` + inspeção estática de rota/OpenAPI.

**Requirement IDs**: REG-09, REG-10

---

## Edge Cases

- E-mail allowlisted com `+alias` ou subaddressing não listado → `403 REGISTRATION_NOT_ALLOWED`
- Dois POSTs concorrentes com mesmo e-mail → no máximo um `201`; demais `403` uniforme
- Allowlist indisponível → `503 SERVICE_UNAVAILABLE` (sem vazar formato da lista)
- Falha na emissão do token após persistir usuário → rollback transacional + `403 REGISTRATION_NOT_ALLOWED`
- Senha válida na política mas com caracteres Unicode fora das quatro categorias ASCII exigidas → `422`
- Requisição sem `Content-Type: application/json` ou JSON malformado → `400 MALFORMED_REQUEST` (sem side effects)
- Falha ao enfileirar job de verificação após persistência → HTTP `201` mantido; falha registrada internamente para retry da fila
- Token plaintext nunca aparece em exceções, logs ou corpo de erro

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| AUTH-01 | P1: Allowlist | Execute | Done |
| AUTH-02 | P1: Anti-enumeração | Execute | Done |
| AUTH-03 | P1: Registro bem-sucedido | Execute | Done |
| AUTH-04 | P1: Registro bem-sucedido | Execute | Done |
| AUTH-05 | P1: Registro bem-sucedido | Execute | Done |
| REG-01 | P1: Registro bem-sucedido | Execute | Done |
| REG-02 | P1: Registro bem-sucedido | Execute | Done |
| REG-03 | P1: Registro bem-sucedido | Execute | Done |
| REG-04 | P1: Registro bem-sucedido | Execute | Done |
| REG-05 | P1: Allowlist | Execute | Done |
| REG-06 | P1: Anti-enumeração | Execute | Done |
| REG-07 | P1: Validação HTTP | Execute | Done |
| REG-08 | P1: Rate limiting | Execute | Done |
| REG-09 | P2: Contrato HTTP | Execute | Done |
| REG-10 | P2: Test discovery | Execute | Done |

**Coverage:** 15 total, 15 mapped to tasks ✅

---

## Success Criteria

- [x] Convidado allowlisted completa registro via API e recebe token `verification` utilizável nos probes/endpoints restritos.
- [x] Cenários de enumeração (convite inválido vs duplicado) produzem respostas indistinguíveis no contrato público.
- [x] `make test-backend` passa com cobertura Feature E2E do registro.
- [x] OpenAPI permanece sincronizada (sem drift de schema/respostas para `register`).

---

## Referências

| Documento | Uso |
| --- | --- |
| `docs/product.md` §3 | Regras de cadastro e termos |
| `docs/api.md` §3.2, §8 | Contrato HTTP e rate limit |
| `docs/openapi.yaml` | `register`, `RegisterRequest`, `AuthIssued`, `RegistrationNotAllowed` |
| `docs/security.md` §4.1–4.2, §13 | Allowlist, senha, telemetria |
| `docs/testing.md` §6.1 | Casos obrigatórios convite/enumeração |
| `docs/data-model.md` §3 | Schema `users` |
| `docs/decisions.md` | Cadastro invite-only |
| `.specs/features/auth/bearer-tokens/spec.md` | Emissão e TTL de token `verification` |
