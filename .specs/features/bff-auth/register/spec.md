# BFF Auth — Cadastro

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 5 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-40 … BFFUI-41  
**Requirement IDs (fatia):** REG-01 … REG-10  
**Depende de:** [login](../login/spec.md) (padrões de sessão/erro/UI)  
**Upstream API:** `POST /api/v1/auth/register`

## Problem Statement

Convidados elegíveis precisam criar conta pelo browser oficial, aceitar Terms versionados e receber sessão restrita — sem expor Bearer e sem revelar se o e-mail está na allowlist.

## Goals

- [ ] Route Handler BFF register (allowlist + CSRF): chama API, cria sessão BFF `verification`, resposta sem Bearer.
- [ ] UI cadastro + aceite explícito de Terms (versão + timestamp via API).
- [ ] Erros anti-enumeração alinhados à API (convite inválido ≡ e-mail duplicado genérico).
- [ ] Política de senha refletida no Zod client-side **e** erros server-side preservados.
- [ ] Testes Vitest/RTL do fluxo e da ausência de Bearer.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Allowlist server-side | Já na API Auth |
| Verificação de e-mail UI/handlers | Fatia `email-verification` |
| Login | Fatia `login` |
| Página jurídica completa Terms (conteúdo legal final) | Deepen / checklist jurídico; UI precisa aceite versionado |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path UI | `/register` | Product §8 | n — deepen |
| Fonte `terms_version` | Constante/config frontend alinhada à API | Product §3 | n — deepen |
| Pós-register | Redirect para verificação | Product jornada 1 | y |
| Página “convite” separada | Opcional; cadastro único com mensagem genérica | Roadmap menciona “convite” — deepen | n — deepen |

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | Espelhar RegisterRequest OpenAPI + Password policy |
| Failure | 422/4xx genéricos; 503 allowlist unavailable mapeado |
| Idempotency | Retry de submit: sem double-submit UI; API decide |
| Auth boundaries | Público + CSRF |
| Concurrency | N/A especial |
| Data lifecycle | Sessão verification TTL |
| Observability | Sem senha/Bearer/allowlist dump |
| External-dependency | API fail → sem cookie parcial |
| State-transition | Conta nasce `pending_verification` via API |

---

## User Stories

### P1: Cadastro por convite via BFF ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN cadastro válido THEN BFF SHALL criar sessão verification e UI SHALL ir ao fluxo de verificação **sem** Bearer no browser.
2. WHEN e-mail não convidado ou duplicado THEN mensagem SHALL ser genérica (anti-enum).
3. WHEN Terms não aceitos THEN submit SHALL falhar client e/ou server sem criar sessão.
4. WHEN senha viola política THEN erros SHALL aparecer sem vazar detalhes de infraestrutura.

**Independent Test:** MSW register 201/4xx + assert cookie set / Bearer absent.

---

## Deepen checklist

- [ ] Paths + versão de Terms
- [ ] Conteúdo mínimo da tela de Terms
- [ ] Matriz de erros OpenAPI
- [ ] Status → Approved

## Referências

- `.specs/features/auth/registration/spec.md`  
- `docs/product.md` §3  
- `docs/openapi.yaml` `register`
