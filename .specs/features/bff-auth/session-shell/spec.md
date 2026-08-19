# BFF Auth — Sessão e shell

**Status:** Verified — 2026-08-19  
**Context:** [context.md](./context.md)  
**Design:** [design.md](./design.md)  
**Tasks:** [tasks.md](./tasks.md)  
**Fatia:** 8 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-70 … BFFUI-74  
**Requirement IDs (fatia):** SH-01 … SH-28  
**Depende de:** [login](../login/spec.md) (Verified), [csrf-proxy](../csrf-proxy/spec.md) (Verified), [session-core](../session-core/spec.md) (Verified); consome guards de [email-verification](../email-verification/spec.md) e UI `/settings/password` de [password](../password/spec.md)  
**Upstream API:** `POST /api/v1/auth/logout`, `POST /api/v1/auth/logout-all`, `GET /api/v1/me`, `PATCH /api/v1/me` (Auth API Verified — `.specs/features/auth/session-and-profile/spec.md`)

## Problem Statement

Usuários autenticados no browser oficial precisam encerrar a sessão atual, revogar todas as sessões com senha, ver o perfil e alterar somente o nome — sem nunca receber Bearer. A API Laravel já entrega `logout`, `logout-all` e `GET/PATCH /me`. As fatias BFF 1–7 entregaram cookie/Redis, CSRF/proxy, login/register/verify/password e um guard mínimo de sessão `verification`, mas **não** os handlers de logout/me nem o shell autenticado.

Esta fatia fecha a jornada de conta na Fase 1: Route Handlers BFF allowlisted, UI de perfil em `/settings`, nav mínima no destino pós-login (`/`), guards completos (convidado vs `verification` vs `session`) e logout seguro quando Redis ou Laravel falham (`docs/security.md` §5.2).

## Goals

- [ ] `POST /api/bff/auth/logout`: sempre expira o cookie; tenta `destroySession` + revogar Bearer; falha remota = best-effort + contador interno (sem fila).
- [ ] `POST /api/bff/auth/logout-all`: exige `kind: session` + `current_password`; só após `204` upstream encerra a sessão BFF local.
- [ ] `GET/PATCH /api/bff/auth/me`: proxy allowlist; GET aceita `session` e `verification`; PATCH só `name` e só `session`.
- [ ] UI pt-BR: `/settings` (nome editável, e-mail somente leitura, logout-all, link para `/settings/password`); shell autenticado em `/` quando `kind: session`.
- [ ] Guards: convidado em rotas de conta → `/login`; `verification` fora da allowlist → `/verify-email`; `session` em `/verify-email` → `/`.
- [ ] Vitest/RTL: best-effort logout, matriz de kinds, perfil, guards; cobertura ≥75% nos arquivos desta fatia (`docs/testing.md` §4).
- [ ] Bearer, senha e session ID bruto ausentes de JSON/HTML/storage simulado e logs de app.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Dashboard / CRUD de Links | Fase 2 |
| UI de change/forgot/reset password | Fatia `password` (Verified) — esta fatia **linka** para `/settings/password` |
| Lista de sessões / dispositivos | Fora do produto (`docs/product.md` §3) |
| Alteração de e-mail | Fora do MVP (`docs/data-model.md`) |
| Playwright / axe gate completo | Fatia `e2e-security-gate` |
| Rate limiting BFF adicional | API Laravel já limita (leituras 300/min token; escritas 120/min conta) |
| OpenTelemetry export / alertas Grafana | Fase 4; esta fatia entrega **hook de contador** in-process (paridade `bff_session_decrypt_fail_total`) |
| ETag / optimistic concurrency no PATCH | Last-write-wins; API não expõe ETag |
| OpenAPI do BFF como contrato público | Boundary interno browser↔Next |
| Proxy genérico / URL upstream dinâmica | Proibido (`docs/security.md` §5.3) |
| Middleware Next.js global obrigatório | Guards composáveis por layout/página (paridade fatias 6–7) |

---

## Assumptions & Open Questions

