#!/usr/bin/env bash
#
# Arms the content guard for this clone: points git at the tracked hooks
# directory. Run once after cloning — `core.hooksPath` lives in .git/config and
# so is never cloned or committed.
#
#   bash .freshet/install-hooks.sh
#
# Idempotent, and a no-op outside a git work tree so it can safely be an npm
# `prepare` script.
set -uo pipefail

root=$(git rev-parse --show-toplevel 2>/dev/null) || exit 0
cd "$root" || exit 0

git config core.hooksPath .freshet/hooks
chmod +x .freshet/hooks/* .freshet/content-guard.sh .freshet/install-hooks.sh 2>/dev/null

echo "content guard armed — core.hooksPath = .freshet/hooks"
echo "the same check runs in CI on every push, where it cannot be skipped"
