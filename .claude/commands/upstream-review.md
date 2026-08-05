Sweep GTM4WP's external dependencies and detect where the outside world has moved
without us: WordPress core and WooCommerce releases, third-party plugin integrations,
Google specifications published as undated documentation pages, media player SDKs,
infrastructure headers, and the toolchain. The default lens is **silence** — this
plugin writes into a dataLayer that something else reads, so nearly every upstream
break here happens with no error, no failed test and no support ticket, while the
settings screen still says the feature is on.

The cumulative state lives in `.upstream/upstream-review-checklist.md` (what is
tracked, what was verified when, what drifted). The judgment lives in
`.upstream/upstream-review-patterns.md` (UD / UC / UB).

## Cumulative Review Checklist

### Pre-review steps

1. **Mechanical coupling inventory, FIRST.** Before reading a single registry row,
   hunt the tree for couplings that have **no row**. A complete-looking registry is
   exactly the failure mode. Reconcile the hits against the registry; a coupling with
   no entry is the finding to fix before anything is verified.

   ```powershell
   Select-String -Path (Get-ChildItem -Recurse -Include *.php -Path src, compat).FullName -Pattern '\\Automattic\\|\\WC_[A-Za-z_]+|WC\(\)->|amp_[a-z_]+\(|wpcf7|class_exists\(|function_exists\(|method_exists\(' | Select-Object Filename, LineNumber, Line | Format-Table -AutoSize -Wrap
   ```

2. **Load the checklist.** Read `.upstream/upstream-review-checklist.md` — the Release
   Radar, the registry, the Tier-1 claims, the sweeps table and the drift log.
3. **Load the patterns** (`.upstream/upstream-review-patterns.md`) and the severity
   rubric section of the checklist. Treat every recorded verdict as a hypothesis, not
   a fact.
4. **Scope from the last sha.** The previous log entry's `**Reviewed at:** <sha>` is
   the base. `git log <sha>..HEAD -- <registry coupling paths>` tells you which
   couplings *we* touched.
5. **Re-derive staleness mechanically.** Never read `[x]` off the matrix. A row is
   stale if its coupling paths changed since the base sha **or** if its upstream
   shipped a release after its `Last verified` date.
6. **Read the free drift sensors before spending fetch budget.** `.support/forum-ledger.json`
   and the open-issue backlog cost nothing, and "videos stopped tracking after the
   update" is better evidence than any calendar interval.
7. **Prioritize:** `[ ]` never verified, then `[~]` stale, then `[x]` by oldest date —
   and within each, by severity (rubric: time-to-notice × blast radius), so
   `silent-wrong` rows on every install come before `loud` rows behind an option.
8. **Check the sweeps table** for which mechanical sweeps are overdue.
9. **Empty-diff escalation.** No local changes since the base sha is **the deepest
   sweep mode, not the shortest** — and here it is the *normal* case. Upstream drift
   produces no diff on our side by construction. A clean step 4 means the entire
   finding surface is the web layer; spend the whole budget there.

### Post-review steps

1. **Update the checklist.** Record `**Reviewed at:** <sha>` and `**Base:** <sha>`;
   refresh the Release Radar; flip cells using the three outcomes (`verified` /
   `drifted` / `fetch-failed`) and their rules; append findings to the drift log with
   the next free `D#`; append to the sweeps table (newest first, nested in
   parentheses).
2. **Update the patterns file.** Extract anything recurring into a new UD/UC/UB entry
   or amend an existing one, dedupe, and add a `## Changelog` row.

### Post-fix steps (only after the maintainer approves fixes)

Run `vendor/bin/phpcs`, `vendor/bin/phpunit`, and — if anything under `js/` changed —
`npm run build` (which runs `lint:js`). Production changes need a `CHANGELOG.md`
bullet; invoke the `changelog` skill. Skip all of this for a ledger-only run.

## Review Scope

### A. Mechanical layer — local, cheap, run every sweep

