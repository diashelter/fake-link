# Auth — Tokens Bearer

**Status:** Fechada — confirmada 2026-07-26 (Verifier PASS)  
**Fatia:** 2 de 7 — ver [índice](../README.md)  
**Requirement IDs (catálogo):** AUTH-13 … AUTH-19, AUTH-33 (parcial), AUTH-37, AUTH-38  
**Requirement IDs (fatia):** BT-01 … BT-12  
**Depende de:** [foundation](../foundation/spec.md)

## Problem Statement

Fatias com endpoints autenticados (registro, login, verificação, senha, sessão/perfil) precisam de infraestrutura compartilhada de tokens Bearer: emissão, validação, revogação, TTL absoluto/idle, throttle de `last_used_at`, middleware HTTP e identidade exportável. Sem essa capacidade, cada fatia reimplementaria regras de segurança.

## Goals

- [x] Migration `auth_tokens` com hash único, `token_kind` CHECK e TTLs persistidos.
- [x] Use cases: emitir, validar, revogar um token, revogar todos por usuário.
- [x] Middleware `Authorization: Bearer` com TTL absoluto, idle expiry e throttle `last_used_at` ≤1×/15 min.
- [x] Middleware/atributo de rota `token.kind` (`session` / `verification`).
- [x] Contrato exportável de identidade autenticada para outros módulos.
- [x] Helper de ownership com `404` uniforme.
- [x] Feature tests descobertos pelo suite padrão (`phpunit.xml` / `make test-backend`).

## Out of Scope

| Item | Motivo |
| --- | --- |
| Endpoints públicos Auth (`/auth/*`, `/me`) | Fatias 3–7 |
| `email_action_tokens` | Fatias `email-verification` / `password` |
| HTTP logout / logout-all | Fatia `session-and-profile` (usa revoke já entregue) |
| BFF / cookies / CSRF | Fora do escopo backend desta fatia |
| MFA, device list, integration tokens | Fora do MVP |

## Referências

- `docs/api.md` §3.1
- `docs/security.md` §6–7
- `docs/data-model.md` (`auth_tokens`)
- `docs/testing.md` (Bearer TTL/idle/throttle)

---

## Acceptance Criteria

### P1: Token kinds and storage

| ID | Criterion | Spec-defined outcome |
| --- | --- | --- |
| AUTH-13 / BT-01 | WHEN a token is issued THEN its kind is only `verification` or `session` | Enum + DB CHECK; no other kinds |
| AUTH-14 / BT-02 | WHEN a token is persisted THEN only `token_hash` is stored | Plaintext equals hasher input only at issue response; DB hash ≠ plaintext |
| AUTH-15 / BT-03 | WHEN issuing `verification` THEN absolute TTL is 24h; WHEN issuing `session` THEN absolute TTL is 7d | `expires_at` = now + 86400 / 604800 seconds |
| AUTH-16 / BT-04 | WHEN idle exceeds kind limit THEN validation fails as unauthenticated | verification idle 3600s; session idle 86400s; reference `last_used_at` or `created_at` |

### P1: Validation and throttle

| ID | Criterion | Spec-defined outcome |
| --- | --- | --- |
| AUTH-17 / BT-05 | WHEN validating within 15 min of last touch THEN `last_used_at` is unchanged; WHEN ≥15 min THEN it updates | Throttle window 900 seconds; no write on expired/revoked paths |
| AUTH-18 / BT-06 | WHEN `Authorization: Bearer <valid>` THEN request proceeds with principal; WHEN missing/invalid/expired/revoked THEN `401` | Scheme must be exact `Bearer` (case-sensitive prefix per tests) |
| AUTH-19 / BT-07 | WHEN token kind is not allowed on the route THEN `403 TOKEN_RESTRICTED` | `session`-only, `verification`-only, and both-allowed routes |

### P1: Revocation, identity, ownership

| ID | Criterion | Spec-defined outcome |
| --- | --- | --- |
| AUTH-33 partial / BT-08 | WHEN `RevokeAllUserTokens` runs THEN all tokens for that user are deleted | Returns deleted count; second call returns 0 |
| AUTH-37 / BT-09 | WHEN middleware authenticates THEN `AuthenticatedPrincipal` is bound for other modules | Contract exposes user id, status, token kind (minimum) |
| AUTH-38 / BT-10 | WHEN principal does not own a resource THEN response is uniform `404 RESOURCE_NOT_FOUND` | No existence leak via distinct 403 |

### P1: Delivery constraints

| ID | Criterion | Spec-defined outcome |
| --- | --- | --- |
| BT-11 | WHEN `APP_ENV=testing` THEN probe routes `/api/v1/_test/auth/*` exist; WHEN not testing THEN they are absent | No public Auth product endpoints in this slice |
| BT-12 | WHEN `make test-backend` / bare `php artisan test` runs THEN `modules/Auth/Tests/Feature` is discovered | `phpunit.xml` Feature suite includes the directory |

---

## Edge Cases

- Absolute expiry rejects without updating `last_used_at`
- Idle expiry rejects without updating `last_used_at`
- Suspended / `deletion_pending` → `403` (not `401`)
- Lowercase `bearer` scheme rejected
- Session token + `pending_verification` user → `403 TOKEN_RESTRICTED` where required
- Plaintext token never appears in exception messages
- Ownership mismatch → `404`, not `403`

## Gate

- `make test-backend` must execute Unit + Integration + Feature Auth bearer tests (including `BearerMiddlewareTest`)
- Auth coverage gate remains applicable per `docs/testing.md`
