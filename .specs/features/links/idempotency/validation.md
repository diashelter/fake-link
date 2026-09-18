# Links — Idempotência Validation

**Date**: 2026-09-18  
**Spec**: `.specs/features/links/idempotency/spec.md`  
**Diff range**: `bc6b4755..HEAD` (`bc6b475558fe851f0d79e28aaeb1fdb56558b6fb` … `e1d901714fa394d564f6d6255d69e5ee60dec330`)  
**Verifier**: independent sub-agent (author ≠ verifier)  
**Re-verify**: iteration 1/3 after fix commit `e1d90171`

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | All Done-when checked |
| T2 | ✅ Done | All Done-when checked |
| T3 | ✅ Done | All Done-when checked |
| T4 | ✅ Done | All Done-when checked |
| T5 | ✅ Done | All Done-when checked |
| T6 | ✅ Done | All Done-when checked |
| T7 | ✅ Done | All Done-when checked |
| T8 | ✅ Done | All Done-when checked |
| T9 | ✅ Done | All Done-when checked |

---

## Spec-Anchored Acceptance Criteria

### P1: Criar link com proteção idempotente

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN session user creates with valid Idempotency-Key THEN exactly one link + one `idempotency_keys` row in same transaction | 1 short_link, 1 idempotency_keys with snapshot | `CreateIdempotentLinkTest.php:181-189` — `replayed` false; `short_links`=1; `idempotency_keys`=1; snapshot not null | ✅ PASS |
| WHEN transaction confirms THEN record has `user_id`, `key_hash`, `request_fingerprint`, `response_snapshot`, `key_id`, `created_at`, `expires_at = created_at + 24h`; no raw key | columns + `expires_at - created_at == 86400` | Schema/no raw key: `IdempotencyKeyRepositoryTest.php:77-99`. UseCase TTL: `CreateIdempotentLinkTest.php:191-197` — `$expiresAt->getTimestamp() - $createdAt->getTimestamp())->toBe(24 * 60 * 60)` | ✅ PASS |
| WHEN header absent THEN non-idempotent create; SHALL NOT create `idempotency_keys` | `idempotency_keys` count = 0 | `CreateLinkTest.php:124-128` — `createLinkTableCounts()` + `DB::table('idempotency_keys')->count())->toBe(0)` | ✅ PASS |
| WHEN header &lt;16, &gt;128, or charset invalid THEN `422` + `INVALID_IDEMPOTENCY_KEY`; no writes | status 422, code INVALID_IDEMPOTENCY_KEY, zero rows | `CreateLinkTest.php:672-689` — `assertStatus(422)` + `errors.Idempotency-Key.0.code` = `INVALID_IDEMPOTENCY_KEY` + counts 0; bounds: `IdempotencyKeyTest.php:9-36` | ✅ PASS |
| WHEN auth/token/account/rate-limit/payload fail THEN applicable response; SHALL NOT reserve key | no `idempotency_keys` row | With key: `CreateLinkTest.php:627-635` (401), `:638-652` (403 verification), `:655-669` (403 suspended), `:692-715` (429) — `idempotency_keys` count 0 | ✅ PASS |

### P1: Reproduzir a resposta original

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN same user/key/command within 24h after first 201 THEN 201 and SHALL NOT create another link/reservation/version | status 201, links=1 | `CreateLinkTest.php:720-745` — both `assertCreated()`, links=1, `idempotency_keys`=1; integration `CreateIdempotentLinkTest.php:212-219` — `replayed` true, slug source calls unchanged | ✅ PASS |
| WHEN replayed THEN status, UTF-8 body bytes, Location, ETag, Cache-Control identical to original | byte-identical body + semantic headers | `CreateLinkTest.php:740-743` — `$secondBody)->toBe($firstBody)` + Location/ETag/Cache-Control equal; unit `LinkResponseFactoryTest.php:63-73` | ✅ PASS |
| WHEN replayed THEN new `X-Request-ID` | distinct request ID | `LinkResponseFactoryTest.php:76-87` — `req-a` vs `req-b`; snapshot DTO excludes request ID | ✅ PASS |
| WHEN link altered afterward THEN replay still returns original 201 snapshot (no reconstruct) | body/ETag unchanged vs original despite DB change | `CreateLinkTest.php:748-797` — mutate `short_links`/`link_destination_versions` then replay; `$secondBody)->toBe($firstBody)`, contains `Original Title`, not `Mutated Live Title`, ETag/Location preserved, live row still mutated | ✅ PASS |
| WHEN snapshot persisted THEN AES-256-GCM with exclusive keyring; no cleartext destination/title/body/headers in persistence/logs/metrics/traces | ciphertext opaque; sentinels absent | `Aes256GcmIdempotencySnapshotCipherTest.php` round-trip; privacy `CreateIdempotentLinkPrivacyTest.php:143-157` | ✅ PASS |

