<?php
/**
 * Campaign editor integration fixtures.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Repository;

/**
 * Focused fixture construction shared by campaign editor scenarios.
 */
trait CampaignEditorFixtures {

	/**
	 * Completes stored campaign data used by review and submission.
	 *
	 * @param string $title Campaign title.
	 * @return int
	 */
	private function complete_campaign( string $title ): int {
		$campaign_id = $this->editor->create( $title );
		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );
		$this->add_creative( $campaign_id );

		$start = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
		$this->assertSame( 2, $this->actions->process_save_schedule( $campaign_id, $start, '', 1 ) );

		return $campaign_id;
	}

	/**
	 * Adds a valid creative for the configured package placement.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	private function add_creative( int $campaign_id ): int {
		$creative_id = Plugin::instance()->container()->get( Creative_Repository::class )->create(
			$campaign_id,
			$this->org_id,
			$this->placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/exhibition',
				'alt_text'  => 'Visitors viewing an exhibition',
				'size'      => '728x90',
			)
		);

		update_post_meta( $creative_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $creative_id, Creative_Repository::META_HEIGHT, 90 );

		return $creative_id;
	}
}
