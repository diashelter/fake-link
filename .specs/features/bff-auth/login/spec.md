# BFF Auth — Login

**Status:** Approved — 2026-08-11  
**Fatia:** 4 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-30 … BFFUI-32  
**Requirement IDs (fatia):** LOG-01 … LOG-14  
**Depende de:** [csrf-proxy](../csrf-proxy/spec.md) (Verified), [session-core](../session-core/spec.md) (Verified)  
**Upstream API:** `POST /api/v1/auth/login` (Auth API Verified — `.specs/features/auth/login/spec.md`)

## Problem Statement

Usuários com conta precisam autenticar no browser oficial (`https://app.localhost`) sem jamais receber o Bearer emitido pelo Laravel. A infraestrutura BFF (sessão cifrada, CSRF, allowlist, proxy upstream) já existe, mas ainda não há Route Handler de produto nem UI de login — o fluxo de autenticação browser-side está bloqueado.

Esta fatia entrega o primeiro fluxo Auth de produto end-to-end no frontend: `POST /api/bff/auth/login` chama a API Laravel, persiste o Bearer somente no Redis cifrado, emite cookie de sessão opaco, e a página `/login` (server-first, pt-BR) permite entrar com e-mail e senha, respeitando anti-enumeração, status de conta e `returnUrl` seguro.

## Goals

- [ ] Route Handler BFF `POST /api/bff/auth/login` registrado na `AUTH_BFF_ALLOWLIST`, com guards de Origin/CSRF pré-auth, chamada upstream allowlisted e emissão de sessão BFF **sem** Bearer na resposta ao browser.
- [ ] Página UI `/login` server-first com formulário RHF+Zod; suporte a `?returnUrl=` sanitizado; redirecionamento pós-sucesso conforme `User.status` e destino interno.
- [ ] Matriz de erros alinhada à API Laravel (401/403/422/429) com mensagens pt-BR; upstream 5xx/504 genéricos; guards BFF 403 genérico.
- [ ] Vitest cobre Route Handler (happy path, erros, strip de Bearer), RTL do formulário e ausência de Bearer em JSON/HTML/storage simulado.
- [ ] Cobertura ≥75% linhas/branches nos arquivos introduzidos nesta fatia (`docs/testing.md` §4).

## Out of Scope

