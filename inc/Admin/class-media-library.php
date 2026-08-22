<?php
/**
 * Keeps delivered advertising out of the Media Library.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Repository\Creative_Repository;
use WP_Query;

/**
 * Hides creative attachments from the library, without moving the files.
 *
 * An approved creative is promoted into a real attachment so it is delivered as
 * a static file — cacheable by the browser and any CDN, with no PHP on the
 * impression path. That is the right trade for the highest-volume request the
 * plugin serves, but it means a site running hundreds of campaigns ends up with
 * a Media Library that is mostly advertising.
 *
 * These filters change what the library *lists*, never where a file lives or
 * how it is served. A creative attachment stays a normal attachment: it renders
 * in the review screen, in the portal, and in a live ad exactly as before.
 */
final class Media_Library {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_modal_query' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_list_query' ) );
	}

	/**
	 * The grid and the media modal.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	public function filter_modal_query( array $args ): array {
		return $this->exclude( $args );
	}

	/**
	 * The list-table view at upload.php.
	 *
	 * Restricted to the admin attachment query so a front-end template asking
	 * for attachments is not quietly filtered underneath it.
	 *
	 * @param WP_Query $query The query about to run.
	 * @return void
	 */
	public function filter_list_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( null === $screen || 'upload' !== $screen->id ) {
			return;
		}

		$meta_query = $query->get( 'meta_query' );
		$query->set( 'meta_query', $this->deny_clause( is_array( $meta_query ) ? $meta_query : array() ) );
	}

	/**
	 * Adds the exclusion to a query argument array.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 * @return array<string, mixed>
	 */
	private function exclude( array $args ): array {
		$existing = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Filtering the Media Library's own attachment query is only expressible as a meta_query, and NOT EXISTS on postmeta.meta_key is index-backed.
		$args['meta_query'] = $this->deny_clause( $existing );

		return $args;
	}

	/**
	 * Wraps any existing clauses in an AND with the creative exclusion.
	 *
	 * Existing clauses are preserved rather than replaced. Another plugin
	 * filtering the same query first is not a reason to drop its conditions,
	 * and a library that silently ignores someone else's filter is a bug
	 * report nobody can reproduce.
	 *
	 * @param array<mixed> $existing Clauses already on the query.
	 * @return array<mixed>
	 */
	private function deny_clause( array $existing ): array {
		$deny = array(
			'key'     => Creative_Repository::META_IS_CREATIVE,
			'compare' => 'NOT EXISTS',
		);

		if ( array() === $existing ) {
			return array( $deny );
		}

		return array(
			'relation' => 'AND',
			$existing,
			array( $deny ),
		);
	}
}
