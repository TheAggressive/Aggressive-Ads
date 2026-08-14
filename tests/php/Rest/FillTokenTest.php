<?php
/**
 * Fill tokens bind the current site.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * HMAC without a blog id would verify on every site in a network. These
 * assertions run on single-site PHPUnit: get_current_blog_id() is 1, and
 * mint_on_site() is how a foreign token is produced without switch_to_blog().
 */
final class FillTokenTest extends WP_UnitTestCase {

	/**
	 * Fixture placement for beacon tests.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Registers roles, a placement, and REST routes.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_name'   => 'token-slot',
				'post_status' => 'publish',
				'post_title'  => 'Token slot',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_HOUSE_CLICK_URL, 'https://example.com/house' );
		update_post_meta(
			$this->placement_id,
			Placement_Repository::META_HOUSE_ATTACHMENT,
			(int) self::factory()->attachment->create_object(
				array(
					'file'           => 'house.png',
					'post_mime_type' => 'image/png',
				)
			)
		);

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Drops the settings option so later tests see defaults.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );
		parent::tear_down();
	}

	/**
	 * A minted token has seven parts and leads with the current blog id.
	 *
	 * @return void
	 */
	public function test_mint_binds_the_current_blog(): void {
		$tokens = new Fill_Token();
		$minted = $tokens->mint( $this->placement_id, 0, 0 );
		$parts  = explode( '.', $minted['token'] );

		$this->assertCount( Fill_Token::PARTS, $parts );
		$this->assertSame( (string) get_current_blog_id(), $parts[0] );
		$this->assertSame( get_current_blog_id(), $minted['blog_id'] );
		$this->assertSame( $this->placement_id, $minted['placement_id'] );

		$parsed = $tokens->parse( $minted['token'] );
		$this->assertIsArray( $parsed );
		$this->assertSame( get_current_blog_id(), $parsed['blog_id'] );
		$this->assertSame( $this->placement_id, $parsed['placement_id'] );
	}

	/**
	 * Six-part tokens from before ADR-0034 are refused. TTL is five minutes;
	 * there is no dual-read.
	 *
	 * @return void
	 */
	public function test_parse_rejects_a_legacy_six_part_token(): void {
		$placement_id = $this->placement_id;
		$campaign_id  = 0;
		$creative_id  = 0;
		$exp          = time() + 60;
		$nonce        = bin2hex( random_bytes( 8 ) );
		$payload      = implode(
			'.',
			array(
				(string) $placement_id,
				(string) $campaign_id,
				(string) $creative_id,
				(string) $exp,
				$nonce,
			)
		);
		$hmac         = substr( hash_hmac( 'sha256', $payload, wp_salt( 'aggr_fill' ) ), 0, 32 );
		$legacy       = $payload . '.' . $hmac;

		$this->assertCount( 6, explode( '.', $legacy ) );
		$this->assertNull( ( new Fill_Token() )->parse( $legacy ) );
	}

	/**
	 * A valid HMAC for another blog is still not a fill on this one.
	 *
	 * @return void
	 */
	public function test_parse_rejects_a_token_bound_to_another_blog(): void {
		$tokens  = new Fill_Token();
		$foreign = get_current_blog_id() + 1;
		$minted  = $tokens->mint_on_site( $foreign, $this->placement_id, 0, 0 );

		$this->assertSame( $foreign, $minted['blog_id'] );
		$this->assertNotSame( $foreign, get_current_blog_id() );
		$this->assertNull( $tokens->parse( $minted['token'] ) );
	}

	/**
	 * The beacon does not count a token minted for another site.
	 *
	 * Asserts a same-site token would have counted, so a 400 here is the blog
	 * check and not a missing house creative.
	 *
	 * @return void
	 */
	public function test_beacon_rejects_a_token_bound_to_another_blog(): void {
		$local   = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 );
		$request = new WP_REST_Request( 'POST', '/aggr/v1/i' );
		$request->set_body_params( array( 'token' => $local['token'] ) );

		$ok = rest_get_server()->dispatch( $request );
		$this->assertSame( 204, $ok->get_status() );

		$foreign = ( new Fill_Token() )->mint_on_site( get_current_blog_id() + 1, $this->placement_id, 0, 0 );
		$request->set_body_params( array( 'token' => $foreign['token'] ) );

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
	}
}
