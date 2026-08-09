<?php
/**
 * The service contract.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Core;

/**
 * A unit of application behaviour that attaches itself to WordPress.
 *
 * Constructing a service must do nothing observable — no hooks added, no
 * options read, no queries. All of that belongs in init(), which the
 * composition root calls explicitly and in a visible order.
 *
 * That separation is what makes a service constructible in a unit test
 * without WordPress, and what stops registering a service from accidentally
 * starting the application. See docs/architecture.md.
 */
interface Service {

	/**
	 * Attaches this service's hooks. Called once, by Plugin::init_services().
	 *
	 * @return void
	 */
	public function init(): void;
}
