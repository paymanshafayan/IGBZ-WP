<?php
/**
 * Phase 47 — SEO & advertising: structured data keeps content parity, head
 * tags carry one honest canonical, the sitemap lists only sellable products,
 * campaigns spend only after approval and only within their cap, and the
 * bulk-content guard refuses mass generation past the daily cap.
 */

declare( strict_types = 1 );

use IGBZ\Suite\Modules\MultiTenant\Seo\AdCampaignService;
use IGBZ\Suite\Modules\MultiTenant\Seo\AdvertorialPublisherInterface;
use IGBZ\Suite\Modules\MultiTenant\Seo\ContentThrottle;
use IGBZ\Suite\Modules\MultiTenant\Seo\HeadTagsService;
use IGBZ\Suite\Modules\MultiTenant\Seo\SeoService;
use IGBZ\Suite\Modules\MultiTenant\Seo\SitemapService;
use IGBZ\Suite\Modules\MultiTenant\Seo\StructuredDataService;
use IGBZ\Suite\Support\Db;

if ( ! function_exists( 'wc_get_products' ) ) {
	/** Test stand-in: products registered in the global basket. */
	function wc_get_products( array $args = [] ) {
		$products = array_values( $GLOBALS['igbz_test_products'] ?? [] );
		$limit    = (int) ( $args['limit'] ?? count( $products ) );
		return array_slice( $products, 0, $limit );
	}
}

/** Minimal product stand-in for the SEO layer. */
final class SeoProduct {

	public function __construct(
		private int $id,
		private string $name,
		private string $description,
		private float $price,
		private int $stock,
		private string $sku = '',
		private int $image_id = 0
	) {}

	public function get_id(): int { return $this->id; }
	public function get_name(): string { return $this->name; }
	public function get_description(): string { return $this->description; }
	public function get_price(): float { return $this->price; }
	public function get_stock_quantity(): int { return $this->stock; }
	public function get_sku(): string { return $this->sku; }
	public function get_image_id(): int { return $this->image_id; }
}

/** A publisher whose answer is scripted. */
final class ScriptedPublisher implements AdvertorialPublisherInterface {

	public function __construct( public bool $configured = true, public bool $ok = true ) {}

	public function is_configured(): bool {
		return $this->configured;
	}

	public function publish_advertorial( string $title, string $body_html, array $target_media = [] ): array {
		return $this->ok
			? [ 'ok' => true, 'reference' => 'tri-77', 'message' => '' ]
			: [ 'ok' => false, 'reference' => '', 'message' => 'network down' ];
	}
}

/** In-memory engine for campaigns and the SEO activity ledger. */
final class SeoDb extends wpdb {

	/** @var array<string,array<int,array<string,mixed>>> */
	public array $tables = [ 'ig_ad_campaigns' => [], 'ig_seo_activity' => [] ];

	private int $next_id = 1;

	/** @return array<string,mixed>|null */
	public function first_row( string $table ): ?array {
		$rows = array_values( $this->tables[ $table ] );
		return $rows[0] ?? null;
	}

	/** The bare logical name, whatever prefix the engine used. */
	private function logical( string $table ): string {
		foreach ( [ 'ig_ad_campaigns', 'ig_seo_activity' ] as $known ) {
			if ( str_ends_with( $table, $known ) ) {
				return $known;
			}
		}
		return $table;
	}

