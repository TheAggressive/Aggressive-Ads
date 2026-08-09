<?php
/**
 * One edge in the campaign lifecycle.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Domain;

/**
 * A single legal status change, and everything that is true about it.
 *
 * Immutable, and free of WordPress. Who may make this change, what has to hold
 * first, and what it does are all data here rather than branches somewhere in
 * a controller — which is what makes the whole lifecycle reviewable as one
 * table and testable without a bootstrap.
 */
final class Campaign_Transition {

	/**
	 * Constructor.
	 *
	 * @param string             $from         Status the campaign is leaving.
	 * @param string             $to           Status the campaign is entering.
	 * @param array<int, string> $actors       Who may make this change.
	 * @param array<int, string> $capabilities Capabilities the actor must hold, all of them.
	 * @param array<int, string> $guards       Conditions that must hold, all of them.
	 * @param array<int, string> $effects      What the transition does besides writing the status.
	 */
	public function __construct(
		public readonly string $from,
		public readonly string $to,
		public readonly array $actors,
		public readonly array $capabilities = array(),
		public readonly array $guards = array(),
		public readonly array $effects = array()
	) {
	}

	/**
	 * Whether a given kind of actor may make this transition.
	 *
	 * @param string $actor One of the Transition_Table ACTOR_* constants.
	 * @return bool
	 */
	public function allows_actor( string $actor ): bool {
		return in_array( $actor, $this->actors, true );
	}

	/**
	 * Whether this transition is driven by the clock rather than by a person.
	 *
	 * @return bool
	 */
	public function is_system(): bool {
		return $this->allows_actor( Transition_Table::ACTOR_SYSTEM );
	}

	/**
	 * Whether this transition carries a named effect.
	 *
	 * @param string $effect One of the Transition_Table EFFECT_* constants.
	 * @return bool
	 */
	public function has_effect( string $effect ): bool {
		return in_array( $effect, $this->effects, true );
	}

	/**
	 * Whether this transition carries a named guard.
	 *
	 * @param string $guard One of the Transition_Table GUARD_* constants.
	 * @return bool
	 */
	public function has_guard( string $guard ): bool {
		return in_array( $guard, $this->guards, true );
	}

	/**
	 * A stable identifier, for audit context and error messages.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->from . '->' . $this->to;
	}
}
