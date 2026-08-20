#!/usr/bin/env bash
#
# CI parity gate.
#
# Every `ci:*` script in package.json must be run verbatim by a job in
# .github/workflows/ci.yml, and must be reached by the local rehearsal.
#
# The documentation has claimed "each maps 1:1 onto a GitHub Actions job" since
# before any workflow existed. A claim about process that nothing checks is a
# claim that quietly stops being true — which is the same argument the rest of
# this directory makes about conventions in general.
#
# The second half of that used to be a grep against verify.sh, which held its
# own copy of the list. It no longer does: verify.sh runs whatever
# bin/ci/lanes.mjs reads out of the workflow, so the question is not "did
# somebody remember to add it there too" but "does the derivation actually reach
# it". A lane that resolves to nothing locally is a lane only CI runs, which is
# the drift this file exists to refuse.
#
# ci:verify is exempt: it is the local aggregate that runs every lane, and a CI
# job running it would run every other job again inside one.
#
# ci:php:forward is exempt for a different reason, and the exemption is narrow
# on purpose. It answers "will this still work on the next PHP", which is not a
# question worth asking on every change, so it lives on a schedule in its own
# workflow and is deliberately absent from the local rehearsal — a lane that
# needs a PHP the development environment does not have could not run there
# anyway. What is asserted instead is that the scheduled workflow exists, still
# runs on a schedule, and still calls the canonical lane rather than inlining
# shell that nobody can run locally.

set -euo pipefail

cd "$(dirname "$0")/../.."

WORKFLOW=.github/workflows/ci.yml
FORWARD_WORKFLOW=.github/workflows/php-forward-compatibility.yml
VERIFY=bin/ci/verify.sh

if [ ! -f "$WORKFLOW" ]; then
	echo "check-ci-parity: no workflow at ${WORKFLOW}" >&2
	exit 1
fi

# verify.sh must still be the thing that runs them, and must still get its list
# from the workflow rather than from a copy somebody has to maintain.
if ! grep -q 'bin/ci/lanes.mjs' "$VERIFY"; then
	echo "check-ci-parity: ${VERIFY} no longer derives its lanes from the workflow" >&2
	exit 1
fi

local_lanes=$( node bin/ci/lanes.mjs | cut -f2 )

lanes=$(
	node -e "
		const s = require('./package.json').scripts;
		console.log(
			Object.keys(s)
				.filter((k) => k.startsWith('ci:'))
				.filter((k) => k !== 'ci:verify' && k !== 'ci:php:forward')
				.join('\n')
		);
	"
)

status=0

# The forward lane's own contract, since it is exempt from the one above.
# Without these three assertions the exemption would be a hole rather than a
# considered exception: the workflow could be deleted, moved off its schedule,
# or rewritten as inline shell, and nothing would notice.
if [ ! -f "$FORWARD_WORKFLOW" ]; then
	echo "check-ci-parity: ci:php:forward is exempt only while ${FORWARD_WORKFLOW} exists" >&2
	status=1
elif ! grep -q 'schedule:' "$FORWARD_WORKFLOW"; then
	echo "check-ci-parity: ${FORWARD_WORKFLOW} must stay on a schedule — forward" >&2
	echo "  coverage that only runs on demand is coverage nobody runs" >&2
	status=1
elif ! grep -qE 'pnpm ci:php:forward( |$)' "$FORWARD_WORKFLOW"; then
	echo "check-ci-parity: ${FORWARD_WORKFLOW} must call the canonical ci:php:forward" >&2
	echo "  lane rather than inline shell, so it stays runnable locally" >&2
	status=1
fi

for lane in $lanes; do
	# Anchored so ci:php does not match ci:php:wp.
	if ! grep -qE "pnpm ${lane}( |$)" "$WORKFLOW"; then
		echo "  ${lane}: no job runs it in ${WORKFLOW}" >&2
		status=1
	fi

	if ! printf '%s\n' "$local_lanes" | grep -qxF "$lane"; then
		echo "  ${lane}: the workflow runs it, but bin/ci/lanes.mjs does not reach it" >&2
		echo "    (a publishing job, or a step shape the parser does not read)" >&2
		status=1
	fi
done

if [ "$status" -ne 0 ]; then
	echo >&2
	echo "Every ci:* lane runs in both the workflow and verify.sh, or neither." >&2
	echo "See docs/build-and-release.md." >&2
	exit 1
fi

echo "check-ci-parity: ok"
