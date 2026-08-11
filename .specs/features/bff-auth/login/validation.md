# BFF Auth — Login Validation

**Date**: 2026-08-11  
**Spec**: `.specs/features/bff-auth/login/spec.md`  
**Diff range**: `cb5a316..HEAD`  
**Verifier**: independent sub-agent + fix iteration 1

---

## Verdict: PASS ✅

| Check | Result |
| ----- | ------ |
| Spec-anchored AC coverage (LOG-01 … LOG-14, BFFUI-30/31/32) | ✅ All mapped with `file:line` evidence |
| Gate: `make lint-frontend` | ✅ PASS |
| Gate: `make test-frontend-coverage` | ✅ PASS (222 tests) |
| Discrimination sensor | ✅ 3/3 mutants killed (iteration 0) |
| Fix iteration gaps (iteration 1) | ✅ Closed |

---

## Fix iteration 1 (post-verifier)

| Gap | Fix | Evidence |
| --- | --- | --- |
| LOG-05 dual 401 MSW scenarios | Added loop test unknown-email + wrong-password | `login-form.test.tsx` — same anti-enum message |
| LOG-06 ACCOUNT_PENDING_DELETION UI | Added RTL/MSW test | `login-form.test.tsx` |
| LOG-06/08 403/429 no session cookie | Set-Cookie assertions on 403/429/503 | `bff-login.test.ts` |
| LOG-12 upstream 503 | Integration test in performBffLogin | `bff-login.test.ts` |
| LOG-11 default `/` redirect | Page test without returnUrl | `page.test.tsx` |
| T11 bff-login branch coverage | Additional edge-case tests | `bff-login.ts` branches 78.84% |

---

## Gate results

- **Tests**: 222 passed, 0 failed, 0 skipped
- **Lint**: typecheck + eslint + prettier ✅
- **Coverage highlights**: `login-schema.ts` 100%, `auth-messages.ts` 94%, `bff-login.ts` 89.58% lines / 78.84% branches

---

## Discrimination sensor (iteration 0)

| Mutation | Target | Result |
| -------- | ------ | ------ |
| Force verification redirect to `/` | `buildSuccessRedirect` | KILLED |
| Skip Bearer strip in success body | `performBffLogin` response | KILLED |
| Skip destroySession before create | prior session path | KILLED |

---

## Requirement summary

All LOG-01 … LOG-14 and BFFUI-30/31/32 acceptance criteria have spec-anchored test evidence. Success criteria in spec.md satisfied for MVP login slice.
