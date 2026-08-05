# Pre-Flight Check Before Coupling to Something External

> ⛔ **Disclosure rule (hard):** public repo — committed == published. Never write
> "this integration is currently broken and unfixed" into a committed file. That
> detail belongs only in the git-ignored `.upstream/upstream-review-report-*.md`.
> Canonical rule at the top of `.security/code-review-checklist.md`.

Third sibling of `.security/pre-flight-check.md` (governs the **code** you write) and
`.testing/pre-flight-check.md` (governs the **test** that ships with it). This one
governs **the belief about the outside world** that your code is about to hardcode.

**BEFORE hardcoding any external string, list, selector, endpoint or version**, read
`.upstream/upstream-review-patterns.md` and register the coupling in
`.upstream/upstream-review-checklist.md` **in the same change**. A coupling with no
registry row is invisible, not unverified.

Pay special attention to (⭐ = highest impact):

- **⭐ Register the mirror (UD-1, UD-3):** copying a list somebody else publishes —
  entity ids, signal names, event names, verticals — creates a snapshot that looks
  correct forever while the source moves. It ships with a registry row carrying the
  source URL, the **claim** to re-verify, and a cadence. Record the *claim*, never a
  page hash: a hash only ever produces noise or silence.
- **⭐ A comment is not a control (UD-2):** "synced from Google's docs, added X removed
  Y" does not fire when the sync goes stale, and if it carries no date it cannot even
  answer how stale it is. If the failure is silent, the mitigation must be something
  that *breaks* — a test pinning a count or an exact set, or at minimum a ledger row
  with a due date.
- **⭐ "It evidently works" is not evidence (UD-11):** for a coupling nothing on our
  side can observe, nobody complaining carries no information. Measure it, or write it
  down as unverified. This exact inference was made about a restriction key and was
  backwards — the feature was disabling entire containers.
- **⭐ Grep for the siblings before adding a version floor (UC-1):** PHP `8.0` is
  written in 6 places, WP `6.3` in 5, WC `5.0` in 2. Adding a seventh without checking
  the other six is how they diverge. The header/constant pair drifts first, because one
  is a documentation act and the other a code act.
- **⭐ Do not let a test double absorb the coupling (UC-3):** if your stand-in accepts
  and ignores the very prop, key or argument the coupling depends on, the suite is
  green *because* the thing is untested. That is worse than no test — a green suite
  reads as evidence. Make the double no more permissive than the real collaborator.
- **Measure, do not copy (UD-13):** any count you write down is measured from the file
  now, with its counting rule beside it. An unmeasured field is `[ ]`, never a
  plausible-looking value.
- **Guard the reach into somebody else's internals (UC-2):** a namespace containing
  `Admin`, `Internal` or `Utilities`, or a `@internal` docblock, carries no
  compatibility promise. `class_exists` + `method_exists` and a defined fallback, or
  accept that you have delegated a fatal to their refactor schedule.
- **Prefer one definition over two (UC-6, UC-4):** an upstream string written in two of
  *our* files diverges internally before upstream ever moves. A contract spanning our
  PHP, our JS and a third party has no single owner — collapse the two ends we own into
  one definition so only one edge is external.
- **Do not encode the future as a validator (UC-5):** a regex that validates somebody
  else's identifier format rejects their next format, and the failure presents as user
  error rather than a plugin bug.
- **A remote asset with no version has no check (UD-6, UD-12):** a floating
  `sdk.latest.js` cannot be pinned or diffed, and HTTP 200 proves only that the host is
  up. If you bind to a global, the claim is that *the symbol* still exists.
- **Note the lead time (UD-7):** if the thing you are coupling to has a public beta/RC
  channel, put it in the row's `Watch` column. Absorbing a break at RC is planned work;
  the same work after GA is a bug fix with users already affected.

When you finish a change that touches an external contract, self-review it against
`.upstream/upstream-review-patterns.md` before calling it complete — the same way the
`.security/` and `.testing/` pre-flights ask.
