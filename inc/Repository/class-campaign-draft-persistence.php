<?php
/**
 * Verified campaign draft writes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use WP_Error;

/**
 * Writes, reads back, and compensates one campaign draft field set.
 */
final class Campaign_Draft_Persistence {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository $campaigns Canonical campaign reads.
	 */
	public function __construct( private readonly Campaign_Repository $campaigns ) {
	}

	/**
	 * Persists a field set only when every requested value can be read back.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $fields      Allowlisted draft values.
	 * @return true|WP_Error
	 */
	public function update( int $campaign_id, array $fields ) {
		$before  = $this->values( $campaign_id, array_keys( $fields ) );
		$written = $this->write( $campaign_id, $fields );

		if ( is_wp_error( $written ) ) {
			return $written;
		}

		if ( $this->values( $campaign_id, array_keys( $fields ) ) === $this->normalize( $fields ) ) {
			return true;
		}

		$this->write( $campaign_id, $before );

		return new WP_Error(
			'aggr_campaign_write_failed',
			__( 'The campaign changes could not be saved completely.', 'aggressive-ads' )
		);
	}

	/**
	 * Writes an allowlisted draft field set.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $fields      Draft values.
	 * @return true|WP_Error
	 */
	private function write( int $campaign_id, array $fields ) {
		if ( isset( $fields['title'] ) ) {
			$updated = wp_update_post(
				array(
					'ID'         => $campaign_id,
					'post_title' => (string) $fields['title'],
				),
				true
			);

			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
		}

		$meta = array(
			'start_ts'         => Campaign_Repository::META_START_TS,
			'end_ts'           => Campaign_Repository::META_END_TS,
			'advertiser_notes' => Campaign_Repository::META_ADVERTISER_NOTES,
			'wizard_step'      => Campaign_Repository::META_WIZARD_STEP,
			'package_id'       => Campaign_Repository::META_PACKAGE_ID,
			'budget_cents'     => Campaign_Repository::META_BUDGET_CENTS,
			'currency'         => Campaign_Repository::META_CURRENCY,
		);

		foreach ( $meta as $field => $meta_key ) {
			if ( array_key_exists( $field, $fields ) ) {
				update_post_meta( $campaign_id, $meta_key, $fields[ $field ] );
			}
		}

		if ( isset( $fields['placement_ids'] ) && is_array( $fields['placement_ids'] ) ) {
			delete_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID );

			foreach ( $fields['placement_ids'] as $placement_id ) {
				add_post_meta( $campaign_id, Campaign_Repository::META_PLACEMENT_ID, (int) $placement_id );
			}
		}

		return true;
	}

	/**
	 * Reads requested fields in their canonical persistence shape.
	 *
	 * @param int                $campaign_id Campaign post id.
	 * @param array<int, string> $keys        Field names.
	 * @return array<string, mixed>
	 */
	private function values( int $campaign_id, array $keys ): array {
		$getters = array(
			'title'            => 'title',
			'start_ts'         => 'start_ts',
			'end_ts'           => 'end_ts',
			'advertiser_notes' => 'advertiser_notes',
			'wizard_step'      => 'wizard_step',
			'package_id'       => 'package_id',
			'budget_cents'     => 'budget_cents',
			'currency'         => 'currency',
			'placement_ids'    => 'placement_ids',
		);
		$values  = array();

		foreach ( $keys as $key ) {
			if ( isset( $getters[ $key ] ) ) {
				$values[ $key ] = $this->campaigns->{$getters[ $key ]}( $campaign_id );
			}
		}

		return $values;
	}

	/**
	 * Normalizes caller values to the same scalar types used by reads.
	 *
	 * @param array<string, mixed> $fields Draft values.
	 * @return array<string, mixed>
	 */
	private function normalize( array $fields ): array {
		$normalized = array();

		foreach ( array_keys( $fields ) as $key ) {
			if ( 'placement_ids' === $key && is_array( $fields[ $key ] ) ) {
				$normalized[ $key ] = array_values( array_unique( array_filter( array_map( 'intval', $fields[ $key ] ) ) ) );
			} elseif ( in_array( $key, array( 'start_ts', 'end_ts', 'package_id', 'budget_cents' ), true ) ) {
				$normalized[ $key ] = (int) $fields[ $key ];
			} elseif ( in_array( $key, array( 'title', 'advertiser_notes', 'wizard_step', 'currency' ), true ) ) {
				$normalized[ $key ] = (string) $fields[ $key ];
			}
		}

		return $normalized;
	}
}
