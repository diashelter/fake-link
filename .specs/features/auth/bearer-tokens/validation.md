# Auth — Bearer Tokens Validation

**Date**: 2026-07-23  
**Spec**: `.specs/features/auth/bearer-tokens/spec.md`  
**Diff range**: `d2e4ed4..90681f2` (first commit `d2e4ed4` through HEAD `90681f2`, branch `feature/bearer-tokens`, 18 commits)  
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Overall Verdict

**⚠️ Conditional PASS** — all gates green, 57 bearer-scoped tests pass, discrimination sensor 4/5 confirmed kills; evidence-or-zero gaps remain on BT-15 (plaintext in failure paths), invalid `token_kind` rejection, partial principal-field assertions, and revoked-token `last_used_at` guard.

---

## Task Completion (T1–T18)

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 Migration `auth_tokens` | ✅ Done | `2026_07_23_143256_create_auth_tokens_table.php` + schema contract test |
| T2 Domain types | ✅ Done | `TokenKind`, `AuthToken`, `AuthTokenId` + unit tests |
| T3 Generator + hasher | ✅ Done | `BearerTokenGenerator`, `Sha256TokenHasher` + unit tests |
| T4 Repository contracts | ✅ Done | `AuthTokenRepository`, `TokenHasher`, `AuthTokenIdGenerator` |
| T5 Eloquent repository | ✅ Done | `EloquentAuthTokenRepository` + integration tests |
| T6 UserRepository `findById` | ✅ Done | Used by `ValidateAuthToken` |
| T7 IssueAuthToken | ✅ Done | Integration tests for TTL + hash |
| T8 Revoke use cases | ✅ Done | `RevokeAuthToken`, `RevokeAllUserTokens` |
| T9 AuthenticatedPrincipal | ✅ Done | Contract + `AuthenticatedPrincipalRecord` |
| T10 ValidateAuthToken | ✅ Done | Integration tests (expiry, idle, status, throttle) |
| T11 AuthenticateBearer | ✅ Done | Feature tests + probe routes |
| T12 RequireTokenKind | ✅ Done | Feature tests incl. pending_verification |
| T13 Auth error responses | ✅ Done | `AuthErrorResponseFactory` unit tests |
| T14 AuthorizesOwnedResource | ✅ Done | Unit test for allow/deny |
| T15 Middleware aliases | ✅ Done | `bootstrap/app.php` `auth.bearer`, `token.kind` |
| T16 Feature tests + probe routes | ✅ Done | `routes/api.php` testing-only group |
| T17 Plaintext discipline | ✅ Done | `AuthTokenExceptionTest` (partial — see BT-15 gap) |
| T18 Docs + gates | ✅ Done | `docs/data-model.md` §3 aligned; gates reported green |

---

## Requirement Traceability (BT-01 … BT-18, AUTH-13 … AUTH-19, AUTH-33, AUTH-37, AUTH-38)

