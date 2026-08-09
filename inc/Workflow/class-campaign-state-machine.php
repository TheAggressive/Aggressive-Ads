<?php
/**
 * The only thing that writes a campaign's status.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Workflow;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Core\Service;
use LAAO_Advertiser_Portal\Domain\Campaign_Transition;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use Throwable;
use WP_Error;

/**
 * Applies a status change, or explains why it cannot.
 *
 * The ordering below is the design, not an implementation detail:
 *
 *   1. the edge exists          6. write post_status
 *   2. the actor holds the caps 7. write the transition's meta
 *   3. ownership                8. audit
 *   4. guards                   9. domain event
 *   5. failable side effects   10. notifications
 *
 * Side effects that can fail run at step 5, **before** the status write. A
 * failed publish therefore leaves the campaign in review with an error, rather
 * than marked live with nothing behind it. Notifications run last and their
 * failure is swallowed: a submitted campaign stays submitted when the mail
 * server is down.
 *
 * See docs/adr/0008-explicit-transition-table.md.
 */
final class Campaign_State_Machine implements Service {

	/**
	 * Effects that talk to the outside world and can therefore fail.
	 *
	 * @var array<int, string>
	 */
	private const FAILABLE_EFFECTS = array(
		Transition_Table::EFFECT_PUBLISH,
		Transition_Table::EFFECT_UNPUBLISH,
		Transition_Table::EFFECT_SUPPRESS,
		Transition_Table::EFFECT_RESUME,
	);

