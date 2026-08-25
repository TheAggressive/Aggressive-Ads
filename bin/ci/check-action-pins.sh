#!/usr/bin/env bash
#
# GitHub Actions supply-chain gate.
#
# Every third-party action must be pinned to a full 40-character commit SHA,
# never a tag or a branch.
#
# A tag is a moving pointer the action's owner controls. `uses: foo/bar@v4`
# means "run whatever foo/bar decides v4 means, with our repository token, on
# every push" — and a compromised or simply repointed tag is a code-execution
# grant nobody re-approved. Several real supply-chain incidents have worked
# exactly this way.
#
# actions/* from GitHub itself is held to the same rule: the argument is about
# mutability, not about trusting the author.
#
# Two things this used to get wrong, both found by writing its test:
#
#   1. A missing .github/workflows printed "ok (no workflows)" and exited 0. A
#      gate that cannot find the code it guards has failed, not passed — and
#      here that means an unpinned action ships because a directory moved.
#   2. Only .github/workflows was scanned. A composite action under
#      .github/actions/ runs with the same token and can call anything it likes,
#      so it needs the same rule.

set -euo pipefail

cd "$(dirname "$0")/../.."

# Overridable so the guard can be pointed at a fixture; defaults to this
# repository. See check-action-pins.test.mjs.
ROOT="${AGGR_ACTION_PINS_SCAN_DIR:-.github}"

if [ ! -d "$ROOT" ]; then
	echo "check-action-pins: scan directory does not exist: ${ROOT}" >&2
	echo "A gate that cannot find the code it guards must fail, not pass. See CLAUDE.md." >&2
	exit 1
fi

# Workflows and composite actions both execute with the repository token.
targets=$(find "$ROOT" -type d \( -name workflows -o -name actions \) 2>/dev/null || true)

if [ -z "$targets" ]; then
	echo "check-action-pins: no workflows or actions directory under ${ROOT}" >&2
	echo "A gate that reads nothing reports success over nothing. See CLAUDE.md." >&2
	exit 1
fi

# shellcheck disable=SC2086 # Word splitting is how the directory list is passed.
files=$(find $targets -type f \( -name '*.yml' -o -name '*.yaml' \) | sort)

if [ -z "$files" ]; then
	echo "check-action-pins: no workflow or action files under ${ROOT}" >&2
	echo "A gate that reads nothing reports success over nothing. See CLAUDE.md." >&2
	exit 1
fi

count=$(printf '%s\n' "$files" | wc -l | tr -d ' ')

# The `-?` is the whole guard.
#
# This read `^\s*uses:` — whitespace, then the key — which matches a `uses:` on
# its own line under a named step and nothing else. Actions are overwhelmingly
# written as list items, `- uses: foo/bar@sha`, and that dash meant the pattern
# never saw them: 9 of this repository's 81 `uses:` lines, so an unpinned
# `- uses: evil/action@v1` had always passed. Found by testing the gate.
#
# The anchor is still what keeps a commented-out example from matching.
# shellcheck disable=SC2086
unpinned=$(
	grep -nE '^\s*-?\s*uses:\s*[^#]+' $files 2>/dev/null \
		| grep -vE 'uses:\s*\./' \
		| grep -vE 'uses:\s*[^@]+@[0-9a-f]{40}\b' \
		|| true
)

if [ -n "$unpinned" ]; then
	echo "GitHub Actions must be pinned to a full commit SHA:" >&2
	echo >&2
	echo "$unpinned" >&2
	echo >&2
	echo "A tag is a moving pointer the action's owner can repoint at any time." >&2
	exit 1
fi

# A SHA with no version comment is pinned but unreadable: nobody can tell what
# they are updating from, so nobody updates it.
# shellcheck disable=SC2086
undocumented=$(
	grep -nE '^\s*-?\s*uses:\s*[^@]+@[0-9a-f]{40}\s*$' $files 2>/dev/null || true
)

if [ -n "$undocumented" ]; then
	echo "Pinned actions must carry a comment naming the exact tag:" >&2
	echo >&2
	echo "$undocumented" >&2
	exit 1
fi

echo "check-action-pins: ok (${count} files)"
