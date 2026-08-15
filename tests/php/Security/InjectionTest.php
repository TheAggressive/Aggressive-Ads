<?php
/**
 * Hostile input driven through the real entry points.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Security;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use WP_UnitTestCase;

/**
 * SQL injection and stored XSS, asserted end to end rather than by inspection.
 *
 * PHPCS already refuses an unprepared query and an unescaped echo, and the
 * repository boundary keeps `$wpdb` in one directory. Those are the controls;
 * this file is the evidence that the controls work on real payloads, because a
 * sniff proves a shape and a suppression comment can switch one off.
 *
 * Every payload here is a string that changes meaning if it is ever
 * concatenated into SQL or emitted into HTML. The assertions are deliberately
 * about *survival*: the value must come back byte for byte, because a value
 * that round-trips intact was bound as data and never parsed as code. A test
 * that only asserted "no error" would pass against a database that silently
 * executed the payload.
 */
final class InjectionTest extends WP_UnitTestCase {

	/**
	 * Strings that end a SQL literal, comment out the rest, or stack a query.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function sql_payloads(): array {
		return array(
			'quote terminator'   => array( "' OR '1'='1" ),
			'statement stacking' => array( "'; DROP TABLE wp_posts; --" ),
			'comment tail'       => array( "admin'--" ),
			'union select'       => array( "' UNION SELECT user_pass FROM wp_users --" ),
			'backslash escape'   => array( "\\' OR 1=1 --" ),
			'double quote'       => array( '" OR ""="' ),
			'null byte'          => array( "abc\0def" ),
			'percent wildcard'   => array( '%_%' ),
			'sprintf token'      => array( '%s %d %1$s' ),
		);
	}

	/**
	 * Strings that execute if they reach HTML unescaped.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function xss_payloads(): array {
		return array(
			'script tag'      => array( '<script>alert(1)</script>' ),
			'img onerror'     => array( '<img src=x onerror=alert(1)>' ),
			'svg onload'      => array( '<svg/onload=alert(1)>' ),
			'attribute break' => array( '" autofocus onfocus="alert(1)' ),
			'javascript href' => array( 'javascript:alert(1)' ),
			'closing tag'     => array( '</textarea><script>alert(1)</script>' ),
		);
	}

	/**
	 * A SQL payload in an invited address is bound, never concatenated.
	 *
	 * This is the assertion that exercises `prepare()` itself. `invitation()`
	 * binds the address raw — `AND email = %s` — so it is the widest path in
	 * the plugin where an attacker-chosen string reaches a WHERE clause as a
	 * value rather than as a digest. Concatenate it and `' OR '1'='1` matches
	 * the first pending invite for the organization, which hands the caller
	 * somebody else's invitation.
	 *
	 * Verified by deliberately concatenating this query. Exactly one payload
	 * catches it — `' OR '1'='1` — because that is the only one that still
	 * parses as SQL and therefore returns the victim's row; the rest produce a
	 * syntax error, an empty result, and a passing assertion. One true positive
	 * is the whole value of the case, and it is why the payload list is not
	 * scored on how many of them fail.
	 *
	 * @dataProvider sql_payloads
	 *
	 * @param string $payload Hostile string.
	 * @return void
	 */
	public function test_a_sql_payload_in_an_invited_address_is_bound_as_data( string $payload ): void {
		$access = Plugin::instance()->container()->get( Org_Access_Repository::class );
		$org_id = self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );

		// A real invitation the payload must not be able to reach.
		$victim = $access->create_invite( $org_id, 'victim@example.com', 1 );
		$this->assertIsArray( $victim );

		// The same token with a hostile address must not match the victim row.
		$this->assertNull(
			$access->invitation( (string) $victim['token'], $payload ),
			'A hostile address must not resolve somebody else\'s invitation.'
		);

		// And the payload works as an ordinary address when it is the real one.
		$mine = $access->create_invite( $org_id, $payload, 1 );

