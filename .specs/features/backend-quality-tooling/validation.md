# Backend Quality Tooling Validation

**Date**: 2026-07-22
**Spec**: `.specs/features/backend-quality-tooling/spec.md`
**Diff range**: `9cb7f68^..72dccea` (15 commits; base before feature `abbf3c4`; HEAD `72dccea`)
**Branch**: `feature/package-defaults`
**Verifier**: independent sub-agent (author ≠ verifier)

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1 | ✅ Done | Packages in `composer.json` + lock |
| T2 | ✅ Done | Scripts `lint` / `analyse` / `md` / `quality` / `test:coverage` |
| T3 | ✅ Done | `pecl install pcov` in Dockerfile `dev` only |
| T4 | ✅ Done | `phpunit.xml` `<coverage>` text+html |
| T5 | ✅ Done | `phpstan.neon` level 6; SPEC_DEVIATION for PHPStan 2 params + Pest ignore |
| T6 | ✅ Done | `phpmd.xml`; SPEC_DEVIATION phpmd 3.x-dev |
| T7 | ✅ Done | Makefile static targets via compose |
| T8 | ✅ Done | `test-backend-coverage` |
| T9 | ✅ Done | `make lint` real pipeline (no placeholder) |
| T10 | ✅ Done | `.github/workflows/backend-quality.yml` |
| T11 | ✅ Done | `tests/compose/backend-quality-gates.sh` |
| T12 | ✅ Done | `ModularMonolithTest.php` + Architecture suite |
| T13 | ✅ Done | Arch in `make lint` + CI step |
| T14 | ✅ Done | AD-009 in `.specs/STATE.md`; README + testing.md |
| T15 | ✅ Done | `QualityToolingTest.php` |

**All T1–T15 marked done in tasks.md.**

---

## Spec-Anchored Acceptance Criteria

### P1: Composer packages (QTOOL-01..04)

| Criterion | Spec-defined outcome | `file:line` + assertion / evidence | Result |
| --------- | -------------------- | ---------------------------------- | ------ |
| QTOOL-01 WHEN composer install THEN install larastan, strict-rules, phpmd, pest-plugin-arch compatible PHP^8.3 / Laravel^13.8 | Packages present in require-dev with compatible constraints | `backend/composer.json:15-27` — `larastan/larastan ^3.10`, `phpstan/phpstan-strict-rules ^2.0`, `phpmd/phpmd 3.x-dev`, `pestphp/pest-plugin-arch ^4.0`; lock committed; container `composer show` resolves packages | ✅ PASS (phpmd via documented 3.x-dev SPEC_DEVIATION) |
| QTOOL-02 WHEN composer.json inspected THEN keep pint/pest/phpunit; no PHP-CS-Fixer/PHPCS/Insights | Excluded packages absent from require-dev | `backend/composer.json:13-28` — present: pint/pest/phpunit; `rg` on composer.json: no friendsofphp/phpcs/insights | ✅ PASS |
| QTOOL-03 WHEN composer.lock committed THEN reflects resolved graph | Lock updated with new packages | `git ls-tree 72dccea backend/composer.lock` present; lock contains `larastan/larastan` v3.10.0 and related packages | ✅ PASS |
| QTOOL-04 WHEN composer scripts run THEN lint/analyse/md via composer run | Scripts invoke pint --test, phpstan analyse, phpmd | `backend/composer.json:58-71` — `"lint": pint --test`, `"analyse": phpstan analyse`, `"md": phpmd analyze…`, `"quality": @lint→@analyse→@md` | ✅ PASS |

