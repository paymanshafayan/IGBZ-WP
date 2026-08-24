/**
 * لایهٔ شبکهٔ ویرا — تماس‌های پرووایدر از این‌جا می‌گذرند (۰.۹.۵).
 *
 * چرا لازم شد: Node.js برخلاف مرورگر پراکسی سیستم (Hiddify، v2rayN، Clash…) را
 * خودکار استفاده نمی‌کند؛ درخواست‌ها مستقیم می‌روند و از IP ایران پاسخ کلاسیک
 * ۴۲۹/«درخواست‌ها زیاد است» می‌آید. با این لایه، هر تماس می‌تواند از پراکسی بگذرد:
 *
 *   پراکسی مؤثر = اتصالِ خودش ← پراکسی سراسری هاب ← متغیر محیط (HTTPS_PROXY/ALL_PROXY)
 *
 * مقصدهای محلی (Ollama، LM Studio، vLLM روی 127.0.0.1) **هرگز** از پراکسی نمی‌گذرند.
 * دو شکل آدرس پشتیبانی می‌شود: `http://127.0.0.1:7890` و `socks5://127.0.0.1:1080`
 * (Hiddify پورت مخصوص ۷۸۹۰ هر دو را می‌دهد).
 */

/*
 * نکتهٔ پیاده‌سازی: داسپچرِ بستهٔ npm (undici 7) با fetch داخلی Node (undici همراه
 * خود Node) ناسازگار است («invalid onRequestStart method»). پس درخواستِ پراکسی‌دار
 * از fetchِ خودِ همان بسته می‌رود؛ درخواست مستقیم همان fetch سراسری می‌مانَد.
 */
import { fetch as undiciFetch, ProxyAgent } from 'undici';
import { socksDispatcher } from 'fetch-socks';

/** @type {Map<string, any>} آدرس → داسپچر؛ ساخت داسپچر ارزان است ولی کش که کنیم، اتصال‌های پراکسی هم دوباره استفاده می‌شوند. */
const cache = new Map();

/** شکل درست آدرس پراکسی — «127.0.0.1:7890» هم بدون اسکیم قبول است. */
export function normalizeProxy( raw ) {
	const s = String( raw || '' ).trim();
	if ( ! s ) {
		return '';
	}
	if ( ! /^[a-z][a-z0-9+.-]*:\/\//i.test( s ) ) {
		return `http://${ s }`;
	}
	return s;
}

/** پراکسی از متغیرهای محیط — همان قراردادی که ابزارهای خط فرمان می‌شناسند. */
export function envProxy() {
	return normalizeProxy(
		process.env.HTTPS_PROXY || process.env.https_proxy ||
		process.env.ALL_PROXY || process.env.all_proxy || ''
	);
}

/** پراکسی مؤثر برای یک تماس: خود اتصال، بعد سراسری هاب، بعد محیط. */
export function effectiveProxy( connProxy, globalProxy ) {
	return normalizeProxy( connProxy ) || normalizeProxy( globalProxy ) || envProxy();
}

/** آیا مقصد محلی است؟ محلی از پراکسی نمی‌گذرد — پراکسیِ روشنِ خاموشش هم ممکن است. */
export function isLocalTarget( url ) {
	return /^(https?:)?\/\/(localhost|127\.|0\.0\.0\.0|\[::1\])/i.test( String( url ) );
}

/*
 * استثناها — همان صفحهٔ پراکسی ویندوز (Snap15): «به‌جز نشانی‌هایی که با این‌ها
 * شروع می‌شوند، با ؛ جدا». الگو با * تمام می‌شود یعنی پیشوند.
 * @type {{ exceptions: string[], bypassLocal: boolean }}
 */
const policy = { exceptions: [], bypassLocal: true };

export function setProxyPolicy( p = {} ) {
	policy.exceptions = String( p.exceptions || '' ).split( ';' ).map( ( x ) => x.trim().toLowerCase() ).filter( Boolean );
	policy.bypassLocal = p.bypassLocal !== false;
}

