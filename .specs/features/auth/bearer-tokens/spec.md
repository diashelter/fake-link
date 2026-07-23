# Auth — Tokens Bearer

**Status:** Pendente — spec a detalhar antes do Execute  
**Fatia:** 2 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-13 … AUTH-19, AUTH-33 (parcial), AUTH-37, AUTH-38  
**Depende de:** [foundation](../foundation/spec.md)

## Escopo previsto

Infraestrutura compartilhada de tokens Bearer usada por todas as fatias com endpoints autenticados.

### Entregáveis (rascunho)

- Migration `auth_tokens`
- Use cases: emitir, validar, revogar um token, revogar todos por usuário
- Middleware/guard: `Authorization: Bearer`, TTL absoluto, idle expiry, throttle `last_used_at`
- Middleware ou atributo de rota: `token_kind` permitido (`session` / `verification`)
- Contrato exportável: identidade autenticada para outros módulos

### Endpoints

Nenhum endpoint público novo — apenas capacidade consumida pelas fatias 3–7.

### Referências

- `docs/api.md` §3.1
- `docs/security.md` §6
- `docs/data-model.md` (`auth_tokens`)
