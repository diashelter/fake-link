# Auth — Sessão e perfil — Context

**Gathered:** 2026-07-29  
**Spec:** `.specs/features/auth/session-and-profile/spec.md`  
**Status:** Ready for design — decisions locked; design.md drafted

---

## Feature Boundary

API Laravel Auth: encerramento de sessão (`POST /auth/logout`, `POST /auth/logout-all`) e perfil mínimo (`GET/PATCH /api/v1/me`). Revoga Bearers; não invalida sessão BFF/Redis. Fora: BFF, UI, devices, alteração de e-mail/senha, Operations.

---

## Implementation Decisions

### Body em logout (Q1=A)

- Body ausente ou JSON vazio `{}` aceito.
- Campos extras → `422 VALIDATION_FAILED`.

### Normalização de nome no PATCH (Q2=A)

- Trim de espaços externos antes de validar e persistir.
- Vazio após trim → `422 VALIDATION_FAILED`.
- Valor persistido é o trimado (ex.: `"  Ana  "` → `"Ana"`).

### PATCH no-op (Q3=A)

- Nome idêntico ao atual (após trim) → `200` com `UserResponse`.
- **Não** bumpa `updated_at` (sem write desnecessário).

### Throttle de GET /me (Q4=A)

- Novo middleware de leituras privadas: 300/min por token.
- Chave Redis: HMAC do hash do token (não o plaintext).
- Janela 60s; conta todas as tentativas na rota.

### Logout-all — senha incorreta

- `401 INVALID_CREDENTIALS` + message do login; sem revogar tokens.
- Paridade com `POST /password/change`.

### Agent's Discretion

- Reutilizar `RevokeAuthToken` / `RevokeAllUserTokens`.
- Forma exata de registrar rotas `/me` sob `api/v1` (arquivo de rotas separado vs. grupo extra no provider).
- Nome do middleware alias (`throttle.private_auth.read` ou equivalente).

### Declined / Undiscussed Gray Areas → Assumptions

- None remaining — all gray areas resolved 2026-07-29.

---

## Specific References

- Paridade com fatia `password` para `INVALID_CREDENTIALS` e write throttle.
- OpenAPI design-first já publica os quatro endpoints.
- `AuthUserResource` existente deve receber `created_at`/`updated_at` reais da persistência.

---

## Deferred Ideas

- None — discussion stayed within feature scope.
