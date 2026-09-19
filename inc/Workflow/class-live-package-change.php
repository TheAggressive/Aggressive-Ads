<?php
/**
 * Choosing a different package for a campaign that is already running.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Domain\Validation_Result;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use WP_Error;

/**
 * An upgrade or a downgrade, proposed like any other live edit.
 *
 * **Creation's rules, not a second copy of them.** Whether a package may be
 * bought, and what buying it writes — its placements, its price and currency
 * frozen onto the campaign — is `Campaign_Editor::package_snapshot()`, which
 * the creation wizard uses. A live change applies the same snapshot, so a
 * campaign upgraded while running carries exactly what one created on that
 * package would.
 *
 * **Nothing changes until it is approved.** The campaign keeps running on its
 * current package, at its current price, while the proposal waits. Approval
 * is when the snapshot is written.
 *
 * **Billing settles the difference later (#263).** The price of both packages
 * as they stand when the change is proposed, and again when it is approved, is
 * recorded in the audit trail, so the ledger can charge an upgrade or credit a
 * downgrade from what the advertiser was shown rather than from a package
 * price that may have moved since.
 */
final class Live_Package_Change {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Campaign persistence.
	 * @param Package_Repository  $packages  The catalogue.
	 * @param Campaign_Editor     $editor    Owns what buying a package writes.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Package_Repository $packages,
		private readonly Campaign_Editor $editor
	) {
	}

	/**
	 * The packages a running campaign may move to: those on sale today.
	 *
	 * @return array<int, int>
	 */
	public function on_sale(): array {
		return array_values( array_map( 'intval', $this->packages->active_ids() ) );
	}

	/**
	 * The placements a package sells, or none for no package.
	 *
	 * @param int $package_id Package post id.
	 * @return array<int, int>
	 */
	public function placements( int $package_id ): array {
		return $package_id > 0 ? array_map( 'intval', $this->packages->placement_ids( $package_id ) ) : array();
	}

	/**
	 * The placements a change may choose from, or none to mean any.
	 *
	 * The package's own placements, because the package is what was priced,
	 * and whatever the campaign runs on now, so nothing live is taken away by
	 * a package being edited after the sale. Empty when there is no package —
	 * a campaign made before packages, or by staff — which leaves the rule as
	 * it was. With a proposed package, that package's placements: an upgrade
	 * brings its placements with it, and choosing among them is the point.
	 *
	 * @param int      $campaign_id      Campaign post id.
	 * @param int|null $proposed_package A package being moved to, or null for the campaign's own.
	 * @return array<int, int>
	 */
	public function placement_choices( int $campaign_id, ?int $proposed_package = null ): array {
		$package_id = $proposed_package ?? $this->campaigns->package_id( $campaign_id );

		if ( $package_id <= 0 ) {
			return array();
		}

		return array_values(
			array_unique(
				array_map( 'intval', array_merge( $this->placements( $package_id ), $this->campaigns->placement_ids( $campaign_id ) ) )
			)
		);
	}

	/**
	 * The campaign as the rules should judge a change set against it.
	 *
	 * @param array<string, mixed> $current     `Campaign_Change_Manager::current()`.
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       The change set being judged.
	 * @return array<string, mixed>
	 */
	public function judged( array $current, int $campaign_id, array $edits ): array {
		if ( array_key_exists( 'package_id', $edits ) ) {
			$current['placement_choices'] = $this->placement_choices( $campaign_id, (int) $edits['package_id'] );
		}

		return $current;
	}

	/**
	 * Judges a change set as it would be applied.
	 *
	 * With the end its package implies, and against that package's
	 * placements. A downgrade whose derived end has already passed would
	 * otherwise be approved on a site that lets advertisers change packages
	 * but not dates — and complete the campaign the moment it was applied.
	 *
	 * @param array<string, mixed> $current     `Campaign_Change_Manager::current()`.
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       The change set.
	 * @return Validation_Result
	 */
	public function validate( array $current, int $campaign_id, array $edits ): Validation_Result {
		return Live_Edit_Rules::validate( $this->implied( $campaign_id, $edits ), $this->judged( $current, $campaign_id, $edits ), time() );
	}

	/**
	 * A change set with the end its package change implies, as approval writes it.
	 *
	 * What `fields()` will write, made visible: to the rules, and to the
	 * reviewer reading the summary, who should see the end move rather than
	 * find out from the campaign afterwards.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       The change set.
	 * @return array<string, mixed>
	 */
	public function implied( int $campaign_id, array $edits ): array {
		if ( ! array_key_exists( 'package_id', $edits ) || array_key_exists( 'end_ts', $edits ) ) {
			return $edits;
		}

		$derived = $this->with_derived_end( $campaign_id, $edits, array() );

		if ( isset( $derived['end_ts'] ) && (int) $derived['end_ts'] !== $this->campaigns->end_ts( $campaign_id ) ) {
			$edits['end_ts'] = (int) $derived['end_ts'];
		}

		return $edits;
	}

	/**
	 * A proposal with the end a fixed-length package implies.
	 *
	 * Creation's rule: a fixed package sells a number of days, so its end is
	 * derived from the start rather than asked for, and the edit screen hides
	 * the end field for one exactly as the wizard does. A disabled field is not
	 * posted, so without this a new start or a new package would leave the old
	 * end behind — and the "Runs through" line the advertiser was shown would
	 * describe a campaign nobody proposed.
	 *
	 * Only when the start or the package actually moves. Re-saving the step as
	 * it stands must not propose an end a reviewer set by hand, and putting
	 * either back puts the end back.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    One step's fields.
	 * @param array<string, mixed> $staged      The proposal so far.
	 * @return array<string, mixed>
	 */
	public function with_derived_end( int $campaign_id, array $proposed, array $staged ): array {
		if ( array_key_exists( 'end_ts', $proposed ) ) {
			return $proposed;
		}

		$stored_package = $this->campaigns->package_id( $campaign_id );
		$stored_start   = $this->campaigns->start_ts( $campaign_id );
		$package        = (int) ( $proposed['package_id'] ?? $staged['package_id'] ?? $stored_package );
		$start          = (int) ( $proposed['start_ts'] ?? $staged['start_ts'] ?? $stored_start );
		$moved          = ( array_key_exists( 'package_id', $proposed ) && $package !== $stored_package )
			|| ( array_key_exists( 'start_ts', $proposed ) && $start !== $stored_start );

		$posted = array_key_exists( 'package_id', $proposed ) || array_key_exists( 'start_ts', $proposed );

		if ( ! $posted || $package <= 0 || $start <= 0 || $this->packages->has_custom_duration( $package ) ) {
			return $proposed;
		}

		$days = $this->packages->duration_days( $package );

		/*
		 * Put back as it was, the end goes back too. The stored end rather
		 * than nothing: an absent end would leave an earlier step's derived
		 * one in the proposal, where the stored one is no change and drops out.
		 */
		if ( ! $moved ) {
			$proposed['end_ts'] = $this->campaigns->end_ts( $campaign_id );
		} elseif ( $days > 0 ) {
			$proposed['end_ts'] = Campaign_Rules::fixed_end_ts( $start, $days, wp_timezone()->getName() );
		}

		return $proposed;
	}

	/**
	 * Whether a proposed package can be bought, when the change names one.
	 *
	 * @param array<string, mixed> $edits Change set.
	 * @return true|WP_Error
	 */
	public function buyable( array $edits ): bool|WP_Error {
		return array_key_exists( 'package_id', $edits ) ? $this->check( (int) $edits['package_id'] ) : true;
	}

	/**
	 * Both prices of a package change, as audit context billing reads.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       Change set.
	 * @return array<string, mixed> Empty when the package is not changing.
	 */
	public function price_context( int $campaign_id, array $edits ): array {
		return array_key_exists( 'package_id', $edits )
			? array( 'package_change' => $this->prices( $campaign_id, (int) $edits['package_id'] ) )
			: array();
	}

	/**
	 * Whether a package can actually be bought, by creation's own test.
	 *
	 * On sale is not enough: a package with no placements, no price or a
	 * retired placement is refused at creation, and a running campaign moving
	 * onto one would be left with nothing valid to serve.
	 *
	 * @param int $package_id Package post id.
	 * @return true|WP_Error
	 */
	public function check( int $package_id ): bool|WP_Error {
		$snapshot = $this->editor->package_snapshot( $package_id );

		return is_wp_error( $snapshot ) ? $snapshot : true;
	}

	/**
	 * What approving a package change writes to the campaign.
	 *
	 * The snapshot — package, placements, price, currency — and, for a
	 * fixed-length package, the end date it implies from the campaign's start,
	 * as creation derives it. A placement or end-date change proposed in the
	 * same edit wins over what the package would imply: the advertiser chose
	 * those explicitly, and the rules already held them to the package.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $edits       The whole approved change set.
	 * @return array<string, mixed>|WP_Error Fields for `update_draft()`.
	 */
	public function fields( int $campaign_id, array $edits ): array|WP_Error {
		$package_id = (int) ( $edits['package_id'] ?? 0 );
		$snapshot   = $this->editor->package_snapshot( $package_id );

		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}

		if ( array_key_exists( 'placement_ids', $edits ) ) {
			unset( $snapshot['placement_ids'] );
		}

		if ( ! array_key_exists( 'end_ts', $edits ) && ! $this->packages->has_custom_duration( $package_id ) ) {
			$start = array_key_exists( 'start_ts', $edits ) ? (int) $edits['start_ts'] : $this->campaigns->start_ts( $campaign_id );

			if ( $start > 0 ) {
				$snapshot['end_ts'] = Campaign_Rules::fixed_end_ts( $start, $this->packages->duration_days( $package_id ), wp_timezone()->getName() );
			}
		}

		return $snapshot;
	}

	/**
	 * Both prices, as they stand now, for the audit trail billing reads.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $to          The package being moved to.
	 * @return array{from_package: int, from_cents: int, from_currency: string, to_package: int, to_cents: int, to_currency: string}
	 */
	public function prices( int $campaign_id, int $to ): array {
		return array(
			'from_package'  => $this->campaigns->package_id( $campaign_id ),
			// What the campaign was sold at, not the package's price today.
			'from_cents'    => $this->campaigns->budget_cents( $campaign_id ),
			'from_currency' => $this->campaigns->currency( $campaign_id ),
			'to_package'    => $to,
			'to_cents'      => $to > 0 ? $this->packages->price_cents( $to ) : 0,
			'to_currency'   => $to > 0 ? $this->packages->currency( $to ) : '',
		);
	}
}
