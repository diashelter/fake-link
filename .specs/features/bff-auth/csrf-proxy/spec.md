# BFF Auth — CSRF e proxy

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 3 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-20 … BFFUI-24  
**Requirement IDs (fatia):** CP-01 … CP-10  
**Depende de:** [session-core](../session-core/spec.md)

## Problem Statement

Mutations do browser oficial precisam de proteção CSRF e o BFF não pode ser um proxy genérico. Esta fatia entrega o framework de Origin, double-submit CSRF, allowlist estática e sanitização de `returnUrl` usado por todos os handlers Auth seguintes.

## Goals

- [ ] Middleware/helpers: `Origin` presente e igual ao App host HTTPS; rejeitar `null`/divergente.
- [ ] CSRF double-submit: valor vinculado à sessão por HMAC; comparação em tempo constante.
- [ ] Allowlist estática `(method, bffPath) → laravelPath` — parâmetros nunca escolhem URL upstream.
- [ ] `returnUrl` / redirect pós-auth: somente caminhos internos seguros (sem absolute, protocol-relative, encoding ambíguo, host externo).
- [ ] Respostas privadas BFF: `Cache-Control: private, no-store`.
- [ ] Vitest cobre aceitar/rejeitar Origin, CSRF, returnUrl e ausência de open redirect.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Handlers de produto (login, etc.) | Fatias 4–8 — **consomem** estes helpers |
| UI | Fatias 4–8 |
| Rate limiting BFF adicional | Deepen; API já limita upstream |
| Playwright full suite | Fatia `e2e-security-gate` |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| CSRF em GETs seguros | Não exigir CSRF em GET idempotente de leitura; exigir em POST/PATCH/DELETE | Padrão web + SameSite=Lax | n — deepen |
| Entrega do token CSRF | Cookie não-HttpOnly separado **ou** header derivado — escolher no deepen | Security exige double-submit + HMAC à sessão | n — deepen |
| Allowlist inicial | Tabela vazia + stubs de teste; rotas reais preenchidas nas fatias 4–8 | Evita proxy prematuro | y |
| App host | Config env (ex.: `APP_ORIGIN=https://app.localhost`) | Compose local HTTPS | y |
| Orçamento proxy → Laravel | 10s | `docs/architecture.md` §8 | y |

**Open questions:** mecanismo exato do double-submit; se CSRF cookie usa `__Host-` também; mapa inicial de rotas no design.

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | Origin exact match; returnUrl path-only allowlist |
| Failure | 403/401 genéricos sem vazar regra interna | 
| Idempotency | N/A no framework |
| Auth boundaries | Mutations exigem sessão válida **quando** a rota exigir cookie |
| Concurrency | N/A |
| Data lifecycle | CSRF token rotaciona com session id |
| Observability | Sem logar CSRF secrets / Bearer |
| External-dependency | Timeout 10s ao chamar Laravel (usado pelas fatias seguintes) |
| State-transition | N/A |

---

## User Stories

### P1: Proteger mutations e redirects ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN mutation sem Origin válido THEN BFF SHALL rejeitar sem chamar Laravel.
2. WHEN mutation com CSRF inválido/ausente THEN BFF SHALL rejeitar com comparação constant-time.
3. WHEN código tenta montar upstream fora da allowlist THEN SHALL ser impossível (compile-time/table-driven).
4. WHEN `returnUrl` é absoluto ou externo THEN SHALL ser rejeitado ou substituído por default interno seguro.
5. WHEN resposta privada é emitida THEN `Cache-Control` SHALL incluir `private, no-store`.

**Independent Test:** Vitest matrix Origin/CSRF/returnUrl; tentativa de open redirect falha.

---

## Deepen checklist

- [ ] Fechar transporte do CSRF token
- [ ] Esboçar allowlist completa esperada (mesmo que preenchida depois)
- [ ] ACs de códigos HTTP exatos
- [ ] Status → Approved

## Referências

- `docs/security.md` §5.3  
- `docs/testing.md` §6.2  
- `docs/architecture.md` §8
