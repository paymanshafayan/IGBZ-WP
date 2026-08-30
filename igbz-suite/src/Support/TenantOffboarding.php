<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Phase 13 (offboarding): when a tenant is deleted, every tenant-scoped row goes with it.
 *
 * The tenant row itself is the handle; the data behind it is spread over every module, so the
 * cascade lives here as one audited sweep instead of being rediscovered by each module. Tables
 * without a tenant_id column (progress rows, label items) hang off the rows deleted here and
 * are documented as the residual, not silently forgotten.
 */
final class TenantOffboarding {

	/**
	 * Every table carrying a tenant_id column (Schema.php, db v38).
	 *
	 * Phase 50 audit: the list had silently stopped at db v22 — eleven tables
	 * added by later phases (jobs, webhook_events, the ledger/journal/points/
	 * SEO/translation tables, ig_domain_quotes, logs) were never swept on
	 * offboarding. They are appended here so data erasure is complete; any new
	 * tenant table must join this list in the same change that adds it.
	 */
	public const TABLES = [
		'tenant_domains', 'tenant_members', 'wallet_ledger', 'wallet_balances', 'subscriptions',
		'bnpl_credit', 'bnpl_contracts', 'bnpl_installments', 'affiliates', 'affiliate_commissions',
		'referral_clicks', 'courses', 'lessons', 'enrollments', 'quizzes', 'quiz_attempts',
		'payments', 'otp_codes', 'marketplace_links', 'ig_accounts', 'ig_content', 'ig_insights',
		'ig_funnels', 'ig_subscribers', 'ig_funnel_hits', 'ig_intake', 'api_tokens', 'devices',
		'vip_plans', 'vip_memberships', 'vip_posts', 'vip_post_comments', 'vip_entitlements',
		'vip_threads', 'vip_messages', 'fx_wallets', 'fx_ledger', 'fx_accounts', 'fx_bills',
		'ig_shipments', 'ig_marketplace_sync', 'ig_category_mapping', 'ig_abandoned_carts',
		'ig_ai_credit_ledger', 'ig_giveaways', 'ig_giveaway_entries', 'ig_competitors',
		'ig_competitor_snapshots', 'ig_master_payments', 'ig_master_disputes',
		'ig_master_withdrawals', 'ig_master_agreements', 'ig_nid_verifications', 'ig_legal_agreements',
		'ig_domains', 'ig_domain_orders', 'ig_web_presence', 'ig_couriers', 'ig_label_groups',
		'ig_cod_payments', 'ig_courier_routes', 'ig_courier_tracking', 'ig_courier_chat',
		'ig_zernio_profiles', 'ig_social_migration',
		'ig_zernio_inbox', 'ig_inbox_rules', 'ig_inbox_actions', 'ig_inbox_optouts',
		'ig_product_registrations',
		'ig_publish_events',
		'jobs', 'webhook_events', 'ig_domain_journal', 'ig_points_ledger', 'ig_point_rewards',
		'ig_reward_redemptions', 'ig_domain_quotes', 'logs', 'ig_ad_campaigns', 'ig_seo_activity',
		'ig_translation_memory', 'ig_glossary_terms', 'ig_intl_consents',
		'approval_requests', 'themes',
	];

	public function __construct( private Db $db, private Logger $logger ) {}

	public function purge( int $tenant_id ): int {
		if ( $tenant_id <= 0 ) {
			return 0;
		}

		$this->logger->log( Logger::WARNING, 'security', 'Tenant offboarding started', [ 'tenant_id' => $tenant_id ] );

		$deleted = 0;
		foreach ( self::TABLES as $table ) {
			// Phase 20: a long-lived store can hold a lot of rows in some of these tables;
			// sweep in bounded batches so the erasure cannot lock the database while running.
			$deleted += $this->db->delete_batches( $table, 'tenant_id = %d', [ $tenant_id ] );
		}

		$this->logger->log( Logger::WARNING, 'security', 'Tenant offboarding finished', [ 'tenant_id' => $tenant_id, 'rows_removed' => $deleted ] );

		return $deleted;
	}
}