| Item | Motivo |
| --- | --- |
| Register / verify / password (handlers + UI) | Fatias 5–7 |
| Logout / me / perfil / guards de rota autenticada | Fatia `session-shell` |
| Playwright E2E security gate completo | Fatia `e2e-security-gate` |
| Rate limiting BFF adicional | API Laravel já limita upstream; duplicar chaves Redis no BFF fica fora desta fatia |
| MFA, lockout persistente | Pós-MVP (`docs/security.md` §4.2) |
| Dashboard de Links / shell autenticado completo | Fase 2; placeholder `/` até Links existir |
| Implementação de forgot/reset password | Fatia `password`; link na UI de login é permitido como navegação apenas |
| Revogação de tokens Laravel pré-existentes no login | Paridade com API — multi-sessão; revogação em logout |
| OpenAPI do BFF como contrato público separado | BFF é boundary interno browser↔Next; upstream permanece design-first |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path BFF | `POST /api/bff/auth/login` → upstream `POST /auth/login` | AD-017; prefixo `/api/bff/...`; path relativo a `LARAVEL_INTERNAL_URL` | y |
| Path UI | `/login` | `docs/product.md` §8 | y |
| Allowlist entry | `{ method: 'POST', bffPath: '/api/bff/auth/login', upstreamMethod: 'POST', upstreamPath: '/auth/login', requireSession: false, requireCsrf: true }` | Login é mutation pública pré-auth (`csrf-proxy` spec) | y |
| Pós-login `active` | Redirect para `sanitizeReturnUrl(returnUrl, '/')` — default `/` | Dashboard Links inexistente (Fase 2); `csrf-proxy` default `/` | y |
| Pós-login `pending_verification` | Redirect fixo `/verify-email` | Fatia `email-verification` seed; ignora `returnUrl` de shell completo | y |
| `returnUrl` em conta restrita | Sempre `/verify-email` independente de query | Usuário `pending_verification` não deve ser redirecionado para rotas de shell | y |
| Mensagens de erro UI | pt-BR; preservar `code` da API quando presente; mapear mensagens genéricas para 5xx/gateway | Product UI pt-BR; anti-enum depende de paridade com API | y |
| Rate limit UI | Confiar no `429` da API + exibir `Retry-After` quando header presente | Evitar duplicar Redis keys no BFF nesta fase | y |
| Corpo BFF de sucesso | `{ "data": { "user": User, "redirect_to": string } }` — **sem** campos `token`, `token_type`, `token_kind`, `expires_at` | Bearer fica só no Redis cifrado; browser recebe metadados mínimos | y |
| Sessão pré-existente no login | Se cookie `__Host-fl_session` válido existir, `destroySession` best-effort **antes** de `createSession` | BFFUI-15 — rotação/fixation no login; novo ID opaco sempre | y |
| CSRF pós-login | Após `createSession`, chamar `issueCsrfForSession(newSessionId)` no response; cookies pré-auth podem permanecer até expirar | Token CSRF passa a derivar da sessão autenticada | y |
| Bootstrap CSRF na página login | GET `/login` SHALL garantir cookies pré-auth (`issuePreAuthCsrf`) antes do submit — implementação (RSC `cookies().set`, layout ou handler auxiliar) fica no Design | Pré-requisito para double-submit sem sessão | y |
| Validação Zod client | Espelha `LoginRequest` OpenAPI: `email` (format, max 254), `password` (required, max 128); **sem** revalidar composição de senha | Paridade com API Auth login (`auth/login` spec) | y |
| Payload BFF → Laravel | Repassar JSON `{ email, password }` somente; campos extras do browser (ex.: `returnUrl`) SHALL NOT ser encaminhados upstream | Upstream contrato estrito `additionalProperties: false` | y |
| Pass-through de erros 4xx | Repassar status + body JSON da API (`code`, `message`, `errors` quando 422) com headers privados | UI mapeia códigos; anti-enum depende de paridade | y |
| Upstream timeout/abort | Responder `504` com `{ "message": "Bad gateway." }` (ou equivalente pt-BR no Design) | Helper `callAllowlistedUpstream` existente | y |
| Upstream 500/503 | Responder `{ "message": "Something went wrong. Please try again." }` (pt-BR) com status espelhado | Sem vazar detalhe interno Laravel | y |
| Kind da sessão BFF | Mapear `data.token_kind` upstream → `kind` Redis: `session`→`session`, `verification`→`verification` | Alinhado a `session-core` e API | y |
| `userId` na sessão | `data.user.id` (UUID v7 string) | Schema `User` OpenAPI | y |
| Link "Esqueci minha senha" | `/forgot-password` (rota placeholder ou 404 até fatia `password`) | Navegação permitida; handler não é escopo desta fatia | y |
| Link "Criar conta" | `/register` (rota placeholder até fatia `register`) | Navegação permitida | y |
| Usuário já autenticado visita `/login` | Redirect para `/` se sessão `session`; para `/verify-email` se sessão `verification` | Evita re-login desnecessário; guards finos em `session-shell` | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Zod client espelha `LoginRequest`; BFF valida JSON parseável antes de upstream; `returnUrl` max 2048 via `sanitizeReturnUrl`; rejeitar body > limite Next default |
| Failure / partial-failure states | Origin/CSRF → 403 genérico sem Laravel; credencial → 401; status bloqueado → 403; validação → 422; rate limit → 429; upstream fail → 504/502/500 genérico; **nunca** cookie de sessão parcial se upstream falhou ou Bearer ausente |
| Idempotency / retry / duplicate handling | Re-login válido cria **nova** sessão BFF + novo Bearer upstream; submit duplo UI desabilitado durante pending (RHF/foundation defaults) |
| Auth boundaries & rate limits | Endpoint BFF público com CSRF+Origin; rate limit só upstream; guards BFF não distinguem motivo de falha CSRF/Origin |
| Concurrency / ordering | Dois logins concorrentes → duas sessões BFF distintas; destroy-before-create no handler serializa por request |
| Data lifecycle / expiry | Cookie/session TTL conforme `kind` emitido (`session`: 7d abs / 24h idle; `verification`: 24h / 1h) |
| Observability | Proibido logar e-mail, senha, Bearer, session ID bruto ou token CSRF completo |
| External-dependency failure | Laravel timeout → 504 sem sessão; Redis fail em `createSession` → 500 genérico sem Bearer vazado; destroy pré-existente best-effort |
| State-transition integrity | Login BFF **não** altera `User.status`; só lê upstream para decidir `kind` e redirect |

