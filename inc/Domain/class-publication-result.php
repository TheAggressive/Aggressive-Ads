<?php
/**
 * What a publication attempt actually did.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Domain;

/**
 * A per-creative record of what was created, reused, and what failed.
 *
 * **Partial failure is the normal case to design for**, not an edge case. A
 * campaign with four creatives can fail on the third, and the two that
 * succeeded are real ads on a public site. Reporting only "it failed" throws
 * that away and makes the retry either duplicate them or refuse.
 *
 * So this distinguishes created from reused: a retry that reuses everything
 * and creates nothing is the proof that publication is idempotent.
 */
final class Publication_Result {

	/**
	 * Ads created by this attempt, creative id to provider ad id.
	 *
	 * @var array<int, int>
	 */
	private array $created = array();

	/**
	 * Ads that already existed and were reconciled, creative id to ad id.
	 *
	 * @var array<int, int>
	 */
	private array $reused = array();

	/**
	 * Creatives that could not be published, creative id to reason code.
	 *
	 * @var array<int, string>
	 */
	private array $failed = array();

	/**
	 * Records a newly created ad.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $ad_id       Provider ad id.
	 * @return void
	 */
	public function created( int $creative_id, int $ad_id ): void {
		$this->created[ $creative_id ] = $ad_id;
	}

	/**
	 * Records an ad that already existed and was brought up to date.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $ad_id       Provider ad id.
	 * @return void
	 */
	public function reused( int $creative_id, int $ad_id ): void {
		$this->reused[ $creative_id ] = $ad_id;
	}

	/**
	 * Records a creative that could not be published.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $reason      Machine-readable reason.
	 * @return void
	 */
	public function failed( int $creative_id, string $reason ): void {
		$this->failed[ $creative_id ] = $reason;
	}

	/**
	 * Whether every creative published.
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return array() === $this->failed;
	}

	/**
	 * Whether anything at all was published.
	 *
	 * A retry needs to know the difference between "nothing worked" and "some
	 * of it is already live".
	 *
	 * @return bool
	 */
	public function has_published(): bool {
		return array() !== $this->created || array() !== $this->reused;
	}

	/**
	 * Ads created by this attempt.
	 *
	 * @return array<int, int>
	 */
	public function created_ids(): array {
		return $this->created;
	}

	/**
	 * Ads that were already there.
	 *
	 * @return array<int, int>
	 */
	public function reused_ids(): array {
		return $this->reused;
	}

	/**
	 * Failures, creative id to reason.
	 *
	 * @return array<int, string>
	 */
	public function failures(): array {
		return $this->failed;
	}

	/**
	 * Every provider ad id this campaign now has, created or reused.
	 *
	 * @return array<int, int>
	 */
	public function ad_ids(): array {
		return array_values( $this->created + $this->reused );
	}
}
