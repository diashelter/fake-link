# Auth — Sessão e perfil

**Status:** In Tasks — 2026-07-30  
**Fatia:** 7 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-30, AUTH-31, AUTH-33 (parcial), AUTH-34, AUTH-35, AUTH-36  
**Requirement IDs (fatia):** SP-01 … SP-16  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md), [login](../login/spec.md)  
**Context:** [context.md](./context.md)  
**Reutiliza:** `RevokeAuthToken`, `RevokeAllUserTokens`, `AuthUserResource`, middlewares `auth.bearer` / `token.kind` / `throttle.private_auth.write`

## Problem Statement

Usuários autenticados precisam encerrar a sessão atual, revogar todas as sessões com confirmação de senha e consultar/atualizar o perfil mínimo (`name`). As fatias anteriores emitiram e gerenciam Bearers e senhas, mas os endpoints `logout`, `logout-all` e `GET/PATCH /me` ainda não existem na API. Sem esta fatia, AUTH-30…36 ficam incompletos e o cliente direto (e o futuro BFF) não consegue fechar a jornada de sessão/perfil.

## Goals

- [x] `POST /api/v1/auth/logout` → `204`; revoga **somente** o Bearer apresentado (`session` ou `verification`).
- [x] `POST /api/v1/auth/logout-all` → `204`; exige Bearer `session` + `current_password`; revoga **todos** os Bearers da conta (incluindo o apresentado).
- [x] `GET /api/v1/me` → `200` `UserResponse`; aceita `session` ou `verification`.
- [x] `PATCH /api/v1/me` → `200` `UserResponse`; aceita somente `session`; altera **somente** `name`; e-mail imutável.
- [x] Representação pública do `User` alinhada a `docs/openapi.yaml` (`id`, `name`, `email`, `status`, `email_verified_at`, `terms_version`, `terms_accepted_at`, `created_at`, `updated_at`).
- [x] Rate limits: leituras privadas 300/min por token; escritas privadas 120/min por conta (logout, logout-all, PATCH).
- [x] Feature + integration tests cobrindo auth boundaries, revogação, validação de nome, rate limits e envelope OpenAPI.

## Out of Scope

| Item | Motivo |
| --- | --- |
| BFF Next.js, cookies, CSRF, invalidação Redis de sessão opaca | Camada frontend — Fase 1 posterior; API só revoga Bearers |
| UI de perfil, login, logout | Frontend |
| Listagem/revogação seletiva de dispositivos | Fora do MVP (`docs/product.md` §3; sem `device_name`) |
| Alteração de e-mail | Fora do MVP (`docs/data-model.md` §3) |
| Alteração/reset de senha | Fatia `password` (concluída) |
| MFA, lockout persistente, tokens de integração | Fora do MVP |
| Comandos `Operations` (suspend, delete, AUTH-39/40) | Fase 4 |
| Emissão de Bearer nestes endpoints | Logout/perfil não emitem token |
| OpenAPI/client TS regeneration automática | Infra transversal; esta fatia **pode** alinhar exemplos se necessário |

---

## Assumptions & Open Questions

