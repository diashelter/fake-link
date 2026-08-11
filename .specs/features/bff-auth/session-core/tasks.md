# BFF Auth — Núcleo de sessão — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/session-core/design.md`  
**Spec**: `.specs/features/bff-auth/session-core/spec.md`  
**Status**: Approved — 2026-08-11 (pré-Execute)

> **Sub-agent note:** 16 tasks → ~3 batches (~5–6 tasks/worker). Execute MUST offer batch sub-agents before implementation.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest/RTL), §4 (domínios frontend ≥75%), §6.2 (BFF session cases), `.specs/features/bff-auth/session-core/spec.md`, amostras `frontend/lib/*.test.ts`, `frontend/app/health/route.test.ts`, `frontend/modules/shared/lib/foundation-gates.test.ts`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| Session config loader | unit | SC-14; chaves ausentes/malformadas fail-fast | `frontend/modules/auth/lib/session/config.test.ts` | `make test-frontend` |
| Session ID (generate/parse) | unit | SC-06 edge cases; charset/comprimento inválidos | `frontend/modules/auth/lib/session/session-id.test.ts` | `make test-frontend` |
| AES-GCM crypto envelope | unit | SC-01, SC-07, SC-15; nonce único; kid desconhecido | `frontend/modules/auth/lib/session/crypto.test.ts` | `make test-frontend` |
| Redis HMAC key builder | unit | SC-06; prefixo `bff:sess:`; determinístico | `frontend/modules/auth/lib/session/redis-key.test.ts` | `make test-frontend` |
| TTL / idle / throttle | unit | SC-04, SC-08–SC-10; fake timers | `frontend/modules/auth/lib/session/ttl.test.ts` | `make test-frontend` |
| Session store (Redis adapter) | unit | serialize/parse v1; schemaVersion inválido | `frontend/modules/auth/lib/session/session-store.test.ts` | `make test-frontend` |
| BFF session facade | unit | SC-01–SC-13; Bearer absent in JSON.stringify | `frontend/modules/auth/services/bff-session.test.ts` | `make test-frontend` |
| Probe Route Handler | unit | SC-16–SC-17; 404 prod; no bearer in response | `frontend/app/api/_test/session/route.test.ts` | `make test-frontend` |
| Metrics hook | unit | increment on decrypt fail | `frontend/modules/auth/lib/session/metrics.test.ts` | `make test-frontend` |
| Types-only / fake store | none | — build gate | — | `make lint-frontend` |
| Docker / `.env.example` | none | SC-18 doc assert opcional em test | — | `make lint-frontend` |
| Foundation gates update | unit | allowlist probe + health only | `frontend/modules/shared/lib/foundation-gates.test.ts` | `make test-frontend` |
| Session cookie helper (regressão) | unit | DOCKER-06 flags unchanged | `frontend/lib/session-cookie.test.ts` | `make test-frontend` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks de types/config/primitivos sem facade | `make test-frontend` |
| Full | Após tasks com unit tests de domínio/facade/route | `make test-frontend` |
| Build | Após config Docker/env ou fechamento | `make lint-frontend && make test-frontend` |
| Coverage | Task final T16 | `make test-frontend-coverage` — ≥75% linhas/branches em `modules/**` |

---

## Execution Plan

Phases run sequentially; tasks within a phase run in order.

### Phase 1: Dependencies & Primitives

Config, types, crypto primitives — no Redis wiring yet.

```
T1 → T2 → T3 → T4 → T5 → T6 → T7
```

### Phase 2: Store & Facade

Redis store + public session API.

```
T8 → T9 → T10 → T11 → T12
```

### Phase 3: Integration & Gates

Compose env, probe route, foundation gates, coverage.

```
T13 → T14 → T15 → T16
```

---

## Task Breakdown

### T1: Pin `redis` dependency

