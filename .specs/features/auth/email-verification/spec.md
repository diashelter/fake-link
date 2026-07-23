# Auth — Verificação de e-mail

**Status:** Pendente — spec a detalhar antes do Execute  
**Fatia:** 5 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-12, AUTH-20 … AUTH-25  
**Depende de:** [registration](../registration/spec.md), [login](../login/spec.md)

## Escopo previsto

Tokens de e-mail de uso único, verificação explícita por POST, reenvio e ativação da conta.

### Endpoints

- `POST /api/v1/auth/email/verify` → `204`
- `POST /api/v1/auth/email/verification-notification` → `202`

### Entregáveis (rascunho)

- Migration `email_action_tokens` (se ainda não existir)
- Use cases: enviar verificação, reenviar, consumir token e ativar conta
- Job Resend na fila `notifications` (token cifrado no payload do job)
- Revogação do Bearer `verification` após verify; **sem** emissão de `session`
- Rate limits: reenvio 3/h conta; verify 5/h conta

### Referências

- `docs/openapi.yaml` — `verifyEmail`, `resendEmailVerification`
- `docs/security.md` §4.3
- `docs/testing.md` §6.1 (POST explícito, expiração, scanner)
