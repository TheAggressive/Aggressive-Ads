<?php
/**
 * Pure targeting evaluation rules for the decision engine.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

use Throwable;

/**
 * Pure domain logic evaluating declarative targeting criteria against request context.
 */
final class Targeting_Rules {

	public const OP_AND = 'AND';
	public const OP_OR  = 'OR';
	public const OP_NOT = 'NOT';

	public const CMP_EQ           = 'eq';
	public const CMP_NEQ          = 'neq';
	public const CMP_IN           = 'in';
	public const CMP_NOT_IN       = 'not_in';
	public const CMP_CONTAINS     = 'contains';
	public const CMP_NOT_CONTAINS = 'not_contains';
	public const CMP_EXISTS       = 'exists';
	public const CMP_NOT_EXISTS   = 'not_exists';

	/**
	 * Evaluates candidate row against request context facts.
	 *
	 * @param array<string, mixed> $row     Assignment/Line-Item row.
	 * @param Decision_Context     $context Decision context.
	 * @return string|null Exclusion reason, or null if candidate passes targeting.
	 */
	public static function evaluate_candidate( array $row, Decision_Context $context ): ?string {
		$tree = self::extract_targeting_tree( $row );

		if ( null === $tree || array() === $tree ) {
			return null;
		}

		try {
			$matches = self::evaluate_node( $tree, $context->facts );

			return $matches ? null : Exclusion_Reason::TARGETING_EXCLUDED;
		} catch ( Throwable ) {
			return Exclusion_Reason::TARGETING_STAGE_ERROR;
		}
	}

	/**
	 * Recursively evaluates an AST node.
	 *
	 * @param array<string, mixed> $node  Targeting rule or group node.
	 * @param array<string, mixed> $facts Request facts.
	 */
	public static function evaluate_node( array $node, array $facts ): bool {
		// Group node with 'rules'.
		if ( isset( $node['rules'] ) && is_array( $node['rules'] ) ) {
			$op = strtoupper( (string) ( $node['operator'] ?? self::OP_AND ) );

			if ( array() === $node['rules'] ) {
				return true;
			}

			if ( self::OP_OR === $op ) {
				foreach ( $node['rules'] as $child ) {
					if ( is_array( $child ) && self::evaluate_node( $child, $facts ) ) {
						return true;
					}
				}
				return false;
			}

			if ( self::OP_NOT === $op ) {
				foreach ( $node['rules'] as $child ) {
					if ( is_array( $child ) && self::evaluate_node( $child, $facts ) ) {
						return false;
					}
				}
				return true;
			}

			// Evaluate AND group.
			foreach ( $node['rules'] as $child ) {
				if ( ! is_array( $child ) || ! self::evaluate_node( $child, $facts ) ) {
					return false;
				}
			}
			return true;
		}

		// Leaf comparison rule.
		if ( isset( $node['dimension'] ) && is_string( $node['dimension'] ) ) {
			$dimension = $node['dimension'];
			$op        = strtolower( (string) ( $node['operator'] ?? self::CMP_EQ ) );
			$expected  = $node['value'] ?? null;
			$actual    = self::resolve_fact_value( $dimension, $facts );

			return self::evaluate_comparison( $op, $actual, $expected );
		}

		return true;
	}

	/**
	 * Evaluates a single comparison operator.
	 *
	 * @param string $op       Comparison operator.
	 * @param mixed  $actual   Context value.
	 * @param mixed  $expected Expected rule value.
	 */
	public static function evaluate_comparison( string $op, mixed $actual, mixed $expected ): bool {
		switch ( $op ) {
			case self::CMP_EXISTS:
				return null !== $actual && '' !== $actual && array() !== $actual;

			case self::CMP_NOT_EXISTS:
				return null === $actual || '' === $actual || array() === $actual;

			case self::CMP_EQ:
				if ( is_array( $actual ) ) {
					return in_array( (string) $expected, array_map( 'strval', $actual ), true );
				}
				return (string) $actual === (string) $expected;

			case self::CMP_NEQ:
				if ( is_array( $actual ) ) {
					return ! in_array( (string) $expected, array_map( 'strval', $actual ), true );
				}
				return (string) $actual !== (string) $expected;

			case self::CMP_IN:
				$expected_list = is_array( $expected ) ? $expected : array( $expected );
				if ( is_array( $actual ) ) {
					return count( array_intersect( array_map( 'strval', $actual ), array_map( 'strval', $expected_list ) ) ) > 0;
				}
				return in_array( (string) $actual, array_map( 'strval', $expected_list ), true );

			case self::CMP_NOT_IN:
				$expected_list = is_array( $expected ) ? $expected : array( $expected );
				if ( is_array( $actual ) ) {
					return 0 === count( array_intersect( array_map( 'strval', $actual ), array_map( 'strval', $expected_list ) ) );
				}
				return ! in_array( (string) $actual, array_map( 'strval', $expected_list ), true );

			case self::CMP_CONTAINS:
				if ( is_array( $actual ) ) {
					return in_array( (string) $expected, array_map( 'strval', $actual ), true );
				}
				if ( is_string( $actual ) && is_scalar( $expected ) ) {
					return '' !== (string) $expected && str_contains( strtolower( $actual ), strtolower( (string) $expected ) );
				}
				return false;

			case self::CMP_NOT_CONTAINS:
				if ( is_array( $actual ) ) {
					return ! in_array( (string) $expected, array_map( 'strval', $actual ), true );
				}
				if ( is_string( $actual ) && is_scalar( $expected ) ) {
					return '' === (string) $expected || ! str_contains( strtolower( $actual ), strtolower( (string) $expected ) );
				}
				return true;

			default:
				return true;
		}
	}

	/**
	 * Resolves a nested key or dot-notation dimension from context facts.
	 *
	 * @param string               $dimension Dimension key, e.g. 'wp.post_type' or 'device'.
	 * @param array<string, mixed> $facts     Context facts.
	 */
	public static function resolve_fact_value( string $dimension, array $facts ): mixed {
		if ( array_key_exists( $dimension, $facts ) ) {
			return $facts[ $dimension ];
		}

		$parts   = explode( '.', $dimension );
		$current = $facts;

		foreach ( $parts as $part ) {
			if ( ! is_array( $current ) || ! array_key_exists( $part, $current ) ) {
				return null;
			}
			$current = $current[ $part ];
		}

		return $current;
	}

	/**
	 * Extracts targeting AST from candidate row.
	 *
	 * @param array<string, mixed> $row Candidate row.
	 * @return array<string, mixed>|null
	 */
	public static function extract_targeting_tree( array $row ): ?array {
		if ( isset( $row['targeting_rules'] ) ) {
			if ( is_array( $row['targeting_rules'] ) ) {
				return $row['targeting_rules'];
			}
			if ( is_string( $row['targeting_rules'] ) ) {
				$decoded = json_decode( $row['targeting_rules'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		if ( isset( $row['targeting'] ) ) {
			if ( is_array( $row['targeting'] ) ) {
				return $row['targeting'];
			}
			if ( is_string( $row['targeting'] ) ) {
				$decoded = json_decode( $row['targeting'], true );
				if ( is_array( $decoded ) ) {
					return $decoded;
				}
			}
		}

		if ( isset( $row['delivery_settings'] ) ) {
			$settings = is_array( $row['delivery_settings'] )
				? $row['delivery_settings']
				: json_decode( (string) $row['delivery_settings'], true );

			if ( is_array( $settings ) && isset( $settings['targeting'] ) && is_array( $settings['targeting'] ) ) {
				return $settings['targeting'];
			}
		}

		return null;
	}
}
