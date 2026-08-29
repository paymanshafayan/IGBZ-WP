<?php
/**
 * Phase 63 — the four growth Playbooks: immutable versioning with rollback,
 * the provenance-gated run journal, the strict output contract (no unbacked
 * claims), safe stop without evidence, cost per accepted output, the KPI
 * learning loop into the tenant's memory, and periodic maintenance.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\Pado\Services\GrowthPlaybookService;
use IGBZ\Suite\Modules\Pado\Services\PadoMemoryService;
use IGBZ\Suite\Support\Db;

/** Flat-store double for the two playbook tables (memory tables come from PadoMemoryTest's double). */
final class PlaybookStoreDb extends MemoryStoreDb {
	public array $playbooks = [];
	public array $playbook_runs = [];
	/** @var array<int,array<string,mixed>> ig_insights rows for the KPI snapshot */
	public array $insights = [];
	public array $funnels = [];
	public array $funnel_hits = [];
	protected function boot_playbook_store(): void {}

	public function insert( string $table, array $data, $format = null ): int|bool {
		if ( str_contains( $table, 'igbz_pado_playbook_runs' ) ) {
			$data['id'] = $this->next_id++;
			$this->playbook_runs[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		if ( str_contains( $table, 'igbz_pado_playbooks' ) ) {
			$data['id'] = $this->next_id++;
			$this->playbooks[ $data['id'] ] = $data;
			$this->insert_id = $data['id'];
			return 1;
		}
		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		if ( str_contains( $table, 'igbz_pado_playbook_runs' ) || str_contains( $table, 'igbz_pado_playbooks' ) ) {
			$store = str_contains( $table, 'runs' ) ? 'playbook_runs' : 'playbooks';
			$changed = 0;
			foreach ( $this->$store as $id => $row ) {
				if ( ! $this->row_where( $row, $where ) ) { continue; }
				$this->{$store}[ $id ] = array_merge( $row, $data );
				++$changed;
			}
			return $changed;
		}
		return parent::update( $table, $data, $where, $format, $where_format );
	}

	public function delete( string $table, array $where, $format = null ): int|bool {
		if ( str_contains( $table, 'igbz_pado_playbook_runs' ) || str_contains( $table, 'igbz_pado_playbooks' ) ) {
			$store = str_contains( $table, 'runs' ) ? 'playbook_runs' : 'playbooks';
			$removed = 0;
			foreach ( $this->$store as $id => $row ) {
				if ( ! $this->row_where( $row, $where ) ) { continue; }
				unset( $this->{$store}[ $id ] );
				++$removed;
			}
			return $removed;
		}
		return parent::delete( $table, $where, $format );
	}

	public function get_row( string $sql, ...$args ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'igbz_pado_playbooks' ) ) {
			// MAX(version) AS v and single-row selects both resolve against the flat store
			if ( preg_match( '/MAX\(version\)/', $sql ) ) {
				$versions = array_map( 'intval', array_column( array_filter( $this->playbooks, fn ( array $r ): bool => $this->row_where( $r, $this->where_pairs( $sql ) ) ), 'version' ) );
				return [ 'v' => $versions ? max( $versions ) : 0 ];
			}
			foreach ( $this->playbooks as $row ) {
				if ( $this->row_where( $row, $this->where_pairs( $sql ) ) ) { return $row; }
			}
			return null;
		}
		if ( str_contains( $sql, 'igbz_pado_playbook_runs' ) ) {
			foreach ( $this->playbook_runs as $row ) {
				if ( $this->row_where( $row, $this->where_pairs( $sql ) ) ) { return $row; }
			}
			return null;
		}
		return parent::get_row( $sql, ...$args );
	}

