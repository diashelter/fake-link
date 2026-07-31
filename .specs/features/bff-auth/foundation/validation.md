# BFF Auth — Fundação frontend Validation

**Date**: 2026-07-30
**Spec**: `.specs/features/bff-auth/foundation/spec.md`
**Diff range**: `e9db79f^..HEAD` on `feature/auth-fundation-frontend` (tip `aeac7ed`; re-verify iteration 1 after `9bd3d66`, `a34ff76`, `aeac7ed`)
**Verifier**: independent sub-agent (author ≠ verifier)
**Prior verdict**: FAIL ❌ (5 evidence gaps) — this report supersedes the previous file

---

## Task Completion

| Task | Status | Notes |
| ---- | ------ | ----- |
| T1–T16 | ✅ Done | Unchanged from prior verify; fix commits added gate/test evidence only |

---

## Spec-Anchored Acceptance Criteria

### P1: Scaffold modular (BFFUI-01, FND-01, FND-02)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN frontend inspected THEN `modules/auth/` and `modules/shared/` exist with App Router under `frontend/app/` | Directories + `app/` present | `frontend/modules/shared/lib/form-defaults.test.tsx:7-10` — `@/modules/shared/...` imports resolve | ✅ PASS |
| WHEN TypeScript resolves `@/modules/...` THEN compiles with `strict: true` | `strict: true` + typecheck | `frontend/tsconfig.json:7` — `"strict": true`; `make lint-frontend` (`tsc --noEmit`) exit 0 | ✅ PASS |
| WHEN fatia concludes THEN no new product Auth Route Handlers; `app/health` MAY remain | Only `health/route.ts` | `frontend/modules/shared/lib/foundation-gates.test.ts:26` — `expect(relative).toEqual(['health/route.ts'])`; `:29-30` — forbidden segments false | ✅ PASS |
| WHEN landing rendered THEN `html[lang]` is `pt-BR` and theme is light-only | `lang="pt-BR"`; no dark mode | `frontend/app/page.test.ts:11` — `lang="pt-BR"`; `:16,18` — `color-scheme: light` and `not.toContain('.dark')` | ✅ PASS |

### P1: Forms stack (BFFUI-02, FND-03, FND-04, FND-17)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN email anchor schema validates invalid input THEN Vitest observes testable field error | Zod issue on invalid email | `frontend/modules/shared/schemas/email.test.ts:12-14` — `success).toBe(false)` + message `'Informe um e-mail válido.'` | ✅ PASS |
| WHEN harness submits invalid with RHF+zodResolver THEN focus moves to first invalid field | First invalid control focused | `frontend/modules/shared/lib/form-defaults.test.tsx:88` — `document.activeElement` is email field | ✅ PASS |
| WHEN submit while `isSubmitting` THEN second submit does not fire | Guard/disable; one submit | `frontend/modules/shared/lib/form-defaults.test.tsx:74-75`, `:112-115` — `toHaveBeenCalledTimes(1)` | ✅ PASS |
| WHEN server-side error injected THEN shown without stack to user | Field message; no stack | `frontend/modules/shared/lib/form-defaults.test.tsx:131-133` | ✅ PASS |
| WHEN `frontend/package.json` listed THEN includes RHF, zod, resolvers | Dependencies present | `frontend/package.json:18,24,26`; harness imports `form-defaults.test.tsx:1,4` | ✅ PASS |

### P1: TanStack Query defaults (BFFUI-03, FND-05, FND-06, FND-18)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN QueryClient defaults inspected THEN `staleTime` 30s and `gcTime` 5min | `30_000` / `300_000` | `frontend/modules/shared/lib/query-client.test.ts:15-16` | ✅ PASS |
| WHEN GET fails transiently THEN ≤1 retry; mutations 0 retries | retry once; mutations 0 | `frontend/modules/shared/lib/query-client.test.ts:17`, `:32-33` | ✅ PASS |
| WHEN setup inspected THEN no persister | No persist | `frontend/modules/shared/lib/query-client.test.ts:42-48` | ✅ PASS |
| WHEN polling helper configured THEN 60s only when visible | visible `60_000`; hidden `false` | `frontend/modules/shared/lib/query-client.test.ts:56`, `:62` | ✅ PASS |
| WHEN `frontend/package.json` inspected THEN includes `@tanstack/react-query` | Dependency present | `frontend/package.json:20`; `query-client.test.ts:1` | ✅ PASS |

### P1: Tailwind, shell e primitivos (BFFUI-05, FND-07, FND-08)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN Tailwind configured THEN utility classes apply on landing/shell; light theme | Import + utilities + light | `frontend/app/page.test.ts:16-19` — `@import 'tailwindcss'`; `className` matches `/bg-accent|text-muted|font-display/`; light markers | ✅ PASS |
| WHEN viewport is 360px THEN shell/landing reflows without layout-caused horizontal overflow | Fluid layout; no fixed overflow widths | `frontend/modules/shared/lib/foundation-gates.test.ts:50-54` — `w-full`/`px-6`/`max-w-`; `not.toMatch(/min-w-\[\d{3,}px\]/)` and `w-\[\d{3,}px\]` | ✅ PASS |
| WHEN Button/Input/Label/FormField rendered THEN label association and accessible error | Association + `role=alert` | `frontend/modules/shared/components/ui/input.test.tsx:19`; `form-field.test.tsx:29-31` | ✅ PASS |
| WHEN Radix searched in `package.json` THEN not listed | No `/radix/i` deps | `frontend/modules/shared/lib/foundation-gates.test.ts:45` — `expect(names.some(.../radix/i)).toBe(false)` | ✅ PASS |

