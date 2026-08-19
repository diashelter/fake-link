# BFF Auth — Verificação de e-mail

**Status:** Verified — 2026-08-18  
**Fatia:** 6 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-50 … BFFUI-52  
**Requirement IDs (fatia):** EV-01 … EV-22  
**Depende de:** [register](../register/spec.md) (Verified), [csrf-proxy](../csrf-proxy/spec.md) (Verified), [session-core](../session-core/spec.md) (Verified)  
**Upstream API:** `POST /api/v1/auth/email/verify`, `POST /api/v1/auth/email/verification-notification` (Auth API Verified — `.specs/features/auth/email-verification/spec.md`)

## Problem Statement

Usuários recém-cadastrados chegam a `/verify-email` com sessão BFF restrita (`verification`) e precisam confirmar posse do e-mail por ação explícita no browser — sem que prefetch, scanners ou `GET` com side-effect consumam o token. A API Laravel entrega verify/resend com Bearer `verification`, rate limits e revogação pós-verify (AUTH-12).

Esta fatia **entregou** `POST /api/bff/auth/email/verify` e `POST /api/bff/auth/email/resend` (proxy allowlisted com sessão + CSRF), a página `/verify-email` server-first em pt-BR, guards mínimos de sessão restrita e testes Vitest/RTL que provam que abrir o link do e-mail **não** verifica automaticamente. Validação: [validation.md](./validation.md).

## Goals

- [x] Route Handlers BFF verify + resend registrados na `AUTH_BFF_ALLOWLIST`, com guards Origin/CSRF **autenticados** (`requireSession: true`), Bearer somente server-side e encerramento da sessão BFF após verify bem-sucedido.
- [x] Página UI `/verify-email` server-first: hidrata token da query `?token=` apenas no formulário; submit explícito via POST BFF; reenvio com feedback de `202`/`429`.
- [x] UX restrita: usuário com sessão `verification` não acessa shell autenticado (`/` e rotas futuras redirecionam para `/verify-email`); pós-verify redireciona para `/login` com mensagem pt-BR.
- [x] Matriz de erros alinhada à API (`403 INVALID_VERIFICATION_TOKEN`, `403 EMAIL_ALREADY_VERIFIED`, `401`, `422`, `429`, `5xx`) com mensagens pt-BR; token de e-mail ausente de logs e respostas sanitizadas.
- [x] Vitest cobre handlers (happy path, erros, destroy cookie, Bearer absent), RTL (render, submit, resend, scanner-safe GET) e guards de sessão restrita.
- [x] Cobertura ≥75% linhas/branches nos arquivos introduzidos nesta fatia (`docs/testing.md` §4).

## Out of Scope