---

## Entregáveis técnicos (mínimo)

```txt
frontend/
  app/
    login/
      page.tsx                    # Server-first; bootstrap CSRF; redirect se já autenticado
      page.test.tsx               # RTL: render, validação, submit MSW
    api/bff/auth/login/
      route.ts                    # POST handler produto
      route.test.ts               # Vitest: matriz status upstream + Bearer absent
  modules/auth/
    schemas/
      login-schema.ts             # Zod LoginRequest mirror
    components/
      login-form.tsx              # Client: RHF form + fetch BFF
    bff/
      allowlist.ts                # + entrada login (única alteração allowlist prod)
    services/
      bff-login.ts                # (opcional) orquestração testável handler ↔ session
```

Handler **não** pode usar pass-through cru de `callAllowlistedUpstream` em respostas `200` — deve parsear `AuthResponse`, extrair Bearer server-side, criar sessão e responder corpo sanitizado.

---

## User Stories

### P1: Login bem-sucedido via BFF ⭐ MVP

**User Story**: Como usuário registrado, quero enviar e-mail e senha pelo browser oficial para obter sessão BFF (cookie opaco) sem nunca ver o Bearer.

**Why P1**: Desbloqueia todo fluxo Auth browser-side; BFFUI-30.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/login` recebe `{ email, password }` válidos com guards Origin+CSRF satisfeitos e upstream retorna `200 AuthResponse` para usuário `active` THEN o handler SHALL chamar Laravel via entrada allowlisted, extrair `data.token` **somente server-side**, invocar `createSession({ bearer, kind: 'session', userId })`, emitir `Set-Cookie` `__Host-fl_session`, invocar `issueCsrfForSession`, e responder `200` com `{ data: { user, redirect_to } }` onde `redirect_to` = `sanitizeReturnUrl(returnUrlQuery, '/')`.
2. WHEN upstream retorna `200` para usuário `pending_verification` THEN o handler SHALL criar sessão BFF com `kind: 'verification'` e responder com `redirect_to: '/verify-email'` **independente** de `returnUrl` na query.
3. WHEN a resposta BFF de sucesso é inspecionada THEN o JSON SHALL NOT conter substrings `token`, `Bearer`, `token_kind`, `token_type`, `expires_at` nem valor igual ao Bearer upstream.
4. WHEN login bem-sucedido THEN headers SHALL incluir `Cache-Control: private, no-store`.
5. WHEN login bem-sucedido e cookie de sessão pré-existente válido THEN o handler SHALL `destroySession` da sessão anterior (best-effort) antes de `createSession`.
6. WHEN login bem-sucedido THEN o registro Redis SHALL conter Bearer cifrado e SHALL NOT conter Bearer plaintext.

**Independent Test**: Vitest handler com fetch mock upstream `200` (active + pending); assert `createSession` spy, Set-Cookie, body sem token; MSW + RTL submit happy path.

**Requirement IDs**: BFFUI-30, BFFUI-15, LOG-01, LOG-02, LOG-03

---

### P1: Credenciais inválidas e anti-enumeração ⭐ MVP

**User Story**: Como plataforma, quero que falhas de credencial no BFF espelhem a API sem vazar existência de conta ou Bearer.

**Why P1**: BFFUI-32; `docs/security.md` §4.1, `docs/testing.md` §6.1.

**Acceptance Criteria**:

1. WHEN upstream retorna `401` com `code=INVALID_CREDENTIALS` THEN o BFF SHALL repassar status `401` e corpo equivalente (`code`, `message`) ao browser **sem** emitir cookie de sessão.
2. WHEN upstream retorna `401` THEN a resposta BFF SHALL NOT conter campos de usuário, token ou indício de motivo específico além do contrato API.
3. WHEN credencial inválida THEN nenhuma entrada nova SHALL ser criada no Redis de sessão BFF.
4. WHEN a UI recebe `401 INVALID_CREDENTIALS` THEN SHALL exibir mensagem pt-BR genérica equivalente à API (ex.: credenciais inválidas) **sem** diferenciar e-mail inexistente vs senha errada.

**Independent Test**: Vitest handler upstream `401`; assert zero Set-Cookie session; RTL exibe mesma mensagem para dois cenários MSW.

**Requirement IDs**: BFFUI-32, LOG-04, LOG-05

---

### P1: Bloqueio por status da conta ⭐ MVP

**User Story**: Como plataforma, quero bloquear login de contas suspensas ou em exclusão quando a credencial é reconhecida, espelhando a API.

**Why P1**: BFFUI-32; paridade AUTH-11.

**Acceptance Criteria**:

1. WHEN upstream retorna `403` com `code=ACCOUNT_SUSPENDED` ou `code=ACCOUNT_PENDING_DELETION` THEN o BFF SHALL repassar status e corpo ao browser sem criar sessão BFF.
2. WHEN bloqueio por status THEN a resposta SHALL NOT usar envelope `401 INVALID_CREDENTIALS`.
3. WHEN a UI recebe `403 ACCOUNT_SUSPENDED` ou `403 ACCOUNT_PENDING_DELETION` THEN SHALL exibir mensagem pt-BR específica mapeada do `code` (conta suspensa / exclusão pendente).
4. WHEN upstream retorna `403` por status bloqueado THEN nenhum cookie `__Host-fl_session` SHALL ser emitido.

**Independent Test**: Vitest upstream `403` variants; RTL mapeia mensagens distintas de `401`.

**Requirement IDs**: BFFUI-32, LOG-06

---

### P1: Validação de entrada ⭐ MVP

**User Story**: Como usuário, quero feedback imediato de formulário inválido; como API gateway, quero rejeitar payloads inválidos sem side effects.

**Why P1**: Contrato OpenAPI; UX foundation RHF+Zod.

**Acceptance Criteria**:

1. WHEN o formulário UI tem `email` inválido ou ausente THEN o client SHALL bloquear submit e exibir erro de campo **sem** chamar o BFF.
2. WHEN `password` excede 128 caracteres ou está ausente THEN o client SHALL bloquear submit com erro de campo.
3. WHEN o BFF recebe JSON com campos além de `email`/`password` no body upstream THEN SHALL encaminhar somente `{ email, password }` — campos extras do request browser (ex.: `returnUrl` no body) SHALL NOT ir ao Laravel.
4. WHEN upstream retorna `422 VALIDATION_FAILED` THEN o BFF SHALL repassar status e `errors` ao browser sem criar sessão.
5. WHEN o BFF recebe body JSON malformado ou `Content-Type` inválido THEN SHALL responder `400` com mensagem genérica sem chamar Laravel (validação local handler).

**Independent Test**: RTL validation matrix; Vitest handler malformed body → 400 sem fetch upstream.

**Requirement IDs**: LOG-07

---

### P1: Rate limiting upstream ⭐ MVP

**User Story**: Como usuário, quero ver feedback claro quando excedo tentativas de login.

**Why P1**: Paridade API; BFFUI-32.

**Acceptance Criteria**:

1. WHEN upstream retorna `429 RATE_LIMIT_EXCEEDED` THEN o BFF SHALL repassar status `429` e corpo ao browser sem criar sessão.
2. WHEN upstream inclui header `Retry-After` THEN o BFF SHALL repassar o header ao browser.
3. WHEN a UI recebe `429` THEN SHALL exibir mensagem pt-BR de limite excedido e, se `Retry-After` presente, orientação temporal (segundos ou minutos arredondados).
4. WHEN rate limit dispara THEN nenhuma sessão BFF SHALL ser criada.

**Independent Test**: Vitest upstream `429` + header; RTL exibe mensagem de throttle.

**Requirement IDs**: BFFUI-32, LOG-08

---

### P1: Guards BFF (Origin / CSRF) ⭐ MVP

**User Story**: Como mantenedor, quero que login rejeite mutations cross-site ou sem CSRF antes de chamar Laravel.

**Why P1**: Integração com fatia `csrf-proxy`; `docs/testing.md` §6.2.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/login` chega sem `Origin` válido, com CSRF ausente/inválido, ou header/cookie CSRF divergentes THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel e **sem** criar sessão.
2. WHEN guards falham THEN a resposta SHALL incluir `Cache-Control: private, no-store`.
3. WHEN guards passam com `requireSession: false` THEN SHALL usar modo CSRF pré-auth (`__Host-fl_csrf_sid` + `__Host-fl_csrf`).

