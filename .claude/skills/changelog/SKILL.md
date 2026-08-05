---
name: changelog
description: How to write GTM4WP CHANGELOG.md / readme.txt entries. Follow when adding, editing, or grouping a changelog bullet for a production-code change, or when the require-changelog Stop/commit-msg hook blocks you. Covers the "last released stable version is the baseline" rule (drop back-ported fixes and dev-only regressions), the "write for the upgrading user" rule (edit an unreleased feature's existing bullet vs. add a new Fixed: bullet), the 2.0 theme grouping, the readme.txt mirror, and the [skip changelog] escape hatch.
license: GPL-2.0-or-later
---

# GTM4WP Changelog Policy

## What requires an entry

Every change to **production code** ships a matching bullet under the top `## 2.0`
heading in `CHANGELOG.md` (`* Added:` / `* Changed:` / `* Updated:` / `* Fixed:`).

"Production code" = `src/**.php`, `compat/**.php`, `js/frontend/**.js`, `js/admin/**.js`,
the main plugin file and `uninstall.php`. Tests, docs and `.security/`/`.testing/`
housekeeping are exempt.

## The baseline is always the last released stable version

Every bullet in the unreleased block describes a delta against the **last
released stable version** — check `Stable tag:` in `readme.txt` on the `1.x`
branch, not against the previous major or against last week's working tree. Two
consequences:

- A fix **back-ported** to that stable release gets **no bullet** in the
  unreleased block. It is not a delta any more; the reader sees it in the
  released version's own block directly below.
- Do not soften the baseline because some sites are still on an older version.
  Admins upgrading from further back read the intervening blocks, which sit
  right below the unreleased one, so they stay informed either way.

Before writing "previously…", "1.x did…", or "no longer…", confirm the claim
against the released code (`git grep <symbol> 1.x`). A bullet whose "previously"
only ever existed on the development branch describes nothing the reader lived
through.

## Write for the upgrading user, not for the development history

While a version is **unreleased**, a fix to a feature introduced *in that same
version* must **edit that feature's existing bullet**, not add a new `* Fixed:`
bullet. A user upgrading from the last release never ran the intermediate code,
so for them the feature plus its development fixes is a single `* Added:`. Add a
`* Fixed:` bullet only for a defect that shipped in a **released** version.

Corollaries:

- A change that only repairs a regression introduced earlier in the same
  unreleased version gets **no bullet at all** — its net effect versus the last
  release is zero. Touch `CHANGELOG.md` (e.g. refine the feature's wording) to
  satisfy the hook.
- An internal refactor with "no functional change" is not a changelog entry.
  Use `[skip changelog]` in the commit message instead.
- Editing an existing bullet **satisfies both hooks** — they check that
  `CHANGELOG.md` changed, not that a bullet was added.
- The 2.0 section is grouped under `###` theme headings (Architecture, Settings
  screen, Container, Page variables, WooCommerce, Media events, Consent, Contact
  Form 7, AMP, Removed). Put a new bullet in its theme group rather than at the
  top of the section.
- `readme.txt`'s `= 2.0.0 =` block **mirrors** the `## 2.0` section (flattened
  for WordPress.org: no nested lists, `**bold**` lead-ins instead of `###`).
  A user-visible change updates both files together.

## Enforcement

One shared script, `.claude/hooks/require-changelog.sh`, enforces this:

- a Claude Code **`Stop` hook** (in `.claude/settings.json`) blocks wrapping up a turn
  that left production code modified without a `CHANGELOG.md` change;
- a git **`commit-msg` hook** (`.githooks/commit-msg`) rejects a commit that stages
  production code without staging `CHANGELOG.md`. Escape hatch for non-user-facing
  commits: put `[skip changelog]` in the commit message (or `git commit --no-verify`).

**One-time setup after cloning** (the git hook lives in a tracked dir, so it must be
activated once per clone): `git config core.hooksPath .githooks`.