| Item | Motivo |
| --- | --- |
| Envio Resend / jobs / templates de e-mail | API Auth (`.specs/features/auth/email-verification/`) |
| Login / register (handlers + UI) | Fatias 4–5 (entregues) |
| Change / forgot / reset password | Fatia `password` |
| Logout / me / perfil / guards completos de shell | Fatia `session-shell` (esta fatia entrega guards mínimos + contrato exportável) |
| Playwright E2E security gate completo | Fatia `e2e-security-gate` |
| Rate limiting BFF adicional | API Laravel já limita upstream (3/h reenvio, 5/h verify por conta) |
| Auto-login pós-verify | Proibido pela API (AUTH-12); novo login obrigatório |
| `GET` com efeito colateral na API ou BFF | `docs/security.md` §4.3 |
| OpenAPI do BFF como contrato público separado | BFF é boundary interno browser↔Next |
| Dashboard de Links / shell autenticado completo | Fase 2; placeholder `/` recebe guard mínimo apenas |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path BFF verify | `POST /api/bff/auth/email/verify` → upstream `POST /auth/email/verify` | AD-017; prefixo `/api/bff/...` | y |
| Path BFF resend | `POST /api/bff/auth/email/resend` → upstream `POST /auth/email/verification-notification` | Nome curto no BFF; upstream mantém path OpenAPI | y |
| Path UI | `/verify-email` | Auth API URL do e-mail; login/register já redirecionam aqui | y |
| Allowlist verify | `{ method: 'POST', bffPath: '/api/bff/auth/email/verify', upstreamMethod: 'POST', upstreamPath: '/auth/email/verify', requireSession: true, requireCsrf: true }` | Mutation autenticada com Bearer restrito | y |
| Allowlist resend | `{ method: 'POST', bffPath: '/api/bff/auth/email/resend', upstreamMethod: 'POST', upstreamPath: '/auth/email/verification-notification', requireSession: true, requireCsrf: true }` | Idem | y |
| Sessão exigida | Ambos handlers exigem cookie BFF válido com `kind: 'verification'` | Upstream exige Bearer `verification`; `session` kind bloqueado no BFF antes do upstream | y |
| Kind incorreto no BFF | Se `session.kind !== 'verification'` → `403` `{ "message": "Forbidden." }` sem chamar Laravel | Evita round-trip; paridade guards BFF | y |
| Payload verify upstream | `{ "token": "<plaintext>" }` somente — campos extras do browser SHALL NOT ir ao Laravel | OpenAPI `VerifyEmailRequest`; `additionalProperties: false` | y |
| Corpo BFF verify sucesso | Traduz upstream `204` → BFF `200` com `{ "data": { "redirect_to": "/login", "message": "E-mail confirmado. Faça login para continuar." } }` | UI precisa de destino e copy; upstream não retorna body | y |
| Pós-verify sessão BFF | `destroySession` + `clearSessionCookie` no response de sucesso | API revoga Bearer `verification`; cookie BFF obsoleto | y |
| Pós-verify CSRF | **Não** emitir novo CSRF de sessão após destroy | Sessão encerrada; próximo login emite novo par | y |
| Resend corpo | Sem body (ou `{}` ignorado); upstream não exige body | OpenAPI resend sem requestBody | y |
| Resend sucesso | Repassar `202` + envelope `Accepted` da API; **manter** sessão BFF e cookies | Usuário ainda `pending_verification` até verify | y |
| Token na URL do e-mail | Query `?token=` hidrata campo do form **somente**; submit é POST explícito | Security §4.3; AUTH-22; `docs/testing.md` §6.1 | y |
| Strip query após hidratar | Client remove `?token=` via `history.replaceState` após montar form (best-effort) | Reduz vazamento por referrer/histórico; token permanece no state do form | y |
| Trim do token | **Sem** trim no client nem BFF — token opaco validado estritamente | Paridade API Auth (`auth/email-verification` spec) | y |
| Mensagens de erro UI | pt-BR; preservar `code` da API quando presente; 5xx/gateway genérico | Product UI pt-BR | y |
| `INVALID_VERIFICATION_TOKEN` UI | Mensagem pt-BR: "Link de verificação inválido ou expirado." | Mensagem uniforme; não distinguir expirado/usado/inexistente | y |
| `EMAIL_ALREADY_VERIFIED` UI | Mensagem pt-BR + navegação para `/login` | Conta já ativa; novo login para obter `session` | y |
| Rate limit UI | Confiar no `429` upstream + exibir `Retry-After` quando header presente | Paridade login/register | y |
| Pass-through 4xx | Repassar status + body JSON (`code`, `message`, `errors` quando 422) com headers privados | UI mapeia códigos | y |
| Upstream timeout/abort | Responder `504` com mensagem genérica pt-BR | Helper existente; orçamento 10s | y |
| Upstream 500/503 | Mensagem genérica pt-BR espelhando status | Sem vazar detalhe Laravel | y |
| Guards sessão restrita (mínimo) | Helper `resolveVerificationSessionGuard` + aplicar em `/` e `/verify-email`; exportar allowlist de paths para `session-shell` | BFFUI-52; shell completo na fatia 8 | y |
| Paths permitidos com `verification` | `/verify-email`, `/login`, `/terms` | Jornada restrita: verificar, reler termos, ir ao login pós-sucesso | y |
| Visitante sem sessão em `/verify-email` | Redirect `/login` | Token de e-mail sozinho não autentica BFF | y |
| Sessão `session` visita `/verify-email` | Redirect `/` | Usuário já verificado | y |
| Link "Ir para login" na UI | `/login` sempre visível na página de verificação | Escape se usuário já verificou em outro dispositivo | y |
| Bootstrap CSRF na página | **Não** usar pré-auth CSRF — página exige sessão `verification`; CSRF derivado da sessão via `issueCsrfForSession` no register/login anterior | `requireSession: true` usa modo session no guard | y |
| Reenvio cooldown UX | Botão resend desabilitado durante pending; após `202` exibir confirmação genérica pt-BR | Evita spam acidental; API já limita 3/h | y |
| Confirmação reenvio copy | "Se o e-mail estiver cadastrado e pendente, você receberá um novo link." (ou equivalente alinhado ao produto) | Não revelar estado além do necessário; reenvio exige sessão anyway | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Zod client: `token` string `minLength: 1`; BFF valida JSON parseável e presença de `token`; rejeitar body malformado localmente (`400`); sem trim destrutivo |
| Failure / partial-failure states | Origin/CSRF/sessão → `403` genérico; bearer expirado → `401`; token inválido → `403 INVALID_VERIFICATION_TOKEN`; já verificado → `403 EMAIL_ALREADY_VERIFIED`; validação → `422`; rate limit → `429`; upstream fail → `504`/`500` genérico; **nunca** destroy cookie se upstream verify falhou |
| Idempotency / retry / duplicate handling | Segundo verify com mesmo token → `403 INVALID_VERIFICATION_TOKEN` (uso único); reenvio gera novo token upstream invalidando anteriores; submit duplo UI desabilitado durante pending |
| Auth boundaries & rate limits | Handlers exigem sessão `verification` + CSRF session-mode; rate limit só upstream (3/h resend, 5/h verify por conta) |
| Concurrency / ordering | Verify concorrente: API garante um `204`; demais `403`; BFF destroy cookie apenas no response de sucesso |
| Data lifecycle / expiry | Após verify sucesso: Redis session removida + cookie expirado; token e-mail 60 min (API); sessão BFF verification TTL 24h abs / 1h idle |
| Observability | Proibido logar token de e-mail plaintext, Bearer, session ID bruto, query `?token=` em access logs de app |
| External-dependency failure | Laravel timeout → `504` sem destroy de sessão; Redis fail em `destroySession` pós-verify → ainda clear cookie + `200` success (best-effort destroy) |
| State-transition integrity | BFF **não** altera `User.status` localmente; só reflete upstream; pós-verify UI exige novo login para `session` kind |

