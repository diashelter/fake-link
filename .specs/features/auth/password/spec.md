# Auth — Senha (alterar e recuperar)

**Status:** Approved — 2026-07-28 (Specify)  
**Fatia:** 6 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-26 … AUTH-29, AUTH-32, AUTH-33 (parcial)  
**Requirement IDs (fatia):** PW-01 … PW-17  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md), [login](../login/spec.md), [email-verification](../email-verification/spec.md) (reutiliza `email_action_tokens` + pipeline de job/mail)  
**Context:** [context.md](./context.md)

## Problem Statement

Usuários autenticados precisam alterar a senha com confirmação da senha atual, e usuários que perderam o acesso precisam solicitar e concluir a recuperação sem enumeração de contas. A infraestrutura de tokens de e-mail (`email_action_tokens`), hash SHA-256, jobs cifrados e Resend já existe para verificação, mas ainda não há purpose `password_reset`, endpoints de change/reset nem revogação total de Bearers nesses fluxos. Sem esta fatia, AUTH-26…29 e AUTH-32 ficam incompletos e a jornada forgot/reset do produto não existe na API.

## Goals

- [x] Estender `email_action_tokens` / `EmailActionPurpose` com `password_reset` (TTL absoluto 30 min).
- [x] `POST /api/v1/auth/password/reset-request` → sempre `202` para body válido (anti-enumeração).
- [x] `POST /api/v1/auth/password/reset` → `204`; consome token, atualiza hash Argon2id, revoga **todos** os Bearers.
- [x] `POST /api/v1/auth/password/change` → `204` (Bearer `session`); exige `current_password`; revoga **todos** os Bearers.
- [x] Envio de recuperação via Resend (job na fila `notifications`), espelhando o padrão de verificação.
- [x] Rate limits: reset-request 3/h e-mail+IP; reset 5/h IP+token; change sob escritas privadas 120/min por conta.
- [x] Feature + integration tests cobrindo TTL, uso único, concorrência, elegibilidade, anti-enumeração, política de senha e revogação total.

## Out of Scope

| Item | Motivo |
| --- | --- |
| BFF Next.js, UI, cookies, CSRF | Camada frontend — Fase 1 posterior |
| Página que renderiza o link do e-mail | Frontend; API define URL alvo configurável |
| `GET` com efeito colateral no reset | `docs/security.md` §4.3 — POST explícito; paridade com verify |
| `GET/PATCH /api/v1/me`, logout, logout-all | Fatia `session-and-profile` |
| Listagem/revogação seletiva de dispositivos | Fora do MVP (`docs/product.md` §3) |
| MFA, lockout persistente | Fora do MVP (`docs/security.md` §4.2) |
| Alteração de e-mail | Fora do MVP |
| Comandos `Operations` (suspend, delete) | Fase 4 |
| Invalidação de sessões BFF/Redis | Camada Next.js; API só revoga Bearers |
| OpenAPI/client TS regeneration automática | Infra transversal; esta fatia **pode** acrescentar exemplo/código de erro estável se necessário |

---

## Assumptions & Open Questions

