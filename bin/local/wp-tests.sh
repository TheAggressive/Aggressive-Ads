#!/usr/bin/env bash
#
# Run the WordPress PHPUnit suites natively: no Docker, no sudo.
#
# This is a fast feedback loop, NOT a CI substitute, and the difference is not
# pedantry. CI pins MySQL 8.4 and PHP 8.4; this host runs whatever it has. The
# schema and dbDelta assertions are the ones that can legitimately disagree, so
# the versions actually used are printed on every run — a local-vs-CI
# disagreement should cost a glance, not an afternoon.
#
# `pnpm qa` against the Compose stack remains the contract for declaring a
# change finished. See docs/testing-strategy.md.
#
# Usage: wp-tests.sh [config-file …]

set -euo pipefail

cd "$(dirname "$0")/../.."

configs=( "$@" )

if [ "${#configs[@]}" -eq 0 ]; then
	configs=( phpunit-integration.xml.dist phpunit-multisite.xml.dist )
fi

bash bin/local/mysql.sh start
bash bin/local/wp-core.sh

WP_DIR="${AGGR_TESTS_WP_DIR:-.cache/ci/wordpress}"

# Assigned before export so a failing subshell is not masked by export's own
# exit status.
abspath="$(pwd -P)/${WP_DIR}"
plugin_dir="$(cd .. && pwd -P)"
config_file="$(pwd -P)/tests/wp-tests-config.php"

export AGGR_TESTS_RUNNER=native
export AGGR_TESTS_ABSPATH="${abspath}"
export AGGR_TESTS_PLUGIN_DIR="${plugin_dir}"
export AGGR_TESTS_DB_HOST="127.0.0.1:${AGGR_TESTS_DB_PORT:-13306}"

# PHPUnit's <env> elements do not overwrite a variable that is already set, so
# exporting here beats the container path baked into the XML without either
# config file needing a second copy for native runs.
export WP_PHPUNIT__TESTS_CONFIG="${config_file}"

status=0

for config in "${configs[@]}"; do
	echo
	echo "──────────────────────────────────────────────────────────────"
	echo "  native: ${config}"
	echo "──────────────────────────────────────────────────────────────"

	# Multisite is a constant in its own XML, but the core bootstrap reads the
	# environment before PHPUnit applies <const>, so it has to be set here too.
	if [[ "${config}" == *multisite* ]]; then
		WP_TESTS_MULTISITE=1 bash bin/ci/run-wp-tests.sh "${config}" || status=$?
	else
		bash bin/ci/run-wp-tests.sh "${config}" || status=$?
	fi

	if [ "${status}" -ne 0 ]; then
		break
	fi
done

echo
echo "Ran against MySQL $(bash bin/local/mysql.sh status >/dev/null 2>&1 && mysql --protocol=TCP -h127.0.0.1 -P"${AGGR_TESTS_DB_PORT:-13306}" -uroot -N -B -e 'SELECT VERSION();' 2>/dev/null || echo unknown) and PHP $(php -r 'echo PHP_VERSION;')."
echo "CI pins MySQL 8.4 and PHP 8.4 — schema behaviour there is authoritative."

exit "${status}"
