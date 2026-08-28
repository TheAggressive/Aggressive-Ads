<?php
/**
 * Shared receipt reservation and retry scheduling for campaign mail.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Throwable;

/**
 * The mechanics every campaign mailer repeats: reserve a receipt, send, release
 * on failure; schedule a bounded, deduplicated cron retry with a fixed backoff.
 */
final class Notification_Delivery {

	public const MAX_RETRIES = 3;

	public const RESULT_QUEUED  = 'queued';
	public const RESULT_SKIPPED = 'skipped';
	public const RESULT_FAILED  = 'failed';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 * @param Audit_Repository    $audit     Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Reserves a receipt, runs the transport, and releases on failure.
	 *
	 * @param int             $campaign_id Campaign post id.
	 * @param string          $receipt     Idempotence key.
	 * @param callable():bool $send        Transport that returns true when queued.
	 * @return string One of the RESULT_* constants.
	 */
	public function deliver( int $campaign_id, string $receipt, callable $send ): string {
		if ( ! $this->campaigns->reserve_notification_receipt( $campaign_id, $receipt ) ) {
			return self::RESULT_SKIPPED;
		}

		if ( ! $send() ) {
			$this->campaigns->release_notification_receipt( $campaign_id, $receipt );

			return self::RESULT_FAILED;
		}

		return self::RESULT_QUEUED;
	}

	/**
	 * Runs a callback in the recipient's locale when one is available.
	 *
	 * @template T
	 * @param int           $user_id  Recipient user id.
	 * @param callable(): T $callback Work that builds and sends the message.
	 * @return T|false Callback result, or false when the callback throws.
	 */
	public function with_user_locale( int $user_id, callable $callback ): mixed {
		$switched = switch_to_user_locale( $user_id );

		try {
			return $callback();
		} catch ( Throwable ) {
			return false;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Schedules one deduplicated retry with the shared backoff curve.
	 *
	 * @param string               $hook          Cron hook.
	 * @param array<int, mixed>    $args          Hook arguments including attempt.
	 * @param int                  $attempt       One-based attempt number.
	 * @param int                  $campaign_id   Campaign post id for audit.
	 * @param array<string, mixed> $audit_context Context without PII.
	 * @return void
	 */
	public function schedule_retry( string $hook, array $args, int $attempt, int $campaign_id, array $audit_context ): void {
		if ( $attempt < 1 || $attempt > self::MAX_RETRIES ) {
			return;
		}

		$args = array_values( $args );

		if ( false !== wp_get_scheduled_event( $hook, $args ) ) {
			return;
		}

		$result = wp_schedule_single_event( time() + $this->delay_for( $attempt ), $hook, $args, true );

		if ( false !== $result && ! is_wp_error( $result ) ) {
			return;
		}

		if ( false !== wp_next_scheduled( $hook, $args ) ) {
			return;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.notification_retry_schedule_failed',
				outcome: Audit_Event::OUTCOME_FAILED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Campaign notification retry could not be scheduled.',
				context: $audit_context
			)
		);
	}

	/**
	 * Backoff for a one-based attempt: 5 minutes, 30 minutes, then 2 hours.
	 *
	 * @param int $attempt One-based attempt.
	 * @return int Seconds.
	 */
	public function delay_for( int $attempt ): int {
		return match ( $attempt ) {
			1       => 5 * MINUTE_IN_SECONDS,
			2       => 30 * MINUTE_IN_SECONDS,
			default => 2 * HOUR_IN_SECONDS,
		};
	}

	/**
	 * Builds valid email sender headers, avoiding invalid localhost addresses in local environments.
	 *
	 * @return list<string>
	 */
	public static function sender_headers(): array {
		$admin_email = get_option( 'admin_email' );
		$from_email  = is_string( $admin_email ) && is_email( $admin_email ) ? $admin_email : '';

		if ( '' === $from_email || str_ends_with( $from_email, '@localhost' ) ) {
			$parsed_host = wp_parse_url( home_url(), PHP_URL_HOST );
			$host        = is_string( $parsed_host ) && '' !== $parsed_host ? $parsed_host : 'localhost';
			$domain      = ( 'localhost' === $host || ! str_contains( $host, '.' ) ) ? 'localhost.localdomain' : $host;
			$from_email  = 'no-reply@' . $domain;
		}

		$site_name = sanitize_text_field( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' === $site_name ) {
			$site_name = 'Advertising';
		}

		return array(
			sprintf( 'From: %s <%s>', $site_name, $from_email ),
		);
	}
}