Decisões da revisão 2026-07-29. Todas as gray areas confirmadas.

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Logout — kinds aceitos | `session` **e** `verification` | OpenAPI `x-allowed-token-kinds: [session, verification]`; `docs/api.md` §3.1 | y |
| Logout — efeito | Remove **somente** o `auth_tokens` do Bearer apresentado (`RevokeAuthToken`) | AUTH-30; `docs/api.md` §3.1 | y |
| Logout — HTTP sucesso | `204 No Content` (corpo vazio) | OpenAPI `logout` | y |
| Logout — body | Sem `requestBody`; body ausente ou JSON vazio `{}` aceito; campos extras → `422 VALIDATION_FAILED` | Confirmado 2026-07-29 (Q1=A) | y |
| Logout-all — kind | Somente `session`; `verification` → `403 TOKEN_RESTRICTED` | OpenAPI `x-allowed-token-kinds: [session]` | y |
| Logout-all — body | `CurrentPasswordRequest`: somente `current_password` (`additionalProperties: false`, max 128) | OpenAPI | y |
| Logout-all — senha incorreta | `401` + `code=INVALID_CREDENTIALS` + `message=The provided credentials are invalid.` **sem** revogar tokens | Paridade change password (PW) | y |
| Logout-all — sucesso | `204`; `RevokeAllUserTokens` (inclui o token apresentado) | AUTH-31, AUTH-33; OpenAPI description | y |
| Logout-all — não altera User | Status, e-mail, nome, hash de senha inalterados | Só revoga tokens | y |
| GET /me — kinds | `session` **e** `verification` | OpenAPI; restricted consulta estado do User | y |
| GET /me — HTTP | `200` com envelope `UserResponse` (`{ "data": User }`) | OpenAPI | y |
| PATCH /me — kind | Somente `session`; `verification` → `403 TOKEN_RESTRICTED` | OpenAPI | y |
| PATCH /me — campos | Somente `name` required; e-mail e demais campos rejeitados se enviados | AUTH-35; OpenAPI `UpdateUserRequest` | y |
| PATCH — bounds de `name` | `minLength: 1`, `maxLength: 120` (após normalização) | OpenAPI + `docs/data-model.md` varchar(120) | y |
| PATCH — normalização de `name` | Trim de espaços externos (`trim`); se vazio após trim → `422`; persistir valor trimado | Confirmado 2026-07-29 (Q2=A) | y |
| PATCH — no-op (mesmo nome) | Responde `200` com User atual; **não** bumpa `updated_at` (sem write desnecessário) | Confirmado 2026-07-29 (Q3=A) | y |
| PATCH — sucesso | `200` `UserResponse`; atualiza `name` + `updated_at` quando o nome mudou | OpenAPI | y |
| Timestamps em User | `created_at` / `updated_at` vêm da persistência (não fallback para `terms_accepted_at` no path `/me`) | Contrato OpenAPI; `AuthUserResource` já aceita timestamps explícitos | y |
| Conta `suspended` / `deletion_pending` | Middleware bearer bloqueia com `403 ACCOUNT_*` **antes** do use case (qualquer endpoint desta fatia) | Paridade endpoints privados Auth | y |
| Bearer ausente/inválido/expirado/revogado | `401 UNAUTHENTICATED` | Middleware existente | y |
| Reuso após logout | Bearer revogado em qualquer rota autenticada → `401` | Efeito observável de SP-01/02 | y |
| Rate limit escritas | Logout, logout-all e PATCH usam `throttle.private_auth.write` (120/min por conta, janela 60s) | `docs/api.md` §8 | y |
| Rate limit leituras | GET /me usa novo throttle **leituras privadas** 300/min **por token** (chave HMAC do hash do token), janela 60s | Confirmado 2026-07-29 (Q4=A); `docs/api.md` §8 | y |
| Contagem de throttle | Incrementa em **todas** as tentativas que passam no middleware de throttle (qualquer status HTTP da rota) | Paridade login/password | y |
| Rotas `/me` | Registradas em `api/v1` (não sob prefixo `auth/`), ainda no módulo Auth | OpenAPI path `/api/v1/me` | y |
| Use cases existentes | Reutilizar `RevokeAuthToken` / `RevokeAllUserTokens`; novos use cases finos para GET/PATCH profile se necessário | Evitar duplicar revogação | y |
| Senha / token em logs | Proibidos | `docs/security.md` §13 | y |
| Sem e-mail ao usuário | Logout/perfil não enviam notificação | `docs/product.md` §3 | y |

**Open questions:** none — all resolved 2026-07-29.

**Dimensões implícitas (Large):**

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | OpenAPI `CurrentPasswordRequest` / `UpdateUserRequest`; `additionalProperties: false`; name 1–120; current_password max 128 |
| Failure / partial-failure states | Credencial → `401 INVALID_CREDENTIALS`; kind errado → `403 TOKEN_RESTRICTED`; status bloqueado → `403 ACCOUNT_*`; validação → `422`; rate limit → `429`; bearer → `401` |
| Idempotency / retry / duplicate handling | Segundo logout com mesmo Bearer → `401` (já revogado); logout-all não é idempotente após sucesso (Bearer some); PATCH no-op → `200` estável |
| Auth boundaries & rate limits | logout: session\|verification; logout-all/PATCH: session; GET: session\|verification; write 120/min conta; read 300/min token |
| Concurrency / ordering | Dois logout-all concorrentes: ambos podem ver senha válida; revogação é delete-all idempotente em efeito (zero tokens restantes) |
| Data lifecycle / expiry | Tokens removidos (delete), não soft-delete; TTL de Bearer inalterado nesta fatia |
| Observability | Sem senha, token plaintext ou e-mail em telemetria |
| External-dependency failure | Sem Resend; falha DB → `500` genérico sem detalhe sensível |
| State-transition integrity | Nenhum endpoint desta fatia altera `User.status`; PATCH só `name` (+ `updated_at` se mudou) |

