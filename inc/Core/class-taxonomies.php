<?php
/**
 * Organisational grouping for placements.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Core;

use Aggressive\Ads\Security\Capabilities;

/**
 * Registers the placement group taxonomy.
 *
 * A group is the publisher's own filing, not a thing an advertiser buys.
 * `aggr_package` is the sellable bundle — it carries a price, a duration and a
 * placement list, and a campaign snapshots it. A group carries none of that,
 * and nothing in the decision path reads one. It exists so a publisher with
 * forty placements can find the eight they mean, and so utilisation can be
 * rolled up by something other than one placement at a time.
 *
 * Keeping the two apart is the whole point of the type. The moment a group
 * grows a price it has become a package badly, and the moment delivery reads
 * one it has become targeting badly.
 *
 * Private in the same full sense as the post types — see
 * `Post_Types::privacy_baseline()` for why `show_ui => false` alone is not
 * enough.
 */
final class Taxonomies implements Service {

	public const PLACEMENT_GROUP = 'aggr_placement_group';

	/**
	 * `wp_term_taxonomy.taxonomy` is varchar(32).
	 *
	 * The same truncate-on-write, never-match-on-read failure as
	 * `Post_Types::MAX_SLUG_LENGTH`, at a different width. Confirmed against
	 * `wp-admin/includes/schema.php` rather than assumed from the post-type
	 * limit, because guessing 20 here would have been wrong in the safe
	 * direction and guessing 64 wrong in the dangerous one.
	 */
	public const MAX_SLUG_LENGTH = 32;

	/**
	 * Attaches registration.
	 *
	 * Priority 6: after `Post_Types` at 5, because a taxonomy registered
	 * against a post type that does not exist yet silently attaches to
	 * nothing.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ), 6 );
	}

	/**
	 * Registers every taxonomy.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::registration_args() as $slug => $args ) {
			$args['labels'] = self::labels_for( $slug );

			register_taxonomy( $slug, self::object_types_for( $slug ), $args );
		}
	}

	/**
	 * Every taxonomy slug.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array( self::PLACEMENT_GROUP );
	}

	/**
	 * The post types a taxonomy attaches to.
	 *
	 * @param string $slug Taxonomy slug.
	 * @return array<int, string>
	 */
	public static function object_types_for( string $slug ): array {
		return self::PLACEMENT_GROUP === $slug
			? array( Post_Types::PLACEMENT )
			: array();
	}

	/**
	 * The register_taxonomy() arguments for every taxonomy, minus labels.
	 *
	 * Free of WordPress calls and of translation, so the privacy baseline is
	 * unit-testable without a bootstrap — the same split `Post_Types` uses,
	 * for the same reason.
	 *
	 * @return array<non-empty-string&lowercase-string, array<string, mixed>>
	 */
	public static function registration_args(): array {
		return array(
			self::PLACEMENT_GROUP => array_merge(
				self::privacy_baseline(),
				array( 'capabilities' => self::capabilities_for( self::PLACEMENT_GROUP ) )
			),
		);
	}

	/**
	 * The arguments that make a taxonomy genuinely private.
	 *
	 * `public => false` does not imply the rest: `show_in_rest` would still
	 * expose terms on the core route, and `rewrite`/`query_var` would still
	 * answer a front-end URL. A publisher's internal filing of their own
	 * inventory is not a public archive.
	 *
	 * Flat rather than hierarchical, deliberately. A nested group makes every
	 * roll-up ask whether a parent's number includes its children, and there is
	 * no answer that is right for both "how much of my sidebar sold" and "how
	 * much of my site sold". Slice 4 has to total something unambiguous.
	 *
	 * @return array<string, mixed>
	 */
	private static function privacy_baseline(): array {
		return array(
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => false,
			'show_in_menu'       => false,
			'show_in_nav_menus'  => false,
			'show_in_rest'       => false,
			'show_tagcloud'      => false,
			'show_in_quick_edit' => false,
			'show_admin_column'  => false,
			'rewrite'            => false,
			'query_var'          => false,
			'hierarchical'       => false,
		);
	}

	/**
	 * The four term capabilities for one taxonomy.
	 *
	 * All four map to the same capability that gates placement writes. A group
	 * is placement configuration, so anyone who may reshape placements may file
	 * them, and anyone who may not, may not. `assign_terms` is deliberately not
	 * widened to readers: assigning is a write.
	 *
	 * @param string $slug Taxonomy slug.
	 * @return array<string, string>
	 */
	public static function capabilities_for( string $slug ): array {
		$cap = self::PLACEMENT_GROUP === $slug ? Capabilities::MANAGE_PLACEMENTS : 'do_not_allow';

		return array(
			'manage_terms' => $cap,
			'edit_terms'   => $cap,
			'delete_terms' => $cap,
			'assign_terms' => $cap,
		);
	}

	/**
	 * The labels for one taxonomy.
	 *
	 * @param string $slug Taxonomy slug.
	 * @return array<string, string>
	 */
	private static function labels_for( string $slug ): array {
		if ( self::PLACEMENT_GROUP !== $slug ) {
			return array();
		}

		return array(
			'name'          => __( 'Placement groups', 'aggressive-ads' ),
			'singular_name' => __( 'Placement group', 'aggressive-ads' ),
		);
	}
}
