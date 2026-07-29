# Auth Password Validation

**Date**: 2026-07-28  
**Iteration**: re-verify 1 of max 3 (after fix commit `3184620`)  
**Spec**: `.specs/features/auth/password/spec.md`  
**Diff range**: `9c616f9..3184620` (merge-base with `main` .. HEAD; includes fix `3184620 fix(auth): close password verification gaps from Verifier FAIL`)  
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status  | Notes |
| ---- | ------- | ----- |
| T1–T18 | ✅ Done | All Done-when checkboxes `[x]` in `tasks.md` |
| Fix round | ✅ Done | Commit `3184620` closes prior FAIL gaps 1–6 |

---

## Spec-Anchored Acceptance Criteria

### P1: Solicitar recuperação (AUTH-26, AUTH-29, PW-01…04)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Reset-request body válido → 202 Accepted (anti-enum) | HTTP `202` | `PasswordResetTest.php:58` — `$response->assertAccepted()` | ✅ PASS |
| Active → token `password_reset`, TTL 1800, hash, 1 job | 1 token + purpose + 1 job on `notifications` | `PasswordResetTest.php:64-67` — `count()->toBe(1)` + `Queue::assertPushedOn('notifications', …)`; TTL `IssuePasswordResetTokenTest` / `EmailActionPurposeTest.php:21` — `absoluteTtlSeconds()->toBe(1800)` | ✅ PASS |
| Missing / non-active → 202 sem token/job | 0 tokens, nothing pushed | `PasswordResetTest.php:79-82` (unknown); `:97-101` (pending); integration `RequestPasswordResetTest` non-active paths | ✅ PASS |
| Novo request invalida tokens anteriores unused | previous `used_at` set | `IssuePasswordResetTokenTest.php:83-85` — prior `used_at` set; second unused | ✅ PASS |
| Job envia URL com token só no corpo; sem plaintext em logs | URL `/reset-password?token=`; sentinel ausente | `SendPasswordResetJobTest.php` — URL + subject/serialize/exception without sentinel | ✅ PASS |
| 4ª reset-request → 429 + Retry-After | `429 RATE_LIMIT_EXCEEDED` + `Retry-After` | `PasswordResetTest.php:123-125` — `assertStatus(429)` / `RATE_LIMIT_EXCEEDED` / `Retry-After` not null | ✅ PASS |
| Validação falha → 422 sem side effects | `422 VALIDATION_FAILED`; 0 tokens/jobs | `PasswordResetTest.php:137-141` — `VALIDATION_FAILED` / `count()->toBe(0)` / `assertNothingPushed()` | ✅ PASS |
| Contador rate limit incrementa antes do use case (qualquer status) | 422 responses still count toward limit | `ThrottlePasswordMiddlewareTest.php:81-101` — three `422` then 4th → `429 RATE_LIMIT_EXCEEDED` + `Retry-After` | ✅ PASS |

### P1: Concluir reset (AUTH-27/28/33, PW-05…08, PW-17)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Valid reset → `204` | HTTP 204 empty | `PasswordResetTest.php:194` — `assertNoContent()`; `:197` empty content | ✅ PASS |
| Hash Argon2id novo + `used_at` na txn | verify new hash; `used_at` not null | Feature `:202-203`; integration `ResetPasswordTest` happy path | ✅ PASS |
| Todos `auth_tokens` removidos | count 0 | `PasswordResetTest.php:200` — `AuthTokenModel::…->count())->toBe(0)` | ✅ PASS |
| `User.status` inalterado | `active` | `PasswordResetTest.php:201` — `status)->toBe(UserStatus::Active->value)` | ✅ PASS |
| Sem Bearer na resposta | empty body / no token | `PasswordResetTest.php:197` — `getContent()->toBe('')` | ✅ PASS |
| Token inválido/expirado/usado/purpose/email mismatch → 422 field `token` + exact message | message `The password reset token is invalid or has expired.` | Feature invalid token path; integration expired/used/purpose/mismatch; used+same-password: `ResetPasswordTest.php:186-201` + `PasswordResetTest.php:351-393` | ✅ PASS |
| Política / confirmation fail → 422 **sem** consumir token | `422` + token unused | `PasswordResetTest.php:279-312` (weak) — `VALIDATION_FAILED` + `used_at)->toBeNull()`; `:315-348` (mismatch) — same | ✅ PASS |
| Nova senha = atual → `422` + `PASSWORD_REUSED` + message; token unused | code + message exact; `used_at` null | `PasswordResetTest.php:272-276` — code/message + `used_at)->toBeNull()` | ✅ PASS |
| 6ª reset → 429 + Retry-After | 429 on 6th | `ThrottlePasswordMiddlewareTest` ThrottlePasswordReset suite | ✅ PASS |
| Método ≠ POST → sem side effect | 405; token unused | `PasswordResetTest.php:456-459` — `assertStatus(405)` / `used_at)->toBeNull()` | ✅ PASS |