---

## Entregáveis técnicos (mínimo)

```txt
frontend/
  app/
    verify-email/
      page.tsx                    # Server-first; guards; hidrata ?token=; redirect rules
      page.test.tsx               # RTL: render, guards, GET não chama verify/resend
    page.tsx                      # + guard: verification session → /verify-email
    api/bff/auth/email/
      verify/
        route.ts                  # POST handler produto
        route.test.ts             # Vitest: matriz upstream + destroy session + Bearer absent
      resend/
        route.ts                  # POST handler produto
        route.test.ts             # Vitest: 202/429/403 + sessão preservada
  modules/auth/
    schemas/
      verify-email-schema.ts      # Zod VerifyEmailRequest mirror
    components/
      verify-email-form.tsx       # Client: token field + confirmar + reenviar
      verify-email-success.tsx    # (opcional) estado pós-verify antes de redirect
    bff/
      allowlist.ts                # + entradas verify e resend
    lib/
      verification-guard.ts       # Paths permitidos + resolveVerificationSessionGuard
    services/
      bff-verify-email.ts         # Orquestração verify handler ↔ session ↔ upstream
      bff-resend-verification.ts  # Orquestração resend handler ↔ upstream
```

Handlers verify **não** podem repassar `204` cru sem encerrar sessão BFF — devem `destroySession`, `clearSessionCookie` e responder corpo sanitizado com `redirect_to`.

