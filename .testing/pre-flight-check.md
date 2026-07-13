# Pre-Flight Check Before Writing Tests

> ⛔ **Disclosure rule (hard):** this is a public repo — committed == published. A
> missing test on a security sink can point at an unfixed vulnerability. Never
> write exploit detail into a committed `.testing/` file; keep gaps terse and
> defer live-vuln detail to the git-ignored `.security/code-review-report-*.md`.

This is the test-side companion to `.security/pre-flight-check.md`. That one
governs the **code** you write; this one governs the **test that ships with it**.

- **A code change ships its test in the same change.** A change to an
  output-escaping path, input sanitizer, nonce/capability check, or
  container-ID/domain/path validator lands with a PHPUnit regression test that
  would fail if the guard were removed (TC-1). This is non-negotiable for
  anything the `.security/` pre-flight flags.
- **BEFORE writing or modifying a test**, skim `.testing/test-review-patterns.md`
  and avoid every smell. Treat it as a checklist while writing, not an audit
  after.

Pay special attention to (⭐ = highest impact):

- **⭐ Covered ≠ asserted (TS-1):** exercising a sink with benign data is not a
  test of its security behavior. Every security-relevant output sink gets a
  **hostile-input** case (`</script>`, `"`, `&`, a spoofed IP/header).
- **⭐ Both directions on escaping (TS-2):** assert the safe form is **present**
  AND the raw break-out char is **absent**. One alone is false confidence.
- **⭐ Every request/header-sourced dataLayer field (TC-5)** — `?s=`,
  `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`, cookies, `$_SERVER` — ships a hostile-input
  regression test.
- **Assert the effect, not the call (TS-3):** if a method does N things, assert N
  (the queue flushed, the value changed) — not just that it ran.
- **Cover the edge/error branch (TS-5):** empty, invalid, boundary, and the
  fallback/else — not only the happy path. If a sibling validator has a negative
  test, this one should too.
- **Isolate (TS-7/TS-8):** snapshot and reset `$_SERVER`/`$GLOBALS`/statics in
  `setUp`/`tearDown`; inject fixed time/IDs; never depend on test order.
- **Right harness (TC-2/TC-3/TC-4):** build expected encoded output with the same
  `wp_json_encode(... hex flags)` the source uses (never hand-type `\uXXXX`);
  extend `FrontendTestCase` for Options-backed services, the base `TestCase` for
  pure helpers; assert hooks via `has_action`/`has_filter` with both the enabled
  and disabled state.
- **Bless, don't chase (BE-1..BE-3):** the 1.x byte-exact script assertions are
  intentional; not every class needs a unit test — record an intentionally
  untested orchestrator/interface as `[-]` N/A rather than writing a
  stub-asserting (tautological) test.

When you finish a multi-file change, self-review the tests against
`.testing/test-review-patterns.md` before calling it complete — the same way the
`.security/` pre-flight asks for a self-review of the code.