### P1: Change (AUTH-32/33, PW-09…11, PW-17)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Session + body correto → `204` | 204 | `PasswordChangeTest.php:66` — `assertNoContent()` | ✅ PASS |
| Hash atualizado | login nova senha 200 | `PasswordChangeTest.php:78-84` — login `assertOk()` + `token_kind session` | ✅ PASS |
| Todos bearers revogados (incl. apresentado) | count 0; probe 401 | `PasswordChangeTest.php:72-76` — count 0 / `assertUnauthorized()` | ✅ PASS |
| `current_password` errada → `401 INVALID_CREDENTIALS` + login message; sem revoke | code + message; tokens intact | `PasswordChangeTest.php:104-109` — `INVALID_CREDENTIALS` / exact message / token count | ✅ PASS |
| Bearer ausente → `401 UNAUTHENTICATED`; verification → `403 TOKEN_RESTRICTED` | codes | `PasswordChangeTest.php:213-214` / `:126-127` | ✅ PASS |
| Política / confirmation → 422 sem side effects | 422; no hash/revoke | `PasswordChangeTest.php:157-179` (weak) — `VALIDATION_FAILED` + unchanged tokens/hash; `:182-204` (mismatch) — same | ✅ PASS |
| Nova = atual → `422` + `PASSWORD_REUSED` + message; tokens intact | exact code/message | `PasswordChangeTest.php:147-153` — code/message + token count | ✅ PASS |
| Escritas privadas 121ª → 429 | 429 + Retry-After | `ThrottlePasswordMiddlewareTest.php:167-183` | ✅ PASS |
| Campos extras → 422 | `VALIDATION_FAILED` | `PasswordChangeTest.php:217+` — extra fields → 422 | ✅ PASS |

### P1: Privacidade (PW-12)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| Plaintext não em logs/exceptions/factories | sentinel absent | `SendPasswordResetJobTest` serialize/exception; factory unit sentinels | ✅ PASS |
| URL query com token não registrada por servidores | job/mail surface | Covered via ciphertext job + mail body URL construction | ✅ PASS |
| Sentinelas em testes | asserts on factories/exceptions | as above | ✅ PASS |

### P2: Headers / OpenAPI (PW-13)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| `Cache-Control: private, no-store` + request id | headers present | `PasswordResetTest.php:59-61`, `:195-196`; `PasswordChangeTest` happy-path headers | ✅ PASS |
| OpenAPI documenta `PASSWORD_REUSED` | example in yaml | `docs/openapi.yaml:1256-1265` — `code: PASSWORD_REUSED` + exact message | ✅ PASS |

### P2: Gates (PW-14, PW-15)

| Criterion | Evidence | Result |
| --------- | -------- | ------ |
| Feature/Integration discovered by `make test-backend` | suite ran Password* + EmailVerification regression | ✅ PASS |
| `make lint && make test-backend` green | this re-verification run | ✅ PASS |

### P2: Schema purpose (PW-16)

| Criterion | Evidence | Result |
| --------- | -------- | ------ |
| CHECK allows `email_verification` + `password_reset` | `EmailActionTokensSchemaContractTest` both purposes | ✅ PASS |
| `PasswordReset->absoluteTtlSeconds() === 1800` | `EmailActionPurposeTest.php:20-21` | ✅ PASS |

**Status**: ✅ All ACs covered (prior gaps closed)

---

## Discrimination Sensor

Scratch: `git worktree` at `/tmp/fake-link-password-sensor-reverify` (detached `3184620`) with physical `vendor/` copy; Docker `-v` mount of worktree backend. Real tree UseCases untouched (verified `git diff --stat HEAD -- backend/modules/Auth/UseCases` empty after discard). Worktree removed after run.

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `ResetPassword.php:46` | Replaced `\|\| $token->isUsed()` with `\|\| false` | ✅ Killed — `ResetPasswordTest` “rejects a used token as invalid even when the password matches…” (`1 failed, 9 passed`); without early guard, `PasswordReusedException` wins over invalid-token |
| 2 | `RequestPasswordReset.php:34` | Dropped `status !== Active` guard | ✅ Killed — `RequestPasswordResetTest` `3 failed, 3 passed` |
| 3 | `ResetPassword.php` reused check | Forced `if (false && verify…)` skip `PASSWORD_REUSED` | ✅ Killed — `1 failed, 9 passed` |
| 4 | `ResetPassword.php` revoke | Removed `revokeAllUserTokens->execute` | ✅ Killed — `2 failed, 8 passed` |
| 5 | `ChangePassword.php` revoke | Removed `revokeAllUserTokens->execute` | ✅ Killed — `1 failed, 4 passed` |
| 6 | `ThrottlePasswordResetRequest.php` | `$maxAttempts = 999` | ✅ Killed — `ThrottlePasswordMiddlewareTest` `2 failed, 2 passed` |

**Sensor depth**: P0-full (≥5 behavior-level mutations; mandatory prior surviving mutant re-tried)  
**Result**: **6/6 killed** — PASS ✅

---

## Interactive UAT Results

N/A — backend API-only feature; automated checks sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ (mirrors email-verification / login) |
| Spec-anchored outcome check (asserted values match spec) | ✅ |
| Per-layer Coverage Expectation met (domain 1:1 ACs; routes happy+edge+error) | ✅ |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed: `docs/testing.md` §6.1, `LARAVEL_CODE_DESIGN.md`, `AGENTS.md` | ✅ |

