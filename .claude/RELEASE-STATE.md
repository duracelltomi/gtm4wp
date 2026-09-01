# Release state

<!--
The single source of truth for "what version lives where" in GTM4WP.

Rules:
- Every other file (skills, commands, docs, hooks, CLAUDE.md) REFERENCES this
  file for current-state version facts instead of restating them. A file that
  must embed a concrete value anyway (a command snippet, a reply template)
  marks the line with the literal string `release-coupled` so the /release
  propagation sweep finds it: grep -rn "release-coupled" --include="*.md"
- Updated by the /release skill at every release, and by hand when a policy
  changes (e.g. a line's maintenance status).
- tests/unit/ReleaseStateConsistencyTest.php pins the Facts table below to the
  main plugin file, readme.txt and package.json - editing one side without the
  other breaks the PHP test suite. Keep the `| Key |` strings stable; the test
  greps them.
- Historical records (review-ledger run entries, released CHANGELOG sections,
  dated memory/FAQ entries) legitimately contain old versions. The propagation
  sweep never rewrites history, only current-state claims.
-->

Epoch: **2026-09-01** — 2.0.0 became the wordpress.org stable.

## Facts

| Key | Value |
|---|---|
| Released stable version | 2.0.0 |
| Released stable branch | `2.0` |
| wordpress.org Stable tag | 2.0.0 |
| Development branch | `master` |
| Development target | 2.1 |
| Frozen line | `1.x` (last release 1.22.5) |
| Frozen line policy | unmaintained — ONLY a fix for a reported security issue lands there |
| PHP floor | 8.0 |
| WP floor | 6.3 |
| WP tested up to | 7.1 |
| WC floor | 5.0 |
| WC tested up to | 11.0.0 |

## Branch and bugfix flow

- A bugfix to the released stable lands on BOTH `master` and the released
  stable branch, and ships as a patch release (2.0.x) cut from that branch.
- New feature work lands on `master` only.
- At each new minor/major release the /release skill creates a maintenance
  branch at the release commit. What happens to the *previous* stable branch is
  a maintainer decision made at that release — record it here when made (the
  1.x precedent: frozen, reported-security-fixes only).

## Support consequences (for the triage skills and sweeps)

- Fix status and settings labels are verified against the **released stable
  branch** (`git show <released-branch>:<path>`), never against `master`
  (unreleased development work) and never against the working tree.
- A reporter below the WP/PHP floors is never offered the update and stays on
  the frozen line. Never promise a bugfix on a frozen line; the answer is the
  upgrade path plus a workaround where one exists.

## History

| Date | Event |
|---|---|
| 2026-09-01 | 2.0.0 stable released (GitHub + wordpress.org); `2.0` branch created; `1.x` declared frozen (reported-security-fixes only) |
| 2026-08-17 | 2.0.0-rc1 (GitHub pre-release; feature freeze) |
| 2026-08-04 | 1.22.5 (1.x security release) |
| 2026-07-27 | 2.0.0-beta1 (first GitHub pre-release); `1.x` branch created at 1.22.4 |
