#!/usr/bin/env bash
# Reconcile a private GitHub draft, verify the uploaded bytes, then publish it.
# A failed run leaves only a draft, which the plugin updater deliberately ignores.

set -euo pipefail

cd "$(dirname "$0")/../.."

SLUG=aggressive-ads
TAG="${RELEASE_TAG:?RELEASE_TAG is required}"
REPOSITORY="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
VERSION="${TAG#v}"
ZIP="release/${SLUG}-${VERSION}.zip"
CHECKSUM="${ZIP}.sha256"

if [[ ! "${TAG}" =~ ^v[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release tag: ${TAG}" >&2
	exit 2
fi

if [[ ! "${REPOSITORY}" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]]; then
	echo "Invalid GitHub repository: ${REPOSITORY}" >&2
	exit 2
fi

for asset in "${ZIP}" "${CHECKSUM}"; do
	if [[ ! -f "${asset}" ]]; then
		echo "Missing release asset: ${asset}" >&2
		exit 1
	fi
done

if gh release view "${TAG}" --repo "${REPOSITORY}" >/dev/null 2>&1; then
	DRAFT="$(gh release view "${TAG}" --repo "${REPOSITORY}" --json isDraft --jq .isDraft)"
	if [[ "${DRAFT}" != "true" ]]; then
		echo "Release ${TAG} is already public; refusing to replace immutable assets." >&2
		exit 1
	fi
else
	gh release create "${TAG}" --repo "${REPOSITORY}" --verify-tag --draft --generate-notes --title "${TAG}"
fi

for asset in "${ZIP}" "${CHECKSUM}"; do
	NAME="$(basename "${asset}")"
	ASSET_ID="$(gh api "repos/${REPOSITORY}/releases/tags/${TAG}" --jq ".assets[] | select(.name == \"${NAME}\") | .id")"
	if [[ -n "${ASSET_ID}" ]]; then
		gh api --method DELETE "repos/${REPOSITORY}/releases/assets/${ASSET_ID}" >/dev/null
	fi
	gh release upload "${TAG}" "${asset}" --repo "${REPOSITORY}"
done

DOWNLOAD_DIR="$(mktemp -d "${RUNNER_TEMP:-/tmp}/laao-release.XXXXXX")"
cleanup() {
	rm -rf "${DOWNLOAD_DIR}"
}
trap cleanup EXIT

for asset in "${ZIP}" "${CHECKSUM}"; do
	NAME="$(basename "${asset}")"
	ASSET_ID="$(gh api "repos/${REPOSITORY}/releases/tags/${TAG}" --jq ".assets[] | select(.name == \"${NAME}\") | .id")"
	if [[ ! "${ASSET_ID}" =~ ^[0-9]+$ ]]; then
		echo "Could not resolve remote asset ${NAME}." >&2
		exit 1
	fi

	gh api -H 'Accept: application/octet-stream' \
		"repos/${REPOSITORY}/releases/assets/${ASSET_ID}" >"${DOWNLOAD_DIR}/${NAME}"
done

cmp "${ZIP}" "${DOWNLOAD_DIR}/$(basename "${ZIP}")"
cmp "${CHECKSUM}" "${DOWNLOAD_DIR}/$(basename "${CHECKSUM}")"
(
	cd "${DOWNLOAD_DIR}"
	sha256sum --check "$(basename "${CHECKSUM}")"
)
gh attestation verify "${DOWNLOAD_DIR}/$(basename "${ZIP}")" --repo "${REPOSITORY}"

gh release edit "${TAG}" --repo "${REPOSITORY}" --draft=false --latest
