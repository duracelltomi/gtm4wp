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
- **TS-14** — an assertion deliberately loosened (a comment saying "not an exact count", "shape via find()", a workaround for "leaked listeners") may be routing around a *production* defect rather than a harness quirk. A comment explaining why an assertion is weak is a review lead, not housekeeping.
- **TC-5** — every request/header-sourced dataLayer field (a `.security` PA-3 sink: `?s=`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, cookies, `$_SERVER`) ships a hostile-input regression test. This is the intersection of the two review systems.
- **TS-6** — a whole class with **zero** tests is the cheapest, highest-value find. Run the missing-test-file sweep first every review.
- **TS-12** — an authorization gate (a `permission_callback`, a `current_user_can()` check, a filterable capability like `gtm4wp_admin_page_capability`) is a test surface in its own right: it needs a **grant + deny** test and, if filterable, a test that the filter changes the required capability while the default stays unchanged. The XSS/output-sink lens (TS-1/TS-2/TC-5) never prompts for it, so an untested gate hides inside a component the matrix already marks `[x]`.
- **TS-15** — the only proof that a guard is tested is **deleting the guard and watching a test go red**. "The method has a test file", "the line is covered", "a finding forced a test here" are all compatible with a fix nothing asserts. Revert-and-run the highest-value guards every review; it is the one check no tooling in this project performs.
- **TS-16** — a green suite is **not** evidence of test isolation. When the mocking framework defines functions **process-wide and permanently** (Brain Monkey does), one file's stub silently satisfies another file's missing one, and the dependency is invisible in declaration order. `--order-by=random` is a one-flag check that no other signal in this project performs.
- **TS-13** — a test double must be **no more capable than the real collaborator**: if the mock does the safe thing the real dependency does *not* (returns a live object the real one returns null for; leaves an element the real SDK replaces; exposes a property the real object hides behind `__get`), the failure it would cause is invisible and the suite stays green over a real bug.
- **TS-17** — the *environment* absorbs couplings too, and leaves no double to interrogate. jsdom's `global === window` makes a window property satisfy a bare-identifier read, so the suite cannot tell the two binding kinds apart and passed identically over three features that shipped dead. Ask the TS-13 question **of the harness**; where it cannot be made faithful, move the guard to a tool that can see it (ESLint) and record the blind spot.

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
- **TC-9 (JS)** — to test a side-effect frontend tracker (`js/frontend/*.js`): set the bare globals it reads (`global.gtm4wp_datalayer_name = 'dataLayer'`), `import '../tracker'` for its side effects, reset `window.dataLayer = []` per test, build a jsdom fixture and dispatch a **bubbling** event, then assert the `window.dataLayer` push. For the window-attached helper API (ecommerce-generic), import once then call `window.gtm4wp_*`. For a **load-time detection** tracker that reads `navigator` (`client-device-data`), override `navigator.userAgent`/`userAgentData` with `Object.defineProperty(window.navigator, …, {value, configurable:true})` **before** each fresh `jest.isolateModules(() => require('../tracker'))` (the IIFE re-runs against that state; always set both so nothing leaks — TS-7), and `await` a macrotask flush (`new Promise(r => setTimeout(r, 0))`) for the Client-Hints `getHighEntropyValues().then/catch` chain; assert the reject branch still pushes and the no-`navigator` case does not throw and does not push. Tests live in `js/frontend/test/`; run `npm run test:unit` + `npm run lint:js` (no `npm run build` — test files are not bundled).
- **TC-10 (JS)** — to test a tracker that wraps an **external player SDK global** (`YT.Player`, `Twitch.Player`, `SC.Widget`, VideoPress postMessage): stub the SDK global with a fake constructor that **captures** the events/handlers config the tracker registers, load via `jest.isolateModules(() => require('../tracker'))`, then **drive the captured handlers** with a mock player exposing `getCurrentTime`/`getDuration`/`getVideoData` (or emit a `MessageEvent`). Use `jest.useFakeTimers()` + `advanceTimersByTime` for interval-polled progress, and assert missing-SDK / no-embed = no push, no throw. This is the concrete recipe for closing the `youtube` gap (T17).
- **TC-11 (JS)** — a JS tracker's `window.dataLayer.push({...})` is a **structured object sink**, not an HTML string sink: the `</script>`-into-`<script>` output-encoding lens (TS-1/TS-2/TC-5) does **not** apply at the push site, so do not flag a JS tracker for "missing hostile-input encoding test." The real untrusted-input surface for a JS tracker is a **message/origin boundary** (e.g. VideoPress `postMessage` origin validation) — that validator gets a both-directions test (accept the legit hosts, reject spoofed origin + malformed payload). A raw-passthrough assertion (guid `</script>&x` present verbatim, no entity-encoding) is a mild belt-and-suspenders documentation of intent, welcome but not required.
- **TC-12** — an admin inline-`<script>` hex sink whose `wp_json_encode(..., HEX_FLAGS)` lives in the **enqueue method** (not the data-builder) is regression-tested by driving the enqueue method and capturing `wp_add_inline_script` with a hostile stored field value; pick a payload with `<>"&'` so dropping any one of the four flags fails. A raw-passthrough test on the data-builder alone does not guard the flags.
- **TC-1** — every security-relevant code change ships a PHPUnit regression test in the same change (shared rule with `.security/`; the guard tests live in `tests/unit/Frontend/`).
- **TC-2** — the Brain Monkey `wp_json_encode` stub honors the JSON flags, so build the expected encoded output by calling `wp_json_encode(...)` the way the source does — never hand-type `\uXXXX` literals.
- **TC-3** — extend the right base: `FrontendTestCase` for services that read `Options` (it provides the Options factory + global reset); the plain `TestCase` for pure/static helpers with no Options dependency (`VisitorIp`).
- **TC-4** — assert hook registration via `has_action`/`has_filter` (they return the priority integer), and prove **both** the enabled and the disabled state (the `ModuleHooksTest` gate pattern).
- **TC-13** — the Brain Monkey recipe for a TS-12 capability gate: simulate the filter with `Filters\expectApplied( 'gtm4wp_admin_page_capability' )->andReturn( 'custom_cap' )` (omit it for the default-unchanged case — `apply_filters` passes the default through), assert `current_user_can` is called `->with()` that cap for grant/deny, and capture the `add_options_page()` 3rd arg for the menu/render gate (`AdminCapabilityFilterTest`).
- **TC-15 (JS/admin)** — an admin React component is tested with `@testing-library/react` against the local `@wordpress/*` stand-ins in `js/admin/test-support/` (mapped in `jest.config.js`), never against an installed `@wordpress/components`: those packages are build-time externals, so no installed version is "the real one". Assert a guard through its **effect** (`onChange` not called), never through a rendered `disabled`/`readOnly` prop.
- **TC-14** — code gated on a WordPress conditional tag that then reads the companion global (`is_singular()` → `$GLOBALS['post']`) ships a tag-true/global-null case: promote warnings to failures via a throwing error handler, assert the affected keys are **absent** (omission, not placeholders) and the global-independent keys still emit.
- **TC-16 (JS/frontend)** — a global the PHP side prints as a top-level `const` is tested by injecting a real classic `<script>` (jsdom runs it synchronously, creating a true lexical binding) and planting the **opposite** value on `window.<name>` as a decoy, in its own test file. Indirect `eval` cannot substitute — measured; `let`/`const` never escape eval's declarative environment.

