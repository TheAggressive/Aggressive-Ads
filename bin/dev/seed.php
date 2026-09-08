<?php
/**
 * Seeds the local dev site with enough data to look at the portal.
 *
 * Development only, never shipped: bin/ is excluded from the release archive.
 * Run with `pnpm dev:seed`, which passes this to WP-CLI inside the test stack.
 *
 * Idempotent by design — it looks each object up by slug before creating it,
 * so running it twice does not produce a second organization and leave the
 * advertiser owning both.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

/*
 * **Refused outside a development site, because this writes reporting data.**
 *
 * `bin/` never enters a release archive, so the shipped plugin cannot run
 * this. That was enough while the seed only made campaigns and organisations:
 * obviously fake rows a person would notice. It stopped being enough when the
 * seed began writing `aggr_decision_rollups` and `aggr_rollups`, because
 * fabricated counters are indistinguishable from real delivery once they are
 * in the table — there is no flag on a row saying it was invented, and a
 * publisher reading a fill rate has no way to tell.
 *
 * The risk is a checkout pointed at a production database: a staging box
 * sharing credentials, or a developer restoring a dump. Wrong numbers are
 * worse than no numbers, so this refuses rather than warns, and there is no
 * override flag — changing the environment type is the deliberate act.
 */
$aggr_seed_environment = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

if ( ! in_array( $aggr_seed_environment, array( 'local', 'development' ), true ) ) {
	WP_CLI::error(
		sprintf(
			'Refusing to seed: this writes delivery counters that cannot be told from real traffic, and WP_ENVIRONMENT_TYPE is "%s". Set it to "local" or "development" on a site where invented numbers are acceptable.',
			$aggr_seed_environment
		)
	);
}