---

## User Stories

### P1: Logout do token atual ⭐ MVP

**User Story**: Como usuário autenticado (sessão completa ou restrita), quero encerrar somente a sessão atual sem afetar outros dispositivos.

**Why P1**: AUTH-30; `docs/product.md` §3 — encerrar sessão atual.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/logout` com Bearer `session` ou `verification` válido THEN o sistema SHALL responder `204 No Content` com corpo vazio.
2. WHEN logout bem-sucedido THEN o registro `auth_tokens` correspondente ao Bearer apresentado SHALL ser removido.
3. WHEN logout bem-sucedido THEN **outros** `auth_tokens` do mesmo usuário SHALL permanecer intactos.
4. WHEN o mesmo Bearer é reutilizado após logout em qualquer rota autenticada THEN SHALL responder `401 UNAUTHENTICATED`.
5. WHEN Bearer ausente ou inválido THEN SHALL `401 UNAUTHENTICATED` **sem** side effects de revogação.
6. WHEN rate limit de escritas privadas excedido THEN SHALL `429 RATE_LIMIT_EXCEEDED` com `Retry-After`.

**Independent Test**: Feature — emitir dois tokens session → logout com A → probe com A falha `401`; probe com B permanece `200`.

**Requirement IDs**: AUTH-30, SP-01, SP-02

---

### P1: Logout global com confirmação de senha ⭐ MVP

**User Story**: Como usuário com sessão completa, quero revogar todas as sessões confirmando minha senha atual.

**Why P1**: AUTH-31, AUTH-33; `docs/api.md` §3.1.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/logout-all` com Bearer `session` válido e `current_password` correta THEN SHALL responder `204 No Content`.
2. WHEN logout-all bem-sucedido THEN **todos** os `auth_tokens` do usuário (incluindo o apresentado) SHALL ser removidos.
3. WHEN `current_password` não confere THEN SHALL responder `401 INVALID_CREDENTIALS` com `message=The provided credentials are invalid.` **sem** revogar tokens.
4. WHEN Bearer `verification` THEN SHALL `403 TOKEN_RESTRICTED` **sem** avaliar senha de forma que altere tokens.
5. WHEN Bearer ausente/inválido THEN SHALL `401 UNAUTHENTICATED`.
6. WHEN body omite `current_password`, excede maxLength, ou contém campos extras THEN SHALL `422 VALIDATION_FAILED` sem revogar tokens.
7. WHEN rate limit de escritas privadas excedido THEN SHALL `429 RATE_LIMIT_EXCEEDED` + `Retry-After`.
8. WHEN logout-all bem-sucedido THEN `User.status`, e-mail, nome e hash de senha SHALL permanecer inalterados.

**Independent Test**: Feature — dois tokens session → logout-all → ambos falham em probe; logout-all com senha errada → `401` e tokens intactos.

**Requirement IDs**: AUTH-31, AUTH-33, SP-03, SP-04, SP-05

---

### P1: Consultar usuário atual ⭐ MVP

**User Story**: Como cliente autenticado, quero obter o estado atual da minha conta (incluindo status pendente de verificação).

**Why P1**: AUTH-34, AUTH-36; restricted session precisa ler o User.

**Acceptance Criteria**:

1. WHEN `GET /api/v1/me` com Bearer `session` ou `verification` válido THEN SHALL responder `200` com envelope `UserResponse` (`data` = `User`).
2. WHEN sucesso THEN `data` SHALL conter exatamente os campos OpenAPI: `id`, `name`, `email`, `status`, `email_verified_at`, `terms_version`, `terms_accepted_at`, `created_at`, `updated_at` (sem campos extras).
3. WHEN sucesso THEN `created_at` e `updated_at` SHALL refletir os timestamps persistidos do usuário (UTC, formato OpenAPI).
4. WHEN Bearer `verification` de conta `pending_verification` THEN SHALL retornar `status=pending_verification` e `email_verified_at=null`.
5. WHEN Bearer ausente/inválido THEN SHALL `401`; WHEN kind não permitido (N/A — ambos kinds ok) — N/A; WHEN status `suspended`/`deletion_pending` THEN SHALL `403 ACCOUNT_*`.
6. WHEN rate limit de leituras privadas excedido THEN SHALL `429 RATE_LIMIT_EXCEEDED` + `Retry-After`.

