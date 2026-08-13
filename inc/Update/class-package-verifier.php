<?php
/**
 * Integrity verification for GitHub update packages.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Update;

/**
 * Resolves a release checksum and verifies the downloaded ZIP before install.
 */
final class Package_Verifier {

	/** Trusted update metadata transient. */
	public const CACHE_KEY = 'aggr_update_package';

	/**
	 * Release repository.
	 *
	 * @var Release_Repository
	 */
	private Release_Repository $releases;

	/**
	 * HTTP client.
	 *
	 * @var Update_Http_Client
	 */
	private Update_Http_Client $http;

	/**
	 * Constructor.
	 *
	 * @param Release_Repository $releases Release repository.
	 * @param Update_Http_Client $http     HTTP client.
	 */
	public function __construct( Release_Repository $releases, Update_Http_Client $http ) {
		$this->releases = $releases;
		$this->http     = $http;
	}

	/**
	 * Resolve the checksum for a package from cached or fresh release data.
	 *
	 * @param string                    $package Package URL.
	 * @param array<string, mixed>|null $release GitHub release.
	 * @return string|false
	 */
	public function checksum( string $package, ?array $release = null ) {
		$cached = get_transient( self::CACHE_KEY );
		if (
			is_array( $cached )
			&& isset( $cached['package'], $cached['checksum'] )
			&& is_string( $cached['package'] )
			&& is_string( $cached['checksum'] )
			&& hash_equals( $cached['package'], $package )
			&& $this->valid_checksum( $cached['checksum'] )
		) {
			return strtolower( $cached['checksum'] );
		}

		$release = $release ?? $this->releases->latest();
		if ( ! is_array( $release ) || $package !== $this->releases->package_url( $release ) ) {
			return false;
		}

		$checksum_url = $this->releases->checksum_url( $release );
		if ( ! is_string( $checksum_url ) ) {
			return false;
		}

		$response = $this->http->get(
			$checksum_url,
			array(
				'headers' => array( 'User-Agent' => 'Aggressive-Ads-Updater' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! is_string( $body ) || 1 !== preg_match( '/^\s*([a-f0-9]{64})(?:\s+\*?[^\r\n]+)?\s*$/i', $body, $matches ) ) {
			return false;
		}

		$checksum = strtolower( $matches[1] );

		return $this->valid_checksum( $checksum ) ? $checksum : false;
	}

	/**
	 * Cache metadata only after every release asset has been validated.
	 *
	 * @param string $version  Release version.
	 * @param string $package  Package URL.
	 * @param string $checksum SHA-256 checksum.
	 */
	public function remember( string $version, string $package, string $checksum ): void {
		if ( ! $this->releases->is_allowed_package_url( $package ) || ! $this->valid_checksum( $checksum ) ) {
			return;
		}

		set_transient(
			self::CACHE_KEY,
			array(
				'version'  => $version,
				'package'  => $package,
				'checksum' => strtolower( $checksum ),
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Download and verify our package before WordPress extracts it.
	 *
	 * @param false|\WP_Error|string $reply   Existing pre-download result.
	 * @param mixed                  $package Package URL.
	 * @return false|\WP_Error|string
	 */
	public function verify_download( $reply, $package ) {
		if ( false !== $reply || ! is_string( $package ) || ! $this->releases->is_allowed_package_url( $package ) ) {
			return $reply;
		}

		$checksum = $this->checksum( $package );
		if ( ! is_string( $checksum ) ) {
			return new \WP_Error(
				'aggr_update_checksum_missing',
				__( 'LAAO Advertiser Portal update is missing a valid SHA-256 checksum.', 'aggressive-ads' )
			);
		}

		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$downloaded = download_url( $package, 30 );
		if ( is_wp_error( $downloaded ) ) {
			return $downloaded;
		}

		$actual = hash_file( 'sha256', $downloaded );
		if ( ! is_string( $actual ) || ! hash_equals( $checksum, strtolower( $actual ) ) ) {
			wp_delete_file( $downloaded );

			return new \WP_Error(
				'aggr_update_checksum_mismatch',
				__( 'LAAO Advertiser Portal update failed integrity verification.', 'aggressive-ads' )
			);
		}

		return $downloaded;
	}

	/**
	 * Whether a string is a SHA-256 digest.
	 *
	 * @param string $checksum Candidate checksum.
	 */
	private function valid_checksum( string $checksum ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/i', $checksum );
	}
}