### P1: Distinguir reuso indevido e concorrência

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN same user, non-expired key, different fingerprint THEN `409` + `IDEMPOTENCY_KEY_REUSED`; no side effects | status 409, code IDEMPOTENCY_KEY_REUSED, links unchanged | `CreateLinkTest.php:800-827` — `assertStatus(409)` + `code` = `IDEMPOTENCY_KEY_REUSED` + links=1; integration throws `IdempotencyKeyReused` `:222-232` | ✅ PASS |
| WHEN two users use same raw key THEN independent scopes, each may create | 2 links, 2 idempotency rows | `CreateIdempotentLinkTest.php:286-303` | ✅ PASS |
| WHEN two concurrent same user/key/command THEN exactly one creates; other awaits and gets 201 replay if author commits | 1 link; second `replayed` | `CreateIdempotentLinkTest.php:330-352` — two connections; second `replayed` true, `source->calls` = 1. Shape is sequential across connections (not OS-thread parallel overlap) | ✅ PASS |
| WHEN author rolls back THEN no residue; competitor may execute | all related tables empty, then create succeeds | `CreateIdempotentLinkTest.php:356-392` | ✅ PASS |
| WHEN compare THEN JSON order / alias case / title trim / absent vs null → same FP; normalized field change → different FP | equal/unequal fingerprints | `CanonicalCreateLinkCommandTest.php:39-93` | ✅ PASS |

### P2: Expirar e limpar registros idempotentes

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN `now() < expires_at` THEN eligible for replay/conflict | active lookup returns record / replay works | Replay paths while unexpired; `IdempotencyKeyRepositoryTest.php:140` findActive within window | ✅ PASS |
| WHEN `now() >= expires_at` THEN key treated as new; no replay/conflict with expired row | findActive null at equality; reuse creates new | `IdempotencyKeyRepositoryTest.php:194-204` — `findActive(...$now)->toBeNull()` when `expires_at == now`; UseCase reuse `CreateIdempotentLinkTest.php:255-283` after expiry | ✅ PASS |
| WHEN scheduler runs THEN remove only `expires_at <= now()` in batches; safe to repeat | only expired removed; second run stable | `PruneExpiredIdempotencyKeysTest.php:78-92`, `:115-128`; batch `:95-112`; schedule `* * * * *` + withoutOverlapping `:210-225` | ✅ PASS |
| WHEN cleanup fails THEN emit sanitized signal; SHALL NOT delete non-expired / affect valid key | failure + valid keys remain **and** sanitized `cleanup_failed` | `PruneExpiredIdempotencyKeysTest.php:191-205` — `assertFailed()`; log message `links.idempotency.cleanup_failed`; level `warning`; context `['operation' => 'prune']` only; no secret/exception text; valid+expired rows remain | ✅ PASS |
| WHEN decrypt of non-expired snapshot fails THEN `503 SERVICE_UNAVAILABLE`; no second link; no partial Location/ETag | status 503, code SERVICE_UNAVAILABLE, Location/ETag null, links=1 | `CreateLinkTest.php:830-857`; privacy `:160-188` | ✅ PASS |

**Status**: ✅ All ACs covered (20/20; prior Fix 1–5 gaps closed)

---

## Edge Cases

