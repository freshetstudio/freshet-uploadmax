#!/usr/bin/env bash
#
# Freshet content guard — the single pattern source for this repo.
#
# Both the local pre-commit hook (.freshet/hooks/pre-commit) and the CI workflow
# (.github/workflows/content-guard.yml) call this one file, so the two cannot
# drift apart.
#
#   content-guard.sh --staged     scan staged changes            (pre-commit)
#   content-guard.sh --tree       scan every tracked file         (CI)
#   content-guard.sh --history    scan every blob on every ref    (CI)
#   content-guard.sh --paths      scan a path list on stdin       (release build)
#
# Exit 0 clean, 1 on a hit.
#
# Why the text patterns are base64: they are the literal strings this repo must
# never contain. Writing them out here would put them in a public repo — the
# exact thing being prevented. This is not secrecy (base64 is trivially
# reversible), it is keeping the repo free of, and unsearchable for, the strings
# themselves. Decoding happens in memory at runtime.
set -uo pipefail

# Banned filenames — agent notes, deploy scripts, credential files.
BANNED_FILES='(^|/)(CLAUDE\.md|TODO\.md|DEPLOY\.(md|sh)|CREDENTIALS\.md|\.env\.local|\.env\..+\.local)$'

# Banned content patterns live OUTSIDE every repo, in an untracked file, so the
# list is never committed to any repo. Fail CLOSED if it is missing or empty.
PATTERNS_FILE="${FRESHET_GUARD_PATTERNS:-$HOME/Development/.githooks/patterns.b64}"
if [ ! -r "$PATTERNS_FILE" ] || [ ! -s "$PATTERNS_FILE" ]; then
  echo "content-guard: pattern source missing/empty ($PATTERNS_FILE) - refusing commit." >&2
  echo "content-guard: restore ~/Development/.githooks/patterns.b64 or set FRESHET_GUARD_PATTERNS." >&2
  exit 2
fi
BANNED_TEXT_B64=$(cat "$PATTERNS_FILE")

# GNU coreutils decodes with -d, BSD/macOS historically with -D.
decode() {
  local out
  out=$(printf '%s' "$1" | base64 -d 2>/dev/null) \
    || out=$(printf '%s' "$1" | base64 -D 2>/dev/null) \
    || { echo "content-guard: cannot decode patterns" >&2; exit 2; }
  printf '%s' "$out"
}

BANNED_TEXT=$(decode "$BANNED_TEXT_B64")

# Every scan below is relative to the repo root, whichever directory it was
# called from.
if root=$(git rev-parse --show-toplevel 2>/dev/null); then
  cd "$root" || exit 2
else
  echo "content-guard: not inside a git work tree" >&2
  exit 2
fi

# Third-party trees only. Build output is deliberately NOT excluded: compiled
# bundles and source maps are a real leak path for local filesystem paths.
EXCLUDES=(':(exclude)vendor' ':(exclude)node_modules')

fail=0

# Reads paths on stdin. Never call this through a pipe — a pipeline would run it
# in a subshell and lose `fail`.
check_names() {
  local f
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    if [[ "$f" =~ $BANNED_FILES ]]; then
      echo "  ✗ internal file: $f"
      fail=1
    fi
  done
}

# Reports location only, never the matched line: this output lands in CI logs,
# which are as public as the repo.
report_locations() {
  local label="$1" hits="$2"
  [ -z "$hits" ] && return
  echo "  ✗ internal reference ($label):"
  echo "$hits" | sed 's/^/      /'
  fail=1
}

case "${1:---tree}" in
  --staged)
    check_names < <(git diff --cached --name-only --diff-filter=ACM)

    # Added lines only, with the offending line shown — this mode runs in a
    # local terminal, where seeing the hit is the point.
    hits=$(git diff --cached -U0 --diff-filter=ACM -- . "${EXCLUDES[@]}" \
            | grep -E '^\+' | grep -vE '^\+\+\+' | grep -nE "$BANNED_TEXT" || true)
    if [ -n "$hits" ]; then
      echo "  ✗ internal reference in staged content:"
      echo "$hits" | sed 's/^/      /'
      fail=1
    fi
    ;;

  --tree)
    check_names < <(git ls-files)
    report_locations "tracked files" \
      "$(git grep -InE -e "$BANNED_TEXT" -- . "${EXCLUDES[@]}" | cut -d: -f1,2 | sort -u || true)"
    ;;

  --history)
    check_names < <(git log --all --diff-filter=AM --name-only --format= | sort -u)
    revs=$(git rev-list --all)
    if [ -n "$revs" ]; then
      # shellcheck disable=SC2086
      report_locations "commit history" \
        "$(git grep -InE -e "$BANNED_TEXT" $revs -- . "${EXCLUDES[@]}" | cut -d: -f1,2,3 | sort -u || true)"
    fi
    ;;

  --paths)
    check_names
    ;;

  *)
    echo "usage: content-guard.sh [--staged|--tree|--history|--paths]" >&2
    exit 2
    ;;
esac

if [ "$fail" -ne 0 ]; then
  cat <<'MSG'

Blocked by the portfolio content rule.
These repos are public; source comments and commit history count as published.
Locally you can re-run with --no-verify if this is genuinely intended — CI cannot
be skipped, so a hit will fail the push anyway. Fix it instead.
MSG
  exit 1
fi
exit 0
