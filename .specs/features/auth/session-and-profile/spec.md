# Auth — Sessão e perfil

**Status:** Pendente — spec a detalhar antes do Execute  
**Fatia:** 7 de 7 — ver [índice](../README.md)  
**Requirement IDs:** AUTH-30, AUTH-31, AUTH-33 (parcial), AUTH-34 … AUTH-36  
**Depende de:** [bearer-tokens](../bearer-tokens/spec.md), [login](../login/spec.md)

## Escopo previsto

Encerramento de sessão (atual e global) e perfil mínimo do usuário autenticado.

### Endpoints

- `POST /api/v1/auth/logout` → `204`
- `POST /api/v1/auth/logout-all` → `204`
- `GET /api/v1/me` → `200`
- `PATCH /api/v1/me` → `200`

### Entregáveis (rascunho)

- Logout revoga somente token corrente (`session` ou `verification`)
- Logout-all exige `session` + `current_password`; revoga todos os tokens
- `GET /me` aceita `session` ou `verification`
- `PATCH /me` aceita somente `name`; e-mail imutável
- Resource `User` alinhado à OpenAPI

### Referências

- `docs/openapi.yaml` — `logout`, `logoutAll`, `getCurrentUser`, `updateCurrentUser`
- `docs/testing.md` §6.1 (logout, perfil)
