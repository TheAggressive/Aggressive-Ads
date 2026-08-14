#!/usr/bin/env bash
#
# CI parity gate.
#
# Every `ci:*` script in package.json must be run verbatim by a job in
# .github/workflows/ci.yml, and must appear in bin/ci/verify.sh.
#
# The documentation has claimed "each maps 1:1 onto a GitHub Actions job" since
# before any workflow existed. A claim about process that nothing checks is a
# claim that quietly stops being true — which is the same argument the rest of
# this directory makes about conventions in general.
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

	if ! grep -qE "pnpm ${lane}( |$)" "$VERIFY"; then
		echo "  ${lane}: not run by ${VERIFY}" >&2
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
