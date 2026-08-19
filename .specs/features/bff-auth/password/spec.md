# BFF Auth — Senha

**Status:** Verified — 2026-08-18  
**Fatia:** 7 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-60 … BFFUI-63  
**Requirement IDs (fatia):** PW-01 … PW-24  
**Depende de:** [login](../login/spec.md) (Verified), [csrf-proxy](../csrf-proxy/spec.md) (Verified), [session-core](../session-core/spec.md) (Verified); idealmente [email-verification](../email-verification/spec.md) (Verified)  
**Upstream API:** `POST /api/v1/auth/password/reset-request`, `POST /api/v1/auth/password/reset`, `POST /api/v1/auth/password/change` (Auth API Verified — `.specs/features/auth/password/spec.md`)

## Problem Statement

Usuários precisam recuperar acesso (forgot/reset) e alterar a senha autenticados, sempre pelo browser oficial (`https://app.localhost`) sem jamais receber Bearer. A API Laravel entrega reset-request com anti-enumeração (`202` uniforme), reset com token de uso único (30 min) e revogação total de Bearers, e change com confirmação da senha atual.

Esta fatia **entregou** três mutations BFF allowlisted (`reset-request`, `reset`, `change`), páginas server-first em pt-BR (`/forgot-password`, `/reset-password`, `/settings/password`), encerramento da sessão BFF local após reset/change bem-sucedidos (API revoga todos os Bearers upstream), e testes Vitest/RTL que provam anti-enumeração, scanner-safe GET no reset, ausência de Bearer/senhas/tokens em respostas e storage. Validação: [validation.md](./validation.md).

## Goals

- [x] Route Handlers BFF `POST /api/bff/auth/password/reset-request`, `…/reset` e `…/change` registrados na `AUTH_BFF_ALLOWLIST`, com guards Origin/CSRF adequados (pré-auth nos fluxos públicos; sessão `session` no change), Bearer somente server-side e encerramento da sessão BFF após reset/change bem-sucedidos.
- [x] Páginas UI server-first: forgot com feedback uniforme em `202`; reset com token hidratado de `?token=` e submit explícito POST; change com sessão `session` + CSRF session-mode.
- [x] Política de senha (`passwordSchema`) e código `PASSWORD_REUSED` mapeados na UI; erros `422`/`401`/`429`/`5xx` alinhados à API com mensagens pt-BR.
- [x] Vitest cobre handlers (happy path, erros, destroy/clear cookie, Bearer absent), RTL dos três formulários, scanner-safe GET em reset e ausência de credenciais sensíveis em JSON/HTML/storage simulado.
- [x] Cobertura ≥75% linhas/branches nos arquivos introduzidos nesta fatia (`docs/testing.md` §4).

## Out of Scope

