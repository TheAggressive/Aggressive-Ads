<?php
/**
 * Front-of-site placement slot.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;

/**
 * Reserved box + noscript house. Paid creatives arrive via fill.
 */
final class Placement_Slot implements Service {

	public const SHORTCODE = 'aggr_placement';
	public const BLOCK     = 'aggr/placement';

	/**
	 * Constructor.
	 *
	 * @param Fill_Service         $fill       Module gate.
	 * @param Placement_Repository $placements Size and house meta.
	 */
	public function __construct(
		private readonly Fill_Service $fill,
		private readonly Placement_Repository $placements
	) {
	}

	/**
	 * Registers the public surfaces. Callbacks no-op when the module is off.
	 */
	public function init(): void {
		require_once AGGR_PLUGIN_DIR . 'inc/Workflow/placement-slot-function.php';

		add_shortcode( self::SHORTCODE, array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string, string>|string $atts Shortcode attributes.
	 */
	public function shortcode( array|string $atts ): string {
		$atts = shortcode_atts( array( 'slot' => '' ), is_array( $atts ) ? $atts : array() );

		return $this->markup( (string) $atts['slot'] );
	}

	/**
	 * Dynamic block from dist/blocks/placement. Absent when the module is off
	 * so the inserter does not offer a slot that cannot fill.
	 */
	public function register_block(): void {
		if ( ! $this->fill->is_enabled() ) {
			return;
		}

		$block_dir = AGGR_PLUGIN_DIR . 'dist/blocks/placement';
		$args      = array(
			'render_callback' => array( $this, 'render_block' ),
		);

		if ( is_file( $block_dir . '/block.json' ) ) {
			register_block_type( $block_dir, $args );

			return;
		}

		register_block_type(
			self::BLOCK,
			array_merge(
				$args,
				array(
					'api_version' => '3',
					'title'       => __( 'Ad placement', 'aggressive-ads' ),
					'description' => __( 'A reserved slot filled at request time. Editors place a slot, never a campaign.', 'aggressive-ads' ),
					'category'    => 'widgets',
					'attributes'  => array(
						'slot' => array(
							'type'    => 'string',
							'default' => '',
						),
					),
				)
			)
		);
	}

	/**
	 * Renders a reserved slot from block attributes.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 */
	public function render_block( array $attributes ): string {
		$slot = isset( $attributes['slot'] ) && is_string( $attributes['slot'] ) ? $attributes['slot'] : '';

		return $this->markup( $slot, true );
	}

	/**
	 * Markup for one slot. Used by the PHP helper, shortcode, and block.
	 *
	 * Does not mint a fill token. A token in cached HTML is a replay.
	 *
	 * @param string $slug     Placement post_name.
	 * @param bool   $as_block Whether to apply core block wrapper supports.
	 */
	public function markup( string $slug, bool $as_block = false ): string {
		$slug = sanitize_title( $slug );

		if ( '' === $slug || ! $this->fill->is_enabled() ) {
			return '';
		}

		$placement_id = $this->placements->id_by_slug( $slug );

		if ( $placement_id <= 0 || ! $this->placements->is_active( $placement_id ) ) {
			return '';
		}

		$size   = $this->placements->size( $placement_id );
		$dims   = explode( 'x', $size );
		$width  = isset( $dims[0] ) ? (int) $dims[0] : 0;
		$height = isset( $dims[1] ) ? (int) $dims[1] : 0;
		$fill   = rest_url( Creative_File_Controller::NAMESPACE . '/fill/' . rawurlencode( $slug ) );

		$this->enqueue_view();

		$style        = '';
		$canvas_style = '';

		if ( $width > 0 && $height > 0 ) {
			/*
			 * Block padding belongs to the outer shell. The inner canvas owns the
			 * declared ad dimensions, so border-box theme CSS cannot subtract the
			 * padding from a 728x90 creative. max-width keeps smaller viewports
			 * proportional instead of creating horizontal page overflow.
			 */
			$style        = 'display:grid;width:fit-content;max-width:100%;box-sizing:border-box;';
			$canvas_style = sprintf( 'width:%dpx;max-width:100%%;aspect-ratio:%d/%d;', $width, $width, $height );
		}

		$extra = array(
			'class'          => 'aggr-slot',
			'data-aggr-slot' => $slug,
			'data-aggr-fill' => $fill,
		);

		if ( '' !== $style ) {
			$extra['style'] = $style;
		}

		$open = $as_block && function_exists( 'get_block_wrapper_attributes' )
			? '<div ' . get_block_wrapper_attributes( $extra ) . '>'
			: $this->plain_wrapper( $extra );

		$canvas  = '<div class="aggr-slot__canvas"';
		$canvas .= '' !== $canvas_style ? ' style="' . esc_attr( $canvas_style ) . '"' : '';
		$canvas .= '>' . $this->noscript_house( $placement_id ) . '</div>';

		return $open . $canvas . '</div>';
	}

	/**
	 * Noscript house uses the destination URL, never a minted hop token.
	 *
	 * @param int $placement_id Placement post id.
	 */
	private function noscript_house( int $placement_id ): string {
		if ( Settings_Schema::HOUSE_WHEN_EMPTY !== $this->fill->house_policy() ) {
			return '';
		}

		if ( ! $this->fill->house_is_servable( $placement_id ) ) {
			return '';
		}

		$attachment_id = $this->placements->house_attachment_id( $placement_id );
		$image         = wp_get_attachment_image_url( $attachment_id, 'full' );
		$click         = $this->placements->house_click_url( $placement_id );
		$alt           = $this->placements->house_alt( $placement_id );

		if ( ! is_string( $image ) || '' === $image ) {
			return '';
		}

		return '<noscript><a href="' . esc_url( $click ) . '"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $alt ) . '"></a></noscript>';
	}

	/**
	 * Shortcode and PHP helper wrapper. Block supports do not apply here.
	 *
	 * @param array<string, string> $extra Slot attributes.
	 */
	private function plain_wrapper( array $extra ): string {
		$html  = '<div class="' . esc_attr( $extra['class'] ?? '' ) . '"';
		$html .= ' data-aggr-slot="' . esc_attr( $extra['data-aggr-slot'] ?? '' ) . '"';
		$html .= ' data-aggr-fill="' . esc_url( $extra['data-aggr-fill'] ?? '' ) . '"';

		if ( isset( $extra['style'] ) && '' !== $extra['style'] ) {
			$html .= ' style="' . esc_attr( $extra['style'] ) . '"';
		}

		return $html . '>';
	}

	/**
	 * Enqueues the fill script module when a slot is rendered.
	 */
	private function enqueue_view(): void {
		if ( ! function_exists( 'generate_block_asset_handle' ) ) {
			return;
		}

		$handle = generate_block_asset_handle( self::BLOCK, 'viewScriptModule' );

		if ( function_exists( 'wp_enqueue_script_module' ) && is_string( $handle ) && '' !== $handle ) {
			wp_enqueue_script_module( $handle );
		}

		$style_handle = generate_block_asset_handle( self::BLOCK, 'style' );

		if ( is_string( $style_handle ) && '' !== $style_handle && wp_style_is( $style_handle, 'registered' ) ) {
			wp_enqueue_style( $style_handle );
		}
	}
}
