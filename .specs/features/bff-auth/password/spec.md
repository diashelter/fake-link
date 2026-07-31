# BFF Auth — Senha

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 7 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-60 … BFFUI-63  
**Requirement IDs (fatia):** PW-01 … PW-12  
**Depende de:** [login](../login/spec.md); idealmente [email-verification](../email-verification/spec.md)  
**Upstream API:** `password/reset-request`, `password/reset`, `password/change`

## Problem Statement

Usuários precisam recuperar acesso (forgot/reset) e alterar senha autenticados, com revogação de sessões — sempre via BFF, sem Bearer no browser, e com UX pt-BR alinhada aos contratos anti-enumeração da API.

## Goals

- [ ] BFF + UI **forgot**: sempre feedback uniforme (API 202); sem revelar existência de conta.
- [ ] BFF + UI **reset**: consome token de e-mail via POST; limpa sessão BFF local; API revoga todos Bearers.
- [ ] BFF + UI **change**: sessão `session` + CSRF; após sucesso encerra sessão BFF atual e exige novo login.
- [ ] Política de senha + `PASSWORD_REUSED` mapeados na UI.
- [ ] Vitest/RTL para os três fluxos e ausência de Bearer/token sensível em storage.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Jobs Resend | API Auth |
| Logout-all UI genérico | Fatia `session-shell` (change/reset já revogam) |
| Lista de dispositivos | Fora do produto |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Paths UI | `/forgot-password`, `/reset-password`, change no perfil | Product §8 | n — deepen |
| Token reset na URL | Query hidrata form; submit POST BFF | Paridade com verify | y |
| Pós-change/reset | Redirect login com mensagem | API revoga tokens | y |
| Change no shell | Pode viver em `/settings/password` entregue aqui ou session-shell — preferir **aqui** | Coesão password | n — deepen |

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | Password policy Zod; confirmation match |
| Failure | 422 field errors; 401 current_password; anti-enum no request |
| Idempotency | Token reset uso único |
| Auth boundaries | reset-request/reset públicos; change autenticado session |
| Concurrency | N/A |
| Data lifecycle | Encerrar cookie BFF após change/reset sucesso |
| Observability | Sem senhas/tokens em logs |
| External-dependency | API fail sem consumir UX como sucesso |
| State-transition | User.status inalterado no reset (API) |

---

## User Stories

### P1: Recuperar e alterar senha ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN forgot com qualquer e-mail sintaticamente válido THEN UI SHALL mostrar sucesso uniforme sem enumerar.
2. WHEN reset válido THEN senha atualiza, sessões invalidam, UI exige login; Bearer ausente no browser.
3. WHEN change com sessão válida THEN sucesso limpa sessão BFF e revoga via API.
4. WHEN nova senha = atual THEN UI SHALL refletir `PASSWORD_REUSED`.

**Independent Test:** MSW matrix + asserts de clear cookie.

---

## Deepen checklist

- [ ] Paths e se change fica nesta fatia ou session-shell
- [ ] Copy anti-enum
- [ ] Matriz OpenAPI completa
- [ ] Status → Approved

## Referências

- `.specs/features/auth/password/spec.md`  
- `docs/openapi.yaml` password operations  
- `docs/product.md` §3
