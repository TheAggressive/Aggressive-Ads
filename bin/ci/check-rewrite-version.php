<?php
/**
 * Rewrite rules and their declared version must move together.
 *
 * `Rewrite_Flusher` writes the rules once, when a declared REWRITE_VERSION
 * moves. Nothing else re-writes them on an already-active plugin, so a change
 * to a rule that forgets the bump ships rules that exist in the code and not
 * in the database. The site 404s on a path the code says it serves, no log
 * records anything, and it reads as a broken deploy rather than a stale cache.
 *
 * `Install\Rewrite_Health` catches that at runtime, but only on the site it is
 * looking at, and only once somebody opens Site Health. This catches it before
 * the change is pushed.
 *
 * It compares the rules as *data* — `Router::rules()` and `Click_Hop::rules()`
 * are pure and static for this reason — rather than as source text, so
 * reformatting a method or editing its docblock is not a rule change, and a
 * new rule assembled from constants somewhere else still is.
 *
 * The recorded history is append-only on purpose. Re-recording a hash under
 * the version already there is the exact mistake this exists to stop, so the
 * only way to make the guard pass after a rule change is to append an entry,
 * and appending requires a version higher than the last one.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Portal\Router;
use Aggressive\Ads\Workflow\Click_Hop;

$root = dirname( __DIR__, 2 );

require_once $root . '/inc/class-autoloader.php';

\Aggressive\Ads\Autoloader::register( $root . '/inc' );

/**
 * The rule sets under version control, and the constant that versions each.
 *
 * Keyed by the name used in the contract file. Adding a rule set means adding
 * it here and in the contract; a set that installs rules and appears in
 * neither is caught by the stray-call scan below.
 */
$sets = array(
	'portal'   => array(
		'class'    => Router::class,
		'version'  => Router::REWRITE_VERSION,
		'constant' => 'Portal\Router::REWRITE_VERSION',
	),
	'delivery' => array(
		'class'    => Click_Hop::class,
		'version'  => Click_Hop::REWRITE_VERSION,
		'constant' => 'Workflow\Click_Hop::REWRITE_VERSION',
	),
);

$contract_path = getenv( 'AGGR_REWRITE_CONTRACT' );
$contract_path = is_string( $contract_path ) && '' !== $contract_path
	? $contract_path
	: $root . '/bin/ci/rewrite-contract.json';

/*
 * Overridable only so this guard's own tests can point it at fixtures — the
 * same reason check-navigation.mjs takes AGGR_NAVIGATION_SCAN_DIR. A guard
 * nobody exercises rots into one that permits everything, silently.
 */
$scan_dir = getenv( 'AGGR_REWRITE_SCAN_DIR' );
$scan_dir = is_string( $scan_dir ) && '' !== $scan_dir ? $scan_dir : $root . '/inc';

/**
 * A stable serialization of one rule set.
 *
 * Deliberately not JSON: the hash is compared across machines and PHP builds,
 * and a serializer with escaping options is one flag away from producing two
 * spellings of the same rules.
 *
 * @param array<int, array{regex: string, query: string, position: string}> $rules Declared rules.
 * @return string
 */
function aggr_rewrite_fingerprint( array $rules ): string {
	$lines = array();

	foreach ( $rules as $rule ) {
		$lines[] = $rule['regex'] . "\t" . $rule['query'] . "\t" . $rule['position'];
	}

	return hash( 'sha256', implode( "\n", $lines ) );
}

/**
 * Every PHP file under a directory.
 *
 * @param string $dir Directory to walk.
 * @return array<int, string>
 */
function aggr_rewrite_php_files( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}

	$files    = array();
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );

	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}

	sort( $files );

	return $files;
}

/**
 * The nearest token either side of a position that is not whitespace or a comment.
 *
 * @param array<int, array{0: int, 1: string, 2: int}|string> $tokens    Token stream.
 * @param int                                                 $index     Starting position.
 * @param int                                                 $direction -1 to look back, 1 to look forward.
 * @return array{0: int, 1: string, 2: int}|string|null
 */
function aggr_rewrite_significant( array $tokens, int $index, int $direction ) {
	for ( $i = $index + $direction; isset( $tokens[ $i ] ); $i += $direction ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return $token;
	}

	return null;
}

/**
 * Files that call add_rewrite_rule(), read as code rather than as text.
 *
 * A grep would report the docblocks above that name the function, and a guard
 * that fires on prose is one people learn to route around.
 *
 * @param string $dir Directory to walk.
 * @return array<int, string> Repository-relative paths, each listed once.
 */
