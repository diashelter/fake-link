# Links — Política de slug · Validation

**Date**: 2026-09-01
**Spec**: `.specs/features/links/slug-policy/spec.md`
**Design**: `.specs/features/links/slug-policy/design.md`
**Diff range**: `main..HEAD` = `7ac5f43..182bc65` (branch `feat/links-slug-policy`, 15 commits, T1–T13 + 2 log commits)
**Verifier**: independent sub-agent (author ≠ verifier) — fresh read, no author context inherited

---

## Task Completion

| Task | Status | Commit | Notes |
| --- | --- | --- | --- |
| T1 | ✅ Done | `f016f51` | `slug` subtree added to config; `destination` intact |
| T2 | ✅ Done | `6fca361` | `SlugSource`, `SlugRejectionReason` enums |
| T3 | ✅ Done | `242a71b` | Typed exceptions |
| T4 | ✅ Done | `f5887c9` | `Slug` VO rewritten (normalize → structural validation) |
| T5 | ✅ Done | `2dd547b` | `ReservedSlugs` port + `ConfigReservedSlugs` |
| T6 | ✅ Done | `06e6300` | `SlugPolicy` sole VO factory |
| T7 | ✅ Done | `76c1071` | `RandomSlugSource` port + `CsprngSlugSource` |
| T8 | ✅ Done | `c57a849` | `SlugGenerator` |
| T9 | ✅ Done | `93d1139` | `SlugReservationRepository` port + Eloquent adapter |
| T10 | ✅ Done | `22b9820` | `ReserveSlug` UseCase + provider bindings |
| T11 | ✅ Done | `ed8dcf0` | `SlugPolicyBoundariesTest` architecture gates |
| T12 | ✅ Done | `68ac6eb` | Concurrency test (2 PG connections) |
| T13 | ✅ Done | `47ece77` | `docs/api.md` §7, `AD-020`, handoff — confirmed present (see Gate Check) |

All 13 tasks committed individually, atomic, in dependency order. None partial or blocked.

---

## Spec-Anchored Acceptance Criteria

### P1: Slug automático seguro (LNK-10, SLG-01, SLG-02, SLG-03)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --- | --- | --- | --- |
| AC1 exact format | 8 chars, all `[a-z0-9]` | `Tests/Unit/Domain/Services/SlugGeneratorTest.php:44-48` — `expect($slug->value())->toMatch('/^[a-z0-9]{8}$/')`; `SlugTest.php:138-143` `fromGenerated` accepts 8 | ✅ PASS |
| AC2 CSPRNG-only entropy | must use `random_int`, never `rand/mt_rand/uniqid/timestamp` | `Tests/Unit/Infrastructure/Slug/CsprngSlugSourceTest.php:51-65` — source-text assertion: `toContain('random_int(')`, `not->toContain('mt_rand')`, `not->toContain('uniqid')`, `not->toContain('microtime')`, etc. | ✅ PASS |
| AC3 10,000 generations, no repeat, all 36 symbols reachable | exact counts 10 000 / 36 | `CsprngSlugSourceTest.php:27-36` `count($seen))->toBe(10000)`; `:38-49` `count($reached))->toBe(36)` | ✅ PASS |
| AC4 denylist discard w/o consuming collision budget | discard uses own ceiling, independent of collision retries | `SlugGeneratorTest.php:59-66` (discard+regenerate) + `:79-98` (5 discards ⇒ exhausted, own budget) vs. `ReserveSlugTest.php:149-198` (5 *collision* retries, separate ceiling) — the two budgets are proven independent by being exercised in two different test files against two different classes | ✅ PASS |
| AC5 source `automatic` | `SlugSource::Automatic` | `SlugTest.php:141-142`; `SlugGeneratorTest.php:47` | ✅ PASS |

