<?php
namespace IGBZ\Suite\Modules\Pado\Services;

use IGBZ\Suite\Support\Db;
use IGBZ\Suite\Support\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 63 — the four growth Playbooks of PROMPT-IG-GROWTH-PADO (gather /
 * analyze / strategy / produce), as versioned, auditable, roll-backable
 * artefacts, plus the run journal and the KPI learning loop around them.
 *
 * The discipline this service enforces (mirrored from the prompt's contract
 * and the phase-63 research on agent/playbook versioning):
 *
 *  - IMMUTABLE VERSIONS: a playbook change is always a NEW version row with a
 *    changelog and a content hash. Nothing is edited in place, so any past run
 *    stays reproducible from (version + model + input snapshot) and rollback
 *    is a pointer flip, not a merge.
 *  - PROVENANCE ON EVERY FACT: an input fact without {type, confidence,
 *    captured_at} never enters a run; the type must be one of the prompt's six;
 *    connected-account data is never mixed with public competitor data; PII
 *    never enters the growth memory.
 *  - STRICT OUTPUT CONTRACT: the model's output that does not satisfy the
 *    playbook's contract is REJECTED by the backend, not repaired — every
 *    suggestion must cite fact ids that exist in the input (no unbacked
 *    claims), and reality / analysis / suggestion must arrive separated.
 *  - SAFE STOP: without enough evidence the run stops honestly instead of
 *    producing a confident analysis of nothing.
 *  - COST PER ACCEPTED OUTPUT: usage of every run is journalled; the summary
 *    divides cost by accepted outputs only.
 *  - PERIODIC MAINTENANCE: the daily sweep prunes run rows past the retention
 *    window and caps retired playbook versions, never the active one or the
 *    rollback target.
 */
class GrowthPlaybookService {

	public const KIND_GATHER   = 'gather';
	public const KIND_ANALYZE  = 'analyze';
	public const KIND_STRATEGY = 'strategy';
	public const KIND_PRODUCE  = 'produce';

	public const KINDS = [ self::KIND_GATHER, self::KIND_ANALYZE, self::KIND_STRATEGY, self::KIND_PRODUCE ];

	public const STATUS_DRAFT  = 'draft';
	public const STATUS_ACTIVE = 'active';
	public const STATUS_RETIRED= 'retired';

	public const RUN_RUNNING = 'running';
	public const RUN_DONE    = 'done';
	public const RUN_STOPPED = 'stopped';
	public const RUN_FAILED  = 'failed';

	public const VERDICT_VALID    = 'valid';
	public const VERDICT_REJECTED = 'rejected';

	/** The prompt's §۱ data-contract types — nothing else may enter a run. */
	public const FACT_TYPES = [ 'connected_account', 'public', 'admin_input', 'estimate', 'model_inference', 'unavailable' ];

