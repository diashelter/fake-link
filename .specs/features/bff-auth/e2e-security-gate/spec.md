# BFF Auth — Gate E2E de segurança

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 9 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-80 … BFFUI-83  
**Requirement IDs (fatia):** E2E-01 … E2E-12  
**Depende de:** [login](../login/spec.md), [register](../register/spec.md), [email-verification](../email-verification/spec.md), [password](../password/spec.md), [session-shell](../session-shell/spec.md)

## Problem Statement

As fatias 1–8 entregam comportamento com Vitest/RTL. Os critérios de saída da Fase 1 exigem prova em composição real: Bearer nunca vaza para o browser, CSRF/cookie/Redis/expirações funcionam ponta a ponta, e fluxos Auth críticos passam em acessibilidade.

## Goals

- [ ] Suite Playwright contra stack Docker (app HTTPS, API, Redis efêmero) cobrindo jornada: register → verify → login → logout / logout-all; forgot/reset.
- [ ] Asserções de segurança: Bearer ausente em HTML, JS bundle samples, cookies (exceto sessão opaca), `localStorage`/`sessionStorage`/IndexedDB, URLs, responses BFF.
- [ ] Casos: CSRF rejeitado; Origin inválido; returnUrl maligno; flush Redis → logout; idle/absoluto (com relógio controlado ou TTLs de teste).
- [ ] `axe` sem violações de impacto relevante nos fluxos Auth; reflow 360px smoke.
- [ ] Gate Makefile/CI documentado (`make test-e2e-auth` ou equivalente) verde como critério de saída Fase 1 Auth+BFF.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Novas features de produto | Somente verificação agregada |
| BrowserStack matrix completa | Pré-release (`docs/testing.md` §3.3); smoke local nesta fatia |
| E2E Links/Analytics | Fase 2+ |
| Snapshots visuais extensos | Só estados críticos estáveis se deepen exigir |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Ambiente | Compose profile test/e2e com HTTPS app.localhost | Paridade prod-like | n — deepen |
| Conta de teste | Seed allowlist + factory/API admin ou register harness | Determinismo | n — deepen |
| Relógio / TTL | Env de teste com TTLs curtos **ou** freeze controlado | Idle/absoluto testáveis | n — deepen |
| Escopo axe | login, register, verify, forgot, reset, account | WCAG 2.2 AA crítico | y |
| Falha bloqueante | Qualquer vazamento Bearer ou CSRF bypass falha o gate | Roadmap Fase 1 exit | y |

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | N/A — consome produto |
| Failure | Testes cobrem caminhos de erro críticos |
| Idempotency | N/A |
| Auth boundaries | Matriz session vs verification |
| Concurrency | Opcional smoke logout-all |
| Data lifecycle | Redis flush + expiry |
| Observability | Testes não devem logar secrets nos artifacts CI |
| External-dependency | Resend fake/determinístico; sem rede externa flaky |
| State-transition | Jornada completa pending → active → session |

---

## User Stories

### P1: Critérios de saída Fase 1 ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN a suíte E2E Auth roda THEN jornada convidado → verificado → autenticado → logout SHALL passar.
2. WHEN artifacts/browser state são inspecionados THEN nenhum Bearer Laravel SHALL ser encontrado.
3. WHEN CSRF/Origin/returnUrl inválidos THEN mutations SHALL falhar.
4. WHEN Redis efêmero é flushed mid-session THEN usuário SHALL aparecer deslogado sem fallback de token.
5. WHEN axe roda nos fluxos críticos THEN SHALL NOT haver violações de impacto relevante conhecidas.

**Independent Test:** `make test-e2e-auth` (nome a fechar) exit 0 na CI local Docker.

---

## Deepen checklist

- [ ] Nome do gate Makefile + wiring CI
- [ ] Estratégia TTL/relógio
- [ ] Lista exata de asserts de vazamento
- [ ] Status → Approved

## Referências

- `docs/roadmap.md` Fase 1 critérios de saída  
- `docs/testing.md` §3.2–3.4, §6.2  
- `docs/security.md` §5, checklist §17