---

## User Stories

### P1: Verificar e-mail via BFF ⭐ MVP

**User Story**: Como usuário com sessão restrita, quero confirmar meu e-mail enviando o token recebido para ativar minha conta sem nunca ver o Bearer.

**Why P1**: Núcleo da fatia; BFFUI-50; desbloqueia jornada cadastro → verify → login.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/email/verify` recebe `{ "token": "<plaintext>" }` válido com guards Origin+CSRF satisfeitos, sessão BFF `kind: 'verification'` válida e upstream retorna `204` THEN o handler SHALL chamar Laravel via entrada allowlisted com `Authorization: Bearer <cifrado>`, invocar `destroySession(sessionId)` best-effort, emitir `clearSessionCookie`, e responder `200` com `{ "data": { "redirect_to": "/login", "message": "E-mail confirmado. Faça login para continuar." } }`.
2. WHEN verify bem-sucedido THEN headers SHALL incluir `Cache-Control: private, no-store` e cookie `__Host-fl_session` SHALL estar expirado/removido.
3. WHEN verify bem-sucedido THEN nenhum registro Redis da sessão anterior SHALL permanecer resolvível pelo cookie emitido na resposta.
4. WHEN a resposta BFF de sucesso é inspecionada THEN o JSON SHALL NOT conter substrings `token` (como campo de auth), `Bearer`, `token_kind`, `token_type`, `expires_at` nem o plaintext do token de e-mail submetido.
5. WHEN upstream retorna qualquer status ≠ `204` THEN o handler SHALL NOT chamar `destroySession` nem `clearSessionCookie` (sessão BFF permanece para retry).
6. WHEN `session.kind !== 'verification'` THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel.

**Independent Test**: Vitest handler com fetch mock upstream `204`; assert `destroySession` + Set-Cookie Max-Age=0; body com `redirect_to`; upstream `403` assert sessão intacta.

**Requirement IDs**: BFFUI-50, EV-01, EV-02, EV-03, EV-04

---

### P1: Reenviar e-mail de verificação via BFF ⭐ MVP

**User Story**: Como usuário com sessão restrita, quero solicitar novo e-mail de verificação se não recebi o anterior.

**Why P1**: BFFUI-50; paridade AUTH-23.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/email/resend` com sessão BFF `verification` válida e guards satisfeitos e upstream retorna `202` THEN o handler SHALL repassar status `202` e envelope `Accepted` ao browser **sem** alterar sessão BFF (cookie permanece válido).
2. WHEN resend bem-sucedido THEN o handler SHALL NOT chamar `destroySession`.
3. WHEN upstream retorna `429 RATE_LIMIT_EXCEEDED` THEN o BFF SHALL repassar status `429`, corpo e header `Retry-After` quando presente **sem** destruir sessão.
4. WHEN `session.kind !== 'verification'` THEN o handler SHALL responder `403` sem chamar Laravel.
5. WHEN resend THEN headers SHALL incluir `Cache-Control: private, no-store`.

**Independent Test**: Vitest upstream `202`/`429`; assert zero destroySession; cookie inalterado.

**Requirement IDs**: BFFUI-50, EV-05, EV-06, EV-07

---

### P1: Erros de verificação e estado da conta ⭐ MVP

**User Story**: Como usuário, quero feedback claro quando o token é inválido, expirado, já usado ou minha conta já está verificada.

**Why P1**: BFFUI-32; paridade API Auth.

**Acceptance Criteria**:

