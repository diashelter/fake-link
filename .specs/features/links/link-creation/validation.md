# Links — Criação de link Validation

**Date**: 2026-09-18  
**Spec**: `.specs/features/links/link-creation/spec.md`  
**Diff range**: `316587ca..7dfdec0d` (`46e081a9`…`7dfdec0d` feature commits)  
**Verifier**: independent sub-agent (author ≠ verifier)  
**Working tree verified**: commit tip `7dfdec0d` (read-only analysis; mutants only in scratch overlay)

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | Config `links.php`, env, validate-env |
| T2 | ✅ Done | `SLUG_GENERATION_FAILED` in api.md + OpenAPI |
| T3 | ✅ Done | `ApiFormRequest::errorCodes()` |
| T4 | ✅ Done | `EffectiveStatus` |
| T5 | ✅ Done | `LinkETag` |
| T6 | ✅ Done | Create link DTOs |
| T7 | ✅ Done | `ShortLinkRepository` |
| T8 | ✅ Done | `DestinationVersionRepository` |
| T9 | ✅ Done | `CreateLink` UseCase + transaction |
| T10 | ✅ Done | `CreateLinkRequest` closed payload |
| T11 | ✅ Done | `LinkDetailResource` + response factory |
| T12 | ✅ Done | `LinkErrorResponseFactory` |
| T13 | ✅ Done | Route / controller / e2e |
| T14 | ✅ Done | Throttle 60/min |
| T15 | ✅ Done | Concurrency suite |
| T16 | ✅ Done | OpenAPI contract tests |
| T17 | ✅ Done | Telemetry + redaction sentinel |

All T1–T17 Done-when checkboxes marked complete in `tasks.md`.

---

## Spec-Anchored Acceptance Criteria

### P1: Criar link com slug automático (LNK-30, LNK-32, LNC-01…04)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| Session token + `destination_url` → 201 + headers | `201`; `Location: /api/v1/links/{id}`; ETag `^"[^"]+"$`; `Cache-Control` private,no-store; `X-Request-ID` | `CreateLinkTest.php:103-117` — `assertCreated()`, `Location`/`ETag`/`Cache-Control`/`X-Request-ID` | ✅ PASS |
| Body is exact `LinkDetail` envelope | `data` keys only LinkDetail; no `version`/`blocked_at`/`user_id`/analytics | `CreateLinkTest.php:597-609` — `array_keys(...)->toEqualCanonicalizing([...])` | ✅ PASS |
| No `custom_alias` → 8-char `[a-z0-9]` + `slug_source=automatic` | slug `^[a-z0-9]{8}$`; `slug_source` `automatic` | `SlugGeneratorTest.php:44-48` — `toMatch('/^[a-z0-9]{8}$/')`; `CreateLinkTest.php:105` — `assertJsonPath('data.slug_source', 'automatic')` | ✅ PASS |
| Initial fields / timestamps | UUID v7 id; `is_enabled=true`; `status=active`; `title`/`expires_at` null when omitted; ISO `Z` dates | `ShortLinkRepositoryTest.php:97` — UUID v7 regex; `CreateLinkTest.php:106-108,119-120` — enabled/status/title null + `Z` timestamps; `LinkDetailResourceTest.php:84-85` — null `expires_at` serialization; `CreateLinkTest.php`(integration):`161-162` — DTO nulls | ✅ PASS |
| `short_url` from config base, not request host | `{links.short_url.base_url}/{slug}` | `CreateLinkTest.php:118,142-143` — `https://go.localhost/...`, not `evil` | ✅ PASS |
| Ownership + initial row state | `user_id` = token owner; `blocked_at` null; `version=1`; payload `user_id` rejected | `CreateLinkTest.php:567` — `user_id`; integration `CreateLinkTest.php:427-432` — enabled/blocked/version; `CreateLinkRequestTest.php:283` — `UNKNOWN_FIELD` for `user_id` | ✅ PASS |

