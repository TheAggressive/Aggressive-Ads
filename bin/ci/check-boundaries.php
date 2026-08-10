<?php
/**
 * Architecture boundary checks.
 *
 * Uses PHP's own tokenizer rather than grep, because the first grep version of
 * this reported every docblock that *named* a forbidden function — including
 * the comment explaining why the domain layer deliberately does not call
 * wp_parse_url(). A gate that fires on correct code teaches people to work
 * around the gate, so it has to read code as code.
 *
 * Three rules, all from docs/architecture.md:
 *
 *   1. Data access appears only in inc/Repository/.
 *   2. AdSanity appears only in inc/Integration/Adsanity/.
 *   3. inc/Domain/ calls no WordPress function at all.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$inc  = $root . '/inc';

if ( ! is_dir( $inc ) ) {
	echo "check-boundaries: ok (no inc/ yet)\n";
	exit( 0 );
}

/**
 * Data-access functions that belong to the repository layer.
 */
const DATA_ACCESS_FUNCTIONS = array(
	'get_posts',
	'get_post_meta',
	'add_post_meta',
	'update_post_meta',
	'delete_post_meta',
	'get_post',
	'get_post_type',
	'get_post_status',
	'wp_insert_post',
	'wp_update_post',
	'wp_delete_post',
	'get_the_title',
	'get_post_field',
);

/**
 * AdSanity's API surface, as identifiers.
 */
const ADSANITY_PREFIXES = array( 'adsanity_', 'ADSANITY_', 'AdSanity_' );

/**
 * AdSanity's data, as string literals.
 */
const ADSANITY_LITERALS = array( 'ad-group', '_start_date', '_end_date', 'ad_src', 'ads' );

/**
 * WordPress functions the domain layer may not call. Prefix-matched, plus the
 * handful of core names that carry no prefix.
 */
const WORDPRESS_PREFIXES = array( 'wp_', 'get_', 'add_', 'update_', 'delete_', 'esc_', 'sanitize_', 'is_wp_', 'do_', 'apply_', 'register_', 'has_', 'remove_', 'current_user_' );
const WORDPRESS_NAMES    = array( '__', '_e', '_n', '_x', '_ex', '_nx', 'absint', 'wpautop', 'selected', 'checked', 'antispambot' );

$violations = array();

/**
 * Every PHP file under a directory.
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
 * Whether a name starts with any of the given prefixes.
 *
 * @param string             $name     Candidate name.
 * @param array<int, string> $prefixes Prefixes to test.
 * @return bool
 */
function has_prefix( string $name, array $prefixes ): bool {
	foreach ( $prefixes as $prefix ) {
		if ( str_starts_with( $name, $prefix ) ) {
			return true;
		}
	}

	return false;
}

