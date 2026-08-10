<?php
/**
 * Seeds the local dev site with enough data to look at the portal.
 *
 * Development only, never shipped: bin/ is excluded from the release archive.
 * Run with `pnpm dev:seed`, which passes this to WP-CLI inside wp-env.
 *
 * Idempotent by design — it looks each object up by slug before creating it,
 * so running it twice does not produce a second organization and leave the
 * advertiser owning both.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Security\Roles;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/**
 * Finds a post of ours by slug, or makes one.
 *
 * @param string               $post_type Post type.
 * @param string               $slug      Post slug.
 * @param string               $title     Post title.
 * @param string               $status    Post status.
 * @param array<string, mixed> $meta      Meta to set.
 * @return int
 */
function laao_ads_seed_post( string $post_type, string $slug, string $title, string $status, array $meta ): int {
	/*
	 * Every status by name, not 'any'.
	 *
	 * 'any' means "every status not excluded from search", and the lap_
	 * statuses are all excluded. The lookup therefore matched nothing, the
	 * script created a second copy of every object on its second run, and the
	 * dashboard counted ten campaigns where five were seeded.
	 */
	$existing = get_posts(
		array(
			'post_type'        => $post_type,
			'name'             => $slug,
			'post_status'      => array_merge( Post_Statuses::all(), array( 'publish', 'draft' ) ),
			'posts_per_page'   => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
		)
	);

	$post_id = array() === $existing ? 0 : (int) $existing[0];

	if ( 0 === $post_id ) {
		$post_id = (int) wp_insert_post(
			array(
				'post_type'   => $post_type,
				'post_name'   => $slug,
				'post_title'  => $title,
				'post_status' => $status,
			),
			true
		);
	} else {
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_title'  => $title,
				'post_status' => $status,
			)
		);
	}

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	return $post_id;
}

$user = get_user_by( 'login', 'advertiser' );

if ( ! $user instanceof WP_User ) {
	$user_id = wp_insert_user(
		array(
			'user_login' => 'advertiser',
			'user_pass'  => 'advertiser',
			'user_email' => 'advertiser@example.test',
			'first_name' => 'Dana',
			'last_name'  => 'Okonkwo',
			'role'       => Roles::ADVERTISER,
		)
	);

	$user = get_user_by( 'id', is_int( $user_id ) ? $user_id : 0 );
}

if ( ! $user instanceof WP_User ) {
	WP_CLI::error( 'Could not create the advertiser account.' );
}

$user->set_role( Roles::ADVERTISER );

$org_id = laao_ads_seed_post(
	Post_Types::ORGANIZATION,
	'bright-angle-media',
	'Bright Angle Media',
	'publish',
	array( Org_Repository::META_OWNER_USER => $user->ID )
);

$placements = array(
	'leaderboard' => array( 'Homepage leaderboard', '728x90' ),
	'sidebar'     => array( 'Article sidebar', '300x250' ),
);

$placement_ids = array();

foreach ( $placements as $slug => $placement ) {
	$placement_ids[ $slug ] = laao_ads_seed_post(
		Post_Types::PLACEMENT,
		$slug,
		$placement[0],
		'publish',
		array( Placement_Repository::META_SIZE => $placement[1] )
	);
}

$day = DAY_IN_SECONDS;
$now = time();

$campaigns = array(
	array( 'spring-season-launch', 'Spring season launch', Post_Statuses::LIVE, $now - ( 7 * $day ), $now + ( 21 * $day ), 'leaderboard' ),
	array( 'gallery-opening', 'Gallery opening night', Post_Statuses::SUBMITTED, $now + ( 3 * $day ), $now + ( 17 * $day ), 'sidebar' ),
	array( 'summer-workshops', 'Summer workshops', Post_Statuses::DRAFT, 0, 0, 'sidebar' ),
	array( 'winter-retrospective', 'Winter retrospective', Post_Statuses::COMPLETE, $now - ( 90 * $day ), $now - ( 30 * $day ), 'leaderboard' ),
	array( 'members-drive', 'Members drive', Post_Statuses::PAUSED, $now - ( 4 * $day ), $now + ( 40 * $day ), 'leaderboard' ),
);

foreach ( $campaigns as $campaign ) {
	list( $slug, $campaign_title, $campaign_status, $start, $end, $placement ) = $campaign;

	$campaign_id = laao_ads_seed_post(
		Post_Types::CAMPAIGN,
		$slug,
		$campaign_title,
		$campaign_status,
		array(
			Campaign_Repository::META_ORG_ID   => $org_id,
			Campaign_Repository::META_START_TS => $start,
			Campaign_Repository::META_END_TS   => $end,
			Campaign_Repository::META_REVISION => 1,
		)
	);

	delete_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID );
	add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, $placement_ids[ $placement ] );

	if ( Post_Statuses::SUBMITTED === $campaign_status ) {
		update_post_meta( $campaign_id, Campaign_Repository::META_SUBMITTED_AT, $now - $day );
	}

	if ( Post_Statuses::DRAFT === $campaign_status ) {
		update_post_meta(
			$campaign_id,
			Campaign_Repository::META_REVIEW_NOTES,
			'The leaderboard artwork is 728x90 but the file supplied is 720x90. Please re-export at the exact size.'
		);
	}
}

WP_CLI::success(
	sprintf(
		'Seeded %d campaigns for "%s". Sign in as advertiser / advertiser, then open %s',
		count( $campaigns ),
		get_the_title( $org_id ),
		\LAAO_Advertiser_Portal\Portal\Routes::url()
	)
);