Decisões da revisão 2026-07-28. Itens marcados **n** ainda abertos — ver perguntas no fim desta seção / chat.

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Purpose na tabela | `password_reset` (CHECK + enum); migration altera CHECK existente | `docs/data-model.md` §3; EV reservou o purpose | y |
| TTL do token de reset | 30 minutos absolutos (`expires_at = now + 1800s`) | AUTH-27; `docs/api.md` §3.2; `docs/security.md` §4.3 | y |
| Formato do token | Base64url de 32 bytes CSPRNG (~43 chars), hash SHA-256 hex 64 no DB | Paridade com verificação / `BearerTokenGenerator` | y |
| Uso único | `used_at` preenchido atomicamente no primeiro consumo válido | `docs/data-model.md` §3; `docs/testing.md` §6.1 | y |
| Novo reset-request invalida tokens anteriores | Ao emitir novo `password_reset`, marcar como usados todos os **não usados** do mesmo `user_id` + purpose | Confirmado 2026-07-28; paridade EV | y |
| Contas elegíveis para **enviar** e-mail | Somente `User.status = active` | Confirmado 2026-07-28 | y |
| Conta inexistente / inelegível no reset-request | Mesmo `202` + envelope `Accepted`; **sem** criar token, **sem** enfileirar job | AUTH-26; anti-enumeração | y |
| Timing no reset-request | Executar trabalho mínimo uniforme (lookup + dummy `PasswordHasher::verify` contra hash Argon2id pré-computado) sem distinguir existência/elegibilidade no tempo observável | Confirmado 2026-07-28 (opção A); paridade com login | y |
| Body reset-request | Somente `email` (`additionalProperties: false`); normalização via `EmailAddress` | OpenAPI `PasswordResetRequest` | y |
| Body reset | `email`, `token`, `password`, `password_confirmation` | OpenAPI `ResetPasswordRequest` | y |
| Binding e-mail ↔ token | Token válido somente se pertencer ao `user_id` do e-mail normalizado; mismatch → mesmo erro de token inválido | Evita uso cruzado; OpenAPI exige ambos | y |
| Token inválido/expirado/usado/purpose errado no reset | `422 VALIDATION_FAILED` com erro de campo em `token`; **sem** `403` novo | Confirmado 2026-07-28 (default); OpenAPI do reset | y |
| Mensagem de token inválido no reset | Field message estável: `The password reset token is invalid or has expired.` — idêntica para todos os motivos | Confirmado via default 2026-07-28; anti-enumeração | y |
| Política da nova senha | `PasswordPolicy` completa (12–128 + complexidade ASCII) em change e reset | AUTH-06/07; OpenAPI `Password` | y |
| Confirmação de senha | `password` === `password_confirmation`; mismatch → `422` | OpenAPI required fields | y |
| Nova senha igual à atual | **Proibido** em **change e reset**; rejeitar sem consumir token (reset) e sem revogar Bearers (change) | Confirmado 2026-07-28 (Q3=A) | y |
| Envelope HTTP da senha igual à atual | `422 VALIDATION_FAILED` com `errors.password[]` contendo `code=PASSWORD_REUSED` e `message=The new password must be different from the current password.` | Confirmado 2026-07-28 (Q2 opção 1) | y |
| Change — autenticação | Bearer `session` obrigatório (`auth.bearer` + `token.kind:session`) | OpenAPI `x-allowed-token-kinds: [session]` | y |
| Change — `current_password` incorreta | `401` + `code=INVALID_CREDENTIALS` + mesma message do login | Confirmado 2026-07-28 (default) | y |
| Change — Bearer `verification` | `403 TOKEN_RESTRICTED` | Middleware existente | y |
| Change — conta `suspended` / `deletion_pending` | `403 ACCOUNT_*` via guard de status do bearer (antes do use case) | Paridade com endpoints privados Auth | y |
| Change — sucesso | `204`; atualiza `password` hash; `RevokeAllUserTokens` na mesma transação | AUTH-32, AUTH-33; `docs/api.md` §3.1 | y |
| Reset — sucesso | `204`; atualiza hash; marca `used_at`; `RevokeAllUserTokens`; **não** altera `User.status` | AUTH-28; status permanece | y |
| Reset — **não** emite Bearer | Cliente deve `POST /login` após reset | Paridade com verify (sem sessão automática) | y |
| Transporte Resend | Laravel Mail transport `resend`; job na fila `notifications`; payload do plaintext **cifrado** | Paridade EV; sem nova dependência | y |
| Testes determinísticos | `Mail::fake()` / driver array|log; sem HTTP real ao Resend | `docs/testing.md` §6.1 | y |
| URL no e-mail de reset | `{APP_URL}/reset-password?token={plaintext}` via config `auth.password_reset.frontend_base_url` + path `/reset-password` | Confirmado 2026-07-28 (default) | y |
| Idioma do e-mail | pt-BR (MVP) | `docs/product.md` §8 | y |
| Rate limit reset-request | 3/h por e-mail normalizado + IP; chave HMAC; janela 3600s; conta **todas** as tentativas POST | `docs/api.md` §8 | y |
| Rate limit reset | 5/h por IP + digest HMAC do token apresentado; janela 3600s; conta todas as tentativas | `docs/api.md` §8 “IP e token do fluxo” | y |
| Rate limit change | 120/min por conta (escritas privadas Auth); janela 60s | `docs/api.md` §8 | y |
| Plaintext em logs | Proibido (senha, token, URL com token) | `docs/security.md` §13; AUTH-25 analog | y |
| Falha Resend no job | Retry da fila; falha permanente não reverte hash nem invalida token já emitido no request | External-dependency; HTTP já respondeu `202` | y |
| Concorrência no reset | Transação: primeiro consumo vence; demais → erro de token inválido | `docs/testing.md` §6.1 | y |
| Rows consumidas | Manter row com `used_at` (não deletar) | Paridade EV | y |