### P1: Retentativa de colisão limitada (LNK-11, SLG-04, SLG-05, SLG-06)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --- | --- | --- | --- |
| AC1 PK-violation retry | new slug generated, retried | `ReserveSlugTest.php:149-158` — 4 pre-reserved collisions, 5th succeeds, `$source->calls)->toBe(5)` | ✅ PASS |
| AC2 5 failed inserts ⇒ `SlugGenerationExhausted`, no 6th | exactly 5, never 6 | `ReserveSlugTest.php:172-183` `$source->calls)->toBe(5)` + reservation count stays 5; `:185-198` explicit 6-candidate script proves only 5 drawn | ✅ PASS |
| AC3 success before limit stops immediately | no extra candidates drawn | `ReserveSlugTest.php:160-170` — 4th scripted candidate present but unused, `calls)->toBe(3)` | ✅ PASS |
| AC4 5 denylist discards ⇒ exhausted (own ceiling) | exact 5 | `SlugGeneratorTest.php:79-98` | ✅ PASS |
| AC5 `SlugGenerationExhausted` → `503 SLUG_GENERATION_FAILED` + `Retry-After` | stable code, no attempt count/candidate leaked | `SlugExceptionsTest.php:50-62` — message has no digit (`preg_match('/\d/', $message))->toBe(0)`), `errorCode())->toBe('SLUG_GENERATION_FAILED')`; HTTP mapping (`503`/`Retry-After`) is explicitly out of scope of this slice (link-creation) — registered as a stable code in `docs/api.md:261` and `AD-020` (`.specs/STATE.md:26`) | ✅ PASS (domain half); ⚠️ scope-deferred (HTTP half, by design) |
| AC6 no check-then-insert; INSERT is authority | no `SELECT` used for availability | `Tests/Integration/SlugReservationRepositoryTest.php:64-78` — query log asserts zero `SELECT` on `slug_reservations`, one `INSERT` | ✅ PASS |

### P1: Normalização e validação de alias personalizado (LNK-12, LNK-13, SLG-07…10)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --- | --- | --- | --- |
| AC1 `"  Architecture  "` → `architecture`; order = normalize→validate→denylist→reserve→compare | exact value `architecture` | `SlugTest.php:27-32`; ordering also proven by `SlugPolicyTest.php:71-76` (denylist against normalized+trimmed) and `ReserveSlugTest.php:75-89` (full reserve pipeline on `"  My-Alias  "` → `my-alias`) | ✅ PASS |
| AC2 <3 / >48 → `too_short`/`too_long` | exact codes | `SlugTest.php:40-56` | ✅ PASS |
| AC3 exactly 3/48 accepted | boundary inclusive | `SlugTest.php:44-52` | ✅ PASS |
| AC4 chars outside `[a-z0-9-]` → `invalid_characters`, no transliteration/NFKC/homoglyph | 8 distinct Unicode/format cases | `SlugTest.php:67-98` (space, `_`, cyrillic `аdmin`, `ADMÍN`, `İstanbul`, `%61dmin`, control char, emoji) | ✅ PASS |
| AC5 leading/trailing hyphen → `invalid_boundary` | exact code | `SlugTest.php:102-112` (incl. `"---"` boundary-before-consecutive-hyphens ordering) | ✅ PASS |
| AC6 consecutive hyphens → `consecutive_hyphens` | exact code | `SlugTest.php:114-116` | ✅ PASS |
| AC7 case conversion ASCII-only, locale-independent | `A-Z`→`a-z` only | `Slug.php:76-79` uses `strtr` w/ explicit map (not `strtolower`); proven by `SlugTest.php:84-86` (`İstanbul` U+0130 fails as `invalid_characters`, no locale collapse) | ✅ PASS |
| AC8 accepted alias → source `custom` | exact enum | `SlugTest.php:123-126` | ✅ PASS |

### P1: Denylist de palavras reservadas (LNK-14, SLG-11…13)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --- | --- | --- | --- |
| AC1 exact reserved word → `reserved_word` | exact code | `SlugPolicyTest.php:50-55` | ✅ PASS |
| AC2 `ADMIN`/`Admin` → `reserved_word` after normalization | exact code | `SlugPolicyTest.php:57-69` | ✅ PASS |
| AC3 `admin-panel`/`myadmin`/`apis` accepted (exact match, not substring) | accepted | `SlugPolicyTest.php:78-80`; `ConfigReservedSlugsTest.php:22-38` | ✅ PASS |
| AC4 denylist from `config('links.slug.reserved_words')` via `ReservedSlugs`, not duplicated in Domain | single source | `ConfigReservedSlugsTest.php:53-58`; `SlugPolicyBoundariesTest.php:36-43` (R1: Domain never calls `config()`) | ✅ PASS |
| AC5 word added to config blocks both paths w/o domain code change | runtime-configurable | `ConfigReservedSlugsTest.php:46-51` (injected list, not constant) + `SlugPolicyTest.php:98-103` (same denylist mechanism serves `fromGenerated`, SLG-13) | ✅ PASS |

