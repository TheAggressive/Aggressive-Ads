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

set -euo pipefail

cd "$(dirname "$0")/../.."

WORKFLOW=.github/workflows/ci.yml
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
				.filter((k) => k.startsWith('ci:') && k !== 'ci:verify')
				.join('\n')
		);
	"
)

status=0

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
