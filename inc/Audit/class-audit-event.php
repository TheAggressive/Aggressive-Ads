<?php
/**
 * One audit log entry.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Audit;

use InvalidArgumentException;

/**
 * An immutable, validated audit record.
 *
 * Validation lives here rather than in the repository so that the rules — and
 * in particular the rule about what must never be logged — are enforced before
 * a row can be built at all, and are testable with no database.
 *
 * See docs/data-schema.md and docs/adr/0003-audit-log-in-custom-table.md.
 */
final class Audit_Event {

	public const OUTCOME_OK     = 'ok';
	public const OUTCOME_DENIED = 'denied';
	public const OUTCOME_FAILED = 'failed';

	/**
	 * `message` is varchar(255). Truncating on write beats MySQL doing it.
	 */
	public const MAX_MESSAGE_LENGTH = 255;

	/**
	 * Context keys that must never be written, at any nesting depth.
	 *
	 * The audit log is read by more people than write it, and is retained far
	 * longer than any of these values should be. A nonce or token in a log row
	 * outlives its own validity window and is readable by every reviewer.
	 *
	 * File paths are here for a different reason: the private creative root is
	 * the one secret protecting unpublished artwork, and a path in a log is a
	 * path in a support ticket.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN_CONTEXT_KEYS = array(
		'password',
		'pass',
		'pwd',
		'nonce',
		'_wpnonce',
		'token',
		'_aggr_private_token',
		'secret',
		'api_key',
		'authorization',
		'cookie',
		'session',
		'ip',
		'ip_address',
		'remote_addr',
		'email',
		'email_address',
		'user_email',
		'recipient_email',
		'path',
		'file_path',
		'private_path',
		'_aggr_private_path',
	);

	/**
	 * Constructor.
	 *
	 * @param string               $event         Event name, e.g. `campaign.submitted`.
	 * @param string               $outcome       One of the OUTCOME_* constants.
	 * @param string               $object_type   Entity type, e.g. `campaign`.
	 * @param int                  $object_id     Entity id.
	 * @param int                  $org_id        Owning organization id.
	 * @param string               $from_state    Previous status, if this was a transition.
	 * @param string               $to_state      New status, if this was a transition.
	 * @param string               $message       Short human summary.
	 * @param array<string, mixed> $context       Structured detail. Never secrets.
	 * @param int                  $actor_user_id Acting user, or 0 for the system.
	 *
	 * @throws InvalidArgumentException When the outcome is unknown, the event is empty, or the context carries a forbidden key.
	 */
	public function __construct(
		private readonly string $event,
		private readonly string $outcome = self::OUTCOME_OK,
		private readonly string $object_type = '',
		private readonly int $object_id = 0,
		private readonly int $org_id = 0,
		private readonly string $from_state = '',
		private readonly string $to_state = '',
		private readonly string $message = '',
		private readonly array $context = array(),
		private readonly int $actor_user_id = 0
	) {
		if ( '' === trim( $this->event ) ) {
			throw new InvalidArgumentException( 'An audit event must be named.' );
		}

		if ( ! in_array( $this->outcome, self::outcomes(), true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Unknown audit outcome "%s".', $this->outcome )
			);
		}

		self::assert_context_is_safe( $this->context );
	}

	/**
	 * The permitted outcomes.
	 *
	 * `denied` is the interesting one. A log that only records successes cannot
	 * show an attack — it can only fail to show one, and absence is not a thing
	 * you can query for.
	 *
	 * @return array<int, string>
	 */
	public static function outcomes(): array {
		return array( self::OUTCOME_OK, self::OUTCOME_DENIED, self::OUTCOME_FAILED );
	}

	/**
	 * Rejects a context array carrying anything that must not be logged.
	 *
	 * Recursive, because the dangerous key is usually nested one level down in
	 * a request payload someone passed through wholesale.
	 *
	 * @param array<mixed, mixed> $context Candidate context.
	 * @param int                 $depth   Current recursion depth.
	 * @return void
	 *
	 * @throws InvalidArgumentException When a forbidden key is present.
	 */
	private static function assert_context_is_safe( array $context, int $depth = 0 ): void {
		if ( $depth > 8 ) {
			return;
		}

		foreach ( $context as $key => $value ) {
			if ( is_string( $key ) && in_array( strtolower( $key ), self::FORBIDDEN_CONTEXT_KEYS, true ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Audit context may not contain "%s".', $key )
				);
			}

			if ( is_array( $value ) ) {
				self::assert_context_is_safe( $value, $depth + 1 );
			}
		}
	}

	/**
	 * The event name.
	 *
	 * @return string
	 */
	public function event(): string {
		return $this->event;
	}

	/**
	 * The outcome.
	 *
	 * @return string
	 */
	public function outcome(): string {
		return $this->outcome;
	}

	/**
	 * The entity type.
	 *
	 * @return string
	 */
	public function object_type(): string {
		return $this->object_type;
	}

	/**
	 * The entity id.
	 *
	 * @return int
	 */
	public function object_id(): int {
		return $this->object_id;
	}

	/**
	 * The owning organization id.
	 *
	 * @return int
	 */
	public function org_id(): int {
		return $this->org_id;
	}

	/**
	 * The previous status.
	 *
	 * @return string
	 */
	public function from_state(): string {
		return $this->from_state;
	}

	/**
	 * The new status.
	 *
	 * @return string
	 */
	public function to_state(): string {
		return $this->to_state;
	}

	/**
	 * The human summary, truncated to what the column holds.
	 *
	 * @return string
	 */
	public function message(): string {
		if ( strlen( $this->message ) <= self::MAX_MESSAGE_LENGTH ) {
			return $this->message;
		}

		return substr( $this->message, 0, self::MAX_MESSAGE_LENGTH );
	}

	/**
	 * The structured detail.
	 *
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * The acting user, or 0 for the system.
	 *
	 * @return int
	 */
	public function actor_user_id(): int {
		return $this->actor_user_id;
	}
}
