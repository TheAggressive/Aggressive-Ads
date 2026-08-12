<?php
/**
 * GitHub plugin updater integration.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Update\Package_Verifier;
use LAAO_Advertiser_Portal\Update\Plugin_Updates;
use LAAO_Advertiser_Portal\Update\Release_Repository;
use LAAO_Advertiser_Portal\Update\Update_Http_Client;
use WP_UnitTestCase;

/**
 * The updater advertises only exact, checksummed, stable release artifacts.
 */
final class PluginUpdatesTest extends WP_UnitTestCase {

	/**
	 * Release repository.
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
	 * Plugin updater.
	 *
	 * @var Plugin_Updates
	 */
	private Plugin_Updates $updates;

	/**
	 * Build updater collaborators with real WordPress HTTP filters.
	 */
	public function set_up(): void {
		parent::set_up();

		delete_transient( Release_Repository::CACHE_KEY );
		delete_transient( Package_Verifier::CACHE_KEY );

		$http           = new Update_Http_Client();
		$this->releases = new Release_Repository( $http );
		$this->packages = new Package_Verifier( $this->releases, $http );
		$this->updates  = new Plugin_Updates( $this->releases, $this->packages );
	}

	/**
	 * Remove cross-test HTTP and cache state.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_http_request' );
		delete_transient( Release_Repository::CACHE_KEY );
		delete_transient( Package_Verifier::CACHE_KEY );

		parent::tear_down();
	}

	/**
	 * A newer stable release with both exact assets reaches WordPress.
	 */
	public function test_advertises_an_exact_checksummed_release(): void {
		$this->mock_github( $this->release( '1.4.0' ), str_repeat( 'a', 64 ) );

		$transient          = new \stdClass();
		$transient->checked = array( plugin_basename( LAAO_ADS_PLUGIN_FILE ) => '1.3.0' );
		$result             = $this->updates->check( $transient );
		$plugin             = plugin_basename( LAAO_ADS_PLUGIN_FILE );

		$this->assertTrue( property_exists( $result, 'response' ) );
		$this->assertArrayHasKey( $plugin, $result->response );
		$this->assertSame( '1.4.0', $result->response[ $plugin ]->new_version );
		$this->assertSame(
			'https://github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/laao-advertiser-portal-1.4.0.zip',
			$result->response[ $plugin ]->package
		);
		$this->assertSame( str_repeat( 'a', 64 ), get_transient( Package_Verifier::CACHE_KEY )['checksum'] );
	}

	/**
	 * A missing checksum makes the release invisible, not merely un-installable.
	 */
	public function test_missing_checksum_asset_fails_closed(): void {
		$release           = $this->release( '1.4.0' );
		$release['assets'] = array_slice( $release['assets'], 0, 1 );
		$this->mock_github( $release, str_repeat( 'a', 64 ) );

		$transient          = new \stdClass();
		$transient->checked = array( plugin_basename( LAAO_ADS_PLUGIN_FILE ) => '1.3.0' );
		$result             = $this->updates->check( $transient );

		$this->assertFalse( property_exists( $result, 'response' ) );
	}

	/**
	 * Drafts, prereleases, and malformed versions cannot outrank stable tags.
	 */
	public function test_selects_the_highest_strict_stable_release(): void {
		$stable                   = $this->release( '1.9.0' );
		$draft                    = $this->release( '9.0.0' );
		$prerelease               = $this->release( '8.0.0' );
		$malformed                = $this->release( '7.0.0' );
		$draft['draft']           = true;
		$prerelease['prerelease'] = true;
		$malformed['tag_name']    = 'release-7.0.0';
		$this->mock_github( array( $draft, $prerelease, $malformed, $stable ), str_repeat( 'b', 64 ) );

		$release = $this->releases->latest();

		$this->assertIsArray( $release );
		$this->assertSame( '1.9.0', $this->releases->version( $release ) );
	}

	/**
	 * URL validation rejects alternate origins, credentials, traversal, and
	 * mismatched tag/filename versions.
	 *
	 * @param string $url Candidate package URL.
	 * @dataProvider data_untrusted_package_urls
	 */
	public function test_rejects_untrusted_package_urls( string $url ): void {
		$this->assertFalse( $this->releases->is_allowed_package_url( $url ) );
	}

	/**
	 * Untrusted package URL cases.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_untrusted_package_urls(): array {
		return array(
			'http'        => array( 'http://github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/laao-advertiser-portal-1.4.0.zip' ),
			'lookalike'   => array( 'https://github.example/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/laao-advertiser-portal-1.4.0.zip' ),
			'credentials' => array( 'https://user@github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/laao-advertiser-portal-1.4.0.zip' ),
			'traversal'   => array( 'https://github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/../laao-advertiser-portal-1.4.0.zip' ),
			'mismatch'    => array( 'https://github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/laao-advertiser-portal-1.5.0.zip' ),
			'query'       => array( 'https://github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v1.4.0/laao-advertiser-portal-1.4.0.zip?token=nope' ),
		);
	}

	/**
	 * Construct GitHub release metadata with the exact public contract.
	 *
	 * @param string $version Version.
	 * @return array<string, mixed>
	 */
	private function release( string $version ): array {
		$base = "https://github.com/TheAggressive/LAAO-Advertiser-Portal/releases/download/v{$version}";
		$zip  = "laao-advertiser-portal-{$version}.zip";

		return array(
			'tag_name'     => "v{$version}",
			'draft'        => false,
			'prerelease'   => false,
			'published_at' => '2026-08-11T12:00:00Z',
			'body'         => 'Release notes.',
			'assets'       => array(
				array(
					'name'                 => $zip,
					'browser_download_url' => "{$base}/{$zip}",
				),
				array(
					'name'                 => "{$zip}.sha256",
					'browser_download_url' => "{$base}/{$zip}.sha256",
				),
			),
		);
	}

	/**
	 * Mock only GitHub API and checksum requests; unexpected traffic fails.
	 *
	 * @param array<mixed> $releases Release or release list.
	 * @param string       $checksum Checksum response.
	 */
	private function mock_github( array $releases, string $checksum ): void {
		if ( isset( $releases['tag_name'] ) ) {
			$releases = array( $releases );
		}

		add_filter(
			'pre_http_request',
			static function ( $preempt, array $_args, string $url ) use ( $releases, $checksum ) {
				if ( str_contains( $url, 'api.github.com/repos/' ) ) {
					return self::response( (string) wp_json_encode( $releases ) );
				}

				if ( str_ends_with( $url, '.zip.sha256' ) ) {
					return self::response( $checksum . '  package.zip' );
				}

				return new \WP_Error( 'unexpected_updater_request', $url );
			},
			10,
			3
		);
	}

	/**
	 * WordPress HTTP response fixture.
	 *
	 * @param string $body Response body.
	 * @return array<string, mixed>
	 */
	private static function response( string $body ): array {
		return array(
			'headers'  => array(),
			'body'     => $body,
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