1. WHEN upstream retorna `403` com `code=INVALID_VERIFICATION_TOKEN` THEN o BFF SHALL repassar status e corpo ao browser sem destruir sessão BFF.
2. WHEN upstream retorna `403` com `code=EMAIL_ALREADY_VERIFIED` THEN o BFF SHALL repassar status e corpo; UI SHALL exibir mensagem pt-BR e navegar para `/login`.
3. WHEN upstream retorna `401 UNAUTHENTICATED` (Bearer expirado/revogado) THEN o BFF SHALL repassar ao browser; UI SHALL tratar como sessão expirada e orientar novo login/cadastro.
4. WHEN upstream retorna `403` com `code=ACCOUNT_SUSPENDED` ou `code=ACCOUNT_PENDING_DELETION` THEN o BFF SHALL repassar; UI SHALL exibir mensagem pt-BR específica do `code`.
5. WHEN upstream retorna `422 VALIDATION_FAILED` (token ausente no body) THEN o BFF SHALL repassar `errors` sem chamar destroy de sessão.
6. WHEN upstream retorna `500`/`503`/`504` THEN o BFF SHALL responder mensagem genérica pt-BR espelhando status (ou `504` gateway) **sem** destroy de sessão.

**Independent Test**: Vitest matriz 403/401/422/5xx; RTL mapeia mensagens pt-BR por `code`.

**Requirement IDs**: BFFUI-32, EV-08, EV-09, EV-10

---

### P1: Guards BFF (Origin / CSRF / sessão) ⭐ MVP

**User Story**: Como mantenedor, quero que verify/resend rejeitem mutations cross-site, sem CSRF ou sem sessão válida antes de chamar Laravel.

**Why P1**: Integração `csrf-proxy`; `docs/testing.md` §6.2.

**Acceptance Criteria**:

1. WHEN `POST` verify/resend chega sem `Origin` válido, CSRF ausente/inválido, cookie de sessão ausente ou CSRF/header divergentes THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel.
2. WHEN guards passam com `requireSession: true` THEN SHALL usar modo CSRF `session` vinculado ao `sessionId` carregado.
3. WHEN guards falham THEN a resposta SHALL incluir `Cache-Control: private, no-store`.

**Independent Test**: Vitest Request sintéticos; assert fetch upstream não chamado.

**Requirement IDs**: EV-11

---

### P1: UI de verificação server-first ⭐ MVP

**User Story**: Como usuário pendente, quero uma página em pt-BR para confirmar meu e-mail e reenviar o link, acessível a partir de 360px.

**Why P1**: BFFUI-51; `docs/product.md` §8.

**Acceptance Criteria**:

1. WHEN o usuário navega para `GET /verify-email` sem sessão BFF válida THEN a página SHALL `redirect('/login')`.
2. WHEN o usuário possui sessão `session` e visita `/verify-email` THEN SHALL `redirect('/')`.
3. WHEN o usuário possui sessão `verification` THEN SHALL renderizar formulário com campo de token (preenchido se `?token=` presente), botão primário "Confirmar e-mail" e ação secundária "Reenviar e-mail", usando primitivos `shared`.
4. WHEN `?token=` está presente na URL THEN o valor SHALL hidratar o campo do formulário **sem** disparar verify/resend automaticamente no load.
5. WHEN o formulário monta com `?token=` THEN o client SHOULD remover o query param da barra de endereço via `history.replaceState` mantendo o valor no state do form.
6. WHEN o usuário submete token válido THEN o client SHALL `POST /api/bff/auth/email/verify` com `Content-Type: application/json`, header `X-CSRF-Token` e body `{ "token": "..." }`.
7. WHEN verify BFF responde `200` com `redirect_to` THEN a UI SHALL exibir mensagem de sucesso e navegar para `/login`.
8. WHEN o usuário aciona reenviar THEN o client SHALL `POST /api/bff/auth/email/resend` com CSRF e exibir confirmação pt-BR em `202`.
9. WHEN inspeção de HTML, props serializadas e respostas fetch THEN Bearer e token upstream de auth SHALL NOT aparecer.
10. WHEN a página renderiza THEN SHALL exibir link "Ir para login" para `/login`.

