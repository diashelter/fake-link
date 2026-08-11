# BFF Auth — CSRF e proxy

**Status:** Approved — 2026-08-11  
**Fatia:** 3 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-20 … BFFUI-24  
**Requirement IDs (fatia):** CP-01 … CP-15  
**Depende de:** [session-core](../session-core/spec.md)

## Problem Statement

Mutations do browser oficial precisam de proteção CSRF e o BFF não pode ser um proxy genérico. Sem um framework compartilhado de `Origin`, double-submit CSRF, allowlist estática e sanitização de `returnUrl`, cada Route Handler Auth reinventaria regras de segurança — aumentando risco de open redirect, bypass de CSRF ou encaminhamento upstream arbitrário.

Esta fatia entrega bibliotecas e helpers reutilizáveis usados pelas fatias 4–8 (login, register, verify, password, session-shell). Não entrega handlers de produto.

## Goals

- [ ] Validar `Origin` exato (App host HTTPS) em mutations; rejeitar ausência, `null` e divergência **antes** de chamar Laravel.
- [ ] CSRF double-submit: token vinculado à sessão (ou nonce pré-auth) por HMAC; cookie + header; comparação em tempo constante.
- [ ] Allowlist estática `(method, bffPath) → upstream Laravel`; parâmetros de request **nunca** escolhem URL upstream.
- [ ] `returnUrl` / redirect pós-auth: somente caminhos internos seguros; rejeição ou fallback para default interno.
- [ ] Helper de resposta privada BFF: `Cache-Control: private, no-store`.
- [ ] Helper de chamada upstream allowlisted com timeout 10s (para fatias seguintes).
- [ ] Vitest cobre matriz Origin / CSRF / returnUrl / allowlist; tentativas de open redirect e upstream arbitrário falham.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Handlers de produto (login, register, logout, etc.) | Fatias 4–8 — **consomem** estes helpers |
| UI e páginas Auth | Fatias 4–8 |
| Entradas reais na allowlist além de stubs de teste | Preenchidas nas fatias 4–8 ao criar cada handler |
| Rate limiting BFF adicional | API Laravel já limita upstream; duplicar chaves Redis no BFF fica fora desta fatia |
| Playwright / axe gate completo | Fatia `e2e-security-gate` |
| Middleware global Next.js obrigatório | Guards são funções composáveis invocadas pelos handlers; middleware global fica opcional no Design |
| ETag / conflitos de perfil | Fatia `session-shell` |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| App origin config | Env `BFF_APP_ORIGIN`; fallback para `NEXT_PUBLIC_APP_URL` quando ausente | `.env.example` já define `https://app.localhost`; comparação exata case-sensitive do origin completo (`scheme://host[:port]`) | y |
| CSRF em GET idempotente | **Não** exigir CSRF nem `Origin` em GET de leitura segura; exigir em `POST`/`PATCH`/`DELETE` | `docs/security.md` §5.3 foca mutations; `SameSite=Lax` cobre navegação top-level | y |
| Transporte double-submit | Cookie `__Host-fl_csrf` (**não** HttpOnly) + header `X-CSRF-Token` com valor idêntico | Padrão double-submit; server-first pode também embutir token em form hidden, mas header+cookie é o contrato testável | y |
| Prefixo `__Host-` no CSRF | **Sim** — `__Host-fl_csrf` com `Secure`, `SameSite=Lax`, `Path=/`, sem `Domain` | Paridade com cookie de sessão; reforça escopo | y |
| Derivação do token CSRF (autenticado) | `base64url(HMAC-SHA256(BFF_CSRF_HMAC_KEY, sessionId))` | Security §5.3 exige vínculo à sessão por HMAC; material distinto de lookup Redis (§5.1, §14) | y |
| Derivação do token CSRF (pré-auth) | Cookie HttpOnly `__Host-fl_csrf_sid` (256-bit nonce) emitido pelo BFF; token = `base64url(HMAC-SHA256(BFF_CSRF_HMAC_KEY, csrfSid))` | Login/register são mutations públicas sem Bearer; Origin + double-submit ainda obrigatórios | y |
| Rotação CSRF | Token autenticado recalcula quando `sessionId` rotaciona (hook exposto para session-core); nonce pré-auth rotaciona ao emitir novo cookie | Evita replay após rotate | y |
| Status HTTP em falha Origin/CSRF | `403` + JSON genérico `{ "message": "Forbidden." }`; **sem** indicar qual verificação falhou; **sem** chamar Laravel | Seed + security: não vazar regra interna | y |
| Sessão obrigatória | Por entrada da allowlist: `requireSession: true \| false`; default `true` para mutations autenticadas futuras | Login/register terão `false`; change-password `true` | y |
| Allowlist inicial em código | Tabela tipada **vazia** em runtime + entradas **somente** em testes/probe dev | Evita proxy prematuro; fatias 4–8 registram rotas reais | y |
| Upstream base URL | Env `LARAVEL_INTERNAL_URL` (ex.: `http://backend/api/v1` no compose) | BFF chama Laravel na rede interna Docker; browser nunca vê esse host | y |
| Timeout upstream | 10s (`AbortSignal.timeout(10_000)`) | `docs/architecture.md` §8 | y |
| Default `returnUrl` seguro | `/` quando input ausente, inválido ou malicioso | Landing autenticada placeholder até Links (Fase 2) | y |
| Probe de teste | Route Handler `app/api/bff/_probe/...` registrado **somente** quando `NODE_ENV !== 'production'` | Espelha probes Auth API; Vitest e dev local | y |
| Local do código | `frontend/modules/auth/bff/` (guards, allowlist, upstream, return-url, headers) | Domínio Auth; `shared` permanece UI/query | y |
| Chave CSRF env | `BFF_CSRF_HMAC_KEY` — material separado de `BFF_SESSION_*` | Security §14 — finalidades distintas | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | `Origin` string exata vs `BFF_APP_ORIGIN`; `returnUrl` max 2048 chars; path-only; normalização e revalidação pós-decode |
| Failure / partial-failure states | Origin/CSRF inválidos → 403 imediato; timeout upstream → 504 ou 502 genérico **sem** Bearer no body; upstream 4xx/5xx repassados com headers privados |
| Idempotency / retry / duplicate | N/A no framework; handlers futuros usam idempotência da API quando aplicável |
| Auth boundaries & rate limits | Guard compõe `requireSession` + CSRF + Origin; rate limit confia na API nesta fatia |
| Concurrency / ordering | Comparação CSRF constant-time; emissão de token determinística dado mesmo `sessionId`/nonce |
| Data lifecycle / expiry | Cookie pré-auth `__Host-fl_csrf_sid` max-age 1h; token autenticado invalida com rotate/expiração de sessão |
| Observability | Logs/metrics **sem** Bearer, `sessionId` bruto, `BFF_CSRF_HMAC_KEY`, ou valor completo do token CSRF |
| External-dependency failure | Timeout/abort Laravel em 10s; erro genérico ao browser; sem fallback de token |
| State-transition integrity | Hook `issueCsrfForSession(sessionId)` chamado após create/rotate de sessão (session-core) |

