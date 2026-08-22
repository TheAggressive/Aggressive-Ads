#!/usr/bin/env bash
#
# Fetch the WordPress core the native test runner boots against.
#
# The core PHPUnit library needs a real ABSPATH. The container gets one from the
# image; a native run has to put one somewhere, and it must be the same release
# the pinned image carries or the two runners are testing different WordPress.
#
# Downloaded with the pinned WP-CLI rather than curl, because `core download`
# verifies the release against api.wordpress.org's checksums and `core
# verify-checksums` re-checks every file afterwards.

set -euo pipefail

cd "$(dirname "$0")/../.."

# Must track compose.yml's wordpress image tag. There is no way to derive one
# from the other, so they are grepped against each other below.
WP_VERSION="${AGGR_TESTS_WP_VERSION:-7.1}"
WP_DIR="${AGGR_TESTS_WP_DIR:-.cache/ci/wordpress}"

if ! grep -q "wordpress:${WP_VERSION}-" compose.yml; then
	echo "wp-core: compose.yml no longer pins wordpress:${WP_VERSION}-*" >&2
	echo "The native runner and the container must boot the same release." >&2
	echo "Update AGGR_TESTS_WP_VERSION in bin/local/wp-core.sh to match." >&2
	exit 1
fi

bash bin/ci/install-wp-cli.sh

# Deprecations are silenced, warnings and errors are not.
#
# WP-CLI 2.12.0 is not clean under the PHP this host runs (the container's 8.4
# never sees them), and PHP CLI prints notices to stdout — so they land in the
# middle of command output and any parse of it. Suppressing the class that is
# noise here keeps a real failure loud.
wp_cli() {
	php -d error_reporting='E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' \
		.cache/ci/wp --path="${WP_DIR}" "$@"
}

installed_version() {
	[ -f "${WP_DIR}/wp-includes/version.php" ] || return 1
	grep -m1 -oE "\\\$wp_version = '[^']+'" "${WP_DIR}/wp-includes/version.php" \
		| cut -d"'" -f2
}

if [ "$(installed_version || true)" = "${WP_VERSION}" ]; then
	echo "wp-core: ${WP_DIR} already at ${WP_VERSION}"
else
	echo "wp-core: downloading WordPress ${WP_VERSION}"
	mkdir -p "${WP_DIR}"
	wp_cli core download --version="${WP_VERSION}" --skip-content --force
fi

# The suites are not the place to discover a truncated download. Core's own
# checksum list is authoritative and costs a second.
if ! wp_cli core verify-checksums >/dev/null; then
	echo "wp-core: ${WP_DIR} failed core verify-checksums" >&2
	echo "Delete it and re-run to fetch a clean copy." >&2
	exit 1
fi

# The multisite suite calls activate_plugin( plugin_basename( AGGR_PLUGIN_FILE ) ).
# plugin_basename() maps a path only by stripping WP_PLUGIN_DIR, and the
# bootstrap loads this plugin straight from the checkout rather than through
# wp_register_plugin_realpath() — so a symlink into wp-content/plugins does not
# help and the tests die with "Plugin file does not exist". The native config
# points WP_PLUGIN_DIR at the directory that really contains the checkout; this
# only asserts that it does.
plugins_parent="$(cd .. && pwd -P)"

if [ ! -f "${plugins_parent}/aggressive-ads/aggressive-ads.php" ]; then
	echo "wp-core: ${plugins_parent} does not contain aggressive-ads/." >&2
	echo "The native runner needs the checkout inside a plugins directory so" >&2
	echo "plugin_basename() can resolve it. See docs/testing-strategy.md." >&2
	exit 1
fi

echo "wp-core: ${WP_DIR} (${WP_VERSION}), plugins dir ${plugins_parent}"
