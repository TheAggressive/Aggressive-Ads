#!/usr/bin/env bash
#
# The contract for declaring a change finished.
#
# Green locally means green in CI, and this file no longer promises that by
# keeping a list in step by hand. It asks bin/ci/lanes.mjs what to run, and that
# reads .github/workflows/ci.yml — so the workflow is the only place a lane is
# declared, and a step added to a verification job appears here without anyone
# remembering to add it twice.
#
# Two things this does that CI does not, both preconditions rather than lanes:
# it refuses an uncommitted working tree, because CI checks out the commit; and
# it requires Docker, because the WordPress and browser suites need it and a
# suite that quietly skips itself has stopped covering anything.
#
# One thing CI does that this does not: `test:e2e:install`, whose --with-deps
# apt-gets browser libraries through sudo on every run. lanes.mjs substitutes
# `test:e2e:browsers`, which installs the same pinned browsers without the root
# prompt, and records why beside the substitution.
#
# See docs/build-and-release.md.

set -euo pipefail

cd "$(dirname "$0")/../.."

# CI checks out committed content, so a run against an uncommitted working tree
# can pass here and fail there for a file that only exists on this machine.
bash bin/ci/check-worktree.sh

# The one gate lanes.mjs cannot reach. Actionlint and Zizmor are required checks
# but live in workflow-security.yml, which the derivation does not read, so a
# workflow edit used to pass here and fail only on GitHub — the divergence this
# file exists to prevent, in the corner it could not see. Run first because it
# is seconds and a broken workflow invalidates everything after it.
bash bin/ci/lint-workflows.sh

# The WordPress suites need real MySQL and the browser lanes need real browsers.
# Failing with a usable instruction beats a wall of connection errors.
if ! docker info >/dev/null 2>&1; then
	echo
	echo "ci:verify: Docker is not running, so the WordPress and browser" >&2
	echo "           suites cannot run. Start Docker, then: pnpm env:start" >&2
	exit 1
fi

lanes=$(node bin/ci/lanes.mjs)

if [ -z "$lanes" ]; then
	echo "ci:verify: no lanes resolved from the workflow." >&2
	exit 1
fi

while IFS=$'\t' read -r job command; do
	[ -n "$command" ] || continue

	echo
	echo "──────────────────────────────────────────────────────────────"
	echo "  ${job}: pnpm ${command}"
	echo "──────────────────────────────────────────────────────────────"

	# Unquoted on purpose: a command may carry its own arguments, exactly as it
	# does in the workflow line it was read from.
	# shellcheck disable=SC2086
	pnpm $command
done <<< "$lanes"

echo
echo "ci:verify: all lanes passed"