/** آیا این مقصد در استثناهای کاربر است؟ */
export function matchesException( url ) {
	let host = '';
	try { host = new URL( url ).hostname.toLowerCase(); } catch { return false; }
	if ( policy.bypassLocal && isLocalTarget( url ) ) { return true; }
	for ( const pat of policy.exceptions ) {
		if ( ! pat ) { continue; }
		if ( pat.endsWith( '*' ) ? host.startsWith( pat.slice( 0, -1 ) ) : host === pat ) { return true; }
	}
	return false;
}

/**
 * داسپچرِ پراکسی برای یک مقصد — null یعنی مستقیم.
 *
 * ⚠️ اینجا **`effectiveProxy` صدا زده می‌شود، نه `normalizeProxy`**. تا ۰.۹.۶ فقط
 * آرگومان `proxy` خوانده می‌شد و زنجیرهٔ «اتصال ← هاب ← محیط» عملاً مرده بود: تابع
 * `effectiveProxy` نوشته شده بود ولی هیچ‌جا استفاده نمی‌شد، پس `HTTPS_PROXY` هرگز
 * دیده نمی‌شد. نتیجه‌اش این بود که حالت هاب — که پیش از هر پاسخ چند تماس می‌زند —
 * پشت تحریم می‌ماند و «همهٔ مسیرها شکست خوردند» می‌داد، در حالی که حالت تک‌واحد با
 * یک تماس شانس رد شدن داشت. (گزارش کارفرما ۱۴۰۵/۰۵/۳۰، سند DESIGN-HUB-UI-FIX §۲.۶)
 *
 * @param {string} url
 * @param {string} [proxy] پراکسی مخصوص همین اتصال
 * @param {string} [globalProxy] پراکسی سراسری هاب
 */
export function dispatcherFor( url, proxy, globalProxy = '' ) {
	const p = ( isLocalTarget( url ) || matchesException( url ) ) ? '' : effectiveProxy( proxy, globalProxy );
	if ( ! p ) {
		return null;
	}
	if ( ! cache.has( p ) ) {
		if ( /^socks[45]?:\/\//i.test( p ) ) {
			/*
			 * `fetch-socks` آبجکت `{ type, host, port }` می‌خواهد، نه `{ url }`.
			 * با شکل غلط، «Invalid SOCKS proxy details were provided» می‌دهد — یعنی
			 * هر پراکسی socks5 (که Hiddify و v2rayN معمولاً همان را می‌دهند) بی‌صدا
			 * شکست می‌خورد و کاربر فکر می‌کند تحریم است.
			 */
			const u = new URL( p );
			cache.set( p, socksDispatcher( {
				type: u.protocol === 'socks4:' ? 4 : 5,
				host: u.hostname,
				port: Number( u.port ) || 1080,
				...( u.username ? { userId: decodeURIComponent( u.username ) } : {} ),
				...( u.password ? { password: decodeURIComponent( u.password ) } : {} ),
			} ) );
		} else {
			cache.set( p, new ProxyAgent( p ) );
		}
	}
	return cache.get( p );
}

/**
 * fetch با پراکسی — همان fetch معمولی، به‌جز اینکه در صورت پراکسیِ مؤثر داسپچر
 * روی درخواست سوار می‌شود.
 * @param {string} url
 * @param {any} [opts]
 * @param {string} [proxy]
 */
export async function proxyFetch( url, opts = {}, proxy = '', globalProxy = '' ) {
	const d = opts.dispatcher || dispatcherFor( url, proxy, globalProxy );
	if ( ! d ) {
		return fetch( url, opts );
	}
	// signal باید جدا منتقل شود؛ undici-fetch ورودی‌های استاندارد fetch را می‌گیرد.
	return undiciFetch( url, { ...opts, dispatcher: d } );
}
