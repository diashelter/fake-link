# BFF Auth — Cadastro

**Status:** Verified — 2026-08-11  
**Fatia:** 5 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-40 … BFFUI-41  
**Requirement IDs (fatia):** RGR-01 … RGR-18  
**Depende de:** [login](../login/spec.md) (Verified), [csrf-proxy](../csrf-proxy/spec.md) (Verified), [session-core](../session-core/spec.md) (Verified)  
**Upstream API:** `POST /api/v1/auth/register` (Auth API Verified — `.specs/features/auth/registration/spec.md`)

## Problem Statement

Convidados elegíveis precisam criar conta pelo browser oficial (`https://app.localhost`), aceitar Terms versionados e receber sessão BFF restrita (`verification`) — sem jamais receber o Bearer emitido pelo Laravel e sem revelar se o e-mail está na allowlist ou já cadastrado.

A fatia `login` entregou o padrão de Route Handler BFF + UI server-first; esta fatia aplica o mesmo modelo ao cadastro: `POST /api/bff/auth/register` chama a API Laravel, persiste o Bearer somente no Redis cifrado, emite cookie de sessão opaco com `kind: verification`, e a página `/register` (server-first, pt-BR) permite criar conta com nome, e-mail, senha, confirmação e aceite explícito de termos.

## Goals

- [x] Route Handler BFF `POST /api/bff/auth/register` registrado na `AUTH_BFF_ALLOWLIST`, com guards de Origin/CSRF pré-auth, chamada upstream allowlisted e emissão de sessão BFF `verification` **sem** Bearer na resposta ao browser.
- [x] Página UI `/register` server-first com formulário RHF+Zod; aceite explícito de Terms com versão exibida; redirecionamento pós-sucesso fixo para `/verify-email`.
- [x] Matriz de erros alinhada à API Laravel (403/422/429/503) com mensagens pt-BR; anti-enumeração uniforme para convite inválido e e-mail duplicado; upstream 5xx/504 genéricos; guards BFF 403 genérico.
- [x] Política de senha refletida no Zod client-side **e** erros server-side `422` preservados (composição ASCII: minúscula, maiúscula, dígito, símbolo; 12–128 caracteres).
- [x] Vitest cobre Route Handler (happy path, erros, strip de Bearer), RTL do formulário e ausência de Bearer em JSON/HTML/storage simulado.
- [x] Cobertura ≥75% linhas/branches nos arquivos introduzidos nesta fatia (`docs/testing.md` §4).

## Out of Scope