---

## Entregáveis técnicos

### Módulos (mínimo)

```txt
frontend/modules/auth/bff/
  origin.ts              # validateMutationOrigin(request)
  csrf.ts                # issue/validate double-submit; HMAC bind
  return-url.ts          # sanitizeReturnUrl(input, fallback?)
  allowlist.ts           # tipo AllowlistEntry + tabela vazia + lookup
  upstream.ts            # callAllowlistedUpstream(entry, ctx, init)
  private-response.ts    # jsonWithPrivateCache, applyPrivateCacheHeaders
  mutation-guard.ts      # compose Origin + CSRF + optional session
  index.ts               # re-exports públicos da fatia
```

### Contrato `AllowlistEntry`

| Campo | Tipo | Descrição |
| --- | --- | --- |
| `method` | `'GET' \| 'POST' \| 'PATCH' \| 'DELETE'` | Método HTTP do Route Handler BFF |
| `bffPath` | `string` | Path absoluto do handler (ex.: `/api/bff/auth/login`) |
| `upstreamMethod` | mesmo union | Método na API Laravel (geralmente igual) |
| `upstreamPath` | `string` | Path fixo Laravel (ex.: `/auth/login`) relativo a `LARAVEL_INTERNAL_URL` |
| `requireSession` | `boolean` | Se true, exige cookie de sessão válido + CSRF derivado de `sessionId` |
| `requireCsrf` | `boolean` | Default true para mutations; false só para GET allowlisted futuros |

