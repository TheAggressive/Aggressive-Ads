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
use Aggressive\Ads\Domain\Slot_Options;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;

/**
 * Reserved box + noscript house. Paid creatives arrive via fill.
 */
final class Placement_Slot implements Service {

	public const SHORTCODE = 'aggr_placement';
	public const BLOCK     = 'aggr/ad-slot';

	/**
	 * The name this block shipped under until 1.6.0.
	 *
	 * Kept registered, and out of the inserter, because the block name is
	 * serialized into content as `<!-- wp:aggr/placement … -->`. Every post,
	 * template and reusable block that already carries one would otherwise
	 * render as "This block contains unexpected or invalid content".
	 *
	 * Nothing rewrites anybody's `post_content`. A migration across posts,
	 * templates, revisions and widgets to save one registration is how a
	 * homepage gets eaten; an alias costs a few lines and no risk.
	 */
	public const LEGACY_BLOCK = 'aggr/placement';

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
		$atts = shortcode_atts( Slot_Options::shortcode_defaults(), is_array( $atts ) ? $atts : array() );

		return $this->markup( (string) $atts['slot'], false, Slot_Options::from_atts( $atts ) );
	}

	/**
	 * Dynamic block from dist/blocks/placement. Absent when the module is off
	 * so the inserter does not offer a slot that cannot fill.
	 */
	public function register_block(): void {
		if ( ! $this->fill->is_enabled() ) {
			return;
		}

		$block_dir = AGGR_PLUGIN_DIR . 'dist/blocks-interactivity/ad-slot';
		$args      = array(
			'render_callback' => array( $this, 'render_block' ),
		);

		if ( is_file( $block_dir . '/block.json' ) ) {
			register_block_type( $block_dir, $args );
			$this->register_legacy_alias();

			return;
		}

		register_block_type(
			self::BLOCK,
			array_merge(
				$args,
				array(
					'api_version' => '3',
					'title'       => __( 'Ad Slot', 'aggressive-ads' ),
					'description' => __( 'A reserved slot filled at request time, optionally rotating. Editors place a slot, never a campaign.', 'aggressive-ads' ),
					'category'    => 'widgets',
					'attributes'  => self::block_attributes(),
				)
			)
		);

		$this->register_legacy_alias();
	}

	/**
	 * Registers the pre-1.6.0 block name so existing content keeps rendering.
	 *
	 * Same render callback, same attributes, hidden from the inserter. An
	 * author editing an old post can convert it; one who never opens the post
	 * never notices, which is the point.
	 */
	private function register_legacy_alias(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( self::LEGACY_BLOCK ) ) {
			return;
		}

		register_block_type(
			self::LEGACY_BLOCK,
			array(
				'render_callback' => array( $this, 'render_block' ),
				'api_version'     => '3',
				'title'           => __( 'Ad Slot (legacy)', 'aggressive-ads' ),
				'category'        => 'widgets',
				'supports'        => array( 'inserter' => false ),
				'attributes'      => self::block_attributes(),
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

		return $this->markup( $slot, true, Slot_Options::from_block_attributes( $attributes ) );
	}

	/**
	 * The attributes every registration of this block declares.
	 *
	 * One list for the fallback registration and the pre-1.6.0 alias. They used
	 * to carry different ones, and the alias's were the complete set — so a
	 * `dist/` build without a `block.json` rendered new content with rotation
	 * silently dropped while the *legacy* name kept it.
	 *
	 * `src/blocks-interactivity/ad-slot/block.json` is still the source of
	 * truth — it is what a build is made from — and
	 * `PlacementSlotTest::test_every_registration_declares_the_same_attributes`
	 * holds every registration to it.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function block_attributes(): array {
		return array(
			'slot'              => array(
				'type'    => 'string',
				'default' => '',
			),
			'rotate'            => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'rotateSeconds'     => array(
				'type'    => 'number',
				'default' => Slot_Options::DEFAULT_ROTATE_SECONDS,
			),
			'collapseWhenEmpty' => array(
				'type'    => 'boolean',
				'default' => true,
			),
		);
	}

	/**
	 * Markup for one slot. Used by the PHP helper, shortcode, and block.
	 *
	 * Does not mint a fill token. A token in cached HTML is a replay.
	 *
	 * @param string            $slug     Placement post_name.
	 * @param bool              $as_block Whether to apply core block wrapper supports.
	 * @param Slot_Options|null $options  Per-slot settings; the defaults when absent.
	 */
	public function markup( string $slug, bool $as_block = false, ?Slot_Options $options = null ): string {
		$slug    = sanitize_title( $slug );
		$options = $options ?? Slot_Options::defaults();

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
			'class'               => $this->wrapper_classes( $placement_id, $options ),
			'data-aggr-slot'      => $slug,
			'data-aggr-fill'      => $fill,

			/*
			 * The Interactivity API directives. `data-wp-init` rather than a
			 * `DOMContentLoaded` listener, so a slot inside a block WordPress
			 * hydrates late still fills — the previous DOM script queried the
			 * document once and missed anything that arrived afterwards.
			 */
			'data-wp-interactive' => self::BLOCK,
			'data-wp-init'        => 'callbacks.fill',
		);

		/*
		 * Encoded directly rather than through `wp_interactivity_data_wp_context()`,
		 * which returns a whole `data-wp-context='…'` attribute string. This
		 * builds an attribute *array* that the wrapper helpers escape, so taking
		 * the helper's output and stripping the name and quotes back off it
		 * would be doing the same work twice and undoing half of it.
		 */
		$encoded = wp_json_encode( $options->to_context() );

		if ( is_string( $encoded ) ) {
			$extra['data-wp-context'] = $encoded;
		}

		if ( '' !== $style ) {
			$extra['style'] = $style;
		}

		$open = $as_block && function_exists( 'get_block_wrapper_attributes' )
			? '<div ' . get_block_wrapper_attributes( $extra ) . '>'
			: $this->plain_wrapper( $extra );

		$house = $this->noscript_house( $placement_id );

		$canvas  = '<div class="aggr-slot__canvas"';
		$canvas .= '' !== $canvas_style ? ' style="' . esc_attr( $canvas_style ) . '"' : '';
		$canvas .= '>' . $house . '</div>';

		return $this->noscript_collapse_rule( $house, $options ) . $open . $canvas . '</div>';
	}

	/**
	 * The class the emitted stylesheet rule hides.
	 *
	 * Only ever meaningful inside `<noscript>`. A rule on this class in the
	 * ordinary stylesheet would hide the slot from everybody, because the class
	 * is server-rendered and the server does not know whether the visitor has
	 * JavaScript. Removing it on hydration instead would show a box and then
	 * take it away, which is the layout shift the reserved box exists to avoid.
	 */
	public const NEEDS_JS_CLASS = 'aggr-slot--needs-js';

	/**
	 * The wrapper's classes, including the marker for a slot no-JS cannot fill.
	 *
	 * @param int          $placement_id Placement post id.
	 * @param Slot_Options $options      Per-slot settings.
	 */
	private function wrapper_classes( int $placement_id, Slot_Options $options ): string {
		if ( '' === $this->noscript_house( $placement_id ) && $options->collapse_when_empty ) {
			return 'aggr-slot ' . self::NEEDS_JS_CLASS;
		}

		return 'aggr-slot';
	}

	/**
	 * The rule that takes an unfillable slot off the page without JavaScript.
	 *
	 * **The server can answer this one, and cache-safely.** It cannot know
	 * whether a paid ad will fill a slot — that needs a per-request candidate
	 * query, and a cached page would bake the answer in — but whether a *no-JS*
	 * visitor will see anything depends only on the house policy and whether a
	 * house creative exists, which `noscript_house()` already resolved from
	 * placement configuration. Same inputs as the house markup beside it, so
	 * the same page cache holds both correctly.
	 *
	 * A slot that asked to keep its space keeps it here too. Reserving the box
	 * is a layout decision, and a visitor without JavaScript is still looking at
	 * the layout.
	 *
	 * **Emitted per slot rather than once per page**, which is the less elegant
	 * of the two and the only correct one. Emitting once meant a flag on this
	 * service, and the service outlives the request under any long-running SAPI
	 * — FrankenPHP, RoadRunner, a pooled worker — where "once per request"
	 * quietly becomes "once per process" and every page after the first renders
	 * the marker with no rule to act on it. The cost of being right is seventy
	 * bytes per unfillable slot, and identical rules cost a browser nothing.
	 *
	 * **`!important`, which is otherwise a smell and here is the requirement.**
	 * A sized slot carries `display:grid` as an inline style — the wrapper has
	 * to, because the reserved box's dimensions come from the placement rather
	 * than from a stylesheet — and an inline declaration beats every ordinary
	 * rule no matter how specific. Without this the marker was applied, the rule
	 * was parsed, and the box stayed exactly where it was. Only the browser test
	 * saw it; every server-side assertion was about markup that was already
	 * correct.
	 *
	 * @param string       $house   The noscript house markup, empty when none.
	 * @param Slot_Options $options Per-slot settings.
	 */
	private function noscript_collapse_rule( string $house, Slot_Options $options ): string {
		if ( '' !== $house || ! $options->collapse_when_empty ) {
			return '';
		}

		return '<noscript><style>.' . self::NEEDS_JS_CLASS . '{display:none!important}</style></noscript>';
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
	 * **Emits whatever it is given.** It used to name four attributes by hand,
	 * which was correct when it was written and stopped being correct the day
	 * the Interactivity directives joined the array: `data-wp-interactive`,
	 * `data-wp-init` and `data-wp-context` were built for every slot and then
	 * dropped on the way out of this method, so a slot placed with the
	 * shortcode or with `aggr_placement()` rendered a reserved box that no
	 * store ever hydrated and no fill ever reached. It looked like a slot with
	 * no inventory, which is a state the plugin has on purpose, so nothing
	 * about it read as broken.
	 *
	 * A list of attribute names in a second place is a list that goes stale
	 * without a build failing, so there is not one any more.
	 *
	 * @param array<string, string> $extra Slot attributes.
	 */
	private function plain_wrapper( array $extra ): string {
		$html = '<div';

		foreach ( $extra as $name => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$escaped = 'data-aggr-fill' === $name ? esc_url( $value ) : esc_attr( $value );

			$html .= ' ' . esc_attr( $name ) . '="' . $escaped . '"';
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
