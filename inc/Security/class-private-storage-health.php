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
			/*
			 * Recommended, not critical, and the distinction is deliberate.
			 *
			 * **What is served is ciphertext.** Stored creative is encrypted at
			 * rest with a key that is not in the uploads directory, so a server
			 * that hands out this file hands out an authenticated blob. That is
			 * the reason this reads as a missing layer rather than an exposure,
			 * and it is a stronger reason than the ones that used to be here:
			 * the UUID filename, the refused directory listing and the deletion
			 * of approved originals all still hold, but none of them would
			 * matter if the bytes themselves were readable.
			 *
			 * WordPress also serves the media of unpublished posts from the
			 * same directory and ships no deny rule for it, so calling this a
			 * broken install holds the site to a standard the platform does not
			 * meet. A red banner on every admin page for that trains people to
			 * dismiss Site Health, which costs more than it protects.
			 */
			return $this->result(
				'recommended',
				__( 'Unapproved advertising creative is not denied by the web server', 'aggressive-ads' ),
				__( 'A direct request for a private-storage verification file was served rather than refused. Creative stored there is encrypted at rest, so what a request would return is unreadable without a key the directory does not contain — this is a missing layer rather than an open door. Add the deny rule to close it.', 'aggressive-ads' ),
				$this->remedy_html()
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
	 * What to actually do, for the server this site is running.
	 *
	 * The probe above proves the file was served; it says nothing about why.
	 * Naming one server's syntax as though it were the answer is worse than
	 * saying nothing: an Apache site that reaches here has a `.htaccess` this
	 * plugin already wrote and a server configured to ignore it, and pasting an
	 * nginx `location` block leaves creative exactly as exposed while looking
	 * like the job is done.
	 *
	 * Detection is a hint, not a guarantee — SERVER_SOFTWARE can be absent or
	 * rewritten by a proxy — so the rule for every common server is offered
	 * underneath whichever one matched.
	 *
	 * @return string HTML for the Site Health actions panel.
	 */
	private function remedy_html(): string {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_string( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';

		$nginx  = 'location ~ ^/wp-content/uploads(?:/sites/[0-9]+)?/ads-uploads(?:/|$) { return 404; }';
		$caddy  = "@aggr path_regexp ^/wp-content/uploads(/sites/[0-9]+)?/ads-uploads(/|$)\nrespond @aggr 404";
		$apache = "<Directory \"/path/to/wp-content/uploads/ads-uploads\">\n    AllowOverride All\n</Directory>";

		if ( str_contains( $software, 'nginx' ) ) {
			return $this->remedy_block(
				__( 'This site reports nginx, which does not read the .htaccess file this plugin writes. Add the deny rule to the server block:', 'aggressive-ads' ),
				$nginx,
				$caddy,
				$apache
			);
		}

		if ( str_contains( $software, 'apache' ) || str_contains( $software, 'litespeed' ) ) {
			return $this->remedy_block(
				__( 'This site reports Apache or LiteSpeed, which do read .htaccess — and this plugin already wrote one in that directory. Reaching this check means the server is ignoring it, so the fix is to allow overrides there rather than to add a new rule:', 'aggressive-ads' ),
				$apache,
				$nginx,
				$caddy
			);
		}

		if ( str_contains( $software, 'caddy' ) ) {
			return $this->remedy_block(
				__( 'This site reports Caddy, which does not read .htaccess. Add the matcher to the site block:', 'aggressive-ads' ),
				$caddy,
				$nginx,
				$apache
			);
		}

		return $this->remedy_block(
			__( 'The web server could not be identified from SERVER_SOFTWARE, so deny the directory using whichever of these matches your stack. The .htaccess and web.config files this plugin writes only take effect on Apache, LiteSpeed and IIS:', 'aggressive-ads' ),
			$nginx,
			$caddy,
			$apache
		);
	}

	/**
	 * The matched rule first, the alternatives after it.
	 *
	 * @param string $lead      Sentence explaining the match.
	 * @param string $primary   Rule for the detected server.
	 * @param string $other_one First alternative.
	 * @param string $other_two Second alternative.
	 * @return string
	 */
	private function remedy_block( string $lead, string $primary, string $other_one, string $other_two ): string {
		return '<p>' . esc_html( $lead ) . '</p>'
			. '<pre><code>' . esc_html( $primary ) . '</code></pre>'
			. '<p>' . esc_html__( 'If that is not your server, one of these will be:', 'aggressive-ads' ) . '</p>'
			. '<pre><code>' . esc_html( $other_one ) . '</code></pre>'
			. '<pre><code>' . esc_html( $other_two ) . '</code></pre>';
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