**Open questions:** none — all resolved 2026-07-28.

**Dimensões implícitas (Large):**

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Schemas OpenAPI; `PasswordPolicy`; `additionalProperties: false`; e-mail max 254 |
| Failure / partial-failure states | Credencial atual inválida → `401`; token reset inválido → `422` campo `token`; senha igual à atual → `422` + `errors.password[].code=PASSWORD_REUSED`; validação → `422`; rate limit → `429`; bearer → `401`/`403`; Resend down → job retry / `503` só se enqueue impossível |
| Idempotency / retry / duplicate handling | Novo reset-request gera **novo** token e invalida anteriores; reset/change não idempotentes após sucesso |
| Auth boundaries & rate limits | change privado `session`; reset\* públicos; throttles dedicados + escritas privadas |
| Concurrency / ordering | Consumo atômico `used_at`; UNIQUE `token_hash` |
| Data lifecycle / expiry | Token reset 30 min; rows com `used_at` retidas |
| Observability | Sem e-mail, senha, token plaintext ou URL com token em telemetria |
| External-dependency failure | Resend: retry; HTTP `202` se persist+enqueue OK |
| State-transition integrity | Change/reset **não** alteram `User.status`; só hash de senha + tokens |

---

## User Stories

### P1: Solicitar recuperação de senha ⭐ MVP

**User Story**: Como usuário que esqueceu a senha, quero solicitar um e-mail de recuperação sem que a API revele se minha conta existe.

**Why P1**: AUTH-26, AUTH-29; entrada do fluxo forgot-password.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/password/reset-request` recebe body válido `{ "email": "..." }` THEN o sistema SHALL responder `202` com envelope `Accepted` (`docs/openapi.yaml`), exista ou não conta elegível.
2. WHEN o e-mail normalizado corresponde a usuário `active` THEN o sistema SHALL criar `email_action_tokens` com `purpose=password_reset`, `expires_at=now+1800s`, `used_at=null` e hash SHA-256 do plaintext, e enfileirar exatamente um job de envio.
3. WHEN o e-mail não existe OU o usuário não é `active` (`pending_verification`, `suspended`, `deletion_pending`) THEN o sistema SHALL responder o mesmo `202` **sem** persistir token e **sem** enfileirar job.
4. WHEN reset-request bem-sucedido para conta `active` THEN tokens `password_reset` anteriores não usados do mesmo usuário SHALL tornar-se inválidos antes da resposta `202`.
5. WHEN o job de recuperação executa THEN SHALL enviar e-mail via Resend contendo URL configurada com o token plaintext **somente** no corpo; plaintext SHALL NOT aparecer em logs, `failed_jobs`, traces ou assunto de forma reproduzível.
6. WHEN rate limit de reset-request excedido (4ª requisição na hora para o mesmo e-mail+IP) THEN SHALL responder `429 RATE_LIMIT_EXCEEDED` com `Retry-After`.
7. WHEN validação falha (e-mail ausente/inválido, campos extras) THEN SHALL responder `422 VALIDATION_FAILED` sem side effects de token/job.
8. WHEN reset-request THEN o contador de rate limit SHALL incrementar antes do use case (conta tentativas com qualquer status HTTP da rota).

**Independent Test**: Feature — e-mail active → `202` + 1 token + 1 mail fake; e-mail desconhecido → `202` + 0 tokens + 0 mails; 4º POST → `429`.

**Requirement IDs**: AUTH-26, AUTH-29, PW-01, PW-02, PW-03, PW-04

---

### P1: Concluir reset de senha ⭐ MVP

**User Story**: Como usuário com token de recuperação, quero definir uma nova senha e invalidar todas as sessões anteriores.

**Why P1**: AUTH-27, AUTH-28, AUTH-33; fecha o fluxo forgot-password.

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/password/reset` recebe `email`, `token`, `password` e `password_confirmation` válidos correspondentes a token `password_reset` não expirado/não usado do mesmo usuário THEN SHALL responder `204 No Content`.
2. WHEN reset bem-sucedido THEN `users.password` SHALL ser o novo hash Argon2id e o registro do token SHALL ter `used_at` preenchido na mesma transação.
3. WHEN reset bem-sucedido THEN **todos** os `auth_tokens` do usuário SHALL ser removidos (`RevokeAllUserTokens`).
4. WHEN reset bem-sucedido THEN `User.status` e demais campos de perfil SHALL permanecer inalterados.
5. WHEN reset bem-sucedido THEN o sistema SHALL NOT emitir Bearer na resposta.
6. WHEN token expirado, inexistente, já usado, purpose ≠ `password_reset`, ou e-mail não corresponde ao dono do token THEN SHALL responder `422 VALIDATION_FAILED` com erro no campo `token` e message `The password reset token is invalid or has expired.`
7. WHEN a nova senha viola `PasswordPolicy` ou não confere com `password_confirmation` THEN SHALL responder `422 VALIDATION_FAILED` **sem** consumir o token.
8. WHEN a nova senha é igual à senha atual (verificada via `PasswordHasher::verify`) THEN SHALL responder `422 VALIDATION_FAILED` com `errors.password[]` contendo `code=PASSWORD_REUSED` e `message=The new password must be different from the current password.` **sem** consumir o token.
9. WHEN rate limit de reset excedido (6ª requisição na hora para IP+token) THEN SHALL responder `429 RATE_LIMIT_EXCEEDED` + `Retry-After`.
10. WHEN reset é tentado via método ≠ `POST` THEN SHALL NOT ter efeito colateral (`405` ou rota inexistente).

