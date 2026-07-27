# auth/bearer-tokens Validation

**Date**: 2026-07-26
**Spec**: `.specs/features/auth/bearer-tokens/spec.md` (formal WHEN/THEN ACs AUTH-13…19, AUTH-33 partial, AUTH-37, AUTH-38, BT-11/BT-12)
**Diff range**: feature on `main` through `953626e`; re-verify fixes `1f294aa` (Feature suite discovery) + `86a2557` (formal ACs)
**Verifier**: independent sub-agent (author ≠ verifier)
**Prior FAIL**: 2026-07-26 — Feature suite not in default gate; draft spec lacked formal WHEN/THEN

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1–T18 (tasks.md) | ✅ Done (by commits) | Done-when checkboxes in `tasks.md` still unchecked; implementation present on `main` via atomic commits through `90681f2` / docs `86a2557` |
| Fix CI discovery (`1f294aa`) | ✅ Done | `phpunit.xml` Feature suite + `Pest.php` RefreshDatabase for `modules/Auth/Tests/Feature` |
| Formal ACs (`86a2557`) | ✅ Done | Spec tables AUTH-13…19, AUTH-33 partial, AUTH-37, AUTH-38, BT-11, BT-12 |

---

## Spec-Anchored Acceptance Criteria

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| AUTH-13 / BT-01: WHEN a token is issued THEN kind is only `verification` or `session` | Enum + DB CHECK; no other kinds | `TokenKindTest.php:8-10` — `expect(TokenKind::Verification->value)->toBe('verification')->and(TokenKind::Session->value)->toBe('session')`; `AuthTokensSchemaContractTest.php:51` — `toContain('auth_tokens_token_kind_check')` | ✅ PASS |
| AUTH-14 / BT-02: WHEN a token is persisted THEN only `token_hash` is stored | DB hash ≠ plaintext; hash = hasher(plaintext) | `IssueAuthTokenTest.php:47-50` — `->and($model?->token_hash)->toBe($hasher->hash($issued->plainTextToken))->and(...)->not->toBe($issued->plainTextToken)`; `EloquentAuthTokenRepositoryTest.php:59-61` — same | ✅ PASS |
| AUTH-15 / BT-03: WHEN issuing verification/session THEN absolute TTL 24h / 7d | `expires_at` = now + 86400 / 604800 | `TokenKindTest.php:13-15` — `absoluteTtlSeconds()` `86400` / `604800`; `IssueAuthTokenTest.php:47-48` — verification expires `2026-01-02T00:00:00+00:00`; `:63-64` — session `2026-01-08T00:00:00+00:00` | ✅ PASS |
| AUTH-16 / BT-04: WHEN idle exceeds kind limit THEN unauthenticated | verification idle 3600s; session idle 86400s; ref `last_used_at` or `created_at` | `TokenKindTest.php:18-20` — idle `3600` / `86400`; `AuthTokenTest.php:41-42` — verification idle boundary; `:75-76` — session 24h; `ValidateAuthTokenTest.php:107-108` — idle expired throws unauthenticated; `BearerMiddlewareTest.php:98-100` — HTTP 401 | ✅ PASS |
| AUTH-17 / BT-05: WHEN validating within 15 min THEN `last_used_at` unchanged; WHEN ≥15 min THEN updates | Throttle 900s; no write on expired/revoked paths | `ValidateAuthTokenTest.php:139-152` — still `00:00:00` at +10m, updates to `00:16:00` at +16m; `BearerMiddlewareTest.php:146-153` — same over HTTP; `ValidateAuthTokenTest.php:95` / `:110` — expired paths leave `last_used_at` null | ✅ PASS |
| AUTH-18 / BT-06: WHEN `Authorization: Bearer <valid>` THEN proceeds; WHEN missing/invalid/expired/revoked THEN `401` | Scheme exact `Bearer` (case-sensitive) | `BearerMiddlewareTest.php:42-44` — `assertOk` + `token_kind` `session`; `:52-53` — missing → `assertUnauthorized` + `UNAUTHENTICATED`; `:64-65` — lowercase `bearer` → 401; `:71-72` — invalid → 401; `:82-84` — absolute expiry → 401; `:131-133` — revoked → 401 | ✅ PASS |
| AUTH-19 / BT-07: WHEN token kind not allowed on route THEN `403 TOKEN_RESTRICTED` | session-only, verification-only, both-allowed | `BearerMiddlewareTest.php:166-167` — verification on session-only → 403 `TOKEN_RESTRICTED`; `:176-177` — session on verification-only; `:183-189` — both kinds on any-kind `assertOk` | ✅ PASS |
| AUTH-33 partial / BT-08: WHEN `RevokeAllUserTokens` THEN all user tokens deleted | Returns deleted count; second call `0` | `RevokeAuthTokenTest.php:82-87` — `expect($deleted)->toBe(2)->and(...count())->toBe(0)->and(...)->toBe(0)` | ✅ PASS |
| AUTH-37 / BT-09: WHEN middleware authenticates THEN `AuthenticatedPrincipal` bound | Contract exposes user id, status, token kind (min) | `AuthenticatedPrincipal.php:15-19` — contract methods; `ValidateAuthTokenTest.php:60-62` — `userId` / `userStatus` / `tokenKind`; `AuthenticateBearer.php:49` — `$this->app->instance(AuthenticatedPrincipal::class, $principal)`; probe `BearerMiddlewareTest.php:42-44` | ✅ PASS |
| AUTH-38 / BT-10: WHEN principal does not own resource THEN uniform `404 RESOURCE_NOT_FOUND` | No existence leak via distinct 403 | `AuthorizesOwnedResourceTest.php:53` — `->throws(ResourceNotFoundException::class, 'The requested resource was not found.')`; `AuthErrorResponseFactoryTest.php:45-46` — status `404` + `RESOURCE_NOT_FOUND` | ✅ PASS |
| BT-11: WHEN `APP_ENV=testing` THEN probe routes exist; WHEN not testing THEN absent | No public Auth product endpoints in this slice | Exist: `BearerMiddlewareTest.php:38-44` — `GET /api/v1/_test/auth/probe` `assertOk`; Absent / no product Auth: `routes/api.php:10-21` — probes only inside `if (app()->environment('testing'))`; file has no `/auth/*` product routes (only health + probes) | ✅ PASS |
| BT-12: WHEN `make test-backend` / bare `php artisan test` THEN Feature Auth discovered | `phpunit.xml` Feature suite includes directory | `phpunit.xml:12-14` — Feature suite lists `modules/Auth/Tests/Feature`; `Pest.php:20-22` — RefreshDatabase binding; gate evidence: `Modules\Auth\Tests\Feature\BearerMiddlewareTest` **13/13** ran under default `make test-backend` | ✅ PASS |