// The development site should expose the signup surface this repository ships.
// bin/ never enters a release archive, so this cannot open registration on a
// production install by surprise.
update_option( 'users_can_register', 1 );

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
function aggr_seed_post( string $post_type, string $slug, string $title, string $status, array $meta ): int {
	/*
	 * Every status by name, not 'any'.
	 *
	 * 'any' means "every status not excluded from search", and the campaign
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

$org_id = aggr_seed_post(
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
	$placement_ids[ $slug ] = aggr_seed_post(
		Post_Types::PLACEMENT,
		$slug,
		$placement[0],
		'publish',
		array(
			Placement_Repository::META_SIZE      => $placement[1],
			Placement_Repository::META_IS_ACTIVE => 1,
		)
	);
}

$packages = array(
	'launch-bundle'   => array( 'Launch bundle', 30, 45000, array( 'leaderboard', 'sidebar' ) ),
	'focused-sidebar' => array( 'Focused sidebar', 14, 17500, array( 'sidebar' ) ),
);

foreach ( $packages as $slug => $package ) {
	list( $package_title, $duration_days, $price_cents, $package_placements ) = $package;

	$package_id = aggr_seed_post(
		Post_Types::PACKAGE,
		$slug,
		$package_title,
		'publish',
		array(
			Package_Repository::META_DURATION_DAYS => $duration_days,
			Package_Repository::META_PRICE_CENTS   => $price_cents,
			Package_Repository::META_CURRENCY      => 'USD',
			Package_Repository::META_IS_ACTIVE     => 1,
			Package_Repository::META_IS_DEFAULT    => 'launch-bundle' === $slug ? 1 : 0,
		)
	);

	delete_post_meta( $package_id, Package_Repository::META_PLACEMENT_ID );

	foreach ( $package_placements as $placement ) {
		add_post_meta( $package_id, Package_Repository::META_PLACEMENT_ID, $placement_ids[ $placement ] );
	}
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

	$campaign_id = aggr_seed_post(
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

/*
 * Ninety days of delivery, so the reporting and outlook screens have something
 * to show.
 *
 * Without this every figure on those screens is nought on a fresh site, which
 * reads as a broken report rather than an empty one — and the forecast screen
 * has no history to draw from at all, because a forecast is a quantile over
 * observed days.
 *
 * Written through `Decision_Rollup_Repository::add()` and
 * `Rollup_Repository::increment()`, the same calls delivery makes. A seed that
 * inserted rows directly would be a second way to write these tables, and the
 * one that is never exercised is the one that drifts.
 */
$aggr_seed_rollups  = new Decision_Rollup_Repository();
$aggr_seed_delivery = new Rollup_Repository();

$aggr_seed_rollups->install_table();
$aggr_seed_delivery->install_table();

/*
 * Cleared first, because the counters increment.
 *
 * `add()` and `increment()` are the production write paths and they add to
 * what is there — which is right for delivery and wrong for a seed. Running
 * `dev:seed` twice would otherwise double every figure on the reporting
 * screens, and a number that changes because somebody re-ran a script is a
 * number nobody can trust. The rest of the seeder is find-or-make for the same
 * reason.
 */
global $wpdb;

foreach ( $placement_ids as $aggr_seed_clear ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev seeder resetting its own rows.
	$wpdb->delete( $wpdb->prefix . 'aggr_decision_rollups', array( 'placement_id' => $aggr_seed_clear ), array( '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Dev seeder resetting its own rows.
	$wpdb->delete( $wpdb->prefix . 'aggr_rollups', array( 'placement_id' => $aggr_seed_clear ), array( '%d' ) );
}

$aggr_seed_days = 90;
$aggr_seed_end  = strtotime( gmdate( 'Y-m-d' ) . ' 00:00:00 UTC' ) - DAY_IN_SECONDS;

/*
 * A weekday rhythm rather than a flat line, because a forecast that models
 * seasonality is only demonstrable against data that has some. Weekends run at
 * about a third, which is roughly what a publisher's own traffic does.
 */
$aggr_seed_shape = array( 1.0, 1.05, 1.1, 1.05, 0.95, 0.35, 0.3 );

foreach ( $placement_ids as $aggr_seed_slug => $aggr_seed_placement ) {
	// A stable per-placement scale, so the catalogue is not uniform.
	$aggr_seed_scale = 40 + ( crc32( (string) $aggr_seed_slug ) % 60 );

	for ( $aggr_seed_ago = $aggr_seed_days; $aggr_seed_ago >= 1; $aggr_seed_ago-- ) {
		$aggr_seed_ts  = $aggr_seed_end - ( $aggr_seed_ago - 1 ) * DAY_IN_SECONDS;
		$aggr_seed_day = gmdate( 'Y-m-d', $aggr_seed_ts );
		$aggr_seed_dow = (int) gmdate( 'N', $aggr_seed_ts ) - 1;

		$aggr_seed_requests = (int) round( $aggr_seed_scale * $aggr_seed_shape[ $aggr_seed_dow ] * ( 0.85 + ( ( $aggr_seed_ago % 7 ) / 20 ) ) );

		if ( $aggr_seed_requests < 1 ) {
			continue;
		}

		$aggr_seed_targeting = (int) floor( $aggr_seed_requests * 0.12 );
		$aggr_seed_capped    = (int) floor( $aggr_seed_requests * 0.05 );
		$aggr_seed_fills     = $aggr_seed_requests - $aggr_seed_targeting - $aggr_seed_capped;

		$aggr_seed_rollups->add(
			$aggr_seed_day,
			$aggr_seed_placement,
			array(
				Decision_Outcome::REQUEST          => $aggr_seed_requests,
				Decision_Outcome::FILL             => $aggr_seed_fills,
				No_Fill_Reason::TARGETING_MISMATCH => $aggr_seed_targeting,
				No_Fill_Reason::FREQUENCY_CAPPED   => $aggr_seed_capped,
			),
			Opportunity::PAGE
		);

		// A rotating slot refreshes, and refresh is separate inventory.
		$aggr_seed_rollups->add(
			$aggr_seed_day,
			$aggr_seed_placement,
			array(
				Decision_Outcome::REQUEST => (int) floor( $aggr_seed_requests * 0.4 ),
				Decision_Outcome::FILL    => (int) floor( $aggr_seed_requests * 0.36 ),
			),
			Opportunity::REFRESH
		);

		$aggr_seed_delivery->increment( 'impressions', $aggr_seed_placement, (int) $campaign_id, $aggr_seed_day, 0, $org_id );

		if ( 0 === $aggr_seed_ago % 3 ) {
			$aggr_seed_delivery->increment( 'clicks', $aggr_seed_placement, (int) $campaign_id, $aggr_seed_day, 0, $org_id );
		}
	}
}

WP_CLI::success(
	sprintf(
		'Seeded %d days of delivery across %d placements.',
		$aggr_seed_days,
		count( $placement_ids )
	)
);

WP_CLI::success(
	sprintf(
		'Seeded %d campaigns for "%s". Sign in as advertiser / advertiser, then open %s',
		count( $campaigns ),
		get_the_title( $org_id ),
		\Aggressive\Ads\Portal\Routes::url()
	)
);
