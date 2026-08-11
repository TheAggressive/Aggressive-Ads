<?php
/**
 * Audit event validation tests.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Audit;

use InvalidArgumentException;
use LAAO_Advertiser_Portal\Audit\Audit_Event;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The audit log is retained longer than most of what could be written into it,
 * and read by more people than write it. These tests are about what must never
 * reach it.
 */
final class AuditEventTest extends TestCase {

	/**
	 * A denial is a first-class outcome. A log recording only successes cannot
	 * show an attack — it can only fail to show one, and absence is not
	 * something you can query for.
	 *
	 * @return void
	 */
	public function test_denied_is_a_valid_outcome(): void {
		$event = new Audit_Event(
			event: 'campaign.transition',
			outcome: Audit_Event::OUTCOME_DENIED
		);

		$this->assertSame( 'denied', $event->outcome() );
		$this->assertContains( 'denied', Audit_Event::outcomes() );
	}

	/**
	 * An unknown outcome is a programming error, caught at construction rather
	 * than becoming an unqueryable string in a column.
	 *
	 * @return void
	 */
	public function test_unknown_outcome_is_rejected(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Unknown audit outcome' );

		new Audit_Event( event: 'campaign.transition', outcome: 'maybe' );
	}

	/**
	 * An unnamed event is unqueryable, so it is refused.
	 *
	 * @param string $name A name that is not one.
	 * @return void
	 *
	 * @dataProvider data_empty_names
	 */
	public function test_an_event_must_be_named( string $name ): void {
		$this->expectException( InvalidArgumentException::class );

		new Audit_Event( event: $name );
	}

	/**
	 * Names that are not names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_empty_names(): array {
		return array(
			'empty'   => array( '' ),
			'spaces'  => array( '   ' ),
			'newline' => array( "\n" ),
			'tab'     => array( "\t" ),
		);
	}

	/**
	 * A secret at the top level of the context is refused.
	 *
	 * @param string $key A key that must never be logged.
	 * @return void
	 *
	 * @dataProvider data_forbidden_keys
	 */
	public function test_forbidden_context_keys_are_rejected( string $key ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Audit context may not contain' );

		new Audit_Event( event: 'campaign.submitted', context: array( $key => 'value' ) );
	}

	/**
	 * Keys that must never be logged.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_forbidden_keys(): array {
		return array(
			'password'      => array( 'password' ),
			'nonce'         => array( '_wpnonce' ),
			'token'         => array( 'token' ),
			'private token' => array( '_laao_ads_private_token' ),
			'raw ip'        => array( 'ip_address' ),
			'email address' => array( 'recipient_email' ),
			'file path'     => array( '_laao_ads_private_path' ),
			'authorization' => array( 'authorization' ),
		);
	}

	/**
	 * A secret nested inside the context is refused too.
	 *
	 * This is the case that matters. A forbidden key is almost never at the top
	 * level — it arrives because somebody passed a request payload through
	 * wholesale, and the nonce is two levels down inside it.
	 *
	 * @return void
	 */
	public function test_nested_forbidden_keys_are_rejected(): void {
		$this->expectException( InvalidArgumentException::class );

		new Audit_Event(
			event: 'campaign.submitted',
			context: array(
				'request' => array(
					'body' => array(
						'campaign_id' => 42,
						'_wpnonce'    => 'abc123',
					),
				),
			)
		);
	}

	/**
	 * Key matching ignores case, since request payloads are inconsistent.
	 *
	 * @return void
	 */
	public function test_forbidden_key_matching_is_case_insensitive(): void {
		$this->expectException( InvalidArgumentException::class );

		new Audit_Event( event: 'campaign.submitted', context: array( 'Authorization' => 'Bearer x' ) );
	}

	/**
	 * Ordinary structured detail is accepted, or the guard would be useless.
	 *
	 * @return void
	 */
	public function test_safe_context_is_accepted(): void {
		$event = new Audit_Event(
			event: 'campaign.submitted',
			object_type: 'campaign',
			object_id: 42,
			org_id: 7,
			from_state: 'lap_draft',
			to_state: 'lap_submitted',
			context: array(
				'placements' => array( 6, 48 ),
				'revision'   => 2,
			)
		);

		$this->assertSame( 'campaign', $event->object_type() );
		$this->assertSame( 42, $event->object_id() );
		$this->assertSame( 7, $event->org_id() );
		$this->assertSame( 'lap_draft', $event->from_state() );
		$this->assertSame( 'lap_submitted', $event->to_state() );
		$this->assertSame( array( 6, 48 ), $event->context()['placements'] );
	}

	/**
	 * The message is truncated to what the column holds, rather than letting
	 * MySQL do it — which either warns or silently cuts, depending on mode.
	 *
	 * @return void
	 */
	public function test_message_is_truncated_to_the_column_width(): void {
		$event = new Audit_Event(
			event: 'campaign.submitted',
			message: str_repeat( 'a', 400 )
		);

		$this->assertSame( Audit_Event::MAX_MESSAGE_LENGTH, strlen( $event->message() ) );
	}

	/**
	 * A message that fits is untouched.
	 *
	 * @return void
	 */
	public function test_a_short_message_is_unchanged(): void {
		$event = new Audit_Event( event: 'campaign.submitted', message: 'Submitted for review.' );

		$this->assertSame( 'Submitted for review.', $event->message() );
	}

	/**
	 * The system actor is user 0, which is what migrations and cron write as.
	 *
	 * @return void
	 */
	public function test_the_system_actor_is_zero(): void {
		$event = new Audit_Event( event: 'plugin.migrated' );

		$this->assertSame( 0, $event->actor_user_id() );
	}
}