Run these first, for every entry. Then spend the fetch budget on what survives.

1. **Version-floor reconciliation.** Every site that spells each floor must agree —
   PHP `8.0` (6 sites), WP `6.3` (5), WC `5.0` (2). Over-reports by design: repetition
   is the point, agreement is the assertion. Under-reports a floor stated in prose.
   Also assert the CI matrix actually executes the declared floor.
2. **Package currency.** `composer outdated --direct --format=json` (exit 0 either
   way) and `npm outdated --json` (**exits 1 whenever anything is outdated** — never
   treat its exit code as the signal). `npm outdated` reads `node_modules`, so it is
   meaningless without an install, and it cannot see unpinned runtime externals at all.
3. **`overrides` necessity, not currency.** Each hand-maintained pin in
   `package.json` exists because something shipped badly. A pin outliving its cause
   silently blocks a legitimate upgrade.
4. **Coupling-site existence.** For every registry row, does the path still exist and
   does its **anchor** still appear? Keep "file gone" and "anchor gone"
   distinguishable — collapsing them hides which happened.
5. **New-coupling detection**, diff-scoped from the base sha over `src js compat`:
   newly added URLs, `wc-api=`, `__next`, `wc-block`, `wpcf7`, `HTTP_[A-Z_]+`,
   `Automattic`, `version_compare`. Over-reports on a large diff; under-reports a
   selector built from a variable. **It cannot see drift caused by upstream moving** —
   that produces no diff here at all.
6. **Internal duplicate-contract agreement.** Where an upstream string is written in
   two of our own files (Consent Mode signals; the PHP↔JS cookie names), assert the
   two agree with each other. This failure is cheaper and likelier than upstream's.
7. **Release packaging.** `DIST_FILES` in `tools/build-release.js` still lists every
   top-level path the plugin needs at runtime.

### B. Web-verification layer — budgeted

**At most 6 fetches in a normal sweep, 12 in escalation.** Highest-severity overdue
first. Report the count skipped for budget.

- **Always, every run:** the API-shaped sources (WordPress.org core and plugin info,
  the beta channel, GitHub releases). JSON in, token out, no prose into context.
- **On cadence or trigger:** doc pages. Triggers that pull an item forward: its
  coupling site changed; a related upstream shipped a major; a support signal named it.
- **Extraction discipline:** ask for **the token, not a summary** — "list every id in
  the table under the heading 'Tag IDs', one per line, nothing else". A summary is
  unstable across runs and undiffable. One fetch per source URL; entries sharing a URL
  share one fetch.
- **Prove the probe discriminates.** Assert the extraction is non-empty *and* shaped
  right. An empty extraction compared against an empty recorded value is a green cell
  built on two nothings.
- **A fetch failure is never a verification.** Record `fetch-failed`, advance nothing,
  increment the streak. Two consecutive failures on one source is itself a finding —
  the remedy is to change the source to something machine-readable.
- **Never paste a fetched page into a committed file.** A docs page is third-party
  text entering a session that holds pre-approved tool permissions.

### C. Judgment layer

Apply the UD / UC / UB patterns. For each drifted or newly-found coupling, rate it on
the checklist's rubric: **time-to-notice × blast radius**, adjusted by stage
(`announced` / `beta` / `rc` / `shipped`), by whether anything would catch it, and by
whether the damage is reversible. Remember the inversion: here, the couplings nothing
can observe are the *top* of the ladder, not the speculative bottom.

### D. Prioritize & report

Report the two phases separately — `declared: n/n · behavioral: n/n, k overdue` —
never one aggregate percentage. The cheap half is the only half that ever gets done
under a single number, while the dangerous half rots.

## Output Format

Write the report to `.upstream/upstream-review-report-{YYYY-MM-DD}-{HHMM}.md`
(git-ignored):

