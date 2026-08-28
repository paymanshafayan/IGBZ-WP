<?php
namespace IGBZ\Suite\Support;

defined( 'ABSPATH' ) || exit;

/**
 * All IGBZ tables. Every tenant-scoped table carries tenant_id so a single WordPress install can
 * host many stores without Multisite (the design decision that replaces nopCommerce's Store entity).
 */
final class Schema {

	/** @return string[] dbDelta statements */
	/**
	 * Every table this plugin owns, without the `{$wpdb->prefix}igbz_` prefix.
	 *
	 * Kept next to statements() on purpose: uninstall.php reads this list, so a new CREATE TABLE
	 * can never be forgotten in the drop routine. tests/SchemaTest asserts the two stay in sync.
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		return [
			'tenants',
			'tenant_domains',
			'tenant_members',
			'wallet_ledger',
			'wallet_balances',
			'plans',
			'subscriptions',
			'bnpl_credit',
			'bnpl_contracts',
			'bnpl_installments',
			'affiliates',
			'affiliate_commissions',
			'referral_clicks',
			'courses',
			'lessons',
			'enrollments',
			'lesson_progress',
			'quizzes',
			'quiz_attempts',
			'payments',
			'otp_codes',
			'marketplace_links',
			'ig_accounts',
			'ig_content',
			'ig_insights',
			'ig_funnels',
			'ig_subscribers',
			'ig_funnel_hits',
			'ig_intake',
			'vip_plans',
			'vip_memberships',
			'vip_posts',
			'vip_post_likes',
			'vip_post_saves',
			'vip_post_comments',
			'vip_post_views',
			'vip_entitlements',
			'vip_threads',
			'vip_messages',
			'api_tokens',
			'devices',
			'fx_wallets',
			'fx_ledger',
			'fx_rates',
			'fx_prices',
			'fx_accounts',
			'fx_bills',
			'ig_shipments',
			'ig_marketplace_sync',
			'ig_category_mapping',
			'ig_abandoned_carts',
			'ig_ai_credit_ledger',
			'ig_giveaways',
			'ig_master_payments',
			'ig_master_disputes',
			'ig_master_withdrawals',
			'ig_master_agreements',
			'ig_nid_verifications',
			'ig_legal_agreements',
			'ig_domains',
			'ig_domain_orders',
			'ig_web_presence',
			'ig_couriers',
			'ig_label_groups',
			'ig_label_group_items',
			'ig_cod_payments',
			'ig_courier_routes',
			'ig_courier_tracking',
			'ig_courier_chat',
			'logs',
			'approval_requests',
			'themes',
		];
	}

	/** Fully qualified table name. */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'igbz_' . $name;
	}

	public static function statements(): array {
		global $wpdb;
		$p       = $wpdb->prefix . 'igbz_';
		$charset = $wpdb->get_charset_collate();
		$sql     = [];

		$sql[] = "CREATE TABLE {$p}tenants (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			owner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			theme VARCHAR(64) NOT NULL DEFAULT '',
			logo_url VARCHAR(255) NOT NULL DEFAULT '',
			primary_color VARCHAR(16) NOT NULL DEFAULT '',
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			locale VARCHAR(10) NOT NULL DEFAULT 'fa_IR',
			settings LONGTEXT NULL,
			trial_ends_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY owner_user_id (owner_user_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}tenant_domains (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			domain VARCHAR(191) NOT NULL,
			is_primary TINYINT(1) NOT NULL DEFAULT 0,
			verified_at DATETIME NULL,
			verification_token VARCHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY domain (domain),
			KEY tenant_id (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}tenant_members (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			role VARCHAR(32) NOT NULL DEFAULT 'staff',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_user (tenant_id,user_id),
			KEY user_id (user_id)
		) {$charset};";

		// ------------------------------------------------------------ wallet
		$sql[] = "CREATE TABLE {$p}wallet_ledger (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(18,4) NOT NULL,
			balance_after DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			direction VARCHAR(8) NOT NULL,
			reason VARCHAR(64) NOT NULL,
			reference_code VARCHAR(128) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			note VARCHAR(255) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY idempotency (tenant_id,user_id,reason,reference_code),
			KEY user_tenant (user_id,tenant_id),
			KEY order_id (order_id),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}wallet_balances (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			balance DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_user (tenant_id,user_id)
		) {$charset};";

		// ------------------------------------------------------------ plans
		$sql[] = "CREATE TABLE {$p}plans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			description TEXT NULL,
			price DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			billing_period VARCHAR(16) NOT NULL DEFAULT 'monthly',
			trial_days INT NOT NULL DEFAULT 0,
			max_products INT NOT NULL DEFAULT 0,
			max_orders INT NOT NULL DEFAULT 0,
			max_staff INT NOT NULL DEFAULT 0,
			features LONGTEXT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}subscriptions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			starts_at DATETIME NOT NULL,
			ends_at DATETIME NULL,
			cancelled_at DATETIME NULL,
			auto_renew TINYINT(1) NOT NULL DEFAULT 1,
			price_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
			last_invoice_at DATETIME NULL,
			renewal_failures INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_id (tenant_id),
			KEY status_ends (status,ends_at)
		) {$charset};";

		// ------------------------------------------------------------ BNPL
		$sql[] = "CREATE TABLE {$p}bnpl_credit (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			credit_limit DECIMAL(18,4) NOT NULL DEFAULT 0,
			used_credit DECIMAL(18,4) NOT NULL DEFAULT 0,
			score INT NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			national_code VARCHAR(32) NOT NULL DEFAULT '',
			verified_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_user (tenant_id,user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}bnpl_contracts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(32) NOT NULL DEFAULT 'internal',
			provider_ref VARCHAR(128) NOT NULL DEFAULT '',
			principal DECIMAL(18,4) NOT NULL DEFAULT 0,
			down_payment DECIMAL(18,4) NOT NULL DEFAULT 0,
			fee_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			total_payable DECIMAL(18,4) NOT NULL DEFAULT 0,
			installment_count INT NOT NULL DEFAULT 0,
			interval_days INT NOT NULL DEFAULT 30,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			signed_at DATETIME NULL,
			settled_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_user (tenant_id,user_id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}bnpl_installments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contract_id BIGINT UNSIGNED NOT NULL,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			sequence INT NOT NULL DEFAULT 1,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			penalty DECIMAL(18,4) NOT NULL DEFAULT 0,
			due_date DATE NOT NULL,
			paid_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'due',
			payment_ref VARCHAR(128) NOT NULL DEFAULT '',
			reminder_sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY contract_seq (contract_id,sequence),
			KEY due_status (due_date,status),
			KEY user_id (user_id)
		) {$charset};";

		// ------------------------------------------------------------ affiliate
		$sql[] = "CREATE TABLE {$p}affiliates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(32) NOT NULL,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tier INT NOT NULL DEFAULT 1,
			commission_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			total_earned DECIMAL(18,4) NOT NULL DEFAULT 0,
			total_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
			clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
			signups BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			UNIQUE KEY tenant_user (tenant_id,user_id),
			KEY parent_id (parent_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}affiliate_commissions (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			referred_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tier INT NOT NULL DEFAULT 1,
			base_amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			approved_at DATETIME NULL,
			paid_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_affiliate_tier (order_id,affiliate_id,tier),
			KEY affiliate_id (affiliate_id),
			KEY status (status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}referral_clicks (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			affiliate_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(64) NOT NULL DEFAULT '',
			landing_url VARCHAR(255) NOT NULL DEFAULT '',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			converted_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY affiliate_id (affiliate_id),
			KEY created_at (created_at)
		) {$charset};";

		// ------------------------------------------------------------ LMS
		$sql[] = "CREATE TABLE {$p}courses (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			summary TEXT NULL,
			description LONGTEXT NULL,
			cover_url VARCHAR(255) NOT NULL DEFAULT '',
			level VARCHAR(20) NOT NULL DEFAULT 'beginner',
			duration_minutes INT NOT NULL DEFAULT 0,
			instructor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			certificate_enabled TINYINT(1) NOT NULL DEFAULT 0,
			pass_score INT NOT NULL DEFAULT 60,
			is_published TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_slug (tenant_id,slug),
			KEY product_id (product_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}lessons (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			course_id BIGINT UNSIGNED NOT NULL,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL,
			content LONGTEXT NULL,
			video_key VARCHAR(255) NOT NULL DEFAULT '',
			attachment_url VARCHAR(255) NOT NULL DEFAULT '',
			duration_minutes INT NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			is_free_preview TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY course_sort (course_id,sort_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}enrollments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			course_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			progress_percent INT NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			certificate_code VARCHAR(64) NOT NULL DEFAULT '',
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY course_user (course_id,user_id),
			KEY user_id (user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}lesson_progress (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			enrollment_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			seconds_watched INT NOT NULL DEFAULT 0,
			completed TINYINT(1) NOT NULL DEFAULT 0,
			completed_at DATETIME NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY enrollment_lesson (enrollment_id,lesson_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}quizzes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			course_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL,
			questions LONGTEXT NULL,
			pass_score INT NOT NULL DEFAULT 60,
			max_attempts INT NOT NULL DEFAULT 3,
			time_limit_minutes INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY course_id (course_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}quiz_attempts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			quiz_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			answers LONGTEXT NULL,
			score INT NOT NULL DEFAULT 0,
			passed TINYINT(1) NOT NULL DEFAULT 0,
			started_at DATETIME NOT NULL,
			finished_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY quiz_user (quiz_id,user_id)
		) {$charset};";

		// ------------------------------------------------------------ payments & OTP
		$sql[] = "CREATE TABLE {$p}payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			gateway VARCHAR(32) NOT NULL,
			purpose VARCHAR(32) NOT NULL DEFAULT 'order',
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			authority VARCHAR(191) NOT NULL DEFAULT '',
			reference_id VARCHAR(191) NOT NULL DEFAULT '',
			card_pan VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'created',
			error_code VARCHAR(32) NOT NULL DEFAULT '',
			error_message VARCHAR(255) NOT NULL DEFAULT '',
			verified_at DATETIME NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY authority (authority),
			KEY order_id (order_id),
			KEY gateway_status (gateway,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}otp_codes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			phone VARCHAR(32) NOT NULL,
			code_hash CHAR(64) NOT NULL,
			purpose VARCHAR(32) NOT NULL DEFAULT 'login',
			attempts INT NOT NULL DEFAULT 0,
			consumed_at DATETIME NULL,
			expires_at DATETIME NOT NULL,
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_purpose (phone,purpose),
			KEY phone_ip_purpose (phone,ip_hash,purpose),
			KEY expires_at (expires_at)
		) {$charset};";

		// ------------------------------------------------------------ marketplace
		$sql[] = "CREATE TABLE {$p}marketplace_links (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL,
			channel VARCHAR(32) NOT NULL,
			external_id VARCHAR(128) NOT NULL DEFAULT '',
			last_synced_at DATETIME NULL,
			sync_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			sync_message VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY product_channel (product_id,channel),
			KEY tenant_channel (tenant_id,channel)
		) {$charset};";

		// ------------------------------------------------------------ Instagram
		$sql[] = "CREATE TABLE {$p}ig_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			username VARCHAR(64) NOT NULL,
			display_name VARCHAR(191) NOT NULL DEFAULT '',
			manus_project_id VARCHAR(128) NOT NULL DEFAULT '',
			manychat_page_id VARCHAR(128) NOT NULL DEFAULT '',
			manus_api_key TEXT NULL,
			manychat_api_key TEXT NULL,
			manus_webhook_token VARCHAR(64) NULL DEFAULT NULL,
			manychat_webhook_token VARCHAR(64) NULL DEFAULT NULL,
			credential_mode VARCHAR(16) NOT NULL DEFAULT 'own',
			trial_started_at DATETIME NULL,
			trial_expires_at DATETIME NULL,
			trial_tasks_used INT NOT NULL DEFAULT 0,
			timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Tehran',
			niche VARCHAR(191) NOT NULL DEFAULT '',
			brand_voice TEXT NULL,
			peak_hours VARCHAR(191) NOT NULL DEFAULT '',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_username (tenant_id,username),
			UNIQUE KEY manychat_webhook_token (manychat_webhook_token),
			UNIQUE KEY manus_webhook_token (manus_webhook_token)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_content (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'post',
			title VARCHAR(191) NOT NULL DEFAULT '',
			brief LONGTEXT NULL,
			caption LONGTEXT NULL,
			hashtags TEXT NULL,
			media LONGTEXT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			funnel_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(32) NOT NULL DEFAULT 'manus',
			provider_task_id VARCHAR(191) NOT NULL DEFAULT '',
			provider_status VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			scheduled_for DATETIME NULL,
			published_at DATETIME NULL,
			permalink VARCHAR(255) NOT NULL DEFAULT '',
			ig_shortcode VARCHAR(64) NOT NULL DEFAULT '',
			last_error VARCHAR(500) NOT NULL DEFAULT '',
			retry_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY account_status (account_id,status),
			KEY scheduled_for (scheduled_for),
			KEY provider_task (provider_task_id),
			KEY ig_shortcode (ig_shortcode)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_insights (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL,
			metric VARCHAR(64) NOT NULL,
			dimension VARCHAR(64) NOT NULL DEFAULT '',
			value DECIMAL(18,4) NOT NULL DEFAULT 0,
			captured_for DATE NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY account_metric_day (account_id,metric,dimension,captured_for)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_funnels (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(191) NOT NULL,
			keyword VARCHAR(64) NOT NULL,
			match_mode VARCHAR(16) NOT NULL DEFAULT 'contains',
			post_id VARCHAR(128) NOT NULL DEFAULT '',
			reply_text LONGTEXT NULL,
			target_type VARCHAR(20) NOT NULL DEFAULT 'url',
			target_url VARCHAR(255) NOT NULL DEFAULT '',
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			coupon_code VARCHAR(64) NOT NULL DEFAULT '',
			manychat_flow_ns VARCHAR(128) NOT NULL DEFAULT '',
			manychat_tag VARCHAR(64) NOT NULL DEFAULT '',
			grant_wallet_credit DECIMAL(18,4) NOT NULL DEFAULT 0,
			per_user_limit INT NOT NULL DEFAULT 1,
			total_limit INT NOT NULL DEFAULT 0,
			hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
			conversions BIGINT UNSIGNED NOT NULL DEFAULT 0,
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY keyword_active (keyword,is_active),
			KEY tenant_id (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_subscribers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			manychat_subscriber_id VARCHAR(64) NOT NULL,
			ig_username VARCHAR(64) NOT NULL DEFAULT '',
			ig_user_id VARCHAR(64) NOT NULL DEFAULT '',
			first_name VARCHAR(191) NOT NULL DEFAULT '',
			last_name VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(32) NOT NULL DEFAULT '',
			email VARCHAR(191) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			custom_fields LONGTEXT NULL,
			tags TEXT NULL,
			last_interaction_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY subscriber (manychat_subscriber_id),
			KEY user_id (user_id),
			KEY ig_username (ig_username)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_funnel_hits (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			funnel_id BIGINT UNSIGNED NOT NULL,
			subscriber_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			manychat_subscriber_id VARCHAR(64) NOT NULL DEFAULT '',
			ig_username VARCHAR(64) NOT NULL DEFAULT '',
			comment_id VARCHAR(128) NOT NULL DEFAULT '',
			comment_text TEXT NULL,
			post_id VARCHAR(128) NOT NULL DEFAULT '',
			event VARCHAR(32) NOT NULL DEFAULT 'comment',
			delivered TINYINT(1) NOT NULL DEFAULT 0,
			delivery_error VARCHAR(255) NOT NULL DEFAULT '',
			coupon_issued VARCHAR(64) NOT NULL DEFAULT '',
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (funnel_id,comment_id),
			KEY funnel_id (funnel_id),
			KEY subscriber (manychat_subscriber_id)
		) {$charset};";

		// One row per product registered from the phone. This is the state machine behind the
		// "shoot a photo -> answer a few questions -> the post is live" flow: the app never
		// touches wp-admin, so every intermediate artefact (the graded photo, the cleaned-up
		// image, the edited version, the dictated description, the generated video) has to be
		// stored somewhere durable between REST calls.
		$sql[] = "CREATE TABLE {$p}ig_intake (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(32) NOT NULL DEFAULT 'uploaded',
			sku VARCHAR(32) NULL DEFAULT NULL,
			public_code VARCHAR(32) NOT NULL DEFAULT '',
			source_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source_url VARCHAR(500) NOT NULL DEFAULT '',
			clean_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clean_url VARCHAR(500) NOT NULL DEFAULT '',
			edited_attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			edited_url VARCHAR(500) NOT NULL DEFAULT '',
			quality_score INT NOT NULL DEFAULT 0,
			quality_verdict VARCHAR(20) NOT NULL DEFAULT '',
			quality_reasons LONGTEXT NULL,
			attempt INT NOT NULL DEFAULT 1,
			raw_description LONGTEXT NULL,
			input_mode VARCHAR(16) NOT NULL DEFAULT 'text',
			transcript LONGTEXT NULL,
			specs LONGTEXT NULL,
			price DECIMAL(18,4) NOT NULL DEFAULT 0,
			sale_price DECIMAL(18,4) NOT NULL DEFAULT 0,
			stock INT NOT NULL DEFAULT 0,
			category_ids VARCHAR(255) NOT NULL DEFAULT '',
			copy_json LONGTEXT NULL,
			translations LONGTEXT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			funnel_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			content_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_kind VARCHAR(16) NOT NULL DEFAULT '',
			video_prompt LONGTEXT NULL,
			video_url VARCHAR(500) NOT NULL DEFAULT '',
			video_approved TINYINT(1) NOT NULL DEFAULT 0,
			provider_task_id VARCHAR(191) NOT NULL DEFAULT '',
			provider_stage VARCHAR(32) NOT NULL DEFAULT '',
			last_error VARCHAR(500) NOT NULL DEFAULT '',
			retry_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY sku (sku),
			KEY public_code (public_code),
			KEY tenant_status (tenant_id,status),
			KEY account_id (account_id),
			KEY provider_task (provider_task_id),
			KEY product_id (product_id)
		) {$charset};";

		// ------------------------------------------------------------ API
		$sql[] = "CREATE TABLE {$p}api_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			jti CHAR(64) NOT NULL,
			refresh_hash CHAR(64) NOT NULL DEFAULT '',
			device_id VARCHAR(128) NOT NULL DEFAULT '',
			issued_at DATETIME NOT NULL,
			expires_at DATETIME NOT NULL,
			refresh_expires_at DATETIME NULL,
			revoked_at DATETIME NULL,
			last_used_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY jti (jti),
			KEY user_id (user_id),
			KEY refresh_hash (refresh_hash),
			KEY expires_at (expires_at),
			KEY refresh_expires_at (refresh_expires_at),
			KEY revoked_at (revoked_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}devices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			device_id VARCHAR(128) NOT NULL,
			signing_key VARCHAR(255) NOT NULL DEFAULT '',
			platform VARCHAR(16) NOT NULL DEFAULT '',
			fcm_token VARCHAR(255) NOT NULL DEFAULT '',
			app_version VARCHAR(32) NOT NULL DEFAULT '',
			locale VARCHAR(10) NOT NULL DEFAULT '',
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY device (device_id),
			KEY user_id (user_id),
			KEY fcm_token (fcm_token(191)),
			KEY last_seen_at (last_seen_at)
		) {$charset};";

		// The VIP channel: a private Instagram-shaped feed inside our own app.
		//
		// Deliberately NOT reusing plans/subscriptions above. Those are keyed on tenant_id and model
		// a *shop owner's* SaaS plan with us. A VIP membership is a *shopper's* subscription to one
		// shop's private feed. Overloading one table would make "the shop's plan lapsed" and "the
		// customer's membership lapsed" indistinguishable, and they need opposite handling.
		$sql[] = "CREATE TABLE {$p}vip_plans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			description TEXT NULL,
			price DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			duration_days INT NOT NULL DEFAULT 30,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			sort_order INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_slug (tenant_id,slug),
			KEY active (tenant_id,is_active,sort_order)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}vip_memberships (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			starts_at DATETIME NULL,
			ends_at DATETIME NULL,
			payment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			auto_renew TINYINT(1) NOT NULL DEFAULT 0,
			price_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
			cancelled_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user_active (user_id,status,ends_at),
			KEY tenant_status (tenant_id,status)
		) {$charset};";

		// media is a JSON array of {type,url,thumb,width,height,duration} so one row can be a single
		// image, a carousel or a video without a child table -- the feed is always read whole.
		$sql[] = "CREATE TABLE {$p}vip_posts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shortcode VARCHAR(24) NOT NULL DEFAULT '',
			kind VARCHAR(20) NOT NULL DEFAULT 'image',
			caption LONGTEXT NULL,
			media LONGTEXT NULL,
			teaser_content_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			access VARCHAR(20) NOT NULL DEFAULT 'members',
			price DECIMAL(18,4) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			comments_enabled TINYINT(1) NOT NULL DEFAULT 1,
			publish_at DATETIME NULL,
			published_at DATETIME NULL,
			expires_at DATETIME NULL,
			expiry_action VARCHAR(20) NOT NULL DEFAULT 'hide',
			expired_at DATETIME NULL,
			likes_count INT NOT NULL DEFAULT 0,
			comments_count INT NOT NULL DEFAULT 0,
			views_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY shortcode (shortcode),
			KEY feed (tenant_id,status,published_at),
			KEY expiry (status,expires_at),
			KEY schedule (status,publish_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}vip_post_likes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_user (post_id,user_id),
			KEY user_likes (user_id,id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}vip_post_saves (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			offline_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_user (post_id,user_id),
			KEY user_saves (user_id,id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}vip_post_comments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			post_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			is_admin TINYINT(1) NOT NULL DEFAULT 0,
			body TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'visible',
			is_pinned TINYINT(1) NOT NULL DEFAULT 0,
			likes_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY post_visible (post_id,status,is_pinned,id),
			KEY thread (parent_id,id),
			KEY moderation (tenant_id,status,id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}vip_post_views (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			seconds_watched INT NOT NULL DEFAULT 0,
			view_count INT NOT NULL DEFAULT 1,
			first_viewed_at DATETIME NOT NULL,
			viewed_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_user (post_id,user_id)
		) {$charset};";

		// A per-post purchase outlives the membership that might also have granted access, which is
		// why this is a separate table and not a flag on vip_memberships.
		$sql[] = "CREATE TABLE {$p}vip_entitlements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			post_id BIGINT UNSIGNED NOT NULL,
			source VARCHAR(20) NOT NULL DEFAULT 'purchase',
			payment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			price_paid DECIMAL(18,4) NOT NULL DEFAULT 0,
			expires_at DATETIME NULL,
			revoked_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_post (user_id,post_id),
			KEY post (post_id)
		) {$charset};";

		// In-app direct messages. Unlike Instagram DM there is no 24-hour window here: the shop can
		// message a member whenever it likes, because the transport is ours.
		$sql[] = "CREATE TABLE {$p}vip_threads (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL,
			subject VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			last_message_at DATETIME NULL,
			last_message_preview VARCHAR(255) NOT NULL DEFAULT '',
			unread_admin INT NOT NULL DEFAULT 0,
			unread_user INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY inbox (tenant_id,status,last_message_at),
			KEY user_threads (user_id,last_message_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}vip_messages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			thread_id BIGINT UNSIGNED NOT NULL,
			sender_type VARCHAR(10) NOT NULL DEFAULT 'user',
			sender_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			body TEXT NULL,
			attachment LONGTEXT NULL,
			post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			read_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY thread_stream (thread_id,id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}fx_wallets (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			balance_usd DECIMAL(18,4) NOT NULL DEFAULT 0,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}fx_ledger (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reason VARCHAR(32) NOT NULL DEFAULT '',
			reference VARCHAR(191) NOT NULL DEFAULT '',
			amount_usd DECIMAL(18,4) NOT NULL DEFAULT 0,
			amount_irt DECIMAL(18,4) NOT NULL DEFAULT 0,
			rate_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (tenant_id,reason,reference),
			KEY tenant_stream (tenant_id,id),
			KEY created_at (created_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}fx_rates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rate_irt_per_usd DECIMAL(18,4) NOT NULL DEFAULT 0,
			source VARCHAR(16) NOT NULL DEFAULT 'manual',
			captured_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY captured_at (captured_at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}fx_prices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			service VARCHAR(32) NOT NULL DEFAULT '',
			price_usd DECIMAL(18,4) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY service_active (service,is_active)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}fx_accounts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			provider VARCHAR(16) NOT NULL DEFAULT '',
			provider_account_id VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			billing_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_provider (tenant_id,provider)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}fx_bills (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			fx_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			period_start DATE NULL,
			period_end DATE NULL,
			amount_usd DECIMAL(18,4) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'due',
			paid_at DATETIME NULL,
			payout_ref VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status),
			KEY account_period (fx_account_id,period_start)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_shipments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			carrier VARCHAR(64) NOT NULL DEFAULT '',
			tracking_code VARCHAR(191) NOT NULL DEFAULT '',
			delivery_pin VARCHAR(8) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			route_type VARCHAR(32) NOT NULL DEFAULT '',
			cost_irt DECIMAL(18,4) NOT NULL DEFAULT 0,
			is_cod TINYINT(1) NOT NULL DEFAULT 0,
			recipient_name VARCHAR(191) NOT NULL DEFAULT '',
			recipient_phone VARCHAR(32) NOT NULL DEFAULT '',
			recipient_address TEXT NULL,
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status),
			KEY order_id (order_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_marketplace_sync (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			marketplace VARCHAR(32) NOT NULL DEFAULT '',
			action VARCHAR(16) NOT NULL DEFAULT 'upsert',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts INT NOT NULL DEFAULT 0,
			last_error VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status),
			KEY product_market (product_id,marketplace)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_category_mapping (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			marketplace VARCHAR(32) NOT NULL DEFAULT '',
			local_category VARCHAR(191) NOT NULL DEFAULT '',
			remote_category VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_market (tenant_id,marketplace)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_abandoned_carts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			session_key VARCHAR(191) NOT NULL DEFAULT '',
			cart_total DECIMAL(18,4) NOT NULL DEFAULT 0,
			reminder_sent_at DATETIME NULL,
			coupon_code VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status),
			KEY session (session_key)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_ai_credit_ledger (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			delta DECIMAL(12,4) NOT NULL DEFAULT 0,
			reason VARCHAR(32) NOT NULL DEFAULT '',
			reference VARCHAR(191) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY dedupe (user_id,reason,reference),
			KEY tenant_user (tenant_id,user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_giveaways (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			ig_post_id VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			winner_subscriber VARCHAR(191) NOT NULL DEFAULT '',
			winner_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			entries_count INT NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_master_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			phase VARCHAR(8) NOT NULL DEFAULT 'rial',
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'IRT',
			status VARCHAR(20) NOT NULL DEFAULT 'held',
			hold_until DATETIME NULL,
			released_at DATETIME NULL,
			gateway_ref VARCHAR(191) NOT NULL DEFAULT '',
			meta LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_phase (order_id,phase),
			KEY tenant_status (tenant_id,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_master_disputes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			payment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(16) NOT NULL DEFAULT 'app',
			reason VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			resolved_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY payment (payment_id),
			KEY tenant_status (tenant_id,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_master_withdrawals (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			method VARCHAR(16) NOT NULL DEFAULT 'card',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			detail VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_master_agreements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(32) NOT NULL DEFAULT 'escrow',
			version VARCHAR(16) NOT NULL DEFAULT '1.0',
			accepted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			accepted_at DATETIME NOT NULL,
			ip VARCHAR(64) NOT NULL DEFAULT '',
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_type (tenant_id,type)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_nid_verifications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			national_id_hash VARCHAR(64) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			ref VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY user (user_id),
			KEY tenant (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_legal_agreements (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(32) NOT NULL DEFAULT 'payment_without_nid',
			version VARCHAR(16) NOT NULL DEFAULT '1.0',
			accepted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			accepted_at DATETIME NOT NULL,
			ip VARCHAR(64) NOT NULL DEFAULT '',
			content_hash VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_type (tenant_id,type)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_domains (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(191) NOT NULL DEFAULT '',
			type VARCHAR(16) NOT NULL DEFAULT 'subdomain',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			provider_ref VARCHAR(191) NOT NULL DEFAULT '',
			dns_verified TINYINT(1) NOT NULL DEFAULT 0,
			expires_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant (tenant_id),
			KEY name (name)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_domain_orders (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			domain_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			action VARCHAR(16) NOT NULL DEFAULT 'register',
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			provider_ref VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant (tenant_id),
			KEY domain (domain_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_web_presence (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			service VARCHAR(32) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			detail VARCHAR(255) NOT NULL DEFAULT '',
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tenant_service (tenant_id,service)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_couriers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(191) NOT NULL DEFAULT '',
			phone VARCHAR(32) NOT NULL DEFAULT '',
			vehicle VARCHAR(32) NOT NULL DEFAULT '',
			zone VARCHAR(64) NOT NULL DEFAULT '',
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant (tenant_id),
			KEY user (user_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_label_groups (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			title VARCHAR(191) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_label_group_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			group_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY group_shipment (group_id,shipment_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_cod_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			method VARCHAR(16) NOT NULL DEFAULT 'cash',
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			ref VARCHAR(191) NOT NULL DEFAULT '',
			gateway_link VARCHAR(500) NOT NULL DEFAULT '',
			card_transfer_ref VARCHAR(191) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY shipment (shipment_id),
			KEY tenant (tenant_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_courier_routes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			courier_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shipment_ids TEXT NULL,
			payload LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY courier (courier_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_courier_tracking (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			lat DECIMAL(10,7) NOT NULL DEFAULT 0,
			lng DECIMAL(10,7) NOT NULL DEFAULT 0,
			at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY shipment (shipment_id),
			KEY at (at)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}ig_courier_chat (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			shipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sender VARCHAR(12) NOT NULL DEFAULT 'courier',
			body TEXT NULL,
			read_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY shipment (shipment_id)
		) {$charset};";

		$sql[] = "CREATE TABLE {$p}logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			level VARCHAR(16) NOT NULL DEFAULT 'info',
			channel VARCHAR(64) NOT NULL DEFAULT '',
			message VARCHAR(1000) NOT NULL DEFAULT '',
			context LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY level_channel (level,channel),
			KEY created_at (created_at)
		) {$charset};";

		// ---------------------------------------------------------------------
		// Pado (AI assistant) scaffolding (v19, 1406/05/31).
		//
		// approval_requests is the single table behind the "درخواست‌های مجوز"
		// tab in the Pado center. Every sensitive operation that needs a
		// human yes/no (theme apply/rollback, price change, refund, instagram
		// publish of any of the four content kinds, bulk delete, campaign
		// send, policy change, ...) creates one row. Statuses: pending /
		// approved / rejected / executed / failed / cancelled.
		// ---------------------------------------------------------------------
		$sql[] = "CREATE TABLE {$p}approval_requests (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			kind VARCHAR(64) NOT NULL DEFAULT '',
			title VARCHAR(255) NOT NULL DEFAULT '',
			reason TEXT NULL,
			payload LONGTEXT NULL,
			impact VARCHAR(32) NOT NULL DEFAULT 'low',
			requested_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			decided_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'pending',
			decision_note TEXT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			decided_at DATETIME NULL,
			executed_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status,created_at),
			KEY kind (kind),
			KEY created_at (created_at)
		) {$charset};";

		// Theme artefacts produced by Pado (or uploaded) and validated by
		// the backend gate. Statuses: draft / preview / live / rejected /
		// archived.
		$sql[] = "CREATE TABLE {$p}themes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			slug VARCHAR(128) NOT NULL DEFAULT '',
			name VARCHAR(191) NOT NULL DEFAULT '',
			source VARCHAR(16) NOT NULL DEFAULT 'pado',
			zip_path VARCHAR(512) NOT NULL DEFAULT '',
			size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(16) NOT NULL DEFAULT 'draft',
			validation LONGTEXT NULL,
			preview_url VARCHAR(512) NOT NULL DEFAULT '',
			approval_request_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			generated_by VARCHAR(64) NOT NULL DEFAULT '',
			prompt LONGTEXT NULL,
			metadata LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tenant_status (tenant_id,status),
			KEY slug (slug),
			KEY created_at (created_at)
		) {$charset};";

		return $sql;
	}
}
