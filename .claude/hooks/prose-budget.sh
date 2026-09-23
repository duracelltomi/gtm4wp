#!/usr/bin/env bash
# Flag prose this session wrote that is longer than the project's budget:
# code comments and changelog bullets.
#
#   prose-budget.sh stop     # Stop hook: reads JSON on stdin, checks the working tree
#   prose-budget.sh check    # same check, printed as text (run it yourself any time)
#   prose-budget.sh report   # full scan of every tracked production file, worst first
#
# Advisory, not a gate. It reports once per stop (the stop_hook_active guard),
# and a block that is justified is answered by stopping again. It only ever
# measures what the working tree ADDS, so an old long comment is never raised
# against a session that did not write it.
set -u
MODE="${1:-stop}"

# Production paths whose comments are budgeted (POSIX ERE, repo-relative).
INCLUDE_RE='^(duracelltomi-google-tag-manager-for-wordpress\.php|uninstall\.php|compat/.*\.php|src/.*\.php|js/frontend/.*\.js|js/admin/.*\.js|tools/.*\.(php|js))$'
EXCLUDE_RE='(^js/frontend/test/|^js/admin/test/|^js/admin/test-support/|\.test\.js$)'

# Budgets. A block is a run of consecutive comment lines; a bullet is one
# "* Added:"-style changelog line.
MAX_BLOCK=15      # lines in one new comment block
MAX_ADDED=25      # comment lines added to one file before the ratio applies
MAX_WORDS=60      # words in one changelog bullet

is_production() {
  printf '%s\n' "$1" | grep -Eq "$INCLUDE_RE" && ! printf '%s\n' "$1" | grep -Eq "$EXCLUDE_RE"
}

# Reads a unified diff (or a whole file as added lines) on stdin and prints
# "<added comment lines> <added code lines> <longest run of comment lines>".
measure_added() {
  awk '
    /^\+\+\+/ { next }
    /^\+/ {
      line = substr($0, 2)
      if (line ~ /^[ \t]*(\/\/|\/\*|\*|#[^!])/ || line ~ /^[ \t]*\*\//) {
        comments++; run++
        if (run > longest) longest = run
      } else if (line ~ /^[ \t]*$/) {
        # A blank line neither breaks a docblock nor counts as code.
      } else {
        code++; run = 0
      }
      next
    }
    { run = 0 }
    END { printf "%d %d %d\n", comments + 0, code + 0, longest + 0 }
  '
}

changed_files() {
  git status --porcelain=v1 --untracked-files=all 2>/dev/null |
    sed -e 's/^...//' -e 's/.* -> //'
}

# --- comment budget -----------------------------------------------------------
comment_findings() {
  local f status counts added code longest
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    is_production "$f" || continue
    [ -f "$f" ] || continue

    if git ls-files --error-unmatch "$f" >/dev/null 2>&1; then
      counts="$(git diff HEAD -U0 -- "$f" | measure_added)"
    else
      counts="$(sed 's/^/+/' "$f" | measure_added)"   # new file: all of it is added
    fi

    added="${counts%% *}"; counts="${counts#* }"
    code="${counts%% *}"; longest="${counts##* }"

    if [ "$longest" -gt "$MAX_BLOCK" ]; then
      printf '  %s: a new comment block of %s lines (budget %s)\n' "$f" "$longest" "$MAX_BLOCK"
    elif [ "$added" -gt "$MAX_ADDED" ] && [ "$added" -gt "$code" ]; then
      printf '  %s: %s comment lines added against %s code lines\n' "$f" "$added" "$code"
    fi
  done <<EOF
$(changed_files)
EOF
}

# --- changelog budget ---------------------------------------------------------
changelog_findings() {
  local f
  for f in CHANGELOG.md readme.txt; do
    [ -f "$f" ] || continue
    git diff HEAD -U0 -- "$f" 2>/dev/null |
      awk -v file="$f" -v max="$MAX_WORDS" '
        /^\+[*-] / {
          line = substr($0, 2)
          n = split(line, w, /[ \t]+/)
          if (n > max) {
            label = substr(line, 1, 48)
            printf "  %s: a %d-word bullet (budget %d): %s...\n", file, n, max, label
          }
        }
      '
  done
}

# --- full scan ----------------------------------------------------------------
if [ "$MODE" = "report" ]; then
  printf 'Longest comment block per production file (budget %s lines):\n\n' "$MAX_BLOCK"
  git ls-files -- '*.php' '*.js' | while IFS= read -r f; do
    is_production "$f" || continue
    awk -v file="$f" '
      /^[ \t]*(\/\/|\/\*|\*)/ { run++; if (run > longest) longest = run; comments++; next }
      /^[ \t]*$/ { next }
      { run = 0 }
      END { if (longest > 0) printf "%4d %4d %s\n", longest, comments, file }
    ' "$f"
  done | sort -rn | head -25
  exit 0
fi

FINDINGS="$(comment_findings)
$(changelog_findings)"
FINDINGS="$(printf '%s\n' "$FINDINGS" | sed '/^[[:space:]]*$/d')"

[ -z "$FINDINGS" ] && exit 0

ADVICE='Prose budget: keep the one-line WHAT, the non-obvious WHY (an invariant, a trap, an upstream claim, a U-row or issue number) and the @param/@return phpcs needs. Drop rejected alternatives, measurement narrative, phase history and anything the code already says. A changelog bullet is written for a user upgrading from the last release: what changed, what they must do, one sentence of why - not the investigation. If a block genuinely needs the length, keep it and stop again.'

if [ "$MODE" = "check" ]; then
  printf 'Over the prose budget:\n%s\n\n%s\n' "$FINDINGS" "$ADVICE"
  exit 0
fi

# stop mode: loop-guard first, then one block decision so Claude can trim.
INPUT="$(cat)"
printf '%s' "$INPUT" | grep -Eq '"stop_hook_active"[[:space:]]*:[[:space:]]*true' && exit 0

REASON="$(printf 'Over the prose budget:\n%s\n\n%s' "$FINDINGS" "$ADVICE" |
  sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' | awk '{ printf "%s\\n", $0 }')"

printf '{"decision":"block","reason":"%s"}\n' "$REASON"
exit 0