**What**: Adicionar pacote `redis` (node-redis) em `frontend/package.json` com versão pinada; atualizar lockfile via container.
**Where**: `frontend/package.json`, `frontend/pnpm-lock.yaml`
**Depends on**: None
**Reuses**: pnpm pin pattern de `foundation`
**Requirement**: SC-01 (infra)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [x] `dependencies.redis` presente com versão exata
- [x] `pnpm install` no container frontend resolve sem erro
- [x] `make lint-frontend` passa (typecheck inclui tipos do pacote)

**Tests**: none
**Gate**: build

**Commit**: `chore(frontend): pin redis client for bff session store`

---

### T2: Session domain types

**What**: Definir `SessionKind`, `SessionRecord`, `SessionEnvelope`, `SessionContext`, inputs/results.
**Where**: `frontend/modules/auth/lib/session/types.ts`
**Depends on**: T1
**Reuses**: UUID v7 string type (plain `string` + JSDoc AD-010)
**Requirement**: SC-01, SC-05

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Tipos exportados conforme design.md
- [x] `schemaVersion` literal `1`
- [x] `make lint-frontend` passa

**Tests**: none
**Gate**: build

**Commit**: `feat(auth): add bff session domain types`

---

### T3: Config loader + tests

**What**: `loadBffSessionConfig()` — parse/env validate AES/HMAC keys, cookie name, redis URL, probe flag.
**Where**: `frontend/modules/auth/lib/session/config.ts`, `config.test.ts`
**Depends on**: T2
**Reuses**: `.env.example` keys documentadas
**Requirement**: SC-14

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] AES key exige 32 bytes decodificados
- [x] HMAC key exige ≥32 bytes
- [x] Ausência de env lança erro explícito
- [x] Testes cobrem happy + missing + malformed
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: quick

**Commit**: `feat(auth): add bff session config loader`

---

### T4: Session ID generate/parse + tests

**What**: `generateSessionId()` CSPRNG 256-bit base64url; `parseSessionId()` validação estrita.
**Where**: `frontend/modules/auth/lib/session/session-id.ts`, `session-id.test.ts`
**Depends on**: T2
**Reuses**: `node:crypto`
**Requirement**: SC-02, SC-06

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] ID gerado tem 43 chars base64url
- [x] Parse rejeita charset inválido, comprimento errado, decode ≠32 bytes
- [x] Testes cobrem edge cases da spec
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: quick

**Commit**: `feat(auth): add opaque session id helpers`

---

### T5: AES-GCM crypto envelope + tests

**What**: `encryptBearer` / `decryptBearer` com envelope `{ kid, nonce, ciphertext }`; `SessionDecryptError`.
**Where**: `frontend/modules/auth/lib/session/crypto.ts`, `crypto.test.ts`
**Depends on**: T3, T4
**Reuses**: `node:crypto` AES-256-GCM
**Requirement**: SC-01, SC-07, SC-15

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Round-trip encrypt/decrypt
- [x] Nonces distintos em duas escritas
- [x] Tag inválida / kid desconhecido falha decrypt
- [x] Plaintext Bearer não aparece em envelope serializado além de ciphertext
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: quick

**Commit**: `feat(auth): add aes-gcm bearer envelope`

---

### T6: Redis HMAC key + TTL helpers + tests

**What**: `buildRedisSessionKey`; constantes/helpers absoluto/idle/throttle (900s).
**Where**: `frontend/modules/auth/lib/session/redis-key.ts`, `ttl.ts`, `*.test.ts`
**Depends on**: T2, T3
**Reuses**: paridade bearer-tokens TTL table
**Requirement**: SC-04, SC-06, SC-08, SC-09, SC-10

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Chave Redis prefixo `bff:sess:` + hex HMAC
- [x] TTL session 604800/86400; verification 86400/3600
- [x] `shouldTouch` false <900s, true ≥900s
- [x] Fake timers testam idle/absoluto expired
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: quick

**Commit**: `feat(auth): add session redis key and ttl helpers`

---

### T7: Metrics hook + fake session store