| Item | Motivo |
| --- | --- |
| Jobs Resend / templates de e-mail | API Auth (`.specs/features/auth/password/`) |
| Logout / logout-all / me / perfil (nome) | Fatia `session-shell` |
| Shell autenticado completo / nav de conta | Fatia `session-shell`; esta fatia entrega apenas `/settings/password` |
| Playwright E2E security gate completo | Fatia `e2e-security-gate` |
| Rate limiting BFF adicional | API Laravel já limita upstream |
| Auto-login pós-reset | Proibido pela API (paridade verify); novo login obrigatório |
| `GET` com efeito colateral no reset | `docs/security.md` §4.3 — POST explícito |
| OpenAPI do BFF como contrato público separado | BFF é boundary interno browser↔Next |
| Dashboard de Links | Fase 2 |
| Lista de dispositivos / sessões ativas | Fora do produto |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path BFF reset-request | `POST /api/bff/auth/password/reset-request` → upstream `POST /auth/password/reset-request` | AD-017; prefixo `/api/bff/...` | y |
| Path BFF reset | `POST /api/bff/auth/password/reset` → upstream `POST /auth/password/reset` | Idem | y |
| Path BFF change | `POST /api/bff/auth/password/change` → upstream `POST /auth/password/change` | Idem | y |
| Path UI forgot | `/forgot-password` | Link já presente em `/login`; paridade produto §3 | y |
| Path UI reset | `/reset-password` | URL do e-mail API: `…/reset-password?token=` (AUTH-29) | y |
| Path UI change | `/settings/password` | Coesão da fatia password; `session-shell` linka para cá | y |
| Allowlist reset-request | `{ method: 'POST', bffPath: '/api/bff/auth/password/reset-request', upstreamMethod: 'POST', upstreamPath: '/auth/password/reset-request', requireSession: false, requireCsrf: true }` | Mutation pública pré-auth (paridade login/register) | y |
| Allowlist reset | `{ method: 'POST', bffPath: '/api/bff/auth/password/reset', upstreamMethod: 'POST', upstreamPath: '/auth/password/reset', requireSession: false, requireCsrf: true }` | Idem | y |
| Allowlist change | `{ method: 'POST', bffPath: '/api/bff/auth/password/change', upstreamMethod: 'POST', upstreamPath: '/auth/password/change', requireSession: true, requireCsrf: true }` | Mutation autenticada; upstream exige Bearer `session` | y |
| Sessão exigida no change | Cookie BFF válido com `kind: 'session'` | Upstream `x-allowed-token-kinds: [session]` | y |
| Kind incorreto no change | Se `session.kind !== 'session'` → `403` `{ "message": "Forbidden." }` sem chamar Laravel | Paridade verify/resend guards | y |
| Payload reset-request upstream | `{ "email": "<normalized>" }` somente | OpenAPI `PasswordResetRequest`; `additionalProperties: false` | y |
| Payload reset upstream | `{ email, token, password, password_confirmation }` somente | OpenAPI `ResetPasswordRequest` | y |
| Payload change upstream | `{ current_password, password, password_confirmation }` somente | OpenAPI `ChangePasswordRequest` | y |
| Corpo BFF reset-request sucesso | Repassar `202` + envelope `Accepted` da API inalterado | Anti-enumeração depende de paridade exata | y |
| Corpo BFF reset sucesso | Traduz upstream `204` → BFF `200` com `{ "data": { "redirect_to": "/login", "message": "Senha redefinida. Faça login para continuar." } }` | UI precisa de destino e copy; upstream sem body | y |
| Corpo BFF change sucesso | Traduz upstream `204` → BFF `200` com `{ "data": { "redirect_to": "/login", "message": "Senha alterada. Faça login para continuar." } }` | Idem | y |
| Pós-reset/change sessão BFF | `destroySession` best-effort (se cookie existir) + `clearSessionCookie` no response de sucesso | API revoga todos Bearers; cookie BFF obsoleto (BFFUI-63) | y |
| Pós-reset/change CSRF | **Não** emitir novo CSRF de sessão após destroy | Sessão encerrada; próximo login emite novo par | y |
| Reset-request/reset falha | **Não** destroy de sessão BFF | Usuário pode retry; falha não revoga upstream | y |
| Token na URL do e-mail | Query `?token=` hidrata campo do form **somente**; submit é POST explícito | Security §4.3; paridade verify-email | y |
| Strip query após hidratar | Client remove `?token=` via `history.replaceState` após montar form (best-effort) | Reduz vazamento por referrer/histórico | y |
| Trim do token | **Sem** trim no client nem BFF — token opaco validado estritamente | Paridade API Auth password spec | y |
| E-mail no reset | Usuário informa no formulário; **não** vem na URL do e-mail | URL API só inclui `token` | y |
| Normalização de e-mail | Trim + lowercase no client antes do submit (forgot + reset) | Paridade login/register | y |
| Política de senha client | Reutilizar `passwordSchema` (12–128, composição ASCII) em reset e change | Já em `modules/auth/schemas/password-schema.ts` | y |
| `current_password` client | Required, `maxLength: 128`; **sem** revalidar composição | Paridade login (`LoginRequest`) | y |
| Mensagens de erro UI | pt-BR; preservar `code` da API quando presente; 5xx/gateway genérico | Product UI pt-BR | y |
| Anti-enum forgot copy | "Se o e-mail estiver cadastrado, você receberá instruções para redefinir sua senha." (ou equivalente) em **qualquer** `202` | AUTH-26; mesma UX para e-mail existente ou não | y |
| `PASSWORD_REUSED` UI | Erro de campo em `password` pt-BR: "A nova senha deve ser diferente da senha atual." | OpenAPI example; AUTH password spec | y |
| Token reset inválido UI | Erro uniforme pt-BR no campo `token`: "Link de redefinição inválido ou expirado." | Não distinguir expirado/usado/inexistente | y |
| `INVALID_CREDENTIALS` change | Mensagem pt-BR genérica em `current_password` (credenciais inválidas) | Paridade login; API `401` | y |
| Rate limit UI | Confiar no `429` upstream + exibir `Retry-After` quando header presente | Paridade login/register/verify | y |
| Pass-through 4xx | Repassar status + body JSON (`code`, `message`, `errors` quando 422) com headers privados | UI mapeia códigos | y |
| Upstream timeout/abort | Responder `504` com mensagem genérica pt-BR | Helper existente; orçamento 10s | y |
| Upstream 500/503 | Mensagem genérica pt-BR espelhando status | Sem vazar detalhe Laravel | y |
| Bootstrap CSRF forgot/reset | GET `/forgot-password` e GET `/reset-password` SHALL garantir cookies pré-auth (`ensurePreAuthCsrfCookies`) | `requireSession: false` usa modo pré-auth | y |
| Bootstrap CSRF change | GET `/settings/password` exige sessão `session`; CSRF derivado da sessão via cookies existentes | `requireSession: true` usa modo session | y |
| Visitante em `/settings/password` | Redirect `/login` | Change exige autenticação | y |
| Sessão `verification` em `/settings/password` | Redirect `/verify-email` | Change exige `session` kind | y |
| Sessão `session` em forgot/reset | Páginas públicas permanecem acessíveis; sucesso em reset ainda destroy sessão | Usuário pode iniciar recovery estando logado | y |
| Paths permitidos com `verification` | Expandir allowlist mínima para incluir `/forgot-password` e `/reset-password` além de `/verify-email`, `/login`, `/terms` | Recovery acessível durante conta restrita | y |
| Link "Voltar ao login" | Presente em forgot e reset | Jornada de recovery | y |
| Sessão pré-existente no reset sucesso | `destroySession` best-effort mesmo se `requireSession: false` no handler | BFFUI-63; API revogou Bearers | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Zod client espelha OpenAPI; `passwordSchema` em reset/change; `email` max 254; `current_password` max 128; BFF valida JSON parseável antes de upstream; rejeitar body malformado localmente (`400`) |
| Failure / partial-failure states | Origin/CSRF → `403` genérico; forgot sempre `202` se upstream aceitar; token inválido → `422` campo `token`; senha reutilizada → `422` + `PASSWORD_REUSED`; credencial atual errada → `401`; validação → `422`; rate limit → `429`; upstream fail → `504`/`500` genérico; **nunca** destroy cookie se upstream reset/change falhou |
| Idempotency / retry / duplicate handling | Novo reset-request invalida tokens anteriores (API); reset/change não idempotentes após sucesso; submit duplo UI desabilitado durante pending |
| Auth boundaries & rate limits | reset-request/reset públicos com CSRF pré-auth; change exige sessão `session` + CSRF session-mode; rate limits só upstream (3/h reset-request e-mail+IP; 5/h reset IP+token; 120/min change por conta) |
| Concurrency / ordering | Reset concorrente: API garante um `204`; demais `422` token inválido; BFF destroy cookie apenas no response de sucesso |
| Data lifecycle / expiry | Token reset 30 min (API); após reset/change sucesso: Redis session removida + cookie expirado; sessão BFF `session`: 7d abs / 24h idle |
| Observability | Proibido logar senha plaintext, token de reset, Bearer, session ID bruto ou query `?token=` em logs de app |
| External-dependency failure | Laravel timeout → `504` sem destroy de sessão; Redis fail em `destroySession` pós-sucesso → ainda clear cookie + `200` success (best-effort destroy) |
| State-transition integrity | BFF **não** altera `User.status` localmente; só reflete upstream; pós-reset/change UI exige novo login |

