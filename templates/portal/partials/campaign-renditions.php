<?php
/**
 * Optional responsive raster uploads shared by upload and replacement forms.
 *
 * @var int $aggr_rendition_placement Authorized form's placement.
 * @var string $aggr_rendition_key Unique form identity.
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Placement_Repository;

if ( $aggr_rendition_placement < 1 ) {
	return;
}

$aggr_rendition_repository = new Placement_Repository();
$aggr_rendition_sizes      = array_unique( array_merge( array( $aggr_rendition_repository->size( $aggr_rendition_placement ) ), array_values( $aggr_rendition_repository->size_map( $aggr_rendition_placement )->breakpoints() ) ) );

if ( count( $aggr_rendition_sizes ) < 2 ) {
	return;
}
?>
<div class="aggr-field">
	<label for="aggr-renditions-<?php echo esc_attr( $aggr_rendition_key ); ?>"><?php esc_html_e( 'Responsive renditions (optional)', 'aggressive-ads' ); ?></label>
	<p class="aggr-hint" id="aggr-renditions-hint-<?php echo esc_attr( $aggr_rendition_key ); ?>">
		<?php
		printf(
			/* translators: %s: supported image sizes, e.g. 320x50, 728x90. */
			esc_html__( 'Add up to five different sizes from: %s. Include the base image above. Each file must meet the placement limit; all images together must be at most 2 MB. Review covers the whole set.', 'aggressive-ads' ),
			esc_html( implode( ', ', $aggr_rendition_sizes ) )
		);
		?>
	</p>
	<input id="aggr-renditions-<?php echo esc_attr( $aggr_rendition_key ); ?>" name="renditions[]" type="file" multiple accept="<?php echo esc_attr( Upload_Rules::accept_attribute() ); ?>" aria-describedby="aggr-renditions-hint-<?php echo esc_attr( $aggr_rendition_key ); ?>">
</div>
