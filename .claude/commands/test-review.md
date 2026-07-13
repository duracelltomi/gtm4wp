Perform an in-depth review of the GTM4WP **unit-test suite** — not the production
code (that is `/code-review`). The goal is *behavioral confidence*, not a coverage
number: find code the tests don't guard, sinks exercised only with benign data,
weak/tautological assertions, and whole untested classes — then close the highest-
value gaps.

This plugin builds a JavaScript `dataLayer` from request state, so the dimension
that matters most is: **does a regression test guard every value that reaches a
`<script>`/HTML sink, with a hostile input?** Treat that as the default lens, the
mirror of `/code-review`'s output-escaping focus.

The system is a **hybrid**: a cheap, objective *mechanical* layer (missing-test
detection, coverage if a driver is present, mutation testing if enabled) finds
candidates; the *judgment* layer classifies them and finds what tooling can't see
(covered-but-under-asserted, TS-1). Do not skip either.

---

## Cumulative Review Checklist

**Before starting, read `.testing/test-review-checklist.md`.** It tracks what has
been reviewed and what hasn't, so runs are cumulative and don't repeat.

### Pre-review steps

1. **Load the checklist** — `.testing/test-review-checklist.md` (coverage matrix,
   Test Debt Sweeps, Known Test-Gaps Log).
2. **Load learned patterns** — `.testing/test-review-patterns.md`. Use the Test
   Smells and Project-Specific Test Conventions as the review checklist. Respect
   the Blessed Exceptions — do not flag BE-1..BE-3.
3. **Check staleness** — for `[x]` cells, run
   `git log --since="YYYY-MM-DD" -- <src> <test>`; mark `[~]` if the source or its
   test changed since the review date.
4. **Prioritize** — `[ ]` cells and any sweep marked `open`/`never`/stale first,
   then `[~]`, then `[x]` only if time permits. Within `[ ]`, take security-sink
   components before pure-logic ones.
5. **Cross-reference** the Known Test-Gaps Log — don't re-log a gap already
   `addressed` unless it regressed; advance `open` gaps.
6. **Cross-reference `.security/`** — its Known Findings Log is the source of
   truth for which sinks exist. Every PA-3/RI-2 sink there should map to a
   Sec-input test here (the Regression-per-bug sweep).
7. **Empty-diff escalation — DO NOT STOP when nothing is new.** If there are no
   new commits and no `[ ]`/`[~]` cells, this is the trigger to **go deep**, not to
   stop:
   - Run **every** Test Debt Sweep (missing-file, untested-method, security-input,
     regression-per-bug, JS, and — if enabled — mutation testing).
   - Re-audit the **oldest-reviewed or highest-risk `[x]` components** (sort the
     matrix by date; take the ~5 oldest plus anything that outputs into a
     `<script>`/HTML context, handles order/customer data, or reads request
     headers) against the **current** patterns catalog — a benign-data sink that
     slipped through last time (TS-1) is exactly what a deep pass catches.
   - Re-verify the **⭐ patterns** (TS-1, TS-2, TC-5, TS-6) still hold at their
     known sites.
   - Treat "no new code" as the **deepest** mode. Only report a clean result after
     a genuine cross-suite pass turns up nothing — and say so explicitly.

### Post-review steps

1. **Update the checklist**:
   - Flip reviewed cells to `[x] {date}` (or `[-]` with a reason for an
     intentionally-untested component — patterns BE-3).
   - Append new gaps to the Known Test-Gaps Log (continue the `T#` numbering);
     mark closed gaps `addressed`.
   - Update the **Test Debt Sweeps** table: set each sweep's Last-run date and a
     one-line result.
