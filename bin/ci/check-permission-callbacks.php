<?php
/**
 * REST permission gate.
 *
 * Every route needs a real permission callback. An unauthenticated endpoint on
 * this system exposes another organization's unpublished creative, so the gate
 * that prevents one is worth more than the convenience of writing it as grep.
 *
 * **This replaced a grep, and the grep did not work.** It matched exactly one
 * spelling — `'permission_callback' => '__return_true'`, on a single line — and
 * testing it found four ways past that a person would reach for without
 * thinking, plus a fifth that leaves no trace in the source at all:
 *
 *   1. the same literal wrapped onto the next line, which is what a long array
 *      looks like after formatting;
 *   2. `fn () => true`;
 *   3. `function () { return true; }`;
 *   4. a route registered with no `permission_callback` key whatsoever — which
 *      WordPress has warned about since 5.5 and still registers as public; and
 *   5. a missing scan directory, which made the whole gate exit 0 and print
 *      "ok" over nothing at all.
 *
 * Numbers 2 and 3 are the dangerous ones. "I'll just return true for now" is
 * how a debugging shortcut gets committed, and it is the form a reviewer's eye
 * slides over, because it looks like code rather than like a stub.
 *
 * So this reads code as code, exactly as check-boundaries.php does and for the
 * same reason recorded there: reading code as text cannot tell a call from
 * prose about a call. A docblock naming `__return_true` — including the one
 * above — is prose, and must not fail the build.
 *
 * See docs/rest-api.md and docs/threat-model.md.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$scan = getenv( 'AGGR_PERMISSION_SCAN_DIR' );
$scan = is_string( $scan ) && '' !== $scan ? $scan : $root . '/inc';

if ( ! is_dir( $scan ) ) {
	fwrite( STDERR, "check-permission-callbacks: scan directory does not exist: {$scan}\n" );
	fwrite( STDERR, "A gate that cannot find the code it guards must fail, not pass.\n" );

	exit( 1 );
}

/**
 * Functions that register a REST route, and the argument position of the
 * options array.
 *
 * Everything here funnels through `Creative_File_Controller::register_route()`,
 * which is the only caller of `register_rest_route()`. Both are listed so the
 * gate keeps working if that ever stops being true.
 */
const REGISTRARS = array( 'register_rest_route', 'register_route' );

/**
 * Every PHP file under a directory, sorted.
 *
 * @param string $dir Directory to walk.
 * @return array<int, string>
 */
function php_files( string $dir ): array {
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
 * The next meaningful token index at or after a position.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $from   Starting index.
 * @return int|null
 */
function next_meaningful( array $tokens, int $from ): ?int {
	$count = count( $tokens );

	for ( $i = $from; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		return $i;
	}

	return null;
}

/**
 * Whether a token is one of the given single-character punctuation marks.
 *
 * @param mixed              $token Token.
 * @param array<int, string> $chars Characters to test.
 * @return bool
 */
function is_punct( mixed $token, array $chars ): bool {
	return is_string( $token ) && in_array( $token, $chars, true );
}

/**
 * Indexes of the meaningful tokens inside a balanced bracket run.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $open   Index of the opening bracket.
 * @return array<int, int>
 */
function balanced_body( array $tokens, int $open ): array {
	$count = count( $tokens );
	$depth = 0;
	$body  = array();

	for ( $i = $open; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_punct( $token, array( '(', '[', '{' ) ) ) {
			++$depth;

			if ( 1 === $depth ) {
				continue;
			}
		}

		if ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			++$depth;
		}

		if ( is_punct( $token, array( ')', ']', '}' ) ) ) {
			--$depth;

			if ( 0 === $depth ) {
				return $body;
			}
		}

		if ( $depth >= 1 && ! ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
			$body[] = $i;
		}
	}

	return $body;
}

