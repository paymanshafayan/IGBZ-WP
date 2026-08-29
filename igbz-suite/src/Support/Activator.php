<?php
namespace IGBZ\Suite\Support;

use IGBZ\Suite\Modules\Instagram\Services\PostIdentity;
use IGBZ\Suite\Modules\MultiTenant\Lms\LmsService;
use IGBZ\Suite\Modules\MultiTenant\Wallet\WalletService;

defined( 'ABSPATH' ) || exit;

/**
 * Installation, incremental upgrades and role/capability setup.
 *
 * Unlike the nopCommerce original (three migrations all stamped 2025/01/01 and marked
 * Installation-only) this uses a numeric IGBZ_DB_VERSION so upgrades really do run.
 */
final class Activator {

	public const VERSION_OPTION = 'igbz_db_version';

	public static function activate(): void {
		self::install_tables();
		self::add_roles();
		self::seed_defaults();
		// A fresh install has to end up in the same state as an upgraded one. The starter VIP plan
		// used to be written only by the v10 migration, so a brand-new site got a Plans screen with
		// nothing on it and a share page with nothing to sell.
		self::seed_starter_vip_plan();
		self::seed_fx_prices();
		update_option( self::VERSION_OPTION, IGBZ_DB_VERSION, true );
		if ( false === get_option( Modules::OPTION, false ) ) {
			Modules::save( Modules::defaults() );
		}
		self::schedule_events();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		foreach ( array_keys( Cron::events() ) as $hook ) {
			$timestamp = wp_next_scheduled( $hook );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $hook );
				$timestamp = wp_next_scheduled( $hook );
			}
		}
		flush_rewrite_rules();
	}

	public static function maybe_upgrade(): void {
		$current = (int) get_option( self::VERSION_OPTION, 0 );
		if ( $current === IGBZ_DB_VERSION ) {
			return;
		}
		self::install_tables();
		self::add_roles();
		self::seed_defaults();

		// Preserve the historical behaviour: a site with no recorded version gets stamped,
		// not migrated through the whole ladder.
		if ( $current <= 0 ) {
			update_option( self::VERSION_OPTION, IGBZ_DB_VERSION, true );
			return;
		}

		// Phase 19: upgrades run through the Migrator — one runner at a time, a checkpoint
		// after every step, and a readable progress record. A failed or interrupted upgrade
		// simply resumes from its checkpoint on the next request instead of replaying blind.
		$result = self::migrator()->run( $current, IGBZ_DB_VERSION );
		if ( ! $result['ok'] ) {
			// 'locked' means another request is upgrading right now; a step failure keeps the
			// version option where it was. Either way the next request retries safely.
			return;
		}

		self::schedule_events();
	}

	/**
	 * Phase 19: the ordered data-migration steps, driven by the Migrator. Every step must
	 * stay idempotent — dbDelta adds the columns, but existing rows still need values, and
	 * the checkpoint/resume machinery re-runs steps after an interruption.
	 *
	 * @return array<int,callable> target version => step
	 */
	private static function migration_steps(): array {
		return [
			6  => [ self::class, 'migrate_to_v6' ],
			7  => [ self::class, 'migrate_to_v7' ],
			9  => [ self::class, 'migrate_to_v9' ],
			10 => [ self::class, 'migrate_to_v10' ],
			11 => [ self::class, 'migrate_to_v11' ],
			12 => [ self::class, 'migrate_to_v12' ],
			13 => [ self::class, 'migrate_to_v13' ],
			14 => [ self::class, 'migrate_to_v14' ],
			15 => [ self::class, 'migrate_to_v15' ],
			16 => [ self::class, 'migrate_to_v16' ],
			17 => [ self::class, 'migrate_to_v17' ],
			19 => [ self::class, 'migrate_to_v19' ],
			20 => [ self::class, 'migrate_to_v20' ],
			21 => [ self::class, 'migrate_to_v21' ],
			22 => [ self::class, 'migrate_to_v22' ],
			23 => [ self::class, 'migrate_to_v23' ],
			24 => [ self::class, 'migrate_to_v24' ],
			25 => [ self::class, 'migrate_to_v25' ],
			26 => [ self::class, 'migrate_to_v26' ],
			27 => [ self::class, 'migrate_to_v27' ],
			28 => [ self::class, 'migrate_to_v28' ],
			29 => [ self::class, 'migrate_to_v29' ],
			30 => [ self::class, 'migrate_to_v30' ],
			31 => [ self::class, 'migrate_to_v31' ],
			32 => [ self::class, 'migrate_to_v32' ],
			33 => [ self::class, 'migrate_to_v33' ],
			34 => [ self::class, 'migrate_to_v34' ],
			35 => [ self::class, 'migrate_to_v35' ],
			36 => [ self::class, 'migrate_to_v36' ],
			37 => [ self::class, 'migrate_to_v37' ],
			38 => [ self::class, 'migrate_to_v38' ],
			39 => [ self::class, 'migrate_to_v39' ],
			40 => [ self::class, 'migrate_to_v40' ],
			41 => [ self::class, 'migrate_to_v41' ],
			42 => [ self::class, 'migrate_to_v42' ],
			43 => [ self::class, 'migrate_to_v43' ],
		];
	}

	private static function migrator(): Migrator {
		$migrator = new Migrator();
		foreach ( self::migration_steps() as $version => $step ) {
			$migrator->add( $version, $step );
		}
		return $migrator;
	}

	/**
	 * v22 (phase 12): biometric signature contract — devices gain `signing_key`, the
	 * encrypted device key the server uses to verify signed bulk requests. dbDelta adds the
	 * column; existing rows simply carry the empty default. No data back-fill.
	 */
	public static function migrate_to_v22(): void {
		// Pure dbDelta work; see Schema::devices().
	}

	/**
	 * v23 (phase 20): composite indexes for the housekeeping and routing access paths.
	 *
	 * Derived from the query patterns in the code rather than a live EXPLAIN (the sandbox has
	 * no real MySQL): api_tokens gains expires_at / refresh_expires_at / revoked_at for the
	 * daily prune and session scans, devices gains last_seen_at for stale-device trimming.
	 * Validating them with EXPLAIN on production-sized data stays a recorded production task.
	 * Pure dbDelta work; see Schema::api_tokens() and Schema::devices().
	 */
	public static function migrate_to_v23(): void {
		// Pure dbDelta work; see Schema::api_tokens() and Schema::devices().
	}

	/**
	 * v24 (phase 23): the durable job queue table (`jobs`).
	 *
	 * Pure dbDelta work — install_tables() creates the new table from Schema::statements().
	 */
	public static function migrate_to_v24(): void {
		// Pure dbDelta work; see the jobs table in Schema::statements().
	}

	/**
	 * v25 (phase 29): the durable webhook inbox (`webhook_events`).
	 *
	 * Pure dbDelta work — install_tables() creates the new table from Schema::statements().
	 */
	public static function migrate_to_v25(): void {
		// Pure dbDelta work; see the webhook_events table in Schema::statements().
	}

	/**
	 * v26 (phase 31): escrow hardening — `ig_master_payments.refunded_amount` (partial-refund
	 * running total) and `ig_master_withdrawals.idempotency_key` with a per-tenant unique key
	 * (a replayed withdrawal request can never debit twice). Pure dbDelta work.
	 */
	public static function migrate_to_v26(): void {
		// Pure dbDelta work; see the ig_master_payments / ig_master_withdrawals tables.
	}

	/**
	 * v27 (phase 33): `bnpl_installments.collection_attempts` — the bounded-retry counter that
	 * stops the dunning sweep from hammering an empty wallet forever. Pure dbDelta work.
	 */
	public static function migrate_to_v27(): void {
		// Pure dbDelta work; see the bnpl_installments table.
	}

	/**
	 * v28 (phase 35): locked FX quotes carry their evidence — `spread_percent`, `rate_applied`
	 * (the exact number the top-up was priced with) and `expires_at` (a quote is a promise with
	 * a deadline, not forever). Pure dbDelta work.
	 */
	public static function migrate_to_v28(): void {
		// Pure dbDelta work; see the fx_rates table.
	}

	/**
	 * v29 (phase 37): domain commerce — `ig_domain_quotes` (a quote is a price with a
	 * deadline) and `ig_domain_orders.idempotency_key` with a per-tenant unique key (a
	 * replayed order request can never create a second reservation). Pure dbDelta work.
	 */
	public static function migrate_to_v29(): void {
		// Pure dbDelta work; see the ig_domain_quotes / ig_domain_orders tables.
	}

	/**
	 * v30 (phase 38): domain registration evidence — `ig_domain_journal` records every
	 * registration event (registered, failed, refunded, callback) per order, so a provider
	 * failure is always explainable after the fact. Pure dbDelta work.
	 */
	public static function migrate_to_v30(): void {
		// Pure dbDelta work; see the ig_domain_journal table.
	}

	/**
	 * v31 (phase 39): `ig_domains.auto_renew` — the tenant's opt-in for automatic renewal,
	 * carried on the domain row itself so the expiry sweep can honour it. Pure dbDelta work.
	 */
	public static function migrate_to_v31(): void {
		// Pure dbDelta work; see the ig_domains table.
	}

	/**
	 * v32 (phase 41): gamification — `ig_points_ledger` (an append-only, idempotent points
	 * ledger with per-row expiry), `ig_point_rewards` (the catalogue) and
	 * `ig_reward_redemptions` (idempotent per user + key). Pure dbDelta work.
	 */
	public static function migrate_to_v32(): void {
		// Pure dbDelta work; see the ig_points_ledger / ig_point_rewards / ig_reward_redemptions tables.
	}

	/**
	 * v33 (phase 44): proof of delivery — `ig_shipments.pod_ref` / `pod_at` keep the evidence
	 * that a delivery actually happened (photo reference, signature id, or whatever the courier
	 * app captured), so a COD dispute can be answered from the row itself. Pure dbDelta work.
	 */
	public static function migrate_to_v33(): void {
		// Pure dbDelta work; see the ig_shipments table.
	}

	/**
	 * v34 (phase 46): durable marketplace sync — `marketplace_links.payload_hash` /
	 * `remote_rev` remember what we last published and what revision the marketplace
	 * acknowledged, so unchanged products are never re-pushed and foreign edits surface
	 * as conflicts instead of being silently overwritten; `ig_marketplace_sync.not_before`
	 * is the rate-limit/backoff gate that keeps a throttled row invisible until its due
	 * time. No data back-fill.
	 */
	public static function migrate_to_v34(): void {
		// Pure dbDelta work; see the marketplace_links and ig_marketplace_sync tables.
	}

	/**
	 * v35 (phase 47): SEO & advertising governance — `ig_ad_campaigns` carries the approval
	 * state machine (pending_approval → approved/rejected, only an approved campaign may
	 * spend) and the hard budget cap that cost control checks before every advertorial;
	 * `ig_seo_activity` counts a tenant's generated SEO content per day so the bulk
	 * low-value-content guard has an honest ledger to enforce its cap against.
	 */
	public static function migrate_to_v35(): void {
		// Pure dbDelta work; see the ig_ad_campaigns and ig_seo_activity tables.
	}

	/**
	 * v36 (phase 48): international commerce foundations — `ig_translation_memory`
	 * (tenant-scoped exact-match segments, unique per tenant/language/hash),
	 * `ig_glossary_terms` (the do-not-translate term base a tenant locks per language)
	 * and `ig_intl_consents` (the consent ledger cross-border processing checks before it
	 * touches a customer's data). No data back-fill.
	 */
	public static function migrate_to_v36(): void {
		// Pure dbDelta work; see the ig_translation_memory, ig_glossary_terms and ig_intl_consents tables.
	}

	/**
	 * v37 (phase 49): Zernio connection registry — exactly one row per tenant holds the
	 * profile/account/Instagram mapping the backend enforces, the profile-scoped key and
	 * webhook secret (both encrypted at rest), the rotation counter and the revoke stamp.
	 * The central Zernio key never lands in this table; it stays in the settings secret
	 * store. No data back-fill.
	 */
	public static function migrate_to_v37(): void {
		// Pure dbDelta work; see the ig_zernio_profiles table.
	}

	/**
	 * v38 (phase 50, ADR-0004 §6): single-provider migration groundwork.
	 * `ig_zernio_profiles.key_id` keeps the provider-side key id (revocation is
	 * by key id, not by profile), `ig_accounts.legacy_deprecated_at` is the
	 * reversible migration stamp that makes legacy credentials unusable while
	 * keeping them auditable until offboarding, and `ig_social_migration` is
	 * the per-tenant per-step journal the controlled migration round writes.
	 * No data back-fill.
	 */
	public static function migrate_to_v38(): void {
		// Pure dbDelta work; see the ig_zernio_profiles, ig_accounts and ig_social_migration tables.
	}

	/**
	 * v39 (phase 51): the Zernio inbox — captured events, backend rules, the
	 * delivery ledger with its stable idempotency keys and the opt-out register.
	 * Pure dbDelta work.
	 */
	public static function migrate_to_v39(): void {
	}

	/**
	 * v40 (phase 52): the rebuilt 13-step product registration — one checkpoint
	 * row per registration, idempotent on the app's client token. Pure dbDelta
	 * work; the ig_content provider default also moved to the single provider.
	 */
	public static function migrate_to_v40(): void {
	}

	/**
	 * v41 (phase 53): the publish webhook ledger. Pure dbDelta work; every statement
	 * already exists in Schema::statements() for fresh installs.
	 */
	public static function migrate_to_v41(): void {
	}

	/**
	 * v21 (phase 06): bring installs activated before the security defaults existed in line.
	 *
	 * seed_defaults() only fills keys that are absent, so a site created before
	 * `security.*` landed never sees them and the Advanced tab renders the protections as
	 * off while the code quietly runs them on. Filling the gaps here makes the form and the
	 * behaviour agree. No schema change.
	 */
	public static function migrate_to_v21(): void {
		self::seed_defaults();
	}

	/**
	 * v42 (phase 54): the VIP channel hardening columns — `payments.idempotency_key` (creation
	 * idempotency per tenant+purpose, so a repeated VIP purchase start reuses its row) and
	 * `vip_posts.media_purged_at` (the purge ledger marker the daily reconcile retries on).
	 *
	 * Pure dbDelta work — install_tables() creates both from Schema::statements(); existing
	 * rows keep the NULL default, which the UNIQUE key permits.
	 */
	public static function migrate_to_v42(): void {
		// Pure dbDelta work; see the payments and vip_posts tables in Schema::statements().
	}

	/**
	 * v43 (phase 55): the growth-intel tables — `ig_giveaway_entries` (the frozen pool an
	 * auditable draw is derived from), `ig_competitors` + `ig_competitor_snapshots` (manual,
	 * evidence-linked competitor tracking) — plus the draw-audit columns on `ig_giveaways`
	 * and the provenance columns on `ig_insights`.
	 *
	 * It also backfills `ig_publish_events`: sites that ran the v42 step before the phase-54
	 * fix landed carry db version 42 without the table, because a stray dot at the end of its
	 * CREATE statement made dbDelta silently skip it (fresh installs came up 91/92). The fix
	 * removed the dot; this step re-runs dbDelta so the table finally exists everywhere.
	 * Idempotent: install_tables() only creates what is missing.
	 */
	public static function migrate_to_v43(): void {
		self::install_tables();
	}

	/**
	 * v20 (phase 05): registered secrets stored in plaintext are encrypted at rest.
	 *
	 * The v19 registry covered 17 keys, but the admin forms render 36 password fields and 22 of
	 * them were never members, so every value an operator pasted in was persisted as a plain
	 * option and echoed back into the form HTML. Phase 05 added those keys (plus one generated
	 * token found along the way) to Settings::SECRETS; this step brings the rows already on
	 * disk in line. No schema change. Idempotent by construction: encrypt_legacy_secrets()
	 * skips values that already carry the versioned payload prefix, and the read path never
	 * broke because Crypto::decrypt() passes unversioned payloads through.
	 */
	public static function migrate_to_v20(): void {
		( new Settings() )->encrypt_legacy_secrets();
	}

	/**
	 * v19 (1406/05/31): Pado (AI assistant) module scaffolding.
	 *
	 * Two new tables (added via dbDelta):
	 *   - `igbz_approval_requests`: every operation that requires human approval (theme
	 *     apply/rollback, price change, refund, instagram publish, bulk delete, …) creates a
	 *     row here; the "مركز پادو / درخواست‌های مجوز" screen reads this table.
	 *   - `igbz_themes`: theme .zip artefacts generated by Pado (or uploaded manually),
	 *     with metadata, status (draft/preview/live/rejected) and the validation verdict.
	 *
	 * The `igbz_ai_credit_ledger` table already exists (v14) and is re-used for token
	 * budgeting through the unified Vira API gateway.
	 *
	 * This migration also grants the new `igbz_manage_pado` capability to the shop
	 * owner role and administrators, and seeds two demo approval requests so the admin
	 * screen is not empty on first load.
	 */
	public static function migrate_to_v19(): void {
		self::install_tables();
		self::add_roles();
		( new \IGBZ\Suite\Modules\Pado\PadoModule() )->seed_demo_requests();
	}

	/**
	 * v17: the ratified VIP expiry policy, and the table behind the save button.
	 * window is set centrally by the IGBZ senior admin, it is a week by default, and when it runs
	 * out the content really leaves the server. Two stored settings contradicted that ruling on
	 * every install made before it, so they are corrected here rather than only in the defaults —
	 * seed_defaults() fills gaps, it does not overwrite a value that already exists.
	 *
	 * The two keys are treated differently on purpose:
	 *
	 *   - `vip.default_expiry_days` is only rewritten when it is zero or less. Zero means "keep
	 *     posts forever", which the ruling forbids; a positive number is a window somebody chose
	 *     deliberately, and the ruling is about who owns the setting, not about erasing their
	 *     choice.
	 *   - `vip.default_expiry_action` is forced to `delete`. `hide` is no longer a policy the
	 *     product offers — the promise made to the customer on the purchase page is that the post
	 *     leaves the server — so leaving an install on `hide` would make that promise false.
	 *
	 * Existing rows are then brought in line: every post still carrying `hide` is switched, and a
	 * published post with no expiry at all gets one counted from its own publish time. That
	 * back-fill is written as a read plus per-row updates because a correlated UPDATE does not
	 * survive the SQLite translator on Playground installs.
	 */
	public static function migrate_to_v17(): void {
		$settings = new Settings();

		if ( $settings->int( 'vip.default_expiry_days', 0 ) <= 0 ) {
			$settings->set( 'vip.default_expiry_days', 7 );
		}
		$settings->set( 'vip.default_expiry_action', 'delete' );

		$db    = new Db();
		$posts = $db->table( 'vip_posts' );

		$db->query( "UPDATE {$posts} SET expiry_action = %s WHERE expiry_action <> %s", 'delete', 'delete' );

		$days = max( 1, $settings->int( 'vip.default_expiry_days', 7 ) );
		$rows = $db->results(
			"SELECT id, published_at FROM {$posts} WHERE status = %s AND expires_at IS NULL LIMIT 500",
			'published'
		);

		foreach ( $rows as $row ) {
			$base = (string) ( $row['published_at'] ?? '' );
			$from = '' === $base ? time() : (int) strtotime( $base . ' UTC' );

			$db->update(
				'vip_posts',
				[ 'expires_at' => gmdate( 'Y-m-d H:i:s', $from + ( $days * DAY_IN_SECONDS ) ) ],
				[ 'id' => (int) $row['id'] ]
			);
		}
	}

	/**
	 * v14: the FX payment gateway tables (created by dbDelta) plus default prices.
	 *
	 * The six fx_* tables are plain dbDelta work. What cannot be expressed in DDL is the price
	 * list the meter and the top-up screen depend on, so an upgrade seeds it exactly like a fresh
	 * install does. Prices are deliberately conservative defaults; the operator edits them on the
	 * FX payments screen (or leaves the module off entirely).
	 */
	public static function migrate_to_v14(): void {
		self::seed_fx_prices();
	}

	/**
	 * Seed the default per-service USD prices. No-op once anything is priced, so a shop that
	 * deliberately cleared the list never has it come back.
	 */
	private static function seed_fx_prices(): void {
		$db = igbz()->db();

		$existing = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'fx_prices' ) );
		if ( $existing > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		// Phase 50: the legacy per-provider services were seeded under their
		// vendor names; fresh installs now price the provider-neutral services
		// (the meter is generic over any service key).
		foreach (
			[
				'social_task' => 0.5,
				'dm_delivery' => 0.1,
			] as $service => $price
		) {
			$db->insert(
				'fx_prices',
				[
					'service'    => $service,
					'price_usd'  => $price,
					'is_active'  => 1,
					'created_at' => $now,
					'updated_at' => $now,
				]
			);
		}
	}

	/**
	 * v13: drop the unused generic job queue.
	 *
	 * `jobs` was a general-purpose worker queue -- handler, payload, attempts, max_attempts,
	 * available_at, reserved_at. Nothing ever wrote a row to it and no runner ever read one; the
	 * only code that touched the table was the daily sweep deleting completed rows that could not
	 * exist. Every background job in the plugin is already durable through a queue that models
	 * its own work properly: ig_product_intake for intake, ig_content for generation and
	 * publishing, ig_funnel_hits for delivery -- each with its own retry counter and last_error,
	 * driven by the cron ticks. A second, emptier queue alongside them is a table an operator can
	 * find, wonder about and mistake for a system that is running.
	 *
	 * Dropping rather than filling it in: a queue runner is only worth its failure modes once
	 * something needs to enqueue, and nothing does. If a future subsystem wants one, it will want
	 * columns chosen for that job anyway, and this DDL is in the history.
	 */
	public static function migrate_to_v15(): void {
		// Phase 6-14 tables are plain dbDelta work; nothing to back-fill.
		self::seed_phase_defaults();
	}

	/**
	 * Defaults for the phase 6-14 features. Guarded so a deliberate removal
	 * is not resurrected on the next upgrade.
	 */
	private static function seed_phase_defaults(): void {
		$db   = igbz()->db();
		$have = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'ig_category_mapping' ) );
		if ( $have > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );
		$db->insert(
			'ig_category_mapping',
			[
				'tenant_id'        => 0,
				'marketplace'      => 'digikala',
				'local_category'   => 'default',
				'remote_category'  => '',
				'created_at'       => $now,
			]
		);
	}

	public static function migrate_to_v16(): void {
		// Phase 6-14 tables are plain dbDelta work; nothing to back-fill yet.
	}

	public static function migrate_to_v13(): void {
		$db = new Db();

		// IF EXISTS keeps this a no-op on installs created after the table stopped being made.
		$db->query( 'DROP TABLE IF EXISTS ' . $db->table( 'jobs' ) );
	}

	/**
	 * v12: funnel rewards are labelled correctly, and published posts get an identity.
	 *
	 * Two independent back-fills, both cosmetic in the sense that no money and no post changes
	 * hands -- but both fix rows that currently say something untrue.
	 *
	 * The ledger first. A funnel that credits a wallet was filing the credit under
	 * `affiliate_commission`, the reason reserved for money earned by referring a sale. Customers
	 * who had never joined the affiliate programme saw "Affiliate commission" on their statement
	 * for having commented on a post, and the two kinds of money could not be told apart by anyone
	 * totalling the ledger. The code now writes `instagram_reward`; these are the rows it already
	 * wrote. They are identifiable with certainty by their reference code, which the funnel owns
	 * exclusively (`ig_funnel:<hit id>`, versus `commission:<id>` for real commissions), so the
	 * UPDATE cannot touch a genuine commission.
	 *
	 * Rewriting the reason is safe against the ledger's UNIQUE (tenant, user, reason, reference)
	 * key: the reference code is unique per hit on its own, so moving a row to a different reason
	 * cannot collide with another row. And it cannot cause a re-payment, because a hit is paid only
	 * from settle(), which claims the hit row before crediting and never re-settles a claimed one.
	 *
	 * Then the posts. dbDelta adds ig_content.ig_shortcode, but existing published rows have it
	 * empty, which would leave the new funnel post-picker showing nothing on a site that has been
	 * publishing for months. The shortcode is derived from the permalink already on the row, so the
	 * back-fill needs no network call -- fitting, since we have no Instagram API to ask.
	 */
	public static function migrate_to_v12(): void {
		$db = new Db();

		$ledger = $db->table( 'wallet_ledger' );

		// A plain UPDATE: no subquery, no join, nothing the SQLite translator on Playground
		// installs has to reinterpret.
		$db->query(
			"UPDATE {$ledger} SET reason = %s WHERE reason = %s AND reference_code LIKE %s",
			WalletService::REASON_IG_REWARD,
			WalletService::REASON_COMMISSION,
			'ig_funnel:%'
		);

		$content = $db->table( 'ig_content' );

		// Only rows that can yield a shortcode are read, and each is written individually. The set
		// is bounded by how much a shop has published, and per-row updates keep the parsing rules
		// in PHP where they are tested, instead of restating them as SQL string surgery.
		$rows = $db->results(
			"SELECT id, permalink FROM {$content} WHERE ig_shortcode = %s AND permalink <> %s",
			'',
			''
		);

		foreach ( $rows as $row ) {
			$shortcode = PostIdentity::from_permalink( (string) $row['permalink'] );

			if ( '' === $shortcode ) {
				continue;
			}

			$db->update( 'ig_content', [ 'ig_shortcode' => $shortcode ], [ 'id' => (int) $row['id'] ] );
		}
	}

	/**
	 * v11: quizzes reach learners, and certificates become verifiable.
	 *
	 * No schema change — the six LMS tables already had everything. What is new is a public route,
	 * /{lms.certificate_slug}/{code}, so the rewrite cache has to be rebuilt for the same reason
	 * v10 did it, and at the same late point on `init`.
	 *
	 * The back-fill is the interesting half. Until now `refresh_progress()` minted a certificate
	 * the moment the last lesson was ticked, whatever the quizzes said, so sites upgrading may be
	 * carrying certificates that were never earned. Those are withdrawn — the code is cleared, not
	 * the completion — and any student who has in fact passed everything gets theirs re-issued on
	 * their next visit, because `maybe_issue_certificate()` now runs on every refresh. Withdrawing
	 * is the safe direction: a certificate wrongly issued is a claim we cannot stand behind, while
	 * one wrongly withheld comes back by itself.
	 */
	public static function migrate_to_v11(): void {
		add_action(
			'init',
			static function (): void {
				flush_rewrite_rules( false );
			},
			99
		);

		$db          = new Db();
		$enrollments = $db->table( 'enrollments' );

		// Deliberately a read plus per-row updates rather than one UPDATE ... WHERE (correlated
		// subquery): only certificates that exist need looking at, that set is small, and the
		// subquery form has to survive the SQLite translator on Playground installs.
		$rows = $db->results(
			"SELECT id, course_id, user_id FROM {$enrollments} WHERE certificate_code <> %s",
			''
		);
		if ( ! $rows ) {
			return;
		}

		$lms = new LmsService( $db );
		foreach ( $rows as $row ) {
			if ( $lms->has_passed_required_quizzes( (int) $row['course_id'], (int) $row['user_id'] ) ) {
				continue;
			}
			$db->update( 'enrollments', [ 'certificate_code' => '' ], [ 'id' => (int) $row['id'] ] );
		}
	}

	/**
	 * v10: the VIP channel.
	 *
	 * dbDelta creates the nine vip_* tables on its own, so there are no columns to back-fill. Two
	 * things still have to happen by hand.
	 *
	 * The share landing page lives at /{vip.landing_slug}/p/{shortcode}, which is a rewrite rule
	 * added at boot. Rules are cached in an option, and an upgrade — unlike an activation — never
	 * flushes them, so on an existing site every share link would 404 until somebody happened to
	 * re-save the permalink settings. The flush is deferred to `init` because rewrite rules are not
	 * registered yet at the point maybe_upgrade() runs on `plugins_loaded`; flushing here would just
	 * persist the old set.
	 *
	 * The starter plan exists so the paywall has something to sell on day one. It is written only
	 * when the table is completely empty, which makes re-running the step a no-op and means a shop
	 * that deleted the sample never gets it back.
	 */
	public static function migrate_to_v10(): void {
		add_action(
			'init',
			static function (): void {
				flush_rewrite_rules( false );
			},
			99
		);

		self::seed_starter_vip_plan();
	}

	/**
	 * Write the sample membership plan, once, into an empty table.
	 *
	 * Shared by activate() and the v10 migration so a new install and an upgraded one land in the
	 * same place. Guarding on an empty table makes it a no-op on every re-run and means a shop that
	 * deliberately deleted the sample never has it come back.
	 */
	private static function seed_starter_vip_plan(): void {
		$db = igbz()->db();

		$existing = (int) $db->scalar( 'SELECT COUNT(*) FROM ' . $db->table( 'vip_plans' ) );
		if ( $existing > 0 ) {
			return;
		}

		$now = current_time( 'mysql', true );

		$db->insert(
			'vip_plans',
			[
				'tenant_id'     => 0,
				'slug'          => 'monthly',
				'name'          => 'VIP Monthly',
				'description'   => '',
				'price'         => 0,
				'currency'      => 'IRT',
				'duration_days' => 30,
				// Inactive on purpose: a plan priced at zero must never be buyable. The shop owner
				// sets a real price in the VIP admin screen, which is what activates it.
				'is_active'     => 0,
				'sort_order'    => 0,
				'created_at'    => $now,
				'updated_at'    => $now,
			]
		);
	}

	/**
	 * v9: the code a shopper comments is the product id, not the SKU.
	 *
	 * Until v9 the funnel keyword was the warehouse SKU (IGBZ-P6R4). It is unreadable off a phone
	 * screen and needs a Latin keyboard to type, so the shopper-facing code became the padded
	 * WooCommerce product id instead. Rows that already created a product get the new code
	 * back-filled; rows that never got that far are left empty on purpose, because the code is
	 * minted at product creation and there is nothing yet to derive it from.
	 *
	 * The existing funnels are deliberately NOT rewritten. A live post out in the world tells
	 * people to comment the old SKU, and repointing its funnel would silently break every one of
	 * those posts. Old funnels keep matching the old keyword forever; only products registered
	 * from here on use numbers.
	 */
	public static function migrate_to_v9(): void {
		$db     = igbz()->db();
		$intake = $db->table( 'ig_intake' );

		// Padded in PHP rather than with LPAD(): LPAD truncates when the value is longer than the
		// pad length, so a six-digit product id would silently become a four-digit code pointing
		// at somebody else's product.
		$ids = $db->column(
			"SELECT product_id FROM {$intake}
			 WHERE product_id > 0 AND ( public_code = '' OR public_code IS NULL )"
		);

		// Constructed directly rather than pulled from the container: the Instagram module may be
		// switched off, and a disabled module still has rows that need migrating.
		$skus = new \IGBZ\Suite\Modules\Instagram\Services\SkuGenerator( $db );

		foreach ( array_unique( array_map( 'intval', (array) $ids ) ) as $product_id ) {
			$db->update(
				'ig_intake',
				[ 'public_code' => $skus->public_code( $product_id ) ],
				[ 'product_id' => $product_id ],
				[ '%s' ],
				[ '%d' ]
			);
		}
	}

	/**
	 * v7: funnel hits record whether the DM was really sent, not whether the webhook ran.
	 *
	 * Before v7 the webhook stamped `delivered = 1` the moment it had computed a reply, so rows
	 * written by an account with no working ManyChat key look successful and are invisible to the
	 * hourly retry. Those rows cannot be re-sent honestly either — Instagram DMs are not
	 * idempotent and the subscriber may well have received the reply inline — so they are
	 * relabelled rather than reset: delivered stays 1 and the row is marked unconfirmed, which is
	 * exactly what it is. Rows that really were confirmed by the old deliver() path are relabelled
	 * along with them, because nothing stored on the row tells the two apart — downgrading a
	 * handful of genuine deliveries to "we cannot prove this" is the honest direction to err in.
	 *
	 * Rows that were left undelivered with no error message are the ones that never got as far as
	 * a send. They become explicitly pending so retry_failed() picks them up.
	 */
	public static function migrate_to_v7(): void {
		// Historical one-shot step. The marker strings are inlined (the source class
		// that owned them was removed in phase 50) so legacy upgrades still run.
		$db    = new Db();
		$table = $db->table( 'ig_funnel_hits' );

		$db->query(
			"UPDATE {$table} SET delivery_error = %s WHERE delivered = 1 AND delivery_error = %s",
			'unconfirmed',
			''
		);

		$db->query(
			"UPDATE {$table} SET delivery_error = %s WHERE delivered = 0 AND delivery_error = %s",
			'pending',
			''
		);
	}

	/**
	 * v6: Manus and ManyChat credentials moved from one global key to per-account keys.
	 *
	 * Existing accounts are switched to the trial engine so a live site keeps working on the
	 * operator's key after the upgrade instead of going dark, and every account is given its own
	 * webhook tokens. The old global webhook tokens are intentionally left in settings: they no
	 * longer authenticate anything, but keeping them avoids breaking a rollback.
	 */
	public static function migrate_to_v6(): void {
		$db    = new Db();
		$table = $db->table( 'ig_accounts' );

		$rows = $db->results( "SELECT id, manus_webhook_token, manychat_webhook_token FROM {$table}" );
		if ( ! $rows ) {
			return;
		}

		$now     = current_time( 'mysql', true );
		$days    = (int) ( new Settings() )->int( 'trial.days', 14 );
		$expires = gmdate( 'Y-m-d H:i:s', strtotime( $now . ' UTC' ) + ( $days * DAY_IN_SECONDS ) );

		foreach ( $rows as $row ) {
			$update = [];

			if ( '' === (string) ( $row['manus_webhook_token'] ?? '' ) ) {
				$update['manus_webhook_token'] = Crypto::token( 24 );
			}
			if ( '' === (string) ( $row['manychat_webhook_token'] ?? '' ) ) {
				$update['manychat_webhook_token'] = Crypto::token( 24 );
			}
			// Rows that predate v6 have no key of their own, so they start on the trial engine.
			// dbDelta has already stamped them with the 'own' column default, hence the explicit
			// overwrite rather than a check for an empty mode.
			$update['credential_mode']  = 'trial';
			$update['trial_started_at'] = $now;
			$update['trial_expires_at'] = $expires;
			$update['trial_tasks_used'] = 0;

			if ( $update ) {
				$db->update( 'ig_accounts', $update, [ 'id' => (int) $row['id'] ] );
			}
		}
	}

	public static function install_tables(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::statements() as $statement ) {
			dbDelta( $statement );
		}
	}

	/**
	 * Whether translations may safely be requested yet.
	 *
	 * maybe_upgrade() runs on `plugins_loaded`, i.e. before `init`. Calling __() there forces a
	 * just-in-time textdomain load, which WordPress 6.7+ reports as a
	 * `_load_textdomain_just_in_time` doing-it-wrong notice. Both the role labels and the seeded
	 * defaults below are *persisted* values, so the English original is the correct thing to store
	 * anyway — WordPress itself stores role names untranslated. When this runs later than `init`
	 * (the real activation request does) the translated string is used.
	 */
	private static function can_translate(): bool {
		return did_action( 'init' ) > 0;
	}

	public static function add_roles(): void {
		$caps = Capabilities::all();
		$t    = self::can_translate();

		add_role(
			Capabilities::ROLE_TENANT_OWNER,
			$t ? __( 'IGBZ Tenant Owner', 'igbz-suite' ) : 'IGBZ Tenant Owner',
			array_merge(
				[ 'read' => true, 'upload_files' => true ],
				array_fill_keys(
					[
						Capabilities::MANAGE_OWN_TENANT,
						Capabilities::MANAGE_WALLET,
						Capabilities::MANAGE_INSTAGRAM,
						Capabilities::MANAGE_LMS,
						Capabilities::MANAGE_AFFILIATE,
						Capabilities::MANAGE_BNPL,
						Capabilities::MANAGE_PADO,
					],
					true
				)
			)
		);

		add_role(
			Capabilities::ROLE_TENANT_STAFF,
			$t ? __( 'IGBZ Tenant Staff', 'igbz-suite' ) : 'IGBZ Tenant Staff',
			[ 'read' => true, 'upload_files' => true, Capabilities::MANAGE_OWN_TENANT => true ]
		);

		add_role(
			Capabilities::ROLE_INSTRUCTOR,
			$t ? __( 'IGBZ Instructor', 'igbz-suite' ) : 'IGBZ Instructor',
			[ 'read' => true, 'upload_files' => true, Capabilities::MANAGE_LMS => true ]
		);

		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( $caps as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	public static function seed_defaults(): void {
		$settings = new Settings();
		$t        = self::can_translate();
		$defaults = [
			'general.default_currency'      => 'IRT',
			'general.tenant_resolution'     => 'domain',
			'general.tenant_path_base'      => 'store',
			'general.default_tenant_id'     => 0,
			'general.allow_self_signup'     => true,
			'general.auto_approve_tenants'  => false,
			'log.level'                     => Logger::INFO,
			'log.retention_days'            => 30,
			'security.disable_xmlrpc'       => true,
			'security.disable_app_passwords' => true,
			'security.block_user_enumeration' => true,
			'security.disable_oembed'       => true,
			'security.senior_admin_id'      => 0,
			'http.timeout'                  => 20,
			'purge_on_uninstall'            => false,
			'wallet.enabled'                => true,
			'wallet.allow_negative'         => false,
			'wallet.order_cashback_percent' => 2.0,
			'wallet.max_topup'              => 50000000,
			'wallet.min_topup'              => 10000,
			'wallet.topup_enabled'          => true,
			'wallet.checkout_enabled'       => true,
			'bnpl.enabled'                  => true,
			'bnpl.default_installments'     => 4,
			'bnpl.interval_days'            => 30,
			'bnpl.fee_percent'              => 0.0,
			'bnpl.penalty_percent_per_day'  => 0.1,
			'bnpl.min_order_total'          => 500000,
			'bnpl.default_credit_limit'     => 20000000,
			'bnpl.provider'                 => 'internal',
			'bnpl.auto_collect'             => true,
			'bnpl.reminder_days_before'     => 3,
			'bnpl.default_after_days'       => 14,
			'affiliate.enabled'             => true,
			'affiliate.tier1_rate'          => 5.0,
			'affiliate.tier2_rate'          => 2.0,
			'affiliate.cookie_days'         => 30,
			'affiliate.approve_after_days'  => 7,
			'affiliate.min_payout'          => 1000000,
			'affiliate.payout_to_wallet'    => true,
			'lms.enabled'                   => true,
			'lms.video_link_ttl'            => 7200,
			'lms.max_quiz_attempts'         => 3,
			'lms.course_page_id'            => 0,
			'lms.pass_score'                => 60,
			'lms.certificate_enabled'       => true,
			'lms.certificate_slug'          => 'certificate',
			'lms.revoke_on_refund'          => true,
			'vip.enabled'                   => true,
			'vip.feed_page_size'            => 12,
			'vip.default_expiry_days'       => 7,
			'vip.default_expiry_action'     => 'delete',
			'vip.purge_media_on_expiry'     => true,
			'vip.media_link_ttl'            => 900,
			'vip.offline_link_ttl'          => 3600,
			'vip.comments_enabled'          => true,
			'vip.comment_max_length'        => 1000,
			'vip.comment_rate_seconds'      => 15,
			'vip.messages_enabled'          => true,
			'vip.tips_enabled'              => true,
			'vip.tip_presets'               => '50000,100000,200000,500000',
			'vip.tip_min'                   => 10000,
			'vip.landing_slug'              => 'vip',
			'vip.app_android_url'           => '',
			'vip.app_ios_url'               => '',
			'vip.app_direct_apk_url'        => '',
			'vip.deep_link_scheme'          => 'igbz',
			'otp.enabled'                   => true,
			'otp.code_length'               => 6,
			'otp.ttl_seconds'               => 300,
			'otp.max_attempts'              => 5,
			'otp.resend_seconds'            => 120,
			'otp.max_per_hour'              => 5,
			'otp.sms_provider'              => 'log',
			'otp.message_template'          => $t ? __( 'Your verification code: {code}', 'igbz-suite' ) : 'Your verification code: {code}',
			'otp.kavenegar.template'        => '',
			'otp.kavenegar.sender'          => '',
			'otp.smsir.template_id'         => 0,
			'plans.enabled'                 => true,
			'plans.grace_days'              => 3,
			'plans.renewal_retries'         => 3,
			'plans.notify_days_before'      => 5,
			'plans.six_month_discount'      => 10.0,
			'plans.yearly_discount'         => 20.0,
			'payments.default_gateway'      => 'zarinpal',
			'payments.zarinpal.enabled'     => true,
			'payments.idpay.enabled'        => false,
			'payments.nextpay.enabled'      => false,
			'payments.payir.enabled'        => false,
			'payments.payir.sandbox'        => false,
			'payments.httppsp.enabled'      => false,
			'payments.httppsp.api_key'      => '',
			'payments.httppsp.send_url'     => '',
			'payments.httppsp.verify_url'   => '',
			'payments.httppsp.redirect_base' => '',
			'payments.httppsp.auth_scheme'  => 'Bearer',
			'payments.httppsp.field_token'  => '',
			'payments.httppsp.field_redirect_url' => '',
			'payments.httppsp.field_status' => '',
			'payments.httppsp.field_amount' => '',
			'payments.httppsp.field_ref_id' => '',
			'bnpl.snapppay_api_key'         => '',
			'bnpl.snapppay_base_url'        => 'https://api.snapppay.ir',
			'bnpl.snapppay_auth_scheme'     => 'Bearer',
			'bnpl.tara_api_key'             => '',
			'bnpl.tara_base_url'            => 'https://api.tara.ir',
			'bnpl.tara_auth_scheme'         => 'Bearer',
			'payments.currency_multiplier'  => 10,
			'payments.zarinpal.sandbox'     => false,
			'payments.idpay.sandbox'        => false,
			'fx.enabled'                    => true,
			'fx.fee_percent'                => 10,
			'fx.rate_source'                => 'manual',
			'fx.rate_url'                   => '',
			'fx.rate_json_path'             => '',
			'fx.rate_manual'                => 0,
			'fx.rate_cache_ttl'             => 3600,
			'fx.payout_provider'            => '',
			'fx.webhook_token'              => '',
			'fx.pstnet_api_key'             => '',
			'fx.pstnet_card_id'             => '',
			'fx.pstnet_base_url'            => 'https://api.pst.net',
			'fx.redotpay_api_key'           => '',
			'fx.redotpay_card_id'           => '',
			'fx.redotpay_base_url'          => 'https://openapi.redotpay.com',
			'fx.ramp_enabled'               => false,
			'fx.ramp_api_key'               => '',
			'fx.ramp_base_url'              => 'https://api.nobitex.ir',
			'fx.ramp_price_path'            => '/v2/otc/price',
			'fx.ramp_price_json_path'       => 'price',
			'fx.ramp_buy_path'              => '/v2/otc/orders/create',
			'fx.ramp_withdraw_path'         => '/v2/profile/wallets/withdraw',
			'fx.ramp_auth_scheme'           => 'Token',
			'fx.ramp_usdt_deposit_address'  => '',
			'fx.ramp_min_card_balance'      => 50,
			'fx.ramp_max_irt_per_run'       => 0,
			'fx.ramp_manual_irt'            => 0,
			'logistics.enabled'             => true,
			'logistics.delivery_pin_digits' => 4,
			'logistics.weight_threshold_kg' => 30,
			'logistics.express_cities'      => 'تهران',
			'logistics.express_cost_irt'    => 65000,
			'logistics.national_cost_irt'   => 45000,
			'logistics.heavy_cost_irt'      => 150000,
			'logistics.tapin_api_key'       => '',
			'logistics.tapin_base_url'      => 'https://api.tapin.ir',
			'logistics.postex_api_key'      => '',
			'logistics.postex_base_url'     => 'https://api.postex.ir',
			'ai_studio.enabled'             => true,
			'ai_studio.provider'            => '',
			'ai_studio.base_url'            => '',
			'ai_studio.api_key'             => '',
			'ai_studio.auth_scheme'         => 'Bearer',
			'ai_studio.image_path'          => '/v1/enhance',
			'ai_studio.background_path'     => '/v1/remove-background',
			'ai_studio.video_path'          => '/v1/story',
			'ai_studio.tts_path'            => '/v1/synthesize',
			'ai_studio.model_image_path'    => '/v1/generate-model',
			'ai_studio.result_json_path'    => 'result_url',
			'marketplace.digikala_api_key'  => '',
			'marketplace.digikala_base_url' => 'https://openapi.digikala.com',
			'marketplace.divar_token'       => '',
			'marketplace.divar_base_url'    => 'https://api.divar.ir',
			'marketplace.sync_retries'      => 3,
			'seo.enabled'                   => true,
			'seo.use_ai'                    => false,
			'seo.feed_page_size'            => 500,
			'seo.triboon_api_key'           => '',
			'seo.triboon_base_url'          => 'https://api.triboon.ir',
			'gamification.enabled'          => true,
			'gamification.spin_cooldown_hours' => 24,
			'gamification.spin_rewards'     => '5,10,20',
			'gamification.spin_coupon_prefix' => 'SPIN',
			'abandoned_cart.enabled'        => true,
			'abandoned_cart.remind_after_hours' => 6,
			'abandoned_cart.discount_percent' => 30,
			'abandoned_cart.coupon_prefix'  => 'CART',
			'nowpayments.enabled'           => true,
			'nowpayments.api_key'           => '',
			'nowpayments.pay_currency'      => 'usdttrc20',
			'nowpayments.price_currency'    => 'usd',
			'nowpayments.usd_rate_irt'      => 0,
			'bale.provider_token'           => '',
			'bale.bot_token'                => '',
			'bnpl.cash_discount_percent'    => 0,
			'payments.sadad.enabled'        => false,
			'payments.sadad.merchant_id'    => '',
			'payments.sadad.terminal_id'    => '',
			'payments.sadad.private_key'    => '',
			'payments.asanpardakht.enabled' => false,
			'payments.asanpardakht.api_key' => '',
			'payments.asanpardakht.merchant_config' => '',
			'payments.parsian.enabled'      => false,
			'payments.parsian.login_account' => '',
			'payments.irankish.enabled'     => false,
			'payments.irankish.terminal_id' => '',
			'payments.irankish.api_key'     => '',
			'payments.mellat.enabled'       => false,
			'payments.mellat.terminal_id'   => '',
			'payments.mellat.username'      => '',
			'payments.mellat.password'      => '',
			'payments.saman.enabled'        => false,
			'payments.saman.terminal_id'    => '',
			'payments.saman.public_key'     => '',
			'payments.saman.private_key'    => '',
			'payments.pasargad.enabled'     => false,
			'payments.pasargad.merchant_code' => '',
			'payments.pasargad.terminal_code' => '',
			'payments.pasargad.private_key' => '',
			'payments.sepehr.enabled'       => false,
			'payments.sepehr.terminal_id'   => '',
			'payments.sepehr.api_key'       => '',
			'translation.provider'          => '',
			'translation.base_url'          => '',
			'translation.api_key'           => '',
			'translation.auth_scheme'       => 'Bearer',
			'translation.path'              => '/v1/translate',
			'translation.result_json_path'  => 'translatedFields',
			'lms.vod_enabled'               => false,
			'lms.vod_secure_key'            => '',
			'lms.vod_base_url'              => '',
			'lms.vod_ttl_seconds'           => 7200,
			'lms.vod_bind_ip'               => true,
			'ai_credits.enabled'            => true,
			'ai_credits.purchase_percent'   => 2.0,
			'ai_credits.min_topup'          => 10000,
			'master_payment.enabled'        => true,
			'master_payment.release_hours'  => 24,
			'master_payment.fx_fee_percent' => 2.0,
			'legal.national_id_check'       => false,
			'legal.enamad_active'           => false,
			'legal.shahkar_api_key'         => '',
			'legal.shahkar_base_url'        => '',
			'pado.api_key'                  => '',
			'pado.endpoint'                 => '',
			'pado.model_label'              => '',
			'domain.provider'               => '',
			'domain.provider_api_key'       => '',
			'domain.provider_base_url'      => '',
			'domain.mother_subdomain'       => 'igbz.ir',
			'webpresence.google_*'          => '',
			'i18n.enabled'                  => false,
			'i18n.languages'                => 'fa',
			'i18n.default_language'         => 'fa',
			'stripe.enabled'                => false,
			'stripe.secret_key'             => '',
			'paypal.enabled'                => false,
			'paypal.client_id'              => '',
			'basalam.enabled'               => false,
			'basalam.api_key'               => '',
			'basalam.base_url'              => 'https://api.basalam.com',
			'basalam.gharhe_id'             => '',
			'marketplace.enabled'           => true,
			'marketplace.torob.enabled'     => true,
			'marketplace.emalls.enabled'    => true,
			'marketplace.google.enabled'    => false,
			'marketplace.feed_limit'        => 500,
			'marketplace.cache_ttl'         => 900,
			// Phase 50 — the single social provider (ADR-0004). Base URL and
			// paths default to the documented docs.zernio.com endpoints and stay
			// overridable per install (proxy, staging, future path changes).
			'zernio.base_url'               => 'https://zernio.com/api/v1',
			'zernio.profiles_path'          => '/profiles',
			'zernio.api_keys_path'          => '/api-keys',
			'zernio.connect_path'           => '/connect/instagram',
			'zernio.accounts_path'          => '/accounts',
			'zernio.posts_path'             => '/posts',
			'zernio.dm_path'                => '/messages',
			'zernio.story_reply_path'       => '/messages/story-reply',
			'zernio.inbox_path'             => '/inbox',
			'zernio.analytics_path'         => '/analytics',
			'zernio.audio_path'             => '/audio/trending',
			'zernio.timeout'                => 30,
			// Provider-neutral intake knobs the rebuilt registration flow (phase 52)
			// reads with safe defaults.
			'intake.sku_prefix'             => 'IGBZ',
			'intake.code_digits'            => 4,
			'intake.languages'              => '',
			'intake.default_language'       => '',
			'hub.enabled'                   => true,
			'hub.vip_link_ttl'              => 900,
			'hub.sync_interval'             => 3600,
			'hub.featured_limit'            => 12,
			'hub.subdomain_base'            => '',
			'hub.cname_target'              => '',
			'hub.mother_origin'             => '',
			'api.jwt_ttl'                   => 3600,
			'api.refresh_ttl'               => 2592000,
			'api.rate_limit_per_minute'     => 120,
			'api.tenant_rate_limit_per_minute' => 600,
			'api.push_enabled'              => false,
			'api.push_channel_id'           => 'igbz_default',
			'api.push_order_updates'        => true,
			'api.device_retention_days'     => 180,
			'api.app_scheme'                => 'igbz',
			'api.min_app_version'           => '',
			'api.latest_app_version'        => '',
			'api.apk_url'                   => '',
			'api.ios_store_url'             => '',
			'api.android_package'           => '',
			'api.ios_bundle_id'             => '',
			'api.universal_link'            => '',
		];
		foreach ( $defaults as $key => $value ) {
			if ( ! $settings->has( $key ) ) {
				$settings->set( $key, $value );
			}
		}

		// Secrets that must exist for signed URLs / tokens are generated once, never hardcoded.
		$generated = [
			'api.jwt_secret',
			'lms.video_hmac_secret',
			'hub.vip_link_secret',
			'vip.media_hmac_secret',
		];
		foreach ( $generated as $key ) {
			if ( ! $settings->has( $key ) ) {
				$settings->set( $key, Crypto::token( 32 ) );
			}
		}
	}

	public static function schedule_events(): void {
		// Guarantee the custom recurrences are known even if this runs before the plugin
		// bootstrap did it (e.g. a direct call from an upgrade routine or WP-CLI).
		Cron::register_schedules();

		foreach ( Cron::events() as $hook => $recurrence ) {
			if ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 60, $recurrence, $hook );
			}
		}
	}
}
