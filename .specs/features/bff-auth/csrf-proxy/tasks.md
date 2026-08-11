# BFF Auth — CSRF e proxy — Tasks

## Execution Protocol (MANDATORY -- do not skip)

Implement these tasks with the `tlc-spec-driven` skill: **activate it by name and follow its Execute flow and Critical Rules.** Do not search for skill files by filesystem path. The skill is the source of truth for the full flow (per-task cycle, sub-agent delegation, adequacy review, Verifier, discrimination sensor).

**If the skill cannot be activated, STOP and tell the user — do not proceed without it.**

---

**Design**: `.specs/features/bff-auth/csrf-proxy/design.md`  
**Spec**: `.specs/features/bff-auth/csrf-proxy/spec.md`  
**Status**: Approved — 2026-08-11 (pré-Execute)

> **Sub-agent note:** 11 tasks → ~2 batches (~6 + ~5 tasks/worker). Execute MUST offer batch sub-agents before implementation if user accepts.
>
> **Blocker externo:** `session-core` ainda não executada — T8 usa `SessionLoader` mock; integração real na fatia `login`.

---

## Test Coverage Matrix

> Generated from codebase, project guidelines, and spec — confirm before Execute. Guidelines found: `AGENTS.md` (Fake Link), `docs/testing.md` §3.2 (Vitest Route Handlers/BFF), §4 (Auth/BFF ≥80%), §6.2 (CSRF, Origin, returnUrl), `.specs/features/bff-auth/csrf-proxy/spec.md`, amostras `frontend/lib/session-cookie.test.ts`, `frontend/app/health/route.test.ts`.

| Code Layer | Required Test Type | Coverage Expectation | Location Pattern | Run Command |
| ---------- | ------------------ | -------------------- | ---------------- | ----------- |
| BFF env + crypto helpers | unit | Key/origin validation; HMAC determinístico; timing-safe path | `frontend/modules/auth/bff/*.test.ts` | `make test-frontend` |
| Origin validator | unit | CP-01/02 ACs 1:1; GET exempt; edge Referer ignored | `frontend/modules/auth/bff/origin.test.ts` | `make test-frontend` |
| CSRF issue/validate | unit | CP-03–07; pre-auth + session modes; rotate invalidates | `frontend/modules/auth/bff/csrf.test.ts` | `make test-frontend` |
| returnUrl sanitizer | unit | CP-10/11 + all spec edge cases (OWASP table) | `frontend/modules/auth/bff/return-url.test.ts` | `make test-frontend` |
| Allowlist + upstream | unit | CP-08/09/14; empty prod table; no arbitrary URL; timeout | `frontend/modules/auth/bff/allowlist.test.ts`, `upstream.test.ts` | `make test-frontend` |
| Private response helpers | unit | CP-12; 403 includes private no-store | `frontend/modules/auth/bff/private-response.test.ts` | `make test-frontend` |
| Mutation guard | unit | CP-13; compose failures; mock SessionLoader | `frontend/modules/auth/bff/mutation-guard.test.ts` | `make test-frontend` |
| Probe Route Handler | unit (Route Handler) | POST guard matrix; 404 in production NODE_ENV | `frontend/app/api/bff/_probe/**/*.test.ts` | `make test-frontend` |
| Env / compose docs | none | — checklist in T11 | — | `make lint-frontend` |
| Barrel `index.ts` | none | — typecheck gate | — | `make lint-frontend` |

## Gate Check Commands

> Generated from codebase — confirm before Execute.

| Gate Level | When to Use | Command |
| ---------- | ----------- | ------- |
| Quick | Após tasks com unit tests isolados (T1–T10) | `make test-frontend` |
| Full | Após probe route (T10) | `make test-frontend` |
| Build | Após T11 ou fechamento de fatia | `make lint-frontend && make test-frontend` |
| Coverage | Task T11 | `make test-frontend-coverage` — **`modules/auth/bff/**` ≥ 80%** linhas/branches (spec fatia; ajustar `vitest.config.ts` se necessário) |