	public function get_results( string $sql, ...$args ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'igbz_pado_playbooks' ) ) {
			if ( str_contains( $sql, 'DISTINCT' ) ) {
				$out = [];
				foreach ( $this->playbooks as $row ) {
					$out[ $row['tenant_id'] . ':' . $row['kind'] ] = [ 'tenant_id' => (int) $row['tenant_id'], 'kind' => (string) $row['kind'] ];
				}
				return array_values( $out );
			}
			$found = array_values( array_filter( $this->playbooks, fn ( array $r ): bool => $this->row_where( $r, $this->where_pairs( $sql ) ) ) );
			usort( $found, fn ( array $a, array $b ): int => (int) $b['version'] <=> (int) $a['version'] ); // ORDER BY version DESC
			return $found;
		}
		if ( str_contains( $sql, 'igbz_pado_playbook_runs' ) ) {
			return array_values( array_filter( $this->playbook_runs, fn ( array $r ): bool => $this->row_where( $r, $this->where_pairs( $sql ) ) ) );
		}
		if ( str_contains( $sql, 'igbz_pado_memory_access' ) ) {
			return parent::get_results( $sql, ...$args );
		}
		if ( str_contains( $sql, 'igbz_ig_insights' ) ) {
			// GROUP BY metric, COUNT(*) n, SUM(value) total — like the real SQL
			$agg = [];
			foreach ( $this->insights as $r ) {
				if ( ! $this->row_where( $r, $this->where_pairs( $sql ) ) ) { continue; }
				$m = (string) $r['metric'];
				$agg[ $m ] = $agg[ $m ] ?? [ 'metric' => $m, 'n' => 0, 'total' => 0.0 ];
				++$agg[ $m ]['n'];
				$agg[ $m ]['total'] += (float) $r['value'];
			}
			return array_values( $agg );
		}
		if ( str_contains( $sql, 'igbz_ig_funnel_hits' ) ) {
			return array_values( array_filter( $this->funnel_hits, fn ( array $r ): bool => $this->row_where( $r, $this->where_pairs( $sql ) ) ) );
		}
		return parent::get_results( $sql, ...$args );
	}

	public function get_var( string $sql, ...$args ) {
		$this->queries[] = $sql;
		if ( str_contains( $sql, 'igbz_ig_funnel_hits' ) ) {
			return (string) count( array_filter( $this->funnel_hits, fn ( array $r ): bool => $this->row_where( $r, $this->where_pairs( $sql ), true ) ) );
		}
		return parent::get_var( $sql, ...$args );
	}

	/** Extract the WHERE column = 'value' pairs from a prepared SQL string. */
	private function where_pairs( string $sql ): array {
		preg_match_all( "/([a-z_]+) = '([^']*)'/", $sql, $pairs, PREG_SET_ORDER );
		$out = [];
		foreach ( $pairs as $p ) { $out[ $p[1] ] = $p[2]; }
		if ( preg_match( "/captured_for >= '([^']+)'/", $sql, $c ) ) { $out['captured_for>='] = $c[1]; }
		if ( preg_match( "/occurred_at >= '([^']+)'/", $sql, $c ) ) { $out['occurred_at>='] = $c[1]; }
		if ( preg_match( "/created_at < '([^']+)'/", $sql, $c ) ) { $out['created_at<'] = $c[1]; }
		return $out;
	}

	private function row_where( array $row, array $where ): bool {
		foreach ( $where as $column => $value ) {
			$op = '=';
			if ( str_ends_with( $column, '>=' ) ) { $column = substr( $column, 0, -2 ); $op = '>='; }
			if ( str_ends_with( $column, '<' ) ) { $column = substr( $column, 0, -1 ); $op = '<'; }
			$actual = (string) ( $row[ $column ] ?? '' );
			$expect = (string) $value; // int 1 and string '1' are the same value in SQL
			if ( '>=' === $op && $actual < $expect ) { return false; }
			if ( '<' === $op && $actual >= $expect ) { return false; }
			if ( '=' === $op && $actual !== $expect ) { return false; }
		}
		return true;
	}
}

final class GrowthPlaybookTest extends TestCase {

	private PlaybookStoreDb $db;
	private GrowthPlaybookService $pb;
	private PadoMemoryService $mem;

