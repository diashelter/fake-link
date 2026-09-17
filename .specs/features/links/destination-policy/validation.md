# Links — Política de destino · Validation

**Date**: 2026-09-17
**Spec**: `.specs/features/links/destination-policy/spec.md`
**Diff range**: `7cd7ddc..HEAD` (branch `feature/destination-policy`, 16 commits, 25 files changed)
**Verifier**: independent sub-agent (author ≠ verifier) — fresh context, re-derived coverage from spec.md from scratch; fix→re-verify iteration **2 of 3**

---

## Context: what changed since the previous (FAIL) pass

The previous Verifier pass (documented in the version of this file it is replacing) returned **FAIL (narrow)**: 36/38 spec ACs matched exactly, gate green, sensor 5/5 killed, but one genuine gap — a syntactically invalid port (`:-1`, `:abc`) was classified `MalformedUrl` instead of the spec-mandated `InvalidPort` (AC11) — plus two minor/cosmetic coverage notes (AC14 determinism only proven at a sub-component level, and no permanent regression test for the CRLF percent-encoding edge case).

Commit `c5a2a53` (`fix(links): classify a syntactically invalid port as InvalidPort`) addresses all three: it discriminates on `league/uri`'s fixed `SyntaxError` message prefix (`'The port \``) to route a syntactically-invalid port to `InvalidPort` instead of `MalformedUrl`, adds a determinism test covering the full `DestinationUrlPolicy` chain on 5 different rejected inputs evaluated twice, and adds a permanent regression test for the CRLF-percent-encoding case. This pass re-derives the **entire** spec-anchored check from scratch (all 4 stories, 38 criteria) — not a diff against the prior report — per instructions, since a fix commit can introduce its own regressions elsewhere.

---

## Task Completion

