# Auth Password Validation

**Date**: 2026-07-28  
**Spec**: `.specs/features/auth/password/spec.md`  
**Diff range**: `9c616f9..8b86955` (merge-base with `main` .. HEAD; first feature commit `e6dccec` … final `8b86955 docs(auth): document PASSWORD_REUSED validation error`; 18 commits)  
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status  | Notes |
| ---- | ------- | ----- |
| T1   | ✅ Done | All Done-when checkboxes `[x]` |
| T2   | ✅ Done | - |
| T3   | ✅ Done | - |
| T4   | ✅ Done | - |
| T5   | ✅ Done | - |
| T6   | ✅ Done | - |
| T7   | ✅ Done | - |
| T8   | ✅ Done | - |
| T9   | ✅ Done | - |
| T10  | ✅ Done | - |
| T11  | ✅ Done | - |
| T12  | ✅ Done | - |
| T13  | ✅ Done | - |
| T14  | ✅ Done | - |
| T15  | ✅ Done | - |
| T16  | ✅ Done | - |
| T17  | ✅ Done | - |
| T18  | ✅ Done | OpenAPI `PASSWORD_REUSED` + final commit `8b86955` |

---

## Spec-Anchored Acceptance Criteria

### P1: Solicitar recuperação (AUTH-26, AUTH-29, PW-01…04)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Reset-request body válido → 202 Accepted (anti-enum) | HTTP `202` | `PasswordResetTest.php:58` — `$response->assertAccepted()`; factory `AuthResponseFactoryTest.php:125` — `getStatusCode()->toBe(202)` | ✅ PASS |
| Active → token `password_reset`, TTL 1800, hash, 1 job | 1 token + purpose + 1 job on `notifications` | `PasswordResetTest.php:64-67` — `count()->toBe(1)` + `Queue::assertPushedOn('notifications', …)`; TTL `IssuePasswordResetTokenTest.php:44-49` — `absoluteTtlSeconds()->toBe(1800)` / `expires_at … 00:30:00` | ✅ PASS |
| Missing / non-active → 202 sem token/job | 0 tokens, nothing pushed | `PasswordResetTest.php:79-82` (unknown); `:97-101` (pending); integration `RequestPasswordResetTest.php:106-110` / `:125-128` / `:142-145` / `:159-162` | ✅ PASS |
| Novo request invalida tokens anteriores unused | previous `used_at` set | `IssuePasswordResetTokenTest.php:83-85` — `$firstModel?->used_at?->toIso8601String())->toBe('2026-01-01T00:00:00+00:00')` | ✅ PASS |
| Job envia URL com token só no corpo; sem plaintext em logs | URL `/reset-password?token=`; sentinel ausente | `SendPasswordResetJobTest.php:52-59` — `$mail->resetUrl === $expectedUrl` + subject sem sentinel; `:72-73` serialize; `:90-91` exception message | ✅ PASS |
| 4ª reset-request → 429 + Retry-After | `429 RATE_LIMIT_EXCEEDED` + `Retry-After` | `PasswordResetTest.php:123-125` — `assertStatus(429)` / `assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')` / `Retry-After` not null; unit `ThrottlePasswordMiddlewareTest.php:72-78` | ✅ PASS |
| Validação falha → 422 sem side effects | `422 VALIDATION_FAILED`; 0 tokens/jobs | `PasswordResetTest.php:137-141` — `assertUnprocessable()` / `code VALIDATION_FAILED` / `count()->toBe(0)` / `assertNothingPushed()` | ✅ PASS |
| Contador rate limit incrementa antes do use case | hit before `$next` (qualquer status da rota) | Middleware `ThrottlePasswordResetRequest.php:36` — `RateLimiter::hit` before `$next`; 4ª tentativa feature/unit proves counting. **No explicit assert that a `422` validation failure still increments** | ⚠️ Spec-precision gap |