| Item | Motivo |
| --- | --- |
| Allowlist server-side / lógica de convite | Já na API Auth (`.specs/features/auth/registration/`) |
| Verificação de e-mail (handlers + UI) | Fatia `email-verification` |
| Login / logout / perfil / guards autenticados | Fatias `login` (entregue) e `session-shell` |
| Playwright E2E security gate completo | Fatia `e2e-security-gate` |
| Rate limiting BFF adicional | API Laravel já limita upstream (5/h por IP) |
| MFA, cadastro público | Pós-MVP |
| Conteúdo jurídico final dos Terms | Checklist jurídico externo; UI precisa aceite versionado e página `/terms` mínima |
| Página de convite separada | Cadastro único em `/register` com mensagem genérica em falha |
| Envio Resend / jobs de verificação | API Auth |
| OpenAPI do BFF como contrato público separado | BFF é boundary interno browser↔Next |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path BFF | `POST /api/bff/auth/register` → upstream `POST /auth/register` | AD-017; prefixo `/api/bff/...` | y |
| Path UI | `/register` | `docs/product.md` §8; link já presente em `/login` | y |
| Allowlist entry | `{ method: 'POST', bffPath: '/api/bff/auth/register', upstreamMethod: 'POST', upstreamPath: '/auth/register', requireSession: false, requireCsrf: true }` | Cadastro é mutation pública pré-auth (paridade login) | y |
| Pós-register | Redirect fixo `/verify-email` | Usuário nasce `pending_verification`; jornada produto §2.1 | y |
| `returnUrl` | **Não aplicável** nesta fatia | Conta sempre restrita; destino único é verificação | y |
| Versão dos Terms (exibição UI) | `NEXT_PUBLIC_AUTH_TERMS_CURRENT_VERSION` (default `2026-01`) alinhada a `AUTH_TERMS_CURRENT_VERSION` do backend | API persiste versão server-side; browser só exibe e exige aceite | y |
| Payload upstream | `{ name, email, password, password_confirmation, accept_terms: true }` somente | OpenAPI `RegisterRequest`; `terms_version` **não** é enviado pelo cliente | y |
| Corpo BFF de sucesso | `{ "data": { "user": User, "redirect_to": "/verify-email" } }` — **sem** campos `token`, `token_type`, `token_kind`, `expires_at` | Bearer fica só no Redis cifrado | y |
| Kind da sessão BFF | Sempre `verification` em sucesso (`201`) | Upstream emite `token_kind: verification` para registro | y |
| Sessão pré-existente no register | Se cookie `__Host-fl_session` válido existir, `destroySession` best-effort **antes** de `createSession` | BFFUI-15 — rotação/fixation; paridade login | y |
| CSRF pós-register | Após `createSession`, chamar `issueCsrfForSession(newSessionId)` | Token CSRF passa a derivar da sessão verification | y |
| Bootstrap CSRF na página register | GET `/register` SHALL garantir cookies pré-auth (`ensurePreAuthCsrfCookies`) antes do submit | Paridade login | y |
| Mensagens de erro UI | pt-BR; preservar `code` da API quando presente; mapear 5xx/gateway para genérico | Product UI pt-BR | y |
| Anti-enumeração UI | `403 REGISTRATION_NOT_ALLOWED` → mesma mensagem pt-BR genérica para convite inválido **e** e-mail duplicado | Paridade API + `docs/testing.md` §6.1 | y |
| Rate limit UI | Confiar no `429` upstream + exibir `Retry-After` quando header presente | Paridade login | y |
| Pass-through de erros 4xx | Repassar status + body JSON da API (`code`, `message`, `errors` quando 422) com headers privados | UI mapeia códigos | y |
| Upstream timeout/abort | Responder `504` com mensagem genérica pt-BR | Helper existente; orçamento 10s | y |
| Upstream 500/503 | Responder mensagem genérica pt-BR espelhando status | Sem vazar detalhe Laravel | y |
| Allowlist upstream indisponível | Repassar `503 SERVICE_UNAVAILABLE` com mensagem genérica pt-BR | OpenAPI register | y |
| Validação Zod client | Espelha `RegisterRequest` + política de senha completa; normaliza e-mail (trim + lowercase) | Paridade OpenAPI + UX imediata | y |
| Página `/terms` | RSC estática mínima em pt-BR exibindo versão atual; link abre em nova aba a partir do checkbox | Aceite auditável; conteúdo legal final fora do escopo | y |
| Link "Já tenho conta" | `/login` | Navegação simétrica ao link "Criar conta" em login | y |
| Usuário já autenticado visita `/register` | Redirect `/verify-email` se sessão `verification`; redirect `/` se sessão `session` | Paridade login | y |
| Status upstream de sucesso | `201 Created` (não `200`) | OpenAPI `AuthIssued` para register | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Zod client espelha `RegisterRequest` + `Password` OpenAPI; BFF valida JSON parseável; `name` 1–120; `email` max 254; senha 12–128 com composição; `accept_terms` literal `true`; rejeitar body malformado localmente |
| Failure / partial-failure states | Origin/CSRF → 403 genérico; convite/duplicidade → 403 uniforme; validação → 422; rate limit → 429; allowlist indisponível → 503; upstream fail → 504/500 genérico; **nunca** cookie parcial se upstream falhou ou Bearer ausente |
| Idempotency / retry / duplicate handling | Re-submit válido após sucesso cria nova sessão se API permitir (API trata duplicata como 403); submit duplo UI desabilitado durante pending |
| Auth boundaries & rate limits | Endpoint BFF público com CSRF+Origin; rate limit só upstream (5/h/IP contando todas tentativas POST register) |
| Concurrency / ordering | Dois registers concorrentes no mesmo e-mail → API garante no máximo um `201`; demais `403` uniforme; destroy-before-create serializa por request |
| Data lifecycle / expiry | Sessão BFF `verification`: 24h abs / 1h idle (`session-core`) |
| Observability | Proibido logar e-mail, senha, Bearer, session ID bruto, conteúdo de allowlist ou token CSRF completo |
| External-dependency failure | Laravel timeout → 504 sem sessão; Redis fail em `createSession` → 500 genérico; destroy pré-existente best-effort |
| State-transition integrity | Register BFF **não** altera regras upstream; usuário nasce `pending_verification` via API |

