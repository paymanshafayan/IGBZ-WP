<?php
namespace IGBZ\Suite\Modules\MultiTenant\Repository;

use IGBZ\Suite\Support\Db;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and carries the "current tenant" for the request.
 *
 * This is the WordPress equivalent of nopCommerce's IStoreContext / Store entity. A single
 * WordPress install serves many tenants; every tenant-scoped query filters on tenant_id.
 */
final class TenantContext {

	private ?Tenant $current = null;
	private bool $resolved   = false;
	private ?int $override   = null;

	public function __construct( private Db $db ) {}

	public function repository(): TenantRepository {
		return new TenantRepository( $this->db );
	}

	/** Force a tenant for the remainder of the request (CLI, cron, REST). */
	public function force( ?int $tenant_id ): void {
		$this->override = $tenant_id;
		$this->resolved = false;
		$this->current  = null;
	}

	/** Run a callback with a temporary tenant scope. */
	public function with( ?int $tenant_id, callable $callback ): mixed {
		$previous       = $this->override;
		$previous_state = [ $this->resolved, $this->current ];
		$this->force( $tenant_id );
		try {
			return $callback();
		} finally {
			$this->override = $previous;
			[ $this->resolved, $this->current ] = $previous_state;
		}
	}

	public function current(): ?Tenant {
		if ( $this->resolved ) {
			return $this->current;
		}
		$this->resolved = true;

		$repo = $this->repository();

		if ( null !== $this->override ) {
			$this->current = $this->override > 0 ? $repo->find( $this->override ) : null;
			return $this->current;
		}

		$filtered = apply_filters( 'igbz_pre_resolve_tenant', null );
		if ( $filtered instanceof Tenant ) {
			$this->current = $filtered;
			return $this->current;
		}

		$mode = (string) igbz()->settings()->get( 'general.tenant_resolution', 'domain' );

		// Phase 17: the two branches that SERVE a storefront to a visitor only ever resolve a
		// routable tenant — active, or a trial that has not expired. A suspended, closed or
		// pending store must stop answering on its URL the moment its status flips, on the
		// domain branch and on the path branch alike. The user/default fallbacks below stay
		// unfiltered on purpose: they are the admin-side context, where an owner must still
		// see a pending store.
		if ( 'domain' === $mode || 'hybrid' === $mode ) {
			$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
			$host = preg_replace( '/:\d+$/', '', $host );
			if ( $host ) {
				$candidate = $repo->find_by_domain( $host );
				if ( $candidate && $candidate->is_active() ) {
					$this->current = $candidate;
				}
			}
		}

		if ( ! $this->current && ( 'path' === $mode || 'hybrid' === $mode ) ) {
			$uri      = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			$path     = trim( (string) wp_parse_url( $uri, PHP_URL_PATH ), '/' );
			$segments = '' === $path ? [] : explode( '/', $path );
			$base     = trim( igbz()->settings()->string( 'general.tenant_path_base', 'store' ), '/' );
			$slug     = ( $segments && $segments[0] === $base ) ? ( $segments[1] ?? '' ) : ( $segments[0] ?? '' );
			if ( '' !== $slug ) {
				$candidate = $repo->find_by_slug( sanitize_title( $slug ) );
				if ( $candidate && $candidate->is_active() ) {
					$this->current = $candidate;
				}
			}
		}

		if ( ! $this->current && is_user_logged_in() ) {
			$this->current = $repo->find_primary_for_user( get_current_user_id() );
		}

		if ( ! $this->current ) {
			$default = (int) igbz()->settings()->get( 'general.default_tenant_id', 0 );
			if ( $default > 0 ) {
				$this->current = $repo->find( $default );
			}
		}

		return $this->current;
	}

	public function id(): int {
		$tenant = $this->current();
		return $tenant ? $tenant->id : 0;
	}

	public function require_tenant(): Tenant {
		$tenant = $this->current();
		if ( ! $tenant ) {
			throw new \RuntimeException( 'IGBZ Suite: no tenant could be resolved for this request.' );
		}
		return $tenant;
	}

	public function is_active(): bool {
		$tenant = $this->current();
		return $tenant && $tenant->is_active();
	}

	/** Users may only see data from tenants they belong to (or everything, for suite admins). */
	public function user_can_access( int $tenant_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( user_can( $user_id, \IGBZ\Suite\Support\Capabilities::MANAGE_TENANTS ) || user_can( $user_id, 'manage_options' ) ) {
			return true;
		}
		return $this->repository()->user_belongs_to( $user_id, $tenant_id );
	}

	/** @return int[] */
	public function accessible_tenant_ids( ?int $user_id = null ): array {
		$user_id = $user_id ?? get_current_user_id();
		return $this->repository()->tenant_ids_for_user( $user_id );
	}
}
