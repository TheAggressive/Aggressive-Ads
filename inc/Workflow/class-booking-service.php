<?php
/**
 * Books inventory against a forecast, and records when somebody sells past it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Availability;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Reservation_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * The one place a booking decision is made, so it is made the same way twice.
 *
 * `Reservation_Repository` enforces capacity atomically but is told what the
 * capacity is; `Forecast_Repository` knows what was forecast but not what has
 * been claimed. Neither can answer "may this be booked", and a caller that
 * assembled the answer itself would be a second opinion about which forecast
 * version counts and whether a hold consumes.
 *
 * **Overselling is warned about, not prevented.** The contract is explicit:
 * oversell warns and logs the override rather than silently blocking staff. A
 * forecast is the twentieth percentile of observed days, so a publisher who
 * knows their inventory better than the model does is often right to sell past
 * it. What must not happen is selling past it unrecorded — so an override
 * carries a reason, and the audit row names the actor, the reason, the
 * forecast version it overrode and the shortfall it accepted. That is the
 * contract's "actor, reason, forecast version and expected impact", written
 * where an investigation will look.
 *
 * **Gated on `MANAGE_PLACEMENTS`, not on a capability of its own.** A separate
 * `oversell` primitive was the obvious design and would today have exactly the
 * same holders — only administrators hold `MANAGE_PLACEMENTS` — so it would be
 * a distinction with no difference, and a permission nobody can hold
 * separately reads as protection it does not provide. Role granularity arrives
 * with P21; that is the change which makes a second capability mean something.
 */
final class Booking_Service {

	/**
	 * Composes the forecast, the ledger and the audit log.
	 *
	 * @param Forecast_Repository    $forecasts    What a window was forecast to supply.
	 * @param Reservation_Repository $reservations What has already been claimed.
	 * @param Audit_Repository       $audit        Where an override is recorded.
	 */
	public function __construct(
		private readonly Forecast_Repository $forecasts,
		private readonly Reservation_Repository $reservations,
		private readonly Audit_Repository $audit
	) {}

	/**
	 * Whether a window has room for a request, and by how much it does not.
	 *
	 * **Read before the lock, and therefore advisory.** Two staff can both be
	 * shown "available" and only one of them can book it, which is why
	 * `Reservation_Repository::claim()` checks again inside its critical
	 * section. This answer is for a screen; that one is the truth.
	 *
	 * @param int    $placement    Placement post id.
	 * @param string $opportunity  `Domain\Opportunity` kind.
	 * @param string $from_utc     First day of the window, `Y-m-d`.
	 * @param string $to_utc       Last day of the window, `Y-m-d`.
	 * @param int    $requested    Opportunities wanted.
	 * @return array{verdict: string, remaining: int|null, shortfall: int, capacity: int|null, committed: int, forecast_version: int}
	 */
	public function availability( int $placement, string $opportunity, string $from_utc, string $to_utc, int $requested ): array {
		$snapshot = $this->forecasts->latest( $placement, $opportunity, $from_utc, $to_utc );
		$capacity = is_array( $snapshot ) ? (int) $snapshot['estimate'] : null;
		$version  = is_array( $snapshot ) ? (int) $snapshot['version'] : 0;

		$committed = $this->reservations->committed( $placement, $opportunity, $from_utc, $to_utc );
		$decision  = Availability::decide( $capacity, $committed, $requested );

		return array(
			'verdict'          => $decision['verdict'],
			'remaining'        => $decision['remaining'],
			'shortfall'        => $decision['shortfall'],
			'capacity'         => $capacity,
			'committed'        => $committed,
			'forecast_version' => $version,
		);
	}