/**
 * The call's top-level arguments, each as a list of token indexes.
 *
 * Splitting on depth-0 commas is the whole point: `array( $this, 'index' )` is
 * one argument containing a comma, and treating that comma as a separator is
 * how the first version of this convinced itself the options were a variable.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $open   Index of the opening parenthesis.
 * @return array<int, array<int, int>>
 */
function call_arguments( array $tokens, int $open ): array {
	$count = count( $tokens );
	$depth = 0;
	$args  = array();
	$here  = array();

	for ( $i = $open; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( is_punct( $token, array( '(', '[', '{' ) ) ) {
			++$depth;

			if ( 1 === $depth ) {
				continue;
			}
		}

		if ( is_punct( $token, array( ')', ']', '}' ) ) ) {
			--$depth;

			if ( 0 === $depth ) {
				if ( array() !== $here ) {
					$args[] = $here;
				}

				return $args;
			}
		}

		if ( 1 === $depth && is_punct( $token, array( ',' ) ) ) {
			$args[] = $here;
			$here   = array();

			continue;
		}

		if ( $depth >= 1 && ! ( is_array( $token ) && in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
			$here[] = $i;
		}
	}

	return $args;
}

/**
 * Whether a callback value is trivially true.
 *
 * Three forms, all of which mean "no gate": the `__return_true` literal, an
 * arrow function whose whole body is `true`, and a closure whose whole body is
 * `return true;`. A callback with any real logic in it is this gate's business
 * to allow, not to judge — only the empty ones are refused.
 *
 * @param array<int, mixed> $tokens Token stream.
 * @param int               $start  Index of the first value token.
 * @return string|null Description of the violation, or null when acceptable.
 */
function trivially_true( array $tokens, int $start ): ?string {
	$token = $tokens[ $start ];

	if ( is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] ) {
		return '__return_true' === trim( $token[1], "'\"" ) ? "'__return_true'" : null;
	}

	if ( ! is_array( $token ) ) {
		return null;
	}

	// `static fn` and `static function` put T_STATIC first.
	$index = $start;

	if ( T_STATIC === $tokens[ $index ][0] ) {
		$index = next_meaningful( $tokens, $index + 1 ) ?? $index;
	}

	if ( ! is_array( $tokens[ $index ] ) ) {
		return null;
	}

	$kind = $tokens[ $index ][0];

	if ( T_FN === $kind ) {
		// The shape is `fn ( ... ): type => BODY`, so find the arrow first.
		$arrow = null;
		$count = count( $tokens );

		for ( $i = $index; $i < $count; $i++ ) {
			if ( is_array( $tokens[ $i ] ) && T_DOUBLE_ARROW === $tokens[ $i ][0] ) {
				$arrow = $i;

				break;
			}
		}

		if ( null === $arrow ) {
			return null;
		}

		$body = next_meaningful( $tokens, $arrow + 1 );

		if ( null === $body ) {
			return null;
		}

		$after = next_meaningful( $tokens, $body + 1 );
		$ends  = null === $after || is_punct( $tokens[ $after ], array( ',', ')', ']' ) );

		return $ends && is_array( $tokens[ $body ] ) && 'true' === strtolower( $tokens[ $body ][1] )
			? 'an arrow function returning true'
			: null;
	}

	if ( T_FUNCTION !== $kind ) {
		return null;
	}

	// The shape is `function ( ... ) { BODY }`, so find the brace first.
	$count = count( $tokens );

	for ( $i = $index; $i < $count; $i++ ) {
		if ( ! is_punct( $tokens[ $i ], array( '{' ) ) ) {
			continue;
		}

		$body  = balanced_body( $tokens, $i );
		$words = array();

		foreach ( $body as $at ) {
			$words[] = is_array( $tokens[ $at ] ) ? strtolower( $tokens[ $at ][1] ) : $tokens[ $at ];
		}

		return array( 'return', 'true', ';' ) === $words || array( 'return', 'true' ) === $words
			? 'a closure returning true'
			: null;
	}

	return null;
}

$violations = array();
$scanned    = 0;