Lookup por `(method, bffPath)` retorna entrada ou `undefined`. **Não existe** API que aceite URL upstream como string arbitrária do caller.

### Contrato CSRF

| Artefato | Atributos |
| --- | --- |
| Cookie token `__Host-fl_csrf` | `Secure`, `SameSite=Lax`, `Path=/`, **sem** `HttpOnly`, **sem** `Domain` |
| Cookie sid pré-auth `__Host-fl_csrf_sid` | `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, `Max-Age=3600` |
| Header mutation | `X-CSRF-Token: <valor idêntico ao cookie __Host-fl_csrf>` |
| Comparação | `timingSafeEqual` (ou equivalente) entre header e cookie |

### Regras `sanitizeReturnUrl`

Entrada aceita **somente** se, após normalização:

1. Resultado non-empty string começa com exatamente um `/`.
2. **Não** começa com `//` (protocol-relative).
3. **Não** contém `://`, `\`, `@`, ou bytes nulos.
4. Decodificação percent-encoding (até duas passagens) não produz violação das regras acima.
5. Comprimento ≤ 2048.

Caso contrário: retorna fallback (`/` por default). Query string e fragmento internos (`/path?q=1#x`) são permitidos se o path base for seguro.

### Env vars (documentar em `.env.example` frontend/compose)

| Variável | Obrigatória | Exemplo local |
| --- | --- | --- |
| `BFF_APP_ORIGIN` | Recomendada (fallback `NEXT_PUBLIC_APP_URL`) | `https://app.localhost` |
| `BFF_CSRF_HMAC_KEY` | Sim em runtime BFF | 32+ bytes base64/hex |
| `LARAVEL_INTERNAL_URL` | Sim para upstream helper | `http://backend/api/v1` |

---

## User Stories

### P1: Validar Origin em mutations ⭐ MVP

**User Story**: Como mantenedor de segurança, quero que mutations do browser oficial só sejam aceitas com `Origin` exatamente igual ao App host, para bloquear requests cross-site antes de qualquer lógica upstream.

**Why P1**: BFFUI-21; requisito explícito em `docs/security.md` §5.3 e `docs/testing.md` §6.2.

**Acceptance Criteria**:

1. WHEN um `POST`/`PATCH`/`DELETE` chega **sem** header `Origin` THEN `validateMutationOrigin` SHALL retornar falha e o handler SHALL responder `403` com `{ "message": "Forbidden." }` sem invocar Laravel.
2. WHEN `Origin` é literalmente `null` THEN SHALL falhar com o mesmo `403` genérico.
3. WHEN `Origin` difere de `BFF_APP_ORIGIN` (incluindo scheme, host, porta) THEN SHALL falhar com `403` genérico.
4. WHEN `Origin` é exatamente igual a `BFF_APP_ORIGIN` THEN `validateMutationOrigin` SHALL retornar sucesso.
5. WHEN o método é `GET` idempotente de leitura THEN Origin SHALL NOT ser exigido por default (guard de mutation only).

**Independent Test**: Vitest unitário com `Request` sintéticos cobrindo missing/null/wrong/exact match.

**Requirement IDs**: BFFUI-21, CP-01, CP-02

---

### P1: CSRF double-submit vinculado à sessão ⭐ MVP