---

## Execution Plan

Phases are ordered and run sequentially — each phase completes before the next begins, and tasks within a phase execute in order.

### Phase 1: Config e primitivos

```
T1 → T2 → T3 → T4
```

### Phase 2: CSRF, allowlist, guard

```
T5 → T6 → T7 → T8
```

### Phase 3: Upstream, probe, integração

```
T9 → T10 → T11
```

---

## Task Breakdown

### T1: Config BFF (`env.ts`) e crypto HMAC

**What**: Criar `env.ts` (origins, CSRF key, Laravel URL) e `crypto.ts` (HMAC base64url, timing-safe compare) com testes.
**Where**: `frontend/modules/auth/bff/env.ts`, `crypto.ts`, `env.test.ts`, `crypto.test.ts`, `types.ts` (tipos base)
**Depends on**: None
**Reuses**: `.env.example` URLs; Node `crypto`
**Requirement**: CP-04, CP-06 (base)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `getBffAppOrigin()` fallback `NEXT_PUBLIC_APP_URL`; throw se ambos ausentes em server test
- [ ] `getCsrfHmacKey()` exige ≥32 bytes
- [ ] `hmacSha256Base64Url` determinístico em teste fixo
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): add env and crypto helpers for csrf-proxy`

---

### T2: Respostas privadas (`private-response.ts`)

**What**: Helpers `applyPrivateCacheHeaders`, `jsonWithPrivateCache`, `forbiddenResponse`.
**Where**: `frontend/modules/auth/bff/private-response.ts`, `private-response.test.ts`
**Depends on**: T1
**Reuses**: `next/server`
**Requirement**: BFFUI-24, CP-12

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `Cache-Control` contém `private` e `no-store`
- [ ] `forbiddenResponse()` → status 403 + body `{ message: 'Forbidden.' }`
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): add private cache response helpers`

---

### T3: Validação Origin (`origin.ts`)

**What**: `validateMutationOrigin`, `isMutationMethod` com matriz de testes spec ACs.
**Where**: `frontend/modules/auth/bff/origin.ts`, `origin.test.ts`
**Depends on**: T1
**Reuses**: `env.getBffAppOrigin()`
**Requirement**: BFFUI-21, CP-01, CP-02

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Missing/null/wrong Origin → `{ ok: false }`; exact match → `{ ok: true }`
- [ ] GET não exige Origin por default
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): validate mutation Origin header`

---

### T4: Sanitização returnUrl (`return-url.ts`)

**What**: `sanitizeReturnUrl` com table-driven OWASP vectors da spec.
**Where**: `frontend/modules/auth/bff/return-url.ts`, `return-url.test.ts`
**Depends on**: None
**Reuses**: none
**Requirement**: BFFUI-23, CP-10, CP-11

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Paths seguros passam; absolute/protocol-relative/encoding maliciosa → fallback `/`
- [ ] null/undefined/empty/>2048 → fallback
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): add safe returnUrl sanitizer`

---

### T5: CSRF double-submit (`csrf.ts`)

**What**: Derivação HMAC, emissão cookies `__Host-fl_csrf` / `__Host-fl_csrf_sid`, validação double-submit, `issueCsrfForSession`.
**Where**: `frontend/modules/auth/bff/csrf.ts`, `csrf.test.ts`
**Depends on**: T1, T2
**Reuses**: `frontend/lib/session-cookie.ts` (`buildSessionCookieOptions`)
**Requirement**: BFFUI-22, CP-03, CP-04, CP-05, CP-06, CP-07

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Session + pre-auth modes aceitos/rejeitados conforme spec ACs
- [ ] Header/cookie mismatch → fail; rotate sessionId invalida token anterior
- [ ] Token cookie **not** HttpOnly; sid cookie HttpOnly
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): implement CSRF double-submit with session binding`

---

### T6: Allowlist estática (`allowlist.ts`)

**What**: Tipo `AllowlistEntry`, `AUTH_BFF_ALLOWLIST = []`, `lookupAllowlistEntry`, testes com tabela in-memory.
**Where**: `frontend/modules/auth/bff/allowlist.ts`, `allowlist.test.ts`
**Depends on**: T1
**Reuses**: none
**Requirement**: BFFUI-20, CP-08, CP-09

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Export produção length 0
- [ ] Testes provam lookup stub sem mutar export produção
- [ ] Nenhuma função pública aceita URL string arbitrária
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): add static BFF upstream allowlist`