	/**
	 * Depth of in-flight apply() status writes.
	 *
	 * Static, and deliberately so. "The state machine is writing right now" is
	 * a fact about the request, not about an object: the instance attached to
	 * transition_post_status at boot is not necessarily the instance calling
	 * apply(), and with per-instance state the listener flags the state
	 * machine's own write as foreign. A counter rather than a boolean so a
	 * nested write cannot clear the flag early.
	 *
	 * @var int
	 */
	private static int $applying = 0;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository                                                $campaigns Campaign persistence.
	 * @param Audit_Repository                                                   $audit     Audit persistence.
	 * @param Transition_Guards                                                  $guards    Guard evaluation.
	 * @param array<string, callable(int, Campaign_Transition): (true|WP_Error)> $effects   Failable effect handlers, keyed by effect name.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Audit_Repository $audit,
		private readonly Transition_Guards $guards,
		private readonly array $effects = array()
	) {
	}

	/**
	 * Attaches the divergence listener.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'transition_post_status', array( $this, 'note_foreign_status_change' ), 10, 3 );
	}

	/**
	 * Moves a campaign to a new status.
	 *
	 * Returns WP_Error rather than throwing for anything a caller can cause —
	 * an advertiser POSTing `lap_approved` is an expected event, not an
	 * exceptional one. Exceptions are reserved for genuine faults.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param string               $to          Target status.
	 * @param array<string, mixed> $context     Caller-supplied context, e.g. review_notes.
	 * @return true|WP_Error
	 */
	public function apply( int $campaign_id, string $to, array $context = array() ) {
		$from = $this->campaigns->status( $campaign_id );

		if ( '' === $from ) {
			return $this->deny( $campaign_id, 0, '', $to, 'laao_ads_campaign_not_found', __( 'Campaign not found.', 'laao-advertiser-portal' ) );
		}

		$org_id = $this->campaigns->org_id( $campaign_id );

		// 1. The edge exists.
		$transition = Transition_Table::find( $from, $to );

		if ( null === $transition ) {
			return $this->deny(
				$campaign_id,
				$org_id,
				$from,
				$to,
				'laao_ads_illegal_transition',
				__( 'That is not something this campaign can do right now.', 'laao-advertiser-portal' )
			);
		}

		// 2 and 3. Capabilities, and ownership through them: every capability
		// is checked against the object, so the org-scoped map_meta_cap filter
		// answers the ownership question in the same call.
		$system = $transition->is_system() && ( $context['system'] ?? false );

		if ( ! $system ) {
			foreach ( $transition->capabilities as $capability ) {
				if ( ! current_user_can( $capability, $campaign_id ) ) {
					return $this->deny(
						$campaign_id,
						$org_id,
						$from,
						$to,
						'laao_ads_forbidden',
						__( 'You do not have permission to do that.', 'laao-advertiser-portal' ),
						array( 'capability' => $capability )
					);
				}
			}
		}

		// 4. Guards.
		$guarded = $this->guards->check( $transition->guards, $campaign_id, $context );

		if ( is_wp_error( $guarded ) ) {
			$this->record_denial( $campaign_id, $org_id, $from, $to, $guarded->get_error_code(), $guarded->get_error_message() );

			return $guarded;
		}

		// 5. Side effects that can fail — before anything is written.
		$applied = $this->run_failable_effects( $campaign_id, $transition );

		if ( is_wp_error( $applied ) ) {
			$this->record_denial( $campaign_id, $org_id, $from, $to, $applied->get_error_code(), $applied->get_error_message() );

			return $applied;
		}

		// 6. The status write.
		++self::$applying;

		try {
			$written = $this->campaigns->update_status( $campaign_id, $to );
		} finally {
			--self::$applying;
		}

		if ( ! $written ) {
			return $this->deny(
				$campaign_id,
				$org_id,
				$from,
				$to,
				'laao_ads_status_write_failed',
				__( 'The campaign could not be updated.', 'laao-advertiser-portal' )
			);
		}

		// 7. The transition's own meta.
		$this->apply_meta_effects( $campaign_id, $transition, $context );

		// 8. Audit.
		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.transitioned',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $org_id,
				from_state: $from,
				to_state: $to,
				message: sprintf( 'Campaign moved from %1$s to %2$s.', $from, $to ),
				context: array( 'transition' => $transition->id() ),
				actor_user_id: get_current_user_id()
			)
		);

		// 9. The domain event.
		do_action( 'laao_ads_campaign_transitioned', $campaign_id, $from, $to, $context );

		// 10. Notifications, whose failure must never reverse a business fact
		// that has already happened.
		$this->notify( $campaign_id, $from, $to, $context );

		return true;
	}

	/**
	 * Records a campaign status change that did not come from apply().
	 *
	 * This cannot prevent the write — transition_post_status has no veto — but
	 * it makes the divergence visible in the audit log instead of surfacing
	 * months later as a state nobody can explain. Cheap defence against a bulk
	 * edit, a WP-CLI script, or a future us reaching for wp_update_post().
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Previous status.
	 * @param \WP_Post $post       The post.
	 * @return void
	 */
	public function note_foreign_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( self::$applying > 0 || $new_status === $old_status ) {
			return;
		}

		if ( ! $this->campaigns->exists( (int) $post->ID ) ) {
			return;
		}

		// A brand new campaign arriving in draft is ordinary creation, not a
		// transition somebody smuggled past the state machine.
		if ( 'new' === $old_status || 'auto-draft' === $old_status ) {
			return;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.status_changed_outside_workflow',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'campaign',
				object_id: (int) $post->ID,
				org_id: $this->campaigns->org_id( (int) $post->ID ),
				from_state: $old_status,
				to_state: $new_status,
				message: 'A campaign status was written without the state machine.',
				actor_user_id: get_current_user_id()
			)
		);
	}

	/**
	 * Runs the effects that talk to the outside world.
	 *
	 * @param int                 $campaign_id Campaign post id.
	 * @param Campaign_Transition $transition  The transition being applied.
	 * @return true|WP_Error
	 */
	private function run_failable_effects( int $campaign_id, Campaign_Transition $transition ) {
		foreach ( $transition->effects as $effect ) {
			if ( ! in_array( $effect, self::FAILABLE_EFFECTS, true ) ) {
				continue;
			}

			if ( ! isset( $this->effects[ $effect ] ) ) {
				// Fails closed, exactly like an unimplemented guard: an
				// approval that skipped publishing would mark a campaign live
				// with no ads behind it, which is the failure this whole
				// ordering exists to prevent.
				return new WP_Error(
					'laao_ads_effect_unavailable',
					__( 'This action cannot be completed yet.', 'laao-advertiser-portal' ),
					array( 'effect' => $effect )
				);
			}

			$result = ( $this->effects[ $effect ] )( $campaign_id, $transition );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		return true;
	}

	/**
	 * Writes the meta a transition owns.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param Campaign_Transition  $transition  The transition being applied.
	 * @param array<string, mixed> $context     Caller-supplied context.
	 * @return void
	 */
	private function apply_meta_effects( int $campaign_id, Campaign_Transition $transition, array $context ): void {
		if ( $transition->has_effect( Transition_Table::EFFECT_STAMP_SUBMITTED ) ) {
			$this->campaigns->set_submitted_at( $campaign_id, time() );
		}

		if ( $transition->has_effect( Transition_Table::EFFECT_INCREMENT_REVISION ) ) {
			$this->campaigns->increment_revision( $campaign_id );
		}

		if ( $transition->has_effect( Transition_Table::EFFECT_CLAIM_REVIEWER ) ) {
			$this->campaigns->set_reviewed_by( $campaign_id, get_current_user_id() );
		}

		if ( $transition->has_effect( Transition_Table::EFFECT_RELEASE_REVIEWER ) ) {
			$this->campaigns->set_reviewed_by( $campaign_id, 0 );
		}

		if ( $transition->has_guard( Transition_Table::GUARD_REVIEW_NOTES ) && isset( $context['review_notes'] ) && is_string( $context['review_notes'] ) ) {
			$this->campaigns->set_review_notes( $campaign_id, trim( $context['review_notes'] ) );
		}
	}

	/**
	 * Dispatches notifications, swallowing anything they throw.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param string               $from        Previous status.
	 * @param string               $to          New status.
	 * @param array<string, mixed> $context     Caller-supplied context.
	 * @return void
	 */
	private function notify( int $campaign_id, string $from, string $to, array $context ): void {
		try {
			do_action( 'laao_ads_notify_campaign_transitioned', $campaign_id, $from, $to, $context );
		} catch ( Throwable $e ) {
			$this->audit->insert(
				new Audit_Event(
					event: 'campaign.notification_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'campaign',
					object_id: $campaign_id,
					message: $e->getMessage()
				)
			);
		}
	}

	/**
	 * Records a denial and returns it.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param int                  $org_id      Owning organization.
	 * @param string               $from        Current status.
	 * @param string               $to          Attempted status.
	 * @param string               $code        Error code.
	 * @param string               $message     Error message.
	 * @param array<string, mixed> $context     Extra audit context.
	 * @return WP_Error
	 */
	private function deny( int $campaign_id, int $org_id, string $from, string $to, string $code, string $message, array $context = array() ): WP_Error {
		$this->record_denial( $campaign_id, $org_id, $from, $to, $code, $message, $context );

		return new WP_Error( $code, $message );
	}

	/**
	 * Writes a denied audit row.
	 *
	 * Denials are the interesting records: a log that only holds successes
	 * cannot show an attack, only fail to show one.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param int                  $org_id      Owning organization.
	 * @param string               $from        Current status.
	 * @param string               $to          Attempted status.
	 * @param int|string           $code        Error code. WP_Error permits integer codes, so third-party ones arrive here as int.
	 * @param string               $message     Error message.
	 * @param array<string, mixed> $context     Extra audit context.
	 * @return void
	 */
	private function record_denial( int $campaign_id, int $org_id, string $from, string $to, int|string $code, string $message, array $context = array() ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.transition_denied',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $org_id,
				from_state: $from,
				to_state: $to,
				message: $message,
				context: array_merge( array( 'code' => (string) $code ), $context ),
				actor_user_id: get_current_user_id()
			)
		);
	}
}