**Independent Test**: RTL + MSW; mock `getSessionFromRequest`; labels acessíveis; submit/resend flows.

**Requirement IDs**: BFFUI-51, EV-12, EV-13, EV-14

---

### P1: Proteção contra scanner / prefetch ⭐ MVP

**User Story**: Como plataforma, quero que abrir o link do e-mail (GET) nunca consuma o token de verificação.

**Why P1**: `docs/security.md` §4.3; `docs/testing.md` §6.1; AUTH-22.

**Acceptance Criteria**:

1. WHEN `GET /verify-email` (com ou sem `?token=`) THEN o servidor SHALL NOT invocar `POST /api/bff/auth/email/verify` nem `POST /api/bff/auth/email/resend` durante o render.
2. WHEN testes RTL montam a página com `?token=` THEN SHALL assert zero chamadas fetch para endpoints verify/resend até interação explícita do usuário.
3. WHEN prefetch de link (simulado por mount sem user gesture) THEN nenhum side-effect de verificação SHALL ocorrer.

**Independent Test**: Vitest/RTL spy em `fetch` durante render de `VerifyEmailPage`; contagem zero pré-click.

**Requirement IDs**: BFFUI-51, EV-15

---

### P1: UX de sessão restrita ⭐ MVP

**User Story**: Como usuário não verificado, devo permanecer no fluxo permitido até concluir verificação e novo login.

**Why P1**: BFFUI-52; `docs/product.md` §9.

**Acceptance Criteria**:

1. WHEN usuário com sessão `verification` navega para `/` THEN SHALL `redirect('/verify-email')`.
2. WHEN usuário com sessão `verification` navega para path **fora** da allowlist `{ /verify-email, /login, /terms }` em rotas App Router que aplicam o guard mínimo desta fatia THEN SHALL `redirect('/verify-email')`.
3. WHEN helper `resolveVerificationSessionGuard` é exportado THEN SHALL documentar contrato para expansão em `session-shell` (lista de paths + comportamento).
4. WHEN verify bem-sucedido e usuário chega a `/login` THEN novo login com credenciais SHALL emitir sessão `session` (fluxo coberto por teste de integração MSW login após verify mock).

**Independent Test**: Vitest `verification-guard.ts`; teste de página `/` com sessão verification mock → redirect.

**Requirement IDs**: BFFUI-52, EV-16, EV-17

---

### P1: Validação de entrada ⭐ MVP

**User Story**: Como usuário, quero feedback imediato se o token estiver vazio; como BFF, quero rejeitar payloads inválidos sem side effects.

**Why P1**: Contrato OpenAPI; UX foundation RHF+Zod.

**Acceptance Criteria**:

1. WHEN o formulário UI tem `token` vazio THEN o client SHALL bloquear submit com erro de campo pt-BR **sem** chamar o BFF.
2. WHEN o BFF verify recebe JSON sem campo `token` ou `token` vazio THEN SHALL responder `400` com mensagem genérica pt-BR **sem** chamar Laravel **ou** repassar `422` upstream se encaminhar body inválido — **preferência: validação local `400` antes do upstream**.
3. WHEN o BFF recebe body JSON malformado ou `Content-Type` inválido THEN SHALL responder `400` genérico sem chamar Laravel.
4. WHEN token contém apenas whitespace THEN o client SHALL tratá-lo como inválido (sem trim silencioso que mascare erro).

**Independent Test**: RTL token vazio; Vitest malformed body → 400 sem fetch.

**Requirement IDs**: EV-18

---

### P1: Privacidade do token de e-mail ⭐ MVP

**User Story**: Como plataforma, quero que tokens de verificação não vazem em telemetria, logs ou respostas sanitizadas.

**Why P1**: AUTH-25; `docs/security.md` §4.3, §13.