**Blessed Exceptions (BE) — do NOT flag:**
- **BE-4** — snapshotting `$_POST`/`$_GET` in a test `setUp` (for TS-7 isolation) trips `WordPress.Security.NonceVerification.Missing` (a phpcs *error*, not warning). Suppress with a scoped `// phpcs:ignore WordPress.Security.NonceVerification.Missing -- test isolation snapshot; the handler's own nonce check is asserted`.
- **BE-1** — byte-exact `<script>` string assertions in `ContainerCodeTest`/`ConsentDefaultsTest` are intentional (1.x-parity port); this coupling is deliberate there, not a TS-9 smell.
- **BE-2** — unused `$handle`/`$position` params in `wp_add_inline_script` stub closures match the existing test style; phpcs warns, accepted.
- **BE-3** — some classes are intentionally not unit-tested (interfaces, the `Frontend` orchestrator, `Autoloader`); record `[-]` N/A in the checklist — not a gap.

---

## Test Smells

### TS-14: The suite adapted its assertions AROUND a defect instead of failing on it ⭐
Worse than an untested behavior is a *tested* one whose assertions were reshaped to
tolerate the bug. The suite then stays green **because** of the defect, and every
future reader takes the accommodation for intended behavior. TS-1 asks "covered with
what input?"; this asks **"why is this assertion shaped so defensively?"**

Confirmed 2026-07-30 while fixing #71 (two bundles missing the double-init guard).
The WooCommerce tracker suite carried this comment:

> *"prior describes in this file also leaked onto the shared document; each leaked
> copy reads the same DOM/globals and pushes an identical event, so these assert the
> pushed shape via find() (not an exact count)"*

That is a precise description of double-pushing — the exact production symptom #71
names — recorded as a test-harness quirk and routed around by asserting shape rather
than count. The information needed to find the bug was written down in the suite,
in prose, and read as housekeeping. Three further tests in the same file pinned the
string `quantity: '3'` / `'2'` that finding #79 is about, so the defect was asserted
as the expectation in two independent places.

**Rules:**
- **An assertion that avoids counting, ordering or identity is a question, not a
  convention.** When a test says "not an exact count", "shape via find()", "order
  independent" or similar, ask what would break if it *did* assert precisely — and
  whether that thing is a production defect rather than a harness artifact.
- **Test-harness leakage and production double-binding look identical from inside a
  test.** Before writing a workaround comment, check whether the same shape is
  reachable in a browser (a re-injected bundle, an AJAX navigation, a page builder
  duplicating a handle). If it is, the workaround is hiding a finding.
- **A comment explaining why an assertion is weak is a review lead.** Grep the suite
  for such comments during a `/test-review` pass; they mark the places where someone
  already saw the symptom and did not recognize it.
- Related: **BE-1** blesses byte-exact assertions that are *deliberate*; this smell
  is the opposite — assertions deliberately loosened to keep a defect green.

### TS-15: Only a revert proves a guard is tested ⭐
TS-1 asks *"covered with what input?"*. This asks the blunter question: **if I delete
this guard, does anything go red?** Every other signal this project has is compatible
with the answer being "no":

- *"The class has a test file"* — file-granular; the missing-test sweep (TS-6) counts
  the whole file as covered no matter which method the fix lives in.
- *"The line is covered"* — a coverage driver reports an unexecuted branch inside an
  otherwise-green method as one uncovered line among hundreds, and reports an
  under-asserted gate as fully covered.
- *"A finding forced a test here"* — the test the finding forced may assert the
  *outcome* while the guard's actual content (which capability, which sanitizer, which
  collaborator state) is supplied by a stub.

Confirmed twice in Run 5 (2026-08-05), both found only by reverting:

1. The fix for a **High** security finding was deleted outright and the suite stayed at
   **706/706 with an identical assertion count**. Cause: TS-13 — every test double
   supplied the collaborator state whose absence *is* the bug, so the remedy branch had
   never executed. The checklist had recorded "regression test does not stub X wholesale"
   as though the test existed.
2. A capability gate was downgraded to a weaker capability **and its filter removed**, and
   its component's 17 tests stayed green — because grant/deny was asserted through a
   `justReturn` stub that pins no capability at all (TS-12/TC-13).

**Rules:**
- On every review, pick the highest-value guards in range — the fixes for High/Medium
  security findings, and every authorization gate — and **revert each one in the working
  tree, run the suite, restore**. It costs a minute per guard and is the only check that
  can distinguish a test from a covered line. Restore byte-exact and confirm with
  `git status --porcelain`.
- A ledger sentence asserting a test exists ("regression test does not stub…",
  "read-only property is test-pinned") is a **claim to verify**, not evidence. Grep for
  the test; if you cannot name the assertion that would fail, it does not exist.
- Mutation testing (Infection) is the mechanical form of this pattern and would catch
  case 1 automatically. It catches case 2 only if the mutation operator reaches the
  capability *string*, so the manual revert still earns its place.

### TS-16: A green suite is not evidence of isolation — check the order ⭐
TS-7 asks whether a test *resets* the state it writes. This asks the question one
level up: **would the suite still pass if the tests ran in a different order?** The
two are not the same, and the second is invisible to every other signal here —
including a full read of the test file, because the missing stub is supplied by a
*different* file entirely.

The mechanism is specific to this project's harness and worth stating plainly:
**Brain Monkey / Patchwork define a mocked function process-wide and permanently.**
Once *any* test calls `Functions\when( 'foo' )`, `function_exists( 'foo' )` is true
for the rest of the run. So a test that never stubs `foo` still passes, as long as
some earlier file did. Nothing in the file under review hints at the dependency.

Confirmed 2026-08-05 (Run 6), found by a single flag on an otherwise unchanged,
763-green suite:

