<?php
/**
 * Staff settings: modules and brand.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\REST\Creative_File_Controller;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Reviewer_Access;

/**
 * The first surface that uses aggr_manage_settings.
 */
final class Settings_Screen implements Service {

	public const MENU_SLUG = 'aggr-settings';

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings document.
	 * @param Reviewer_Access $access   Per-user review access.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Reviewer_Access $access
	) {
	}

	/**
	 * Attaches the menu.
	 *
	 * There is no admin-post handler any more. Every write this screen makes —
	 * the settings document and the review roster alike — goes to
	 * REST\Settings_Controller, so there is one authenticated path to each
	 * rather than two that have to be kept in agreement.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * The screen's own hook suffix, captured at registration.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Registers Settings under the Advertising parent.
	 */
	public function register_menu(): void {
		$hook = add_submenu_page(
			Menu::PARENT_SLUG,
			__( 'Settings', 'aggressive-ads' ),
			__( 'Settings', 'aggressive-ads' ),
			Capabilities::MANAGE_SETTINGS,
			self::MENU_SLUG,
			array( $this, 'render' )
		);

		$this->hook_suffix = is_string( $hook ) ? $hook : '';
	}

	/**
	 * Loads the screen's bundle, on this screen only.
	 *
	 * Enqueuing belongs here rather than inside the render callback. A callback
	 * runs after the document head has already been sent, so a stylesheet asked
	 * for there only survives because core prints late styles in the footer —
	 * which works, and flashes an unstyled screen while it does. This hook is
	 * also the thing an integration test can see; enqueuing during render is
	 * invisible to `has_action`, which is how the regression that prompted this
	 * comment went unnoticed.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$asset = AGGR_PLUGIN_DIR . 'dist/admin/settings.asset.php';

		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta = require $asset;

		wp_enqueue_script(
			'aggr-settings',
			AGGR_PLUGIN_URL . 'dist/admin/settings.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION,
			true
		);

		// The component library brings its own stylesheet, and it is registered
		// by core rather than shipped here.
		wp_enqueue_style( 'wp-components' );
	}

	/*
	 * No stylesheet is enqueued here.
	 *
	 * This screen is native WordPress admin markup — form-table, wp-list-table,
	 * notice, button — so core already styles every part of it. Loading the
	 * plugin's own design system would only give it something to fight, and
	 * would hand us a second set of visuals to maintain across WordPress
	 * releases for no gain.
	 */

	/**
	 * Renders modules and brand.
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this page.', 'aggressive-ads' ),
				'',
				array( 'response' => 403 )
			);
		}

		$this->render_screen( $this->settings->get() );
	}

	/**
	 * Prints the React mount point and hands it the current document.
	 *
	 * Data travels in a data attribute rather than through wp_localize_script:
	 * it is a small, screen-specific payload, and an attribute keeps it beside
	 * the element that consumes it instead of on a global.
	 *
	 * @param array<string, mixed> $settings Settings document.
	 * @return void
	 */
	private function render_screen( array $settings ): void {
		$asset = AGGR_PLUGIN_DIR . 'dist/admin/settings.asset.php';

		/*
		 * A missing build is the one failure that would render a blank page with
		 * no explanation, and it is the likeliest one: `dist/` is not committed,
		 * so a checkout without `pnpm build` has no screen at all. Saying so
		 * beats an empty wrap the reader has to diagnose from the console.
		 */
		if ( ! is_file( $asset ) ) {
			printf(
				'<div class="wrap"><h1>%1$s</h1><div class="notice notice-error"><p>%2$s</p></div></div>',
				esc_html__( 'Advertising Settings', 'aggressive-ads' ),
				esc_html__( 'The settings screen has not been built. Run “pnpm build” and reload.', 'aggressive-ads' )
			);

			return;
		}

		$toggle = static function ( string $key, string $label, bool $on, string $help = '' ): array {
			return array(
				'key'     => $key,
				'label'   => $label,
				'help'    => $help,
				'enabled' => $on,
			);
		};

		$payload = array(
			'modules'   => array(
				$toggle( Settings_Schema::MODULE_PUBLIC_SIGNUP, __( 'Public signup', 'aggressive-ads' ), ! empty( $settings['modules'][ Settings_Schema::MODULE_PUBLIC_SIGNUP ] ), __( 'WordPress “Anyone can register” must also be on.', 'aggressive-ads' ) ),
				$toggle( Settings_Schema::MODULE_BILLING, __( 'Billing UI', 'aggressive-ads' ), ! empty( $settings['modules'][ Settings_Schema::MODULE_BILLING ] ) ),
				$toggle( Settings_Schema::MODULE_REPORTING, __( 'Reporting', 'aggressive-ads' ), ! empty( $settings['modules'][ Settings_Schema::MODULE_REPORTING ] ), __( 'Native fill is always recording; this switch only shows the numbers.', 'aggressive-ads' ) ),
			),
			'liveEdits' => array(
				$toggle( Settings_Schema::EDIT_TITLE, __( 'Campaign name', 'aggressive-ads' ), ! empty( $settings['live_edits'][ Settings_Schema::EDIT_TITLE ] ) ),
				$toggle( Settings_Schema::EDIT_NOTES, __( 'Advertiser notes', 'aggressive-ads' ), ! empty( $settings['live_edits'][ Settings_Schema::EDIT_NOTES ] ) ),
				$toggle( Settings_Schema::EDIT_SCHEDULE, __( 'Start and end dates', 'aggressive-ads' ), ! empty( $settings['live_edits'][ Settings_Schema::EDIT_SCHEDULE ] ), __( 'A start date that has already passed cannot be moved.', 'aggressive-ads' ) ),
				$toggle( Settings_Schema::EDIT_DESTINATION, __( 'Destination URL', 'aggressive-ads' ), ! empty( $settings['live_edits'][ Settings_Schema::EDIT_DESTINATION ] ), __( 'Repoints the click without replacing the artwork.', 'aggressive-ads' ) ),
				$toggle( Settings_Schema::EDIT_PLACEMENTS, __( 'Placements (structural)', 'aggressive-ads' ), ! empty( $settings['live_edits'][ Settings_Schema::EDIT_PLACEMENTS ] ), __( 'Stops the campaign serving until a correctly sized creative is uploaded and reviewed.', 'aggressive-ads' ) ),
			),
			'brand'     => array(
				'productName'  => (string) $settings['brand']['product_name'],
				'tagline'      => (string) $settings['brand']['tagline'],
				'supportEmail' => (string) ( $settings['brand']['support_email'] ?? '' ),
				'logoUrl'      => (string) $settings['brand']['logo_url'],
				'colours'      => array(
					array(
						'key'   => 'accent',
						'label' => __( 'Accent', 'aggressive-ads' ),
						'value' => (string) $settings['brand']['accent'],
					),
					array(
						'key'   => 'accent_strong',
						'label' => __( 'Accent (text on buttons)', 'aggressive-ads' ),
						'value' => (string) $settings['brand']['accent_strong'],
					),
					array(
						'key'   => 'canvas',
						'label' => __( 'Canvas', 'aggressive-ads' ),
						'value' => (string) $settings['brand']['canvas'],
					),
					array(
						'key'   => 'surface',
						'label' => __( 'Surface', 'aggressive-ads' ),
						'value' => (string) $settings['brand']['surface'],
					),
					array(
						'key'   => 'text',
						'label' => __( 'Text', 'aggressive-ads' ),
						'value' => (string) $settings['brand']['text'],
					),
				),
			),
			'delivery'  => array(
				'fillTtl'      => (int) $settings['delivery']['fill_ttl'],
				'housePolicy'  => (string) $settings['delivery']['house_policy'],
				'houseOptions' => array(
					array(
						'value' => Settings_Schema::HOUSE_WHEN_EMPTY,
						'label' => __( 'When no paid creative is live', 'aggressive-ads' ),
					),
					array(
						'value' => Settings_Schema::HOUSE_NEVER,
						'label' => __( 'Never', 'aggressive-ads' ),
					),
				),
			),
			'tracking'  => array( 'retentionDays' => (int) $settings['tracking']['retention_days'] ),
			'roster'    => $this->access->roster(),

			/*
			 * Strings hydrated from PHP rather than translated in JavaScript.
			 *
			 * `wp i18n make-pot` does not parse .tsx, so __() calls in the
			 * component compile and run correctly while producing no catalog
			 * entries at all — the code looks right and a translator never sees
			 * the string. Hydrating server-side is the same convention the
			 * portal's Interactivity stores already use, for a different reason
			 * but with the same result: every user-visible string is extracted
			 * from PHP, where the tooling can see it. See docs/i18n.md.
			 */

			/*
			 * The screen saves as it is changed, which a form POST cannot do
			 * without reloading the page, so writes go to a REST route instead.
			 *
			 * That route is deliberately thin. It shapes the body with
			 * Settings_Input and calls Settings::save(), which is the same pair
			 * of calls handle_save() makes — so the WCAG contrast gate in
			 * Settings_Schema::validate() is reached identically either way, and
			 * there is no second implementation of it to keep in step.
			 *
			 * apiFetch signs the request with core's REST nonce; there is no
			 * plugin nonce here because there is no plugin-owned handshake.
			 */
			'restPath'  => '/' . Creative_File_Controller::NAMESPACE . '/settings',
			'i18n'      => array(
				'add'             => __( 'Give access', 'aggressive-ads' ),
				'addReviewer'     => __( 'Give someone review access', 'aggressive-ads' ),
				'addReviewerHint' => __( 'Username or email address. Advertiser accounts cannot be given review access — reviewing means reading every organization’s unpublished work.', 'aggressive-ads' ),
				'remove'          => __( 'Remove', 'aggressive-ads' ),
				'statusPending'   => __( 'Not saved yet…', 'aggressive-ads' ),
				'statusSaving'    => __( 'Saving…', 'aggressive-ads' ),
				'statusSaved'     => __( 'Saved.', 'aggressive-ads' ),
				'statusError'     => __( 'Not saved.', 'aggressive-ads' ),
				'saveFailed'      => __( 'Those settings could not be saved.', 'aggressive-ads' ),
				'retry'           => __( 'Try again', 'aggressive-ads' ),
				'modules'         => __( 'Modules', 'aggressive-ads' ),
				'modulesHelp'     => __( 'Off means the route, menu, or field is not registered.', 'aggressive-ads' ),
				'liveEdits'       => __( 'Changes to running campaigns', 'aggressive-ads' ),
				'liveEditsHelp'   => __( 'What an advertiser may ask to change on a campaign that is already scheduled, live or paused. Every change still waits for staff approval.', 'aggressive-ads' ),
				'brand'           => __( 'Brand', 'aggressive-ads' ),
				'brandHelp'       => __( 'Advertiser-facing name and colours. The code prefix never changes.', 'aggressive-ads' ),
				'productName'     => __( 'Product name', 'aggressive-ads' ),
				'tagline'         => __( 'Tagline', 'aggressive-ads' ),
				'taglineHelp'     => __( 'Shown under the name on the portal rail and sign-in screens.', 'aggressive-ads' ),
				'supportEmail'    => __( 'Support email', 'aggressive-ads' ),
				'supportHelp'     => __( 'Shown to advertisers on the Help screen. Empty uses the site administration email.', 'aggressive-ads' ),
				'logoUrl'         => __( 'Logo URL', 'aggressive-ads' ),
				'delivery'        => __( 'Delivery and tracking', 'aggressive-ads' ),
				'deliveryHelp'    => __( 'Native fill cache, house-ad policy and event retention.', 'aggressive-ads' ),
				'fillTtl'         => __( 'Fill cache TTL (seconds)', 'aggressive-ads' ),
				'houseAds'        => __( 'House ads', 'aggressive-ads' ),
				'retentionDays'   => __( 'Event retention (days)', 'aggressive-ads' ),
				'access'          => __( 'Access', 'aggressive-ads' ),
				'accessHelp'      => __( 'Who can review advertising campaigns. Access is added to the person’s existing account, so their current role is unchanged.', 'aggressive-ads' ),
				'accessEmpty'     => __( 'Nobody has been given review access yet.', 'aggressive-ads' ),
				'alwaysAdmin'     => __( 'Always, as an administrator', 'aggressive-ads' ),
			),
		);

		/*
		 * This screen requires JavaScript, which is a deliberate narrowing.
		 *
		 * It saves as it is changed, and there is no form to fall back to: the
		 * server-rendered template it replaced is gone rather than kept as a
		 * second implementation of the same writes. wp-admin already has screens
		 * that need scripting, so the cost is a message rather than a dead end —
		 * but it is a real cost, and it is stated here rather than discovered.
		 */
		printf(
			'<div class="wrap aggr-admin"><h1>%1$s</h1><noscript><div class="notice notice-error"><p>%2$s</p></div></noscript><div id="aggr-settings-root" data-aggr-settings="%3$s"></div></div>',
			esc_html__( 'Advertising Settings', 'aggressive-ads' ),
			esc_html__( 'Advertising settings need JavaScript enabled.', 'aggressive-ads' ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);
	}

	/**
	 * Screen URL.
	 */
	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}
}