### P1: Alias personalizado (LNC-05…07)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Mixed-case alias normalizes | e.g. `"Architecture"` → `architecture`; `slug_source=custom`; `short_url` uses normalized | `CreateLinkTest.php:159-161` — `My-Cool-Alias` → `my-cool-alias` + custom + short_url; `SlugTest.php:28` — `Architecture` → lowercase | ✅ PASS |
| Normalization before reserve/persist | persisted slug lowercase in both tables | integration `CreateLinkTest.php:180` — `slug` `my-alias`; concurrency / feature conflict use lowercase reservation | ✅ PASS |
| Invalid alias → uniform `INVALID_ALIAS` | `422` + `errors.custom_alias[0].code=INVALID_ALIAS`; no rule leak | `CreateLinkTest.php:286-288`; `CreateLinkRequestTest.php:123-125` — not contain `character` | ✅ PASS |
| Explicit `null` alias → `422 INVALID_ALIAS` | not treated as absent | `CreateLinkRequestTest.php:105`; `CreateLinkTest.php:444-445` | ✅ PASS |
| Valid alias → reservation + `slug_source=custom` | same transaction; source custom | integration `CreateLinkTest.php:180-184` | ✅ PASS |

### P1: Transação única (LNK-31, LNC-08…10)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Happy path three rows | exactly 1 reservation + 1 link + 1 version | integration `CreateLinkTest.php:154` — `tableCounts() === [1,1,1]` | ✅ PASS |
| First version encrypted + `valid_to=null` + `key_id` | ciphertext; no plaintext URL; `valid_to` null; `key_id` set | `DestinationVersionRepositoryTest.php:104-107`; integration `CreateLinkTest.php:201-204` — stored not contain plaintext | ✅ PASS |
| Destination insert fail → no link/reservation | zero rows | integration `CreateLinkTest.php:260-261` | ✅ PASS |
| Link insert fail → no reservation | zero rows | integration `CreateLinkTest.php:311-312` | ✅ PASS |
| Any rollback → no `201` / no partial rows | no success path with partial state | covered by fail-path counts above + feature 422/409/503 count asserts | ✅ PASS |
| Postgres/infra failure → `503 SERVICE_UNAVAILABLE` + `request_id`, no leak | `503` + code + `request_id` | `LinkErrorResponseFactoryTest.php:52-55` — code/request_id; `CreateLinkTelemetryTest.php:273` — HTTP `503` on unexpected throw; controller maps `Throwable` → `serviceUnavailable()` | ✅ PASS |

### P1: ETag (LNK-32, LNC-11…12)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Strong ETag format | no `W/`; `^"[^"]+"$` | `LinkETagTest.php:55`; `CreateLinkTest.php:114` | ✅ PASS |
| Canonical tuple via HMAC-SHA256 | id, slug, dest, title, enabled, expires, blocked, updated, effective status | `LinkETag.php:38-50` + sensitivity tests `LinkETagTest.php:64-115` per field | ✅ PASS |
| Opacity | cannot recover version / user_id / destination | `LinkETagTest.php:126-132` | ✅ PASS |
| Sensitivity | any tuple field change → different ETag | `LinkETagTest.php:64-115,152` | ✅ PASS |
| Determinism | same state → same ETag | `LinkETagTest.php:60` | ✅ PASS |

### P1: Conflito / exaustão (LNK-33, LNC-13…15)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Reserved alias → `409 ALIAS_UNAVAILABLE` | code + no new link / no reservation mutate | `CreateLinkTest.php:216-218` | ✅ PASS |
| Identical 409 for linked vs orphan | same code/message/keys; no occupant data | `CreateLinkTest.php:260-265,221-223` | ✅ PASS |
| Invalid+occupied → `422` not `409` | validation precedes write | `CreateLinkTest.php:286-290` | ✅ PASS |
| Exhaustion → `503 SLUG_GENERATION_FAILED` + `Retry-After` | code + header ≥1; no partial link | `CreateLinkTest.php:315-320` | ✅ PASS |
| Concurrent equivalent aliases | exactly one `201` + one `409`; single rows | `CreateLinkConcurrencyTest.php:146,167-169` | ✅ PASS |

