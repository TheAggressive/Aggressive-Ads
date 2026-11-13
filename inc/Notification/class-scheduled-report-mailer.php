<?php
/**
 * Branded report presentation and individualized mail transport.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Notification;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Portal\Campaign_Report_View_Data;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Workflow\Reporting_Read;

/** Reuses portal metrics and disclosures; never creates a public report artifact. */
final class Scheduled_Report_Mailer {

	/**
	 * Reuse the portal's facts and presentation without a browser session.
	 *
	 * @param Settings                  $settings White-label identity.
	 * @param Reporting_Read            $reporting Shared facts and freshness.
	 * @param Delivery_View_Data        $display Shared metric formatting.
	 * @param Org_Repository            $organizations Organization name.
	 * @param Campaign_Report_View_Data $campaigns Shared plan summaries.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Reporting_Read $reporting,
		private readonly Delivery_View_Data $display,
		private readonly Org_Repository $organizations,
		private readonly Campaign_Report_View_Data $campaigns
	) {}

	/**
	 * Construct in the recipient's locale; null history means Not measured.
	 *
	 * @param int           $org_id Authorized organization.
	 * @param Report_Period $period Closed calendar period.
	 * @param int           $now Generation timestamp.
	 * @return array{subject: string, body: string, headers: list<string>}|\WP_Error
	 */
	public function message( int $org_id, Report_Period $period, int $now ): array|\WP_Error {
		if ( ! $this->reporting->surfaces() ) {
			return new \WP_Error( 'aggr_reporting_disabled' );
		}
		$read = null;
		if ( $this->reporting->history_available( $period ) ) {
			$read = $this->reporting->snapshot_for_org( $org_id, $period );
			if ( is_wp_error( $read ) ) {
				return $read;
			}
		}
		$brand   = sanitize_text_field( $this->settings->product_name() );
		$org     = sanitize_text_field( $this->organizations->name( $org_id ) );
		$state   = $this->reporting->freshness( $period )['state'];
		$labels  = array(
			Report_Period::RECONCILED  => __( 'Reconciled', 'aggressive-ads' ),
			Report_Period::PROVISIONAL => __( 'Provisional', 'aggressive-ads' ),
			Report_Period::PARTIAL     => __( 'Partial', 'aggressive-ads' ),
		);
		$subject = sprintf( /* translators: 1: product name. 2: organization name. */ __( '%1$s — %2$s delivery report', 'aggressive-ads' ), $brand, $org );
		$lines   = array(
			$subject,
			sprintf( /* translators: 1: first date. 2: last date. 3: timezone. */ __( '%1$s to %2$s (%3$s)', 'aggressive-ads' ), $period->start, $period->end, $period->timezone ),
			sprintf( /* translators: %s: report freshness state. */ __( 'Report status: %s', 'aggressive-ads' ), $labels[ $state ] ),
			$this->display->freshness_note( $period ),
			'',
		);
		foreach ( $this->display->format_counts( $read, $period ) as $tile ) {
			$lines[] = $tile['label'] . ': ' . $tile['value'];
		}
		$campaigns = $this->campaigns->for_org( $org_id, $period, $now );
		if ( is_wp_error( $campaigns ) ) {
			return $campaigns;
		}
		$lines   = array_merge( $lines, $this->campaigns->email_lines( $campaigns ) );
		$url     = add_query_arg(
			array(
				'from' => $period->start,
				'to'   => $period->end,
			),
			Routes::url( Request::ROUTE_DASHBOARD )
		);
		$lines[] = '';
		$lines[] = __( 'Open your report and download its CSV after signing in:', 'aggressive-ads' );
		$lines[] = $url;
		$lines[] = '';
		$lines[] = sprintf( /* translators: %s: generation timestamp in UTC. */ __( 'Source: native delivery. Generated %s UTC. Portal figures may change as reconciliation finishes.', 'aggressive-ads' ), gmdate( 'Y-m-d H:i', $now ) );
		$lines[] = __( 'Change or stop report emails in Account settings:', 'aggressive-ads' );
		$lines[] = Routes::url( Request::ROUTE_ACCOUNT ) . '#aggr-report-emails';
		$body    = implode( "\n", $lines );
		if ( strlen( $body ) > 32 * 1024 || strlen( $subject ) > 2048 ) {
			return new \WP_Error( 'aggr_report_message_too_large' );
		}
		$headers = Notification_Delivery::sender_headers();
		// Keep the validated sender address, but use the configured product name.
		$headers[0] = (string) preg_replace_callback( '/^From: .* </', static fn (): string => 'From: ' . str_replace( array( '<', '>' ), '', $brand ) . ' <', $headers[0] );
		$headers[]  = 'Content-Type: text/plain; charset=UTF-8';

		return array(
			'subject' => $subject,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * An exception can occur after acceptance; do not classify it as retry-safe.
	 *
	 * @param string                                                      $address Fresh authorized account email.
	 * @param array{subject: string, body: string, headers: list<string>} $message Bounded message.
	 */
	public function send( string $address, array $message ): string {
		try {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Explicit personal opt-in, one recipient, at most twenty messages per bounded pass; the configured WordPress transport remains replaceable.
			return wp_mail( $address, $message['subject'], $message['body'], $message['headers'] ) ? 'queued' : 'failed';
		} catch ( \Throwable ) {
			return 'uncertain';
		}
	}
}