	/**
	 * Phase 64 hardening — the same data/command separation the memory layer enforces.
	 * A fact VALUE that tries to be a command (direct or indirect prompt injection) is
	 * refused at the gate instead of riding into the model's context.
	 */
	private const INJECTION_PATTERNS = [
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

	/** Phase 64 hardening — model output that echoes a secret is refused, never journalled as valid. */
	private const SECRET_ECHO_PATTERNS = [
		'/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
		'/\b(sk|pk|rk)-[A-Za-z0-9]{16,}/',
		'/\bAKIA[0-9A-Z]{16}\b/',
		'/(api[_-]?key|secret|password|token)\s*[:=]\s*\S{8,}/i',
	];

	/** Connected-account data must stay separate from public competitor data. */
	public const COMPETITOR_TYPES = [ 'public', 'estimate' ];

	public const MAX_VERSIONS_KEPT     = 20;
	public const RUN_RETENTION_DAYS    = 90;
	public const MIN_FACTS_FOR_ANALYSIS = 3;

	/** @var callable|null seam: fn( array $run_ctx ): array{output:array,usage:array} — the adapter in production, a stub in tests */
	private $executor;

	public function __construct(
		private Db $db,
		private Logger $logger,
		private ?PadoMemoryService $memory = null
	) {}

	public function set_executor( ?callable $executor ): void {
		$this->executor = $executor;
	}

	// ----------------------------------------------------------- versioning

	/**
	 * Append a new immutable version. The body must satisfy the playbook
	 * contract: steps, the facts contract, the output contract, and separated
	 * reality/analysis/suggestion sections — a prompt without the separation is
	 * refused (acceptance criteria of the prompt's §۸).
	 *
	 * @param array<string,mixed> $def {body:array, facts_contract?:array, output_contract?:array, tools?:array, model?:string, changelog?:string, parent_version?:int}
	 * @return array{ok:bool,id:int,version:int,error:string}
	 */
	public function create_version( int $tenant_id, string $kind, string $title, array $def, string $actor = 'team' ): array {
		if ( $tenant_id <= 0 ) {
			return $this->fail( 'tenant_scope_required' );
		}
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return $this->fail( 'invalid_kind' );
		}

		$body = $def['body'] ?? [];
		if ( ! is_array( $body ) || [] === $body ) {
			return $this->fail( 'empty_body' );
		}
		foreach ( [ 'steps', 'reality', 'analysis', 'suggestion' ] as $section ) {
			if ( ! array_key_exists( $section, $body ) ) {
				return $this->fail( 'body_must_separate_' . $section );
			}
		}

		$facts_contract   = $def['facts_contract'] ?? [ 'types' => self::FACT_TYPES ];
		$output_contract  = $def['output_contract'] ?? [ 'requires_fact_ids' => true ];

		$now = current_time( 'mysql', true );
		$parent = 0;
		if ( ! empty( $def['parent_version'] ) ) {
			$prow = $this->db->row( 'SELECT id FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s AND version = %d', $tenant_id, $kind, (int) $def['parent_version'] );
			$parent = $prow ? (int) $prow['id'] : 0;
		}
		$last = $this->db->row( 'SELECT MAX(version) AS v FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s', $tenant_id, $kind );
		$version = (int) ( $last['v'] ?? 0 ) + 1;

		$hash = hash( 'sha256', wp_json_encode( [ $kind, $body, $facts_contract, $output_contract ], JSON_UNESCAPED_UNICODE ) );

		$id = $this->db->insert( 'pado_playbooks', [
			'tenant_id'       => $tenant_id,
			'kind'            => $kind,
			'version'         => $version,
			'schema_version'  => 1,
			'title'           => mb_substr( sanitize_text_field( $title ), 0, 191 ),
			'body'            => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			'facts_contract'  => wp_json_encode( $facts_contract, JSON_UNESCAPED_UNICODE ),
			'output_contract' => wp_json_encode( $output_contract, JSON_UNESCAPED_UNICODE ),
			'tools'           => wp_json_encode( array_values( (array) ( $def['tools'] ?? [] ) ), JSON_UNESCAPED_UNICODE ),
			'model'           => mb_substr( (string) ( $def['model'] ?? '' ), 0, 120 ),
			'changelog'       => mb_substr( sanitize_text_field( (string) ( $def['changelog'] ?? '' ) ), 0, 500 ),
			'content_hash'    => $hash,
			'created_by'      => mb_substr( $actor, 0, 64 ),
			'status'          => self::STATUS_DRAFT,
			'parent_id'       => $parent,
			'created_at'      => $now,
			'updated_at'      => $now,
		] );

		if ( $id <= 0 ) {
			return $this->fail( 'write_failed' );
		}
		return [ 'ok' => true, 'id' => $id, 'version' => $version, 'error' => '' ];
	}