---

## Edge Cases

- [x] Reset-request repetido: only latest token valid — `IssuePasswordResetTokenTest`
- [x] Reset concorrente: one success — `ResetPasswordTest` concurrency
- [x] Token `email_verification` em `/password/reset` — `ResetPasswordTest` purpose mismatch
- [x] Token `password_reset` em `/email/verify` — `EmailVerificationTest.php:117-135` — `INVALID_VERIFICATION_TOKEN` + reset token unused
- [x] Senha nova = atual (PW-17) change + reset — feature + integration
- [x] Bearer antigo após change → 401 — `PasswordChangeTest.php:74-76`
- [x] Reset-request `pending_verification` → 202 sem e-mail — feature + integration
- [x] Plaintext token com whitespace (sem trim) — `bootstrap/app.php:32-37` `trimStrings(except: … 'token')` + `PasswordResetTest.php:396-430` → 422 token field, unused
- [ ] Resend 429/5xx job retry — residual external-dependency note (parity EV; not in prior fix list)
- [ ] Enqueue fail após persist — residual parity note (not in prior fix list)

---

## Gate Check

- **Gate command**: `make lint && make test-backend` (from `tasks.md` Build/Final)
- **Result**: lint PASS (pint/phpstan/phpmd + architecture **12 passed**); backend **350 passed**, **0 failed**, **0 skipped** (1462 assertions)
- **Test count before feature**: 285 (email-verification validation baseline)
- **Test count after first FAIL report**: 341
- **Test count after fix re-verify**: 350
- **Delta vs pre-feature**: +65; **delta vs prior FAIL**: +9
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans (if issues found)

None — prior FAIL gaps closed; sensor clean.

### Prior gaps closure evidence

| Prior gap | Evidence now |
| --------- | ------------ |
| 1. Surviving `isUsed()` mutant | Discriminating tests: `ResetPasswordTest.php:186-201`, `PasswordResetTest.php:351-393`; sensor MUT1 killed |
| 2. Reset policy/confirmation without consuming token | `PasswordResetTest.php:279-348` |
| 3. Change policy/confirmation without side effects | `PasswordChangeTest.php:157-204` |
| 4. Rate-limit increments on 422 | `ThrottlePasswordMiddlewareTest.php:81-101` |
| 5. Whitespace token (no trim) | `bootstrap/app.php:32-37` + `PasswordResetTest.php:396-430` |
| 6. `password_reset` on `/email/verify` | `EmailVerificationTest.php:117-135` |

---

## Requirement Traceability Update

| Requirement | Previous Status (FAIL report) | New Status |
| ----------- | ----------------------------- | ---------- |
| AUTH-26 | ✅ Verified | ✅ Verified |
| AUTH-27 | ⚠️ Needs Fix | ✅ Verified |
| AUTH-28 | ✅ Verified | ✅ Verified |
| AUTH-29 | ✅ Verified | ✅ Verified |
| AUTH-32 | ⚠️ Needs Fix | ✅ Verified |
| AUTH-33 | ✅ Verified | ✅ Verified |
| PW-01 | ⚠️ Spec-precision (AC8) | ✅ Verified |
| PW-02 | ✅ Verified | ✅ Verified |
| PW-03 | ✅ Verified | ✅ Verified |
| PW-04 | ⚠️ AC8 precision | ✅ Verified |
| PW-05 | ✅ Verified | ✅ Verified |
| PW-06 | ✅ Verified | ✅ Verified |
| PW-07 | ✅ Verified | ✅ Verified |
| PW-08 | ✅ Verified | ✅ Verified |
| PW-09 | ⚠️ Needs Fix | ✅ Verified |
| PW-10 | ✅ Verified | ✅ Verified |
| PW-11 | ✅ Verified | ✅ Verified |
| PW-12 | ✅ Verified | ✅ Verified |
| PW-13 | ✅ Verified | ✅ Verified |
| PW-14 | ✅ Verified | ✅ Verified |
| PW-15 | ✅ Verified | ✅ Verified |
| PW-16 | ✅ Verified | ✅ Verified |
| PW-17 | ✅ Verified | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: All precise AC rows matched (prior 2 AC gaps + 1 precision gap closed)  
**Sensor**: **6/6 killed** (including mandatory `isUsed()` re-try)  
**Gate**: **350 passed**, 0 failed  
**Lessons**: none new (clean PASS; L-028–L-030 retained from first FAIL)

**What works**: Anti-enumeration reset-request, mail/job pipeline, reset/change happy paths, policy/confirmation without side effects, `PASSWORD_REUSED`, used-token discrimination vs reused-password, whitespace no-trim, password_reset→verify regression, throttles including 422 increments, OpenAPI example, schema/TTL, lint+tests green.

**Issues found**: None blocking. Residual unchecked: Resend retry / enqueue-after-persist (external-dependency parity notes).

**Next steps**: Feature ready; orchestrator may mark verified / proceed to UAT-N/A closeout.
