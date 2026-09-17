# LESSONS — auto-maintained by scripts/lessons.py

> Machine-owned. Do NOT hand-edit. Changes are overwritten on the next `lessons.py` write.
> Canonical state lives in `.specs/lessons.json`. Edit lessons only via the script.
> promote_threshold=2 distinct features · window_days=45 · quarantine_threshold=2

## Confirmed (load these at Specify/Design)

Corroborated across multiple features. Safe to apply as guidance.

### L-026 — When an AC requires infrastructure behavior outside the app test suite, mark it explicitly as ops-verified or add a contract testable seam in-repo
- signal: `spec_precision_gap` · recurrence: 2 feature(s) · scope: `security,observability` · harmful: 0
- features: auth/email-verification, bff-auth/email-verification
- evidence: AUTH-25 AC2 access-log redaction (security,observability) (+1 more)
- last seen: 2026-08-18T18:03:26Z

### L-046 — For client BFF submits, assert Content-Type and CSRF header together with the request body shape
- signal: `ac_gap` · recurrence: 2 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/register, bff-auth/password
- evidence: UI AC3 / register-form.test.tsx — Content-Type (frontend/modules/auth) (+1 more)
- last seen: 2026-08-19T00:36:55Z

### L-053 — When a spec edge distinguishes rate-limit UI with and without Retry-After, assert both copies
- signal: `ac_gap` · recurrence: 2 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/email-verification, bff-auth/password
- evidence: Edge: 429 without Retry-After (frontend/modules/auth)
- last seen: 2026-08-19T00:36:55Z

## Candidates (under observation — do NOT load as guidance yet)

Seen once or not yet corroborated. Tracked, not trusted.

### L-042 — When rejecting whitespace-only input, assert against a value that survives TrimStrings (or disable trimming in the test) so whitespace validation rules are not masked by framework middleware
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `auth,validation,http-requests` · harmful: 0
- features: auth/module-closure
- evidence: M3 VerifyEmailRequest.php:40 / EmailVerificationTest.php:155 (auth,validation,http-requests)
- last seen: 2026-08-11T13:34:56Z

### L-043 — When a spec lists a concurrency edge for session rotation, add an explicit concurrent-call test asserting the old id is invalid and at most one successor remains valid
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth/session` · harmful: 0
- features: bff-auth/session-core
- evidence: validation.md edge: concurrent rotateSession — no file:line (auth/session)
- last seen: 2026-08-11T16:35:24Z

### L-044 — On BFF auth success paths, assert CSRF re-issue side effects (cookie or issueCsrfForSession), not only the session cookie
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/register
- evidence: Success AC1 / bff-register.test.ts happy path — missing issueCsrfForSession (frontend/modules/auth)
- last seen: 2026-08-11T20:38:51Z

### L-045 — When the spec requires multiple success body fields, assert every named field value, not a subset
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/register
- evidence: Terms AC5 / bff-register.test.ts:129 — terms_accepted_at (frontend/modules/auth)
- last seen: 2026-08-11T20:38:51Z

### L-047 — When stripping Bearer from BFF JSON, assert absence of the bare substring token, not only token_* field names
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/register
- evidence: Success AC3 / bff-register.test.ts:160 — bare token substring (frontend/modules/auth)
- last seen: 2026-08-11T20:38:51Z

### L-048 — When a spec forbids trim on an opaque token, assert the exact upstream request body including surrounding whitespace, not only a trimmed sentinel
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/email-verification
- evidence: mutant 8 parseVerifyBody trim / bff-verify-email.ts:42 (frontend/modules/auth)
- last seen: 2026-08-18T18:03:26Z

### L-049 — When a spec requires rejecting invalid Content-Type before upstream, assert 400 with fetch not called for a non-JSON content type
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/email-verification
- evidence: mutant 9 Content-Type guard / bff-verify-email.ts:79 (frontend/modules/auth)
- last seen: 2026-08-18T18:03:26Z

### L-050 — When a spec forbids clearing the session cookie on error, assert Set-Cookie Max-Age=0 is absent on error responses, not only that destroySession was not called
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/email-verification
- evidence: mutant 10 clearSessionCookie on error / bff-verify-email.test.ts:218 (frontend/modules/auth)
- last seen: 2026-08-18T18:03:26Z

### L-051 — When a spec requires the UI to display a message and navigate, assert the visible copy, not only the router destination
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/email-verification
- evidence: P1 UI AC7 / EV-09 verify-email-form.tsx:73-80 (frontend/modules/auth)
- last seen: 2026-08-18T18:03:26Z

### L-052 — When a spec names a cross-flow integration test, add that chained test in the feature suite rather than relying on a prior slice
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/email-verification
- evidence: P1 UX AC4 login-after-verify MSW (frontend/modules/auth)
- last seen: 2026-08-18T18:03:26Z

### L-054 — When a spec requires 429 Retry-After pass-through on every BFF mutation handler, assert the header on each service, not only one sibling
- signal: `surviving_mutant` · recurrence: 1 feature(s) · scope: `auth, bff` · harmful: 0
- features: bff-auth/password
- evidence: mutant 8 / bff-password-reset.ts 4xx Retry-After (auth, bff)
- last seen: 2026-08-19T00:36:55Z

### L-055 — Each BFF auth slice that lists ACCOUNT_SUSPENDED pass-through needs a handler and UI test in that slice, not only login or verify coverage
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth, bff` · harmful: 0
- features: bff-auth/password
- evidence: PW-20 AC4 / ACCOUNT_SUSPENDED (auth, bff)
- last seen: 2026-08-19T00:36:55Z

