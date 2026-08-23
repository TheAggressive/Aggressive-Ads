#!/usr/bin/env bash
#
# Runs a WordPress PHPUnit suite and refuses to believe a silent success.
#
# The runner's exit code is not sufficient evidence that the suite ran. Portal
# code redirects and then calls exit(), and an exit() inside a test kills the
# PHPUnit process itself: no summary is printed, no exit code is set, and
# a container runner can report a clean pass. Ten test classes — the whole REST
# suite and the upgrader among them — stopped running that way, and CI stayed
# green for as long as it took somebody to run one of them on its own.
#
# So the exit code is checked, and then the JUnit report is checked, because the
# report only exists if PHPUnit reached the end of the run. A missing or
# incomplete report is treated as a failure rather than as an absence of news.
#
# Two environments and two PHPUnits, one report check. AGGR_TESTS_RUNNER=native
# runs on this host against bin/local/mysql.sh; anything else runs inside the
# Compose stack, which is what CI does and what the default stays. Separately,
# the config file selects the PHPUnit major — 13 for the unit suite, 9.6 for the
# WordPress suites — for the reason in tests/wp/README.md. The verification
# below is deliberately shared: it is the half that catches a suite dying
# mid-run, and a runner that skipped it would be the one place that could
# report a clean pass over ten dead test classes again.
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

# Which PHPUnit runs is decided by the config file, and by nothing else.
#
# The unit config runs on the plugin's own PHPUnit 13; the WordPress configs run
# on the 9.6 quarantined in tests/wp/, because wp-phpunit cannot run on 10 or
# later. Deciding it here means no caller — not verify.sh, not run-coverage.sh,
# not a person typing a command — has to know which suite is on which major.
# See tests/wp/README.md.
case "${config}" in
	*integration*|*multisite*)
		bash bin/ci/install-wp-runner.sh >/dev/null
		phpunit_bin="tests/wp/vendor/bin/phpunit"
		;;
	*)
		phpunit_bin="vendor/bin/phpunit"
		;;
esac

if [ ! -x "${phpunit_bin}" ]; then
	echo "run-wp-tests: ${phpunit_bin} is missing. Run composer install." >&2
	exit 1
fi

status=0

if [ "${AGGR_TESTS_RUNNER:-docker}" = "native" ]; then
	# bin/local/wp-tests.sh has already exported AGGR_TESTS_* and pointed
	# WP_PHPUNIT__TESTS_CONFIG at this checkout's config. Paths are host paths,
	# and nothing here needs root, so there is no ownership to restore.
	"${phpunit_bin}" \
		-c "${config}" \
		--log-junit "${report}" \
		"$@" || status=$?
else
	# Point at the native runner rather than let Docker's own connection error
	# be the whole answer. `test:php:integration` is the name a person reaches
	# for first, and on a machine that deliberately has no Docker it fails with
	# a daemon-socket message that says nothing about the suite that does run
	# there. A no-op in CI, where the daemon is up.
	#
	# The probe runs in a subshell whose stderr is discarded because the docker
	# CLI does not always fail cleanly: with Docker Desktop stopped under WSL it
	# dies of SIGBUS, and the shell then prints "Bus error (core dumped)" over
	# the instruction below. Redirecting docker's own output is not enough — the
	# message comes from the shell that reaps it.
	if ! ( docker info >/dev/null 2>&1 ) 2>/dev/null; then
		echo "run-wp-tests: ${config} runs inside the Compose stack, and Docker is not available." >&2
		echo "  Locally: pnpm test:php:native runs these same suites on a local MySQL." >&2
		echo "  The containerized run is a CI lane. See docs/testing-strategy.md." >&2
		exit 1
	fi

	# PHPUnit runs as root so it can write reports into the host checkout.
	# Restore anything it creates under uploads to the same user that serves
	# web requests.
	restore_web_ownership() {
		bash bin/ci/environment.sh exec \
			chown -R www-data:www-data /var/www/html/wp-content/uploads \
			>/dev/null 2>&1 || true
	}
	trap restore_web_ownership EXIT

	bash bin/ci/environment.sh exec \
		php "/var/www/html/${plugin_path}/${phpunit_bin}" \
		-c "${plugin_path}/${config}" \
		--log-junit "${plugin_path}/${report}" \
		"$@" || status=$?
fi

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

# The body is PHP, not shell: $report and $xml are PHP variables and must not
# be expanded by the shell before php sees them.
# shellcheck disable=SC2016
php -r '
$report = $argv[1];
$xml = @simplexml_load_file( $report );

if ( false === $xml ) {
	fwrite( STDERR, "run-wp-tests: JUnit report at {$report} is not readable XML.\n" );
	exit( 1 );
}

$tests     = 0;
$bad       = 0;
$summaries = "testsuite" === $xml->getName() ? array( $xml ) : $xml->testsuite;

foreach ( $summaries as $suite ) {
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
