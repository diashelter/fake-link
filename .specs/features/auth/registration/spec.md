# Auth — Registro por convite

**Status:** Pendente — spec a detalhar antes do Execute  
**Fatia:** 3 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-01 … AUTH-05  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md)

## Escopo previsto

Cadastro invite-only com allowlist, aceite de termos, anti-enumeração e emissão de token `verification`.

### Endpoint

- `POST /api/v1/auth/register` → `201`

### Entregáveis (rascunho)

- Port `InviteAllowlist` (fonte configurável; SOPS em produção)
- Use case `RegisterUser`
- Form Request, Controller fino, Resource de resposta alinhados à OpenAPI
- Rate limit: 5/h por IP
- Job de e-mail de verificação (pode delegar envio à fatia `email-verification` se preferir stub inicial)

### Referências

- `docs/openapi.yaml` — `register`, `RegisterRequest`
- `docs/product.md` §3
- `docs/testing.md` §6.1 (convite e enumeração)