**What**: `incrementDecryptFail` counter; `FakeSessionStore` in-memory para testes da facade.
**Where**: `frontend/modules/auth/lib/session/metrics.ts`, `metrics.test.ts`, `test/fake-session-store.ts`
**Depends on**: T2
**Reuses**: Vitest fake pattern
**Requirement**: SC-07 (observability hook)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Counter incrementa e é testável
- [x] Fake store implementa get/set/del usado pela facade nos testes
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: quick

**Commit**: `feat(auth): add session metrics and fake store test double`

---

### T8: Session store (Redis adapter) + tests

**What**: `createSessionStore`, serialize/parse `SessionRecord` v1; lazy redis client; injectable for tests.
**Where**: `frontend/modules/auth/lib/session/session-store.ts`, `session-store.test.ts`
**Depends on**: T1, T3, T6, T7
**Reuses**: `redis` createClient; FakeSessionStore para unit
**Requirement**: SC-01, SC-02

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] `SET` com EX seconds
- [x] `parseSessionRecord` rejeita schemaVersion ≠1, kind inválido
- [x] Testes usam fake/inject sem Redis real
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `feat(auth): add redis session store adapter`

---

### T9: Facade — `createSession` + cookie helpers + tests

**What**: `createSession`, `applySessionCookie`, `clearSessionCookie`; `import 'server-only'`.
**Where**: `frontend/modules/auth/services/bff-session.ts` (partial), `bff-session.test.ts`
**Depends on**: T4, T5, T6, T8
**Reuses**: `frontend/lib/session-cookie.ts`
**Requirement**: SC-01, SC-02, SC-03, SC-04

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Create grava registro cifrado; Redis payload sem Bearer plaintext
- [x] `JSON.stringify(record)` não contém Bearer de teste
- [x] Set-Cookie usa `__Host-fl_session` + flags Secure/HttpOnly/SameSite
- [x] Bearer vazio rejeitado antes de SET
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `feat(auth): implement bff createSession and cookie helpers`

---

### T10: Facade — `getSession` + tests

**What**: Read path: cookie parse → Redis GET → expiry checks → decrypt → `SessionContext`.
**Where**: `frontend/modules/auth/services/bff-session.ts`, `bff-session.test.ts`
**Depends on**: T9
**Reuses**: crypto, ttl, metrics
**Requirement**: SC-05, SC-06, SC-07, SC-12

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Happy path retorna context com bearer
- [x] Cookie malformado → null sem store get (spy)
- [x] Decrypt fail → destroy + incrementDecryptFail + clearCookie
- [x] `JSON.stringify(context)` contém bearer → test MUST fail (assert negativo)
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `feat(auth): implement bff getSession read path`

---

### T11: Facade — `touchSession` + expiry enforcement + tests

**What**: Idle/absoluto em getSession; `touchSession` com throttle 900s.
**Where**: `frontend/modules/auth/services/bff-session.ts`, `bff-session.test.ts`
**Depends on**: T10
**Reuses**: ttl helpers, fake timers
**Requirement**: SC-08, SC-09, SC-10

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Sessão idle expirada retorna null + clearCookie
- [x] Sessão absoluto expirada destruída
- [x] Touch não escreve se elapsed <900s
- [x] Touch atualiza lastActivityAt se ≥900s
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `feat(auth): add session idle absolute expiry and touch throttle`

---

### T12: Facade — `rotateSession` + `destroySession` + Redis failure + tests

**What**: Rotate (del old + create new); destroy; Redis errors → null + clearCookie.
**Where**: `frontend/modules/auth/services/bff-session.ts`, `bff-session.test.ts`
**Depends on**: T11
**Reuses**: createSession path
**Requirement**: SC-11, SC-12, SC-13

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Após rotate, old id não resolve
- [x] destroySession remove key + clearCookie
- [x] Store throw → getSession null + clearCookie
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `feat(auth): implement session rotate destroy and redis failure handling`

---

### T13: Docker Compose env + `.env.example` documentation

