#!/usr/bin/env bash
#
# Install and exercise the distributable ZIP in a WordPress that has no mapping
# to this source tree.
#
# Everything before this proves the source is sound. This proves the artifact a
# customer receives can be installed and activated on its own — which is a
# different claim, and the one that matters at the moment of publishing. The
# package job assembles and verifies bytes; nothing until now has asked
# WordPress to run them.
#
# The environment is deliberately separate from the development one: its own
# config directory, its own WP_ENV_HOME, its own ports. A run that borrowed the
# development environment would be exercising the mapped source tree and would
# pass whatever the archive contained.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
CONFIG_DIR="${SCRIPT_DIR}/artifact"
WP_ENV="${REPO_ROOT}/node_modules/.bin/wp-env"
PLAYWRIGHT="${REPO_ROOT}/node_modules/.bin/playwright"
ARTIFACT_HOME="${REPO_ROOT}/.cache/ci/wp-env-artifact"
ARTIFACT_FILES="${REPO_ROOT}/.cache/ci/artifact-files"
SLUG="aggressive-ads"

release_version="${AGGR_RELEASE_VERSION:-}"

if [[ -z "${release_version}" ]]; then
	# Match whatever the package job produced rather than guessing a version.
	package_path="$(find "${REPO_ROOT}/release" -maxdepth 1 -name "${SLUG}-*.zip" | sort | tail -n 1)"
else
	package_path="${REPO_ROOT}/release/${SLUG}-${release_version}.zip"
fi

if [[ -z "${package_path}" || ! -f "${package_path}" ]]; then
	echo "Artifact acceptance found no ${SLUG}-*.zip in release/." >&2
	exit 1
fi

if [[ ! -x "${WP_ENV}" || ! -x "${PLAYWRIGHT}" ]]; then
	echo "wp-env or Playwright is missing. Run pnpm install --frozen-lockfile." >&2
	exit 1
fi

package_name="$(basename "${package_path}")"

# Read the version out of the archive rather than trusting the filename or the
# workflow input. A ZIP named for one version containing another is exactly the
# defect this lane exists to catch.
expected_version="$(
	unzip -p "${package_path}" "${SLUG}/${SLUG}.php" \
		| sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' \
		| head -n 1
)"

if [[ -z "${expected_version}" ]]; then
	echo "Could not read the packaged plugin header version." >&2
	exit 1
fi

if [[ -n "${release_version}" && "${expected_version}" != "${release_version}" ]]; then
	echo "Packaged version ${expected_version} does not match ${release_version}." >&2
	exit 1
fi

mkdir -p "${ARTIFACT_FILES}"
find "${ARTIFACT_FILES}" -mindepth 1 -maxdepth 1 -type f -name '*.zip' -delete
cp "${package_path}" "${ARTIFACT_FILES}/${package_name}"

artifact_wp_env() {
	(
		cd "${CONFIG_DIR}"
		WP_ENV_HOME="${ARTIFACT_HOME}" CI=true "${WP_ENV}" "$@"
	)
}

cleanup() {
	if ! artifact_wp_env stop; then
		echo "Warning: artifact containers could not be stopped." >&2
	fi
}
trap cleanup EXIT

artifact_wp_env start
artifact_wp_env clean all --no-scripts
artifact_wp_env run cli wp plugin install \
	"/var/www/html/wp-content/aggr-artifacts/${package_name}" --activate --force

actual_version="$(artifact_wp_env run cli wp plugin get "${SLUG}" --field=version | tail -n 1 | tr -d '\r')"

if [[ "${actual_version}" != "${expected_version}" ]]; then
	echo "Installed version ${actual_version} does not match ${expected_version}." >&2
	exit 1
fi

cd "${REPO_ROOT}"
CI=1 \
	AGGR_E2E_ARTIFACT=true \
	AGGR_E2E_BASE_URL=http://localhost:9940 \
	AGGR_E2E_OUTPUT_DIR=.playwright-results-artifact \
	"${PLAYWRIGHT}" test --project=artifact

# A plugin can activate, serve pages, and still be writing fatals to the log on
# every request. Activation alone is a weaker claim than it looks.
#
# The single-quoted body must expand $log inside the container, not here.
# shellcheck disable=SC2016
artifact_wp_env run cli bash -c '
	log=/var/www/html/wp-content/debug.log
	if [[ -f "$log" ]] && grep -E "PHP (Fatal error|Parse error)" "$log"; then
		echo "Fatal PHP error found in the artifact smoke-test log." >&2
		exit 1
	fi
'

echo "Artifact acceptance passed for ${SLUG} ${expected_version}."