---

## Entregáveis técnicos (mínimo)

```txt
frontend/
  app/
    register/
      page.tsx                    # Server-first; bootstrap CSRF; redirect se já autenticado
      page.test.tsx               # RTL: render, validação, submit MSW
    terms/
      page.tsx                    # RSC estática mínima — versão dos Terms
    api/bff/auth/register/
      route.ts                    # POST handler produto
      route.test.ts               # Vitest: matriz status upstream + Bearer absent
  modules/auth/
    schemas/
      register-schema.ts          # Zod RegisterRequest mirror + password policy
      password-schema.ts          # Reutilizável (register + fatia password)
    components/
      register-form.tsx           # Client: RHF form + Terms checkbox + fetch BFF
    bff/
      allowlist.ts                # + entrada register
    services/
      bff-register.ts             # Orquestração testável handler ↔ session
```

Handler **não** pode usar pass-through cru de respostas `201` — deve parsear `AuthResponse`, extrair Bearer server-side, criar sessão `verification` e responder corpo sanitizado.

---

## User Stories

### P1: Cadastro bem-sucedido via BFF ⭐ MVP

**User Story**: Como convidado elegível, quero criar conta pelo browser oficial para obter sessão BFF restrita sem nunca ver o Bearer.

**Why P1**: Desbloqueia jornada de verificação; BFFUI-40.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/register` recebe `{ name, email, password, password_confirmation, accept_terms: true }` válidos com guards Origin+CSRF satisfeitos e upstream retorna `201 AuthResponse` THEN o handler SHALL chamar Laravel via entrada allowlisted, extrair `data.token` **somente server-side**, invocar `createSession({ bearer, kind: 'verification', userId })`, emitir `Set-Cookie` `__Host-fl_session`, invocar `issueCsrfForSession`, e responder `201` com `{ data: { user, redirect_to: '/verify-email' } }`.
2. WHEN upstream retorna `201` THEN `data.user.status` SHALL ser `pending_verification` e `data.user.email_verified_at` SHALL ser `null`.
3. WHEN a resposta BFF de sucesso é inspecionada THEN o JSON SHALL NOT conter substrings `token`, `Bearer`, `token_kind`, `token_type`, `expires_at` nem valor igual ao Bearer upstream.
4. WHEN cadastro bem-sucedido THEN headers SHALL incluir `Cache-Control: private, no-store`.
5. WHEN cadastro bem-sucedido e cookie de sessão pré-existente válido THEN o handler SHALL `destroySession` da sessão anterior (best-effort) antes de `createSession`.
6. WHEN cadastro bem-sucedido THEN o registro Redis SHALL conter Bearer cifrado e SHALL NOT conter Bearer plaintext.

**Independent Test**: Vitest handler com fetch mock upstream `201`; assert `createSession` spy com `kind: 'verification'`, Set-Cookie, body sem token; MSW + RTL submit happy path → redirect `/verify-email`.

**Requirement IDs**: BFFUI-40, BFFUI-15, BFFUI-17, RGR-01, RGR-02, RGR-03

---

### P1: Anti-enumeração no cadastro ⭐ MVP

**User Story**: Como plataforma, quero que falhas de convite ou e-mail duplicado no BFF espelhem a API sem vazar existência de conta ou allowlist.

**Why P1**: BFFUI-32; `docs/security.md` §4.1, `docs/testing.md` §6.1.

**Acceptance Criteria**:

1. WHEN upstream retorna `403` com `code=REGISTRATION_NOT_ALLOWED` (convite inválido **ou** e-mail duplicado) THEN o BFF SHALL repassar status `403` e corpo equivalente (`code`, `message`) ao browser **sem** emitir cookie de sessão.
2. WHEN upstream retorna `403 REGISTRATION_NOT_ALLOWED` THEN a resposta BFF SHALL NOT conter campos de usuário, token ou indício de motivo específico além do contrato API.
3. WHEN convite inválido ou duplicata THEN nenhuma entrada nova SHALL ser criada no Redis de sessão BFF.
4. WHEN a UI recebe `403 REGISTRATION_NOT_ALLOWED` THEN SHALL exibir a **mesma** mensagem pt-BR genérica para ambos os cenários (MSW: e-mail não convidado vs e-mail já cadastrado).

**Independent Test**: Vitest handler upstream `403` (dois fixtures); assert zero Set-Cookie; RTL exibe mensagem idêntica nos dois cenários.

**Requirement IDs**: BFFUI-32, RGR-04, RGR-05

---

### P1: Aceite de Terms ⭐ MVP

**User Story**: Como plataforma, quero aceite explícito e versionado de Terms antes de criar conta.

**Why P1**: BFFUI-41; `docs/product.md` §3, §9.

**Acceptance Criteria**:

1. WHEN o formulário UI é submetido com checkbox de Terms desmarcado THEN o client SHALL bloquear submit com erro de campo **sem** chamar o BFF.
2. WHEN o formulário renderiza THEN SHALL exibir label pt-BR com versão atual (ex.: "Li e aceito os Termos de uso (versão 2026-01)") e link para `/terms` (nova aba).
3. WHEN submit válido THEN o body enviado ao BFF SHALL incluir `accept_terms: true` (boolean literal).
4. WHEN upstream retorna `422` por `accept_terms` inválido/ausente THEN o BFF SHALL repassar erros de campo sem criar sessão.
5. WHEN cadastro bem-sucedido THEN `data.user.terms_version` na resposta sanitizada SHALL refletir a versão persistida pela API (ex.: `2026-01`) e `terms_accepted_at` SHALL estar preenchido.

**Independent Test**: RTL checkbox obrigatório; Vitest upstream 422 accept_terms; happy path assert user.terms_version.

**Requirement IDs**: BFFUI-41, RGR-06, RGR-07

---

### P1: Política de senha ⭐ MVP

**User Story**: Como convidado, quero feedback imediato sobre senha fraca; como API gateway, quero payloads conformes antes de side effects.

**Why P1**: Product §3; paridade `PasswordPolicy` backend.

**Acceptance Criteria**:

1. WHEN `password` tem menos de 12 ou mais de 128 caracteres THEN o client SHALL bloquear submit com erro de campo pt-BR.
2. WHEN `password` não contém minúscula ASCII, maiúscula ASCII, dígito ASCII ou símbolo ASCII (`!`–`/` `:`–`@` `[`–`` ` `{`–`~`) THEN o client SHALL bloquear submit com erro de campo pt-BR.
3. WHEN `password` ≠ `password_confirmation` THEN o client SHALL bloquear submit com erro de campo.
4. WHEN upstream retorna `422 VALIDATION_FAILED` por violação de senha THEN o BFF SHALL repassar `errors.password` / `errors.password_confirmation` ao browser sem criar sessão.
5. WHEN senha válida no client mas upstream rejeita por política THEN a UI SHALL exibir erros server-side preservados (sem sobrescrever por mensagem genérica).