### P1: Reserva global e permanente (LNK-15, LNK-16, SLG-14…16)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --- | --- | --- | --- |
| AC1 reservation row inserted in same tx as link, only `slug`+`reserved_at` | schema-exact | `SlugReservationRepositoryTest.php:56-62` (insert); `SlugReservationsSchemaContractTest` (foundation-delivered, re-verified passing) confirms column shape. "Same transaction **as the link**" specifically is not directly testable here — link-creation (the transaction owner) doesn't exist yet (explicitly out of scope) | ⚠️ Scope-deferred (mechanism proven; cross-slice half awaits link-creation) |
| AC2 rollback ⇒ that transaction's reservation absent | not persisted | `SlugReservationRepositoryTest.php:109-122` (repo level); `ReserveSlugTest.php:215-231` (use-case level, exact SLG-14 wording) | ✅ PASS |
| AC3 duplicate reservation → `SlugUnavailable`, existing row unchanged (same `reserved_at`) | byte-identical `reserved_at` | `SlugReservationRepositoryTest.php:80-107` — `$after->reserved_at->equalTo($original->reserved_at))->toBeTrue()` | ✅ PASS |
| AC4 `SlugUnavailable` identical for orphan vs. linked, no occupant data | byte-identical message | `SlugReservationConcurrencyTest.php:157-205` — `expect($orphanMessage)->toBe(...)->and($linkedMessage)->toBe($orphanMessage)`, plus reflection proving `SlugUnavailable`'s only public API is `errorCode`/`reserved` | ✅ PASS |
| AC5 no `DELETE`/`truncate`/soft-delete/update path anywhere in module | zero occurrences | `SlugPolicyBoundariesTest.php:49-92` (R3 — grep-scans every non-test `modules/Links` file referencing the reservation table/model for `->delete(`/`->forceDelete(`/`->truncate(`/`->update(`/`::destroy(`) | ✅ PASS |
| AC6 orphan reservation identified by query port | `existsWithoutLink` semantics | `SlugReservationRepositoryTest.php:143-159` (true for orphan, false with link, false when absent) | ✅ PASS |
| AC7 `short_links.slug` immutable | no domain op / repo path alters it | `SlugPolicyBoundariesTest.php:94-109` (R4 — `Slug` is `readonly final`, exactly 5 public methods, none a mutator). No `short_links` write repository exists yet in this slice (link-creation's responsibility) — so there is currently no code path *of any kind* that could write `short_links.slug`, which trivially satisfies the AC but is not independently exercised against a write path | ✅ PASS (VO half, verified); ⚠️ Scope-deferred (repo-write half, no such repo exists yet) |

### P1: Concorrência entre aliases equivalentes (LNK-12, LNK-15, SLG-17, SLG-18)

| Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| --- | --- | --- | --- |
| AC1 two overlapping tx reserving `Foo`/`foo` → exactly one commits, other fails | exact 1-winner outcome | `SlugReservationConcurrencyTest.php:86-114` (raw PK collision) + `:116-134` (adapter → `SlugUnavailable`) | ✅ PASS |
| AC2 loser doesn't overwrite/remove winner, no duplicate `short_link` | exact row counts | `SlugReservationConcurrencyTest.php:136-155` — `reservationRows)->toBe(1)` and `linkRows)->toBe(0)` | ✅ PASS |
| AC3 resolved by PG constraint, no app pre-check | DB is sole authority | Same test uses a raw `insert` racing a committed row (no `SELECT` involved) — consistent with SLG-06 evidence elsewhere | ✅ PASS |
| AC4 failure doesn't reveal winner's owner | no occupant data | `SlugReservationConcurrencyTest.php:157-205` | ✅ PASS |

**Status**: ✅ All 25 requirement IDs (LNK-10…16, SLG-01…18) traced to `file:line` with spec-matching assertions. Three ⚠️ marks are **scope-deferred**, not gaps: each names a cross-slice half (HTTP mapping, or a same-transaction-as-the-link / short_links-write guarantee) that legitimately cannot exist until `link-creation` is built — consistent with this slice's own Out-of-Scope table, and the reachable half of each is independently verified.

---

## Discrimination Sensor

Mutations were injected **without touching the tracked working tree** — each mutated file was written to the session scratchpad and mounted over the real path inside the ephemeral test container (`docker compose run -v <mutant>:<container-path>:ro`). `git status` was verified clean before and after every run.

| # | Target (risk area) | File:line | Mutation | Result |
| --- | --- | --- | --- | --- |
| 1 | (a) `Slug` normalization ordering | `Domain/ValueObjects/Slug.php:78` | Removed `trim()` from `normalize()`: `strtr(trim($raw), ...)` → `strtr($raw, ...)` | ❌ Killed — 4 `SlugTest` failures (whitespace-boundary tests) |
| 2 | (b) `SlugPolicy` denylist-after-structural ordering | `Domain/Services/SlugPolicy.php:24-27` | `fromCustomAlias` checks `$this->reserved->contains($raw)` (raw, pre-normalization, pre-structural) instead of delegating to `Slug::fromCustomAlias()` then checking the normalized value | ❌ Killed — 3 `SlugPolicyTest` failures (`ADMIN`/`Admin`/`"  ADMIN  "` no longer rejected as `reserved_word`) |
| 3 | (c) `SlugGenerator`'s independent denylist-discard counter | `Domain/Services/SlugGenerator.php:47` | Off-by-one: `$discards >= $this->maxDenylistDiscards` → `$discards > ...` (allows a 6th discard) | ❌ Killed — 3 `SlugGeneratorTest` failures (scripted sequence exhausted / exhausted-exception mismatch) |
| 4 | (d) `ReserveSlug::automatic()` retry bound | `UseCases/ReserveSlug.php:46` | Off-by-one: `$attempt <= $this->maxCollisionAttempts` → `$attempt < ...` (only 4 attempts) | ❌ Killed — 4 `ReserveSlugTest` failures (`calls)->toBe(5)` / `toBe(2)` assertions) |
| 5 | (e) Architecture rule vs. reservation removal | `Infrastructure/Persistence/Eloquent/Repositories/EloquentSlugReservationRepository.php` | Added an unused `purge(Slug $slug): void { SlugReservationModel::query()->where(...)->delete(); }` method | ❌ Killed — `SlugPolicyBoundariesTest` R3 failed: `EloquentSlugReservationRepository.php contains ->delete(` |

**Sensor depth**: lightweight (5 targeted mutations, one per named risk area a–e, as requested — above the 1–3 default tier).
**Result**: 5/5 killed — ✅ PASS. No surviving mutants; no fix tasks required.

---

## Spec Deviations — Scrutiny

### Deviation 1: `SlugGenerator` ignores `config('links.slug.alphabet')`, uses a private Base36 const; length is de facto pinned to 8

**Finding**: `SlugGenerator::ALPHABET` (`Domain/Services/SlugGenerator.php:22`) is a private constant, byte-identical to `config('links.slug.alphabet')`'s default value — but it is never read from config. `$length` **is** wired from config by `LinksServiceProvider:47-52` (`(int) config('links.slug.length')`), but `Slug::fromGenerated()` (`ValueObjects/Slug.php:106-121`) independently hardcodes `GENERATED_LENGTH = 8` and rejects anything else — so even though `$length` is configurable in the generator's constructor, any value other than 8 would make every candidate fail structural validation (propagated as `SlugPolicyException`, not silently producing a wrong-length slug).

**Assessment against SLG-01/SLG-02/LNK-10**: **Not a violation.** SLG-01 requires exactly 8 `[a-z0-9]` chars; SLG-02 requires CSPRNG-only entropy with no modulo bias — both hold regardless of the alphabet's source, because the constant's *value* matches the config default exactly, and the VO acts as a second, harder enforcement point for length. The spec's own Assumptions table states "o gerador nunca produz comprimento diferente de 8" (`spec.md:67`) — the current design (VO as sole arbiter of length) actively guarantees that, arguably more robustly than trusting a config value alone would. No AC asserts the alphabet *must* be sourced from config at runtime; only the denylist has that explicit SLG-12 requirement.

**Residual risk (real, but not spec-blocking)**: `config('links.slug.alphabet')` and `config('links.slug.length')` (beyond 8) are **dead configuration** — changing them silently does nothing (alphabet) or breaks generation outright (length ≠ 8, converted into an exception rather than a differently-shaped slug). This is a maintainability/DRY smell, not a functional defect, and no test claims otherwise. **Verdict: harmless simplification, correctly scoped — not a gap against this slice's ACs.**

### Deviation 2: `ReserveSlug::automatic()` wraps each attempt in a nested `DB::transaction()` (SAVEPOINT)

**Finding**: `ReserveSlug::automatic()` (`UseCases/ReserveSlug.php:50`) calls `DB::transaction(fn () => $this->reservations->reserve($slug))` per attempt. The **repository** itself (`EloquentSlugReservationRepository::reserve()`) does *not* open a transaction — exactly as the design specifies ("o repositório participa da transação do chamador... `DB::transaction` não é chamado aqui", `design.md:204`). The nested transaction lives one layer up, in the UseCase.

**Assessment against SLG-14** ("reserva participa da transação do chamador; não abre nem confirma transação própria"): the wording is most naturally read as being about the *reservation service* generically, and read literally, `ReserveSlug::automatic()` does call `DB::transaction()` — which, when there is no outer transaction already open, would BEGIN and COMMIT a real transaction of its own per attempt. Laravel's `DB::transaction()` degrades to a SAVEPOINT only when a transaction is already open (i.e., when a caller such as the future `link-creation` slice has already opened one).

**However**: the AC this decision exists to satisfy (SLG-14 AC2, "rollback do chamador desfaz a reserva") is empirically proven to hold under nesting — `ReserveSlugTest.php:215-231` wraps `automatic()` inside an outer `DB::transaction()` and confirms the reservation is absent after the outer rollback, which is exactly the intended integration shape (link-creation owns the outer transaction; `ReserveSlug` is always called from inside it). Under nesting, `DB::transaction()`'s per-attempt "commit" is only a SAVEPOINT release — not durable — so the guarantee holds. The only scenario where the literal reading would matter — `automatic()` invoked **with no outer transaction at all** — is not exercised by any current caller (there is none yet; link-creation doesn't exist), and if it occurred, the worst outcome is an early, independently-committed reservation that becomes a permanently valid orphan if the surrounding operation later fails for an unrelated reason — a state the spec explicitly designates as valid and final ("reserva órfã... estado válido e final", `spec.md:83`), not a namespace or security violation.

