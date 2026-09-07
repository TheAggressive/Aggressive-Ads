<?php
/**
 * Identity of this plugin's REST surface.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

/**
 * Where every route this plugin registers lives.
 *
 * The namespace used to be a constant on `Creative_File_Controller`, which is
 * where it happened to be needed first. Thirteen files then depended on that
 * controller for a fact that has nothing to do with creative files — screens,
 * the fill service, the slot renderer, the portal. Moving the controller, or
 * splitting it, would have touched all of them.
 */
final class Api {

	/**
	 * REST namespace, versioned.
	 *
	 * The version is part of the namespace rather than a route prefix because
	 * that is how WordPress separates one plugin's surface from another's, and
	 * how a future v2 can exist beside v1 rather than replacing it.
	 */
	public const NAMESPACE = 'aggr/v1';
}
