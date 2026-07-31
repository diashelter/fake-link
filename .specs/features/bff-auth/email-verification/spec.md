# BFF Auth — Verificação de e-mail

**Status:** Seed — deepen before Design/Tasks/Execute  
**Fatia:** 6 de 9 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** BFFUI-50 … BFFUI-52  
**Requirement IDs (fatia):** EV-01 … EV-10  
**Depende de:** [register](../register/spec.md)  
**Upstream API:** `POST …/email/verify`, `POST …/email/verification-notification`

## Problem Statement

Contas `pending_verification` precisam confirmar e-mail por ação explícita no browser (sem GET mágico que scanners ativem), reenviar o e-mail quando necessário, e permanecer em UX restrita até novo login com sessão completa.

## Goals

- [ ] BFF verify: exige sessão BFF verification; encaminha token de e-mail no body (não depende de GET com side-effect); limpa sessão BFF após sucesso (API revoga Bearer).
- [ ] BFF resend: rate-limit/erros da API refletidos na UI.
- [ ] UI verificação: botão/ação explícita; token pode vir de query **somente** para preencher formulário — submit é POST.
- [ ] UX restrita: rotas autenticadas de produto bloqueadas até `active` + novo login.
- [ ] Testes: scanner GET não verifica; POST sucesso → estado ativo e sessão BFF encerrada; novo login necessário.

## Out of Scope

| Item | Motivo |
| --- | --- |
| Envio Resend / jobs | API Auth |
| Login / register | Fatias 4–5 |
| Change password | Fatia `password` |
| Auto-login pós-verify | Proibido pela API (AUTH-12) |

---

## Assumptions & Open Questions

| Assumption / decision | Chosen default | Rationale | Confirmed? |
| --- | --- | --- | --- |
| Token na URL do e-mail | Query `?token=` só hidrata form; verify via POST BFF | Security / AUTH-22 / AUTH-25 | y |
| Pós-verify | Tela “e-mail confirmado — faça login” | API não emite session | y |
| Prefetch/scanner | GET página NÃO chama verify | Product + Auth spec | y |
| Path UI | `/verify-email` | Deepen | n — deepen |

---

## Implicit-Requirement Dimensions (seed)

| Dimension | Resolução preliminar |
| --- | --- |
| Input validation | Token required; sem trim destrutivo se API não trimar |
| Failure | Token inválido/expirado → erro UI; already verified → mensagem |
| Idempotency | Segundo verify falha (token used) |
| Auth boundaries | Só sessão verification; session kind → 403 TOKEN_RESTRICTED |
| Concurrency | N/A |
| Data lifecycle | Após verify, cookie BFF removido |
| Observability | Token de e-mail não em logs/access logs de app |
| External-dependency | API down → erro; não marcar verificado localmente |
| State-transition | pending → active só via API; UI reflete necessidade de login |

---

## User Stories

### P1: Verificar e reenviar ⭐ MVP

**Acceptance Criteria (seed):**

1. WHEN usuário com sessão verification submete token válido via POST BFF THEN conta ativa na API e sessão BFF SHALL ser encerrada; UI SHALL pedir novo login.
2. WHEN GET na página de verificação THEN SHALL NOT chamar endpoint de verify.
3. WHEN resend THEN UI SHALL mostrar sucesso genérico/202 e respeitar 429.
4. WHEN usuário pending tenta área autenticada de produto THEN SHALL ser redirecionado ao fluxo restrito.

**Independent Test:** Vitest handlers + RTL; MSW verify/resend.

---

## Deepen checklist

- [ ] Paths e copy pt-BR
- [ ] Integração exata query→form
- [ ] Guards compartilhados com session-shell (contrato)
- [ ] Status → Approved

## Referências

- `.specs/features/auth/email-verification/spec.md`  
- `docs/security.md` (prefetch / token em URL)  
- `docs/product.md` §3
