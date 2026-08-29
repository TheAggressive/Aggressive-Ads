<?php
/**
 * Recording one attributed conversion.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;

/**
 * Turns a report into a row, or into a reason it is not one.
 *
 * The ledger is durable truth and the rollup is a projection, exactly as for
 * every other measurement event: the conversion row is written first and a
 * failed projection never discards it, because the daily reconcile rebuilds the
 * counter from the ledger.
 *
 * Nothing a client sends decides attribution. The token is signed, the
 * interaction is a row the server wrote, the window and the value come from the
 * definition, and the organization comes from the campaign. The only client
 * input that survives is *which* definition and *when* the outcome happened,
 * and both are bounded against server state.
 */
final class Conversion_Recorder {

	/** Accepted and written. */
	public const RECORDED = 'recorded';

	/** Accepted before, and counted once. */
	public const DUPLICATE = 'duplicate';

	/** Written, but the projection did not land. The reconcile repairs it. */
	public const RECORDED_PENDING = 'recorded_pending';

	/** The write itself failed. Retryable. */
	public const FAILED = 'failed';

	/**
	 * Constructor.
	 *
	 * @param Conversion_Repository            $conversions Durable conversion ledger.
	 * @param Conversion_Definition_Repository $definitions What counts as a conversion.
	 * @param Event_Repository                 $events      Interaction lineage.
	 * @param Rollup_Repository                $rollups     Reporting projection.
	 * @param Campaign_Repository              $campaigns   Campaign ownership.
	 * @param Creative_Assignment_Repository   $assignments Line-item attribution.
	 */
	public function __construct(
		private readonly Conversion_Repository $conversions,
		private readonly Conversion_Definition_Repository $definitions,
		private readonly Event_Repository $events,
		private readonly Rollup_Repository $rollups,
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Assignment_Repository $assignments
	) {
	}

	/**
	 * Records one conversion against a verified token.
	 *
	 * @param array{blog_id: int, placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string} $parsed          Verified token payload.
	 * @param string                                                                                              $token_hash      Replay digest of the same token.
	 * @param string                                                                                              $public_key      Definition the reporter named.
	 * @param string                                                                                              $idempotency_key Reporter-supplied outcome key.
	 * @param int                                                                                                 $occurred_at_ts  When the outcome happened.
	 * @param string                                                                                              $source          Conversion_Rules::SOURCE_*.
	 * @return array{outcome: string, reason: string}
	 */
	public function record(
		array $parsed,
		string $token_hash,
		string $public_key,
		string $idempotency_key,
		int $occurred_at_ts,
		string $source
	): array {
		$definition = $this->definitions->find_by_public_key( $public_key );

		/*
		 * The campaign's organization, not the definition's. A house fill has
		 * campaign 0 and therefore organization 0, which only a house
		 * definition (org 0) can match.
		 */
		$campaign_org_id = $parsed['campaign_id'] > 0 ? $this->campaigns->org_id( $parsed['campaign_id'] ) : 0;

		/*
		 * Decided once with no interaction, which answers every definition-level
		 * question on its own. `NO_INTERACTION` is the only reason that can
		 * survive a null lineage, so any other answer here is a refusal that
		 * never needed the ledger at all.
		 *
		 * That ordering is the point: this endpoint is public, and a request
		 * naming a definition that does not exist must cost one indexed read
		 * rather than a read plus a seek into the highest-volume table in the
		 * schema. The cheap path is the one an attacker repeats.
		 */
		$without_lineage = Conversion_Attribution::decide( $definition, null, $campaign_org_id, $occurred_at_ts );

		if ( Conversion_Attribution::NO_INTERACTION !== $without_lineage ) {
			return array(
				'outcome' => self::FAILED,
				'reason'  => $without_lineage,
			);
		}

		if ( null === $definition ) {
			// Unreachable: a null definition answers NO_DEFINITION above. Kept
			// so the narrowing is real rather than asserted.
			return array(
				'outcome' => self::FAILED,
				'reason'  => Conversion_Attribution::NO_DEFINITION,
			);
		}

		$interaction = $this->events->interaction_for_token( $token_hash );
		$reason      = Conversion_Attribution::decide( $definition, $interaction, $campaign_org_id, $occurred_at_ts );

		if ( Conversion_Attribution::ACCEPTED !== $reason || null === $interaction ) {
			return array(
				'outcome' => self::FAILED,
				'reason'  => $reason,
			);
		}

		$line_item_id = $this->assignments->line_item_for( $parsed['creative_id'], $parsed['placement_id'] );

		$written = $this->conversions->insert(
			array(
				'definition_id'    => (int) $definition['id'],
				'idempotency_key'  => $idempotency_key,
				'placement_id'     => $parsed['placement_id'],
				'campaign_id'      => $parsed['campaign_id'],
				'creative_id'      => $parsed['creative_id'],
				'line_item_id'     => $line_item_id,
				'token_hash'       => $token_hash,
				'attributed_event' => (string) $interaction['event'],
				'occurred_at_ts'   => $occurred_at_ts,

				/*
				 * From the definition, never the request. This is the whole
				 * reason a definition is a stored record: an anonymous browser
				 * may not declare what its own outcome was worth.
				 */
				'value_micros'     => (int) $definition['default_value_micros'],
				'currency'         => (string) $definition['currency'],
				'source'           => $source,
			)
		);

		if ( ! $written ) {
			return array(
				'outcome' => $this->conversions->exists( (int) $definition['id'], $idempotency_key )
					? self::DUPLICATE
					: self::FAILED,
				'reason'  => Conversion_Attribution::ACCEPTED,
			);
		}

		$projected = $this->rollups->increment(
			'conversions',
			$parsed['placement_id'],
			$parsed['campaign_id'],
			gmdate( 'Y-m-d', $occurred_at_ts ),
			$line_item_id
		);

		return array(
			'outcome' => $projected ? self::RECORDED : self::RECORDED_PENDING,
			'reason'  => Conversion_Attribution::ACCEPTED,
		);
	}

	/**
	 * The source a browser report is recorded under.
	 */
	public static function browser_source(): string {
		return Conversion_Rules::SOURCE_BROWSER;
	}
}