---

## Entregáveis técnicos (mínimo)

```txt
frontend/
  app/
    forgot-password/
      page.tsx                    # Server-first; bootstrap CSRF pré-auth
      page.test.tsx               # RTL: render, validação, submit MSW, anti-enum
    reset-password/
      page.tsx                    # Server-first; hidrata ?token=; bootstrap CSRF
      page.test.tsx               # RTL: scanner-safe GET, submit, erros
    settings/
      password/
        page.tsx                  # Server-first; guard session; redirect rules
        page.test.tsx             # RTL: guard, submit change MSW
    api/bff/auth/password/
      reset-request/
        route.ts                  # POST handler produto
        route.test.ts             # Vitest: 202/422/429 + anti-enum pass-through
      reset/
        route.ts                  # POST handler produto
        route.test.ts             # Vitest: 204→200, destroy, Bearer absent
      change/
        route.ts                  # POST handler produto
        route.test.ts             # Vitest: 204→200, kind guard, destroy
  modules/auth/
    schemas/
      forgot-password-schema.ts   # Zod PasswordResetRequest mirror
      reset-password-schema.ts    # Zod ResetPasswordRequest mirror + passwordSchema
      change-password-schema.ts   # Zod ChangePasswordRequest mirror + passwordSchema
    components/
      forgot-password-form.tsx
      reset-password-form.tsx
      change-password-form.tsx
    bff/
      allowlist.ts                # + entradas reset-request, reset, change
    lib/
      verification-guard.ts       # + /forgot-password, /reset-password na allowlist
    services/
      bff-password-reset-request.ts
      bff-password-reset.ts
      bff-password-change.ts
```

Handlers reset/change **não** podem repassar `204` cru sem encerrar sessão BFF quando aplicável — devem `destroySession` best-effort, `clearSessionCookie` e responder corpo sanitizado com `redirect_to`.

---

## User Stories

### P1: Solicitar recuperação de senha (forgot) via BFF ⭐ MVP