**User Story**: Como mantenedor, quero double-submit CSRF com token derivado por HMAC da sessão (ou nonce pré-auth), comparado em tempo constante, para impedir mutations forjadas mesmo com `SameSite=Lax`.

**Why P1**: BFFUI-22; núcleo de `docs/security.md` §5.3.

**Acceptance Criteria**:

1. WHEN uma mutation allowlisted com `requireCsrf: true` chega **sem** header `X-CSRF-Token` ou **sem** cookie `__Host-fl_csrf` THEN o guard SHALL responder `403` genérico sem chamar Laravel.
2. WHEN header e cookie existem mas diferem THEN SHALL responder `403` usando comparação em tempo constante.
3. WHEN `requireSession: true` e sessão válida com `sessionId` THEN o token esperado SHALL ser `base64url(HMAC-SHA256(BFF_CSRF_HMAC_KEY, sessionId))` e SHALL aceitar request se header === cookie === token esperado.
4. WHEN `requireSession: false` (pré-auth) e cookie `__Host-fl_csrf_sid` válido THEN token SHALL ser HMAC do sid e SHALL aceitar double-submit correspondente.
5. WHEN `sessionId` é rotacionado e `issueCsrfForSession(newId)` é chamado THEN token anterior SHALL deixar de ser aceito para aquela sessão.
6. WHEN qualquer valor de CSRF é logado THEN logs SHALL NOT conter token completo, sid, ou chave HMAC.

**Independent Test**: Vitest com sid/sessionId fixos em teste; asserts de timing-safe path (mock/spy); mutação de token rejeitada.

**Requirement IDs**: BFFUI-22, CP-03, CP-04, CP-05, CP-06, CP-07

---

### P1: Allowlist estática e anti-proxy genérico ⭐ MVP

**User Story**: Como desenvolvedor, quero que o BFF só possa chamar upstream Laravel por entradas explícitas na allowlist, para que parâmetros de usuário nunca escolham destino HTTP.

**Why P1**: BFFUI-20; `docs/architecture.md` §8 proíbe proxy genérico.

**Acceptance Criteria**:

1. WHEN código de produção inspeciona `allowlist.ts` THEN a tabela exportada SHALL estar vazia (length 0) — rotas reais entram nas fatias 4–8.
2. WHEN testes registram entrada stub `(POST, /api/bff/_probe/mutate) → POST /auth/login` THEN lookup SHALL resolver upstream fixo.
3. WHEN `callAllowlistedUpstream` é invocado com entrada **não** presente na tabela THEN SHALL throw/retornar erro **antes** de `fetch` (impossível chamar URL arbitrária via API pública do helper).
4. WHEN assinatura TypeScript de `callAllowlistedUpstream` é inspecionada THEN primeiro argumento SHALL ser `AllowlistEntry` (ou chave tipada), **não** `string` URL livre.
5. WHEN probe dev/test existe THEN SHALL compilar somente fora de `production` (guard por `NODE_ENV` ou arquivo excluído do build prod — decisão no Design).

**Independent Test**: Vitest com tabela in-memory de teste; assert que `fetch` mock recebe URL montada apenas de `LARAVEL_INTERNAL_URL + upstreamPath` fixo.

**Requirement IDs**: BFFUI-20, CP-08, CP-09

---

### P1: Sanitizar returnUrl ⭐ MVP

**User Story**: Como usuário autenticando, quero que redirects pós-login usem apenas caminhos internos seguros, para não cair em open redirect.

**Why P1**: BFFUI-23; `docs/testing.md` §6.2.

**Acceptance Criteria**:

1. WHEN `sanitizeReturnUrl('/dashboard')` THEN SHALL retornar `/dashboard`.
2. WHEN input é `https://evil.com/x`, `//evil.com/x`, `/\\evil.com`, `/%2f%2fevil.com`, ou contém `@`/`%00` THEN SHALL retornar fallback `/` (ou fallback explícito passado).
3. WHEN input é `undefined`, `null`, ou string vazia THEN SHALL retornar fallback `/`.
4. WHEN input excede 2048 caracteres THEN SHALL retornar fallback `/`.
5. WHEN `/login?returnUrl=%2Flinks` é processado pelo helper no query param THEN path decodificado `/links` SHALL ser aceito.

