# Auth — Senha (alterar e recuperar)

**Status:** Pendente — spec a detalhar antes do Execute  
**Fatia:** 6 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-26 … AUTH-29, AUTH-32, AUTH-33 (parcial)  
**Depende de:** [bearer-tokens](../bearer-tokens/spec.md), [login](../login/spec.md)

## Escopo previsto

Alteração autenticada, solicitação e conclusão de reset com anti-enumeração e revogação total de tokens.

### Endpoints

- `POST /api/v1/auth/password/change` → `204` (token `session`)
- `POST /api/v1/auth/password/reset-request` → `202` (público)
- `POST /api/v1/auth/password/reset` → `204` (público)

### Entregáveis (rascunho)

- Reutilizar `email_action_tokens` com purpose de reset (30 min, uso único)
- Use cases: solicitar reset, consumir reset, alterar senha autenticado
- Resposta uniforme `202` no reset-request
- Revogação de todos os Bearer após change/reset
- Rate limits conforme índice

### Referências

- `docs/openapi.yaml` — `changePassword`, `requestPasswordReset`, `resetPassword`
- `docs/testing.md` §6.1 (tokens de recuperação, revogação)
