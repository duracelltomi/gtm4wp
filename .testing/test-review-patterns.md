# Test Review — Learned Patterns

Accumulated patterns from past reviews of the GTM4WP **unit-test suite**. The
test-review command reads this file before each review and appends new
generalizable patterns after. It is also pre-loaded before writing any test —
see `.testing/pre-flight-check.md`.

This is the test-quality sibling of `.security/code-review-patterns.md`: that
file governs the *code*, this one governs the *tests that ship with it*. Where a
rule spans both (a security sink needs a hostile-input regression test), the two
files cross-reference rather than duplicate.

> ⛔ **Disclosure rule (hard):** this is a public repo — committed == published.
> A test gap is normally just a coverage note. But a *missing regression test on
> a security sink* can effectively point at an unfixed vulnerability. Never write
> the exploit detail of an unfixed finding into this file — keep it terse and,
> for a live vuln, defer to the git-ignored `.security/code-review-report-*.md`.
> Full rule at the top of `.security/code-review-checklist.md`.

**Categories:**
- **Test Smells (TS)** — recurring weaknesses that keep appearing in tests
- **Project-Specific Test Conventions (TC)** — GTM4WP-specific test patterns to follow (a generic reviewer would miss these)
- **Blessed Exceptions (BE)** — things that look like a smell but are intentional here

---

## Quick Index

Scan this first. Each row is `ID — one-line litmus`. Jump to the full entry only
when a candidate matches. The ⭐ tier is the **highest-impact** set — check these
on every review before anything else.

**⭐ Highest impact — check first:**
- **TS-1** — a *covered* line is not an *asserted behavior*: a test that exercises an output sink with only benign data leaves the security/edge case unguarded. Coverage stays green; the risk is invisible. Every security-relevant sink needs a hostile-input case.
- **TS-2** — an escaping/XSS test must assert **both** that the safe form is present **and** that the raw break-out char is absent. One direction alone gives false confidence.
- **TC-5** — every request/header-sourced dataLayer field (a `.security` PA-3 sink: `?s=`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, cookies, `$_SERVER`) ships a hostile-input regression test. This is the intersection of the two review systems.
- **TS-6** — a whole class with **zero** tests is the cheapest, highest-value find. Run the missing-test-file sweep first every review.

**Test Smells (TS):**
- **TS-11** — upstream raw-passthrough contract: a module that hands a value to a *shared downstream JSON sink* needs a **special-character** input proving it does NOT pre-escape (`esc_js`/`esc_attr`). With benign data (`'HU'`) an accidental pre-escape is invisible and coverage stays green (the module-boundary form of TS-1 / RI-4).
- **TS-3** — the test asserts it *ran* (a call happened, a handle registered) but not the *effect* (the queue flushed, the value changed).
- **TS-4** — tautological test: it asserts the value a stub/mock was told to return, exercising nothing real.
- **TS-5** — happy-path only: no error / empty / boundary / invalid-input branch (a valid custom value tested, the fallback path not).
- **TS-7** — state leakage: a test reads/writes `$_SERVER`, `$GLOBALS`, statics or singletons without snapshotting and resetting them in `setUp`/`tearDown`.
- **TS-8** — non-determinism: reliance on real time, randomness, or test-execution order.
- **TS-9** — over-coupling: asserting an exact rendered string where a behavioral assertion would do (see BE-1 for the blessed exception).
- **TS-10** — an untested public method or branch of an *otherwise-covered* class (a getter, an alternate `$echo`/placement path, a priority branch).

