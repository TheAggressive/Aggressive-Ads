#!/usr/bin/env bash
# Retry a network-bound command with exponential backoff.
#
# Usage: bash bin/ci/retry.sh <attempts> <command> [args...]
#
# For commands that fail for reasons that have nothing to do with the change
# under test. The release of v1.1.0 failed its first attempt because
# api.github.com returned HTTP 504 for twenty-six package downloads inside a
# Docker build — a GitHub outage, reported as a red build on this plugin.
#
# Deliberately not a general-purpose wrapper. A retry around a command that
# fails for real reasons converts a fast failure into a slow one and hides
# flakiness that deserves fixing, so this belongs only on steps that pull from
# the network and are idempotent.
#
# Do not combine this with `timeout`. `timeout` kills the command it was given,
# not the process group beneath it: wrapping `playwright install --with-deps`
# killed playwright while the apt-get it had spawned as root kept running and
# kept /var/lib/apt/lists/lock. Both remaining attempts then died in seconds
# with "Could not get lock", so the retry did not merely fail to help — it
# guaranteed failure, on a first attempt that may well have finished given
# another minute.

set -euo pipefail

ATTEMPTS="${1:?attempts is required}"
shift

if [[ ! "${ATTEMPTS}" =~ ^[1-9][0-9]*$ ]]; then
	echo "retry: attempts must be a positive integer, got '${ATTEMPTS}'" >&2
	exit 2
fi

if [[ $# -eq 0 ]]; then
	echo "retry: a command is required" >&2
	exit 2
fi

delay=5

for attempt in $(seq 1 "${ATTEMPTS}"); do
	# Captured from the command itself, not read after the `if`. Following a
	# failed condition with no else branch, `$?` is the if statement's status —
	# zero — so a wrapper written that way exits 0 when every attempt failed
	# and turns a red build green. That is worse than having no retry at all.
	status=0
	"$@" || status=$?

	if [[ "${status}" -eq 0 ]]; then
		if [[ "${attempt}" -gt 1 ]]; then
			echo "retry: '$*' succeeded on attempt ${attempt}."
		fi

		exit 0
	fi

	if [[ "${attempt}" -eq "${ATTEMPTS}" ]]; then
		echo "retry: '$*' failed ${ATTEMPTS} times; giving up." >&2
		exit "${status}"
	fi

	# Surfaced as a workflow warning so a run that only passed on the second
	# attempt still says so. A retry nobody can see is how a flaky dependency
	# becomes invisible infrastructure debt.
	echo "::warning title=Retrying a flaky step::'$*' failed (exit ${status}); retrying in ${delay}s (attempt $((attempt + 1)) of ${ATTEMPTS})."

	sleep "${delay}"
	delay=$(( delay * 2 ))
done
