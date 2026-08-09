#!/usr/bin/env bash
#
# File length gate.
#
# Warn above 800 lines, fail above 1000. No allowlist, deliberately: the
# remedy is always to split by responsibility. Raising the threshold is not an
# option, because the threshold is not the point — a 1200-line class is telling
# you it has more than one job, and the number is just how you found out.
#
# See docs/architecture.md.

set -euo pipefail

WARN_AT=800
FAIL_AT=1000

cd "$(dirname "$0")/.."

failed=0
warned=0

while IFS= read -r file; do
	lines=$(wc -l < "$file")

	if [ "$lines" -gt "$FAIL_AT" ]; then
		printf 'FAIL  %5d lines  %s\n' "$lines" "$file"
		failed=1
	elif [ "$lines" -gt "$WARN_AT" ]; then
		printf 'WARN  %5d lines  %s\n' "$lines" "$file"
		warned=1
	fi
done < <(
	find inc src tests templates bin -type f \
		\( -name '*.php' -o -name '*.ts' -o -name '*.tsx' -o -name '*.js' -o -name '*.mjs' -o -name '*.css' \) \
		-not -path '*/node_modules/*' \
		-not -path '*/vendor/*' \
		2>/dev/null | sort
)

if [ "$failed" -ne 0 ]; then
	echo "Files above ${FAIL_AT} lines. Split by responsibility." >&2
	exit 1
fi

if [ "$warned" -ne 0 ]; then
	echo "Files above ${WARN_AT} lines. Consider splitting before they reach ${FAIL_AT}."
fi

echo "check-file-length: ok"
