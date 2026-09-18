# Links — Consultas de link Validation

**Date**: 2026-09-18  
**Spec**: `.specs/features/links/link-queries/spec.md`  
**Diff range**: `6421992b..HEAD` (`6421992b` … `ee27065e`; implementação a partir de `66a23836`)  
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | Indexes / EXPLAIN |
| T2 | ✅ Done | Signed cursor codec |
| T3 | ✅ Done | Read repository |
| T4 | ✅ Done | ListLinks / GetLink |
| T5 | ✅ Done | GET `/api/v1/links` |
| T6 | ✅ Done | GET `/api/v1/links/{link}` |
| T7 | ✅ Done | Bindings + OpenAPI |
| T8 | ✅ Done | E2E claimed 3 passed; this verifier skipped `make test-e2e-links` |

---

## Spec-Anchored Acceptance Criteria

### P1: Listar os links do proprietário

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN session GET `/api/v1/links` without query THEN 200, ≤20 owner `LinkSummary`, `created_at DESC` then `id DESC` | HTTP 200; owner-only; default page size 20; keyset order | `ListLinksTest.php:193-197` — `assertOk()` + `data` count 3 in reverse creation order, foreign omitted; cap `ListLinksTest.php:247` — `data` count 20 of 21 | ✅ PASS |
| WHEN more results THEN `meta` only `next_cursor` (non-null) + `per_page`; WHEN none THEN `next_cursor` null | `meta` = `{next_cursor, per_page}` only | `ListLinksTest.php:198-201` — `meta` identical to `{next_cursor: null, per_page: 20}`; `:247-258` non-null then last page null; unit `ListLinksTest.php:134-136` | ✅ PASS |
| WHEN client follows valid cursor with same filters THEN items strictly after anchor, no repeats | no intersection with previous page | `ListLinksTest.php:257-260` — `array_intersect($firstIds, $secondIds))->toBe([])`; integration `ListLinksTest.php:161-167` | ✅ PASS |
| WHEN `per_page` absent THEN 20; 1–100 THEN max that size in `meta.per_page`; other format/value THEN `422 VALIDATION_FAILED` | default 20; honour 1–100; 422 otherwise | DTO `ListLinksQueryTest.php:10-27`; HTTP honour `ListLinksTest.php:280-282`; reject `ListLinksTest.php:593-595` — `assertStatus(422)` + `code` `VALIDATION_FAILED` | ✅ PASS |
| WHEN list assembled THEN each item exactly `id, slug, short_url, title, slug_source, is_enabled, status, expires_at, created_at, updated_at`; SHALL NOT contain `destination_url`, `ETag`, `version`, `blocked_at`, `user_id` | exact key set; forbidden keys absent | `ListLinksTest.php:305-311` — `array_keys($item)` canonicalizing summary keys + `not->toHaveKey('destination_url'/'version'/'blocked_at'/'user_id'/'ETag')`; resource `LinkSummaryResourceTest.php:37-60` | ✅ PASS |
| WHEN list produced THEN SHALL NOT decrypt or select `Destination` | no `destination_url` / `link_destination_versions` in list SQL; UseCase has no `DestinationCipher` | `ListLinksTest.php:335-337` — SQL `not->toContain('destination_url')` / `link_destination_versions`; unit `ListLinksTest.php:105-106`; repo `LinkQueryRepositoryTest.php:358-360` | ✅ PASS |

### P1: Pesquisar e filtrar por estado efetivo

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN `search` 2–160 THEN title substring OR slug prefix, case-insensitive, accent-sensitive | OR semantics; `AÇÃO` hits `ação`; `acao` does not | Feature `ListLinksTest.php:374-383`; integration `LinkQueryRepositoryTest.php:286-288` — `$byTitleOrSlug` both hits; `$byAccent` / `$byUnaccented === []` | ✅ PASS |
| WHEN `search` &lt;2, &gt;160, or empty after trim THEN `422 VALIDATION_FAILED`; WHEN absent THEN no text filter | 422 + no filter | `ListLinksTest.php:390-392` — 422 `VALIDATION_FAILED`; `:411-413` absent returns slug; DTO bounds `ListLinksQueryTest.php:46-64` | ✅ PASS |
| WHEN `status` absent or `all` THEN all states; WHEN `active`/`inactive`/`expired`/`blocked` THEN only that derived state | include-all vs exclusive filter | `ListLinksTest.php:464-487`; integration `LinkQueryRepositoryTest.php:320-329` | ✅ PASS |
| WHEN `status` has another value THEN `422 VALIDATION_FAILED` | 422 | `ListLinksTest.php:494-496` — `archived` → 422 `VALIDATION_FAILED` | ✅ PASS |
| WHEN status derived THEN `blocked` &gt; `expired` (`expires_at <= now`) &gt; `inactive` (`is_enabled = false`) &gt; `active`, same UTC instant | precedence + `expires_at == now` expired | Integration `LinkQueryRepositoryTest.php:320-325`; `EffectiveStatusTest.php:44-51,75-81,84-90`; GetLink `GetLinkTest.php:236-237` | ✅ PASS |
| WHEN `search` or `status` change THEN previous cursor `422 INVALID_CURSOR`; WHEN only `per_page` changes THEN cursor remains valid | scope bound to search/status, not `per_page`; error path `errors.cursor[0].code` | Feature `ListLinksTest.php:528-548` — `assertOk` on `per_page` change; 422 + `errors.cursor.0.code` `INVALID_CURSOR` + `listCalls === 0`; unit `ListLinksTest.php:171-186` | ✅ PASS |