### P1: Concluir reset (AUTH-27/28/33, PW-05…08, PW-17)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Valid reset → `204` | HTTP 204 empty | `PasswordResetTest.php:194` — `assertNoContent()`; `:197` — `getContent()->toBe('')` | ✅ PASS |
| Hash Argon2id novo + `used_at` na txn | verify new hash; `used_at` not null | `ResetPasswordTest.php:124-126` — `verify($newPassword, …)->toBeTrue()` / `$token?->used_at)->not->toBeNull()`; feature `:202-203` | ✅ PASS |
| Todos `auth_tokens` removidos | count 0 | `PasswordResetTest.php:200` — `AuthTokenModel::…->count())->toBe(0)`; `ResetPasswordTest.php:128` | ✅ PASS |
| `User.status` inalterado | `active` | `PasswordResetTest.php:201` — `status)->toBe(UserStatus::Active->value)`; `ResetPasswordTest.php:123` | ✅ PASS |
| Sem Bearer na resposta | empty body / no token | `PasswordResetTest.php:197` — `getContent()->toBe('')` | ✅ PASS |
| Token inválido/expirado/usado/purpose/email mismatch → 422 field `token` + exact message | message `The password reset token is invalid or has expired.` | `PasswordResetTest.php:235-238` — `errors.token.0.message` / `INVALID`; unit factory `:17-21`; integration expired/used/purpose/mismatch `ResetPasswordTest.php:153-223` | ✅ PASS |
| Política / confirmation fail → 422 **sem** consumir token | `422` + token unused | Form Request wires `confirmed` + `PasswordPolicyRule` (`ResetPasswordRequest.php:51`) but **no feature/integration assertion** that weak/mismatched password on `/password/reset` leaves `used_at` null | ❌ GAP |
| Nova senha = atual → `422` + `PASSWORD_REUSED` + message; token unused | code + message exact; `used_at` null | `PasswordResetTest.php:272-276` — `errors.password.0.code` / `MESSAGE` / `used_at)->toBeNull()`; integration `ResetPasswordTest.php:231-237` | ✅ PASS |
| 6ª reset → 429 + Retry-After | 429 on 6th | `ThrottlePasswordMiddlewareTest.php:115-121` — status 429 + `RATE_LIMIT_EXCEEDED` + Retry-After | ✅ PASS |
| Método ≠ POST → sem side effect | 405; token unused | `PasswordResetTest.php:302-305` — `assertStatus(405)` / `used_at)->toBeNull()` | ✅ PASS |

### P1: Change (AUTH-32/33, PW-09…11, PW-17)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Session + body correto → `204` | 204 | `PasswordChangeTest.php:66` — `assertNoContent()` | ✅ PASS |
| Hash atualizado | login nova senha 200 / verify | `PasswordChangeTest.php:78-84` — `assertOk()` + `token_kind session`; integration `ChangePasswordTest.php:113-114` | ✅ PASS |
| Todos bearers revogados (incl. apresentado) | count 0; probe 401 | `PasswordChangeTest.php:72-76` — `count()->toBe(0)` / `assertUnauthorized()` | ✅ PASS |
| `current_password` errada → `401 INVALID_CREDENTIALS` + login message; sem revoke | code + message; tokens intact | `PasswordChangeTest.php:104-109` — `INVALID_CREDENTIALS` / `The provided credentials are invalid.` / token count unchanged | ✅ PASS |
| Bearer ausente → `401 UNAUTHENTICATED`; verification → `403 TOKEN_RESTRICTED` | codes | `PasswordChangeTest.php:163-164` / `:126-127` | ✅ PASS |
| Política / confirmation → 422 sem side effects | 422; no hash/revoke | Request has `confirmed` + `PasswordPolicyRule`; **no feature test** asserting weak/mismatched password on `/password/change` | ❌ GAP |
| Nova = atual → `422` + `PASSWORD_REUSED` + message; tokens intact | exact code/message | `PasswordChangeTest.php:147-153` — code/message + token count; integration `ChangePasswordTest.php:145-155` | ✅ PASS |
| Escritas privadas 121ª → 429 | 429 + Retry-After | `ThrottlePasswordMiddlewareTest.php:154-160` | ✅ PASS |
| Campos extras → 422 | `VALIDATION_FAILED` | `PasswordChangeTest.php:180-181` | ✅ PASS |

### P1: Privacidade (PW-12)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| Plaintext não em logs/exceptions/factories | sentinel absent | `SendPasswordResetJobTest.php:72-73`, `:90-91`; `AuthValidationResponseFactoryTest.php:44-45` | ✅ PASS |
| URL query com token não registrada por servidores | job/mail assertions only (no request-log assertion) | Covered via ciphertext job + mail body URL construction tests | ✅ PASS (via job/mail surface) |
| Sentinelas em testes | asserts on factories/exceptions | as above | ✅ PASS |