- `vendor/bin/phpunit --order-by=random` → **13 errors**. Twelve tests in
  `RestControllerTest`/`SettingsPageTest` need `wp_get_environment_type` (reached
  via `Container/AdminSchema.php:430` when they build the real
  `Registry::with_default_modules()`); one needs `get_multiple_authors`. Neither
  file stubs them; `ContainerCodeTest`, `ContainerAdminSchemaTest` and
  `ModuleConsistencyTest` do.
- **Three of the thirteen are security regression guards** — the settings-import
  hostile-payload test, the hostile-container-ID rejection, and the T13/finding-#11
  admin hex-sink guard. Their execution depends on file ordering.
- Each file still passes **alone**, so the usual "run the file on its own" check
  says nothing. The dependency is *within-file test order* plus cross-file stub
  leakage, and only shuffling exposes it.

**Rules:**
- **Run `--order-by=random` every review.** It is one flag, costs one suite run, and
  is the only mechanical check in this project that can find this class. Record the
  seed so a failure is reproducible.
- **A test must stub every function it reaches, in its own `setUp`** — even when the
  suite is green without it. "It passes" is compatible with "it borrows another
  file's stub."
- **Do not confuse this with the blessed limitation.** That the
  `function_exists( … ) === false` *fallback leg* cannot be exercised in-process is a
  real, documented constraint (Run 5 recorded it for `UserEvents`; see the NOTE at
  `PageVariablesModuleTest.php:1556`). Documenting an untestable branch is correct.
  Relying on the same stickiness for a branch you *do* test is the smell.
- Mutation testing cannot be adopted until this is fixed — Infection reorders and
  re-runs tests, so an order-dependent suite reports noise.

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

**JS variant (confirmed 2026-07-15, Run 4).** A frontend tracker that attaches a
**`document`-level delegated listener at module load** (`gtm4wp-woocommerce.js`
delegated `click`, `gtm4wp-woocommerce-blocks.js`) leaks it across
`jest.isolateModules`/`require` reloads: the wp-scripts jest preset shares one jsdom
`document` and never resets it, and the listener is never removed — so one dispatched
event fires **every prior test's** handler too. It stays invisible when assertions use
`window.dataLayer.find(...)`, but it means those tests do **not** prove "fired exactly
once" and any `toHaveBeenCalledTimes`/exact-count assertion becomes order-dependent
(a probe saw a second test double-push `remove_from_cart`). Fix: capture and remove the
delegated listener in `afterEach` — the CheckoutWC `describe` in
`woocommerce-tracker.test.js` already does this for its `cfw_step_changed` listener, so
apply the same cleanup to the module-load `click` listeners, then the click-driven
tests can safely assert exact counts.

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

### TS-12: Authorization/access-control gates are their own test surface ⭐
A `permission_callback`, a `current_user_can()` gate, or a filterable required
capability is a security control, but the review's XSS-first lens (TS-1/TS-2/TC-5,
all about *output* sinks) never prompts for it — so an untested gate hides inside a
component the matrix already marks `[x]`. Two things must be asserted, or the guard
can be removed without a test going red:

1. **Grant AND deny.** Drive the gate with a capable and an incapable user and
   assert access is allowed vs refused (for a handler that `wp_die()`s, use the
   TC-7 throw-and-observe form: stub `wp_die` to throw and assert the post-halt
   side effect never ran).
2. **The filter actually customizes the capability.** For a filterable cap
   (`gtm4wp_admin_page_capability`, default `manage_options`), assert (a) the
   default is checked when the filter is absent — so nothing changes unless
   filtered — and (b) a filtered custom cap is the one passed to
   `current_user_can()` / `add_options_page()`, granting or denying accordingly.

**Sweep for the sites, don't close the ones a finding named (added Run 5, 2026-08-05).**
The #143 fix below closed the two sites *the issue mentioned* and the canonical test
says so in its own docblock ("the two enforcement sites the issue names"). A grep three
weeks later found **four**: the gate in `Plugin::boot()` that decides whether the admin
UI loads at all had no test, and the `Notices` gate had grant+deny that pinned no
capability. A gate closed at the sites a finding named is a fix, not a sweep — run
the grep in § Review Scope A.4 every time and reconcile it against the test file, and
prove each site by revert (TS-15), not by reading.

