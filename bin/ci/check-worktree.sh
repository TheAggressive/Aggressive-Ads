#!/usr/bin/env bash
#
# Pre-push precondition: verify the commit, not the desk.
#
# This is not a lane. Every lane in verify.sh maps 1:1 onto a CI job; this runs
# before them and has no CI counterpart *by design*, because it exists to
# simulate the one thing CI does that a local run cannot: start from a clean
# checkout of committed content.
#
# The failure it prevents is specific and has happened. `pnpm run qa` reads the
# working tree, so a file that exists on disk but was never `git add`ed is read
# by every lane and passes every one. GitHub checks out only what is committed,
# the file is not there, and the same suite fails on a missing class or a
# missing template. Locally green, remotely red, with nothing in the diff to
# explain it — because the explanation is in what the diff does *not* contain.
#
# The other two divergences are already handled, and are worth knowing about
# rather than rediscovering:
#
#   dist/ is gitignored and CI builds it fresh. `pnpm build` starts with
#   `rm -rf dist`, so qa cannot pass on a stale bundle. Running `pnpm test:e2e`
#   on its own can, which is a different mistake with the same shape.
#
#   The Compose database is disposable. `pnpm qa:fresh` recreates it before
#   testing when a completely clean state is required.
#
# Set AGGR_QA_ALLOW_DIRTY=1 to run the lanes against uncommitted work — useful
# mid-change, and never what you want before pushing.

set -euo pipefail

cd "$(dirname "$0")/../.."

if [ "${AGGR_QA_ALLOW_DIRTY:-0}" = "1" ]; then
	echo "check-worktree: skipped (AGGR_QA_ALLOW_DIRTY=1) — this run does not represent the commit."
	exit 0
fi

if ! git rev-parse --git-dir >/dev/null 2>&1; then
	echo "check-worktree: not a git repository, nothing to compare."
	exit 0
fi

# --porcelain honours .gitignore, so built output and release archives are
# invisible here exactly as they are to CI.
changes=$(git status --porcelain)

if [ -z "$changes" ]; then
	echo "check-worktree: ok (clean tree; these lanes test what CI will)"
	exit 0
fi

echo "check-worktree: the working tree does not match the commit." >&2
echo >&2
echo "$changes" >&2
echo >&2

if git status --porcelain | grep -q '^??'; then
	echo "  Files marked ?? are untracked. CI will not have them at all: every" >&2
	echo "  lane here can read them and pass while the same lane fails there." >&2
	echo >&2
fi

echo "  Commit or stash, then run qa again — or AGGR_QA_ALLOW_DIRTY=1 pnpm qa" >&2
echo "  to check work in progress, knowing it is not what CI will see." >&2

exit 1
