<?php
/**
 * What stands between a campaign under review and its approval.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Workflow\Campaign_Validator;

/**
 * The review screen's readiness list, from the guard that decides approval.
 *
 * A reviewer used to learn why a campaign could not be approved by trying:
 * the button was offered, the transition was refused, and the reason arrived
 * as an error after the click. The facts were on the page — "No creative
 * uploaded" in a grey box that looked like every other empty panel — but
 * nothing said they were the reason.
 *
 * **This runs the guard's own check, not a copy.** `validate_for_approval()`
 * is what `Transition_Table::GUARD_APPROVABLE` calls, so the list cannot say
 * "ready" while the transition refuses, or the reverse. Grouping the problems
 * is the domain's (`Campaign_Rules::check_group()`), and the sentences are the
 * validator's own, so the advertiser and the reviewer read the same words.
 *
 * Presentation only: it decides nothing, and the guard still runs on the
 * transition whatever this said.
 */
final class Approval_Readiness {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Validator $validator The validator the approval guard uses.
	 */
	public function __construct( private readonly Campaign_Validator $validator ) {
	}

	/**
	 * Whether the list applies to a campaign in this status.
	 *
	 * Only while a decision is waiting. Before submission the advertiser is
	 * still building it; after approval the question is a different one.
	 *
	 * @param string $status Campaign status.
	 */
	public static function applies_to( string $status ): bool {
		return in_array( $status, array( Post_Statuses::SUBMITTED, Post_Statuses::REVIEW ), true );
	}

	/**
	 * Each check, ticked or with the problems that block it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array{ready: bool, checks: list<array{key: string, label: string, ok: bool, problems: list<string>}>}
	 */
	public function for_campaign( int $campaign_id ): array {
		$problems = array_fill_keys( Campaign_Rules::CHECK_GROUPS, array() );

		foreach ( $this->validator->validate_for_approval( $campaign_id )->problems() as $problem ) {
			$group   = Campaign_Rules::check_group( $problem['code'] );
			$message = Campaign_Validator::message_for( $problem['code'], $problem['context'] );

			// One sentence once: two creatives missing a link is one thing to fix.
			if ( ! in_array( $message, $problems[ $group ], true ) ) {
				$problems[ $group ][] = $message;
			}
		}

		$labels = self::labels();
		$checks = array();

		foreach ( Campaign_Rules::CHECK_GROUPS as $key ) {
			$checks[] = array(
				'key'      => $key,
				'label'    => $labels[ $key ],
				'ok'       => array() === $problems[ $key ],
				'problems' => $problems[ $key ],
			);
		}

		return array(
			'ready'  => array() === array_merge( ...array_values( $problems ) ),
			'checks' => $checks,
		);
	}

	/**
	 * What each check is called on screen.
	 *
	 * @return array<string, string>
	 */
	private static function labels(): array {
		return array(
			'details'    => __( 'Campaign details', 'aggressive-ads' ),
			'advertiser' => __( 'Advertiser account', 'aggressive-ads' ),
			'package'    => __( 'Package and price', 'aggressive-ads' ),
			'schedule'   => __( 'Schedule', 'aggressive-ads' ),
			'placements' => __( 'Placements', 'aggressive-ads' ),
			'artwork'    => __( 'Artwork and links', 'aggressive-ads' ),
		);
	}
}