**User Story**: Como usuário que esqueceu a senha, quero informar meu e-mail e receber feedback uniforme sem revelar se a conta existe.

**Why P1**: Entrada do fluxo recovery; BFFUI-60; AUTH-26.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/password/reset-request` recebe `{ "email": "..." }` válido com guards Origin+CSRF pré-auth satisfeitos e upstream retorna `202` THEN o handler SHALL chamar Laravel via entrada allowlisted com body `{ email }` normalizado e repassar status `202` e envelope `Accepted` ao browser **sem** criar ou alterar sessão BFF.
2. WHEN upstream retorna `202` para e-mail inexistente ou conta inelegível THEN o BFF SHALL repassar `202` com o **mesmo** envelope que para conta elegível (anti-enumeração).
3. WHEN reset-request bem-sucedido THEN headers SHALL incluir `Cache-Control: private, no-store` e nenhum cookie `__Host-fl_session` SHALL ser emitido ou alterado.
4. WHEN upstream retorna `422 VALIDATION_FAILED` THEN o BFF SHALL repassar status e `errors` sem chamar destroy de sessão.
5. WHEN upstream retorna `429 RATE_LIMIT_EXCEEDED` THEN o BFF SHALL repassar status, corpo e header `Retry-After` quando presente.
6. WHEN guards Origin/CSRF falham THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel.

**Independent Test**: Vitest handler upstream `202` (e-mail existente e inexistente) — corpos idênticos ao browser; assert zero Set-Cookie session; RTL forgot exibe mesma mensagem de sucesso em ambos cenários MSW.

**Requirement IDs**: BFFUI-60, PW-01, PW-02, PW-03

---

### P1: UI de forgot password server-first ⭐ MVP

**User Story**: Como visitante, quero uma página em pt-BR para solicitar recuperação de senha, acessível a partir de 360px.

**Why P1**: BFFUI-60; complemento do link em `/login`.

**Acceptance Criteria**:

1. WHEN o usuário navega para `GET /forgot-password` THEN a página SHALL renderizar formulário com campo e-mail, rótulos/erros em pt-BR e link "Voltar ao login" para `/login`, usando primitivos `shared`.
2. WHEN a página carrega THEN SHALL garantir cookies CSRF pré-auth (`ensurePreAuthCsrfCookies`) antes de permitir submit bem-sucedido.
3. WHEN o usuário submete e-mail sintaticamente válido THEN o client SHALL `POST /api/bff/auth/password/reset-request` com `Content-Type: application/json`, header `X-CSRF-Token` e body `{ email }` (e-mail trim + lowercase).
4. WHEN o BFF responde `202` THEN a UI SHALL exibir mensagem de sucesso uniforme pt-BR (anti-enum) **independente** do e-mail informado e SHALL NOT revelar existência de conta.
5. WHEN o formulário tem `email` inválido THEN o client SHALL bloquear submit com erro de campo **sem** chamar o BFF.
6. WHEN inspeção de HTML renderizado e respostas fetch THEN Bearer SHALL NOT aparecer.

**Independent Test**: RTL + MSW; dois e-mails distintos → mesma copy de sucesso; validação Zod de e-mail.

**Requirement IDs**: BFFUI-60, PW-04, PW-05

---

### P1: Concluir reset de senha via BFF ⭐ MVP

**User Story**: Como usuário com token de recuperação, quero definir uma nova senha sem nunca ver Bearer e com todas as sessões invalidadas.

**Why P1**: BFFUI-61, BFFUI-63; AUTH-27, AUTH-28.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/password/reset` recebe `{ email, token, password, password_confirmation }` válidos com guards Origin+CSRF pré-auth satisfeitos e upstream retorna `204` THEN o handler SHALL chamar Laravel via entrada allowlisted, invocar `destroySession(sessionId)` best-effort se cookie BFF existir, emitir `clearSessionCookie`, e responder `200` com `{ "data": { "redirect_to": "/login", "message": "Senha redefinida. Faça login para continuar." } }`.
2. WHEN reset bem-sucedido THEN headers SHALL incluir `Cache-Control: private, no-store` e cookie `__Host-fl_session` SHALL estar expirado/removido.
3. WHEN reset bem-sucedido THEN a resposta BFF SHALL NOT conter Bearer, senhas nem o plaintext do token submetido.
4. WHEN upstream retorna qualquer status ≠ `204` THEN o handler SHALL NOT chamar `destroySession` nem `clearSessionCookie`.
5. WHEN upstream retorna `422` com erro no campo `token` THEN o BFF SHALL repassar status e corpo ao browser sem destruir sessão BFF.
6. WHEN upstream retorna `422` com `errors.password[].code=PASSWORD_REUSED` THEN o BFF SHALL repassar sem consumir token (paridade API).

