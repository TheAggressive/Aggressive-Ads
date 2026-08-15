#!/usr/bin/env bash
# Reconcile a semantic-release draft with the accepted artifacts, verify the
# downloaded bytes and provenance, then publish it. A failure leaves a draft,
# which the updater deliberately ignores.

set -euo pipefail

cd "$(dirname "$0")/../.."

SLUG=aggressive-ads
VERSION="${AGGR_RELEASE_VERSION:?AGGR_RELEASE_VERSION is required}"
REPOSITORY="${GITHUB_REPOSITORY:?GITHUB_REPOSITORY is required}"
TAG="v${VERSION}"
ZIP="release/${SLUG}-${VERSION}.zip"
CHECKSUM="${ZIP}.sha256"

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "Invalid release version: ${VERSION}" >&2
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

(
	cd release
	sha256sum --check "$(basename "${CHECKSUM}")"
)
bash bin/release/verify-package.sh "${VERSION}"

# Draft releases are not returned by the tag endpoint consistently, so inspect
# the complete releases collection and require exactly one semantic-release
# draft. The reconciler never invents tags or release notes itself.
release_rows=$(
	gh api --paginate "repos/${REPOSITORY}/releases?per_page=100" \
		--jq ".[] | select(.tag_name == \"${TAG}\") | [.id, .draft] | @tsv"
)
row_count="$(grep -c . <<<"${release_rows}" || true)"
if [[ "${row_count}" -ne 1 ]]; then
	echo "Expected exactly one GitHub release for ${TAG}, found ${row_count}." >&2
	exit 1
fi

read -r release_id release_is_draft <<<"${release_rows}"
if [[ ! "${release_id}" =~ ^[0-9]+$ || "${release_is_draft}" != "true" ]]; then
	echo "Release ${TAG} is not a private semantic-release draft." >&2
	exit 1
fi

list_assets() {
	gh api "repos/${REPOSITORY}/releases/${release_id}/assets" \
		--jq '.[] | [.id, .name] | @tsv'
}

asset_id() {
	local asset_name="$1"
	list_assets | awk -F '\t' -v name="${asset_name}" '$2 == name { print $1 }'
}

upload_asset() {
	local asset_path="$1"
	gh release upload "${TAG}" "${asset_path}" --repo "${REPOSITORY}"
}

DOWNLOAD_DIR="$(mktemp -d "${RUNNER_TEMP:-/tmp}/aggr-release.XXXXXX")"
cleanup() {
	rm -rf "${DOWNLOAD_DIR}"
}
trap cleanup EXIT

for asset in "${ZIP}" "${CHECKSUM}"; do
	name="$(basename "${asset}")"
	remote_id="$(asset_id "${name}")"
	if [[ -z "${remote_id}" ]]; then
		upload_asset "${asset}"
		remote_id="$(asset_id "${name}")"
	fi
	if [[ ! "${remote_id}" =~ ^[0-9]+$ ]]; then
		echo "Could not resolve remote asset ${name}." >&2
		exit 1
	fi

	gh api -H 'Accept: application/octet-stream' \
		"repos/${REPOSITORY}/releases/assets/${remote_id}" >"${DOWNLOAD_DIR}/${name}"

	if ! cmp -s "${asset}" "${DOWNLOAD_DIR}/${name}"; then
		echo "Remote ${name} differs from the accepted artifact; replacing it."
		gh api --method DELETE \
			"repos/${REPOSITORY}/releases/assets/${remote_id}" >/dev/null
		upload_asset "${asset}"
		remote_id="$(asset_id "${name}")"
		gh api -H 'Accept: application/octet-stream' \
			"repos/${REPOSITORY}/releases/assets/${remote_id}" >"${DOWNLOAD_DIR}/${name}"
		cmp "${asset}" "${DOWNLOAD_DIR}/${name}"
	fi
done

(
	cd "${DOWNLOAD_DIR}"
	sha256sum --check "$(basename "${CHECKSUM}")"
)

remote_zip="${DOWNLOAD_DIR}/$(basename "${ZIP}")"
remote_checksum="${DOWNLOAD_DIR}/$(basename "${CHECKSUM}")"
cmp "${ZIP}" "${remote_zip}"
cmp "${CHECKSUM}" "${remote_checksum}"
gh attestation verify "${remote_zip}" --repo "${REPOSITORY}"

gh api --method PATCH "repos/${REPOSITORY}/releases/${release_id}" \
	-F draft=false -F make_latest=true >/dev/null

published_draft_state=$(
	gh api "repos/${REPOSITORY}/releases/${release_id}" --jq '.draft'
)
if [[ "${published_draft_state}" != "false" ]]; then
	echo "Release ${TAG} is still a draft after promotion." >&2
	exit 1
fi

echo "Release ${TAG} is remotely verified, attested, and published."