**Project-Specific Test Conventions (TC):**
- **TC-6** — to regression-test a PA-7 `addcslashes( …, '\\$' )` guard (data-bearing `preg_replace` replacement), the input must carry a literal `$`+digit; assert it survives verbatim (without the guard `$1` resolves to the capture group). Note JSON doubles `\`, so assert on the `$n` form, not `\n`.
- **TC-7** — to test a handler that calls `wp_die()`/`exit` and then continues, stub it to throw (`Functions\expect('wp_die')->…->andThrow(\RuntimeException::class, 'wp_die')`), catch it, and assert the *post-halt* side effect never ran (e.g. `update_user_meta` count 0). Proves the gate actually stops execution.
- **TC-8** — to unit-test a method coupled to the `Plugin` singleton chain (`Plugin::instance()->frontend()->datalayer()`), build the intermediates with `ReflectionClass::newInstanceWithoutConstructor()`, set their private props via reflection, install the singleton with a `ReflectionProperty` on `Plugin::$instance`, and **reset it to `null` in tearDown** (TS-7). Seed `DataLayer`'s private `compiled` directly rather than running the compile filter.
- **TC-9 (JS)** — to test a side-effect frontend tracker (`js/frontend/*.js`): set the bare globals it reads (`global.gtm4wp_datalayer_name = 'dataLayer'`), `import '../tracker'` for its side effects, reset `window.dataLayer = []` per test, build a jsdom fixture and dispatch a **bubbling** event, then assert the `window.dataLayer` push. For the window-attached helper API (ecommerce-generic), import once then call `window.gtm4wp_*`. Tests live in `js/frontend/test/`; run `npm run test:unit` + `npm run lint:js` (no `npm run build` — test files are not bundled).
- **TC-1** — every security-relevant code change ships a PHPUnit regression test in the same change (shared rule with `.security/`; the guard tests live in `tests/unit/Frontend/`).
- **TC-2** — the Brain Monkey `wp_json_encode` stub honors the JSON flags, so build the expected encoded output by calling `wp_json_encode(...)` the way the source does — never hand-type `\uXXXX` literals.
- **TC-3** — extend the right base: `FrontendTestCase` for services that read `Options` (it provides the Options factory + global reset); the plain `TestCase` for pure/static helpers with no Options dependency (`VisitorIp`).
- **TC-4** — assert hook registration via `has_action`/`has_filter` (they return the priority integer), and prove **both** the enabled and the disabled state (the `ModuleHooksTest` gate pattern).

**Blessed Exceptions (BE) — do NOT flag:**
- **BE-4** — snapshotting `$_POST`/`$_GET` in a test `setUp` (for TS-7 isolation) trips `WordPress.Security.NonceVerification.Missing` (a phpcs *error*, not warning). Suppress with a scoped `// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test isolation snapshot; the handler's own nonce check is asserted`.
- **BE-1** — byte-exact `<script>` string assertions in `ContainerCodeTest`/`ConsentDefaultsTest` are intentional (1.x-parity port); this coupling is deliberate there, not a TS-9 smell.
- **BE-2** — unused `$handle`/`$position` params in `wp_add_inline_script` stub closures match the existing test style; phpcs warns, accepted.
- **BE-3** — some classes are intentionally not unit-tested (interfaces, the `Frontend` orchestrator, `Autoloader`); record `[-]` N/A in the checklist — not a gap.

---

## Test Smells

### TS-1: A covered line is not an asserted behavior ⭐
Line/branch coverage tells you a line *executed*, not that the test would *fail if
the line were wrong*. The classic trap here: a sink is exercised with benign data,
so coverage is 100 % green, yet the security/edge behavior is unasserted.

Confirmed 2026-07-13: `DataLayer::flush_pushes()` was fully line-covered by
`test_flush_pushes_adds_inline_script_and_resets_queue` (data `value => 42`), but
no test proved a hostile value (`</script>`, `"`, `&`) is hex-encoded. Added
`test_flush_pushes_hex_encodes_script_breakout_characters`. When you see a covered
output sink, ask "with *what* input?" — if only benign data, the security case is a
gap. This is the single failure mode a coverage tool cannot see; it is the review's
core value. Mutation testing (Infection, optional) surfaces the same class
mechanically: mutate the flag, does a test die?

### TS-2: Escaping tests need both directions ⭐
An XSS/escaping guard must assert the **safe** form is present **and** the **raw**
break-out is absent. Asserting only "the encoded value appears" misses a case where
the raw value *also* appears elsewhere; asserting only "the raw char is gone" misses
data corruption. The `tests/unit/Frontend` guards do both:
`assertStringContainsString($safe_fragment)` + `assertStringNotContainsString('</script>')`.
Prefer building `$safe_fragment` via `wp_json_encode(...)` (TC-2), not a literal.

### TS-3: Asserting the call, not the effect
A test that asserts a function was *invoked* (a script registered, a hook added,
`wp_add_inline_script` called) but not its *observable effect* under-specifies the
contract. Confirmed 2026-07-13: `enqueue_push_handle()` asserted register + enqueue
but not that it *also flushes* the queue; strengthened to queue an event and assert
it was flushed and the queue reset. When a method does N things, assert N.

### TS-4: Tautological tests
A test that stubs `foo()` to return `X` and then asserts the result is `X` exercises
the stub, not the code. Watch for `Functions\when(...)->justReturn($x)` immediately
followed by an assertion on `$x`. Assert something the *code under test* computed,
transformed, or routed — not the mock's own echo.

### TS-5: Happy-path only
Every branch that can go wrong deserves a case: empty input, invalid input, the
fallback/else, the boundary. Confirmed 2026-07-13: `container_domain()` had both a
valid and an invalid-fallback test, but `container_path()` had only the valid path —
the `gtm.js` fallback for an invalid custom path (a PA-2 output-sink validation) was
untested. Symmetry across sibling validators is a good heuristic: if one has a
negative test, the others should too.

### TS-6: A whole class with zero tests ⭐
The cheapest high-value gap. Confirmed 2026-07-13: `Frontend/VisitorIp` — a
security-sensitive `$_SERVER`/custom-header IP reader with spoof-rejection logic —
had **no** test file at all. Detect mechanically first: for each `src/**/*.php`,
check for a matching `tests/**/*Test.php` (or documented indirect coverage). The
sweep over-reports (interfaces, modules covered only via `ModuleHooksTest`) — the
judgment layer classifies each candidate as real gap / indirectly covered / N/A.

### TS-7: State leakage between tests
Superglobals (`$_SERVER`, `$_GET`, `$_COOKIE`), `$GLOBALS['gtm4wp_*']`, statics and
singletons persist across tests unless reset. A test that sets them must snapshot in
`setUp` and restore in `tearDown` (see `VisitorIpTest`'s `$_SERVER` snapshot and
`FrontendTestCase`'s `unset($GLOBALS['gtm4wp_*'])`). A test that passes alone but
fails in-suite (or vice versa) is the tell.

### TS-8: Non-determinism
No reliance on wall-clock time, randomness, or the order tests run in. The codebase
already mocks time-dependent WordPress functions; a new test needing "now" or an ID
must inject a fixed value, not read the real clock. Order-dependence is usually a
TS-7 leak in disguise.

### TS-9: Over-coupling to rendered output
Asserting a full exact string where the intent is behavioral makes the test brittle:
an innocuous whitespace/attribute-order change breaks it without a real regression.
Prefer `assertStringContainsString` on the load-bearing fragment, or a structural
assertion. **Exception:** BE-1 — the 1.x-parity script blocks are deliberately
byte-exact.

### TS-10: Untested public method or branch of a covered class
A class with a test file can still have unguarded surface. Confirmed 2026-07-13 in
`ContainerCode`/`DataLayer`: `compiled()`, `name()`'s non-string fallback,
`header_top($echo=false)`, `body_open()`/`footer()`/`the_tag()` placement routing,
`register_hooks()` LOADEARLY priority, and `rocket_excluded_inline_js_content()`
were all unguarded despite full test files existing. Enumerate a class's public
methods and each conditional branch; map them to assertions.

---

### TS-11: Upstream raw-passthrough contract
A value that a module hands to a *shared downstream sink* (the `wp_json_encode`
hex-flag dataLayer/inline-script path) must arrive **raw** so the sink can escape
it once and correctly (RI-4). The regression that guards this lives at the module
boundary, not the sink: assert the special-character value is present **raw** and
absent in entity-encoded form. Confirmed 2026-07-13: `geoCloudflareCountryCode`
was exercised only with `'HU'` — a value with no special characters — so a
re-introduced `esc_js()`/`esc_attr()` pre-escape (finding #12 / RI-4 data
corruption) would pass unnoticed with green coverage. The fix uses a hostile
`A&"<B` header and asserts `assertSame('A&"<B', …)` **plus**
`assertStringNotContainsString('&amp;'/'&quot;'/'&lt;', …)`; because the test case
calls `stubEscapeFunctions()` (real `htmlspecialchars`), a re-added `esc_js` makes
the assertion fail. This is TS-1 applied one hop upstream of the sink — check it
for every module field that feeds the dataLayer with request/header data.