**Independent Test**: Vitest upstream `204` com cookie BFF mock → assert destroy + clear cookie + body `redirect_to`; upstream `422` token → sessão intacta.

**Requirement IDs**: BFFUI-61, BFFUI-63, PW-06, PW-07, PW-08

---

### P1: UI de reset password server-first ⭐ MVP

**User Story**: Como usuário com link de recuperação, quero uma página em pt-BR para informar e-mail, nova senha e confirmar — sem consumo automático do token no carregamento.

**Why P1**: BFFUI-61; scanner-safe; paridade verify-email.

**Acceptance Criteria**:

1. WHEN o usuário navega para `GET /reset-password` THEN a página SHALL renderizar formulário com campos `email`, `token`, `password`, `password_confirmation`, rótulos/erros em pt-BR e link "Voltar ao login", usando primitivos `shared`.
2. WHEN `?token=` está presente na URL THEN o valor SHALL hidratar o campo `token` **sem** disparar reset automaticamente no load.
3. WHEN o formulário monta com `?token=` THEN o client SHOULD remover o query param via `history.replaceState` mantendo o valor no state do form.
4. WHEN a página carrega THEN SHALL garantir cookies CSRF pré-auth antes do submit.
5. WHEN o usuário submete dados válidos THEN o client SHALL `POST /api/bff/auth/password/reset` com CSRF e body completo `ResetPasswordRequest`.
6. WHEN reset BFF responde `200` com `redirect_to` THEN a UI SHALL exibir mensagem de sucesso e navegar para `/login`.
7. WHEN upstream/BFF retorna `422` com `PASSWORD_REUSED` THEN a UI SHALL exibir erro pt-BR no campo `password`.
8. WHEN upstream/BFF retorna `422` com erro em `token` THEN a UI SHALL exibir mensagem uniforme pt-BR de link inválido/expirado no campo `token`.
9. WHEN `GET /reset-password` (com ou sem `?token=`) THEN o servidor SHALL NOT invocar `POST` reset durante o render (scanner-safe).
10. WHEN testes RTL montam a página com `?token=` THEN SHALL assert zero chamadas fetch para reset até interação explícita.

**Independent Test**: RTL spy em `fetch` durante render; MSW happy path + PASSWORD_REUSED + token inválido.

**Requirement IDs**: BFFUI-61, PW-09, PW-10, PW-11

---

### P1: Alterar senha autenticada via BFF ⭐ MVP

**User Story**: Como usuário autenticado, quero alterar minha senha confirmando a atual, com revogação de todas as sessões e encerramento da sessão BFF atual.

