<?php
/**
 * How the staff review screens print dates, times, statuses and people.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

/**
 * Shared by the queue, the campaign view and its audit trail, so the three
 * cannot print the same moment or the same status two different ways. Moved
 * out of Review_Data with the trail; nothing here holds state.
 */
final class Review_Format {

	/**
	 * Formats a UTC timestamp in the site's timezone, or returns an empty value.
	 *
	 * @param int  $timestamp UTC Unix timestamp.
	 * @param bool $with_time Whether to include the site's time format.
	 * @return string
	 */
	public static function date( int $timestamp, bool $with_time = false ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$format = (string) get_option( 'date_format', 'M j, Y' );

		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format', 'g:i a' );
		}

		$formatted = wp_date( $format, $timestamp );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * The time of day alone, in the site's format and timezone.
	 *
	 * @param int $timestamp Unix time.
	 * @return string
	 */
	public static function time( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		$formatted = wp_date( (string) get_option( 'time_format', 'g:i a' ), $timestamp );

		return is_string( $formatted ) ? $formatted : '';
	}

	/**
	 * The status's human label, from the registered status itself.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	public static function status( string $status ): string {
		$object = get_post_status_object( $status );

		return null === $object ? $status : (string) $object->label;
	}

	/**
	 * A user's display name, or a dash.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	public static function user( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$user = get_userdata( $user_id );

		return false === $user ? '' : (string) $user->display_name;
	}
}