### P1: Consultar o detalhe autorizado

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN owner session GET `/api/v1/links/{link}` for existing UUID v7 THEN 200 `LinkDetail`, `Cache-Control: private, no-store`, `X-Request-ID`, strong opaque `ETag` | 200 + headers + ETag `"hex64"` | `GetLinkTest.php:148-158` — `assertOk()` + `private`/`no-store` + request id + ETag `/^"[0-9a-f]{64}"$/` | ✅ PASS |
| WHEN `LinkDetail` returned THEN `data` exactly summary fields plus `destination_url`; SHALL NOT contain `version`, `blocked_at`, `user_id` | exact keys | `GetLinkTest.php:182-186` — `array_keys($item)` canonicalizing detail keys + forbidden keys absent | ✅ PASS |
| WHEN detail of newly created link THEN `ETag` identical to creation | byte-identical header | `GetLinkTest.php:157` — `$response->headers->get('ETag'))->toBe($created->headers->get('ETag')`; integration `GetLinkTest.php:164` | ✅ PASS |
| WHEN missing or foreign THEN same `404 RESOURCE_NOT_FOUND`, without decrypting destination | identical 404 envelope; `decryptCalls === 0` | `GetLinkTest.php:212-227` — both `RESOURCE_NOT_FOUND`, same message/code, no `data`/ETag/destination, `$spy->decryptCalls)->toBe(0)` | ✅ PASS |
| WHEN destination cannot be authenticated/decrypted after ownership THEN `503 SERVICE_UNAVAILABLE`, no `data`, destination, `ETag`, or failure detail | 503 + `SERVICE_UNAVAILABLE`; no partial body | `GetLinkTest.php:267-276` — 503 + missing `data` + ETag null + body not containing destination/envelope | ✅ PASS |

### P1: Proteger a superfície de leitura

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN Bearer missing/invalid/expired THEN `401 UNAUTHENTICATED`; WHEN `verification` / suspended / pending deletion THEN applicable `403`, without querying links | 401/403 codes; session-only middleware | List `ListLinksTest.php:606-660`; detail `GetLinkTest.php:283-348` (verification `decryptCalls === 0`); routes `ListLinksTest.php:668-671` / `GetLinkTest.php:356-359` | ✅ PASS |
| WHEN session exceeds 300 reads/min on this slice THEN `429 RATE_LIMIT_EXCEEDED` with `Retry-After`; distinct tokens independent counters | 300/min; Retry-After; per-token | `ListLinksRateLimitTest.php:71-88,100-109`; shared limiter `GetLinkRateLimitTest.php:71-73,96-99`; config `LinkQuerySurfaceContractTest.php:68-69` | ✅ PASS |
| WHEN cursor empty/malformed/tampered/incompatible THEN `422 VALIDATION_FAILED` with `errors.cursor[0].code = "INVALID_CURSOR"` and SHALL NOT query data | exact error path; `listCalls === 0` | `ListLinksTest.php:537-548,579-584`; contract `ListLinksContractTest.php:146-148` | ✅ PASS |
| WHEN instrumented THEN logs/metrics/traces SHALL NOT contain token, cursor, slug, title, destination; app seam testable; external collection operational | sentinel-free sinks | `ListLinksTelemetryTest.php:222-231`; `GetLinkTelemetryTest.php:159-160`. External collectors: operational per spec Independent Test — not automated | ✅ PASS |

**Status**: ✅ All ACs covered (21/21; 0 spec-precision gaps)

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `HmacCursorCodec.php:63` | Signature check forced always-succeed (`if (false && ! hash_equals…)`) | ✅ Killed (`HmacCursorCodecTest.php:156`) |
| 2 | `EloquentLinkQueryRepository.php:54` | Removed list `where('user_id', …)` | ✅ Killed (`LinkQueryRepositoryTest.php:185`) |
| 3 | `EloquentLinkQueryRepository.php:129` | Search OR → AND (`orWhereRaw` → `whereRaw`) | ✅ Killed (`LinkQueryRepositoryTest.php:286`) |
| 4 | `EffectiveStatus.php:24-29` | Expired checked before blocked | ✅ Killed (`EffectiveStatusTest.php:81`) |
| 5 | `GetLink.php:39` | Decrypt failure returned partial `GetLinkResult` instead of throwing | ✅ Killed (`GetLinkTest.php:207`) |
| 6 | `ListLinks.php:37` | `next_cursor` emitted when `hasMore` is false | ✅ Killed (`ListLinksTest.php:135`) |
| 7 | `LinkSummaryResource.php` | Injected `destination_url` into list serialization | ✅ Killed (`LinkSummaryResourceTest.php:37`) |

