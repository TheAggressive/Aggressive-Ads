<?php
/**
 * REST upload request fixture.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use WP_REST_Request;

/**
 * Generates exact-size creative uploads for REST route scenarios.
 */
trait RestUploadRequestFixture {

	/**
	 * Builds an upload request and remembers its temporary file.
	 *
	 * @param int $campaign_id Campaign to upload to.
	 * @param int $placement_id Placement to fill.
	 * @return WP_REST_Request
	 */
	private function upload_request( int $campaign_id, int $placement_id ): WP_REST_Request {
		$image = imagecreatetruecolor( 728, 90 );
		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();
		$temp  = wp_tempnam( 'aggr-rest-upload' );
		file_put_contents( $temp, $bytes );
		$this->temporary[] = $temp;

		$request = new WP_REST_Request( 'POST', '/aggr/v1/campaigns/' . $campaign_id . '/creatives' );
		$request->set_body_params(
			array(
				'placement_id' => $placement_id,
				'click_url'    => 'https://example.com/tickets',
				'alt_text'     => 'Spring season poster',
			)
		);
		$request->set_file_params(
			array(
				'file' => array(
					'name'     => 'poster.png',
					'tmp_name' => $temp,
					'error'    => UPLOAD_ERR_OK,
					'size'     => strlen( $bytes ),
				),
			)
		);

		return $request;
	}
}
