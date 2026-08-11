# BFF Auth — Núcleo de sessão

**Status:** Approved — 2026-08-11  
**Fatia:** 2 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-10 … BFFUI-17  
**Requirement IDs (fatia):** SC-01 … SC-18  
**Depende de:** [foundation](../foundation/spec.md) (Verified); API Auth MVP (`.specs/features/auth/` Verified)

## Problem Statement

O browser oficial não pode receber o Bearer emitido pelo Laravel. A fatia `foundation` entregou scaffold modular, forms stack e helpers de cookie seguro, mas **sem** persistência de sessão, cifra do token nem lookup Redis. Sem um núcleo de sessão server-side, as fatias seguintes (CSRF/proxy, login, register, etc.) não têm onde armazenar credenciais de forma segura.

Esta fatia implementa a **biblioteca/serviço de sessão BFF** no Next.js: gera ID opaco de 256 bits, cifra o Bearer com AES-256-GCM (chave fora do Redis), persiste estado mínimo no `redis-ephemeral` com chave derivada por HMAC, expõe API interna para fatias posteriores e garante que o Bearer **nunca** apareça em estruturas serializáveis ao browser. **Não** entrega handlers de produto (login/logout) nem CSRF — apenas o núcleo reutilizável.

## Goals

- [ ] Serviço de sessão em `frontend/modules/auth/` com cifra AES-256-GCM, envelope versionado (`kid`) e nonces únicos por escrita.
- [ ] Persistência Redis: chave = `HMAC-SHA256(lookup_key, session_id_bytes)`; valor = JSON com metadados mínimos + ciphertext; ID bruto não é chave pesquisável.
- [ ] Cookie `__Host-fl_session` com `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, sem `Domain` — via helper existente `frontend/lib/session-cookie.ts`.
- [ ] TTL absoluto e idle por `kind`: `session` 7d/24h; `verification` 24h/1h — alinhados a `docs/security.md` §5.2 e API Bearer.
- [ ] Throttle de `lastActivityAt` no Redis: no máximo 1 escrita a cada 15 minutos por sessão ativa (espelha AUTH-17 / `last_used_at`).
- [ ] Rotação de session ID: API pública `rotateSession` invalida ID anterior e emite novo cookie (consumida por login e mudanças sensíveis nas fatias 4–8).
- [ ] Falha de decrypt, Redis miss, flush/eviction, TTL absoluto/idle ou chave AES inválida → sessão inválida + cookie limpo; **sem** fallback de Bearer ao browser.
- [ ] Vitest cobre crypto, HMAC lookup, TTL/idle, throttle, rotação e ausência de Bearer em `JSON.stringify` / respostas de probe.
- [ ] Cobertura ≥75% linhas/branches em `frontend/modules/auth/**` introduzido nesta fatia (`docs/testing.md` §4).

## Out of Scope

| Item | Motivo |
| --- | --- |
| CSRF, `Origin`, allowlist de rotas upstream | Fatia `csrf-proxy` |
| Route Handlers de produto (login, register, logout, me) | Fatias 4–8 |
| UI / páginas Auth | Fatias 4–8 |
| Playwright / axe | Fatia `e2e-security-gate` |
| Chamadas HTTP ao Laravel | Fatias 4–8 (via csrf-proxy) |
| Revogação Bearer no Laravel em logout | Fatia `session-shell` / handlers |
| Rate limiting BFF adicional | API upstream já limita; csrf-proxy pode aprofundar |
| Keyring multi-chave em produção (SOPS) | Fase 4; esta fatia suporta `kid` no envelope + rotação documentada |
| Persistência TanStack Query | Proibida desde `foundation` |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Redis alvo | Instância `redis-ephemeral` (`REDIS_HOST` / `REDIS_PORT` no container frontend) | `docs/architecture.md` §9 — sessões BFF no efêmero | y |
| Cliente Redis Node | **`redis`** (pacote oficial `@redis/client` via `redis` npm) | Mantido pelo time Redis; suporte TLS futuro; **aprovado pelo mantenedor 2026-08-11** | y |
| Nome do cookie | `__Host-fl_session` (env `BFF_SESSION_COOKIE_NAME`, default fixo) | Prefixo `__Host-` exigido; `fl_session` já usado em testes DOCKER-06 | y |
| Formato do session ID no cookie | 32 bytes CSPRNG → **base64url** sem padding (43 caracteres) | Opaco, URL-safe, entropia 256 bits | y |
| Prefixo de chave Redis | `bff:sess:` + hex do HMAC-SHA256 | Namespace explícito; evita colisão com rate-limit/cache | y |
| Materiais criptográficos | `BFF_SESSION_AES_KEY` (32 bytes base64) e `BFF_SESSION_HMAC_KEY` (≥32 bytes base64) — **distintos** | `docs/security.md` §5.1, §14 | y |
| Envelope GCM | `{ kid: string, nonce: base64url, ciphertext: base64url }` onde ciphertext inclui tag GCM | Nonce 12 bytes únicos por escrita; `kid` default `"1"` | y |
| Schema valor Redis (v1) | Ver § Entregáveis — campo `schemaVersion: 1` | Metadados mínimos + envelope; Bearer só dentro de `ciphertext` | y |
| Idle touch throttle | Atualizar `lastActivityAt` no Redis ≤1× a cada **900 s**; leituras sempre validam idle contra timestamp armazenado | Paridade com AUTH-17 (`last_used_at` 15 min) | y |
| TTL Redis key | `EX` = segundos restantes até **expiração absoluta** do kind; revalidado em touch | Idle checado em leitura; absoluto limita vida máxima da chave | y |
| Concorrência em touch | Last-write-wins aceitável; throttle reduz corrida | Sem transação distribuída no MVP | y |
| Concorrência em rotate | `MULTI`/`DEL` antigo + `SET` novo ou delete-before-create atômico | ID antigo não deve resolver após rotate | y |
| Cookie malformado | Rejeitar sem lookup Redis (comprimento/charset inválido) | Evita oracle de HMAC em lixo | y |
| Probe de teste | Route Handler `app/api/_test/session/route.ts` **somente** se `NODE_ENV=test` **ou** `BFF_SESSION_PROBE_ENABLED=true` (dev) | Espelha probes Auth API; ausente em produção | y |
| Localização do código | `frontend/modules/auth/lib/session/` (crypto, store, types) + `services/bff-session.ts` (facade) | Modular; `shared` permanece genérico | y |
| Env vars no Compose | Adicionar ao serviço `frontend` em `docker-compose.yml` + `.env.example` raiz | Chaves dev determinísticas documentadas; prod via SOPS (Fase 4) | y |
| Dependência de chave inválida | Startup falha em produção; em test/dev, chaves fixas de `.env.testing` ou doc | Fail-fast vs sessões silenciosamente quebradas | y |

**Open questions:** none — all resolved or logged above.

---

## Implicit-Requirement Dimensions

| Dimension | Resolução |
| --- | --- |
| Input validation & bounds | Session ID: exatamente 43 chars base64url após decode = 32 bytes; rejeitar cookie ausente/malformado sem Redis; `kind` enum `session` \| `verification`; `userId` UUID v7 string |
| Failure / partial-failure states | Decrypt fail → destroy local session + clear cookie; Redis timeout/connection error → tratar como miss (sessão inválida, cookie limpo); partial write → próxima leitura miss |
| Idempotency / retry | `createSession` sempre gera novo ID; `getSession` idempotente; `rotateSession` não idempotente (sempre novo ID); retry de touch throttle-safe |
| Auth boundaries | API interna TypeScript only nesta fatia; probe HTTP gated; Bearer recuperável **somente** em memória server-side durante request |
| Concurrency / ordering | Rotate: delete-before-set do registro antigo; touch: throttle 900s; create: único writer por emissão |
| Data lifecycle / expiry | Absoluto: 604800s (`session`) / 86400s (`verification`); idle: 86400s / 3600s; Redis EX alinhado ao absoluto restante |
| Observability | Proibido logar Bearer, session ID bruto, chaves ou plaintext Redis; métrica/contador interno `bff_session_decrypt_fail_total` (hook no código, export OTel Fase 4) |
| External-dependency failure | Redis indisponível = sessão inválida + cookie cleared; sem queue de reconciliação (`docs/security.md` §5.2) |
| State-transition integrity | `kind` imutável no registro; transição `verification` → `session` exige **nova emissão** (rotate/create) nas fatias seguintes, não patch in-place |

---

## Entregáveis técnicos

### Variáveis de ambiente

| Variável | Obrigatória | Default dev | Descrição |
| --- | --- | --- | --- |
| `BFF_SESSION_AES_KEY` | sim | doc em `.env.example` (32 bytes base64) | Chave AES-256-GCM; nunca no Redis |
| `BFF_SESSION_HMAC_KEY` | sim | doc em `.env.example` (≥32 bytes base64) | HMAC lookup; distinta da AES |
| `BFF_SESSION_COOKIE_NAME` | não | `__Host-fl_session` | Nome completo do cookie |
| `BFF_SESSION_AES_KEY_ID` | não | `1` | `kid` ativo para novas escritas |
| `BFF_SESSION_PROBE_ENABLED` | não | `false` | Permite probe em dev |
| `REDIS_HOST` | sim | `redis-ephemeral` | Host Redis efêmero |
| `REDIS_PORT` | sim | `6379` | Porta Redis |

### Schema Redis (valor JSON, `schemaVersion: 1`)

```json
{
  "schemaVersion": 1,
  "kind": "session",
  "userId": "019082da-…",
  "createdAt": "2026-08-11T12:00:00.000Z",
  "lastActivityAt": "2026-08-11T12:00:00.000Z",
  "envelope": {
    "kid": "1",
    "nonce": "…",
    "ciphertext": "…"
  }
}
```

- **`envelope.ciphertext`**: output AES-256-GCM do Bearer UTF-8 (plaintext nunca persistido fora do envelope).
- **Chave Redis**: `bff:sess:` + `HMAC-SHA256(BFF_SESSION_HMAC_KEY, decode_base64url(session_id_from_cookie))` em hex minúsculo.

### API interna (facade `bff-session`)

| Função | Comportamento |
| --- | --- |
| `createSession(input)` | Gera ID 256-bit, cifra Bearer, grava Redis com EX absoluto, retorna `{ sessionId, cookieValue, expiresAt }` |
| `getSession(cookieHeader)` | Valida cookie → HMAC key → GET Redis → valida absoluto/idle → decrypt Bearer **em memória** → retorna `SessionContext` ou `null` |
| `touchSession(sessionId)` | Se throttle ≥900s desde `lastActivityAt`, atualiza timestamp e refresca EX restante |
| `rotateSession(currentId, input?)` | Destrói registro antigo, cria novo ID (opcionalmente novo Bearer/kind), emite novo cookie value |
| `destroySession(sessionId)` | DEL Redis key; retorna instrução de cookie cleared (`Max-Age=0`) |
| `buildSessionCookie(value, maxAge?)` | Usa `setSessionCookie` / `buildSessionCookieOptions` de `frontend/lib/session-cookie.ts` |

`SessionContext` (server-only): `{ sessionId, kind, userId, bearer, createdAt, lastActivityAt }` — **proibido** serializar para Client Components ou JSON responses de produto.

### Árvore (mínima)

```txt
frontend/
  modules/auth/
    lib/session/
      crypto.ts           # AES-GCM encrypt/decrypt, envelope
      redis-key.ts          # HMAC lookup key builder
      session-id.ts         # generate + validate base64url 256-bit
      types.ts              # SessionKind, SessionRecord, SessionContext
      ttl.ts                # absoluto/idle por kind, throttle
    services/
      bff-session.ts        # facade create/get/touch/rotate/destroy
    lib/session/*.test.ts
    services/bff-session.test.ts
  app/api/_test/session/
    route.ts                # probe gated (test/dev only)
    route.test.ts
  lib/session-cookie.ts     # existente — reusar, não duplicar flags
```

### Probe HTTP (test/dev)

- `GET /api/_test/session` — retorna `{ authenticated: boolean, kind?: string }` **sem** Bearer, session ID completo ou ciphertext.
- `POST /api/_test/session` — body `{ bearer, kind, userId }` cria sessão de teste; response `Set-Cookie` apenas.
- Ausente quando `NODE_ENV=production` e `BFF_SESSION_PROBE_ENABLED` não é `true`.

---

## User Stories

### P1: Criar sessão cifrada no Redis ⭐ MVP

**User Story**: Como implementador das fatias BFF Auth seguintes, quero criar uma sessão com Bearer cifrado no Redis e cookie opaco para que o browser nunca receba o token.

**Why P1**: Sem create + persistência segura, nenhum fluxo Auth browser-side é possível.

**Acceptance Criteria**:

1. WHEN `createSession` é chamado com Bearer válido, `kind` e `userId` THEN o sistema SHALL gerar session ID de 256 bits, cifrar o Bearer com AES-256-GCM usando nonce único, gravar registro JSON v1 sob chave HMAC no Redis com TTL absoluto do `kind`, e retornar valor de cookie opaco (base64url do ID).
2. WHEN o registro Redis é inspecionado THEN o valor SHALL NOT conter substring do Bearer plaintext nem header `Authorization`.
3. WHEN o cookie é emitido via `buildSessionCookie` THEN o `Set-Cookie` SHALL usar nome `__Host-fl_session` (ou env override), `HttpOnly`, `Secure`, `SameSite=Lax`, `Path=/`, e SHALL NOT incluir `Domain`.
4. WHEN `kind` é `session` THEN `expiresAt` absoluto SHALL ser ≤ now + 604800s; WHEN `kind` é `verification` THEN SHALL ser ≤ now + 86400s.

**Independent Test**: Vitest com Redis mock/in-memory: create → assert Redis payload + assert `JSON.stringify(record)` não contém Bearer; assert Set-Cookie flags via helper.

**Maps to**: SC-01, SC-02, SC-03, SC-04 · BFFUI-10, BFFUI-11, BFFUI-12

---

### P1: Recuperar sessão server-side sem vazar Bearer ⭐ MVP

**User Story**: Como Route Handler futuro, quero ler a sessão a partir do cookie e obter o Bearer apenas em memória server-side para chamar a API Laravel.

**Why P1**: Core do BFF — leitura segura é pré-requisito de proxy.

**Acceptance Criteria**:

1. WHEN cookie válido é apresentado e Redis contém registro não expirado THEN `getSession` SHALL retornar `SessionContext` com Bearer decryptado **somente** no objeto retornado ao caller server-side.
2. WHEN cookie está ausente, malformado (≠43 chars base64url válidos) ou HMAC key não encontra registro THEN `getSession` SHALL retornar `null` **sem** consultar Redis com ID bruto como chave.
3. WHEN decrypt GCM falha (tag inválida, `kid` desconhecido) THEN o sistema SHALL chamar `destroySession`, limpar cookie, e retornar `null`.
4. WHEN `getSession` succeeds THEN nenhum método de serialização exposto (`toJSON`, spread para Response) SHALL incluir campo `bearer` — testes SHALL falhar se `JSON.stringify(sessionContext)` contiver prefixo do Bearer de teste.

**Independent Test**: Vitest: cookie round-trip; mutate ciphertext → decrypt fail → cookie cleared instruction.

**Maps to**: SC-05, SC-06, SC-07 · BFFUI-10, BFFUI-17

---

### P1: Expiração absoluta, idle e throttle de atividade ⭐ MVP

**User Story**: Como operador de segurança, quero que sessões expirem por tempo absoluto e inatividade alinhados à API Auth, com throttle de escrita, para limitar janela de abuso e carga Redis.

**Why P1**: Requisito de segurança explícito em `docs/security.md` §5.2.

**Acceptance Criteria**:

1. WHEN now > `createdAt` + limite absoluto do `kind` THEN `getSession` SHALL retornar `null`, destruir registro Redis e instruir limpeza de cookie.
2. WHEN now > `lastActivityAt` + limite idle (`86400s` session / `3600s` verification) THEN `getSession` SHALL retornar `null` e destruir sessão.
3. WHEN `touchSession` é chamado e elapsed desde `lastActivityAt` < 900s THEN o sistema SHALL NOT escrever no Redis.
4. WHEN `touchSession` é chamado e elapsed ≥ 900s THEN SHALL atualizar `lastActivityAt` para now e ajustar EX da chave para o absoluto restante.

**Independent Test**: Vitest com fake timers: avançar relógio além idle/absoluto; assert touch write count.

**Maps to**: SC-08, SC-09, SC-10 · BFFUI-14

---

### P1: Rotação e destruição de sessão ⭐ MVP

**User Story**: Como implementador dos fluxos login/senha, quero rotacionar e destruir sessões para mitigar fixation e encerrar credenciais com segurança.

**Why P1**: Rotação no login é requisito de segurança (`docs/security.md` §5.2).

**Acceptance Criteria**:

1. WHEN `rotateSession(currentId)` succeeds THEN a chave Redis do ID anterior SHALL NOT existir e um novo ID/cookie SHALL ser emitido.
2. WHEN cookie com ID antigo é apresentado após rotate THEN `getSession` SHALL retornar `null`.
3. WHEN `destroySession(sessionId)` é chamado THEN Redis key SHALL ser removida e resposta SHALL incluir cookie cleared (`Max-Age=0` ou equivalente via helper).
4. WHEN Redis retorna miss durante `getSession` THEN o sistema SHALL instruir limpeza de cookie e retornar `null` **sem** fallback de Bearer.

**Independent Test**: Vitest: create → rotate → old id null; destroy → GET miss.

**Maps to**: SC-11, SC-12 · BFFUI-15, BFFUI-16

---

### P2: Falha Redis e integridade de chaves

**User Story**: Como operador, quero que indisponibilidade do Redis encerre sessões de forma segura, sem expor tokens ao browser.

**Why P2**: Cenário operacional documentado; não bloqueia API interna mas exige comportamento definido.

**Acceptance Criteria**:

1. WHEN Redis connection falha em `getSession` THEN SHALL retornar `null` e instruir cookie cleared (mesmo comportamento que miss).
2. WHEN `BFF_SESSION_AES_KEY` ou `BFF_SESSION_HMAC_KEY` está ausente/malformada na inicialização do módulo THEN startup SHALL falhar com erro explícito (testável via import do config loader).
3. WHEN rotação da chave AES (`kid` no envelope ≠ chaves ativas) THEN decrypt SHALL falhar e sessão SHALL ser destruída (comportamento idêntico a SC-07).

**Independent Test**: Vitest mock Redis throw; config loader tests.

**Maps to**: SC-13, SC-14, SC-15 · BFFUI-16

---

### P2: Probe HTTP gated para integração

**User Story**: Como autor de testes, quero um Route Handler de probe para validar cookie+Redis em integração sem expor Bearer.

**Why P2**: Acelera fatias seguintes e gate E2E; isolado de produção.

**Acceptance Criteria**:

1. WHEN `NODE_ENV=production` e `BFF_SESSION_PROBE_ENABLED` não é `true` THEN rota `/api/_test/session` SHALL NOT existir (404).
2. WHEN probe habilitado e POST cria sessão THEN GET subsequente com cookie SHALL retornar `{ authenticated: true, kind }` sem campos `bearer`, `sessionId` ou `ciphertext`.
3. WHEN probe response THEN headers SHALL NOT incluir Bearer em qualquer forma.

**Independent Test**: Vitest route tests com env mock.

**Maps to**: SC-16, SC-17 · BFFUI-17

---

### P3: Documentação operacional de rotação de chave BFF

**User Story**: Como operador futuro, quero documentação clara de que rotacionar `BFF_SESSION_AES_KEY` encerra todas as sessões.

**Why P3**: Requisito comunicável em `docs/security.md` §5.2; baixo risco se código já trata `kid` desconhecido.

**Acceptance Criteria**:

1. WHEN fatia conclui THEN `README.md` ou comentário em `.env.example` SHALL documentar que alterar `BFF_SESSION_AES_KEY*` invalida sessões existentes.

**Independent Test**: Doc review / assert string in `.env.example` comment.

**Maps to**: SC-18 · BFFUI-10

---

## Edge Cases

- WHEN cookie contém caracteres fora do alfabeto base64url THEN `getSession` SHALL retornar `null` sem Redis GET.
- WHEN cookie decodifica para ≠32 bytes THEN `getSession` SHALL retornar `null`.
- WHEN registro Redis tem `schemaVersion` ≠ 1 THEN SHALL tratar como miss e destruir cookie.
- WHEN `kind` no registro é desconhecido THEN SHALL tratar como miss e destruir cookie.
- WHEN duas requisições concorrentes chamam `rotateSession` no mesmo ID THEN ao menos uma SHALL falhar ou só um novo ID SHALL permanecer válido (ID antigo inválido).
- WHEN `createSession` recebe Bearer vazio THEN SHALL rejeitar com erro de validação antes de Redis SET.
- WHEN Redis eviction remove chave antes do TTL THEN próximo `getSession` SHALL comportar-se como miss (SC-12).
- WHEN probe POST em produção sem flag THEN SHALL responder 404, não 500.

---

## Requirement Traceability

| Requirement ID | Story | Catálogo | Phase | Status |
| --- | --- | --- | --- | --- |
| SC-01 | P1: Criar sessão | BFFUI-10 | Tasks (T5,T8,T9) | ✅ Verified |
| SC-02 | P1: Criar sessão | BFFUI-11 | Tasks (T4,T8,T9) | ✅ Verified |
| SC-03 | P1: Criar sessão | BFFUI-12 | Tasks (T9) | ✅ Verified |
| SC-04 | P1: Criar sessão | BFFUI-14 | Tasks (T6,T9) | ✅ Verified |
| SC-05 | P1: Recuperar sessão | BFFUI-10 | Tasks (T10) | ✅ Verified |
| SC-06 | P1: Recuperar sessão | BFFUI-13 | Tasks (T4,T6,T10) | ✅ Verified |
| SC-07 | P1: Recuperar sessão | BFFUI-17 | Tasks (T5,T7,T10) | ✅ Verified |
| SC-08 | P1: Expiração | BFFUI-14 | Tasks (T6,T11) | ✅ Verified |
| SC-09 | P1: Expiração | BFFUI-14 | Tasks (T6,T11) | ✅ Verified |
| SC-10 | P1: Expiração | BFFUI-14 | Tasks (T6,T11) | ✅ Verified |
| SC-11 | P1: Rotação/destruição | BFFUI-15 | Tasks (T12) | ✅ Verified |
| SC-12 | P1: Rotação/destruição | BFFUI-16 | Tasks (T10,T12) | ✅ Verified |
| SC-13 | P2: Falha Redis | BFFUI-16 | Tasks (T12) | ✅ Verified |
| SC-14 | P2: Falha Redis | BFFUI-10 | Tasks (T3) | ✅ Verified |
| SC-15 | P2: Falha Redis | BFFUI-10 | Tasks (T5,T10) | ✅ Verified |
| SC-16 | P2: Probe | BFFUI-17 | Tasks (T14,T15) | ✅ Verified |
| SC-17 | P2: Probe | BFFUI-17 | Tasks (T14) | ✅ Verified |
| SC-18 | P3: Doc rotação chave | BFFUI-10 | Tasks (T13) | ✅ Verified |

**Coverage:** 18 total, 18 mapped to tasks ✅

---

## Success Criteria

- [ ] `make lint-frontend && make test-frontend` verde após implementação.
- [ ] Cobertura ≥75% linhas/branches em `frontend/modules/auth/**` desta fatia.
- [ ] Testes provam ausência de Bearer em `JSON.stringify` de registros/respostas de probe.
- [ ] Sensor de mutação (Bearer leak, HMAC bypass, TTL) mata mutantes — Verifier na Execute.
- [ ] `.env.example` documenta chaves BFF dev e comportamento de rotação.
- [ ] Nenhum handler de produto Auth além do probe gated introduzido.

---

## Referências do projeto

| Documento | Uso |
| --- | --- |
| `docs/security.md` §5.1–5.2 | Modelo de sessão BFF |
| `docs/architecture.md` §8–§9 | BFF gateway, Redis efêmero |
| `docs/testing.md` §3.2, §4, §6.2 | Estratégia Vitest, cobertura, casos BFF |
| `docs/decisions.md` | BFF e credenciais |
| `.specs/features/bff-auth/README.md` | Catálogo BFFUI-10…17 |
| `.specs/features/bff-auth/foundation/spec.md` | Pré-requisitos e limites |
| `frontend/lib/session-cookie.ts` | Helper cookie seguro existente |
| `.specs/features/auth/bearer-tokens/spec.md` | Paridade TTL/idle/throttle |
