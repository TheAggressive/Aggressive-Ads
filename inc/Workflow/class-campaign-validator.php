<?php
/**
 * Whether a campaign is fit to submit.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Domain\Campaign_Rules;
use LAAO_Advertiser_Portal\Domain\Validation_Result;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
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
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Org_Repository $orgs
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
			'laao_ads_campaign_invalid',
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
				return __( 'Add at least one creative before submitting.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_NO_PLACEMENTS:
				return __( 'Choose at least one placement before submitting.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_PLACEMENT_INACTIVE:
				return __( 'One of the placements on this campaign is no longer available.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_PLACEMENT_UNCOVERED:
				return __( 'Every placement needs its own creative.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_CREATIVE_KIND:
				return __( 'Only image creatives can be submitted.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_CREATIVE_PLACEMENT:
				return __( 'A creative is attached to a placement this campaign has not selected.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_CREATIVE_SIZE:
				return sprintf(
					/* translators: 1: uploaded dimensions, e.g. 1200 × 400. 2: required dimensions, e.g. 1200 × 300. */
					__( 'Uploaded: %1$s. Required: %2$s.', 'laao-advertiser-portal' ),
					sprintf( '%d × %d', (int) ( $context['width'] ?? 0 ), (int) ( $context['height'] ?? 0 ) ),
					(string) ( $context['expected'] ?? '' )
				);

			case Campaign_Rules::ERROR_CLICK_URL_MISSING:
				return __( 'Every creative needs a destination URL.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_CLICK_URL_INVALID:
				return __( 'A destination URL is not a valid http or https address.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_START_MISSING:
				return __( 'Choose a start date.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_START_IN_PAST:
				return __( 'The start date has already passed. Choose a later one.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_END_BEFORE_START:
				return __( 'The end date must be after the start date.', 'laao-advertiser-portal' );

			case Campaign_Rules::ERROR_ORG_NOT_ACTIVE:
				return __( 'This organization cannot submit campaigns. Please get in touch.', 'laao-advertiser-portal' );

			default:
				return __( 'This campaign is not ready to submit.', 'laao-advertiser-portal' );
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