	/**
	 * Holds inventory, refusing an unacknowledged oversell.
	 *
	 * An oversell without a reason is refused rather than warned about,
	 * because a warning nobody has to answer is a warning nobody reads. With a
	 * reason it proceeds and is recorded — the reason is what turns "the system
	 * let me" into "somebody decided", and it is the only part of the audit row
	 * a person has to supply.
	 *
	 * @param array{placement: int, opportunity: string, from: string, to: string, campaign: int, org: int, quantity: int} $claim  What to book.
	 * @param string                                                                                                       $reason Why an oversell is acceptable, when it is one.
	 * @return array{id: int, verdict: string, shortfall: int}|WP_Error
	 */
	public function book( array $claim, string $reason = '' ) {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			$this->deny( $claim, 'Booking refused: the current user may not manage inventory.' );

			return new WP_Error(
				'aggr_booking_forbidden',
				__( 'You do not have permission to book inventory.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Opportunity::is_valid( (string) ( $claim['opportunity'] ?? '' ) ) ) {
			return new WP_Error(
				'aggr_booking_opportunity',
				__( 'That is not a kind of inventory.', 'aggressive-ads' ),
				array( 'status' => 400 )
			);
		}

		$available = $this->availability(
			(int) $claim['placement'],
			(string) $claim['opportunity'],
			(string) $claim['from'],
			(string) $claim['to'],
			(int) $claim['quantity']
		);

		$oversell = Availability::OVERSELL === $available['verdict'];

		if ( $oversell && '' === trim( $reason ) ) {
			return new WP_Error(
				'aggr_booking_oversell',
				__( 'This booking is larger than the forecast supports. Give a reason to book it anyway.', 'aggressive-ads' ),
				array(
					'status'    => 409,
					'shortfall' => $available['shortfall'],
					'remaining' => $available['remaining'],
				)
			);
		}

		$result = $this->reservations->claim(
			array(
				'placement'        => (int) $claim['placement'],
				'opportunity'      => (string) $claim['opportunity'],
				'from'             => (string) $claim['from'],
				'to'               => (string) $claim['to'],
				'campaign'         => (int) ( $claim['campaign'] ?? 0 ),
				'org'              => (int) ( $claim['org'] ?? 0 ),
				'quantity'         => (int) $claim['quantity'],

				'capacity'         => Availability::ceiling( (int) $available['committed'], (int) $claim['quantity'] ),
				'forecast_version' => $available['forecast_version'],
			)
		);

		if ( 0 === $result['id'] ) {
			return new WP_Error(
				'aggr_booking_refused',
				__( 'The booking could not be recorded.', 'aggressive-ads' ),
				array(
					'status' => 'capacity' === $result['refused'] ? 409 : 400,
					'reason' => $result['refused'],
				)
			);
		}

		if ( $oversell ) {
			$this->record_override( $claim, $available, $reason, $result['id'] );
		}

		return array(
			'id'        => $result['id'],
			'verdict'   => $available['verdict'],
			'shortfall' => $available['shortfall'],
		);
	}


	/**
	 * Writes the row an investigation opens the log for.
	 *
	 * Everything the contract asks an override to name is here: the actor, the
	 * reason a person typed, the forecast version overridden and the shortfall
	 * accepted. The forecast *value* is recorded too, because a version number
	 * alone stops meaning anything once the snapshot is purged by retention.
	 *
	 * @param array<string, mixed> $claim     The booking.
	 * @param array<string, mixed> $available What the forecast said.
	 * @param string               $reason    Why it was booked anyway.
	 * @param int                  $id        Reservation row id.
	 */
	private function record_override( array $claim, array $available, string $reason, int $id ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'reservation.oversold',
				outcome: Audit_Event::OUTCOME_OK,
				object_type: 'reservation',
				object_id: $id,
				org_id: (int) ( $claim['org'] ?? 0 ),
				message: 'Inventory booked beyond the forecast.',
				context: array(
					'placement_id'     => (int) $claim['placement'],
					'opportunity'      => (string) $claim['opportunity'],
					'window_start'     => (string) $claim['from'],
					'window_end'       => (string) $claim['to'],
					'quantity'         => (int) $claim['quantity'],
					'forecast_version' => (int) $available['forecast_version'],
					'forecast'         => $available['capacity'],
					'committed'        => (int) $available['committed'],
					'shortfall'        => (int) $available['shortfall'],
					'reason'           => $reason,
				),
				actor_user_id: get_current_user_id()
			)
		);
	}

	/**
	 * Records a refusal.
	 *
	 * `denied` is the row an investigation opens the log for, and a log that
	 * only records successes cannot show an attempt.
	 *
	 * @param array<string, mixed> $claim   The booking that was refused.
	 * @param string               $message What to record.
	 */
	private function deny( array $claim, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'reservation.denied',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'placement',
				object_id: (int) ( $claim['placement'] ?? 0 ),
				org_id: (int) ( $claim['org'] ?? 0 ),
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
