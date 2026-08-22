<?php
/**
 * A campaign cannot reach review without a name the advertiser chose.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Workflow\Campaign_Validator;
use WP_Error;
use WP_UnitTestCase;

/**
 * The placeholder a draft is created with is not a name.
 *
 * A draft with no name is given "Untitled campaign" so lists have something to
 * render, which means "not empty" is not the question — whether the advertiser
 * ever chose it is.
 */
final class CampaignTitleRequiredTest extends WP_UnitTestCase {

	/**
	 * A draft with the given title and placeholder marker.
	 *
	 * @param string $title       Stored post title.
	 * @param bool   $placeholder Whether that title was generated.
	 * @return int
	 */
	private function draft( string $title, bool $placeholder ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_title'  => $title,
			)
		);

		Plugin::instance()->container()->get( Campaign_Repository::class )
			->set_title_is_placeholder( $id, $placeholder );

		return $id;
	}

	/**
	 * The codes a validation run reported.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, string>
	 */
	private function codes( int $campaign_id ): array {
		$result = Plugin::instance()->container()->get( Campaign_Validator::class )->validate( $campaign_id );

		return array_map(
			static fn ( array $problem ): string => $problem['code'],
			$result->problems()
		);
	}

	/**
	 * The generated placeholder is refused even though it is not empty.
	 *
	 * @return void
	 */
	public function test_a_placeholder_title_is_refused(): void {
		$campaign = $this->draft( 'Untitled campaign', true );

		$this->assertContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->codes( $campaign ) );
	}

	/**
	 * An empty title is refused.
	 *
	 * @return void
	 */
	public function test_an_empty_title_is_refused(): void {
		$campaign = $this->draft( '', false );

		$this->assertContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->codes( $campaign ) );
	}

	/**
	 * Whitespace is not a name.
	 *
	 * @return void
	 */
	public function test_a_whitespace_title_is_refused(): void {
		$campaign = $this->draft( '   ', false );

		$this->assertContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->codes( $campaign ) );
	}

	/**
	 * A name the advertiser chose passes, even if it reads like the default.
	 *
	 * The marker is what decides, so an advertiser who genuinely types
	 * "Untitled campaign" is not second-guessed.
	 *
	 * @return void
	 */
	public function test_a_chosen_title_passes_even_when_it_matches_the_placeholder(): void {
		$campaign = $this->draft( 'Untitled campaign', false );

		$this->assertNotContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->codes( $campaign ) );
	}

	/**
	 * An ordinary name passes.
	 *
	 * @return void
	 */
	public function test_a_real_title_passes(): void {
		$campaign = $this->draft( 'Spring leaderboard', false );

		$this->assertNotContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->codes( $campaign ) );
	}

	/**
	 * The guard the submit transition runs refuses it too.
	 *
	 * Asserted through the guard rather than only through validate(), because
	 * a rule the validator reports and the transition never consults would
	 * block nothing.
	 *
	 * @return void
	 */
	public function test_the_submit_guard_refuses_a_placeholder_title(): void {
		$campaign = $this->draft( 'Untitled campaign', true );

		$guard  = Plugin::instance()->container()->get( Campaign_Validator::class )->as_guard();
		$result = $guard( $campaign, array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_campaign_invalid', $result->get_error_code() );

		$data  = (array) $result->get_error_data();
		$codes = array_map(
			static fn ( array $problem ): string => $problem['code'],
			(array) ( $data['problems'] ?? array() )
		);

		$this->assertContains( Campaign_Rules::ERROR_TITLE_MISSING, $codes );
	}
}