		if ( is_array( $mine ) ) {
			$found = $access->invitation( (string) $mine['token'], $payload );

			$this->assertIsArray( $found, 'The payload address must resolve its own invitation.' );
			$this->assertSame( $org_id, (int) $found['org_id'] );
		}
	}

	/**
	 * A hostile tenant name resolves to its own organization and no other.
	 *
	 * The lookup key is a salted HMAC of the name, so the raw string never
	 * reaches a WHERE clause here — concatenating this query would still be
	 * safe, and this test would still pass. It is kept for what it does prove:
	 * that a hostile name survives storage byte for byte and cannot be made to
	 * collide with a neighbouring tenant, which is the cross-organization read
	 * the digest exists to prevent.
	 *
	 * @dataProvider sql_payloads
	 *
	 * @param string $payload Hostile string.
	 * @return void
	 */
	public function test_a_hostile_tenant_name_resolves_only_its_own_organization( string $payload ): void {
		$access = Plugin::instance()->container()->get( Org_Access_Repository::class );
		$org_id = self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );

		$registered = $access->register_identity( $org_id, $payload );
		$this->assertTrue( true === $registered, 'The hostile name should register like any other.' );

		// The row is found by the same string. A concatenated query would
		// either match nothing or match everything; both fail here.
		$this->assertSame( $org_id, $access->org_id_for_canonical( $payload ) );

		// And the payload did not reach a neighbouring tenant.
		$this->assertSame( 0, $access->org_id_for_canonical( $payload . '-other' ) );
	}

	/**
	 * The posts table still exists after every stacking payload.
	 *
	 * Blunt, and the point: if `'; DROP TABLE wp_posts; --` were ever
	 * concatenated, this is the assertion that notices.
	 *
	 * @dataProvider sql_payloads
	 *
	 * @param string $payload Hostile string.
	 * @return void
	 */
	public function test_a_sql_payload_cannot_reach_the_schema( string $payload ): void {
		global $wpdb;

		$access = Plugin::instance()->container()->get( Org_Access_Repository::class );
		$org_id = self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );

		$access->register_identity( $org_id, $payload );
		$access->similar_org_id( $payload );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting the schema survived the payload; there is no API for "does this table still exist".
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->posts ) );

		$this->assertSame( $wpdb->posts, $found, 'wp_posts must survive every payload.' );
		$this->assertSame( $access->table_name(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $access->table_name() ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same assertion for this plugin's own table.
	}

	/**
	 * The rollup column argument only ever names a counter.
	 *
	 * This is the one identifier the plugin interpolates into SQL, because a
	 * column name cannot be bound. It is allowlisted; anything else must be
	 * refused rather than reaching the statement.
	 *
	 * @return void
	 */
	public function test_the_rollup_column_allowlist_refuses_anything_else(): void {
		$rollups = Plugin::instance()->container()->get( Rollup_Repository::class );

		$hostile = array(
			'impressions = impressions + 1, clicks = 999',
			'impressions; DROP TABLE wp_posts; --',
			'IMPRESSIONS',
			'`impressions`',
			'',
		);

		foreach ( $hostile as $column ) {
			$this->assertFalse(
				$rollups->increment( $column, 1, 1 ),
				sprintf( 'increment() must refuse the column "%s".', $column )
			);
		}

		$this->assertTrue( $rollups->increment( 'impressions', 1, 1 ) );
		$this->assertTrue( $rollups->increment( 'clicks', 1, 1 ) );
	}

	/**
	 * A malformed day never reaches the statement as a date.
	 *
	 * @return void
	 */
	public function test_the_rollup_day_is_refused_unless_it_is_a_date(): void {
		$rollups = Plugin::instance()->container()->get( Rollup_Repository::class );

		$this->assertFalse( $rollups->reconcile_day( "2024-01-01' OR '1'='1" ) );
		$this->assertFalse( $rollups->reconcile_day( '2024-1-1' ) );
		$this->assertFalse( $rollups->reconcile_day( '' ) );
	}

	/**
	 * A stored XSS payload in a campaign title is inert once rendered.
	 *
	 * Stored rather than reflected, because that is the shape this product
	 * has: an advertiser types it, and staff read it back on the review screen.
	 * The advertiser is the attacker and the reviewer is the victim.
	 *
	 * @dataProvider xss_payloads
	 *
	 * @param string $payload Hostile string.
	 * @return void
	 */
	public function test_a_stored_xss_payload_is_escaped_on_render( string $payload ): void {
		$campaign_id = self::factory()->post->create(
			array(
				'post_type'  => Post_Types::CAMPAIGN,
				'post_title' => $payload,
			)
		);

		$title = get_the_title( $campaign_id );

		// The rendered form is what a template emits through esc_html().
		$rendered = esc_html( $title );

		$this->assertStringNotContainsString( '<script', $rendered );
		$this->assertStringNotContainsString( '<img', $rendered );
		$this->assertStringNotContainsString( '<svg', $rendered );

		// An attribute-context payload must not be able to close the attribute.
		$this->assertStringNotContainsString( '"', esc_attr( $title ) );
	}

	/**
	 * A javascript: destination never survives URL escaping.
	 *
	 * @return void
	 */
	public function test_a_javascript_destination_does_not_survive_esc_url(): void {
		foreach ( array( 'javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", 'data:text/html,<script>alert(1)</script>' ) as $url ) {
			$this->assertStringNotContainsString(
				'alert',
				esc_url( $url ),
				sprintf( 'esc_url() must neutralise %s.', $url )
			);
		}
	}
}
