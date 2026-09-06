<?php
/**
 * The page a slot is on, as facts a targeting rule can sell against.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Taxonomies;
use Aggressive\Ads\Repository\Page_Context_Repository;
use WP_UnitTestCase;

/**
 * `Targeting_Rules` could always evaluate a fact set — it matches an
 * array-valued fact with `contains` and needed no change for this. What was
 * missing was anything about the page: delivery supplied only `visitor_id` and
 * `size`, so a publisher's rule naming a category matched nobody, silently,
 * because the dimension was never populated.
 *
 * These tests are about what goes into that fact set, and — more importantly —
 * what must stay out of it.
 */
final class PageContextFactsTest extends WP_UnitTestCase {

	/**
	 * The repository under test.
	 *
	 * @var Page_Context_Repository
	 */
	private Page_Context_Repository $context;

	public function set_up(): void {
		parent::set_up();

		$this->context = new Page_Context_Repository();
	}

	/**
	 * A published post in one category and one tag.
	 *
	 * @return int
	 */
	private function post_in_category(): int {
		$post_id = (int) self::factory()->post->create(
			array( 'post_status' => 'publish' )
		);

		wp_set_object_terms( $post_id, array( 'sports' ), 'category', false );
		wp_set_object_terms( $post_id, array( 'playoffs' ), 'post_tag', false );

		return $post_id;
	}

	public function test_a_post_reports_its_categories(): void {
		$facts = $this->context->facts_for( $this->post_in_category() );

		$this->assertSame( array( 'sports' ), $facts['categories'] );
		$this->assertSame( 'post', $facts['post_type'] );
	}

	/**
	 * Terms are qualified by taxonomy, because a slug is not unique without it.
	 *
	 * A category "sports" and a tag "sports" are different inventory. A rule
	 * that cannot tell them apart sells one as the other, and neither side ever
	 * sees a discrepancy.
	 */
	public function test_terms_are_qualified_by_taxonomy(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		wp_set_object_terms( $post_id, array( 'sports' ), 'category', false );
		wp_set_object_terms( $post_id, array( 'sports' ), 'post_tag', false );

		$facts = $this->context->facts_for( $post_id );

		$this->assertSame(
			array( 'category:sports', 'post_tag:sports' ),
			$facts['terms']
		);

		// The bare list stays category-only, so it cannot be confused with the
		// qualified one.
		$this->assertSame( array( 'sports' ), $facts['categories'] );
	}

	/**
	 * **A private taxonomy is never a targeting dimension.**
	 *
	 * `aggr_placement_group` is the publisher's own filing. Exposing it here
	 * would let an advertiser's rule read how a publisher organises their
	 * inventory, which is not something they are shown anywhere else.
	 */
	public function test_a_private_taxonomy_is_not_exposed(): void {
		$post_id = (int) self::factory()->post->create( array( 'post_status' => 'publish' ) );

		register_taxonomy( 'aggr_secret', 'post', array( 'public' => false ) );
		wp_set_object_terms( $post_id, array( 'internal' ), 'aggr_secret', false );
		wp_set_object_terms( $post_id, array( 'sports' ), 'category', false );

		$facts = $this->context->facts_for( $post_id );

		$this->assertNotContains( 'aggr_secret:internal', $facts['terms'] );

		/*
		 * The public term must be present in the same result. Asserting only
		 * the absence would pass just as well over a repository that returned
		 * nothing at all, which is the shape of test this codebase keeps
		 * catching after the fact.
		 */
		$this->assertContains( 'category:sports', $facts['terms'] );

		unregister_taxonomy( 'aggr_secret' );
	}

	/** The placement group taxonomy specifically is private, and stays out. */
	public function test_placement_groups_are_not_a_targeting_dimension(): void {
		$this->assertFalse( get_taxonomy( Taxonomies::PLACEMENT_GROUP )->public );
	}

	/**
	 * No page reported means no page facts, rather than a guess.
	 *
	 * This is the state every fill from a page cached before this existed is
	 * in, so it has to be the same answer delivery has always given.
	 */
	public function test_no_page_reports_no_facts(): void {
		$this->assertSame( array(), $this->context->facts_for( 0 ) );
		$this->assertSame( array(), $this->context->facts_for( -5 ) );
		$this->assertSame( array(), $this->context->facts_for( 999999 ) );
	}

	/**
	 * An unpublished post reports nothing.
	 *
	 * The id travels in a URL, so a caller can name any post. Reading a draft's
	 * categories would leak what an editor is working on to anyone who guessed
	 * an id.
	 */
	public function test_an_unpublished_post_reports_no_facts(): void {
		$draft = (int) self::factory()->post->create(
			array( 'post_status' => 'draft' )
		);

		wp_set_object_terms( $draft, array( 'unannounced' ), 'category', false );

		$this->assertSame( array(), $this->context->facts_for( $draft ) );
	}

	/** A post with no terms still reports its type, and empty lists. */
	public function test_a_post_with_no_terms_reports_empty_lists(): void {
		$post_id = (int) self::factory()->post->create(
			array( 'post_status' => 'publish' )
		);

		wp_set_object_terms( $post_id, array(), 'category', false );

		$facts = $this->context->facts_for( $post_id );

		$this->assertSame( 'post', $facts['post_type'] );
		$this->assertSame( array(), $facts['categories'] );
		$this->assertSame( array(), $facts['terms'] );
	}

	/** The term list is bounded, so one over-tagged post cannot slow every fill. */
	public function test_the_term_list_is_bounded(): void {
		$post_id = (int) self::factory()->post->create(
			array( 'post_status' => 'publish' )
		);

		$tags = array();

		for ( $i = 0; $i < Page_Context_Repository::MAX_TERMS + 20; $i++ ) {
			$tags[] = sprintf( 'tag-%03d', $i );
		}

		wp_set_object_terms( $post_id, $tags, 'post_tag', false );

		$this->assertCount(
			Page_Context_Repository::MAX_TERMS,
			$this->context->facts_for( $post_id )['terms']
		);
	}

	/** Facts are stable: the same page resolves the same way twice. */
	public function test_facts_are_deterministic(): void {
		$post_id = $this->post_in_category();

		$this->assertSame(
			$this->context->facts_for( $post_id ),
			$this->context->facts_for( $post_id )
		);
	}
}
