<?php
namespace IGBZ\Suite\Modules\Instagram\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * The assistant, as the product-registration flow needs it.
 *
 * The rebuilt product-registration agent (phase 52) is a focused class — it starts one of a few
 * tasks and reads JSON out of the answer. Naming that slice keeps the dependency honest, and it
 * is what lets a different agent be dropped in later without touching the state machine, which
 * is the same promise
 * PublisherInterface / ContentGeneratorInterface already make for the content side.
 *
 * Every task-starting method returns the provider's task id, or '' when the job was refused. They
 * are all asynchronous: the result arrives later through the webhook or the cron sweep.
 */
interface IntakeAgentInterface {

	/** @return array<string,mixed>|null */
	public function account( int $id ): ?array;

	/** @return array<int,array<string,mixed>> */
	public function accounts( int $tenant_id = 0, bool $active_only = true ): array;

	/**
	 * Grade a product photograph for background removal and video suitability.
	 *
	 * @param array<string,mixed> $account
	 */
	public function grade_photo( array $account, string $image_url, string $hint = '' ): string;

	/**
	 * Turn a shop photo into a commercial product image.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function prepare_product_image( array $account, string $image_url, array $brief = [] ): string;

	/**
	 * Write the WooCommerce listing, translated when the store is multilingual.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function write_product_copy( array $account, array $brief, string $image_url = '' ): string;

	/**
	 * Produce the Instagram video for a registered product.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function produce_product_video( array $account, array $brief, string $image_url = '' ): string;

	/**
	 * Stamp the product code onto the media and write the comment-to-DM caption.
	 *
	 * @param array<string,mixed> $account
	 * @param array<string,mixed> $brief
	 */
	public function finish_product_post( array $account, array $brief, string $image_url = '' ): string;

	/**
	 * Transcribe a voice note. The fallback speech-to-text path.
	 *
	 * @param array<string,mixed> $account
	 */
	public function transcribe_audio( array $account, string $audio_url, string $language = '' ): string;

	/**
	 * Pull a JSON object out of an agent's reply, fenced or not.
	 *
	 * @return array<string,mixed>
	 */
	public function parse_json_block( string $text ): array;
}