	/**
	 * Activation is a pointer flip: the current active version retires, the new
	 * one activates — atomically enough that a tenant never has two actives.
	 */
	public function activate( int $tenant_id, string $kind, int $version, string $actor = 'team' ): array {
		$row = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s AND version = %d', $tenant_id, $kind, $version );
		if ( ! $row ) {
			return $this->fail( 'version_not_found' );
		}
		if ( self::STATUS_RETIRED === (string) $row['status'] ) {
			return $this->fail( 'cannot_activate_retired' ); // retired-by-rollback versions need a fresh version, not a zombie flip
		}

		$now = current_time( 'mysql', true );
		$this->db->update( 'pado_playbooks', [ 'status' => self::STATUS_RETIRED, 'updated_at' => $now ], [ 'tenant_id' => $tenant_id, 'kind' => $kind, 'status' => self::STATUS_ACTIVE ] );
		$this->db->update( 'pado_playbooks', [ 'status' => self::STATUS_ACTIVE, 'updated_at' => $now ], [ 'id' => (int) $row['id'], 'status' => self::STATUS_DRAFT ] );

		$active = $this->db->results( 'SELECT id FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s AND status = %s', $tenant_id, $kind, self::STATUS_ACTIVE );
		if ( 1 !== count( $active ) || (int) $active[0]['id'] !== (int) $row['id'] ) {
			return $this->fail( 'activation_conflict' );
		}
		$this->logger->info( 'pado', 'Playbook version activated', [ 'tenant' => $tenant_id, 'kind' => $kind, 'version' => $version, 'by' => $actor ] );
		return [ 'ok' => true, 'id' => (int) $row['id'], 'version' => $version, 'error' => '' ];
	}

	/**
	 * One-call rollback: re-activate the most recently retired version of the
	 * kind. The rollback target is preserved by prune().
	 */
	public function rollback( int $tenant_id, string $kind, string $actor = 'team' ): array {
		$target = $this->db->row(
			'SELECT * FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s AND status = %s ORDER BY version DESC',
			$tenant_id, $kind, self::STATUS_RETIRED
		);
		if ( ! $target ) {
			return $this->fail( 'no_rollback_target' );
		}
		$now = current_time( 'mysql', true );
		$this->db->update( 'pado_playbooks', [ 'status' => self::STATUS_RETIRED, 'updated_at' => $now ], [ 'tenant_id' => $tenant_id, 'kind' => $kind, 'status' => self::STATUS_ACTIVE ] );
		$this->db->update( 'pado_playbooks', [ 'status' => self::STATUS_ACTIVE, 'updated_at' => $now ], [ 'id' => (int) $target['id'] ] );
		$this->logger->warning( 'pado', 'Playbook rolled back', [ 'tenant' => $tenant_id, 'kind' => $kind, 'to_version' => (int) $target['version'], 'by' => $actor ] );
		return [ 'ok' => true, 'id' => (int) $target['id'], 'version' => (int) $target['version'], 'error' => '' ];
	}

	/** Soft removal: a kind can be retired entirely (its history stays). */
	public function retire_kind( int $tenant_id, string $kind, string $actor = 'team' ): bool {
		$now = current_time( 'mysql', true );
		return $this->db->update( 'pado_playbooks', [ 'status' => self::STATUS_RETIRED, 'updated_at' => $now ], [ 'tenant_id' => $tenant_id, 'kind' => $kind, 'status' => self::STATUS_ACTIVE ] ) > 0;
	}

	/** @return array<string,mixed>|null */
	public function active_version( int $tenant_id, string $kind ): ?array {
		return $this->db->row( 'SELECT * FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s AND status = %s', $tenant_id, $kind, self::STATUS_ACTIVE );
	}

	/** @return array<int,array<string,mixed>> */
	public function versions( int $tenant_id, string $kind ): array {
		return $this->db->results( 'SELECT id, version, title, changelog, content_hash, status, created_by, created_at FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s ORDER BY version DESC', $tenant_id, $kind );
	}

	// ----------------------------------------------------------------- runs

