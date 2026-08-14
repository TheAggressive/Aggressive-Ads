#!/usr/bin/env bash
#
# REST permission gate.
#
# Every route needs a real permission_callback. '__return_true' is banned
# because it is a thing people write while debugging and then commit, and the
# result is an unauthenticated endpoint on a system whose highest-value asset
# is another organization's unpublished creative.
#
# See docs/rest-api.md and docs/threat-model.md.

set -euo pipefail

cd "$(dirname "$0")/../.."

hits=$(
	grep -rInE "'permission_callback'[[:space:]]*=>[[:space:]]*'__return_true'|\"permission_callback\"[[:space:]]*=>[[:space:]]*\"__return_true\"" inc \
		--include='*.php' \
		2>/dev/null || true
)

if [ -n "$hits" ]; then
	echo "permission_callback => '__return_true' found:" >&2
	echo "$hits" >&2
	echo >&2
	echo "Every REST route needs a real permission callback. See docs/rest-api.md." >&2
	exit 1
fi

echo "check-permission-callbacks: ok"