| ID | Spec outcome | Evidence (`file:line` + assertion) | Result |
| --- | --- | --- | --- |
| **BT-01** | Migration `auth_tokens` UUID PK, FK, UNIQUE hash, CHECK kind, indexes | `AuthTokensSchemaContractTest.php:17-40` — column list + uuid types; `:43-51` — `auth_tokens_token_kind_check`; `:54-73` — unique hash + user_id index + FK | ✅ PASS |
| **BT-02** | Enum `TokenKind` + entity `AuthToken` | `TokenKindTest.php:8-21` — kinds + TTL constants; `AuthTokenTest.php:12-76` — expiry/idle domain rules | ✅ PASS |
| **BT-03** | `TokenHasher` SHA-256 port | `Sha256TokenHasherTest.php:8-30` — 64-char hex hash + verify/reject | ✅ PASS |
| **BT-04** | `IssueAuthToken` + repository persist hash only | `IssueAuthTokenTest.php:36-80` — 24h/7d expiry, hash ≠ plaintext, unique hashes | ✅ PASS |
| **BT-05** | `ValidateAuthToken` resolves principal or 401/403 | `ValidateAuthTokenTest.php:53-115` — principal + reject paths; `:67-98` — unknown/expired/idle | ⚠️ GAP — principal asserts `userId`, `userStatus`, `tokenKind` only; **`tokenId` / `expiresAt` not asserted** |
| **BT-06** | Middleware `AuthenticateBearer` | `BearerMiddlewareTest.php:32-134` — 401/403 HTTP + codes; probe OK with Bearer | ✅ PASS |
| **BT-07** | Account status checks | `ValidateAuthTokenTest.php:101-115`; `BearerMiddlewareTest.php:105-118` — suspended / deletion_pending → 403 codes | ✅ PASS |
| **BT-08** | Throttle `last_used_at` 15 min | `ValidateAuthTokenTest.php:117-140`; `EloquentAuthTokenRepositoryTest.php:142-170`; `BearerMiddlewareTest.php:136-155` | ⚠️ GAP — expired/idle skip touch asserted; **revoked token `last_used_at` not asserted** |
| **BT-09** | `RequireTokenKind` middleware | `BearerMiddlewareTest.php:159-200` — session-only, verification-only, any-kind, pending_verification | ✅ PASS |
| **BT-10** | `403 TOKEN_RESTRICTED` | `BearerMiddlewareTest.php:167,177,199` — `assertJsonPath('code', TOKEN_RESTRICTED)` | ✅ PASS |
| **BT-11** | `RevokeAuthToken` delete + idempotent | `RevokeAuthTokenTest.php:44-64` — count 0 after revoke; no throw on missing | ✅ PASS |
| **BT-12** | `RevokeAllUserTokens` | `RevokeAuthTokenTest.php:68-87` — deletes 2, returns 0 when empty | ✅ PASS |
| **BT-13** | Contract `AuthenticatedPrincipal` exportable | `AuthenticatedPrincipal.php:13-24` — interface fields; `BearerMiddlewareTest.php:33-44` — probe resolves via `app(AuthenticatedPrincipal::class)` | ⚠️ GAP — container binding exercised indirectly; **no explicit `app()->bound()` assertion** |
| **BT-14** | Ownership → `404 RESOURCE_NOT_FOUND` | `AuthorizesOwnedResourceTest.php:40-53` — throws `ResourceNotFoundException`; `AuthErrorResponseFactoryTest.php:42-46` — 404 response shape | ⚠️ GAP — **no HTTP/feature test** mapping ownership exception → 404 |
| **BT-15** | Plaintext absent from exceptions/logs on failure | `AuthTokenExceptionTest.php:8-20` — sentinel absent in static factory messages | ❌ GAP — **does not cover emissão/validação/revogação failure paths** with sentinel plaintext |
| **BT-16** | Test route `APP_ENV=testing` only | `routes/api.php:10-20` — `app()->environment('testing')` guard; `BearerMiddlewareTest.php` hits `/api/v1/_test/auth/*` | ✅ PASS |
| **BT-17** | Repository + test factory | `EloquentAuthTokenRepositoryTest.php:47-173`; `AuthTokenModelFactory.php` exists | ✅ PASS |
| **BT-18** | `docs/data-model.md` §3 aligned | `docs/data-model.md:144-156` — UUID v7, fields, CHECK kinds, 15 min throttle note; commit `90681f2` | ✅ PASS (doc + migration parity) |
| **AUTH-13** | Kinds `verification` / `session` | `TokenKindTest.php:8-10`; migration CHECK `AuthTokensSchemaContractTest.php:51` | ✅ PASS |
| **AUTH-14** | Storage by hash only | `IssueAuthTokenTest.php:49-50`; `EloquentAuthTokenRepositoryTest.php:59-61` | ✅ PASS |
| **AUTH-15** | Absolute TTL by type | `IssueAuthTokenTest.php:48,64`; `TokenKindTest.php:13-15` | ✅ PASS |
| **AUTH-16** | Idle expiry by type | `AuthTokenTest.php:29-76`; `ValidateAuthTokenTest.php:86-98` (verification integration); session idle at unit layer | ⚠️ Spec-precision — session idle not re-tested at integration/HTTP |
| **AUTH-17** | 15 min throttle | Same as BT-08 | ⚠️ GAP (revoked path) |
| **AUTH-18** | `Authorization: Bearer` parse | `BearerMiddlewareTest.php:49-72` — missing, lowercase `bearer`, invalid token | ✅ PASS |
| **AUTH-19** | Endpoint kind restriction | Same as BT-09/BT-10 | ✅ PASS |
| **AUTH-33** | Mass revocation UseCase | `RevokeAuthTokenTest.php:68-87` | ✅ PASS |
| **AUTH-37** | Exportable authenticated identity | Contract + probe JSON `user_id`/`token_kind` (`BearerMiddlewareTest.php:42-44`) | ⚠️ Partial — see BT-05/13 |
| **AUTH-38** | Ownership policies → 404 | Unit + factory only (see BT-14) | ⚠️ GAP |