### P1: Validação payload fechado (LNK-30, LNC-16…18)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Missing `destination_url` → `REQUIRED` | `422` + code | `CreateLinkTest.php:388`; `CreateLinkRequestTest.php:63` | ✅ PASS |
| Bad destination → `INVALID_DESTINATION_URL`, no echo | code; body lacks URL | `CreateLinkTest.php:405,545`; `CreateLinkRequestTest.php:69,78` | ✅ PASS |
| Title 161 → `TITLE_TOO_LONG`; 160 OK | codes / accept | `CreateLinkRequestTest.php:177,186,194-202` | ✅ PASS |
| `""` / spaces title → persist `null` | DTO title null | `CreateLinkRequestTest.php:165-166` | ✅ PASS |
| `expires_at <= now` → `EXPIRES_AT_NOT_IN_FUTURE` | code | `CreateLinkRequestTest.php:242`; `CreateLinkTest.php:485` | ✅ PASS |
| Non-`Z` datetime → `INVALID_DATETIME` | `+00:00` rejected | `CreateLinkRequestTest.php:223`; `CreateLinkTest.php:505` | ✅ PASS |
| Extra field → `UNKNOWN_FIELD` | per field | `CreateLinkRequestTest.php:283-294`; `CreateLinkTest.php:525` | ✅ PASS |
| Any `422` writes nothing | zero rows in three tables | `CreateLinkTest.php:390-392` (and siblings per case) | ✅ PASS |

### P1: Auth + rate limit (LNK-34, LNC-19…21)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| No/invalid bearer → `401 UNAUTHENTICATED` | code | `CreateLinkTest.php:327-328,341-342` | ✅ PASS |
| Verification token → `403 TOKEN_RESTRICTED` | code; no link | `CreateLinkTest.php:352-355` | ✅ PASS |
| Suspended / pending deletion → `403` codes | `ACCOUNT_SUSPENDED` / `ACCOUNT_PENDING_DELETION` | `CreateLinkTest.php:366,377` | ✅ PASS |
| 61st in 60s → `429 RATE_LIMIT_EXCEEDED` + `Retry-After` ≥1 | status/code/header | `CreateLinkRateLimitTest.php:99-102` | ✅ PASS |
| `422`/`409`/`503` consume attempt | attempts == 1 after each | `CreateLinkRateLimitTest.php:115,137,160` | ✅ PASS |
| Unauthenticated does not consume | attempts stay 0 | `CreateLinkRateLimitTest.php:173` | ✅ PASS |
| Distinct accounts independent | second account still creates after first limited | `CreateLinkRateLimitTest.php:210-220` | ✅ PASS |
| HMAC key; no plaintext user_id | key equals `hash_hmac(...)`; not raw id | `CreateLinkRateLimitTest.php:230` | ✅ PASS |
| Redis fail-open + metric | request proceeds `201`; warning metric | `CreateLinkRateLimitTest.php:258,264-266` | ✅ PASS |

### P1: OpenAPI (LNC-22 + docs)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --------- | -------------------- | ----------------------- | ------ |
| Request + 201 vs schemas/headers | CreateLinkRequest / LinkCreated | `CreateLinkContractTest.php:76-109` | ✅ PASS |
| 409/422/429 vs schemas | LinkConflict / ValidationError / TooManyRequests | `CreateLinkContractTest.php:149-200+` | ✅ PASS |
| Docs + `lint-openapi` | `SLUG_GENERATION_FAILED` documented; spectral 0 errors | T2 artifacts; gate `make lint-openapi` → 0 errors (3 inherited warnings) | ✅ PASS |
| 201 `data` no extras (`additionalProperties:false`) | reject injected `version` | `CreateLinkContractTest.php:132-146` | ✅ PASS |

**Status**: ✅ All ACs covered — **48/48** matched spec outcome · **0** spec-precision gaps flagged

---

## Discrimination Sensor

Scratch method: detached worktree at `7dfdec0d` + single-file bind-mount overlays into main Docker `backend` container (worktree lacks `vendor`). Mutations discarded afterward; worktree removed.

| # | Mutation | File | Description | Killed? |
| - | -------- | ---- | ----------- | ------- |
| 1 | Transaction removed | `UseCases/CreateLink.php` | Run create closure without `DB::transaction` | ✅ Killed — integration rollback tests failed (`reservations => 1`) |
| 2 | Conflict → 200 | `CreateLinkController.php` | `SlugUnavailable` returns JSON 200 | ✅ Killed — `CreateLinkTest` expected 409 got 200 |
| 3 | Exhaustion → 500 | `CreateLinkController.php` | `SlugGenerationExhausted` → 500 INTERNAL | ✅ Killed — expected 503 got 500 |
| 4 | Constant ETag | `LinkETag.php` | Always return fixed hex digest | ✅ Killed — 12 sensitivity/determinism failures |
| 5 | Never throttle | `ThrottleLinkCreation.php` | Skip `tooManyAttempts` branch | ✅ Killed — 61st expected 429 got 201 |
| 6 | Ignore unknown fields | `CreateLinkRequest.php` | `$extra = []`; no `UNKNOWN_FIELD` map | ✅ Killed — UNKNOWN_FIELD tests expected 422 got 200 |

