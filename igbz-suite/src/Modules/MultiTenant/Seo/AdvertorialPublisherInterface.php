<?php
namespace IGBZ\Suite\Modules\MultiTenant\Seo;

defined( 'ABSPATH' ) || exit;

/**
 * Anything that can publish one advertorial to an ad network.
 * Phase 47: campaigns depend on the contract, not the Triboon adapter, so
 * the budget gate is testable on its own.
 */
interface AdvertorialPublisherInterface {

	public function is_configured(): bool;

	/** @param array<int,string> $target_media @return array{ok:bool,reference:string,message:string} */
	public function publish_advertorial( string $title, string $body_html, array $target_media = [] ): array;
}
