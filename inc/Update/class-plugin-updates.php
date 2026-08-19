<?php
/**
 * WordPress plugin update integration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Update;

use Aggressive\Ads\Core\Service;

/**
 * Publishes verified GitHub releases to WordPress's native update UI.
 */
final class Plugin_Updates implements Service {

	/** Plugin slug and installed directory. */
	private const SLUG = 'aggressive-ads';

	/**
	 * Release metadata.
	 *
	 * @var Release_Repository
	 */
	private Release_Repository $releases;

	/**
	 * Package verifier.
	 *
	 * @var Package_Verifier
	 */
	private Package_Verifier $packages;

	/**
	 * Constructor.
	 *
	 * @param Release_Repository $releases Release metadata.
	 * @param Package_Verifier   $packages Package verifier.
	 */
	public function __construct( Release_Repository $releases, Package_Verifier $packages ) {
		$this->releases = $releases;
		$this->packages = $packages;
	}

	/**
	 * Environments where a self-update is never wanted.
	 *
	 * `wp_get_environment_type()` is the WordPress-native signal, and it is the
	 * one a site owner can set deliberately. It defaults to `production` when
	 * unset, which is why it cannot be the only signal.
	 */
	private const DEVELOPMENT_ENVIRONMENTS = array( 'local', 'development' );

	/**
	 * The policy, with no WordPress in it.
	 *
	 * Separate from the lookups so it can be tested exhaustively without a
	 * bootstrap — the interesting part is the combination of signals, not the
	 * reading of them.
	 *
	 * @param bool   $is_checkout Whether the plugin root looks like a checkout.
	 * @param string $environment WordPress environment type.
	 * @return bool
	 */
	public static function should_enable( bool $is_checkout, string $environment ): bool {
		if ( $is_checkout ) {
			return false;
		}

		return ! in_array( $environment, self::DEVELOPMENT_ENVIRONMENTS, true );
	}

	/**
	 * Whether the self-updater may run against this install.
	 *
	 * WordPress installs a plugin update through `Plugin_Upgrader`, which clears
	 * the destination directory before unpacking. The release package is built
	 * from an allowlist, so it contains none of the repository: no `.git`, no
	 * `src`, no `bin`, no `tests`. Running the updater against a working
	 * checkout therefore replaces the checkout with the shipped subset and
	 * destroys uncommitted work and history.
	 *
	 * Two independent signals switch it off, because they fail differently. The
	 * environment type is what a site owner sets deliberately, but it defaults
	 * to `production` when unset, so it is silent on most development installs.
	 * The `.git` marker needs no configuration but exists only on a checkout.
	 * Either alone leaves a real case uncovered.
	 *
	 * Tested with `file_exists`, not `is_dir`: a worktree or a submodule stores
	 * `.git` as a *file* holding a pointer, so an `is_dir` check reads those as
	 * distributed copies and offers them the update that deletes them.
	 *
	 * @return bool True when update checks and installation may proceed.
	 */
	public static function is_enabled(): bool {
		$is_checkout = file_exists( trailingslashit( AGGR_PLUGIN_DIR ) . '.git' );
		$environment = function_exists( 'wp_get_environment_type' )
			? wp_get_environment_type()
			: 'production';

		/**
		 * Filters whether the GitHub self-updater is active.
		 *
		 * Returning false unhooks the updater entirely: no update is advertised,
		 * and no package can be installed over this plugin.
		 *
		 * @param bool   $enabled     Whether the updater may run.
		 * @param bool   $is_checkout Whether the plugin root looks like a checkout.
		 * @param string $environment WordPress environment type.
		 */
		return (bool) apply_filters(
			'aggr_enable_plugin_updates',
			self::should_enable( $is_checkout, $environment ),
			$is_checkout,
			$environment
		);
	}

