#!/usr/bin/env bash

set -euo pipefail

cd "$(dirname "$0")/../.."

report_dir=.phpunit.cache
unit_report="${report_dir}/unit-coverage.xml"
integration_report="${report_dir}/integration-coverage.xml"
plugin_path=wp-content/plugins/aggressive-ads

mkdir -p "$report_dir"
rm -f "$unit_report" "$integration_report"

# Both reports use the same PHP build and coverage driver, avoiding a second
# source of disagreement about executable lines. The unit suite's bootstrap
# stays WordPress-free while its hits are unioned with the WordPress suite.
if ! bash bin/ci/environment.sh exec php -r \
	'exit( extension_loaded( "pcov" ) ? 0 : 1 );'; then
	echo "ci:coverage: the WordPress test image does not have PCOV enabled." >&2
	echo "Start it with: pnpm env:start" >&2
	exit 1
fi

bash bin/ci/run-wp-tests.sh phpunit.xml.dist \
	--coverage-clover "${plugin_path}/${unit_report}"
bash bin/ci/run-wp-tests.sh phpunit-integration.xml.dist \
	--coverage-clover "${plugin_path}/${integration_report}"

node bin/ci/check-coverage.mjs "$unit_report" "$integration_report"