Aprovada em 2026-08-19 (mantenedor: gerar Design/Tasks). Closures da revisão estão nas linhas novas no fim desta tabela. Contrato HTTP segue a API Auth e o padrão das fatias 4–7.

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path BFF logout | `POST /api/bff/auth/logout` → upstream `POST /auth/logout` | AD-017 | y |
| Path BFF logout-all | `POST /api/bff/auth/logout-all` → upstream `POST /auth/logout-all` | AD-017 | y |
| Path BFF me | `GET` e `PATCH /api/bff/auth/me` → upstream `GET`/`PATCH /me` | OpenAPI `/api/v1/me` (base Laravel já inclui `/api/v1`) | y |
| Path UI perfil | `/settings` | Coesão com `/settings/password` já entregue | y |
| Path UI logout-all | Seção na mesma `/settings` (formulário senha + submit) | Sem lista de dispositivos; uma tela de conta | y |
| Shell autenticado | Layout/nav mínima em rotas de conta: Início `/`, Conta `/settings`, Sair (POST logout); `/` com `session` troca o CTA anônimo por placeholder pt-BR (“em breve” / sem Links) | Login `redirect_to` default `/`; Fase 2 substitui placeholder | y |
| `/` sem sessão | Mantém landing pública atual (sem exigir login) | Já implementado; produto §8 landing | y |
| Allowlist logout | `{ method: 'POST', bffPath: '/api/bff/auth/logout', upstreamMethod: 'POST', upstreamPath: '/auth/logout', requireSession: true, requireCsrf: true }` | Mutation autenticada | y |
| Allowlist logout-all | `{ method: 'POST', bffPath: '/api/bff/auth/logout-all', upstreamMethod: 'POST', upstreamPath: '/auth/logout-all', requireSession: true, requireCsrf: true }` | Idem | y |
| Allowlist GET me | `{ method: 'GET', bffPath: '/api/bff/auth/me', upstreamMethod: 'GET', upstreamPath: '/me', requireSession: true, requireCsrf: false }` | Primeira leitura GET allowlisted; csrf-proxy: GET sem CSRF/Origin | y |
| Allowlist PATCH me | `{ method: 'PATCH', bffPath: '/api/bff/auth/me', upstreamMethod: 'PATCH', upstreamPath: '/me', requireSession: true, requireCsrf: true }` | Mutation autenticada | y |
| Kind logout | Cookie BFF `session` **ou** `verification` | OpenAPI `x-allowed-token-kinds`; API AUTH-30 | y |
| Kind logout-all / PATCH | Somente `session`; outro kind → `403` `{ "message": "Forbidden." }` **sem** Laravel | Paridade change-password / TOKEN_RESTRICTED | y |
| Kind GET me | `session` **ou** `verification` | OpenAPI; sessão restrita consulta User | y |
| Payload logout | Sem body de produto; JSON vazio `{}` aceito; extras **não** encaminhados (omitir body ao upstream) | API aceita sem requestBody | y |
| Payload logout-all | `{ "current_password": "..." }` somente | `CurrentPasswordRequest`; `additionalProperties: false` | y |
| Payload PATCH | `{ "name": "<trimado>" }` somente | `UpdateUserRequest`; e-mail imutável | y |
| Normalização `name` | Trim de espaços externos no client e no BFF; vazio após trim → bloqueio client / `400` local **sem** Laravel | Paridade API AUTH (trim + 1–120) | y |
| Bounds `name` | 1–120 após trim | OpenAPI + varchar(120) | y |
| `current_password` client | Required, `maxLength: 128`; **sem** revalidar composição | Paridade login / logout-all API | y |
| Corpo logout sucesso | Traduz upstream `204` (ou best-effort local) → BFF `200` `{ "data": { "redirect_to": "/login", "message": "Você saiu da conta." } }` | UI precisa de destino; cookie já limpo | y |
| Corpo logout-all sucesso | Upstream `204` → BFF `200` `{ "data": { "redirect_to": "/login", "message": "Todas as sessões foram encerradas. Faça login para continuar." } }` | Paridade reset/change | y |
| GET me sucesso | Repassar `200` + envelope `UserResponse` inalterado (campos OpenAPI do User) | UI hidrata perfil | y |
| PATCH sucesso | Repassar `200` + `UserResponse` | Nome atualizado visível | y |
| Logout — cookie sempre | `clearSessionCookie` em **todo** response de logout em que o browser pediu encerrar (incluindo Redis miss / sem Bearer) | Security §5.2 | y |
| Logout — Redis miss / cookie inválido | **Não** chamar Laravel (não há Bearer); clear cookie; `200` + `redirect_to: /login` | Idempotência; sem oracle extra | y |
| Logout — Laravel `204` | `destroySession` best-effort + clear cookie + `200` | Happy path | y |
| Logout — Laravel timeout/5xx/indisponível | Ainda `destroySession` best-effort + clear cookie + `200` sucesso local; incrementar `bff_logout_upstream_fail_total` | Security §5.2; sem fila | y |
| Logout — `destroySession` falha | Ainda tenta revoke Laravel (se Bearer em memória) + clear cookie + `200`; incrementar `bff_logout_redis_fail_total` | Security §5.2 | y |
| Logout — Laravel `401` (já revogado) | Tratar como sucesso local: destroy + clear cookie + `200` | Token já morto; cookie não pode ficar | y |
| Logout-all — falha upstream | **Não** destroy/clear; sessão permanece; pass-through `401`/`403`/`422`/`429`/`5xx` | Senha errada não pode deslogar; API é fonte da revogação global | y |
| Logout-all — sucesso + Redis fail | `204` upstream → ainda clear cookie + `200` (paridade password change BFFUI-63) | Cookie obsoleto | y |
| Pós-logout CSRF | **Não** emitir CSRF de sessão; cookies CSRF de sessão devem expirar junto (clear session-bound CSRF se helper existir; senão próximo login reemite) | Sessão encerrada | y |
| Hydratação GET me na página | RSC `/settings` chama o **mesmo serviço** server-side (não self-HTTP obrigatório); GET handler existe para o client refetch após PATCH | Server-first; um contrato | y |
| Visitante `/settings` | Redirect `/login` | Conta exige autenticação | y |
| `verification` em `/settings` ou `/settings/password` | Redirect `/verify-email` | PATCH/logout-all exigem `session`; password já redireciona | y |
| `session` em `/login` ou `/register` | Comportamento existente (redirect `/` ou returnUrl) — **não** reabrir nesta fatia | Já Verified | y |
| Paths `verification` | Manter `VERIFICATION_ALLOWED_PATHS`: `/verify-email`, `/login`, `/terms`, `/forgot-password`, `/reset-password` | `/settings` **fora** | y |
| Rotas que aplicam guard completo | `/`, `/settings`, `/settings/password` e qualquer layout de conta desta fatia | Expandir helper; novas rotas Fase 2 reutilizam | y |
| Redis flush mid-session | Próximo `getSession` miss → tratar como deslogado; **sem** fallback Bearer ao browser (SC já define); UI/guard redireciona `/login` em rotas protegidas | BFFUI-16 + BFFUI-74 | y |
| Concorrência logout-all | Dois submits: UI pending; API delete-all; BFF só limpa cookie no `204` | Paridade API SP | y |
| PATCH no-op (mesmo nome) | Repassar `200` da API | API não bumpa `updated_at` | y |
| Mensagens UI | pt-BR; preservar `code` da API quando houver; 5xx/504 genérico | Product UI | y |
| `INVALID_CREDENTIALS` logout-all | Erro de campo `current_password` pt-BR genérico (credenciais inválidas) | Paridade change password | y |
| Rate limit UI | `429` + copy distinta **com** e **sem** `Retry-After` (L-053) | Lesson confirmada | y |
| Submit client | `Content-Type: application/json` + `X-CSRF-Token` nas mutations (L-046) | Lesson confirmada | y |
| Métricas / alerta | Contadores in-process `bff_logout_upstream_fail_total` e `bff_logout_redis_fail_total` com getter de teste; **export OTel e alerta de ops = ops-verified / Fase 4** (L-026) | Sem infra fora da suíte | y |
| Observabilidade | Proibido logar Bearer, senha, session ID bruto, HMAC keys | Security §13 | y |
| Sem e-mail ao usuário | Logout/perfil não disparam notificação | Product §3 | y |
| IDs locais | Prefixo `SH-` (não `SP-`) | Evita colisão com Auth API `SP-01…` | y |
| Guard logout (idempotente) | **Não** usar `assertMutationGuard` com `requireSession: true` quando a sessão é miss: Origin **sempre** no POST; CSRF session-mode **somente** se sessão resolvível; miss → `200` + clear cookies **sem** Laravel e **sem** CSRF | `assertMutationGuard` hoje devolve `403` em sessão ausente e quebraria SH-04 | y |
| Allowlist logout `requireSession` | Permanece `true` (documenta happy path / lookup); o **serviço** de logout implementa o guard especial acima | Tabela allowlist não ganha terceiro modo | y |
| Validação local body | JSON malformado, extras ou bounds → **`400`** pt-BR **sem** Laravel; `422` só pass-through upstream | Remove “400 ou 422” | y |
| Sessão ausente em GET/PATCH/logout-all | **`403` `{ "message": "Forbidden." }`** (paridade mutation-guard / change) — **não** `401` JSON | Consistência BFF existente | y |
| Logout Laravel `403`/`429`/`422` | Mesmo tratamento que sucesso local (clear cookie + `200`); **não** incrementar `bff_logout_upstream_fail_total`. Só 5xx/timeout/rede incrementam esse contador | Cookie não pode ficar preso | y |
| Pós-logout CSRF | Helper `clearCsrfCookies` (expira `__Host-fl_csrf` e `__Host-fl_csrf_sid`) em todo logout/logout-all **sucesso local** | Fecha “se helper existir” | y |
| Navegação pós-sucesso UI | `router.push('/login')` imediato; **sem** query `?message=`; copy fica no JSON BFF (paridade change-password) | Fecha “inline ou no destino” | y |
| `ACCOUNT_*` no GET me (RSC `/settings`) | Destroy sessão BFF best-effort + clear cookies + `redirect('/login')` para não loop com `/login` → `/` | Conta suspensa ainda tem cookie `session` | y |
| Paths de conta | `pathname === '/settings'` **ou** `pathname.startsWith('/settings/')` | Cobre `/settings/password` via layout | y |
| `/settings/password` chrome | Passa a usar o mesmo shell autenticado (nav) desta fatia | Evita página de senha órfã | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Zod: `name` 1–120 pós-trim; `current_password` max 128; BFF rejeita JSON malformado/`Content-Type` inválido com `400` sem Laravel; PATCH só `name` |
| Failure / partial-failure states | Origin/CSRF → `403` genérico; logout sempre limpa cookie; logout-all só limpa após `204`; GET/PATCH 401/403/422/429 pass-through; timeout → `504` genérico (**exceto** logout, que ainda conclui localmente) |
| Idempotency / retry / duplicate handling | Segundo logout sem sessão válida → `200` local + cookie limpo, sem Laravel; logout-all não é idempotente após sucesso (precisa novo login); submit UI desabilitado em pending |
| Auth boundaries & rate limits | logout + GET me: `session`\|`verification`; logout-all + PATCH: `session`; CSRF em mutations; GET me sem CSRF; rate limit só upstream |
| Concurrency / ordering | logout-all concorrente: efeito API = zero tokens; BFF clear cookie apenas no `204`; PATCH last-write-wins |
| Data lifecycle / expiry | Cookie/Redis removidos no logout (best-effort Redis); tokens Laravel sujeitos a TTL se revoke falhar; sessão BFF 7d/24h (`session`) ou 24h/1h (`verification`) |
| Observability | Contadores logout fail; sem senha/Bearer/session ID em logs; OTel Fase 4 |
| External-dependency failure | Redis/API down no **logout** não bloqueiam clear cookie; no **logout-all**/PATCH/GET, indisponibilidade Laravel → erro (`504`/`502`) **sem** fingir sucesso (exceto Redis fail **após** logout-all `204`, que ainda clear cookie) |
| State-transition integrity | PATCH não muda e-mail/`status`; logout/logout-all não mudam User persistido além de tokens; BFF não inventa `User.status` |

