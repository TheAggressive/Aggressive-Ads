<?php
/**
 * Operational Site Health check for unreleased creative storage.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Storage\Private_Storage;

/**
 * Proves the web server refuses direct requests for private creative bytes.
 */
final class Private_Storage_Health implements Service {

	/**
	 * Constructor.
	 *
	 * @param Private_Storage $storage Private creative storage.
	 */
	public function __construct( private readonly Private_Storage $storage ) {
	}

	/**
	 * Registers the direct Site Health test.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'site_status_tests', array( $this, 'register_test' ) );
	}

	/**
	 * Adds the test without disturbing tests registered by core or plugins.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public function register_test( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['aggr_private_storage'] = array(
			'label' => __( 'Unapproved advertising creative is not publicly accessible', 'aggressive-ads' ),
			'test'  => array( $this, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * Requests a random probe through the site's public uploads URL.
	 *
	 * @return array<string, mixed> Site Health direct-test result.
	 */
	public function run_test(): array {
		$probe = $this->storage->create_verification_probe();

		if ( null === $probe ) {
			return $this->result(
				'recommended',
				__( 'Private creative protection could not be verified', 'aggressive-ads' ),
				__( 'The verification file could not be created. Confirm that the uploads directory is writable, then run this check again.', 'aggressive-ads' )
			);
		}

		try {
			$response = wp_safe_remote_get(
				$probe['url'],
				array(
					'redirection' => 0,
					'timeout'     => 3,
				)
			);
		} finally {
			$this->storage->delete( $probe['path'] );
		}

		if ( is_wp_error( $response ) ) {
			return $this->result(
				'recommended',
				__( 'Private creative protection could not be verified', 'aggressive-ads' ),
				__( 'The site could not request its own uploads URL. Verify the private-storage deny rule during deployment.', 'aggressive-ads' )
			);
		}

		$status = wp_remote_retrieve_response_code( $response );

		if ( in_array( $status, array( 401, 403, 404, 410 ), true ) ) {
			return $this->result(
				'good',
				__( 'Unapproved advertising creative is protected', 'aggressive-ads' ),
				__( 'A direct request for a private-storage verification file was refused by the web server.', 'aggressive-ads' )
			);
		}

		if ( $status >= 200 && $status < 300 ) {
			return $this->result(
				'critical',
				__( 'Unapproved advertising creative is publicly accessible', 'aggressive-ads' ),
				__( 'The web server returned a private-storage verification file directly. Add a deny rule for the aggr-private uploads directory before accepting creative uploads.', 'aggressive-ads' ),
				'<p><code>location ~ ^/wp-content/uploads(?:/sites/[0-9]+)?/aggr-private(?:/|$) { return 404; }</code></p>'
			);
		}

		return $this->result(
			'recommended',
			__( 'Private creative protection needs manual verification', 'aggressive-ads' ),
			sprintf(
				/* translators: %d: HTTP response status. */
				__( 'The verification request returned HTTP %d, which does not prove that the file was denied. Verify the uploads deny rule during deployment.', 'aggressive-ads' ),
				$status
			)
		);
	}

	/**
	 * Builds the common Site Health result shape.
	 *
	 * @param string $status      Site Health status.
	 * @param string $label       Result heading.
	 * @param string $description Result explanation.
	 * @param string $actions     Optional trusted actions markup.
	 * @return array<string, mixed>
	 */
	private function result( string $status, string $label, string $description, string $actions = '' ): array {
		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'Security', 'aggressive-ads' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $actions,
			'test'        => 'aggr_private_storage',
		);
	}
}