	/**
	 * Attach updater hooks.
	 *
	 * Nothing is hooked on a checkout rather than each callback returning early,
	 * so `upgrader_pre_download` cannot verify and hand back a package for a
	 * directory this must never overwrite.
	 */
	public function init(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check' ), 100 );
		add_filter( 'upgrader_pre_download', array( $this, 'verify_download' ), 10, 4 );
		add_filter( 'plugins_api', array( $this, 'plugin_information' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_after_update' ), 10, 2 );
	}

	/**
	 * Add a verified newer release to the native plugin update transient.
	 *
	 * @param mixed $transient Core update transient.
	 * @return mixed
	 */
	public function check( $transient ) {
		if ( ! $transient instanceof \stdClass || ! isset( $transient->checked ) || ! is_array( $transient->checked ) ) {
			return $transient;
		}

		$plugin  = plugin_basename( AGGR_PLUGIN_FILE );
		$current = $transient->checked[ $plugin ] ?? AGGR_VERSION;
		if ( ! is_string( $current ) ) {
			return $transient;
		}

		$release = $this->releases->latest();
		if ( ! is_array( $release ) ) {
			return $transient;
		}

		$version = $this->releases->version( $release );
		if ( null === $version || ! version_compare( $version, $current, '>' ) ) {
			return $transient;
		}

		$package = $this->releases->package_url( $release );
		if ( ! is_string( $package ) ) {
			return $transient;
		}

		$checksum = $this->packages->checksum( $package, $release );
		if ( ! is_string( $checksum ) ) {
			return $transient;
		}

		$this->packages->remember( $version, $package, $checksum );

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$transient->response[ $plugin ] = (object) array(
			'id'           => $this->releases->repository_url(),
			'slug'         => self::SLUG,
			'plugin'       => $plugin,
			'new_version'  => $version,
			'url'          => $this->releases->repository_url(),
			'package'      => $package,
			'requires'     => AGGR_MIN_WP,
			'requires_php' => AGGR_MIN_PHP,
		);

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ $plugin ] );
		}

		return $transient;
	}

	/**
	 * Verify the package before WordPress installs it.
	 *
	 * @param false|\WP_Error|string $reply       Existing result.
	 * @param mixed                  $package     Package URL.
	 * @param mixed                  $_upgrader   Core upgrader.
	 * @param array<string, mixed>   $_hook_extra Upgrader context.
	 * @return false|\WP_Error|string
	 */
	public function verify_download( $reply, $package, $_upgrader = null, array $_hook_extra = array() ) {
		return $this->packages->verify_download( $reply, $package );
	}

	/**
	 * Supply release details to WordPress's plugin information modal.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action API action.
	 * @param mixed  $args   API arguments.
	 * @return mixed
	 */
	public function plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || self::SLUG !== ( $args->slug ?? null ) ) {
			return $result;
		}

		$release = $this->releases->latest();
		if ( ! is_array( $release ) ) {
			return $result;
		}

		$version  = $this->releases->version( $release );
		$package  = $this->releases->package_url( $release );
		$checksum = is_string( $package ) ? $this->packages->checksum( $package, $release ) : false;
		if ( null === $version || ! is_string( $package ) || ! is_string( $checksum ) ) {
			return $result;
		}

		$this->packages->remember( $version, $package, $checksum );

		$published = $release['published_at'] ?? '';
		$body      = $release['body'] ?? '';

		return (object) array(
			'name'              => __( 'Aggressive Ads', 'aggressive-ads' ),
			'slug'              => self::SLUG,
			'version'           => $version,
			'author'            => '<a href="https://theaggressive.com">The Aggressive, LLC</a>',
			'homepage'          => $this->releases->repository_url(),
			'requires'          => AGGR_MIN_WP,
			'requires_php'      => AGGR_MIN_PHP,
			'last_updated'      => is_string( $published ) ? $published : '',
			'short_description' => __( 'Live means live. White-label advertising management for WordPress.', 'aggressive-ads' ),
			'sections'          => array(
				'description' => __( 'Advertisers build campaigns and staff review and publish them.', 'aggressive-ads' ),
				'changelog'   => is_string( $body ) && '' !== trim( $body )
					? nl2br( esc_html( $body ) )
					: esc_html__( 'No release notes were provided.', 'aggressive-ads' ),
			),
			'download_link'     => $package,
		);
	}

	/**
	 * Clear updater caches after this plugin is successfully updated.
	 *
	 * @param mixed                $_upgrader Core upgrader.
	 * @param array<string, mixed> $options   Completed operation.
	 */
	public function clear_after_update( $_upgrader, array $options ): void {
		$plugins = $options['plugins'] ?? array();
		if (
			'update' !== ( $options['action'] ?? null )
			|| 'plugin' !== ( $options['type'] ?? null )
			|| ! is_array( $plugins )
			|| ! in_array( plugin_basename( AGGR_PLUGIN_FILE ), $plugins, true )
		) {
			return;
		}

		delete_transient( Release_Repository::CACHE_KEY );
		delete_transient( Package_Verifier::CACHE_KEY );
	}
}
