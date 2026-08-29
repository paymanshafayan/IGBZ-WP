<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Anything that can put content on an Instagram account.
 *
 * The IGBZ WordPress port deliberately does NOT call the Instagram Graph API: publishing is
 * delegated to the single social provider (Zernio), which performs the workflow (caption ->
 * schedule -> publish) through official OAuth without a manual download/upload step. This
 * interface keeps the seam so a different publisher can be dropped in later without touching
 * the scheduler or the admin screens.
 */
interface PublisherInterface {

	public function id(): string;

	public function title(): string;

	public function is_configured(): bool;

	/** Media kinds this publisher can emit: post, carousel, story, reel. */
	public function supports( string $kind ): bool;

	/**
	 * Publish (or hand off for publishing) a content row.
	 *
	 * @param array<string,mixed> $content Row from {prefix}igbz_ig_content.
	 * @return PublishResult
	 */
	public function publish( array $content ): PublishResult;

	/**
	 * Schedule a content row for a future timestamp.
	 *
	 * @param array<string,mixed> $content
	 * @param int                 $timestamp UTC unix timestamp.
	 */
	public function schedule( array $content, int $timestamp ): PublishResult;
}