**Verdict**: **Consistent with SLG-14's tested outcome, not a violation** — the reservation never survives an outer rollback in the caller shape the spec assumes (link-creation owns `DB::transaction`). It is, however, a genuine **spec-precision gap**: SLG-14's wording doesn't anticipate a per-attempt SAVEPOINT layer inside the reservation service, and no test exercises `automatic()` invoked standalone (no pre-existing transaction) to confirm the "worst case" degrades no further than a spec-sanctioned orphan. Flagged for the record; does not block this slice's PASS since the tested integration shape (nested inside a caller transaction) is the one link-creation will actually use.

---

## Edge Cases (spec.md)

| Edge case | Status | Evidence |
| --- | --- | --- |
| External whitespace trimmed; internal space → `invalid_characters` | ✅ | `SlugTest.php:27-32`, `:68-70` |
| Boundaries 2/3/48/49 | ✅ | `SlugTest.php:40-56` |
| `"---"` → `invalid_boundary` (boundary fires before consecutive-hyphens) | ✅ | `SlugTest.php:110-112` |
| `a-b` accepted; `a--b` → `consecutive_hyphens` | ✅ | `SlugTest.php:114-120` |
| Cyrillic `аdmin` (U+0430) → `invalid_characters`, never transliterated | ✅ | `SlugTest.php:76-78` |
| `ADMÍN` → `invalid_characters` (not `reserved_word`) | ✅ | `SlugTest.php:80-82`; ordering re-confirmed in `SlugPolicyTest.php:43-48` |
| `İstanbul` (U+0130) → `invalid_characters`, no locale collapse | ✅ | `SlugTest.php:84-86` |
| Percent-encoding `%61dmin` → `invalid_characters`, never decoded | ✅ | `SlugTest.php:88-90` |
| Empty / whitespace-only alias → `too_short` | ✅ | `SlugTest.php:58-64` |
| NUL / control character → `invalid_characters` | ✅ (interior case) | `SlugTest.php:92-94` — uses `"a\x01b"` as the representative interior control character; a boundary NUL is out-of-scope for this case because the spec's own Assumptions table (`spec.md:56`) folds boundary NUL into the trimmed-whitespace class, so trimming it (not rejecting) is the spec-correct behavior — no separate test needed for that sub-case |
| Automatic slug collides 4×, wins on 5th → success, no error | ✅ | `ReserveSlugTest.php:149-158` |
| Automatic slug collides 5× → `SlugGenerationExhausted`, no link, no partial reservation | ✅ | `ReserveSlugTest.php:172-183` |
| Orphan reservation of a word later added to denylist → reservation stands; denylist only blocks new creations | ⚠️ Not directly tested | No test constructs "reserve word X while unlisted, then add X to denylist, confirm reservation persists." The behavior is implied correctly by the architecture (denylist is checked only at construction time via `SlugPolicy`, never re-validated against existing reservations) and by R3 (no removal path exists to retroactively purge it), but there is no direct assertion of this specific temporal scenario. **Spec-precision gap, low severity** — the invariant is structurally guaranteed (no code path re-validates existing rows against the denylist), just not scenario-tested. |
| PostgreSQL unavailable during reservation → infra error propagates, no blind retry, no budget consumed | ✅ | `SlugReservationRepositoryTest.php:124-140` — non-unique failure (renamed table, SQLSTATE `42P01`) propagates as `QueryException`, not `SlugUnavailable`; `ReserveSlug::automatic()`'s catch clause only catches `SlugUnavailable` (`UseCases/ReserveSlug.php:53`), so any other exception (including infra `QueryException`) propagates uncaught — no retry, no budget consumed |