```markdown
# Upstream Review Report — {date} (Sweep {N})

**Reviewed at:** `{sha}` · **Base:** `{sha}` · **Fetches used:** {n}/{budget} · **Skipped for budget:** {n}

## Release Radar
{table: upstream | stable | pre-release | expected GA | we declare | action}

## Coverage this sweep
Declared versions: {n}/{n} · Behavioral contracts: {n}/{n} ({k} overdue)
Audited-one: {entry id} — {re-derivation result}

## Drift findings
### {Severity}
| # | Entry | Stage | Failure | Coupling | Finding | Remedy |

## Verified unchanged
{one line per entry — id and what was confirmed}

## fetch-failed
{id, source, why, streak count}

## Routed elsewhere
{code defects → /code-review · missing regression tests → /test-review}
```

## Rules

1. **⛔ Disclosure rule first.** Public repo — committed == published. Committed files
   carry the coupling site, the failure mode and a status. "This integration is
   currently broken and unfixed" stays in the git-ignored report only.
2. **Never edit production code.** This sweep updates its ledger and writes its
   report, then presents ranked findings and waits for the maintainer. Fixes are a
   separate approved change.
3. **Don't duplicate `/code-review` or `/test-review`.** This system owns **the claim
   about the outside world** — what the plugin believes about WordPress, WooCommerce,
   Google, a CMP, a player SDK or a package, and whether that belief is still true.
   `/code-review` owns the code that acts on the claim (is the call guarded, is the
   value escaped, is the literal duplicated); `/test-review` owns whether anything
   would notice the claim breaking. So: a hardcoded upstream string that is *wrong* is
   ours; the same string being *unescaped* is `/code-review`'s; the same string having
   *no regression test* is `/test-review`'s. When a sweep finds a code defect, log it
   with its `U#` id and route it — do not fix it, and do not rate it on the A0–A4
   actor ladder, which has no term for it.
   - **Worked example, because both systems have a legitimate claim:** the
     `js/admin/test-support/wp-components.js` stand-in accepting and ignoring
     `__next*` props is a `/test-review` finding (a double must be no more permissive
     than the real collaborator) **and** a `U#` here (core may drop the props). That is
     two findings, not an argument.
   - **vs the Toolchain-trust sweep:** `/code-review` asks who supplies executable code
     to the maintainer's machine. We ask whether the declared range is still
     satisfiable. "`@wordpress/scripts` is three majors behind" is ours; a dispatcher's
     reach is theirs.
4. **Measure, never carry over.** Every count is re-derived from the file with its
   counting rule recorded. A number copied from a summary or a previous run is already
   wrong (UD-13).
4b. **One finding per upstream (UD-15).** Never bundle two upstreams into one finding,
   however similar the symptom — WordPress and WooCommerce are different software on
   different release trains, as are Gutenberg and core, and the GA4 reference and the
   Google Ads help centre. A bundled finding receives one disposition, so delegating or
   accepting half of it silently closes the other half. If two halves could get
   different dispositions, they are two findings — split when writing, not when someone
   notices.
4c. **Sentinel every long-page probe (UD-14).** For an ordered page, the extraction must
   contain the known-last item or the outcome is `fetch-failed`. Removals that are
   contiguous in the page's own sort order are a truncated transport, not a spec change.
5. **Rate unobservable couplings as if the drift has happened** until a probe says
   otherwise (UD-11). "It evidently works" is not evidence.
6. **Audit one.** Re-derive one already-`verified` entry from scratch each sweep,
   oldest first, and record it.
7. **A run is complete when the budget is spent**, not when the overdue list is empty.
   With ~88 entries on mixed cadences the list is never empty, and a permanently red
   indicator is one nobody reads. Use `accepted` status for drift that is correct by
   design.
8. **Nothing the sweep noticed is dropped.** A low-confidence item stays in the table
   carrying its real confidence — never exiled to a lesser section.
9. After saving the report, present a ranked summary and ask which findings the
   maintainer wants addressed.