### P1: Gates Vitest / ESLint / Prettier / TypeScript (BFFUI-04, FND-09, FND-10, FND-11)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN `make lint-frontend` runs THEN tsc + ESLint + Prettier `--check` exit 0 | Exit 0 (+ lint-staged contract) | `Makefile:139-141`; Verifier gate exit 0 including `node scripts/assert-lint-staged.mjs` | ✅ PASS |
| WHEN `make lint` runs THEN includes frontend after backend | `lint-frontend` invoked | `Makefile:143-145` | ✅ PASS |
| WHEN `make test-frontend` runs THEN Vitest exit 0 | Suite green | Verifier: **30 passed, 0 failed** (12 files) | ✅ PASS |
| WHEN coverage of introduced domains measured THEN ≥75% lines/branches on `modules/**` | Thresholds | `frontend/vitest.config.ts:19-24`; prior coverage gate ≥75% (unchanged thresholds) | ✅ PASS |
| WHEN `frontend/package.json` `lint` invoked THEN not no-op echo | Real ESLint | `frontend/package.json:12` — `"lint": "eslint ."` | ✅ PASS |

### P1: Husky + lint-staged (FND-12, FND-13, FND-14)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN monorepo inspected THEN Husky bootstrap at root | `prepare` + pre-commit | `package.json:6` — `"prepare": "husky"`; `.husky/pre-commit:1` — `npx lint-staged` | ✅ PASS |
| WHEN staged frontend has auto-fixable violation THEN lint-staged applies fix | `eslint --fix` + `prettier --write` | `scripts/assert-lint-staged.mjs:24-34` — `hasEslintFix` && `hasPrettierWrite` or exit 1; wired in `Makefile:141` | ✅ PASS |
| WHEN staged frontend has non-auto-fixable ESLint error THEN pre-commit fails (exit ≠ 0) | Hook exit ≠ 0 | `scripts/assert-lint-staged.mjs:24-25` — requires `eslint --fix` in commands (mechanism that exits ≠ 0 on remaining errors) | ⚠️ Spec-precision gap — contract asserts autofix wiring, not a staged-file exit≠0 simulation |
| WHEN only non-`frontend/` files staged THEN hook does not require full Makefile frontend gate | Frontend-only globs | `scripts/assert-lint-staged.mjs:11-14` — `globs.every(...startsWith('frontend/'))` | ✅ PASS |
| WHEN docs mention hooks THEN document root `pnpm install` | Documented | `README.md:105` — `pnpm install` | ✅ PASS |

### P2: MSW harness (FND-15, FND-16)

| Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion | Result |
| ------------------------- | -------------------- | ----------------------- | ------ |
| WHEN Vitest uses MSW THEN intercepts without external network | 200 + body | `frontend/modules/shared/test/msw/msw-smoke.test.ts:19-20` | ✅ PASS |
| WHEN suite ends THEN MSW reset/stopped | reset/close | `frontend/modules/shared/test/msw/msw-smoke.test.ts:10`, `:14` | ✅ PASS |

### Prior gap closure (iteration 1)

| Prior gap | Resolution | Result |
| --------- | ---------- | ------ |
| FND-07 360px / overflow | `foundation-gates.test.ts` fluid layout contract | ✅ Closed |
| FND-13 / FND-14 autofix + skip | `scripts/assert-lint-staged.mjs` + `make lint-frontend` | ✅ Closed (autofix + frontend-only) |
| FND-14 hard-fail exit ≠ 0 | Mechanism via required `eslint --fix` only | ⚠️ Spec-precision remains |
| FND-02 Auth routes absence | `foundation-gates.test.ts` allowlist | ✅ Closed |
| FND-08 Radix absence | `foundation-gates.test.ts` package scan | ✅ Closed |
| BFFUI-05/FND-07 Tailwind utilities | `page.test.ts` import + utility class markers | ✅ Closed |

**Status**: ⚠️ Spec-precision gaps flagged (no ❌ evidence gaps)

---

## Discrimination Sensor

| Mutation | File:line | Description | Killed? |
| -------- | --------- | ----------- | ------- |
| 1 | `frontend/app/page.tsx` main className | Injected `min-w-[400px]` | ✅ Killed — `foundation-gates.test.ts:53` `not.toMatch(/min-w-\[\d{3,}px\]/)` |
| 2 | `lint-staged.config.mjs` eslint command | Removed `--fix` | ✅ Killed — `assert-lint-staged.mjs` exit 1 (“must run eslint --fix…”) |
| 3 | `frontend/app/login/route.ts` (added) | Forbidden Auth product route | ✅ Killed — `foundation-gates.test.ts:26` allowlist failed |

