<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Support\Crypto;
use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 62 — Pado's memory: the three persistent layers of DESIGN-PADO §لایهٔ ۴
 * (knowledge / experience / episodic) plus the transient working memory, with
 * the four non-negotiables the design and the research demand:
 *
 *  - PROVENANCE on every entry: source class, actor, reference, trust. Team
 *    base entries are immutable; AI-sourced entries carry the lowest trust and
 *    are marked as data — never as instructions.
 *  - TENANT SCOPE at the storage layer: every read and write carries tenant_id
 *    in the SQL itself; a shared store with an application-side filter is a
 *    label, not a boundary. The only cross-tenant rows are explicitly
 *    anonymized global experience patterns.
 *  - RETENTION: working memory carries a TTL; the episodic layer a retention
 *    window; the daily sweep expires and erases — memory must age out, not
 *    accumulate (and poison must not survive forever by accident).
 *  - POISONING DEFENCE, layered at write time: memory is DATA. Entries that
 *    smell like instructions ("ignore previous", role markers, PHP/script
 *    tags) are refused outright; secrets are refused; AI/tool writes are rate
 *    capped; duplicates bump a hit counter instead of creating a new row; and
 *    the episodic layer is encrypted at rest, masked on retrieval, with every
 *    read audited.
 */
class PadoMemoryService {

	public const LAYER_KNOWLEDGE  = 'knowledge';
	public const LAYER_EXPERIENCE = 'experience';
	public const LAYER_EPISODIC   = 'episodic';
	public const LAYER_WORKING    = 'working';

	/** The seven knowledge domains, mirrored from the design. */
	public const KNOWLEDGE_DOMAINS = [ 'theme', 'business', 'ops', 'marketing', 'support', 'legal', 'security' ];

	/** The four experience sources, mirrored from the design. */
	public const EXPERIENCE_SOURCES = [ 'admin_behavior', 'customer_outcome', 'pado_performance', 'competitor' ];

	/** Source classes and the trust they earn. Team base is immutable. */
	public const SOURCE_TEAM      = 'team';       // 100, immutable
	public const SOURCE_HUMAN     = 'human';      // 80, a store operator's own words
	public const SOURCE_OUTCOME   = 'outcome';    // 70, a measured real-world result
	public const SOURCE_COMPETITOR= 'competitor'; // 40, rival behaviour
	public const SOURCE_TOOL      = 'tool';       // 40, a tool observation
	public const SOURCE_AI        = 'ai';         // 30, model output — lowest, never instructions

	private const TRUST = [
		self::SOURCE_TEAM       => 100,
		self::SOURCE_HUMAN      => 80,
		self::SOURCE_OUTCOME    => 70,
		self::SOURCE_COMPETITOR => 40,
		self::SOURCE_TOOL       => 40,
		self::SOURCE_AI         => 30,
	];

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_PROMOTED = 'promoted';
	public const STATUS_EXPIRED  = 'expired';
	public const STATUS_ERASED   = 'erased';

	public const MAX_CONTENT_CHARS = 16384;
	public const MAX_AI_WRITES_PER_DAY = 200;
	public const WORKING_TTL_HOURS    = 24;
	public const EPISODIC_RETENTION_DAYS = 365;

	/** Content that tries to be a COMMAND, not data — refused at write time. */
	private const INSTRUCTION_PATTERNS = [
		'/ignore\s+(all\s+)?(previous|prior|above)/i',
		'/disregard\s+(the\s+)?(previous|prior|above)/i',
		'/^system\s*:/im',
		'/^assistant\s*:/im',
		'/new\s+instructions?\s*:/i',
		'/you\s+are\s+now/i',
		'/<\?php/i',
		'/<script/i',
		'/act\s+as\s+(if|an?)/i',
	];

	/** Secrets never belong in memory. */
	private const SECRET_PATTERNS = [
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
		'/\b(sk|pk|rk)-[A-Za-z0-9]{16,}/',
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/(api[_-]?key|secret|password|token)\s*[:=]\s*\S{8,}/i',
	];

	public function __construct( private Db $db, private Logger $logger ) {}

	// ---------------------------------------------------------------- write

