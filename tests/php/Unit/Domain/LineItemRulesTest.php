<?php
/**
 * Line-item domain rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Line_Item_Rules;
use PHPUnit\Framework\TestCase;

final class LineItemRulesTest extends TestCase {

	public function test_every_campaign_status_maps_to_a_line_item_status(): void {
		$expected = array(
			Post_Statuses::DRAFT     => 'draft',
			Post_Statuses::SUBMITTED => 'draft',
			Post_Statuses::REVIEW    => 'draft',
			Post_Statuses::CHANGES   => 'draft',
			Post_Statuses::REJECTED  => 'draft',
			Post_Statuses::APPROVED  => 'ready',
			Post_Statuses::SCHEDULED => 'scheduled',
			Post_Statuses::LIVE      => 'live',
			Post_Statuses::PAUSED    => 'paused',
			Post_Statuses::COMPLETE  => 'completed',
			Post_Statuses::CANCELLED => 'cancelled',
		);

		$this->assertSame( Post_Statuses::all(), array_keys( $expected ) );
		foreach ( $expected as $campaign => $line_item ) {
			$this->assertSame( $line_item, Line_Item_Rules::status_for_campaign( $campaign ) );
			$this->assertContains( $line_item, Line_Item_Rules::statuses() );
		}
	}

	public function test_every_line_item_status_pair_has_an_explicit_answer(): void {
		$legal   = array(
			'draft->ready',
			'draft->cancelled',
			'ready->draft',
			'ready->scheduled',
			'ready->live',
			'ready->cancelled',
			'scheduled->live',
			'scheduled->paused',
			'scheduled->cancelled',
			'live->paused',
			'live->completed',
			'live->cancelled',
			'paused->live',
			'paused->completed',
			'paused->cancelled',
		);
		$checked = 0;

		foreach ( Line_Item_Rules::statuses() as $from ) {
			foreach ( Line_Item_Rules::statuses() as $to ) {
				++$checked;
				$this->assertSame( in_array( "{$from}->{$to}", $legal, true ), Line_Item_Rules::can_transition( $from, $to ), "{$from}->{$to}" );
			}
		}

		$this->assertSame( 49, $checked );
	}

	public function test_delivery_vocabularies_are_closed_and_unique(): void {
		$this->assertSame( Line_Item_Rules::PRICING_MODELS, array_values( array_unique( Line_Item_Rules::PRICING_MODELS ) ) );
		$this->assertSame( Line_Item_Rules::GOAL_TYPES, array_values( array_unique( Line_Item_Rules::GOAL_TYPES ) ) );
		$this->assertSame( Line_Item_Rules::PACING_MODES, array_values( array_unique( Line_Item_Rules::PACING_MODES ) ) );
		$this->assertContains( 'share_of_voice', Line_Item_Rules::PRICING_MODELS );
	}
}
