#!/usr/bin/env bash
# Require a CHANGELOG.md entry whenever production code changes.
#   require-changelog.sh stop            # Stop hook: reads JSON on stdin, checks working tree
#   require-changelog.sh commitmsg FILE  # git commit-msg hook: checks staged files, honors [skip changelog]
set -u
MODE="${1:-stop}"

# Production-code paths that REQUIRE a changelog entry (POSIX ERE, repo-relative, forward slashes).
INCLUDE_RE='^(duracelltomi-google-tag-manager-for-wordpress\.php|uninstall\.php|compat/.*\.php|src/.*\.php|js/frontend/.*\.js|js/admin/.*\.js)$'
# Carve-outs (test dirs / test files) even if they match the include set.
# js/admin/test-support/ holds the @wordpress/* stand-ins the admin component tests
# run against (mapped in jest.config.js, see .testing patterns TC-15). It cannot live
# under js/admin/test/ because the wp jest preset's testMatch collects every .js in a
# `test/` directory as a suite — hence its own carve-out here.
EXCLUDE_RE='(^js/frontend/test/|^js/admin/test/|^js/admin/test-support/|\.test\.js$)'
# One place to add readme.txt later: change to CHANGELOG.md|readme.txt and require both.
CHANGELOG='CHANGELOG.md'

case "$MODE" in
  commitmsg)
    MSG_FILE="${2:-}"
    [ -n "$MSG_FILE" ] && grep -qi '\[skip changelog\]' "$MSG_FILE" && exit 0
    FILES="$(git diff --cached --name-only --diff-filter=ACMR)"
    ;;
  *)  # stop: uncommitted working-tree changes only (-uall lists files in new untracked dirs individually)
    FILES="$(git status --porcelain=v1 --untracked-files=all | sed -e 's/^...//' -e 's/.* -> //')"
    ;;
esac

code_touched=0; changelog_touched=0
while IFS= read -r f; do
  [ -z "$f" ] && continue
  [ "$f" = "$CHANGELOG" ] && changelog_touched=1
  if printf '%s\n' "$f" | grep -Eq "$INCLUDE_RE" && ! printf '%s\n' "$f" | grep -Eq "$EXCLUDE_RE"; then
    code_touched=1
  fi
done <<EOF
$FILES
EOF

# Satisfied: no production code changed, or the changelog was already touched.
{ [ "$code_touched" -eq 0 ] || [ "$changelog_touched" -eq 1 ]; } && exit 0

MSG="Production code changed but CHANGELOG.md has no matching entry. Describe this change under the top '## 2.0' heading, in its theme group (### Architecture / Settings screen / Container / Page variables / WooCommerce / Media events / Consent / Contact Form 7 / AMP / Removed). IMPORTANT - 2.0 is UNRELEASED: if this change fixes or extends a feature that was itself introduced in 2.0, EDIT that feature's existing bullet instead of adding a new '* Fixed:' bullet (a user upgrading from 1.x never ran the intermediate code); editing an existing bullet satisfies this hook. Add a new bullet ('* Added:' / '* Changed:' / '* Updated:' / '* Fixed:') only for a change that is genuinely new versus the last release. Pure internal refactors with no functional change need no entry - use [skip changelog] on the commit instead. Mirror user-visible changes into the '= 2.0.0 =' block in readme.txt."

if [ "$MODE" = "commitmsg" ]; then
  { echo "BLOCKED: $MSG"
    echo "For a non-user-facing change, add [skip changelog] to the commit message (or: git commit --no-verify)."; } >&2
  exit 1
fi

# stop mode: loop-guard on stop_hook_active, then emit a block decision so Claude continues.
INPUT="$(cat)"
printf '%s' "$INPUT" | grep -Eq '"stop_hook_active"[[:space:]]*:[[:space:]]*true' && exit 0
printf '{"decision":"block","reason":"%s"}\n' "$MSG"
exit 0
