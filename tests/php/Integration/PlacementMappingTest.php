<?php
/**
 * Placement to ad-group resolution.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Install\Installer;
use LAAO_Advertiser_Portal\Integration\Adsanity\Adsanity;
use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Ownership;
use LAAO_Advertiser_Portal\Security\Roles;
use LAAO_Advertiser_Portal\Workflow\Campaign_State_Machine;
use WP_Error;
use WP_UnitTestCase;

/**
 * Approval resolves every mapping before it writes anything. These are the
 * tests that keep that true.
 *
 * Runs against the AdSanity contract stub, since the real plugin is licensed
 * and cannot be installed here. See docs/adr/0015-adsanity-contract-stub-for-ci.md.
 */
final class PlacementMappingTest extends WP_UnitTestCase {

	/**
	 * The subject.
	 *
	 * @var Placement_Mapping
	 */
	private Placement_Mapping $mapping;

	/**
	 * Placement persistence.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Sets up the subject.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->placements = new Placement_Repository();
		$this->mapping    = new Placement_Mapping( $this->placements );
	}

	/**
	 * Creates an ad-group term.
	 *
	 * @param string $name Term name.
	 * @return int
	 */
	private function ad_group( string $name ): int {
		$term = wp_insert_term( $name, Adsanity::TAXONOMY );

		$this->assertIsArray( $term );

		return (int) $term['term_id'];
	}

	/**
	 * Creates a placement, optionally mapped to an ad group.
	 *
	 * @param string $name    Placement name.
	 * @param int    $term_id Ad-group term id, or 0 for unmapped.
	 * @return int
	 */
	private function placement( string $name, int $term_id ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		update_post_meta( $id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $id, Placement_Repository::META_ADGROUP_TERM, $term_id );

		return $id;
	}

	/**
	 * The contract stub is providing what the publisher depends on.
	 *
	 * Asserted first because everything below is meaningless if the provider
	 * shapes are absent — a suite that silently tests nothing is the failure
	 * mode this whole file is guarding against.
	 *
	 * @return void
	 */
	public function test_the_provider_shapes_are_present(): void {
		$this->assertTrue( post_type_exists( Adsanity::POST_TYPE ) );
		$this->assertTrue( taxonomy_exists( Adsanity::TAXONOMY ) );
		$this->assertTrue( Adsanity::is_available() );
		$this->assertSame( 2082672000, Adsanity::end_of_life() );
		$this->assertTrue( Adsanity::knows_size( '728x90' ) );
	}

	/**
	 * Fully mapped placements resolve to their terms.
	 *
	 * @return void
	 */
	public function test_mapped_placements_resolve(): void {
		$header  = $this->ad_group( '728x90 Header' );
		$sidebar = $this->ad_group( '160x600' );

		$a = $this->placement( 'Homepage Leaderboard', $header );
		$b = $this->placement( 'Article Sidebar', $sidebar );

		$resolved = $this->mapping->resolve_all( array( $a, $b ) );

		$this->assertSame(
			array(
				$a => $header,
				$b => $sidebar,
			),
			$resolved
		);
	}

	/**
	 * **Mapping is keyed on term id, not term name.**
	 *
	 * The live taxonomy contains a term named with U+00D7 rather than the
	 * letter `x`, while every other one uses `x`. Renaming a term must not
	 * change where a placement publishes, or a name-based implementation would
	 * work for four placements and fail on the fifth.
	 *
	 * @return void
	 */
	public function test_renaming_an_ad_group_does_not_break_resolution(): void {
		$term      = $this->ad_group( '728x90 Break' );
		$placement = $this->placement( 'Article Break', $term );

		wp_update_term( $term, Adsanity::TAXONOMY, array( 'name' => "728\u{00D7}90 Break" ) );

		$this->assertSame(
			array( $placement => $term ),
			$this->mapping->resolve_all( array( $placement ) )
		);
	}