**Independent Test**: Feature — emitir token via request → reset → `204`; assert hash mudou; zero `auth_tokens`; login com senha nova → `200`; token reutilizado → `422` no campo `token`.

**Requirement IDs**: AUTH-27, AUTH-28, AUTH-33, PW-05, PW-06, PW-07, PW-08, PW-17

---

### P1: Alterar senha autenticado ⭐ MVP

**User Story**: Como usuário com sessão completa, quero alterar minha senha confirmando a senha atual e encerrar todas as sessões.

**Why P1**: AUTH-32, AUTH-33; requisito de produto (`docs/product.md` §3).

**Acceptance Criteria**:

1. WHEN `POST /api/v1/auth/password/change` com Bearer `session` válido e body `{ current_password, password, password_confirmation }` corretos THEN SHALL responder `204 No Content`.
2. WHEN change bem-sucedido THEN `users.password` SHALL ser o novo hash Argon2id.
3. WHEN change bem-sucedido THEN **todos** os `auth_tokens` do usuário (incluindo o apresentado) SHALL ser removidos.
4. WHEN `current_password` não confere THEN SHALL responder `401 INVALID_CREDENTIALS` com `message=The provided credentials are invalid.` **sem** alterar hash nem revogar tokens.
5. WHEN Bearer ausente/inválido THEN SHALL `401 UNAUTHENTICATED`; WHEN kind `verification` THEN SHALL `403 TOKEN_RESTRICTED`.
6. WHEN nova senha viola política ou confirmação THEN SHALL `422 VALIDATION_FAILED` sem side effects.
7. WHEN a nova senha é igual à senha atual THEN SHALL responder `422 VALIDATION_FAILED` com `errors.password[]` contendo `code=PASSWORD_REUSED` e `message=The new password must be different from the current password.` **sem** alterar hash nem revogar tokens.
8. WHEN rate limit de escritas privadas excedido THEN SHALL `429 RATE_LIMIT_EXCEEDED` + `Retry-After`.
9. WHEN campos extras no body THEN SHALL `422 VALIDATION_FAILED`.

**Independent Test**: Feature — login session → change → `204`; bearer antigo falha em rota autenticada; login com senha nova → `200`; change com senha igual → `422` + `PASSWORD_REUSED`.

**Requirement IDs**: AUTH-32, AUTH-33, PW-09, PW-10, PW-11, PW-17

---

### P1: Privacidade de tokens e senhas ⭐ MVP

**User Story**: Como plataforma, quero que senhas e tokens de reset não vazem em telemetria.

**Why P1**: Paridade AUTH-25; `docs/security.md` §13.

**Acceptance Criteria**:

1. WHEN qualquer camada processa senha ou token de reset THEN plaintext SHALL NOT aparecer em logs, exceptions, traces, métricas ou `failed_jobs`.
2. WHEN URLs de reset são construídas THEN servidores SHALL NOT registrar query string contendo o token.
3. WHEN testes usam sentinelas THEN asserts SHALL varrer mensagens de exceção/factories de erro.

**Independent Test**: Unit sentinel — marcadores ausentes de `getMessage()` em factories/exceções do fluxo.