---

### T7: Mutation guard (`mutation-guard.ts`)

**What**: `assertMutationGuard` compondo Origin, CSRF, sessão opcional via `SessionLoader` injetado.
**Where**: `frontend/modules/auth/bff/mutation-guard.ts`, `mutation-guard.test.ts`
**Depends on**: T2, T3, T5, T6
**Reuses**: all Phase 1–2 helpers
**Requirement**: CP-13

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `requireSession: true` sem loader/mock session → 403 privado
- [ ] Falhas Origin/CSRF retornam `forbiddenResponse` sem chamar upstream mock
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): add composed mutation guard`

---

### T8: Chamada upstream (`upstream.ts`)

**What**: `callAllowlistedUpstream` com Bearer server-side, timeout 10s, repasse status, headers privados.
**Where**: `frontend/modules/auth/bff/upstream.ts`, `upstream.test.ts`
**Depends on**: T1, T2, T6
**Reuses**: MSW ou `vi.stubGlobal('fetch')`
**Requirement**: CP-14

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Fetch mock recebe URL = `LARAVEL_INTERNAL_URL + entry.upstreamPath` only
- [ ] Authorization Bearer presente quando session ctx; **ausente** no JSON de retorno browser-facing helper
- [ ] Timeout abort → erro genérico 502/504
- [ ] Gate: `make test-frontend` passa

**Tests**: unit  
**Gate**: quick  
**Commit**: `feat(bff): add allowlisted upstream caller with timeout`

---

### T9: Barrel exports (`index.ts`)

**What**: Re-exportar API pública do módulo; atualizar `frontend/modules/auth/index.ts`.
**Where**: `frontend/modules/auth/bff/index.ts`, `frontend/modules/auth/index.ts`
**Depends on**: T1–T8
**Reuses**: design barrel pattern
**Requirement**: CP-15 (prepara consumo fatias 4–8)

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] `@/modules/auth/bff` resolve todos exports documentados no design
- [ ] Gate: `make lint-frontend` passa (tsc)

**Tests**: none  
**Gate**: build (`make lint-frontend`)  
**Commit**: `feat(bff): export csrf-proxy public API`

---

### T10: Probe Route Handler (dev/test)

**What**: `POST app/api/bff/_probe/mutate/route.ts` com allowlist local, guard + upstream mock; testes Route Handler; 404 em production.
**Where**: `frontend/app/api/bff/_probe/mutate/route.ts`, `route.test.ts`
**Depends on**: T7, T8, T9
**Reuses**: `frontend/app/health/route.test.ts` pattern
**Requirement**: CP-09 (probe), CP-15

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Vitest cobre happy guard reject paths
- [ ] `NODE_ENV=production` → 404
- [ ] Gate: `make test-frontend` passa

**Tests**: unit (Route Handler)  
**Gate**: full  
**Commit**: `feat(bff): add dev-only probe mutation route`

---

### T11: Env compose, cobertura 80%, README índice

**What**: Documentar `BFF_APP_ORIGIN`, `BFF_CSRF_HMAC_KEY`, `LARAVEL_INTERNAL_URL` em `.env.example` + `docker-compose.yml` frontend; ajustar coverage threshold `modules/auth/bff/**` ≥80%; atualizar traceability spec se necessário.
**Where**: `.env.example`, `docker-compose.yml`, `frontend/vitest.config.ts` (ou script coverage scoped), `.specs/features/bff-auth/README.md`
**Depends on**: T10
**Reuses**: Makefile `test-frontend-coverage`
**Requirement**: CP-15, Success Criteria spec

**Tools**:

- MCP: NONE
- Skill: `tlc-spec-driven`

**Done when**:

- [ ] Env vars documentadas com exemplo local (`http://nginx/api/v1`)
- [ ] `make test-frontend-coverage` passa com ≥80% em `modules/auth/bff/**`
- [ ] `make lint-frontend && make test-frontend` verdes
- [ ] README fatia 3: Execute ⏳ ready

**Tests**: none (coverage gate)  
**Gate**: build + coverage  
**Commit**: `chore(bff): document csrf env vars and enforce bff coverage gate`

---

## Phase Execution Map

```
Phase 1:  T1 ──→ T2 ──→ T3 ──→ T4
Phase 2:  T5 ──→ T6 ──→ T7 ──→ T8
Phase 3:  T9 ──→ T10 ──→ T11
```

Execution is strictly sequential — one task at a time, in order.

**Batch packing (~2 workers):**

- **Batch 1:** Phase 1 + Phase 2 (T1–T8) — 8 tasks  
- **Batch 2:** Phase 3 (T9–T11) — 3 tasks  

---

## Task Granularity Check

| Task | Scope | Status |
| --- | --- | --- |
| T1: env + crypto | 2 arquivos coesos (config) | ✅ Granular |
| T2: private-response | 1 módulo | ✅ Granular |
| T3: origin | 1 módulo | ✅ Granular |
| T4: return-url | 1 módulo | ✅ Granular |
| T5: csrf | 1 módulo | ✅ Granular |
| T6: allowlist | 1 módulo | ✅ Granular |
| T7: mutation-guard | 1 módulo | ✅ Granular |
| T8: upstream | 1 módulo | ✅ Granular |
| T9: barrel | 2 arquivos export | ✅ Granular |
| T10: probe route | 1 route + test | ✅ Granular |
| T11: env + coverage | config/docs | ✅ Granular |

---

## Diagram-Definition Cross-Check

| Task | Depends On (task body) | Diagram Shows | Status |
| --- | --- | --- | --- |
| T1 | None | (start) | ✅ Match |
| T2 | T1 | T1 → T2 | ✅ Match |
| T3 | T1 | T1 → T3 | ✅ Match |
| T4 | None | parallel start | ✅ Match |
| T5 | T1, T2 | T2 → T5 | ✅ Match |
| T6 | T1 | T1 → T6 | ✅ Match |
| T7 | T2, T3, T5, T6 | T3,T5,T6 → T7 | ✅ Match |
| T8 | T1, T2, T6 | T2,T6 → T8 | ✅ Match |
| T9 | T1–T8 | T8 → T9 | ✅ Match |
| T10 | T7, T8, T9 | T9 → T10 | ✅ Match |
| T11 | T10 | T10 → T11 | ✅ Match |

---

## Test Co-location Validation

| Task | Code Layer Created/Modified | Matrix Requires | Task Says | Status |
| --- | --- | --- | --- | --- |
| T1 | env + crypto | unit | unit | ✅ OK |
| T2 | private-response | unit | unit | ✅ OK |
| T3 | origin | unit | unit | ✅ OK |
| T4 | return-url | unit | unit | ✅ OK |
| T5 | csrf | unit | unit | ✅ OK |
| T6 | allowlist | unit | unit | ✅ OK |
| T7 | mutation-guard | unit | unit | ✅ OK |
| T8 | upstream | unit | unit | ✅ OK |
| T9 | barrel | none | none | ✅ OK |
| T10 | probe Route Handler | unit (Route Handler) | unit (Route Handler) | ✅ OK |
| T11 | env/vitest config | none | none | ✅ OK |

---

## MCPs e Skills (confirmar antes de Execute)

| Task | MCP sugerido | Skill |
| --- | --- | --- |
| T1–T11 | NONE (Docker/Makefile) | `tlc-spec-driven` |
| T8, T10 | opcional: Context7 Next.js Route Handlers | — |