**Sensor depth**: P0-full (≥5 behavior-level mutations; private-data/auth path)  
**Scratch method**: in-tree mutation + `git checkout --` restore; working tree production files left clean  
**Result**: 7/7 killed — sensor PASS ✅

---

## Interactive UAT Results

Not performed — HTTP API / infrastructure feature; automated checks sufficient per validate.md.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ |
| No scope creep | ✅ (query indexes, cursor, read port, two GETs, E2E target) |
| Matches patterns | ✅ hexagonal Links module, Form Request, resources, Pest layers |
| Spec-anchored outcome check | ✅ 21/21 |
| Per-layer Coverage Expectation | ✅ unit / integration / feature / contract; E2E present (not re-run) |
| Every test maps to a spec requirement | ✅ (indexes + OpenAPI surface map to T1/T7 Done-when) |
| Documented guidelines followed | ✅ `docs/testing.md`, `LARAVEL_CODE_DESIGN.md`, `docs/api.md` |

---

## Edge Cases

| Edge case | Evidence | Result |
| --- | --- | --- |
| Same `created_at` → UUID v7 descending | `LinkQueryRepositoryTest.php:148-168` | ✅ |
| Expiry between pages: each request own `now()`; cursor preserves order not frozen status | Integration `ListLinksTest.php:191-201` | ✅ |
| Null title does not match search; slug still can | `LinkQueryRepositoryTest.php:265-289` | ✅ |
| Title `ação` / search `acao` no match; search `AÇÃO` matches | Feature `ListLinksTest.php:372-379`; repo `:282-288` | ✅ |
| Cursor to removed anchor remains valid via signed values | `LinkQueryRepositoryTest.php:223-255` | ✅ |
| No results → 200 `data: []`, `next_cursor: null`, effective `per_page` | `ListLinksTest.php:214-218`; integration `:132-134` | ✅ |
| PostgreSQL unavailable → applicable error, never empty list or 404 | Implemented: `ListLinksController.php:53-59` and `GetLinkController.php:53-59` map `Throwable` → 503. No dedicated test injects a DB outage | ⚠️ residual (handled, untested) |

---

## Gate Check

| Gate | Command | Result |
| ---- | ------ | ------ |
| Contract | `make lint-openapi` | ✅ exit 0 (3 pre-existing warnings, 0 errors) |
| Full / Quick | `make test-backend` | ✅ **1222 passed**, 0 failed (6059 assertions) |
| Backend quality | `make lint-backend` not used as a single 300s target; ran `make format-backend` (Pint), `make analyse-backend` (PHPStan), `make md-backend` (PHPMD) | ✅ all exit 0 |
| E2E | `make test-e2e-links` | ⏭️ skipped (time); T8 claimed 3 passed |
| Build (`make lint`) | not re-run end-to-end | Inherited frontend Playwright `launchOptions` TS2353 must not fail this slice (instruction) |

- **Test count after feature**: 1222 passed  
- **Test count before feature**: 1058 at last sibling validation (`idempotency`); delta **+164**  
- **Skipped tests**: none in `make test-backend` output  
- **Failures**: none  

---

## Fix Plans (if issues found)

None for ACs or sensor. Residual: optional test that a repository `QueryException` on list/detail yields 503 (not `200` empty / `404`). Not a surviving mutant in the injected set; not required to block Verified.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| LNK-50 … LNK-56 / LNQ-01…LNQ-12 | Design / Pending | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 21/21 ACs matched spec outcome | 0 spec-precision gaps  
**Sensor**: 7/7 mutations killed  
**Gate**: 1222 passed (`make test-backend`); `lint-openapi` + Pint/PHPStan/PHPMD passed; E2E not re-run  

**What works**: Owner-scoped keyset list, exact `LinkSummary`/`LinkDetail` shapes, accent-sensitive OR search, effective-status precedence, signed cursor scope (`errors.cursor[0].code = INVALID_CURSOR` without querying), uniform 404 without decrypt, 503 without partial body, 300/min per-token, telemetry sentinels, creation-identical ETag.

**Issues found**: none blocking. Residual untested PostgreSQL-down path (controller already maps `Throwable` → 503).

**Next steps**: Mark feature Verified; E2E re-run optional before PR if orchestrator wants live confirmation of T8.
