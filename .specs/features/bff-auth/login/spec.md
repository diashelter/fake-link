# BFF Auth — Login

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 4 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-30 … BFFUI-32  
**Requirement IDs (fatia):** LOG-01 … LOG-10  
**Depende de:** [csrf-proxy](../csrf-proxy/spec.md)  
**Upstream API:** `POST /api/v1/auth/login` (Auth API verified)

## Problem Statement

Usuários com conta precisam autenticar no browser oficial sem jamais ver o Bearer. O BFF deve chamar o login Laravel, criar sessão BFF (cookie + Redis) e a UI deve oferecer o fluxo de login em pt-BR com erros alinhados à API.

## Goals

- [ ] Route Handler BFF de login na allowlist: valida CSRF/Origin, chama API, emite sessão BFF, **não** inclui Bearer na resposta ao browser.
- [ ] UI server-first `/login` (ou rota acordada) com RHF+Zod; suporte a `returnUrl` seguro.
- [ ] Comportamento por status espelha API: `session` vs `verification`; `suspended`/`deletion_pending` bloqueados; credencial inválida anti-enum.
- [ ] Vitest/RTL cobrem happy path, erros e ausência de Bearer em HTML/JSON do BFF.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Register / verify / password | Fatias 5–7 |
| Logout / me / perfil | Fatia `session-shell` |
| Playwright full security gate | Fatia 9 |
| MFA | Pós-MVP |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Path BFF | `/api/bff/auth/login` (ou equivalente sob App Router `app/api/...`) | Separar de `/api/v1` Laravel | n — deepen |
| Path UI | `/login` | Product §8 | n — deepen |
| Pós-login `active` | Redirect dashboard placeholder ou `/` até Links | Links Fase 2 | n — deepen |
| Pós-login `pending_verification` | Redirect para fluxo de verificação | Product §3 | y |
| Mensagens | pt-BR; códigos/erros mapeados sem vazar allowlist | Product UI | y |
| Rate limit UI | Confiar no 429 da API + exibir Retry-After | Evitar duplicar Redis keys no BFF nesta fase | n — deepen |

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | Zod espelha LoginRequest OpenAPI (email, password max) |
| Failure | 401/403/422/429 mapeados; 5xx genérico |
| Idempotency | Novo login = nova sessão BFF + rotate id |
| Auth boundaries | Público; CSRF se mutation |
| Concurrency | Dois logins → duas sessões BFF (multi-sessão API) |
| Data lifecycle | Cookie TTL alinhado ao kind emitido |
| Observability | Sem e-mail/senha/Bearer em logs |
| External-dependency | Laravel timeout/5xx → erro genérico; sem cookie parcial |
| State-transition | Não altera User; só cria sessão BFF |

---

## User Stories

### P1: Login via BFF + UI ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN credenciais válidas de user `active` THEN BFF SHALL setar cookie de sessão e responder sem Bearer; UI SHALL redirecionar para destino interno seguro.
2. WHEN user `pending_verification` THEN sessão BFF SHALL usar TTL/idle de verification e UI SHALL encaminhar ao fluxo restrito.
3. WHEN credenciais inválidas THEN UI/BFF SHALL apresentar erro genérico equivalente à API (anti-enum).
4. WHEN inspeção da resposta BFF / HTML / storage THEN Bearer SHALL NOT aparecer.

**Independent Test:** Vitest Route Handler + RTL form; MSW stub da API login.

---

## Deepen checklist

- [ ] Paths BFF/UI finais
- [ ] Destino pós-login até existir dashboard
- [ ] Matriz completa de status/erros
- [ ] Status → Approved

## Referências

- `.specs/features/auth/login/spec.md`  
- `docs/openapi.yaml` `login`  
- `docs/security.md` §5  
- `docs/product.md` §3, §8
