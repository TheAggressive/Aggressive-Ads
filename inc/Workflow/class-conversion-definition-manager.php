<?php
/**
 * Staff writes to conversion definitions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * Validation, capability and audit for one definition, in that order.
 *
 * The capability check lives here rather than only in the REST controller, and
 * deliberately repeats it: a route is one caller, and a workflow that trusts
 * having been reached is a workflow that grants whatever the next caller
 * forgets to check. The controller's `permission_callback` decides whether the
 * request is answered at all; this decides whether the write happens, and
 * records the denial either way.
 */
final class Conversion_Definition_Manager {

	/**
	 * Constructor.
	 *
	 * @param Conversion_Definition_Repository $definitions Definition persistence.
	 * @param Audit_Repository                 $audit       Audit persistence.
	 */
	public function __construct(
		private readonly Conversion_Definition_Repository $definitions,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Creates one definition.
	 *
	 * @param array<string, mixed> $input Raw, already-allowlisted fields.
	 * @return int|WP_Error Definition id.
	 */
	public function create( array $input ): int|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$this->record( 0, Audit_Event::OUTCOME_DENIED, 'Conversion definition create denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage conversion definitions.', 'aggressive-ads' )
			);
		}

		$validated = Conversion_Definition::validate( $input );

		if ( true !== ( $validated['ok'] ?? false ) ) {
			return $this->invalid( $validated['errors'] ?? array() );
		}

		$id = $this->definitions->create( $validated['value'] );

		if ( $id <= 0 ) {
			$this->record( 0, Audit_Event::OUTCOME_FAILED, 'Conversion definition create failed.' );

			return new WP_Error(
				'aggr_conversion_definition_not_saved',
				__( 'The conversion definition could not be created.', 'aggressive-ads' )
			);
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'conversion_definition.created',
				object_type: 'conversion_definition',
				object_id: $id,
				org_id: $validated['value']['org_id'],
				message: 'Conversion definition created.',
				context: $this->context( $validated['value'] ),
				actor_user_id: get_current_user_id()
			)
		);

		return $id;
	}

	/**
	 * Updates one definition, refusing a stale revision.
	 *
	 * @param int                  $id                Definition id.
	 * @param array<string, mixed> $input             Raw, already-allowlisted fields.
	 * @param int                  $expected_revision Revision the caller last read.
	 * @return true|WP_Error
	 */
	public function update( int $id, array $input, int $expected_revision ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			$this->record( $id, Audit_Event::OUTCOME_DENIED, 'Conversion definition update denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage conversion definitions.', 'aggressive-ads' )
			);
		}

		$existing = $this->definitions->find( $id );

		if ( null === $existing ) {
			return new WP_Error(
				'aggr_conversion_definition_not_found',
				__( 'That conversion definition no longer exists.', 'aggressive-ads' )
			);
		}

		$validated = Conversion_Definition::validate( $input );

		if ( true !== ( $validated['ok'] ?? false ) ) {
			return $this->invalid( $validated['errors'] ?? array() );
		}

		if ( ! $this->definitions->update( $id, $validated['value'], $expected_revision ) ) {
			/*
			 * A refused update is a stale revision far more often than a
			 * vanished row, and the two need different words: one asks the
			 * person to reload, the other tells them there is nothing to
			 * reload. Distinguished by re-reading, which is safe because the
			 * write already failed.
			 */
			$still_there = $this->definitions->find( $id );

			if ( null !== $still_there ) {
				return new WP_Error(
					'aggr_conversion_definition_stale',
					__( 'Somebody else changed this conversion definition. Reload and try again.', 'aggressive-ads' )
				);
			}

			$this->record( $id, Audit_Event::OUTCOME_FAILED, 'Conversion definition update failed.' );

			return new WP_Error(
				'aggr_conversion_definition_not_found',
				__( 'That conversion definition no longer exists.', 'aggressive-ads' )
			);
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'conversion_definition.updated',
				object_type: 'conversion_definition',
				object_id: $id,
				org_id: $validated['value']['org_id'],
				from_state: (string) $existing['status'],
				to_state: $validated['value']['status'],
				message: 'Conversion definition updated.',
				context: $this->context( $validated['value'] ),
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}

	/**
	 * The audit context for one definition.
	 *
	 * The public key is deliberately absent. It is the credential a page
	 * presents to report a conversion, and an audit log is read by more people,
	 * and kept longer, than the screen that shows it.
	 *
	 * @param array{name: string, org_id: int, window_seconds: int, default_value_micros: int, currency: string, allow_s2s: bool, status: string} $fields Validated definition.
	 * @return array<string, mixed>
	 */
	private function context( array $fields ): array {
		return array(
			'name'           => $fields['name'],
			'window_seconds' => $fields['window_seconds'],
			'allow_s2s'      => $fields['allow_s2s'],
			'status'         => $fields['status'],
		);
	}

	/**
	 * One validation failure, naming the fields rather than guessing an order.
	 *
	 * @param array<int, string> $errors Field names.
	 */
	private function invalid( array $errors ): WP_Error {
		return new WP_Error(
			'aggr_conversion_definition_invalid',
			__( 'That conversion definition is not valid.', 'aggressive-ads' ),
			array( 'fields' => $errors )
		);
	}

	/**
	 * Records a denial or failure.
	 *
	 * @param int    $id      Definition id, or 0.
	 * @param string $outcome Audit outcome.
	 * @param string $message Audit message.
	 */
	private function record( int $id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'conversion_definition.write',
				outcome: $outcome,
				object_type: 'conversion_definition',
				object_id: $id,
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
