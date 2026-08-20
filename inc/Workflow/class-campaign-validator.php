<?php
/**
 * Whether a campaign is fit to submit.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Validation_Result;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use WP_Error;

/**
 * Gathers a campaign's state and applies the domain rules to it.
 *
 * Runs at every advertiser-triggered submission **and again at approval**.
 * Re-running is not redundant: a placement can be deactivated, an
 * organization suspended, or a start date fall into the past while a campaign
 * sits in the queue, and approval is the moment that costs money.
 *
 * Reports every problem it finds rather than the first. An advertiser who
 * fixes one issue, resubmits, and is told about the next one has been made to
 * do the work twice.
 */
final class Campaign_Validator {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Org_Repository       $orgs       Organization persistence.
	 * @param Package_Repository   $packages   Package persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs,
		private readonly Package_Repository $packages
	) {
	}

	/**
	 * Validates a campaign, collecting every problem.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return Validation_Result
	 */
	public function validate( int $campaign_id ): Validation_Result {
		$result = new Validation_Result();

		$this->check_organization( $campaign_id, $result );
		$this->check_package( $campaign_id, $result );
		$this->check_window( $campaign_id, $result );

		$placement_ids = $this->campaigns->placement_ids( $campaign_id );

		$this->check_placements( $placement_ids, $result );
		$this->check_creatives( $campaign_id, $placement_ids, $result );

		return $result;
	}

	/**
	 * The guard callable the state machine consumes.
	 *
	 * @return callable(int, array<string, mixed>): (true|WP_Error)
	 */
	public function as_guard(): callable {
		return function ( int $campaign_id ): bool|WP_Error {
			$result = $this->validate( $campaign_id );

			if ( $result->is_valid() ) {
				return true;
			}

			return $this->to_wp_error( $result );
		};
	}

	/**
	 * The same checks, minus the one that only makes sense before submission.
	 *
	 * A start date is in the future when the advertiser picks it. By the time a
	 * reviewer reaches the campaign it may not be, and that is a fact about the
	 * queue rather than a defect in the campaign — which the advertiser cannot
	 * fix anyway, because a campaign in review is no longer theirs to edit.
	 *
	 * Everything else still applies: a campaign approved after its start date
	 * must still have its creatives, placements, package and price.
	 *
	 * @return callable(int, array<string, mixed>): (true|WP_Error)
	 */
	public function as_approval_guard(): callable {
		return function ( int $campaign_id ): true|WP_Error {
			$result = $this->validate_for_approval( $campaign_id );

			if ( $result->is_valid() ) {
				return true;
			}

			return $this->to_wp_error( $result );
		};
	}

	/**
	 * Validation as a reviewer should see it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return Validation_Result
	 */
	public function validate_for_approval( int $campaign_id ): Validation_Result {
		return self::without( $this->validate( $campaign_id ), Campaign_Rules::ERROR_START_IN_PAST );
	}

	/**
	 * A copy of a result with one problem code removed.
	 *
	 * Filtering the result rather than re-running a different set of checks, so
	 * the two paths cannot drift: a rule added to validate() is enforced at
	 * approval too unless it is named here.
	 *
	 * @param Validation_Result $result Result to copy.
	 * @param string            $code   Problem code to drop.
	 * @return Validation_Result
	 */
	private static function without( Validation_Result $result, string $code ): Validation_Result {
		$kept = new Validation_Result();

		foreach ( $result->problems() as $problem ) {
			if ( $problem['code'] !== $code ) {
				$kept->add( $problem['code'], $problem['field'], $problem['context'] );
			}
		}

		return $kept;
	}

	/**
	 * Turns a result into a WP_Error carrying every problem.
	 *
	 * The codes travel in the error data so a form can highlight the exact
	 * fields; the message is the human summary.
	 *
	 * @param Validation_Result $result The result to convert.
	 * @return WP_Error
	 */
	public function to_wp_error( Validation_Result $result ): WP_Error {
		$messages = array();
		$problems = array();

		foreach ( $result->problems() as $problem ) {
			$messages[] = self::message_for( $problem['code'], $problem['context'] );
			$problems[] = array(
				'code'    => $problem['code'],
				'field'   => $problem['field'],
				'context' => $problem['context'],
			);
		}

		return new WP_Error(
			'aggr_campaign_invalid',
			implode( ' ', $messages ),
			array( 'problems' => $problems )
		);
	}

	/**
	 * The sentence an advertiser reads for a problem code.
	 *
	 * Translation lives here rather than in the domain layer, which calls no
	 * WordPress. It also means the rules are asserted against stable codes
	 * instead of against English a copy edit would break.
	 *
	 * @param string               $code    Problem code.
	 * @param array<string, mixed> $context Problem context.
	 * @return string
	 */
	public static function message_for( string $code, array $context = array() ): string {
		switch ( $code ) {
			case Campaign_Rules::ERROR_NO_CREATIVES:
				return __( 'Add at least one creative before submitting.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_NO_PLACEMENTS:
				return __( 'Choose at least one placement before submitting.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_PLACEMENT_INACTIVE:
				return __( 'One of the placements on this campaign is no longer available.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_PLACEMENT_UNCOVERED:
				return __( 'Every placement needs its own creative.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_CREATIVE_KIND:
				return __( 'Only image creatives can be submitted.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_CREATIVE_PLACEMENT:
				return __( 'A creative is attached to a placement this campaign has not selected.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_CREATIVE_SIZE:
				return sprintf(
					/* translators: 1: uploaded dimensions, e.g. 1200 × 400. 2: required dimensions, e.g. 1200 × 300. */
					__( 'Uploaded: %1$s. Required: %2$s.', 'aggressive-ads' ),
					sprintf( '%d × %d', (int) ( $context['width'] ?? 0 ), (int) ( $context['height'] ?? 0 ) ),
					(string) ( $context['expected'] ?? '' )
				);

			case Campaign_Rules::ERROR_CLICK_URL_MISSING:
				return __( 'Every creative needs a destination URL.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_CLICK_URL_INVALID:
				return __( 'A destination URL is not a valid http or https address.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_START_MISSING:
				return __( 'Choose a start date.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_START_IN_PAST:
				return __( 'The start date has already passed. Choose a later one.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_START_NOT_MIDNIGHT:
				return __( 'The start date must begin at midnight in the site timezone.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_END_BEFORE_START:
				return __( 'The end date must be after the start date.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_END_NOT_DAY_END:
				return __( 'The end date must include the full selected day in the site timezone.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_ORG_NOT_ACTIVE:
				return __( 'This organization cannot submit campaigns. Please get in touch.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_ORG_MISSING:
				return __( 'This campaign is not connected to an organization. Please get in touch.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_PACKAGE_MISSING:
				return __( 'Choose a package before submitting.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_PACKAGE_UNAVAILABLE:
				return __( 'The package on this campaign is no longer offered. Choose another one.', 'aggressive-ads' );

			case Campaign_Rules::ERROR_PRICE_MISSING:
				return __( 'This campaign has no price recorded. Choose its package again.', 'aggressive-ads' );

			default:
				return __( 'This campaign is not ready to submit.', 'aggressive-ads' );
		}
	}

	/**
	 * The organization exists and may transact.
	 *
	 * @param int               $campaign_id Campaign post id.
	 * @param Validation_Result $result      Result to add to.
	 * @return void
	 */
	private function check_organization( int $campaign_id, Validation_Result $result ): void {
		$org_id = $this->campaigns->org_id( $campaign_id );

		if ( $org_id <= 0 ) {
			$result->add( Campaign_Rules::ERROR_ORG_MISSING, 'org_id' );

			return;
		}

		if ( ! $this->orgs->is_active( $org_id ) ) {
			$result->add( Campaign_Rules::ERROR_ORG_NOT_ACTIVE, 'org_id', array( 'org_id' => $org_id ) );
		}
	}

	/**
	 * A package is selected, still offered, and its price was captured.
	 *
	 * The wizard already refuses to save a campaign without one, and that was
	 * not enough: the steps are reachable by URL, so a campaign could be
	 * submitted having skipped step two entirely. It arrived in the review
	 * queue with no package and no price, and nothing downstream noticed —
	 * staff would have approved commercial terms that did not exist.
	 *
	 * The price is checked separately from the package because they are written
	 * separately: the package id is a reference, the price is the snapshot taken
	 * when it was chosen, and a campaign carrying one without the other is the
	 * shape a half-completed write leaves behind.
	 *
	 * @param int               $campaign_id Campaign post id.
	 * @param Validation_Result $result      Result to add to.
	 * @return void
	 */
	private function check_package( int $campaign_id, Validation_Result $result ): void {
		$package_id = $this->campaigns->package_id( $campaign_id );

		if ( $package_id <= 0 ) {
			$result->add( Campaign_Rules::ERROR_PACKAGE_MISSING, 'package_id' );

			return;
		}

		if ( ! $this->packages->is_active( $package_id ) ) {
			$result->add(
				Campaign_Rules::ERROR_PACKAGE_UNAVAILABLE,
				'package_id',
				array( 'package_id' => $package_id )
			);
		}

		if ( $this->campaigns->budget_cents( $campaign_id ) <= 0 || '' === $this->campaigns->currency( $campaign_id ) ) {
			$result->add( Campaign_Rules::ERROR_PRICE_MISSING, 'package_id' );
		}
	}

	/**
	 * The date window is sane.
	 *
	 * @param int               $campaign_id Campaign post id.
	 * @param Validation_Result $result      Result to add to.
	 * @return void
	 */
	private function check_window( int $campaign_id, Validation_Result $result ): void {
		$result->absorb(
			Campaign_Rules::validate_window(
				$this->campaigns->start_ts( $campaign_id ),
				$this->campaigns->end_ts( $campaign_id ),
				time()
			)
		);

		$result->absorb(
			Campaign_Rules::validate_day_boundaries(
				$this->campaigns->start_ts( $campaign_id ),
				$this->campaigns->end_ts( $campaign_id ),
				wp_timezone()->getName()
			)
		);
	}

	/**
	 * At least one placement, and every one still offered.
	 *
	 * @param array<int, int>   $placement_ids Selected placements.
	 * @param Validation_Result $result        Result to add to.
	 * @return void
	 */
	private function check_placements( array $placement_ids, Validation_Result $result ): void {
		if ( array() === $placement_ids ) {
			$result->add( Campaign_Rules::ERROR_NO_PLACEMENTS, 'placement_ids' );

			return;
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( ! $this->placements->is_active( $placement_id ) ) {
				$result->add(
					Campaign_Rules::ERROR_PLACEMENT_INACTIVE,
					'placement_ids',
					array(
						'placement_id' => $placement_id,
						'name'         => $this->placements->name( $placement_id ),
					)
				);
			}
		}
	}

	/**
	 * Every creative is usable, and every placement has one.
	 *
	 * @param int               $campaign_id   Campaign post id.
	 * @param array<int, int>   $placement_ids Selected placements.
	 * @param Validation_Result $result        Result to add to.
	 * @return void
	 */
	private function check_creatives( int $campaign_id, array $placement_ids, Validation_Result $result ): void {
		$creatives = $this->creatives->for_campaign( $campaign_id );

		if ( array() === $creatives ) {
			$result->add( Campaign_Rules::ERROR_NO_CREATIVES, 'creatives' );

			return;
		}

		$covered = array();

		foreach ( $creatives as $creative ) {
			$field = 'creative:' . $creative['id'];

			if ( Campaign_Rules::ADVERTISER_CREATIVE_KIND !== $creative['kind'] ) {
				$result->add( Campaign_Rules::ERROR_CREATIVE_KIND, $field, array( 'kind' => $creative['kind'] ) );
			}

			if ( ! in_array( $creative['placement_id'], $placement_ids, true ) ) {
				$result->add(
					Campaign_Rules::ERROR_CREATIVE_PLACEMENT,
					$field,
					array( 'placement_id' => $creative['placement_id'] )
				);
			} else {
				$covered[] = $creative['placement_id'];

				$expected = $this->placements->size( $creative['placement_id'] );

				if ( '' !== $expected && ! Campaign_Rules::size_matches( $creative['width'], $creative['height'], $expected ) ) {
					$result->add(
						Campaign_Rules::ERROR_CREATIVE_SIZE,
						$field,
						array(
							'width'    => $creative['width'],
							'height'   => $creative['height'],
							'expected' => $expected,
						)
					);
				}
			}

			$this->check_click_url( $creative['click_url'], $field, $result );
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( ! in_array( $placement_id, $covered, true ) ) {
				$result->add(
					Campaign_Rules::ERROR_PLACEMENT_UNCOVERED,
					'placement_ids',
					array(
						'placement_id' => $placement_id,
						'name'         => $this->placements->name( $placement_id ),
					)
				);
			}
		}
	}

	/**
	 * A destination URL is present and acceptable.
	 *
	 * Both checks run: the domain rule enforces the scheme allowlist and
	 * refuses credentials, and wp_http_validate_url() applies whatever the
	 * site itself considers reachable. Neither is a superset of the other.
	 *
	 * @param string            $url    Candidate URL.
	 * @param string            $field  Field name for the form.
	 * @param Validation_Result $result Result to add to.
	 * @return void
	 */
	private function check_click_url( string $url, string $field, Validation_Result $result ): void {
		if ( '' === trim( $url ) ) {
			$result->add( Campaign_Rules::ERROR_CLICK_URL_MISSING, $field );

			return;
		}

		if ( ! Campaign_Rules::is_valid_click_url( $url ) || false === wp_http_validate_url( $url ) ) {
			$result->add( Campaign_Rules::ERROR_CLICK_URL_INVALID, $field, array( 'url' => $url ) );
		}
	}
}