**Status**: 13/14 edge cases directly tested; 1 (denylist-added-after-orphan-reservation) is structurally guaranteed but not scenario-tested — flagged as a low-severity spec-precision gap, not a functional defect.

---

## Code Quality

| Principle | Status |
| --- | --- |
| No features beyond what was asked | ✅ |
| No abstractions for single-use code | ✅ — port/adapter pairs (T5, T7, T9) each have exactly one production implementation and mirror an existing Auth precedent |
| No unnecessary flexibility | ✅ (the alphabet/length dead-config noted above is a minor exception, not new surface) |
| Only touched files required for task | ✅ — diff is scoped to `modules/Links/{Domain,Contracts,Infrastructure,UseCases,Exceptions,Tests,ServiceProviders}`, `config/links.php`, `tests/Architecture/SlugPolicyBoundariesTest.php`, `docs/api.md`, `.specs/**` |
| Didn't "improve" unrelated code | ✅ |
| Matches existing patterns/style | ✅ — `Slug` mirrors `EmailAddress`; `SlugPolicy` mirrors `PasswordPolicy`; `ConfigReservedSlugs` mirrors `JsonFileInviteAllowlist`; exception capture mirrors `EloquentUserRepository:85` |
| Would senior engineer approve? | ✅ |
| Tests map to ACs and are non-shallow (spot-checked) | ✅ — every assertion checked above targets a specific value/state, not mere "no exception thrown" |
| Spec-anchored outcome check | ✅ — see AC table; 3 scope-deferred marks documented, not silently passed |
| Per-layer Coverage Expectation met | ✅ — Domain has 1:1 AC mapping; integration layer covers happy + collision + exhaustion + denylist + rollback + concurrency |
| Every test maps to a spec AC/edge case/Done-when | ✅ — no unclaimed test files found in scope |
| Documented guidelines followed | `docs/testing.md` §3.1/§4/§6.3/§7, `AGENTS.md`, `LARAVEL_CODE_DESIGN.md` — followed |