	private function body(): array {
		return [
			'steps'      => [ 'گردآوری', 'اعتبارسنجی', 'گزارش' ],
			'reality'    => 'فقط دادهٔ معتبر',
			'analysis'   => 'تحلیل تفکیک‌شده',
			'suggestion' => 'پیشنهاد با ارجاع به fact',
		];
	}

	private function facts( int $n = 4 ): array {
		$out = [];
		for ( $i = 1; $i <= $n; $i++ ) {
			$out[] = [ 'id' => 'f' . $i, 'type' => 'connected_account', 'confidence' => 0.9, 'captured_at' => '2026-08-01', 'value' => 'متریک ' . $i, 'source' => 'ig_insights' ];
		}
		return $out;
	}

	/** good model output: separated sections, suggestions cite real facts */
	private function good_output(): array {
		return [
			'reality'    => [ 'ردیف واقعیت' ],
			'analysis'   => [ 'ردیف تحلیل' ],
			'suggestion' => [ [ 'what' => 'ارسال ساعت ۲۱', 'fact_ids' => [ 'f1', 'f2' ] ] ],
			'forecast'   => [ 'views' => 1200.0 ],
		];
	}

	public function run(): void {
		$this->versions_are_immutable_and_hashed();
		$this->activation_flips_and_never_doubles();
		$this->rollback_reactivates_the_previous_version();
		$this->kind_retirement_and_history();
		$this->runs_require_provenance_on_every_fact();
		$this->pii_never_enters_a_run();
		$this->unknown_fact_types_are_rejected();
		$this->no_confident_analysis_without_evidence();
		$this->provider_gap_stops_safely();
		$this->out_of_contract_output_is_rejected_not_repaired();
		$this->unbacked_claims_are_rejected();
		$this->the_learning_loop_writes_refined_experience();
		$this->kpi_snapshot_reads_the_real_tables();
		$this->cost_counts_only_accepted_outputs();
		$this->maintenance_prunes_but_protects_rollback_targets();
	}

	// ------------------------------------------------------------ scenarios

	private function versions_are_immutable_and_hashed(): void {
		$this->fresh();
		$r = $this->pb->create_version( 1, 'gather', 'گردآوری', [ 'body' => $this->body(), 'changelog' => 'نسخهٔ نخست', 'model' => 'deepinfra/x' ] );
		$this->assert_true( $r['ok'], 'the first version lands' , 'the invariant holds' );
		$this->assert_same( 1, $r['version'], 'versioning starts at one' );
		$row = $this->db->playbooks[ $r['id'] ];
		$this->assert_true( 64 === strlen( (string) $row['content_hash'] ), 'every version carries a content hash' );
		$this->assert_same( 'draft', (string) $row['status'], 'a new version starts as draft' );

		$r2 = $this->pb->create_version( 1, 'gather', 'گردآوری', [ 'body' => array_merge( $this->body(), [ 'steps' => [ 'یک مرحلهٔ اضافه' ] ] ), 'changelog' => 'افزودن مرحله', 'parent_version' => 1 ] );
		$this->assert_same( 2, $r2['version'], 'a change is a new version, never an edit' , 'the invariant holds' );
		$this->assert_same( (int) $this->db->playbooks[ $r2['id'] ]['parent_id'], $r['id'], 'lineage is recorded' );
		$this->assert_not_same( (string) $this->db->playbooks[ $r2['id'] ]['content_hash'], (string) $row['content_hash'], 'the hash proves the bodies differ' );

		$bad = $this->pb->create_version( 1, 'gather', 'بدون تفکیک', [ 'body' => [ 'steps' => [ 'یک' ] ] ] );
		$this->assert_false( $bad['ok'], 'a body without the reality/analysis/suggestion separation is refused' );
		$this->assert_same( 'body_must_separate_reality', $bad['error'], 'the invariant holds' );

		$badkind = $this->pb->create_version( 1, 'vibes', 'نامعتبر', [ 'body' => $this->body() ] );
		$this->assert_false( $badkind['ok'], 'the kinds are the closed four' );
		$this->assert_false( $this->pb->create_version( 0, 'gather', 'بدون مستأجر', [ 'body' => $this->body() ] )['ok'], 'tenant scope is required' );
	}

