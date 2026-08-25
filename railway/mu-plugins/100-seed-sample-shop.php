<?php
/**
 * Harness only. Seeds the dev environment with a sample cosmetics store:
 * 25 simple products across realistic categories, plus 100 customer accounts
 * with Persian names and a default password. Safe to re-run: the presence of
 * the igbz_seeded_v1 option skips everything on subsequent boots.
 *
 * Runs late on wp_loaded (after 030-default-theme has created WC pages),
 * and wraps each logical block in try/catch so a
 * single bad record never fatal-flags the whole bootstrap.
 */
add_action( 'wp_loaded', function () {
	if ( get_option( 'igbz_seeded_v1' ) ) { return; }
	if ( ! class_exists( 'WooCommerce' ) ) { return; }
	if ( ! get_role( 'customer' ) ) { return; } // WooCommerce not ready yet.

	// Elementor Kit guard removed 1406/05/31 — Elementor no longer bundled.

	$summary = [ 'products' => 0, 'customers' => 0, 'orders' => 0, 'errors' => 0 ];
	$now = current_time( 'mysql', true );

	try {
	// ----- 1. Product categories -----
	$cat_defs = [
		'مراقبت پوست'      => 'پاک‌کننده، مرطوب‌کننده و ضدآفتاب',
		'مراقبت مو'        => 'شامپو، نرم‌کننده و ماسک مو',
		'آرایش صورت'       => 'کرم‌پودر، پنکیک، کانسیلر، رژگونه',
		'آرایش چشم'        => 'سایه، خط‌چشم، ریمل، ابرو',
		'آرایش لب'         => 'رژ لب، بالم لب، خط لب',
		'عطر و ادکلن'      => 'عطرهای زنانه و مردانه',
		'بهداشت بدن'       => 'شاور ژل، لوسیون، دئودورانت',
		'ابزار آرایش'      => 'براش، پد پاک‌کننده، کیف',
	];
	$cat_ids = [];
	foreach ( $cat_defs as $name => $desc ) {
		$existing = term_exists( $name, 'product_cat' );
		if ( $existing ) { $cat_ids[ $name ] = (int) $existing['term_id']; continue; }
		$r = wp_insert_term( $name, 'product_cat', [ 'description' => $desc ] );
		if ( ! is_wp_error( $r ) ) { $cat_ids[ $name ] = (int) $r['term_id']; }
	}

	// ----- 2. Placeholder PNG (solid gradient, generated with PHP image primitives if available) -----
	$placeholder_url = '';
	$placeholder_id  = 0;
	if ( function_exists( 'imagecreatetruecolor' ) ) {
		$upload = wp_upload_dir();
		if ( empty( $upload['error'] ) ) {
			$file = trailingslashit( $upload['path'] ) . 'igbz-sample-placeholder.png';
			if ( ! file_exists( $file ) ) {
				$w = 800; $h = 800;
				$im = @imagecreatetruecolor( $w, $h );
				if ( $im ) {
					for ( $y = 0; $y < $h; $y++ ) {
						$r = 236 - (int)( $y * 0.08 );
						$g = 72  + (int)( $y * 0.05 );
						$b = 153 - (int)( $y * 0.02 );
						$c = imagecolorallocate( $im, max(0,min(255,$r)), max(0,min(255,$g)), max(0,min(255,$b)) );
						imageline( $im, 0, $y, $w, $y, $c );
					}
					// Centered product-mark.
					$white = imagecolorallocate( $im, 255, 255, 255 );
					$bx = (int)( $w*0.18 ); $by = (int)( $h*0.18 );
					imagefilledrectangle( $im, $bx, $by, $w-$bx, $h-$by, imagecolorallocatealpha( $im, 255, 255, 255, 60 ) );
					imagerectangle( $im, $bx, $by, $w-$bx, $h-$by, $white );
					imagestring( $im, 5, (int)( $w*0.32 ), (int)( $h*0.48 ), 'IGBZ SAMPLE', $white );
					imagepng( $im, $file );
					imagedestroy( $im );
				}
			}
			if ( file_exists( $file ) ) {
				$ft = wp_check_filetype( basename( $file ), null );
				$attach = [
					'guid'           => $upload['url'] . '/' . basename( $file ),
					'post_mime_type' => $ft['type'],
					'post_title'     => 'تصویر نمونه محصول',
					'post_content'   => '',
					'post_status'    => 'inherit',
				];
				$aid = wp_insert_attachment( $attach, $file );
				if ( $aid && ! is_wp_error( $aid ) ) {
					require_once ABSPATH . 'wp-admin/includes/image.php';
					wp_update_attachment_metadata( $aid, wp_generate_attachment_metadata( $aid, $file ) );
					$placeholder_id  = $aid;
					$placeholder_url = wp_get_attachment_url( $aid );
				}
			}
		}
	}

	// ----- 3. 25 simple products -----
	$products = [
		// name, category, regular price (IRR), sale price|null, short desc
		[ 'کرم مرطوب‌کننده صورت ۱۰۰میل',          'مراقبت پوست',  3200000,  2800000, 'آبرسان قوی برای پوست خشک و حساس' ],
		[ 'سرم ویتامین سی روشن‌کننده ۳۰میل',      'مراقبت پوست',  4500000,  null,    'روشن‌کننده و ضد لک با ویتامین سی پایدار' ],
		[ 'ضدآفتاب فاقد چربی SPF50 ۵۰میل',         'مراقبت پوست',  1950000,  1750000, 'مناسب پوست چرب و مستعد جوش' ],
		[ 'پاک‌کننده میسلار واتر ۴۰۰میل',          'مراقبت پوست',  1250000,  null,    'پاک‌کننده آرایش چشم و صورت بدون نیاز به آبکشی' ],
		[ 'کرم دور چشم ضدچروک ۱۵میل',              'مراقبت پوست',  2700000,  null,    'کاهش تیرگی و پف زیر چشم' ],
		[ 'شامپو ترمیم‌کننده مو آسیب‌دیده ۴۰۰میل', 'مراقبت مو',    1800000,  1550000, 'با کراتین و آرگان برای موهای وزدار' ],
		[ 'نرم‌کننده مو ابریشمی ۳۰۰میل',          'مراقبت مو',    1450000,  null,    'نرم‌کننده بدون سولفات برای مو رنگ‌شده' ],
		[ 'ماسک مو روغن آرگان ۲۵۰میل',            'مراقبت مو',    2100000,  1890000, 'تغذیه عمیق و درخشندگی فوری' ],
		[ 'کرم‌پودر مات با پوشش متوسط',            'آرایش صورت',  3100000,  null,    'ماندگاری ۲۴ساعته، مناسب انواع پوست' ],
		[ 'پنکیک فشرده پودری SPF15',               'آرایش صورت',  1750000,  1500000, 'فینیش طبیعی و کنترل چربی' ],
		[ 'کانسیلر مایع با پوشش بالا',             'آرایش صورت',  1400000,  null,    'پوشاننده تیرگی، لک و جای جوش' ],
		[ 'رژگونه مایع گل‌بهی',                    'آرایش صورت',  1250000,  null,    'بافت سبک و طبیعی، ماندگاری بالا' ],
		[ 'پالت سایه چشم ۱۲رنگ نود',              'آرایش چشم',    2950000,  2650000, 'رنگ‌های مات و براق مناسب آرایش روزانه' ],
		[ 'ریمل حجم‌دهنده و بلندکننده',           'آرایش چشم',     950000,  null,    'ضدریزش و ضدلک تا ۱۲ساعت' ],
		[ 'خط چشم مایع مشکی ضدآب',                 'آرایش چشم',     850000,  null,    'نوک نمدی ظریف برای خط چشم حرفه‌ای' ],
		[ 'مداد ابرو با برس دوسر',                 'آرایش چشم',     720000,  620000,  '۳ رنگ قهوه‌ای، بلوند و مشکی' ],
		[ 'رژ لب مات مخملی ۲۴ساعته',              'آرایش لب',      980000,  null,    'بافت نرم، بدون خشکی لب' ],
		[ 'بالم لب مغذی با عسل و وازلین',          'آرایش لب',      450000,  null,    'ترمیم لب‌های ترک‌خورده' ],
		[ 'خط لب ضدخش رنگ رز',                     'آرایش لب',      550000,  null,    'ماندگاری بالا و بافت کرمی' ],
		[ 'عطر زنانه گل رز ۱۰۰میل',                'عطر و ادکلن',  5800000,  5200000, 'رایحه گلی و شیرین با ماندگاری ۸ساعته' ],
		[ 'ادکلن مردانه چوبی-شرقی ۱۰۰میل',         'عطر و ادکلن',  6200000,  null,    'خنک و جذاب برای استفاده روزمره' ],
		[ 'شاور ژل کرمی شیر و عسل ۵۰۰میل',        'بهداشت بدن',   780000,  null,    'شست‌وشوی ملایم با حفظ رطوبت پوست' ],
		[ 'لوسیون بدن مغذی شی باتر ۴۰۰میل',       'بهداشت بدن',  1100000,  null,    'نرم‌کننده قوی برای پوست خشک' ],
		[ 'دئودورانت رول‌آن بانوان ۲۴ساعته',        'بهداشت بدن',   620000,  560000,  'فاقد الکل، ضدلک لباس' ],
		[ 'ست ۱۲قلمی براش آرایش حرفه‌ای',          'ابزار آرایش', 1900000,  1650000, 'براش‌های با موی مصنوعی نرم' ],
	];

	$n = 0;
	$customer_count = 0;
	$order_idx = 0;
	foreach ( $products as [ $name, $cat, $reg, $sale, $desc ] ) {
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( sanitize_title( $name ) . '-' . ( $n + 1 ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_description( '<p>' . esc_html( $desc ) . ' — محصول نمونه جهت تست و نمایش فروشگاه.</p>' );
		$product->set_short_description( esc_html( $desc ) );
		$product->set_regular_price( (string) $reg );
		if ( $sale ) { $product->set_sale_price( (string) $sale ); }
		$product->set_price( (string) ( $sale ?? $reg ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 50 + wp_rand( 0, 200 ) );
		$product->set_stock_status( 'instock' );
		$product->set_weight( (string) ( 0.1 + ( wp_rand( 5, 80 ) / 100 ) ) );
		$product->set_sku( 'SAMPLE-' . str_pad( (string)( $n + 1 ), 4, '0', STR_PAD_LEFT ) );
		$product->set_sold_individually( false );
		if ( $placeholder_id ) { $product->set_image_id( $placeholder_id ); }
		$pid = $product->save();
		if ( $pid && ! is_wp_error( $pid ) && isset( $cat_ids[ $cat ] ) ) {
			wp_set_object_terms( $pid, [ (int) $cat_ids[ $cat ] ], 'product_cat' );
		}
		$n++;
	}

	// ----- 4. 100 customers -----
	$first_female = [ 'مریم','سارا','زهرا','فاطمه','نگار','پریسا','نیلوفر','الناز','آناهیتا','آتنا','مهسا','شقایق','نازنین','یاسمن','رؤیا','ترانه','بهار','ملیکا','پانته‌آ','هستی','محدثه','ریحانه','آیلین','سپیده','هلیا' ];
	$first_male   = [ 'علی','محمدرضا','امیر','حسین','سینا','آرش','مهدی','بهرام','نیما','کامران','رضا','پوریا','سهیل','بهزاد','شاهین','فرزاد','آرمین','کیان','محمد','پدرام','میلاد','سامان','یاشار','حامد','مبین' ];
	$last_names   = [ 'احمدی','محمدی','رضایی','کریمی','موسوی','حسینی','نوری','صادقی','فرجی','عباسی','مرادی','اکبری','رحیمی','نظری','سلطانی','شریفی','شیرازی','زاده','کاظمی','قاسمی','جعفری','پارسا','نیکو','بهروز','رستمی' ];

	$customer_count = 0;
	$password = wp_hash_password( 'Customer123!' );
	for ( $i = 1; $i <= 100; $i++ ) {
		$female = ( $i % 2 === 0 );
		$first  = $female ? $first_female[ $i % count( $first_female ) ] : $first_male[ $i % count( $first_male ) ];
		$last   = $last_names[ ( $i * 7 + 3 ) % count( $last_names ) ];
		$email  = sprintf( 'customer%03d@example.com', $i );
		if ( email_exists( $email ) ) { continue; }
		$user_id = wp_insert_user( [
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => 'Customer123!',
			'first_name'   => $first,
			'last_name'    => $last,
			'display_name' => $first . ' ' . $last,
			'nickname'     => $first . ' ' . $last,
			'role'         => 'customer',
		] );
		if ( is_wp_error( $user_id ) ) { continue; }
		// Phone (0912/0935/0930/0901) — fake but valid format.
		$prefixes = [ '0912','0935','0930','0901','0990','0903' ];
		$phone = $prefixes[ $i % count( $prefixes ) ] . str_pad( (string) ( 1000000 + ( $i * 137 ) % 9000000 ), 7, '0', STR_PAD_LEFT );
		update_user_meta( $user_id, 'billing_phone',     $phone );
		update_user_meta( $user_id, 'billing_first_name', $first );
		update_user_meta( $user_id, 'billing_last_name',  $last );
		update_user_meta( $user_id, 'billing_country',   'IR' );
		update_user_meta( $user_id, 'billing_city',       $i % 3 === 0 ? 'کرج' : 'تهران' );
		update_user_meta( $user_id, 'billing_address_1', 'خیابان نمونه، پلاک ' . ( 10 + $i ) );
		update_user_meta( $user_id, 'billing_postcode',  sprintf( '%010d', 1000000000 + $i * 137 % 900000000 ) );
		$customer_count++;
	}

	// ----- 5. A handful of processing/completed orders for realism -----
	global $wpdb;
	$customer_ids = get_users( [ 'role' => 'customer', 'fields' => 'ID', 'number' => 12 ] );
	$product_ids  = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type='product' AND post_status='publish' ORDER BY ID ASC LIMIT 15" );
	$statuses     = [ 'processing','processing','completed','completed','on-hold','completed' ];
	$order_idx    = 0;
	foreach ( $customer_ids as $cid ) {
		if ( $order_idx >= 8 ) { break; }
		$order = wc_create_order( [ 'customer_id' => (int) $cid, 'status' => 'pending' ] );
		if ( is_wp_error( $order ) ) { continue; }
		$pick = array_slice( $product_ids, wp_rand( 0, max( 0, count( $product_ids ) - 3 ) ), wp_rand( 1, 3 ) );
		foreach ( $pick as $pid ) {
			$order->add_product( wc_get_product( (int) $pid ), wp_rand( 1, 3 ) );
		}
		$order->set_address( [
			'first_name' => get_user_meta( $cid, 'billing_first_name', true ),
			'last_name'  => get_user_meta( $cid, 'billing_last_name',  true ),
			'address_1'  => get_user_meta( $cid, 'billing_address_1',  true ),
			'city'       => get_user_meta( $cid, 'billing_city',       true ),
			'postcode'   => get_user_meta( $cid, 'billing_postcode',   true ),
			'country'    => 'IR',
			'phone'      => get_user_meta( $cid, 'billing_phone',       true ),
			'email'      => get_userdata( $cid )->user_email,
		], 'billing' );
		$order->calculate_totals();
		$order->update_status( $statuses[ $order_idx % count( $statuses ) ] );
		$order_idx++;
	}

	$summary = [
		'products'  => (int) ( $n ?? 0 ),
		'customers' => (int) ( $customer_count ?? 0 ),
		'orders'    => (int) ( $order_idx ?? 0 ),
		'at'        => $now,
	];
	} catch ( \Throwable $e ) {
		$summary['fatal'] = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
	}
	// Always mark as seeded — the site is meant for demos, not for re-seeding
	// every request. If a partial run happened, bump 'v1' -> 'v2' to reseed.
	update_option( 'igbz_seeded_v1', $summary );
}, 60 );