## Project-Specific Test Conventions

### TC-1: A security-relevant change ships a regression test
Shared with `.security/`. Any change to an output-escaping path, an input
sanitizer, a nonce/capability check, or a container-ID/domain/path validator lands
with a PHPUnit test that would fail if the guard were removed. The canonical
examples are in `tests/unit/Frontend/` (the `does_not_decode_*` /
`hex_encodes_*` guards). This convention is what keeps the two review systems in
sync: `.security` flags the sink; `.testing` owns the guard's existence and quality.

### TC-2: Build expected encoded output the way the source does
`FrontendTestCase` aliases `wp_json_encode` to real `json_encode($data, $flags)`,
so the JSON hex flags are honored in tests. Compute the expected safe string with
the same call the source uses —
`wp_json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_HEX_APOS)`
— and assert on that. Do **not** hand-type `<`-style literals: they are
error-prone (tooling may re-decode them) and drift from the source's flag set.
See `ContainerCodeTest::test_header_begin_does_not_decode_html_entities_in_datalayer_values`.

Corollary for the *hostile input itself*: write break-out characters with `\xNN`
escapes in a double-quoted string (`"x\x22y\x26z"` for `x"y&z`) so no literal
`<`/`"`/`&` appears in the test source, then compute the expected encoded fragment
with `json_encode( $value, $flags )` (trimming the surrounding quotes). This keeps
the assertion in lock-step with the source's flags and sidesteps editor/tooling
that HTML-encodes literal break-out chars. See `AmpModuleTest::encoded_fragment()`.