### P1: Config files (QTOOL-05..09)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-05 phpstan.neon level 6, paths app+tests, Larastan+strict includes, exclude vendor/cache/storage | Exact config shape | `backend/phpstan.neon:1-14` — `level: 6`, paths `app`/`tests`, includes larastan + strict-rules; excludePaths `bootstrap/cache/*`, `storage/*` (vendor excluded by not being in `paths`) | ✅ PASS |
| QTOOL-06 phpstan analyse exit 0 | Exit 0, no ignored warnings beyond documented | Gate: `composer run analyse` → `[OK] No errors`; meta-test `QualityToolingTest.php:28` — `expect($result->exitCode())->toBe(0)` | ✅ PASS |
| QTOOL-07 phpmd.xml analyzes app with CC≤12, size, CouplingBetweenObjects | Documented thresholds in file | `backend/phpmd.xml:13-17,23-45` — CC reportLevel 12; ExcessiveMethodLength 40; ExcessiveClassLength 300; CouplingBetweenObjects 13 | ✅ PASS |
| QTOOL-08 phpmd exit 0 | Exit 0 on baseline | Gate: `composer run md` exit 0; `QualityToolingTest.php:44` — `expect($result->exitCode())->toBe(0)` | ✅ PASS |
| QTOOL-09 pint --test exit 0 | Exit 0 on baseline | Gate: pint PASS 29 files; `QualityToolingTest.php:18` — `expect($result->exitCode())->toBe(0)` | ✅ PASS |

### P1: PCOV / coverage (QTOOL-10..14)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-10 PHP dev image includes pcov | pecl install + enable in `dev` stage | `docker/php/Dockerfile:42-49` — `FROM base AS dev` then `pecl install pcov` + `docker-php-ext-enable pcov` | ✅ PASS |
| QTOOL-11 php -m lists pcov | Module listed | Verifier ran: `docker compose … run … backend php -m \| grep pcov` → `pcov` | ✅ PASS |
| QTOOL-12 phpunit.xml source app + coverage html/text | PHPUnit 12 compatible reporters | `backend/phpunit.xml:18-28` — `<source><include><directory>app</directory>`, `<coverage><report><text…/><html outputDirectory="storage/coverage"/>` | ✅ PASS |
| QTOOL-13 Pest/coverage runs without missing-driver error | Coverage report produced | Gate: `make test-backend-coverage` → summary `Total: 33.3 %`; no "No code coverage driver available"; `composer.json:72-74` `test:coverage` → `artisan test --coverage` | ✅ PASS |
| QTOOL-14 prod/runtime must not depend on PCOV | PCOV only in dev | `docker/php/Dockerfile:51-53` — `FROM base AS prod` / `FROM prod AS runtime` with no pcov install | ✅ PASS |

### P1: Makefile (QTOOL-15..18)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-15 make lint runs Pint→PHPStan→PHPMD→Pest fail-fast via container | Order + compose | `Makefile:116-131` — `lint-backend` → `composer run quality` (pint→analyse→md); then `test-architecture`; then `test-backend`. All via `$(COMPOSE) run`. Gate `make lint` exit 0 | ✅ PASS (Arch inserted per QTOOL-22; static order preserved) |
| QTOOL-16 make help lists lint/backend targets | Targets listed | `make help` lists `lint`, `lint-backend`, `analyse-backend`, `md-backend`, `format-backend`, `test-backend-coverage`, `test-architecture` | ✅ PASS |
| QTOOL-17 lint/analyse targets use docker compose, never host binaries | COMPOSE run pattern | `Makefile:1,116-126` — all targets use `$(COMPOSE) run --rm --no-deps backend …` | ✅ PASS |
| QTOOL-18 make lint success → exit 0, never mask failure | Exit 0; fail-fast Make | Gate `make lint` exit 0; Make stops on first non-zero (no `-` ignore prefixes on quality steps) | ✅ PASS |

### P1: GitHub Actions (QTOOL-27..33)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-27 PR against main runs backend gates | Workflow on pull_request→main | `.github/workflows/backend-quality.yml:3-7` — `on.pull_request.branches: [main]` | ✅ PASS |
| QTOOL-28 Workflow uses Docker Compose; no host PHP/Composer setup | No setup-php | `.github/workflows/backend-quality.yml:21-40` — `docker compose … build/run` + `make *`; no `shivammathur/setup-php` / host composer | ✅ PASS |
| QTOOL-29 Static analysis order Pint(--test)→PHPStan→PHPMD fail-fast | Ordered named steps | `backend-quality.yml:27-34` — Pint (`make format-backend`→`composer run lint`→pint --test) → analyse → md | ✅ PASS |
| QTOOL-30 Pest with PCOV coverage text/artifact | Coverage step | `backend-quality.yml:39-40` — `make test-backend-coverage` | ✅ PASS |
| QTOOL-31 Gate failure fails job; identifiable logs | Separate steps per tool | Steps named Pint / PHPStan / PHPMD / Architecture / Pest coverage (`:27-40`) | ✅ PASS |
| QTOOL-32 push to main runs same workflow | push→main | `backend-quality.yml:6-7` | ✅ PASS |
| QTOOL-33 Local/CI parity | Same revision green locally ⇒ CI green | Local Full gate green; CI invokes same Make targets (`format-backend`/`analyse-backend`/`md-backend`/`test-architecture`/`test-backend-coverage`); compose smoke asserts workflow + real lint pipeline (`tests/compose/backend-quality-gates.sh:7-35`) | ✅ PASS |