**Independent Test**: RTL matrix de senhas inválidas; Vitest 422 password errors; shared `password-schema.test.ts`.

**Requirement IDs**: RGR-08, RGR-09

---

### P1: Validação de entrada ⭐ MVP

**User Story**: Como convidado, quero feedback imediato de formulário inválido; como BFF, quero rejeitar payloads inválidos sem chamar Laravel quando possível.

**Why P1**: Contrato OpenAPI; UX foundation RHF+Zod.

**Acceptance Criteria**:

1. WHEN `name` está vazio ou excede 120 caracteres THEN o client SHALL bloquear submit com erro de campo.
2. WHEN `email` é sintaticamente inválido ou excede 254 caracteres THEN o client SHALL bloquear submit com erro de campo.
3. WHEN e-mail é normalizado THEN o client SHALL aplicar trim + lowercase **antes** do submit (paridade API).
4. WHEN o BFF recebe JSON THEN SHALL encaminhar upstream somente `{ name, email, password, password_confirmation, accept_terms }` — campos extras SHALL NOT ir ao Laravel.
5. WHEN upstream retorna `422 VALIDATION_FAILED` THEN o BFF SHALL repassar status e `errors` ao browser sem criar sessão.
6. WHEN o BFF recebe body JSON malformado ou `Content-Type` inválido THEN SHALL responder `400` com mensagem genérica pt-BR sem chamar Laravel.