**Acceptance Criteria**:

1. WHEN handlers ou serviços BFF processam verify THEN o plaintext do token de e-mail SHALL NOT aparecer em `console.log`, mensagens de erro serializadas ao browser (exceto eco acidental — **proibido**), ou fixtures de teste expostas ao bundle client.
2. WHEN testes usam token sentinela THEN asserts SHALL varrer JSON de resposta de sucesso/erro e HTML renderizado.
3. WHEN verify bem-sucedido THEN o corpo BFF SHALL NOT incluir o token submetido nem dados de usuário além de `redirect_to` e `message`.

**Independent Test**: Vitest sentinel token ausente de `JSON.stringify(responseBody)`; RTL HTML scan.

**Requirement IDs**: EV-19

---

### P2: Allowlist, descoberta de testes e validação client ⭐ Should have

**User Story**: Como mantenedor, quero entradas na allowlist, schema Zod compartilhado e testes descobertos pelo suite padrão.

**Why P2**: Auditabilidade e gates CI.

**Acceptance Criteria**:

1. WHEN `AUTH_BFF_ALLOWLIST` é inspecionada THEN SHALL conter entradas verify e resend além de login/register.
2. WHEN `make test-frontend` roda THEN testes em `app/api/bff/auth/email/`, `app/verify-email/` e `schemas/verify-email-schema` SHALL ser descobertos.
3. WHEN `verify-email-schema` é testado THEN SHALL espelhar `VerifyEmailRequest` OpenAPI (`token` required, `minLength: 1`).

**Independent Test**: Inspeção estática allowlist; `make test-frontend` discovery; unit schema.

**Requirement IDs**: EV-20, EV-21, EV-22

---

## Edge Cases

- WHEN usuário abre link do e-mail em dispositivo sem cookie BFF (sessão expirou) THEN `/verify-email` redireciona `/login`; token na query é perdido — usuário deve fazer login pendente ou pedir reenvio após reautenticar.
- WHEN `?token=` inválido na query mas sessão válida THEN form exibe valor; submit retorna `403 INVALID_VERIFICATION_TOKEN` sem destroy de sessão; usuário pode reenviar.
- WHEN reenvio invalida token anterior (API) e usuário submete token antigo THEN `403 INVALID_VERIFICATION_TOKEN`.
- WHEN verify concorrente (dois tabs) THEN no máximo um sucesso `200`; outro tab pode receber `403` e sessão já destruída no primeiro — segundo tab trata como sessão expirada.
- WHEN upstream verify `204` mas `destroySession` falha (Redis down) THEN BFF ainda clear cookie e retorna sucesso; sessão órfã expira por TTL.
- WHEN `429` sem header `Retry-After` THEN UI exibe mensagem genérica de limite.
- WHEN conta `suspended` com bearer ainda válido tenta resend THEN `403 ACCOUNT_SUSPENDED`.
- WHEN token na query é URL-encoded THEN página decodifica uma vez antes de hidratar form.
- WHEN usuário cola token com newline trailing THEN validação client rejeita ou API retorna `403` — **sem** trim automático no BFF.
- Bearer upstream nunca aparece em traces de teste serializados ou MSW handlers expostos ao browser bundle.

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| BFFUI-50 | P1: Verify + resend BFF | Tasks | ✅ Verified |
| BFFUI-51 | P1: UI + scanner-safe | Tasks | ✅ Verified |
| BFFUI-52 | P1: UX sessão restrita | Tasks | ✅ Verified |
| BFFUI-32 | P1: Erros / rate limit | Tasks | ✅ Verified |
| EV-01 | P1: Verify — happy path handler | Tasks | ✅ Verified |
| EV-02 | P1: Verify — destroy session/cookie | Tasks | ✅ Verified |
| EV-03 | P1: Verify — Bearer absent | Tasks | ✅ Verified |
| EV-04 | P1: Verify — kind guard | Tasks | ✅ Verified |
| EV-05 | P1: Resend — 202 pass-through | Tasks | ✅ Verified |
| EV-06 | P1: Resend — 429 + Retry-After | Tasks | ✅ Verified |
| EV-07 | P1: Resend — sessão preservada | Tasks | ✅ Verified |
| EV-08 | P1: Erros — INVALID_VERIFICATION_TOKEN | Tasks | ✅ Verified |
| EV-09 | P1: Erros — EMAIL_ALREADY_VERIFIED | Tasks | ✅ Verified |
| EV-10 | P1: Erros — 401/422/5xx | Tasks | ✅ Verified |
| EV-11 | P1: Guards Origin/CSRF/sessão | Tasks | ✅ Verified |
| EV-12 | P1: UI — render e redirects | Tasks | ✅ Verified |
| EV-13 | P1: UI — submit verify | Tasks | ✅ Verified |
| EV-14 | P1: UI — resend | Tasks | ✅ Verified |
| EV-15 | P1: Scanner-safe GET | Tasks | ✅ Verified |
| EV-16 | P1: Guard `/` → verify-email | Tasks | ✅ Verified |
| EV-17 | P1: Guard allowlist paths | Tasks | ✅ Verified |
| EV-18 | P1: Validação entrada | Tasks | ✅ Verified |
| EV-19 | P1: Privacidade token | Tasks | ✅ Verified |
| EV-20 | P2: Allowlist entries | Tasks | ✅ Verified |
| EV-21 | P2: Test discovery | Tasks | ✅ Verified |
| EV-22 | P2: Zod schema | Tasks | ✅ Verified |