**Independent Test**: Feature — login session → GET /me → `200` + campos; register/login pending → GET /me com verification → `pending_verification`.

**Requirement IDs**: AUTH-34, AUTH-36, SP-06, SP-07, SP-08

---

### P1: Alterar nome do perfil ⭐ MVP

**User Story**: Como usuário com sessão completa, quero alterar somente o meu nome.

**Why P1**: AUTH-35; `docs/product.md` §3 — perfil permite alterar somente o nome.

**Acceptance Criteria**:

1. WHEN `PATCH /api/v1/me` com Bearer `session` e body `{ "name": "<válido>" }` THEN SHALL responder `200` com `UserResponse` refletindo o novo nome.
2. WHEN PATCH bem-sucedido com nome diferente THEN `users.name` SHALL ser atualizado e `users.updated_at` SHALL avançar.
3. WHEN Bearer `verification` THEN SHALL `403 TOKEN_RESTRICTED` sem alterar nome.
4. WHEN body inclui `email` ou qualquer campo além de `name` THEN SHALL `422 VALIDATION_FAILED` sem side effects.
5. WHEN `name` ausente, vazio (após normalização) ou com mais de 120 caracteres THEN SHALL `422 VALIDATION_FAILED`.
6. WHEN e-mail é enviado no body THEN SHALL NOT alterar `users.email` (imutável).
7. WHEN rate limit de escritas privadas excedido THEN SHALL `429 RATE_LIMIT_EXCEEDED` + `Retry-After`.

**Independent Test**: Feature — PATCH nome → `200` + novo name; GET /me confirma; PATCH com `email` extra → `422`; verification bearer → `403`.

**Requirement IDs**: AUTH-35, SP-09, SP-10, SP-11

---

### P1: Privacidade de credenciais ⭐ MVP

**User Story**: Como plataforma, quero que senhas não vazem em telemetria nos fluxos de logout-all.

**Why P1**: Paridade PW-12 / AUTH-25; `docs/security.md` §13.

**Acceptance Criteria**:

1. WHEN logout-all processa `current_password` THEN plaintext SHALL NOT aparecer em logs, exceptions, traces, métricas ou mensagens de erro.
2. WHEN testes usam sentinelas THEN asserts SHALL varrer factories/exceções do fluxo.

**Independent Test**: Unit sentinel — marcador ausente de `getMessage()` em caminhos de erro de senha.

**Requirement IDs**: SP-12

---

### P2: Contrato HTTP e headers

**User Story**: Como cliente da API, quero respostas previsíveis e headers de segurança consistentes.

**Why P2**: Paridade PW-13 / EV-12.

**Acceptance Criteria**:

1. WHEN respostas de sucesso (`200`/`204`) e erros deste fluxo THEN SHALL incluir `Cache-Control: private, no-store` e `request_id` / `X-Request-ID` conforme envelope OpenAPI.
2. WHEN esta fatia registra rotas `/api/v1/me` THEN OpenAPI permanece a fonte de verdade dos paths já documentados (sem divergência de method/status).

**Independent Test**: Feature — assert headers em happy path logout e GET /me, e em um erro representativo.

**Requirement IDs**: SP-13

---

### P2: Test discovery e gates

**User Story**: Como mantenedor, quero que testes E2E desta fatia rodem no gate padrão.

**Why P2**: Paridade PW-14/15.

**Acceptance Criteria**:

1. WHEN `make test-backend` roda THEN testes Feature/Integration desta fatia SHALL ser descobertos via `phpunit.xml`.
2. WHEN gate final da fatia THEN `make lint && make test-backend` SHALL passar sem regressão das fatias anteriores.
3. WHEN gate de cobertura Auth roda THEN linhas/métodos do módulo Auth SHALL permanecer ≥ 80%.

**Requirement IDs**: SP-14, SP-15, SP-16

---

## Edge Cases

