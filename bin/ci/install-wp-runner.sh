#!/usr/bin/env bash
#
# Installs the PHPUnit 9.6 runner the WordPress suites need.
#
# The plugin's own composer.json runs PHPUnit 13, matching the LAAO theme. The
# suites that load real WordPress cannot: wp-phpunit's WP_UnitTestCase_Base
# calls PHPUnit\Util\Test::parseTestMethodAnnotations() from its constructor,
# and PHPUnit removed PHPUnit\Util\Test in 10. Two majors of one package cannot
# live in one composer.json, so the old one lives in tests/wp/ with its own
# vendor. See tests/wp/README.md.
#
# Idempotent and cheap on a warm checkout: composer install is a no-op when the
# lock is already satisfied, so every caller can just call this.

set -euo pipefail

cd "$(dirname "$0")/../.."

runner_dir="tests/wp"

if [ ! -f "${runner_dir}/composer.json" ]; then
	echo "install-wp-runner: ${runner_dir}/composer.json is missing." >&2
	exit 1
fi

# --no-scripts because this project has none and should never gain any: it
# exists to place three packages on disk.
composer install \
	--working-dir="${runner_dir}" \
	--no-interaction \
	--no-progress \
	--no-scripts \
	--prefer-dist

echo "install-wp-runner: $("${runner_dir}/vendor/bin/phpunit" --version | head -1)"
