#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/../.."

report=.phpunit.cache/unit-coverage.xml
mkdir -p "$(dirname "$report")"

driver=$(php -r 'echo extension_loaded("pcov") ? "pcov" : (extension_loaded("xdebug") ? "xdebug" : "");')

case "$driver" in
	pcov)
		php -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover "$report"
		;;
	xdebug)
		XDEBUG_MODE=coverage php vendor/bin/phpunit --coverage-clover "$report"
		;;
	*)
		if ! command -v phpdbg >/dev/null 2>&1; then
			echo "ci:coverage: install PCOV, Xdebug, or phpdbg to collect coverage" >&2
			exit 1
		fi
		phpdbg -qrr vendor/bin/phpunit --coverage-clover "$report"
		;;
esac

node bin/ci/check-coverage.mjs "$report"
