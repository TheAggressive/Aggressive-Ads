<?php
/**
 * What an advertiser may see of the audit trail.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Advertiser_Events;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The hidden half is the half worth testing.
 *
 * The trail is written for the publisher: it records refusals, internal notes,
 * mail failures and who did what. An advertiser reading their own campaign
 * must see what happened to the campaign and nothing about how the publisher
 * runs it, so every assertion below that expects '' is a disclosure that does
 * not happen.
 */
final class AdvertiserEventsTest extends TestCase {

	/**
	 * Events an advertiser must never be shown.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function hidden(): array {
		return array(
			'internal notes'                => array( 'campaign.internal_notes_updated', Audit_Event::OUTCOME_OK ),
			'refused transition'            => array( 'campaign.transition_denied', Audit_Event::OUTCOME_DENIED ),
			'mail failure'                  => array( 'campaign.notification_failed', Audit_Event::OUTCOME_FAILED ),
			'queued mail'                   => array( 'campaign.notification_queued', Audit_Event::OUTCOME_OK ),
			'retry schedule'                => array( 'campaign.notification_retry_schedule_failed', Audit_Event::OUTCOME_FAILED ),
			'outside the workflow'          => array( 'campaign.status_changed_outside_workflow', Audit_Event::OUTCOME_OK ),
			'purged files'                  => array( 'campaign.private_files_purged', Audit_Event::OUTCOME_OK ),
			'copy refused'                  => array( 'campaign.copy_denied', Audit_Event::OUTCOME_DENIED ),
			'copy failed'                   => array( 'campaign.copy_failed', Audit_Event::OUTCOME_FAILED ),
			'upload failed'                 => array( 'creative.upload_failed', Audit_Event::OUTCOME_FAILED ),
			'replace failed'                => array( 'creative.artwork_replace_failed', Audit_Event::OUTCOME_FAILED ),
			'published while live'          => array( 'creative.published_on_running_campaign', Audit_Event::OUTCOME_OK ),
			'weight changed'                => array( 'creative.weight_changed', Audit_Event::OUTCOME_OK ),
			'oversold inventory'            => array( 'reservation.oversold', Audit_Event::OUTCOME_OK ),
			'denied reservation'            => array( 'reservation.denied', Audit_Event::OUTCOME_DENIED ),
			'another org invited'           => array( 'organization.invited', Audit_Event::OUTCOME_OK ),
			'a new event nobody considered' => array( 'campaign.something_new', Audit_Event::OUTCOME_OK ),
		);
	}

	#[DataProvider( 'hidden' )]
	public function test_it_shows_nothing_for_an_event_that_is_not_the_advertisers( string $event, string $outcome ): void {
		$this->assertSame( '', Advertiser_Events::code( $event, $outcome ), $event );
	}

	public function test_a_refused_transition_is_silent_even_when_the_event_is_allowed(): void {
		$this->assertSame(
			'',
			Advertiser_Events::code( 'campaign.transitioned', Audit_Event::OUTCOME_DENIED, Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ),
			'A refusal was read as the campaign reaching review.'
		);
	}

	/**
	 * Arrivals and what each one means to the advertiser.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public static function arrivals(): array {
		return array(
			'submitted' => array( Post_Statuses::DRAFT, Post_Statuses::SUBMITTED, Advertiser_Events::SUBMITTED ),
			'in review' => array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW, Advertiser_Events::IN_REVIEW ),
			'approved'  => array( Post_Statuses::REVIEW, Post_Statuses::APPROVED, Advertiser_Events::APPROVED ),
			'scheduled' => array( Post_Statuses::APPROVED, Post_Statuses::SCHEDULED, Advertiser_Events::SCHEDULED ),
			'live'      => array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE, Advertiser_Events::LIVE ),
			'paused'    => array( Post_Statuses::LIVE, Post_Statuses::PAUSED, Advertiser_Events::PAUSED ),
			'resumed'   => array( Post_Statuses::PAUSED, Post_Statuses::LIVE, Advertiser_Events::RESUMED ),
			'complete'  => array( Post_Statuses::LIVE, Post_Statuses::COMPLETE, Advertiser_Events::COMPLETE ),
			'cancelled' => array( Post_Statuses::APPROVED, Post_Statuses::CANCELLED, Advertiser_Events::CANCELLED ),
			'rejected'  => array( Post_Statuses::REVIEW, Post_Statuses::REJECTED, Advertiser_Events::REJECTED ),
		);
	}

	#[DataProvider( 'arrivals' )]
	public function test_it_names_an_arrival_the_way_the_advertiser_would( string $from, string $to, string $code ): void {
		$this->assertSame( $code, Advertiser_Events::code( 'campaign.transitioned', Audit_Event::OUTCOME_OK, $from, $to ) );
	}

	public function test_returning_to_draft_says_which_of_the_two_things_happened(): void {
		// The same arrival, two meanings, and telling them apart matters: one
		// is the review team asking for something, the other is the
		// advertiser's own doing.
		$this->assertSame(
			Advertiser_Events::CHANGES,
			Advertiser_Events::code( 'campaign.transitioned', Audit_Event::OUTCOME_OK, Post_Statuses::REVIEW, Post_Statuses::DRAFT )
		);
		$this->assertSame(
			Advertiser_Events::WITHDRAWN,
			Advertiser_Events::code( 'campaign.transitioned', Audit_Event::OUTCOME_OK, Post_Statuses::SUBMITTED, Post_Statuses::DRAFT )
		);
	}

	public function test_it_shows_what_happened_to_the_ads(): void {
		$this->assertSame( Advertiser_Events::AD_UPLOADED, Advertiser_Events::code( 'creative.uploaded', Audit_Event::OUTCOME_OK ) );
		$this->assertSame( Advertiser_Events::AD_REPLACED, Advertiser_Events::code( 'creative.artwork_replaced', Audit_Event::OUTCOME_OK ) );
		$this->assertSame( Advertiser_Events::AD_REMOVED, Advertiser_Events::code( 'creative.removed', Audit_Event::OUTCOME_OK ) );
		$this->assertSame( Advertiser_Events::LINK_CHANGED, Advertiser_Events::code( 'creative.destination_changed', Audit_Event::OUTCOME_OK ) );
	}

	public function test_only_the_stages_the_timeline_draws_are_dated(): void {
		$this->assertSame( Post_Statuses::APPROVED, Advertiser_Events::stage( Post_Statuses::APPROVED ) );
		$this->assertSame( Post_Statuses::COMPLETE, Advertiser_Events::stage( Post_Statuses::COMPLETE ) );

		// A pause is on the line the campaign walks, not a stage of it.
		$this->assertSame( '', Advertiser_Events::stage( Post_Statuses::PAUSED ) );
		$this->assertSame( '', Advertiser_Events::stage( Post_Statuses::DRAFT ) );
		$this->assertSame( '', Advertiser_Events::stage( Post_Statuses::CANCELLED ) );
	}
}