**What**: Injetar `BFF_SESSION_*`, `REDIS_*` no serviço `frontend`; documentar chaves dev e rotação invalida sessões.
**Where**: `docker-compose.yml`, `.env.example`, opcional `README.md` nota curta
**Depends on**: T3
**Reuses**: padrão env backend
**Requirement**: SC-18

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Frontend container recebe env vars
- [x] `.env.example` inclui chaves base64 dev + comentário rotação
- [x] `make lint-frontend` passa

**Tests**: none (opcional assert string em test existente)
**Gate**: build

**Commit**: `chore(docker): wire bff session env for frontend`

---

### T14: Probe Route Handler + tests

**What**: `GET/POST /api/_test/session` gated; sem Bearer na response.
**Where**: `frontend/app/api/_test/session/route.ts`, `route.test.ts`
**Depends on**: T12, T13
**Reuses**: `health/route.test.ts` pattern
**Requirement**: SC-16, SC-17

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Prod + probe disabled → 404
- [x] POST cria sessão; GET com cookie → `{ authenticated: true, kind }`
- [x] Response body/headers sem bearer/sessionId/ciphertext
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `feat(auth): add gated session probe route for tests`

---

### T15: Foundation gates allowlist + module exports

**What**: Atualizar `foundation-gates.test.ts` allowlist (`health` + `api/_test/session`); export server-safe symbols em `modules/auth/index.ts`.
**Where**: `frontend/modules/shared/lib/foundation-gates.test.ts`, `frontend/modules/auth/index.ts`
**Depends on**: T14
**Reuses**: FND-02 gate pattern
**Requirement**: SC-16 (no product routes)

**Tools**:

- MCP: NONE
- Skill: NONE

**Done when**:

- [x] Gate allowlist inclui apenas health + probe
- [x] `modules/auth/index.ts` não exporta bearer helpers perigosos para client
- [x] `make test-frontend` verde

**Tests**: unit
**Gate**: full

**Commit**: `test(frontend): allow session probe in foundation route gate`

---

### T16: Coverage gate + spec traceability update