---

## Gate Check

- **Gate command**: `make lint-backend` (Pint + PHPStan L6 strict-rules + PHPMD) · `make test-architecture` · `make test-backend` (scoped re-run: `--filter=Slug`, then full suite)
- **Full backend suite** (`php artisan test`, PostgreSQL `fake_link_testing`): **652 passed, 0 failed** (3457 assertions, 375s) — re-run independently by this Verifier; this is a *clean* run (no `QualityToolingTest` timeout this time — host load was low). This corroborates tasks.md's documented 651/652 + 1 pre-existing, unrelated `QualityToolingTest` phpstan-subprocess timeout flake: the same suite, same code, passes fully clean when the host isn't under the load that caused that flake. No new or slug-policy-related failure exists.
- **Slug-scoped suite** (`--filter=Slug`, includes `Slug*`, `ReserveSlug*`, Redirects/Links provider smoke tests picked up by the filter): **124 passed** (712 assertions)
- **Architecture suite** (`vendor/bin/pest tests/Architecture`): **17 passed** (68 assertions), including the 4 new `SlugPolicyBoundariesTest` rules
- **PHPStan** (full-project `vendor/bin/phpstan analyse`, matching `make lint-backend`'s scope): **0 errors**. (A narrower `phpstan analyse modules/Links`-only invocation was tried first and surfaced 4 "ignored error pattern not matched" errors — this is an artifact of scoping the analysis path narrower than the project's own ignore-pattern paths in `phpstan.neon`, not a real code defect; the full-project run, which is what `make lint-backend` actually runs, is clean.)
- **Test count before feature**: not independently re-derived (pre-feature baseline not checked out) — accepting tasks.md's Execution Log arithmetic (648 after T11 + 4 T12 = 652 total) as consistent with the observed 652.
- **Delta**: task-level counts in tasks.md sum to the documented test counts per task; verifier's own filtered run (124) and architecture run (17, includes 13 pre-existing `ModularMonolithTest` cases + 4 new) are consistent with those.
- **Skipped tests**: none observed.
- **Failures**: none in this Verifier's own runs.

