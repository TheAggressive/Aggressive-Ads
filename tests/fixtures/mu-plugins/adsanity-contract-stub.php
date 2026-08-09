<?php
/**
 * Plugin Name: AdSanity Contract Stub
 * Description: Registers the parts of AdSanity 2.0.1 this plugin integrates with, for environments that cannot install the real thing.
 *
 * AdSanity is licensed and cannot be fetched in CI, so the publisher's tests
 * run against this instead. It reproduces the registration arguments, the
 * taxonomy, the EOL sentinel and the size filter — nothing else.
 *
 * **This is not a simulation of AdSanity.** It is a statement of what we
 * depend on, and `tests/php/Contract/AdsanityContractTest.php` asserts the real
 * plugin still matches it, field for field, whenever the real plugin is
 * present. CI does not test the real integration; that test is what makes the
 * gap detectable rather than invisible. See
 * docs/adr/0015-adsanity-contract-stub-for-ci.md.
 *
 * Every fact here is taken from the installed source, with the file and line
 * recorded in docs/adsanity-integration.md.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

// The real plugin wins, always. A stub that shadowed it would make local runs
// prove nothing and hide the drift the contract test exists to find.
if ( defined( 'ADSANITY_VERSION' ) || defined( 'ADSANITY_EOL' ) ) {
	return;
}

/**
 * "No end date", as AdSanity defines it: adsanity.php:56.
 *
 * Deliberately the string form, because that is what the real plugin defines
 * and what the admin list compares against for an exact match.
 */
define( 'ADSANITY_EOL', '2082672000' );

/**
 * Registers the ad post type and its group taxonomy.
 *
 * Arguments mirror custom-post-types/class-adsanity-ads-cpt.php:180 and :225.
 * Note the absence of `editor` and `custom-fields` in supports: an AdSanity ad
 * is a title, a featured image and meta.
 *
 * @return void
 */
function laao_ads_stub_register_adsanity(): void {
	register_post_type(
		'ads',
		array(
			'label'               => 'Ads',
			'public'              => true,
			'show_in_rest'        => true,
			'supports'            => array( 'title', 'thumbnail' ),
			'capability_type'     => 'post',
			'exclude_from_search' => true,
			'has_archive'         => false,
			'hierarchical'        => false,
		)
	);

	register_taxonomy(
		'ad-group',
		'ads',
		array(
			'label'        => 'Ad Groups',
			'hierarchical' => true,
			'public'       => true,
			'show_in_rest' => true,
		)
	);

	// AdSanity fires this immediately after registering the CPT, and it is the
	// supported place for a third party to attach to it.
	do_action( 'ads_init' );
}
add_action( 'init', 'laao_ads_stub_register_adsanity', 0 );

/**
 * The stock size map, from adsanity.php:57-94.
 *
 * Read through the `adsanity_ad_sizes` filter and never from the option, since
 * that filter is how the custom-ad-sizes add-on injects and removes entries.
 *
 * @return array<string, string>
 */
function laao_ads_stub_adsanity_sizes(): array {
	return array(
		'728x90'  => '728x90 - Leaderboard',
		'300x250' => '300x250 - Medium Rectangle',
		'160x600' => '160x600 - Wide Skyscraper',
		'320x50'  => '320x50 - Mobile Leaderboard',
		'720x300' => '720x300 - Custom',
	);
}
add_filter( 'adsanity_ad_sizes', 'laao_ads_stub_adsanity_sizes' );