### L-056 — When a spec requires dropping extra body keys on every BFF mutation, assert the upstream JSON for each handler, not only one
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth, bff` · harmful: 0
- features: bff-auth/password
- evidence: PW-18 AC5 change extra fields (auth, bff)
- last seen: 2026-08-19T00:36:55Z

### L-057 — When BFF services duplicate 400 Content-Type, timeout 504, or 500/503 handling, assert those outcomes on each service, not only a representative sibling
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `auth, bff` · harmful: 0
- features: bff-auth/password
- evidence: PW-18 AC4 / PW-21 reset+change (auth, bff)
- last seen: 2026-08-19T00:36:55Z

### L-058 — When UI copy is specified only as generic pt-BR on a field, freeze the exact string in the spec before tests assert a specific sentence
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth, forms` · harmful: 0
- features: bff-auth/password
- evidence: PW-17 / INVALID_CREDENTIALS current_password copy (auth, forms)
- last seen: 2026-08-19T01:02:39Z

### L-059 — Keep new assertion strings within Prettier printWidth; format:check is part of make lint-frontend
- signal: `gate_fail` · recurrence: 1 feature(s) · scope: `frontend-tests` · harmful: 0
- features: bff-auth/password
- evidence: forgot-password-form.test.tsx:113 (frontend-tests)
- last seen: 2026-08-19T00:53:18Z

### L-060 — When a spec requires pass-through of 422 errors, assert the errors field, not only status and code
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth, bff` · harmful: 0
- features: bff-auth/password
- evidence: PW-01 / bff-password-reset-request.test.ts:171 (auth, bff)
- last seen: 2026-08-19T01:02:39Z

### L-061 — When a spec requires using shared UI primitives, name the components or a rendered contract so tests can assert them
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth, forms` · harmful: 0
- features: bff-auth/password
- evidence: PW-04 / PW-09 / PW-16 shared primitives (auth, forms)
- last seen: 2026-08-19T01:02:39Z

### L-062 — When a spec requires sentinel scans of rendered HTML, assert password and token sentinels are absent, not only Bearer
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `auth, forms` · harmful: 0
- features: bff-auth/password
- evidence: PW-17 / change-password-form.test.tsx:67 (auth, forms)
- last seen: 2026-08-19T01:02:39Z

### L-063 — When a spec forbids logging secrets, spy on console.log or the app logger rather than treating source-grep absence as coverage
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `security,observability` · harmful: 0
- features: bff-auth/password
- evidence: PW-22 AC1 console.log / validation.md (security,observability)
- last seen: 2026-08-19T01:02:39Z