**Independent Test**: Vitest table-driven com vetor OWASP open-redirect (absolute, protocol-relative, encoding dupla, backslash).

**Requirement IDs**: BFFUI-23, CP-10, CP-11

---

### P1: Respostas privadas sem cache ⭐ MVP

**User Story**: Como mantenedor, quero header de cache consistente em respostas privadas do BFF para evitar vazamento via cache intermediário.

**Why P1**: BFFUI-24; `docs/security.md` §5.3.

**Acceptance Criteria**:

1. WHEN `applyPrivateCacheHeaders(response)` é aplicado THEN `Cache-Control` SHALL ser exatamente `private, no-store` (ordem dos diretivos pode variar; ambos presentes).
2. WHEN `jsonWithPrivateCache(body, init)` emite JSON THEN response SHALL incluir `Cache-Control: private, no-store` e `Content-Type: application/json`.
3. WHEN resposta de erro 403 do mutation guard usa helper THEN SHALL incluir `private, no-store`.

**Independent Test**: Vitest assert headers em `Response`/`NextResponse`.

**Requirement IDs**: BFFUI-24, CP-12

---

### P2: Guard composto e chamada upstream ⭐

**User Story**: Como desenvolvedor das fatias Auth seguintes, quero um guard único que aplique Origin + CSRF + sessão opcional e um helper de upstream com timeout, para implementar handlers com uma linha de pré-checagem.

**Why P2**: Reduz erro humano nas fatias 4–8; materializa orçamento 10s.

**Acceptance Criteria**:

1. WHEN `assertMutationGuard(request, entry, sessionCtx)` é chamado com entrada `requireSession: true` sem sessão THEN SHALL retornar `403` privado sem upstream.
2. WHEN guard passa e `callAllowlistedUpstream` executa THEN fetch SHALL usar `Authorization: Bearer <plaintext só em memória>` quando sessão presente.
3. WHEN upstream não responde em 10s THEN SHALL abortar e retornar erro genérico (`502` ou `504`) com `private, no-store`, **sem** Bearer no corpo.
4. WHEN upstream retorna 401/422 THEN BFF SHALL repassar status/body sanitizado (sem injetar Bearer) e aplicar headers privados.

**Independent Test**: Vitest + MSW/fetch mock com relógio; timeout simulado; assert ausência de Bearer em JSON retornado ao caller browser-facing.

**Requirement IDs**: CP-13, CP-14

---

## Edge Cases

- WHEN `BFF_APP_ORIGIN` e `Origin` diferem só por trailing slash (`https://app.localhost/` vs sem) THEN SHALL tratar como divergente (match exato) — normalização fica fora de escopo.
- WHEN request traz `Origin` válido e `Referer` malicioso THEN decisão SHALL depender **somente** de `Origin` (Referer ignorado).
- WHEN mutation tem CSRF válido mas sessão expirada e `requireSession: true` THEN SHALL retornar `403` genérico (não 401), sem distinguir CSRF vs sessão no body.
- WHEN cookie `__Host-fl_csrf` presente mas header ausente (classic CSRF) THEN SHALL falhar.
- WHEN atacante fixa header CSRF sem cookie correspondente THEN SHALL falhar.
- WHEN allowlist tem `GET` futuro com `requireCsrf: false` THEN guard SHALL pular CSRF mas ainda aplicar sessão se `requireSession: true`.
- WHEN `LARAVEL_INTERNAL_URL` ausente em teste de upstream THEN helper SHALL falhar fast com erro explícito **em runtime server** (não exposto ao browser).
- WHEN probe route é acessada em `production` THEN SHALL responder `404` ou não existir no bundle — nunca expor mutação de teste.
- WHEN `sanitizeReturnUrl` recebe `/login` (path válido) THEN MAY retornar `/login` — loop de redirect é responsabilidade da UI nas fatias 4–8, não desta fatia.
- WHEN múltiplos valores `Origin` (não padrão) THEN SHALL falhar (tratar como inválido).