	/**
	 * Execute the active version of a kind against validated input facts.
	 *
	 * @param array<int,array<string,mixed>> $facts each {id, type, confidence, captured_at, value, source}
	 * @param array<string,mixed> $context free run context (goals, catalog bounds…)
	 * @return array{ok:bool,run_id:int,status:string,verdict:string,error:string,output:array<string,mixed>}
	 */
	public function run( int $tenant_id, string $kind, array $facts, array $context = [], string $actor = 'pado' ): array {
		$playbook = $this->active_version( $tenant_id, $kind );
		if ( ! $playbook ) {
			return $this->fail_run( 'no_active_version' );
		}

		// ---- the data contract (prompt §۱), enforced by the backend
		$clean = [];
		foreach ( $facts as $fact ) {
			$type = (string) ( $fact['type'] ?? '' );
			if ( ! in_array( $type, self::FACT_TYPES, true ) ) {
				return $this->record_rejected( $tenant_id, $playbook, $facts, 'fact_type_out_of_contract: ' . $type, $actor );
			}
			if ( ! isset( $fact['id'], $fact['confidence'], $fact['captured_at'] ) ) {
				return $this->record_rejected( $tenant_id, $playbook, $facts, 'fact_missing_provenance', $actor );
			}
			if ( preg_match( '/[\w.+-]+@[\w-]+\.[\w.]+|\b09\d{9}\b/', (string) ( $fact['value'] ?? '' ) ) ) {
				return $this->record_rejected( $tenant_id, $playbook, $facts, 'fact_contains_pii', $actor );
			}
			foreach ( self::INJECTION_PATTERNS as $pattern ) {
				if ( preg_match( $pattern, (string) ( $fact['value'] ?? '' ) . ' ' . (string) ( $fact['source'] ?? '' ) ) ) {
					return $this->record_rejected( $tenant_id, $playbook, $facts, 'fact_is_instructions_not_data', $actor );
				}
			}
			$clean[ (string) $fact['id'] ] = $fact;
		}

		// Connected-account data must never be mixed with public competitor data.
		$types = array_unique( array_column( $clean, 'type' ) );
		$has_connected = in_array( 'connected_account', $types, true );
		$has_competitor_public = in_array( 'public', $types, true );
		if ( $has_connected && $has_competitor_public && self::KIND_ANALYZE === $kind ) {
			// market analysis of competitors runs on its own facts, never blended with the store's private data
			$competitor_facts = array_filter( $clean, fn ( array $f ): bool => in_array( $f['type'], self::COMPETITOR_TYPES, true ) );
			$clean = array_diff_key( $clean, $competitor_facts ) ?: $clean;
		}

		// ---- safe stop: not enough evidence, no confident analysis
		if ( in_array( $kind, [ self::KIND_ANALYZE, self::KIND_STRATEGY ], true ) && count( $clean ) < self::MIN_FACTS_FOR_ANALYSIS ) {
			$run_id = $this->insert_run( $tenant_id, $playbook, $facts, [], 'insufficient_evidence', $actor, self::RUN_STOPPED );
			return [ 'ok' => false, 'run_id' => $run_id, 'status' => self::RUN_STOPPED, 'verdict' => '', 'error' => 'insufficient_evidence', 'output' => [] ];
		}

		if ( null === $this->executor ) {
			$run_id = $this->insert_run( $tenant_id, $playbook, $facts, [], 'provider_not_configured', $actor, self::RUN_STOPPED );
			return [ 'ok' => false, 'run_id' => $run_id, 'status' => self::RUN_STOPPED, 'verdict' => '', 'error' => 'provider_not_configured', 'output' => [] ];
		}

		$ctx = [ 'playbook' => $playbook, 'facts' => array_values( $clean ), 'context' => $context ];
		try {
			$result = ( $this->executor )( $ctx );
		} catch ( \Throwable $e ) {
			// Model outage: journal the failure honestly, never leave a half-run behind.
			$run_id = $this->insert_run( $tenant_id, $playbook, $facts, [], [ 'error' => $e->getMessage() ], $actor, self::RUN_FAILED, self::VERDICT_REJECTED, 'provider_error' );
			$this->logger->error( 'pado', 'Playbook run failed on a provider error', [ 'tenant' => $tenant_id, 'kind' => $kind, 'error' => $e->getMessage() ] );
			return [ 'ok' => false, 'run_id' => $run_id, 'status' => self::RUN_FAILED, 'verdict' => self::VERDICT_REJECTED, 'error' => 'provider_error', 'output' => [] ];
		}
		$output = is_array( $result['output'] ?? null ) ? $result['output'] : [];
		$usage  = is_array( $result['usage'] ?? null ) ? $result['usage'] : [];

		// Phase 64 hardening: output that echoes a credential is refused no matter how
		// well-cited it is — the journal must never become a secret store.
		foreach ( self::SECRET_ECHO_PATTERNS as $pattern ) {
			if ( preg_match( $pattern, (string) wp_json_encode( $output, JSON_UNESCAPED_UNICODE ) ) ) {
				// The journal records THAT a secret was echoed and why it was refused —
				// never the bytes themselves. The run log must not become a secret store.
				$redacted = [ 'redacted' => true, 'reason' => 'output_contains_secret' ];
				$run_id = $this->insert_run( $tenant_id, $playbook, $facts, $redacted, $usage, $actor, self::RUN_DONE, self::VERDICT_REJECTED, 'output_contains_secret' );
				return [ 'ok' => false, 'run_id' => $run_id, 'status' => self::RUN_DONE, 'verdict' => self::VERDICT_REJECTED, 'error' => 'output_contains_secret', 'output' => [] ];
			}
		}

		// ---- the output contract (prompt §۸), enforced by the backend
		$violation = $this->output_violation( $output, $clean );
		if ( '' !== $violation ) {
			$run_id = $this->insert_run( $tenant_id, $playbook, $facts, $output, $usage, $actor, self::RUN_DONE, self::VERDICT_REJECTED, $violation );
			return [ 'ok' => false, 'run_id' => $run_id, 'status' => self::RUN_DONE, 'verdict' => self::VERDICT_REJECTED, 'error' => $violation, 'output' => $output ];
		}

		$run_id = $this->insert_run( $tenant_id, $playbook, $facts, $output, $usage, $actor, self::RUN_DONE, self::VERDICT_VALID );
		return [ 'ok' => true, 'run_id' => $run_id, 'status' => self::RUN_DONE, 'verdict' => self::VERDICT_VALID, 'error' => '', 'output' => $output ];
	}

