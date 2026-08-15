#!/usr/bin/env bash
# Build, verify, and prove reproducibility of the distributable plugin archive.

set -euo pipefail

cd "$(dirname "$0")/../.."

PLUGIN_FILE=aggressive-ads.php
VERSION="${AGGR_RELEASE_VERSION:-}"
if [[ -z "${VERSION}" ]]; then
	VERSION="$(grep -m1 -oE '^\s*\*\s*Version:\s*\S+' "${PLUGIN_FILE}" | awk '{print $NF}')"
fi

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
