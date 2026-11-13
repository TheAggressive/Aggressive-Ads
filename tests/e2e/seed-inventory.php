<?php
/**
 * Actual package and forecast configuration for the disposable storefront flow.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Forecast_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Package_Manager;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
$aggr_inventory_e2e_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( array() === $aggr_inventory_e2e_admins ) {
	WP_CLI::error( 'The disposable fixture needs a staff account.' );
}
wp_set_current_user( (int) $aggr_inventory_e2e_admins[0] );
$aggr_inventory_e2e_timezone = (string) get_option( 'timezone_string', '' );
update_option( 'timezone_string', 'UTC' );
$aggr_inventory_e2e_placements = new Placement_Repository();
$aggr_inventory_e2e_slug       = 'e2e-inventory-' . wp_generate_uuid4();
$aggr_inventory_e2e_placement  = $aggr_inventory_e2e_placements->create( 'E2E inventory banner', $aggr_inventory_e2e_slug );
if ( is_wp_error( $aggr_inventory_e2e_placement ) || ! $aggr_inventory_e2e_placements->save(
	$aggr_inventory_e2e_placement,
	array(
		'name'       => 'E2E inventory banner',
		'slug'       => $aggr_inventory_e2e_slug,
		'size'       => '728x90',
		'is_active'  => true,
		'sort_order' => 0,
	)
) ) {
	WP_CLI::error( 'The fixture placement could not be configured.' );
}
$aggr_inventory_e2e_bytes = file_get_contents( __DIR__ . '/../fixtures/creative-728x90.avif' );
if ( false === $aggr_inventory_e2e_bytes ) {
	WP_CLI::error( 'The publisher image fixture is missing.' );
}
$aggr_inventory_e2e_upload = wp_upload_bits( 'e2e-public-house.avif', null, $aggr_inventory_e2e_bytes );
if ( $aggr_inventory_e2e_upload['error'] ) {
	WP_CLI::error( 'The publisher example could not be uploaded.' );
}
$aggr_inventory_e2e_image = wp_insert_attachment(
	array(
		'post_title'     => 'E2E publisher example',
		'post_mime_type' => 'image/avif',
		'post_status'    => 'inherit',
	),
	$aggr_inventory_e2e_upload['file'],
	0,
	true
);
if ( is_wp_error( $aggr_inventory_e2e_image ) ) {
	WP_CLI::error( 'The publisher example attachment could not be created.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata( $aggr_inventory_e2e_image, wp_generate_attachment_metadata( $aggr_inventory_e2e_image, $aggr_inventory_e2e_upload['file'] ) );
if ( ! $aggr_inventory_e2e_placements->set_house( $aggr_inventory_e2e_placement, $aggr_inventory_e2e_image, 'https://example.test', 'Publisher example' ) ) {
	WP_CLI::error( 'The publisher example could not be configured.' );
}
$aggr_inventory_e2e_package = Plugin::instance()->container()->get( Package_Manager::class )->create(
	array(
		'name'            => 'E2E inventory package',
		'placement_ids'   => array( $aggr_inventory_e2e_placement ),
		'duration_days'   => 3,
		'custom_duration' => false,
		'price_cents'     => 12000,
		'currency'        => 'USD',
		'is_active'       => true,
		'is_default'      => false,
	)
);
if ( is_wp_error( $aggr_inventory_e2e_package ) ) {
	WP_CLI::error( 'The fixture package could not be configured.' );
}
$aggr_inventory_e2e_start = new DateTimeImmutable( '+10 days', new DateTimeZone( 'UTC' ) );
$aggr_inventory_e2e_from  = $aggr_inventory_e2e_start->format( 'Y-m-d' );
$aggr_inventory_e2e_to    = $aggr_inventory_e2e_start->modify( '+2 days' )->format( 'Y-m-d' );
if ( 0 === ( new Forecast_Repository() )->record(
	$aggr_inventory_e2e_placement,
	'page',
	$aggr_inventory_e2e_from,
	$aggr_inventory_e2e_to,
	array(
		'estimate'      => 123456789,
		'optimistic'    => 234567890,
		'confidence'    => 'high',
		'days_observed' => 30,
		'days_forecast' => 3,
	)
) ) {
	WP_CLI::error( 'The fixture forecast could not be recorded.' );
}
echo wp_json_encode(
	array(
		'package'   => $aggr_inventory_e2e_package,
		'placement' => $aggr_inventory_e2e_placement,
		'image'     => $aggr_inventory_e2e_image,
		'from'      => $aggr_inventory_e2e_from,
		'to'        => $aggr_inventory_e2e_to,
		'timezone'  => $aggr_inventory_e2e_timezone,
	)
);