**Independent Test**: RTL validation matrix; Vitest malformed body → 400 sem fetch upstream.

**Requirement IDs**: RGR-10

---

### P1: Rate limiting upstream ⭐ MVP

**User Story**: Como plataforma, quero feedback claro quando tentativas de cadastro excedem o limite.

**Why P1**: Paridade API (5/h/IP); BFFUI-32.

**Acceptance Criteria**:

1. WHEN upstream retorna `429 RATE_LIMIT_EXCEEDED` THEN o BFF SHALL repassar status `429` e corpo ao browser sem criar sessão.
2. WHEN upstream inclui header `Retry-After` THEN o BFF SHALL repassar o header ao browser.
3. WHEN a UI recebe `429` THEN SHALL exibir mensagem pt-BR de limite excedido e, se `Retry-After` presente, orientação temporal.
4. WHEN rate limit dispara THEN nenhuma sessão BFF SHALL ser criada.

**Independent Test**: Vitest upstream `429` + header; RTL exibe throttle.

**Requirement IDs**: BFFUI-32, RGR-11

---

### P1: Guards BFF (Origin / CSRF) ⭐ MVP

**User Story**: Como mantenedor, quero que cadastro rejeite mutations cross-site ou sem CSRF antes de chamar Laravel.

**Why P1**: Integração `csrf-proxy`; `docs/testing.md` §6.2.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/register` chega sem `Origin` válido, com CSRF ausente/inválido, ou header/cookie CSRF divergentes THEN o handler SHALL responder `403` `{ "message": "Forbidden." }` **sem** invocar Laravel e **sem** criar sessão.
2. WHEN guards falham THEN a resposta SHALL incluir `Cache-Control: private, no-store`.
3. WHEN guards passam com `requireSession: false` THEN SHALL usar modo CSRF pré-auth.

**Independent Test**: Vitest handler com Request sintéticos; assert fetch upstream não chamado.

**Requirement IDs**: RGR-12

---

### P1: UI de cadastro server-first ⭐ MVP

**User Story**: Como convidado, quero uma página de cadastro em pt-BR, acessível e funcional a partir de 360px.

**Why P1**: BFFUI-41; `docs/product.md` §8.

**Acceptance Criteria**:

1. WHEN o usuário navega para `GET /register` sem sessão THEN a página SHALL renderizar formulário com campos nome, e-mail, senha, confirmação de senha e aceite de Terms, usando primitivos `shared` (Button, Input, Label, FormField, Checkbox se disponível).
2. WHEN a página carrega THEN SHALL garantir cookies CSRF pré-auth antes de permitir submit bem-sucedido.
3. WHEN o usuário submete cadastro válido THEN o client SHALL `POST /api/bff/auth/register` com `Content-Type: application/json`, header `X-CSRF-Token` igual ao cookie CSRF, e body conforme `RegisterRequest`.
4. WHEN o BFF responde `201` com `redirect_to` THEN a UI SHALL navegar para `/verify-email` via router Next.
5. WHEN o usuário possui sessão BFF válida `session` e visita `/register` THEN SHALL redirect para `/`.
6. WHEN o usuário possui sessão BFF `verification` e visita `/register` THEN SHALL redirect para `/verify-email`.
7. WHEN inspeção de HTML renderizado, props serializadas RSC→client e respostas fetch THEN Bearer SHALL NOT aparecer.
8. WHEN `/register` renderiza THEN SHALL exibir link pt-BR "Já tenho conta" para `/login`.

**Independent Test**: RTL + MSW; labels associados; Vitest página redirect se autenticado mock.

**Requirement IDs**: BFFUI-41, RGR-13, RGR-14

---

### P1: Falhas upstream e gateway ⭐ MVP

**User Story**: Como convidado, quero mensagem genérica quando o serviço está indisponível.

**Why P1**: Robustez BFF; orçamento 10s.

**Acceptance Criteria**:

1. WHEN upstream aborta por timeout (10s) THEN o BFF SHALL responder `504` com mensagem genérica pt-BR e **sem** cookie de sessão.
2. WHEN upstream retorna `500` ou `503` (incluindo allowlist indisponível) THEN o BFF SHALL responder com mensagem genérica pt-BR espelhando status e **sem** vazar stack trace ou detalhe Laravel/allowlist.
3. WHEN falha upstream ou Redis em `createSession` após upstream `201` THEN o handler SHALL NOT retornar Bearer ao browser e SHALL NOT deixar cookie de sessão válido (rollback best-effort destroy se write parcial).
4. WHEN upstream retorna `201` com body sem `data.token` THEN o BFF SHALL responder `500` genérico sem cookie.

**Independent Test**: Vitest fetch reject/500/503; simulate Redis throw on createSession.

**Requirement IDs**: RGR-15, RGR-16

---

### P2: Página Terms, allowlist e descoberta de testes ⭐ Should have

**User Story**: Como mantenedor, quero página Terms mínima, entrada na allowlist e testes descobertos pelo suite padrão.

**Why P2**: Auditabilidade e gates CI.

**Acceptance Criteria**:

1. WHEN o usuário navega para `GET /terms` THEN SHALL ver página estática pt-BR com versão atual dos Terms (mesma fonte que o checkbox).
2. WHEN `AUTH_BFF_ALLOWLIST` é inspecionada THEN SHALL conter entrada de register além de login e probes dev/test gated.
3. WHEN `make test-frontend` roda THEN testes em `app/api/bff/auth/register/`, `app/register/` e `schemas/register-schema` SHALL ser descobertos.

**Independent Test**: RTL link `/terms`; inspeção estática allowlist; Makefile test discovery.

**Requirement IDs**: RGR-17, RGR-18

---

## Edge Cases

- E-mail allowlisted com subaddress não listado → `403 REGISTRATION_NOT_ALLOWED` (API); UI mensagem genérica
- Dois POSTs concorrentes com mesmo e-mail → no máximo um `201` upstream; demais `403` uniforme
- Allowlist upstream indisponível → `503` genérico pt-BR; sem cookie
- Senha válida na composição mas com Unicode fora das quatro categorias ASCII exigidas → `422` upstream; UI exibe erro de campo
- Duplo submit rápido na UI → botão desabilitado / pending; no máximo uma sessão BFF por submit completado
- `Retry-After` ausente em `429` → UI ainda exibe mensagem genérica de limite
- Usuário marca Terms, desmarca e re-marca → submit só quando `accept_terms: true` no payload
- Upstream `201` com `token_kind` diferente de `verification` → BFF responde `500` genérico sem cookie
- Bearer upstream nunca aparece em `console.log`, traces de teste serializados ou MSW handlers expostos ao browser bundle
- Nome com apenas espaços → trim client-side ou erro de validação antes do BFF

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| BFFUI-40 | P1: Cadastro bem-sucedido via BFF | Execute | Verified |
| BFFUI-41 | P1: UI cadastro + Terms | Execute | Verified |
| BFFUI-32 | P1: Anti-enumeração / rate limit | Execute | Verified |
| BFFUI-15 | P1: Cadastro bem-sucedido (rotate/destroy prévio) | Execute | Verified |
| BFFUI-17 | P1: Cadastro bem-sucedido (Bearer absent) | Execute | Verified |
| RGR-01 | P1: Cadastro bem-sucedido — handler | Execute | Verified |
| RGR-02 | P1: Cadastro bem-sucedido — user pending | Execute | Verified |
| RGR-03 | P1: Cadastro bem-sucedido — Bearer strip | Execute | Verified |
| RGR-04 | P1: Anti-enumeração — repasse 403 | Execute | Verified |
| RGR-05 | P1: Anti-enumeração — UI uniforme | Execute | Verified |
| RGR-06 | P1: Terms — checkbox obrigatório | Execute | Verified |
| RGR-07 | P1: Terms — versão e link | Execute | Verified |
| RGR-08 | P1: Política de senha — client | Execute | Verified |
| RGR-09 | P1: Política de senha — server 422 | Execute | Verified |
| RGR-10 | P1: Validação de entrada | Execute | Verified |
| RGR-11 | P1: Rate limiting | Execute | Verified |
| RGR-12 | P1: Guards Origin/CSRF | Execute | Verified |
| RGR-13 | P1: UI — render e submit | Execute | Verified |
| RGR-14 | P1: UI — redirect autenticado + link login | Execute | Verified |
| RGR-15 | P1: Falhas upstream/gateway | Execute | Verified |
| RGR-16 | P1: Upstream 201 sem token | Execute | Verified |
| RGR-17 | P2: Página `/terms` | Execute | Verified |
| RGR-18 | P2: Allowlist + test discovery | Execute | Verified |

**Coverage:** 23 total, 23 mapped ✅

---

## Success Criteria

- [x] Convidado allowlisted completa cadastro em `/register` e chega a `/verify-email` com cookie `__Host-fl_session` (`verification`) e **zero** Bearer no browser.
- [x] E-mail não convidado e e-mail duplicado produzem mesma UX `403` genérica pt-BR.
- [x] Terms não aceitos bloqueiam submit client-side; versão exibida alinha com API (`2026-01`).
- [x] Senha fraca bloqueia no client; erros `422` server-side aparecem sem vazar infraestrutura.
- [x] `429` upstream exibe feedback pt-BR; guards CSRF/Origin bloqueiam sem chamar Laravel.
- [x] `503` allowlist indisponível exibe mensagem genérica sem cookie.
- [x] `make test-frontend` passa com cobertura ≥75% nos arquivos novos da fatia.
- [x] Nenhum teste Vitest/RTL falha se `JSON.stringify(resposta)` ou HTML contiver substring do Bearer de fixture.

---

## Referências

| Documento | Uso |
| --- | --- |
| `.specs/features/auth/registration/spec.md` | Contrato upstream verificado |
| `.specs/features/bff-auth/login/spec.md` | Padrão handler/UI/sessão (Verified) |
| `.specs/features/bff-auth/session-core/spec.md` | `createSession`, TTL verification |
| `.specs/features/bff-auth/csrf-proxy/spec.md` | Guards, allowlist, upstream |
| `.specs/features/bff-auth/foundation/spec.md` | RHF+Zod, primitivos UI |
| `docs/openapi.yaml` | `register`, `RegisterRequest`, `AuthIssued`, `RegistrationNotAllowed` |
| `docs/product.md` §3, §8, §9 | Jornada, UI pt-BR, aceite Terms |
| `docs/security.md` §4.1, §5 | Anti-enum, sessão BFF, CSRF |
| `docs/testing.md` §3.2, §6.1, §6.2 | Vitest/RTL/MSW BFF |
| `docs/architecture.md` §8.1 | Organização módulo Auth frontend |