**Independent Test**: Vitest handler com Request sintéticos; assert fetch upstream não chamado.

**Requirement IDs**: LOG-09

---

### P1: UI de login server-first ⭐ MVP

**User Story**: Como usuário, quero uma página de login em pt-BR, acessível e funcional a partir de 360px, para entrar na plataforma.

**Why P1**: BFFUI-31; `docs/product.md` §8.

**Acceptance Criteria**:

1. WHEN o usuário navega para `GET /login` sem sessão THEN a página SHALL renderizar formulário com campos e-mail e senha, rótulos/erros em pt-BR, usando primitivos `shared` (Button, Input, Label, FormField).
2. WHEN a página carrega THEN SHALL garantir cookies CSRF pré-auth antes de permitir submit bem-sucedido (bootstrap conforme Assumptions).
3. WHEN o usuário submete credenciais válidas THEN o client SHALL `POST /api/bff/auth/login` com `Content-Type: application/json`, header `X-CSRF-Token` igual ao cookie CSRF, e body `{ email, password }`.
4. WHEN o BFF responde `200` com `redirect_to` THEN a UI SHALL navegar para esse caminho via router Next (sem full page reload externo).
5. WHEN query `?returnUrl=/safe-path` está presente THEN o client SHALL incluir `returnUrl` apenas na query do BFF ou conforme contrato Design — destino final SHALL ser sanitizado pelo BFF na resposta `redirect_to`.
6. WHEN o usuário já possui sessão BFF válida `session` e visita `/login` THEN SHALL redirect para `/` (ou `returnUrl` sanitizado se permitido para sessão completa).
7. WHEN o usuário possui sessão BFF `verification` e visita `/login` THEN SHALL redirect para `/verify-email`.
8. WHEN inspeção de HTML renderizado, props serializadas RSC→client e respostas fetch THEN Bearer SHALL NOT aparecer.