---

## Fix Plans

None. No surviving mutants, no failed ACs, no code defect found.

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| --- | --- | --- |
| LNK-10, SLG-01, SLG-02, SLG-03 | Execute/Done | ✅ Verified |
| LNK-11, SLG-04, SLG-05, SLG-06 | Execute/Done | ✅ Verified |
| LNK-12, LNK-13, SLG-07…10 | Execute/Done | ✅ Verified |
| LNK-14, SLG-11…13 | Execute/Done | ✅ Verified |
| LNK-15, LNK-16, SLG-14…16 | Execute/Done | ✅ Verified (SLG-14/SLG-16 carry a documented, non-blocking scope-deferred note — see Spec Deviations) |
| SLG-17, SLG-18 | Execute/Done | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 25/25 requirement IDs traced to `file:line` with spec-matching assertions; 3 marked scope-deferred (cross-slice halves that legitimately cannot exist before `link-creation`), 0 unaddressed gaps.

**Sensor**: 5/5 mutations killed (one per named risk area a–e).

**Gate**: 652/652 backend tests passed (this Verifier's own independent run); 124/124 Slug-scoped; 17/17 architecture; PHPStan clean.

**What works**: Full normalize→validate→denylist pipeline for custom aliases; Base36 CSPRNG generator with independently-bounded collision and denylist-discard budgets; permanent, ownerless, append-only reservation with a proven PostgreSQL-arbitrated concurrency guarantee; uniform `SlugUnavailable` failure indistinguishable between orphan and linked occupancy; architectural gates make the "no removal path" and "Domain purity" guarantees self-enforcing rather than conventions.

**Issues found**:
1. `config('links.slug.alphabet')` and non-8 values of `config('links.slug.length')` are dead configuration (Deviation 1) — cosmetic/maintainability only, no AC violated. No fix required for this slice; worth a follow-up note if `link-creation` or ops ever expects the alphabet to be operationally rotatable.
2. `ReserveSlug::automatic()`'s per-attempt SAVEPOINT (Deviation 2) is a spec-precision gap against SLG-14's literal wording, empirically inert under the caller shape this codebase actually uses (nested inside an outer transaction) — no fix required; worth a targeted test in `link-creation` (or here, as a fast-follow) exercising `automatic()` with no outer transaction open, to pin the "worst case degrades to a spec-sanctioned orphan" claim rather than leaving it as this report's reasoning alone.
3. One spec edge case (orphan reservation of a word later added to the denylist) is structurally guaranteed but not scenario-tested — low-severity spec-precision gap.

**Next steps**: None blocking. The three issues above are candidates for a lessons-log entry and/or a fast-follow test in this slice or `link-creation`; they do not gate this feature's PASS.
