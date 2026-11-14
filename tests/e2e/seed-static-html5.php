<?php
/**
 * Empty campaign plus actual ZIP input; approval and serving facts are written by the tested UI.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Acting_As;
use Aggressive\Ads\Repository\Campaign_Repository;

ob_start();
require __DIR__ . '/seed-responsive-images.php';
$aggr_fixture = json_decode( (string) ob_get_clean(), true, 8, JSON_THROW_ON_ERROR );
$aggr_staff   = get_user_by( 'login', 'admin' );
if ( ! $aggr_staff instanceof WP_User ) {
	throw new RuntimeException( 'Static HTML5 browser reviewer is missing.' );
}
wp_set_current_user( $aggr_staff->ID );
if ( ! Plugin::instance()->container()->get( Acting_As::class )->enter( ( new Campaign_Repository() )->org_id( $aggr_fixture['campaign'] ) ) ) {
	throw new RuntimeException( 'Staff browser acting context could not be established.' );
}
foreach ( array(
	'archive' => '<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="stylesheet" href="local.css"></head><body><div class="banner">Reviewed static HTML5</div><p>&lt;/iframe&gt;&lt;script&gt;parent.p18Escaped=true&lt;/script&gt;</p></body></html>',
	'refused' => '<div>Blocked executable creative</div><script>new RTCPeerConnection()</script>',
) as $aggr_key => $aggr_html ) {
	$aggr_path = wp_tempnam( 'aggr-static-input' );
	try {
		$aggr_zip = new ZipArchive();
		if ( true !== $aggr_zip->open( $aggr_path, ZipArchive::CREATE | ZipArchive::OVERWRITE )
			|| ! $aggr_zip->addFromString( 'index.html', $aggr_html )
			|| ! $aggr_zip->addFromString( 'local.css', '.banner{width:100%;height:100%;background-color:navy;color:white;display:flex;align-items:center;justify-content:center;font-size:24px}' )
			|| ! $aggr_zip->close() ) {
			throw new RuntimeException( 'Static HTML5 input ZIP could not be built.' );
		}
		$aggr_bytes = file_get_contents( $aggr_path );
		if ( false === $aggr_bytes ) {
			throw new RuntimeException( 'Static HTML5 input ZIP could not be read.' );
		}
		$aggr_fixture[ $aggr_key ] = base64_encode( $aggr_bytes );
	} finally {
		wp_delete_file( $aggr_path );
	}
}
echo wp_json_encode( $aggr_fixture );
