<?php
/**
 * The frame every Advertising screen is drawn inside.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

/**
 * Prints one page header and one mount point, so every staff screen opens the same way.
 *
 * Seven screens each carried their own copy of the same `printf`: a `.wrap`, a
 * bare `<h1>`, a `<noscript>` notice and the React root. None of them said what
 * the screen was for, and none printed `wp-header-end` — so core's `common.js`,
 * which moves every admin notice to sit after that marker or else after the
 * first heading, had nothing to aim at but the `<h1>` itself. With a sentence
 * under the title, a notice would have landed between the two.
 *
 * Static for the reason `Shared_Assets` is: this renders markup from its
 * arguments and holds nothing, so an instance in the container would be a
 * collaborator every screen has to be handed for no gain.
 *
 * **Presentation only.** Every caller checks its own capability before it gets
 * here, and nothing here reads a request or decides who may see what. A screen
 * that moves its capability check below a call to this class has changed its
 * authorization, not its layout.
 *
 * The Review screen is not a caller yet: its header is rendered by React and
 * changes with the view (queue or campaign). Moving it is the review slice's
 * decision, not this one's. See docs/admin-ui.md.
 */
final class Screen_Shell {

	/**
	 * Opens the screen: the wrap, its header, and the marker notices go under.
	 *
	 * The caller prints the body and then `close()`. Used directly only by a
	 * screen that renders its body in PHP; a React screen calls `mount()`.
	 *
	 * @param string $title   Page title, already translated.
	 * @param string $purpose One sentence saying what the screen is for, already translated. Empty for none.
	 * @param string $section    The group the screen belongs to, shown above the title, already translated. Empty for none.
	 * @param string $actions_id Id for an empty actions slot beside the title, which a React screen fills. Empty for none.
	 * @return void
	 */
	public static function open( string $title, string $purpose = '', string $section = '', string $actions_id = '' ): void {
		/*
		 * A div, not a header. WordPress wraps every admin page in
		 * `#wpbody[role=main]`, and a header there is read as a second banner
		 * nested inside main — axe flags it on every Advertising screen. This
		 * is the page's title block, not the site's banner.
		 */
		echo '<div class="wrap aggr-admin"><div class="aggr-admin-head"><div class="aggr-admin-head__text">';

		/*
		 * The eyebrow is the portal's page-head pattern: a small monospaced
		 * label naming where you are, above the title. Here it names the group —
		 * Inventory, Advertisers, Measurement — which the flat sidebar does not.
		 *
		 * The mark is decorative and hidden from assistive technology. It is
		 * drawn by CSS from the same SVG the sidebar icon is, so there is one
		 * copy of the shape.
		 */
		if ( '' !== $section ) {
			printf(
				'<p class="aggr-admin-head__eyebrow"><span class="aggr-admin-head__mark" aria-hidden="true"></span>%s</p>',
				esc_html( $section )
			);
		}

		printf( '<h1 class="aggr-admin-head__title">%s</h1>', esc_html( $title ) );

		if ( '' !== $purpose ) {
			printf( '<p class="aggr-admin-head__purpose">%s</p>', esc_html( $purpose ) );
		}

		echo '</div>';

		/*
		 * The screen's one primary action, beside its title, when the screen
		 * is not a list (a list puts it in the DataViews header instead). Empty
		 * here and filled by the screen's bundle through a portal, so the
		 * header stays PHP's and the action stays the bundle's.
		 */
		if ( '' !== $actions_id ) {
			printf( '<div class="aggr-admin-head__actions" id="%s"></div>', esc_attr( $actions_id ) );
		}

		// Core moves admin notices to sit directly after this element.
		echo '</div><hr class="wp-header-end">';
	}

	/**
	 * The group names shown above a screen's title.
	 *
	 * One place, so two screens in the same group cannot spell it two ways,
	 * and with a context, because each is a single word a translator meets out
	 * of any sentence — "Measurement" as a noun for a set of screens, not an act.
	 *
	 * @param string $key One of `campaigns`, `inventory`, `advertisers`, `measurement`, `setup`.
	 * @return string
	 */
	public static function section( string $key ): string {
		return match ( $key ) {
			'campaigns'   => _x( 'Campaigns', 'admin screen group', 'aggressive-ads' ),
			'inventory'   => _x( 'Inventory', 'admin screen group', 'aggressive-ads' ),
			'advertisers' => _x( 'Advertisers', 'admin screen group', 'aggressive-ads' ),
			'measurement' => _x( 'Measurement', 'admin screen group', 'aggressive-ads' ),
			'setup'       => _x( 'Setup', 'admin screen group', 'aggressive-ads' ),
			default       => '',
		};
	}

	/**
	 * Closes what `open()` opened.
	 *
	 * @return void
	 */
	public static function close(): void {
		echo '</div>';
	}

	/**
	 * A complete React screen: header, the no-script notice and the mount point.
	 *
	 * The payload travels as a JSON attribute on the root, which is how every
	 * screen already hydrated; the attribute name is each bundle's own, so it is
	 * passed rather than derived.
	 *
	 * @param string               $title     Page title, already translated.
	 * @param string               $purpose   One sentence saying what the screen is for, already translated.
	 * @param string               $root_id   Element id the bundle mounts on.
	 * @param string               $attribute Data attribute the bundle reads its payload from.
	 * @param array<string, mixed> $payload   Bootstrap data.
	 * @param string               $noscript  What to say when scripting is off, already translated.
	 * @param string               $section   The group the screen belongs to, already translated.
	 * @param bool                 $actions   Whether to leave an actions slot in the header, as `{$root_id}-actions`.
	 * @return void
	 */
	public static function mount( string $title, string $purpose, string $root_id, string $attribute, array $payload, string $noscript, string $section = '', bool $actions = false ): void {
		self::open( $title, $purpose, $section, $actions ? $root_id . '-actions' : '' );

		printf(
			'<noscript><div class="notice notice-error"><p>%1$s</p></div></noscript><div id="%2$s" %3$s="%4$s"></div>',
			esc_html( $noscript ),
			esc_attr( $root_id ),
			esc_attr( $attribute ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);

		self::close();
	}

	/**
	 * The screen when its bundle has not been built.
	 *
	 * `dist/` is not committed, so a checkout without `pnpm build` has no screen
	 * at all. Saying so beats an empty wrap the reader has to diagnose from the
	 * console.
	 *
	 * @param string $title   Page title, already translated.
	 * @param string $message What to do about it, already translated.
	 * @param string $section The group the screen belongs to, already translated.
	 * @return void
	 */
	public static function unbuilt( string $title, string $message, string $section = '' ): void {
		self::open( $title, '', $section );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		self::close();
	}
}
