# BFF Auth — Sessão e shell — Context

**Gathered:** 2026-08-19  
**Spec:** `.specs/features/bff-auth/session-shell/spec.md`  
**Status:** Ready for design — spec Approved

---

## Feature Boundary

Route Handlers BFF `logout`, `logout-all`, `GET/PATCH me`; UI `/settings` (nome, e-mail leitura, logout-all, link senha); shell autenticado em `/` e `/settings*`; “Sair” em `/verify-email`; guards guest / `verification` / `session`. Sem Links, Playwright ou lista de dispositivos.

---

## Implementation Decisions

### Conta e navegação

- Perfil em `/settings`; logout-all na mesma página.
- Nav: Início `/`, Conta `/settings`, Sair (POST logout).
- `/` guest = landing atual; `/` com `session` = shell + placeholder “em breve”.
- `/settings/password` usa o mesmo shell.

### Logout e falhas

- Logout sempre limpa cookie + CSRF cookies; Redis/API fail = best-effort.
- Sessão miss no logout: Origin obrigatório, CSRF dispensado, `200` local.
- Logout-all só limpa sessão após `204` upstream.

### Formulários e erros

- `400` local para body inválido; `403` genérico sem sessão em GET/PATCH/logout-all.
- `router.push('/login')` sem flash query.
- 429 com e sem `Retry-After` (copies distintas).
- Submits: `Content-Type: application/json` + `X-CSRF-Token`.

### Agent's Discretion

- Copy exata do placeholder da home (pt-BR, tema claro, 360px).
- Agrupamento visual das seções em `/settings` (perfil / senha / encerrar sessões).

### Declined / Undiscussed Gray Areas → Assumptions

Todas registradas na spec (tabela Assumptions, Confirmed = y). Nenhuma permanece aberta.

---

## Specific References

- `docs/security.md` §5.2 — logout best-effort sem fila.
- `/settings/password` já Verified (password slice).
- `resolveVerificationSessionGuard` + `VERIFICATION_ALLOWED_PATHS`.

---

## Deferred Ideas

- Dashboard Links (Fase 2) substitui o placeholder de `/`.
- Playwright / axe — fatia `e2e-security-gate`.
- Export OTel dos contadores de logout — Fase 4.