---

## Entregáveis técnicos (mínimo)

```txt
frontend/
  app/
    page.tsx                         # + shell quando kind session; landing quando guest
    settings/
      page.tsx                       # Perfil: GET me RSC; PATCH name; logout-all; link senha
      page.test.tsx
      layout.tsx                     # opcional: nav autenticada compartilhada
    api/bff/auth/
      logout/route.ts                # POST
      logout/route.test.ts
      logout-all/route.ts            # POST
      logout-all/route.test.ts
      me/route.ts                    # GET + PATCH
      me/route.test.ts
  modules/auth/
    schemas/
      update-profile-schema.ts       # name 1–120
      logout-all-schema.ts           # current_password
    components/
      authenticated-shell.tsx        # nav Início / Conta / Sair
      profile-form.tsx
      logout-button.tsx              # POST logout + CSRF
      logout-all-form.tsx
    bff/
      allowlist.ts                   # + logout, logout-all, GET me, PATCH me
    lib/
      verification-guard.ts          # reutilizar; aplicar em /settings
      session/metrics.ts             # + contadores logout fail
    services/
      bff-logout.ts
      bff-logout-all.ts
      bff-me.ts                      # GET/PATCH orquestração
```

Handlers de **logout** não podem deixar o cookie válido quando o usuário pediu sair. Handlers de **logout-all** não podem limpar cookie se a API recusou a senha.

