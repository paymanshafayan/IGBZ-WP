/**
 * صفحهٔ تنظیمات «پراکسی» — به سبک صفحهٔ پراکسی ویندوز (تصویر Snap15 کارفرما):
 * کلید روشن/خاموش، آدرس+پورت، استثناها با «؛»، تیک «شبکهٔ محلی بدون پراکسی»، Save.
 * به‌علاوهٔ بخش موتور تونل داخلی (۰.۹.۶) و دکمهٔ تست مسیر.
 */

import { el, h, toast } from './lib/dom.js';
import { api, post } from './lib/api.js';

let state = { proxy: {}, tunnel: {} };

async function refresh() {
	const [ p, t ] = await Promise.all( [ api( '/api/proxy' ), api( '/api/tunnel' ) ] );
	state = { proxy: p.proxy || {}, tunnel: t };
}

export async function renderProxySettings( box ) {
	box.replaceChildren( el( 'div', 'loading', 'در حال خواندن تنظیمات…' ) );
	await refresh();
	box.replaceChildren();

	const p = state.proxy;
	const t = state.tunnel || {};
	const core = t.core || {};

	/* ——— بخش ویندوزی: استفاده از سرور پراکسی ——— */
	const on = p.mode !== 'off';
	const mode = h( 'select', { class: 'field' } );
	for ( const [ v, label ] of [ [ 'off', 'خاموش (اتصال مستقیم)' ], [ 'manual', 'پراکسی دستی — مثل Hiddify روی این سیستم' ], [ 'engine', 'موتور تونل داخلی ویرا' ] ] ) {
		mode.appendChild( h( 'option', { value: v, text: label } ) );
	}
	mode.value = p.mode || 'off';

	const address = h( 'input', { class: 'field', dir: 'ltr', value: p.address || '127.0.0.1' } );
	const port = h( 'input', { class: 'field', dir: 'ltr', type: 'number', value: p.port || 7890, style: 'max-width:110px' } );
	const exceptions = h( 'textarea', {
		class: 'field mono', dir: 'ltr', rows: 3,
		value: p.exceptions || 'localhost;127.*;10.*;172.16.*;172.17.*;172.18.*;172.19.*;172.2?.*;172.30.*;172.31.*;192.168.*',
	} );
	const bypassLocal = h( 'input', { type: 'checkbox', checked: p.bypassLocal !== false } );

	const manualRow = h( 'div', { class: 'row wrap' } );
	const syncManual = () => {
		manualRow.replaceChildren(
			h( 'div', { class: 'field' }, [ h( 'b', { text: 'نشانی' } ), address ] ),
			h( 'div', { class: 'field' }, [ h( 'b', { text: 'درگاه' } ), port ] ),
		);
		manualRow.hidden = mode.value !== 'manual';
		exceptions.parentElement.hidden = mode.value === 'off';
	};
	mode.onchange = syncManual;

	const result = el( 'p', 'note' );
	const save = h( 'button', {
		class: 'btn solid', text: 'Save',
		onClick: async () => {
			const out = await post( '/api/proxy', {
				mode: mode.value,
				address: address.value.trim() || '127.0.0.1',
				port: Number( port.value ) || 7890,
				exceptions: exceptions.value,
				bypassLocal: bypassLocal.checked,
			} );
			toast( 'تنظیمات پراکسی ذخیره شد.' );
			result.textContent = `نشانی مؤثر: ${ out.effective || 'مستقیم (بدون پراکسی)' }`;
			await refresh();
			syncManual();
			mode.value = state.proxy.mode || 'off';
		},
	} );
	const testBtn = h( 'button', {
		class: 'btn outline', text: 'تست اتصال',
		onClick: async () => {
			result.textContent = 'در حال آزمودن…';
			const out = await post( '/api/hub', { action: 'proxy-test' } );
			const fmt = ( r ) => r?.ok ? `IP ${ r.ip || '؟' } · ${ r.ms }ms` : ( r?.error ? `✗ ${ String( r.error ).slice( 0, 60 ) }` : '✗' );
			result.textContent = `از پراکسی: ${ fmt( out.proxied ) } · مستقیم: ${ fmt( out.direct ) }`;
		},
	} );

	/*
	 * اینجا عمداً هیچ توضیحِ فنی‌ای نیست.
	 *
	 * تا ۰.۹.۶ یک جمله دربارهٔ اینکه «Node.js پراکسی سیستم را نمی‌بیند» بالای این کارت
	 * بود. کارفرما درست گفت که چنین چیزی در رابط جا ندارد: علتِ پیاده‌سازی را توضیح
	 * می‌داد، نه کاری که کاربر باید بکند. توضیح فنی سر جای خودش در `src/net.js` و
	 * DESIGN-HUB-UI-FIX §۲.۱۰.۲ هست.
	 */
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'پراکسی ویرا' } ),
		h( 'div', { class: 'field' }, [ h( 'b', { text: 'حالت' } ), mode ] ),
		manualRow,
		h( 'div', { class: 'field' }, [
			h( 'b', { text: 'استثناها' } ),
			exceptions,
			h( 'p', { class: 'note', text: 'نشانی‌هایی که با این‌ها شروع می‌شوند از پراکسی عبور نمی‌کنند. با «؛» جدا کن. * یعنی هر چیز بعد از پیشوند.' } ),
		] ),
		h( 'label', { class: 'check' }, [ bypassLocal, h( 'span', { text: 'برای نشانی‌های محلی (شبکهٔ داخلی) از پراکسی استفاده نشود' } ) ] ),
		h( 'div', { class: 'modal-actions' }, [ testBtn, h( 'span', { class: 'grow' } ), save ] ),
		result,
	] ) );
	syncManual();

	/* ——— موتور تونل داخلی ——— */
	const tstat = el( 'p', 'note' );
	const paintT = () => {
		const cur = t.current;
		tstat.textContent = t.running
			? `روشن — از «${ cur?.name || '؟' }» می‌گذرد · socks:${ t.ports?.socks } http:${ t.ports?.http } · IP خروجی: ${ t.exitIp || 'نامعلوم' }${ t.lastCheck ? ` · آخرین بررسی ${ t.lastCheck.ok ? 'سالم' : 'ناموفق' } (${ t.lastCheck.ms || 0 }ms)` : '' }`
			: 'خاموش';
	};

	const coreBtn = h( 'button', {
		class: 'btn outline', text: core.present ? `هسته نصب است (${ core.version || '؟' })` : 'دانلود هستهٔ xray',
		onClick: async () => {
			// کار بدون درصد → اسپینر داخل خود دکمه، تا معلوم باشد چیزی در جریان است.
			const label = coreBtn.textContent;
			coreBtn.disabled = true;
			coreBtn.textContent = 'در حال دانلود…';
			coreBtn.classList.add( 'busy' );
			const out = await post( '/api/tunnel', { action: 'download-core' } );
			coreBtn.classList.remove( 'busy' );
			coreBtn.textContent = label;
			coreBtn.disabled = false;
			toast( out.ok ? `هسته آماده شد: ${ out.version || '' }` : ( out.error || 'نشد' ), out.ok ? 'ok' : 'error' );
			await refresh();
			box.replaceChildren();
			await renderProxySettings( box );
		},
	} );
	const startBtn = h( 'button', {
		class: 'btn solid', text: t.running ? 'توقف تونل' : 'روشن‌کردن تونل',
		onClick: async () => {
			const out = await post( '/api/tunnel', { action: t.running ? 'stop' : 'start' } );
			toast( out.ok ? ( t.running ? 'تونل خاموش شد.' : `تونل روشن شد — از «${ out.current || '' }»` ) : ( out.error || 'نشد' ), out.ok ? 'ok' : 'error' );
			await refresh();
			box.replaceChildren();
			await renderProxySettings( box );
		},
	} );
	const harvestBtn = h( 'button', {
		class: 'btn outline', text: 'به‌روزرسانی منابع',
		onClick: async () => {
			harvestBtn.disabled = true;
			harvestBtn.textContent = 'در حال جمع‌آوری…';
			harvestBtn.classList.add( 'busy' );
			const out = await post( '/api/tunnel', { action: 'harvest' } );
			harvestBtn.classList.remove( 'busy' );
			harvestBtn.textContent = 'به‌روزرسانی منابع';
			harvestBtn.disabled = false;
			toast( `${ out.added } کانفیگ تازه (مجموع ${ out.total }).`, 'ok' );
			await refresh();
			box.replaceChildren();
			await renderProxySettings( box );
		},
	} );
	/*
	 * نوار پیشرفت آزمون.
	 *
	 * رویداد `progress` را بک‌اند از قبل می‌فرستاد؛ فقط کسی گوش نمی‌داد. حالا مرحله را
	 * هم نشان می‌دهیم، چون مرحلهٔ ۲ (آزمون سرویس واقعی) کندتر است و بدون توضیح کاربر
	 * فکر می‌کند گیر کرده.
	 */
	const progWrap = h( 'div', { class: 'tunnel-prog', hidden: true } );
	const progBar = h( 'div', { class: 'prog-fill' } );
	const progText = h( 'p', { class: 'note' } );
	const cancelBtn = h( 'button', {
		class: 'btn outline sm', text: 'لغو',
		onClick: async () => {
			cancelBtn.disabled = true;
			await post( '/api/tunnel', { action: 'cancel-test' } );
			toast( 'لغو درخواست شد — تا پایان کانفیگ جاری صبر کن.' );
		},
	} );
	progWrap.append(
		h( 'div', { class: 'prog-track' }, [ progBar ] ),
		h( 'div', { class: 'prog-row' }, [ progText, h( 'span', { class: 'grow' } ), cancelBtn ] )
	);

	const paintProgress = ( p ) => {
		if ( ! p ) {
			progWrap.hidden = true;
			return;
		}
		progWrap.hidden = false;
		cancelBtn.disabled = Boolean( p.cancelled );
		const pct = p.total ? Math.round( ( p.done / p.total ) * 100 ) : 0;
		progBar.style.inlineSize = `${ pct }%`;
		const phase = p.phase === 2
			? `مرحلهٔ ۲ — آزمون سرویس${ p.service ? ` (${ p.service })` : '' }`
			: 'مرحلهٔ ۱ — غربال سریع';
		const tally = `✅ ${ p.healthy || 0 }  ·  🟡 ${ p.internetOnly || 0 }  ·  ❌ ${ p.broken || 0 }`;
		progText.textContent = `${ phase } — ${ p.done } از ${ p.total }  ·  ${ tally }${ p.name ? `  ·  اکنون: ${ p.name }` : '' }`;
	};

	// اگر آزمونی از قبل در جریان بوده (کاربر پنجره را بسته و برگشته)، نوار ادامه دهد.
	paintProgress( t.testing );

	const onTunnelEvent = ( e ) => {
		if ( e.detail?.progress ) {
			paintProgress( e.detail.progress );
		}
	};
	document.addEventListener( 'vira:tunnel', onTunnelEvent );

	const testAllBtn = h( 'button', {
		class: 'btn outline', text: 'تست همهٔ کانفیگ‌ها',
		onClick: async () => {
			testAllBtn.disabled = true;
			testAllBtn.textContent = 'در حال آزمودن…';
			cancelBtn.disabled = false;
			progWrap.hidden = false;
			const out = await post( '/api/tunnel', { action: 'test-all' } );
			document.removeEventListener( 'vira:tunnel', onTunnelEvent );
			testAllBtn.disabled = false;
			testAllBtn.textContent = 'تست همهٔ کانفیگ‌ها';
			toast(
				out.ok
					? `${ out.working } کانفیگ به سرویس رسید${ out.internetOnly ? `، ${ out.internetOnly } فقط اینترنت` : '' } (از ${ out.total }).${ out.cancelled ? ' — لغو شد' : '' }`
					: ( out.error || 'نشد' ),
				out.ok ? 'ok' : 'error'
			);
			await refresh();
			box.replaceChildren();
			await renderProxySettings( box );
		},
	} );
	const rotateBtn = h( 'button', {
		class: 'btn outline', text: 'چرخش به کانفیگ بعدی',
		onClick: async () => {
			const out = await post( '/api/tunnel', { action: 'rotate' } );
			toast( out.ok ? `چرخید به «${ out.current || '' }»` : ( out.error || 'نشد' ), out.ok ? 'ok' : 'error' );
			await refresh();
			paintT();
		},
	} );

	/* منابع */
	const srcBox = h( 'textarea', { class: 'field mono', dir: 'ltr', rows: 4, value: ( t.sources || [] ).join( '\n' ) } );
	const saveSrc = h( 'button', {
		class: 'btn outline sm', text: 'ذخیرهٔ منابع',
		onClick: async () => {
			const urls = srcBox.value.split( /\r?\n/ ).map( ( x ) => x.trim() ).filter( Boolean );
			await post( '/api/tunnel', { action: 'set-sources', urls } );
			toast( 'منابع ذخیره شد.' );
		},
	} );

	/* جدول کانفیگ‌ها */
	const pool = el( 'div', 'card-list compact' );
	const drawPool = () => {
		pool.replaceChildren();
		const rows = ( t.pool || [] ).slice( 0, 40 );
		if ( ! rows.length ) {
			pool.appendChild( el( 'div', 'empty', 'هنوز کانفیگی نیست — «به‌روزرسانی منابع» و بعد «تست همه» را بزن.' ) );
			return;
		}
		for ( const c of rows ) {
			/*
			 * سه حالت به‌جای «سالم/ناسالم»:
			 *   ✅ سالم       — سرویس واقعی از این مسیر جواب داد
			 *   🟡 فقط اینترنت — تونل بالاست ولی سرویس مسدود است
			 *   ❌ خراب        — حتی اینترنت رد نشد
			 * علتِ شکست هم دیده می‌شود؛ قبلاً ذخیره می‌شد ولی نمایش داده نمی‌شد.
			 */
			const tier = c.serviceOk ? 'ok' : c.ok1 ? 'partial' : c.lastCheck ? 'bad' : 'unknown';
			const tierLabel = { ok: 'سالم', partial: 'فقط اینترنت', bad: 'خراب', unknown: 'آزموده نشده' }[ tier ];
			const detail = tier === 'ok'
				? `سرویس پاسخ داد · ${ c.serviceMs || c.ms }ms`
				: tier === 'partial'
					? `اینترنت دارد ولی ${ c.error || 'سرویس جواب نداد' }`
					: c.error || 'آزموده نشده';

			pool.appendChild( h( 'div', { class: `item ${ tier === 'ok' ? '' : 'off' } ${ ( t.current?.name === c.name && t.running ) ? 'bad' : '' }` }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: `${ c.pinned ? '📌 ' : '' }${ c.name || c.host }` } ),
					h( 'p', { class: 'mono note', text: `${ c.proto } · ${ c.host }:${ c.port }` } ),
					h( 'p', { class: 'note', text: `${ detail }${ c.lastCheck ? ` · ${ String( c.lastCheck ).slice( 0, 16 ).replace( 'T', ' ' ) }` : '' }` } ),
				] ),
				h( 'span', { class: `tag ${ tier === 'ok' ? 'ok' : tier === 'partial' ? 'warn' : '' }`, text: tier === 'ok' ? `${ c.ms }ms` : tierLabel } ),
				h( 'button', {
					class: 'btn outline sm', text: c.pinned ? 'برداشتن سنجاق' : 'سنجاق',
					onClick: async () => { await post( '/api/tunnel', { action: 'toggle-config', id: c.id, pinned: ! c.pinned, enabled: c.enabled } ); await refresh(); drawPool(); },
				} ),
				h( 'button', {
					class: 'btn outline sm', text: c.enabled === false ? 'روشن' : 'خاموش',
					onClick: async () => { await post( '/api/tunnel', { action: 'toggle-config', id: c.id, pinned: c.pinned, enabled: c.enabled === false } ); await refresh(); drawPool(); },
				} ),
			] ) );
		}
	};
	drawPool();

	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'موتور تونل داخلی' } ),
		h( 'p', { class: 'note', text: 'مثل یک v2ray داخل ویرا: کانفیگ‌های رایگان را از مخازن عمومی جمع می‌کند، می‌آزماید، سالم‌ها را نگه می‌دارد و درگاه محلی پایداری می‌سازد که خودکار روی بهترین می‌چرخد. هشدار: کانفیگ رایگان یعنی اپراتور ناشناس — برای کار حساس، سرور خودت را به منابع اضافه کن.' } ),
		tstat, paintT(),
		h( 'div', { class: 'modal-actions' }, [ coreBtn, startBtn, rotateBtn ] ),
		h( 'div', { class: 'modal-actions' }, [ harvestBtn, testAllBtn ] ),
	] ) );
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: 'منابع کانفیگ' } ),
		h( 'p', { class: 'note', text: 'هر خط یک نشانی (اشتراک base64 یا فهرست لینک). این‌ها پیش‌فرض‌های راستی‌آزمایی‌شده‌اند؛ می‌توانی کم و زیاد کنی.' } ),
		srcBox,
		h( 'div', { class: 'modal-actions' }, [ h( 'span', { class: 'grow' } ), saveSrc ] ),
	] ) );
	box.appendChild( h( 'div', { class: 'form-card' }, [
		h( 'h4', { text: `کانفیگ‌ها (${ t.working ?? 0 } سالم از ${ t.poolSize ?? 0 })` } ),
		pool,
	] ) );
}