**Requirement IDs**: PW-12

---

### P2: Contrato HTTP e headers

**User Story**: Como cliente da API, quero respostas previsíveis e headers de segurança consistentes.

**Why P2**: Fecha lacunas OpenAPI; paridade EV-12.

**Acceptance Criteria**:

1. WHEN respostas de sucesso `202`/`204` e erros deste fluxo THEN SHALL incluir `Cache-Control: private, no-store` e `request_id` / `X-Request-ID` conforme envelope OpenAPI.
2. WHEN esta fatia introduz o field code `PASSWORD_REUSED` THEN `docs/openapi.yaml` SHALL ser atualizado com o exemplo na mesma fatia.

**Independent Test**: Feature — assert headers em happy path e em um erro representativo.

**Requirement IDs**: PW-13

---

### P1: Nova senha distinta da atual ⭐ MVP

**User Story**: Como plataforma, quero rejeitar troca/redefinição quando a nova senha é idêntica à atual.

**Why P1**: Decisão de revisão 2026-07-28; evita “change” no-op que ainda assim revogaria sessões se permitido.

**Acceptance Criteria**:

1. WHEN change ou reset recebe `password` que confere com o hash atual via `PasswordHasher::verify` THEN SHALL rejeitar sem side effects de persistência de nova senha / consumo de token / revogação.
2. WHEN a rejeição ocorre THEN SHALL responder `422 VALIDATION_FAILED` com `errors.password[]` contendo `code=PASSWORD_REUSED` e `message=The new password must be different from the current password.`
3. WHEN esta fatia introduz o field code `PASSWORD_REUSED` THEN `docs/openapi.yaml` SHALL documentar o exemplo em `ValidationError` / schemas de validação na mesma fatia.

**Independent Test**: Feature — change e reset com mesma senha → `422` + `errors.password[0].code=PASSWORD_REUSED`; token de reset permanece unused; `auth_tokens` intactos no change.

**Requirement IDs**: PW-17

---

### P2: Test discovery e gates

**User Story**: Como mantenedor, quero que testes E2E desta fatia rodem no gate padrão.

**Why P2**: Paridade EV-13/14, LOG-11.

**Acceptance Criteria**:

1. WHEN `make test-backend` roda THEN testes Feature/Integration desta fatia SHALL ser descobertos via `phpunit.xml`.
2. WHEN gate final da fatia THEN `make lint && make test-backend` SHALL passar sem regressão das fatias anteriores.

**Requirement IDs**: PW-14, PW-15

---

### P2: Extensão de schema `password_reset`

**User Story**: Como plataforma, quero persistir tokens de reset na mesma tabela de ações de e-mail com CHECK atualizado.

**Why P2**: Pré-requisito estrutural (pode ser tarefa T1); rastreável.

**Acceptance Criteria**:

1. WHEN migration roda THEN `email_action_tokens_purpose_check` SHALL permitir `email_verification` e `password_reset`.
2. WHEN `EmailActionPurpose::PasswordReset` THEN `absoluteTtlSeconds()` SHALL retornar `1800`.

**Independent Test**: Integration schema contract + unit enum TTL.

**Requirement IDs**: PW-16

---

## Edge Cases

