# BFF Auth — Sessão e shell

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 8 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-70 … BFFUI-74  
**Requirement IDs (fatia):** SP-01 … SP-12  
**Depende de:** [login](../login/spec.md)  
**Upstream API:** `POST /logout`, `POST /logout-all`, `GET/PATCH /me`

## Problem Statement

Usuários autenticados precisam encerrar a sessão atual ou todas, ver/editar o nome do perfil e navegar sob guards que respeitam sessão completa vs restrita — com logout seguro mesmo se Redis ou Laravel falharem.

## Goals

- [ ] BFF logout: sempre expira cookie; tenta apagar Redis + revogar Bearer; falha remota = best-effort + métrica/alerta (sem fila).
- [ ] BFF logout-all: session kind + senha; limpa sessão local; API revoga todos tokens.
- [ ] BFF me GET/PATCH: proxy allowlist; PATCH só `name`.
- [ ] UI mínima: perfil (nome), logout, logout-all; shell autenticado placeholder (pré-Links).
- [ ] Guards: não autenticado → login; verification → só rotas restritas; active → shell.
- [ ] Vitest para best-effort logout e guards.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Dashboard Links | Fase 2 |
| Change password UI | Fatia `password` (se não movida) |
| Lista de sessões/dispositivos | Fora do produto |
| Playwright gate completo | Fatia 9 |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path perfil | `/account` ou `/settings` | Deepen | n — deepen |
| Shell autenticado | Layout com nav mínima + logout | Até Links existir | y |
| Logout Redis down | Clear cookie + tentar revoke API; métrica | Security §5.2 | y |
| Logout API down | Clear cookie + delete Redis best-effort; métrica | Security §5.2 | y |
| GET me com verification | Permitido pela API; UI restrita usa para status | Auth session-and-profile | y |

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | PATCH name 1–120 trim; logout-all password required |
| Failure | 401/403/422; logout sempre limpa cookie mesmo se upstream falhar |
| Idempotency | Segundo logout → já deslogado (redirect login) |
| Auth boundaries | logout aceita verification+session; logout-all/PATCH só session |
| Concurrency | logout-all concorrente → zero tokens (API) |
| Data lifecycle | Cookie/Redis removidos no logout |
| Observability | Métricas de best-effort fail; sem senha/Bearer em logs |
| External-dependency | Redis/API fail não bloqueiam clear cookie |
| State-transition | PATCH não muda email/status |

---

## User Stories

### P1: Logout, perfil e guards ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN logout THEN cookie SHALL expirar e Bearer SHALL NOT vazar; tentativas Redis/API são best-effort.
2. WHEN logout-all com senha correta THEN todas sessões API invalidam e browser fica deslogado.
3. WHEN PATCH name válido THEN UI/BFF SHALL refletir novo nome sem alterar e-mail.
4. WHEN usuário verification acessa rota de shell completo THEN SHALL ser bloqueado/redirecionado.
5. WHEN Redis flush mid-session THEN próximo request SHALL tratar como deslogado (sem Bearer fallback).

**Independent Test:** Vitest handlers com Redis/API stubs falhando; RTL perfil/guards.

---

## Deepen checklist

- [ ] IA de rotas e layout shell
- [ ] Contrato de métricas/alertas best-effort
- [ ] Fronteira com fatia password (change)
- [ ] Status → Approved

## Referências

- `.specs/features/auth/session-and-profile/spec.md`  
- `docs/security.md` §5.2  
- `docs/testing.md` §6.2  
- `docs/product.md` §3