---

## User Stories

### P1: Logout da sessão atual via BFF ⭐ MVP

**User Story**: Como usuário com sessão completa ou restrita, quero sair só deste browser sem ver o Bearer e mesmo se Redis ou a API falharem.

**Why P1**: BFFUI-70; `docs/security.md` §5.2; `docs/product.md` §3.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/logout` com Origin+CSRF válidos, sessão BFF `kind` `session` ou `verification` resolvível e upstream retorna `204` THEN o handler SHALL chamar Laravel via allowlist com `Authorization: Bearer` somente server-side, invocar `destroySession` best-effort, emitir `clearSessionCookie`, e responder `200` com `{ "data": { "redirect_to": "/login", "message": "Você saiu da conta." } }`.
2. WHEN logout conclui (happy path ou best-effort) THEN headers SHALL incluir `Cache-Control: private, no-store` e o cookie `__Host-fl_session` SHALL estar expirado/removido.
3. WHEN a resposta BFF é inspecionada THEN JSON SHALL NOT conter `token`, `Bearer`, `token_kind`, `token_type`, `expires_at` nem o Bearer plaintext.
4. WHEN Redis `destroySession` falha e o Bearer ainda está em memória THEN o handler SHALL ainda tentar `POST /auth/logout`, SHALL still `clearSessionCookie`, SHALL responder `200` com o mesmo envelope de sucesso, e SHALL incrementar `bff_logout_redis_fail_total`.
5. WHEN Laravel está indisponível, aborta em 10s ou retorna 5xx THEN o handler SHALL ainda `destroySession` best-effort, `clearSessionCookie`, responder `200` sucesso local, e incrementar `bff_logout_upstream_fail_total`.
6. WHEN o cookie está ausente, malformado ou o Redis miss (sem Bearer) THEN o handler SHALL exigir `Origin` válido, SHALL NOT exigir CSRF, SHALL NOT chamar Laravel, SHALL `clearSessionCookie` + `clearCsrfCookies`, e SHALL responder `200` com `redirect_to: "/login"`.
7. WHEN Laravel retorna `401` (token já revogado) THEN o handler SHALL `destroySession` best-effort, `clearSessionCookie`, e SHALL responder `200` sucesso local (**sem** incrementar `bff_logout_upstream_fail_total`).
8. WHEN Origin ou CSRF falham THEN SHALL `403` `{ "message": "Forbidden." }` **sem** Laravel e **sem** destroy (sessão permanece — pedido não autenticado como mutation válida).
9. WHEN `session.kind` é suportado (`session`\|`verification`) THEN SHALL NOT responder `403` só por kind.

**Independent Test**: Vitest: upstream `204`; Redis throw + Laravel ok; Laravel timeout; Redis miss sem fetch; `401` upstream; Origin fail; assert Set-Cookie Max-Age=0 e contadores.

**Requirement IDs**: BFFUI-70, SH-01, SH-02, SH-03, SH-04, SH-05

---

### P1: Logout-all com senha via BFF ⭐ MVP

**User Story**: Como usuário com sessão completa, quero encerrar todas as sessões confirmando a senha atual.

**Why P1**: BFFUI-71; AUTH-31.

**Acceptance Criteria**:

1. WHEN `POST /api/bff/auth/logout-all` com CSRF+Origin, `kind: session`, body `{ "current_password": "<correta>" }` e upstream `204` THEN o handler SHALL enviar somente `{ current_password }` ao Laravel, `destroySession` best-effort, `clearSessionCookie`, e responder `200` com `{ "data": { "redirect_to": "/login", "message": "Todas as sessões foram encerradas. Faça login para continuar." } }`.
2. WHEN upstream retorna `401 INVALID_CREDENTIALS` THEN o BFF SHALL repassar status e corpo **sem** destroy/clear cookie.
3. WHEN `session.kind !== 'session'` THEN SHALL `403` `{ "message": "Forbidden." }` **sem** Laravel e **sem** avaliar senha.
4. WHEN body omite `current_password`, excede 128, não é JSON, ou contém campos extras THEN SHALL `400` local pt-BR **sem** Laravel e **sem** destroy.
5. WHEN Origin/CSRF falham THEN SHALL `403` sem Laravel.
6. WHEN upstream `429` THEN SHALL repassar status, corpo e `Retry-After` quando presente, **sem** destroy.
7. WHEN upstream timeout/5xx THEN SHALL `504`/`5xx` genérico **sem** destroy/clear.
8. WHEN upstream `204` e `destroySession` falha THEN SHALL ainda `clearSessionCookie` + `200` sucesso.

**Independent Test**: Vitest senha ok → cookie limpo; senha errada → cookie intacto; kind `verification` → zero fetch; 429 com e sem Retry-After.

**Requirement IDs**: BFFUI-71, SH-06, SH-07, SH-08, SH-09

---

### P1: GET e PATCH `/me` via BFF ⭐ MVP

**User Story**: Como cliente do browser oficial, quero ler meu User e alterar só o nome, com Bearer só no servidor.

**Why P1**: BFFUI-72; AUTH-34/35.

**Acceptance Criteria**:

1. WHEN `GET /api/bff/auth/me` com sessão `session` ou `verification` válida THEN o handler SHALL chamar Laravel GET `/me` com Bearer server-side, **sem** exigir CSRF/Origin, e repassar `200` `UserResponse` com `Cache-Control: private, no-store`.
2. WHEN GET me THEN o JSON SHALL conter `data` com campos OpenAPI do User (`id`, `name`, `email`, `status`, `email_verified_at`, `terms_version`, `terms_accepted_at`, `created_at`, `updated_at`) e SHALL NOT conter Bearer.
3. WHEN `PATCH /api/bff/auth/me` com CSRF+Origin, `kind: session` e `{ "name": "<válido>" }` THEN SHALL enviar somente `{ name }` trimado, e repassar `200` `UserResponse`.
4. WHEN PATCH com `kind !== 'session'` THEN SHALL `403` sem Laravel.
5. WHEN PATCH inclui `email` ou qualquer campo além de `name` THEN SHALL `400` local **sem** Laravel (zero fetch) e o e-mail persistido SHALL permanecer inalterado.
6. WHEN `name` vazio após trim ou com mais de 120 caracteres THEN SHALL bloquear no client e, se chegar ao BFF, `400` local sem Laravel.
7. WHEN GET/PATCH sem sessão válida THEN o handler SHALL `403` `{ "message": "Forbidden." }` **sem** Laravel.
8. WHEN Origin/CSRF falham no PATCH THEN SHALL `403` sem Laravel.
9. WHEN GET com `verification` THEN SHALL suceder se upstream `200` (status `pending_verification` visível no envelope).

**Independent Test**: Vitest GET session/verification; PATCH name; PATCH extra field; PATCH verification 403; GET sem CSRF header ainda 200.

**Requirement IDs**: BFFUI-72, SH-10, SH-11, SH-12, SH-13

---

### P1: UI de perfil (somente nome) ⭐ MVP

**User Story**: Como usuário com sessão completa, quero ver meu e-mail (somente leitura) e alterar meu nome em pt-BR a partir de 360px.

**Why P1**: BFFUI-73; product §3.

**Acceptance Criteria**:

1. WHEN `GET /settings` com `kind: session` THEN a página SHALL renderizar nome editável, e-mail visível e **não** editável, link para `/settings/password` (“Alterar senha”), seção logout-all e ações em pt-BR, usando primitivos `shared`.
2. WHEN a página carrega THEN SHALL hidratar o formulário com `name` e `email` do User (serviço GET me server-side).
3. WHEN o usuário submete nome válido THEN o client SHALL `PATCH /api/bff/auth/me` com `Content-Type: application/json`, header `X-CSRF-Token` e body `{ name }` (trim).
4. WHEN PATCH `200` THEN a UI SHALL refletir o novo nome **sem** alterar o e-mail exibido.
5. WHEN validação Zod falha THEN SHALL erros de campo pt-BR **sem** chamar o BFF.
6. WHEN HTML/fetch são inspecionados THEN Bearer SHALL NOT aparecer.

**Independent Test**: RTL + MSW; e-mail permanece o mesmo após PATCH; submit inclui Content-Type + CSRF.

**Requirement IDs**: BFFUI-73, SH-14, SH-15

---

### P1: UI de logout e logout-all ⭐ MVP

**User Story**: Como usuário autenticado, quero sair desta sessão pela nav e encerrar todas as sessões em `/settings` com senha.

**Why P1**: BFFUI-70/71; product §3.

**Acceptance Criteria**:

1. WHEN o shell autenticado está visível (`/` ou `/settings` com `session`) THEN SHALL existir ação “Sair” que `POST /api/bff/auth/logout` com JSON + `X-CSRF-Token`.
2. WHEN logout BFF responde `200` THEN a UI SHALL navegar para `/login` (router) e o usuário SHALL aparecer deslogado no próximo GET protegido.
3. WHEN `/settings` com `session` THEN SHALL existir formulário logout-all com `current_password` e submit `POST /api/bff/auth/logout-all` (Content-Type + CSRF).
4. WHEN logout-all `200` THEN SHALL `router.push('/login')` imediatamente (**sem** query de flash).
5. WHEN logout-all `401 INVALID_CREDENTIALS` THEN SHALL erro de campo senha pt-BR e o usuário SHALL permanecer em `/settings` autenticado.
6. WHEN `verification` THEN `/settings` redireciona `/verify-email`; o shell de `/` e `/settings` (kind `session`) tem “Sair”; `/verify-email` SHALL incluir o mesmo `LogoutButton` (handler aceita `verification`).

**Independent Test**: RTL logout nav → `/login`; logout-all senha errada permanece na página; verify-email tem Sair.

**Requirement IDs**: BFFUI-70, BFFUI-71, SH-16, SH-17

---

### P1: Guards de rota autenticada / restrita ⭐ MVP

**User Story**: Como plataforma, quero que convidados não vejam conta, que `verification` não entre no shell, e que `session` use o placeholder autenticado.

**Why P1**: BFFUI-74; BFFUI-52 expansão.

**Acceptance Criteria**:

1. WHEN visitante sem sessão acessa `/settings` THEN SHALL `redirect('/login')`.
2. WHEN `kind: verification` acessa `/`, `/settings` ou `/settings/password` THEN SHALL `redirect('/verify-email')` (password já cobre `/settings/password`; esta fatia garante `/settings` e `/` via helper compartilhado).
3. WHEN `kind: session` acessa `/verify-email` THEN SHALL `redirect('/')` (já EV; regressão).
4. WHEN `kind: session` acessa `/` THEN SHALL renderizar shell autenticado (nav + placeholder) **não** o CTA anônimo “Começar” como único chrome.
5. WHEN visitante acessa `/` THEN SHALL permanecer a landing pública (sem forçar login).
6. WHEN Redis flush faz `getSession` retornar null em `/settings` THEN SHALL tratar como visitante → `/login` **sem** devolver Bearer ao browser.
7. WHEN `VERIFICATION_ALLOWED_PATHS` é inspecionado THEN SHALL **não** incluir `/settings`.

**Independent Test**: Vitest helper + testes de página com sessão mock (null / verification / session); flush simulado = null session.

**Requirement IDs**: BFFUI-74, SH-18, SH-19, SH-20

---

### P1: Privacidade de credenciais ⭐ MVP

**User Story**: Como plataforma, senha e Bearer não vazam em telemetria nem no browser.

**Why P1**: Security §13; paridade SP-12 / EV-19.

**Acceptance Criteria**:

1. WHEN logout-all processa `current_password` THEN plaintext SHALL NOT aparecer em logs, exceptions serializadas ao browser, métricas ou HTML.
2. WHEN qualquer handler desta fatia responde THEN Bearer SHALL NOT aparecer em JSON, `Set-Cookie` (exceto session id opaco) ou HTML.
3. WHEN testes usam sentinelas THEN asserts SHALL varrer body/HTML/storage simulado.

**Independent Test**: Vitest sentinel password/Bearer ausentes de `JSON.stringify(response)`.

**Requirement IDs**: SH-21

---

### P1: Erros, rate limit e validação ⭐ MVP

**User Story**: Como usuário, quero feedback claro em 422/429/5xx sem enumerar regras internas de CSRF.

**Why P1**: Paridade login/password; L-053.

**Acceptance Criteria**:

1. WHEN PATCH/logout-all recebem `422 VALIDATION_FAILED` THEN UI SHALL mapear `errors` para campos pt-BR.
2. WHEN `429` **com** `Retry-After` THEN UI SHALL incluir a informação de espera na copy.
3. WHEN `429` **sem** `Retry-After` THEN UI SHALL copy genérica de limite **distinta** da copy com Retry-After.
4. WHEN GET/PATCH/logout-all timeout THEN UI/handler SHALL mensagem genérica pt-BR (`504`).
5. WHEN Origin/CSRF falham THEN UI SHALL mensagem genérica de permissão/proibido **sem** dizer “CSRF” ou “Origin”.

**Independent Test**: RTL/MSW 429 com e sem header; 403 genérico.

**Requirement IDs**: SH-22, SH-23

---

### P2: Allowlist, métricas e descoberta de testes

**User Story**: Como mantenedor, quero entradas estáticas na allowlist, contadores testáveis e suíte descoberta pelo Makefile.

**Why P2**: Auditabilidade; L-026.

**Acceptance Criteria**:

1. WHEN `AUTH_BFF_ALLOWLIST` é inspecionada THEN SHALL conter as quatro entradas (logout, logout-all, GET me, PATCH me) além das sete já existentes.
2. WHEN Redis ou upstream falham no logout THEN os getters de teste dos contadores SHALL refletir o incremento (sem exigir backend OTel).
3. WHEN `make test-frontend` roda THEN testes em `app/api/bff/auth/logout`, `logout-all`, `me`, `app/settings` e schemas desta fatia SHALL ser descobertos.
4. WHEN schemas são testados THEN `name` espelha 1–120 e `current_password` max 128.

**Independent Test**: Unit allowlist length/paths; unit metrics; `make test-frontend` discovery.

**Requirement IDs**: SH-24, SH-25, SH-26, SH-27, SH-28

---

## Edge Cases

- WHEN segundo clique em Sair após cookie já limpo THEN logout BFF responde `200` local sem Laravel.
- WHEN duas abas fazem logout THEN ambas terminam deslogadas; segundo Laravel pode ser `401` tratado como sucesso local.
- WHEN logout-all e change-password em voo THEN ambos revogam tokens; BFF de cada um limpa cookie só no sucesso da **sua** API.
- WHEN PATCH nome idêntico THEN `200` e UI permanece consistente.
- WHEN GET me `403 ACCOUNT_SUSPENDED` ou `ACCOUNT_PENDING_DELETION` na RSC `/settings` THEN SHALL destroy sessão BFF best-effort, limpar cookies de sessão/CSRF e `redirect('/login')` **sem** vazar Bearer.
- WHEN usuário `verification` chama GET me (ex. futuro) THEN `200` com `pending_verification`; não desbloqueia `/settings`.
- WHEN `name` só espaços THEN client bloqueia; BFF `400` se chegar.
- WHEN logout Laravel `429` THEN ainda clear cookie + `200` local (pedido foi encerrar **esta** sessão BFF; throttle write não deve prender o cookie). Incrementar `bff_logout_upstream_fail_total` **não** — `429` é recusa da API, não indisponibilidade: **tratar `429` no logout como best-effort sucesso local** (cookie sai) **sem** contar como fail de infra; opcionalmente ainda tentou revoke e falhou por throttle — cookie limpo prevalece.
- WHEN logout-all `429` THEN **não** limpar cookie (usuário continua autenticado para retry).

---

## Requirement Traceability

| Requirement ID | Story | Phase | Status |
| --- | --- | --- | --- |
| BFFUI-70 | P1: Logout BFF + UI Sair | Execute | ✅ Verified |
| BFFUI-71 | P1: Logout-all BFF + UI | Execute | ✅ Verified |
| BFFUI-72 | P1: GET/PATCH me | Execute | ✅ Verified |
| BFFUI-73 | P1: UI perfil | Execute | ✅ Verified |
| BFFUI-74 | P1: Guards + shell | Execute | ✅ Verified |
| SH-01 | P1: Logout happy path | Execute | ✅ Verified |
| SH-02 | P1: Logout Redis fail | Execute | ✅ Verified |
| SH-03 | P1: Logout upstream fail | Execute | ✅ Verified |
| SH-04 | P1: Logout sem sessão / miss | Execute | ✅ Verified |
| SH-05 | P1: Logout kinds + CSRF fail | Execute | ✅ Verified |
| SH-06 | P1: Logout-all sucesso | Execute | ✅ Verified |
| SH-07 | P1: Logout-all senha errada | Execute | ✅ Verified |
| SH-08 | P1: Logout-all kind / validação | Execute | ✅ Verified |
| SH-09 | P1: Logout-all 429 / 5xx / Redis pós-204 | Execute | ✅ Verified |
| SH-10 | P1: GET me | Execute | ✅ Verified |
| SH-11 | P1: PATCH name | Execute | ✅ Verified |
| SH-12 | P1: PATCH kind / extras | Execute | ✅ Verified |
| SH-13 | P1: GET/PATCH auth fail | Execute | ✅ Verified |
| SH-14 | P1: UI `/settings` render | Execute | ✅ Verified |
| SH-15 | P1: UI PATCH + CSRF headers | Execute | ✅ Verified |
| SH-16 | P1: UI logout nav | Execute | ✅ Verified |
| SH-17 | P1: UI logout-all + Sair em verify-email | Execute | ✅ Verified |
| SH-18 | P1: Guard visitante `/settings` | Execute | ✅ Verified |
| SH-19 | P1: Guard verification / session shell | Execute | ✅ Verified |
| SH-20 | P1: Redis flush → login | Execute | ✅ Verified |
| SH-21 | P1: Privacidade | Execute | ✅ Verified |
| SH-22 | P1: 422 / 403 copy | Execute | ✅ Verified |
| SH-23 | P1: 429 com e sem Retry-After | Execute | ✅ Verified |
| SH-24 | P2: Allowlist 4 entradas | Execute | ✅ Verified |
| SH-25 | P2: Contadores logout | Execute | ✅ Verified |
| SH-26 | P2: Test discovery | Execute | ✅ Verified |
| SH-27 | P2: Schema name | Execute | ✅ Verified |
| SH-28 | P2: Schema logout-all | Execute | ✅ Verified |

**ID format:** `SH-NN` + catálogo `BFFUI-70…74`  
**Coverage:** 33 total, 33 mapped to tasks ✅

---

## Success Criteria

- [x] Usuário `session` altera o nome em `/settings`, vê e-mail imutável, sai pela nav e/ou encerra todas as sessões com senha, caindo em `/login` sem Bearer no browser.
- [x] Logout com Redis ou Laravel down ainda remove o cookie; suíte incrementa os contadores correspondentes; **não** há fila de reconciliação.
- [x] Logout-all com senha errada **não** desloga; com senha certa desloga e a API foi chamada uma vez com `{ current_password }`.
- [x] `verification` não acessa `/settings`; GET me e logout continuam permitidos no BFF; há “Sair” em `/verify-email`.
- [x] `/` distingue landing (guest) e shell (session); flush Redis em rota protegida equivale a visitante.
- [x] `make test-frontend` passa; cobertura ≥75% nos arquivos novos; 429 testado com e sem `Retry-After`; submits assertam Content-Type + CSRF.
- [x] Playwright **não** é critério desta fatia.

---

## Referências

| Documento | Uso |
| --- | --- |
| `.specs/features/auth/session-and-profile/spec.md` | AUTH-30…36; kinds; bounds de nome; logout-all senha |
| `.specs/features/bff-auth/session-core/spec.md` | Cookie, destroy, Redis miss, métricas in-process |
| `.specs/features/bff-auth/csrf-proxy/spec.md` | GET sem CSRF; allowlist; Origin em mutations |
| `.specs/features/bff-auth/login/spec.md` | `redirect_to` default `/`; envelope BFF |
| `.specs/features/bff-auth/email-verification/spec.md` | `VERIFICATION_ALLOWED_PATHS`; EV-16/17 |
| `.specs/features/bff-auth/password/spec.md` | `/settings/password`; destroy pós-sucesso |
| `docs/openapi.yaml` | `logout`, `logoutAll`, `getCurrentUser`, `updateCurrentUser` |
| `docs/product.md` §3, §8 | Perfil só nome; logout; UI pt-BR 360px |
| `docs/security.md` §5.2, §5.3, §13 | Best-effort logout; allowlist; sem segredos em log |
| `docs/testing.md` §4, §6.2 | Cobertura; casos BFF |
| `docs/api.md` §3.1 | Kinds e revogação |
| AD-017 | Prefixo `/api/bff/...` |