**Sensor depth**: P0-full (critical: transaction, authz/conflict, rate limit) — 6 ≥ 5  
**Result**: 6/6 killed — PASS ✅

---

## Interactive UAT Results

Not performed — backend-only API feature; automated gates sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ Auth throttle / FormRequest / Resource patterns reused |
| Spec-anchored outcome check | ✅ |
| Per-layer coverage (domain 1:1; routes happy+edge+error) | ✅ |
| Every create-link test maps to AC / edge / Done-when | ✅ |
| Documented guidelines | ✅ `docs/testing.md` §3–§7, `LARAVEL_CODE_DESIGN.md`, AD-011/016 |

---

## Edge Cases

- [x] Sequential case-equivalent aliases → second `409` (`CreateLinkTest` Taken-Alias)
- [x] Alias length bounds → `INVALID_ALIAS` (`CreateLinkRequestTest` too-short; slug-policy VO covers max)
- [x] Denylist / reserved → `INVALID_ALIAS` (controller `SlugPolicyException` path + request/VO)
- [x] Unicode/homoglyph alias → `INVALID_ALIAS` (slug-policy VO; request delegates)
- [x] Title 160/161 + multibyte character count (`CreateLinkRequestTest.php:189-202`)
- [x] Title trim / whitespace-only → null (`CreateLinkRequestTest.php:149-166`)
- [x] `expires_at == now` rejected; future accepted; `+00:00` invalid; explicit null OK
- [x] Explicit `custom_alias: null` → `422`
- [x] Extra fields `user_id`/`slug`/… → `UNKNOWN_FIELD`
- [x] Empty `{}` → `REQUIRED` on destination
- [x] Concurrent race → one 201 / one 409
- [x] Slug generation exhaustion → 503, no partial rows
- [x] Rate-limit fail-open on Redis
- [ ] `Idempotency-Key` accepted+ignored (two links) — **not dedicated** (out of scope / known divergence; assumed by assumption table)
- [ ] Global `400`/`413`/`405` — asserted as Auth/global layer, not re-proven here (spec Out of Scope: assert not build)

---

## Gate Check

| Gate | Command | Result |
| ---- | ------- | ------ |
| Build (backend) | `make lint-backend` | ✅ exit 0 (Pint + PHPStan) |
| Full | `make test-backend` | ✅ **987 passed** (4827 assertions), 0 failed, 0 skipped |
| Contract lint | `make lint-openapi` | ✅ 0 errors / 3 inherited warnings (info-contact, unrelated operation-description) |
| Full monorepo `make lint` | not required for verdict | ⚠️ May fail on inherited frontend e2e TS2353 (`launchOptions`) — documented pre-existing on `main`; **not** a link-creation FAIL |

- **Test count after feature**: 987  
- **Test count before feature** (approx. parent of first commit): not re-counted in this run; suite green with large Links surface added; no evidence of silent deletions  
- **Skipped tests**: none  
- **Failures**: none  

Coverage gate (`make test-backend-coverage`) not re-run in this verification pass; implementer handoff recorded Links ≥90%/85% (94.26% lines / 91.52% methods) after T17 — accepted as prior evidence; full suite green.

---

## Fix Plans

None — no surviving mutants, no uncovered ACs.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| LNK-30…34 | Pending / Implementing | ✅ Verified |
| LNC-01…22 | Pending / Implementing | ✅ Verified |

*(Statuses reflected here; orchestrator may sync `spec.md` table.)*

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 48/48 ACs matched spec outcome | 0 spec-precision gaps  
**Sensor**: 6/6 mutations killed  
**Gate**: 987 passed (`make test-backend`); `make lint-backend` + `make lint-openapi` clean  

**What works**: End-to-end create link (auto + custom), transactional integrity, ETag, conflicts/exhaustion, closed payload codes, auth boundaries, rate limit, contract tests, redacted telemetry.

**Issues found**: none grounded.

**Next steps**: Orchestrator may close Handoff as Verified PASS; proceed to merge/PR when ready. Inherited frontend lint failure remains out of scope for this feature.