	public function get_row( string $sql, $output = null ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'ig_ad_campaigns' ) && preg_match( '/id = \'(\d+)\'/', $sql, $m ) ) {
			return $this->tables['ig_ad_campaigns'][ (int) $m[1] ] ?? null;
		}
		if ( str_contains( $sql, 'ig_seo_activity' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			foreach ( $this->tables['ig_seo_activity'] as $row ) {
				if ( (string) $row['tenant_id'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}

		return parent::get_row( $sql, $output );
	}

	public function get_var( string $sql, $column = 0, $row = 0 ) {
		$this->queries[] = $sql;

		if ( str_contains( $sql, 'SUM(count)' ) && str_contains( $sql, 'ig_seo_activity' ) && preg_match( "/tenant_id = '(\d+)'/", $sql, $m ) ) {
			$total = 0;
			foreach ( $this->tables['ig_seo_activity'] as $r ) {
				if ( (string) $r['tenant_id'] === $m[1] ) {
					$total += (int) $r['count'];
				}
			}
			return $total;
		}

		return parent::get_var( $sql, $column, $row );
	}

	public function insert( string $table, array $data, $format = null ): int|bool {
		$this->queries[] = 'INSERT ' . $table;
		$table = $this->logical( $table );

		if ( isset( $this->tables[ $table ] ) ) {
			$id = $this->next_id++;
			$data['id'] = $id;
			$this->tables[ $table ][ $id ] = $data;
			$this->insert_id = $id;
			return $id;
		}

		return parent::insert( $table, $data, $format );
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|bool {
		$this->queries[] = 'UPDATE ' . $table;
		$table = $this->logical( $table );

		if ( isset( $this->tables[ $table ] ) ) {
			$changed = 0;
			foreach ( $this->tables[ $table ] as $id => $row ) {
				$hit = true;
				foreach ( $where as $column => $value ) {
					if ( (string) ( $row[ $column ] ?? '' ) !== (string) $value ) {
						$hit = false;
						break;
					}
				}
				if ( $hit ) {
					$this->tables[ $table ][ $id ] = array_merge( $row, $data );
					++$changed;
				}
			}
			return $changed;
		}

		return parent::update( $table, $data, $where, $format, $where_format );
	}
}

final class SeoAdsTest extends TestCase {

	private SeoDb $sdb;
	private Db $db;

	private function boot(): void {
		igbz_test_reset_settings();

		$this->sdb       = new SeoDb();
		$GLOBALS['wpdb'] = $this->sdb;

		$this->db = new Db();
		$ref = new ReflectionProperty( Db::class, 'is_sqlite' );
		$ref->setValue( $this->db, true );
	}

	/** @return array<string,mixed> */
	private function campaigns(): AdCampaignService {
		return new AdCampaignService( $this->db, new IGBZ\Suite\Support\Logger( igbz()->settings() ), new ScriptedPublisher() );
	}

	public function run(): void {
		$this->test_structured_data_keeps_content_parity();
		$this->test_head_tags_carry_one_honest_canonical();
		$this->test_the_sitemap_lists_only_sellable_products();
		$this->test_campaigns_follow_the_approval_gate();
		$this->test_spending_never_leaves_the_budget();
		$this->test_the_bulk_content_guard_holds_the_cap();
	}

	public function test_structured_data_keeps_content_parity(): void {
		$this->boot();
		$service = new StructuredDataService();

		$product = new SeoProduct( 7, 'کفش کوهنوردی', 'یک کفش محکم برای کوهستان', 1500000.0, 4, 'SKU-9', 3 );
		$jsonld  = $service->product_jsonld( $product );

		$this->assert_same( 'Product', $jsonld['@type'], 'the type is Product' );
		$this->assert_same( 'کفش کوهنوردی', $jsonld['name'], 'the real name is used' );
		$this->assert_same( '1500000.00', $jsonld['offers']['price'], 'price is a plain numeric string' );
		$this->assert_same( 'IRR', $jsonld['offers']['priceCurrency'], 'an ISO 4217 currency' );
		$this->assert_same( 'https://schema.org/InStock', $jsonld['offers']['availability'], 'stock maps to the schema.org URL' );
		$this->assert_false( isset( $jsonld['aggregateRating'] ), 'no fabricated ratings' );

		$sold_out = new SeoProduct( 8, 'کفش', 'توضیح', 900.0, 0 );
		$this->assert_same( 'https://schema.org/OutOfStock', $service->product_jsonld( $sold_out )['offers']['availability'], 'empty stock says OutOfStock' );

		$free = new SeoProduct( 9, 'هدیه', '', 0.0, 1 );
		$this->assert_false( isset( $service->product_jsonld( $free )['offers'] ), 'a zero price never pretends to be a listing' );
	}

	public function test_head_tags_carry_one_honest_canonical(): void {
		$this->boot();
		$head = new HeadTagsService( new SeoService() );

		$sellable = new SeoProduct( 11, 'کوله پشتی', 'یک کولهٔ سبک', 800000.0, 2 );
		$tags     = $head->product_head( $sellable );
		$this->assert_same( 'https://shop.test/?p=11', $tags['canonical'], 'one canonical, the real page' );
		$this->assert_true( str_contains( $tags['meta_title'], 'کوله پشتی' ), 'the title carries the real product name' );
		$this->assert_true( str_contains( $tags['html'], 'rel="canonical"' ), 'the canonical reaches the html' );
		$this->assert_same( 'index,follow', $tags['robots'], 'a sellable page is indexable' );

		$ghost = new SeoProduct( 12, 'نامرئی', '', 0.0, 1 );
		$this->assert_same( 'noindex,follow', $head->product_head( $ghost )['robots'], 'an unsellable page hides itself' );
	}

	public function test_the_sitemap_lists_only_sellable_products(): void {
		$this->boot();
		$GLOBALS['igbz_test_products'] = [
			new SeoProduct( 21, 'الف', '', 100.0, 1 ),
			new SeoProduct( 22, 'ب', '', 0.0, 1 ),
			new SeoProduct( 23, 'ج', '', 200.0, 1 ),
		];

		$urls = ( new SitemapService() )->urls( 500 );
		$this->assert_same( 2, count( $urls ), 'only sellable products appear' );
		$this->assert_true( str_contains( $urls[0], 'p=21' ), 'the first real product is listed' );

		$xml = ( new SitemapService() )->xml( 500 );
		$this->assert_true( str_contains( $xml, '<urlset' ), 'the sitemap renders' );
		$this->assert_false( str_contains( $xml, 'p=22' ), 'the unsellable product stays out' );
	}

	public function test_campaigns_follow_the_approval_gate(): void {
		$this->boot();
		$service    = $this->campaigns();
		$campaign   = $service->create( 'کمپین رپرتاژ', 'triboon', 1000000, 5 );

		$this->assert_same( 'pending_approval', (string) $campaign['status'], 'a campaign is born pending' );

		$early_launch = $service->launch_advertorial( (int) $campaign['id'], 'تیتر', '<p>بدنه</p>', 10000 );
		$this->assert_false( $early_launch['ok'], 'nothing spends before approval' );
		$this->assert_same( 'bad_state', $early_launch['error'], 'the refusal names the state' );

		$this->assert_true( $service->approve( (int) $campaign['id'], 9 )['ok'], 'the pending campaign can be approved' );
		$this->assert_false( $service->approve( (int) $campaign['id'], 9 )['ok'], 'approval is one-way' );

		$second = $service->create( 'کمپین دوم', 'triboon', 50000, 5 );
		$this->assert_true( $service->reject( (int) $second['id'], 'خارج از تقویم' )['ok'], 'rejection lands' );
		$this->assert_false( $service->approve( (int) $second['id'], 9 )['ok'], 'a rejected campaign cannot be revived' );
	}

	public function test_spending_never_leaves_the_budget(): void {
		$this->boot();
		$service  = $this->campaigns();
		$campaign = $service->create( 'کمپین بودجه', 'triboon', 100000, 5 );
		$id       = (int) $campaign['id'];
		$service->approve( $id, 9 );

		$too_big = $service->launch_advertorial( $id, 'تیتر', '<p>بدنه</p>', 150000 );
		$this->assert_false( $too_big['ok'], 'an order bigger than the remaining budget is refused' );
		$this->assert_same( 'over_budget', $too_big['error'], 'the refusal names the budget' );

		$first = $service->launch_advertorial( $id, 'تیتر', '<p>بدنه</p>', 60000 );
		$this->assert_true( $first['ok'], 'a covered order lands' );
		$this->assert_same( 'tri-77', $first['reference'], 'the provider reference is passed through' );
		$this->assert_same( 60000, (int) $service->campaign( $id )['spent_irt'], 'the spend is recorded after acknowledgement' );

		$second = $service->launch_advertorial( $id, 'تیتر', '<p>بدنه</p>', 60000 );
		$this->assert_false( $second['ok'], 'the cap stops the second order' );
		$this->assert_same( 60000, (int) $service->campaign( $id )['spent_irt'], 'nothing extra was spent' );
	}

	public function test_the_bulk_content_guard_holds_the_cap(): void {
		$this->boot();
		igbz()->settings()->set( 'seo.daily_content_cap', 3 );
		$throttle = new ContentThrottle( $this->db );

		$this->assert_same( 3, $throttle->remaining( 5 ), 'the day starts with the full cap' );
		$this->assert_true( $throttle->record( 5 ), 'first artefact lands' );
		$this->assert_true( $throttle->record( 5 ), 'second artefact lands' );
		$this->assert_true( $throttle->record( 5 ), 'third artefact lands' );
		$this->assert_false( $throttle->record( 5 ), 'the cap refuses the fourth' );
		$this->assert_same( 0, $throttle->remaining( 5 ), 'nothing is left today' );
	}
}
