<?php
/**
 * A campaign's audit log, as the review timeline reads it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\User_Repository;

/**
 * Turns stored audit rows into the timeline's entries: the sentence in the
 * reader's words, the day and time apart, the two ends of a status change as
 * pills, and an outcome only when it was not a plain success.
 *
 * Split out of Review_Data, which had grown past nine hundred lines; the trail
 * is the one part of the campaign view with its own query (the log) and its
 * own vocabulary, so it is the part that changes on its own.
 */
final class Audit_Trail {

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository $audit Audit history.
	 * @param User_Repository  $users Actor lookups.
	 */
	public function __construct(
		private readonly Audit_Repository $audit,
		private readonly User_Repository $users
	) {
	}

	/**
	 * Recent history for the staff timeline.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $org_id      The campaign's organization; the log is read through its index.
	 * @return array<int, array<string, mixed>>
	 */
	public function for_campaign( int $campaign_id, int $org_id ): array {
		$events = $this->audit->for_object( 'campaign', $campaign_id, $org_id );

		/*
		 * Every actor's name in one read. Each `get_userdata()` below was its
		 * own pair of queries on a cold cache, so the cost of opening a
		 * campaign grew with how many people had touched it — up to fifty, the
		 * log's page size. Measured: two queries per distinct actor before
		 * this, a constant after.
		 */
		$this->users->prime( array_column( $events, 'actor_user_id' ) );

		$rows = array();

		foreach ( $events as $event ) {
			$transition = '' !== $event['from_state'] && '' !== $event['to_state'];

			$rows[] = array(
				'id'            => $event['id'],
				'created_at'    => $event['created_at_ts'],
				'created_text'  => Review_Format::date( $event['created_at_ts'], true ),

				/*
				 * The day and the time apart, so the timeline can head each day
				 * once and print only the time under it. Formatted here, in the
				 * site's timezone, rather than in the browser's: a log grouped by
				 * the reader's midnight puts a late-evening approval under the
				 * wrong date for everybody in another zone.
				 */
				'day_text'      => Review_Format::date( $event['created_at_ts'] ),
				'time_text'     => Review_Format::time( $event['created_at_ts'] ),
				'actor'         => 0 === $event['actor_user_id'] ? __( 'System', 'aggressive-ads' ) : Review_Format::user( $event['actor_user_id'] ),
				'system'        => 0 === $event['actor_user_id'],
				'event'         => $event['event'],
				'outcome'       => $event['outcome'],
				'outcome_label' => self::outcome_label( $event['outcome'] ),
				'message'       => self::event_message( $event ),

				/*
				 * A status change carries its two ends, so the timeline can
				 * draw them as the same pills the queue uses. A refused one
				 * carries them too: it is the move that did not happen.
				 */
				'from_label'    => $transition ? Review_Format::status( $event['from_state'] ) : '',
				'from_pill'     => $transition ? View_Data::pill_for( $event['from_state'] ) : '',
				'to_label'      => $transition ? Review_Format::status( $event['to_state'] ) : '',
				'to_pill'       => $transition ? View_Data::pill_for( $event['to_state'] ) : '',
			);
		}

		return $rows;
	}

	/**
	 * One audit row's sentence, in the reader's words rather than the schema's.
	 *
	 * A transition stores its own message as `Campaign moved from aggr_submitted
	 * to aggr_review.`, which is the right thing to *store* — an audit row is a
	 * record, and freezing a translated string into it would make the log read
	 * in whichever locale happened to be active when it was written. The status
	 * slugs are also kept in their own columns for exactly this reason.
	 *
	 * So the sentence is composed here, at render time, from those columns. That
	 * localizes it properly and fixes every row already in the table rather than
	 * only the ones written from now on.
	 *
	 * Scoped to `campaign.transitioned` on purpose. A denial carries from/to as
	 * well, and its own message says something this one does not.
	 *
	 * @param array{event: string, from_state: string, to_state: string, message: string} $event Stored row.
	 * @return string
	 */
	private static function event_message( array $event ): string {
		if (
			'campaign.transitioned' !== $event['event']
			|| '' === $event['from_state']
			|| '' === $event['to_state']
		) {
			return $event['message'];
		}

		return sprintf(
			/* translators: 1: previous campaign status, already translated. 2: new campaign status, already translated. */
			__( 'Campaign moved from %1$s to %2$s.', 'aggressive-ads' ),
			Review_Format::status( $event['from_state'] ),
			Review_Format::status( $event['to_state'] )
		);
	}

	/**
	 * An audit outcome in words, or '' for an ordinary success.
	 *
	 * The timeline printed the stored slug — "denied" — untranslated, beside
	 * every entry including the ones that simply happened. Success needs no
	 * word; a refusal and a failure each need one a translator has seen.
	 *
	 * @param string $outcome Stored outcome.
	 * @return string
	 */
	private static function outcome_label( string $outcome ): string {
		return match ( $outcome ) {
			Audit_Event::OUTCOME_DENIED => _x( 'Refused', 'audit outcome', 'aggressive-ads' ),
			Audit_Event::OUTCOME_FAILED => _x( 'Failed', 'audit outcome', 'aggressive-ads' ),
			default                     => '',
		};
	}
}
