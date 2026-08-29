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
	 * Six-part tokens from before site-scoped tenancy are refused. TTL is five minutes;
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
	 * Mutations of a real token that leave the cast integers unchanged.
	 *
	 * Each one produced an identical HMAC before canonicalization, because
	 * `sign()` re-serializes the values `parse()` cast rather than the bytes it
	 * received.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function malleable_segments(): array {
		return array(
			'markup after the blog id'   => array( 0, '<img src=x onerror=alert(1)>' ),
			'markup after the placement' => array( 1, '"><script>' ),
			'text after the campaign'    => array( 2, 'abc' ),
			'text after the creative'    => array( 3, 'abc' ),
			'text after the expiry'      => array( 4, 'abc' ),
			'a quote'                    => array( 0, "'" ),
			'trailing whitespace'        => array( 4, ' ' ),
		);
	}

	/**
	 * **A token whose signature does not cover its own bytes is refused.**
	 *
	 * `parse()` casts each numeric segment before verifying, and `sign()` signs
	 * the cast values — so `1<img src=x>.42.…` hashed identically to `1.42.…`
	 * and passed `hash_equals()` untouched. Only the nonce and HMAC were ever
	 * charset-constrained.
	 *
	 * It was not exploitable: the REST routes gate on `[0-9a-f.]` and the click
	 * hop percent-encodes what it forwards. But the click hop now hands this
	 * token to an advertiser's page, and a signed value whose signature does not
	 * cover its serialization should not be published.
	 *
	 * @dataProvider malleable_segments
	 *
	 * @param int    $index  Segment to mutate.
	 * @param string $suffix Bytes to append to it.
	 * @return void
	 */
	public function test_parse_rejects_a_non_canonical_segment( int $index, string $suffix ): void {
		$tokens = new Fill_Token();
		$minted = $tokens->mint( $this->placement_id, 0, 0 );
		$parts  = explode( '.', $minted['token'] );

		$this->assertNotNull( $tokens->parse( $minted['token'] ), 'The unmutated fixture must parse, or this proves nothing.' );

		$original         = $parts[ $index ];
		$parts[ $index ] .= $suffix;
		$mutated          = implode( '.', $parts );

		$this->assertNotSame( $minted['token'], $mutated, 'The fixture must actually differ.' );
		$this->assertSame(
			(int) $original,
			(int) $parts[ $index ],
			'The mutation must leave the cast value unchanged, or it is refused for an unrelated reason.'
		);

		$this->assertNull( $tokens->parse( $mutated ) );
	}

	/**
	 * The HMAC really is identical, which is what makes the guard necessary.
	 *
	 * Without this the test above passes whether the token was refused for
	 * non-canonicality or because the signature stopped matching — and only the
	 * first is the behaviour being asserted.
	 *
	 * @return void
	 */
	public function test_a_mutated_segment_still_produces_the_same_signature(): void {
		$minted = ( new Fill_Token() )->mint( $this->placement_id, 0, 0 );
		$parts  = explode( '.', $minted['token'] );

		$payload = implode( '.', array_slice( $parts, 0, 6 ) );
		$mutated = implode( '.', array_merge( array( $parts[0] . 'abc' ), array_slice( $parts, 1, 5 ) ) );

		$this->assertNotSame( $payload, $mutated );

		// Both sign to the same digest, because sign() serializes the cast
		// integers. That equality is the defect; the guard is what refuses it.
		$this->assertSame(
			substr( hash_hmac( 'sha256', $payload, wp_salt( 'aggr_fill' ) ), 0, 32 ),
			$parts[6],
			'The fixture HMAC must be the canonical one.'
		);
	}

	/**
	 * Numeric forms `encode()` would never emit are refused.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function non_canonical_numbers(): array {
		return array(
			'a leading zero'    => array( 1, '0' ),
			'two leading zeros' => array( 1, '00' ),
			'a leading plus'    => array( 1, '+' ),
			'a leading space'   => array( 4, ' ' ),
		);
	}

	/**
	 * Asserts one non-canonical numeric prefix.
	 *
	 * @dataProvider non_canonical_numbers
	 *
	 * @param int    $index  Segment to mutate.
	 * @param string $prefix Bytes to prepend to it.
	 * @return void
	 */
	public function test_parse_rejects_a_non_canonical_number( int $index, string $prefix ): void {
		$tokens = new Fill_Token();
		$minted = $tokens->mint( $this->placement_id, 0, 0 );
		$parts  = explode( '.', $minted['token'] );

		$parts[ $index ] = $prefix . $parts[ $index ];

		$this->assertNull( $tokens->parse( implode( '.', $parts ) ) );
	}

	/**
	 * And a canonical token is untouched by the guard.
	 *
	 * The regression half. Every token this plugin mints comes from `encode()`,
	 * which serializes with `(string)`, so none of them can be non-canonical —
	 * asserted rather than assumed, because a guard that refused real tokens
	 * would take delivery down.
	 *
	 * @return void
	 */
	public function test_a_minted_token_still_parses(): void {
		$tokens = new Fill_Token();

		foreach ( array( array( 0, 0 ), array( 7, 9 ) ) as $pair ) {
			$minted = $tokens->mint( $this->placement_id, $pair[0], $pair[1] );
			$parsed = $tokens->parse( $minted['token'] );

			$this->assertIsArray( $parsed, 'A freshly minted token must parse.' );
			$this->assertSame( $this->placement_id, $parsed['placement_id'] );
			$this->assertSame( $pair[0], $parsed['campaign_id'] );
			$this->assertSame( $pair[1], $parsed['creative_id'] );
		}
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