**Status**: ✅ All ACs covered (12/12) — 0 spec-precision gaps

> Note on BT-11 negative path: no process-level test flips `APP_ENV` away from `testing` and asserts 404. Evidence is the env-gated registration block plus absence of product Auth routes. Acceptable for this infrastructure slice; not treated as a blocker.

---

## Discrimination Sensor

Scratch only: `git worktree` at `/tmp/fake-link-bearer-reverify-*` + Docker volume mount of worktree `backend/` (vendor rsync’d into scratch; real tree unmodified; worktree removed after). Mutations killed via **default** `php artisan test` discovery (no path override). Depth: **P0-full** (≥5 behavior mutations).

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `TokenKind.php:13` | `verification` absolute TTL `86400` → `1` | ✅ Killed |
| 2 | `ValidateAuthToken.php:18` | `LAST_USED_THROTTLE_SECONDS` `900` → `0` | ✅ Killed |
| 3 | `AuthToken.php:100` | idle compare `>` → `<` | ✅ Killed |
| 4 | `AuthenticateBearer.php:18` | `BEARER_PREFIX` `'Bearer '` → `'bearer '` | ✅ Killed (`BearerMiddlewareTest` lowercase / valid Bearer cases) |
| 5 | `RequireTokenKind.php:39` | kind restriction short-circuited with `false &&` | ✅ Killed (`BearerMiddlewareTest` TOKEN_RESTRICTED cases) |
| 6 | `AuthorizesOwnedResource.php:15` | ownership check short-circuited with `false &&` | ✅ Killed (`AuthorizesOwnedResourceTest`) |

