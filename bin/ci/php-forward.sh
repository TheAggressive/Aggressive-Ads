#!/usr/bin/env bash
#
# Forward-compatibility PHP lane.
#
# Runs the WordPress PHP suites against a PHP version NEWER than the supported
# floor, to surface deprecations before a host upgrade does.
#
# This exists because development, CI and the release gate deliberately run the
# same PHP version — 8.4 in .wp-env.json, ci.yml, phpstan.neon and the plugin's
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

# wp-env reads these ahead of the committed .wp-env.json, so the parity
# configuration stays the single source of truth for everything else.
export WP_ENV_PHP_VERSION="${PHP_VERSION}"

# Its own home and ports, so a scheduled run can never disturb the environment
# `pnpm qa` depends on. Under .cache/ because every scanner here already
# excludes that tree — a new top-level directory would silently become PHPCS
# input.
export WP_ENV_HOME="${REPO_ROOT}/.cache/ci/wp-env-forward"
# 9930/9931, chosen because everything nearby is taken: the plugin's own
# environment, the artifact environment on 9940, and — the one that actually
# bit — an unrelated LAAO site holding 9950 on this machine. A forward run that
# collides with a developer's other project is a forward run nobody will keep.
export WP_ENV_PORT=9930
export WP_ENV_TESTS_PORT=9931

cleanup() {
	pnpm exec wp-env stop >/dev/null 2>&1 || true
}
trap cleanup EXIT

pnpm exec wp-env start

# The suites that actually load WordPress. Static analysis is pinned to the
# floor by phpstan.neon and says nothing about a newer runtime, so running it
# here would report the floor's answer twice rather than the new one once.
pnpm test:php:integration
pnpm test:php:multisite

echo "PHP ${PHP_VERSION} forward-compatibility run passed."