	/** The strict gate: sections separated, every suggestion cites real facts. */
	private function output_violation( array $output, array $facts ): string {
		foreach ( [ 'reality', 'analysis', 'suggestion' ] as $section ) {
			if ( ! array_key_exists( $section, $output ) ) {
				return 'output_missing_' . $section;
			}
		}
		$suggestions = is_array( $output['suggestion'] ?? null ) ? $output['suggestion'] : [ $output['suggestion'] ?? null ];
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				return 'suggestion_not_structured';
			}
			$cites = (array) ( $suggestion['fact_ids'] ?? [] );
			if ( [] === $cites ) {
				return 'suggestion_without_fact_ids';
			}
			foreach ( $cites as $fact_id ) {
				if ( ! isset( $facts[ (string) $fact_id ] ) ) {
					return 'unbacked_claim: ' . $fact_id;
				}
			}
		}
		return '';
	}

	// ------------------------------------------------------- the KPI loop

	/**
	 * Real metrics from the store's own tables: stored insight metrics over the
	 * window plus funnel conversions. WooCommerce revenue joins when Woo is
	 * loaded (honest degradation otherwise — the number is reported as
	 * unavailable, never guessed).
	 *
	 * @return array<string,mixed>
	 */
	public function kpi_snapshot( int $tenant_id, int $days = 30 ): array {
		$since = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );
		$rows = $this->db->results(
			'SELECT metric, COUNT(*) AS n, SUM(value) AS total FROM ' . $this->db->table( 'ig_insights' ) . ' WHERE tenant_id = %d AND captured_for >= %s GROUP BY metric',
			$tenant_id, $since
		);
		$metrics = [];
		foreach ( $rows as $row ) {
			$metrics[ (string) $row['metric'] ] = [ 'sum' => (float) $row['total'], 'days' => (int) $row['n'] ];
		}

		$conversions = (int) $this->db->scalar(
			'SELECT COUNT(*) FROM ' . $this->db->table( 'ig_funnel_hits' ) . ' h JOIN ' . $this->db->table( 'ig_funnels' ) . ' f ON f.id = h.funnel_id WHERE f.tenant_id = %d AND h.occurred_at >= %s',
			$tenant_id, $since . ' 00:00:00'
		);

		$revenue = null;
		if ( function_exists( 'wc_get_orders' ) ) {
			$orders = wc_get_orders( [ 'limit' => -1, 'status' => [ 'completed', 'processing' ], 'date_created' => '>' . strtotime( $since ) ] );
			$revenue = array_sum( array_map( 'floatval', array_column( array_map( fn ( $o ) => [ 't' => $o->get_total() ], is_array( $orders ) ? $orders : [] ), 't' ) ) );
		}

		return [ 'window_days' => $days, 'insights' => $metrics, 'funnel_conversions' => $conversions, 'woo_revenue' => $revenue, 'woo_revenue_available' => null !== $revenue ];
	}

	/**
	 * Close the loop: compare the run's forecast with the measured actuals and
	 * distill the refinement into the tenant's experience memory — no PII, only
	 * what the real numbers say. Unknown outcomes never become blind retries.
	 *
	 * @param array<string,float> $actuals metric => measured value
	 */
	public function learn( int $tenant_id, int $run_id, array $actuals, string $actor = 'system' ): array {
		$run = $this->db->row( 'SELECT * FROM ' . $this->db->table( 'pado_playbook_runs' ) . ' WHERE id = %d AND tenant_id = %d', $run_id, $tenant_id );
		if ( ! $run ) {
			return [ 'ok' => false, 'error' => 'run_not_found' ];
		}
		$output = json_decode( (string) ( $run['output'] ?? '' ), true ) ?: [];
		$forecast = is_array( $output['forecast'] ?? null ) ? $output['forecast'] : [];

		$comparisons = [];
		foreach ( $forecast as $metric => $predicted ) {
			if ( ! isset( $actuals[ $metric ] ) ) {
				continue; // unknown outcome: recorded as unknown, never retried blind
			}
			$actual = (float) $actuals[ $metric ];
			$comparisons[ $metric ] = [
				'forecast' => (float) $predicted,
				'actual'   => $actual,
				'delta'    => $actual - (float) $predicted,
				'ratio'    => (float) $predicted > 0 ? round( $actual / (float) $predicted, 3 ) : null,
			];
		}

		$learned = 0;
		if ( null !== $this->memory && [] !== $comparisons ) {
			$note = 'مقایسهٔ پیش‌بینی و نتیجهٔ اجرای ' . (string) $run['kind'] . ' نسخهٔ ' . (string) $run['playbook_version'] . ': ' . wp_json_encode( $comparisons, JSON_UNESCAPED_UNICODE );
			$r = $this->memory->remember( $tenant_id, 'experience', 'pado_performance', 'حلقهٔ یادگیری رشد', $note, [ 'source' => 'outcome', 'actor' => $actor, 'reference' => 'run:' . $run_id ] );
			$learned = $r['ok'] ? 1 : 0;
		}

		$this->db->update( 'pado_playbook_runs', [ 'correlation_key' => 'learned:' . gmdate( 'Y-m-d', time() ) ], [ 'id' => $run_id ] );
		$this->logger->info( 'pado', 'Playbook run learned', [ 'tenant' => $tenant_id, 'run' => $run_id, 'metrics' => array_keys( $comparisons ), 'memory' => $learned ] );
		return [ 'ok' => true, 'error' => '', 'comparisons' => $comparisons, 'memory_written' => $learned ];
	}

	/** Cost per accepted output only — the prompt's §۸ economics. */
	public function cost_summary( int $tenant_id, int $days = 30 ): array {
		$since = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . (int) $days . ' days' ) );
		$rows = $this->db->results( 'SELECT verdict, usage_json FROM ' . $this->db->table( 'pado_playbook_runs' ) . ' WHERE tenant_id = %d AND created_at >= %s AND status = %s', $tenant_id, $since, self::RUN_DONE );
		$total = 0.0; $accepted = 0; $rejected = 0;
		foreach ( $rows as $row ) {
			$usage = json_decode( (string) ( $row['usage_json'] ?? '' ), true ) ?: [];
			$total += (float) ( $usage['estimated_cost'] ?? 0 );
			self::VERDICT_VALID === (string) $row['verdict'] ? ++$accepted : ++$rejected;
		}
		return [ 'runs' => count( $rows ), 'accepted' => $accepted, 'rejected' => $rejected, 'total_cost' => round( $total, 6 ), 'cost_per_accepted' => $accepted > 0 ? round( $total / $accepted, 6 ) : null ];
	}

	/**
	 * Periodic maintenance (daily housekeeping): prune run rows past the
	 * retention window; cap retired playbook versions per kind — never the
	 * active version and never the newest retired one (the rollback target).
	 *
	 * @return array<string,int>
	 */
	public function prune(): array {
		$counts = [ 'runs_pruned' => 0, 'versions_pruned' => 0 ];
		$retention = max( 14, (int) get_option( 'pado.playbook.run_retention_days', self::RUN_RETENTION_DAYS ) );
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $retention . ' days' ) );

		$stale = $this->db->results( 'SELECT id FROM ' . $this->db->table( 'pado_playbook_runs' ) . ' WHERE created_at < %s LIMIT 500', $cutoff );
		foreach ( $stale as $row ) {
			$this->db->delete( 'pado_playbook_runs', [ 'id' => (int) $row['id'] ] );
			++$counts['runs_pruned'];
		}

		$kinds = $this->db->results( 'SELECT DISTINCT tenant_id, kind FROM ' . $this->db->table( 'pado_playbooks' ), [] );
		foreach ( $kinds as $pair ) {
			$retired = $this->db->results( 'SELECT id FROM ' . $this->db->table( 'pado_playbooks' ) . ' WHERE tenant_id = %d AND kind = %s AND status = %s ORDER BY version DESC', (int) $pair['tenant_id'], (string) $pair['kind'], self::STATUS_RETIRED );
			array_shift( $retired ); // index 0 = the rollback target — it stays
			// the cap counts the whole kind: 1 active + 1 rollback target + the rest
			$excess = array_slice( $retired, self::MAX_VERSIONS_KEPT - 2 );
			foreach ( $excess as $row ) {
				$this->db->delete( 'pado_playbooks', [ 'id' => (int) $row['id'] ] );
				++$counts['versions_pruned'];
			}
		}

		if ( array_sum( $counts ) > 0 ) {
			$this->logger->info( 'pado', 'Playbook maintenance sweep', $counts );
		}
		return $counts;
	}

	// ---------------------------------------------------------------- util

	private function insert_run( int $tenant_id, array $playbook, array $facts, array $output, array|string $usage, string $actor, string $status, string $verdict = self::VERDICT_REJECTED, string $reason = '' ): int {
		$now = current_time( 'mysql', true );
		$id = $this->db->insert( 'pado_playbook_runs', [
			'tenant_id'        => $tenant_id,
			'playbook_id'      => (int) $playbook['id'],
			'kind'             => (string) $playbook['kind'],
			'playbook_version' => (int) $playbook['version'],
			'schema_version'   => (int) $playbook['schema_version'],
			'model'            => (string) ( $playbook['model'] ?? '' ),
			'input_snapshot'   => wp_json_encode( $facts, JSON_UNESCAPED_UNICODE ),
			'output'           => wp_json_encode( $output, JSON_UNESCAPED_UNICODE ),
			'facts'            => wp_json_encode( array_column( $facts, 'id' ), JSON_UNESCAPED_UNICODE ),
			'verdict'          => '' === $verdict && self::RUN_DONE === $status ? self::VERDICT_REJECTED : ( '' === $verdict ? 'none' : $verdict ),
			'rejection_reason' => mb_substr( $reason, 0, 255 ),
			'usage_json'       => is_array( $usage ) ? wp_json_encode( $usage, JSON_UNESCAPED_UNICODE ) : (string) $usage,
			'status'           => $status,
			'correlation_key'  => 'run:' . $now,
			'created_at'       => $now,
			'finished_at'      => $now,
		] );
		$this->logger->info( 'pado', 'Playbook run journalled', [ 'tenant' => $tenant_id, 'kind' => (string) $playbook['kind'], 'version' => (int) $playbook['version'], 'status' => $status, 'verdict' => $verdict ?: 'none', 'by' => $actor, 'reason' => $reason ] );
		return $id;
	}

	private function record_rejected( int $tenant_id, array $playbook, array $facts, string $reason, string $actor ): array {
		$run_id = $this->insert_run( $tenant_id, $playbook, $facts, [], 'input rejected: ' . $reason, $actor, self::RUN_DONE, self::VERDICT_REJECTED, $reason );
		return [ 'ok' => false, 'run_id' => $run_id, 'status' => self::RUN_DONE, 'verdict' => self::VERDICT_REJECTED, 'error' => $reason, 'output' => [] ];
	}

	private function fail( string $error ): array {
		return [ 'ok' => false, 'id' => 0, 'version' => 0, 'error' => $error ];
	}

	private function fail_run( string $error ): array {
		return [ 'ok' => false, 'run_id' => 0, 'status' => '', 'verdict' => '', 'error' => $error, 'output' => [] ];
	}
}
