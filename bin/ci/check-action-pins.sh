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

set -euo pipefail

cd "$(dirname "$0")/../.."

if [ ! -d .github/workflows ]; then
	echo "check-action-pins: ok (no workflows)"
	exit 0
fi

unpinned=$(
	grep -rnE '^\s*uses:\s*[^#]+' .github/workflows 2>/dev/null \
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
undocumented=$(
	grep -rnE '^\s*uses:\s*[^@]+@[0-9a-f]{40}\s*$' .github/workflows 2>/dev/null || true
)

if [ -n "$undocumented" ]; then
	echo "Pinned actions must carry a '# vX.Y.Z' comment naming the version:" >&2
	echo >&2
	echo "$undocumented" >&2
	exit 1
fi

echo "check-action-pins: ok"