### TC-3: Extend the right base test case
- `GTM4WP\Tests\unit\Frontend\FrontendTestCase` — services that read `Options`
  (DataLayer, ScriptTag, ContainerCode, ConsentDefaults). Provides `make_options()`
  and resets the `gtm4wp_*` globals per test.
- `GTM4WP\Tests\unit\TestCase` (base) — pure/static helpers with no Options
  dependency (`VisitorIp`, `ContainerRows`). Stub only what the unit touches
  (`wp_unslash`, `sanitize_text_field`), keeping the harness minimal.

### TC-4: Assert hook wiring both ways
Use `has_action`/`has_filter` — they return the registered **priority** integer, so
`assertSame(2, has_action('wp_head', [$obj, 'm']))` pins the priority, and
`assertFalse(has_action(...))` proves a gate is closed. Every conditional hook
registration gets an enabled test **and** a disabled test (the `ModuleHooksTest`
pattern). This is the accepted way to test `register_hooks()`/`frontend()` wiring
without booting WordPress.

---

## Blessed Exceptions

### BE-1: Byte-exact `<script>` assertions (1.x parity)
`ContainerCodeTest` and `ConsentDefaultsTest` assert exact inline-script strings on
purpose: 2.0 ports these blocks to be byte-identical to 1.x, and the exact-string
assertion is the contract. Do not "modernize" these into loose behavioral
assertions or flag them as TS-9.

### BE-2: Unused stub-closure parameters
Closures passed to `Functions\when('wp_add_inline_script')->alias(...)` declare
`($handle, $code, $position)` and use only `$code`. phpcs emits an unused-parameter
warning; it matches the existing style in `DataLayerTest` and is accepted (the full
signature documents what the stub receives).

### BE-3: Intentionally untested classes
Not every `src/` class warrants a unit test. Interfaces (`ModuleInterface`,
`AdminSchemaInterface`) and abstract contracts are exercised through their concrete
implementations and `ModuleConsistencyTest`. The `Frontend` orchestrator, `Plugin`
singleton boot, and `Autoloader` are integration-level wiring whose unit test would
be mostly stub-assertion (TS-4) — record them `[-]` N/A with a reason, not as a
gap. Blessing "not tested, and here is why" is a first-class outcome; it prevents
coverage-chasing junk.

---

## Changelog

| Date | Action |
|---|---|
| 2026-07-13 (Run 1 continued) | Full remaining-gap sweep. Added **TC-8** (reflection injection for `Plugin`-singleton-coupled code — AMP finding #11), **TC-9** (JS side-effect tracker harness under `js/frontend/test/`), and extended **TC-2** with the `\xNN` + `json_encode`-computed-expected corollary (from the AMP test, after tooling HTML-encoded literal break-out chars). Derived from closing T9/T11/T12/T13(AMP)/T14 and starting T10 (JS). |
| 2026-07-13 (Run 1) | Module/admin security-sink pass. Added **TS-11** (upstream raw-passthrough contract — the `geoCloudflareCountryCode` benign-only sink), **TC-6** (PA-7 `addcslashes` regression needs a `$n` input; JSON doubles `\`), **TC-7** (test `wp_die`/exit handlers by stubbing to throw + asserting no post-halt side effect), **BE-4** (`$_POST` snapshot in test setUp trips NonceVerification — scoped `phpcs:ignore`). Derived from closing T7 (`ListTrackingTest`, finding #16), T8 (`NoticesTest`, finding #18) and the PageVariables finding-#12 regression. |
| 2026-07-13 | Seeded the patterns file from the `tests/unit/Frontend/` coverage review. Added TS-1..TS-10 (covered-≠-asserted, both-direction escaping, assert-the-effect, tautological, happy-path-only, zero-test class, state leakage, non-determinism, over-coupling, untested method/branch), TC-1..TC-4 (regression-with-change, build-expected-encoding, right base case, hook-both-ways) and BE-1..BE-3 (1.x byte-exact blessing, unused stub params, intentionally-untested classes). Derived from the session that added `VisitorIpTest` and 31 Frontend tests + fixed the OFF-placement iframe leak. |
