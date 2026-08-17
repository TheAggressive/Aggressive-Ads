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

bash bin/release/package.sh "${VERSION}"
bash bin/release/verify-package.sh "${VERSION}"

second_digest="$(sha256sum "${ZIP}" | awk '{print $1}')"
if [[ "${first_digest}" != "${second_digest}" ]]; then
	echo "Package is not reproducible: ${first_digest} != ${second_digest}" >&2
	exit 1
fi

echo "ci:package: reproducible ${ZIP} (${second_digest})"
