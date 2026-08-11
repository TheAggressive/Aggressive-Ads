<?php
/**
 * The five business entities, as private custom post types.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Core;

/**
 * Registers Organization, Placement, Package, Campaign and Creative.
 *
 * All five are private in the full sense — no REST route, no permalink, no
 * query var. See docs/adr/0002-private-cpts-behind-repositories.md.
 */
final class Post_Types implements Service {

	public const ORGANIZATION = 'laao_ads_org';
	public const PLACEMENT    = 'laao_ads_placement';
	public const PACKAGE      = 'laao_ads_package';
	public const CAMPAIGN     = 'laao_ads_campaign';
	public const CREATIVE     = 'laao_ads_creative';

	/**
	 * `wp_posts.post_type` is varchar(20).
	 *
	 * A longer slug does not error — it truncates on write and then fails to
	 * match on read, producing posts that exist and cannot be queried.
	 */
	public const MAX_SLUG_LENGTH = 20;

	/**
	 * Attaches registration.
	 *
	 * Priority 5 so the post types exist before anything on the default
	 * priority — our own services included — tries to query them.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register' ), 5 );
	}

	/**
	 * Registers every post type.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( self::registration_args() as $slug => $args ) {
			$args['labels'] = self::labels_for( $slug );

			register_post_type( $slug, $args );
		}
	}

	/**
	 * Every post-type slug.
	 *
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::ORGANIZATION,
			self::PLACEMENT,
			self::PACKAGE,
			self::CAMPAIGN,
			self::CREATIVE,
		);
	}

	/**
	 * The singular and plural capability names each post type generates.
	 *
	 * Kept here rather than in Capabilities because the post type is what
	 * declares them; Capabilities derives the generated primitives from this.
	 *
	 * @return array<non-empty-string&lowercase-string, array{singular: string, plural: string}>
	 */
	public static function capability_names(): array {
		return array(
			self::ORGANIZATION => array(
				'singular' => 'laao_ads_org',
				'plural'   => 'laao_ads_orgs',
			),
			self::PLACEMENT    => array(
				'singular' => 'laao_ads_placement',
				'plural'   => 'laao_ads_placements',
			),
			self::PACKAGE      => array(
				'singular' => 'laao_ads_package',
				'plural'   => 'laao_ads_packages',
			),
			self::CAMPAIGN     => array(
				'singular' => 'laao_ads_campaign',
				'plural'   => 'laao_ads_campaigns',
			),
			self::CREATIVE     => array(
				'singular' => 'laao_ads_creative',
				'plural'   => 'laao_ads_creatives',
			),
		);
	}

	/**
	 * The register_post_type() arguments for every post type, minus labels.
	 *
	 * Free of WordPress calls and of translation, so the privacy baseline is
	 * unit-testable without a bootstrap. Labels are merged in by register(),
	 * which is the only part that needs WordPress loaded.
	 *
	 * @return array<non-empty-string&lowercase-string, array<string, mixed>>
	 */
	public static function registration_args(): array {
		$args = array();

		foreach ( self::capability_names() as $slug => $names ) {
			$args[ $slug ] = array_merge(
				self::privacy_baseline(),
				array(
					'capability_type' => array( $names['singular'], $names['plural'] ),
					'supports'        => self::supports_for( $slug ),
				)
			);
		}

		return $args;
	}

	/**
	 * The arguments that make a post type genuinely private.
	 *
	 * `show_ui => false` alone hides a post type from the admin and leaves the
	 * REST route, the permalink and the ?post_type= query all open. It is the
	 * combination of show_in_rest, rewrite and query_var that closes them.
	 *
	 * `show_ui` stays false: Phase 5 uses a constrained review screen rather
	 * than WordPress's generic editor, which could bypass the state machine.
	 * `delete_with_user` stays false forever: deleting a WordPress user must
	 * not erase the record of what their organization ran.
	 *
	 * @return array<string, mixed>
	 */
	private static function privacy_baseline(): array {
		return array(
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'hierarchical'        => false,
			'delete_with_user'    => false,
			'map_meta_cap'        => true,
			'can_export'          => true,
		);
	}

	/**
	 * The `supports` array for one post type.
	 *
	 * Placements and packages are configuration rather than someone's work, so
	 * they carry no author. Everything else does, because `author` is what the
	 * admin list tables and core's own meta-cap mapping expect to find — even
	 * though ownership here is resolved by organization, not by author. See
	 * docs/adr/0009-org-scoped-map-meta-cap.md.
	 *
	 * @param string $slug Post type slug.
	 * @return array<int, string>
	 */
	private static function supports_for( string $slug ): array {
		if ( self::PLACEMENT === $slug || self::PACKAGE === $slug ) {
			return array( 'title', 'revisions' );
		}

		return array( 'title', 'author', 'revisions' );
	}

	/**
	 * Labels for one post type.
	 *
	 * These labels support registration and internal APIs. Advertisers never see
	 * them — the portal speaks its own language and never exposes WordPress
	 * terminology.
	 *
	 * @param string $slug Post type slug.
	 * @return array<string, string>
	 */
	private static function labels_for( string $slug ): array {
		$names = match ( $slug ) {
			self::ORGANIZATION => array( __( 'Organization', 'laao-advertiser-portal' ), __( 'Organizations', 'laao-advertiser-portal' ) ),
			self::PLACEMENT    => array( __( 'Placement', 'laao-advertiser-portal' ), __( 'Placements', 'laao-advertiser-portal' ) ),
			self::PACKAGE      => array( __( 'Package', 'laao-advertiser-portal' ), __( 'Packages', 'laao-advertiser-portal' ) ),
			self::CAMPAIGN     => array( __( 'Campaign', 'laao-advertiser-portal' ), __( 'Campaigns', 'laao-advertiser-portal' ) ),
			default            => array( __( 'Creative', 'laao-advertiser-portal' ), __( 'Creatives', 'laao-advertiser-portal' ) ),
		};

		list( $singular, $plural ) = $names;

		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'menu_name'     => $plural,
			'all_items'     => $plural,
			'edit_item'     => $singular,
			'view_item'     => $singular,
			'search_items'  => $plural,
			'not_found'     => $plural,
		);
	}
}