### L-064 — Assert BFF timeout and 5xx mapping to 504 generic pt-BR on every new allowlisted handler, not only on a sibling mutation.
- signal: `ac_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/session-shell
- evidence: SH-22 AC4 GET/PATCH timeout 504 (frontend/modules/auth)
- last seen: 2026-08-19T16:18:54Z

### L-065 — When a spec says a request guard is not required on a branch, assert that branch without sending the guard.
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/session-shell
- evidence: SH-04 logout miss CSRF-optional (frontend/modules/auth)
- last seen: 2026-08-19T16:18:58Z

### L-066 — When a service reuses a mutation-guard helper, still assert Origin and CSRF 403 on the service under test so skipping the helper is killed.
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/session-shell
- evidence: SH-08/SH-13 Origin CSRF via shared helper only (frontend/modules/auth)
- last seen: 2026-08-19T16:18:58Z

### L-067 — Assert generic pt-BR forbidden copy without CSRF or Origin wording on every mutation form, not only on one sibling component.
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `frontend/modules/auth` · harmful: 0
- features: bff-auth/session-shell
- evidence: SH-22 AC5 ProfileForm LogoutAllForm 403 (frontend/modules/auth)
- last seen: 2026-08-19T16:18:58Z

### L-068 — When a config key exists for a value that a value object independently hardcodes, either wire the config through or remove the unused config key — never leave both, since one silently becomes dead configuration.
- signal: `spec_deviation` · recurrence: 1 feature(s) · scope: `config` · harmful: 0
- features: links/slug-policy
- evidence: SPEC_DEVIATION: SlugGenerator alphabet/length (tasks.md Batch A log) (config)
- last seen: 2026-09-01T16:03:24Z

### L-069 — When a spec says a service must not open or commit its own transaction, add a test that exercises the service standalone (no pre-existing caller transaction), not only nested inside one, to catch the case where nesting silently degrades the guarantee.
- signal: `spec_deviation` · recurrence: 1 feature(s) · scope: `transactions` · harmful: 0
- features: links/slug-policy
- evidence: SPEC_DEVIATION: ReserveSlug::automatic() per-attempt DB::transaction() SAVEPOINT (UseCases/ReserveSlug.php:50) (transactions)
- last seen: 2026-09-01T16:03:24Z

### L-070 — When an HTTP-mapping AC is explicitly deferred to a downstream slice, still add a test for the domain-side half (stable error code, no leaked detail) so the AC is not left with zero direct evidence in the slice that owns the domain exception.
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `error-handling` · harmful: 0
- features: links/slug-policy
- evidence: AC LNK-11.5 (SlugGenerationExhausted -> 503 SLUG_GENERATION_FAILED with Retry-After) (error-handling)
- last seen: 2026-09-01T16:03:31Z

### L-071 — When a spec edge case describes a temporal sequence (state created before a rule existed, rule added later), write a scenario test for that exact sequence rather than relying on the invariant being structurally implied by the absence of a re-validation path.
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `testing` · harmful: 0
- features: links/slug-policy
- evidence: Edge case: orphan reservation of a word later added to the denylist (spec.md Edge Cases) (testing)
- last seen: 2026-09-01T16:03:32Z

### L-072 — When a third-party parser validates a field's syntax ahead of a custom range/format check on that same field (e.g. a URI parser's own port-syntax check ahead of a domain port-range check), verify empirically which malformed values the parser itself rejects via its own exception before your check runs — its syntax error can silently preempt a spec's more specific rejection-reason requirement.
- signal: `spec_deviation` · recurrence: 1 feature(s) · scope: `links` · harmful: 0
- features: links/destination-policy
- evidence: backend/modules/Links/Domain/Services/DestinationUrlPolicy.php:101-114 (AC11/LDST-14, spec.md edge case ':-1') (links)
- last seen: 2026-09-17T22:33:06Z

### L-073 — A 'same input evaluated twice is deterministic' acceptance criterion needs a test at the exact layer and input class the claim covers (the full validation chain, including rejected inputs) — proving determinism on one sub-component or only on accepted inputs is not equivalent evidence for the full claim.
- signal: `spec_precision_gap` · recurrence: 1 feature(s) · scope: `links` · harmful: 0
- features: links/destination-policy
- evidence: AC14/LDST-20 (spec.md P1 Política) — evidenced only at PublicHostClassifierTest.php:161-179 (sub-component, no-I/O) and DestinationUrlPolicyTest.php:305-329 (accepted inputs only) (links)
- last seen: 2026-09-17T22:33:11Z

## Quarantined (failed when applied — ignore)

A confirmed lesson that recurred alongside failure. Kept for the maintainer to review.

_none_
