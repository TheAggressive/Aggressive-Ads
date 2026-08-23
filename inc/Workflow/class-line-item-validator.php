<?php
/**
 * Line-item input validation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Line_Item_Rules;
use WP_Error;

/** Normalizes the public line-item editing allowlist. */
final class Line_Item_Validator {

	public const MAX_NAME_LENGTH = Line_Item_Rules::MAX_NAME_LENGTH;

	/**
	 * Validates public line-item fields.
	 *
	 * @param array<string, mixed> $fields  Candidate values.
	 * @param array<string, mixed> $current Current persisted values.
	 * @return array<string, int|string>|WP_Error
	 */
	public function validate( array $fields, array $current = array() ): array|WP_Error {
		if ( array() === $fields ) {
			return $this->error( 'aggr_line_item_fields_required', __( 'Provide at least one line item field to update.', 'aggressive-ads' ), '' );
		}

		$clean = array();

		if ( array_key_exists( 'name', $fields ) ) {
			$name = sanitize_text_field( (string) $fields['name'] );
			if ( '' === $name || mb_strlen( $name ) > self::MAX_NAME_LENGTH ) {
				return $this->error( 'aggr_line_item_name_invalid', __( 'Enter a line item name of 191 characters or fewer.', 'aggressive-ads' ), 'name' );
			}
			$clean['name'] = $name;
		}

		foreach ( array(
			'pricing_model' => Line_Item_Rules::PRICING_MODELS,
			'goal_type'     => Line_Item_Rules::GOAL_TYPES,
			'pacing_mode'   => Line_Item_Rules::PACING_MODES,
		) as $field => $allowed ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				continue;
			}

			$value = sanitize_key( (string) $fields[ $field ] );
			if ( ! in_array( $value, $allowed, true ) ) {
				return $this->error( 'aggr_line_item_value_invalid', __( 'Choose a supported delivery setting.', 'aggressive-ads' ), $field );
			}
			$clean[ $field ] = $value;
		}

		foreach ( array( 'goal_amount', 'budget_cents', 'daily_cap', 'lifetime_cap' ) as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$value = $this->integer( $fields[ $field ], 0, PHP_INT_MAX );
				if ( null === $value ) {
					return $this->error( 'aggr_line_item_value_invalid', __( 'Use a non-negative whole number.', 'aggressive-ads' ), $field );
				}
				$clean[ $field ] = $value;
			}
		}

		foreach ( array(
			'priority' => 65535,
			'weight'   => 4294967295,
		) as $field => $maximum ) {
			if ( array_key_exists( $field, $fields ) ) {
				$value = $this->integer( $fields[ $field ], 1, $maximum );
				if ( null === $value ) {
					return $this->error( 'aggr_line_item_value_invalid', __( 'Use a supported positive whole number.', 'aggressive-ads' ), $field );
				}
				$clean[ $field ] = $value;
			}
		}

		if ( isset( $clean['goal_type'] ) && Line_Item_Rules::GOAL_TYPES[0] === $clean['goal_type'] ) {
			$clean['goal_amount'] = 0;
		}

		$effective = array_merge( $current, $clean );
		$daily     = (int) ( $effective['daily_cap'] ?? 0 );
		$lifetime  = (int) ( $effective['lifetime_cap'] ?? 0 );

		if ( $daily > 0 && $lifetime > 0 && $daily > $lifetime ) {
			return $this->error( 'aggr_line_item_cap_invalid', __( 'The daily cap cannot exceed the lifetime cap.', 'aggressive-ads' ), 'daily_cap' );
		}

		if ( 'none' !== (string) ( $effective['goal_type'] ?? 'none' ) && (int) ( $effective['goal_amount'] ?? 0 ) < 1 ) {
			return $this->error( 'aggr_line_item_goal_invalid', __( 'Enter a positive goal amount for this goal.', 'aggressive-ads' ), 'goal_amount' );
		}

		return $clean;
	}

	/**
	 * Parses an integer without silently truncating decimals or overflow.
	 *
	 * @param mixed $value   Candidate value.
	 * @param int   $minimum Inclusive minimum.
	 * @param int   $maximum Inclusive maximum.
	 */
	private function integer( mixed $value, int $minimum, int $maximum ): ?int {
		$parsed = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => $minimum,
					'max_range' => $maximum,
				),
			)
		);

		return false === $parsed ? null : $parsed;
	}

	/**
	 * Builds one field validation error.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message Reader-facing message.
	 * @param string $field   Invalid field.
	 */
	private function error( string $code, string $message, string $field ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => 422,
				'field'  => $field,
			)
		);
	}
}