**Independent Test**: RTL + MSW; snapshot de acessibilidade básica (labels associados, foco no primeiro erro); Vitest página redirect se autenticado mock.

**Requirement IDs**: BFFUI-31, LOG-10, LOG-11

---

### P1: Falhas upstream e gateway ⭐ MVP

**User Story**: Como usuário, quero mensagem genérica quando o serviço está indisponível, sem exposição de detalhes internos.

**Why P1**: Robustez BFF; `docs/architecture.md` §8 timeout 10s.

**Acceptance Criteria**:

1. WHEN upstream aborta por timeout (10s) THEN o BFF SHALL responder `504` com mensagem genérica pt-BR e **sem** cookie de sessão.
2. WHEN upstream retorna `500` ou `503` THEN o BFF SHALL responder com mensagem genérica pt-BR espelhando status e **sem** repassar stack trace ou detalhe Laravel.
3. WHEN falha upstream ou Redis em `createSession` após upstream `200` THEN o handler SHALL NOT retornar Bearer ao browser e SHALL NOT deixar cookie de sessão válido (rollback best-effort destroy se write parcial).

**Independent Test**: Vitest fetch reject/500/503; simulate Redis throw on createSession.

**Requirement IDs**: LOG-12

---

### P2: Navegação auxiliar e allowlist ⭐ Should have

**User Story**: Como visitante, quero links para cadastro e recuperação de senha a partir da tela de login.

**Why P2**: Jornada produto §3; rotas alvo podem ser placeholder até fatias 5–7.

**Acceptance Criteria**:

1. WHEN `/login` renderiza THEN SHALL exibir link pt-BR para `/register` e link para `/forgot-password`.
2. WHEN `AUTH_BFF_ALLOWLIST` em produção é inspecionada THEN SHALL conter exatamente a entrada de login além de quaisquer probes dev/test gated.
3. WHEN `make test-frontend` roda THEN testes em `app/api/bff/auth/login/` e `app/login/` SHALL ser descobertos.

**Independent Test**: RTL assert links href; inspeção estática allowlist; Makefile test discovery.

**Requirement IDs**: LOG-13, LOG-14

---

## Edge Cases