	private function activation_flips_and_never_doubles(): void {
		$this->fresh();
		$v1 = $this->pb->create_version( 1, 'produce', 'تولید', [ 'body' => $this->body() ] );
		$v2 = $this->pb->create_version( 1, 'produce', 'تولید', [ 'body' => $this->body() ] );
		$this->assert_true(  $this->pb->activate( 1, 'produce', 1 )['ok'] , 'the invariant holds' );
		$this->assert_true(  $this->pb->activate( 1, 'produce', 2 )['ok'] , 'the invariant holds' );
		$actives = array_filter( $this->db->playbooks, fn ( array $r ): bool => 'produce' === $r['kind'] && 'active' === $r['status'] );
		$this->assert_same( 1, count( $actives ), 'exactly one active version per kind — never two' , 'the invariant holds' );
		$this->assert_same( 2, (int) reset( $actives )['version'], 'the newest activation wins' );
		$this->assert_same( 2, (int) $this->pb->active_version( 1, 'produce' )['version'], 'the invariant holds' );

		$this->assert_false( $this->pb->activate( 1, 'produce', 99 )['ok'], 'activating a missing version is refused' );
	}

	private function rollback_reactivates_the_previous_version(): void {
		$this->fresh();
		$this->pb->create_version( 1, 'strategy', 'راهبرد', [ 'body' => $this->body(), 'changelog' => 'v1' ] );
		$this->pb->create_version( 1, 'strategy', 'راهبرد', [ 'body' => $this->body(), 'changelog' => 'v2 بد شد' ] );
		$this->pb->activate( 1, 'strategy', 1 );
		$this->pb->activate( 1, 'strategy', 2 );
		$rb = $this->pb->rollback( 1, 'strategy' );
		$this->assert_true(  $rb['ok'], 'one call rolls the kind back' , 'the invariant holds' , 'the invariant holds' );
		$this->assert_same( 1, $rb['version'], 'the rollback target is the most recent retired version' );
		$this->assert_same( 1, (int) $this->pb->active_version( 1, 'strategy' )['version'], 'the invariant holds' );
		$actives = array_filter( $this->db->playbooks, fn ( array $r ): bool => 'strategy' === $r['kind'] && 'active' === $r['status'] );
		$this->assert_same( 1, count( $actives ), 'still exactly one active after rollback' , 'the invariant holds' );
	}

	private function kind_retirement_and_history(): void {
		$this->fresh();
		$this->pb->create_version( 1, 'analyze', 'تحلیل', [ 'body' => $this->body() ] );
		$this->pb->activate( 1, 'analyze', 1 );
		$this->assert_true(  $this->pb->retire_kind( 1, 'analyze' ), 'a kind can be retired entirely' , 'the invariant holds' , 'the invariant holds' );
		$this->assert_same( null, $this->pb->active_version( 1, 'analyze' ), 'retired kind has no active version' );
		$this->assert_same( 1, count( $this->pb->versions( 1, 'analyze' ) ), 'the history survives retirement' );
	}

	private function runs_require_provenance_on_every_fact(): void {
		$this->fresh();
		$this->seed_active( 'analyze' );
		$no_prov = $this->facts();
		unset( $no_prov[1]['captured_at'] );
		$r = $this->pb->run( 1, 'analyze', $no_prov );
		$this->assert_false( $r['ok'], 'a fact without provenance never enters a run' );
		$this->assert_same( 'fact_missing_provenance', $r['error'], 'the invariant holds' );
		$run = $this->db->playbook_runs[ $r['run_id'] ];
		$this->assert_same( 'rejected', (string) $run['verdict'], 'the refusal itself is journalled' );
	}

