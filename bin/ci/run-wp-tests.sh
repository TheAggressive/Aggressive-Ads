#!/usr/bin/env bash
#
# Runs a WordPress PHPUnit suite and refuses to believe a silent success.
#
# The runner's exit code is not sufficient evidence that the suite ran. Portal
# code redirects and then calls exit(), and an exit() inside a test kills the
# PHPUnit process itself: no summary is printed, no exit code is set, and
# `wp-env run` reports a clean pass. Ten test classes — the whole REST suite and
# the upgrader among them — stopped running that way, and CI stayed green for as
# long as it took somebody to run one of them on its own.
#
# So the exit code is checked, and then the JUnit report is checked, because the
# report only exists if PHPUnit reached the end of the run. A missing or
# incomplete report is treated as a failure rather than as an absence of news.
#
# Usage: run-wp-tests.sh <config-file> [extra phpunit args…]

set -euo pipefail

cd "$(dirname "$0")/../.."

config="${1:?usage: run-wp-tests.sh <config-file> [args…]}"
shift || true

plugin_path="wp-content/plugins/aggressive-ads"
report="build/test-results/$(basename "${config}" .xml.dist).xml"

mkdir -p "$(dirname "${report}")"
rm -f "${report}"

status=0
pnpm exec wp-env run tests-wordpress \
	php "${plugin_path}/vendor/bin/phpunit" \
	-c "${plugin_path}/${config}" \
	--log-junit "${plugin_path}/${report}" \
	"$@" || status=$?

if [ "${status}" -ne 0 ]; then
	echo "run-wp-tests: ${config} failed (exit ${status})" >&2
	exit "${status}"
fi

if [ ! -s "${report}" ]; then
	echo "run-wp-tests: ${config} reported success but wrote no JUnit report." >&2
	echo "The suite did not reach the end of the run — most likely a test hit an" >&2
	echo "exit() or a fatal error. See bin/ci/run-wp-tests.sh and Redirect_Trap." >&2
	exit 1
fi

php -r '
$report = $argv[1];
$xml = @simplexml_load_file( $report );

if ( false === $xml ) {
	fwrite( STDERR, "run-wp-tests: JUnit report at {$report} is not readable XML.\n" );
	exit( 1 );
}

$tests = 0;
$bad   = 0;

foreach ( $xml->xpath( "//testsuite[not(testsuite)]" ) ?: array() as $suite ) {
	$tests += (int) $suite["tests"];
	$bad   += (int) $suite["failures"] + (int) $suite["errors"];
}

if ( 0 === $tests ) {
	fwrite( STDERR, "run-wp-tests: the report records zero tests.\n" );
	exit( 1 );
}

if ( $bad > 0 ) {
	fwrite( STDERR, "run-wp-tests: {$bad} failing test(s) in a run the runner called clean.\n" );
	exit( 1 );
}

echo "run-wp-tests: {$tests} tests, report complete\n";
' "${report}"