**Sensor depth**: P0-full
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results

N/A — backend infrastructure slice (no user-facing UI). Automated checks sufficient per validate.md §3.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ Hexagonal Auth module, UseCases, Form-free middleware, Pest co-located |
| Spec-anchored outcome check (asserted values match spec) | ✅ |
| Per-layer Coverage Expectation met (domain 1:1 ACs; routes happy+edge+error) | ✅ Unit + Integration + Feature probe routes |
| Every test maps to a spec requirement — no unclaimed tests | ✅ Bearer Feature/Integration/Unit map to AUTH/BT IDs; foundation User tests predate slice |
| Documented guidelines followed: `docs/testing.md` §2–4, §6.1; `LARAVEL_CODE_DESIGN.md`; `AGENTS.md` Docker-only | ✅ |

---

## Edge Cases

- [x] Absolute expiry rejects without updating `last_used_at` — `ValidateAuthTokenTest.php:85-95`, `BearerMiddlewareTest.php:75-86`
- [x] Idle expiry rejects without updating `last_used_at` — `ValidateAuthTokenTest.php:100-110`
- [x] Suspended / `deletion_pending` → `403` (not `401`) — `BearerMiddlewareTest.php:105-118`
- [x] Lowercase `bearer` scheme rejected — `BearerMiddlewareTest.php:58-65`
- [x] Session token + `pending_verification` → `403 TOKEN_RESTRICTED` — `BearerMiddlewareTest.php:192-199`
- [x] Plaintext token never in exception messages — `ValidateAuthTokenTest.php:71-77`, `AuthTokenExceptionTest`
- [x] Ownership mismatch → `404`, not `403` — `AuthorizesOwnedResourceTest.php:40-53`, `AuthErrorResponseFactoryTest.php:42-46`

---

## Gate Check

- **Gate command**: `make test-backend` (Build gate from tasks.md; Full/Quick also `make test-backend`)
- **Result**: **133** passed, **0** failed, **0** skipped
- **Assertions**: 296
- **Duration**: ~19.8s
- **Feature discovery**: `Modules\Auth\Tests\Feature\BearerMiddlewareTest` — **13** cases executed under default suite (no path filter)
- **Test count before feature** (prior FAIL baseline): 120 passed (Feature Auth excluded from default suite)
- **Test count after fixes**: 133 passed (**+13** Feature middleware cases now discovered)
- **Delta vs prior FAIL**: +13 (matches `BearerMiddlewareTest` case count)
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

None — no blockers.

---

## Requirement Traceability Update

*(Orchestrator updates `spec.md` status — Verifier does not.)*

| Requirement | Previous Status | New Status (recommended) |
| ----------- | --------------- | ------------------------ |
| AUTH-13…AUTH-19 | Implementing / prior FAIL | ✅ Verified |
| AUTH-33 (partial) | Implementing | ✅ Verified |
| AUTH-37 | Implementing | ✅ Verified |
| AUTH-38 | Implementing | ✅ Verified |
| BT-01…BT-12 (slice) | Implementing | ✅ Verified |
| Prior FAIL: Feature discovery | ❌ | ✅ Fixed (`1f294aa`) |
| Prior FAIL: formal WHEN/THEN | ❌ | ✅ Fixed (`86a2557`) |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 12/12 ACs matched spec outcome | 0 spec-precision gaps
**Sensor**: 6/6 mutations killed
**Gate**: 133 passed (0 failed); `BearerMiddlewareTest` in default suite

**What works**: Bearer issue/validate/revoke, TTL absolute+idle, 15m throttle, HTTP middleware (401/403), principal binding, ownership 404, testing-only probe routes, Feature suite discovery via `phpunit.xml` + `Pest.php`.

**Issues found**: None blocking. Optional hardening: automated assertion that probe routes are unregistered when `APP_ENV≠testing` (BT-11 negative path currently static-reviewed).

**Next steps**: Orchestrator may mark feature verified / update STATE + README; no fix→re-verify iteration required.
