<?php
/**
 * Filing placements into groups, through the real taxonomy.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Taxonomies;
use Aggressive\Ads\Domain\Placement_Groups;
use Aggressive\Ads\Repository\Placement_Repository;
use WP_UnitTestCase;

/**
 * `PlacementGroupsTest` in the unit suite proves the slug rules in isolation.
 * This proves the half that needs a database: that the taxonomy is actually
 * registered against placements, that a write creates terms and is verified by
 * reading back, and that replacing a set removes what it replaced.
 *
 * The removal case is the one worth the most here. An append-shaped write
 * passes every test that only ever adds, and leaves a publisher unable to take
 * a placement out of a group through the only screen there is.
 */
final class PlacementGroupsTest extends WP_UnitTestCase {

	/**
	 * The repository under test.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $repository;

	public function set_up(): void {
		parent::set_up();

		$this->repository = new Placement_Repository();
	}

	/**
	 * Creates a placement.
	 *
	 * @param string $slug Slot slug.
	 * @return int
	 */
	private function placement( string $slug = 'sidebar-slot' ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => $slug,
			)
		);

		update_post_meta( $id, Placement_Repository::META_SIZE, '300x250' );

		return $id;
	}

	public function test_the_taxonomy_is_registered_against_placements(): void {
		$this->assertTrue( taxonomy_exists( Taxonomies::PLACEMENT_GROUP ) );
		$this->assertContains(
			Taxonomies::PLACEMENT_GROUP,
			get_object_taxonomies( Post_Types::PLACEMENT )
		);
	}

	/**
	 * The taxonomy is private in the same full sense the post types are.
	 *
	 * Asserted rather than assumed: `public => false` alone leaves the REST
	 * route and the front-end query var open, and a publisher's internal
	 * filing of their own inventory is not a public archive.
	 */
	public function test_the_taxonomy_is_not_public(): void {
		$taxonomy = get_taxonomy( Taxonomies::PLACEMENT_GROUP );

		$this->assertNotFalse( $taxonomy );
		$this->assertFalse( $taxonomy->public );
		$this->assertFalse( $taxonomy->publicly_queryable );
		$this->assertFalse( $taxonomy->show_in_rest );
		$this->assertFalse( $taxonomy->rewrite );
		$this->assertFalse( $taxonomy->query_var );
		$this->assertFalse( $taxonomy->hierarchical );
	}

	/** Term management is gated on the same capability that gates placements. */
	public function test_term_capabilities_map_to_placement_management(): void {
		$taxonomy = get_taxonomy( Taxonomies::PLACEMENT_GROUP );

		$this->assertNotFalse( $taxonomy );
		$this->assertSame( 'aggr_manage_placements', $taxonomy->cap->manage_terms );
		$this->assertSame( 'aggr_manage_placements', $taxonomy->cap->edit_terms );
		$this->assertSame( 'aggr_manage_placements', $taxonomy->cap->delete_terms );
		$this->assertSame( 'aggr_manage_placements', $taxonomy->cap->assign_terms );
	}

	public function test_a_placement_starts_in_no_groups(): void {
		$this->assertSame( array(), $this->repository->groups( $this->placement() ) );
	}

	public function test_writing_groups_creates_the_terms_and_reads_back(): void {
		$id = $this->placement();

		$this->assertTrue( $this->repository->set_groups( $id, array( 'Sidebar', 'above the fold' ) ) );
		$this->assertSame(
			array( 'above-the-fold', 'sidebar' ),
			$this->repository->groups( $id )
		);

		$this->assertInstanceOf( \WP_Term::class, get_term_by( 'slug', 'sidebar', Taxonomies::PLACEMENT_GROUP ) );
	}

	/**
	 * A second write replaces the first, rather than adding to it.
	 *
	 * Both the count and the specific absence are asserted: "two groups" would
	 * also be true of a set that kept the wrong one.
	 */
	public function test_writing_groups_replaces_the_previous_set(): void {
		$id = $this->placement();

		$this->repository->set_groups( $id, array( 'sidebar', 'footer' ) );
		$this->assertTrue( $this->repository->set_groups( $id, array( 'header' ) ) );

		$groups = $this->repository->groups( $id );

		$this->assertSame( array( 'header' ), $groups );
		$this->assertNotContains( 'sidebar', $groups );
		$this->assertNotContains( 'footer', $groups );
	}

	/** An empty set clears the placement's filing entirely. */
	public function test_an_empty_set_removes_every_group(): void {
		$id = $this->placement();

		$this->repository->set_groups( $id, array( 'sidebar' ) );

		$this->assertTrue( $this->repository->set_groups( $id, array() ) );
		$this->assertSame( array(), $this->repository->groups( $id ) );
	}

	/**
	 * Removing a placement from a group does not delete the group.
	 *
	 * Another placement may still be filed under it, and a term that vanishes
	 * when its last member leaves would take a publisher's naming with it.
	 */
	public function test_clearing_one_placement_leaves_the_term_for_others(): void {
		$first  = $this->placement( 'first-slot' );
		$second = $this->placement( 'second-slot' );

		$this->repository->set_groups( $first, array( 'sidebar' ) );
		$this->repository->set_groups( $second, array( 'sidebar' ) );
		$this->repository->set_groups( $first, array() );

		$this->assertSame( array(), $this->repository->groups( $first ) );
		$this->assertSame( array( 'sidebar' ), $this->repository->groups( $second ) );
	}

	/** Two placements can share a group without either disturbing the other. */
	public function test_two_placements_share_one_group(): void {
		$first  = $this->placement( 'first-slot' );
		$second = $this->placement( 'second-slot' );

		$this->repository->set_groups( $first, array( 'sidebar' ) );
		$this->repository->set_groups( $second, array( 'sidebar', 'footer' ) );

		$this->assertSame( array( 'sidebar' ), $this->repository->groups( $first ) );
		$this->assertSame( array( 'footer', 'sidebar' ), $this->repository->groups( $second ) );
	}

	/** The domain rules apply on the way in, not only in the unit suite. */
	public function test_labels_are_normalised_on_write(): void {
		$id = $this->placement();

		$this->repository->set_groups( $id, array( '  Above   The Fold!! ', 'above-the-fold' ) );

		$this->assertSame( array( 'above-the-fold' ), $this->repository->groups( $id ) );
	}

	public function test_the_group_count_is_bounded_on_write(): void {
		$id   = $this->placement();
		$many = array();

		for ( $i = 0; $i < Placement_Groups::MAX_GROUPS + 10; $i++ ) {
			$many[] = sprintf( 'group-%03d', $i );
		}

		$this->assertTrue( $this->repository->set_groups( $id, $many ) );
		$this->assertCount( Placement_Groups::MAX_GROUPS, $this->repository->groups( $id ) );
	}

	/**
	 * A slug already used by an unrelated taxonomy still files correctly.
	 *
	 * `wp_terms.slug` is shared across taxonomies, so creating a group named
	 * for something that already exists as a category is the case where
	 * WordPress may hand back `sidebar-2` instead of `sidebar`. The read-back
	 * verification in `set_groups()` is what turns that from a silent
	 * mis-filing into a reported failure, so the behaviour is pinned rather
	 * than assumed either way.
	 */
	public function test_a_slug_shared_with_another_taxonomy(): void {
		wp_insert_term( 'Sidebar', 'category', array( 'slug' => 'sidebar' ) );

		$id     = $this->placement();
		$stored = $this->repository->set_groups( $id, array( 'sidebar' ) );

		$this->assertTrue( $stored, 'filing under a slug used elsewhere must succeed' );
		$this->assertSame( array( 'sidebar' ), $this->repository->groups( $id ) );
	}

	/**
	 * A numeric group name files under that name, not under a term id.
	 *
	 * `wp_set_object_terms()` resolves a numeric string through
	 * `term_exists()`, which treats digits as a term_id. A group a publisher
	 * named "2024" is therefore the one label that can attach some unrelated
	 * term — or nothing — while every count and every log still says the write
	 * succeeded.
	 */
	public function test_a_numeric_group_name_is_filed_as_a_name(): void {
		$id = $this->placement();

		$this->assertTrue( $this->repository->set_groups( $id, array( '2024' ) ) );
		$this->assertSame( array( '2024' ), $this->repository->groups( $id ) );
	}

	/**
	 * The numeric case, with a collision that actually exists.
	 *
	 * The test above passes on a fresh database for the wrong reason: term ids
	 * there are small, so "2024" matches nothing and the ambiguity never
	 * arises. This one takes a real term's id and uses it as the label, which
	 * is the only version of the test that can observe a numeric string being
	 * resolved as an id.
	 */
	public function test_a_group_named_after_an_existing_term_id_is_still_a_name(): void {
		$other = wp_insert_term( 'Unrelated', 'category' );

		$this->assertIsArray( $other );

		$label = (string) $other['term_id'];
		$id    = $this->placement();

		$this->assertTrue( $this->repository->set_groups( $id, array( $label ) ) );
		$this->assertSame( array( $label ), $this->repository->groups( $id ) );

		// The unrelated category must not have been dragged into our taxonomy.
		$term = get_term( (int) $other['term_id'] );
		$this->assertInstanceOf( \WP_Term::class, $term );
		$this->assertSame( 'category', $term->taxonomy );
	}

	/** A post that is not a placement is not filed, and says so. */
	public function test_a_non_placement_is_refused(): void {
		$page = (int) self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertFalse( $this->repository->set_groups( $page, array( 'sidebar' ) ) );
		$this->assertSame( array(), $this->repository->groups( $page ) );
		$this->assertSame( array(), $this->repository->groups( 0 ) );
	}
}