- WHEN logout é chamado duas vezes com o mesmo Bearer THEN a segunda chamada SHALL `401 UNAUTHENTICATED`.
- WHEN logout-all e change-password correm com a mesma senha válida THEN ambos revogam todos os tokens (efeito equivalente).
- WHEN logout-all concorrente THEN ao final SHALL existir zero `auth_tokens` para o usuário.
- WHEN GET /me com token já idle-expirado THEN SHALL `401` (middleware bearer — regressão a preservar).
- WHEN PATCH com nome idêntico ao atual (após normalização) THEN SHALL `200` sem alterar `updated_at`.
- WHEN PATCH com `"name": "  Ana  "` THEN SHALL persistir `"Ana"`.
- WHEN conta `pending_verification` chama logout THEN SHALL `204` e revogar o verification token.
- WHEN conta `pending_verification` chama logout-all THEN SHALL `403 TOKEN_RESTRICTED`.
- WHEN conta `pending_verification` chama PATCH /me THEN SHALL `403 TOKEN_RESTRICTED`.
- WHEN JSON malformado THEN SHALL envelope de erro global existente (`400`/`MALFORMED_REQUEST` conforme bootstrap) — regressão.

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| AUTH-30 | P1: Logout atual | Specify | Pending |
| AUTH-31 | P1: Logout-all | Specify | Pending |
| AUTH-33 | P1: Logout-all (parcial) | Specify | Pending |
| AUTH-34 | P1: GET /me | Specify | Pending |
| AUTH-35 | P1: PATCH /me | Specify | Pending |
| AUTH-36 | P1: Representação User | Specify | Pending |
| SP-01 | P1: Logout atual | Specify | Pending |
| SP-02 | P1: Logout atual — isolamento | Specify | Pending |
| SP-03 | P1: Logout-all sucesso | Specify | Pending |
| SP-04 | P1: Logout-all senha | Specify | Pending |
| SP-05 | P1: Logout-all boundaries | Specify | Pending |
| SP-06 | P1: GET /me envelope | Specify | Pending |
| SP-07 | P1: GET /me timestamps | Specify | Pending |
| SP-08 | P1: GET /me restricted | Specify | Pending |
| SP-09 | P1: PATCH nome | Specify | Pending |
| SP-10 | P1: PATCH imutabilidade e-mail | Specify | Pending |
| SP-11 | P1: PATCH boundaries | Specify | Pending |
| SP-12 | P1: Privacidade | Specify | Pending |
| SP-13 | P2: Contrato HTTP | Specify | Pending |
| SP-14 | P2: Discovery | Specify | Pending |
| SP-15 | P2: Lint/test gate | Specify | Pending |
| SP-16 | P2: Coverage gate | Specify | Pending |

**Coverage:** 22 total (6 catálogo + 16 fatia), 0 mapped to tasks, 22 unmapped ⚠️ (esperado em Specify)

---

## Success Criteria

- [ ] Usuário com `session` faz logout de um token sem derrubar os demais.
- [ ] Usuário com `session` faz logout-all com senha e fica sem Bearers utilizáveis.
- [ ] Usuário `pending_verification` consulta `/me` e faz logout; não faz logout-all nem PATCH.
- [ ] PATCH altera só `name`; e-mail permanece imutável.
- [ ] Rate limits 120/min (escritas) e 300/min (leituras) cobertos por testes.
- [ ] `make lint && make test-backend` verde com Feature E2E dos quatro endpoints.
- [ ] OpenAPI permanece fonte de verdade dos contracts já publicados.

---

## Decisões confirmadas (revisão 2026-07-29)

| # | Decisão |
| --- | --- |
| 1 | Logout aceita body ausente ou `{}`; campos extras → `422` |
| 2 | PATCH `name`: trim externo; vazio pós-trim → `422`; persistir trimado |
| 3 | PATCH no-op (mesmo nome) → `200` sem bump de `updated_at` |
| 4 | GET /me: throttle 300/min por token (HMAC do hash) — middleware novo |
| 5 | Logout-all senha incorreta → `401 INVALID_CREDENTIALS` (paridade change) |

---

## Referências

| Documento | Uso |
| --- | --- |
| `docs/product.md` §3 | Logout atual/global; perfil só nome |
| `docs/api.md` §3.1–3.2, §8 | Endpoints, kinds, rate limits, representação User |
| `docs/openapi.yaml` | `logout`, `logoutAll`, `getCurrentUser`, `updateCurrentUser` |
| `docs/security.md` §6, §11, §13 | Bearer, rate limit, telemetria |
| `docs/data-model.md` §3 | `users.name` varchar(120); e-mail imutável |
| `docs/testing.md` §6.1 | Logout, revogação, perfil |
| `.specs/features/auth/README.md` | Catálogo AUTH-30…36 |
| `.specs/features/auth/password/spec.md` | Paridade `INVALID_CREDENTIALS` + write throttle |
| `.specs/features/auth/bearer-tokens/spec.md` | Middleware bearer / token kind |
