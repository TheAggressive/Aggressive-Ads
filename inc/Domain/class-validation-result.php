<?php
/**
 * The outcome of validating something.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Domain;

/**
 * A collected list of problems, or the absence of them.
 *
 * Carries **codes and context, never messages.** Translating a string means
 * calling __(), and the domain layer calls no WordPress — so the workflow
 * layer maps each code to the sentence an advertiser reads. That split has a
 * second benefit: the rules can be asserted against stable codes rather than
 * against English that a copy edit would break.
 *
 * Collecting rather than failing fast is deliberate. An advertiser fixing one
 * problem, resubmitting, and being told about the next one is the worst
 * version of this product.
 */
final class Validation_Result {

	/**
	 * Problems found, in the order they were found.
	 *
	 * @var array<int, array{code: string, field: string, context: array<string, mixed>}>
	 */
	private array $problems = array();

	/**
	 * Records a problem.
	 *
	 * @param string               $code    Stable machine-readable code.
	 * @param string               $field   The field it belongs to, for form display.
	 * @param array<string, mixed> $context Detail the message needs, e.g. expected and actual dimensions.
	 * @return void
	 */
	public function add( string $code, string $field = '', array $context = array() ): void {
		$this->problems[] = array(
			'code'    => $code,
			'field'   => $field,
			'context' => $context,
		);
	}

	/**
	 * Merges another result into this one.
	 *
	 * @param Validation_Result $other Result to absorb.
	 * @return void
	 */
	public function absorb( Validation_Result $other ): void {
		foreach ( $other->problems() as $problem ) {
			$this->problems[] = $problem;
		}
	}

	/**
	 * Whether nothing is wrong.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return array() === $this->problems;
	}

	/**
	 * Every problem found.
	 *
	 * @return array<int, array{code: string, field: string, context: array<string, mixed>}>
	 */
	public function problems(): array {
		return $this->problems;
	}

	/**
	 * Just the codes, for assertions and for logging.
	 *
	 * @return array<int, string>
	 */
	public function codes(): array {
		return array_map(
			static fn ( array $problem ): string => $problem['code'],
			$this->problems
		);
	}

	/**
	 * Whether a particular problem was found.
	 *
	 * @param string $code Problem code.
	 * @return bool
	 */
	public function has( string $code ): bool {
		return in_array( $code, $this->codes(), true );
	}
}