**Coverage summary**: 27/27 mapped; **18 PASS**, **7 spec-precision/partial**, **1 FAIL (BT-15)**, **1 partial principal fields**

---

## Spec-Anchored Acceptance Criteria (by User Story)

### P1: Persistência e emissão (AUTH-13, AUTH-14, AUTH-15, BT-01–04)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| verification `expires_at = created + 24h`, hash unique, plaintext only in DTO | Persist + return once | `IssueAuthTokenTest.php:48-50` | ✅ |
| session `expires_at = created + 7d` | 7-day TTL | `IssueAuthTokenTest.php:64` | ✅ |
| successive issuances → distinct `token_hash` | 2 rows, distinct hashes | `IssueAuthTokenTest.php:78-80` | ✅ |
| repository has no recoverable plaintext | hash ≠ plaintext | `EloquentAuthTokenRepositoryTest.php:59-61` | ✅ |
| invalid `token_kind` fails before persist | reject invalid kind | — | ❌ GAP — typed enum/DTO prevents invalid at compile time; **no runtime test** (`TokenKind::from` invalid) |

### P1: Validação Bearer e expiração (AUTH-15, AUTH-16, AUTH-18, BT-05–07)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| missing / non-`Bearer <token>` → 401 `UNAUTHENTICATED` | 401 + code | `BearerMiddlewareTest.php:52-53` | ✅ |
| hash not found → 401 uniform | 401 | `BearerMiddlewareTest.php:71-72`; `ValidateAuthTokenTest.php:68-69` | ✅ |
| `now >= expires_at` → 401, no `last_used_at` update | 401 + null last_used | `ValidateAuthTokenTest.php:78-81`; `BearerMiddlewareTest.php:86` | ✅ |
| idle expired → 401, no `expires_at` extension | 401 + null last_used | `ValidateAuthTokenTest.php:93-96`; `AuthTokenTest.php:41-42` (boundary exclusive) | ✅ |
| valid token → `AuthenticatedPrincipal` all fields | principal populated | `ValidateAuthTokenTest.php:60-62` | ⚠️ partial (`tokenId`, `expiresAt` missing) |
| suspended → 403 `ACCOUNT_SUSPENDED` | 403 + code | `BearerMiddlewareTest.php:111-112` | ✅ |
| deletion_pending → 403 `ACCOUNT_PENDING_DELETION` | 403 + code | `BearerMiddlewareTest.php:117-118` | ✅ |

### P1: Throttle `last_used_at` (AUTH-17, BT-08)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| valid + `last_used_at` NULL → persist now | first touch | `ValidateAuthTokenTest.php:125-126` | ✅ |
| valid + stale ≥15 min → update | second touch at +16m | `ValidateAuthTokenTest.php:137-138` | ✅ |
| valid + recent <15 min → no UPDATE | unchanged at +10m | `ValidateAuthTokenTest.php:131-132` | ✅ |
| expired/revoked → no UPDATE | skip touch | expired: `ValidateAuthTokenTest.php:81,96`; revoked: — | ⚠️ revoked path not asserted |