- WHEN reset-request repetido antes da expiração THEN apenas o token mais recente SHALL ser válido.
- WHEN reset concorrente com o mesmo token THEN exatamente uma requisição SHALL retornar `204`; demais `422` no campo `token`.
- WHEN token `email_verification` é enviado em `/password/reset` THEN SHALL falhar como token inválido (purpose incorreto).
- WHEN token `password_reset` é enviado em `/email/verify` THEN SHALL continuar falhando como `INVALID_VERIFICATION_TOKEN` (fora desta fatia, regressão a preservar).
- WHEN change ou reset com senha nova igual à atual THEN SHALL rejeitar (PW-17) sem revogar tokens / sem consumir token de reset.
- WHEN usuário `active` troca a senha e tenta reusar o Bearer antigo THEN SHALL `401 UNAUTHENTICATED`.
- WHEN reset-request para `pending_verification` THEN `202` sem e-mail.
- WHEN plaintext token com whitespace no reset THEN validação estrita **sem trim** (rejeitar / token inválido).
- WHEN Resend retorna 429/5xx THEN job retry; usuário e token emitido permanecem.
- WHEN enqueue do job falha após persistir token THEN se dispatch pós-commit falhar, token permanece e re-request recupera (paridade EV registro).

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| AUTH-26 | P1: Reset request | Execute | ✅ Verified |
| AUTH-27 | P1: Reset conclude | Execute | ✅ Verified |
| AUTH-28 | P1: Reset conclude | Execute | ✅ Verified |
| AUTH-29 | P1: Reset request | Execute | ✅ Verified |
| AUTH-32 | P1: Change password | Execute | ✅ Verified |
| AUTH-33 | P1: Reset + Change | Execute | ✅ Verified |
| PW-01 | P1: Reset request | Execute | ✅ Verified |
| PW-02 | P1: Reset request | Execute | ✅ Verified |
| PW-03 | P1: Reset request | Execute | ✅ Verified |
| PW-04 | P1: Reset request | Execute | ✅ Verified |
| PW-05 | P1: Reset conclude | Execute | ✅ Verified |
| PW-06 | P1: Reset conclude | Execute | ✅ Verified |
| PW-07 | P1: Reset conclude | Execute | ✅ Verified |
| PW-08 | P1: Reset conclude | Execute | ✅ Verified |
| PW-09 | P1: Change password | Execute | ✅ Verified |
| PW-10 | P1: Change password | Execute | ✅ Verified |
| PW-11 | P1: Change password | Execute | ✅ Verified |
| PW-12 | P1: Privacidade | Execute | ✅ Verified |
| PW-13 | P2: Contrato HTTP | Execute | ✅ Verified |
| PW-14 | P2: Gates | Execute | ✅ Verified |
| PW-15 | P2: Gates | Execute | ✅ Verified |
| PW-16 | P2: Schema purpose | Execute | ✅ Verified |
| PW-17 | P1: Senha ≠ atual | Execute | ✅ Verified |

**Coverage:** 23 total (6 catálogo + 17 fatia), 23 ✅ Verified (re-verify 2026-07-28 after fix `3184620`)

---

## Success Criteria

- [ ] Usuário `active` solicita reset, recebe e-mail (fake em teste), redefine senha via POST e precisa fazer login de novo.
- [ ] Reset-request para e-mail inexistente/inelegível retorna o mesmo `202` sem side effects observáveis de e-mail.
- [ ] Change com `session` atualiza hash, exige senha atual e revoga todos os Bearers.
- [ ] Nova senha igual à atual é rejeitada em change e reset (PW-17).
- [ ] Tokens expirados, usados, purpose errado e concorrentes falham com `422` no campo `token`.
- [ ] Rate limits 3/h (request), 5/h (reset) e 120/min (change) cobertos por testes.
- [ ] `make lint && make test-backend` verde com Feature E2E dos três endpoints.
- [ ] OpenAPI permanece fonte de verdade.

---

## Decisões confirmadas (revisão 2026-07-28)

| # | Decisão |
| --- | --- |
| 1 | E-mail de reset somente para conta `active` |
| 2 | Token de reset inválido → `422 VALIDATION_FAILED` no campo `token` (sem `403` novo) |
| 3 | `current_password` incorreta no change → `401 INVALID_CREDENTIALS` |
| 4 | Novo reset-request invalida tokens `password_reset` anteriores não usados |
| 5 | URL do e-mail → `{APP_URL}/reset-password?token=…` |
| 6 | Nova senha **não** pode ser igual à atual — em **change e reset** |
| 7 | Timing no reset-request: dummy `PasswordHasher::verify` (paridade login) |
| 8 | Senha igual → `422` + `errors.password[].code=PASSWORD_REUSED` + message fixa |

---

## Referências

| Documento | Uso |
| --- | --- |
| `docs/product.md` §3 | Forgot/reset; change revoga sessões |
| `docs/api.md` §3.1–3.2, §8 | Endpoints, TTL 30 min, rate limits, revogação |
| `docs/openapi.yaml` | `changePassword`, `requestPasswordReset`, `resetPassword` |
| `docs/security.md` §4.3, §11, §13 | POST explícito, TTL, rate limit, telemetria |
| `docs/data-model.md` §3 | `email_action_tokens` |
| `docs/testing.md` §6.1 | Tokens de recuperação, revogação, anti-enumeração |
| `.specs/features/auth/README.md` | Catálogo AUTH-26…29, 32, 33 |
| `.specs/features/auth/email-verification/spec.md` | Padrão de purpose, job, privacidade, invalidação |
| `.specs/features/auth/password/context.md` | Decisões de gray areas |
