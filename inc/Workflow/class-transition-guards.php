<?php
/**
 * Guard evaluation for campaign transitions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Repository\Campaign_Repository;
use WP_Error;

/**
 * Answers whether each named guard holds for a campaign.
 *
 * Guards are resolved by name from the transition table, and **an unknown
 * guard fails closed**. A missing implementation must refuse rather than
 * quietly skip the check that was supposed to protect it.
 */
final class Transition_Guards {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository                                                 $campaigns Campaign persistence.
	 * @param array<string, callable(int, array<string, mixed>): (true|WP_Error)> $extra     Guards supplied by later phases, keyed by name.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly array $extra = array()
	) {
	}

	/**
	 * Evaluates every guard a transition declares.
	 *
	 * @param array<int, string>   $guards      Guard names.
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $context     Caller-supplied context.
	 * @return true|WP_Error
	 */
	public function check( array $guards, int $campaign_id, array $context ) {
		foreach ( $guards as $guard ) {
			$result = $this->evaluate( $guard, $campaign_id, $context );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Evaluates one guard.
	 *
	 * @param string               $guard       Guard name.
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $context     Caller-supplied context.
	 * @return true|WP_Error
	 */
	private function evaluate( string $guard, int $campaign_id, array $context ) {
		if ( isset( $this->extra[ $guard ] ) ) {
			return ( $this->extra[ $guard ] )( $campaign_id, $context );
		}

		switch ( $guard ) {
			case Transition_Table::GUARD_UNCLAIMED:
				return $this->unclaimed( $campaign_id );

			case Transition_Table::GUARD_REVIEW_NOTES:
				return $this->review_notes( $context );

			case Transition_Table::GUARD_STARTED:
				return $this->started( $campaign_id );

			case Transition_Table::GUARD_NOT_STARTED:
				return $this->not_started( $campaign_id );

			case Transition_Table::GUARD_ENDED:
				return $this->ended( $campaign_id );
		}

		// Fails closed, and names itself. A guard that silently passed because
		// nobody had implemented it yet would be indistinguishable from one
		// that ran and approved.
		return new WP_Error(
			'aggr_guard_unavailable',
			__( 'This action cannot be completed yet.', 'aggressive-ads' ),
			array( 'guard' => $guard )
		);
	}

	/**
	 * No reviewer has claimed the campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function unclaimed( int $campaign_id ) {
		if ( 0 !== $this->campaigns->reviewed_by( $campaign_id ) ) {
			return new WP_Error(
				'aggr_campaign_claimed',
				__( 'This campaign is already being reviewed and can no longer be withdrawn.', 'aggressive-ads' )
			);
		}

		return true;
	}

	/**
	 * Advertiser-visible feedback is present.
	 *
	 * Taken from the caller's context rather than from storage, because the
	 * notes for *this* decision are what must be non-empty — leftover notes
	 * from a previous round would otherwise satisfy the guard.
	 *
	 * @param array<string, mixed> $context Caller-supplied context.
	 * @return true|WP_Error
	 */
	private function review_notes( array $context ) {
		$notes = isset( $context['review_notes'] ) && is_string( $context['review_notes'] )
			? trim( $context['review_notes'] )
			: '';

		if ( '' === $notes ) {
			return new WP_Error(
				'aggr_review_notes_required',
				__( 'Tell the advertiser what needs to change before sending the campaign back.', 'aggressive-ads' )
			);
		}

		return true;
	}

	/**
	 * The start time has arrived.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function started( int $campaign_id ) {
		$start = $this->campaigns->start_ts( $campaign_id );

		if ( 0 === $start || $start > time() ) {
			return new WP_Error(
				'aggr_not_started',
				__( 'This campaign has not reached its start date.', 'aggressive-ads' )
			);
		}

		return true;
	}

	/**
	 * The start time is still ahead.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function not_started( int $campaign_id ) {
		$start = $this->campaigns->start_ts( $campaign_id );

		if ( 0 !== $start && $start <= time() ) {
			return new WP_Error(
				'aggr_already_started',
				__( 'This campaign has already reached its start date.', 'aggressive-ads' )
			);
		}

		return true;
	}

	/**
	 * The end time has passed.
	 *
	 * An end time of zero means open-ended, which never ends.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	private function ended( int $campaign_id ) {
		$end = $this->campaigns->end_ts( $campaign_id );

		if ( 0 === $end || $end >= time() ) {
			return new WP_Error(
				'aggr_not_ended',
				__( 'This campaign has not reached its end date.', 'aggressive-ads' )
			);
		}

		return true;
	}
}