### P2: Pest Architecture (QTOOL-19..22, QTOOL-34)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-19 Architecture tests use pest-plugin-arch | Plugin + arch() API | `composer.json:23` pest-plugin-arch; `ModularMonolithTest.php:31-36` — `arch(...)->expect(...)->not->toUse(...)` | ✅ PASS |
| QTOOL-20 Controller+Eloquent mutant fails arch test | Discrimination | Rule `ModularMonolithTest.php:31-36`; sensor M1: inject `MutantController` + `use App\Models\User` → FAIL `Expecting 'App\Http\Controllers' not to use 'App\Models'` (exit 1) | ✅ PASS |
| QTOOL-21 Cross-module Models usage fails | Rule per docs/testing.md §3.1 | `ModularMonolithTest.php:47-49` — `expect("App\\Modules\\{$module}\\Models")->toOnlyBeUsedIn("App\\Modules\\{$module}")` (vacuous until modules exist; namespace-based, not Finder) | ✅ PASS |
| QTOOL-22 make lint / test-architecture includes Arch suite exit 0 | Suite green in pipeline | `Makefile:69-77,128-131`; gate: Architecture 12 passed; `phpunit.xml:14-16` Architecture suite | ✅ PASS |
| QTOOL-34 GHA includes Architecture suite | CI step | `backend-quality.yml:36-37` — `make test-architecture` | ✅ PASS |

### P2: Documentation (QTOOL-23..25)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-23 AD for tooling stack with sequential ID | AD-009 recorded | `.specs/STATE.md:15` — AD-009 Pint/Larastan 6/PHPMD/Pest Arch/PCOV/Docker CI; exclusions noted | ✅ PASS |
| QTOOL-24 README documents make lint, coverage, CI Docker | Commands present | `README.md:89-95` — `make lint`, `make test-backend-coverage`, workflow link, Docker-only | ✅ PASS |
| QTOOL-25 docs/testing.md §4/§10 consistent | Pint, Larastan 6, PHPMD, coverage; no PHPCS | `docs/testing.md:77-86,281` — nível 6, PHPMD, Docker gates, explicitly excludes PHPCS/CS-Fixer/Insights | ✅ PASS |

### P3: Meta-test (QTOOL-26)

| Criterion | Spec-defined outcome | Evidence | Result |
| --------- | -------------------- | -------- | ------ |
| QTOOL-26 QualityToolingTest smokes pint/phpstan/phpmd exit 0 via Process | Assert exit code 0 for three commands | `QualityToolingTest.php:11-18`, `:21-28`, `:31-44` — `Process::…->run([…])` + `expect($result->exitCode())->toBe(0)` | ✅ PASS |

**Status**: ✅ All 34 ACs covered with evidence matching spec-defined outcomes

**SPEC_DEVIATION markers (documented, not AC gaps):**
- `backend/phpmd.xml:3-7` — phpmd/phpmd + pdepend 3.x-dev for Symfony 8 / Laravel 13
- `backend/phpstan.neon:14-20` — PHPStan 2 removed obsolete params; Pest `TestCall` ignore for Feature closures

---

## Discrimination Sensor

| Mutation | Scratch target | Description | Killed? |
| -------- | -------------- | ----------- | ------- |
| 1 | `app/Http/Controllers/MutantController.php` (scratch volume) | Inject Controller using `App\Models\User` | ✅ Killed — Arch FAIL exit 1 (`not to use 'App\Models'`) |
| 2 | Makefile `lint:` body (scratch root) | Restore `@echo "lint targets…"` placeholder | ✅ Killed — smoke: `FAIL: make lint is still a placeholder` exit 1 |
| 3 | `Controller.php` indentation (scratch volume) | Break Pint style (`statement_indentation`) | ✅ Killed — `QualityToolingTest` expect exit 0 fails (got 1) |