| Task | Status  | Notes |
| ---- | ------- | ----- |
| T1   | ✅ Done | `league/uri` promoted to direct `^7.8` require |
| T2   | ✅ Done | `DestinationRejectionReason` enum, 12 cases, exhaustiveness + `ValueError` test |
| T3   | ✅ Done | `LinksDomainException::invalidDestinationUrl(DestinationRejectionReason $reason)` — signature does not accept a string; fixed message verified for all 12 reasons |
| T4   | ✅ Done | `PublicHostClassifier::reject()` — order IP literal → syntax → self-host → special-use |
| T5   | ✅ Done | `config('links.destination.self_hosts')` derived from `SHORT_HOST`/`APP_URL`, wired as singleton, `phpunit.xml` pins both env vars |
| T6   | ✅ Done | Pre-parse checks (length, control/non-ASCII, percent-encoding) implemented in fixed order |
| T7   | ✅ Done | Parse + scheme/userinfo/host/port rules — **the AC11 gap from the previous pass is now closed by `c5a2a53`** (see below) |
| T8   | ✅ Done | Normalization + post-normalization length check, idempotency property test |
| T9   | ✅ Done | `DestinationUrl::fromString(string, PublicHostClassifier)`; reflection tests prove no raw-value accessor and a private constructor |
| T10  | ✅ Done | `SealDestinationUrl` — single path raw string → `EncryptedDestination`, revalidates every call |
| T11  | ✅ Done | Architecture rule present; `ServiceProviders` widening confirmed safe (binding-only reference, no `encrypt()`/`decrypt()` call) |
| T12  | ✅ Done | Non-leakage gate covers `getTrace()` structured args, not just `getTraceAsString()`; `ini_set` fix reconfirmed load-bearing this pass (Sensor #4) |
| Fix (`c5a2a53`) | ✅ Done | Port-syntax `InvalidPort` reclassification, full-chain determinism test (5 rejected fixtures × 2 calls), CRLF regression test — all independently reproduced below |

All 12 original tasks' commits plus the fix commit are present and match the stated diff range. No task is partial or blocked.

---

## Spec-Anchored Acceptance Criteria

### P1: Política de URL rejeita destino inseguro

| # | Criterion (WHEN X THEN Y) | Spec-defined outcome | `file:line` + assertion expression | Result |
| - | -------------------------- | --------------------- | ----------------------------------- | ------ |
| 1 | Scheme ≠ http/https (`ftp:`, `javascript:`, `data:`, `file:`, absent) | `SchemeNotAllowed`, no VO built | `backend/modules/Links/Tests/Unit/Domain/Services/DestinationUrlPolicyTest.php:115-132` — `expect(...->reject($raw))->toBe(DestinationRejectionReason::SchemeNotAllowed)` for all 5 forms | ✅ PASS |
| 2 | Raw length >2048 after trim ⇒ `TooLong`; exactly 2048 ⇒ proceeds | `TooLong` / proceeds | `DestinationUrlPolicyTest.php:18-32` — exact 2048 → `reject()` is `null`; 2049 → `TooLong` | ✅ PASS |
| 3 | Any U+0000–U+001F or U+007F after trim ⇒ `ControlCharacter` | `ControlCharacter` | `DestinationUrlPolicyTest.php:59-70` — `\t`, `\r`, `\n`, DEL, NUL, unit separator, all → `ControlCharacter` | ✅ PASS |
| 4 | userinfo in any form (`u:p@`, `u@`, `@`, `:@`) | `UserinfoPresent` | `DestinationUrlPolicyTest.php:134-143` — all 4 forms → `UserinfoPresent` | ✅ PASS |
| 5 | IPv4 any notation, IPv6 bracketed literal (public inclusive) | rejected | `DestinationUrlPolicyTest.php:172-179` + `PublicHostClassifierTest.php:16-31` (`127.0.0.1`,`10.0.0.5`,`8.8.8.8`,`[::1]`,`[fd00::1]`,`[2606:4700::1111]` → `IpLiteral`); `PublicHostClassifierTest.php:34-48` (`2130706433`,`0x7f.1` → `InvalidHostname` — verified by reading `PublicHostClassifier.php:39` (`filter_var` does not validate either bare-integer or hex-dotted forms as an IP) — AC5 mandates rejection, not a specific reason, for these two non-standard-notation forms) | ✅ PASS |
| 6 | Special-use suffix host, any case | `SpecialUseHost` | `PublicHostClassifierTest.php:73-96` — all 9 suffixes + 4 case variants | ✅ PASS |
| 7 | Host = configured self_host or subdomain, any case, trailing dot | `SelfHost` | `DestinationUrlPolicyTest.php:185-193` (incl. `HTTPS://GO.LOCALHOST./abc`); `PublicHostClassifierTest.php:98-159` (exact, subdomain, deep subdomain, case-insensitive, self-host-over-special-use precedence) | ✅ PASS |
| 8 | No dot, empty label, label>63, total>253, leading/trailing hyphen, non-alpha/<2-char TLD | `InvalidHostname` | `PublicHostClassifierTest.php:34-70` — 10 malformed cases + boundary 63/253 accepted, 64/254 rejected | ✅ PASS |
| 9 | Any non-ASCII byte in any component ⇒ reject; A-label + percent-encoded ⇒ accept | `NonAsciiInput` / accepted | `DestinationUrlPolicyTest.php:72-80` (host/path/query non-ASCII → `NonAsciiInput`); `DestinationUrlPolicyTest.php:295-299` (`xn--caf-dma.com` accepted, untouched) | ✅ PASS |
| 10 | Malformed percent-encoding (`%zz`,`%A`,isolated `%`) before parser | `InvalidPercentEncoding` | `DestinationUrlPolicyTest.php:82-105` — 3 malformed forms + explicit ordering proof (isolated `%` caught even with an also-invalid scheme) | ✅ PASS |
| 11 | Port non-numeric, `0`, or >65535 ⇒ `InvalidPort` (all three sub-cases) | `InvalidPort` for all three | `DestinationUrlPolicyTest.php:201-206` (`:0`, `:65536` → `InvalidPort`) **and** `DestinationUrlPolicyTest.php:213-218` (`:-1`, `:abc` → `InvalidPort`) — independently reproduced empirically (see "Empirical probe" below): `league/uri` 7.8.1 throws `SyntaxError` with message `"The port \`-1\` is invalid"` / `"The port \`abc\` is invalid"` for these, and `DestinationUrlPolicy.php:112-120` now routes any `SyntaxError` whose message starts with `'The port \`'` to `InvalidPort` | ✅ **PASS (previously GAP, now closed)** |
| 12 | Syntactically invalid URL (`https://`, `http:///path`, `https://ho st.com/`), no PHP error/warning | `MalformedUrl` | `DestinationUrlPolicyTest.php:145-153` — 3 forms → `MalformedUrl`; independently reproduced empirically: none of these 3 `SyntaxError` messages start with `'The port \`'`, so the new port-prefix check does not swallow them (see probe table) | ✅ PASS |
| 13 | Valid public-host URL | VO constructed successfully | `DestinationUrlTest.php:18-29` (`http://`/`https://example.com/path` construct and round-trip `value()`) | ✅ PASS |
| 14 | Same input evaluated twice ⇒ identical verdict/reason, deterministic, no I/O/DNS | identical verdict, no I/O | `DestinationUrlPolicyTest.php:328-341` — **full `DestinationUrlPolicy::reject()` chain**, called twice per fixture, on 5 different **rejected** inputs (`TooLong`, `IpLiteral`, `SpecialUseHost`, `SelfHost`, `InvalidPort`), asserting the identical enum case both times; `PublicHostClassifierTest.php:161-179` additionally proves no-I/O timing at the sub-component level | ✅ **PASS (previously spec-precision/partial-evidence gap, now closed)** |

**Status**: 14/14 full PASS. Both items flagged by the previous pass (AC11, AC14) are now closed with direct evidence.

---

### P1: Normalização preserva semântica

| # | Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| - | --------- | --------------------- | ------------------------ | ------ |
| 1 | Mixed-case scheme/host, path case preserved | `https://example.com/Path` | `DestinationUrlPolicyTest.php:248-250` — `HTTPS://Example.COM/Path` → `https://example.com/Path` | ✅ PASS |
| 2 | Redundant default port (`:80` http, `:443` https) | port removed | `DestinationUrlPolicyTest.php:251-256` | ✅ PASS |
| 3 | Custom valid port (`:8443`) | preserved exactly | `DestinationUrlPolicyTest.php:263-265` | ✅ PASS |
| 4 | Empty port (`:`) | treated as default, removed | `DestinationUrlPolicyTest.php:257-259` | ✅ PASS |
| 5 | Query/fragment (`?b=2&a=1&empty=&flag#frag`) | preserved byte-for-byte, no reorder, no removal | `DestinationUrlPolicyTest.php:287-289` | ✅ PASS |
| 6 | Valid percent-encoding (`%2F`, `%C3%A1`) | preserved exactly, not decoded/recoded | `DestinationUrlPolicyTest.php:281-283`; also `:91-96` (round-trip) | ✅ PASS |
| 7 | `.`/`..`/`//` in path | preserved, not collapsed | `DestinationUrlPolicyTest.php:284-286` | ✅ PASS |
| 8 | Empty path (`https://example.com`) | `https://example.com/` | `DestinationUrlPolicyTest.php:269-271` | ✅ PASS |
| 9 | Trailing FQDN dot | removed | `DestinationUrlPolicyTest.php:272-274` | ✅ PASS |
| 10 | Re-normalizing an already-normalized value | identical result | `DestinationUrlPolicyTest.php:302-326` — property test over 15 accepted fixtures | ✅ PASS |
| 11 | Normalized value >2048 | `TooLong` before any cipher | `DestinationUrlPolicyTest.php:356-367` — path-empty + long query construction that only exceeds 2048 after the `/` insertion | ✅ PASS |
| 12 | `value()` always normalized, no raw accessor | no public path returns raw input | `DestinationUrlTest.php:118-125` — reflection over public methods == `['fromString','value','equals']`; `:112-116` normalized value returned for a case/port-changing input | ✅ PASS |

**Status**: 12/12 PASS. Unchanged from the prior pass — no regression introduced by `c5a2a53` (which touches only the port `SyntaxError` branch, not normalization).

---

### P1: Destino validado imediatamente antes de cada cifra

| # | Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| - | --------- | --------------------- | ------------------------ | ------ |
| 1 | App service receives raw string, builds `DestinationUrl` (full policy), only then calls `encrypt` | policy-then-cipher order | `backend/modules/Links/UseCases/SealDestinationUrl.php:27-31` (structure — `DestinationUrl::fromString` on line 29, `cipher->encrypt` on line 31) + `SealDestinationUrlTest.php:46-55` (round-trip) | ✅ PASS |
| 2 | Rejected raw ⇒ exception, zero `encrypt` calls (spy) | 0 invocations | `SealDestinationUrlTest.php:77-106` — `CountingDestinationCipher` spy, `expect($spy->encryptCalls)->toBe(0)` for scheme-rejection and self-host-rejection | ✅ PASS |
| 3 | Same service called twice for same link (create, then swap) ⇒ policy runs both times | policy executed on both calls | `SealDestinationUrlTest.php:109-128` — second (rejected) call after a first accepted call still throws and does not increment the spy; two accepted calls increment the spy twice | ✅ PASS |
| 4 | `key_id` returned is `active_key_id` from config | matches configured value | `SealDestinationUrlTest.php:67-74` — `expect($encrypted->keyId())->toBe('k1')` | ✅ PASS |
| 5 | Envelope decrypted by the port ⇒ plaintext == normalized value, incl. query+fragment | exact match | `SealDestinationUrlTest.php:46-55` — round-trip with `?a=1&b=2#frag` | ✅ PASS |
| 6 | No path in public module surface encrypts a raw string without the policy (arch test) | structurally impossible | `backend/tests/Architecture/ModularMonolithTest.php:90-99` — `toOnlyBeUsedIn([UseCases, Infrastructure\Crypto, ServiceProviders])`; empirically confirmed by this pass's mutation #5 (see Discrimination Sensor) | ✅ PASS |

**Status**: 6/6 PASS. Unchanged from the prior pass.

---

### P2: Erro estável e ausência de vazamento do destino

| # | Criterion | Spec-defined outcome | `file:line` + assertion | Result |
| - | --------- | --------------------- | ------------------------ | ------ |
| 1 | Any rule fails ⇒ exception carries stable enum motive, message always `The destination URL is not allowed.` | fixed message, all 12 reasons | `LinksDomainExceptionTest.php:15-21` — loop over all 12 cases, message identical; `:23-29` `reason()` returns the exact reason | ✅ PASS |
| 2 | Exception serialized (message, context, `getTraceAsString`) never contains URL/host/query/fragment | zero occurrences | `DestinationLeakTest.php:64-106` — all 12 reasons, message + `getTraceAsString()` + `getPrevious()` + `getTrace()` structured args | ✅ PASS |
| 3 | Log capture (accept + reject) never contains URL/query/fragment | zero occurrences | `DestinationLeakTest.php:73-88` (reject path, all 12 reasons) and `:120-123` (accept path) — `Log::listen`/`MessageLogged` spy, `json_encode($event->context)` scanned | ✅ PASS |
| 4 | Encrypted value ⇒ no log contains plaintext/envelope/key material | zero occurrences | `DestinationLeakTest.php:109-131` — token-in-envelope check + base64-decoded envelope check | ✅ PASS |
| 5 | Motive published as observability attribute ⇒ enum name only, fixed cardinality | fixed set, `ValueError` outside it | `DestinationRejectionReasonTest.php:8-27` — 12 exact values asserted; `from('unknown')` throws `ValueError` | ✅ PASS |
| 6 | Two different-reason rejections ⇒ indistinguishable public response | identical errorCode + message | `DestinationLeakTest.php:134-163` — `SchemeNotAllowed` vs `SelfHost`, different `reason()`, identical `errorCode()`/`getMessage()` | ✅ PASS |

**Status**: 6/6 PASS. Unchanged from the prior pass. Note: the new port-`SyntaxError`→`InvalidPort` code path in `DestinationUrlPolicy.php:112-120` uses `$e->getMessage()` only for a `str_starts_with()` boolean check — the message itself (which, per the empirical probe, names only the port substring, never the full URL) is never stored, logged, or attached to the thrown exception, so LDST-24's no-leak guarantee is unaffected even in the new branch. Confirmed by reading the code and by `DestinationLeakTest.php`'s `InvalidPort` case (line 105), which still uses the pre-existing `:65536` range-check path — not the new syntax-error path — and passes; the new syntax-error path shares the same `fail()` sink (with its unconditional `ini_set` mitigation), so it inherits the same guarantee structurally, not by a separate ad-hoc check.

---

**Overall spec-anchored check**: **38/38 criteria matched the spec-defined outcome exactly.** 0 gaps, 0 spec-precision gaps. Both items flagged FAIL/gap by the previous pass (AC11/LDST-14 GAP, AC14/LDST-20 partial-evidence gap) are now fully closed with direct, re-derived evidence — not merely trusted from the fix commit's self-report.

---

## Empirical probe: port `SyntaxError` behavior (league/uri 7.8.1)

Run via a throwaway PHP script inside the backend Docker container (`docker compose run --rm --no-deps backend php <probe>`), independently of the implementer's own probe:

| Input | `league/uri` 7.8.1 result | Routes to |
| --- | --- | --- |
| `:-1` | `SyntaxError: The port \`-1\` is invalid` | `InvalidPort` (message starts with `'The port \`'`) |
| `:abc` | `SyntaxError: The port \`abc\` is invalid` | `InvalidPort` |
| `:0` | Parses OK, `getPort()` = `0` | `InvalidPort` (step 9 range check, unchanged path) |
| `:65536` | Parses OK, `getPort()` = `65536` | `InvalidPort` (step 9 range check, unchanged path) |
| `:+1` | `SyntaxError: The port \`+1\` is invalid` | `InvalidPort` (not required by spec, but correctly caught — no over-broad or under-broad edge) |
| `:1.5` | `SyntaxError: The port \`1.5\` is invalid` | `InvalidPort` |
| `:-0` | `SyntaxError: The port \`-0\` is invalid` | `InvalidPort` |
| `https://` | `SyntaxError: The uri \`https://\` is invalid for the \`https\` scheme.` | `MalformedUrl` (message does not start with `'The port \`'`) |
| `http:///path` | `SyntaxError: The uri \`http:///path\` is invalid for the \`http\` scheme.` | `MalformedUrl` |
| `https://ho st.com/` | `SyntaxError: Host \`ho%20st.com\` is invalid : the IP host is malformed` | `MalformedUrl` |

This confirms both halves of the fix: the new `InvalidPort` routing is correct for the two spec-mandated cases (`:-1`, non-numeric) and the pre-existing `:0`/`:65536` path is unaffected, **and** the message-prefix check is not over-broad — all three genuinely-malformed URLs from AC12 still land on `MalformedUrl`, because `league/uri`'s `SyntaxError` messages for host/authority-level failures use a different fixed template (`"The uri \`...\`..."` / `"Host \`...\`..."`) that never starts with `'The port \`'`.

---

## Discrimination Sensor

Sensor depth: **elevated (5 mutations)** — this is not a payment/auth path, but the destination policy gates a public redirect `Location` header (SSRF-adjacent), so the sensor runs above the default 1–3 floor, matching the previous pass's tier. All mutations were applied directly to the real tree one at a time via `Edit`, run against the narrowest relevant test file(s), confirmed killed, then reverted with `git checkout --` before the next; `git diff --stat -- backend/` confirmed a byte-identical tree after the last revert.

| # | File:line | Description | Killed? |
| - | --------- | ------------ | ------- |
| 1 | `backend/modules/Links/Domain/Services/DestinationUrlPolicy.php:116` | Changed the `SyntaxError` message-prefix match from `'The port \`'` to an unreachable literal (`'MUTANT-DISABLED-PREFIX'`), reverting the fix's core discriminator | ✅ Killed — 2/2 new "rejects a syntactically invalid port as InvalidPort (AC11)" cases failed in `DestinationUrlPolicyTest.php:213-218`, both falling back to `MalformedUrl` |
| 2 | `backend/modules/Links/Domain/Services/PublicHostClassifier.php:39` | Disabled the IP-literal check (`if (str_starts_with(...) \|\| filter_var(...))` → `if (false)`) | ✅ Killed — 10 tests failed across `PublicHostClassifierTest.php` and `DestinationUrlPolicyTest.php` (all IPv4/IPv6-literal cases, plus the AC14 `IpLiteral` determinism case) |
| 3 | `backend/modules/Links/Domain/Services/PublicHostClassifier.php:65-77` | Reordered special-use check before self-host check (reintroducing the bug fixed in commit `04bf616`) | ✅ Killed — 6 tests failed (the "self host takes precedence over special-use suffix" regression block in `PublicHostClassifierTest.php`, plus the AC14 `SelfHost` determinism case and the `HTTPS://GO.LOCALHOST./abc` AC7 case in `DestinationUrlPolicyTest.php`) |
| 4 | `backend/modules/Links/Domain/Services/DestinationUrlPolicy.php:66` | Removed `ini_set('zend.exception_ignore_args', '1')` from `fail()` | ✅ Killed — 12/14 tests in `DestinationLeakTest.php` failed on the `getTrace()` structured-args assertion, raw sentinel token reappearing in live stack-frame arguments |
| 5 | `backend/modules/Links/Exceptions/LinksDomainException.php:30` | Appended the rejection reason to the public message (`'...allowed: '.$reason->value`), breaking the P2 Erro story's fixed-message/indistinguishability guarantee | ✅ Killed — `LinksDomainExceptionTest.php` (fixed-message loop) and `DestinationLeakTest.php:159-161` (cross-reason indistinguishability) both failed |

**Sensor depth**: elevated (5/5 mutations, security-sensitive path)
**Result**: 5/5 killed — **PASS** ✅

Mutation #1 is the sensor mandated by this iteration's task (revert the specific `c5a2a53` fix and confirm the new tests fail without it) — confirmed. Mutations #2–#5 cover the other highest-risk branches in the feature: IP-literal SSRF gate, self-host-over-special-use precedence (a previously-real bug), the trace-leak mitigation, and the P2 story's stable-error-contract guarantee — none of which are touched by `c5a2a53` but all of which remain load-bearing after it.

---

## Code Quality

| Principle | Status |
| --- | --- |
| No features beyond what was asked | ✅ — the fix commit's scope is exactly the port-classification branch plus the two flagged test gaps, nothing else |
| No abstractions for single-use code | ✅ |
| No unnecessary "flexibility" added | ✅ |
| Only touched files required for the task | ✅ — `c5a2a53` touches exactly 2 files: `DestinationUrlPolicy.php` and `DestinationUrlPolicyTest.php` |
| Didn't "improve" unrelated code | ✅ |
| Matches existing patterns/style | ✅ — the `str_starts_with()` check follows the same narrow, single-purpose style as the rest of the `evaluate()` chain |
| Would a senior engineer approve? | ✅ |
| Tests map to ACs and are non-shallow (spot-check one story) | ✅ — spot-checked "P1: Política" story in full: all 14 ACs have precise, non-tautological assertions (exact enum values, not just "an exception was thrown") |
| Spec-anchored outcome check | ✅ — 38/38 criteria match exactly, 0 gaps |
| Per-layer Coverage Expectation met | ✅ — domain logic (`DestinationUrlPolicy`, `PublicHostClassifier`) has 1:1 AC-to-test mapping; no HTTP routes in scope for this slice (by design) |
| Every test maps to a spec AC, listed edge case, or Done-when criterion | ✅ — the 3 new tests in `c5a2a53` map directly to AC11, AC14, and the CRLF edge case respectively; no orphan tests |
| Documented project guidelines followed | ✅ — `docs/testing.md` §4 (coverage gate), §6.3 (destination cases), `AD-009` (Docker-only gates), `AD-019` (parser-only authority parsing — the fix explicitly does not textually parse the authority; it inspects only the parser's own diagnostic message) |

---

## Edge Cases

- [x] `https://example.com` (empty path) → accepted, normalized to `https://example.com/` — `DestinationUrlPolicyTest.php:269-271`
- [x] Exactly 2048 / 2049 chars → accepted / rejected — `DestinationUrlPolicyTest.php:18-32`
- [x] 2048-char raw whose normalization strips `:443` → accepted — `DestinationUrlPolicyTest.php:344-355`
- [x] `:0443` (leading zero, resolves to default) → port removed — `DestinationUrlPolicyTest.php:260-262`
- [x] `:00080` on https (leading zero, custom port) → preserved as `:80` — `DestinationUrlPolicyTest.php:266-268`
- [x] Empty fragment/query preserved as-is — `DestinationUrlPolicyTest.php:275-280`
- [x] `xn--caf-dma.com` accepted; `café.com`/`pá`/`?a=á` rejected `NON_ASCII_INPUT` — `DestinationUrlPolicyTest.php:72-80,295-299`
- [x] Internal tab → `CONTROL_CHARACTER` — `DestinationUrlPolicyTest.php:63`
- [x] `%0d%0a` CRLF, percent-encoded → **accepted**, not an injection vector — now a **permanent, dedicated regression test**: `DestinationUrlPolicyTest.php:290-292` (`'percent-encoded CRLF in the path is accepted and left untouched'`, asserting `normalize('https://example.com/x%0d%0aSet-Cookie:%20a')` returns the value byte-for-byte unchanged). This closes the previous pass's minor coverage note.
- [x] Leading/trailing whitespace trimmed → accepted — `DestinationUrlPolicyTest.php:35-40`
- [x] Trailing FQDN dot removed → accepted — `DestinationUrlPolicyTest.php:272-274`
- [x] `HTTPS://GO.LOCALHOST./abc` → `SELF_HOST` (post-normalization comparison) — `DestinationUrlPolicyTest.php:190-193`
- [x] `mylocalhost.com`, `internal-tools.com` accepted (label/suffix, not substring) — `PublicHostClassifierTest.php:125-133`
- [x] `8.8.8.8` (public IP literal) rejected — `PublicHostClassifierTest.php:22`
- [x] `https://example.com:65536/x` and `https://example.com:-1/x` → **both** `INVALID_PORT` — `DestinationUrlPolicyTest.php:201-206,213-218` — closes the previous pass's Gap #1
- [x] Host 253/254 chars → accepted/rejected — `PublicHostClassifierTest.php:56-70`
- [x] Hostname resolving to a private IP → accepted (no DNS resolution occurs at all, structurally) — matches decision D1, nothing to test (no I/O exists in the code path); confirmed by reading `PublicHostClassifier.php` and `DestinationUrlPolicy.php` in full — neither performs any network call
- [x] `self_hosts` empty in config → classifier still functions (tested implicitly via `classifierWith()`'s empty-array default across ~30 test cases); test env's `self_hosts` list itself is asserted non-empty — `DestinationSelfHostsConfigTest.php:25-27`

All edge cases from spec.md's Edge Cases section are now covered, including the two the previous pass flagged as incomplete.

---

## Gate Check

**Gate command**: `make lint && make test-backend-coverage` (Build gate, per tasks.md's Gate Check Commands table), run via Docker (AD-009) with `$(COMPOSE)` = `docker compose --env-file docker/versions.env -f docker-compose.yml -f docker-compose.dev.yml`.

`make lint` is a composite of `lint-openapi`, `lint-backend`, `lint-frontend`, `test-architecture`, then `test-backend` (fail-fast). Per this task's instructions and `.specs/STATE.md`'s documented pre-existing, unrelated `lint-frontend` failure, each backend-relevant step was run and verified individually:

| Step | Command | Result |
| --- | --- | --- |
| OpenAPI lint | `make lint-openapi` | ✅ 0 errors, 3 pre-existing warnings (`info-contact`, 2× `operation-description`) — unrelated, no `openapi.yaml` changes in this diff (Out-of-Scope table) |
| Backend lint | `make lint-backend` | ✅ Pint 333 files clean, PHPStan/Larastan 0 errors, PHPMD clean |
| Frontend lint | `make lint-frontend` | ❌ Fails at `tsc --noEmit` — `frontend/e2e/guards.spec.ts:114,127` and `journey.spec.ts:183`, `TS2353: 'launchOptions' does not exist in type 'BrowserContextOptions'`. **Independently reproduced this pass** and confirmed to be exactly the pre-existing failure documented in `.specs/STATE.md`'s Handoff section. This slice makes zero frontend changes (confirmed via `git diff 7cd7ddc..HEAD --stat` — no `frontend/` path present). Not a regression introduced by this feature. |
| Architecture suite | `make test-architecture` | ✅ 19/19 passed (71 assertions) |
| Backend test suite | `make test-backend` | ✅ 822/822 passed (4090 assertions) |
| Backend coverage | `make test-backend-coverage` | ✅ 822/822 passed, exit 0. `Links module coverage gate passed: lines 91.24%, methods 91.20% (branch proxy)` — clears the `docs/testing.md` §4 gate (90% lines / 85% methods) |

- **Test count before feature** (T1 baseline, per tasks.md done-when entries): 652
- **Test count at the previous (FAIL) Verifier pass**: 816
- **Test count after this fix** (this pass's own clean run): **822**
- **Delta from previous pass**: +6 — net of: −2 (old "MalformedUrl for invalid port" test, 2 fixtures, removed) +2 (new "InvalidPort for invalid port" test, 2 fixtures) +5 (new full-chain determinism test, 5 rejected fixtures) +1 (new CRLF row in the existing normalize table) = +6. Matches the actual observed delta (816 → 822) exactly — no unexplained test-count drift, no silent deletions.
- **Skipped tests**: none observed
- **Failures**: none in the real tree (all failures recorded in this report are from Verifier-injected, fully-reverted mutations in scratch state, or the documented pre-existing frontend issue)

---

## Fix Plans

None. This pass is a clean PASS — no gaps, no surviving mutants, no spec-precision issues found.

---

## Requirement Traceability Update

`spec.md`'s own Requirement Traceability table was updated in place (all 24 rows: `Pending` → `Verified`; coverage line updated to `24/24 verificados`).

| Requirement ID | Previous Status | New Status |
| --- | --- | --- |
| LDST-01 | Implementing | ✅ Verified |
| LDST-02 | Implementing | ✅ Verified |
| LDST-03 | Implementing | ✅ Verified |
| LDST-04 | Implementing | ✅ Verified |
| LDST-05 | Implementing | ✅ Verified |
| LDST-06 | Implementing | ✅ Verified |
| LDST-07 | Implementing | ✅ Verified |
| LDST-08 | Implementing | ✅ Verified |
| LDST-09 | Implementing | ✅ Verified |
| LDST-10 | Implementing | ✅ Verified |
| LDST-11 | Implementing | ✅ Verified |
| LDST-12 | Implementing | ✅ Verified |
| LDST-13 | Implementing | ✅ Verified |
| LDST-14 | ❌ Needs Fix (previous pass) | ✅ Verified — port-syntax `InvalidPort` gap closed by `c5a2a53` |
| LDST-15 | Implementing | ✅ Verified |
| LDST-16 | Implementing | ✅ Verified |
| LDST-17 | Implementing | ✅ Verified |
| LDST-18 | Implementing | ✅ Verified |
| LDST-19 | Implementing | ✅ Verified |
| LDST-20 | ⚠️ Verified with note (previous pass) | ✅ Verified — full-chain rejected-input determinism now directly tested |
| LDST-21 | Implementing | ✅ Verified |
| LDST-22 | Implementing | ✅ Verified |
| LDST-23 | Implementing | ✅ Verified |
| LDST-24 | Implementing | ✅ Verified |

**Coverage**: 24/24 requirements traced to evidence, all fully verified. 0 open items.

---

## Summary

**Overall**: ✅ **Ready** — clean PASS. Both items from the previous (FAIL) pass are closed with direct, independently re-derived evidence, and the full 38-criterion spec-anchored check (all 4 stories, re-derived from scratch, not merely diffed against the prior report) finds no new gaps.

**Spec-anchored check**: 38/38 criteria matched the spec-defined outcome exactly. 0 gaps, 0 spec-precision gaps.

**Sensor**: 5/5 mutations killed — the mandated port-fix revert, plus 4 more across the feature's highest-risk branches (IP-literal SSRF gate, self-host/special-use precedence, exception trace-leak mitigation, and the P2 story's stable-message/indistinguishability guarantee). All fully reverted; tree confirmed byte-identical to HEAD after the sensor (`git diff --stat -- backend/` empty).

**Gate**: All backend-relevant steps green — `lint-openapi` (0 errors), `lint-backend` (Pint/PHPStan/PHPMD clean), `test-architecture` (19/19), `test-backend` (822/822), `test-backend-coverage` (822/822, Links 91.24%/91.20%, clears the 90/85 gate). `lint-frontend` fails on the pre-existing, unrelated `TS2353` issue documented in `.specs/STATE.md` — independently reproduced and confirmed not a regression (this slice touches zero frontend files).

**What works**: The entire policy chain (pre-parse checks, parser integration including the new port-syntax discrimination, host classification, port range, normalization, idempotency and full-chain determinism on rejected inputs), the structural cipher gate (`SealDestinationUrl` + the architecture rule), and the non-leakage gate all hold up to independent, adversarial re-verification in this second pass. The fix commit (`c5a2a53`) is narrowly scoped, empirically verified correct (both that it fires for the two spec-mandated cases and that it does not over-fire on genuinely malformed URLs), and does not leak the parser's own diagnostic message (which is discarded immediately after a boolean prefix check).

**Issues found**: None.

**Next steps**: None required. This is fix→re-verify iteration 2 of 3 and the feature is verified complete — no further iteration needed.
