#!/usr/bin/env bash
#
# Fetch the pinned WP-CLI phar into .cache/ci/.
#
# The i18n gate diffs a freshly extracted POT against the committed one, so the
# extractor has to be the same program everywhere. "Whatever `wp` is on PATH"
# is not that program: two developers on different WP-CLI releases would flag
# each other's commits as drift forever, and the gate would be turned off
# inside a week.
#
# The checksum is not decoration. This downloads an executable over the network
# and then runs it against the whole source tree, in CI, with the repository
# checked out — the same argument that makes the plugin updater verify its own
# archive applies with more force here.

set -euo pipefail

cd "$(dirname "$0")/../.."

WP_CLI_VERSION="${WP_CLI_VERSION:-2.12.0}"
WP_CLI_SHA256="${WP_CLI_SHA256:-ce34ddd838f7351d6759068d09793f26755463b4a4610a5a5c0a97b68220d85c}"
WP_CLI_URL="https://github.com/wp-cli/wp-cli/releases/download/v${WP_CLI_VERSION}/wp-cli-${WP_CLI_VERSION}.phar"

TOOL_DIR="${WP_CLI_INSTALL_DIR:-.cache/ci}"
TARGET="${TOOL_DIR}/wp"

verify() {
	[ -f "$1" ] || return 1
	echo "${WP_CLI_SHA256}  $1" | sha256sum --check --status
}

if verify "${TARGET}"; then
	[ -n "${WP_CLI_SKIP_INFO:-}" ] || echo "install-wp-cli: ${TARGET} already at ${WP_CLI_VERSION}"
	exit 0
fi

mkdir -p "${TOOL_DIR}"

# Downloaded beside the target and moved only after the checksum passes, so an
# interrupted or tampered download never leaves a runnable file in place.
staging="${TARGET}.download"
trap 'rm -f "${staging}"' EXIT

echo "install-wp-cli: fetching WP-CLI ${WP_CLI_VERSION}"
curl --fail --silent --show-error --location --retry 3 --output "${staging}" "${WP_CLI_URL}"

if ! verify "${staging}"; then
	echo "install-wp-cli: checksum mismatch for ${WP_CLI_URL}" >&2
	echo "  expected ${WP_CLI_SHA256}" >&2
	echo "  actual   $(sha256sum "${staging}" | awk '{print $1}')" >&2
	exit 1
fi

chmod 0755 "${staging}"
mv "${staging}" "${TARGET}"

echo "install-wp-cli: ${TARGET} (${WP_CLI_VERSION})"