**Sensor depth**: lightweight (3 mutations)
**Result**: 3/3 killed — PASS ✅
**Scratch hygiene**: mutations only under `/tmp/…`; main tree Controllers/Makefile untouched; leftover worktree removed.

---

## Interactive UAT Results

N/A — backend/infrastructure tooling; automated gates sufficient.

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ — 16 files in feature diff; focused on tooling |
| No scope creep | ✅ — frontend/OpenAPI/full CI matrix out of scope honored |
| Matches patterns | ✅ — Makefile COMPOSE pattern, compose smoke scripts |
| Spec-anchored outcome check | ✅ |
| Per-layer Coverage Expectation met | ✅ — smoke + arch + meta-test per matrix |
| Every test maps to a spec requirement | ✅ — Arch→QTOOL-19..22/34; QualityTooling→QTOOL-26; compose→QTOOL-33 |
| Documented guidelines followed | ✅ — `docs/testing.md` §2/§3.1/§4/§10, `AGENTS.md` Docker-only |

**Nits (non-blocking):**
- `test-architecture` missing from `.PHONY` in Makefile (still works as file-less target).
- Edge case “vendor/ missing → clear composer-install hint” not specially handled (generic compose/binary missing error). Tool-default failure modes for Pint/PHPStan/PHPMD themselves are adequate.

---

## Edge Cases

- [x] PHPStan errors → exit ≠ 0 with paths (tool behavior; gate fail-fast)
- [x] PHPMD violations → exit ≠ 0 (tool behavior)
- [x] Pint unformatted → exit ≠ 0 (sensor M3)
- [x] PCOV missing → would error on coverage (pcov present in dev; verified)
- [x] Pest Arch suite mapped (phpunit Architecture suite; not silently empty)
- [x] CI Docker-only; no host PHP setup
- [~] vendor/ missing → clear message: **partial** — fails, but message is not a curated “run composer install” hint
- [x] Cache/corrupt image: workflow rebuilds image each run; no silent skip

---

## Gate Check

- **Gate command**: `make lint && make test-backend-coverage && bash tests/compose/backend-quality-gates.sh`
- **Result**: all three exit 0
  - `make lint`: Pint OK → PHPStan OK → PHPMD OK → Architecture 12 passed → Pest suite 20 passed
  - `make test-backend-coverage`: **20 passed**, 0 failed; coverage Total 33.3% (no min threshold — per spec)
  - compose smoke: `OK backend quality gates (workflow present, lint pipeline real, lint-backend exit 0)`
- **Test count before feature** (`abbf3c4`): 5 Pest cases (Health 2 + ShortHost 3)
- **Test count after feature** (`72dccea` full suite): 20 (Δ **+15**: QualityTooling 3 + Architecture 12)
- **Skipped tests**: none
- **Failures**: none

---

## Fix Plans

None — no FAIL gaps. Optional follow-ups (non-blocking):
1. Add `test-architecture` to `.PHONY`
2. Improve vendor-missing UX in Makefile (detect `backend/vendor` before compose run)

---

## Requirement Traceability Update

| Requirement | Previous Status | New Status |
| ----------- | --------------- | ---------- |
| QTOOL-01..34 | Pending / Implementing | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready

**Spec-anchored check**: 34/34 ACs matched spec outcome | 0 uncovered | 2 documented SPEC_DEVIATION markers (phpmd 3.x-dev; PHPStan 2 neon/Pest ignore)
**Sensor**: 3/3 mutations killed
**Gate**: make lint + coverage + compose smoke all passed (20 Pest tests in coverage run)

**What works**: Full local/CI-aligned backend quality stack — Pint, Larastan 6, PHPMD, Pest Arch, PCOV coverage, Makefile, GHA workflow, docs/AD-009, meta-tests; discrimination proven.

**Issues found**: None blocking.

**Next steps**: Orchestrator may mark feature verified / update STATE handoff; no fix loop required.
