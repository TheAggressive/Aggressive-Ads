<?php
/**
 * Campaign-to-default-line-item lifecycle projection.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Line_Item_Repository;

/** Keeps the P1 compatibility line item aligned after official transitions. */
final class Line_Item_Lifecycle implements Service {

	/**
	 * Builds the lifecycle projection.
	 *
	 * @param Line_Item_Repository $line_items Line-item persistence.
	 */
	public function __construct( private readonly Line_Item_Repository $line_items ) {
	}

	/** Attaches the campaign transition listener. */
	public function init(): void {
		add_action( 'aggr_campaign_transitioned', array( $this, 'sync' ), 5, 1 );
		add_action( 'before_delete_post', array( $this, 'delete_campaign' ), 10, 2 );
	}

	/**
	 * Synchronizes after a campaign transition.
	 *
	 * @param int $campaign_id Transitioned campaign id.
	 */
	public function sync( int $campaign_id ): void {
		$this->line_items->sync_default_from_campaign( $campaign_id, false );
	}

	/**
	 * Removes child rows with their parent campaign.
	 *
	 * @param int      $post_id Deleted post id.
	 * @param \WP_Post $post    Deleted post.
	 */
	public function delete_campaign( int $post_id, \WP_Post $post ): void {
		if ( Post_Types::CAMPAIGN === $post->post_type ) {
			$this->line_items->delete_for_campaign( $post_id );
		}
	}
}
