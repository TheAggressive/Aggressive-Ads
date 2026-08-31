#!/usr/bin/env bash
# Build, verify, and prove reproducibility of the distributable plugin archive.

set -euo pipefail

cd "$(dirname "$0")/../.."

# Only a release run knows a real version. Everywhere else this lane is proving
# that the packaging path works and is reproducible, not producing something
# anyone installs, so it builds under a synthetic one.
#
# It used to read the plugin header instead. That worked while the header
# carried the released version and broke the moment it stopped: the header now
# reads 0.0.0-development, which package.sh rejects as not strict semver, so
# every pull request failed at packaging.
VERSION="${AGGR_RELEASE_VERSION:-0.0.0}"

bash bin/release/package.sh "${VERSION}"
bash bin/release/verify-package.sh "${VERSION}"

ZIP="release/aggressive-ads-${VERSION}.zip"
first_digest="$(sha256sum "${ZIP}" | awk '{print $1}')"

# The first archive is kept, because package.sh removes release/ before
# rebuilding and the second run would otherwise leave nothing to compare
# against. Without it a reproducibility failure can only be reported as two
# hex strings.
KEPT="$(mktemp -d)"
trap 'rm -rf "${KEPT}"' EXIT
cp "${ZIP}" "${KEPT}/first.zip"

bash bin/release/package.sh "${VERSION}"

# Before the second verification, deliberately.
#
# Verification reaches its missing-required-file check first, so a second
# archive that lost a path is reported as `required file missing from the
# archive` — which reads as an unbuilt file rather than as the reproducibility
# failure it is. That happened, and cost a re-run and an investigation with no
# evidence to work from. See docs/known-issues.md.
bash bin/release/compare-archives.sh "${KEPT}/first.zip" "${ZIP}"

bash bin/release/verify-package.sh "${VERSION}"

# The digest comparison lives in compare-archives.sh, which ran above and
# answers both halves of the question: which paths differ, and — when none do —
# whether the bytes still do. A second comparison here would be a check that can
# never fire, which is the kind this repository deletes rather than keeps.
echo "ci:package: reproducible ${ZIP} (${first_digest})"