	/**
	 * Write one memory entry. Everything is validated in the backend; refusals
	 * are explicit and audited.
	 *
	 * @param array<string,mixed> $provenance {source: string, actor: string, reference?: string, anonymous?: bool}
	 * @param array<string,mixed> $options {expires_at?: string, status?: string}
	 * @return array{ok:bool,id:int,error:string,duplicate:bool}
	 */
	public function remember( int $tenant_id, string $layer, string $domain, string $title, string $content, array $provenance, array $options = [] ): array {
		$source = (string) ( $provenance['source'] ?? '' );
		$actor  = mb_substr( (string) ( $provenance['actor'] ?? 'system' ), 0, 64 );

		$refuse = fn( string $error ): array => $this->refused( $tenant_id, $layer, $error, $actor );

		if ( ! $this->valid_layer_domain( $layer, $domain ) ) {
			return $refuse( 'invalid_layer_or_domain' );
		}

		// Scope: memory belongs to a tenant. The only tenant-zero rows are
		// explicitly anonymized global experience patterns.
		$anonymous = (bool) ( $provenance['anonymous'] ?? false );
		if ( $tenant_id <= 0 && ! ( self::LAYER_EXPERIENCE === $layer && $anonymous ) ) {
			return $refuse( 'tenant_scope_required' );
		}
		if ( $tenant_id > 0 && $anonymous ) {
			return $refuse( 'anonymous_writes_are_global_only' ); // a tenant row must never claim to be anonymous
		}

		if ( '' === trim( $title ) || '' === trim( $content ) ) {
			return $refuse( 'empty_entry' );
		}
		if ( mb_strlen( $content ) > self::MAX_CONTENT_CHARS ) {
			return $refuse( 'content_too_large' );
		}
		if ( ! isset( self::TRUST[ $source ] ) ) {
			return $refuse( 'invalid_source' );
		}

		// Layer 1 of the poisoning defence: memory is data, never commands — and the
		// title flows back into prompts as readily as the content, so both ride the
		// same gate (the title is checked as its own string: its ^ anchors must work).
		foreach ( [ $content, $title ] as $field ) {
			foreach ( self::INSTRUCTION_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $field ) ) {
					$this->logger->warning( 'pado', 'Refused a memory entry that looked like instructions, not data', [ 'tenant' => $tenant_id, 'layer' => $layer, 'pattern' => $pattern ] );
					return $refuse( 'content_is_instructions_not_data' );
				}
			}
			// Credentials never enter the store — through the title either.
			foreach ( self::SECRET_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, $field ) ) {
					return $refuse( 'content_looks_like_a_secret' );
				}
			}
		}

		// Flood cap on machine-sourced writes (a poisoned tool must not be able
		// to bury a tenant's memory in an afternoon).
		if ( in_array( $source, [ self::SOURCE_AI, self::SOURCE_TOOL ], true ) && $this->machine_writes_today( $tenant_id ) >= self::MAX_AI_WRITES_PER_DAY ) {
			return $refuse( 'machine_write_cap_reached' );
		}

		$now    = current_time( 'mysql', true );
		$digest = hash( 'sha256', $layer . '|' . $domain . '|' . $content );

		// Dedupe: the same entry bumps its hit counter, it does not multiply.
		$existing = $this->db->row(
			'SELECT id, hits FROM ' . $this->db->table( 'pado_memory' ) . ' WHERE tenant_id = %d AND layer = %s AND digest = %s AND status = %s',
			$tenant_id, $layer, $digest, self::STATUS_ACTIVE
		);
		if ( $existing ) {
			$this->db->update( 'pado_memory', [ 'hits' => (int) $existing['hits'] + 1, 'updated_at' => $now ], [ 'id' => (int) $existing['id'] ] );
			$this->audit( (int) $existing['id'], $tenant_id, 'dedupe', $actor, 'seen again' );
			return [ 'ok' => true, 'id' => (int) $existing['id'], 'error' => '', 'duplicate' => true ];
		}

		$stored = self::LAYER_EPISODIC === $layer ? Crypto::encrypt( $content ) : $content;
		$meta  = [
			'source'    => $source,
			'actor'     => $actor,
			'reference' => mb_substr( (string) ( $provenance['reference'] ?? '' ), 0, 191 ),
			'anonymous' => $anonymous,
			'at'        => $now,
		];

		$id = $this->db->insert( 'pado_memory', [
			'tenant_id'   => $tenant_id,
			'layer'       => $layer,
			'domain'      => $domain,
			'title'       => mb_substr( sanitize_text_field( $title ), 0, 191 ),
			'content'     => $stored,
			'provenance'  => wp_json_encode( $meta, JSON_UNESCAPED_UNICODE ),
			'trust'       => self::TRUST[ $source ],
			'digest'      => $digest,
			'status'      => (string) ( $options['status'] ?? self::STATUS_ACTIVE ),
			'expires_at'  => $this->expiry_for( $layer, $options ),
			'created_at'  => $now,
			'updated_at'  => $now,
		] );

		if ( $id <= 0 ) {
			return $refuse( 'write_failed' );
		}

		$this->audit( $id, $tenant_id, 'write', $actor, $source );
		return [ 'ok' => true, 'id' => $id, 'error' => '', 'duplicate' => false ];
	}

	/** Shorthand for the transient context of an active task. */
	public function remember_working( int $tenant_id, string $task, string $title, string $content, string $actor = 'pado' ): array {
		return $this->remember( $tenant_id, self::LAYER_WORKING, $task, $title, $content, [ 'source' => self::SOURCE_AI, 'actor' => $actor ] );
	}

	// ----------------------------------------------------------------- read

	/**
	 * Tenant-scoped recall. Episodic rows are decrypted, masked, and audited.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function recall( int $tenant_id, string $layer, string $domain = '', string $query = '', int $limit = 25, string $reader = 'pado' ): array {
		if ( $tenant_id <= 0 || '' === $layer ) {
			return [];
		}
		$limit = max( 1, min( 100, $limit ) );

		// Anonymized global experience patterns are readable by every tenant
		// alongside its own rows; nothing else crosses the boundary.
		$scope = self::LAYER_EXPERIENCE === $layer
			? $this->db->prepare( '(tenant_id = %d OR (tenant_id = 0 AND status = %s))', $tenant_id, self::STATUS_ACTIVE )
			: $this->db->prepare( 'tenant_id = %d', $tenant_id );

		$sql = 'SELECT * FROM ' . $this->db->table( 'pado_memory' ) . ' WHERE ' . $scope . ' AND layer = %s AND status = %s';
		$args = [ $layer, self::STATUS_ACTIVE ];
		if ( '' !== $domain ) {
			$sql .= ' AND domain = %s';
			$args[] = $domain;
		}
		if ( '' !== $query ) {
			$sql .= ' AND title LIKE %s';
			$args[] = '%' . $this->db->wpdb()->esc_like( $query ) . '%';
		}
		$sql .= ' ORDER BY trust DESC, updated_at DESC LIMIT ' . $limit;

		$rows = $this->db->results( $sql, ...$args );

		$out = [];
		foreach ( $rows as $row ) {
			$entry = [
				'id'         => (int) $row['id'],
				'tenant_id'  => (int) $row['tenant_id'],
				'layer'      => (string) $row['layer'],
				'domain'     => (string) $row['domain'],
				'title'      => (string) $row['title'],
				'trust'      => (int) $row['trust'],
				'hits'       => (int) $row['hits'],
				'provenance' => json_decode( (string) $row['provenance'], true ),
			];

			if ( self::LAYER_EPISODIC === $layer ) {
				// Trust-aware retrieval: sensitive content comes back masked,
				// and the read itself is on the record.
				$entry['content'] = $this->mask( Crypto::decrypt( (string) $row['content'] ) ?? '' );
				$this->audit( (int) $row['id'], (int) $row['tenant_id'], 'read', $reader, 'episodic recall' );
			} else {
				$entry['content'] = (string) $row['content'];
			}
			$out[] = $entry;
		}

		return $out;
	}

	// ------------------------------------------------------------ lifecycle

	/**
	 * Promote a working entry into a persistent layer — the privileged state
	 * transition of the design, audited, provenance chain preserved.
	 */
	public function promote( int $tenant_id, int $memory_id, string $to_layer, string $to_domain, string $actor = 'pado' ): array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'pado_memory' ) . ' WHERE id = %d AND tenant_id = %d', $memory_id, $tenant_id );
		if ( ! $row || self::LAYER_WORKING !== (string) $row['layer'] || self::STATUS_ACTIVE !== (string) $row['status'] ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'not_a_working_entry' ];
		}
		if ( ! $this->valid_layer_domain( $to_layer, $to_domain ) ) {
			return [ 'ok' => false, 'id' => 0, 'error' => 'invalid_layer_or_domain' ];
		}

		$now = current_time( 'mysql', true );
		$this->db->update( 'pado_memory', [ 'status' => self::STATUS_PROMOTED, 'updated_at' => $now ], [ 'id' => $memory_id, 'status' => self::STATUS_ACTIVE ] );

		$provenance = json_decode( (string) $row['provenance'], true ) ?: [];
		$provenance['promoted_from'] = [ 'id' => $memory_id, 'at' => $now, 'by' => $actor ];

		$id = $this->db->insert( 'pado_memory', [
			'tenant_id'   => $tenant_id,
			'layer'       => $to_layer,
			'domain'      => $to_domain,
			'title'       => (string) $row['title'],
			'content'     => (string) $row['content'],
			'provenance'  => wp_json_encode( $provenance, JSON_UNESCAPED_UNICODE ),
			'trust'       => (int) $row['trust'],
			'digest'      => (string) $row['digest'],
			'status'      => self::STATUS_ACTIVE,
			'hits'        => 0,
			'expires_at'  => $this->expiry_for( $to_layer, [] ),
			'created_at'  => $now,
			'updated_at'  => $now,
		] );

		$this->audit( $memory_id, $tenant_id, 'promote', $actor, "to {$to_layer}/{$to_domain}" );
		return [ 'ok' => $id > 0, 'id' => $id, 'error' => $id > 0 ? '' : 'write_failed' ];
	}

	/**
	 * The retention sweep (daily housekeeping): working memory past its TTL is
	 * deleted, episodic entries past the retention window are erased (right to
	 * forget — the tombstone stays, the content does not), stale knowledge is
	 * expired. Poison does not survive by accident either.
	 *
	 * @return array<string,int>
	 */
	public function sweep(): array {
		$now = current_time( 'mysql', true );
		$counts = [ 'working_deleted' => 0, 'episodic_erased' => 0, 'knowledge_expired' => 0 ];

		$stale_working = $this->db->results( 'SELECT id, tenant_id FROM ' . $this->db->table( 'pado_memory' ) . ' WHERE layer = %s AND status = %s AND expires_at IS NOT NULL AND expires_at < %s LIMIT 500', self::LAYER_WORKING, self::STATUS_ACTIVE, $now );
		foreach ( $stale_working as $row ) {
			$this->db->delete( 'pado_memory', [ 'id' => (int) $row['id'] ] );
			$this->audit( (int) $row['id'], (int) $row['tenant_id'], 'expire', 'system', 'working ttl' );
			++$counts['working_deleted'];
		}

		$retention = max( 30, (int) get_option( 'pado.memory.episodic_retention_days', self::EPISODIC_RETENTION_DAYS ) );
		$cutoff    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $retention . ' days', (int) strtotime( $now ) ) ?: time() );
		$stale_epi = $this->db->results( 'SELECT id, tenant_id FROM ' . $this->db->table( 'pado_memory' ) . ' WHERE layer = %s AND status = %s AND created_at < %s LIMIT 500', self::LAYER_EPISODIC, self::STATUS_ACTIVE, $cutoff );
		foreach ( $stale_epi as $row ) {
			$this->erase( (int) $row['tenant_id'], (int) $row['id'], 'system' );
			++$counts['episodic_erased'];
		}

		$expired_knowledge = $this->db->results( 'SELECT id, tenant_id FROM ' . $this->db->table( 'pado_memory' ) . ' WHERE layer = %s AND status = %s AND expires_at IS NOT NULL AND expires_at < %s LIMIT 500', self::LAYER_KNOWLEDGE, self::STATUS_ACTIVE, $now );
		foreach ( $expired_knowledge as $row ) {
			$this->db->update( 'pado_memory', [ 'status' => self::STATUS_EXPIRED, 'updated_at' => $now ], [ 'id' => (int) $row['id'] ] );
			$this->audit( (int) $row['id'], (int) $row['tenant_id'], 'expire', 'system', 'knowledge stale' );
			++$counts['knowledge_expired'];
		}

		if ( array_sum( $counts ) > 0 ) {
			$this->logger->info( 'pado', 'Memory retention sweep', $counts );
		}
		return $counts;
	}

	/**
	 * Erase one entry (the right to forget): the content is gone, the tombstone
	 * and the audit trail remain.
	 */
	public function erase( int $tenant_id, int $memory_id, string $actor ): bool {
		$now = current_time( 'mysql', true );
		$flipped = $this->db->update( 'pado_memory', [
			'status'     => self::STATUS_ERASED,
			'content'    => '',
			'digest'     => '',
			'updated_at' => $now,
		], [ 'id' => $memory_id, 'tenant_id' => $tenant_id, 'status' => self::STATUS_ACTIVE ] );
		if ( $flipped <= 0 ) {
			return false;
		}
		$this->audit( $memory_id, $tenant_id, 'erase', $actor, 'content removed, tombstone kept' );
		return true;
	}

	/** Team base entries never change — the store's ground truth. */
	public function is_immutable( array $row ): bool {
		$provenance = json_decode( (string) ( $row['provenance'] ?? '' ), true );
		return self::SOURCE_TEAM === (string) ( $provenance['source'] ?? '' );
	}

	/** @return array<int,array<string,mixed>> */
	public function audit_trail( int $tenant_id, int $memory_id ): array {
		return $this->db->results( 'SELECT * FROM ' . $this->db->table( 'pado_memory_access' ) . ' WHERE memory_id = %d AND tenant_id = %d ORDER BY id ASC', $memory_id, $tenant_id );
	}

	// ------------------------------------------------------------------ util

	private function valid_layer_domain( string $layer, string $domain ): bool {
		return match ( $layer ) {
			self::LAYER_KNOWLEDGE  => in_array( $domain, self::KNOWLEDGE_DOMAINS, true ),
			self::LAYER_EXPERIENCE => in_array( $domain, self::EXPERIENCE_SOURCES, true ),
			self::LAYER_EPISODIC   => '' !== $domain,
			self::LAYER_WORKING    => '' !== $domain,
			default                => false,
		};
	}

	private function expiry_for( string $layer, array $options ): ?string {
		if ( isset( $options['expires_at'] ) && '' !== (string) $options['expires_at'] ) {
			return (string) $options['expires_at'];
		}
		return self::LAYER_WORKING === $layer
			? gmdate( 'Y-m-d H:i:s', time() + self::WORKING_TTL_HOURS * HOUR_IN_SECONDS )
			: null;
	}

	private function machine_writes_today( int $tenant_id ): int {
		$since = gmdate( 'Y-m-d 00:00:00', time() );
		return (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'pado_memory_access' ) . ' WHERE tenant_id = %d AND action = %s AND note IN (%s, %s) AND created_at >= %s',
			$tenant_id, 'write', self::SOURCE_AI, self::SOURCE_TOOL, $since
		);
	}

	/** Sensitive identifiers never come back in the clear, even to the reader. */
	private function mask( string $content ): string {
		$content = preg_replace( '/[\w.+-]+@[\w-]+\.[\w.]+/', '•••@•••', $content ) ?? $content;
		$content = preg_replace( '/\b09\d{9}\b|\+98\d{10}\b/', '•••‌•••••••', $content ) ?? $content;
		$content = preg_replace( '/\b\d{13,19}\b/', '•••• •••• ••••', $content ) ?? $content;
		return $content;
	}

	private function refused( int $tenant_id, string $layer, string $error, string $actor ): array {
		$this->audit( 0, $tenant_id, 'refuse', $actor, $layer . ': ' . $error );
		return [ 'ok' => false, 'id' => 0, 'error' => $error, 'duplicate' => false ];
	}

	private function audit( int $memory_id, int $tenant_id, string $action, string $actor, string $note ): void {
		$this->db->insert( 'pado_memory_access', [
			'memory_id'  => $memory_id,
			'tenant_id'  => $tenant_id,
			'action'     => $action,
			'actor'      => mb_substr( $actor, 0, 64 ),
			'note'       => mb_substr( $note, 0, 255 ),
			'created_at' => current_time( 'mysql', true ),
		] );
	}
}