	private function pii_never_enters_a_run(): void {
		$this->fresh();
		$this->seed_active( 'gather' );
		$facts = $this->facts();
		$facts[0]['value'] = 'مشتری با user@shop.com تماس گرفت';
		$r = $this->pb->run( 1, 'gather', $facts );
		$this->assert_false( $r['ok'], 'PII never enters the growth memory' );
		$this->assert_same( 'fact_contains_pii', $r['error'], 'the invariant holds' );
	}

	private function unknown_fact_types_are_rejected(): void {
		$this->fresh();
		$this->seed_active( 'gather' );
		$facts = $this->facts();
		$facts[0]['type'] = 'psychic';
		$r = $this->pb->run( 1, 'gather', $facts );
		$this->assert_false( $r['ok'], 'the data contract is the closed six types' );
		$this->assert_same( true, str_starts_with( $r['error'], 'fact_type_out_of_contract' ), 'the invariant holds' );
	}

	private function no_confident_analysis_without_evidence(): void {
		$this->fresh();
		$this->seed_active( 'analyze' );
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $this->good_output(), 'usage' => [ 'estimated_cost' => 0.01 ] ] );
		$r = $this->pb->run( 1, 'analyze', array_slice( $this->facts(), 0, 2 ) );
		$this->assert_false( $r['ok'], 'two facts are not enough for a confident analysis' );
		$this->assert_same( 'stopped', $r['status'], 'the run stops, it does not guess' );
		$this->assert_same( 'insufficient_evidence', $r['error'], 'the invariant holds' );
		$this->assert_same( 0, count( $this->db->memory ), 'nothing was learned from nothing' );
	}

	private function provider_gap_stops_safely(): void {
		$this->fresh();
		$this->seed_active( 'gather' );
		$r = $this->pb->run( 1, 'gather', $this->facts() );
		$this->assert_false( $r['ok'], 'no provider, no pretend run' );
		$this->assert_same( 'provider_not_configured', $r['error'], 'the invariant holds' );
		$this->assert_same( 'stopped', $r['status'], 'a safe stop, not a failure storm' );
	}

	private function out_of_contract_output_is_rejected_not_repaired(): void {
		$this->fresh();
		$this->seed_active( 'produce' );
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => [ 'reality' => [ 'x' ] ], 'usage' => [ 'estimated_cost' => 0.02 ] ] );
		$r = $this->pb->run( 1, 'produce', $this->facts() );
		$this->assert_false( $r['ok'], 'output outside the contract is rejected, never repaired' );
		$this->assert_same( 'output_missing_analysis', $r['error'], 'the invariant holds' );
		$run = $this->db->playbook_runs[ $r['run_id'] ];
		$this->assert_same( 'rejected', (string) $run['verdict'], 'the invariant holds' );
	}

	private function unbacked_claims_are_rejected(): void {
		$this->fresh();
		$this->seed_active( 'strategy' );
		$bad = $this->good_output();
		$bad['suggestion'][0]['fact_ids'] = [ 'f1', 'ghost' ];
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $bad, 'usage' => [ 'estimated_cost' => 0.02 ] ] );
		$r = $this->pb->run( 1, 'strategy', $this->facts() );
		$this->assert_false( $r['ok'], 'a suggestion citing a fact that does not exist is refused' );
		$this->assert_same( true, str_starts_with( $r['error'], 'unbacked_claim' ), 'the invariant holds' );

		$nocite = $this->good_output();
		$nocite['suggestion'][0]['fact_ids'] = [];
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $nocite, 'usage' => [ 'estimated_cost' => 0.02 ] ] );
		$r2 = $this->pb->run( 1, 'strategy', $this->facts() );
		$this->assert_false( $r2['ok'], 'every suggestion must cite its facts' );
		$this->assert_same( 'suggestion_without_fact_ids', $r2['error'], 'the invariant holds' );

		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $this->good_output(), 'usage' => [ 'estimated_cost' => 0.03 ] ] );
		$r3 = $this->pb->run( 1, 'strategy', $this->facts() );
		$this->assert_true(  $r3['ok'], 'the well-cited output passes the gate' , 'the invariant holds' , 'the invariant holds' );
		$run = $this->db->playbook_runs[ $r3['run_id'] ];
		$this->assert_same( 'valid', (string) $run['verdict'], 'the invariant holds' );
		$this->assert_same( 1, (int) $run['playbook_version'], 'the run journal pins the exact playbook version' );
		$this->assert_same( true, str_contains( (string) $run['input_snapshot'], 'f1' ), 'the input snapshot is preserved for reproduction' );
	}

	private function the_learning_loop_writes_refined_experience(): void {
		$this->fresh();
		$this->seed_active( 'produce' );
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $this->good_output(), 'usage' => [ 'estimated_cost' => 0.05 ] ] );
		$r = $this->pb->run( 1, 'produce', $this->facts() );
		$learned = $this->pb->learn( 1, $r['run_id'], [ 'views' => 1800.0 ] );
		$this->assert_true(  $learned['ok'], 'the loop closes' , 'the invariant holds' , 'the invariant holds' );
		$this->assert_same( 1200.0, $learned['comparisons']['views']['forecast'], 'the forecast is on the record' );
		$this->assert_same( 1.5, $learned['comparisons']['views']['ratio'], 'the forecast-vs-actual ratio is measured' );
		$this->assert_same( 1, $learned['memory_written'], 'the refinement lands in the tenant experience memory' );
		$this->assert_same( 1, count( $this->db->memory ), 'the invariant holds' );
		$row = reset( $this->db->memory );
		$this->assert_same( 'experience', (string) $row['layer'], 'the invariant holds' );
		$this->assert_same( 70, (int) $row['trust'], 'measured outcomes carry outcome trust — not team trust' );
		$this->assert_false( str_contains( (string) $row['content'], '@' ), 'no PII rides along into memory' );

		$unknown = $this->pb->learn( 1, 999, [ 'views' => 1.0 ] );
		$this->assert_false( $unknown['ok'], 'a missing run cannot be learned from' );
		$learned2 = $this->pb->learn( 1, $r['run_id'], [ 'views' => 100.0 ] );
		$this->assert_same( 0.083, $learned2['comparisons']['views']['ratio'], 'a second learn() measures the new actuals against the same forecast (100/1200)' );
	}

	private function kpi_snapshot_reads_the_real_tables(): void {
		$this->fresh();
		$this->db->insights = [
			[ 'tenant_id' => 1, 'metric' => 'views', 'value' => 100.0, 'captured_for' => '2026-08-20' ],
			[ 'tenant_id' => 1, 'metric' => 'views', 'value' => 140.0, 'captured_for' => '2026-08-21' ],
			[ 'tenant_id' => 1, 'metric' => 'saves', 'value' => 12.0, 'captured_for' => '2026-08-21' ],
			[ 'tenant_id' => 2, 'metric' => 'views', 'value' => 999.0, 'captured_for' => '2026-08-21' ],
		];
		$this->db->funnel_hits = [ [ 'tenant_id' => 1, 'funnel_id' => 7, 'occurred_at' => '2026-08-22 10:00:00' ] ];
		$kpi = $this->pb->kpi_snapshot( 1, 30 );
		$this->assert_same( 240.0, $kpi['insights']['views']['sum'], 'the snapshot sums the store\'s own insight rows' );
		$this->assert_same( 2, $kpi['insights']['views']['days'], 'the invariant holds' );
		$this->assert_same( 1, $kpi['funnel_conversions'], 'funnel conversions are counted' );
		$this->assert_false( isset( $kpi['insights']['saves999'] ), 'the invariant holds' );
		$this->assert_same( false, isset( $kpi['insights']['views']['sum'] ) && 1240.0 === $kpi['insights']['views']['sum'], 'tenant two\'s numbers never leak in' );
		// Another test file may declare a global wc_get_orders shim in this process, so the
		// honest assertion is: the flag tracks reality and the number is never guessed.
		$this->assert_same( function_exists( 'wc_get_orders' ), $kpi['woo_revenue_available'], 'revenue availability tracks whether Woo is really loaded' );
		if ( ! $kpi['woo_revenue_available'] ) {
			$this->assert_same( null, $kpi['woo_revenue'], 'unavailable revenue is null — a gap, not a zero and not a guess' );
		}
	}

	private function cost_counts_only_accepted_outputs(): void {
		$this->fresh();
		$this->seed_active( 'produce' );
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $this->good_output(), 'usage' => [ 'estimated_cost' => 0.10 ] ] );
		$good = $this->pb->run( 1, 'produce', $this->facts() );
		$bad_out = $this->good_output();
		$bad_out['suggestion'][0]['fact_ids'] = [];
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $bad_out, 'usage' => [ 'estimated_cost' => 0.30 ] ] );
		$bad = $this->pb->run( 1, 'produce', $this->facts() );

		$summary = $this->pb->cost_summary( 1, 30 );
		$this->assert_same( 2, $summary['runs'], 'both runs are journalled' , 'the invariant holds' );
		$this->assert_same( 1, $summary['accepted'], 'the invariant holds' );
		$this->assert_same( 1, $summary['rejected'], 'the invariant holds' );
		$this->assert_same( 0.40, $summary['total_cost'], 'the full spend is on the record' );
		$this->assert_same( 0.40, $summary['cost_per_accepted'], 'cost per accepted output — the honest unit' );
		$this->assert_same( true, $good['run_id'] > 0 && $bad['run_id'] > 0, 'both runs are journalled with ids' );
	}

	private function maintenance_prunes_but_protects_rollback_targets(): void {
		$this->fresh();
		// 25 retired versions + 1 active: prune must keep the newest retired (rollback target)
		for ( $i = 1; $i <= 25; $i++ ) {
			$this->pb->create_version( 1, 'gather', 'گردآوری', [ 'body' => $this->body(), 'changelog' => 'v' . $i ] );
			$this->pb->activate( 1, 'gather', $i );
		}
		$before = count( $this->db->playbooks );
		$this->pb->set_executor( fn ( array $ctx ): array => [ 'output' => $this->good_output(), 'usage' => [ 'estimated_cost' => 0.01 ] ] );
		$this->pb->run( 1, 'gather', $this->facts() );
		$this->db->playbook_runs[1]['created_at'] = '2000-01-01 00:00:00'; // age one run past retention

		$counts = $this->pb->prune();
		$this->assert_same( 1, $counts['runs_pruned'], 'run rows past the retention window are pruned' , 'the invariant holds' );
		$this->assert_same( 5, $counts['versions_pruned'], 'the cap counts the whole kind: 25 versions -> keep 20 (active + rollback target + 18 retired), prune 5' );
		$this->assert_true( $before > count( $this->db->playbooks ), 'the store shrinks — retired versions past the cap are pruned' );
		$active = $this->pb->active_version( 1, 'gather' );
		$this->assert_same( 25, (int) $active['version'], 'the active version survives prune untouched' , 'the invariant holds' );
		$this->assert_same( 20, count( $this->db->playbooks ), 'exactly MAX_VERSIONS_KEPT rows remain for the kind' );
	}

	// -------------------------------------------------------------- helpers

	private function fresh(): void {
		igbz_test_reset_settings();
		$this->db = new PlaybookStoreDb();
		$this->db->memory = [];
		$this->db->access = [];
		$this->db->playbooks = [];
		$this->db->playbook_runs = [];
		$GLOBALS['wpdb'] = $this->db;
		$this->mem = new PadoMemoryService( new Db(), igbz()->get( 'logger' ) );
		$this->pb = new GrowthPlaybookService( new Db(), igbz()->get( 'logger' ), $this->mem );
	}

	private function seed_active( string $kind ): void {
		$r = $this->pb->create_version( 1, $kind, 'عنوان', [ 'body' => $this->body(), 'model' => 'deepinfra/test-model' ] );
		$this->pb->activate( 1, $kind, $r['version'] );
	}
}
