# Auth — Senha — Context

**Gathered:** 2026-07-28  
**Spec:** `.specs/features/auth/password/spec.md`  
**Status:** Ready for design — decisions locked; design.md drafted

---

## Feature Boundary

API Laravel Auth: alteração autenticada de senha (`POST /password/change`) e recuperação pública (`POST /password/reset-request`, `POST /password/reset`), reutilizando `email_action_tokens` com purpose `password_reset`, Resend via job, anti-enumeração e revogação total de Bearers. Fora: BFF, UI, logout/`me`, Operations.

---

## Implementation Decisions

### Elegibilidade do e-mail de reset

- Somente `User.status = active` recebe token + e-mail.
- Demais status e e-mail inexistente → mesmo `202` sem side effects.

### Erro de token de reset inválido

- `422 VALIDATION_FAILED` no campo `token`.
- Message: `The password reset token is invalid or has expired.`
- Sem código `403` novo.

### Change — senha atual incorreta

- `401 INVALID_CREDENTIALS` com message idêntica ao login.

### Invalidação em novo reset-request

- Emite novo token e marca como usados todos os `password_reset` não usados anteriores do mesmo usuário.

### URL do e-mail

- `{APP_URL}/reset-password?token={plaintext}` (e-mail informado de novo no form do frontend).

### Nova senha ≠ atual

- Proibido reutilizar a senha atual em **change e reset**.
- Envelope: `422 VALIDATION_FAILED` com `errors.password[]` contendo `code=PASSWORD_REUSED` e `message=The new password must be different from the current password.`
- Documentar `PASSWORD_REUSED` no OpenAPI nesta fatia.

### Timing no reset-request

- Dummy `PasswordHasher::verify` contra hash Argon2id pré-computado (paridade com login).

### Agent's Discretion

- Purpose `password_reset` + migration CHECK (estrutural).
- Falha de enqueue pós-commit: token permanece; re-request recupera (paridade EV).

### Declined / Undiscussed Gray Areas → Assumptions

- None remaining — all gray areas resolved 2026-07-28.

---

## Specific References

- Paridade explícita com fatia `email-verification` (invalidação, job cifrado, Resend, privacidade).
- OpenAPI design-first para os três endpoints; field code `PASSWORD_REUSED` novo nesta fatia.

---

## Deferred Ideas

- None — discussion stayed within feature scope.