foreach ( php_files( $inc ) as $path ) {
	$relative      = ltrim( str_replace( $root, '', $path ), '/' );
	$in_repository = str_starts_with( $relative, 'inc/Repository/' );
	$in_adsanity   = str_starts_with( $relative, 'inc/Integration/Adsanity/' );
	$in_domain     = str_starts_with( $relative, 'inc/Domain/' );

	$source = file_get_contents( $path );

	if ( false === $source ) {
		continue;
	}

	$tokens = token_get_all( $source );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$token = $tokens[ $i ];

		if ( ! is_array( $token ) ) {
			continue;
		}

		list( $id, $text, $line ) = $token;

		// Comments and docblocks are prose. They may name anything.
		if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
			continue;
		}

		if ( T_VARIABLE === $id && '$wpdb' === $text && ! $in_repository ) {
			$violations[] = "{$relative}:{$line}: \$wpdb outside inc/Repository/";

			continue;
		}

		if ( T_CONSTANT_ENCAPSED_STRING === $id && ! $in_adsanity ) {
			$literal = trim( $text, "'\"" );

			if ( in_array( $literal, ADSANITY_LITERALS, true ) ) {
				$violations[] = "{$relative}:{$line}: AdSanity literal \"{$literal}\" outside inc/Integration/Adsanity/";
			}

			continue;
		}

		/*
		 * Namespaced and fully qualified names are their own token types in
		 * PHP 8: `\AdSanity_Ads_CPT` is T_NAME_FULLY_QUALIFIED and
		 * `Adsanity\Meta_Data` is T_NAME_QUALIFIED, neither of which is a
		 * T_STRING. Checking only T_STRING let `new \AdSanity_Ads_CPT()`
		 * through — found by testing the gate rather than trusting it.
		 */
		$qualified = array( T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE );

		if ( in_array( $id, $qualified, true ) ) {
			if ( ! $in_adsanity ) {
				$segments = explode( '\\', ltrim( $text, '\\' ) );

				/*
				 * `Adsanity\Meta_Data` is a bare segment with no underscore, so
				 * no prefix matches it — and it is exactly the undocumented
				 * internal wrapper the AdSanity notes warn against depending on.
				 *
				 * Only the **root** segment counts, though. Our own adapter
				 * lives in LAAO_Advertiser_Portal\Integration\Adsanity, and the
				 * composition root has to be able to name it in order to wire
				 * it up. Matching any segment flagged that import, which is the
				 * one place in the codebase that must be allowed to say it.
				 */
				$is_vendor_namespace = 0 === strcasecmp( $segments[0], 'adsanity' );
				$has_vendor_prefix   = false;

				foreach ( $segments as $segment ) {
					if ( has_prefix( $segment, ADSANITY_PREFIXES ) ) {
						$has_vendor_prefix = true;

						break;
					}
				}

				if ( $is_vendor_namespace || $has_vendor_prefix ) {
					$violations[] = "{$relative}:{$line}: AdSanity identifier {$text} outside inc/Integration/Adsanity/";
				}
			}

			continue;
		}

		if ( T_STRING !== $id ) {
			continue;
		}

		if ( ! $in_adsanity && has_prefix( $text, ADSANITY_PREFIXES ) ) {
			$violations[] = "{$relative}:{$line}: AdSanity identifier {$text} outside inc/Integration/Adsanity/";

			continue;
		}

		// Only a name followed by "(" is a call. `Foo::get_posts` as a method
		// name on our own class is not what this rule is about, so require the
		// previous meaningful token not to be "->" or "::".
		$next = $tokens[ $i + 1 ] ?? null;

		if ( '(' !== $next ) {
			continue;
		}

		$previous = null;

		for ( $j = $i - 1; $j >= 0; $j-- ) {
			if ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}

			$previous = $tokens[ $j ];

			break;
		}

		$is_method = is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true );

		if ( $is_method ) {
			continue;
		}

		/*
		 * The AdSanity adapter is exempt from the data-access rule, and only
		 * it.
		 *
		 * The two boundaries would otherwise contradict each other: the
		 * publisher has to write AdSanity's own posts, terms and meta, and a
		 * repository is forbidden from naming AdSanity identifiers. Something
		 * has to give, and the right answer is that the repository rule governs
		 * *our* domain data. The provider's data belongs to the provider's
		 * adapter.
		 *
		 * This is directory-level, because no static check can tell which post
		 * type an update_post_meta() call targets. The mitigation is that the
		 * directory is small and every write in it is read back.
		 */
		if ( ! $in_repository && ! $in_adsanity && in_array( $text, DATA_ACCESS_FUNCTIONS, true ) ) {
			$violations[] = "{$relative}:{$line}: {$text}() outside inc/Repository/";

			continue;
		}

		if ( $in_domain && ( has_prefix( $text, WORDPRESS_PREFIXES ) || in_array( $text, WORDPRESS_NAMES, true ) ) ) {
			$violations[] = "{$relative}:{$line}: {$text}() — inc/Domain/ calls no WordPress";
		}
	}
}

if ( array() !== $violations ) {
	fwrite( STDERR, "Architecture boundary violations:\n\n" );

	foreach ( $violations as $violation ) {
		fwrite( STDERR, "  {$violation}\n" );
	}

	fwrite( STDERR, "\nSee docs/architecture.md for why these boundaries exist.\n" );

	exit( 1 );
}

echo "check-boundaries: ok\n";
exit( 0 );
