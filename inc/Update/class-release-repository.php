<?php
/**
 * GitHub release metadata for plugin updates.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Update;

/**
 * Retrieves, validates, and caches the newest stable GitHub release.
 */
final class Release_Repository {

	/** Release metadata transient. */
	public const CACHE_KEY = 'laao_ads_update_release';

	/** Treat cached metadata as fresh for five minutes. */
	private const FRESH_SECONDS = 300;

	/**
	 * GitHub repository owner.
	 *
	 * @var string
	 */
	private string $owner;

	/**
	 * GitHub repository name.
	 *
	 * @var string
	 */
	private string $repository;

	/**
	 * HTTP client.
	 *
	 * @var Update_Http_Client
	 */
	private Update_Http_Client $http;

	/**
	 * Constructor.
	 *
	 * @param Update_Http_Client $http       HTTP client.
	 * @param string             $owner      GitHub owner.
	 * @param string             $repository GitHub repository.
	 */
	public function __construct(
		Update_Http_Client $http,
		string $owner = 'TheAggressive',
		string $repository = 'LAAO-Advertiser-Portal'
	) {
		$this->http       = $http;
		$this->owner      = $owner;
		$this->repository = $repository;
	}

	/**
	 * Repository homepage.
	 */
	public function repository_url(): string {
		return "https://github.com/{$this->owner}/{$this->repository}";
	}

	/**
	 * Return the newest stable release, with stale-cache fallback.
	 *
	 * @return array<string, mixed>|false
	 */
	public function latest() {
		$cached  = get_transient( self::CACHE_KEY );
		$release = $this->cached_release( $cached );

		if (
			is_array( $cached )
			&& null !== $release
			&& isset( $cached['checked_at'] )
			&& ( time() - (int) $cached['checked_at'] ) < self::FRESH_SECONDS
		) {
			return $release;
		}

		$response = $this->http->get(
			"https://api.github.com/repos/{$this->owner}/{$this->repository}/releases?per_page=20",
			array(
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'LAAO-Advertiser-Portal-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $release ?? false;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return $release ?? false;
		}

		$release = $this->select_stable( $decoded );
		if ( null === $release ) {
			return $this->cached_release( $cached ) ?? false;
		}

		set_transient(
			self::CACHE_KEY,
			array(
				'release'    => $release,
				'checked_at' => time(),
			),
			HOUR_IN_SECONDS
		);

		return $release;
	}

	/**
	 * Normalized release version.
	 *
	 * @param array<string, mixed> $release GitHub release.
	 */
	public function version( array $release ): ?string {
		$tag = $release['tag_name'] ?? null;
		if ( ! is_string( $tag ) || 1 !== preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $matches ) ) {
			return null;
		}

		return $matches[1];
	}

	/**
	 * Exact distributable ZIP URL for a release.
	 *
	 * @param array<string, mixed> $release GitHub release.
	 * @return string|false
	 */
	public function package_url( array $release ) {
		$version = $this->version( $release );

		return null === $version
			? false
			: $this->asset_url( $release, "laao-advertiser-portal-{$version}.zip", false );
	}

	/**
	 * Exact checksum sidecar URL for a release.
	 *
	 * @param array<string, mixed> $release GitHub release.
	 * @return string|false
	 */
	public function checksum_url( array $release ) {
		$version = $this->version( $release );

		return null === $version
			? false
			: $this->asset_url( $release, "laao-advertiser-portal-{$version}.zip.sha256", true );
	}

	/**
	 * Whether a URL is a package asset from this repository.
	 *
	 * @param string $url Candidate URL.
	 */
	public function is_allowed_package_url( string $url ): bool {
		return $this->is_allowed_release_url( $url, false );
	}

	/**
	 * Whether a URL is a checksum asset from this repository.
	 *
	 * @param string $url Candidate URL.
	 */
	public function is_allowed_checksum_url( string $url ): bool {
		return $this->is_allowed_release_url( $url, true );
	}

	/**
	 * Select the highest strict stable semantic version.
	 *
	 * @param array<mixed> $releases GitHub releases.
	 * @return array<string, mixed>|null
	 */
	private function select_stable( array $releases ): ?array {
		$best         = null;
		$best_version = null;

		foreach ( $releases as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) || ! empty( $release['prerelease'] ) ) {
				continue;
			}

			$version = $this->version( $release );
			if ( null !== $version && ( null === $best_version || version_compare( $version, $best_version, '>' ) ) ) {
				$best         = $release;
				$best_version = $version;
			}
		}

		return $best;
	}

	/**
	 * Find an exact, trusted asset URL.
	 *
	 * @param array<string, mixed> $release GitHub release.
	 * @param string               $name    Expected filename.
	 * @param bool                 $checksum Whether this is a checksum.
	 * @return string|false
	 */
	private function asset_url( array $release, string $name, bool $checksum ) {
		$assets = $release['assets'] ?? null;
		if ( ! is_array( $assets ) ) {
			return false;
		}

		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['name'] ?? null ) !== $name ) {
				continue;
			}

			$url = $asset['browser_download_url'] ?? null;
			if ( ! is_string( $url ) ) {
				return false;
			}

			$allowed = $checksum
				? $this->is_allowed_checksum_url( $url )
				: $this->is_allowed_package_url( $url );

			return $allowed ? $url : false;
		}

		return false;
	}

	/**
	 * Validate the immutable origin, repository path, tag, and filename.
	 *
	 * @param string $url      Candidate URL.
	 * @param bool   $checksum Whether the URL is for a checksum sidecar.
	 */
	private function is_allowed_release_url( string $url, bool $checksum ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return false;
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = (int) ( $parts['port'] ?? 443 );
		$path   = rawurldecode( (string) ( $parts['path'] ?? '' ) );

		if (
			'https' !== $scheme
			|| 'github.com' !== $host
			|| 443 !== $port
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return false;
		}

		$segments = explode( '/', $path );
		if ( in_array( '.', $segments, true ) || in_array( '..', $segments, true ) ) {
			return false;
		}

		$owner      = preg_quote( $this->owner, '#' );
		$repository = preg_quote( $this->repository, '#' );
		$pattern    = "#^/{$owner}/{$repository}/releases/download/v(\d+\.\d+\.\d+)/([^/]+)$#i";
		if ( 1 !== preg_match( $pattern, $path, $matches ) ) {
			return false;
		}

		$expected = "laao-advertiser-portal-{$matches[1]}.zip";
		if ( $checksum ) {
			$expected .= '.sha256';
		}

		return hash_equals( $expected, $matches[2] );
	}

	/**
	 * Read a release from a transient value.
	 *
	 * @param mixed $cached Transient value.
	 * @return array<string, mixed>|null
	 */
	private function cached_release( $cached ): ?array {
		if ( ! is_array( $cached ) || ! isset( $cached['release'] ) || ! is_array( $cached['release'] ) ) {
			return null;
		}

		return $cached['release'];
	}
}
