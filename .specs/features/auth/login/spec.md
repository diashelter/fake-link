# Auth — Login

**Status:** Pendente — spec a detalhar antes do Execute  
**Fatia:** 4 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-09 … AUTH-11  
**Depende de:** [foundation](../foundation/spec.md), [bearer-tokens](../bearer-tokens/spec.md)

## Escopo previsto

Autenticação por e-mail e senha com emissão de token conforme status da conta.

### Endpoint

- `POST /api/v1/auth/login` → `200`

### Comportamento chave

| Condição | Resultado |
| --- | --- |
| Credenciais inválidas | `401 INVALID_CREDENTIALS` (uniforme) |
| `pending_verification` + credenciais válidas | token `verification` |
| `active` + e-mail verificado | token `session` |
| `suspended` | `403 ACCOUNT_SUSPENDED` |
| `deletion_pending` | `403 ACCOUNT_PENDING_DELETION` |

### Entregáveis (rascunho)

- Use case `LoginUser`
- Rate limit: 5/min e-mail+IP; 30/min IP
- Testes: credencial inválida, cada status, timing observável uniforme

### Referências

- `docs/openapi.yaml` — `login`
- `docs/testing.md` §6.1 (credencial e status)
