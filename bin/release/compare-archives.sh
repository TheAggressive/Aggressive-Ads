#!/usr/bin/env bash
#
# Says how two archives differ, by path.
#
# The reproducibility check compares two digests, which answers "are these the
# same bytes" and nothing else. That was enough until the day the answer was no:
# the second build of one unchanged `dist/` dropped a single 3 KB file, and what
# the operator was told was `required file missing from the archive` — because
# verification runs before the digest comparison and reaches the missing-file
# check first. That reads as an unbuilt file. It was a reproducibility failure,
# and nothing printed the two listings, so there was no evidence left to
# diagnose from. See docs/known-issues.md.
#
# So this runs *before* the second verification and names the paths. A content
# difference is then reported as a content difference.
#
# Usage: bin/release/compare-archives.sh <first.zip> <second.zip>

set -euo pipefail

if [ "$#" -ne 2 ]; then
	echo "Usage: $0 <first.zip> <second.zip>" >&2
	exit 2
fi

first="$1"
second="$2"

for archive in "${first}" "${second}"; do
	if [ ! -f "${archive}" ]; then
		echo "compare-archives: no archive at ${archive}" >&2
		exit 2
	fi
done

# `-Z1` is names only, and sorted so the comparison is about membership rather
# than about the order `zip` happened to write them in. Order is already pinned
# by package.sh, and a diff that reported it here would be noise on top of the
# real answer.
first_list="$(unzip -Z1 "${first}" | LC_ALL=C sort)"
second_list="$(unzip -Z1 "${second}" | LC_ALL=C sort)"

first_digest="$(sha256sum "${first}" | awk '{print $1}')"
second_digest="$(sha256sum "${second}" | awk '{print $1}')"

if [ "${first_digest}" = "${second_digest}" ]; then
	echo "compare-archives: identical ($(printf '%s\n' "${first_list}" | wc -l | tr -d ' ') paths, ${first_digest})"
	exit 0
fi

echo "The second build produced a different archive from the first." >&2
echo "  first:  ${first_digest}" >&2
echo "  second: ${second_digest}" >&2
echo >&2

# `comm` rather than `diff`, so the output says which direction each path went
# rather than leaving the reader to decode a unified diff of a sorted list.
#
# Indented with `sed` rather than by letting `printf` split on whitespace: a
# packaged path may contain a space, and word splitting would print it as two
# broken lines — which is worst precisely when the diagnostic matters.
removed="$(comm -23 <(printf '%s\n' "${first_list}") <(printf '%s\n' "${second_list}"))"
added="$(comm -13 <(printf '%s\n' "${first_list}") <(printf '%s\n' "${second_list}"))"

if [ -n "${removed}" ]; then
	echo "Present in the first build and missing from the second:" >&2
	printf '%s\n' "${removed}" | sed 's/^/  /' >&2
	echo >&2
fi

if [ -n "${added}" ]; then
	echo "Present in the second build and missing from the first:" >&2
	printf '%s\n' "${added}" | sed 's/^/  /' >&2
	echo >&2
fi

# Same names, different bytes.
#
# The digests are compared rather than the listings alone, because a listing
# comparison cannot see this case at all — and an earlier draft of this script
# returned "identical" for it, which is a lie about the one property the lane
# exists to assert. Its own test caught that.
if [ -z "${removed}" ] && [ -z "${added}" ]; then
	echo "Both archives hold the same paths, so the difference is in file contents," >&2
	echo "modes or timestamps rather than in what was included." >&2
	echo >&2
fi

echo "This is a reproducibility failure, not a build failure. See docs/known-issues.md." >&2
exit 1