### P1: Restrição por `token_kind` (AUTH-19, BT-09–10)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| session route + verification token → 403 `TOKEN_RESTRICTED` | 403 | `BearerMiddlewareTest.php:167` | ✅ |
| verification route + session token → 403 | 403 | `BearerMiddlewareTest.php:177` | ✅ |
| route accepts both → allow | 200 both kinds | `BearerMiddlewareTest.php:183-189` | ✅ |
| session token + `pending_verification` user → 403 | 403 | `BearerMiddlewareTest.php:198-199` | ✅ |

### P1: Revogação (AUTH-33 partial, BT-11–12)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| revoke existing → row removed | count 0 | `RevokeAuthTokenTest.php:60` | ✅ |
| revoke missing → no error | idempotent | `RevokeAuthTokenTest.php:62-64` | ✅ |
| revoke all for user → all rows gone | deleted=2, count 0 | `RevokeAuthTokenTest.php:84-86` | ✅ |
| revoked Bearer → 401 | 401 | `BearerMiddlewareTest.php:131-133` | ✅ |

### P1: Identidade e ownership (AUTH-37, AUTH-38, BT-13–14)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| middleware autentica → principal no container | resolvable contract | `AuthenticateBearer.php:49`; probe via `TestingAuthProbeController.php:16` | ⚠️ indirect |
| owner mismatch → 404 `RESOURCE_NOT_FOUND` | exception / response factory | `AuthorizesOwnedResourceTest.php:53`; `AuthErrorResponseFactoryTest.php:45-46` | ⚠️ no HTTP E2E |
| owner match → allow | no throw | `AuthorizesOwnedResourceTest.php:35-37` | ✅ |
| consumer depends only on `Modules\Auth\Contracts\` | no Eloquent cross-import | `ModularMonolithTest.php:49-51` — Eloquent models module-scoped (vacuous until consumer modules) | ✅ (gate) |

### P2: Observabilidade plaintext (BT-15)

| Criterion | Outcome | Evidence | Result |
| --- | --- | --- | --- |
| emissão/validação/revogação failures → no plaintext in messages | sentinel absent | `AuthTokenExceptionTest.php:18-19` — static factories only | ❌ GAP |
| auth error HTTP body → documented codes only | codes in JSON | `AuthErrorResponseFactoryTest.php:17-20,29-30` | ✅ |

---

## Discrimination Sensor

Mutations applied in scratch (backup → patch → run tests → restore). Production tree verified clean after runs.

| Mutation | File:line | Description | Filter / scope | Killed? |
| -------- | --------- | ----------- | -------------- | ------- |
| M1 | `TokenKind.php:18` | verification idle TTL `3600` → `7200` | `AuthToken`, `ValidateAuthToken` | ✅ Killed — failures in `AuthTokenTest`, `ValidateAuthTokenTest` |
| M2 | `ValidateAuthToken.php:55` | comment out `touchLastUsedAtIfStale` call | `ValidateAuthToken`, `BearerMiddleware` | ⚠️ Inconclusive — first scratch patch did not apply; retry caused parse error; throttle tests exist and would fail if touch never ran |
| M3 | `AuthenticateBearer.php:18` | accept lowercase `bearer ` prefix | `BearerMiddleware` | ✅ Killed — `BearerMiddlewareTest.php:58-65` lowercase case fails |
| M4 | `RequireTokenKind.php:34` | disable `pending_verification` + session guard | `BearerMiddlewareTest.php` | ✅ Killed — `returns 403 for session token with pending verification user` failed |
| M5 | `TokenKind.php:13` | verification absolute TTL `86400` → `172800` | `IssueAuthToken`, `TokenKind` | ✅ Killed — 2 failures in `IssueAuthTokenTest`, `TokenKindTest` |

**Sensor depth**: P1-targeted (TTL, idle, Bearer parse, kind guard, pending_verification)  
**Result**: **4/5 confirmed killed**, 1 inconclusive — ✅ PASS (sensor adequate for core paths)

---

## Gate Check

| Gate | Command | Result (reported) |
| ---- | ------- | ----------------- |
| Lint + static analysis | `make lint` | ✅ exit 0 |
| Backend tests | `make test-backend` | ✅ exit 0 |
| Auth token coverage | `make test-backend-coverage` | ✅ exit 0 (auth coverage gate script passed) |
| Architecture | `ModularMonolithTest.php` (via lint/test suite) | ✅ (included in gates) |
| Bearer-scoped tests (verifier spot-check) | 14 files, 57 tests | ✅ 57 passed, 135 assertions |

**Test files in scope** (bearer-related):

- `Feature/BearerMiddlewareTest.php`
- `Integration/IssueAuthTokenTest.php`, `ValidateAuthTokenTest.php`, `RevokeAuthTokenTest.php`, `EloquentAuthTokenRepositoryTest.php`, `AuthTokensSchemaContractTest.php`
- `Unit/AuthorizesOwnedResourceTest.php`, `AuthTokenExceptionTest.php`, `AuthErrorResponseFactoryTest.php`, `Sha256TokenHasherTest.php`, `BearerTokenGeneratorTest.php`, `AuthTokenTest.php`, `AuthTokenIdTest.php`, `TokenKindTest.php`

---

## Edge Cases (spec §Edge Cases)

| Edge case | Evidence | Result |
| --- | --- | --- |
| `Authorization: bearer` lowercase → 401 | `BearerMiddlewareTest.php:58-65` | ✅ |
| Token at exact `expires_at` → expired | `AuthTokenTest.php:25-26` | ✅ |
| Idle at exact limit → valid (exclusive) | `AuthTokenTest.php:41-42` | ✅ |
| Session idle 24h boundary | `AuthTokenTest.php:75-76` | ✅ (unit) |
| `RevokeAllUserTokens` zero tokens → no-op | `RevokeAuthTokenTest.php:87` | ✅ |
| Token with trailing spaces / extra trim | — | ❌ not tested |
| FK RESTRICT on user delete | FK exists (`AuthTokensSchemaContractTest.php:73`); RESTRICT in migration `:27` | ⚠️ behavior not integration-tested |

---

## Ranked Gap List

1. **BT-15 / P2 plaintext discipline** — `AuthTokenExceptionTest` only covers static factory messages; no sentinel-plaintext test through `ValidateAuthToken`, `IssueAuthToken`, or `RevokeAuthToken` failure paths. **Priority: Major**
2. **Invalid `token_kind` before persist** — P1 emissão AC5 has no runtime test (`TokenKind::from('invalid')` or DB CHECK violation). **Priority: Minor** (enum + migration CHECK mitigate)
3. **`AuthenticatedPrincipal` field completeness** — integration test omits `tokenId()` and `expiresAt()` assertions (BT-05, AUTH-37). **Priority: Minor**
4. **Revoked token must not touch `last_used_at`** — BT-08 AC4 partially covered (expired/idle yes; revoked no explicit assertion). **Priority: Minor**
5. **Ownership HTTP 404 mapping** — BT-14 / AUTH-38 tested at unit + response factory; no feature test proving controller/handler returns `404 RESOURCE_NOT_FOUND`. **Priority: Minor**
6. **Session idle at integration/HTTP layer** — domain unit tests cover; no HTTP test with 24h+1s session token. **Priority: Minor**
7. **M2 throttle mutation inconclusive** — consider dedicated test that fails if `touchLastUsedAtIfStale` is removed (already implied by timestamp assertions). **Priority: Trivial**

---

## Summary

**Overall**: ⚠️ **Conditional PASS**

**Spec-anchored check**: 27/27 requirements mapped; ~18 fully evidenced, 7 partial/spec-precision, 1 fail (BT-15 incomplete), 1 compile-time-only gap (invalid kind)  
**Sensor**: 4/5 confirmed killed (1 inconclusive)  
**Gate**: lint + test-backend + coverage reported green; verifier spot-check 57/57 bearer tests pass

**What works**: Migration + domain + use cases + middleware + probe routes; TTL absolute/idle; throttle; kind restriction; revocation; account status codes; SHA-256 hash storage; docs alignment.

**Blockers before marking fully verified**: Close BT-15 gap (sentinel through real failure paths). Remaining gaps are minor hardening.

**Next steps**: Add BT-15 integration test with marker plaintext on validate/revoke failures; optionally assert full principal fields and revoked-token `last_used_at`; re-run Verifier.