**Coverage:** 26 total, 26 mapped ✅

---

## Success Criteria

- [x] Usuário com sessão `verification` completa verify em `/verify-email`, vê confirmação pt-BR e chega a `/login` **sem** Bearer no browser e **sem** cookie de sessão BFF ativo.
- [x] Abrir `GET /verify-email?token=…` não chama verify/resend até clique explícito.
- [x] Reenvio respeita `202`/`429` com feedback pt-BR; sessão BFF permanece até verify bem-sucedido.
- [x] Token inválido/expirado exibe mensagem uniforme; conta já verificada orienta login.
- [x] Usuário `verification` em `/` é redirecionado para `/verify-email`.
- [x] Guards CSRF/Origin bloqueiam sem chamar Laravel.
- [x] `make test-frontend` passa com cobertura ≥75% nos arquivos novos da fatia.
- [x] Nenhum teste Vitest/RTL falha se `JSON.stringify(resposta)` ou HTML contiver Bearer de fixture ou token sentinela de teste.

---

## Referências

| Documento | Uso |
| --- | --- |
| `.specs/features/auth/email-verification/spec.md` | Contrato upstream verificado (AUTH-12, AUTH-20…25) |
| `.specs/features/bff-auth/register/spec.md` | Sessão `verification` pós-cadastro; redirect `/verify-email` |
| `.specs/features/bff-auth/login/spec.md` | Redirect pending → `/verify-email`; padrão handler/UI |
| `.specs/features/bff-auth/csrf-proxy/spec.md` | Guards, allowlist, `requireSession` |
| `.specs/features/bff-auth/session-core/spec.md` | TTL verification, destroy/clear cookie |
| `.specs/features/bff-auth/session-shell/spec.md` | Guards completos (fatia seguinte consome contrato) |
| `docs/openapi.yaml` | `verifyEmail`, `resendEmailVerification`, `VerifyEmailRequest` |
| `docs/product.md` §3, §8, §9 | Jornada, UI pt-BR, conta restrita |
| `docs/security.md` §4.3, §5 | POST explícito, sessão BFF, CSRF |
| `docs/testing.md` §3.2, §6.1, §6.2 | Vitest/RTL/MSW BFF; scanner GET |
| `docs/api.md` §3.1–3.2 | Endpoints e rate limits upstream |