| Edge case | Evidence | Result |
| --- | --- | --- |
| Key length 16 or 128 accepted; 15 or 129 rejected | `IdempotencyKeyTest.php:9-27` | ✅ |
| Space, newline, Unicode, `%` → invalid | `IdempotencyKeyTest.php:29-36` | ✅ |
| Alias `"Architecture"` / `"architecture"` → same fingerprint / replay | `CanonicalCreateLinkCommandTest.php:39-71` | ✅ |
| Different JSON property order does not create second link | Canonicalization equal FP `:39-71` + replay single-link paths | ✅ |
| No Bearer / verification token / suspended / 429 does not block key for later valid attempt | 401/403/429/suspended+key leave `idempotency_keys`=0 (`CreateLinkTest.php:627-715`) | ✅ |
| Reuse after expiry creates new record/link | `CreateIdempotentLinkTest.php:255-283` | ✅ |
| Encrypt/persist/commit failure leaves no usable snapshot without confirmed create | rollback `CreateIdempotentLinkTest.php:234-252` | ✅ |
| Tampered envelope never yields partial body/Location/ETag | Feature `:848-855`; privacy `:160-188`; cipher fail-closed | ✅ |

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `CreateLinkController.php:41-47` | Absent header forced through synthetic `Idempotency-Key` (always reserve) | ✅ Killed (`CreateLinkTest.php:128`) |
| 2 | `CreateIdempotentLink.php:32` | TTL `PT24H` → `PT1H` | ✅ Killed (`CreateIdempotentLinkTest.php:197`) |
| 3 | `CreateIdempotentLink.php:136-144` | Reconstruct replay body title from live `short_links` | ✅ Killed (`CreateLinkTest.php:788`) |
| 4 | `PruneExpiredIdempotencyKeys.php:39-42` | Dropped `Log::warning('links.idempotency.cleanup_failed', …)` | ✅ Killed (`PruneExpiredIdempotencyKeysTest.php:198`) |
| 5 | `CreateIdempotentLink.php:59` | Forced `$existing = null` (skip replay branch) | ✅ Killed |
| 6 | `CreateIdempotentLink.php:127` | Inverted fingerprint compare (`! hash_equals` → `hash_equals`) | ✅ Killed |

**Sensor depth**: P0-full (≥5 behavior-level mutations; focused on Fix 1–5 gaps)  
**Scratch method**: temporary in-tree mutation + `git checkout --` restore; working tree production files left clean  
**Result**: 6/6 killed — sensor PASS ✅

---

## Interactive UAT Results

Not performed — backend/infrastructure feature; automated checks sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ |
| Matches patterns | ✅ |
| Spec-anchored outcome check | ✅ (20/20 ACs) |
| Per-layer Coverage Expectation | ✅ |
| Every test maps to a spec requirement | ✅ |
| Documented guidelines followed | ✅ `docs/testing.md`, `LARAVEL_CODE_DESIGN.md`, AD-011 |

---

## Gate Check

| Gate | Command | Result |
| ---- | ------- | ------ |
| Full / Quick | `make test-backend` | ✅ **1058 passed**, 0 failed (5232 assertions) |
| Contract | `make lint-openapi` | ✅ exit 0 (3 pre-existing warnings, 0 errors) |
| Backend quality | `make lint-backend` | ✅ Pint + PHPStan + PHPMD OK |
| Build (`make lint`) | not re-run end-to-end | Known inherited: frontend e2e TS may fail on `main` (STATE note) — out of slice |

- **Test count after feature**: 1058 passed (was 1056 at pre-fix FAIL; +2 from Fix iteration 1)  
- **Test count before feature** (`bc6b4755`): not re-listed; no evidence of deleted/weakened tests in range  
- **Skipped tests**: none observed in gate output  
- **Failures**: none  

---

## Fix Plans (gaps)

None — prior Fix 1–5 closed; re-verify found no remaining AC gaps or surviving mutants.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| LNK-40 … LNK-44 / LNI-01…LNI-12 | ❌ Needs Fix (Verifier FAIL) | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 20/20 ACs matched spec outcome | 0 gaps  
**Sensor**: 6/6 mutations killed  
**Gate**: 1058 passed (`make test-backend`); `lint-openapi` + `lint-backend` passed  

**What works**: Create/replay/conflict/503/privacy/canonicalization/prune boundaries/concurrency-across-connections; Fix 1–5 coverage (absent-header non-reservation, UseCase 24h TTL, alter-then-replay, sanitized `cleanup_failed`, suspended+key) now has `file:line` evidence and kills corresponding mutants.

**Issues found**: none

**Next steps**: Mark feature Verified; proceed to PR / next Links fatia as orchestrator decides.
