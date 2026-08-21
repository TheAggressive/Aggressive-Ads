#!/usr/bin/env bash
#
# Forward-compatibility PHP lane.
#
# Runs the WordPress PHP suites against a PHP version NEWER than the supported
# floor, to surface deprecations before a host upgrade does.
#
# This exists because development, CI and the release gate deliberately run the
# same PHP version — 8.4 in compose.yml, ci.yml, phpstan.neon and the plugin's
# own floor guard. That parity is what stops an API missing on the floor from
# passing locally and failing in Actions, and it is worth keeping. But it also
# means nothing in the repository ever exercises a newer PHP, so a deprecation
# lands the day a host upgrades rather than the week it is introduced.
#
# Forward coverage therefore belongs on a schedule, in its own environment,
# rather than in a development environment that would then disagree with the
# gate.
#
# Usage: bin/ci/php-forward.sh <php-version>   e.g. bin/ci/php-forward.sh 8.5
set -euo pipefail

PHP_VERSION="${1:?Usage: php-forward.sh <php-version> (e.g. 8.5)}"

# Deliberately not anchored to 8.x, so this keeps working when the plugin
# eventually targets PHP 9 without a change nobody will remember to make.
if [[ ! "${PHP_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
	echo "Expected a PHP major.minor version such as 8.5, got '${PHP_VERSION}'." >&2
	exit 2
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

cd "${REPO_ROOT}"

echo "=== PHP ${PHP_VERSION} forward-compatibility run ==="

case "${PHP_VERSION}" in
	8.5)
		export AGGR_WORDPRESS_IMAGE='wordpress:7.1-php8.5-apache@sha256:26cc4158e9665d943362bd224a0610a1e487514a1e13aa96512366b425c0cab0'
		export AGGR_WP_CLI_IMAGE='wordpress:cli-2.12.0-php8.5@sha256:c2685291859c333b38afdbf882c5b9abdc0423703f3a8c6539bfd5ee3e7e2656'
		;;
	*)
		echo "No pinned WordPress images are declared for PHP ${PHP_VERSION}." >&2
		exit 2
		;;
esac

# A distinct project and port keep this scheduled run away from the normal
# local environment without maintaining a second Compose definition.
export AGGR_COMPOSE_PROJECT=aggressive-ads-forward
export AGGR_WP_PORT=9930

cleanup() {
	bash bin/ci/environment.sh stop >/dev/null 2>&1 || true
}
trap cleanup EXIT

bash bin/ci/environment.sh start

# The suites that actually load WordPress. Static analysis is pinned to the
# floor by phpstan.neon and says nothing about a newer runtime, so running it
# here would report the floor's answer twice rather than the new one once.
pnpm test:php:integration
pnpm test:php:multisite

echo "PHP ${PHP_VERSION} forward-compatibility run passed."
