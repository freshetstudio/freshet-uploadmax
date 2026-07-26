# Content guard

These repos are public. Source comments, filenames and commit history all count
as published, so a small set of things must never land in one: internal notes
and deploy scripts, credential files, local and server paths, client or internal
framework names, live payment keys.

This directory enforces that in two places, from **one** pattern source
(`content-guard.sh`):

| Where | What runs | Skippable? |
| --- | --- | --- |
| Your clone, pre-commit | `content-guard.sh --staged` via `hooks/pre-commit` | yes — `git commit --no-verify` |
| CI, every push and PR | `content-guard.sh --tree` and `--history` | no |

The local hook is for fast feedback; CI is the gate that actually protects the
remote.

## Arming a fresh clone

`core.hooksPath` lives in `.git/config`, which is never cloned. So once, after
cloning:

```bash
bash .freshet/install-hooks.sh
```

Repos with a JS toolchain run this automatically from the npm `prepare` script.
CI needs no setup — the workflow is committed.

## When it blocks you

It prints where the hit is, not what it matched (CI logs are public too). Fix
the content. If a hit is genuinely intended, `--no-verify` gets the commit past
your machine, but CI will still fail the push — change the pattern source
instead, and re-sync it to every repo.

CI scans the **history**, not just the tip, because the leak this guard exists
for was history-only. That means deleting a bad file in a follow-up commit does
not turn the check green — the blob is still reachable. Rewrite the history (or
rebuild the branch) instead.

The banned strings are stored base64-encoded in `content-guard.sh`: they are
precisely the literals that must not appear in a public repo, so spelling them
out here would defeat the purpose. That is not a security measure, and it is not
meant to be one.
