<?php
namespace IGBZ\Suite\Modules\Instagram\Gateways;

defined( 'ABSPATH' ) || exit;

/**
 * The Zernio connection contract (phase 49, ADR-0004 §5).
 *
 * Zernio is the project's only social gateway. The backend owns one central
 * account and provisions exactly one profile per store; this interface is the
 * seam where that happens, and it exists so the connection service stays
 * testable without live credentials — the real endpoints are verified in the
 * dedicated `PV-ZERNIO-*` phase.
 */
interface ZernioAdapterInterface {

	public function is_configured(): bool;

	/** Create the store's profile under the central account. @return array{ok:bool,profile_id:string,error:string} */
	public function create_profile( string $store_slug ): array;

	/** Issue (or rotate to) a profile-scoped key. @return array{ok:bool,key:string,error:string} */
	public function issue_profile_key( string $profile_id ): array;

	/** Revoke the profile-scoped key. @return array{ok:bool,error:string} */
	public function revoke_profile_key( string $profile_id ): array;

	/** Attach the store's Instagram account to its profile via official OAuth. @return array{ok:bool,account_id:string,instagram_account_id:string,error:string} */
	public function connect_account( string $profile_id ): array;
}
