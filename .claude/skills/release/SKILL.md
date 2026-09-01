---
name: release
description: Cut a GTM4WP release — pre-flight verification, the version bumps, tag, ZIP, GitHub release with post-upload verification, branch mechanics, and the propagation sweep that updates RELEASE-STATE.md, docs, skills, ledgers and memory in the same pass. Use when the user says "release X.Y.Z", "cut the release", "prepare the release", or asks how the release process works.
license: GPL-2.0-or-later
---

# GTM4WP release process

Distilled from the 2.0.0 release day (2026-09-01). The companion file
`.claude/RELEASE-STATE.md` is the single source of truth for "what version lives
where" — this skill's last step is keeping it, and everything that references it,
true.

## 0. Read the state, name the release type

Read `.claude/RELEASE-STATE.md`. Then classify:

- **Pre-release** (beta/rc): GitHub only, `--prerelease`, `Stable tag` does NOT
  move, RELEASE-STATE.md gains only a History row.
- **Stable** (new minor/major, or the first stable of a line): `Stable tag`
  moves, `--latest`, wordpress.org follows, a maintenance branch is created, the
  full propagation sweep runs.
- **Patch** (bugfix release of the stable line): cut from the released stable
  branch, then merge/cherry-pick parity with `master` is confirmed; otherwise
  like Stable minus the branch creation.

## 1. Pre-flight — all green before any file changes

1. `git status` clean, synced with origin, on the right branch for the type.
2. `vendor/bin/phpunit` · `vendor/bin/phpcs` · `npm run build` (includes
   `lint:js`) · `npm run test:unit`.
3. Review-ledger freshness: the last `.security/` review covers every production
   commit up to HEAD (check the ledger's Reviewed-at hash); if not, run
   `/code-review` first.
4. **Targeted upstream drift check** — dispatch the `upstream-reviewer` agent
   read-only ("report only, update no ledgers"), scoped to the Release Radar
   (WP, WC, CF7, wordpress.org directory rules, Google GTM/consent) since the
   last sweep date in `.upstream/upstream-review-checklist.md`. Publishing gates
   on its "no release blockers" verdict; advisories go on the follow-up list.
5. Changelog: the top heading must be this release's section, complete, with the
   `readme.txt` mirror block in sync (invoke the `changelog` skill for the
   rules). The heading itself does not change at release time.

## 2. Version bump

The version string lives in exactly these places (a stable release adds one):

1–4. `npm version X.Y.Z --no-git-tag-version` updates `package.json` and both
     `package-lock.json` entries; edit the plugin header `Version:` and
     `GTM4WP_VERSION` in the main plugin file by hand.
5.   `readme.txt` `Stable tag:` — **stable releases only**; moving it is the
     marker of a stable release, pre-releases never touch it.

For a stable release, update the `.claude/RELEASE-STATE.md` Facts table **in the
same commit** — `tests/unit/ReleaseStateConsistencyTest.php` pins the two to each
other, so the suite breaks if one moves without the other. Re-run
`vendor/bin/phpunit` after the bump to prove the pins hold.

## 3. Commit and tag

Commit message via `git commit -F <file>` (never `-m` with a multi-line string),
text `Release X.Y.Z [skip changelog]` — a bump ships no changelog bullet, every
change already carries its own. Then an annotated tag: `git tag -a X.Y.Z -m X.Y.Z`.

## 4. Build, publish, verify

1. `npm run release` → `release/duracelltomi-google-tag-manager-for-wordpress-X.Y.Z.zip`.
   The GitHub auto-generated source archive is **not installable** (`build/` is
   git-ignored) — always attach this ZIP.
2. Push: `git push origin <branch> X.Y.Z` (+ the new maintenance branch, step 5).
3. `gh release create X.Y.Z <zip> --title "GTM4WP X.Y.Z" --notes-file <file>`
   with `--prerelease` or `--latest` per type. Release-note voice: the
   maintainer's public voice (no em dashes, plain register), run the `humanizer`
   skill; model on the previous release's notes via `gh release view`.
   Known snag: the releases endpoint can return bare 503s during GitHub
   incidents while reads still work — retry with a bounded loop.
4. **Post-upload verification (never skip):** `gh release download` the asset,
   SHA256-compare against the local ZIP, and read the plugin header `Version:`
   out of the downloaded ZIP. Confirm `isPrerelease`/`Latest` via
   `gh release view --json` / `gh release list`.

## 5. Branch mechanics (stable minor/major only)

- `git branch X.Y <release-commit>` and push — the maintenance home for X.Y.z
  bugfixes, which from now on land on BOTH `master` and this branch.
- Decide (with the maintainer) what happens to the *previous* stable branch, and
  record the decision in RELEASE-STATE.md. Precedent: at 2.0.0, `1.x` was frozen
  to reported-security-fixes only.

## 6. Propagation sweep — what makes the repo agree with reality

Run after the release is published (stable releases; pre-releases only do a):

a. **`.claude/RELEASE-STATE.md`**: Facts table, branch/bugfix flow, a History
   row; a new Epoch line if the branch model or a maintenance policy changed.
b. **Marker sweep**: `grep -rn "release-coupled" .claude CLAUDE.md` — visit
   every hit and update the embedded value. The grep is the registry; do not
   maintain a separate list that can go stale.
c. **Old-version sweep**: grep the previous released version string across the
   repo (exclude `build/`, `node_modules/`, `vendor/`, `release/`). Classify
   each hit: **historical record** (ledger run entries, released CHANGELOG
   sections, dated FAQ/memory entries) → leave untouched; **current-state
   claim** → fix. Never bulk find-and-replace; today's inversion of the
   forum skill's "trap" paragraph shows these need judgment, not substitution.
d. **Memory**: update the project memory file and its `MEMORY.md` index hook.
e. **`.support/forum-answers.md`** (git-ignored): add/refresh the epoch banner
   when the branch model or maintenance policy changed, so sweeps re-frame
   pre-epoch canonical answers before reuse.
f. Re-run `vendor/bin/phpunit` — the consistency test is the final agreement
   check.

## 7. External checklist — print it for the maintainer, these are manual

- **wordpress.org SVN push** (separate SVN working copy on the maintainer's PC;
  the release ZIP's layout is SVN-ready). After the push, verify: plugins-API
  version, `svn ls` of `tags/X.Y.Z` and `trunk` (the shipped items incl. `build/`),
  `Stable tag` in BOTH readmes, and a per-file hash compare of the wp.org-built
  ZIP against the local one.
- **Announcement post** on gtm4wp.com (drafted beforehand; published after the
  wp.org push).
- **Screenshots/banners** if the UI changed — images to SVN `/assets`, caption
  edits to `tags/X.Y.Z/readme.txt` + `trunk` + git `readme.txt`.
- Pending `Tested up to` bumps and upstream-ledger follow-ups surfaced by the
  step-1 drift check (e.g. retiring an exception that converged at release).

## Hard rules

- Historical records are never rewritten — only current-state claims move.
- Committed == published (public repo): nothing in a release commit, note or
  ledger may carry unfixed-finding detail.
- Every outward step (push, release, SVN) happens only after its gate: the
  pre-flight battery and the drift check verdict.