**What**: Verificar `make test-frontend-coverage` ≥75% em `modules/auth/**`; atualizar `spec.md` traceability Status → In Tasks/Done quando orchestrator fechar Execute.
**Where**: `frontend/vitest.config.ts` (ajuste exclude se necessário), `.specs/features/bff-auth/session-core/spec.md` (status IDs)
**Depends on**: T15
**Reuses**: thresholds existentes
**Requirement**: SC-01–SC-18 (coverage meta)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven` (Verifier após último commit de código)

**Done when**:

- [ ] `make lint-frontend && make test-frontend-coverage` exit 0
- [ ] Cobertura auth module ≥75% lines/branches
- [ ] Nenhum teste skipped/deleted vs T1 baseline

**Tests**: coverage gate
**Gate**: coverage

**Commit**: `test(auth): satisfy session-core coverage thresholds`

> **Nota:** Verifier roda automaticamente após T16 — gera `validation.md`; não é task separada.

---

## Phase Execution Map

```
Phase 1 → Phase 2 → Phase 3

Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4 ──→ T5 ──→ T6 ──→ T7
Phase 2:  T8 ──→ T9 ──→ T10 ──→ T11 ──→ T12
Phase 3:  T13 ──→ T14 ──→ T15 ──→ T16
```

**Batch packing (~7 tasks/worker):**

| Batch | Phases | Tasks |
| --- | --- | --- |
| 1 | Phase 1 | T1–T7 |
| 2 | Phase 2 | T8–T12 |
| 3 | Phase 3 | T13–T16 |

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: Pin redis dep | 1 dependency | ✅ Granular |
| T2: Domain types | 1 file types | ✅ Granular |
| T3: Config loader | 1 module + tests | ✅ Granular |
| T4: Session ID | 1 module + tests | ✅ Granular |
| T5: Crypto envelope | 1 module + tests | ✅ Granular |
| T6: Redis key + TTL | 2 cohesive modules + tests | ✅ Granular |
| T7: Metrics + fake store | 2 test-support files | ✅ Granular |
| T8: Session store | 1 adapter + tests | ✅ Granular |
| T9: createSession | 1 facade method slice | ✅ Granular |
| T10: getSession | 1 facade method slice | ✅ Granular |
| T11: touch + expiry | 1 facade concern | ✅ Granular |
| T12: rotate/destroy | 1 facade concern | ✅ Granular |
| T13: Docker env | compose + env example | ✅ Granular |
| T14: Probe route | 1 route + tests | ✅ Granular |
| T15: Gates + exports | 2 small files | ✅ Granular |
| T16: Coverage gate | verification task | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | T1 (start) | ✅ Match |
| T2 | T1 | T2 after T1 | ✅ Match |
| T3 | T2 | T3 after T2 | ✅ Match |
| T4 | T2 | T4 after T2 | ✅ Match |
| T5 | T3, T4 | T5 after T3,T4 | ✅ Match |
| T6 | T2, T3 | T6 after T2,T3 | ✅ Match |
| T7 | T2 | T7 after T2 | ✅ Match |
| T8 | T1, T3, T6, T7 | T8 after T7 | ✅ Match |
| T9 | T4, T5, T6, T8 | T9 after T8 | ✅ Match |
| T10 | T9 | T10 after T9 | ✅ Match |
| T11 | T10 | T11 after T10 | ✅ Match |
| T12 | T11 | T12 after T11 | ✅ Match |
| T13 | T3 | T13 (Phase 3, after T12) | ✅ Match |
| T14 | T12, T13 | T14 after T13 | ✅ Match |
| T15 | T14 | T15 after T14 | ✅ Match |
| T16 | T15 | T16 after T15 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | dependency | none | none | ✅ OK |
| T2 | types | none | none | ✅ OK |
| T3 | config loader | unit | unit | ✅ OK |
| T4 | session-id | unit | unit | ✅ OK |
| T5 | crypto | unit | unit | ✅ OK |
| T6 | redis-key, ttl | unit | unit | ✅ OK |
| T7 | metrics, fake store | unit | unit | ✅ OK |
| T8 | session store | unit | unit | ✅ OK |
| T9 | facade create | unit | unit | ✅ OK |
| T10 | facade get | unit | unit | ✅ OK |
| T11 | facade touch/expiry | unit | unit | ✅ OK |
| T12 | facade rotate/destroy | unit | unit | ✅ OK |
| T13 | docker/env | none | none | ✅ OK |
| T14 | probe route | unit | unit | ✅ OK |
| T15 | foundation gates | unit | unit | ✅ OK |
| T16 | coverage | coverage gate | coverage gate | ✅ OK |

---

## Requirement → Task Mapping

| Requirement | Task(s) |
| --- | --- |
| SC-01 | T5, T8, T9 |
| SC-02 | T4, T8, T9 |
| SC-03 | T9 |
| SC-04 | T6, T9 |
| SC-05 | T10 |
| SC-06 | T4, T6, T10 |
| SC-07 | T5, T7, T10 |
| SC-08 | T6, T11 |
| SC-09 | T6, T11 |
| SC-10 | T6, T11 |
| SC-11 | T12 |
| SC-12 | T10, T12 |
| SC-13 | T12 |
| SC-14 | T3 |
| SC-15 | T5, T10 |
| SC-16 | T14, T15 |
| SC-17 | T14 |
| SC-18 | T13 |

**Coverage:** 18 total, 18 mapped to tasks ✅

---

## MCPs & Skills (Execute)

| Task range | MCPs | Skills |
| --- | --- | --- |
| T1–T16 | NONE (Context7 opcional para `redis` API se dúvida) | `tlc-spec-driven` (mandatory Execute) |
| UI-adjacent | NONE | NONE |

**Dependency approval:** T1 requer confirmação do mantenedor para pacote `redis` (`AGENTS.md`).

---

## Tips for Execute

- Import `'server-only'` no topo de `bff-session.ts` e `session-store.ts`.
- Nunca logar Bearer, session ID bruto ou chaves.
- Sensor mental: mutante que inclui bearer em JSON response deve falhar nos testes.
- Um commit por task; gate antes de commit.
- Após T16: Verifier automático → `validation.md`.