2. **Update learned patterns** — `.testing/test-review-patterns.md`:
   - Add any generalizable new smell/convention/exception (Litmus: "would this help
     me catch or dismiss the same weakness in a *different* test, or in a test that
     doesn't exist yet?" yes → patterns file; file-specific → gaps log only).
   - Promote a smell seen twice to a numbered entry; dedupe; append a Changelog row.

### Post-work steps (only after tests are added/changed)

1. Run — only after all edits are applied:
   - `vendor/bin/phpunit` — the full suite must stay green.
   - `vendor/bin/phpcs` (WordPress Coding Standards) on changed test files; fix
     errors. Pre-existing warnings unrelated to the change (the `$echo` FP-3 in
     `ContainerCode.php`, unused stub-closure params BE-2) may be left, but note
     them.
   - **If a JS test changed:** `npm run test:unit` and `npm run lint:js`.
   - **If you enabled coverage/mutation tooling:** re-run it and record the delta.
   - **Skip all of the above when only `.md` docs changed** (verify with
     `git diff --name-only`).

---

## Review Scope

### A. Mechanical layer (objective, cheap — run first)

1. **Missing-test-file sweep (TS-6).** For every `src/**/*.php`, is there a
   matching `tests/**/*Test.php` or documented indirect coverage? One-liner:
   ```bash
   for f in $(find src -name '*.php'); do b=$(basename "$f" .php); \
     find tests -name "${b}Test.php" | grep -q . || echo "  no test: $f"; done
   ```
   The list **over-reports** — interfaces, abstracts, and modules covered only via
   `ModuleHooksTest` will appear. Do not treat a hit as a gap until the judgment
   layer classifies it (real gap / indirectly covered / N/A per BE-3).
2. **Coverage (if a driver is present).** Check with
   `php -m | grep -iE 'xdebug|pcov'`. If present, run
   `composer test:coverage` (→ `vendor/bin/phpunit --coverage-text`, scoped to
   `src/` by `phpunit.xml`'s `<source>`). Read the report for 0%/low-% files and
   uncovered lines. **If no driver is loaded, say so and rely on the sweeps + the
   judgment layer** — do not fake coverage numbers. (Install steps: § Optional
   tooling.)
3. **Untested-public-method sweep (TS-10).** For a class that *has* a test file,
   list its public methods (`Grep 'public function'`) and check each name appears
   in the test file. Missing names are candidate gaps (mind hook callbacks reached
   only via `add_action`).
4. **Mutation testing (if enabled — TS-1's mechanical form).** If
   `infection/infection` is installed and a coverage driver is present, run it on
   the changed component; a surviving mutant on a security sink is a real gap even
   at 100% line coverage. Optional; note if skipped.

### B. Judgment layer (what tooling can't see — the core value)

Apply `.testing/test-review-patterns.md` to each prioritized component:

- **Covered-but-under-asserted (TS-1).** For every output/dataLayer sink already
  executed by a test, ask "with *what* input?" A sink exercised only with benign
  data has no security guard even at green coverage. Cross-check every `.security`
  PA-3/RI-2 sink (`get_search_query`/`?s=`, `HTTP_REFERER`, `HTTP_CF_IPCOUNTRY`,
  cookies, `$_SERVER`, WooCommerce billing/shipping) has a hostile-input case
  (TC-5).
- **One-directional escaping tests (TS-2).** Flag XSS guards that assert only the
  safe form OR only the absent raw char, not both.
- **Assert-the-call-not-the-effect (TS-3)** and **tautological (TS-4)** tests.
- **Happy-path-only (TS-5).** Missing error/empty/boundary/invalid/fallback
  branch; sibling-validator asymmetry.
- **Untested branches (TS-10)** of covered classes: alternate `$echo`/placement
  paths, priority branches, null/empty option values.
- **State leakage & non-determinism (TS-7/TS-8).** Superglobal/global/static
  writes without reset; time/random/order dependence.
- **Regression-per-bug.** Walk the `.security/` Known Findings Log; each `fixed`
  finding should have a live test that fails if the fix is reverted. A finding with
  no guard is an `open` gap here.
- **Whole untested classes (from A.1),** now classified: real gap vs indirectly
  covered vs N/A (BE-3, with a recorded reason).

For each candidate, confirm it is real before logging: read the code path and the
existing test. Where feasible, prove the gap by writing the missing test and
watching it (a) pass against correct code, or (b) **fail — surfacing a latent
bug**, as the OFF-placement iframe leak did this cycle.

### C. Prioritize & (optionally) close

Rank gaps: **security-sink hostile-input** > **whole untested security-relevant
class** > **untested error/edge branch** > **weak assertion** > **pure-logic
coverage**. Do not chase coverage for its own sake — a getter test or a
mock-echo test (TS-4) is negative value; prefer recording `[-]` N/A with a reason.

If the user asked to *fix* (not just report): add the highest-value tests, follow
`.testing/pre-flight-check.md` while writing them, then run the Post-work steps.

---

## Output Format

Save the full report to `.testing/test-review-report-{YYYY-MM-DD}-{HHMM}.md`.
**These report files are git-ignored (`.testing/.gitignore`).** The *committed*
files (checklist, patterns) keep only terse gap summaries — for a gap on a security
sink, never restate exploit detail; defer to `.security/`.

```markdown
# Test Review Report — {date} (Run {N})

## Executive Summary
{2-3 sentences: suite health, biggest gaps, whether a latent bug surfaced}

## Scope reviewed
{commit range or component groups; coverage-driver present? mutation run?}

## Mechanical findings
- Missing test files: {classified list}
- Coverage: {numbers, or "no driver — sweeps only"}
- Untested public methods: {list}
- Mutation survivors: {list, or "not run"}

## Judgment findings (by severity)

### High (close now)
| # | Smell | Component | File:Line | Gap | Suggested test |
|---|-------|-----------|-----------|-----|----------------|

### Medium
| # | Smell | Component | File:Line | Gap | Suggested test |

### Low
| # | Smell | Component | File:Line | Gap | Suggested test |

## Verified-solid (no gap)
{components/sinks confirmed well-guarded — cite the guarding test}

## Statistics
- Components reviewed: X
- Gaps: X (High: X, Med: X, Low: X) · Latent bugs surfaced: X
- Patterns confirmed clean: {TS/TC ids}
```

---

## Rules

- ⛔ **Disclosure rule (hard):** public repo, committed == published. The full
  report is git-ignored. In committed files (checklist gaps log, patterns) write
  ONLY a one-line gap summary + severity + status + file. For a gap on a security
  sink, do not paste the payload or the unfixed-vuln detail — defer to
  `.security/`. Canonical rule at the top of `.security/code-review-checklist.md`.
- **Behavioral confidence, not a coverage %.** A green coverage number with
  benign-only assertions (TS-1) is the exact failure this system exists to catch.
- **Bless N/A as a first-class outcome (BE-3).** Recording "intentionally not unit
  tested, here's why" is a valid, valuable result — it stops coverage-chasing junk.
- **Don't duplicate `/code-review`.** If a review turns up a *code* bug (not a test
  gap), note it and route it to `/code-review`; this system owns the tests. The one
  shared rule is TC-1 (a security change ships a regression test).
- Read the code path and the existing test before logging a gap — no guessing from
  names. Reference `file:line`.
- Prefer proving a gap by writing the failing/passing test over asserting it
  abstractly.
- After saving the report, present a summary and ask which gaps the user wants
  closed.

---

## Optional tooling (enables the mechanical layer's strongest signals)

Neither is required — the system runs on the sweeps + judgment without them — but
both sharpen it. Suggest, don't silently install.

- **Coverage driver.** No PCOV/Xdebug is loaded by default here, so
  `--coverage-*` is inert. To enable: install PCOV (`pecl install pcov`, fast) or
  Xdebug and re-run `composer test:coverage`. `phpunit.xml` already scopes coverage
  to `src/` via `<source>`, so the report is meaningful the moment a driver exists.
- **Mutation testing (Infection).** The best mechanical detector of TS-1
  (covered-but-under-asserted): `composer require --dev infection/infection`, add a
  minimal `infection.json5` targeting `src/`, run `vendor/bin/infection`. Needs a
  coverage driver. Run it targeted (changed component), not whole-suite, to keep it
  fast. A surviving mutant on a `<script>`/dataLayer line is a real gap.
