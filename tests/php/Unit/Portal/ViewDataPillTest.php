<?php
/**
 * The status-to-pill mapping.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Portal;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Portal\View_Data;
use PHPUnit\Framework\TestCase;

/**
 * Covers View_Data::pill_for().
 *
 * A pure function over the status vocabulary, so it is asserted exhaustively
 * rather than sampled: every registered status is named here, and adding a
 * twelfth status fails this file until somebody decides what colour it is.
 * Sampling would let a new status fall through to the default and render grey
 * in production without anyone choosing that.
 */
final class ViewDataPillTest extends TestCase {

	/**
	 * The colour every status is meant to render as.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function status_provider(): array {
		return array(
			'draft is neutral'             => array( Post_Statuses::DRAFT, 'neutral' ),
			'submitted is pending'         => array( Post_Statuses::SUBMITTED, 'pending' ),
			'in review is pending'         => array( Post_Statuses::REVIEW, 'pending' ),
			'changes requested is pending' => array( Post_Statuses::CHANGES, 'pending' ),
			'rejected is danger'           => array( Post_Statuses::REJECTED, 'danger' ),
			'approved is live'             => array( Post_Statuses::APPROVED, 'live' ),
			'scheduled is live'            => array( Post_Statuses::SCHEDULED, 'live' ),
			'live is live'                 => array( Post_Statuses::LIVE, 'live' ),
			'paused is pending, not live'  => array( Post_Statuses::PAUSED, 'pending' ),
			'complete is ended'            => array( Post_Statuses::COMPLETE, 'ended' ),
			'cancelled is danger'          => array( Post_Statuses::CANCELLED, 'danger' ),
			'an unknown status is neutral' => array( 'lap_invented', 'neutral' ),
			'a core status is neutral'     => array( 'publish', 'neutral' ),
			'the empty string is neutral'  => array( '', 'neutral' ),
		);
	}

	/**
	 * Each status maps to the colour it was assigned.
	 *
	 * @dataProvider status_provider
	 *
	 * @param string $status   Status slug.
	 * @param string $expected Expected pill modifier.
	 * @return void
	 */
	public function test_status_maps_to_its_pill( string $status, string $expected ): void {
		$this->assertSame( $expected, View_Data::pill_for( $status ) );
	}

	/**
	 * Paused is the case the "published means live" shortcut gets wrong.
	 *
	 * A paused campaign is published — AdSanity still holds its ad — so any
	 * mapping written as "published statuses are green" shows it as running.
	 * The advertiser then sees green for a campaign that is serving nothing.
	 *
	 * @return void
	 */
	public function test_paused_is_published_but_does_not_read_as_running(): void {
		$this->assertContains( Post_Statuses::PAUSED, Post_Statuses::published() );
		$this->assertNotSame( 'live', View_Data::pill_for( Post_Statuses::PAUSED ) );
	}

	/**
	 * Every registered status has a colour decided for it here.
	 *
	 * @return void
	 */
	public function test_every_registered_status_is_covered(): void {
		$named = array();

		foreach ( self::status_provider() as $case ) {
			$named[] = $case[0];
		}

		foreach ( Post_Statuses::all() as $status ) {
			$this->assertContains(
				$status,
				$named,
				"Status {$status} has no expected pill colour. Decide one and add it."
			);
		}
	}

	/**
	 * Only the modifiers the stylesheet defines are ever returned.
	 *
	 * A modifier PHP emits and CSS does not define renders as an unstyled grey
	 * box, which looks like a bug in the data rather than a gap in the styles.
	 *
	 * @return void
	 */
	public function test_only_defined_modifiers_are_emitted(): void {
		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'assets/portal.css' );

		$this->assertIsString( $css, 'assets/portal.css must be readable.' );

		foreach ( array_merge( Post_Statuses::all(), array( 'lap_invented', '' ) ) as $status ) {
			$modifier = View_Data::pill_for( $status );

			$this->assertStringContainsString(
				'.laao-ads-pill--' . $modifier . ' {',
				$css,
				"assets/portal.css defines no rule for .laao-ads-pill--{$modifier}."
			);
		}
	}
}
