<?php
/**
 * One creative's destination, editable while review has not accepted it.
 *
 * A typo in a destination used to be unfixable: the only route was removing
 * the creative and uploading the file again, which drops the placement's
 * coverage in between for the sake of one character.
 *
 * Rendered inside a dialog, which is where the card's other one-field edits
 * live. The trigger is on the creative card; this is only the body.
 *
 * The server decides whether this may be edited at all — `Revision_Policy`
 * is the authority and `Creative_Manager::set_destination()` asks it. Hiding
 * the form for a frozen creative is presentation, not enforcement, and the
 * refusal stands whether or not this template renders anything.
 *
 * @var array<string, mixed> $aggr_creative One uploaded creative.
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 * @var string               $aggr_creative_error_for Which field owns the current error.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\REST\Api;

$aggr_destination_creative = (int) ( $aggr_creative['id'] ?? 0 );

if ( $aggr_destination_creative < 1 ) {
	return;
}

$aggr_destination_id    = 'aggr-destination-' . $aggr_destination_creative;
$aggr_destination_error = $aggr_destination_id === $aggr_creative_error_for;
?>
<form
	class="aggr-variant-destination"
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-aggr-save="<?php echo esc_attr( 'aggr-save-destination-' . $aggr_destination_creative ); ?>"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::DESTINATION_ACTION ); ?>">
	<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) $aggr_destination_creative ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
	<?php wp_nonce_field( Creative_Actions::destination_nonce_action( $aggr_destination_creative ) ); ?>

	<?php
	/*
	 * The campaign's own destination field, the same one: its checking, its
	 * tracking tags and the tidying that saves `example.com` as a URL. An ad's
	 * link is a link like any other, and the two asked for it differently for
	 * as long as there were two of them.
	 */
	$aggr_dest_field_id    = $aggr_destination_id;
	$aggr_dest_field_name  = 'click_url';
	$aggr_dest_field_value = (string) ( $aggr_creative['click_url'] ?? '' );
	$aggr_dest_field_label = __( 'Destination URL for this ad', 'aggressive-ads' );

	/*
	 * No stored result for an ad's own link yet — the campaign keeps its
	 * last check, an ad does not — so the chip starts at "Valid link" and
	 * says what the check answers from the moment one is run.
	 */
	$aggr_dest_field_check     = null;
	$aggr_dest_field_error     = $aggr_destination_error;
	$aggr_dest_field_used      = '';
	$aggr_dest_field_save      = '';
	$aggr_dest_field_check_url = add_query_arg(
		'creative',
		$aggr_destination_creative,
		rest_url( Api::NAMESPACE . '/campaigns/' . (int) $aggr_campaign['id'] . '/link-check' )
	);

	require AGGR_PLUGIN_DIR . 'templates/portal/partials/destination-field.php';
	?>

	<button class="aggr-button aggr-button--secondary" type="submit">
		<?php esc_html_e( 'Save destination', 'aggressive-ads' ); ?>
	</button>
</form>