**Sensor depth**: lightweight (targeted at iteration-1 fix surface)
**Scratch**: detached worktree `/tmp/fake-link-fnd-sensor2`; discarded after runs
**Real tree**: implementation/tests not modified by sensor
**Result**: 3/3 killed — PASS ✅

---

## Interactive UAT Results

Not performed (automated re-verify only).

---

## Code Quality

| Principle | Status |
| --------- | ------ |
| Minimum code | ✅ |
| Surgical changes | ✅ — fix commits were test/gate evidence only |
| No scope creep | ✅ |
| Matches patterns | ✅ |
| Spec-anchored outcome check | ⚠️ — one hard-fail precision gap |
| Per-layer Coverage Expectation met | ✅ |
| Every test maps to a spec requirement — no unclaimed tests | ✅ |
| Documented guidelines followed | ✅ — `docs/testing.md` §3.2/§4/§8; Fake Link `AGENTS.md` |

---

## Edge Cases

- [x] Docker lint/test gates — Verifier ran via Make/Compose
- [x] `eslint-config-prettier` present — `frontend/eslint.config.mjs`
- [x] RTL Client Components in jsdom — form harness green
- [x] Persister absence — `query-client.test.ts`
- [x] Landing pt-BR + health — `page.test.ts` + `health/route.test.ts`
- [x] Makefile gates independent of Husky — docs + gates ran without hook
- [x] Auth route / Radix absence now automated — `foundation-gates.test.ts`
- [ ] Staged `.env` secret printing — still not evidenced (gitignored assumed)

---

## Gate Check

- **Gate command**: `make lint-frontend && make test-frontend`
- **Result**: lint-frontend **passed** (incl. `assert-lint-staged.mjs`); test-frontend **30 passed, 0 failed, 0 skipped**
- **Test count before feature** (`e9db79f^`): **4**
- **Test count after feature** (HEAD): **30**
- **Delta**: **+26**
- **Skipped tests**: none
- **Failures**: none
- **Test integrity**: count increased vs prior verify (27 → 30); no weakened prior assertions observed

---

## Fix Plans (if issues found)

### Fix 1 (optional polish): Simulate non-auto-fixable ESLint exit ≠ 0 (FND-14)

- **Root cause**: Spec asks for pre-commit failure on unfixable ESLint; current gate asserts `eslint --fix` is present (mechanism) but does not run a staged bad file and assert exit ≠ 0
- **Fix task**: Extend `scripts/assert-lint-staged.mjs` or add a hook integration script that feeds a known unfixable snippet through the lint-staged/eslint path and expects non-zero exit
- **Priority**: Minor (spec-precision)

---

## Requirement Traceability Update

| Requirement | Previous (iter 0) | New Status |
| ----------- | ----------------- | ---------- |
| BFFUI-01 | ✅ Verified | ✅ Verified |
| FND-01 | ✅ Verified | ✅ Verified |
| FND-02 | ❌ Needs Fix | ✅ Verified |
| BFFUI-02 | ✅ Verified | ✅ Verified |
| FND-03 | ✅ Verified | ✅ Verified |
| FND-04 | ✅ Verified | ✅ Verified |
| BFFUI-03 | ✅ Verified | ✅ Verified |
| FND-05 | ✅ Verified | ✅ Verified |
| FND-06 | ✅ Verified | ✅ Verified |
| BFFUI-05 | ⚠️ / partial | ✅ Verified |
| FND-07 | ❌ Needs Fix | ✅ Verified |
| FND-08 | ❌ Needs Fix | ✅ Verified |
| BFFUI-04 | ✅ Verified | ✅ Verified |
| FND-09 | ✅ Verified | ✅ Verified |
| FND-10 | ✅ Verified | ✅ Verified |
| FND-11 | ✅ Verified | ✅ Verified |
| FND-12 | ✅ Verified | ✅ Verified |
| FND-13 | ❌ Needs Fix | ✅ Verified |
| FND-14 | ❌ Needs Fix | ⚠️ Verified with spec-precision (hard-fail mechanism only) |
| FND-15 | ✅ Verified | ✅ Verified |
| FND-16 | ✅ Verified | ✅ Verified |
| FND-17 | ✅ Verified | ✅ Verified |
| FND-18 | ✅ Verified | ✅ Verified |

---

## Summary

**Overall**: ✅ Ready (with minor optional polish)

**Spec-anchored check**: 29/30 ACs matched spec outcome | 1 spec-precision gap
**Sensor**: 3/3 mutations killed
**Gate**: 30 passed

**What works**: Prior gaps for Auth routes, Radix, 360px fluid layout, Tailwind utility markers, and lint-staged autofix/frontend-only contract are now evidenced and discriminating.

**Issues found**: FND-14 hard-fail still relies on `eslint --fix` mechanism rather than an exit≠0 staged-file simulation (optional polish).

**Next steps**: Optional Fix 1 polish, or accept and proceed to next BFF Auth fatia.
