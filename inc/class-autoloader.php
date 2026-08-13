<?php
/**
 * The production autoloader.
 *
 * The plugin ships without vendor/, so this is the only autoloader in a
 * released build. See docs/adr/0012-own-autoloader-in-production.md.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads;

/**
 * Maps this plugin's namespaced class names onto WordPress-style filenames.
 *
 *     Aggressive\Ads\Workflow\Campaign_State_Machine
 *         → inc/Workflow/class-campaign-state-machine.php
 *
 * Namespace segments after the root map to directories verbatim. The final
 * segment lowercases, underscores become hyphens, and one of the WPCS file
 * prefixes is applied.
 */
final class Autoloader {

	/**
	 * The namespace this autoloader is responsible for. Nothing else.
	 */
	private const NAMESPACE_PREFIX = __NAMESPACE__ . '\\';

	/**
	 * WPCS filename prefixes, tried in order.
	 *
	 * `class-` first because it is the overwhelming majority; the cost of the
	 * remaining checks is paid once per class, on a miss, at first load.
	 *
	 * @var array<int, string>
	 */
	private const FILE_PREFIXES = array( 'class-', 'interface-', 'trait-', 'enum-' );

	/**
	 * Absolute path to the directory holding the namespace root, no trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * Constructor.
	 *
	 * @param string $base_dir Absolute path to the directory holding the namespace root.
	 */
	public function __construct( string $base_dir ) {
		$this->base_dir = rtrim( $base_dir, '/\\' );
	}

	/**
	 * Builds an autoloader and registers it with SPL.
	 *
	 * @param string $base_dir Absolute path to the directory holding the namespace root.
	 * @return self
	 */
	public static function register( string $base_dir ): self {
		$loader = new self( $base_dir );

		spl_autoload_register( array( $loader, 'autoload' ) );

		return $loader;
	}

	/**
	 * SPL callback. Loads the file backing a class, if this autoloader owns it.
	 *
	 * @param string $fqcn Fully qualified class name.
	 * @return void
	 */
	public function autoload( string $fqcn ): void {
		$file = $this->resolve( $fqcn );

		if ( null === $file ) {
			return;
		}

		require_once $file;
	}

	/**
	 * Resolves a class name to the file that defines it.
	 *
	 * Separate from autoload() and free of side effects so the mapping — which
	 * is the part that is easy to get subtly wrong — is unit-testable without
	 * loading anything.
	 *
	 * @param string $fqcn Fully qualified class name.
	 * @return string|null Absolute path, or null when this autoloader does not own the class.
	 */
	public function resolve( string $fqcn ): ?string {
		if ( ! str_starts_with( $fqcn, self::NAMESPACE_PREFIX ) ) {
			return null;
		}

		$relative = substr( $fqcn, strlen( self::NAMESPACE_PREFIX ) );

		if ( '' === $relative ) {
			return null;
		}

		$segments = explode( '\\', $relative );

		foreach ( $segments as $segment ) {
			// A segment containing anything else is not a legal PHP identifier,
			// so it cannot name a real class — and refusing it here means no
			// caller-supplied string can ever walk out of the base directory.
			if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $segment ) ) {
				return null;
			}
		}

		/**
		 * Guaranteed present: explode() always yields at least one segment.
		 *
		 * @var string $class_name
		 */
		$class_name = array_pop( $segments );

		$slug      = strtolower( str_replace( '_', '-', $class_name ) );
		$directory = $this->base_dir;

		if ( array() !== $segments ) {
			$directory .= '/' . implode( '/', $segments );
		}

		foreach ( self::FILE_PREFIXES as $prefix ) {
			$path = $directory . '/' . $prefix . $slug . '.php';

			if ( is_file( $path ) ) {
				return $path;
			}
		}

		return null;
	}
}
