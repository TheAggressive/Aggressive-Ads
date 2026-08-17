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

expected=$(printf '%s\n' "$lanes" | grep -c .)
ran=0

# Read the lane list on fd 3, never stdin.
#
# This loop used to read from stdin, and the commands it runs inherited it.
# Anything that reads stdin — docker exec behind the WordPress suites, the
# bundler — consumed the lines for the lanes that came next, and `read` then
# saw them as already gone. Nine of fourteen lanes ran and `ci:verify` still
# printed "all lanes passed": i18n vanished after lint:files, all three e2e
# lanes after ci:build, and package after the WordPress suites. A skipped lane
# reported as a passing lane is worse than a failing one, because it is the
# exact promise this file exists to make.
while IFS=$'\t' read -r job command <&3; do
	[ -n "$command" ] || continue

	ran=$(( ran + 1 ))

	echo
	echo "──────────────────────────────────────────────────────────────"
	echo "  ${job}: pnpm ${command}"
	echo "──────────────────────────────────────────────────────────────"

	# Unquoted on purpose: a command may carry its own arguments, exactly as it
	# does in the workflow line it was read from.
	# shellcheck disable=SC2086
	#
	# stdin is closed for the same reason the list moved to fd 3. Moving the
	# list alone stops a lane eating it, but then a lane that reads stdin waits
	# on a terminal instead of finishing. CI hands these commands nothing, so
	# closing it here is also what makes a local run behave like the runner.
	pnpm $command </dev/null
done 3<<< "$lanes"

# The fd-3 read makes the loop safe today; this makes a regression loud rather
# than silent tomorrow. Any future lane runner that loses lanes fails here
# instead of congratulating itself.
if [ "$ran" -ne "$expected" ]; then
	echo "ci:verify: ran ${ran} of ${expected} lanes; something consumed the list." >&2
	exit 1
fi

echo
echo "ci:verify: all ${ran} lanes passed"
