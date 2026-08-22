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
# A separate Compose project and an empty plugin directory keep the source tree
# out of this environment. The only plugin bytes it can see arrive in the ZIP.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"
PLAYWRIGHT="${REPO_ROOT}/node_modules/.bin/playwright"
ARTIFACT_FILES="${REPO_ROOT}/.cache/ci/artifact-files"
ARTIFACT_PLUGINS="${REPO_ROOT}/.cache/ci/artifact-plugins"
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

if [[ ! -x "${PLAYWRIGHT}" ]]; then
	echo "Playwright is missing. Run pnpm install --frozen-lockfile." >&2
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

mkdir -p "${ARTIFACT_FILES}" "${ARTIFACT_PLUGINS}"
find "${ARTIFACT_FILES}" -mindepth 1 -maxdepth 1 -type f -name '*.zip' -delete
find "${ARTIFACT_PLUGINS}" -mindepth 1 -delete
cp "${package_path}" "${ARTIFACT_FILES}/${package_name}"

export AGGR_ARTIFACTS_SOURCE="${ARTIFACT_FILES}"
export AGGR_COMPOSE_PROJECT=aggressive-ads-artifact
export AGGR_PLUGIN_SOURCE="${ARTIFACT_PLUGINS}"
export AGGR_PLUGIN_TARGET=/var/www/html/wp-content/plugins
export AGGR_SKIP_PLUGIN_ACTIVATION=1
export AGGR_WP_PORT=9940
export AGGR_WP_USER=root

artifact_environment() {
	bash "${REPO_ROOT}/bin/ci/environment.sh" "$@"
}

cleanup() {
	artifact_environment exec chown -R "$(id -u):$(id -g)" /var/www/html/wp-content/plugins >/dev/null 2>&1 || true
	if ! artifact_environment stop; then
		echo "Warning: artifact containers could not be stopped." >&2
	fi
}
trap cleanup EXIT

artifact_environment start
artifact_environment wp plugin install \
	"/var/www/html/wp-content/aggr-artifacts/${package_name}" --activate --force

actual_version="$(artifact_environment wp plugin get "${SLUG}" --field=version | tail -n 1 | tr -d '\r')"

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
artifact_environment exec bash -c '
	log=/var/www/html/wp-content/debug.log
	if [[ -f "$log" ]] && grep -E "PHP (Fatal error|Parse error)" "$log"; then
		echo "Fatal PHP error found in the artifact smoke-test log." >&2
		exit 1
	fi
'

echo "Artifact acceptance passed for ${SLUG} ${expected_version}."