function aggr_rewrite_installers( string $dir ): array {
	$found = array();

	foreach ( aggr_rewrite_php_files( $dir ) as $path ) {
		$source = file_get_contents( $path );

		if ( false === $source ) {
			continue;
		}

		$tokens = token_get_all( $source );
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];

			if ( ! is_array( $token ) || T_STRING !== $token[0] || 'add_rewrite_rule' !== $token[1] ) {
				continue;
			}

			/*
			 * Only a bare call installs a core rule. A declaration, a method
			 * call and a static call all read as the same T_STRING, and they
			 * are told apart by what sits either side of it — skipping the
			 * whitespace between, which is what the first version of this
			 * forgot: `public function add_rewrite_rule()` has a T_WHITESPACE
			 * at $i - 1, so the lookbehind saw nothing and the guard reported
			 * a declaration as an installation. Found by its own test.
			 */
			$previous = aggr_rewrite_significant( $tokens, $i, -1 );
			$next     = aggr_rewrite_significant( $tokens, $i, 1 );

			if ( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION, T_DOUBLE_COLON ), true ) ) {
				continue;
			}

			if ( '(' !== $next ) {
				continue;
			}

			$found[ $path ] = true;

			break;
		}
	}

	return array_keys( $found );
}

$failures = array();

$raw      = file_get_contents( $contract_path );
$contract = false === $raw ? null : json_decode( $raw, true );

if ( ! is_array( $contract ) ) {
	fwrite( STDERR, "check-rewrite-version: {$contract_path} is missing or is not valid JSON.\n" );

	exit( 1 );
}

foreach ( $sets as $name => $set ) {
	if ( ! isset( $contract[ $name ]['history'] ) || ! is_array( $contract[ $name ]['history'] ) || array() === $contract[ $name ]['history'] ) {
		$failures[] = "{$name}: no history recorded in " . basename( $contract_path ) . '.';

		continue;
	}

	$history  = array_values( $contract[ $name ]['history'] );
	$previous = 0;
	$sound    = true;

	foreach ( $history as $index => $entry ) {
		$version = isset( $entry['version'] ) && is_int( $entry['version'] ) ? $entry['version'] : 0;
		$hash    = isset( $entry['hash'] ) && is_string( $entry['hash'] ) ? $entry['hash'] : '';

		if ( $version < 1 || 1 !== preg_match( '/^[0-9a-f]{64}$/', $hash ) ) {
			$failures[] = "{$name}: history entry {$index} needs an integer version and a sha256 hash.";
			$sound      = false;

			break;
		}

		if ( $version <= $previous ) {
			$failures[] = "{$name}: history version {$version} does not follow {$previous}. History is append-only and versions must increase.";
			$sound      = false;

			break;
		}

		$previous = $version;
	}

	if ( ! $sound ) {
		continue;
	}

	$latest   = $history[ count( $history ) - 1 ];
	$declared = $set['class']::rules();
	$actual   = aggr_rewrite_fingerprint( $declared );
	$expected = (string) $latest['hash'];
	$recorded = (int) $latest['version'];

	if ( $recorded !== $set['version'] ) {
		$failures[] = sprintf(
			'%s: %s is %d but the newest recorded version is %d. Append a history entry for %d, or restore the constant.',
			$name,
			$set['constant'],
			$set['version'],
			$recorded,
			$set['version']
		);

		continue;
	}

	if ( ! hash_equals( $expected, $actual ) ) {
		$failures[] = sprintf(
			"%s: the rules changed but %s is still %d.\n"
				. "    Bump it to %d and append to %s:\n"
				. '      { "version": %d, "hash": "%s" }',
			$name,
			$set['constant'],
			$set['version'],
			$set['version'] + 1,
			basename( $contract_path ),
			$set['version'] + 1,
			$actual
		);
	}
}

$known = array();

foreach ( array_keys( $sets ) as $name ) {
	$declared_file = isset( $contract[ $name ]['installer'] ) && is_string( $contract[ $name ]['installer'] )
		? $contract[ $name ]['installer']
		: '';

	if ( '' !== $declared_file ) {
		$known[] = $declared_file;
	}
}

foreach ( aggr_rewrite_installers( $scan_dir ) as $path ) {
	$relative = ltrim( str_replace( $root, '', $path ), '/' );

	if ( ! in_array( $relative, $known, true ) ) {
		$failures[] = "{$relative}: installs a rewrite rule outside a versioned rule set. Declare it through a static rules() method and record it in " . basename( $contract_path ) . '.';
	}
}

if ( array() !== $failures ) {
	fwrite( STDERR, "check-rewrite-version: rewrite rules and their declared versions disagree.\n\n" );

	foreach ( $failures as $failure ) {
		fwrite( STDERR, "  {$failure}\n" );
	}

	fwrite( STDERR, "\nSee docs/portal-routing-and-ui.md for the procedure.\n" );

	exit( 1 );
}

echo 'check-rewrite-version: ok (' . count( $sets ) . " rule sets)\n";
