#!/usr/bin/env bash
# Require a CHANGELOG.md entry whenever production code changes.
#   require-changelog.sh stop            # Stop hook: reads JSON on stdin, checks working tree
#   require-changelog.sh commitmsg FILE  # git commit-msg hook: checks staged files, honors [skip changelog]
set -u
MODE="${1:-stop}"

# Production-code paths that REQUIRE a changelog entry (POSIX ERE, repo-relative, forward slashes).
INCLUDE_RE='^(duracelltomi-google-tag-manager-for-wordpress\.php|uninstall\.php|compat/.*\.php|src/.*\.php|js/frontend/.*\.js|js/admin/.*\.js)$'
# Carve-outs (test dirs / test files) even if they match the include set.
EXCLUDE_RE='(^js/frontend/test/|^js/admin/test/|\.test\.js$)'
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

MSG="Production code changed but CHANGELOG.md has no matching entry. Add a concise bullet ('* Added:' / '* Changed:' / '* Updated:' / '* Fixed:') under the top '## 2.0' heading in CHANGELOG.md describing this change."

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