### P2: Headers / OpenAPI (PW-13)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| `Cache-Control: private, no-store` + request id | headers present | `PasswordResetTest.php:59-61`, `:195-196`; `PasswordChangeTest.php:67-69`; factory unit `:22-23`, `:35-36` | ✅ PASS |
| OpenAPI documenta `PASSWORD_REUSED` | example in yaml | `docs/openapi.yaml:1256-1265` — `code: PASSWORD_REUSED` + exact message | ✅ PASS |

### P2: Gates (PW-14, PW-15)

| Criterion | Evidence | Result |
| --------- | -------- | ------ |
| Feature/Integration discovered by `make test-backend` | suite ran Password* tests | ✅ PASS |
| `make lint && make test-backend` green | this verification run | ✅ PASS |

### P2: Schema purpose (PW-16)

| Criterion | Evidence | Result |
| --------- | -------- | ------ |
| CHECK allows `email_verification` + `password_reset` | `EmailActionTokensSchemaContractTest.php:68+` (both purposes inserted) | ✅ PASS |
| `PasswordReset->absoluteTtlSeconds() === 1800` | `EmailActionPurposeTest.php:20-21` | ✅ PASS |

**Status**: ❌ Gaps present (policy/confirmation side-effect ACs) + ⚠️ 1 spec-precision gap (rate-limit counts on validation failures) + surviving mutant (see sensor)

---

## Discrimination Sensor

Scratch: `git worktree` at `/tmp/fake-link-password-sensor` with physical `vendor/` copy; Docker `-v` mount. Real tree untouched for mutations (verified `git diff` clean on `backend/modules/Auth` after discard).

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `RequestPasswordReset.php` eligibility | Dropped `status !== Active` guard (non-active would enqueue) | ✅ Killed (3 failed) |
| 2 | `ResetPassword.php` reused check | Forced `if (false && verify…)` skip `PASSWORD_REUSED` | ✅ Killed (2 failed) |
| 3 | `ResetPassword.php` revoke | Removed `revokeAllUserTokens->execute` | ✅ Killed (1 failed) |
| 4 | `ChangePassword.php` revoke | Removed `revokeAllUserTokens->execute` | ✅ Killed (2 failed) |
| 5 | `ResetPassword.php` `isUsed()` | Replaced `\|\| $token->isUsed()` with `\|\| false` | ❌ **Survived** (full `ResetPassword` suite 9 passed — `consumeForUser` still rejects) |
| 6 | `ThrottlePasswordResetRequest.php` | `$maxAttempts = 999` | ✅ Killed (2 failed) |
| 7 | `ResetPassword.php` consume | Forced `$consumed = true` without `consumeForUser` | ✅ Killed (4 failed) |
| 8 | `ChangePassword.php` reused | Skip `PASSWORD_REUSED` | ✅ Killed (2 failed) |

**Sensor depth**: P0-full (≥5 behavior-level mutations)  
**Result**: 7/8 killed, **1 survived** — FAIL ❌

---

## Interactive UAT Results

N/A — backend API-only feature; automated checks sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ (OpenAPI example only beyond core) |
| Matches patterns | ✅ (mirrors email-verification / login) |
| Spec-anchored outcome check | ❌ (policy/confirmation ACs lack route-level evidence) |
| Per-layer Coverage Expectation met | ⚠️ matrix mostly met; reset/change policy paths lack feature coverage |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed: `docs/testing.md` §6.1, `LARAVEL_CODE_DESIGN.md`, `AGENTS.md` | ✅ |

---

## Edge Cases

- [x] Reset-request repetido: only latest token valid — `IssuePasswordResetTokenTest.php:70-88`
- [x] Reset concorrente: one success — `ResetPasswordTest.php:242-273` (`['ok','invalid']`)
- [x] Token `email_verification` em `/password/reset` — `ResetPasswordTest.php:203-223`
- [ ] Token `password_reset` em `/email/verify` (regressão) — **no dedicated password→verify assertion in this diff**
- [x] Senha nova = atual (PW-17) change + reset — feature + integration
- [x] Bearer antigo após change → 401 — `PasswordChangeTest.php:74-76`
- [x] Reset-request `pending_verification` → 202 sem e-mail — feature + integration
- [ ] Plaintext token com whitespace (sem trim) — **no test evidence**
- [ ] Resend 429/5xx job retry — **not covered in this slice** (external-dependency; parity note only)
- [ ] Enqueue fail após persist — **not covered** (parity EV registration note)

---

## Gate Check