Confirmed 2026-07-15 (issue #143): the `gtm4wp_admin_page_capability` filter gates
`RestController::can_manage()` (the REST `permission_callback`) and
`SettingsPage::add_admin_page()` (the `add_options_page()` capability argument =
the Settings submenu **and** the page render guard), yet **no** test executed
either method — the unit tests call the REST handlers directly, bypassing
`permission_callback`, so even a coverage driver would have shown `can_manage()` at
0%. `NoticesTest` had a deny-direction test only because a *finding* (#18) forced
it; the settings/REST gate had no finding, so nothing demanded one — the pattern of
the miss. Closed by `tests/unit/Admin/AdminCapabilityFilterTest.php`. Recipe:
TC-13. How the flow was hardened so a future run catches this class: the
**Access-control coverage** Test Debt Sweep in the checklist.

### TS-13: A test double more capable than the real collaborator hides the bug ⭐
The dual of TS-1: TS-1 is about the *input* (benign vs hostile); this is about the
*collaborator*. A mock that does the safe thing the real dependency does **not** makes
the failure it would cause invisible, so the suite stays green over a real bug. This
is not hypothetical — in the 2026-07-17 security review, **three of the four most
serious findings were each masked by exactly this**, and each fix had to change the
double, not just add an assertion:

- **#33** (WC session on REST): tests stubbed `WC()` **wholesale**, always returning a
  live `->session`. The real `WC()->session` is *null* on a REST route (PA-11), so the
  one-shot resolvers silently returned nothing in production while every test passed.
  The regression test must NOT stub `WC()` into existence for the endpoint path — it
  must exercise the null-session path (or an integration test).
- **#40** (Spotify observer loop): the SDK fake `createController` was a no-op that left
  the element in place. The real SDK **replaces** the element (PA-9), which is what
  causes the unbounded re-wire. The fix: the fake must perform the real `replaceChild`
  + synchronous `src` assignment, and a `SPOTIFY_CAP` turns the loop into a failing
  count assertion rather than a hung run.
- **#43** (PublishPress `__isset`): author fixtures were plain `stdClass` with real
  properties, where `isset()` always works. Real PublishPress `Author` objects resolve
  via `__get()` and may lack `__isset()` (RI-12), so `isset()` reports false and blanks
  the value. The regression test needs a `__get`-without-`__isset` stub.

**Rule while writing a double:** ask *"what does the real thing do here that my fake
doesn't?"* — for an external SDK, a WP/WC core object, or a third-party class, model
the one behavior the bug depends on (a null return, an element replacement, a magic
accessor), not just the happy shape. **Rule while reviewing:** when a security/correctness
finding turns on a collaborator's real behavior, check whether the test double reproduces
it; a green test over a stubbed-away contract is not coverage.

### TS-17: The test *environment* can absorb a coupling, with no double to interrogate ⭐
TS-13's rule — *"what does the real thing do here that my fake doesn't?"* — only fires
when there is a fake to look at. Sometimes nobody wrote one and the **realm itself** is
more permissive than the browser, so the distinction the bug turns on does not exist
inside the harness. There is no over-capable stub to find, which is why TS-13's prompt
misses it entirely.

**Confirmed 2026-08-06.** In jest's jsdom environment `global === window`, so
`window.gtm4wp_list_attribution = 1` in a fixture *also* satisfies a bare-identifier
read. In a real browser those are two different bindings: the PHP side prints the
inline globals as a top-level `const`, which is lexical and never a window property
(RI-14). Every existing case set the window property, so the suite passed identically
whether the tracker read `window.<name>` or the bare name — and three features shipped
dead behind that green suite, one of them inherited from released 1.x. Nothing was
stubbed; the environment simply cannot represent the difference.

Same shape, other harnesses: `document`/`window` shared across a whole test *file* (a
real page gets a fresh one per load), Brain Monkey defining a mocked function
process-wide and permanently (TS-16), and Node globals that a browser lacks.

**Rules:**
- When a bug turns on a *host-environment* behavior rather than a collaborator's, ask
  the TS-13 question **of the environment**: what does a real browser/request do here
  that jsdom / Brain Monkey / the shared document does not? If the harness cannot
  represent the difference, an ordinary test is not evidence — say so rather than
  counting the file as covered.
- Reproduce the real mechanism instead of describing it. Here that is
  `js/frontend/test/inline-head-globals.test.js` (recipe **TC-16**): inject a genuine
  classic `<script>` to create real lexical bindings, then plant the **opposite** value
  on `window.<name>` as a decoy, so correct and regressed code cannot both pass.
- Where the harness genuinely cannot be made faithful, move the guard to a tool that
  can see it — the `no-restricted-properties` rule in `.eslintrc.js` is the static half
  of this one — and record the blind spot rather than leaving a green suite to imply
  coverage.
- **TS-15 is what catches this in practice:** revert the fix and watch the new cases go
  red. All 7 were watched failing on the unfixed source before being trusted; had the
  harness still been absorbing the coupling, they would have stayed green and said so.

**A second instance, and this one makes the environment test the WRONG TREE
(2026-08-12, `.security` #177).** `/code-review` now runs verifiers that patch files in
an isolated `git worktree`. A worktree has no `vendor/`, and Composer's generated
autoloader hardcodes the **absolute** install path — so with `vendor/` symlinked or
junctioned in from the main checkout, `vendor/bin/phpunit` run inside the worktree loads
the **main checkout's** `src/` while reporting the worktree's `phpunit.xml`. Two
verifiers hit this independently in one run; both got a fully green suite that was
measuring code they had not edited, and one caught it only by printing
`(new ReflectionClass( … ))->getFileName()`.

This is TS-17's worst variant because it fails **green in the direction of reassurance**:
the probe appears to prove a proposed fix safe. The rule is the same shape as the one
above — interrogate the environment, do not describe it: before believing any suite run
in a worktree, `composer install` (or `composer dump-autoload`) **there**, and assert the
class under test resolves to a path inside the worktree.

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

### TC-12: Admin inline-`<script>` hex-sink capture harness
When a hex-flag `<script>` sink lives in an **enqueue method** (the
`wp_json_encode(..., HEX_FLAGS)` is at the `wp_add_inline_script` call site, not in
the data-builder it wraps — e.g. `SettingsPage::enqueue_assets()` vs.
`bootstrap_data()`), the regression test must drive the **enqueue method** and
capture the inline script; a raw-passthrough test on the data-builder alone does
NOT guard the flags. Recipe (confirmed 2026-07-14, `SettingsPageTest`, T13):

1. Build the real `Registry::with_default_modules()` and share it with the
   `RestController` (exactly as `Plugin` wires them). Building every module schema
   needs the `RestControllerTest` stub set (`stubTranslationFunctions` +
   `stubEscapeFunctions` + `wp_kses`/`sanitize_text_field`/`sanitize_key`/
   `get_object_taxonomies`/`wp_roles`/`translate_user_role`).
2. Stub `get_option` to seed a **hostile value at a real settings field** — a
   container `domain` is admin free-text that reaches the sink un-sanitized (only
   the REST *save* path sanitizes; `current_values()` returns the raw stored value).
3. Stub the enqueue machinery as no-ops (`wp_enqueue_script`/`wp_enqueue_style`/
   `wp_set_script_translations`/`wp_style_add_data`/`plugins_url`) and capture
   `wp_add_inline_script`. Do **not** stub `is_file` — it is a PHP internal
   Patchwork can't redefine, and the inline script fires whether the build asset
   exists or not, so the assertion doesn't depend on it.
4. Pick a payload that exercises **all four** flags (`</script>"&'` →
   `\x3C/script\x3E\x22\x26\x27`) so dropping ANY of JSON_HEX_TAG/AMP/QUOT/APOS
   changes the encoded fragment and fails the test; assert the fragment (computed
   with `json_encode($v, HEX_FLAGS)`, TC-2) present + raw `</script>` absent. Also
   cover the wrong-`$hook` early return (TS-5). Proven by a throwaway probe:
   dropping `JSON_HEX_APOS` from the source failed the test.

### TC-10: Player-SDK tracker harness (capture-and-drive)
The media trackers wrap an external player SDK (`YT.Player`, `Twitch.Player`,
`SC.Widget`) or a `postMessage` stream (VideoPress). The blessed way to unit-test
them (confirmed 2026-07-13 across `twitch`/`soundcloud`/`videopress` tests):

1. Stub the SDK global with a fake **constructor** that stores `this` and captures
   the handlers the tracker registers — e.g. `Ctor.prototype.addEventListener =
   function (e, cb) { this.handlers[e] = cb; }`, plus `getCurrentTime`/`getDuration`/
   `getVideoData` accessors the tracker reads. Expose the SDK's event-name
   constants (`Ctor.PLAY`, `YT.PlayerState.PLAYING`, …).
2. Render the provider iframe into `document.body`, then load with
   `jest.isolateModules(() => require('../tracker'))` (fresh module state per test).
3. Capture the constructed player, **drive** its handlers directly
   (`player.emit(Ctor.PLAY)` or `events.onStateChange({ target, data })`) and assert
   the `window.dataLayer` push shape + the flat `gtm.video*` keys.
4. `jest.useFakeTimers()` + `advanceTimersByTime(1000)` for interval-polled
   percentage milestones (Twitch/YouTube have no native time event); restore in a
   `finally`. Always add a missing-SDK / no-embed case: no throw, no push.

This recipe is exactly what closes the `youtube` gap (T17) — YouTube exposes its
handlers via `new YT.Player(id, { events: { onStateChange, … } })`, so capture the
`events` config and call `onStateChange({ target: mockPlayer, data: YT.PlayerState.PLAYING })`.

### TC-11: A JS dataLayer push is a structured sink, not an HTML sink
`window.dataLayer.push({ ... })` writes a JavaScript **object**; nothing is
concatenated into HTML at the push site, so the `</script>`-breakout /
output-encoding lens (TS-1/TS-2/TC-5) that governs the PHP `<script>` sinks does
**not** apply to a JS tracker. Do not flag a JS tracker for a "missing hostile-input
encoding test." The genuine untrusted-input surface for a JS tracker is a
**message/origin boundary**: VideoPress reads `postMessage` from any origin and
gates it with `gtm4wp_isVideoPressOrigin()` — *that* validator earns a
both-directions test (accept the legit hosts incl. subdomains + `video.wordpress.com`,
reject a spoofed origin and a malformed/non-JSON payload), the TS-5/TC-1 form. A
raw-passthrough assertion (a `</script>&x` guid appears verbatim, not
entity-encoded) is a welcome documentation of intent but is not the load-bearing
guard. Confirmed 2026-07-13: `videopress-tracker.test.js` does the origin-reject
and raw-passthrough correctly; its accept-branch coverage is thin (T20).

### TC-13: Capability-gate / filterable-capability test recipe
The Brain Monkey recipe for a TS-12 gate (canonical example
`tests/unit/Admin/AdminCapabilityFilterTest.php`):

- **Filterable capability.** Simulate the filter with
  `Filters\expectApplied( 'gtm4wp_admin_page_capability' )->once()->with( 'manage_options' )->andReturn( 'custom_cap' )`.
  For the *default* (unfiltered) case set **no** `expectApplied` — Brain Monkey's
  `apply_filters` returns the first arg unchanged, which is exactly the "nothing
  changes unless filtered" assertion.
- **Grant vs deny.** `Functions\expect( 'current_user_can' )->once()->with( $cap )->andReturn( true|false )`
  and assert the method's boolean; a separate test per direction (TS-5).
- **Menu/render capability.** `add_options_page()` enforces the cap for both the
  submenu and the page render, so capture its 3rd argument
  (`Functions\when( 'add_options_page' )->alias( fn( $t, $m, $cap, ... ) => $captured = $cap )`)
  and assert it equals the filtered / default capability — no need to boot the menu.
- The registry is irrelevant to `can_manage()` / `add_admin_page()`, so a bare
  `new Registry()` (empty) keeps the test focused and skips the schema-building
  stub set the other Admin tests need.

### TC-15: Admin React component harness (stand-ins, not an installed library)
The admin app (`js/admin/`) imports `@wordpress/components`, `@wordpress/element`,
`@wordpress/i18n` and `@wordpress/api-fetch`. All four are **build-time externals** —
`DependencyExtractionWebpackPlugin` rewrites them to `window.wp.*`, so the plugin
never bundles them and never pins a version; at runtime the app runs against
whatever WordPress ships across the 6.3 → 7.x support range.

Consequences for testing, settled in Run 6 (2026-08-05) while closing T36:

1. **Do not install `@wordpress/components` to test against.** It is not "the real
   thing" — it is one arbitrary point in the supported range, it drags in a large
   tree, and the current release ships untransformed `.mjs`/`.tsx` that jest will
   not parse without widening `transformIgnorePatterns`. Instead `jest.config.js`
   maps the four packages to small stand-ins in **`js/admin/test-support/`**.
2. **This is not TS-13.** The stand-ins are strictly *less* capable than the real
   library: plain accessible DOM honouring the documented prop contract
   (`onChange` receives the value, `disabled`/`readOnly` reach the input, `label`
   gives the accessible name) and nothing else. TS-13 warns about a double that
   does the safe thing the real collaborator does not; here the risk runs the other
   way, so the rule below closes it.
3. **Assert a guard by its effect, never by a rendered prop.** `TableControl`
   re-checks `isCellLocked()` inside `updateCell()` precisely because `readOnly`
   reaches the input through the library's prop pass-through and cannot be trusted
   across every supported WordPress. So the test fires the change and asserts
   `onChange` was **not** called. Asserting `toHaveAttribute('readonly')` would test
   the stand-in and prove nothing.
4. **Keep the JSX transform out of the project root.** wp-scripts' webpack applies
   `@wordpress/babel-preset-default` only while `hasBabelConfig()` is false, so a
   root `babel.config.js` would silently take over the production build. Configure
   `transform` inline in `jest.config.js` instead, and verify after any admin-test
   change that `npm run build` leaves `build/` byte-identical.
5. `react`/`react-dom` are devDependencies pinned to **^18** to match what
   WordPress ships (npm will otherwise pull React 19).

Mechanics: `render()` + `screen` queries by role/label; `fireEvent.change` for
controlled inputs; a deferred promise plus `act()` to land a **late** async response
for a stale-request/cancellation guard; `apiFetch` is a `jest.fn` that rejects
unless the test configures it, so an unexpected network call fails loudly. Note the
wp jest preset **fails a test on any `console.error`**, so a real anchor `click()`
(jsdom logs an unimplemented-navigation error) must be `mockImplementation`'d, not
spied through.

### TC-14: Conditional-tag-gated code ships a tag-true/global-null case
Code gated on a WordPress conditional tag (`is_singular()`, `is_author()`, …)
that then reads the companion global (`$GLOBALS['post']`, `$authordata`) gets a
test where **the tag is true but the global is null** — the state a conflicting
plugin or unusual template routing produces in production (security **RI-13**).
This is the fixture-side dual of TS-13: a well-formed `$GLOBALS['post']` stdClass
in every fixture is a double more capable than the real request state, so the
suite stays green over a warning-raising path (the PageVariables bug shipped in
1.22.x and survived a fully-`[x]` review matrix this way).

Recipe (canonical:
`PageVariablesModuleTest::test_singular_request_without_global_post_omits_post_variables_without_warning`):

1. Stub the conditional tag true; set `$GLOBALS['post'] = null` (tearDown unsets).
   If the source resolves via `get_post()`, alias it to `$GLOBALS['post'] ?? null`
   in `setUp` so all fixtures keep working.
2. Wrap the call in a `set_error_handler` that **throws** on `E_WARNING | E_NOTICE`,
   restored in `finally`. The handler is the load-bearing part: it is what makes
   the test fail against unguarded code — `assertArrayNotHasKey` alone can pass
   straight over a warning-raising read (warnings are not failures by default).
3. Assert the affected keys are **absent** — omission, never `''`/`0`/`null`
   placeholders (a GTM trigger may test key presence) — AND that the
   global-independent keys still emit (proves the gate is scoped, not a bail-out
   of the whole block).

Scoped `phpcs:ignore`s are accepted for the handler
(`WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler`,
`WordPress.Security.EscapeOutput.ExceptionNotEscaped`) with a test-only
justification, in the BE-4 spirit.

### TC-16: Lexical-global harness (inline `<script>` + window decoy)
The recipe TS-17 calls for, and the only way this suite can tell a real inline-head
global from a window property. Canonical file:
`js/frontend/test/inline-head-globals.test.js`.

1. **Create the binding the way the page does.** In `beforeAll`, append a real
   `<script>` element to `document.head` with the declarations as its `textContent`.
   `jest-environment-jsdom` runs with `runScripts: 'dangerously'` and jsdom executes
   an inline script synchronously on insertion, so this produces genuine global
   *lexical* bindings — not window properties.
2. **Use `let`, not the production `const`.** A global lexical binding can be neither
   redeclared nor deleted for the life of a realm, so `const` would freeze the value
   for the whole file. The binding *kind* — the thing under test — is identical.
   Expose a setter from inside that same script (`window.gtm4wp_test_set_lexical`),
   since only code evaluated there can assign to its own lexical bindings.
3. **Plant a decoy.** Every helper that sets a lexical value also writes the
   **opposite** value to `window.<name>`. This is what makes the case discriminate:
   correct code reads the lexical value, regressed code reads the decoy, and they
   cannot both pass. Set a decoy in `beforeEach` too, so a case that forgets one
   still cannot pass by reading window.
4. **Pin the harness itself.** Capture `hasOwnProperty( window, name )` in `beforeAll`
   *before* any decoy exists and assert it is `false` in its own case. If that ever
   flips, every other case silently stops discriminating — assert it rather than
   trusting the mechanism.
5. **One realm per file.** Because the bindings cannot be deleted, this needs its own
   test file; injecting them into an existing suite shadows every `window.<name> = …`
   in that file's setup.

Do **not** try indirect `eval` (`(0, eval)( 'const x = 1' )`) — measured and rejected
2026-08-06: `PerformEval` puts `let`/`const` in a throwaway declarative environment, so
only `var` ever reaches the global object. It cannot create a persistent lexical global.

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
| 2026-08-06 (bug report, no review run) | Added **TS-17** (⭐ the test *environment* can absorb a coupling, with no double to interrogate) and its recipe **TC-16** (lexical-global harness). TS-13 asks what the real collaborator does that your fake does not; here nobody wrote a fake — jest's jsdom makes `global === window`, so a fixture's `window.<name> = 1` also satisfies a bare-identifier read and the harness structurally cannot tell a lexical `const` global from a window property. The suite passed identically whether the tracker read the right spelling or the wrong one, and **three features shipped dead behind it** (`.security/` RI-14), one inherited from released 1.x. TC-16 reproduces the real page instead: inject a genuine classic `<script>` (jsdom's `runScripts: 'dangerously'` executes it synchronously, creating a true lexical binding), then plant the **opposite** value on `window.<name>` as a decoy so correct and regressed code cannot both pass; own test file, since a global lexical binding can be neither redeclared nor deleted for the life of a realm. **A proposed harness was measured and rejected before being built on:** indirect `eval` cannot create a persistent lexical global — `PerformEval` puts `let`/`const` in a throwaway declarative environment, only `var` reaches the global object. All 7 cases were watched failing on the unfixed source per TS-15, which is the check that would have exposed the harness had it still been absorbing the coupling. Where a harness genuinely cannot be made faithful, the guard moves to a tool that can see it — here `no-restricted-properties` in `.eslintrc.js`, which fails `npm run build`. |
| 2026-08-05 (Run 6 — gaps closed) | Closed T35–T38 on the user's "close all gaps" go-ahead. **PHP 763→767 / 2463→2488 assertions; JS 23→30 suites, 309→404 tests; all green. `phpcs` exit 0 repo-wide, `lint:js` clean, and `npm run build` output verified byte-identical.** No production code changed (tests + tooling only → CHANGELOG exempt). Added **TC-15** (admin React component harness). **TS-16 was applied as the acceptance criterion, not just the diagnosis:** the suite now passes `--order-by=random` across 5 seeds with an assertion count identical to declaration order, which is what proves the fix rather than one lucky seed. Three closes were probe-verified by reverting the guard — the `uninstall.php` `WP_UNINSTALL_PLUGIN` gate, `TableControl`'s locked-cell rejection and `AxeptioVersionControl`'s `cancelled` guard — and T37's new assertion was probed with a *warning-free* `'Array'` stringify, the exact case its predecessor let through. Process lessons worth keeping: (a) the T35 fix for PageVariables is **behaviour-preserving, not a stub added for the linter** — declaring `get_multiple_authors` in `setUp` as "returns no authors" is outcome-equivalent to the function being absent, which is why the ordering NOTE could be deleted rather than reworded; (b) the jsdoc rules fire on **named** exports with destructured props but not on `export default`, so the stand-ins carry one scoped `eslint-disable` in the BE-4 spirit rather than a dozen restated prop tables; (c) `js/admin/test-support/` must NOT be named `test/` — the wp jest preset's `testMatch` includes `**/test/*.[jt]s?(x)`, so any `.js` in a `test/` dir is collected as a suite. |
| 2026-08-05 (Run 6 — report only) | Added **TS-16** (a green suite is not evidence of isolation — check the order) after `--order-by=random` errored **13 tests** on an otherwise unchanged 763-green suite, three of them security regression guards. Root cause is the mechanism Run 5 had already written down as a *limitation* — Brain Monkey defines mocked functions process-wide and permanently — but never followed through as a *risk*: one file's stub silently satisfies another file's missing one, and each file still passes alone, so the usual isolation checks all say "fine". Two process lessons, no numbered entry: (a) **the inventory step must recurse** — the Admin JS row said `js/admin/` and the command's own one-liner is `ls js/admin/*.js`, so a `components/` subdirectory of 6 files / 939 lines was never inventoried; this is the Run-5 "invisible, not unreviewed" lesson recurring one directory level down, and it argues for `find js/admin -name '*.js'` over `ls`. (b) A **whole-schema sweep test** — `ModuleConsistencyTest::test_every_field_sanitizer_handles_non_scalar_input_without_warning`, which iterates every field of every module and promotes warnings to failures — is the shape that made the run's biggest probe come back clean; it pins fields that do not exist yet, which per-field tests never do. Worth copying wherever a contract spans a whole class of declarations. |
| 2026-08-05 (Run 5 — gaps closed) | Closed T28–T34 on the user's "close all gaps" go-ahead. **PHP 706→763 / 2325→2463 assertions, JS 23 suites/309, all green; `phpcs` exit 0 repo-wide with warnings blocking; `lint:js` clean; no production code changed** (tests-only → CHANGELOG exempt). **TS-15 was applied as the acceptance criterion, not just the diagnosis:** four fixes were signed off by re-reverting the guard and watching the *new* test go red (#33's session load, the `Plugin::boot()` gate, the `Notices` gate, the scoped ampersand restore), and T33's exact counts by neutralizing the detach (4 tests that had passed as shape assertions failed). New process lessons worth keeping: (a) **Brain Monkey makes `function_exists()` sticky for the whole process** — once any test mocks a function it exists forever, so a "helper absent on older WooCommerce" branch is not unit-testable here; document the leg instead of faking it; (b) `setcookie`/`headers_sent` ARE redefinable via `patchwork.json`, but `UserEventsModule` uses the **positional** 7-arg signature while `VisitorDataModule` uses the options-array form — match the one under test; (c) a test-wide `document.addEventListener` capture in the **file-level** `beforeEach`/`afterEach` beats a per-describe harness: it cannot be forgotten by the next describe someone adds, which is how T26's fix left seven describes leaking; (d) `#[\PHPUnit\Framework\Attributes\DataProvider]` is the house style — a `@dataProvider` doc-comment raises a PHPUnit 11 deprecation; (e) never run bare `npx prettier` on this repo — it uses default config and reformats the whole file; `npx wp-scripts lint-js js --fix` is the correct fixer. |
| 2026-08-05 (Run 5) | Added **TS-15** (only a revert proves a guard is tested) after two Run-5 probes: the fix for a High security finding was **deleted outright with the suite staying 706/706 green** (TS-13 — every double supplied the collaborator state whose absence is the bug), and a capability gate was **downgraded with its filter removed** while its 17 component tests stayed green (a `justReturn` stub pins no capability). Both sat inside components the matrix marked `[x]`, and neither was reachable by any mechanical signal the project has — which is the entry's point. Extended **TS-12** with "sweep for the sites, don't close the ones a finding named": the #143 fix closed the two sites the issue mentioned and the canonical test says so in its docblock; a grep found four. Also recorded (no numbered entry): **TS-14's live site is now a harness gap, not a masked defect** — the #71 double-init guard shipped and is tested in every bundle, so the loosened `find()` assertions in `woocommerce-tracker.test.js` now only block the exact-count assertions that would catch the *next* regression. And a positive process note: three Run-5 candidate gaps (`is_new_customer`, `build_config`, the no-`fetch` guard) **collapsed on verification** — read the sibling test file before logging, the global grep over `tests/` beats the per-file one. |
| 2026-07-30 (2.0 fix session, no `/test-review` run) | Added **TS-14** (the suite adapted its assertions *around* a defect instead of failing on it) after fixing security findings #71 and #79. The WooCommerce tracker suite carried a comment describing double-pushed events as leaked-listener housekeeping and switched to shape-based assertions to tolerate it — a precise description of the production symptom #71 names, written down and read as a harness quirk. Three further tests in the same file pinned the string `quantity: '3'` / `'2'` that #79 is about, so the defect was asserted as the expectation in two independent places. Sibling evidence for the same session: the `remove_from_cart` zero-quantity test existed only for the mini-cart — the surface that always worked — and the `VisitorIp` X-Forwarded-For tests all used comma-**without**-space lists, which is why #67 survived a green suite. Litmus added: a comment explaining why an assertion is weak is a review lead, and test-harness leakage vs production double-binding look identical from inside a test. |
| 2026-07-22 (forum-reported bug) | Added **TC-14** (conditional-tag-gated code ships a tag-true/global-null case, with the throwing-error-handler recipe) after the PageVariables `$GLOBALS['post']`-on-null warning shipped in 1.22.x and survived every review and a green suite: all fixtures set a well-formed post global (the TS-13 fixture-side dual), and nothing demanded the null case. Canonical test: `PageVariablesModuleTest::test_singular_request_without_global_post_omits_post_variables_without_warning` (verified to fail pre-fix). Companion security entry: RI-13 + the Unguarded WP-global reads sweep. |
| 2026-07-17 (security Review 7) | Added **TS-13** (a test double more capable than the real collaborator hides the bug — the dual of TS-1, about the *collaborator* not the *input*). Prompted by the security review where three of the four most serious findings were each masked by this: `WC()` stubbed wholesale hid the null REST session (#33 / PA-11), a no-op `createController` fake hid the SDK element-replacement loop (#40 / PA-9), and plain-`stdClass` author fixtures hid the `__isset` blanking (#43 / RI-12). Each fix changed the double, not just the assertion. Cross-referenced from security PA-9/PA-11/RI-12. |
| 2026-07-15 (issue #143) | Added **TS-12** (authorization/access-control gates are their own test surface — grant+deny + the filter customizes the required cap; the XSS-first lens never prompted for it, so the untested `gtm4wp_admin_page_capability` gate hid in an `[x]` component) and **TC-13** (the Brain Monkey capability-gate recipe). Prompted by closing the #143 gap with `AdminCapabilityFilterTest`. Also added the **Access-control coverage** Test Debt Sweep to the checklist and an access-control bullet to the pre-flight + the `test-reviewer` agent + the `/test-review` command, so the lens is applied mechanically on future runs. |
| 2026-07-15 (Run 4 — gaps closed) | Closed T21–T26 on the user's "fix all" go-ahead. **PHP 381→416, JS 214→231, all green; phpcs 0 errors; lint:js clean; tests-only (no CHANGELOG).** Two security-contract tests throwaway-probe-verified (T21 `StoreApiData` fails if `item_name` pre-escaped; T22 purchase orderData fails if JSON_HEX_AMP dropped). New process lessons reinforced: (a) the **ListTracking constructor seeds four cross-request globals** (`gtm4wp_product_counter/last_widget_title/grouped_product_ix/cart_item_proddata`) — stage per-item test state AFTER `make_list_tracking()`, not before, or the constructor clobbers it; (b) TS-11 raw-passthrough for a delegated REST sink (`StoreApiData`) is written by asserting the value round-trips the callback verbatim with `stubEscapeFunctions()` installed, so a re-added `esc_*` fails; (c) the TC-8 Plugin-singleton harness extends to `WooCommerceModule::register_frontend_hooks()` (protected → `ReflectionMethod::invoke`; needs `Frontend` with both `datalayer` and `script_tag` props). New test stub `store-api-stub.php` (fake `StoreApi`/`ExtendSchema`/schemas + `ARRAY_A`). |
| 2026-07-15 (Run 4 — report only) | Audited the shipped tests for the WooCommerce overhaul + CookieYes/CheckoutWC bridges (`780875c..HEAD`). **Security/XSS well-guarded, no High gap, no latent bug** — the previously-open `woocommerce` JS tracker gap is closed (793-line suite), the block cart-diff lib is fully covered, and the one new untrusted request surface (list-attribution cookie #405) is hostile-input both-directions. Extended **TS-7** with the JS delegated-`document`-listener leak across `jest.isolateModules`. **No new numbered pattern.** Two process lessons (no numbered entry): (a) a `ProductData` eligibility-helper branch is often exercised through `PurchaseTracking::on_thankyou`, not `ProductDataTest` — read the sibling test file before logging a helper branch as untested (two Run-4 fan-out Mediums collapsed this way: the age-gate TRUE branch and the `$_COOKIE[...tracked]` dedupe branch); (b) a delegated REST/Store-API sink still earns a **raw-passthrough contract** test (TS-11), even though it's FP-4 for XSS — it's the only place the sink's item passthrough is asserted. Gaps T21–T26. No tests written. |
| 2026-07-14 (Run 3 — gaps closed) | Closed T13 (`SettingsPageTest` — admin bootstrap hex sink, throwaway-probe-proven), T15 (`PluginRowTest` — action links + remote-notice both-directions escape), and T16's `client-device-data` half (`client-device-data-tracker.test.js` — both detection paths, config gating, reject branch, no-push guard); `woocommerce` tracker left open per the user (under review). Added **TC-12** (admin enqueue-method hex-sink capture harness — the encode is at the `wp_add_inline_script` call site, so drive the enqueue method, not the data-builder) and extended **TC-9** with the `navigator` override + Client-Hints promise-flush recipe. PHP 282→290, JS 161→167, all green; phpcs/lint clean; tests-only (no CHANGELOG). |
| 2026-07-14 (Run 3 — report only) | Audited the 2026-07-14 code batch (Axeptio CMP, CF7, AMP amp-wp 2.x, Blacklist `sandboxedScripts`, ContainerCode `header_top`). A concurrent `/code-review` had already shipped the TC-1 regression tests, so Run 3's job was **quality-auditing the shipped tests, not finding zero-test classes** — and every one held up (hostile-input both-directions on each new `<script>` sink; correct raw-passthrough on the two delegated sinks: AMP→amp-wp, CF7 title→`wpcf7_format_atts`). **No new pattern** — the batch confirms TS-1/TS-2/TS-11/TC-2/TC-4/TC-11/BE-3 at fresh sites. Process note (no numbered entry): when a code-review lands regression tests for a range, do **not** skip that range next `/test-review` — "tests exist" ≠ "tests are hostile-input"; read each against its sink. Confirmed `AmpModuleTest`'s delegated-sink raw-passthrough as the canonical TS-11 shape for a downstream-encodes sink. No tests written. |
| 2026-07-13 (Run 2 — gaps closed) | Closed T17–T20 after approval. **TC-10 paid off immediately:** applying the player-SDK harness to the untested `youtube` tracker **surfaced a latent bug** — an undeclared `player = new YT.Player(...)` throwing `ReferenceError` in the strict-mode 2.0 module (fixed; regression test proven to fail pre-fix). This is the second time a zero-test class hid a live defect (cf. the OFF-placement iframe leak, Run 0/T3): **write the missing test for a whole-untested class before assuming it works.** Added `MediaEventsModuleTest` (PHP enqueue gate + oEmbed rewrite) and extended VideoPress (origin accept/reject branches). |
| 2026-07-13 (Run 2) | Media-tracker batch review (12 trackers + `native-video-params`, rewritten `MediaEvents` PHP module). Added **TC-10** (player-SDK capture-and-drive harness — the recipe to close the `youtube` gap T17) and **TC-11** (a JS dataLayer push is a structured object sink, not an HTML sink — the output-encoding lens doesn't apply; validate JS trackers at the message/origin boundary instead). No tests written; report-only. New gaps T17–T20. Derived from reviewing the 12 new JS test files (all high-quality) against the untested `youtube` tracker + `MediaEventsModule` PHP branches. |
| 2026-07-13 (Run 1 continued) | Full remaining-gap sweep. Added **TC-8** (reflection injection for `Plugin`-singleton-coupled code — AMP finding #11), **TC-9** (JS side-effect tracker harness under `js/frontend/test/`), and extended **TC-2** with the `\xNN` + `json_encode`-computed-expected corollary (from the AMP test, after tooling HTML-encoded literal break-out chars). Derived from closing T9/T11/T12/T13(AMP)/T14 and starting T10 (JS). |
| 2026-07-13 (Run 1) | Module/admin security-sink pass. Added **TS-11** (upstream raw-passthrough contract — the `geoCloudflareCountryCode` benign-only sink), **TC-6** (PA-7 `addcslashes` regression needs a `$n` input; JSON doubles `\`), **TC-7** (test `wp_die`/exit handlers by stubbing to throw + asserting no post-halt side effect), **BE-4** (`$_POST` snapshot in test setUp trips NonceVerification — scoped `phpcs:ignore`). Derived from closing T7 (`ListTrackingTest`, finding #16), T8 (`NoticesTest`, finding #18) and the PageVariables finding-#12 regression. |
| 2026-07-13 | Seeded the patterns file from the `tests/unit/Frontend/` coverage review. Added TS-1..TS-10 (covered-≠-asserted, both-direction escaping, assert-the-effect, tautological, happy-path-only, zero-test class, state leakage, non-determinism, over-coupling, untested method/branch), TC-1..TC-4 (regression-with-change, build-expected-encoding, right base case, hook-both-ways) and BE-1..BE-3 (1.x byte-exact blessing, unused stub params, intentionally-untested classes). Derived from the session that added `VisitorIpTest` and 31 Frontend tests + fixed the OFF-placement iframe leak. |
