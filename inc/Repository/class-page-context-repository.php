<?php
/**
 * What a page is about, as targeting facts.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

/**
 * Reads the taxonomy terms and post type of the page a slot is being filled on.
 *
 * This is the missing half of contextual selling. `Targeting_Rules` has always
 * been able to evaluate a fact set — it matches an array-valued fact with
 * `contains` and needed no change — but delivery only ever supplied
 * `visitor_id` and `size`, so there was nothing about the *page* to sell
 * against. A publisher could write a rule naming a category and it would match
 * nobody, silently, because the dimension was never populated.
 *
 * Public taxonomies only. A private taxonomy is the site's own bookkeeping —
 * `aggr_placement_group` among them — and exposing it as a targeting dimension
 * would let an advertiser's rule read a publisher's internal filing.
 */
final class Page_Context_Repository {

	/**
	 * Upper bound on term facts for one page.
	 *
	 * A post with hundreds of tags would otherwise put hundreds of strings into
	 * every fill on that page, and every targeting comparison walks them.
	 */
	public const MAX_TERMS = 50;

	/**
	 * The facts describing one page.
	 *
	 * Returns an empty set for anything that is not a readable post, which is
	 * the honest answer for a fill that reported no page — a request from a
	 * cached page rendered before this existed, or a forged id pointing at
	 * nothing. An archive has no post row and is answered by
	 * {@see self::facts_for_term()} instead.
	 *
	 * @param int $post_id Post the slot is being filled on.
	 * @return array<string, mixed>
	 */
	public function facts_for( int $post_id ): array {
		if ( $post_id <= 0 ) {
			return array();
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return array();
		}

		$facts = array( 'post_type' => (string) $post->post_type );

		$categories = array();
		$qualified  = array();

		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy->name );

			if ( ! is_array( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				/*
				 * Qualified, because a slug is only unique within its taxonomy.
				 * A category "sports" and a tag "sports" are different
				 * inventory, and a rule that cannot tell them apart sells one
				 * as the other.
				 */
				$qualified[] = $taxonomy->name . ':' . $term->slug;

				if ( 'category' === $taxonomy->name ) {
					$categories[] = $term->slug;
				}
			}
		}

		sort( $categories );
		sort( $qualified );

		$facts['categories'] = array_slice( array_values( array_unique( $categories ) ), 0, self::MAX_TERMS );
		$facts['terms']      = array_slice( array_values( array_unique( $qualified ) ), 0, self::MAX_TERMS );

		return $facts;
	}

	/**
	 * The facts describing one term archive.
	 *
	 * A category archive is inventory a publisher sells, and it was the one
	 * place contextual targeting silently did not reach: the fill reported no
	 * page, `facts_for()` returned nothing, and a campaign bought against that
	 * category simply did not serve there. No error, no exclusion reason — the
	 * same shape as ordinary no-fill, which is why it went unnoticed.
	 *
	 * **No `post_type` fact, deliberately.** An archive is not a post, and
	 * inventing one would make `post_type contains post` match a page that has
	 * no post type. Absent resolves to null, which fails a `contains` and
	 * passes a `not contains` — the honest answers for a dimension that does
	 * not apply here.
	 *
	 * Public taxonomies only, for the same reason `facts_for()` filters them:
	 * a private taxonomy is the publisher's own bookkeeping. A term in one is
	 * answered as no context rather than as an error, because a forged id and
	 * an unlisted taxonomy should be indistinguishable from outside.
	 *
	 * @param int $term_id Term whose archive the slot is being filled on.
	 * @return array<string, mixed>
	 */
	public function facts_for_term( int $term_id ): array {
		if ( $term_id <= 0 ) {
			return array();
		}

		$term = get_term( $term_id );

		if ( ! $term instanceof \WP_Term ) {
			return array();
		}

		$taxonomy = get_taxonomy( $term->taxonomy );

		if ( ! $taxonomy instanceof \WP_Taxonomy || ! $taxonomy->public ) {
			return array();
		}

		$facts = array(
			'terms'      => array( $term->taxonomy . ':' . $term->slug ),
			'categories' => 'category' === $term->taxonomy ? array( $term->slug ) : array(),
		);

		return $facts;
	}
}