	/**
	 * An unmapped placement fails the whole resolution, and is named.
	 *
	 * @return void
	 */
	public function test_an_unmapped_placement_fails_and_is_named(): void {
		$mapped   = $this->placement( 'Homepage Leaderboard', $this->ad_group( '728x90 Header' ) );
		$unmapped = $this->placement( 'Mobile Banner', 0 );

		$result = $this->mapping->resolve_all( array( $mapped, $unmapped ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_placement_unmapped', $result->get_error_code() );
		$this->assertStringContainsString( 'Mobile Banner', $result->get_error_message() );

		// The one that did resolve is not reported as a problem.
		$this->assertStringNotContainsString( 'Homepage Leaderboard', $result->get_error_message() );
	}

	/**
	 * A placement pointing at a deleted term fails, rather than publishing
	 * into an id that resolves to nothing.
	 *
	 * @return void
	 */
	public function test_a_dangling_term_fails(): void {
		$term      = $this->ad_group( 'Temporary' );
		$placement = $this->placement( 'Homepage Feature', $term );

		wp_delete_term( $term, Adsanity::TAXONOMY );

		$result = $this->mapping->resolve_all( array( $placement ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_placement_unmapped', $result->get_error_code() );
		$this->assertStringContainsString( 'Homepage Feature', $result->get_error_message() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertContains( 'Homepage Feature', $data['dangling'] );
	}

	/**
	 * Every problem is named at once, so a reviewer fixes the configuration in
	 * one pass rather than discovering the next one on retry.
	 *
	 * @return void
	 */
	public function test_every_problem_is_named_at_once(): void {
		$term = $this->ad_group( 'Doomed' );

		$unmapped_a = $this->placement( 'Mobile Banner', 0 );
		$unmapped_b = $this->placement( 'Inline Article', 0 );
		$dangling   = $this->placement( 'Homepage Feature', $term );

		wp_delete_term( $term, Adsanity::TAXONOMY );

		$result = $this->mapping->resolve_all( array( $unmapped_a, $unmapped_b, $dangling ) );

		$this->assertInstanceOf( WP_Error::class, $result );

		foreach ( array( 'Mobile Banner', 'Inline Article', 'Homepage Feature' ) as $name ) {
			$this->assertStringContainsString( $name, $result->get_error_message() );
		}
	}

	/**
	 * **With AdSanity absent, resolution refuses rather than guessing.**
	 *
	 * AdSanity being inactive is a supported state, not an error state: the
	 * portal keeps accepting, reviewing and rejecting campaigns, and only
	 * approval refuses. Nothing else asserts this, because the contract stub
	 * always provides the provider — so removing the availability check left
	 * the whole suite green until this test existed.
	 *
	 * @return void
	 */
	public function test_resolution_refuses_when_the_provider_is_absent(): void {
		$placement = $this->placement( 'Homepage Leaderboard', $this->ad_group( '728x90 Header' ) );

		$this->assertTrue( Adsanity::is_available() );

		unregister_taxonomy( Adsanity::TAXONOMY );

		try {
			$this->assertFalse( Adsanity::is_available() );

			$result = $this->mapping->resolve_all( array( $placement ) );

			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'laao_ads_provider_unavailable', $result->get_error_code() );
		} finally {
			// Registered globally at init, so it has to be put back by hand or
			// every later test in the process runs without the taxonomy.
			register_taxonomy(
				Adsanity::TAXONOMY,
				Adsanity::POST_TYPE,
				array(
					'label'        => 'Ad Groups',
					'hierarchical' => true,
					'public'       => true,
					'show_in_rest' => true,
				)
			);
		}

		$this->assertTrue( Adsanity::is_available(), 'The taxonomy was not restored for later tests.' );
	}

	/**
	 * A campaign with no placements cannot be published.
	 *
	 * @return void
	 */
	public function test_no_placements_is_a_failure(): void {
		$result = $this->mapping->resolve_all( array() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_no_placements_to_resolve', $result->get_error_code() );
	}

	/**
	 * **Approval aborts before writing anything when a mapping is missing.**
	 *
	 * This is the property ADR-0007 exists for. Discovering the problem partway
	 * through publishing leaves some ads live and the campaign in a state
	 * nobody designed.
	 *
	 * @return void
	 */
	public function test_approval_aborts_before_any_write_when_a_mapping_is_missing(): void {
		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$org_id   = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $reviewer );

		$unmapped = $this->placement( 'Mobile Banner', 0 );

		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::REVIEW,
			)
		);
		update_post_meta( $campaign, Campaign_Repository::META_ORG_ID, $org_id );
		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $unmapped );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
		wp_set_current_user( $reviewer );

		$published = false;

		$machine = new Campaign_State_Machine(
			new Campaign_Repository(),
			new Audit_Repository(),
			new \LAAO_Advertiser_Portal\Workflow\Transition_Guards(
				new Campaign_Repository(),
				array(
					\LAAO_Advertiser_Portal\Domain\Transition_Table::GUARD_VALIDATOR        => static fn (): bool => true,
					\LAAO_Advertiser_Portal\Domain\Transition_Table::GUARD_MAPPINGS_RESOLVE => $this->mapping->as_guard(
						static fn ( int $id ): array => ( new Campaign_Repository() )->placement_ids( $id )
					),
				)
			),
			array(
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_PUBLISH => static function () use ( &$published ): bool {
					$published = true;

					return true;
				},
			)
		);

		$result = $machine->apply( $campaign, Post_Statuses::APPROVED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_placement_unmapped', $result->get_error_code() );
		$this->assertFalse( $published, 'Publication ran despite an unresolved mapping.' );
		$this->assertSame(
			Post_Statuses::REVIEW,
			get_post_status( $campaign ),
			'The campaign was approved with a placement that resolves to nothing.'
		);
	}
}