**Why P1**: BFFUI-62, BFFUI-63; AUTH-32.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/password/change` recebe `{ current_password, password, password_confirmation }` válidos com guards Origin+CSRF session-mode satisfeitos, sessão BFF `kind: 'session'` válida e upstream retorna `204` THEN o handler SHALL chamar Laravel com Bearer server-side, invocar `destroySession(sessionId)` best-effort, emitir `clearSessionCookie`, e responder `200` com `{ "data": { "redirect_to": "/login", "message": "Senha alterada. Faça login para continuar." } }`.
2. WHEN change bem-sucedido THEN cookie `__Host-fl_session` SHALL estar expirado/removido e nenhum Bearer SHALL aparecer na resposta.
3. WHEN upstream retorna `401 INVALID_CREDENTIALS` (senha atual incorreta) THEN o BFF SHALL repassar status e corpo **sem** destroy de sessão BFF.
4. WHEN upstream retorna `422` com `PASSWORD_REUSED` THEN o BFF SHALL repassar **sem** alterar hash nem revogar tokens (paridade API).
5. WHEN `session.kind !== 'session'` THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel.
6. WHEN upstream retorna qualquer status ≠ `204` THEN o handler SHALL NOT chamar `destroySession` nem `clearSessionCookie`.

**Independent Test**: Vitest upstream `204`/`401`/`422 PASSWORD_REUSED`; assert destroy apenas em sucesso.

**Requirement IDs**: BFFUI-62, BFFUI-63, PW-12, PW-13, PW-14

---

### P1: UI de change password server-first ⭐ MVP

**User Story**: Como usuário com sessão completa, quero alterar minha senha em uma página dedicada em pt-BR.

**Why P1**: BFFUI-62; entrega `/settings/password` nesta fatia.

**Acceptance Criteria**:

1. WHEN visitante sem sessão navega para `GET /settings/password` THEN SHALL `redirect('/login')`.
2. WHEN usuário com sessão `verification` visita `/settings/password` THEN SHALL `redirect('/verify-email')`.
3. WHEN usuário com sessão `session` visita `/settings/password` THEN SHALL renderizar formulário com `current_password`, `password`, `password_confirmation`, rótulos/erros em pt-BR, usando primitivos `shared`.
4. WHEN o usuário submete dados válidos THEN o client SHALL `POST /api/bff/auth/password/change` com header `X-CSRF-Token` session-mode e body `ChangePasswordRequest`.
5. WHEN change BFF responde `200` com `redirect_to` THEN a UI SHALL exibir mensagem de sucesso e navegar para `/login`.
6. WHEN BFF responde `401 INVALID_CREDENTIALS` THEN a UI SHALL exibir erro pt-BR em `current_password` sem revelar motivo além do contrato API.
7. WHEN BFF responde `422 PASSWORD_REUSED` THEN a UI SHALL exibir erro pt-BR no campo `password`.
8. WHEN validação client falha (política de senha ou confirmação divergente) THEN SHALL bloquear submit **sem** chamar BFF.
9. WHEN inspeção de HTML e respostas fetch THEN senhas e Bearer SHALL NOT aparecer.

**Independent Test**: RTL guards + MSW change happy path + 401 + PASSWORD_REUSED.

**Requirement IDs**: BFFUI-62, PW-15, PW-16, PW-17

---

### P1: Validação de entrada e política de senha ⭐ MVP

**User Story**: Como usuário, quero feedback imediato de formulário inválido; como BFF, quero rejeitar payloads inválidos sem side effects.

**Why P1**: Contrato OpenAPI; `passwordSchema` já existente.

**Acceptance Criteria**:

1. WHEN reset/change UI viola `passwordSchema` ou `password_confirmation` diverge THEN o client SHALL bloquear submit com erros de campo pt-BR **sem** chamar BFF.
2. WHEN forgot UI tem `email` inválido THEN o client SHALL bloquear submit **sem** chamar BFF.
3. WHEN reset UI tem `token` vazio THEN o client SHALL bloquear submit com erro de campo **sem** chamar BFF.
4. WHEN BFF recebe body JSON malformado ou `Content-Type` inválido THEN SHALL responder `400` com mensagem genérica pt-BR **sem** chamar Laravel.
5. WHEN BFF reset/change/forgot recebe campos extras no body browser THEN SHALL encaminhar ao Laravel **somente** os campos do schema upstream (`additionalProperties: false` parity).
6. WHEN token contém apenas whitespace THEN o client SHALL tratá-lo como inválido (sem trim silencioso que mascare erro).

**Independent Test**: RTL validation matrix por schema; Vitest malformed body → 400 sem fetch upstream.

**Requirement IDs**: PW-18

---

### P1: Guards BFF (Origin / CSRF / sessão) ⭐ MVP

**User Story**: Como mantenedor, quero que mutations de senha rejeitem cross-site, CSRF inválido ou sessão inadequada antes de chamar Laravel.

**Why P1**: Integração `csrf-proxy`; `docs/testing.md` §6.2.

**Acceptance Criteria**:

1. WHEN `POST` reset-request/reset chega sem `Origin` válido ou CSRF ausente/inválido THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel.
2. WHEN `POST` change chega sem sessão válida, CSRF session-mode inválido ou kind ≠ `session` THEN o handler SHALL responder `403` **sem** invocar Laravel.
3. WHEN guards falham THEN a resposta SHALL incluir `Cache-Control: private, no-store`.
4. WHEN guards passam em fluxos públicos THEN SHALL usar modo CSRF pré-auth; em change SHALL usar modo CSRF `session`.

**Independent Test**: Vitest Request sintéticos; assert fetch upstream não chamado.

**Requirement IDs**: PW-19

---

### P1: Rate limiting e falhas upstream ⭐ MVP

**User Story**: Como usuário, quero feedback claro em limites de taxa e indisponibilidade sem exposição de detalhes internos.

**Why P1**: Paridade API; robustez BFF.

**Acceptance Criteria**:

1. WHEN upstream retorna `429 RATE_LIMIT_EXCEEDED` em qualquer handler desta fatia THEN o BFF SHALL repassar status `429`, corpo e header `Retry-After` quando presente **sem** destroy de sessão (exceto se já houve sucesso — N/A).
2. WHEN upstream aborta por timeout (10s) THEN o BFF SHALL responder `504` com mensagem genérica pt-BR.
3. WHEN upstream retorna `500` ou `503` THEN o BFF SHALL responder mensagem genérica pt-BR espelhando status.
4. WHEN upstream retorna `403` com `code=ACCOUNT_SUSPENDED` ou `code=ACCOUNT_PENDING_DELETION` THEN o BFF SHALL repassar; UI SHALL exibir mensagem pt-BR específica do `code`.
5. WHEN a UI recebe `429` THEN SHALL exibir mensagem pt-BR de limite excedido e orientação temporal se `Retry-After` presente.

**Independent Test**: Vitest upstream `429`/`504`/`500`; RTL throttle message.

**Requirement IDs**: BFFUI-32, PW-20, PW-21

---

### P1: Privacidade de senhas e tokens ⭐ MVP

**User Story**: Como plataforma, quero que senhas e tokens de reset não vazem em telemetria, logs ou respostas sanitizadas.

**Why P1**: AUTH password spec; `docs/security.md` §4.3, §13.

**Acceptance Criteria**:

1. WHEN handlers ou serviços BFF processam forgot/reset/change THEN plaintext de senha, `current_password` e token de reset SHALL NOT aparecer em `console.log`, mensagens de erro serializadas ao browser ou fixtures expostas ao bundle client.
2. WHEN testes usam valores sentinela de senha/token THEN asserts SHALL varrer JSON de resposta e HTML renderizado.
3. WHEN reset/change bem-sucedidos THEN o corpo BFF SHALL NOT incluir senhas nem tokens submetidos.

**Independent Test**: Vitest sentinel scan; RTL HTML scan.

**Requirement IDs**: PW-22

---

### P1: UX de sessão restrita (recovery durante verification) ⭐ MVP

**User Story**: Como usuário com sessão `verification`, devo poder acessar fluxos de recuperação de senha sem ser bloqueado pelo guard restrito.

**Why P1**: Jornada edge case; paridade paths públicos.

**Acceptance Criteria**:

1. WHEN usuário com sessão `verification` navega para `/forgot-password` ou `/reset-password` THEN SHALL NOT ser redirecionado para `/verify-email` pelo guard mínimo de verification.
2. WHEN helper `verification-guard` é atualizado THEN SHALL incluir `/forgot-password` e `/reset-password` na allowlist de paths permitidos.
3. WHEN reset bem-sucedido com sessão `verification` ativa THEN o handler SHALL destroy essa sessão BFF (mesmo comportamento que `session` kind).

**Independent Test**: Vitest guard paths; página forgot/reset com sessão verification mock → render OK.

**Requirement IDs**: PW-23

---

### P2: Allowlist, schemas e descoberta de testes ⭐ Should have

**User Story**: Como mantenedor, quero entradas na allowlist, schemas Zod compartilhados e testes descobertos pelo suite padrão.

**Why P2**: Auditabilidade e gates CI.

**Acceptance Criteria**:

1. WHEN `AUTH_BFF_ALLOWLIST` é inspecionada THEN SHALL conter entradas reset-request, reset e change além das existentes.
2. WHEN `make test-frontend` roda THEN testes em `app/api/bff/auth/password/`, `app/forgot-password/`, `app/reset-password/`, `app/settings/password/` e schemas SHALL ser descobertos.
3. WHEN schemas são testados THEN SHALL espelhar OpenAPI (`PasswordResetRequest`, `ResetPasswordRequest`, `ChangePasswordRequest`).

**Independent Test**: Inspeção estática allowlist; `make test-frontend` discovery; unit schemas.

**Requirement IDs**: PW-24

---

## Edge Cases

- WHEN usuário abre link de reset em dispositivo sem cookie BFF THEN `/reset-password?token=` renderiza form; submit público com CSRF pré-auth funciona.
- WHEN `?token=` inválido na query mas submit com e-mail correto THEN API retorna `422` no campo `token` sem destroy de sessão.
- WHEN novo reset-request invalida token anterior (API) e usuário submete token antigo THEN `422` mensagem uniforme no campo `token`.
- WHEN reset concorrente (dois tabs) THEN no máximo um sucesso `200`; outro tab pode receber `422` ou sessão já destruída no primeiro — segundo tab trata como sessão expirada se cookie limpo.
- WHEN upstream reset `204` mas `destroySession` falha (Redis down) THEN BFF ainda clear cookie e retorna sucesso; sessão órfã expira por TTL.
- WHEN `429` sem header `Retry-After` THEN UI exibe mensagem genérica de limite.
- WHEN change com senha atual correta mas nova senha igual à atual THEN `422 PASSWORD_REUSED` sem logout.
- WHEN conta `suspended` tenta change com bearer ainda válido THEN `403 ACCOUNT_SUSPENDED`.
- WHEN token na query é URL-encoded THEN página decodifica uma vez antes de hidratar form.
- WHEN usuário cola token com newline trailing THEN validação client rejeita ou API retorna `422` — **sem** trim automático no BFF.
- Bearer upstream nunca aparece em traces de teste serializados ou MSW handlers expostos ao browser bundle.

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| BFFUI-60 | P1: Forgot BFF + UI | Design | ✅ Verified |
| BFFUI-61 | P1: Reset BFF + UI | Design | ✅ Verified |
| BFFUI-62 | P1: Change BFF + UI | Design | ✅ Verified |
| BFFUI-63 | P1: Reset/change destroy sessão BFF | Design | ✅ Verified |
| BFFUI-32 | P1: Rate limit / erros upstream | Design | ✅ Verified |
| PW-01 | P1: Forgot — handler 202 pass-through | Design | ✅ Verified |
| PW-02 | P1: Forgot — anti-enum paridade | Design | ✅ Verified |
| PW-03 | P1: Forgot — sem cookie sessão | Design | ✅ Verified |
| PW-04 | P1: UI forgot — render e CSRF pré-auth | Design | ✅ Verified |
| PW-05 | P1: UI forgot — sucesso uniforme | Design | ✅ Verified |
| PW-06 | P1: Reset — happy path handler | Design | ✅ Verified |
| PW-07 | P1: Reset — destroy/clear cookie | Design | ✅ Verified |
| PW-08 | P1: Reset — erros sem destroy | Design | ✅ Verified |
| PW-09 | P1: UI reset — render e hidratação token | Design | ✅ Verified |
| PW-10 | P1: UI reset — scanner-safe GET | Design | ✅ Verified |
| PW-11 | P1: UI reset — PASSWORD_REUSED / token inválido | Design | ✅ Verified |
| PW-12 | P1: Change — happy path handler | Design | ✅ Verified |
| PW-13 | P1: Change — kind guard session | Design | ✅ Verified |
| PW-14 | P1: Change — erros sem destroy | Design | ✅ Verified |
| PW-15 | P1: UI change — guards redirect | Design | ✅ Verified |
| PW-16 | P1: UI change — submit e sucesso | Design | ✅ Verified |
| PW-17 | P1: UI change — 401 / PASSWORD_REUSED | Design | ✅ Verified |
| PW-18 | P1: Validação entrada / passwordSchema | Design | ✅ Verified |
| PW-19 | P1: Guards Origin/CSRF/sessão | Design | ✅ Verified |
| PW-20 | P1: Rate limit pass-through | Design | ✅ Verified |
| PW-21 | P1: Falhas upstream/gateway | Design | ✅ Verified |
| PW-22 | P1: Privacidade senha/token | Design | ✅ Verified |
| PW-23 | P1: Verification guard paths recovery | Design | ✅ Verified |
| PW-24 | P2: Allowlist + schemas + test discovery | Design | ✅ Verified |

**Coverage:** 29 total, 29 mapped ✅

---

## Success Criteria

- [x] Usuário completa forgot em `/forgot-password` e vê mensagem uniforme em `202` sem indício de enumeração.
- [x] Usuário completa reset em `/reset-password` com token do e-mail, chega a `/login` **sem** Bearer no browser e **sem** cookie de sessão BFF ativo.
- [x] Usuário autenticado altera senha em `/settings/password`, é deslogado e redirecionado a `/login` com confirmação pt-BR.
- [x] Abrir `GET /reset-password?token=…` não chama reset até clique explícito.
- [x] `PASSWORD_REUSED` e token inválido exibem erros pt-BR nos campos corretos.
- [x] Guards CSRF/Origin bloqueiam sem chamar Laravel; change bloqueia kind `verification`.
- [x] `make test-frontend` passa com cobertura ≥75% nos arquivos novos da fatia.
- [x] Nenhum teste Vitest/RTL falha se `JSON.stringify(resposta)` ou HTML contiver Bearer de fixture ou senha/token sentinela de teste.

---

## Referências

| Documento | Uso |
| --- | --- |
| `.specs/features/auth/password/spec.md` | Contrato upstream verificado (AUTH-26…29, AUTH-32) |
| `.specs/features/bff-auth/login/spec.md` | Padrão handler/UI pré-auth; anti-enum; link `/forgot-password` |
| `.specs/features/bff-auth/email-verification/spec.md` | Scanner-safe GET; hidratação `?token=`; destroy pós-sucesso |
| `.specs/features/bff-auth/register/spec.md` | `passwordSchema`; política de senha client |
| `.specs/features/bff-auth/csrf-proxy/spec.md` | Guards, allowlist, `requireSession` |
| `.specs/features/bff-auth/session-core/spec.md` | `destroySession`, `clearSessionCookie`, TTL |
| `.specs/features/bff-auth/session-shell/spec.md` | Perfil/logout (change UI entregue aqui) |
| `docs/openapi.yaml` | `requestPasswordReset`, `resetPassword`, `changePassword`, schemas |
| `docs/product.md` §3, §9 | Jornada recovery; revogação de sessões |
| `docs/security.md` §4.3, §5, §8 | POST explícito, token 30 min, rate limits |
| `docs/testing.md` §3.2, §4, §6.1 | Vitest/RTL/MSW BFF; cobertura |
| `docs/api.md` §3.1, §8 | Endpoints e rate limits upstream |