- E-mail com caixa/espaços → normalizado pelo client (trim + lowercase) **antes** do submit, alinhado ao padrão API
- `returnUrl` malicioso (`https://evil.com`, `//evil`, encoded) → BFF responde `redirect_to: '/'` (ou `/verify-email` se verification)
- Login com sessão expirada no cookie → tratada como anônimo; login cria nova sessão
- Upstream `200` com body sem `data.token` → BFF responde `500` genérico sem cookie
- Upstream `200` com `token_kind` desconhecido → BFF responde `500` genérico sem cookie
- Duplo submit rápido na UI → botão desabilitado / pending; no máximo uma sessão BFF criada por submit completado
- `Retry-After` não numérico ou ausente → UI ainda exibe mensagem 429 genérica
- Conta `suspended` + senha errada → UI mostra `401` genérico (mesmo que API)
- Query `returnUrl` vazio → default `/` para usuário `active`
- Bearer upstream nunca aparece em `console.log`, traces de teste serializados ou MSW handlers expostos ao browser bundle

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| BFFUI-30 | P1: Login bem-sucedido via BFF | Execute | Verified |
| BFFUI-31 | P1: UI de login server-first | Execute | Verified |
| BFFUI-32 | P1: Credenciais / bloqueio / rate limit | Execute | Verified |
| BFFUI-15 | P1: Login bem-sucedido (rotate/destroy prévio) | Execute | Verified |
| BFFUI-17 | P1: Login bem-sucedido (Bearer absent) | Execute | Verified |
| BFFUI-23 | P1: Login bem-sucedido (`returnUrl`) | Execute | Verified |
| LOG-01 | P1: Login bem-sucedido — handler active | Execute | Verified |
| LOG-02 | P1: Login bem-sucedido — handler verification | Execute | Verified |
| LOG-03 | P1: Login bem-sucedido — Bearer strip | Execute | Verified |
| LOG-04 | P1: Credenciais inválidas — repasse 401 | Execute | Verified |
| LOG-05 | P1: Credenciais inválidas — UI anti-enum | Execute | Verified |
| LOG-06 | P1: Bloqueio por status | Execute | Verified |
| LOG-07 | P1: Validação de entrada | Execute | Verified |
| LOG-08 | P1: Rate limiting upstream | Execute | Verified |
| LOG-09 | P1: Guards Origin/CSRF | Execute | Verified |
| LOG-10 | P1: UI — render e submit | Execute | Verified |
| LOG-11 | P1: UI — redirect autenticado | Execute | Verified |
| LOG-12 | P1: Falhas upstream/gateway | Execute | Verified |
| LOG-13 | P2: Links auxiliares | Execute | Verified |
| LOG-14 | P2: Allowlist + test discovery | Execute | Verified |

**Coverage:** 19 total, 19 mapped ✅

---

## Success Criteria

- [ ] Usuário `active` completa login em `/login` e chega a `/` (ou `returnUrl` seguro) com cookie `__Host-fl_session` setado e **zero** Bearer no browser.
- [ ] Usuário `pending_verification` completa login e é enviado a `/verify-email` com sessão BFF `verification`.
- [ ] Credenciais inválidas e senha errada em conta bloqueada produzem mesma UX `401` genérica.
- [ ] Contas suspensas/exclusão pendente com senha correta veem mensagem `403` específica sem sessão criada.
- [ ] `429` upstream exibe feedback pt-BR; guards CSRF/Origin bloqueiam sem chamar Laravel.
- [ ] `make test-frontend` passa com cobertura ≥75% nos arquivos novos da fatia.
- [ ] Nenhum teste Vitest/RTL falha se `JSON.stringify(resposta)` ou HTML contiver substring do Bearer de fixture.

---

## Referências

| Documento | Uso |
| --- | --- |
| `.specs/features/auth/login/spec.md` | Contrato upstream verificado |
| `.specs/features/bff-auth/session-core/spec.md` | `createSession`, TTL, rotate/destroy |
| `.specs/features/bff-auth/csrf-proxy/spec.md` | Guards, allowlist, `returnUrl`, upstream |
| `.specs/features/bff-auth/foundation/spec.md` | RHF+Zod, primitivos UI, server-first |
| `docs/openapi.yaml` | `login`, `LoginRequest`, `AuthResponse`, erros |
| `docs/product.md` §3, §8 | Jornada e UI pt-BR |
| `docs/security.md` §4.1, §5 | Anti-enum, sessão BFF, CSRF |
| `docs/testing.md` §3.2, §6.2 | Estratégia Vitest/RTL/MSW BFF |
| `docs/architecture.md` §8.1 | Organização módulo Auth frontend |