foreach ( php_files( $scan ) as $path ) {
	$source = file_get_contents( $path );

	if ( false === $source ) {
		$violations[] = "{$path}: could not be read, so it was not checked";

		continue;
	}

	++$scanned;

	$relative = ltrim( str_replace( $scan, '', $path ), '/' );
	$tokens   = token_get_all( $source );
	$count    = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		list( $id, $text, $line ) = $token;

		/*
		 * A string literal that is the array key. Comments are a different
		 * token type entirely and never reach here, which is what lets the
		 * docblock at the top of this file name `__return_true` safely.
		 */
		if ( T_CONSTANT_ENCAPSED_STRING === $id && 'permission_callback' === trim( $text, "'\"" ) ) {
			$arrow = next_meaningful( $tokens, $i + 1 );

			if ( null === $arrow || ! is_array( $tokens[ $arrow ] ) || T_DOUBLE_ARROW !== $tokens[ $arrow ][0] ) {
				continue;
			}

			$value = next_meaningful( $tokens, $arrow + 1 );

			if ( null === $value ) {
				continue;
			}

			$why = trivially_true( $tokens, $value );

			if ( null !== $why ) {
				$violations[] = "{$relative}:{$line}: permission_callback is {$why}";
			}

			continue;
		}

		// A registration call with no permission_callback key at all.
		if ( T_STRING !== $id || ! in_array( $text, REGISTRARS, true ) ) {
			continue;
		}

		$open = next_meaningful( $tokens, $i + 1 );

		if ( null === $open || ! is_punct( $tokens[ $open ], array( '(' ) ) ) {
			continue;
		}

		// `function register_route( ... )` is the wrapper's declaration, not a
		// registration. A `::` or `->` before the name is a real call and is
		// checked like any other.
		$before = null;

		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$before = $tokens[ $j ];

			break;
		}

		if ( is_array( $before ) && T_FUNCTION === $before[0] ) {
			continue;
		}

		$arguments = call_arguments( $tokens, $open );
		$options   = array() === $arguments ? array() : end( $arguments );

		/*
		 * The wrapper's single call site forwards its options as `$args`, so
		 * the key is not lexically present and cannot be. That is the only
		 * shape excused, and it has to be the *whole* argument: a bare
		 * variable, nothing else.
		 *
		 * The first version of this asked whether a variable appeared anywhere
		 * inside the call, which `array( $this, 'index' )` satisfies — so every
		 * ordinary registration excused itself, and the hole this check exists
		 * to close stayed open. The test caught it.
		 */
		$forwarded = 1 === count( $options ) && is_array( $tokens[ $options[0] ] ) && T_VARIABLE === $tokens[ $options[0] ][0];

		if ( $forwarded ) {
			continue;
		}

		$declared = false;

		foreach ( $options as $at ) {
			$inner = $tokens[ $at ];

			if (
				is_array( $inner )
				&& T_CONSTANT_ENCAPSED_STRING === $inner[0]
				&& 'permission_callback' === trim( $inner[1], "'\"" )
			) {
				$declared = true;

				break;
			}
		}

		if ( ! $declared ) {
			$violations[] = "{$relative}:{$line}: {$text}() registers a route with no permission_callback";
		}
	}
}

if ( 0 === $scanned ) {
	fwrite( STDERR, "check-permission-callbacks: no PHP files found under {$scan}\n" );
	fwrite( STDERR, "A gate that reads nothing reports success over nothing. See CLAUDE.md.\n" );

	exit( 1 );
}

if ( array() !== $violations ) {
	fwrite( STDERR, "REST routes without a real permission callback:\n\n" );

	foreach ( $violations as $violation ) {
		fwrite( STDERR, "  {$violation}\n" );
	}

	fwrite( STDERR, "\nEvery REST route needs a real permission callback. See docs/rest-api.md.\n" );

	exit( 1 );
}

printf( "check-permission-callbacks: ok (%d files)\n", (int) $scanned );
exit( 0 );