---

## Requirement Traceability

| Requirement ID | Story | Descrição | Phase | Status |
| --- | --- | --- | --- | --- |
| BFFUI-20 | P1: Allowlist | Tabela estática method/path → upstream | Execute | Done |
| CP-08 | P1: Allowlist | Tabela vazia em prod | Execute | Done |
| CP-09 | P1: Allowlist | Sem URL upstream arbitrária | Execute | Done |
| BFFUI-21 | P1: Origin | Match exato App host HTTPS | Execute | Done |
| CP-01 | P1: Origin | Aceita origin correto | Execute | Done |
| CP-02 | P1: Origin | Rejeita missing/null/wrong → 403 | Execute | Done |
| BFFUI-22 | P1: CSRF | Double-submit HMAC sessão/nonce | Execute | Done |
| CP-03 | P1: CSRF | Cookie + header obrigatórios | Execute | Done |
| CP-04 | P1: CSRF | Token bound a sessionId | Execute | Done |
| CP-05 | P1: CSRF | Token bound a csrf sid pré-auth | Execute | Done |
| CP-06 | P1: CSRF | Comparação constant-time | Execute | Done |
| CP-07 | P1: CSRF | Rotação com session rotate | Execute | Done |
| BFFUI-23 | P1: returnUrl | Path interno seguro | Execute | Done |
| CP-10 | P1: returnUrl | Vetor malicioso → fallback | Execute | Done |
| CP-11 | P1: returnUrl | Default `/` | Execute | Done |
| BFFUI-24 | P1: Cache | `private, no-store` | Execute | Done |
| CP-12 | P1: Cache | Helper JSON + erro 403 | Execute | Done |
| CP-13 | P2: Guard | Compose Origin+CSRF+session | Execute | Done |
| CP-14 | P2: Upstream | Timeout 10s; Bearer só server-side | Execute | Done |
| CP-15 | P1–P2 | Cobertura Vitest matriz segurança | Execute | Done |

**ID format:** `CP-NN` (fatia) + `BFFUI-NN` (catálogo índice)

**Coverage:** 19 total, 19 mapped to stories, 0 unmapped

---

## Success Criteria

- [ ] `make test-frontend` passa com suítes Vitest de Origin, CSRF, returnUrl, allowlist e headers privados.
- [ ] Tentativa de open redirect (vetor table-driven) falha em teste — nenhum caso malicioso retorna URL externa.
- [ ] Teste prova que URL upstream não pode ser passada como string livre pela API pública do módulo.
- [ ] Nenhum teste/log/HTML/JSON expõe Bearer ou `BFF_CSRF_HMAC_KEY`.
- [ ] Tabela allowlist de produção permanece vazia; fatia `login` pode adicionar primeira entrada real sem refatorar guards.
- [ ] Fatias 4–8 podem importar `@/modules/auth/bff` e implementar handlers sem reimplementar Origin/CSRF/returnUrl.

---

## Verificação (gates da fatia)

| Gate | Comando / artefato |
| --- | --- |
| Testes frontend | `make test-frontend` |
| Lint | `make lint-frontend` |
| Cobertura | Módulos `frontend/modules/auth/bff/**` ≥ 80% linhas/branches (meta BFF Auth em `docs/testing.md` §4) |

---

## Referências

- `docs/security.md` §5.3 — CSRF, Origin, allowlist, cache
- `docs/architecture.md` §8 — BFF gateway, timeout 10s
- `docs/testing.md` §3.2, §6.2 — estratégia Vitest BFF e casos de segurança
- `docs/decisions.md` — BFF somente operações conhecidas
- `.specs/features/bff-auth/README.md` — BFFUI-20 … BFFUI-24
- `frontend/lib/session-cookie.ts` — defaults Secure/HttpOnly/SameSite para cookies de sessão (CSRF usa variante não-HttpOnly)