- **Gate command**: `make lint && make test-backend`
- **Result**: lint PASS (pint/phpstan/phpmd + architecture **12 passed**); backend **341 passed**, **0 failed**, **0 skipped** (1414 assertions)
- **Test count before feature**: 285 (email-verification validation baseline)
- **Test count after feature**: 341
- **Delta**: +56
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans (if issues found)

### Fix 1: Surviving mutant — early `isUsed()` guard untested

- **Root cause**: `ResetPassword` rejects used tokens via both `isUsed()` and `consumeForUser`; removing the early check alone does not change observable outcomes, so the suite still passes.
- **Fix task**: Either (a) add a focused unit/integration test that stubs the repository so a used token would pass `consumeForUser` unless `isUsed()` runs, or (b) remove the redundant early check if `consumeForUser` is the single source of truth — and document that choice.
- **Verify**: Re-run mutation 5; must FAIL tests after the chosen fix.
- **Priority**: Major (P0 auth sensor fail)

### Fix 2: Reset AC — policy / confirmation without consuming token

- **Root cause**: No feature/integration test posts weak or mismatched `password`/`password_confirmation` to `/api/v1/auth/password/reset` and asserts `422` + `used_at` still null.
- **Fix task**: Add feature cases in `PasswordResetTest` (weak password; confirmation mismatch) asserting status/`VALIDATION_FAILED` and unused token.
- **Priority**: Major

### Fix 3: Change AC — policy / confirmation without side effects

- **Root cause**: Same gap on `/password/change`.
- **Fix task**: Add feature cases in `PasswordChangeTest` asserting `422` and unchanged hash/token count.
- **Priority**: Major

### Fix 4: Rate-limit counts validation failures (AC8 precision)

- **Root cause**: Spec requires counting attempts with any HTTP status; tests only prove 202→429 path.
- **Fix task**: Feature or middleware test: invalid body still increments; Nth then 429.
- **Priority**: Minor

### Fix 5: Edge — whitespace token; password_reset on verify

- **Root cause**: Listed edge cases lack assertions.
- **Fix task**: Feature: token with trailing space → 422 token field; issue `password_reset` plaintext against `/email/verify` → `INVALID_VERIFICATION_TOKEN`.
- **Priority**: Minor

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| AUTH-26 | Pending | ✅ Verified |
| AUTH-27 | Pending | ⚠️ Needs Fix (policy AC gap on reset) |
| AUTH-28 | Pending | ✅ Verified |
| AUTH-29 | Pending | ✅ Verified |
| AUTH-32 | Pending | ⚠️ Needs Fix (policy AC gap on change) |
| AUTH-33 | Pending | ✅ Verified |
| PW-01 | Pending | ⚠️ Spec-precision (AC8 any-status increment) |
| PW-02 | Pending | ✅ Verified |
| PW-03 | Pending | ✅ Verified |
| PW-04 | Pending | ✅ Verified (429 path) / ⚠️ AC8 precision |
| PW-05 | Pending | ✅ Verified |
| PW-06 | Pending | ✅ Verified |
| PW-07 | Pending | ✅ Verified |
| PW-08 | Pending | ✅ Verified |
| PW-09 | Pending | ⚠️ Needs Fix (policy AC) |
| PW-10 | Pending | ✅ Verified |
| PW-11 | Pending | ✅ Verified |
| PW-12 | Pending | ✅ Verified |
| PW-13 | Pending | ✅ Verified |
| PW-14 | Pending | ✅ Verified |
| PW-15 | Pending | ✅ Verified |
| PW-16 | Pending | ✅ Verified |
| PW-17 | Pending | ✅ Verified |

---

## Summary

**Overall**: ❌ Not Ready

**Spec-anchored check**: ~28/32 precise AC rows matched; **2 AC gaps** (reset/change password policy & confirmation without side effects); **1 spec-precision gap** (rate-limit any-status)  
**Sensor**: **7/8 killed**, **1 survived** (`isUsed()` early guard)  
**Gate**: **341 passed**, 0 failed

**What works**: Anti-enumeration reset-request, mail/job pipeline, reset/change happy paths, `PASSWORD_REUSED`, invalid token field errors, bearer revoke, throttles (unit + feature for request), OpenAPI example, schema/TTL, lint+tests green.

**Issues found**: Surviving `isUsed()` mutant; missing route-level policy/confirmation side-effect tests; a few edge cases untested.

**Next steps**: Implement Fix 1–3 (blocker/major) then re-verify; Fix 4–5 as follow-ups.
